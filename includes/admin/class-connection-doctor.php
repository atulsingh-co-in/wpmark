<?php
/**
 * Connection doctor.
 *
 * @package WPMark
 */

namespace WPMark\Admin;

use WP\MCP\Core\McpAdapter;
use WP_REST_Request;
use WPMark\Abilities\Abilities;
use WPMark\Abilities\Ability;
use WPMark\Forms\Form_Sources;
use WPMark\Mcp_Server;
use WPMark\OAuth\Bearer_Auth;
use WPMark\OAuth\Connection_Log;
use WPMark\OAuth\OAuth;
use WPMark\OAuth\Store;
use WPMark\Privacy\Lead_Access;
use WPMark\Seo\Seo_Sources;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the things that stop AI assistants connecting, and says how to fix them.
 *
 * Most "it doesn't connect" problems are not WPMark bugs but site setup:
 * no HTTPS, Application Passwords switched off by a security plugin, a
 * firewall blocking the API, or the web server dropping the Authorization
 * header. Each check returns a status and, when something is wrong, a fix
 * written for a non-developer.
 */
final class Connection_Doctor {

	public const GOOD    = 'good';
	public const WARNING = 'warning';
	public const PROBLEM = 'problem';
	public const INFO    = 'info';

	/**
	 * REST route used to test whether the Authorization header arrives.
	 */
	public const CHECK_ROUTE = 'wpmark/v1/connection-check';

	/**
	 * Checks that only look at settings. Fast; safe to run on every page view.
	 *
	 * @return array<int, array{id: string, label: string, status: string, message: string, fix: string}>
	 */
	public static function quick_checks(): array {
		return array(
			self::wordpress_version(),
			self::mcp_adapter(),
			self::https(),
			self::application_passwords(),
			self::oauth(),
			self::recent_connections(),
			self::permalinks(),
			self::your_access(),
			self::data_sources(),
		);
	}

	/**
	 * Every check, including live requests from the site to itself.
	 *
	 * @return array<int, array>
	 */
	public static function all_checks(): array {
		return array_merge( self::quick_checks(), array( self::endpoint(), self::authorization_header(), self::well_known() ) );
	}

	/**
	 * A plain-text report to paste into a support request.
	 *
	 * Versions, active plugin names and the check results: what is needed to
	 * see why an app cannot connect. No leads, passwords, keys or addresses.
	 *
	 * @param array<int, array> $checks Results from all_checks().
	 * @return string
	 */
	public static function report( array $checks ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$plugins[] = $data['Name'] . ' ' . $data['Version'];
			}
		}

		$lines = array(
			'WPMark ' . WPMARK_VERSION,
			'WordPress ' . get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' ),
			'PHP ' . PHP_VERSION,
			'Plugins: ' . implode( ', ', $plugins ),
			'',
		);

		foreach ( $checks as $check ) {
			$lines[] = sprintf( '[%s] %s: %s', strtoupper( $check['status'] ), $check['label'], $check['message'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Register the REST route used by the Authorization header check.
	 */
	public static function register_route(): void {
		register_rest_route(
			'wpmark/v1',
			'/connection-check',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'route_callback' ),
				'permission_callback' => array( self::class, 'route_permission' ),
			)
		);
	}

	/**
	 * Anyone may call the check route. It reveals nothing: it only says
	 * whether the one-time code the doctor just sent arrived intact.
	 *
	 * @return bool
	 */
	public static function route_permission(): bool {
		return true;
	}

	/**
	 * Report whether the Authorization header carrying the doctor's code arrived.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{received: bool}
	 */
	public static function route_callback( WP_REST_Request $request ): array {
		unset( $request );

		// Read exactly as sign-in reads it, so this check tells the truth about sign-in.
		$header   = Bearer_Auth::authorization_header();
		$expected = get_transient( 'wpmark_connection_check' );

		return array( 'received' => is_string( $expected ) && '' !== $expected && hash_equals( 'WPMark-Check ' . $expected, $header ) );
	}

	/**
	 * WordPress 6.9 or newer.
	 *
	 * @return array
	 */
	private static function wordpress_version(): array {
		$ok = function_exists( 'wp_register_ability' );

		return self::result(
			'wordpress_version',
			__( 'WordPress version', 'wpmark' ),
			$ok ? self::GOOD : self::PROBLEM,
			/* translators: %s: WordPress version. */
			sprintf( __( 'WordPress %s.', 'wpmark' ), get_bloginfo( 'version' ) ),
			$ok ? '' : __( 'Update WordPress to 6.9 or newer from Dashboard → Updates.', 'wpmark' )
		);
	}

	/**
	 * The MCP Adapter (bundled with WPMark) is loaded.
	 *
	 * @return array
	 */
	private static function mcp_adapter(): array {
		$ok = class_exists( McpAdapter::class );

		if ( ! $ok ) {
			return self::result(
				'mcp_adapter',
				__( 'Connection engine (MCP Adapter)', 'wpmark' ),
				self::PROBLEM,
				__( 'Missing: this copy of WPMark is incomplete.', 'wpmark' ),
				__( 'Delete this copy and install wpmark.zip from the Releases page of the WPMark GitHub repository (not GitHub\'s "Download ZIP" button).', 'wpmark' )
			);
		}

		$source = self::adapter_source();

		return self::result(
			'mcp_adapter',
			__( 'Connection engine (MCP Adapter)', 'wpmark' ),
			self::GOOD,
			'' === $source
				/* translators: %s: version number. */
				? sprintf( __( 'Built into WPMark (version %s).', 'wpmark' ), McpAdapter::VERSION )
				/* translators: 1: plugin name, 2: version number. */
				: sprintf( __( 'Provided by %1$s (reports version %2$s). Several plugins include the MCP Adapter and only one copy loads; WPMark works with it.', 'wpmark' ), $source, McpAdapter::VERSION )
		);
	}

	/**
	 * Which plugin the loaded MCP Adapter copy came from, or "" for WPMark's own.
	 *
	 * @return string Plugin name.
	 */
	private static function adapter_source(): string {
		$file = (string) ( new \ReflectionClass( McpAdapter::class ) )->getFileName();

		if ( str_starts_with( wp_normalize_path( $file ), wp_normalize_path( WPMARK_DIR ) ) ) {
			return '';
		}

		$relative = ltrim( substr( wp_normalize_path( $file ), strlen( wp_normalize_path( WP_PLUGIN_DIR ) ) ), '/' );
		$folder   = strtok( $relative, '/' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $plugin_file => $data ) {
			if ( str_starts_with( $plugin_file, $folder . '/' ) ) {
				return (string) $data['Name'];
			}
		}

		return (string) $folder;
	}

	/**
	 * Sign-in for AI apps (OAuth) is on and possible.
	 *
	 * @return array
	 */
	private static function oauth(): array {
		$label = __( 'Sign-in for Claude and ChatGPT (OAuth)', 'wpmark' );

		if ( ! \WPMark\Settings::oauth_enabled() ) {
			return self::result( 'oauth', $label, self::INFO, __( 'Switched off. Apps can still connect with connection keys.', 'wpmark' ), __( 'Switch it on under WPMark → Access & privacy.', 'wpmark' ) );
		}

		if ( ! \WPMark\OAuth\OAuth::secure_enough() ) {
			return self::result( 'oauth', $label, self::PROBLEM, __( 'Not available: sign-in needs a secure (https://) site address.', 'wpmark' ), __( 'Switch the site to HTTPS (see "Secure connection" above).', 'wpmark' ) );
		}

		return self::result( 'oauth', $label, self::GOOD, __( 'Ready. Paste the connection address into Claude or ChatGPT and sign in.', 'wpmark' ) );
	}

	/**
	 * What happened when AI apps last called WPMark after signing in.
	 *
	 * The most common failure after a successful sign-in is a web server or
	 * proxy that removes the Authorization header: the app signed in, but
	 * its requests arrive without the access token and are refused. From
	 * outside this only looks like "Last used: Not yet" and a vague error in
	 * the app, so it is spelled out here.
	 *
	 * @return array
	 */
	private static function recent_connections(): array {
		$label = __( 'Recent connections from AI apps', 'wpmark' );

		if ( ! OAuth::is_enabled() ) {
			return self::result( 'recent_connections', $label, self::INFO, __( 'Not checked: sign-in is off.', 'wpmark' ) );
		}

		$log       = Connection_Log::all();
		$latest    = Connection_Log::latest();
		$signed_in = 0;

		foreach ( Store::connections() as $connection ) {
			$signed_in = max( $signed_in, (int) strtotime( $connection['connected_at'] . ' UTC' ) );
		}

		$ok       = (int) ( $log[ Connection_Log::OK ]['time'] ?? 0 );
		$no_token = (int) ( $log[ Connection_Log::NO_TOKEN ]['time'] ?? 0 );

		if ( $signed_in && $ok < $signed_in && $no_token > $signed_in ) {
			return self::result(
				'recent_connections',
				$label,
				self::PROBLEM,
				__( 'An AI app signed in, but its requests reach WordPress without its sign-in pass, so they are refused. Your web server, or a CDN or firewall in front of it, removes the Authorization header.', 'wpmark' ),
				__( 'On Apache or LiteSpeed hosting (including Hostinger), add this line at the top of the site\'s .htaccess file: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1 . If a CDN or firewall is in front of the site (Cloudflare, Hostinger CDN, Sucuri), make sure it passes the Authorization header on. Then disconnect WPMark in your AI app and connect again.', 'wpmark' )
			);
		}

		if ( null !== $latest && in_array( $latest['outcome'], array( Connection_Log::UNKNOWN, Connection_Log::EXPIRED ), true ) ) {
			return self::result(
				'recent_connections',
				$label,
				self::WARNING,
				/* translators: %s: how long ago, for example "5 mins". */
				sprintf( __( '%s ago an AI app used a sign-in that WPMark no longer recognises (expired, disconnected, or from before a reinstall).', 'wpmark' ), human_time_diff( $latest['time'] ) ),
				__( 'In your AI app, disconnect WPMark and connect again.', 'wpmark' )
			);
		}

		if ( null !== $latest && Connection_Log::NOT_ALLOWED === $latest['outcome'] ) {
			return self::result(
				'recent_connections',
				$label,
				self::WARNING,
				__( 'An AI app signed in as someone whose role is not allowed to use WPMark, so it was refused.', 'wpmark' ),
				__( 'Allow the role under WPMark → Access & privacy, or connect as someone whose role is allowed.', 'wpmark' )
			);
		}

		if ( $ok ) {
			$app = (string) ( $log[ Connection_Log::OK ]['app'] ?? '' );

			return self::result(
				'recent_connections',
				$label,
				self::GOOD,
				'' !== $app
					/* translators: 1: how long ago, for example "5 mins", 2: app name. */
					? sprintf( __( 'Working. Last successful request %1$s ago, from %2$s.', 'wpmark' ), human_time_diff( $ok ), $app )
					/* translators: %s: how long ago, for example "5 mins". */
					: sprintf( __( 'Working. Last successful request %s ago.', 'wpmark' ), human_time_diff( $ok ) )
			);
		}

		if ( $signed_in ) {
			return self::result(
				'recent_connections',
				$label,
				self::WARNING,
				__( 'An AI app signed in, but none of its requests have reached WordPress since.', 'wpmark' ),
				__( 'If your AI app showed an error after you clicked Allow, something in front of WordPress (a firewall, CDN or security plugin) may be blocking /wp-json/wpmark/mcp. Allow that address, then connect again.', 'wpmark' )
			);
		}

		return self::result( 'recent_connections', $label, self::INFO, __( 'No AI app has connected through sign-in yet.', 'wpmark' ) );
	}

	/**
	 * AI apps find the sign-in service at /.well-known/ addresses on the site's root.
	 *
	 * Some servers keep /.well-known/ for SSL certificates only and never pass
	 * these requests to WordPress, which breaks sign-in discovery.
	 *
	 * @return array
	 */
	private static function well_known(): array {
		$label = __( 'Sign-in discovery (/.well-known/)', 'wpmark' );

		if ( ! \WPMark\OAuth\OAuth::is_enabled() ) {
			return self::result( 'well_known', $label, self::INFO, __( 'Not checked: sign-in is off.', 'wpmark' ) );
		}

		$url      = trailingslashit( \WPMark\OAuth\OAuth::issuer() ) . '.well-known/oauth-authorization-server';
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return self::result( 'well_known', $label, self::WARNING, __( 'The site could not reach itself to test this.', 'wpmark' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && is_array( $body ) && ( $body['issuer'] ?? '' ) === \WPMark\OAuth\OAuth::issuer() ) {
			return self::result( 'well_known', $label, self::GOOD, __( 'AI apps can find this site\'s sign-in service.', 'wpmark' ) );
		}

		return self::result(
			'well_known',
			$label,
			self::PROBLEM,
			__( 'The web server does not pass /.well-known/ addresses to WordPress, so AI apps cannot find the sign-in service.', 'wpmark' ),
			__( 'Ask your host to let WordPress answer /.well-known/oauth-authorization-server and /.well-known/oauth-protected-resource (on Nginx: send them to index.php like other pages). If WordPress is installed in a subfolder, this needs access to the domain root.', 'wpmark' )
		);
	}

	/**
	 * The site uses HTTPS. Application Passwords require it outside local development.
	 *
	 * @return array
	 */
	private static function https(): array {
		$https = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$local = 'local' === wp_get_environment_type();

		if ( $https ) {
			return self::result( 'https', __( 'Secure connection (HTTPS)', 'wpmark' ), self::GOOD, __( 'The site uses HTTPS.', 'wpmark' ) );
		}

		return self::result(
			'https',
			__( 'Secure connection (HTTPS)', 'wpmark' ),
			$local ? self::WARNING : self::PROBLEM,
			$local ? __( 'Not using HTTPS. Allowed on a local development site only.', 'wpmark' ) : __( 'The site does not use HTTPS, so WordPress will not allow connection keys (Application Passwords).', 'wpmark' ),
			__( 'Ask your host to switch on a free SSL certificate (most offer Let\'s Encrypt), then change the site address to https:// under Settings → General.', 'wpmark' )
		);
	}

	/**
	 * Application Passwords are available. Security plugins often switch them off.
	 *
	 * @return array
	 */
	private static function application_passwords(): array {
		$ok = wp_is_application_passwords_available();

		return self::result(
			'application_passwords',
			__( 'Connection keys (Application Passwords)', 'wpmark' ),
			$ok ? self::GOOD : self::PROBLEM,
			$ok ? __( 'Available.', 'wpmark' ) : __( 'Switched off on this site.', 'wpmark' ),
			$ok ? '' : __( 'They are usually switched off by a security plugin (Wordfence: Login Security → Settings → "Disable application passwords"; others have a similar setting). Switch them back on. They need HTTPS too.', 'wpmark' )
		);
	}

	/**
	 * Pretty permalinks give the endpoint a clean address.
	 *
	 * @return array
	 */
	private static function permalinks(): array {
		$pretty = '' !== (string) get_option( 'permalink_structure' );

		return self::result(
			'permalinks',
			__( 'Permalinks', 'wpmark' ),
			$pretty ? self::GOOD : self::WARNING,
			$pretty
				? __( 'Pretty permalinks are on.', 'wpmark' )
				: __( 'Plain permalinks are in use, so the connection address uses ?rest_route= instead of /wp-json/. It still works; the setup instructions use the right address.', 'wpmark' ),
			$pretty ? '' : __( 'Optional: choose "Post name" under Settings → Permalinks for cleaner addresses.', 'wpmark' )
		);
	}

	/**
	 * What the current person can do through WPMark.
	 *
	 * @return array
	 */
	private static function your_access(): array {
		$user  = wp_get_current_user();
		$can   = Settings::user_can_connect( $user );
		$leads = Settings::lead_access_for( $user );

		return self::result(
			'your_access',
			__( 'Your access', 'wpmark' ),
			$can ? self::GOOD : self::WARNING,
			$can
				/* translators: %s: lead access level, e.g. "Leads with masked contact details". */
				? sprintf( __( 'You can connect. Leads: %s.', 'wpmark' ), Lead_Access::label( $leads ) )
				: __( 'Your role is not allowed to use WPMark.', 'wpmark' ),
			$can ? '' : __( 'An administrator can switch on your role under WPMark → Access & privacy.', 'wpmark' )
		);
	}

	/**
	 * Which form and SEO plugins WPMark found, and how many tools are on.
	 *
	 * @return array
	 */
	private static function data_sources(): array {
		$forms = array();

		foreach ( Form_Sources::available() as $source ) {
			$forms[] = $source->supports_entries()
				? $source->get_label()
				/* translators: %s: form plugin name. */
				: sprintf( __( '%s (does not save leads)', 'wpmark' ), $source->get_label() );
		}

		$seo   = Seo_Sources::active();
		$tools = count( Abilities::offered( Ability::TOOL ) );

		$message = sprintf(
			/* translators: 1: number of tools, 2: form plugins, 3: SEO plugin. */
			__( '%1$d tools offered. Form plugins: %2$s. SEO plugin: %3$s.', 'wpmark' ),
			$tools,
			$forms ? implode( ', ', $forms ) : __( 'none found', 'wpmark' ),
			$seo ? $seo->get_label() : __( 'none found', 'wpmark' )
		);

		return self::result(
			'data_sources',
			__( 'What WPMark can read', 'wpmark' ),
			self::INFO,
			$message,
			$forms ? '' : __( 'To review leads, use Elementor Pro, WPForms (paid), or the free Contact Form 7 with the free Flamingo plugin.', 'wpmark' )
		);
	}

	/**
	 * The MCP endpoint answers, from the site's own point of view.
	 *
	 * An unauthenticated request should be refused with 401: that proves the
	 * address exists and nothing in front of WordPress is blocking it.
	 *
	 * @return array
	 */
	private static function endpoint(): array {
		$label    = __( 'Connection address', 'wpmark' );
		$response = wp_remote_post(
			Mcp_Server::url(),
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'                   => 'application/json',
					'Accept'                         => 'application/json, text/event-stream',
					Connection_Log::SELF_TEST_HEADER => '1',
				),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'ping',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result(
				'endpoint',
				$label,
				self::WARNING,
				__( 'The site could not reach itself to test this. Many hosts block that; it does not always mean AI apps cannot connect.', 'wpmark' ),
				__( 'Try connecting from your AI app. If it fails, ask your host whether requests to /wp-json/ are blocked.', 'wpmark' )
			);
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$type   = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$cached = self::cache_hit( $response );

		if ( $cached ) {
			return self::result(
				'endpoint',
				$label,
				self::PROBLEM,
				/* translators: %s: caching header. */
				sprintf( __( 'A cache answered instead of WordPress (%s).', 'wpmark' ), $cached ),
				__( 'Exclude /wp-json/ from your caching plugin, CDN or host cache (for example Cloudflare: add a Cache Rule to bypass cache for /wp-json/*).', 'wpmark' )
			);
		}

		if ( in_array( $code, array( 200, 401, 403 ), true ) && str_contains( $type, 'json' ) ) {
			return self::result( 'endpoint', $label, self::GOOD, __( 'The connection address answers and asks for a login, as it should.', 'wpmark' ) );
		}

		if ( 404 === $code ) {
			return self::result(
				'endpoint',
				$label,
				self::PROBLEM,
				__( 'The connection address was not found (404).', 'wpmark' ),
				__( 'Re-save Settings → Permalinks to refresh the site\'s addresses. If that does not help, a plugin may be switching off the WordPress REST API.', 'wpmark' )
			);
		}

		return self::result(
			'endpoint',
			$label,
			self::PROBLEM,
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Something in front of WordPress blocked the request (status %d, not a WordPress reply).', 'wpmark' ), $code ),
			__( 'A firewall or security plugin (Wordfence, Sucuri, Cloudflare, ModSecurity…) is probably blocking the API. Allow requests to /wp-json/wpmark/ or ask your host to.', 'wpmark' )
		);
	}

	/**
	 * The web server passes the Authorization header on to WordPress.
	 *
	 * AI apps log in by sending an Authorization header. Some Apache and
	 * FastCGI setups drop it, and every login then fails with "not allowed".
	 *
	 * @return array
	 */
	private static function authorization_header(): array {
		$label = __( 'Login header reaches WordPress', 'wpmark' );
		$code  = wp_generate_password( 32, false );

		set_transient( 'wpmark_connection_check', $code, MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			rest_url( self::CHECK_ROUTE ),
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'WPMark-Check ' . $code ),
			)
		);

		delete_transient( 'wpmark_connection_check' );

		if ( is_wp_error( $response ) ) {
			return self::result(
				'authorization_header',
				$label,
				self::WARNING,
				__( 'The site could not reach itself to test this.', 'wpmark' ),
				__( 'If AI apps say "not allowed" with a correct key, see the fix for this check in the WPMark README.', 'wpmark' )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['received'] ) ) {
			return self::result( 'authorization_header', $label, self::GOOD, __( 'The web server passes the login header on to WordPress.', 'wpmark' ) );
		}

		return self::result(
			'authorization_header',
			$label,
			self::PROBLEM,
			__( 'The web server removes the login header, so AI apps will be refused even with a correct key.', 'wpmark' ),
			__( 'On Apache hosting, add this line near the top of the site\'s .htaccess file (or ask your host to): SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1', 'wpmark' )
		);
	}

	/**
	 * A caching header saying the response came from a cache, if any.
	 *
	 * @param array $response HTTP response.
	 * @return string Header and value, or "".
	 */
	private static function cache_hit( array $response ): string {
		foreach ( array( 'cf-cache-status', 'x-cache', 'x-litespeed-cache', 'x-proxy-cache', 'x-sucuri-cache', 'x-kinsta-cache' ) as $header ) {
			$value = (string) wp_remote_retrieve_header( $response, $header );

			if ( str_contains( strtolower( $value ), 'hit' ) ) {
				return $header . ': ' . $value;
			}
		}

		return '';
	}

	/**
	 * One check's result.
	 *
	 * @param string $id      Check ID.
	 * @param string $label   Name.
	 * @param string $status  GOOD, WARNING, PROBLEM or INFO.
	 * @param string $message What was found.
	 * @param string $fix     How to fix it, or "".
	 * @return array{id: string, label: string, status: string, message: string, fix: string}
	 */
	private static function result( string $id, string $label, string $status, string $message, string $fix = '' ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'fix'     => $fix,
		);
	}
}
