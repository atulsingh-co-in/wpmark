<?php
/**
 * WordPress settings for the test suite.
 *
 * The tests install a fresh WordPress into this database and wipe it on
 * every run. Point it at an EMPTY database made for tests, never at a real
 * site's database.
 *
 * Settings come from environment variables (CI sets these), or from
 * tests/wp-tests-config.local.php if you create one (see tests/README.md).
 * That file is ignored by git, so your local credentials stay local.
 *
 * @package WPMark
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress reads $table_prefix from this file.

$wpmark_local_config = __DIR__ . '/wp-tests-config.local.php';
if ( is_readable( $wpmark_local_config ) ) {
	require $wpmark_local_config;
}

/**
 * Read a setting: a constant from the local file, then an environment variable, then a default.
 *
 * @param string $name    Setting name, e.g. "DB_NAME".
 * @param string $fallback Value when neither is set.
 * @return string
 */
function wpmark_test_setting( string $name, string $fallback ): string {
	if ( defined( 'WPMARK_TEST_' . $name ) ) {
		return (string) constant( 'WPMARK_TEST_' . $name );
	}

	$value = getenv( 'WPMARK_TEST_' . $name );

	return false === $value ? $fallback : $value;
}

define( 'ABSPATH', rtrim( wpmark_test_setting( 'WP_DIR', dirname( __DIR__ ) . '/.wp/wordpress' ), '/' ) . '/' );

define( 'DB_NAME', wpmark_test_setting( 'DB_NAME', 'wpmark_tests' ) );
define( 'DB_USER', wpmark_test_setting( 'DB_USER', 'root' ) );
define( 'DB_PASSWORD', wpmark_test_setting( 'DB_PASSWORD', '' ) );
define( 'DB_HOST', wpmark_test_setting( 'DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WPMark Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
