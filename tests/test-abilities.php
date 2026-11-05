<?php
/**
 * Tests shared by every ability.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Abilities\Abilities;
use WPMark\Abilities\Ability;
use WPMark\Settings;

/**
 * Registration, read-only hints, descriptions and the shared permission rules.
 */
class Test_Abilities extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * Every ability is registered in the Marketing category and kept off the default server.
	 */
	public function test_all_registered(): void {
		foreach ( Abilities::all() as $ability ) {
			$registered = wp_get_ability( $ability->name() );

			$this->assertNotNull( $registered, $ability->name() );
			$this->assertSame( 'wpmark', $registered->get_category() );
			$this->assertFalse( $registered->get_meta()['mcp']['public'], $ability->name() );
		}
	}

	/**
	 * Reading tools are marked read-only and safe to repeat; the two writing
	 * tools are marked as writing. None is destructive.
	 */
	public function test_tool_annotations(): void {
		foreach ( Abilities::of_type( Ability::TOOL ) as $tool ) {
			$annotations = wp_get_ability( $tool->name() )->get_meta()['annotations'];

			$this->assertSame( ! $tool->is_write(), $annotations['readonly'], $tool->name() );
			$this->assertFalse( $annotations['destructive'], $tool->name() );
			$this->assertSame( ! $tool->is_write(), $annotations['idempotent'], $tool->name() );
		}

		$writing = array_map( static fn( Ability $tool ): string => $tool->slug(), array_filter( Abilities::of_type( Ability::TOOL ), static fn( Ability $tool ): bool => $tool->is_write() ) );
		$this->assertSame( array( 'create-draft', 'update-draft' ), array_values( $writing ) );
	}

	/**
	 * Tool descriptions are a real brief: long enough, and say what they never do.
	 */
	public function test_tool_descriptions_are_briefs(): void {
		foreach ( Abilities::of_type( Ability::TOOL ) as $tool ) {
			$this->assertGreaterThan( 300, strlen( $tool->description() ), $tool->name() );
			$this->assertStringContainsString( $tool->is_write() ? 'Never publishes' : 'Read-only', $tool->description(), $tool->name() );
		}
	}

	/**
	 * The seventeen tools, two resources and six prompts, each mapping to a marketing job.
	 */
	public function test_catalogue(): void {
		$this->assertCount( 17, Abilities::of_type( Ability::TOOL ) );
		$this->assertCount( 2, Abilities::of_type( Ability::RESOURCE ) );
		$this->assertCount( 6, Abilities::of_type( Ability::PROMPT ) );
	}

	/**
	 * Logged-out visitors are refused.
	 */
	public function test_logged_out_refused(): void {
		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'site-overview', null, '' ) );
	}

	/**
	 * Subscribers are refused.
	 */
	public function test_subscriber_refused(): void {
		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'site-overview', null, 'subscriber' ) );
	}

	/**
	 * A role switched off on the Access & privacy screen is refused.
	 */
	public function test_switched_off_role_refused(): void {
		Settings::save_roles(
			array(
				'editor' => array(
					'enabled' => '',
					'leads'   => 'none',
				),
			)
		);

		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'site-overview', null, 'editor' ) );
	}

	/**
	 * A tool switched off on the Tools screen is refused and no longer offered.
	 */
	public function test_switched_off_tool_refused(): void {
		Settings::save_tools( array( 'search-content' => false ) );

		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'search-content', array( 'query' => 'pricing' ) ) );
		$this->assertNotContains( 'wpmark/search-content', \WPMark\Mcp_Server::tool_names() );
	}

	/**
	 * The permission message is plain language, pointing to the fix.
	 */
	public function test_permission_message_is_plain(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ( new \WPMark\Abilities\Site_Overview() )->check_permission();

		$this->assertStringContainsString( 'Access & privacy', $result->get_error_message() );
	}
}
