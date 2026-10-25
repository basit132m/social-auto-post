<?php
/**
 * Sharing a back catalogue: finding posts that predate the plugin, queueing
 * them with spacing, and never sharing one twice however it is triggered.
 *
 * @package SocialAutoPost
 */

require __DIR__ . '/wp-stub.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		$fail++;
	}
	printf( "%s %-56s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $got, true ), var_export( $want, true ) );
}
function setup() {
	$GLOBALS['opts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['posts'] = array();
	$GLOBALS['cron'] = array(); $GLOBALS['http'] = array(); $GLOBALS['http_queue'] = array();
	SAP_Settings::update( array( 'enabled' => true, 'post_types' => array( 'post' ), 'bulk_interval' => 10 ) );
	SAP_Settings::save_app( 'cid', 'sec' );
	SAP_Settings::save_token( array( 'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => time() + 99999, 'username' => 'andrew' ) );
}
function mkpost( $id, $status = 'publish', $type = 'post' ) {
	$GLOBALS['posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_title' => "Article $id", 'post_content' => 'body', 'post_status' => $status, 'post_type' => $type ) );
	return $GLOBALS['posts'][ $id ];
}
$OK = json_encode( array( 'data' => array( 'id' => '555', 'text' => 't' ) ) );

echo "=== 1. a site with 50 old articles: they are all found ===\n";
setup();
for ( $i = 1; $i <= 50; $i++ ) { mkpost( $i ); }
$pub = SAP_Publisher::instance();
check( 'all 50 found as unshared', count( $pub->find_unshared( -1 ) ), 50 );
check( 'count helper agrees', $pub->count_unshared(), 50 );
check( 'oldest first', $pub->find_unshared( -1 )[0], 1 );
check( 'limit is respected', count( $pub->find_unshared( 10 ) ), 10 );

echo "\n=== 2. already-shared, opted-out and draft posts are excluded ===\n";
setup();
mkpost( 1 ); mkpost( 2 ); mkpost( 3 ); mkpost( 4, 'draft' ); mkpost( 5, 'publish', 'page' );
update_post_meta( 2, '_sap_x_tweet_id', '111' );   // already shared
update_post_meta( 3, '_sap_skip', '1' );           // author opted out
$found = SAP_Publisher::instance()->find_unshared( -1 );
check( 'only the one shareable post', $found, array( 1 ) );

echo "\n=== 3. queueing spaces them out instead of firing at once ===\n";
setup();
for ( $i = 1; $i <= 5; $i++ ) { mkpost( $i ); }
$r = SAP_Publisher::instance()->queue_many( array( 1, 2, 3, 4, 5 ) );
check( 'all five queued', $r['queued'], 5 );
check( 'five cron jobs', count( $GLOBALS['cron'] ), 5 );
check( 'nothing posted yet', count( $GLOBALS['http'] ), 0 );
check( 'first goes immediately', $GLOBALS['cron'][0]['ts'] - time() < 5, true );
check( 'second is +10 min', (int) round( ( $GLOBALS['cron'][1]['ts'] - $GLOBALS['cron'][0]['ts'] ) / 60 ), 10 );
check( 'fifth is +40 min', (int) round( ( $GLOBALS['cron'][4]['ts'] - $GLOBALS['cron'][0]['ts'] ) / 60 ), 40 );
check( 'all marked pending', get_post_meta( 3, '_sap_x_status', true ), 'pending' );

echo "\n=== 4. ONCE ONLY: queueing a post that was already shared ===\n";
setup(); mkpost( 1 ); mkpost( 2 );
update_post_meta( 1, '_sap_x_tweet_id', '111' );
$r = SAP_Publisher::instance()->queue_many( array( 1, 2 ) );
check( 'shared one is skipped', $r['already'], 1 );
check( 'only the unshared one queued', $r['queued'], 1 );
check( 'one cron job', count( $GLOBALS['cron'] ), 1 );
check( 'and it is for post 2', $GLOBALS['cron'][0]['args'], array( 2 ) );

echo "\n=== 5. ONCE ONLY: clicking \"Queue these posts\" twice ===\n";
setup();
for ( $i = 1; $i <= 3; $i++ ) { mkpost( $i ); }
SAP_Publisher::instance()->queue_many( array( 1, 2, 3 ) );
$r = SAP_Publisher::instance()->queue_many( array( 1, 2, 3 ) );   // impatient double-click
check( 'second run queues nothing', $r['queued'], 0 );
check( 'all three seen as pending', $r['already'], 3 );
check( 'still only three cron jobs', count( $GLOBALS['cron'] ), 3 );

echo "\n=== 6. ONCE ONLY: the queue runs, then everything fires again ===\n";
setup();
for ( $i = 1; $i <= 3; $i++ ) { mkpost( $i ); }
SAP_Publisher::instance()->queue_many( array( 1, 2, 3 ), 0 );
for ( $i = 1; $i <= 3; $i++ ) { sap_queue_http( 201, $OK ); }
foreach ( array( 1, 2, 3 ) as $id ) { SAP_Publisher::instance()->process( $id ); }
check( 'three posts, three API calls', count( $GLOBALS['http'] ), 3 );
// Now throw everything at them again.
SAP_Publisher::instance()->queue_many( array( 1, 2, 3 ) );
foreach ( array( 1, 2, 3 ) as $id ) { SAP_Publisher::instance()->process( $id ); }
SAP_Publisher::instance()->on_transition( 'publish', 'publish', get_post( 2 ) );
SAP_Publisher::instance()->process( 2 );
check( 'STILL three API calls', count( $GLOBALS['http'] ), 3 );
check( 'usage counted three times only', SAP_Settings::usage_this_month(), 3 );

echo "\n=== 7. ineligible posts are reported, not silently dropped ===\n";
setup(); mkpost( 1 ); mkpost( 2, 'draft' );
SAP_Settings::update( array( 'exclude_categories' => array( 9 ) ) );
$GLOBALS['cats'][1] = array( 9 );
$r = SAP_Publisher::instance()->queue_many( array( 1, 2 ) );
check( 'both rejected', $r['ineligible'], 2 );
check( 'none queued', $r['queued'], 0 );
check( 'reason recorded on the post', get_post_meta( 1, '_sap_x_error', true ), 'Post is in an excluded category.' );

echo "\n=== 8. the bulk action on the posts list ===\n";
setup();
mkpost( 1 ); mkpost( 2 );
$actions = SAP_Publisher::instance()->bulk_action( array( 'trash' => 'Trash' ) );
check( 'menu entry added', isset( $actions['sap_share'] ), true );
check( 'existing entries kept', isset( $actions['trash'] ), true );
$redirect = SAP_Publisher::instance()->handle_bulk_action( 'edit.php', 'sap_share', array( 1, 2 ) );
check( 'two queued via bulk', count( $GLOBALS['cron'] ), 2 );
check( 'result reported in the URL', strpos( $redirect, 'sap_queued=2' ) !== false, true );
$untouched = SAP_Publisher::instance()->handle_bulk_action( 'edit.php', 'trash', array( 1, 2 ) );
check( 'other bulk actions untouched', $untouched, 'edit.php' );

echo "\n=== 9. the row action offers share, then the tweet link ===\n";
setup(); $p = mkpost( 1 );
$row = SAP_Publisher::instance()->row_action( array(), $p );
check( 'unshared post offers Share to X', strpos( $row['sap_share'], 'sap_share_now' ) !== false, true );
sap_queue_http( 201, $OK );
SAP_Publisher::instance()->process( 1 );
$row = SAP_Publisher::instance()->row_action( array(), $p );
check( 'shared post offers no Share link', isset( $row['sap_share'] ), false );
check( 'shared post links to the tweet', strpos( $row['sap_view'], 'x.com/andrew/status/555' ) !== false, true );

echo "\n=== 10. a draft never gets a row action ===\n";
setup(); $d = mkpost( 1, 'draft' );
check( 'no actions on a draft', SAP_Publisher::instance()->row_action( array(), $d ), array() );

echo "\n", $fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
