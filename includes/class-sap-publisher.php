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
		add_action( 'admin_post_sap_backfill', array( $this, 'handle_backfill' ) );

		// Bulk and row actions, on each post type the plugin is set to share.
		foreach ( (array) SAP_Settings::get( 'post_types', array( 'post' ) ) as $type ) {
			add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
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

	/* ----------------------------------------------------------- backfill */

	/**
	 * Published posts that have never been shared.
	 *
	 * This is what makes the plugin useful on a site that already has a back
	 * catalogue: posts published before it was installed never saw a
	 * `publish` transition, so nothing else would ever find them.
	 *
	 * @param int $limit Maximum to return; -1 for all.
	 * @return int[] Post ids, oldest first.
	 */
	public function find_unshared( $limit = -1 ) {
		$query = new WP_Query(
			array(
				'post_type'              => (array) SAP_Settings::get( 'post_types', array( 'post' ) ),
				'post_status'            => 'publish',
				'posts_per_page'         => (int) $limit,
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// Never shared, and not opted out by the author.
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_TWEET_ID,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_SKIP,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * How many published posts are waiting to be shared.
	 *
	 * @return int
	 */
	public function count_unshared() {
		return count( $this->find_unshared( -1 ) );
	}

	/**
	 * Queue a batch of posts, spaced out over time.
	 *
	 * Spacing is the point. Firing fifty tweets in one minute is the fastest
	 * way to trip X's rate limiter and look like a bot; spreading them out
	 * costs nothing and keeps the account healthy.
	 *
	 * @param int[] $post_ids Posts to queue.
	 * @param int   $spacing  Seconds between posts; null uses the setting.
	 * @return array{queued:int,already:int,ineligible:int,last:int}
	 */
	public function queue_many( array $post_ids, $spacing = null ) {
		if ( null === $spacing ) {
			$spacing = (int) SAP_Settings::get( 'bulk_interval', 10 ) * MINUTE_IN_SECONDS;
		}
		$spacing = max( 0, (int) $spacing );

		$result = array(
			'queued'     => 0,
			'already'    => 0,
			'ineligible' => 0,
			'last'       => 0,
		);
		$slot = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				$result['ineligible']++;
				continue;
			}

			if ( $this->already_shared( $post_id ) ) {
				$result['already']++;
				continue;
			}

			// Queueing the same post twice would put two cron rows on it. The
			// second would be harmless — process() re-checks before posting —
			// but a queue that lies about its own length is worth avoiding.
			if ( wp_next_scheduled( self::RETRY_HOOK, array( $post_id ) ) ) {
				$result['already']++;
				continue;
			}

			$reason = $this->ineligible_reason( $post );
			if ( null !== $reason ) {
				update_post_meta( $post_id, self::META_STATUS, 'skipped' );
				update_post_meta( $post_id, self::META_ERROR, $reason );
				$result['ineligible']++;
				continue;
			}

			$delay = $slot * $spacing;
			update_post_meta( $post_id, self::META_STATUS, 'pending' );
			update_post_meta( $post_id, self::META_ATTEMPTS, 0 );
			delete_post_meta( $post_id, self::META_ERROR );
			$this->schedule( $post_id, $delay );

			$result['queued']++;
			$result['last'] = time() + $delay;
			$slot++;
		}

		if ( $result['queued'] > 0 ) {
			SAP_Logger::log(
				'info',
				sprintf(
					/* translators: 1: number of posts, 2: minutes between posts */
					__( 'Queued %1$d existing posts to share, %2$d minutes apart.', 'social-auto-post' ),
					$result['queued'],
					(int) round( $spacing / MINUTE_IN_SECONDS )
				)
			);
		}

		return $result;
	}

	/**
	 * "Share existing posts" from the Backfill tab.
	 *
	 * @return void
	 */
	public function handle_backfill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-auto-post' ), 403 );
		}
		check_admin_referer( 'sap_backfill' );

		$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 0;
		$ids   = $this->find_unshared( $limit > 0 ? $limit : -1 );
		$result = $this->queue_many( $ids );

		wp_safe_redirect(
			add_query_arg(
				array(
					'sap_notice'  => 'queued',
					'sap_queued'  => (int) $result['queued'],
					'sap_skipped' => (int) ( $result['already'] + $result['ineligible'] ),
				),
				SAP_Admin::page_url( 'backfill' )
			)
		);
		exit;
	}

	/* ------------------------------------------------- bulk and row actions */

	/**
	 * Add "Share to X" to the posts list bulk menu.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function bulk_action( $actions ) {
		$actions['sap_share'] = __( 'Share to X', 'social-auto-post' );
		return $actions;
	}

	/**
	 * Run the bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action name.
	 * @param array  $post_ids Selected post ids.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'sap_share' !== $action ) {
			return $redirect;
		}

		$allowed = array();
		foreach ( (array) $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', (int) $post_id ) ) {
				$allowed[] = (int) $post_id;
			}
		}

		$result = $this->queue_many( $allowed );

		return add_query_arg(
			array(
				'sap_queued'  => (int) $result['queued'],
				'sap_skipped' => (int) ( $result['already'] + $result['ineligible'] ),
			),
			$redirect
		);
	}

	/**
	 * Add a "Share to X" link under each unshared published post.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    The post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return $actions;
		}
		if ( ! in_array( $post->post_type, (array) SAP_Settings::get( 'post_types', array( 'post' ) ), true ) ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		if ( $this->already_shared( $post->ID ) ) {
			$url = (string) get_post_meta( $post->ID, self::META_TWEET_URL, true );
			if ( '' !== $url ) {
				$actions['sap_view'] = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $url ),
					esc_html__( 'View on X', 'social-auto-post' )
				);
			}
			return $actions;
		}

		$actions['sap_share'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=sap_share_now&post=' . $post->ID ),
					'sap_share_now_' . $post->ID
				)
			),
			esc_html__( 'Share to X', 'social-auto-post' )
		);

		return $actions;
	}

	/**
	 * Report the result of a bulk share on the posts list.
	 *
	 * @return void
	 */
	public function bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['sap_queued'] ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$queued  = (int) $_GET['sap_queued'];
		$skipped = isset( $_GET['sap_skipped'] ) ? (int) $_GET['sap_skipped'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of posts queued */
			_n( '%d post queued to share on X.', '%d posts queued to share on X.', $queued, 'social-auto-post' ),
			$queued
		);

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of posts skipped */
				_n( '%d was skipped (already shared, or excluded by your rules).', '%d were skipped (already shared, or excluded by your rules).', $skipped, 'social-auto-post' ),
				$skipped
			);
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $queued > 0 ? 'success' : 'info' ),
			esc_html( $message )
		);
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
