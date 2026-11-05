<?php
/**
 * Create draft tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Drafts\Drafts;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a draft page or post in the site's own style.
 *
 * Marketing job 5 (draft content).
 */
final class Create_Draft extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'create-draft';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Create a draft page or post', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Creates a draft page or post (for example a landing page) from your site\'s own templates, colours and fonts. Never publishes.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Creates a new DRAFT page or post on this website, for example a landing page for a product launch, built in the site\'s own page builder and style. It never publishes: a person reviews the draft and publishes it in WordPress. Before calling it: use wpmark-design-guide (builder, colours, fonts, page templates, forms), wpmark-find-templates and wpmark-page-structure (a template or similar page to start from), and agree the page\'s content with the person. Then send the title and the sections top to bottom, or, to make a version of one existing page (for example the same landing page for another city), "copy_of" with its id and "fills" for the slots to change. Best: copy sections from a saved template or existing page with "from" and give new content for their slots in "fills", so the layout and styling stay the site\'s own. Use "compose" for simple new sections, or the builder\'s own format ("blocks" or "elementor") when nothing similar exists. Images must already be in the media library (wpmark-list-media); WPMark never uploads files. Returns the draft\'s id, preview and edit links, its new structure (for changes with wpmark-update-draft), and notes on anything left out. Share the preview link with the person. Never publishes, never deletes, never changes existing pages.', 'wpmark' ) . ( Settings::custom_design_enabled()
			? ' ' . __( 'For a page designed from scratch, such as a professional landing page unlike the site\'s existing pages, send "html" and "css" instead of "sections": your own markup and styles, using the site\'s colours and fonts (css_variables in wpmark-design-guide); WPMark cleans them, keeps the CSS inside the page, and places it full width with the site\'s header and footer ("layout": "site") or alone ("blank"). Act on the design notes in the result.', 'wpmark' )
			: '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_write(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array_merge(
			array(
				'type'    => array(
					'type'        => 'string',
					'enum'        => array( 'page', 'post' ),
					'description' => __( '"page" (default) or "post".', 'wpmark' ),
				),
				'builder' => Draft_Schema::builder(),
				'copy_of' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Instead of "sections": the id of a page or post to copy whole into the new draft (its builder, layout, template and featured image). Put new content into some of its slots with "fills"; everything else stays as it is. The original is not changed.', 'wpmark' ),
				),
				'fills'   => array(
					'type'        => 'object',
					'description' => __( 'With "copy_of": new content for some slots of the copied page, by slot key from wpmark-page-structure, in the same form as "fills" in a section.', 'wpmark' ),
				),
			),
			Draft_Schema::post_fields(),
			array( 'sections' => Draft_Schema::sections() ),
			Draft_Schema::design()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function required_input(): array {
		return array( 'title' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 * @throws Invalid_Input When the draft cannot be created as asked.
	 */
	protected function run( array $input ): array {
		$result = Drafts::create( $input );

		if ( is_wp_error( $result ) ) {
			throw new Invalid_Input( $result->get_error_message() );
		}

		return $this->respond( $result );
	}
}
