<?php
/**
 * SEO source registry.
 *
 * @package WPMark
 */

namespace WPMark\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the SEO plugin WPMark reads from.
 */
final class Seo_Sources {

	/**
	 * Every SEO source WPMark knows about.
	 *
	 * @return Seo_Source[]
	 */
	public static function all(): array {
		/**
		 * Filters the SEO sources WPMark can read from.
		 *
		 * @param Seo_Source[] $sources SEO source objects, in order of preference.
		 */
		$sources = apply_filters( 'wpmark_seo_sources', array( new Rank_Math_Seo_Source() ) );

		return array_values(
			array_filter(
				is_array( $sources ) ? $sources : array(),
				static fn( $source ): bool => $source instanceof Seo_Source
			)
		);
	}

	/**
	 * The first SEO source whose plugin is active, if any.
	 *
	 * @return Seo_Source|null
	 */
	public static function active(): ?Seo_Source {
		foreach ( self::all() as $source ) {
			if ( $source->is_available() ) {
				return $source;
			}
		}

		return null;
	}
}
