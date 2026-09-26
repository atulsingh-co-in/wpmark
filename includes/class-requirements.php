<?php
/**
 * Environment check.
 *
 * @package WPMark
 */

namespace WPMark;

use WP\MCP\Core\McpAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the site has what WPMark needs before anything else loads.
 *
 * If something is missing, WPMark stays switched off and shows one plain
 * notice saying what to do. It never breaks the site with a fatal error.
 */
final class Requirements {

	/**
	 * Check the live site.
	 *
	 * @return string[] Problems in plain language. Empty when everything is in place.
	 */
	public static function missing(): array {
		return self::problems(
			function_exists( 'wp_register_ability' ),
			class_exists( McpAdapter::class )
		);
	}

	/**
	 * Turn the facts about a site into the list of problems to show.
	 *
	 * Kept separate from missing() so tests can check every combination
	 * without changing which plugins are installed.
	 *
	 * @param bool $has_abilities_api Whether WordPress has the Abilities API (6.9+).
	 * @param bool $has_mcp_adapter   Whether the MCP Adapter (bundled with WPMark) could be loaded.
	 * @return string[] Problems in plain language.
	 */
	public static function problems( bool $has_abilities_api, bool $has_mcp_adapter ): array {
		$problems = array();

		if ( ! $has_abilities_api ) {
			$problems[] = __( 'WPMark needs WordPress 6.9 or newer. Please update WordPress from Dashboard → Updates. WPMark will switch on by itself afterwards.', 'wpmark' );
		}

		if ( ! $has_mcp_adapter ) {
			$problems[] = __( 'This copy of WPMark is incomplete: the part that lets AI assistants talk to your site (the MCP Adapter) is missing. This happens when WPMark is downloaded with GitHub\'s "Download ZIP" button. Delete this copy and install the WPMark zip from atulsingh.co.in/wpmark or from the Releases page of the WPMark GitHub repository instead.', 'wpmark' );
		}

		return $problems;
	}

	/**
	 * Show the problems as an admin notice to people who can fix them.
	 *
	 * @param string[] $problems Problems from missing().
	 */
	public static function show_notice( array $problems ): void {
		add_action(
			'admin_notices',
			static function () use ( $problems ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				foreach ( $problems as $problem ) {
					wp_admin_notice(
						esc_html( $problem ),
						array(
							'type'        => 'warning',
							'dismissible' => false,
						)
					);
				}
			}
		);
	}
}
