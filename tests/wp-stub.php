<?php
/**
 * A hand-rolled slice of WordPress: just enough of the API for the plugin's
 * real code to run under plain PHP.
 *
 * This exists so the publishing rules can be tested without a database, a web
 * server or a WordPress install — the logic that must never double-post is
 * worth being able to check in a second, on every change.
 *
 * @package SocialAutoPost
 */

define('ABSPATH', '/tmp/wp/');
define('AUTH_KEY', 'test-auth-key-aaaaaaaaaaaaaaaaaaaa');
define('SECURE_AUTH_KEY', 'test-secure-bbbbbbbbbbbbbbbbbbbb');
define('AUTH_SALT', 'test-salt-cccccccccccccccccccc');
define('MINUTE_IN_SECONDS', 60);
define('SAP_VERSION', 'test');
define('SAP_URL', 'http://example.test/wp-content/plugins/social-auto-post/');

class WP_Post {
    public $ID, $post_title, $post_content, $post_excerpt = '', $post_status, $post_type = 'post',
           $post_author = 1, $post_password = '';
    public function __construct($a) { foreach ($a as $k => $v) { $this->$k = $v; } }
}
class WP_Error {
    private $code, $message, $data;
    public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

$GLOBALS['opts'] = []; $GLOBALS['meta'] = []; $GLOBALS['posts'] = [];
$GLOBALS['cron'] = []; $GLOBALS['http'] = []; $GLOBALS['actions'] = [];

function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function add_option($k, $v, $x = '', $a = 'yes') { if (array_key_exists($k, $GLOBALS['opts'])) return false; $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function set_transient($k, $v, $t) { $GLOBALS['opts']['_t_'.$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['opts']['_t_'.$k] ?? false; }
function delete_transient($k) { unset($GLOBALS['opts']['_t_'.$k]); return true; }

function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function wp_is_post_revision($id) { return false; }

function add_action($h, $c, $p = 10, $n = 1) { $GLOBALS['actions'][$h][] = $c; }
function add_filter($h, $c, $p = 10, $n = 1) { $GLOBALS['actions'][$h][] = $c; }
function add_shortcode($t, $c) {}
function apply_filters($h, $v) { return $v; }
function do_action($h, ...$a) { $GLOBALS['fired'][] = $h; }

function wp_schedule_single_event($ts, $hook, $args = []) { $GLOBALS['cron'][] = ['ts' => $ts, 'hook' => $hook, 'args' => $args]; return true; }
function wp_next_scheduled($h, $args = []) {
    $best = false;
    foreach ($GLOBALS['cron'] as $e) {
        if ($e['hook'] !== $h) continue;
        if ($args !== [] && $e['args'] !== $args) continue;
        if ($best === false || $e['ts'] < $best) $best = $e['ts'];
    }
    return $best;
}
function wp_unschedule_event($t, $h) { return true; }
function wp_clear_scheduled_hook($h) { return true; }

function get_permalink($p) { $id = is_object($p) ? $p->ID : $p; return "https://example.test/?p={$id}"; }
function get_the_title($p) { $o = is_object($p) ? $p : get_post($p); return $o ? $o->post_title : ''; }
function get_the_category($id) { return []; }
function wp_get_post_categories($id) { return $GLOBALS['cats'][$id] ?? []; }
function get_the_author_meta($f, $id) { return 'Test Author'; }
function get_bloginfo($k) { return 'Test Site'; }
function has_excerpt($p) { return '' !== trim($p->post_excerpt); }
function strip_shortcodes($s) { return $s; }
function wp_strip_all_tags($s) { return strip_tags($s); }

function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$s)); }
function sanitize_title($s) { return preg_replace('/[^a-z0-9\-]/', '-', strtolower((string)$s)); }
function esc_url_raw($s) { return $s; }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_textarea($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return $s; }
function esc_attr__($s, $d = null) { return $s; }
function __($s, $d = null) { return $s; }
function wp_unslash($s) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function human_time_diff($a, $b) { return 'a while'; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function is_admin() { return false; }
function is_singular() { return true; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function get_the_ID() { return $GLOBALS['current_post'] ?? 0; }
function current_user_can($c, $o = null) { return true; }
function wp_enqueue_style(...$a) {}
function shortcode_atts($d, $a, $s = '') { return array_merge($d, (array)$a); }
function _n($single, $plural, $n, $d = null) { return $n == 1 ? $single : $plural; }
function get_edit_post_link($id, $c = '') { return 'https://example.test/wp-admin/post.php?post=' . $id; }
function get_the_date($f = '', $id = 0) { return '1 Jan 2024'; }
function submit_button(...$a) {}
function wp_nonce_field(...$a) {}
function check_admin_referer(...$a) { return true; }
function wp_safe_redirect($u) { $GLOBALS['redirect'] = $u; return true; }
function add_query_arg($args, $url = '') {
    if (!is_array($args)) { return $url; }
    $q = [];
    foreach ($args as $k => $v) { $q[] = $k . '=' . $v; }
    return $url . (strpos((string)$url, '?') === false ? '?' : '&') . implode('&', $q);
}
function wp_nonce_url($u, $a = -1) { return $u . '&_wpnonce=test'; }

/**
 * A WP_Query stub covering only what find_unshared() asks for: published
 * posts of given types, filtered by NOT EXISTS meta checks.
 */
class WP_Query {
    public $posts = [];
    public function __construct($args) {
        $types = (array)($args['post_type'] ?? ['post']);
        $limit = (int)($args['posts_per_page'] ?? -1);
        $out = [];
        foreach ($GLOBALS['posts'] as $id => $p) {
            if (($args['post_status'] ?? 'publish') !== $p->post_status) continue;
            if (!in_array($p->post_type, $types, true)) continue;
            $keep = true;
            foreach ((array)($args['meta_query'] ?? []) as $k => $clause) {
                if ($k === 'relation' || !is_array($clause)) continue;
                $val = $GLOBALS['meta'][$id][$clause['key']] ?? '';
                if (($clause['compare'] ?? '') === 'NOT EXISTS' && $val !== '') { $keep = false; break; }
            }
            if ($keep) $out[] = (int)$id;
        }
        sort($out);
        $this->posts = ($limit > 0) ? array_slice($out, 0, $limit) : $out;
    }
}

/* Mocked HTTP: queue responses, record requests. */
$GLOBALS['http_queue'] = [];
function sap_queue_http($status, $body) { $GLOBALS['http_queue'][] = ['status' => $status, 'body' => $body]; }
function wp_remote_post($url, $args = []) {
    $GLOBALS['http'][] = ['url' => $url, 'args' => $args];
    if (!$GLOBALS['http_queue']) return new WP_Error('no_mock', 'no queued response');
    $r = array_shift($GLOBALS['http_queue']);
    if ($r['status'] === 0) return new WP_Error('http_request_failed', 'connection reset');
    return $r;
}
function wp_remote_get($url, $args = []) { return wp_remote_post($url, $args); }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? $r['status'] : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? $r['body'] : ''; }

require __DIR__ . '/../includes/class-sap-settings.php';
require __DIR__ . '/../includes/class-sap-crypto.php';
require __DIR__ . '/../includes/class-sap-logger.php';
require __DIR__ . '/../includes/class-sap-template.php';
require __DIR__ . '/../includes/class-sap-x-oauth.php';
require __DIR__ . '/../includes/class-sap-x-client.php';
require __DIR__ . '/../includes/class-sap-publisher.php';
require __DIR__ . '/../includes/class-sap-socials.php';
