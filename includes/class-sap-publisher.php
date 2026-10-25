<?php
/**
 * Decides what gets shared, and drives the attempt/retry cycle.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Watches for newly published posts and pushes them to X.
 */
class SAP_Publisher {

	/** Cron hook used for both the first attempt and every retry. */
	const RETRY_HOOK = 'sap_publish_post';

	/* Post meta. Leading underscore keeps these out of the custom fields UI. */
	const META_TWEET_ID  = '_sap_x_tweet_id';
	const META_TWEET_URL = '_sap_x_tweet_url';
	const META_POSTED_AT = '_sap_x_posted_at';
	const META_STATUS    = '_sap_x_status';
	const META_ERROR     = '_sap_x_error';
	const META_ATTEMPTS  = '_sap_x_attempts';
	const META_SKIP      = '_sap_skip';
	const META_CUSTOM    = '_sap_custom_text';

	/**
	 * Minutes to wait before each retry. The last value repeats if a site
	 * raises max_attempts beyond this list.
	 *
	 * @var int[]
	 */
	private static $backoff = array( 2, 10, 30, 120 );

	/**
	 * Singleton.
	 *
	 * @var SAP_Publisher|null
	 */
	private static $instance = null;

	/**
	 * Accessor.
	 *
	 * @return SAP_Publisher
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( self::RETRY_HOOK, array( $this, 'process' ), 10, 1 );
		add_action( 'admin_post_sap_share_now', array( $this, 'handle_share_now' ) );
	}

	/* ------------------------------------------------------------- trigger */

	/**
	 * Catch a post becoming published.
	 *
	 * `transition_post_status` is the right hook precisely because it can see
	 * the old status: `publish -> publish` is an edit of something already
	 * live, and must not produce a second tweet. Hooks like `publish_post`
	 * cannot tell the two apart.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$reason = $this->ineligible_reason( $post );
		if ( null !== $reason ) {
			update_post_meta( $post->ID, self::META_STATUS, 'skipped' );
			update_post_meta( $post->ID, self::META_ERROR, $reason );
			return;
		}

		update_post_meta( $post->ID, self::META_STATUS, 'pending' );
		delete_post_meta( $post->ID, self::META_ERROR );

		$delay = (int) SAP_Settings::get( 'delay_minutes', 0 ) * MINUTE_IN_SECONDS;
		$this->schedule( $post->ID, $delay );
	}

	/**
	 * Queue an attempt.
	 *
	 * The work runs on cron rather than inline so that publishing a post never
	 * waits on X — a slow API should not make the editor hang, and a failure
	 * should never be able to block the publish itself.
	 *
	 * @param int $post_id Post id.
	 * @param int $delay   Seconds from now.
	 * @return void
	 */
	private function schedule( $post_id, $delay = 0 ) {
		$when = time() + max( 0, (int) $delay );
		wp_schedule_single_event( $when, self::RETRY_HOOK, array( (int) $post_id ) );
	}

	/* ------------------------------------------------------- eligibility */

	/**
	 * Why this post should not be shared, or null if it should.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public function ineligible_reason( $post ) {
		if ( ! SAP_Settings::get( 'enabled' ) ) {
			return __( 'Auto-posting is turned off.', 'social-auto-post' );
		}

		if ( '1' === (string) get_post_meta( $post->ID, self::META_SKIP, true ) ) {
			return __( 'Skipped: "Do not share this post" is ticked.', 'social-auto-post' );
		}

		if ( $this->already_shared( $post->ID ) ) {
			return __( 'Already shared to X.', 'social-auto-post' );
		}

		$types = (array) SAP_Settings::get( 'post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return sprintf(
				/* translators: %s: post type name */
				__( 'Post type "%s" is not enabled for sharing.', 'social-auto-post' ),
				$post->post_type
			);
		}

		if ( '' !== (string) $post->post_password ) {
			return __( 'Password-protected posts are not shared.', 'social-auto-post' );
		}

		$reason = $this->category_reason( $post );
		if ( null !== $reason ) {
			return $reason;
		}

		if ( ! SAP_Settings::is_connected() ) {
			return __( 'No X account is connected.', 'social-auto-post' );
		}

		/**
		 * Filter the eligibility decision.
		 *
		 * Return a string to block sharing with that reason, or null to allow.
		 *
		 * @param string|null $reason Current decision.
		 * @param WP_Post     $post   The post.
		 */
		return apply_filters( 'sap_ineligible_reason', null, $post );
	}

	/**
	 * Apply the include/exclude category rules.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	private function category_reason( $post ) {
		$include = array_map( 'intval', (array) SAP_Settings::get( 'categories', array() ) );
		$exclude = array_map( 'intval', (array) SAP_Settings::get( 'exclude_categories', array() ) );

		if ( empty( $include ) && empty( $exclude ) ) {
			return null;
		}

		$terms = wp_get_post_categories( $post->ID );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		$terms = array_map( 'intval', (array) $terms );

		if ( ! empty( $exclude ) && array_intersect( $terms, $exclude ) ) {
			return __( 'Post is in an excluded category.', 'social-auto-post' );
		}

		if ( ! empty( $include ) && ! array_intersect( $terms, $include ) ) {
			return __( 'Post is not in any of the selected categories.', 'social-auto-post' );
		}

		return null;
	}

	/**
	 * Whether a tweet id is already recorded for this post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function already_shared( $post_id ) {
		return '' !== (string) get_post_meta( $post_id, self::META_TWEET_ID, true );
	}

	/* -------------------------------------------------------- the attempt */

	/**
	 * Run one publish attempt for a post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function process( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( $this->already_shared( $post_id ) ) {
			return;
		}

		$reason = $this->ineligible_reason( $post );
		if ( null !== $reason ) {
			update_post_meta( $post_id, self::META_STATUS, 'skipped' );
			update_post_meta( $post_id, self::META_ERROR, $reason );
			return;
		}

		if ( ! $this->lock( $post_id ) ) {
			// Another worker holds this post. Dropping out is correct: whoever
			// holds the lock will finish, or its expiry will free a retry.
			return;
		}

		try {
			$this->attempt( $post );
		} finally {
			$this->unlock( $post_id );
		}
	}

	/**
	 * Compose, send, and record the outcome.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private function attempt( $post ) {
		$attempts = (int) get_post_meta( $post->ID, self::META_ATTEMPTS, true ) + 1;
		update_post_meta( $post->ID, self::META_ATTEMPTS, $attempts );

		$custom = (string) get_post_meta( $post->ID, self::META_CUSTOM, true );
		$text   = ( '' !== trim( $custom ) )
			? SAP_Template::fit( strtr( $custom, array( '{url}' => get_permalink( $post ) ) ) )
			: SAP_Template::compose( $post );

		$client = new SAP_X_Client();
		$result = $client->post_tweet( $text );

		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $post->ID, self::META_TWEET_ID, $result['id'] );
			update_post_meta( $post->ID, self::META_TWEET_URL, $result['url'] );
			update_post_meta( $post->ID, self::META_POSTED_AT, time() );
			update_post_meta( $post->ID, self::META_STATUS, 'published' );
			delete_post_meta( $post->ID, self::META_ERROR );

			SAP_Settings::record_post();
			SAP_Logger::log(
				'success',
				sprintf(
					/* translators: %s: post title */
					__( 'Shared "%s" to X.', 'social-auto-post' ),
					get_the_title( $post )
				),
				array(
					'post_id' => $post->ID,
					'url'     => $result['url'],
				)
			);

			/**
			 * Fires after a post has been shared.
			 *
			 * @param int     $post_id Post id.
			 * @param array   $result  Tweet id and url.
			 * @param WP_Post $post    The post.
			 */
			do_action( 'sap_post_shared', $post->ID, $result, $post );
			return;
		}

		$message     = $result->get_error_message();
		$retryable   = SAP_X_Client::is_retryable( $result );
		$max         = max( 1, (int) SAP_Settings::get( 'max_attempts', 4 ) );
		$can_retry   = $retryable && $attempts < $max;

		update_post_meta( $post->ID, self::META_ERROR, $message );
		update_post_meta( $post->ID, self::META_STATUS, $can_retry ? 'pending' : 'failed' );

		if ( $can_retry ) {
			$index = min( $attempts - 1, count( self::$backoff ) - 1 );
			$delay = self::$backoff[ $index ] * MINUTE_IN_SECONDS;
			$this->schedule( $post->ID, $delay );

			SAP_Logger::log(
				'warning',
				sprintf(
					/* translators: 1: post title, 2: error, 3: minutes until retry */
					__( 'Could not share "%1$s": %2$s Retrying in %3$d minutes.', 'social-auto-post' ),
					get_the_title( $post ),
					$message,
					self::$backoff[ $index ]
				),
				array( 'post_id' => $post->ID )
			);
			return;
		}

		SAP_Logger::log(
			'error',
			sprintf(
				/* translators: 1: post title, 2: error message */
				__( 'Gave up sharing "%1$s": %2$s', 'social-auto-post' ),
				get_the_title( $post ),
				$message
			),
			array( 'post_id' => $post->ID )
		);

		/**
		 * Fires when a post will not be retried again.
		 *
		 * @param int    $post_id Post id.
		 * @param string $message Final error.
		 */
		do_action( 'sap_post_failed', $post->ID, $message );
	}

	/* ------------------------------------------------------------ manual */

	/**
	 * "Share now" from the post editor or the posts list.
	 *
	 * @return void
	 */
	public function handle_share_now() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-auto-post' ), 403 );
		}
		check_admin_referer( 'sap_share_now_' . $post_id );

		// A manual share is an explicit instruction, so clear the one-shot
		// blockers an earlier run may have set — but never the tweet id, which
		// is what stops a genuine double-post.
		delete_post_meta( $post_id, self::META_ERROR );
		update_post_meta( $post_id, self::META_ATTEMPTS, 0 );

		$this->process( $post_id );

		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		wp_safe_redirect(
			add_query_arg(
				array( 'sap_shared' => $status ),
				get_edit_post_link( $post_id, 'raw' )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------- locking */

	/**
	 * Take an exclusive lock on a post.
	 *
	 * `add_option` is atomic — the options table has a unique index on the
	 * name — so exactly one caller can create the row. Two cron runs firing at
	 * once therefore cannot both reach the X API for the same post.
	 *
	 * @param int $post_id Post id.
	 * @return bool Whether the lock was acquired.
	 */
	private function lock( $post_id ) {
		$key = 'sap_lock_' . (int) $post_id;

		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}

		// Steal a lock left behind by a crashed or timed-out run.
		$held = (int) get_option( $key, 0 );
		if ( $held > 0 && ( time() - $held ) > 5 * MINUTE_IN_SECONDS ) {
			update_option( $key, time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function unlock( $post_id ) {
		delete_option( 'sap_lock_' . (int) $post_id );
	}
}
