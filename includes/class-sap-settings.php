<?php
/**
 * Plugin configuration: defaults, reads and writes.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every option this plugin owns lives behind this class, so the storage shape
 * can change without the rest of the plugin caring.
 */
class SAP_Settings {

	const OPTION_SETTINGS  = 'sap_settings';
	const OPTION_APP       = 'sap_x_app';
	const OPTION_TOKEN     = 'sap_x_token';
	const OPTION_USAGE     = 'sap_usage';

	const OPTION_BILLING = 'sap_billing_blocked';

	/**
	 * What X charges to create one post, in USD.
	 *
	 * X retired its free and subscription tiers in February 2026 and moved to
	 * pay-per-use credits. A plain post is around $0.015 — but a post
	 * containing a link is far more expensive, and every post this plugin
	 * sends carries a link, because sharing the permalink is the entire point.
	 * So the link rate is the one that matters here.
	 *
	 * These are published rates, not something the plugin can read from your
	 * account. Override with the `sap_cost_per_post` filter if yours differ.
	 */
	const COST_PER_POST_WITH_LINK = 0.20;

	/**
	 * Cost of one post as this site should estimate it.
	 *
	 * @return float USD.
	 */
	public static function cost_per_post() {
		return (float) apply_filters( 'sap_cost_per_post', self::COST_PER_POST_WITH_LINK );
	}

	/**
	 * Estimated spend for a number of posts, formatted for display.
	 *
	 * @param int $count Number of posts.
	 * @return string e.g. "$10.00".
	 */
	public static function estimate( $count ) {
		return '$' . number_format( self::cost_per_post() * (int) $count, 2 );
	}

	/* -------------------------------------------------------------- billing */

	/**
	 * Record that X is refusing posts for billing reasons.
	 *
	 * Kept separate from the per-post error because it is an account-level
	 * problem: every post will fail the same way until it is resolved, so it
	 * is worth saying once, loudly, rather than once per post in a log nobody
	 * is reading.
	 *
	 * @param string $message What X said.
	 * @return void
	 */
	public static function set_billing_blocked( $message ) {
		update_option( self::OPTION_BILLING, (string) $message, false );
	}

	/**
	 * The current billing problem, if any.
	 *
	 * @return string Empty when there is none.
	 */
	public static function billing_blocked() {
		return (string) get_option( self::OPTION_BILLING, '' );
	}

	/**
	 * Clear the billing warning, after a post succeeds.
	 *
	 * @return void
	 */
	public static function clear_billing_blocked() {
		delete_option( self::OPTION_BILLING );
	}

	/**
	 * Defaults, merged under whatever is stored. Adding a key here is all that
	 * is needed to roll a new setting out to existing installs.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'            => false,
			'post_types'         => array( 'post' ),
			'categories'         => array(),
			'exclude_categories' => array(),
			'template'           => "{title}\n\n{url}",
			'hashtags'           => '',
			'delay_minutes'      => 0,
			'max_attempts'       => 4,
			'bulk_interval'      => 10,
			'socials_enabled'    => true,
			'socials_position'   => 'after_content',
			'socials_title'      => 'Socials',
			'socials_show_x'     => true,
			'profiles'           => array(),
			'log_retention'      => 200,
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merge changes into the stored settings.
	 *
	 * @param array $changes Partial settings.
	 * @return void
	 */
	public static function update( array $changes ) {
		update_option( self::OPTION_SETTINGS, array_merge( self::all(), $changes ) );
	}

	/* ------------------------------------------------------------------ app */

	/**
	 * The X developer app credentials. The secret is stored encrypted.
	 *
	 * @return array{client_id:string,client_secret:string}
	 */
	public static function app() {
		$stored = get_option( self::OPTION_APP, array() );
		return array(
			'client_id'     => isset( $stored['client_id'] ) ? (string) $stored['client_id'] : '',
			'client_secret' => isset( $stored['client_secret'] )
				? (string) SAP_Crypto::decrypt( $stored['client_secret'] )
				: '',
		);
	}

	/**
	 * Store the X app credentials.
	 *
	 * @param string $client_id     OAuth 2.0 client id.
	 * @param string $client_secret OAuth 2.0 client secret.
	 * @return void
	 */
	public static function save_app( $client_id, $client_secret ) {
		update_option(
			self::OPTION_APP,
			array(
				'client_id'     => sanitize_text_field( $client_id ),
				'client_secret' => SAP_Crypto::encrypt( $client_secret ),
			)
		);
	}

	/**
	 * Whether an app has been configured at all.
	 *
	 * @return bool
	 */
	public static function has_app() {
		$app = self::app();
		return '' !== $app['client_id'] && '' !== $app['client_secret'];
	}

	/* ---------------------------------------------------------------- token */

	/**
	 * The connected account's token set, with secrets decrypted.
	 *
	 * @return array|null Null when no account is connected.
	 */
	public static function token() {
		$stored = get_option( self::OPTION_TOKEN, array() );
		if ( empty( $stored ) || ! is_array( $stored ) || empty( $stored['access_token'] ) ) {
			return null;
		}
		return array(
			'access_token'  => (string) SAP_Crypto::decrypt( $stored['access_token'] ),
			'refresh_token' => isset( $stored['refresh_token'] )
				? (string) SAP_Crypto::decrypt( $stored['refresh_token'] )
				: '',
			'expires_at'    => isset( $stored['expires_at'] ) ? (int) $stored['expires_at'] : 0,
			'scope'         => isset( $stored['scope'] ) ? (string) $stored['scope'] : '',
			'username'      => isset( $stored['username'] ) ? (string) $stored['username'] : '',
			'user_id'       => isset( $stored['user_id'] ) ? (string) $stored['user_id'] : '',
		);
	}

	/**
	 * Persist a token set, encrypting the secrets.
	 *
	 * @param array $token Token fields.
	 * @return void
	 */
	public static function save_token( array $token ) {
		$existing = get_option( self::OPTION_TOKEN, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$record = $existing;
		if ( isset( $token['access_token'] ) ) {
			$record['access_token'] = SAP_Crypto::encrypt( $token['access_token'] );
		}
		// X rotates refresh tokens: an absent one must not wipe the stored one.
		if ( ! empty( $token['refresh_token'] ) ) {
			$record['refresh_token'] = SAP_Crypto::encrypt( $token['refresh_token'] );
		}
		foreach ( array( 'expires_at', 'scope', 'username', 'user_id' ) as $key ) {
			if ( isset( $token[ $key ] ) ) {
				$record[ $key ] = $token[ $key ];
			}
		}

		update_option( self::OPTION_TOKEN, $record, false );
	}

	/**
	 * Forget the connected account.
	 *
	 * @return void
	 */
	public static function clear_token() {
		delete_option( self::OPTION_TOKEN );
	}

	/**
	 * Whether an account is connected.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return null !== self::token();
	}

	/* ---------------------------------------------------------------- usage */

	/**
	 * Count one published tweet against the current calendar month.
	 *
	 * @return void
	 */
	public static function record_post() {
		$usage = get_option( self::OPTION_USAGE, array() );
		if ( ! is_array( $usage ) ) {
			$usage = array();
		}
		$month           = gmdate( 'Y-m' );
		$usage[ $month ] = isset( $usage[ $month ] ) ? (int) $usage[ $month ] + 1 : 1;

		// Keep a rolling year; this option is not an analytics store.
		if ( count( $usage ) > 12 ) {
			ksort( $usage );
			$usage = array_slice( $usage, -12, null, true );
		}
		update_option( self::OPTION_USAGE, $usage, false );
	}

	/**
	 * How many posts went out this calendar month.
	 *
	 * @return int
	 */
	public static function usage_this_month() {
		$usage = get_option( self::OPTION_USAGE, array() );
		$month = gmdate( 'Y-m' );
		return ( is_array( $usage ) && isset( $usage[ $month ] ) ) ? (int) $usage[ $month ] : 0;
	}
}
