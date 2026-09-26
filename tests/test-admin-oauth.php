<?php
/**
 * Tests for the sign-in parts of the WPMark screens and health check.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use Exception;
use WP_UnitTestCase;
use WPDieException;
use WPMark\Admin\Admin;
use WPMark\Admin\Connection_Doctor;
use WPMark\Mcp_Server;
use WPMark\OAuth\Bearer_Auth;
use WPMark\OAuth\Store;
use WPMark\Settings;

/**
 * Connect tab, connected apps, the OAuth switch, and the OAuth health checks.
 */
class Test_Admin_OAuth extends WP_UnitTestCase {

	/**
	 * An https site, so sign-in is available.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		Store::install();
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * The Connect tab leads with the address and the Claude and ChatGPT steps.
	 */
	public function test_connect_tab_shows_address_and_steps(): void {
		$this->login( 'editor' );

		$html = $this->render_tab( 'connect' );

		$this->assertStringContainsString( 'value="' . esc_attr( Mcp_Server::url() ) . '"', $html );
		$this->assertStringContainsString( 'Add custom connector', $html );
		$this->assertStringContainsString( 'Developer mode', $html );
		$this->assertStringContainsString( 'No apps are connected through sign-in yet.', $html );
		$this->assertStringContainsString( 'Other apps: use a connection key', $html );
	}

	/**
	 * Without HTTPS, the tab explains why sign-in is unavailable.
	 */
	public function test_connect_tab_without_https(): void {
		update_option( 'home', 'http://example.org' );
		$this->login( 'editor' );

		$this->assertStringContainsString( 'needs a secure (https://) site address', $this->render_tab( 'connect' ) );
	}

	/**
	 * People see their own connected apps and can disconnect them.
	 */
	public function test_connected_apps_and_disconnect(): void {
		$user   = $this->login( 'editor' );
		$tokens = $this->connect_app( $user->ID, 'Claude' );

		$this->assertStringContainsString( '<td>Claude</td>', $this->render_tab( 'connect' ) );

		$_POST['user_id']     = (string) $user->ID;
		$_POST['client_id']   = 'wpmark_test_client';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_disconnect' );

		$this->capture_redirect( array( Admin::class, 'disconnect' ) );

		$this->assertNull( Bearer_Auth::user_for_token( $tokens ) );
		$this->assertSame( array(), Store::connections( $user->ID ) );
	}

	/**
	 * "Last used" reflects the app actually using its access token.
	 */
	public function test_last_used(): void {
		$user  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$token = $this->connect_app( $user, 'Claude' );

		$this->assertNull( Store::connections( $user )[0]['last_used_at'] );

		Bearer_Auth::user_for_token( $token );

		$this->assertNotNull( Store::connections( $user )[0]['last_used_at'] );
	}

	/**
	 * Nobody can disconnect someone else's app, except administrators.
	 */
	public function test_cannot_disconnect_others(): void {
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->connect_app( $owner, 'Claude' );
		$this->login( 'author' );

		$_POST['user_id']     = (string) $owner;
		$_POST['client_id']   = 'wpmark_test_client';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_disconnect' );

		try {
			Admin::disconnect();
			$this->fail( 'Expected the request to be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertCount( 1, Store::connections( $owner ) );
		}
	}

	/**
	 * Administrators see everyone's connected apps.
	 */
	public function test_admin_sees_everyone(): void {
		$other = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Priya Editor',
			)
		);
		$this->connect_app( $other, 'ChatGPT' );
		$this->login( 'administrator' );

		$html = $this->render_tab( 'connect' );

		$this->assertStringContainsString( 'Priya Editor', $html );
		$this->assertStringContainsString( '<td>ChatGPT</td>', $html );
	}

	/**
	 * Switching sign-in off signs every app out.
	 */
	public function test_switching_oauth_off_revokes_everything(): void {
		$token = $this->connect_app( self::factory()->user->create( array( 'role' => 'editor' ) ), 'Claude' );
		$this->login( 'administrator' );

		$_POST['roles']       = array();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpmark_save_access' );

		$this->capture_redirect( array( Admin::class, 'save_access' ) );

		$this->assertFalse( Settings::oauth_enabled() );
		$this->assertNull( Bearer_Auth::user_for_token( $token ) );
	}

	/**
	 * The health check reports sign-in readiness and discovery.
	 */
	public function test_health_checks(): void {
		$this->login( 'administrator' );

		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) {
				unset( $args );
				$body = str_contains( $url, '/.well-known/' ) ? wp_json_encode( array( 'issuer' => 'https://example.org' ) ) : '{}';

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => $body,
					'response' => array( 'code' => str_contains( $url, '/.well-known/' ) ? 200 : 401 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$checks = array_column( Connection_Doctor::all_checks(), null, 'id' );

		$this->assertSame( Connection_Doctor::GOOD, $checks['oauth']['status'] );
		$this->assertSame( Connection_Doctor::GOOD, $checks['well_known']['status'] );
		$this->assertStringContainsString( 'Built into WPMark', $checks['mcp_adapter']['message'] );
	}

	/**
	 * A server that keeps /.well-known/ to itself is reported with a fix.
	 */
	public function test_blocked_well_known(): void {
		$this->login( 'administrator' );

		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'content-type' => 'text/html' ),
				'body'     => '<html>404</html>',
				'response' => array( 'code' => 404 ),
				'cookies'  => array(),
			)
		);

		$checks = array_column( Connection_Doctor::all_checks(), null, 'id' );

		$this->assertSame( Connection_Doctor::PROBLEM, $checks['well_known']['status'] );
		$this->assertStringContainsString( 'index.php', $checks['well_known']['fix'] );
	}

	/**
	 * Store a connected app for a person, as a finished sign-in would.
	 *
	 * @param int    $user_id User.
	 * @param string $name    App name.
	 * @return string The access token.
	 */
	private function connect_app( int $user_id, string $name ): string {
		$client_id = 'ChatGPT' === $name ? 'wpmark_test_client_2' : 'wpmark_test_client';

		if ( ! Store::get_client( $client_id ) ) {
			Store::add_client(
				array(
					'client_id'     => $client_id,
					'client_name'   => $name,
					'redirect_uris' => array( 'https://app.example/cb' ),
					'auth_method'   => 'none',
					'secret_hash'   => null,
				)
			);
		}

		$fields = array(
			'client_id' => $client_id,
			'user_id'   => $user_id,
			'family'    => wp_generate_password( 32, false ),
			'scope'     => 'wpmark',
		);

		Store::issue( 'code', $fields, HOUR_IN_SECONDS );
		Store::issue( 'refresh', $fields, HOUR_IN_SECONDS );

		return Store::issue( 'access', $fields, HOUR_IN_SECONDS );
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
		Admin::render();

		return (string) ob_get_clean();
	}

	/**
	 * Run a handler that ends in a redirect.
	 *
	 * @param callable $handler Handler.
	 */
	private function capture_redirect( callable $handler ): void {
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new Exception( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test control flow.
			}
		);

		try {
			$handler();
			$this->fail( 'Expected a redirect.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'page=wpmark', $e->getMessage() );
		}
	}
}
