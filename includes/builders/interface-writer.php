<?php
/**
 * Page writer interface.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_Error;
use WP_Post;
use WPMark\Drafts\Draft_Content;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a draft in one page builder's own format.
 *
 * A draft is a list of sections. Each section comes from one of three
 * places, always in the builder's native format ("nodes": blocks for the
 * block editor, elements for Elementor):
 *
 * 1. Copied from the site: a section of a saved template, pattern or page,
 *    with new content in its slots (fill()). The safest way: the layout and
 *    styling are the site's own.
 * 2. Composed: a simple list of headings, text, buttons, images, lists,
 *    FAQs, quotes and forms, laid out the builder's standard way, using the
 *    site's global colours and fonts (compose()).
 * 3. Native: the builder's own format, as its official export uses (block
 *    markup, or Elementor's template JSON), checked and cleaned (native()).
 *
 * Writers never publish: save() always leaves the post a draft.
 */
interface Writer {

	/**
	 * A post's sections in native form.
	 *
	 * @param WP_Post $post Post, template or reusable section.
	 * @return array<string, array> Section key (as in the outline) => nodes.
	 */
	public function sections( WP_Post $post ): array;

	/**
	 * Put new content into the slots of some nodes.
	 *
	 * @param array         $nodes   Native nodes of one section.
	 * @param string        $key     The section's key, as in the outline.
	 * @param array         $fills   Slot key => new content.
	 * @param Draft_Content $content Content checks and notes.
	 * @param string[]      $applied Slot keys filled so far; updated.
	 * @return array Nodes with the new content.
	 */
	public function fill( array $nodes, string $key, array $fills, Draft_Content $content, array &$applied ): array;

	/**
	 * Build a new section from a simple list of items.
	 *
	 * @param array         $spec    items or columns, and an optional background colour.
	 * @param Draft_Content $content Content checks and notes.
	 * @return array Nodes.
	 */
	public function compose( array $spec, Draft_Content $content ): array;

	/**
	 * Check and clean a section given in the builder's own format.
	 *
	 * @param mixed         $value   Native content from the AI.
	 * @param Draft_Content $content Content checks and notes.
	 * @return array Nodes (empty when nothing usable was given).
	 */
	public function native( $value, Draft_Content $content ): array;

	/**
	 * A custom-designed page: its HTML pieces and forms inside one
	 * full-width wrapper with the class Html_Page::SCOPE.
	 *
	 * @param array<int, array{type: string, value: string}> $parts Cleaned HTML ("html") and form shortcodes ("form"), in order.
	 * @return array Nodes.
	 */
	public function html( array $parts ): array;

	/**
	 * Create or update a draft.
	 *
	 * @param WP_Post|null $existing The WPMark draft to update, or null to create one.
	 * @param array        $post     Post fields: post_title, post_type, post_name, post_excerpt, page_template.
	 * @param array|null   $nodes    All nodes of the page, or null to keep the current content.
	 * @return int|WP_Error Post ID.
	 */
	public function save( ?WP_Post $existing, array $post, ?array $nodes );
}
