<?php
/**
 * Page builder registry.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Which page builders this site has, and which one a page uses.
 */
final class Builders {

	/**
	 * Every page builder WPMark knows, available or not.
	 *
	 * @return array<string, Builder> Keyed by ID.
	 */
	public static function all(): array {
		$built_in = array( new Elementor(), new Block_Editor() );

		/**
		 * Filters the page builders WPMark can read and write.
		 *
		 * @param Builder[] $builders Builder objects. Add yours before the block editor,
		 *                            which is the fallback and must stay last.
		 */
		$builders = apply_filters( 'wpmark_builders', $built_in );

		$keyed = array();
		foreach ( is_array( $builders ) ? $builders : array() as $builder ) {
			if ( $builder instanceof Builder ) {
				$keyed[ $builder->id() ] = $builder;
			}
		}

		// The block editor is always there, as the fallback.
		$keyed['blocks'] ??= new Block_Editor();

		return $keyed;
	}

	/**
	 * The page builders active on this site.
	 *
	 * @return array<string, Builder>
	 */
	public static function available(): array {
		return array_filter( self::all(), static fn( Builder $builder ): bool => $builder->is_available() );
	}

	/**
	 * One available builder by ID.
	 *
	 * @param string $id Builder ID.
	 * @return Builder|null
	 */
	public static function get( string $id ): ?Builder {
		return self::available()[ $id ] ?? null;
	}

	/**
	 * The builder a post was made with. The block editor when no other
	 * builder claims it.
	 *
	 * @param WP_Post $post Post.
	 * @return Builder
	 */
	public static function for_post( WP_Post $post ): Builder {
		foreach ( self::available() as $id => $builder ) {
			if ( 'blocks' !== $id && $builder->owns( $post ) ) {
				return $builder;
			}
		}

		return self::all()['blocks'];
	}

	/**
	 * The builder new pages should use: the one the site's pages are built
	 * with, so a new page looks like the rest.
	 *
	 * @return Builder
	 */
	public static function recommended(): Builder {
		$elementor = self::get( 'elementor' );

		return $elementor instanceof Elementor && $elementor->is_in_use() ? $elementor : self::all()['blocks'];
	}
}
