<?php
/**
 * Plugin Name:       WPMark
 * Plugin URI:        https://atulsingh.co.in/wpmark/
 * Description:       AI for your marketing team. Connect Claude, ChatGPT or Gemini to your site and ask about your content, SEO and leads in plain language. Read-only by default. Never publishes or deletes anything.
 * Version:           0.3.2
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Atul Singh
 * Author URI:        https://atulsingh.co.in
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wpmark
 * Update URI:        https://atulsingh.co.in/wpmark/
 *
 * @package WPMark
 */

defined( 'ABSPATH' ) || exit;

define( 'WPMARK_VERSION', '0.3.2' );
define( 'WPMARK_FILE', __FILE__ );
define( 'WPMARK_DIR', plugin_dir_path( __FILE__ ) );

require_once WPMARK_DIR . 'includes/class-autoloader.php';
WPMark\Autoloader::register();

/*
 * The official MCP Adapter is bundled in vendor/, loaded through the Jetpack
 * Autoloader. If another plugin (or the standalone MCP Adapter plugin) ships
 * the adapter too, the Jetpack Autoloader loads only the newest copy, so
 * nothing clashes. The file is missing only in an unbuilt copy of the code
 * (for example GitHub's "Download ZIP"); Requirements then explains the fix.
 */
if ( is_readable( WPMARK_DIR . 'vendor/autoload_packages.php' ) ) {
	require_once WPMARK_DIR . 'vendor/autoload_packages.php';
}

register_activation_hook( __FILE__, array( WPMark\Plugin::class, 'activate' ) );

// Wait until every plugin has loaded, so we know which copy of the MCP Adapter is in use.
add_action( 'plugins_loaded', array( WPMark\Plugin::class, 'boot' ) );
