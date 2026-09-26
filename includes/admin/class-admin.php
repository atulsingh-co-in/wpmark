<?php
/**
 * WPMark admin screens.
 *
 * @package WPMark
 */

namespace WPMark\Admin;

use WPMark\Abilities\Abilities;
use WPMark\Abilities\Ability;
use WPMark\Links;
use WPMark\Mcp_Server;
use WPMark\OAuth\OAuth;
use WPMark\OAuth\Store;
use WPMark\Privacy\Lead_Access;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The WPMark menu: Connect, Health check, Tools, and Access & privacy.
 *
 * Anyone allowed to use WPMark sees Connect, to create their own
 * connection key. Only administrators see the other tabs, where the site
 * is checked and configured. Every change needs a nonce and the right
 * capability. Written for marketing people: no developer words.
 */
final class Admin {

	/**
	 * Menu slug.
	 */
	public const PAGE = 'wpmark';

	/**
	 * Add the admin hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_wpmark_save_tools', array( self::class, 'save_tools' ) );
		add_action( 'admin_post_wpmark_save_access', array( self::class, 'save_access' ) );
		add_action( 'admin_post_wpmark_disconnect', array( self::class, 'disconnect' ) );
		add_action( 'admin_notices', array( self::class, 'welcome_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMARK_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Add the WPMark menu.
	 */
	public static function add_menu(): void {
		$hook = add_menu_page(
			__( 'WPMark', 'wpmark' ),
			__( 'WPMark', 'wpmark' ),
			'edit_posts',
			self::PAGE,
			array( self::class, 'render' ),
			'dashicons-megaphone',
			58
		);

		add_action( 'admin_print_styles-' . $hook, array( self::class, 'styles' ) );
		add_action( 'admin_print_footer_scripts-' . $hook, array( self::class, 'scripts' ) );
	}

	/**
	 * The Copy button: copies the setup text and says so.
	 */
	public static function scripts(): void {
		wp_print_inline_script_tag(
			sprintf(
				'document.addEventListener("click",function(e){var b=e.target.closest("[data-wpmark-copy]");if(!b){return;}var t=document.getElementById(b.getAttribute("data-wpmark-copy"));t.select();var done=function(){b.nextElementSibling.textContent=b.getAttribute("data-wpmark-copied")||%s;};if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(done);}else{document.execCommand("copy");done();}});',
				wp_json_encode( __( 'Copied. Now paste it into your AI app.', 'wpmark' ) )
			)
		);
	}

	/**
	 * The tabs this person may see.
	 *
	 * @return array<string, string> Slug => label.
	 */
	public static function tabs(): array {
		$tabs = array( 'connect' => __( 'Connect', 'wpmark' ) );

		if ( current_user_can( 'manage_options' ) ) {
			$tabs['health'] = __( 'Health check', 'wpmark' );
			$tabs['tools']  = __( 'Tools', 'wpmark' );
			$tabs['access'] = __( 'Access & privacy', 'wpmark' );
		}

		return $tabs;
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		delete_transient( 'wpmark_welcome' );

		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing a tab to view changes nothing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'connect';

		echo '<div class="wrap wpmark">';
		echo '<h1>' . esc_html__( 'WPMark', 'wpmark' ) . ' <span class="wpmark-badge">' . esc_html__( 'Beta', 'wpmark' ) . '</span></h1>';
		echo '<p class="wpmark-lead">' . esc_html__( 'Ask Claude, ChatGPT or Gemini about your content, SEO and leads. WPMark only reads your site: it never publishes, changes or deletes anything.', 'wpmark' ) . '</p>';

		self::render_saved_notice();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'health':
				self::render_health();
				break;
			case 'tools':
				self::render_tools();
				break;
			case 'access':
				self::render_access();
				break;
			default:
				self::render_connect();
		}

		self::render_footer();
		echo '</div>';
	}

	/**
	 * Who made WPMark, and where to get help.
	 */
	private static function render_footer(): void {
		$links = array(
			Links::doc( 'getting-started.md' )  => __( 'Setup guide', 'wpmark' ),
			Links::doc( 'privacy-and-data.md' ) => __( 'Privacy and your data', 'wpmark' ),
			Links::doc( 'beta-terms.md' )       => __( 'Beta terms', 'wpmark' ),
			Links::ISSUES                       => __( 'Report a problem', 'wpmark' ),
		);

		$items = array();
		foreach ( $links as $url => $label ) {
			$items[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
		}

		echo '<p class="wpmark-footer">';
		printf(
			/* translators: 1: version number, 2: link to the developer's site. */
			esc_html__( 'WPMark %1$s (beta), free and open source. Made by %2$s.', 'wpmark' ),
			esc_html( WPMARK_VERSION ),
			'<a href="' . esc_url( Links::AUTHOR ) . '" target="_blank" rel="noopener">Atul Singh</a>'
		);
		echo ' ' . implode( ' · ', $items ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped above.
	}

	/**
	 * Connect tab: a three-step wizard.
	 */
	private static function render_connect(): void {
		$user = wp_get_current_user();
		$key  = null;
		$app  = 'claude-desktop';

		// A connection key was requested: create it and show it in this response only.
		if ( isset( $_POST['wpmark_create_key'] ) ) {
			check_admin_referer( 'wpmark_create_key' );

			$app = isset( $_POST['wpmark_app'] ) ? sanitize_key( wp_unslash( $_POST['wpmark_app'] ) ) : $app;
			$app = isset( Connection_Wizard::apps()[ $app ] ) ? $app : 'claude-desktop';
			$key = Connection_Wizard::create_key( $user, $app );
		}

		echo '<div class="wpmark-card"><h2>' . esc_html__( '1. Check your site', 'wpmark' ) . '</h2>';
		self::render_checks( Connection_Doctor::quick_checks() );
		if ( current_user_can( 'manage_options' ) ) {
			echo '<p><a href="' . esc_url( self::url( 'health' ) ) . '">' . esc_html__( 'Run the full health check', 'wpmark' ) . '</a></p>';
		}
		echo '</div>';

		if ( ! Settings::user_can_connect( $user ) ) {
			echo '<div class="wpmark-card"><h2>' . esc_html__( '2. Connect your AI app', 'wpmark' ) . '</h2><p>' . esc_html__( 'Your role is not allowed to use WPMark. Ask a site administrator to switch it on under WPMark → Access & privacy.', 'wpmark' ) . '</p></div>';
			return;
		}

		self::render_sign_in_steps();
		self::render_connected_apps( $user );

		// Connection keys, for apps without "sign in" support.
		echo '<div class="wpmark-card"><details' . ( null !== $key ? ' open' : '' ) . '><summary><h2 class="wpmark-inline">' . esc_html__( 'Other apps: use a connection key', 'wpmark' ) . '</h2></summary>';
		echo '<p>' . esc_html__( 'For apps that cannot sign in by themselves, such as Claude Desktop through a configuration file, Cursor or Gemini CLI. The key signs in as you; you can revoke it any time from your profile.', 'wpmark' ) . '</p>';
		echo '<form method="post" action="' . esc_url( self::url( 'connect' ) ) . '">';
		wp_nonce_field( 'wpmark_create_key' );
		echo '<label for="wpmark-app">' . esc_html__( 'Which AI app?', 'wpmark' ) . '</label> <select id="wpmark-app" name="wpmark_app">';
		foreach ( Connection_Wizard::apps() as $id => $name ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $id, $app, false ), esc_html( $name ) );
		}
		echo '</select> ';
		submit_button( __( 'Create key and show setup', 'wpmark' ), 'secondary', 'wpmark_create_key', false );
		echo '</form></details></div>';

		if ( is_wp_error( $key ) ) {
			wp_admin_notice( esc_html( $key->get_error_message() ), array( 'type' => 'error' ) );
		} elseif ( is_array( $key ) ) {
			self::render_key( $app, $key );
		}

		echo '<div class="wpmark-card"><h2>' . esc_html__( 'Try it', 'wpmark' ) . '</h2><p>' . esc_html__( 'Once connected, ask your AI app things like:', 'wpmark' ) . '</p><ul class="ul-disc">';
		foreach (
			array(
				__( 'Give me an overview of our website.', 'wpmark' ),
				__( 'What did people ask in our contact forms this month? Group them into themes.', 'wpmark' ),
				__( 'Which landing pages brought the most enquiries in the last 30 days?', 'wpmark' ),
				__( 'Which pages are missing a meta description or have not been updated for over a year?', 'wpmark' ),
				__( 'Suggest five blog posts that answer questions our customers keep asking.', 'wpmark' ),
			) as $example
		) {
			echo '<li>' . esc_html( $example ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'Claude also lists WPMark\'s ready-made playbooks (lead review, content plan, SEO check) under the + button.', 'wpmark' ) . '</p></div>';
	}

	/**
	 * Step 2: the address to paste, and where to paste it in each app.
	 */
	private static function render_sign_in_steps(): void {
		$url = Mcp_Server::url();

		echo '<div class="wpmark-card"><h2>' . esc_html__( '2. Connect your AI app', 'wpmark' ) . '</h2>';

		if ( ! OAuth::is_enabled() ) {
			$reason = OAuth::secure_enough()
				? __( 'Sign-in for AI apps is switched off under WPMark → Access & privacy.', 'wpmark' )
				: __( 'Sign-in for AI apps needs a secure (https://) site address.', 'wpmark' );
			echo '<p>' . esc_html( $reason ) . ' ' . esc_html__( 'You can still connect with a connection key below.', 'wpmark' ) . '</p></div>';
			return;
		}

		echo '<p>' . esc_html__( 'Copy this address and add it to your AI app. The app then opens a sign-in window on this site: log in and click Allow. No keys or files needed.', 'wpmark' ) . '</p>';
		echo '<p class="wpmark-address"><input type="text" id="wpmark-mcp-url" class="large-text code" readonly value="' . esc_attr( $url ) . '"> ';
		echo '<button type="button" class="button button-primary" data-wpmark-copy="wpmark-mcp-url">' . esc_html__( 'Copy address', 'wpmark' ) . '</button> <span class="wpmark-copied" aria-live="polite"></span></p>';

		$apps = array(
			__( 'Claude (web, desktop and mobile)', 'wpmark' ) => array(
				__( 'Open Settings → Connectors and click "Add custom connector".', 'wpmark' ),
				__( 'Name it "WPMark", paste the address, and click Add.', 'wpmark' ),
				__( 'Click Connect, log in to WordPress in the window that opens, and click Allow.', 'wpmark' ),
				__( 'On Team and Enterprise plans, an organization owner adds the connector first.', 'wpmark' ),
			),
			__( 'ChatGPT', 'wpmark' )                => array(
				__( 'Open Settings → Apps & Connectors → Advanced settings and turn on Developer mode (available on some ChatGPT plans).', 'wpmark' ),
				__( 'Back in Apps & Connectors, click Create. Name it "WPMark", paste the address, choose OAuth for authentication, and click Create.', 'wpmark' ),
				__( 'Log in to WordPress in the window that opens and click Allow.', 'wpmark' ),
			),
			__( 'Gemini (early support)', 'wpmark' ) => array(
				__( 'On gemini.google.com open Settings → Connected apps and choose "Add a custom app". This option depends on your Google account type and region.', 'wpmark' ),
				__( 'Paste the address, then log in to WordPress in the window that opens and click Allow.', 'wpmark' ),
				__( 'No such option yet? Use Gemini CLI with a connection key (below).', 'wpmark' ),
			),
			__( 'Claude Code, Cursor, VS Code and others', 'wpmark' ) => array(
				__( 'Add the address as a remote (HTTP) MCP server. Apps that support sign-in open this site\'s login page; for example in Claude Code run: claude mcp add --transport http wpmark ADDRESS, then /mcp to sign in.', 'wpmark' ),
			),
		);

		foreach ( $apps as $name => $steps ) {
			echo '<h3>' . esc_html( $name ) . '</h3><ol>';
			foreach ( $steps as $step ) {
				echo '<li>' . esc_html( str_replace( 'ADDRESS', $url, $step ) ) . '</li>';
			}
			echo '</ol>';
		}

		echo '</div>';
	}

	/**
	 * Apps connected through sign-in, with a Disconnect button each.
	 *
	 * People see their own. Administrators also see everyone else's.
	 *
	 * @param \WP_User $user Current user.
	 */
	private static function render_connected_apps( \WP_User $user ): void {
		$is_admin    = current_user_can( 'manage_options' );
		$connections = Store::connections( $is_admin ? null : $user->ID );

		echo '<div class="wpmark-card"><h2>' . esc_html__( 'Connected apps', 'wpmark' ) . '</h2>';

		if ( ! $connections ) {
			echo '<p>' . esc_html__( 'No apps are connected through sign-in yet.', 'wpmark' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped wpmark-table"><thead><tr>';
		if ( $is_admin ) {
			echo '<th>' . esc_html__( 'Person', 'wpmark' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'App', 'wpmark' ) . '</th><th>' . esc_html__( 'Connected', 'wpmark' ) . '</th><th>' . esc_html__( 'Last used', 'wpmark' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $connections as $connection ) {
			$person = get_userdata( $connection['user_id'] );

			echo '<tr>';
			if ( $is_admin ) {
				echo '<td>' . esc_html( $person ? $person->display_name : '#' . $connection['user_id'] ) . '</td>';
			}
			echo '<td>' . esc_html( '' !== $connection['client_name'] ? $connection['client_name'] : __( 'Unnamed AI app', 'wpmark' ) ) . '</td>';
			echo '<td>' . esc_html( self::local_date( $connection['connected_at'] ) ) . '</td>';
			echo '<td>' . esc_html( null !== $connection['last_used_at'] ? self::local_date( $connection['last_used_at'] ) : __( 'Not yet', 'wpmark' ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wpmark_disconnect">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $connection['user_id'] ) . '">';
			echo '<input type="hidden" name="client_id" value="' . esc_attr( $connection['client_id'] ) . '">';
			wp_nonce_field( 'wpmark_disconnect' );
			echo '<button type="submit" class="button-link wpmark-danger">' . esc_html__( 'Disconnect', 'wpmark' ) . '</button></form></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Disconnect an app: revoke its tokens for one person.
	 *
	 * People may disconnect their own apps; administrators anyone's.
	 */
	public static function disconnect(): void {
		check_admin_referer( 'wpmark_disconnect' );

		$user_id   = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		if ( ! $user_id || '' === $client_id || ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You can only disconnect your own apps.', 'wpmark' ), 403 );
		}

		Store::revoke_for_user( $user_id, $client_id );

		wp_safe_redirect( add_query_arg( 'disconnected', '1', self::url( 'connect' ) ) );
		exit;
	}

	/**
	 * A stored UTC time in the site's date format and timezone.
	 *
	 * @param string $utc MySQL date in UTC.
	 * @return string
	 */
	private static function local_date( string $utc ): string {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $utc . ' UTC' ) );
	}

	/**
	 * Show a new key once, with setup for the chosen app.
	 *
	 * @param string $app App ID.
	 * @param array  $key Key.
	 */
	private static function render_key( string $app, array $key ): void {
		$setup = Connection_Wizard::setup( $app, $key );

		echo '<div class="wpmark-card wpmark-key">';
		echo '<h2>' . esc_html__( 'Your connection', 'wpmark' ) . '</h2>';
		echo '<p class="wpmark-warning"><strong>' . esc_html__( 'Copy this now. It is shown only once.', 'wpmark' ) . '</strong> ' . esc_html__( 'Anyone with this key can read what your account can read through WPMark. Do not paste it into shared documents or chats.', 'wpmark' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Address', 'wpmark' ), esc_html( $key['url'] ) );
		printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Username', 'wpmark' ), esc_html( $key['username'] ) );
		printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Key', 'wpmark' ), esc_html( $key['password'] ) );
		echo '</tbody></table>';
		echo '<h3>' . esc_html( Connection_Wizard::apps()[ $app ] ) . '</h3>';
		echo '<p>' . esc_html( $setup['steps'] ) . '</p>';
		echo '<textarea id="wpmark-setup-code" class="large-text code wpmark-code" rows="' . esc_attr( (string) min( 24, substr_count( $setup['code'], "\n" ) + 2 ) ) . '" readonly>' . esc_textarea( $setup['code'] ) . '</textarea>';
		echo '<p><button type="button" class="button button-primary" data-wpmark-copy="wpmark-setup-code">' . esc_html__( 'Copy', 'wpmark' ) . '</button> <span class="wpmark-copied" aria-live="polite"></span></p>';
		echo '<p><a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Manage or revoke your keys', 'wpmark' ) . '</a></p>';
		echo '</div>';

		// Turn this page back into a normal page view, so reloading it cannot create a second key.
		wp_print_inline_script_tag( 'if(window.history.replaceState){window.history.replaceState(null,"",window.location.href);}' );
	}

	/**
	 * Health check tab.
	 */
	private static function render_health(): void {
		echo '<div class="wpmark-card"><h2>' . esc_html__( 'Health check', 'wpmark' ) . '</h2>';
		echo '<p>' . esc_html__( 'These checks find the usual reasons an AI app cannot connect. The last three send test requests from your site to itself.', 'wpmark' ) . '</p>';
		$checks = Connection_Doctor::all_checks();
		self::render_checks( $checks );
		echo '</div>';

		echo '<div class="wpmark-card"><h2>' . esc_html__( 'Need help?', 'wpmark' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy this report and include it when you ask for help. It lists versions and the results above. It does not include leads, passwords or keys.', 'wpmark' ) . '</p>';
		echo '<textarea id="wpmark-health-report" class="large-text code wpmark-code" rows="8" readonly>' . esc_textarea( Connection_Doctor::report( $checks ) ) . '</textarea>';
		echo '<p><button type="button" class="button" data-wpmark-copy="wpmark-health-report" data-wpmark-copied="' . esc_attr__( 'Copied.', 'wpmark' ) . '">' . esc_html__( 'Copy health report', 'wpmark' ) . '</button> <span class="wpmark-copied" aria-live="polite"></span></p>';
		echo '</div>';
	}

	/**
	 * A list of check results.
	 *
	 * @param array<int, array> $checks Results from Connection_Doctor.
	 */
	private static function render_checks( array $checks ): void {
		$icons = array(
			Connection_Doctor::GOOD    => array( 'yes-alt', __( 'Good', 'wpmark' ) ),
			Connection_Doctor::WARNING => array( 'warning', __( 'Warning', 'wpmark' ) ),
			Connection_Doctor::PROBLEM => array( 'dismiss', __( 'Problem', 'wpmark' ) ),
			Connection_Doctor::INFO    => array( 'info-outline', __( 'Information', 'wpmark' ) ),
		);

		echo '<ul class="wpmark-checks">';
		foreach ( $checks as $check ) {
			$icon = $icons[ $check['status'] ] ?? $icons[ Connection_Doctor::INFO ];

			printf(
				'<li class="wpmark-check wpmark-%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span><span class="screen-reader-text">%3$s: </span><strong>%4$s</strong> %5$s%6$s</li>',
				esc_attr( $check['status'] ),
				esc_attr( $icon[0] ),
				esc_html( $icon[1] ),
				esc_html( $check['label'] ),
				esc_html( $check['message'] ),
				'' !== $check['fix'] ? '<br><span class="wpmark-fix">' . esc_html( $check['fix'] ) . '</span>' : ''
			);
		}
		echo '</ul>';
	}

	/**
	 * Tools tab: switch tools on and off.
	 */
	private static function render_tools(): void {
		echo '<div class="wpmark-card"><h2>' . esc_html__( 'Tools', 'wpmark' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose what AI apps may use. Every tool only reads; switching one off hides it from all AI apps on this site.', 'wpmark' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpmark_save_tools">';
		wp_nonce_field( 'wpmark_save_tools' );
		echo '<table class="widefat striped wpmark-table"><thead><tr><th>' . esc_html__( 'On', 'wpmark' ) . '</th><th>' . esc_html__( 'Tool', 'wpmark' ) . '</th><th>' . esc_html__( 'What it does', 'wpmark' ) . '</th></tr></thead><tbody>';

		foreach ( Abilities::of_type( Ability::TOOL ) as $tool ) {
			printf(
				'<tr><td><input type="checkbox" id="wpmark-tool-%1$s" name="tools[%1$s]" value="1"%2$s></td><td><label for="wpmark-tool-%1$s"><strong>%3$s</strong></label></td><td>%4$s%5$s</td></tr>',
				esc_attr( $tool->slug() ),
				checked( $tool->is_enabled(), true, false ),
				esc_html( $tool->label() ),
				esc_html( $tool->summary() ),
				$tool->is_available() ? '' : '<br><em>' . esc_html( $tool->unavailable_reason() ) . '</em>'
			);
		}

		echo '</tbody></table>';
		submit_button( __( 'Save tools', 'wpmark' ) );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Always on', 'wpmark' ) . '</h3><ul class="ul-disc">';
		foreach ( array_merge( Abilities::of_type( Ability::RESOURCE ), Abilities::of_type( Ability::PROMPT ) ) as $ability ) {
			printf(
				'<li><strong>%s</strong>: %s%s</li>',
				esc_html( $ability->label() ),
				esc_html( $ability->description() ),
				$ability->is_available() ? '' : ' <em>' . esc_html__( '(needs a form plugin)', 'wpmark' ) . '</em>'
			);
		}
		echo '</ul></div>';
	}

	/**
	 * Access & privacy tab: which roles may connect, and how much of a lead they see.
	 */
	private static function render_access(): void {
		echo '<div class="wpmark-card"><h2>' . esc_html__( 'Access & privacy', 'wpmark' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose which roles may connect an AI app, and how much of each lead (form submission) they may share with it. Roles are read from your site automatically, including ones added by other plugins.', 'wpmark' ) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li><strong>' . esc_html( Lead_Access::label( Lead_Access::Hidden ) ) . '</strong>: ' . esc_html__( 'what people asked, which form and page, but no names, emails, phone numbers or addresses.', 'wpmark' ) . '</li>';
		echo '<li><strong>' . esc_html( Lead_Access::label( Lead_Access::Masked ) ) . '</strong>: ' . esc_html__( 'contact details partly hidden, for example r***@acme.com, *********210, R*** S***. The company domain stays visible.', 'wpmark' ) . '</li>';
		echo '<li><strong>' . esc_html( Lead_Access::label( Lead_Access::Full ) ) . '</strong>: ' . esc_html__( 'everything as submitted.', 'wpmark' ) . '</li>';
		echo '</ul>';
		echo '<p class="wpmark-warning">' . esc_html__( 'Whatever an AI app reads is sent to its provider (for example Anthropic, OpenAI or Google). Only allow full contact details if your privacy policy covers that. Emails and phone numbers typed inside messages are masked or removed to match each role\'s setting.', 'wpmark' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpmark_save_access">';
		wp_nonce_field( 'wpmark_save_access' );
		echo '<table class="widefat striped wpmark-table"><thead><tr><th>' . esc_html__( 'Role', 'wpmark' ) . '</th><th>' . esc_html__( 'Can use WPMark', 'wpmark' ) . '</th><th>' . esc_html__( 'Leads', 'wpmark' ) . '</th></tr></thead><tbody>';

		foreach ( Settings::roles() as $slug => $role ) {
			echo '<tr><td><strong>' . esc_html( $role['name'] ) . '</strong></td><td>';

			if ( $role['can_write'] ) {
				printf(
					'<input type="checkbox" id="wpmark-role-%1$s" name="roles[%1$s][enabled]" value="1"%2$s><label class="screen-reader-text" for="wpmark-role-%1$s">%3$s</label>',
					esc_attr( $slug ),
					checked( $role['enabled'], true, false ),
					/* translators: %s: role name. */
					esc_html( sprintf( __( '%s can use WPMark', 'wpmark' ), $role['name'] ) )
				);
			} else {
				echo '<em>' . esc_html__( 'No: this role cannot write posts.', 'wpmark' ) . '</em>';
			}

			printf( '</td><td><select name="roles[%s][leads]"%s>', esc_attr( $slug ), disabled( $role['can_write'], false, false ) );
			foreach ( Lead_Access::cases() as $level ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $level->value ), selected( $level->value, $role['leads']->value, false ), esc_html( Lead_Access::label( $level ) ) );
			}
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Sign-in for AI apps', 'wpmark' ) . '</h3>';
		printf(
			'<p><label><input type="checkbox" name="oauth" value="1"%s> %s</label></p><p class="description">%s</p>',
			checked( Settings::oauth_enabled(), true, false ),
			esc_html__( 'Let AI apps such as Claude and ChatGPT connect by signing in to WordPress (OAuth).', 'wpmark' ),
			esc_html__( 'Recommended. Each person signs in as themselves, so their role and the settings above apply. Switching this off disconnects every app that signed in; connection keys keep working.', 'wpmark' )
		);

		submit_button( __( 'Save access', 'wpmark' ) );
		echo '</form></div>';
	}

	/**
	 * Save the Tools tab.
	 */
	public static function save_tools(): void {
		self::check_request( 'wpmark_save_tools' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in check_request(); only the keys are used, each through sanitize_key().
		$posted = isset( $_POST['tools'] ) && is_array( $_POST['tools'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['tools'] ) ) ) : array();
		$tools  = array();

		foreach ( Abilities::of_type( Ability::TOOL ) as $tool ) {
			$tools[ $tool->slug() ] = in_array( $tool->slug(), $posted, true );
		}

		Settings::save_tools( $tools );
		self::redirect( 'tools' );
	}

	/**
	 * Save the Access & privacy tab.
	 */
	public static function save_access(): void {
		self::check_request( 'wpmark_save_access' );

		$choices = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in check_request(); each value is sanitized below, and Settings::save_roles() accepts only known roles and levels.
		$posted = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();

		foreach ( $posted as $slug => $choice ) {
			$choices[ sanitize_key( (string) $slug ) ] = array(
				'enabled' => ! empty( $choice['enabled'] ),
				'leads'   => sanitize_key( (string) ( $choice['leads'] ?? '' ) ),
			);
		}

		Settings::save_roles( $choices );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check_request().
		$oauth = ! empty( $_POST['oauth'] );

		if ( ! $oauth && Settings::oauth_enabled() ) {
			Store::revoke_all();
		}

		Settings::save_oauth( $oauth );
		self::redirect( 'access' );
	}

	/**
	 * Stop unless the request has a valid nonce from an administrator.
	 *
	 * @param string $action Nonce action.
	 */
	private static function check_request( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can change WPMark settings.', 'wpmark' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Go back to a tab after saving.
	 *
	 * @param string $tab Tab slug.
	 */
	private static function redirect( string $tab ): void {
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url( $tab ) ) );
		exit;
	}

	/**
	 * "Settings saved." after a redirect.
	 */
	private static function render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['disconnected'] ) ) {
			wp_admin_notice(
				esc_html__( 'The app was disconnected. It can no longer read this site until someone connects it again.', 'wpmark' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['saved'] ) ) {
			wp_admin_notice(
				esc_html__( 'Settings saved.', 'wpmark' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * Address of a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A "Connect" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'connect' ) ) . '">' . esc_html__( 'Connect', 'wpmark' ) . '</a>' );

		return $links;
	}

	/**
	 * After activation, point administrators to the Connect tab once.
	 */
	public static function welcome_notice(): void {
		if ( ! get_transient( 'wpmark_welcome' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_admin_notice(
			sprintf(
				/* translators: %s: link to the WPMark Connect screen. */
				esc_html__( 'WPMark is active. %s to connect Claude, ChatGPT or Gemini to your site.', 'wpmark' ),
				'<a href="' . esc_url( self::url( 'connect' ) ) . '">' . esc_html__( 'Open WPMark', 'wpmark' ) . '</a>'
			),
			array(
				'type'        => 'success',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Styles for the WPMark screen.
	 */
	public static function styles(): void {
		wp_register_style( 'wpmark-admin', false, array(), WPMARK_VERSION );
		wp_enqueue_style( 'wpmark-admin' );
		wp_add_inline_style(
			'wpmark-admin',
			'.wpmark .wpmark-lead{font-size:14px;max-width:720px}
			.wpmark-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:4px 20px 16px;margin:16px 0;max-width:960px}
			.wpmark-checks{margin:0}.wpmark-check{padding:8px 0;border-bottom:1px solid #f0f0f1;margin:0}
			.wpmark-check .dashicons{margin-right:6px}.wpmark-good .dashicons{color:#00a32a}.wpmark-warning .dashicons{color:#dba617}
			.wpmark-problem .dashicons{color:#d63638}.wpmark-info .dashicons{color:#2271b1}
			.wpmark-fix{display:block;margin:4px 0 0 26px;color:#50575e}
			.wpmark-table{max-width:960px}.wpmark-table select{min-width:300px}.wpmark-code{font-size:12px}
			.wpmark-copied{color:#00a32a;margin-left:8px}
			.wpmark-address input{max-width:560px}.wpmark-card summary{cursor:pointer}.wpmark-inline{display:inline;font-size:1.3em}
			.wpmark-danger{color:#b32d2e}
			.wpmark-badge{font-size:12px;font-weight:600;vertical-align:middle;background:#dcdcde;border-radius:10px;padding:2px 8px}
			.wpmark-footer{max-width:960px;color:#50575e;margin-top:24px}
			p.wpmark-warning{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px}'
		);
	}
}
