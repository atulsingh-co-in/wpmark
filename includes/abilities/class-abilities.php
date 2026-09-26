<?php
/**
 * Ability registry.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The full list of what WPMark offers, in one place.
 *
 * Every tool, resource and prompt is registered with WordPress. The MCP
 * server then offers only the ones that are switched on and that this site
 * can support (for example, the lead tools only when a form plugin is
 * active), so the AI never sees a tool it cannot use.
 */
final class Abilities {

	/**
	 * Everything WPMark offers.
	 *
	 * @return Ability[]
	 */
	public static function all(): array {
		return array(
			// Tools.
			new Site_Overview(),
			new Search_Content(),
			new Get_Content(),
			new Content_Inventory(),
			new Find_Seo_Gaps(),
			new List_Leads(),
			new Lead_Summary(),
			// Resources.
			new Site_Profile_Resource(),
			new Forms_Resource(),
			// Prompts.
			new Lead_Review_Prompt(),
			new Content_Plan_Prompt(),
			new Seo_Check_Prompt(),
		);
	}

	/**
	 * Everything of one type.
	 *
	 * @param string $type Ability::TOOL, RESOURCE or PROMPT.
	 * @return Ability[]
	 */
	public static function of_type( string $type ): array {
		return array_values( array_filter( self::all(), static fn( Ability $ability ): bool => $ability->type() === $type ) );
	}

	/**
	 * What the MCP server should offer of one type right now.
	 *
	 * @param string $type Ability::TOOL, RESOURCE or PROMPT.
	 * @return Ability[]
	 */
	public static function offered( string $type ): array {
		return array_values( array_filter( self::of_type( $type ), static fn( Ability $ability ): bool => $ability->is_offered() ) );
	}

	/**
	 * Register everything with WordPress. Runs on wp_abilities_api_init.
	 */
	public static function register_all(): void {
		foreach ( self::all() as $ability ) {
			$ability->register();
		}
	}
}
