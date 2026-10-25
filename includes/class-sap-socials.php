<?php
/**
 * The "Socials" block rendered on a post.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists the post's own tweet alongside any standing profile links.
 *
 * Placement is a setting rather than a theme edit, so moving the block is a
 * dropdown rather than a child-theme change. `[sap_socials]` is always
 * available for themes that want to place it exactly.
 */
class SAP_Socials {

	const SHORTCODE = 'sap_socials';

	/**
	 * Singleton.
	 *
	 * @var SAP_Socials|null
	 */
	private static $instance = null;

	/**
	 * Guards against the block rendering twice on one post.
	 *
	 * @var int[]
	 */
	private $rendered = array();

	/**
	 * Accessor.
	 *
	 * @return SAP_Socials
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
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		// Priority 20 puts the block after most content filters have run, so
		// it is not fed back through wpautop or shortcode parsing.
		add_filter( 'the_content', array( $this, 'maybe_append' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Load the block's stylesheet, on single posts only.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! is_singular() || ! SAP_Settings::get( 'socials_enabled', true ) ) {
			return;
		}
		wp_enqueue_style( 'sap-socials', SAP_URL . 'assets/socials.css', array(), SAP_VERSION );
	}

	/**
	 * `[sap_socials]` handler.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'post_id' => 0 ), (array) $atts, self::SHORTCODE );
		$post_id = (int) $atts['post_id'] ? (int) $atts['post_id'] : get_the_ID();

		return $post_id ? $this->render( $post_id ) : '';
	}

	/**
	 * Add the block to post content when the position setting asks for it.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_append( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$position = (string) SAP_Settings::get( 'socials_position', 'after_content' );
		if ( 'manual' === $position ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || in_array( (int) $post_id, $this->rendered, true ) ) {
			return $content;
		}

		$block = $this->render( (int) $post_id );
		if ( '' === $block ) {
			return $content;
		}

		$this->rendered[] = (int) $post_id;

		return ( 'before_content' === $position )
			? $block . $content
			: $content . $block;
	}

	/**
	 * Build the block's HTML.
	 *
	 * @param int $post_id Post id.
	 * @return string Empty string when there is nothing to show.
	 */
	public function render( $post_id ) {
		if ( ! SAP_Settings::get( 'socials_enabled', true ) ) {
			return '';
		}

		$items = $this->items( $post_id );
		if ( empty( $items ) ) {
			return '';
		}

		$title = (string) SAP_Settings::get( 'socials_title', 'Socials' );

		$html = '<div class="sap-socials">';
		if ( '' !== trim( $title ) ) {
			$html .= '<h3 class="sap-socials__title">' . esc_html( $title ) . '</h3>';
		}
		$html .= '<ul class="sap-socials__list">';

		foreach ( $items as $item ) {
			$html .= sprintf(
				'<li class="sap-socials__item sap-socials__item--%1$s"><span class="sap-socials__label">%2$s:</span> <a class="sap-socials__link" href="%3$s" target="_blank" rel="noopener noreferrer nofollow">%4$s</a></li>',
				esc_attr( $item['slug'] ),
				esc_html( $item['label'] ),
				esc_url( $item['url'] ),
				esc_html( $item['text'] )
			);
		}

		$html .= '</ul></div>';

		/**
		 * Filter the rendered Socials block.
		 *
		 * @param string $html    Markup.
		 * @param int    $post_id Post id.
		 * @param array  $items   Items that were rendered.
		 */
		return (string) apply_filters( 'sap_socials_html', $html, $post_id, $items );
	}

	/**
	 * The rows to render: this post's tweet, then the standing profiles.
	 *
	 * @param int $post_id Post id.
	 * @return array<array{slug:string,label:string,url:string,text:string}>
	 */
	private function items( $post_id ) {
		$items = array();

		if ( SAP_Settings::get( 'socials_show_x', true ) ) {
			$url = (string) get_post_meta( $post_id, SAP_Publisher::META_TWEET_URL, true );
			if ( '' !== $url ) {
				$token  = SAP_Settings::token();
				$handle = ( null !== $token && '' !== $token['username'] ) ? '@' . $token['username'] : __( 'View post', 'social-auto-post' );

				$items[] = array(
					'slug'  => 'x',
					'label' => __( 'X', 'social-auto-post' ),
					'url'   => $url,
					'text'  => $handle,
				);
			}
		}

		foreach ( (array) SAP_Settings::get( 'profiles', array() ) as $profile ) {
			if ( empty( $profile['label'] ) || empty( $profile['url'] ) ) {
				continue;
			}
			$label   = (string) $profile['label'];
			$items[] = array(
				'slug'  => sanitize_title( $label ),
				'label' => $label,
				'url'   => (string) $profile['url'],
				'text'  => ! empty( $profile['handle'] ) ? (string) $profile['handle'] : (string) $profile['url'],
			);
		}

		/**
		 * Filter the Socials rows before rendering.
		 *
		 * @param array $items   Rows.
		 * @param int   $post_id Post id.
		 */
		return (array) apply_filters( 'sap_socials_items', $items, $post_id );
	}
}
