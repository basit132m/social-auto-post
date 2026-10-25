<?php
/**
 * OAuth 2.0 Authorization Code + PKCE against X.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connects, refreshes and disconnects the admin's X account.
 *
 * X requires PKCE even for confidential clients, and rotates the refresh token
 * on every refresh — lose the new one and the connection is dead until the
 * admin reconnects by hand, so the save path never overwrites a good refresh
 * token with an empty one.
 */
class SAP_X_OAuth {

	const SCOPES         = 'tweet.read tweet.write users.read offline.access';
	const STATE_TRANSIENT = 'sap_oauth_state_';
	const ACTION_CONNECT  = 'sap_x_connect';
	const ACTION_CALLBACK = 'sap_x_callback';
	const ACTION_DISCONNECT = 'sap_x_disconnect';

	/**
	 * Singleton.
	 *
	 * @var SAP_X_OAuth|null
	 */
	private static $instance = null;

	/**
	 * Accessor.
	 *
	 * @return SAP_X_OAuth
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the three admin-post endpoints.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
	}

	/* ------------------------------------------------------------ endpoints */

	/**
	 * Where the browser is sent to authorize.
	 *
	 * @return string
	 */
	public static function authorize_endpoint() {
		return (string) apply_filters( 'sap_x_authorize_endpoint', 'https://twitter.com/i/oauth2/authorize' );
	}

	/**
	 * Where codes and refresh tokens are exchanged.
	 *
	 * @return string
	 */
	public static function token_endpoint() {
		return (string) apply_filters( 'sap_x_token_endpoint', 'https://api.twitter.com/2/oauth2/token' );
	}

	/**
	 * Base for v2 API calls.
	 *
	 * @return string
	 */
	public static function api_base() {
		return (string) apply_filters( 'sap_x_api_base', 'https://api.twitter.com/2' );
	}

	/**
	 * The exact redirect URI. Must be registered verbatim in the X app, so the
	 * settings screen shows this string for copy-pasting rather than describing it.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
	}

	/* --------------------------------------------------------- connect flow */

	/**
	 * Start the flow: stash a PKCE verifier, bounce to X.
	 *
	 * @return void
	 */
	public function handle_connect() {
		$this->guard( self::ACTION_CONNECT );

		$app = SAP_Settings::app();
		if ( '' === $app['client_id'] ) {
			$this->bail( __( 'Add your X app Client ID and Secret first.', 'social-auto-post' ) );
		}

		$verifier  = self::base64url( random_bytes( 48 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		$state     = self::base64url( random_bytes( 24 ) );

		// Ten minutes is plenty for a human to approve, and short enough that a
		// stale verifier cannot sit around being replayable.
		set_transient( self::STATE_TRANSIENT . $state, $verifier, 10 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( $app['client_id'] ),
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'scope'                 => rawurlencode( self::SCOPES ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			self::authorize_endpoint()
		);

		wp_redirect( $url );
		exit;
	}

	/**
	 * Finish the flow: validate state, swap the code, record the account.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-auto-post' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- state is the CSRF token here.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			$description = isset( $_GET['error_description'] )
				? sanitize_text_field( wp_unslash( $_GET['error_description'] ) )
				: '';
			$this->bail( sprintf( '%s %s', $error, $description ) );
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $code || '' === $state ) {
			$this->bail( __( 'X did not return an authorization code.', 'social-auto-post' ) );
		}

		$verifier = get_transient( self::STATE_TRANSIENT . $state );
		if ( false === $verifier || ! is_string( $verifier ) ) {
			$this->bail( __( 'This authorization link has expired. Please try connecting again.', 'social-auto-post' ) );
		}
		// One-shot: a code must not be replayable.
		delete_transient( self::STATE_TRANSIENT . $state );

		$token = $this->exchange(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => $verifier,
			)
		);

		if ( is_wp_error( $token ) ) {
			SAP_Logger::log( 'error', 'Could not connect X account: ' . $token->get_error_message() );
			$this->bail( $token->get_error_message() );
		}

		SAP_Settings::save_token( $token );

		$identity = $this->fetch_identity( $token['access_token'] );
		if ( ! is_wp_error( $identity ) ) {
			SAP_Settings::save_token( $identity );
		}

		$username = is_wp_error( $identity ) ? '' : $identity['username'];
		SAP_Logger::log(
			'success',
			$username
				? sprintf( 'Connected X account @%s.', $username )
				: 'Connected X account.'
		);

		wp_safe_redirect( add_query_arg( 'sap_notice', 'connected', SAP_Admin::page_url() ) );
		exit;
	}

	/**
	 * Drop the stored token.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->guard( self::ACTION_DISCONNECT );
		SAP_Settings::clear_token();
		SAP_Logger::log( 'info', 'X account disconnected.' );
		wp_safe_redirect( add_query_arg( 'sap_notice', 'disconnected', SAP_Admin::page_url() ) );
		exit;
	}

	/* ----------------------------------------------------------- token use */

	/**
	 * A usable access token, refreshed if it is close to expiring.
	 *
	 * @return string|WP_Error
	 */
	public function access_token() {
		$token = SAP_Settings::token();
		if ( null === $token ) {
			return new WP_Error( 'sap_not_connected', __( 'No X account is connected.', 'social-auto-post' ) );
		}

		// Refresh a minute early: a token that expires mid-request is a failure
		// that looks like an outage.
		if ( $token['expires_at'] > ( time() + 60 ) ) {
			return $token['access_token'];
		}

		if ( '' === $token['refresh_token'] ) {
			return new WP_Error(
				'sap_token_expired',
				__( 'The X access token expired and no refresh token is stored. Please reconnect the account.', 'social-auto-post' )
			);
		}

		$refreshed = $this->exchange(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $token['refresh_token'],
			)
		);

		if ( is_wp_error( $refreshed ) ) {
			SAP_Logger::log( 'error', 'Token refresh failed: ' . $refreshed->get_error_message() );
			return $refreshed;
		}

		SAP_Settings::save_token( $refreshed );
		return $refreshed['access_token'];
	}

	/**
	 * POST to the token endpoint and normalise the response.
	 *
	 * @param array $body Grant-specific fields.
	 * @return array|WP_Error
	 */
	private function exchange( array $body ) {
		$app = SAP_Settings::app();
		if ( '' === $app['client_id'] || '' === $app['client_secret'] ) {
			return new WP_Error( 'sap_no_app', __( 'X app credentials are missing.', 'social-auto-post' ) );
		}

		$body['client_id'] = $app['client_id'];

		$response = wp_remote_post(
			self::token_endpoint(),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $app['client_id'] . ':' . $app['client_secret'] ),
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $json ) || empty( $json['access_token'] ) ) {
			$detail = '';
			if ( is_array( $json ) ) {
				$detail = isset( $json['error_description'] ) ? (string) $json['error_description']
					: ( isset( $json['error'] ) ? (string) $json['error'] : '' );
			}
			return new WP_Error(
				'sap_token_exchange_failed',
				sprintf(
					/* translators: 1: HTTP status, 2: error detail from X */
					__( 'X rejected the token request (HTTP %1$d). %2$s', 'social-auto-post' ),
					$status,
					$detail
				)
			);
		}

		$expires_in = isset( $json['expires_in'] ) ? (int) $json['expires_in'] : 7200;

		return array(
			'access_token'  => (string) $json['access_token'],
			'refresh_token' => isset( $json['refresh_token'] ) ? (string) $json['refresh_token'] : '',
			'expires_at'    => time() + $expires_in,
			'scope'         => isset( $json['scope'] ) ? (string) $json['scope'] : '',
		);
	}

	/**
	 * Look up the connected account's handle, used to build tweet URLs.
	 *
	 * @param string $access_token Bearer token.
	 * @return array|WP_Error
	 */
	private function fetch_identity( $access_token ) {
		$response = wp_remote_get(
			self::api_base() . '/users/me',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['data']['id'] ) ) {
			return new WP_Error( 'sap_identity_failed', __( 'Could not read the X account details.', 'social-auto-post' ) );
		}

		return array(
			'user_id'  => (string) $json['data']['id'],
			'username' => isset( $json['data']['username'] ) ? (string) $json['data']['username'] : '',
		);
	}

	/* ----------------------------------------------------------- utilities */

	/**
	 * Capability plus nonce check for a form action.
	 *
	 * @param string $action Action name, used as the nonce action.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-auto-post' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Send the admin back to the settings screen with an error to read.
	 *
	 * @param string $message Error text.
	 * @return void
	 */
	private function bail( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'sap_notice' => 'error',
					'sap_error'  => rawurlencode( $message ),
				),
				SAP_Admin::page_url()
			)
		);
		exit;
	}

	/**
	 * URL-safe base64 with no padding, as PKCE requires.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private static function base64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
