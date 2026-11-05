<?php
/**
 * Tests for the WPMark admin screens, connection wizard and health check.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use Exception;
use WP_Application_Passwords;
use WP_UnitTestCase;
use WPDieException;
use WPMark\Admin\Admin;
use WPMark\Admin\Connection_Doctor;
use WPMark\Admin\Connection_Wizard;
use WPMark\Links;
use WPMark\Privacy\Lead_Access;
use WPMark\Settings;

/**
 * Admin screens, connection keys and the connection doctor.
 */
class Test_Admin extends WP_UnitTestCase {

	/**
	 * Application Passwords need HTTPS; the test site has none, so allow them.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * Editors see only Connect; administrators see every tab.
	 */
	public function test_tabs_follow_capability(): void {
		$this->login( 'editor' );
		$this->assertSame( array( 'connect' ), array_keys( Admin::tabs() ) );

		$this->login( 'administrator' );
		$this->assertSame( array( 'connect', 'health', 'tools', 'access', 'drafts', 'agents' ), array_keys( Admin::tabs() ) );
	}

	/**
	 * An editor can create a key; the page shows it once with ready setup.
	 */
	public function test_create_key_from_connect_tab(): void {
		$user = $this->login( 'editor' );

		$_POST['wpmark_create_key'] = '1';
		$_POST['wpmark_app']        = 'claude-code';
		$_REQUEST['_wpnonce']       = wp_create_nonce( 'wpmark_create_key' );

		$html      = $this->render_tab( 'connect' );
		$passwords = WP_Application_Passwords::get_user_application_passwords( $user->ID );

		$this->assertCount( 1, $passwords );
		$this->assertStringStartsWith( 'WPMark – Claude Code – ', $passwords[0]['name'] );
		$this->assertStringContainsString( 'claude mcp add --transport http wpmark', $html );
		$this->assertStringContainsString( 'Authorization: Basic ', $html );
		$this->assertStringContainsString( 'shown only once', $html );
	}

	/**
	 * Without a valid nonce, no key is created.
	 */
	public function test_create_key_needs_nonce(): void {
		$user = $this->login( 'editor' );

		$_POST['wpmark_create_key'] = '1';
		$_REQUEST['_wpnonce']       = 'wrong';

		try {
			$this->render_tab( 'connect' );
			$this->fail( 'Expected the request to be stopped.' );
		} catch ( WPDieException $e ) {
			$this->assertCount( 0, (array) WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
		}
	}

	/**
	 * People whose role is switched off cannot create keys.
	 */
	public function test_create_key_refused_for_switched_off_role(): void {
		Settings::save_roles(
			array(
				'editor' => array(
					'enabled' => '',
					'leads'   => 'none',
				),
			)
		);

		$result = Connection_Wizard::create_key( $this->login( 'editor' ), 'claude-desktop' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpmark_forbidden', $result->get_error_code() );
		$this->assertStringContainsString( 'not allowed to use WPMark', $this->render_tab( 'connect' ) );
	}

	/**
	 * Each app gets setup in its own format, with the address and login header.
	 */
	public function test_setup_formats(): void {
		$key = array(
			'username' => 'maya',
			'password' => 'abcd efgh ijkl mnop',
			'url'      => 'https://example.org/wp-json/wpmark/mcp',
		);

		$header = 'Basic ' . base64_encode( 'maya:abcdefghijklmnop' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected HTTP Basic value.

		$desktop = json_decode( Connection_Wizard::setup( 'claude-desktop', $key )['code'], true );
		$this->assertSame( 'npx', $desktop['mcpServers']['wpmark']['command'] );
		$this->assertContains( 'mcp-remote@latest', $desktop['mcpServers']['wpmark']['args'] );
		$this->assertSame( $header, $desktop['mcpServers']['wpmark']['env']['WPMARK_AUTH'] );

		$vscode = json_decode( Connection_Wizard::setup( 'vscode', $key )['code'], true );
		$this->assertSame( 'http', $vscode['servers']['wpmark']['type'] );

		$gemini = json_decode( Connection_Wizard::setup( 'gemini-cli', $key )['code'], true );
		$this->assertSame( $key['url'], $gemini['mcpServers']['wpmark']['httpUrl'] );

		foreach ( array_keys( Connection_Wizard::apps() ) as $app ) {
			$this->assertStringContainsString( $header, Connection_Wizard::setup( $app, $key )['code'], $app );
		}
	}

	/**
	 * Administrators can switch tools off.
	 */
	public function test_save_tools(): void {
		$this->login( 'administrator' );

		$_POST['tools']       = array( 'site-overview' => '1' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_save_tools' );

		$this->assertStringContainsString( 'tab=tools', $this->capture_redirect( array( Admin::class, 'save_tools' ) ) );
		$this->assertTrue( Settings::tool_enabled( 'site-overview' ) );
		$this->assertFalse( Settings::tool_enabled( 'list-leads' ) );
	}

	/**
	 * Administrators can set each role's access.
	 */
	public function test_save_access(): void {
		$this->login( 'administrator' );

		$_POST['roles']       = array(
			'editor' => array(
				'enabled' => '1',
				'leads'   => 'full',
			),
			'author' => array( 'leads' => 'masked' ),
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_save_access' );

		$this->capture_redirect( array( Admin::class, 'save_access' ) );

		$roles = Settings::roles();
		$this->assertSame( Lead_Access::Full, $roles['editor']['leads'] );
		$this->assertFalse( $roles['author']['enabled'] );
		$this->assertSame( Lead_Access::Masked, $roles['author']['leads'] );
	}

	/**
	 * Drafts are switched on for the site and per role on the Access tab.
	 */
	public function test_save_drafts_settings(): void {
		$this->login( 'administrator' );
		$this->assertFalse( Settings::drafts_enabled(), 'Off by default.' );
		$this->assertTrue( Settings::roles()['editor']['drafts'], 'Editors may once it is on.' );
		$this->assertFalse( Settings::roles()['author']['drafts'] );

		$_POST['drafts']      = '1';
		$_POST['roles']       = array(
			'administrator' => array( 'enabled' => '1' ),
			'editor'        => array( 'enabled' => '1' ),
			'author'        => array(
				'enabled' => '1',
				'drafts'  => '1',
			),
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_save_access' );

		$this->capture_redirect( array( Admin::class, 'save_access' ) );
		unset( $_POST['drafts'] );

		$roles = Settings::roles();
		$this->assertTrue( Settings::drafts_enabled() );
		$this->assertTrue( $roles['author']['drafts'] );
		$this->assertFalse( $roles['editor']['drafts'], 'Unticked means no.' );
		$this->assertStringContainsString( 'name="roles[author][drafts]" value="1" checked', $this->render_tab( 'access' ) );
		$this->assertFalse( Settings::custom_design_enabled(), 'Custom page design stays off unless ticked.' );

		$_POST['drafts']        = '1';
		$_POST['custom_design'] = '1';
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'wpmark_save_access' );
		$this->capture_redirect( array( Admin::class, 'save_access' ) );
		unset( $_POST['drafts'], $_POST['custom_design'] );

		$this->assertTrue( Settings::custom_design_enabled() );
		$this->assertStringContainsString( 'name="custom_design" value="1" checked', $this->render_tab( 'access' ) );
	}

	/**
	 * The Drafts by AI tab says when drafts are off, and lists WPMark drafts.
	 */
	public function test_drafts_tab(): void {
		$this->login( 'administrator' );
		$this->assertStringContainsString( 'Creating drafts is switched off', $this->render_tab( 'drafts' ) );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Spring launch',
			)
		);
		update_post_meta(
			$draft,
			\WPMark\Drafts\Drafts::META,
			array(
				'app'     => 'Claude',
				'user'    => get_current_user_id(),
				'builder' => 'blocks',
			)
		);
		Settings::save_drafts( true );

		$html = $this->render_tab( 'drafts' );
		$this->assertStringContainsString( 'Spring launch', $html );
		$this->assertStringContainsString( 'Claude', $html );
		$this->assertStringNotContainsString( 'switched off', $html );
	}

	/**
	 * The Agent ready tab shows the checks and saves the two switches.
	 */
	public function test_agents_tab(): void {
		$this->login( 'administrator' );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'offline', 'No network in tests' ) );

		$html = $this->render_tab( 'agents' );
		$this->assertStringContainsString( 'AI crawlers (robots.txt)', $html );
		$this->assertStringContainsString( 'name="llms_txt"', $html );
		$this->assertStringContainsString( 'name="webmcp"', $html );

		$_POST['llms_txt']    = '1';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_save_agents' );
		$this->assertStringContainsString( 'tab=agents', $this->capture_redirect( array( Admin::class, 'save_agents' ) ) );
		unset( $_POST['llms_txt'] );

		$this->assertTrue( Settings::llms_txt_enabled() );
		$this->assertFalse( Settings::webmcp_enabled() );
	}

	/**
	 * Editors cannot change settings, even with a valid nonce.
	 */
	public function test_editor_cannot_save(): void {
		$this->login( 'editor' );

		$_POST['tools']       = array();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_save_tools' );

		$this->expectException( WPDieException::class );
		Admin::save_tools();
	}

	/**
	 * The Tools and Access tabs list every tool and every role.
	 */
	public function test_tools_and_access_tabs(): void {
		$this->login( 'administrator' );

		$tools  = $this->render_tab( 'tools' );
		$access = $this->render_tab( 'access' );

		$this->assertStringContainsString( 'name="tools[find-seo-gaps]"', $tools );
		$this->assertStringContainsString( 'No supported form plugin', $tools );
		$this->assertStringContainsString( 'name="roles[editor][leads]"', $access );
		$this->assertStringContainsString( 'this role cannot write posts', $access );
		$this->assertStringContainsString( 'r***@acme.com', $access );
	}

	/**
	 * Every screen says it is a beta, who made it, and where the terms and help are.
	 */
	public function test_footer_and_beta_badge(): void {
		$this->login( 'editor' );

		$html = $this->render_tab( 'connect' );

		$this->assertStringContainsString( 'class="wpmark-badge">Beta<', $html );
		$this->assertStringContainsString( 'Atul Singh', $html );
		$this->assertStringContainsString( esc_url( Links::doc( 'beta-terms.md' ) ), $html );
		$this->assertStringContainsString( esc_url( Links::doc( 'privacy-and-data.md' ) ), $html );
		$this->assertStringContainsString( esc_url( Links::ISSUES ), $html );
	}

	/**
	 * The health report has what support needs, and no site address.
	 */
	public function test_health_report(): void {
		$this->login( 'administrator' );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'No network' ) );

		$html   = $this->render_tab( 'health' );
		$report = Connection_Doctor::report( Connection_Doctor::all_checks() );

		$this->assertStringContainsString( 'Copy health report', $html );
		$this->assertStringContainsString( 'WPMark ' . WPMARK_VERSION, $report );
		$this->assertStringContainsString( 'PHP ' . PHP_VERSION, $report );
		$this->assertStringContainsString( '[GOOD] WordPress version', $report );
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $report );
	}

	/**
	 * Quick checks describe the site without any network requests.
	 */
	public function test_quick_checks(): void {
		$this->login( 'administrator' );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'No network in quick checks' ) );

		$checks = array_column( Connection_Doctor::quick_checks(), null, 'id' );

		$this->assertSame( Connection_Doctor::GOOD, $checks['wordpress_version']['status'] );
		$this->assertArrayHasKey( 'php_security', $checks );
		$this->assertSame( Connection_Doctor::GOOD, $checks['mcp_adapter']['status'] );
		$this->assertSame( Connection_Doctor::PROBLEM, $checks['https']['status'], 'The test site uses http:// and is not local.' );
		$this->assertSame( Connection_Doctor::GOOD, $checks['application_passwords']['status'] );
		$this->assertSame( Connection_Doctor::GOOD, $checks['your_access']['status'] );
		$this->assertStringContainsString( 'none found', $checks['data_sources']['message'] );
	}

	/**
	 * The PHP security check: unsupported, missing the October 2026 fixes, or fine.
	 *
	 * @dataProvider php_versions
	 *
	 * @param string $version PHP version.
	 * @param string $status  Expected status.
	 * @param string $fix     Text expected in the fix, or '' for none.
	 */
	public function test_php_security( string $version, string $status, string $fix ): void {
		$check = Connection_Doctor::php_security_for( $version );

		$this->assertSame( 'php_security', $check['id'] );
		$this->assertSame( $status, $check['status'] );
		$this->assertStringContainsString( $version, $check['message'] );
		if ( '' === $fix ) {
			$this->assertSame( '', $check['fix'] );
		} else {
			$this->assertStringContainsString( $fix, $check['fix'] );
		}
	}

	/**
	 * PHP versions and what the check should say.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function php_versions(): array {
		return array(
			'end of life'         => array( '8.1.31', Connection_Doctor::PROBLEM, 'PHP 8.3 or newer' ),
			'very old'            => array( '7.4.33', Connection_Doctor::PROBLEM, 'PHP 8.3 or newer' ),
			'8.2 before the fix'  => array( '8.2.34', Connection_Doctor::WARNING, '8.2.35' ),
			'8.3 before the fix'  => array( '8.3.6', Connection_Doctor::WARNING, '8.3.36' ),
			'8.4 before the fix'  => array( '8.4.19', Connection_Doctor::WARNING, '8.4.27' ),
			'8.5 before the fix'  => array( '8.5.11', Connection_Doctor::WARNING, '8.5.12' ),
			'8.2 with the fix'    => array( '8.2.35', Connection_Doctor::GOOD, '' ),
			'8.4 after the fix'   => array( '8.4.30', Connection_Doctor::GOOD, '' ),
			'distribution suffix' => array( '8.3.36-1ubuntu1', Connection_Doctor::GOOD, '' ),
			'newer series'        => array( '8.6.0', Connection_Doctor::GOOD, '' ),
		);
	}

	/**
	 * The endpoint check tells a WordPress refusal from a firewall, a 404, a cache and no network.
	 *
	 * @dataProvider endpoint_responses
	 *
	 * @param mixed  $response Mocked response.
	 * @param string $expected Expected status.
	 */
	public function test_endpoint_check( $response, string $expected ): void {
		$this->login( 'administrator' );
		add_filter( 'pre_http_request', static fn() => $response );

		$checks = array_column( Connection_Doctor::all_checks(), null, 'id' );

		$this->assertSame( $expected, $checks['endpoint']['status'], $checks['endpoint']['message'] );
	}

	/**
	 * Mocked endpoint responses.
	 *
	 * @return array<string, array>
	 */
	public static function endpoint_responses(): array {
		$reply = static fn( int $code, string $type, array $headers = array() ): array => array(
			'headers'  => array_merge( array( 'content-type' => $type ), $headers ),
			'body'     => '{}',
			'response' => array( 'code' => $code ),
			'cookies'  => array(),
		);

		return array(
			'WordPress asks for login' => array( $reply( 401, 'application/json; charset=UTF-8' ), Connection_Doctor::GOOD ),
			'firewall page'            => array( $reply( 403, 'text/html' ), Connection_Doctor::PROBLEM ),
			'not found'                => array( $reply( 404, 'text/html' ), Connection_Doctor::PROBLEM ),
			'served from cache'        => array( $reply( 401, 'application/json', array( 'cf-cache-status' => 'HIT' ) ), Connection_Doctor::PROBLEM ),
			'site cannot reach itself' => array( new \WP_Error( 'http_request_failed', 'timeout' ), Connection_Doctor::WARNING ),
		);
	}

	/**
	 * The login header check passes only when the one-time code arrives intact.
	 */
	public function test_authorization_header_check(): void {
		$this->login( 'administrator' );

		// Simulate the site calling itself: deliver the header to the route, or drop it.
		$deliver = true;
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$deliver ) {
				if ( ! str_contains( $url, 'connection-check' ) ) {
					return $pre;
				}

				$_SERVER['HTTP_AUTHORIZATION'] = $deliver ? $args['headers']['Authorization'] : '';
				$body                          = Connection_Doctor::route_callback( new \WP_REST_Request() );

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( $body ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$checks = array_column( Connection_Doctor::all_checks(), null, 'id' );
		$this->assertSame( Connection_Doctor::GOOD, $checks['authorization_header']['status'] );

		$deliver = false;
		$checks  = array_column( Connection_Doctor::all_checks(), null, 'id' );
		$this->assertSame( Connection_Doctor::PROBLEM, $checks['authorization_header']['status'] );
		$this->assertStringContainsString( 'SetEnvIf Authorization', $checks['authorization_header']['fix'] );
	}

	/**
	 * The check route reveals nothing to a caller without the current code.
	 */
	public function test_check_route_needs_current_code(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'WPMark-Check guessed';

		$this->assertFalse( Connection_Doctor::route_callback( new \WP_REST_Request() )['received'] );
	}

	/**
	 * After activation, administrators see a pointer to the Connect screen once.
	 */
	public function test_welcome_notice(): void {
		$this->login( 'administrator' );
		\WPMark\Plugin::activate();

		ob_start();
		Admin::welcome_notice();
		$this->assertStringContainsString( 'Open WPMark', (string) ob_get_clean() );

		$this->render_tab( 'connect' );

		ob_start();
		Admin::welcome_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Log in as a new user with a role.
	 *
	 * @param string $role Role.
	 * @return \WP_User
	 */
	private function login( string $role ): \WP_User {
		$user = self::factory()->user->create_and_get( array( 'role' => $role ) );
		wp_set_current_user( $user->ID );

		return $user;
	}

	/**
	 * Render a tab and return its HTML.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function render_tab( string $tab ): string {
		$_GET['tab'] = $tab;

		ob_start();
		try {
			Admin::render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Run a save handler and return where it redirected to.
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	private function capture_redirect( callable $handler ): string {
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new Exception( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test control flow.
			}
		);

		try {
			$handler();
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected a redirect.' );
	}
}
