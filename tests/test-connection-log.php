<?php
/**
 * Tests for the connection log and the "Recent connections" health check.
 *
 * @package WPMark
 */

// Some servers (LiteSpeed, such as Hostinger) only expose the Authorization
// header through getallheaders(). The PHP command line has no such function,
// so the tests provide one that returns whatever a test puts in this global.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Universal.Namespaces.OneDeclarationPerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.DisallowDeclarationWithoutName
namespace {
	if ( ! function_exists( 'getallheaders' ) ) {
		/**
		 * Test stand-in for the server function.
		 *
		 * @return array<string, string>
		 */
		function getallheaders(): array {
			return $GLOBALS['wpmark_test_headers'] ?? array();
		}
	}
}

namespace WPMark\Tests {

	use WP_UnitTestCase;
	use WPMark\Admin\Connection_Doctor;
	use WPMark\OAuth\Bearer_Auth;
	use WPMark\OAuth\Connection_Log;
	use WPMark\OAuth\Store;
	use WPMark\Settings;

	/**
	 * Why an app that signed in still cannot connect.
	 */
	class Test_Connection_Log extends WP_UnitTestCase {

		/**
		 * The request address before the test, restored afterwards.
		 *
		 * @var string|null
		 */
		private ?string $request_uri;

		/**
		 * An https site, so sign-in is available, and a request to the MCP address.
		 */
		public function set_up(): void {
			parent::set_up();

			update_option( 'home', 'https://example.org' );
			update_option( 'siteurl', 'https://example.org' );
			Store::install();
			Connection_Log::clear();
			Bearer_Auth::reset();

			$this->request_uri      = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore later.
			$_SERVER['REQUEST_URI'] = '/wp-json/wpmark/mcp';
		}

		/**
		 * Clean up request data.
		 */
		public function tear_down(): void {
			unset( $_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wpmark_test_headers'] );
			if ( null === $this->request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $this->request_uri;
			}
			Connection_Log::clear();
			Bearer_Auth::reset();

			parent::tear_down();
		}

		/**
		 * Signed in, but the server removed the header: the health check says so, with the fix.
		 */
		public function test_header_removed_after_sign_in(): void {
			$this->connect( 'Claude', 'editor', 60 );

			$this->assertFalse( Bearer_Auth::authenticate( false ) );

			$check = $this->check();
			$this->assertSame( Connection_Doctor::PROBLEM, $check['status'] );
			$this->assertStringContainsString( 'without its sign-in pass', $check['message'] );
			$this->assertStringContainsString( 'SetEnvIf Authorization', $check['fix'] );
		}

		/**
		 * A request before anyone signed in is normal, not a problem.
		 */
		public function test_no_token_before_sign_in_is_fine(): void {
			Bearer_Auth::authenticate( false );

			$this->assertSame( Connection_Doctor::INFO, $this->check()['status'] );
		}

		/**
		 * A working connection is reported with the app's name.
		 */
		public function test_working_connection(): void {
			$token                         = $this->connect( 'Claude', 'editor', 60 );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

			$this->assertGreaterThan( 0, Bearer_Auth::authenticate( false ) );

			$check = $this->check();
			$this->assertSame( Connection_Doctor::GOOD, $check['status'] );
			$this->assertStringContainsString( 'from Claude', $check['message'] );
		}

		/**
		 * The token is also found where LiteSpeed servers put it.
		 */
		public function test_header_from_getallheaders(): void {
			$token                          = $this->connect( 'ChatGPT', 'editor', 60 );
			$GLOBALS['wpmark_test_headers'] = array( 'authorization' => 'Bearer ' . $token );

			$this->assertSame( 'Bearer ' . $token, Bearer_Auth::authorization_header() );
			$this->assertGreaterThan( 0, Bearer_Auth::authenticate( false ) );
		}

		/**
		 * An unknown or old token: reconnect.
		 */
		public function test_unknown_token(): void {
			$this->connect( 'Claude', 'editor', 60 );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wpmark_at_unknown';

			$this->assertFalse( Bearer_Auth::authenticate( false ) );

			$check = $this->check();
			$this->assertSame( Connection_Doctor::WARNING, $check['status'] );
			$this->assertStringContainsString( 'connect again', $check['fix'] );
		}

		/**
		 * A role that was switched off after sign-in.
		 */
		public function test_role_not_allowed(): void {
			$token = $this->connect( 'Claude', 'editor', 60 );
			Settings::save_roles(
				array(
					'editor' => array(
						'enabled' => false,
						'leads'   => 'none',
					),
				)
			);
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

			$this->assertFalse( Bearer_Auth::authenticate( false ) );
			$this->assertSame( Connection_Doctor::WARNING, $this->check()['status'] );
			$this->assertSame( Connection_Log::NOT_ALLOWED, Connection_Log::latest()['outcome'] );
		}

		/**
		 * An old token followed by a successful reconnect is reported as working.
		 */
		public function test_success_after_old_token_clears_the_warning(): void {
			$token = $this->connect( 'Claude', 'editor', 60 );

			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
			Bearer_Auth::authenticate( false );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wpmark_at_expired';
			Bearer_Auth::authenticate( false );
			$this->assertSame( Connection_Doctor::WARNING, $this->check()['status'] );

			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
			Bearer_Auth::authenticate( false );

			$this->assertSame( Connection_Doctor::GOOD, $this->check()['status'] );
		}

		/**
		 * The health check's own test request is not mistaken for an app's.
		 */
		public function test_self_test_request_not_logged(): void {
			$this->connect( 'Claude', 'editor', 60 );
			$_SERVER['HTTP_X_WPMARK_SELF_TEST'] = '1';

			Bearer_Auth::authenticate( false );
			unset( $_SERVER['HTTP_X_WPMARK_SELF_TEST'] );

			$this->assertNull( Connection_Log::latest() );
			$this->assertSame( Connection_Doctor::WARNING, $this->check()['status'] );
		}

		/**
		 * Requests to other addresses are not logged.
		 */
		public function test_other_addresses_not_logged(): void {
			$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

			Bearer_Auth::authenticate( false );

			$this->assertNull( Connection_Log::latest() );
		}

		/**
		 * The log holds outcomes and app names only: never tokens.
		 */
		public function test_log_holds_no_tokens(): void {
			$token                         = $this->connect( 'Claude', 'editor', 60 );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

			Bearer_Auth::authenticate( false );

			$this->assertStringNotContainsString( $token, (string) wp_json_encode( get_option( Connection_Log::OPTION ) ) );
		}

		/**
		 * Sign an app in, as a finished sign-in would, some seconds ago.
		 *
		 * @param string $app     App name.
		 * @param string $role    The person's role.
		 * @param int    $ago     How many seconds ago they clicked Allow.
		 * @return string The access token.
		 */
		private function connect( string $app, string $role, int $ago ): string {
			global $wpdb;

			$client_id = 'wpmark_test_' . strtolower( $app );
			Store::add_client(
				array(
					'client_id'     => $client_id,
					'client_name'   => $app,
					'redirect_uris' => array( 'https://app.example/cb' ),
					'auth_method'   => 'none',
					'secret_hash'   => null,
				)
			);

			$fields = array(
				'client_id' => $client_id,
				'user_id'   => self::factory()->user->create( array( 'role' => $role ) ),
				'family'    => wp_generate_password( 32, false ),
				'scope'     => 'wpmark',
			);

			Store::issue( 'code', $fields, HOUR_IN_SECONDS );
			Store::issue( 'refresh', $fields, HOUR_IN_SECONDS );
			$token = Store::issue( 'access', $fields, HOUR_IN_SECONDS );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture: move the sign-in into the past.
			$wpdb->update( $wpdb->prefix . 'wpmark_oauth_tokens', array( 'created_at' => Store::time( time() - $ago ) ), array( 'family' => $fields['family'] ) );

			return $token;
		}

		/**
		 * The "Recent connections" check.
		 *
		 * @return array
		 */
		private function check(): array {
			return array_column( Connection_Doctor::quick_checks(), null, 'id' )['recent_connections'];
		}
	}
}
