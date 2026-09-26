<?php
/**
 * Rank Math SEO source.
 *
 * @package WPMark
 */

namespace WPMark\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what Rank Math saves with each post.
 *
 * Rank Math stores its per-post settings as post meta (rank_math_title,
 * rank_math_description, rank_math_focus_keyword, rank_math_robots) and
 * counts internal links in its own table, rank_math_internal_meta. WPMark
 * reads those directly, so it works whichever Rank Math modules are on and
 * never runs Rank Math code.
 */
class Rank_Math_Seo_Source implements Seo_Source {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'rank-math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Rank Math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 */
	public function get_meta( int $post_id ): Seo_Meta {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );

		// Saved as an array such as [ 'noindex', 'nofollow' ]; older versions may use a string.
		$robots = is_array( $robots ) ? $robots : preg_split( '/[\s,]+/', (string) $robots, -1, PREG_SPLIT_NO_EMPTY );

		// Several focus keywords are saved comma-separated; the first is the main one.
		$keywords = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ) )
			)
		);

		return new Seo_Meta(
			trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) ),
			trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) ),
			$keywords,
			in_array( 'noindex', $robots, true )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	public function incoming_links( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_filter( array_map( 'intval', $post_ids ) );
		$table    = $wpdb->prefix . 'rank_math_internal_meta';

		if ( ! $post_ids || ! $this->table_exists( $table ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders -- Rank Math's own table; one %d placeholder per ID.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is our prefix plus a fixed name; IDs are placeholders.
				"SELECT object_id, incoming_link_count FROM {$table} WHERE object_id IN ({$placeholders})",
				$post_ids
			)
		);
		// phpcs:enable

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->object_id ] = (int) $row->incoming_link_count;
		}

		return $counts;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
