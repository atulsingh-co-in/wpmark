<?php
/**
 * SEO meta record.
 *
 * @package WPMark
 */

namespace WPMark\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The SEO settings for one post, the same shape whichever plugin saved them.
 */
final class Seo_Meta {

	/**
	 * Create a record.
	 *
	 * @param string   $title          Custom SEO title, or "" when the plugin's default template is used.
	 * @param string   $description    Custom meta description, or "".
	 * @param string[] $focus_keywords Focus keywords, the main one first.
	 * @param bool     $noindex        Whether this post is set to be hidden from search engines.
	 */
	public function __construct(
		public readonly string $title = '',
		public readonly string $description = '',
		public readonly array $focus_keywords = array(),
		public readonly bool $noindex = false,
	) {}
}
