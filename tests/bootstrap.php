<?php
/**
 * PHPUnit bootstrap: start WordPress with the MCP Adapter and WPMark active.
 *
 * @package WPMark
 */

$wpmark_root = dirname( __DIR__ );

if ( ! is_readable( $wpmark_root . '/vendor/autoload.php' ) ) {
	echo 'Run "composer install" first.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	exit( 1 );
}

// PHPUnit Polyfills and the wp-phpunit test framework.
require $wpmark_root . '/vendor/autoload.php';

// Tell wp-phpunit where our WordPress settings live.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- wp-phpunit reads its config path from this variable.

$wpmark_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

require $wpmark_tests_dir . '/includes/functions.php';

// Load the plugins the way WordPress would, before the test site finishes loading.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wpmark_root ): void {
		// WPMark loads and starts its bundled MCP Adapter itself, as on a real site.
		require $wpmark_root . '/wpmark.php';
	}
);

require $wpmark_tests_dir . '/includes/bootstrap.php';

require __DIR__ . '/includes/class-fake-form-source.php';
require __DIR__ . '/includes/class-form-source-contract-test-case.php';
require __DIR__ . '/includes/trait-plugin-tables.php';
require __DIR__ . '/includes/trait-runs-abilities.php';
