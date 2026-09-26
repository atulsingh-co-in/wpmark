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
	 * Every role on the site with its WPMark settings, saved or default.
	 *
	 * @return array<string, array{name: string, can_write: bool, enabled: bool, leads: Lead_Access}>
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
	 *
	 * @param array<string, bool> $caps The role's capabilities.
	 * @return array{enabled: bool, leads: Lead_Access}
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
	 * @param array<string, array{enabled?: mixed, leads?: mixed}> $choices Role slug => choice.
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
