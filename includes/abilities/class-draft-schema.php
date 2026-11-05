<?php
/**
 * Input schema shared by the draft tools.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Builders\Builders;
use WPMark\Drafts\Html_Page;

defined( 'ABSPATH' ) || exit;

/**
 * The parts of a draft an AI app can send, described for the AI.
 */
final class Draft_Schema {

	/**
	 * The sections of a page.
	 *
	 * @return array
	 */
	public static function sections(): array {
		return array(
			'type'        => 'array',
			'minItems'    => 1,
			'maxItems'    => 30,
			'description' => __( 'The page, top to bottom. Each section is one of: (1) "from": copy a section of a template, pattern or page (from wpmark-page-structure) and put new content in its slots with "fills"; (2) "compose": a new section from simple items; (3) "blocks" (block markup) or "elementor" (Elementor template JSON, as Elementor exports it), matching the draft\'s builder. Prefer "from": it keeps the site\'s own design.', 'wpmark' ),
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'from'      => array(
						'type'        => 'object',
						'description' => __( 'Where to copy the section from: id (a page, post, saved template or reusable section) or pattern (a block pattern name), and section (the section key from wpmark-page-structure).', 'wpmark' ),
						'properties'  => array(
							'id'      => array( 'type' => 'integer' ),
							'pattern' => array( 'type' => 'string' ),
							'section' => array( 'type' => 'string' ),
						),
						'required'    => array( 'section' ),
					),
					'fills'     => array(
						'type'        => 'object',
						'description' => __( 'New content for the copied section\'s slots, by slot key. A heading or text slot takes a string (simple formatting allowed: <strong>, <em>, <a href>, <br>) or { "text", "level" }; a button { "text", "link" }; an image or background { "image_id", "alt" } (images from wpmark-list-media); a list { "items": [ { "text", "link" } ] }; a card { "title", "text", "link", "image_id", "button_text" }; a testimonial { "quote", "name", "role" }; an faq { "items": [ { "question", "answer" } ] }; a counter { "title", "number", "suffix" }; a price { "title", "subtitle", "price", "features", "button_text", "link" }; a video { "url" } (YouTube or Vimeo); a form { "plugin", "form" } (from wpmark-design-guide). Slots you leave out keep the template\'s content, so fill every slot that should change.', 'wpmark' ),
					),
					'compose'   => array(
						'type'        => 'object',
						'description' => __( 'A new section: "items" (top to bottom) or "columns" (2 to 4 lists of items side by side), and optionally "background" (one of the site\'s colours from wpmark-design-guide: a slug for the block editor, an id for Elementor). Each item has a "kind" and its fields: heading { text, level }, text { text }, button { text, link }, image { image_id, alt }, list { items: [ { text } ] }, faq { items: [ { question, answer } ] }, testimonial { quote, name, role }, video { url }, form { plugin, form }. Styling comes from the site\'s theme or Elementor kit.', 'wpmark' ),
						'properties'  => array(
							'items'      => array( 'type' => 'array' ),
							'columns'    => array( 'type' => 'array' ),
							'background' => array( 'type' => 'string' ),
						),
					),
					'blocks'    => array(
						'type'        => 'string',
						'description' => __( 'Block markup for a block editor draft, as the block editor\'s code view shows it. Use the theme\'s preset colours and sizes. Custom HTML blocks, scripts and images not in the media library are removed.', 'wpmark' ),
					),
					'elementor' => array(
						'type'        => array( 'object', 'array', 'string' ),
						'description' => __( 'Elementor content for an Elementor draft, in Elementor\'s own template format: an exported template ({ "content": [ ... ] }), a list of elements, or one element (elType, settings, elements; widgets also widgetType). Use the site\'s global colours and fonts ("__globals__"). HTML widgets, custom code and images not in the media library are removed.', 'wpmark' ),
					),
				),
			),
		);
	}

	/**
	 * Post fields shared by create and update.
	 *
	 * @return array<string, array>
	 */
	public static function post_fields(): array {
		return array(
			'title'             => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'The page or post title.', 'wpmark' ),
			),
			'slug'              => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'Optional: the last part of its web address, e.g. "spring-launch".', 'wpmark' ),
			),
			'excerpt'           => array(
				'type'        => 'string',
				'maxLength'   => 500,
				'description' => __( 'Optional, for posts: a short summary.', 'wpmark' ),
			),
			'page_template'     => array(
				'type'        => 'string',
				'description' => __( 'Optional, for pages: a page template from wpmark-design-guide, e.g. "elementor_canvas" for a landing page without the site header and footer. "" for the default.', 'wpmark' ),
			),
			'featured_image_id' => array(
				'type'        => 'integer',
				'description' => __( 'Optional: a media library image id for the featured image.', 'wpmark' ),
			),
			'seo'               => array(
				'type'        => 'object',
				'description' => __( 'Optional: SEO title, meta description and focus keyword (saved when Rank Math is active).', 'wpmark' ),
				'properties'  => array(
					'title'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'focus_keyword' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * A custom design: the page's own HTML and CSS.
	 *
	 * @return array<string, array>
	 */
	public static function design(): array {
		return array(
			'html'   => array(
				'type'        => 'string',
				'maxLength'   => 200000,
				'description' => __( 'Experimental, only when an administrator has switched on custom page design (wpmark-design-guide then lists css_variables). For a custom-designed page (instead of "sections"): the page\'s own HTML, the body content only (no <html>, <head> or <body>), written like a professional landing page. Build it from full-width <section> elements, each with an inner wrapper (centred, max-width about the site\'s content_width, generous padding); one <h1> at the top, <h2> for sections. Images: only media library addresses from wpmark-list-media, with alt text. Forms: put the site\'s form shortcode where the form goes, e.g. [wpforms id="12"] (from "forms" in wpmark-design-guide). Videos: YouTube or Vimeo <iframe>. Buttons are links: <a class="button" href="...">. Scripts, event attributes, form fields and outside images are removed.', 'wpmark' ),
			),
			'css'    => array(
				'type'        => 'string',
				'maxLength'   => 60000,
				'description' => __( 'Experimental, with "html": the page\'s CSS. It applies only inside this page (WPMark limits every rule to .wpmark-page), so it cannot change the site\'s header, footer or menus. Use the site\'s colours and fonts through the variables in wpmark-design-guide (css_variables), e.g. color: var(--wpmark-color-primary). Make it responsive: add @media (max-width: 768px) rules so columns stack and text stays readable. No JavaScript: use CSS for hover effects and <details> for FAQs. @import, @font-face, outside fonts and files not in the media library are removed. On update-draft, "css" alone replaces the CSS and keeps the HTML.', 'wpmark' ),
			),
			'layout' => array(
				'type'        => 'string',
				'enum'        => Html_Page::LAYOUTS,
				'description' => __( 'Experimental, with "html", for pages: "site" (default) shows the page full width between the site\'s own header and footer; "blank" shows it alone, without them, as a stand-alone landing page.', 'wpmark' ),
			),
		);
	}

	/**
	 * Builder choice for a new draft.
	 *
	 * @return array
	 */
	public static function builder(): array {
		return array(
			'type'        => 'string',
			'enum'        => array_keys( Builders::all() ),
			'description' => __( 'Optional: "elementor" or "blocks". Default: the builder the site\'s pages use (recommended_builder in wpmark-design-guide).', 'wpmark' ),
		);
	}
}
