<?php
require __DIR__ . '/wp-stub.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s %-52s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}
function reset_state() {
    $GLOBALS['opts'] = []; $GLOBALS['meta'] = []; $GLOBALS['posts'] = [];
    $GLOBALS['cron'] = []; $GLOBALS['http'] = []; $GLOBALS['http_queue'] = []; $GLOBALS['fired'] = [];
    SAP_Settings::update(['enabled' => true, 'post_types' => ['post'], 'template' => "{title}\n\n{url}"]);
    SAP_Settings::save_app('client-abc', 'secret-xyz');
    SAP_Settings::save_token([
        'access_token' => 'tok-live', 'refresh_token' => 'ref-live',
        'expires_at' => time() + 99999, 'username' => 'andrew',
    ]);
}
function make_post($id = 7, $status = 'publish') {
    $p = new WP_Post(['ID' => $id, 'post_title' => 'Hello World', 'post_content' => 'Body text here.', 'post_status' => $status]);
    $GLOBALS['posts'][$id] = $p;
    return $p;
}
$ok_body = json_encode(['data' => ['id' => '1777000111', 'text' => 'Hello World']]);

echo "=== 1. credentials round-trip through encryption ===\n";
reset_state();
check('secret encrypted at rest', str_starts_with((string)($GLOBALS['opts']['sap_x_app']['client_secret']), 'sap1:'), true);
check('secret not plaintext in option', str_contains(json_encode($GLOBALS['opts']['sap_x_app']), 'secret-xyz'), false);
check('secret decrypts back', SAP_Settings::app()['client_secret'], 'secret-xyz');
check('token decrypts back', SAP_Settings::token()['access_token'], 'tok-live');

echo "\n=== 2. publishing a new post queues an attempt ===\n";
reset_state(); $p = make_post();
SAP_Publisher::instance()->on_transition('publish', 'draft', $p);
check('one job queued', count($GLOBALS['cron']), 1);
check('queued on the right hook', $GLOBALS['cron'][0]['hook'], 'sap_publish_post');
check('queued with the post id', $GLOBALS['cron'][0]['args'], [7]);
check('marked pending', get_post_meta(7, '_sap_x_status', true), 'pending');

echo "\n=== 3. editing an already-live post queues nothing ===\n";
reset_state(); $p = make_post();
SAP_Publisher::instance()->on_transition('publish', 'publish', $p);
check('publish->publish queues nothing', count($GLOBALS['cron']), 0);

echo "\n=== 4. the attempt actually posts, and records the tweet ===\n";
reset_state(); $p = make_post();
sap_queue_http(201, $ok_body);
SAP_Publisher::instance()->process(7);
check('one HTTP call made', count($GLOBALS['http']), 1);
check('hit the tweets endpoint', $GLOBALS['http'][0]['url'], 'https://api.twitter.com/2/tweets');
check('sent a bearer token', $GLOBALS['http'][0]['args']['headers']['Authorization'], 'Bearer tok-live');
check('sent the composed text', json_decode($GLOBALS['http'][0]['args']['body'], true)['text'], "Hello World\n\nhttps://example.test/?p=7");
check('tweet id stored', get_post_meta(7, '_sap_x_tweet_id', true), '1777000111');
check('tweet url stored', get_post_meta(7, '_sap_x_tweet_url', true), 'https://x.com/andrew/status/1777000111');
check('status published', get_post_meta(7, '_sap_x_status', true), 'published');
check('usage counted', SAP_Settings::usage_this_month(), 1);
check('lock released', isset($GLOBALS['opts']['sap_lock_7']), false);

echo "\n=== 5. THE BIG ONE: it cannot post the same post twice ===\n";
SAP_Publisher::instance()->process(7);                      // cron fires again
SAP_Publisher::instance()->on_transition('publish', 'draft', $p); // re-publish
SAP_Publisher::instance()->process(7);
check('still exactly one HTTP call', count($GLOBALS['http']), 1);
check('still one usage counted', SAP_Settings::usage_this_month(), 1);

echo "\n=== 6. a held lock blocks a concurrent worker ===\n";
reset_state(); make_post();
add_option('sap_lock_7', time(), '', 'no');   // pretend another worker holds it
sap_queue_http(201, $ok_body);
SAP_Publisher::instance()->process(7);
check('no HTTP call while locked', count($GLOBALS['http']), 0);

echo "\n=== 7. transient failure retries with backoff ===\n";
reset_state(); make_post();
sap_queue_http(503, json_encode(['title' => 'Service Unavailable']));
SAP_Publisher::instance()->process(7);
check('still pending for retry', get_post_meta(7, '_sap_x_status', true), 'pending');
check('retry scheduled', count($GLOBALS['cron']), 1);
check('backoff is 2 min', $GLOBALS['cron'][0]['ts'] - time() >= 110, true);
check('attempt counted', (int)get_post_meta(7, '_sap_x_attempts', true), 1);
check('no tweet id recorded', get_post_meta(7, '_sap_x_tweet_id', true), '');

echo "\n=== 8. permanent failure gives up instead of looping ===\n";
reset_state(); make_post();
sap_queue_http(403, json_encode(['detail' => 'You are not allowed to create a Tweet with duplicate content.']));
SAP_Publisher::instance()->process(7);
check('marked failed', get_post_meta(7, '_sap_x_status', true), 'failed');
check('no retry scheduled', count($GLOBALS['cron']), 0);

echo "\n=== 9. a revoked token is not retried either ===\n";
reset_state(); make_post();
sap_queue_http(401, json_encode(['title' => 'Unauthorized']));
SAP_Publisher::instance()->process(7);
check('401 marked failed', get_post_meta(7, '_sap_x_status', true), 'failed');
check('401 not retried', count($GLOBALS['cron']), 0);

echo "\n=== 10. network drop IS retried (request may never have landed) ===\n";
reset_state(); make_post();
sap_queue_http(0, '');
SAP_Publisher::instance()->process(7);
check('transport failure retried', count($GLOBALS['cron']), 1);

echo "\n=== 11. rules: disabled / wrong type / excluded category / skip flag ===\n";
reset_state(); $p = make_post();
SAP_Settings::update(['enabled' => false]);
check('disabled blocks', is_string(SAP_Publisher::instance()->ineligible_reason($p)), true);
SAP_Settings::update(['enabled' => true, 'post_types' => ['page']]);
check('wrong post type blocks', is_string(SAP_Publisher::instance()->ineligible_reason($p)), true);
SAP_Settings::update(['post_types' => ['post'], 'exclude_categories' => [9]]);
$GLOBALS['cats'][7] = [9];
check('excluded category blocks', is_string(SAP_Publisher::instance()->ineligible_reason($p)), true);
$GLOBALS['cats'][7] = [3];
check('non-excluded category allowed', SAP_Publisher::instance()->ineligible_reason($p), null);
SAP_Settings::update(['categories' => [5], 'exclude_categories' => []]);
check('not in include list blocks', is_string(SAP_Publisher::instance()->ineligible_reason($p)), true);
SAP_Settings::update(['categories' => []]);
update_post_meta(7, '_sap_skip', '1');
check('per-post skip blocks', is_string(SAP_Publisher::instance()->ineligible_reason($p)), true);

echo "\n=== 12. no account connected -> no attempt, clear reason ===\n";
reset_state(); $p = make_post();
SAP_Settings::clear_token();
check('disconnected blocks', SAP_Publisher::instance()->ineligible_reason($p), 'No X account is connected.');

echo "\n=== 13. expiring token refreshes before posting ===\n";
reset_state(); make_post();
SAP_Settings::save_token(['access_token' => 'tok-old', 'refresh_token' => 'ref-old', 'expires_at' => time() + 5]);
sap_queue_http(200, json_encode(['access_token' => 'tok-new', 'refresh_token' => 'ref-new', 'expires_in' => 7200]));
sap_queue_http(201, $ok_body);
SAP_Publisher::instance()->process(7);
check('refresh then post = 2 calls', count($GLOBALS['http']), 2);
check('refreshed at token endpoint', $GLOBALS['http'][0]['url'], 'https://api.twitter.com/2/oauth2/token');
check('used refresh_token grant', $GLOBALS['http'][0]['args']['body']['grant_type'], 'refresh_token');
check('posted with the NEW token', $GLOBALS['http'][1]['args']['headers']['Authorization'], 'Bearer tok-new');
check('new refresh token persisted', SAP_Settings::token()['refresh_token'], 'ref-new');
check('post still succeeded', get_post_meta(7, '_sap_x_status', true), 'published');

echo "\n=== 14. rotation safety: a refresh with no new refresh_token keeps the old ===\n";
reset_state();
SAP_Settings::save_token(['access_token' => 'a2', 'expires_at' => time() + 10]);
check('old refresh token survives', SAP_Settings::token()['refresh_token'], 'ref-live');

echo "\n=== 15. the Socials block renders the tweet ===\n";
reset_state(); make_post();
sap_queue_http(201, $ok_body);
SAP_Publisher::instance()->process(7);
SAP_Settings::update(['profiles' => [
    ['label' => 'Instagram', 'url' => 'https://instagram.com/andrew.amns', 'handle' => '@andrew.amns'],
    ['label' => 'YouTube',   'url' => 'https://youtube.com/@amns.andrew',  'handle' => '@amns.andrew'],
]]);
$html = SAP_Socials::instance()->render(7);
check('has heading', str_contains($html, '<h3 class="sap-socials__title">Socials</h3>'), true);
check('links the tweet', str_contains($html, 'https://x.com/andrew/status/1777000111'), true);
check('shows the X handle', str_contains($html, '@andrew'), true);
check('lists Instagram', str_contains($html, '@andrew.amns'), true);
check('lists YouTube', str_contains($html, '@amns.andrew'), true);
echo "\n--- rendered block ---\n" . preg_replace('/></', ">\n<", $html) . "\n\n";

echo "=== 16. an unshared post renders no block ===\n";
reset_state(); make_post(8);
check('nothing to show = empty string', SAP_Socials::instance()->render(8), '');

echo "\n", $fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
