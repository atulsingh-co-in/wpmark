<?php
/**
 * Tests for the requirements check.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Requirements;

/**
 * Requirements: what happens when WordPress is too old or the MCP Adapter is missing.
 */
class Test_Requirements extends WP_UnitTestCase {

	/**
	 * The test site has everything, so nothing is missing.
	 */
	public function test_nothing_missing_on_test_site(): void {
		$this->assertSame( array(), Requirements::missing() );
	}

	/**
	 * Old WordPress gives one problem that says which version is needed.
	 */
	public function test_old_wordpress(): void {
		$problems = Requirements::problems( false, true );

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'WordPress 6.9', $problems[0] );
	}

	/**
	 * A missing adapter gives one problem that names the plugin to install.
	 */
	public function test_missing_adapter(): void {
		$problems = Requirements::problems( true, false );

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'MCP Adapter', $problems[0] );
	}

	/**
	 * Both problems are reported together, so the user can fix both at once.
	 */
	public function test_both_missing(): void {
		$this->assertCount( 2, Requirements::problems( false, false ) );
	}

	/**
	 * Administrators see the notice.
	 */
	public function test_notice_shown_to_admins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$output = $this->render_notices( Requirements::problems( true, false ) );

		$this->assertStringContainsString( 'MCP Adapter', $output );
	}

	/**
	 * People who cannot install plugins do not see it, since they cannot act on it.
	 */
	public function test_notice_hidden_from_editors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render_notices( Requirements::problems( true, false ) ) );
	}

	/**
	 * Register the notice and capture what admin_notices prints.
	 *
	 * @param string[] $problems Problems to show.
	 * @return string
	 */
	private function render_notices( array $problems ): string {
		remove_all_actions( 'admin_notices' );
		Requirements::show_notice( $problems );

		ob_start();
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}
}
