<?php
/**
 * Thin client for the X v2 endpoints this plugin uses.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Posts tweets and classifies failures.
 *
 * Every error carries a `retryable` flag, because that is the only question
 * the queue actually needs answered: a rate limit should come back in ten
 * minutes, a duplicate tweet or a revoked token never should.
 */
class SAP_X_Client {

	/**
	 * Publish a tweet.
	 *
	 * @param string $text Tweet body, already fitted to the limit.
	 * @return array{id:string,url:string}|WP_Error
	 */
	public function post_tweet( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return self::error( 'sap_empty_tweet', __( 'Refusing to post an empty tweet.', 'social-auto-post' ), false );
		}

		$token = SAP_X_OAuth::instance()->access_token();
		if ( is_wp_error( $token ) ) {
			// A missing or unrefreshable token needs a human, not a retry.
			return self::error( $token->get_error_code(), $token->get_error_message(), false );
		}

		$response = wp_remote_post(
			SAP_X_OAuth::api_base() . '/tweets',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( array( 'text' => $text ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Transport failure: the request may never have reached X.
			return self::error( 'sap_http_failure', $response->get_error_message(), true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( 201 === $status || 200 === $status ) {
			if ( ! is_array( $json ) || empty( $json['data']['id'] ) ) {
				return self::error(
					'sap_unexpected_response',
					__( 'X accepted the post but returned no tweet id.', 'social-auto-post' ),
					false
				);
			}
			$id = (string) $json['data']['id'];
			return array(
				'id'  => $id,
				'url' => self::tweet_url( $id ),
			);
		}

		return self::classify( $status, $json, $raw );
	}

	/**
	 * Turn an HTTP failure into a WP_Error that says whether to try again.
	 *
	 * @param int    $status HTTP status.
	 * @param mixed  $json   Decoded body, if it was JSON.
	 * @param string $raw    Raw body.
	 * @return WP_Error
	 */
	private static function classify( $status, $json, $raw ) {
		$detail = self::detail( $json, $raw );

		// X returns 403 for a duplicate tweet. Retrying only repeats the
		// rejection, and the post genuinely is already out there.
		if ( 403 === $status && false !== stripos( $detail, 'duplicate' ) ) {
			return self::error(
				'sap_duplicate',
				__( 'X rejected this as a duplicate of a recent post.', 'social-auto-post' ),
				false
			);
		}

		if ( 429 === $status ) {
			return self::error(
				'sap_rate_limited',
				__( 'X rate limit reached. This will be retried.', 'social-auto-post' )
					. ' ' . $detail,
				true
			);
		}

		if ( 401 === $status ) {
			return self::error(
				'sap_unauthorized',
				__( 'X rejected the credentials. Reconnect the account in Social Auto Post settings.', 'social-auto-post' ),
				false
			);
		}

		if ( 403 === $status ) {
			return self::error(
				'sap_forbidden',
				sprintf(
					/* translators: %s: detail returned by X */
					__( 'X refused the post: %s', 'social-auto-post' ),
					$detail
				),
				false
			);
		}

		// 5xx and anything unclassified: assume the far side, and try again.
		return self::error(
			'sap_x_error',
			sprintf(
				/* translators: 1: HTTP status, 2: detail returned by X */
				__( 'X returned HTTP %1$d: %2$s', 'social-auto-post' ),
				$status,
				$detail
			),
			$status >= 500 || 408 === $status
		);
	}

	/**
	 * Pull the most useful message out of an X error body.
	 *
	 * @param mixed  $json Decoded body.
	 * @param string $raw  Raw body.
	 * @return string
	 */
	private static function detail( $json, $raw ) {
		if ( is_array( $json ) ) {
			if ( ! empty( $json['detail'] ) ) {
				return (string) $json['detail'];
			}
			if ( ! empty( $json['title'] ) ) {
				return (string) $json['title'];
			}
			if ( ! empty( $json['errors'][0]['message'] ) ) {
				return (string) $json['errors'][0]['message'];
			}
		}
		return trim( substr( $raw, 0, 300 ) );
	}

	/**
	 * Public URL of a tweet, using the connected handle when it is known.
	 *
	 * @param string $id Tweet id.
	 * @return string
	 */
	public static function tweet_url( $id ) {
		$token    = SAP_Settings::token();
		$username = ( null !== $token && '' !== $token['username'] ) ? $token['username'] : 'i';
		return sprintf( 'https://x.com/%s/status/%s', $username, $id );
	}

	/**
	 * Build a WP_Error carrying the retry decision.
	 *
	 * @param string $code      Error code.
	 * @param string $message   Message.
	 * @param bool   $retryable Whether another attempt could succeed.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $retryable ) {
		return new WP_Error( $code, $message, array( 'retryable' => (bool) $retryable ) );
	}

	/**
	 * Read the retry flag off an error.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function is_retryable( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['retryable'] );
	}
}
