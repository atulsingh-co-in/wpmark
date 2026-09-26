<?php
/**
 * Plugin bootstrap.
 *
 * @package WPMark
 */

namespace WPMark;

use WP\MCP\Core\McpAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Starts WPMark and connects it to WordPress.
 *
 * This is the one place that adds hooks, so reading this class tells you
 * everything WPMark does when it loads.
 */
final class Plugin {

	/**
	 * Start the plugin. Runs once, on plugins_loaded.
	 */
	public static function boot(): void {
		$problems = Requirements::missing();

		if ( $problems ) {
			Requirements::show_notice( $problems );
			return;
		}

		self::start_adapter();
		self::register_hooks();
	}

	/**
	 * Start the MCP Adapter, which handles the MCP protocol for WPMark.
	 *
	 * If the standalone MCP Adapter plugin is active it has already started
	 * the adapter and this does nothing new. Otherwise WPMark starts its
	 * bundled copy, and switches off the adapter's generic default endpoint:
	 * someone who installed only WPMark should get only WPMark's endpoint.
	 */
	public static function start_adapter(): void {
		if ( ! self::uses_standalone_adapter() ) {
			add_filter( 'mcp_adapter_create_default_server', '__return_false' );
		}

		McpAdapter::instance();
	}

	/**
	 * Whether the site also runs the standalone MCP Adapter plugin.
	 *
	 * That plugin defines WP_MCP_VERSION; WPMark's bundled copy does not.
	 *
	 * @return bool
	 */
	public static function uses_standalone_adapter(): bool {
		return defined( 'WP_MCP_VERSION' );
	}

	/**
	 * Add WPMark's hooks.
	 */
	public static function register_hooks(): void {
		// Group WPMark's tools under one heading in WordPress.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_ability_category' ) );

		// Register WPMark's tools, resources and prompts.
		add_action( 'wp_abilities_api_init', array( Abilities\Abilities::class, 'register_all' ) );

		// Give AI assistants their own WPMark connection address.
		add_action( 'mcp_adapter_init', array( Mcp_Server::class, 'register' ) );

		// "Sign in with WordPress" for Claude, ChatGPT and other apps (OAuth 2.1).
		OAuth\OAuth::register();

		// The health check's test route.
		add_action( 'rest_api_init', array( Admin\Connection_Doctor::class, 'register_route' ) );

		// The WPMark screens, and suggested privacy policy text for site owners.
		if ( is_admin() ) {
			Admin\Admin::register();
			Privacy\Privacy_Policy::register();
		}
	}

	/**
	 * On activation, show a one-time pointer to the Connect screen.
	 */
	public static function activate(): void {
		set_transient( 'wpmark_welcome', 1, DAY_IN_SECONDS );
	}

	/**
	 * Register the "Marketing" category that every WPMark ability belongs to.
	 */
	public static function register_ability_category(): void {
		wp_register_ability_category(
			'wpmark',
			array(
				'label'       => __( 'Marketing', 'wpmark' ),
				'description' => __( 'Read-only tools that help a marketing team understand their site, content, SEO and leads.', 'wpmark' ),
			)
		);
	}
}
