<?php
/**
 * A short, readable activity log for the admin screen.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the most recent events in an option, newest first.
 *
 * Auto-posting fails silently by nature — nobody is watching at 3am when a
 * token expires. This log is what turns "my posts stopped going out" into a
 * line that says which post, when, and what X replied.
 */
class SAP_Logger {

	const OPTION = 'sap_log';

	/**
	 * Append an entry.
	 *
	 * @param string $level   One of info|success|warning|error.
	 * @param string $message Human-readable summary.
	 * @param array  $context Extra fields, e.g. post_id.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		$entries = self::entries();

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'level'   => (string) $level,
				'message' => (string) $message,
				'context' => $context,
			)
		);

		$limit = (int) SAP_Settings::get( 'log_retention', 200 );
		if ( $limit < 10 ) {
			$limit = 10;
		}
		if ( count( $entries ) > $limit ) {
			$entries = array_slice( $entries, 0, $limit );
		}

		// autoload=false: the log can get chunky and is only read on one screen.
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * All stored entries, newest first.
	 *
	 * @return array
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
