<?php
/**
 * OAuth sign-in.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

use WPMark\Mcp_Server;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Sign in with WordPress" for AI apps, following the MCP authorization spec.
 *
 * What the person sees: in Claude or ChatGPT they add a connector with the
 * address https://their-site/wp-json/wpmark/mcp. A window opens on their
 * own site, they log in to WordPress as usual, and click Allow. That is all.
 *
 * What happens underneath (all standard OAuth 2.1, so any compliant app works):
 *
 * 1. Discovery. The app calls the MCP address without a token and gets
 *    "401, sign in first", with a link to a small JSON file describing this
 *    site's sign-in service (Discovery).
 * 2. Registration. The app registers itself automatically and gets a
 *    client ID (Registration, RFC 7591).
 * 3. Sign-in. The app opens the Authorize page. The person logs in and
 *    approves; the page sends them back to the app with a one-time code
 *    (Authorize). PKCE ties the code to the app instance that asked for it.
 * 4. Tokens. The app swaps the code for an access token (one hour) and a
 *    refresh token (30 days, renewed each time it is used) (Token_Endpoint).
 * 5. Use. Each MCP request carries the access token; WPMark treats it as
 *    that WordPress user, so every role and privacy setting applies
 *    (Bearer_Auth). Tokens work only on WPMark's MCP address, nowhere else
 *    in WordPress.
 *
 * Connection keys (Application Passwords) keep working alongside, for apps
 * that do not support OAuth.
 */
final class OAuth {

	/**
	 * REST namespace for the OAuth endpoints.
	 */
	public const NS = 'wpmark/v1/oauth';

	/**
	 * The only scope: read-only use of WPMark.
	 */
	public const SCOPE = 'wpmark';

	/**
	 * Lifetimes, in seconds.
	 */
	public const CODE_LIFETIME    = 600;
	public const ACCESS_LIFETIME  = HOUR_IN_SECONDS;
	public const REFRESH_LIFETIME = 30 * DAY_IN_SECONDS;

	/**
	 * Add the hooks.
	 */
	public static function register(): void {
		Store::maybe_install();

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( Discovery::class, 'serve_well_known' ), 1 );
		add_filter( 'rest_post_dispatch', array( Discovery::class, 'add_challenge_header' ), 10, 3 );
		add_filter( 'determine_current_user', array( Bearer_Auth::class, 'authenticate' ), 30 );

		add_action( 'wpmark_oauth_cleanup', array( Store::class, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'wpmark_oauth_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpmark_oauth_cleanup' );
		}
	}

	/**
	 * Register the OAuth REST routes.
	 */
	public static function register_routes(): void {
		Discovery::register_routes();
		Registration::register_routes();
		Authorize::register_routes();
		Token_Endpoint::register_routes();
	}

	/**
	 * Whether OAuth sign-in is on: switched on in settings, and the site uses
	 * HTTPS (OAuth requires it) unless it is a local development site.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Settings::oauth_enabled() && self::secure_enough();
	}

	/**
	 * HTTPS, or a local development site.
	 *
	 * @return bool
	 */
	public static function secure_enough(): bool {
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) || 'local' === wp_get_environment_type();
	}

	/**
	 * This site's identity as a sign-in service: its home address.
	 *
	 * @return string e.g. "https://example.com".
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * The protected thing: WPMark's MCP address.
	 *
	 * @return string
	 */
	public static function resource(): string {
		return Mcp_Server::url();
	}

	/**
	 * Address of one OAuth endpoint.
	 *
	 * @param string $name "authorize", "token", "register", "revoke", "protected-resource" or "authorization-server".
	 * @return string
	 */
	public static function url( string $name ): string {
		return rest_url( self::NS . '/' . $name );
	}
}
