<?php
define('ABSPATH', __DIR__);
function __($s, $d=null){ return $s; }
require __DIR__ . '/../includes/class-sap-template.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    printf("%s %-46s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($want, true));
}

// --- weighted length ---
check('ascii is 1 each', SAP_Template::weighted_length('hello'), 5);
check('url is always 23 (short)', SAP_Template::weighted_length('https://a.co'), 23);
check('url is always 23 (very long)', SAP_Template::weighted_length('https://example.com/'.str_repeat('x',200)), 23);
check('CJK costs 2', SAP_Template::weighted_length('日本語'), 6);
check('emoji costs 2', SAP_Template::weighted_length('🎉'), 2);
check("mixed: 3 ascii + 1 url", SAP_Template::weighted_length("hi https://a.co"), 3 + 23);

// --- hashtags ---
check('hashtags normalize', SAP_Template::normalize_hashtags('wordpress, #php  news'), '#wordpress #php #news');
check('hashtags dedupe', SAP_Template::normalize_hashtags('#a a #a'), '#a');
check('hashtags empty', SAP_Template::normalize_hashtags('  '), '');

// --- fit: short text untouched ---
$short = "Hello world\n\nhttps://example.com/post";
check('short text untouched', SAP_Template::fit($short), $short);

// --- fit: long body, trailing url preserved ---
$long = str_repeat('word ', 120) . "\n\nhttps://example.com/my-great-post #news";
$fitted = SAP_Template::fit($long);
echo "\n--- fitted long ---\n$fitted\n--- end (weighted=".SAP_Template::weighted_length($fitted).") ---\n\n";
check('fitted within limit', SAP_Template::weighted_length($fitted) <= 280, true);
check('url survived', str_contains($fitted, 'https://example.com/my-great-post'), true);
check('hashtag survived', str_contains($fitted, '#news'), true);
check('has ellipsis', str_contains($fitted, "\u{2026}"), true);
check('no mid-word cut', (bool)preg_match('/word\x{2026}/u', $fitted), true);

// --- fit: no url at all ---
$nourl = str_repeat('alpha ', 100);
$f2 = SAP_Template::fit($nourl);
check('no-url fitted in limit', SAP_Template::weighted_length($f2) <= 280, true);
check('no-url ends ellipsis', str_ends_with($f2, "\u{2026}"), true);

// --- fit: CJK gets half the room ---
$cjk = str_repeat('日', 300) . "\n\nhttps://example.com/x";
$f3 = SAP_Template::fit($cjk);
check('cjk fitted in limit', SAP_Template::weighted_length($f3) <= 280, true);
check('cjk kept url', str_contains($f3, 'https://example.com/x'), true);

// --- fit: title so long only link fits ---
$f4 = SAP_Template::fit(str_repeat('x', 400) . ' https://example.com/a');
check('degenerate still <= 280', SAP_Template::weighted_length($f4) <= 280, true);

echo "\n", $fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
