<?php
/**
 * OAuth dynamic client registration.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Lets AI apps register themselves automatically (RFC 7591).
 *
 * Claude, ChatGPT and other MCP apps do this before sign-in, so nobody has
 * to create client IDs by hand. Registration alone grants nothing: an app
 * can only get a token after a WordPress user logs in and clicks Allow.
 *
 * What is checked:
 * - Return addresses must be HTTPS, or a loopback address (localhost,
 *   127.0.0.1) used by desktop tools. No fragments.
 * - Only the sign-in style WPMark supports (authorization code + PKCE).
 * - A cap on how many apps may be registered, so the open endpoint cannot
 *   be used to fill the database. Unused registrations are deleted after a week.
 */
final class Registration {

	/**
	 * Register the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			OAuth::NS,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// Open by design (RFC 7591): registering grants no access on its own.
				'permission_callback' => array( Discovery::class, 'public_document' ),
			)
		);
	}

	/**
	 * Register an app.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params();

		if ( ! is_array( $data ) ) {
			return self::error( 'invalid_client_metadata', 'Send the registration as a JSON object.' );
		}

		$uris = $data['redirect_uris'] ?? null;

		if ( ! is_array( $uris ) || ! $uris || count( $uris ) > 10 ) {
			return self::error( 'invalid_redirect_uri', 'redirect_uris must list between 1 and 10 addresses.' );
		}

		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! self::is_allowed_redirect( $uri ) ) {
				return self::error( 'invalid_redirect_uri', 'Each redirect URI must be an https:// address, or a loopback address for desktop apps, without a fragment.' );
			}
		}

		$grants = (array) ( $data['grant_types'] ?? array( 'authorization_code' ) );
		if ( array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) ) {
			return self::error( 'invalid_client_metadata', 'Only the authorization_code and refresh_token grant types are supported.' );
		}

		$responses = (array) ( $data['response_types'] ?? array( 'code' ) );
		if ( array_diff( $responses, array( 'code' ) ) ) {
			return self::error( 'invalid_client_metadata', 'Only the "code" response type is supported.' );
		}

		$method = (string) ( $data['token_endpoint_auth_method'] ?? 'none' );
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			return self::error( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.' );
		}

		if ( Store::count_clients() >= Store::MAX_CLIENTS ) {
			return self::error( 'temporarily_unavailable', 'Too many apps are registered on this site right now. Try again later.', 503 );
		}

		$name      = sanitize_text_field( (string) ( $data['client_name'] ?? '' ) );
		$client_id = 'wpmark_' . wp_generate_password( 24, false );
		$secret    = 'none' === $method ? null : Store::new_secret( 'wpmark_cs_' );

		Store::add_client(
			array(
				'client_id'     => $client_id,
				'client_name'   => '' !== $name ? mb_substr( $name, 0, 200 ) : __( 'Unnamed AI app', 'wpmark' ),
				'redirect_uris' => $uris,
				'auth_method'   => $method,
				'secret_hash'   => null === $secret ? null : Store::hash( $secret ),
			)
		);

		$body = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => array_values( $uris ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
			'scope'                      => OAuth::SCOPE,
		);

		if ( null !== $secret ) {
			$body['client_secret']            = $secret;
			$body['client_secret_expires_at'] = 0;
		}

		$response = new WP_REST_Response( $body, 201 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Whether an address may receive sign-in codes.
	 *
	 * @param string $uri Address.
	 * @return bool
	 */
	public static function is_allowed_redirect( string $uri ): bool {
		$parts = wp_parse_url( $uri );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['fragment'] ) || strlen( $uri ) > 2000 ) {
			return false;
		}

		if ( 'https' === strtolower( $parts['scheme'] ) ) {
			return true;
		}

		// Desktop tools receive the code on their own computer (RFC 8252).
		return 'http' === strtolower( $parts['scheme'] ) && in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	/**
	 * An RFC 7591 error response. Messages are for developers, so not translated.
	 *
	 * @param string $code    Error code.
	 * @param string $message Description.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $message,
			),
			$status
		);
	}
}
