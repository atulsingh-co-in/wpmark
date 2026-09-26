<?php
/**
 * Lead access levels.
 *
 * @package WPMark
 */

namespace WPMark\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * How much of a lead a person may see through WPMark.
 *
 * Set per WordPress role on the WPMark → Access & privacy screen. Someone
 * with several roles gets the most generous level among them.
 */
enum Lead_Access: string {

	/**
	 * No leads at all. The lead tools refuse to run.
	 */
	case None = 'none';

	/**
	 * What people asked, but no names, emails or phone numbers.
	 */
	case Hidden = 'hidden';

	/**
	 * Contact details partly hidden, e.g. j***@example.com.
	 */
	case Masked = 'masked';

	/**
	 * Everything, including full contact details.
	 */
	case Full = 'full';

	/**
	 * Order from least to most access, so levels can be compared.
	 *
	 * Static on purpose: PHPCompatibility 9 misreads $this inside enums.
	 *
	 * @param Lead_Access $level Level to rank.
	 * @return int 0 for None up to 3 for Full.
	 */
	public static function rank( Lead_Access $level ): int {
		return match ( $level ) {
			self::None   => 0,
			self::Hidden => 1,
			self::Masked => 2,
			self::Full   => 3,
		};
	}

	/**
	 * The more generous of two levels.
	 *
	 * @param Lead_Access $a First level.
	 * @param Lead_Access $b Second level.
	 * @return Lead_Access
	 */
	public static function most_generous( Lead_Access $a, Lead_Access $b ): Lead_Access {
		return self::rank( $a ) >= self::rank( $b ) ? $a : $b;
	}

	/**
	 * Label shown on the settings screen.
	 *
	 * @param Lead_Access $level Level.
	 * @return string Translated label.
	 */
	public static function label( Lead_Access $level ): string {
		return match ( $level ) {
			self::None   => __( 'No access to leads', 'wpmark' ),
			self::Hidden => __( 'Leads without contact details', 'wpmark' ),
			self::Masked => __( 'Leads with masked contact details', 'wpmark' ),
			self::Full   => __( 'Leads with full contact details', 'wpmark' ),
		};
	}
}
