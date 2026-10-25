<?php
/**
 * Which WordPress status transitions share a post, and which must not.
 *
 * Updating a live post is a `publish -> publish` transition. If that shared,
 * fixing a typo on an old article would tweet it, and a bulk edit across a
 * back catalogue would empty the monthly API allowance in one go.
 *
 * @package SocialAutoPost
 */

require __DIR__ . '/wp-stub.php';

function setup() {
    $GLOBALS['opts']=[];$GLOBALS['meta']=[];$GLOBALS['posts']=[];
    $GLOBALS['cron']=[];$GLOBALS['http']=[];$GLOBALS['http_queue']=[];
    SAP_Settings::update(['enabled'=>true,'post_types'=>['post'],'template'=>"{title}\n\n{url}"]);
    SAP_Settings::save_app('cid','sec');
    SAP_Settings::save_token(['access_token'=>'tok','refresh_token'=>'ref','expires_at'=>time()+99999,'username'=>'andrew']);
}
function mkpost($id){ $p=new WP_Post(['ID'=>$id,'post_title'=>'Old Article','post_content'=>'x','post_status'=>'publish']); $GLOBALS['posts'][$id]=$p; return $p; }
$OK = json_encode(['data'=>['id'=>'999','text'=>'t']]);
$fail = 0;
function r($label,$got,$want){
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s  %-58s %s\n", $ok?'PASS':'FAIL', $label, $ok?'':"(got ".var_export($got,true).")");
}

echo "SCENARIO A — post published BEFORE the plugin existed, never tweeted.\n";
echo "            You now edit it and hit Update.\n\n";
setup(); $p = mkpost(11);
sap_queue_http(201,$OK);
SAP_Publisher::instance()->on_transition('publish','publish',$p);   // = clicking Update
r('does NOT queue a tweet', count($GLOBALS['cron']), 0);
r('does NOT call the X API', count($GLOBALS['http']), 0);
r('no tweet recorded',      get_post_meta(11,'_sap_x_tweet_id',true), '');

echo "\n            ...but is it still eligible, so 'Share now' works?\n";
r('eligible for a manual share', SAP_Publisher::instance()->ineligible_reason($p), null);
SAP_Publisher::instance()->process(11);                              // = clicking Share now
r('Share now DOES tweet it',  count($GLOBALS['http']), 1);
r('tweet recorded',           get_post_meta(11,'_sap_x_tweet_id',true), '999');

echo "\n\nSCENARIO B — post already tweeted. You edit it and hit Update.\n\n";
setup(); $p = mkpost(12);
sap_queue_http(201,$OK); SAP_Publisher::instance()->process(12);     // first share
$before = count($GLOBALS['http']);
sap_queue_http(201,$OK);
SAP_Publisher::instance()->on_transition('publish','publish',$p);    // Update
SAP_Publisher::instance()->process(12);                              // stray cron too
r('no second tweet on update', count($GLOBALS['http']), $before);
r('blocked with a clear reason', SAP_Publisher::instance()->ineligible_reason($p), 'Already shared to X.');

echo "\n\nSCENARIO C — the transitions that DO trigger a share.\n\n";
setup(); $p = mkpost(13); $p->post_status='publish';
SAP_Publisher::instance()->on_transition('publish','draft',$p);
r('draft -> publish   tweets', count($GLOBALS['cron']), 1);

setup(); $p = mkpost(14);
SAP_Publisher::instance()->on_transition('publish','future',$p);
r('scheduled -> publish tweets', count($GLOBALS['cron']), 1);

setup(); $p = mkpost(15);
SAP_Publisher::instance()->on_transition('publish','pending',$p);
r('pending -> publish tweets', count($GLOBALS['cron']), 1);

echo "\n\nSCENARIO D — old post, never tweeted: unpublish to draft, then republish.\n\n";
setup(); $p = mkpost(16);
SAP_Publisher::instance()->on_transition('draft','publish',$p);      // unpublish
r('unpublishing queues nothing', count($GLOBALS['cron']), 0);
SAP_Publisher::instance()->on_transition('publish','draft',$p);      // republish
r('republishing DOES queue it', count($GLOBALS['cron']), 1);

echo "\n", $fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
