<?php
/**
 * SEO source interface.
 *
 * @package WPMark
 */

namespace WPMark\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * One SEO plugin, seen through a single shape WPMark's tools understand.
 *
 * Same idea as the form adapters: Rank Math is one class, Yoast would be
 * another, and the tools never talk to an SEO plugin directly. Read only.
 */
interface Seo_Source {

	/**
	 * Short, stable ID, e.g. "rank-math".
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Name shown to people, e.g. "Rank Math".
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Whether the SEO plugin is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * The SEO settings saved for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return Seo_Meta
	 */
	public function get_meta( int $post_id ): Seo_Meta;

	/**
	 * How many internal links point at each post, where the plugin knows.
	 *
	 * Posts the plugin has not scanned are left out, so a missing entry
	 * means "unknown", never "zero".
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, int> Post ID => incoming internal links.
	 */
	public function incoming_links( array $post_ids ): array;
}
