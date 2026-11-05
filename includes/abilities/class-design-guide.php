<?php
/**
 * Design guide tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Builders\Builder;
use WPMark\Builders\Builders;
use WPMark\Builders\Form_Embeds;
use WPMark\Builders\Outline;
use WPMark\Drafts\Html_Page;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * How this site builds and styles its pages, so new pages match it.
 *
 * Marketing job 5 (draft content): the first step before planning a
 * landing page or a post layout.
 */
final class Design_Guide extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'design-guide';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Design guide', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Tells the AI how your site\'s pages are built and styled (page builder, colours, fonts, templates and forms), so new pages match your site.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Returns how this website builds and styles its pages, so anything you plan or draft matches the site instead of being invented. Includes: which page builder the site uses (recommended_builder: "elementor" or "blocks" for the WordPress block editor) and, for each available builder, the site\'s global colours, fonts, font sizes, spacing, content width, page templates (for example a full-width or no-header landing page template) and the building blocks or widgets available; plus the existing forms (Contact Form 7, WPForms, Gravity Forms) a page can embed. Use it first whenever someone wants a landing page, a new page or a post layout; then use wpmark-find-templates to pick a starting structure and wpmark-page-structure to read it. Never suggest colours or fonts that are not in this guide. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function run( array $input ): array {
		unset( $input );

		$recommended = Builders::recommended();

		return $this->respond(
			array(
				'site'                => array(
					'name'     => Outline::text( get_bloginfo( 'name' ) ),
					'tagline'  => Outline::text( get_bloginfo( 'description' ) ),
					'language' => get_bloginfo( 'language' ),
					'theme'    => Outline::text( wp_get_theme()->get( 'Name' ) ),
				),
				'recommended_builder' => $recommended->id(),
				'builders'            => array_values(
					array_map(
						static fn( Builder $builder ): array => array_merge(
							array( 'label' => $builder->label() ),
							$builder->design(),
							Settings::custom_design_enabled() ? array( 'css_variables' => Html_Page::css_variables( $builder ) ) : array()
						),
						Builders::available()
					)
				),
				'forms'               => Form_Embeds::all(),
				'slot_kinds'          => Outline::KINDS,
				'how_to_build'        => __( 'Start from the site\'s own structure: find a saved template or a similar page with wpmark-find-templates, read it with wpmark-page-structure, then write new text for its slots; the layout and styling stay the site\'s own.', 'wpmark' ) . ( Settings::custom_design_enabled() ? ' ' . __( 'Or, for a custom design, best for a new, professional landing page: write the page\'s HTML and CSS yourself ("html", "css" and "layout" in wpmark-create-draft), using the css_variables of the recommended builder for every colour and font, the site\'s content_width for the inner width, and its forms. Design it like a professional web designer: a clear hero with one main call to action, sections with plenty of white space, a consistent type scale, cards or columns that stack on phones, social proof, an FAQ, and a closing call to action.', 'wpmark' ) : '' ) . ' ' . ( Settings::user_can_draft( wp_get_current_user() )
					? __( 'When the person agrees the plan, save it with wpmark-create-draft. It stays a draft until they publish it in WordPress.', 'wpmark' )
					: __( 'Creating drafts is not switched on for this person, so give the plan and copy for them to build in WordPress, or ask a site administrator to allow drafts under WPMark → Access & privacy.', 'wpmark' ) ),
			)
		);
	}
}
