<?php
/**
 * OAuth access token authentication.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

use WPMark\Mcp_Server;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Recognises OAuth access tokens on WPMark's MCP address, and only there.
 *
 * Hooks into WordPress's own "who is this?" step (determine_current_user).
 * When a request to /wp-json/wpmark/mcp carries "Authorization: Bearer …"
 * with a live token, the request runs as the WordPress user who approved
 * it, so their role and WPMark's privacy settings apply unchanged.
 *
 * A token is ignored on every other address, so it can never be used to
 * call the rest of the WordPress REST API.
 */
final class Bearer_Auth {

	/**
	 * Whether this request sent a token that was not accepted.
	 *
	 * @var bool
	 */
	private static bool $invalid = false;

	/**
	 * The determine_current_user filter.
	 *
	 * @param int|false $user_id User found by earlier checks, if any.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( $user_id || ! OAuth::is_enabled() || ! self::is_mcp_request() ) {
			return $user_id;
		}

		$token = self::bearer_token();

		if ( '' === $token ) {
			return $user_id;
		}

		$user = self::user_for_token( $token );

		if ( null === $user ) {
			self::$invalid = true;
			return $user_id;
		}

		return $user;
	}

	/**
	 * The user a live access token belongs to, if it is still valid.
	 *
	 * @param string $token Access token.
	 * @return int|null User ID.
	 */
	public static function user_for_token( string $token ): ?int {
		$row = Store::find( $token, 'access' );

		if ( ! $row || ! Store::is_live( $row ) ) {
			return null;
		}

		$user = get_userdata( (int) $row['user_id'] );

		// Checked on every request: switching a role off takes effect at once.
		if ( ! $user || ! Settings::user_can_connect( $user ) ) {
			return null;
		}

		Store::touch( (int) $row['id'] );

		return $user->ID;
	}

	/**
	 * Whether this request sent a token that was not accepted.
	 *
	 * @return bool
	 */
	public static function had_invalid_token(): bool {
		return self::$invalid;
	}

	/**
	 * Forget the per-request state. For tests.
	 */
	public static function reset(): void {
		self::$invalid = false;
	}

	/**
	 * The Bearer token from the Authorization header, or "".
	 *
	 * @return string
	 */
	private static function bearer_token(): string {
		$header = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				break;
			}
		}

		return preg_match( '/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i', $header, $m ) ? $m[1] : '';
	}

	/**
	 * Whether this request is for WPMark's MCP address.
	 *
	 * @return bool
	 */
	private static function is_mcp_request(): bool {
		$route = '/' . Mcp_Server::REST_NAMESPACE . '/' . Mcp_Server::REST_ROUTE;

		// Plain permalinks: ?rest_route=/wpmark/mcp.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading which address was requested.
		if ( isset( $_GET['rest_route'] ) && untrailingslashit( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) ) === $route ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// Compared without rest_url(): this can run before WordPress sets up its rewrite rules.
		$path = untrailingslashit( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) );

		return str_ends_with( $path, '/' . rest_get_url_prefix() . $route );
	}
}
