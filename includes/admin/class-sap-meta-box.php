<?php
/**
 * Per-post controls in the editor sidebar.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets an author override the automatic behaviour for one post, and shows
 * what actually happened to it.
 */
class SAP_Meta_Box {

	const NONCE = 'sap_meta_box';

	/**
	 * Singleton.
	 *
	 * @var SAP_Meta_Box|null
	 */
	private static $instance = null;

	/**
	 * Accessor.
	 *
	 * @return SAP_Meta_Box
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
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Add the box to every enabled post type.
	 *
	 * @return void
	 */
	public function add() {
		foreach ( (array) SAP_Settings::get( 'post_types', array( 'post' ) ) as $type ) {
			add_meta_box(
				'sap-meta-box',
				__( 'Share to X', 'social-auto-post' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$status    = (string) get_post_meta( $post->ID, SAP_Publisher::META_STATUS, true );
		$tweet_url = (string) get_post_meta( $post->ID, SAP_Publisher::META_TWEET_URL, true );
		$error     = (string) get_post_meta( $post->ID, SAP_Publisher::META_ERROR, true );
		$skip      = '1' === (string) get_post_meta( $post->ID, SAP_Publisher::META_SKIP, true );
		$custom    = (string) get_post_meta( $post->ID, SAP_Publisher::META_CUSTOM, true );

		echo '<div class="sap-box">';

		if ( '' !== $tweet_url ) {
			echo '<p class="sap-box__status sap-box__status--ok">' . esc_html__( 'Shared to X.', 'social-auto-post' ) . '</p>';
			printf(
				'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( $tweet_url ),
				esc_html__( 'View the post on X', 'social-auto-post' )
			);
		} elseif ( 'pending' === $status ) {
			echo '<p class="sap-box__status sap-box__status--pending">' . esc_html__( 'Queued — will be shared shortly.', 'social-auto-post' ) . '</p>';
		} elseif ( 'failed' === $status ) {
			echo '<p class="sap-box__status sap-box__status--error">' . esc_html__( 'Sharing failed.', 'social-auto-post' ) . '</p>';
		}

		if ( '' !== $error && '' === $tweet_url ) {
			echo '<p class="sap-box__error">' . esc_html( $error ) . '</p>';
		}

		printf(
			'<p><label><input type="checkbox" name="sap_skip" value="1" %1$s> %2$s</label></p>',
			checked( $skip, true, false ),
			esc_html__( 'Do not share this post', 'social-auto-post' )
		);

		printf(
			'<p><label for="sap_custom_text"><strong>%1$s</strong></label><textarea id="sap_custom_text" name="sap_custom_text" rows="4" class="widefat" placeholder="%2$s">%3$s</textarea><span class="description">%4$s</span></p>',
			esc_html__( 'Custom text', 'social-auto-post' ),
			esc_attr__( 'Leave empty to use the template', 'social-auto-post' ),
			esc_textarea( $custom ),
			esc_html__( 'Overrides the template for this post only. {url} still works.', 'social-auto-post' )
		);

		if ( 'publish' === $post->post_status && '' === $tweet_url ) {
			printf(
				'<p><a href="%1$s" class="button">%2$s</a></p>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=sap_share_now&post=' . $post->ID ),
						'sap_share_now_' . $post->ID
					)
				),
				esc_html__( 'Share now', 'social-auto-post' )
			);
		}

		if ( '' === $tweet_url ) {
			$preview = SAP_Template::compose( $post );
			printf(
				'<p class="sap-box__preview"><strong>%1$s</strong><br><span class="sap-box__preview-text">%2$s</span><br><span class="description">%3$s</span></p>',
				esc_html__( 'Preview', 'social-auto-post' ),
				nl2br( esc_html( $preview ) ),
				esc_html(
					sprintf(
						/* translators: 1: used characters, 2: limit */
						__( '%1$d / %2$d characters', 'social-auto-post' ),
						SAP_Template::weighted_length( $preview ),
						SAP_Template::MAX_WEIGHTED
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Persist the per-post overrides.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['sap_skip'] ) ) {
			update_post_meta( $post_id, SAP_Publisher::META_SKIP, '1' );
		} else {
			delete_post_meta( $post_id, SAP_Publisher::META_SKIP );
		}

		$custom = isset( $_POST['sap_custom_text'] )
			? sanitize_textarea_field( wp_unslash( $_POST['sap_custom_text'] ) )
			: '';

		if ( '' !== trim( $custom ) ) {
			update_post_meta( $post_id, SAP_Publisher::META_CUSTOM, $custom );
		} else {
			delete_post_meta( $post_id, SAP_Publisher::META_CUSTOM );
		}
	}
}
