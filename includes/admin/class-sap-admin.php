<?php
/**
 * Admin screens.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's settings pages, rendered as one screen with tabs.
 */
class SAP_Admin {

	const SLUG       = 'social-auto-post';
	const CAPABILITY = 'manage_options';

	/**
	 * Singleton.
	 *
	 * @var SAP_Admin|null
	 */
	private static $instance = null;

	/**
	 * Accessor.
	 *
	 * @return SAP_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * URL of the settings screen.
	 *
	 * @param string $tab Optional tab to open.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		return $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_sap_save_app', array( $this, 'save_app' ) );
		add_action( 'admin_post_sap_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_sap_clear_log', array( $this, 'clear_log' ) );
	}

	/**
	 * Add the menu entry.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'Social Auto Post', 'social-auto-post' ),
			__( 'Social Auto Post', 'social-auto-post' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-share',
			81
		);
	}

	/**
	 * Load the stylesheet on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'sap-admin', SAP_URL . 'assets/admin.css', array(), SAP_VERSION );
	}

	/* ----------------------------------------------------------- rendering */

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'social-auto-post' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		$tabs = array(
			'connection' => __( 'Connection', 'social-auto-post' ),
			'rules'      => __( 'Posting rules', 'social-auto-post' ),
			'socials'    => __( 'Socials section', 'social-auto-post' ),
			'log'        => __( 'Activity log', 'social-auto-post' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connection';
		}

		echo '<div class="wrap sap-wrap">';
		echo '<h1>' . esc_html__( 'Social Auto Post', 'social-auto-post' ) . '</h1>';

		$this->notices();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( self::page_url( $slug ) ),
				esc_attr( $tab === $slug ? 'nav-tab-active' : '' ),
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div class="sap-tab-body">';
		switch ( $tab ) {
			case 'rules':
				$this->tab_rules();
				break;
			case 'socials':
				$this->tab_socials();
				break;
			case 'log':
				$this->tab_log();
				break;
			default:
				$this->tab_connection();
		}
		echo '</div></div>';
	}

	/**
	 * Show the result of the last redirect, if any.
	 *
	 * @return void
	 */
	private function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['sap_notice'] ) ? sanitize_key( wp_unslash( $_GET['sap_notice'] ) ) : '';
		$error  = isset( $_GET['sap_error'] ) ? sanitize_text_field( wp_unslash( $_GET['sap_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice ) {
			return;
		}

		$map = array(
			'connected'    => array( 'success', __( 'Your X account is connected.', 'social-auto-post' ) ),
			'disconnected' => array( 'info', __( 'X account disconnected.', 'social-auto-post' ) ),
			'saved'        => array( 'success', __( 'Settings saved.', 'social-auto-post' ) ),
			'app_saved'    => array( 'success', __( 'App credentials saved. You can connect your account now.', 'social-auto-post' ) ),
			'log_cleared'  => array( 'info', __( 'Activity log cleared.', 'social-auto-post' ) ),
		);

		if ( 'error' === $notice ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( '' !== $error ? $error : __( 'Something went wrong.', 'social-auto-post' ) )
			);
			return;
		}

		if ( isset( $map[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $map[ $notice ][0] ),
				esc_html( $map[ $notice ][1] )
			);
		}
	}

	/* ------------------------------------------------------------ tab: app */

	/**
	 * Connection tab: app credentials and the connect button.
	 *
	 * @return void
	 */
	private function tab_connection() {
		$app       = SAP_Settings::app();
		$token     = SAP_Settings::token();
		$connected = null !== $token;
		$used      = SAP_Settings::usage_this_month();
		$cap       = SAP_Settings::FREE_TIER_MONTHLY_POSTS;

		echo '<div class="sap-card">';
		echo '<h2>' . esc_html__( 'Step 1 — Your X app', 'social-auto-post' ) . '</h2>';
		echo '<p>' . esc_html__( 'Create a free app at the X Developer Portal, enable OAuth 2.0, set the app type to "Web App", and give it Read and Write permissions. Then paste its Client ID and Client Secret here.', 'social-auto-post' ) . '</p>';
		echo '<p><a href="https://developer.x.com/en/portal/dashboard" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the X Developer Portal', 'social-auto-post' ) . '</a></p>';

		echo '<p class="sap-callout"><strong>' . esc_html__( 'Callback URL', 'social-auto-post' ) . '</strong><br>';
		echo esc_html__( 'Paste this exactly into your X app settings, as both the Callback URI and Website URL host:', 'social-auto-post' ) . '<br>';
		echo '<code class="sap-code">' . esc_html( SAP_X_OAuth::redirect_uri() ) . '</code></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sap_save_app' );
		echo '<input type="hidden" name="action" value="sap_save_app">';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="sap_client_id">%1$s</label></th><td><input type="text" class="regular-text" id="sap_client_id" name="client_id" value="%2$s" autocomplete="off"></td></tr>',
			esc_html__( 'Client ID', 'social-auto-post' ),
			esc_attr( $app['client_id'] )
		);
		printf(
			'<tr><th scope="row"><label for="sap_client_secret">%1$s</label></th><td><input type="password" class="regular-text" id="sap_client_secret" name="client_secret" value="%2$s" autocomplete="new-password"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Client Secret', 'social-auto-post' ),
			esc_attr( $app['client_secret'] ),
			esc_html__( 'Stored encrypted using your site\'s WordPress salts.', 'social-auto-post' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Save app credentials', 'social-auto-post' ) );
		echo '</form></div>';

		echo '<div class="sap-card">';
		echo '<h2>' . esc_html__( 'Step 2 — Connect your account', 'social-auto-post' ) . '</h2>';

		if ( ! SAP_Settings::has_app() ) {
			echo '<p class="sap-muted">' . esc_html__( 'Save your Client ID and Secret above first.', 'social-auto-post' ) . '</p>';
		} elseif ( $connected ) {
			printf(
				'<p class="sap-status sap-status--ok">%s <strong>%s</strong></p>',
				esc_html__( 'Connected as', 'social-auto-post' ),
				esc_html( '' !== $token['username'] ? '@' . $token['username'] : __( 'your X account', 'social-auto-post' ) )
			);
			printf(
				'<p class="sap-muted">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human readable time */
						__( 'Access token renews automatically. Current token expires %s.', 'social-auto-post' ),
						$token['expires_at'] > time()
							? human_time_diff( time(), $token['expires_at'] ) . __( ' from now', 'social-auto-post' )
							: __( 'shortly', 'social-auto-post' )
					)
				)
			);
			$this->action_link( SAP_X_OAuth::ACTION_DISCONNECT, __( 'Disconnect', 'social-auto-post' ), 'button' );
		} else {
			$this->action_link( SAP_X_OAuth::ACTION_CONNECT, __( 'Connect X account', 'social-auto-post' ), 'button button-primary' );
		}
		echo '</div>';

		echo '<div class="sap-card">';
		echo '<h2>' . esc_html__( 'This month', 'social-auto-post' ) . '</h2>';
		printf(
			'<p class="sap-usage"><strong>%1$d</strong> / %2$d %3$s</p>',
			(int) $used,
			(int) $cap,
			esc_html__( 'posts sent to X', 'social-auto-post' )
		);
		$percent = $cap > 0 ? min( 100, (int) round( ( $used / $cap ) * 100 ) ) : 0;
		printf(
			'<div class="sap-meter"><span style="width:%d%%"></span></div>',
			(int) $percent
		);
		echo '<p class="description">' . esc_html__( 'X\'s free API tier allows 500 posts per calendar month. This plugin adds no limit of its own; this counter just shows where you stand against X\'s.', 'social-auto-post' ) . '</p>';
		echo '</div>';
	}

	/* ---------------------------------------------------------- tab: rules */

	/**
	 * Posting rules tab.
	 *
	 * @return void
	 */
	private function tab_rules() {
		$s = SAP_Settings::all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sap_save_settings' );
		echo '<input type="hidden" name="action" value="sap_save_settings">';
		echo '<input type="hidden" name="tab" value="rules">';

		echo '<div class="sap-card"><h2>' . esc_html__( 'When to post', 'social-auto-post' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="enabled" value="1" %2$s> %3$s</label></td></tr>',
			esc_html__( 'Auto-posting', 'social-auto-post' ),
			checked( ! empty( $s['enabled'] ), true, false ),
			esc_html__( 'Share new posts to X automatically', 'social-auto-post' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'social-auto-post' ) . '</th><td>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			printf(
				'<label class="sap-inline"><input type="checkbox" name="post_types[]" value="%1$s" %2$s> %3$s</label>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, (array) $s['post_types'], true ), true, false ),
				esc_html( $type->labels->singular_name )
			);
		}
		echo '</td></tr>';

		$categories = get_categories( array( 'hide_empty' => false ) );
		echo '<tr><th scope="row">' . esc_html__( 'Only these categories', 'social-auto-post' ) . '</th><td>';
		$this->category_list( 'categories', $categories, (array) $s['categories'] );
		echo '<p class="description">' . esc_html__( 'Leave all unticked to share posts from every category.', 'social-auto-post' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Never these categories', 'social-auto-post' ) . '</th><td>';
		$this->category_list( 'exclude_categories', $categories, (array) $s['exclude_categories'] );
		echo '<p class="description">' . esc_html__( 'Exclusions win over the list above.', 'social-auto-post' ) . '</p></td></tr>';

		printf(
			'<tr><th scope="row"><label for="sap_delay">%1$s</label></th><td><input type="number" min="0" max="1440" id="sap_delay" name="delay_minutes" value="%2$d" class="small-text"> %3$s<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Delay', 'social-auto-post' ),
			(int) $s['delay_minutes'],
			esc_html__( 'minutes', 'social-auto-post' ),
			esc_html__( 'Wait before sharing, so you have a window to spot a typo. 0 shares as soon as WordPress next runs its scheduler.', 'social-auto-post' )
		);

		printf(
			'<tr><th scope="row"><label for="sap_attempts">%1$s</label></th><td><input type="number" min="1" max="10" id="sap_attempts" name="max_attempts" value="%2$d" class="small-text"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Retry attempts', 'social-auto-post' ),
			(int) $s['max_attempts'],
			esc_html__( 'How many times to retry a failure that looks temporary, backing off between tries.', 'social-auto-post' )
		);

		echo '</tbody></table></div>';

		echo '<div class="sap-card"><h2>' . esc_html__( 'What to post', 'social-auto-post' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="sap_template">%1$s</label></th><td><textarea id="sap_template" name="template" rows="5" class="large-text code">%2$s</textarea>',
			esc_html__( 'Template', 'social-auto-post' ),
			esc_textarea( (string) $s['template'] )
		);
		echo '<p class="description">' . esc_html__( 'Available placeholders:', 'social-auto-post' ) . '</p><ul class="sap-tokens">';
		foreach ( SAP_Template::tokens() as $token => $desc ) {
			printf( '<li><code>%1$s</code> %2$s</li>', esc_html( $token ), esc_html( $desc ) );
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Long posts are trimmed to fit 280 characters. Links always survive trimming, and always cost 23 characters on X however long they are.', 'social-auto-post' ) . '</p>';
		echo '</td></tr>';

		printf(
			'<tr><th scope="row"><label for="sap_hashtags">%1$s</label></th><td><input type="text" class="regular-text" id="sap_hashtags" name="hashtags" value="%2$s" placeholder="wordpress, blogging"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Hashtags', 'social-auto-post' ),
			esc_attr( (string) $s['hashtags'] ),
			esc_html__( 'Used by the {hashtags} placeholder. Commas or spaces; the # is optional.', 'social-auto-post' )
		);

		echo '</tbody></table></div>';

		submit_button( __( 'Save posting rules', 'social-auto-post' ) );
		echo '</form>';
	}

	/**
	 * Checkbox list of categories.
	 *
	 * @param string $name     Field name.
	 * @param array  $terms    Category objects.
	 * @param array  $selected Selected term ids.
	 * @return void
	 */
	private function category_list( $name, $terms, $selected ) {
		if ( empty( $terms ) ) {
			echo '<p class="sap-muted">' . esc_html__( 'No categories yet.', 'social-auto-post' ) . '</p>';
			return;
		}
		$selected = array_map( 'intval', $selected );

		echo '<div class="sap-scrollbox">';
		foreach ( $terms as $term ) {
			printf(
				'<label class="sap-inline"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s> %4$s</label>',
				esc_attr( $name ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name )
			);
		}
		echo '</div>';
	}

	/* -------------------------------------------------------- tab: socials */

	/**
	 * Socials section tab.
	 *
	 * @return void
	 */
	private function tab_socials() {
		$s = SAP_Settings::all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sap_save_settings' );
		echo '<input type="hidden" name="action" value="sap_save_settings">';
		echo '<input type="hidden" name="tab" value="socials">';

		echo '<div class="sap-card"><h2>' . esc_html__( 'Socials section', 'social-auto-post' ) . '</h2>';
		echo '<p>' . esc_html__( 'Once a post has been shared, its tweet can be listed back on the post itself.', 'social-auto-post' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="socials_enabled" value="1" %2$s> %3$s</label></td></tr>',
			esc_html__( 'Show the section', 'social-auto-post' ),
			checked( ! empty( $s['socials_enabled'] ), true, false ),
			esc_html__( 'Display a Socials block on posts', 'social-auto-post' )
		);

		printf(
			'<tr><th scope="row"><label for="sap_socials_title">%1$s</label></th><td><input type="text" class="regular-text" id="sap_socials_title" name="socials_title" value="%2$s"></td></tr>',
			esc_html__( 'Heading', 'social-auto-post' ),
			esc_attr( (string) $s['socials_title'] )
		);

		$positions = array(
			'after_content'  => __( 'After the post content', 'social-auto-post' ),
			'before_content' => __( 'Before the post content', 'social-auto-post' ),
			'manual'         => __( 'Only where I place the shortcode', 'social-auto-post' ),
		);
		echo '<tr><th scope="row"><label for="sap_socials_position">' . esc_html__( 'Position', 'social-auto-post' ) . '</label></th><td>';
		echo '<select id="sap_socials_position" name="socials_position">';
		foreach ( $positions as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $s['socials_position'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s <code>[%s]</code></p>',
			esc_html__( 'Shortcode, usable anywhere regardless of this setting:', 'social-auto-post' ),
			esc_html( SAP_Socials::SHORTCODE )
		);
		echo '</td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="socials_show_x" value="1" %2$s> %3$s</label></td></tr>',
			esc_html__( 'This post\'s tweet', 'social-auto-post' ),
			checked( ! empty( $s['socials_show_x'] ), true, false ),
			esc_html__( 'Link to the X post created for this article', 'social-auto-post' )
		);

		printf(
			'<tr><th scope="row"><label for="sap_profiles">%1$s</label></th><td><textarea id="sap_profiles" name="profiles" rows="5" class="large-text code" placeholder="%2$s">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Other profiles', 'social-auto-post' ),
			esc_attr( "Instagram | https://instagram.com/andrew.amns | @andrew.amns\nYouTube | https://youtube.com/@amns.andrew | @amns.andrew" ),
			esc_textarea( $this->profiles_to_text( (array) $s['profiles'] ) ),
			esc_html__( 'One per line: Label | URL | handle to display. These appear under the same heading on every post.', 'social-auto-post' )
		);

		echo '</tbody></table></div>';
		submit_button( __( 'Save socials section', 'social-auto-post' ) );
		echo '</form>';
	}

	/* ------------------------------------------------------------ tab: log */

	/**
	 * Activity log tab.
	 *
	 * @return void
	 */
	private function tab_log() {
		$entries = SAP_Logger::entries();

		echo '<div class="sap-card"><h2>' . esc_html__( 'Activity log', 'social-auto-post' ) . '</h2>';

		if ( empty( $entries ) ) {
			echo '<p class="sap-muted">' . esc_html__( 'Nothing logged yet.', 'social-auto-post' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped sap-log"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'social-auto-post' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'social-auto-post' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'social-auto-post' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			$level   = isset( $entry['level'] ) ? (string) $entry['level'] : 'info';
			$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
			$url     = isset( $entry['context']['url'] ) ? (string) $entry['context']['url'] : '';

			printf(
				'<tr><td class="sap-log__time">%1$s</td><td><span class="sap-badge sap-badge--%2$s">%3$s</span></td><td>%4$s%5$s</td></tr>',
				esc_html(
					$time
						? sprintf(
							/* translators: %s: time difference */
							__( '%s ago', 'social-auto-post' ),
							human_time_diff( $time, time() )
						)
						: '—'
				),
				esc_attr( $level ),
				esc_html( $level ),
				esc_html( $message ),
				$url ? ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on X', 'social-auto-post' ) . '</a>' : ''
			);
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sap-log__clear">';
		wp_nonce_field( 'sap_clear_log' );
		echo '<input type="hidden" name="action" value="sap_clear_log">';
		submit_button( __( 'Clear log', 'social-auto-post' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/* --------------------------------------------------------- form handlers */

	/**
	 * Save the X app credentials.
	 *
	 * @return void
	 */
	public function save_app() {
		$this->guard( 'sap_save_app' );

		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';

		SAP_Settings::save_app( $client_id, $client_secret );

		wp_safe_redirect( add_query_arg( 'sap_notice', 'app_saved', self::page_url( 'connection' ) ) );
		exit;
	}

	/**
	 * Save the settings form.
	 *
	 * @return void
	 */
	public function save_settings() {
		$this->guard( 'sap_save_settings' );

		$tab     = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'rules';
		$changes = array();

		if ( 'rules' === $tab ) {
			$changes['enabled']            = ! empty( $_POST['enabled'] );
			$changes['post_types']         = isset( $_POST['post_types'] )
				? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) )
				: array();
			$changes['categories']         = isset( $_POST['categories'] )
				? array_map( 'intval', (array) wp_unslash( $_POST['categories'] ) )
				: array();
			$changes['exclude_categories'] = isset( $_POST['exclude_categories'] )
				? array_map( 'intval', (array) wp_unslash( $_POST['exclude_categories'] ) )
				: array();
			$changes['template']           = isset( $_POST['template'] )
				? sanitize_textarea_field( wp_unslash( $_POST['template'] ) )
				: '';
			$changes['hashtags']           = isset( $_POST['hashtags'] )
				? SAP_Template::normalize_hashtags( sanitize_text_field( wp_unslash( $_POST['hashtags'] ) ) )
				: '';
			$changes['delay_minutes']      = isset( $_POST['delay_minutes'] )
				? max( 0, min( 1440, (int) $_POST['delay_minutes'] ) )
				: 0;
			$changes['max_attempts']       = isset( $_POST['max_attempts'] )
				? max( 1, min( 10, (int) $_POST['max_attempts'] ) )
				: 4;
		}

		if ( 'socials' === $tab ) {
			$changes['socials_enabled']  = ! empty( $_POST['socials_enabled'] );
			$changes['socials_show_x']   = ! empty( $_POST['socials_show_x'] );
			$changes['socials_title']    = isset( $_POST['socials_title'] )
				? sanitize_text_field( wp_unslash( $_POST['socials_title'] ) )
				: 'Socials';
			$position                    = isset( $_POST['socials_position'] )
				? sanitize_key( wp_unslash( $_POST['socials_position'] ) )
				: 'after_content';
			$changes['socials_position'] = in_array( $position, array( 'after_content', 'before_content', 'manual' ), true )
				? $position
				: 'after_content';
			$changes['profiles']         = isset( $_POST['profiles'] )
				? $this->parse_profiles( (string) wp_unslash( $_POST['profiles'] ) )
				: array();
		}

		SAP_Settings::update( $changes );

		wp_safe_redirect( add_query_arg( 'sap_notice', 'saved', self::page_url( $tab ) ) );
		exit;
	}

	/**
	 * Empty the activity log.
	 *
	 * @return void
	 */
	public function clear_log() {
		$this->guard( 'sap_clear_log' );
		SAP_Logger::clear();
		wp_safe_redirect( add_query_arg( 'sap_notice', 'log_cleared', self::page_url( 'log' ) ) );
		exit;
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Turn the "Label | URL | handle" textarea into structured profiles.
	 *
	 * @param string $raw Textarea contents.
	 * @return array
	 */
	private function parse_profiles( $raw ) {
		$profiles = array();

		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$label = isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
			$url   = isset( $parts[1] ) ? esc_url_raw( $parts[1] ) : '';

			if ( '' === $label || '' === $url ) {
				continue;
			}

			$profiles[] = array(
				'label'  => $label,
				'url'    => $url,
				'handle' => isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '',
			);
		}

		return $profiles;
	}

	/**
	 * Render stored profiles back into the textarea format.
	 *
	 * @param array $profiles Profiles.
	 * @return string
	 */
	private function profiles_to_text( array $profiles ) {
		$lines = array();

		foreach ( $profiles as $profile ) {
			if ( empty( $profile['label'] ) ) {
				continue;
			}
			$lines[] = implode(
				' | ',
				array_filter(
					array(
						(string) $profile['label'],
						isset( $profile['url'] ) ? (string) $profile['url'] : '',
						isset( $profile['handle'] ) ? (string) $profile['handle'] : '',
					),
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * A nonce-protected link to an admin-post action.
	 *
	 * @param string $action Action name.
	 * @param string $label  Link text.
	 * @param string $class  CSS classes.
	 * @return void
	 */
	private function action_link( $action, $label, $class ) {
		printf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=' . $action ),
					$action
				)
			),
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * Capability plus nonce check.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-auto-post' ), 403 );
		}
		check_admin_referer( $action );
	}
}
