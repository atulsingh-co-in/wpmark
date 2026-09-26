<?php
/**
 * Class autoloader.
 *
 * @package WPMark
 */

namespace WPMark;

defined( 'ABSPATH' ) || exit;

/**
 * Loads WPMark classes on first use, so no file has to be required by hand.
 *
 * WordPress names files after the class they hold, in lowercase with hyphens:
 *
 *     WPMark\Plugin             -> includes/class-plugin.php
 *     WPMark\Forms\Form_Source  -> includes/forms/interface-form-source.php
 *
 * Composer's standard autoloader (PSR-4) expects file names to match class
 * names exactly, which clashes with that convention. This small loader
 * follows the WordPress convention instead, and means the plugin needs no
 * vendor/ folder at runtime.
 */
final class Autoloader {

	/**
	 * File name prefixes to try, in order. WordPress puts classes in
	 * "class-*.php", interfaces in "interface-*.php" and traits in "trait-*.php".
	 *
	 * @var string[]
	 */
	private const PREFIXES = array( 'class-', 'interface-', 'enum-', 'trait-' );

	/**
	 * Register the autoloader with PHP.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load the file for a WPMark class, if there is one.
	 *
	 * @param string $class_name Fully qualified class name, e.g. WPMark\Forms\Form_Source.
	 */
	public static function load( string $class_name ): void {
		$file = self::find_file( $class_name );

		if ( null !== $file ) {
			require_once $file;
		}
	}

	/**
	 * Work out which file holds a class, without loading it.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string|null Absolute path, or null when the class is not ours or the file is missing.
	 */
	public static function find_file( string $class_name ): ?string {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return null;
		}

		// "Forms\Form_Source" -> folder "forms/", base name "form-source".
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$parts = array_map( array( self::class, 'to_file_name' ), $parts );
		$base  = array_pop( $parts );
		$dir   = __DIR__ . '/' . ( $parts ? implode( '/', $parts ) . '/' : '' );

		foreach ( self::PREFIXES as $file_prefix ) {
			$file = $dir . $file_prefix . $base . '.php';

			if ( is_readable( $file ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Turn a class or namespace segment into a WordPress-style file name part.
	 *
	 * @param string $segment e.g. "Form_Source".
	 * @return string e.g. "form-source".
	 */
	private static function to_file_name( string $segment ): string {
		return strtolower( str_replace( '_', '-', $segment ) );
	}
}
