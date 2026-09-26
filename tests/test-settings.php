<?php
/**
 * Tests for settings and role-based access.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Privacy\Lead_Access;
use WPMark\Settings;

/**
 * Settings: tool switches, role access and lead levels.
 */
class Test_Settings extends WP_UnitTestCase {

	/**
	 * Tools are on until switched off.
	 */
	public function test_tools_on_by_default(): void {
		$this->assertTrue( Settings::tool_enabled( 'list-leads' ) );

		Settings::save_tools( array( 'list-leads' => false ) );

		$this->assertFalse( Settings::tool_enabled( 'list-leads' ) );
		$this->assertTrue( Settings::tool_enabled( 'site-overview' ) );
	}

	/**
	 * Out of the box: administrators masked, editors without contact details, others none.
	 */
	public function test_safe_defaults(): void {
		$roles = Settings::roles();

		$this->assertSame( Lead_Access::Masked, $roles['administrator']['leads'] );
		$this->assertSame( Lead_Access::Hidden, $roles['editor']['leads'] );
		$this->assertSame( Lead_Access::None, $roles['author']['leads'] );
		$this->assertSame( Lead_Access::None, $roles['contributor']['leads'] );
		$this->assertTrue( $roles['contributor']['enabled'] );
		$this->assertFalse( $roles['subscriber']['enabled'] );
		$this->assertFalse( $roles['subscriber']['can_write'] );
	}

	/**
	 * A role added later appears automatically, with defaults from its capabilities.
	 */
	public function test_new_roles_appear_automatically(): void {
		add_role(
			'marketing_lead',
			'Marketing Lead',
			array(
				'edit_posts'        => true,
				'edit_others_posts' => true,
			)
		);

		$roles = Settings::roles();

		$this->assertArrayHasKey( 'marketing_lead', $roles );
		$this->assertTrue( $roles['marketing_lead']['enabled'] );
		$this->assertSame( Lead_Access::Hidden, $roles['marketing_lead']['leads'] );

		remove_role( 'marketing_lead' );
	}

	/**
	 * Saved choices are used, and a role that cannot write can never be enabled.
	 */
	public function test_saved_choices(): void {
		Settings::save_roles(
			array(
				'editor'     => array(
					'enabled' => '1',
					'leads'   => 'full',
				),
				'author'     => array( 'leads' => 'masked' ),
				'subscriber' => array(
					'enabled' => '1',
					'leads'   => 'full',
				),
				'unknown'    => array( 'enabled' => '1' ),
			)
		);

		$roles = Settings::roles();

		$this->assertSame( Lead_Access::Full, $roles['editor']['leads'] );
		$this->assertFalse( $roles['author']['enabled'] );
		$this->assertFalse( $roles['subscriber']['enabled'] );
		$this->assertArrayNotHasKey( 'unknown', $roles );
	}

	/**
	 * An unknown lead level saves as no access.
	 */
	public function test_invalid_level_saves_as_none(): void {
		Settings::save_roles(
			array(
				'editor' => array(
					'enabled' => '1',
					'leads'   => 'everything',
				),
			)
		);

		$this->assertSame( Lead_Access::None, Settings::roles()['editor']['leads'] );
	}

	/**
	 * Who can connect follows the role switches.
	 */
	public function test_user_can_connect(): void {
		$editor     = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->assertTrue( Settings::user_can_connect( $editor ) );
		$this->assertFalse( Settings::user_can_connect( $subscriber ) );

		Settings::save_roles(
			array(
				'editor' => array(
					'enabled' => '',
					'leads'   => 'hidden',
				),
			)
		);

		$this->assertFalse( Settings::user_can_connect( $editor ) );
		$this->assertSame( Lead_Access::None, Settings::lead_access_for( $editor ) );
	}

	/**
	 * Someone with two roles gets the more generous lead level.
	 */
	public function test_most_generous_role_wins(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		$user->add_role( 'editor' );

		$this->assertSame( Lead_Access::Hidden, Settings::lead_access_for( $user ) );
	}
}
