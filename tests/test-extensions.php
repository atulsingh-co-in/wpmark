<?php
/**
 * Tests for the hooks add-ons use.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Abilities\Abilities;
use WPMark\Abilities\Ability;
use WPMark\Abilities\Site_Overview;
use WPMark\Admin\Admin;
use WPMark\Mcp_Server;
use WPMark\Settings;

/**
 * The hooks WPMark Pro and other add-ons build on.
 */
class Test_Extensions extends WP_UnitTestCase {

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wpmark_abilities' );
		remove_all_filters( 'wpmark_publishing_allowed' );
		remove_all_filters( 'wpmark_admin_tabs' );
		remove_all_actions( 'wpmark_admin_tab_extra' );
		unset( $_GET['tab'] );
		Settings::save_drafts( false );

		parent::tear_down();
	}

	/**
	 * Add-ons are told when WPMark has started.
	 */
	public function test_loaded_action(): void {
		$this->assertSame( 1, did_action( 'wpmark_loaded' ) );
	}

	/**
	 * An add-on can add abilities but cannot replace WPMark's own or add other things.
	 */
	public function test_abilities_filter(): void {
		$count = count( Abilities::all() );

		add_filter(
			'wpmark_abilities',
			static fn( array $abilities ): array => array_merge(
				array( new Add_On_Tool( 'site-overview' ) ),
				$abilities,
				array( new Add_On_Tool(), 'not an ability' )
			)
		);

		$all   = Abilities::all();
		$names = array_map( static fn( Ability $ability ): string => $ability->name(), $all );

		$this->assertCount( $count + 1, $all );
		$this->assertContains( 'wpmark/add-on-tool', $names );
		$this->assertInstanceOf( Site_Overview::class, $all[ array_search( 'wpmark/site-overview', $names, true ) ], 'WPMark\'s own tool is kept.' );
		$this->assertContains( 'wpmark/add-on-tool', Mcp_Server::tool_names() );
	}

	/**
	 * The server description says what is true when an add-on publishes.
	 */
	public function test_publishing_wording(): void {
		$this->assertStringContainsString( 'Read-only', Mcp_Server::description() );

		Settings::save_drafts( true );
		$this->assertStringContainsString( 'Never publishes', Mcp_Server::description() );

		add_filter( 'wpmark_publishing_allowed', '__return_true' );
		$this->assertTrue( Settings::publishing_allowed() );
		$this->assertStringContainsString( 'Publishes only where the site administrator allows it', Mcp_Server::description() );
	}

	/**
	 * An add-on can add a tab for administrators.
	 */
	public function test_admin_tab(): void {
		add_filter( 'wpmark_admin_tabs', static fn(): array => array( 'extra' => 'Extra', 'tools' => 'Not replaced' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
		add_action(
			'wpmark_admin_tab_extra',
			static function (): void {
				echo '<p>Extra tab</p>';
			}
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertArrayNotHasKey( 'extra', Admin::tabs() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Admin::tabs();
		$this->assertSame( 'Extra', $tabs['extra'] );
		$this->assertSame( 'Tools', $tabs['tools'] );

		$_GET['tab'] = 'extra';
		ob_start();
		Admin::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<p>Extra tab</p>', $html );
	}
}
