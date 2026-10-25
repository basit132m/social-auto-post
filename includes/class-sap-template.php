<?php
/**
 * Turns a WordPress post into the text of a tweet.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template rendering plus X's own length rules.
 */
class SAP_Template {

	/** Maximum weighted length of a tweet. */
	const MAX_WEIGHTED = 280;

	/**
	 * Every URL costs exactly this much, however long it is: X rewrites links
	 * through t.co before counting. A 200-character permalink and a 20-character
	 * one are the same price, so there is no reason to shorten URLs ourselves.
	 */
	const URL_WEIGHT = 23;

	/**
	 * Codepoint ranges that cost 1 instead of 2.
	 *
	 * X weights every character 2 by default and discounts these ranges to 1 —
	 * which is Latin, plus a handful of punctuation blocks. The upshot is that
	 * an English post gets ~280 characters and a Japanese one gets ~140.
	 *
	 * @var array<array{int,int}>
	 */
	private static $light_ranges = array(
		array( 0, 4351 ),
		array( 8192, 8205 ),
		array( 8208, 8223 ),
		array( 8242, 8247 ),
	);

	/**
	 * The placeholders a template may use, with a short description for the UI.
	 *
	 * @return array<string,string>
	 */
	public static function tokens() {
		return array(
			'{title}'      => __( 'The post title', 'social-auto-post' ),
			'{url}'        => __( 'The post permalink', 'social-auto-post' ),
			'{excerpt}'    => __( 'The post excerpt, or the opening of the content', 'social-auto-post' ),
			'{author}'     => __( 'The post author display name', 'social-auto-post' ),
			'{site}'       => __( 'The site title', 'social-auto-post' ),
			'{categories}' => __( 'Category names, comma separated', 'social-auto-post' ),
			'{hashtags}'   => __( 'The hashtags configured in settings', 'social-auto-post' ),
		);
	}

	/**
	 * Render the tweet text for a post, already fitted to X's limit.
	 *
	 * @param WP_Post $post     The post being shared.
	 * @param string  $template Template with {tokens}; falls back to the setting.
	 * @return string
	 */
	public static function compose( $post, $template = null ) {
		if ( null === $template ) {
			$template = (string) SAP_Settings::get( 'template' );
		}

		$text = strtr( $template, self::values( $post ) );

		// Collapse the blank lines left behind by tokens that resolved to ''.
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		$text = trim( (string) $text );

		/**
		 * Filter the composed text before it is fitted to the length limit.
		 *
		 * @param string  $text The rendered tweet.
		 * @param WP_Post $post The post being shared.
		 */
		$text = (string) apply_filters( 'sap_tweet_text', $text, $post );

		return self::fit( $text );
	}

	/**
	 * Resolve every token for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,string>
	 */
	private static function values( $post ) {
		$hashtags = self::normalize_hashtags( (string) SAP_Settings::get( 'hashtags', '' ) );

		$categories = array();
		foreach ( (array) get_the_category( $post->ID ) as $category ) {
			$categories[] = $category->name;
		}

		return array(
			'{title}'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'{url}'        => get_permalink( $post ),
			'{excerpt}'    => self::excerpt( $post ),
			'{author}'     => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'{site}'       => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'{categories}' => implode( ', ', $categories ),
			'{hashtags}'   => $hashtags,
		);
	}

	/**
	 * A plain-text excerpt: the hand-written one if present, else the opening
	 * of the content with shortcodes and markup stripped.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function excerpt( $post ) {
		$raw = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
		$raw = strip_shortcodes( (string) $raw );
		$raw = wp_strip_all_tags( $raw );
		$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
		$raw = preg_replace( '/\s+/u', ' ', $raw );

		return trim( (string) $raw );
	}

	/**
	 * Normalise a comma or space separated hashtag list into "#a #b".
	 *
	 * @param string $raw Raw setting value.
	 * @return string
	 */
	public static function normalize_hashtags( $raw ) {
		$parts = preg_split( '/[\s,]+/u', trim( $raw ) );
		$tags  = array();

		foreach ( (array) $parts as $part ) {
			$part = ltrim( (string) $part, '#' );
			$part = preg_replace( '/[^\p{L}\p{N}_]/u', '', $part );
			if ( '' !== $part ) {
				$tags[] = '#' . $part;
			}
		}

		return implode( ' ', array_unique( $tags ) );
	}

	/**
	 * X's weighted length of a string.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function weighted_length( $text ) {
		$weight = 0;

		foreach ( self::split_on_urls( $text ) as $chunk ) {
			if ( $chunk['is_url'] ) {
				$weight += self::URL_WEIGHT;
				continue;
			}
			$codepoints = self::codepoints( $chunk['text'] );
			foreach ( $codepoints as $cp ) {
				$weight += self::is_light( $cp ) ? 1 : 2;
			}
		}

		return $weight;
	}

	/**
	 * Trim `$text` until it fits, cutting prose rather than links.
	 *
	 * A truncated URL is a broken URL, and the whole purpose of the tweet is to
	 * carry the link, so any trailing URL (and the hashtags after it) is held
	 * back and the body is what gives way.
	 *
	 * @param string $text Composed text.
	 * @return string
	 */
	public static function fit( $text ) {
		$text = trim( $text );
		if ( self::weighted_length( $text ) <= self::MAX_WEIGHTED ) {
			return $text;
		}

		// Split off a trailing "<url> #tags" tail, if there is one.
		$tail = '';
		$body = $text;
		if ( preg_match( '/(https?:\/\/\S+(?:\s+#[\p{L}\p{N}_]+)*)\s*$/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$offset = (int) $m[0][1];
			$tail   = trim( (string) $m[0][0] );
			$body   = rtrim( substr( $text, 0, $offset ) );
		}

		$separator = ( '' === $tail ) ? '' : "\n\n";
		$ellipsis  = "\u{2026}";
		$budget    = self::MAX_WEIGHTED
			- self::weighted_length( $tail . $separator )
			// U+2026 sits outside X's discounted ranges, so it costs 2, not 1.
			- self::weighted_length( $ellipsis );

		if ( $budget < 20 ) {
			// Nothing meaningful would survive; keep the link and drop the prose.
			return '' === $tail ? self::hard_truncate( $text, self::MAX_WEIGHTED ) : $tail;
		}

		$body = self::hard_truncate( $body, $budget );
		$body = preg_replace( '/\s+\S*$/u', '', $body ); // don't cut mid-word
		$body = rtrim( (string) $body, " \t\n\r\0\x0B.,;:!?-" );

		if ( '' === $body ) {
			return '' === $tail ? self::hard_truncate( $text, self::MAX_WEIGHTED ) : $tail;
		}

		return $body . $ellipsis . $separator . $tail;
	}

	/**
	 * Cut a string to a weighted budget, codepoint by codepoint.
	 *
	 * @param string $text   Text.
	 * @param int    $budget Weighted budget.
	 * @return string
	 */
	private static function hard_truncate( $text, $budget ) {
		$out  = '';
		$used = 0;

		foreach ( self::split_on_urls( $text ) as $chunk ) {
			if ( $chunk['is_url'] ) {
				if ( $used + self::URL_WEIGHT > $budget ) {
					return $out;
				}
				$used += self::URL_WEIGHT;
				$out  .= $chunk['text'];
				continue;
			}
			$chars = preg_split( '//u', $chunk['text'], -1, PREG_SPLIT_NO_EMPTY );
			foreach ( (array) $chars as $char ) {
				$cp   = self::codepoints( $char );
				$cost = ( isset( $cp[0] ) && self::is_light( $cp[0] ) ) ? 1 : 2;
				if ( $used + $cost > $budget ) {
					return $out;
				}
				$used += $cost;
				$out  .= $char;
			}
		}

		return $out;
	}

	/**
	 * Break text into alternating plain and URL chunks.
	 *
	 * @param string $text Text.
	 * @return array<array{text:string,is_url:bool}>
	 */
	private static function split_on_urls( $text ) {
		$parts  = preg_split(
			'/(https?:\/\/[^\s]+)/u',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);
		$chunks = array();

		foreach ( (array) $parts as $part ) {
			$chunks[] = array(
				'text'   => $part,
				'is_url' => (bool) preg_match( '/^https?:\/\//', $part ),
			);
		}

		return $chunks;
	}

	/**
	 * Unicode codepoints of a string.
	 *
	 * @param string $text Text.
	 * @return int[]
	 */
	private static function codepoints( $text ) {
		if ( '' === $text ) {
			return array();
		}
		$out = unpack( 'N*', (string) mb_convert_encoding( $text, 'UTF-32BE', 'UTF-8' ) );
		return ( false === $out ) ? array() : array_values( $out );
	}

	/**
	 * Whether a codepoint costs 1 rather than 2.
	 *
	 * @param int $cp Codepoint.
	 * @return bool
	 */
	private static function is_light( $cp ) {
		foreach ( self::$light_ranges as $range ) {
			if ( $cp >= $range[0] && $cp <= $range[1] ) {
				return true;
			}
		}
		return false;
	}
}
