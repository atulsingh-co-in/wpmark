<?php
/**
 * Form source registry.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the list of form plugins WPMark knows how to read.
 *
 * Adding support for a new form plugin means writing one Form_Source class
 * and adding it to the list below (or, from another plugin, through the
 * wpmark_form_sources filter). Nothing else changes.
 */
final class Form_Sources {

	/**
	 * Every form source WPMark knows about, active or not.
	 *
	 * @return array<string, Form_Source> Keyed by source ID.
	 */
	public static function all(): array {
		// Built-in sources. Gravity Forms and others can follow as one class each.
		$built_in = array(
			new Elementor_Form_Source(),
			new WPForms_Form_Source(),
			new Flamingo_Form_Source(),
		);

		/**
		 * Filters the form sources WPMark can read leads from.
		 *
		 * @param Form_Source[] $sources Form source objects. Add yours to the end.
		 */
		$sources = apply_filters( 'wpmark_form_sources', $built_in );

		return self::validate( is_array( $sources ) ? $sources : array() );
	}

	/**
	 * The sources whose form plugin is active on this site.
	 *
	 * A site can run more than one form plugin (say, Elementor on landing
	 * pages and WPForms on the contact page), so this can return several.
	 *
	 * @return array<string, Form_Source> Keyed by source ID.
	 */
	public static function available(): array {
		return array_filter(
			self::all(),
			static fn( Form_Source $source ): bool => $source->is_available()
		);
	}

	/**
	 * Find one source by ID, if it is available.
	 *
	 * @param string $id Source ID, e.g. "wpforms".
	 * @return Form_Source|null
	 */
	public static function get( string $id ): ?Form_Source {
		return self::available()[ $id ] ?? null;
	}

	/**
	 * Drop anything that is not a proper Form_Source and key the rest by ID.
	 *
	 * A mistake in another plugin's filter should not break WPMark, so bad
	 * entries are skipped with a developer notice instead of an error.
	 *
	 * @param array $candidates Whatever the filter returned.
	 * @return array<string, Form_Source>
	 */
	private static function validate( array $candidates ): array {
		$sources = array();

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof Form_Source ) {
				_doing_it_wrong(
					'wpmark_form_sources',
					esc_html__( 'Each form source must be an object implementing WPMark\Forms\Form_Source.', 'wpmark' ),
					'0.1.0'
				);
				continue;
			}

			$id = $candidate->get_id();

			if ( ! preg_match( '/^[a-z0-9-]+$/', $id ) ) {
				_doing_it_wrong(
					'wpmark_form_sources',
					esc_html__( 'Form source IDs may only use lowercase letters, numbers and hyphens.', 'wpmark' ),
					'0.1.0'
				);
				continue;
			}

			if ( isset( $sources[ $id ] ) ) {
				_doing_it_wrong(
					'wpmark_form_sources',
					esc_html(
						sprintf(
							/* translators: %s: form source ID. */
							__( 'Two form sources use the ID "%s". Only the first one is used.', 'wpmark' ),
							$id
						)
					),
					'0.1.0'
				);
				continue;
			}

			$sources[ $id ] = $candidate;
		}

		return $sources;
	}
}
