<?php
/**
 * Forms resource.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Forms\Form_Sources;
use WPMark\Forms\Lead_Reader;
use WPMark\Output\Untrusted_Text;

defined( 'ABSPATH' ) || exit;

/**
 * The site's forms, so an AI knows which ones exist before reading leads.
 *
 * Marketing job 2. Lists forms only, never submissions.
 */
final class Forms_Resource extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return self::RESOURCE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function resource_uri(): string {
		return 'wpmark://forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Forms on this site', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Every contact and enquiry form on this website, grouped by form plugin, with each form\'s source and form_id (for filtering wpmark-list-leads) and whether that plugin saves submissions WPMark can read. Lists forms only, never submissions.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return (bool) Form_Sources::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Unused.
	 */
	protected function run( array $input ): array {
		$plugins = array();

		foreach ( Form_Sources::available() as $source ) {
			$plugins[] = array(
				'source'      => $source->get_id(),
				'name'        => $source->get_label(),
				'saves_leads' => $source->supports_entries(),
				'forms'       => array_map(
					static fn( $form ): array => array(
						'form_id' => $form->id,
						'title'   => Untrusted_Text::clean( $form->title, 200 ),
					),
					$source->list_forms()
				),
			);
		}

		return $this->respond(
			array(
				'form_plugins'                     => $plugins,
				'form_plugins_without_saved_leads' => Lead_Reader::sources_without_saved_leads(),
			)
		);
	}
}
