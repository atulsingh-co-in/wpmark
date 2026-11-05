<?php
/**
 * Update draft tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Drafts\Drafts;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Changes a draft that WPMark created, while it is still a draft.
 *
 * Marketing job 5 (draft content).
 */
final class Update_Draft extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'update-draft';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Change a draft', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Changes a draft that an AI app created through WPMark, while it is still a draft. Every change is kept as a revision.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Changes a DRAFT that was created through WPMark (wpmark-create-draft), while it is still a draft: use it for the person\'s feedback ("make the headline shorter", "add an FAQ"). Give the draft id and what to change: "fills" to put new content into some slots of the draft (slot keys from the draft\'s structure, returned by wpmark-create-draft or wpmark-page-structure; other slots stay as they are); or "sections" to rebuild the whole page (same format as wpmark-create-draft); and/or the title, slug, excerpt, page template, featured image or SEO fields. WordPress keeps the previous version as a revision. It refuses pages a person created, and anything already published, scheduled or sent for review. Returns the updated structure, preview link and notes. Never publishes, never deletes.', 'wpmark' ) . ( Settings::custom_design_enabled()
			? ' ' . __( 'For a custom-designed draft ("html_page" in its structure), send new "html" and/or "css" (the current ones are in html_page), and optionally "layout".', 'wpmark' )
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
				'id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The draft id.', 'wpmark' ),
				),
				'fills' => array(
					'type'        => 'object',
					'description' => __( 'New content for some of the draft\'s slots, by slot key, in the same form as "fills" in wpmark-create-draft.', 'wpmark' ),
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
		return array( 'id' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 * @throws Invalid_Input When the draft cannot be changed as asked.
	 */
	protected function run( array $input ): array {
		$result = Drafts::update( $input );

		if ( is_wp_error( $result ) ) {
			throw new Invalid_Input( $result->get_error_message() );
		}

		return $this->respond( $result );
	}
}
