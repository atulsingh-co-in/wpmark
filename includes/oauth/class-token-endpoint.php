<?php
/**
 * OAuth token and revocation endpoints.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

use WP_REST_Request;
use WP_REST_Response;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exchanges sign-in codes and refresh tokens for access tokens, and revokes them.
 *
 * The checks that make this safe:
 * - A code works once, within 10 minutes, for the app it was issued to,
 *   with the same return address, and only with the PKCE verifier that
 *   matches the challenge sent at sign-in.
 * - Refresh tokens are replaced on every use. If a used code or an old
 *   refresh token is ever presented again, someone copied it: every token
 *   from that sign-in is revoked at once.
 * - The person must still be allowed to use WPMark when tokens are issued.
 * - Apps that registered with a secret must send it.
 */
final class Token_Endpoint {

	/**
	 * Register the routes.
	 */
	public static function register_routes(): void {
		foreach ( array(
			'token'  => 'token',
			'revoke' => 'revoke',
		) as $route => $method ) {
			register_rest_route(
				OAuth::NS,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, $method ),
					// Public by design: callers prove themselves with a code, refresh token or client secret.
					'permission_callback' => array( Discovery::class, 'public_document' ),
				)
			);
		}
	}

	/**
	 * The token endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function token( WP_REST_Request $request ): WP_REST_Response {
		$client = self::authenticate_client( $request );

		if ( ! $client ) {
			return self::error( 'invalid_client', 'Unknown client, or wrong client credentials.', 401 );
		}

		switch ( (string) $request->get_param( 'grant_type' ) ) {
			case 'authorization_code':
				return self::from_code( $request, $client );
			case 'refresh_token':
				return self::from_refresh_token( $request, $client );
			default:
				return self::error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
		}
	}

	/**
	 * Swap a sign-in code for tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $client  Authenticated client.
	 * @return WP_REST_Response
	 */
	private static function from_code( WP_REST_Request $request, array $client ): WP_REST_Response {
		$row = Store::find( (string) $request->get_param( 'code' ), 'code' );

		if ( ! $row || $row['client_id'] !== $client['client_id'] ) {
			return self::error( 'invalid_grant', 'The code is not valid for this client.' );
		}

		// A code presented twice means it was intercepted: revoke everything it produced.
		if ( (int) $row['used'] || (int) $row['revoked'] ) {
			Store::revoke_family( $row['family'] );
			return self::error( 'invalid_grant', 'The code was already used.' );
		}

		if ( ! Store::is_live( $row ) ) {
			return self::error( 'invalid_grant', 'The code expired. Sign in again.' );
		}

		if ( (string) $request->get_param( 'redirect_uri' ) !== (string) $row['redirect_uri'] ) {
			return self::error( 'invalid_grant', 'redirect_uri does not match the one used at sign-in.' );
		}

		if ( ! self::pkce_matches( (string) $request->get_param( 'code_verifier' ), (string) $row['code_challenge'] ) ) {
			return self::error( 'invalid_grant', 'code_verifier does not match the code_challenge.' );
		}

		$resource = (string) $request->get_param( 'resource' );
		if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( OAuth::resource() ) ) {
			return self::error( 'invalid_target', 'The resource must be this site\'s WPMark MCP address.' );
		}

		if ( ! Store::mark_used( (int) $row['id'] ) ) {
			Store::revoke_family( $row['family'] );
			return self::error( 'invalid_grant', 'The code was already used.' );
		}

		return self::issue_tokens( $row, $client );
	}

	/**
	 * Swap a refresh token for new tokens (and retire the old one).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $client  Authenticated client.
	 * @return WP_REST_Response
	 */
	private static function from_refresh_token( WP_REST_Request $request, array $client ): WP_REST_Response {
		$row = Store::find( (string) $request->get_param( 'refresh_token' ), 'refresh' );

		if ( ! $row || $row['client_id'] !== $client['client_id'] ) {
			return self::error( 'invalid_grant', 'The refresh token is not valid for this client.' );
		}

		// An old refresh token coming back means it was copied: revoke the whole sign-in.
		if ( (int) $row['used'] || (int) $row['revoked'] ) {
			Store::revoke_family( $row['family'] );
			return self::error( 'invalid_grant', 'The refresh token was already used or revoked. Sign in again.' );
		}

		if ( ! Store::is_live( $row ) ) {
			return self::error( 'invalid_grant', 'The refresh token expired. Sign in again.' );
		}

		if ( ! Store::mark_used( (int) $row['id'] ) ) {
			Store::revoke_family( $row['family'] );
			return self::error( 'invalid_grant', 'The refresh token was already used. Sign in again.' );
		}

		return self::issue_tokens( $row, $client );
	}

	/**
	 * Issue a new access token and refresh token in the same family.
	 *
	 * @param array $row    The code or refresh token being exchanged.
	 * @param array $client Client.
	 * @return WP_REST_Response
	 */
	private static function issue_tokens( array $row, array $client ): WP_REST_Response {
		$user = get_userdata( (int) $row['user_id'] );

		if ( ! $user || ! Settings::user_can_connect( $user ) ) {
			Store::revoke_family( $row['family'] );
			return self::error( 'invalid_grant', 'This WordPress account is no longer allowed to use WPMark.' );
		}

		$fields = array(
			'client_id' => $client['client_id'],
			'user_id'   => $user->ID,
			'family'    => $row['family'],
			'resource'  => $row['resource'],
			'scope'     => OAuth::SCOPE,
		);

		$response = new WP_REST_Response(
			array(
				'access_token'  => Store::issue( 'access', $fields, OAuth::ACCESS_LIFETIME ),
				'token_type'    => 'Bearer',
				'expires_in'    => OAuth::ACCESS_LIFETIME,
				'refresh_token' => Store::issue( 'refresh', $fields, OAuth::REFRESH_LIFETIME ),
				'scope'         => OAuth::SCOPE,
			),
			200
		);

		return self::no_store( $response );
	}

	/**
	 * The revocation endpoint (RFC 7009): an app signs out.
	 *
	 * Always answers 200, whether or not the token existed, as the RFC requires.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function revoke( WP_REST_Request $request ): WP_REST_Response {
		$client = self::authenticate_client( $request );

		if ( ! $client ) {
			return self::error( 'invalid_client', 'Unknown client, or wrong client credentials.', 401 );
		}

		$token = (string) $request->get_param( 'token' );

		foreach ( array( 'refresh', 'access' ) as $type ) {
			$row = Store::find( $token, $type );

			if ( $row && $row['client_id'] === $client['client_id'] ) {
				Store::revoke_family( $row['family'] );
				break;
			}
		}

		return self::no_store( new WP_REST_Response( null, 200 ) );
	}

	/**
	 * Identify the app, checking its secret when it registered with one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|null Client, or null when unknown or the secret is wrong.
	 */
	private static function authenticate_client( WP_REST_Request $request ): ?array {
		$client_id = (string) $request->get_param( 'client_id' );
		$secret    = (string) $request->get_param( 'client_secret' );
		$basic     = (string) $request->get_header( 'authorization' );

		if ( str_starts_with( strtolower( $basic ), 'basic ' ) ) {
			$pair = explode( ':', (string) base64_decode( substr( $basic, 6 ), true ), 2 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic authentication is defined as base64.

			if ( 2 === count( $pair ) ) {
				$client_id = rawurldecode( $pair[0] );
				$secret    = rawurldecode( $pair[1] );
			}
		}

		$client = '' !== $client_id ? Store::get_client( $client_id ) : null;

		if ( ! $client ) {
			return null;
		}

		if ( 'none' !== $client['auth_method'] && ! hash_equals( (string) $client['secret_hash'], Store::hash( $secret ) ) ) {
			return null;
		}

		return $client;
	}

	/**
	 * Whether a PKCE verifier matches its S256 challenge.
	 *
	 * @param string $verifier  code_verifier from the app.
	 * @param string $challenge code_challenge sent at sign-in.
	 * @return bool
	 */
	public static function pkce_matches( string $verifier, string $challenge ): bool {
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) {
			return false;
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 is defined as base64url(SHA-256).

		return hash_equals( $challenge, $computed );
	}

	/**
	 * An OAuth error response. Descriptions are for developers, so not translated.
	 *
	 * @param string $code        Error code.
	 * @param string $description Description.
	 * @param int    $status      HTTP status.
	 * @return WP_REST_Response
	 */
	private static function error( string $code, string $description, int $status = 400 ): WP_REST_Response {
		return self::no_store(
			new WP_REST_Response(
				array(
					'error'             => $code,
					'error_description' => $description,
				),
				$status
			)
		);
	}

	/**
	 * Tokens must never be cached.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
