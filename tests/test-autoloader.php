<?php
/**
 * Tests for the autoloader.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Autoloader;

/**
 * Autoloader: class name to file name.
 */
class Test_Autoloader extends WP_UnitTestCase {

	/**
	 * Classes, interfaces and enums each map to their WordPress-style file.
	 *
	 * @dataProvider known_classes
	 *
	 * @param string $class_name Class name.
	 * @param string $file       Expected path inside the plugin.
	 */
	public function test_finds_file( string $class_name, string $file ): void {
		$this->assertSame( WPMARK_DIR . $file, Autoloader::find_file( $class_name ) );
	}

	/**
	 * WPMark classes and where they live.
	 *
	 * @return array<string, string[]>
	 */
	public static function known_classes(): array {
		return array(
			'class'           => array( 'WPMark\Plugin', 'includes/class-plugin.php' ),
			'underscore name' => array( 'WPMark\Mcp_Server', 'includes/class-mcp-server.php' ),
			'interface'       => array( 'WPMark\Forms\Form_Source', 'includes/forms/interface-form-source.php' ),
			'enum'            => array( 'WPMark\Forms\Field_Role', 'includes/forms/enum-field-role.php' ),
			'sub-namespace'   => array( 'WPMark\Forms\Entry_Query', 'includes/forms/class-entry-query.php' ),
		);
	}

	/**
	 * Classes from other plugins and missing WPMark classes are left alone.
	 */
	public function test_ignores_other_classes(): void {
		$this->assertNull( Autoloader::find_file( 'WP\MCP\Core\McpAdapter' ) );
		$this->assertNull( Autoloader::find_file( 'WPMarkOther\Plugin' ) );
		$this->assertNull( Autoloader::find_file( 'WPMark\Does_Not_Exist' ) );
	}
}
