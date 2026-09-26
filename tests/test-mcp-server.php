<?php
/**
 * Tests for WPMark's MCP server.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP\MCP\Core\McpAdapter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WPMark\Mcp_Server;

/**
 * Mcp_Server: the endpoint AI assistants connect to.
 */
class Test_Mcp_Server extends WP_UnitTestCase {

	/**
	 * REST server with the MCP routes registered, shared by every test here.
	 *
	 * @var WP_REST_Server
	 */
	private static WP_REST_Server $rest_server;

	/**
	 * Start the REST API once for the whole class.
	 *
	 * The MCP Adapter sets itself up the first time rest_api_init fires and
	 * never again. WordPress rolls back every hook added during a test when
	 * that test ends, so if this ran per test, only the first test would see
	 * the routes. Hooks added here, before any test starts, are kept.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::$rest_server = new WP_REST_Server();

		global $wp_rest_server;
		$wp_rest_server = self::$rest_server;
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Make the shared REST server the current one for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = self::$rest_server;
	}

	/**
	 * Remove the REST server so other test classes start clean.
	 */
	public static function tear_down_after_class(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down_after_class();
	}

	/**
	 * The MCP Adapter knows about the WPMark server.
	 */
	public function test_server_created(): void {
		$server = McpAdapter::instance()->get_server( Mcp_Server::ID );

		$this->assertNotNull( $server );
		$this->assertSame( 'WPMark', $server->get_server_name() );
	}

	/**
	 * With only WPMark installed, the adapter's generic default endpoint is off.
	 */
	public function test_default_server_off_when_bundled(): void {
		$this->assertFalse( \WPMark\Plugin::uses_standalone_adapter() );
		$this->assertNull( McpAdapter::instance()->get_server( 'mcp-adapter-default-server' ) );
		$this->assertArrayNotHasKey( '/mcp/mcp-adapter-default-server', rest_get_server()->get_routes() );
	}

	/**
	 * The endpoint is at /wp-json/wpmark/mcp.
	 */
	public function test_route_registered(): void {
		$this->assertArrayHasKey( '/wpmark/mcp', rest_get_server()->get_routes() );
	}

	/**
	 * Without a form plugin, only the content tools are offered.
	 */
	public function test_offers_tools_the_site_supports(): void {
		$this->assertSame(
			array( 'wpmark/site-overview', 'wpmark/search-content', 'wpmark/get-content', 'wpmark/content-inventory', 'wpmark/find-seo-gaps' ),
			Mcp_Server::tool_names()
		);
		$this->assertSame( array( 'wpmark/site-profile' ), Mcp_Server::names( 'resource' ) );
		$this->assertSame( array( 'wpmark/content-plan', 'wpmark/seo-check' ), Mcp_Server::names( 'prompt' ) );
	}

	/**
	 * Only contributors and above can connect; logged-out visitors and subscribers cannot.
	 *
	 * @dataProvider connection_rules
	 *
	 * @param string $role     WordPress role, or "" for logged out.
	 * @param bool   $expected Whether they may connect.
	 */
	public function test_who_can_connect( string $role, bool $expected ): void {
		$user_id = '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$this->assertSame( $expected, Mcp_Server::can_connect() );
	}

	/**
	 * Roles and whether they may connect.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function connection_rules(): array {
		return array(
			'logged out'    => array( '', false ),
			'subscriber'    => array( 'subscriber', false ),
			'contributor'   => array( 'contributor', true ),
			'author'        => array( 'author', true ),
			'editor'        => array( 'editor', true ),
			'administrator' => array( 'administrator', true ),
		);
	}

	/**
	 * A logged-out request to the endpoint is refused.
	 */
	public function test_endpoint_refuses_logged_out_request(): void {
		$response = rest_get_server()->dispatch( $this->mcp_request( 'initialize', $this->initialize_params() ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * A contributor can open a session and sees the WPMark server.
	 */
	public function test_endpoint_answers_initialize(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$response = rest_get_server()->dispatch( $this->mcp_request( 'initialize', $this->initialize_params() ) );
		// Decode the body as a real client would receive it.
		$data = json_decode( wp_json_encode( $response->get_data() ), true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'WPMark', $data['result']['serverInfo']['name'] ?? null );
	}

	/**
	 * End to end, as an AI client does it: open a session, list tools, call one.
	 */
	public function test_session_lists_and_calls_tools(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// A real request also runs rest_post_dispatch, which is where the session header is added.
		$request = $this->mcp_request( 'initialize', $this->initialize_params() );
		$init    = apply_filters( 'rest_post_dispatch', rest_get_server()->dispatch( $request ), rest_get_server(), $request );
		$session = $init->get_headers()['Mcp-Session-Id'] ?? '';

		$this->assertNotSame( '', $session, 'The server opens a session.' );

		$list  = $this->decode( rest_get_server()->dispatch( $this->mcp_request( 'tools/list', array(), $session ) ) );
		$tools = array_column( $list['result']['tools'], null, 'name' );

		$this->assertArrayHasKey( 'wpmark-site-overview', $tools );
		$this->assertArrayNotHasKey( 'wpmark-list-leads', $tools, 'No form plugin, so no lead tools.' );
		$this->assertTrue( $tools['wpmark-find-seo-gaps']['annotations']['readOnlyHint'] );
		$this->assertFalse( $tools['wpmark-find-seo-gaps']['annotations']['destructiveHint'] );
		$this->assertStringContainsString( 'Read-only', $tools['wpmark-get-content']['description'] );

		$call = $this->decode(
			rest_get_server()->dispatch(
				$this->mcp_request(
					'tools/call',
					array(
						'name'      => 'wpmark-site-overview',
						'arguments' => new \stdClass(),
					),
					$session
				)
			)
		);

		$this->assertFalse( $call['result']['isError'] ?? false );
		$this->assertSame( home_url( '/' ), $call['result']['structuredContent']['site']['url'] );

		$prompts = $this->decode( rest_get_server()->dispatch( $this->mcp_request( 'prompts/list', array(), $session ) ) );
		$this->assertContains( 'wpmark-seo-check', array_column( $prompts['result']['prompts'], 'name' ) );

		$resources = $this->decode( rest_get_server()->dispatch( $this->mcp_request( 'resources/list', array(), $session ) ) );
		$this->assertContains( 'wpmark://site/profile', array_column( $resources['result']['resources'], 'uri' ) );
	}

	/**
	 * A response body as a real client would decode it.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return array
	 */
	private function decode( $response ): array {
		return (array) json_decode( wp_json_encode( $response->get_data() ), true );
	}

	/**
	 * Build a JSON-RPC request to the WPMark endpoint.
	 *
	 * @param string $method  JSON-RPC method.
	 * @param array  $params  Parameters.
	 * @param string $session Session ID from initialize, if any.
	 * @return WP_REST_Request
	 */
	private function mcp_request( string $method, array $params, string $session = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wpmark/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );

		if ( '' !== $session ) {
			$request->set_header( 'Mcp-Session-Id', $session );
		}

		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => $params ? $params : new \stdClass(),
				)
			)
		);

		return $request;
	}

	/**
	 * Parameters an MCP client sends when it first connects.
	 *
	 * @return array
	 */
	private function initialize_params(): array {
		return array(
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new \stdClass(),
			'clientInfo'      => array(
				'name'    => 'wpmark-tests',
				'version' => '1.0.0',
			),
		);
	}
}
