<?php
/**
 * Tests for plugin start-up.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Mcp_Server;
use WPMark\Plugin;

/**
 * Plugin: what WPMark hooks into when it loads.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Constants are defined from the plugin header.
	 */
	public function test_constants(): void {
		$this->assertSame( '0.3.1', WPMARK_VERSION );
		$this->assertFileExists( WPMARK_FILE );
		$this->assertStringEndsWith( '/', WPMARK_DIR );
	}

	/**
	 * With every requirement met, boot() added WPMark's hooks.
	 */
	public function test_hooks_registered(): void {
		$this->assertSame( 10, has_action( 'wp_abilities_api_categories_init', array( Plugin::class, 'register_ability_category' ) ) );
		$this->assertSame( 10, has_action( 'mcp_adapter_init', array( Mcp_Server::class, 'register' ) ) );
	}

	/**
	 * The Marketing category exists for WPMark's abilities.
	 */
	public function test_marketing_category_registered(): void {
		$category = wp_get_ability_category( 'wpmark' );

		$this->assertNotNull( $category );
		$this->assertSame( 'Marketing', $category->get_label() );
	}
}
