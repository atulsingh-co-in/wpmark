<?php
/**
 * Page builder interface.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * One way of building pages (the WordPress block editor, Elementor…), seen
 * through a single shape WPMark's tools understand.
 *
 * WPMark never invents a page layout. It reads how the site already builds
 * pages (its colours, fonts, saved templates and existing pages), shows the
 * AI that structure as a simple outline of sections and slots, and lets the
 * AI fill or adjust it. Each page builder gets one class implementing this
 * interface, and the tools only ever talk to the interface.
 *
 * Rules for every implementation:
 *
 * 1. Reading never changes anything.
 * 2. Text from the site is untrusted: clean it with Outline helpers, which
 *    pass it through Untrusted_Text, before it reaches the AI.
 * 3. Slot and section keys must be stable for a given page, so a later
 *    request can say "fill slot X of section Y".
 */
interface Builder {

	/**
	 * Short, stable ID, used in tool input and output.
	 *
	 * @return string e.g. "blocks" or "elementor".
	 */
	public function id(): string;

	/**
	 * Name shown to people, e.g. "Elementor".
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether this builder can be used on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Whether this post was built with this builder.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function owns( WP_Post $post ): bool;

	/**
	 * The site's look as this builder sees it: colours, fonts, sizes, page
	 * templates and the building blocks it offers.
	 *
	 * @return array
	 */
	public function design(): array;

	/**
	 * Saved templates, reusable sections and existing pages that can be used
	 * as the starting structure for a new page.
	 *
	 * @param string $search Words to look for in titles, or "".
	 * @param int    $limit  Most results per kind.
	 * @return array<int, array> Each with ref, kind, title and builder.
	 */
	public function templates( string $search, int $limit ): array;

	/**
	 * A post's structure as sections and slots.
	 *
	 * @param WP_Post $post Post, template or reusable section.
	 * @return array<int, array> Sections, each with key, label, slots.
	 */
	public function outline( WP_Post $post ): array;

	/**
	 * The writer that builds drafts in this builder's format.
	 *
	 * @return Writer
	 */
	public function writer(): Writer;
}
