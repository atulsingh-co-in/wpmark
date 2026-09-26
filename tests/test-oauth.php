<?php
/**
 * Tests for OAuth sign-in.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMark\OAuth\Authorize;
use WPMark\OAuth\Bearer_Auth;
use WPMark\OAuth\Discovery;
use WPMark\OAuth\OAuth;
use WPMark\OAuth\Registration;
use WPMark\OAuth\Store;
use WPMark\OAuth\Token_Endpoint;
use WPMark\Settings;

/**
 * Discovery, registration, sign-in, tokens, refresh, revocation and bearer auth,
 * including the attack cases each check exists for.
 */
class Test_OAuth extends WP_UnitTestCase {

	private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

	/**
	 * PKCE verifier for the current test.
	 *
	 * @var string
	 */
	private string $verifier;

	/**
	 * The request address before the test, restored afterwards.
	 *
	 * @var string|null
	 */
	private ?string $request_uri;

	/**
	 * OAuth needs HTTPS: give the test site an https address.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		Store::install();
		Bearer_Auth::reset();

		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore later.

		$this->verifier = str_repeat( 'v', 20 ) . wp_generate_password( 40, false );
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		Bearer_Auth::reset();

		parent::tear_down();
	}

	/**
	 * The discovery documents point at the right endpoints and options.
	 */
	public function test_discovery_documents(): void {
		$resource = Discovery::protected_resource();
		$server   = Discovery::authorization_server();

		$this->assertSame( OAuth::resource(), $resource['resource'] );
		$this->assertSame( array( 'https://example.org' ), $resource['authorization_servers'] );
		$this->assertSame( 'https://example.org', $server['issuer'] );
		$this->assertSame( array( 'S256' ), $server['code_challenge_methods_supported'] );
		$this->assertStringEndsWith( '/wpmark/v1/oauth/register', $server['registration_endpoint'] );
		$this->assertStringEndsWith( '/wpmark/v1/oauth/token', $server['token_endpoint'] );
	}

	/**
	 * The /.well-known/ addresses apps try map to the right document.
	 */
	public function test_well_known_paths(): void {
		$this->assertSame( 'resource', Discovery::document_for_path( '/.well-known/oauth-protected-resource' ) );
		$this->assertSame( 'resource', Discovery::document_for_path( '/.well-known/oauth-protected-resource/wp-json/wpmark/mcp' ) );
		$this->assertSame( 'server', Discovery::document_for_path( '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( 'server', Discovery::document_for_path( '/blog/.well-known/openid-configuration' ) );
		$this->assertNull( Discovery::document_for_path( '/.well-known/acme-challenge/abc' ) );
		$this->assertNull( Discovery::document_for_path( '/about/' ) );
	}

	/**
	 * OAuth is off when switched off, or on a plain-http site that is not local.
	 */
	public function test_off_without_https_or_when_switched_off(): void {
		$this->assertTrue( OAuth::is_enabled() );

		Settings::save_oauth( false );
		$this->assertFalse( OAuth::is_enabled() );

		Settings::save_oauth( true );
		update_option( 'home', 'http://example.org' );
		$this->assertFalse( OAuth::is_enabled() );
	}

	/**
	 * An app registers itself and gets a client ID; a confidential app also gets a secret.
	 */
	public function test_registration(): void {
		$public       = $this->register();
		$confidential = $this->register( array( 'token_endpoint_auth_method' => 'client_secret_post' ) );

		$this->assertStringStartsWith( 'wpmark_', $public['client_id'] );
		$this->assertArrayNotHasKey( 'client_secret', $public );
		$this->assertStringStartsWith( 'wpmark_cs_', $confidential['client_secret'] );
		$this->assertSame( 'Claude', Store::get_client( $public['client_id'] )['client_name'] );
	}

	/**
	 * Registration refuses unsafe return addresses and unsupported options.
	 *
	 * @dataProvider bad_registrations
	 *
	 * @param array $data Registration data.
	 */
	public function test_registration_rejects( array $data ): void {
		$response = Registration::handle( $this->json_request( 'register', $data ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );
	}

	/**
	 * Unsafe registrations.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function bad_registrations(): array {
		return array(
			'no redirect'        => array( array( 'client_name' => 'X' ) ),
			'plain http'         => array( array( 'redirect_uris' => array( 'http://evil.example/cb' ) ) ),
			'fragment'           => array( array( 'redirect_uris' => array( 'https://app.example/cb#x' ) ) ),
			'javascript scheme'  => array( array( 'redirect_uris' => array( 'javascript:alert(1)' ) ) ),
			'implicit grant'     => array(
				array(
					'redirect_uris' => array( self::REDIRECT ),
					'grant_types'   => array( 'implicit' ),
				),
			),
			'unknown auth style' => array(
				array(
					'redirect_uris'              => array( self::REDIRECT ),
					'token_endpoint_auth_method' => 'private_key_jwt',
				),
			),
		);
	}

	/**
	 * Desktop tools may use a loopback address.
	 */
	public function test_loopback_redirect_allowed(): void {
		$this->assertTrue( Registration::is_allowed_redirect( 'http://localhost:6274/oauth/callback' ) );
		$this->assertTrue( Registration::is_allowed_redirect( 'http://127.0.0.1:33418/' ) );
		$this->assertFalse( Registration::is_allowed_redirect( 'http://localhost.evil.example/' ) );
	}

	/**
	 * An unknown app or an unregistered return address shows an error and never redirects.
	 */
	public function test_authorize_never_redirects_to_unregistered_address(): void {
		$client = $this->register();

		$unknown = Authorize::process( $this->authorize_request( array( 'client_id' => 'nope' ) ), $this->user( 'editor' ) );
		$foreign = Authorize::process( $this->authorize_request( array( 'client_id' => $client['client_id'], 'redirect_uri' => 'https://evil.example/cb' ) ), $this->user( 'editor' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.

		$this->assertSame( 'page', $unknown['type'] );
		$this->assertSame( 'page', $foreign['type'] );
		$this->assertStringContainsString( 'did not register', $foreign['body'] );
	}

	/**
	 * Without PKCE S256, the app gets an error back.
	 */
	public function test_authorize_requires_pkce(): void {
		$client = $this->register();
		$result = Authorize::process(
			$this->authorize_request(
				array(
					'client_id'             => $client['client_id'],
					'code_challenge_method' => 'plain',
				)
			),
			$this->user( 'editor' )
		);

		$this->assertSame( 'redirect', $result['type'] );
		$this->assertStringStartsWith( self::REDIRECT . '?', $result['url'] );
		$this->assertStringContainsString( 'error=invalid_request', $result['url'] );
		$this->assertStringContainsString( 'state=xyz', $result['url'] );
	}

	/**
	 * Logged-out people are sent to the WordPress login page, then back.
	 */
	public function test_authorize_asks_to_log_in(): void {
		$client = $this->register();
		$result = Authorize::process( $this->authorize_request( array( 'client_id' => $client['client_id'] ) ), null );

		$this->assertSame( 'login', $result['type'] );
		$this->assertStringContainsString( 'wp-login.php', $result['url'] );
		$this->assertStringContainsString( 'redirect_to=', $result['url'] );
	}

	/**
	 * The consent screen names the app, the return address and what it can see.
	 */
	public function test_consent_screen(): void {
		$client = $this->register();
		$result = Authorize::process( $this->authorize_request( array( 'client_id' => $client['client_id'] ) ), $this->user( 'editor' ) );

		$this->assertSame( 'page', $result['type'] );
		$this->assertSame( 'Connect Claude?', $result['title'] );
		$this->assertStringContainsString( 'claude.ai', $result['body'] );
		$this->assertStringContainsString( 'without names, emails or phone numbers', $result['body'] );
		$this->assertStringContainsString( 'cannot publish, change or delete', $result['body'] );
	}

	/**
	 * People whose role is switched off cannot approve.
	 */
	public function test_switched_off_role_cannot_approve(): void {
		$client = $this->register();
		$result = Authorize::process( $this->authorize_request( array( 'client_id' => $client['client_id'] ) ), $this->user( 'subscriber' ) );

		$this->assertSame( 'page', $result['type'] );
		$this->assertStringContainsString( 'not allowed', $result['body'] );
	}

	/**
	 * Allow needs a valid nonce; Cancel sends access_denied back.
	 */
	public function test_decision_needs_nonce_and_cancel_denies(): void {
		$client = $this->register();
		$user   = $this->user( 'editor' );

		$forged = Authorize::process( $this->authorize_request( array( 'client_id' => $client['client_id'], 'decision' => 'allow', '_wpmark_nonce' => 'forged' ), 'POST' ), $user ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
		$cancel = $this->decide( $client, $user, 'deny' );

		$this->assertSame( 'page', $forged['type'] );
		$this->assertStringContainsString( 'error=access_denied', $cancel['url'] );
		$this->assertStringContainsString( 'iss=' . rawurlencode( 'https://example.org' ), $cancel['url'] );
	}

	/**
	 * The full flow: allow, swap the code, use the token, refresh it.
	 */
	public function test_full_flow(): void {
		$client = $this->register();
		$user   = $this->user( 'editor' );
		$code   = $this->code( $client, $user );
		$tokens = $this->exchange( $client, $code );

		$this->assertSame( 'Bearer', $tokens['token_type'] );
		$this->assertStringStartsWith( 'wpmark_at_', $tokens['access_token'] );
		$this->assertSame( $user->ID, Bearer_Auth::user_for_token( $tokens['access_token'] ) );

		$refreshed = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $client['client_id'],
			)
		)->get_data();

		$this->assertNotSame( $tokens['refresh_token'], $refreshed['refresh_token'] );
		$this->assertSame( $user->ID, Bearer_Auth::user_for_token( $refreshed['access_token'] ) );
		$this->assertSame( array( $client['client_id'] ), array_column( Store::connections( $user->ID ), 'client_id' ) );
	}

	/**
	 * A wrong PKCE verifier, return address or client gets nothing.
	 */
	public function test_code_exchange_checks(): void {
		$client = $this->register();
		$other  = $this->register();
		$code   = $this->code( $client, $this->user( 'editor' ) );

		$wrong_verifier = $this->token_request( $this->code_params( $client, $code, array( 'code_verifier' => str_repeat( 'x', 50 ) ) ) );
		$wrong_redirect = $this->token_request( $this->code_params( $client, $code, array( 'redirect_uri' => 'https://claude.ai/other' ) ) );
		$wrong_client   = $this->token_request( $this->code_params( $other, $code ) );

		foreach ( array( $wrong_verifier, $wrong_redirect, $wrong_client ) as $response ) {
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
		}
	}

	/**
	 * A code used twice revokes the tokens the first use produced.
	 */
	public function test_code_reuse_revokes_tokens(): void {
		$client = $this->register();
		$code   = $this->code( $client, $this->user( 'editor' ) );
		$tokens = $this->exchange( $client, $code );
		$again  = $this->token_request( $this->code_params( $client, $code ) );

		$this->assertSame( 'invalid_grant', $again->get_data()['error'] );
		$this->assertNull( Bearer_Auth::user_for_token( $tokens['access_token'] ) );
	}

	/**
	 * An old refresh token coming back revokes the whole sign-in.
	 */
	public function test_refresh_token_reuse_revokes_family(): void {
		$client = $this->register();
		$tokens = $this->exchange( $client, $this->code( $client, $this->user( 'editor' ) ) );
		$params = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $tokens['refresh_token'],
			'client_id'     => $client['client_id'],
		);

		$new    = $this->token_request( $params )->get_data();
		$replay = $this->token_request( $params );

		$this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
		$this->assertNull( Bearer_Auth::user_for_token( $new['access_token'] ) );
	}

	/**
	 * An expired code is refused.
	 */
	public function test_expired_code(): void {
		global $wpdb;

		$client = $this->register();
		$code   = $this->code( $client, $this->user( 'editor' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->update( Store::tokens_table(), array( 'expires_at' => Store::time( time() - 60 ) ), array( 'token_hash' => Store::hash( $code ) ) );

		$this->assertSame( 'invalid_grant', $this->token_request( $this->code_params( $client, $code ) )->get_data()['error'] );
	}

	/**
	 * An app that registered with a secret must send it.
	 */
	public function test_confidential_client_needs_secret(): void {
		$client = $this->register( array( 'token_endpoint_auth_method' => 'client_secret_post' ) );
		$code   = $this->code( $client, $this->user( 'editor' ) );

		$without = $this->token_request( $this->code_params( $client, $code ) );
		$with    = $this->token_request( $this->code_params( $client, $code, array( 'client_secret' => $client['client_secret'] ) ) );

		$this->assertSame( 401, $without->get_status() );
		$this->assertSame( 200, $with->get_status() );
	}

	/**
	 * Switching a role off stops its tokens at once, and blocks refreshing.
	 */
	public function test_switching_role_off_stops_tokens(): void {
		$client = $this->register();
		$tokens = $this->exchange( $client, $this->code( $client, $this->user( 'editor' ) ) );

		Settings::save_roles(
			array(
				'editor' => array(
					'enabled' => '',
					'leads'   => 'none',
				),
			)
		);

		$this->assertNull( Bearer_Auth::user_for_token( $tokens['access_token'] ) );
		$this->assertSame(
			'invalid_grant',
			$this->token_request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $tokens['refresh_token'],
					'client_id'     => $client['client_id'],
				)
			)->get_data()['error']
		);
	}

	/**
	 * Revoking signs the app out.
	 */
	public function test_revocation(): void {
		$client = $this->register();
		$tokens = $this->exchange( $client, $this->code( $client, $this->user( 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/wpmark/v1/oauth/revoke' );
		$request->set_body_params(
			array(
				'token'     => $tokens['refresh_token'],
				'client_id' => $client['client_id'],
			)
		);

		$this->assertSame( 200, Token_Endpoint::revoke( $request )->get_status() );
		$this->assertNull( Bearer_Auth::user_for_token( $tokens['access_token'] ) );
	}

	/**
	 * Tokens are accepted on the MCP address only, never elsewhere in WordPress.
	 */
	public function test_bearer_only_on_mcp_address(): void {
		$client = $this->register();
		$user   = $this->user( 'editor' );
		$tokens = $this->exchange( $client, $this->code( $client, $user ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$_SERVER['REQUEST_URI'] = '/wp-json/wpmark/mcp';
		$this->assertSame( $user->ID, Bearer_Auth::authenticate( false ) );

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users/me';
		$this->assertFalse( Bearer_Auth::authenticate( false ) );

		$_SERVER['REQUEST_URI']        = '/wp-json/wpmark/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wpmark_at_forged';
		$this->assertFalse( Bearer_Auth::authenticate( false ) );
		$this->assertTrue( Bearer_Auth::had_invalid_token() );
	}

	/**
	 * Unauthenticated MCP requests are told where to sign in.
	 */
	public function test_401_links_to_sign_in(): void {
		$request  = new WP_REST_Request( 'POST', '/wpmark/mcp' );
		$response = new \WP_REST_Response( array(), 401 );

		$response = Discovery::add_challenge_header( $response, rest_get_server(), $request );

		$this->assertStringContainsString( 'Bearer resource_metadata="' . OAuth::url( 'protected-resource' ) . '"', $response->get_headers()['WWW-Authenticate'] );
	}

	/**
	 * Register an app.
	 *
	 * @param array $extra Extra registration data.
	 * @return array Registration response.
	 */
	private function register( array $extra = array() ): array {
		$response = Registration::handle(
			$this->json_request(
				'register',
				array_merge(
					array(
						'client_name'   => 'Claude',
						'redirect_uris' => array( self::REDIRECT ),
					),
					$extra
				)
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * A user with a role.
	 *
	 * @param string $role Role.
	 * @return \WP_User
	 */
	private function user( string $role ): \WP_User {
		return self::factory()->user->create_and_get( array( 'role' => $role ) );
	}

	/**
	 * A sign-in request.
	 *
	 * @param array  $params Parameters to add or override.
	 * @param string $method GET or POST.
	 * @return WP_REST_Request
	 */
	private function authorize_request( array $params, string $method = 'GET' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/wpmark/v1/oauth/authorize' );
		$values  = array_merge(
			array(
				'response_type'         => 'code',
				'redirect_uri'          => self::REDIRECT,
				'state'                 => 'xyz',
				'code_challenge'        => self::challenge( $this->verifier ),
				'code_challenge_method' => 'S256',
				'resource'              => OAuth::resource(),
			),
			$params
		);

		if ( 'POST' === $method ) {
			$request->set_body_params( $values );
		} else {
			$request->set_query_params( $values );
		}

		return $request;
	}

	/**
	 * The person's decision on the consent screen, with a valid nonce.
	 *
	 * @param array    $client   Client.
	 * @param \WP_User $user     Person.
	 * @param string   $decision "allow" or "deny".
	 * @return array Result.
	 */
	private function decide( array $client, \WP_User $user, string $decision ): array {
		wp_set_current_user( $user->ID );

		$nonce = wp_create_nonce( 'wpmark_oauth_' . $client['client_id'] . '_' . self::challenge( $this->verifier ) );

		return Authorize::process(
			$this->authorize_request(
				array(
					'client_id'     => $client['client_id'],
					'decision'      => $decision,
					'_wpmark_nonce' => $nonce,
				),
				'POST'
			),
			$user
		);
	}

	/**
	 * Allow, and return the sign-in code from the redirect.
	 *
	 * @param array    $client Client.
	 * @param \WP_User $user   Person.
	 * @return string
	 */
	private function code( array $client, \WP_User $user ): string {
		$result = $this->decide( $client, $user, 'allow' );

		$this->assertSame( 'redirect', $result['type'] );
		parse_str( (string) wp_parse_url( $result['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'xyz', $query['state'] );

		return (string) $query['code'];
	}

	/**
	 * Parameters for swapping a code.
	 *
	 * @param array  $client Client.
	 * @param string $code   Code.
	 * @param array  $extra  Overrides.
	 * @return array
	 */
	private function code_params( array $client, string $code, array $extra = array() ): array {
		return array_merge(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::REDIRECT,
				'client_id'     => $client['client_id'],
				'code_verifier' => $this->verifier,
				'resource'      => OAuth::resource(),
			),
			$extra
		);
	}

	/**
	 * Swap a code for tokens, expecting success.
	 *
	 * @param array  $client Client.
	 * @param string $code   Code.
	 * @return array Tokens.
	 */
	private function exchange( array $client, string $code ): array {
		$response = $this->token_request( $this->code_params( $client, $code ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Call the token endpoint.
	 *
	 * @param array $params Form parameters.
	 * @return \WP_REST_Response
	 */
	private function token_request( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wpmark/v1/oauth/token' );
		$request->set_body_params( $params );

		return Token_Endpoint::token( $request );
	}

	/**
	 * A JSON request.
	 *
	 * @param string $route Route under the OAuth namespace.
	 * @param array  $data  Body.
	 * @return WP_REST_Request
	 */
	private function json_request( string $route, array $data ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wpmark/v1/oauth/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $data ) );

		return $request;
	}

	/**
	 * PKCE S256 challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	private static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256.
	}
}
