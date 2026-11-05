<?php
/**
 * WPMark settings.
 *
 * @package WPMark
 */

namespace WPMark;

use WP_User;
use WPMark\Privacy\Lead_Access;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves what the site owner chose on the WPMark screens.
 *
 * Everything lives in one option, "wpmark_settings":
 *
 *     'tools' => [ 'list-leads' => false, ... ]              Tools switched off.
 *     'roles' => [ 'editor' => [ 'enabled' => true,          Can use WPMark.
 *                                'leads'   => 'hidden' ] ]   Lead access level.
 *
 * Roles are read live from WordPress, so a role added later by another
 * plugin appears automatically, with safe defaults, until someone saves a
 * choice for it.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'wpmark_settings';

	/**
	 * Whether a tool is switched on. Tools are on unless switched off.
	 *
	 * @param string $slug Tool slug, e.g. "list-leads".
	 * @return bool
	 */
	public static function tool_enabled( string $slug ): bool {
		$tools = self::raw()['tools'] ?? array();

		return ! isset( $tools[ $slug ] ) || (bool) $tools[ $slug ];
	}

	/**
	 * Whether AI apps may sign in with OAuth ("Sign in with WordPress"). On unless switched off.
	 *
	 * @return bool
	 */
	public static function oauth_enabled(): bool {
		return (bool) ( self::raw()['oauth'] ?? true );
	}

	/**
	 * Switch OAuth sign-in on or off.
	 *
	 * @param bool $enabled On or off.
	 */
	public static function save_oauth( bool $enabled ): void {
		$settings          = self::raw();
		$settings['oauth'] = $enabled;

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Whether AI apps may create drafts at all. Off until an administrator
	 * switches it on: WPMark only reads unless the site chooses otherwise.
	 *
	 * @return bool
	 */
	public static function drafts_enabled(): bool {
		return ! empty( self::raw()['drafts'] );
	}

	/**
	 * Switch draft writing on or off for the whole site.
	 *
	 * @param bool $enabled On or off.
	 */
	public static function save_drafts( bool $enabled ): void {
		$settings           = self::raw();
		$settings['drafts'] = $enabled;

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Whether AI apps may design pages in their own HTML and CSS
	 * (experimental). Off by default; needs drafts on too.
	 *
	 * @return bool
	 */
	public static function custom_design_enabled(): bool {
		return self::drafts_enabled() && ! empty( self::raw()['custom_design'] );
	}

	/**
	 * Switch the experimental custom page design on or off.
	 *
	 * @param bool $enabled On or off.
	 */
	public static function save_custom_design( bool $enabled ): void {
		$settings                  = self::raw();
		$settings['custom_design'] = $enabled;

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Whether an add-on (WPMark Pro) lets AI apps publish on this site.
	 * WPMark itself never publishes; this only keeps its wording honest.
	 *
	 * @return bool
	 */
	public static function publishing_allowed(): bool {
		/**
		 * Filters whether AI apps may publish through an add-on.
		 *
		 * @param bool $allowed Default false: WPMark itself never publishes.
		 */
		return (bool) apply_filters( 'wpmark_publishing_allowed', false );
	}

	/**
	 * Whether WPMark publishes an llms.txt summary of the site. Off by default.
	 *
	 * @return bool
	 */
	public static function llms_txt_enabled(): bool {
		return ! empty( self::raw()['llms_txt'] );
	}

	/**
	 * Whether forms and search are described for AI agents in visitors'
	 * browsers (WebMCP). Off by default: the standard is still experimental.
	 *
	 * @return bool
	 */
	public static function webmcp_enabled(): bool {
		return ! empty( self::raw()['webmcp'] );
	}

	/**
	 * Switch the agent-ready features on or off.
	 *
	 * @param bool $llms_txt Publish llms.txt.
	 * @param bool $webmcp   Describe forms and search for AI agents.
	 */
	public static function save_agent_features( bool $llms_txt, bool $webmcp ): void {
		$settings             = self::raw();
		$settings['llms_txt'] = $llms_txt;
		$settings['webmcp']   = $webmcp;

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Every role on the site with its WPMark settings, saved or default.
	 *
	 * @return array<string, array{name: string, can_write: bool, enabled: bool, leads: Lead_Access, drafts: bool}>
	 */
	public static function roles(): array {
		$saved = self::raw()['roles'] ?? array();
		$roles = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps      = $role['capabilities'] ?? array();
			$can_write = ! empty( $caps['edit_posts'] );
			$defaults  = self::role_defaults( $caps );
			$choice    = is_array( $saved[ $slug ] ?? null ) ? $saved[ $slug ] : array();

			$roles[ $slug ] = array(
				'name'      => translate_user_role( $role['name'] ),
				'can_write' => $can_write,
				// A role that cannot write posts can never use WPMark, whatever was saved.
				'enabled'   => $can_write && (bool) ( $choice['enabled'] ?? $defaults['enabled'] ),
				'leads'     => Lead_Access::tryFrom( (string) ( $choice['leads'] ?? '' ) ) ?? $defaults['leads'],
				// Whether the role may let AI apps create drafts (when drafts are on for the site).
				'drafts'    => $can_write && (bool) ( $choice['drafts'] ?? $defaults['drafts'] ),
			);
		}

		return $roles;
	}

	/**
	 * Safe starting point for a role nobody has configured yet.
	 *
	 * Administrators see leads with masked contact details, editors see
	 * leads without contact details, everyone else sees no leads. Full
	 * contact details are always something an administrator switches on.
	 * Roles that can edit other people's content (administrators and
	 * editors) may create drafts once drafts are switched on for the site.
	 *
	 * @param array<string, bool> $caps The role's capabilities.
	 * @return array{enabled: bool, leads: Lead_Access, drafts: bool}
	 */
	public static function role_defaults( array $caps ): array {
		if ( ! empty( $caps['manage_options'] ) ) {
			$leads = Lead_Access::Masked;
		} elseif ( ! empty( $caps['edit_others_posts'] ) ) {
			$leads = Lead_Access::Hidden;
		} else {
			$leads = Lead_Access::None;
		}

		return array(
			'enabled' => ! empty( $caps['edit_posts'] ),
			'leads'   => $leads,
			'drafts'  => ! empty( $caps['edit_others_posts'] ),
		);
	}

	/**
	 * Whether a person may connect an AI assistant through WPMark.
	 *
	 * @param WP_User $user The person.
	 * @return bool
	 */
	public static function user_can_connect( WP_User $user ): bool {
		if ( ! $user->exists() || ! user_can( $user, 'edit_posts' ) ) {
			return false;
		}

		$roles = self::roles();

		foreach ( self::user_role_slugs( $user ) as $slug ) {
			if ( ! empty( $roles[ $slug ]['enabled'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a person may let AI apps create drafts through WPMark.
	 *
	 * Needs drafts switched on for the site and a role allowed to. WordPress's
	 * own capabilities still apply on top (for example, editing pages).
	 *
	 * @param WP_User $user The person.
	 * @return bool
	 */
	public static function user_can_draft( WP_User $user ): bool {
		if ( ! self::drafts_enabled() || ! self::user_can_connect( $user ) ) {
			return false;
		}

		$roles = self::roles();

		foreach ( self::user_role_slugs( $user ) as $slug ) {
			if ( ! empty( $roles[ $slug ]['enabled'] ) && ! empty( $roles[ $slug ]['drafts'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How much of a lead a person may see: the most generous of their roles.
	 *
	 * @param WP_User $user The person.
	 * @return Lead_Access
	 */
	public static function lead_access_for( WP_User $user ): Lead_Access {
		if ( ! self::user_can_connect( $user ) ) {
			return Lead_Access::None;
		}

		$roles  = self::roles();
		$access = Lead_Access::None;

		foreach ( self::user_role_slugs( $user ) as $slug ) {
			if ( ! empty( $roles[ $slug ]['enabled'] ) ) {
				$access = Lead_Access::most_generous( $access, $roles[ $slug ]['leads'] );
			}
		}

		return $access;
	}

	/**
	 * Save the tool switches.
	 *
	 * @param array<string, bool> $tools Slug => on or off, for every known tool.
	 */
	public static function save_tools( array $tools ): void {
		$settings          = self::raw();
		$settings['tools'] = array_map( 'boolval', $tools );

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Save role choices. Unknown roles and levels are ignored.
	 *
	 * @param array<string, array{enabled?: mixed, leads?: mixed, drafts?: mixed}> $choices Role slug => choice.
	 */
	public static function save_roles( array $choices ): void {
		$settings = self::raw();
		$clean    = array();

		foreach ( self::roles() as $slug => $role ) {
			$choice = is_array( $choices[ $slug ] ?? null ) ? $choices[ $slug ] : array();
			$level  = Lead_Access::tryFrom( (string) ( $choice['leads'] ?? '' ) ) ?? Lead_Access::None;

			$clean[ $slug ] = array(
				'enabled' => $role['can_write'] && ! empty( $choice['enabled'] ),
				'leads'   => $level->value,
				'drafts'  => $role['can_write'] && ! empty( $choice['drafts'] ),
			);
		}

		$settings['roles'] = $clean;

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * The roles that decide a person's access. On multisite, a network
	 * administrator who has no role on this site is treated as an administrator.
	 *
	 * @param WP_User $user The person.
	 * @return string[]
	 */
	private static function user_role_slugs( WP_User $user ): array {
		$slugs = array_values( (array) $user->roles );

		if ( ! $slugs && is_multisite() && is_super_admin( $user->ID ) ) {
			$slugs = array( 'administrator' );
		}

		return $slugs;
	}

	/**
	 * The stored option, always as an array.
	 *
	 * @return array
	 */
	private static function raw(): array {
		$value = get_option( self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}
}
