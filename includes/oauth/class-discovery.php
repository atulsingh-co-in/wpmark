<?php
/**
 * OAuth discovery.
 *
 * @package WPMark
 */

namespace WPMark\OAuth;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Tells AI apps how to sign in, in the two small JSON files the standards define.
 *
 * - Protected Resource Metadata (RFC 9728): "this MCP address is protected,
 *   and this site signs people in".
 * - Authorization Server Metadata (RFC 8414): where the sign-in, token and
 *   registration endpoints are, and which options are supported.
 *
 * Apps look for these under /.well-known/ at the root of the site, so
 * WPMark answers those addresses itself. They are also available as REST
 * routes, and the 401 reply from the MCP address links straight to the
 * first one, so discovery still works where a host blocks /.well-known/.
 */
final class Discovery {

	/**
	 * Register the REST versions of both documents.
	 */
	public static function register_routes(): void {
		foreach ( array(
			'protected-resource'   => 'protected_resource',
			'authorization-server' => 'authorization_server',
		) as $route => $method ) {
			register_rest_route(
				OAuth::NS,
				'/' . $route,
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, $method ),
					// Public by design: discovery documents describe the endpoints and contain nothing secret.
					'permission_callback' => array( self::class, 'public_document' ),
				)
			);
		}
	}

	/**
	 * Discovery documents are public by definition.
	 *
	 * @return bool
	 */
	public static function public_document(): bool {
		return OAuth::is_enabled();
	}

	/**
	 * Protected Resource Metadata.
	 *
	 * @return array
	 */
	public static function protected_resource(): array {
		return array(
			'resource'                 => OAuth::resource(),
			'authorization_servers'    => array( OAuth::issuer() ),
			'scopes_supported'         => array( OAuth::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => sprintf(
				/* translators: %s: site name. */
				__( 'WPMark on %s', 'wpmark' ),
				wp_strip_all_tags( get_bloginfo( 'name' ) )
			),
		);
	}

	/**
	 * Authorization Server Metadata.
	 *
	 * @return array
	 */
	public static function authorization_server(): array {
		return array(
			'issuer'                                     => OAuth::issuer(),
			'authorization_endpoint'                     => OAuth::url( 'authorize' ),
			'token_endpoint'                             => OAuth::url( 'token' ),
			'registration_endpoint'                      => OAuth::url( 'register' ),
			'revocation_endpoint'                        => OAuth::url( 'revoke' ),
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'token_endpoint_auth_methods_supported'      => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                           => array( OAuth::SCOPE ),
			'authorization_response_iss_parameter_supported' => true,
			'service_documentation'                      => 'https://github.com/atulsingh-co-in/wpmark',
		);
	}

	/**
	 * Answer requests for /.well-known/ discovery documents. Runs early on init.
	 *
	 * Handles the root forms and the path forms apps may try, e.g.
	 * /.well-known/oauth-protected-resource/wp-json/wpmark/mcp and, for sites
	 * in a subfolder, /blog/.well-known/openid-configuration.
	 */
	public static function serve_well_known(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$doc  = self::document_for_path( $path );

		if ( null === $doc || ! OAuth::is_enabled() ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: max-age=300' );

		echo wp_json_encode( 'resource' === $doc ? self::protected_resource() : self::authorization_server(), JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Which document, if any, a request path asks for.
	 *
	 * @param string $path URL path.
	 * @return string|null "resource", "server" or null.
	 */
	public static function document_for_path( string $path ): ?string {
		if ( ! str_contains( $path, '/.well-known/' ) ) {
			return null;
		}

		if ( preg_match( '#/\.well-known/oauth-protected-resource(/.*)?$#', $path ) ) {
			return 'resource';
		}

		if ( preg_match( '#/\.well-known/(oauth-authorization-server|openid-configuration)(/.*)?$#', $path ) ) {
			return 'server';
		}

		return null;
	}

	/**
	 * When the MCP address refuses a request for lack of sign-in, say how to sign in.
	 *
	 * Adds the standard header: WWW-Authenticate: Bearer resource_metadata="…".
	 * AI apps read it to start the sign-in flow.
	 *
	 * @param mixed           $response Response.
	 * @param WP_REST_Server  $server   Server.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function add_challenge_header( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof WP_HTTP_Response || 401 !== $response->get_status() || ! OAuth::is_enabled() ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request || '/' . \WPMark\Mcp_Server::REST_NAMESPACE . '/' . \WPMark\Mcp_Server::REST_ROUTE !== $request->get_route() ) {
			return $response;
		}

		$challenge = sprintf( 'Bearer resource_metadata="%s", scope="%s"', OAuth::url( 'protected-resource' ), OAuth::SCOPE );

		if ( Bearer_Auth::had_invalid_token() ) {
			$challenge .= ', error="invalid_token"';
		}

		$response->header( 'WWW-Authenticate', $challenge );

		return $response;
	}
}
