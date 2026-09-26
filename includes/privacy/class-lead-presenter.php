<?php
/**
 * Lead presenter.
 *
 * @package WPMark
 */

namespace WPMark\Privacy;

use WPMark\Forms\Entry;
use WPMark\Forms\Field_Role;
use WPMark\Output\Untrusted_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a lead into what one particular reader may see.
 *
 * This is the single place where the Access & privacy settings are applied
 * to lead data, so no tool can forget them:
 *
 * - Full:   contact details as entered.
 * - Masked: contact details partly hidden (r***@acme.com).
 * - Hidden: contact details left out entirely.
 *
 * In every case, emails and phone numbers typed into other fields are
 * masked or removed to match, all text is cleaned as untrusted, and page
 * addresses lose any query string except campaign (utm_) tags.
 */
final class Lead_Presenter {

	/**
	 * One lead as the reader may see it.
	 *
	 * @param Entry       $entry      The lead.
	 * @param Lead_Access $level      The reader's access level.
	 * @param string      $form_title The form's title.
	 * @return array
	 */
	public static function present( Entry $entry, Lead_Access $level, string $form_title ): array {
		$fields = array();

		foreach ( $entry->fields as $field ) {
			if ( $field->is_personal() ) {
				if ( Lead_Access::Full !== $level && Lead_Access::Masked !== $level ) {
					continue;
				}

				$value = Untrusted_Text::clean( $field->value, 300 );
				$value = Lead_Access::Masked === $level ? self::mask( $field->role, $value ) : $value;
			} else {
				$value = Masker::protect_free_text( Untrusted_Text::clean( $field->value ), $level );
			}

			$fields[] = array(
				'label' => Untrusted_Text::clean( $field->label, 200 ),
				'kind'  => $field->role->value,
				'value' => $value,
			);
		}

		return array(
			'source'        => $entry->source,
			'form_id'       => $entry->form_id,
			'form'          => Untrusted_Text::clean( $form_title, 200 ),
			'submitted_at'  => $entry->submitted_at->format( 'Y-m-d\TH:i:s\Z' ),
			'page_url'      => null !== $entry->page_url ? self::safe_url( $entry->page_url ) : null,
			'visitor_wrote' => $fields,
		);
	}

	/**
	 * Explain the reader's level to the AI, so it can explain it to the person.
	 *
	 * @param Lead_Access $level Level.
	 * @return string
	 */
	public static function explain( Lead_Access $level ): string {
		return match ( $level ) {
			Lead_Access::Full   => __( 'Contact details are shown in full because the site administrator allows it for your role. Use them only for following up on the enquiry.', 'wpmark' ),
			Lead_Access::Masked => __( 'Contact details are partly hidden (for example r***@acme.com) by the site\'s privacy settings. The full details are in the form plugin inside WordPress.', 'wpmark' ),
			default             => __( 'Names, emails, phone numbers and addresses are not shared with AI assistants for your role. The full details are in the form plugin inside WordPress.', 'wpmark' ),
		};
	}

	/**
	 * A page address without query strings, except utm_ campaign tags.
	 *
	 * Query strings can carry personal data (?email=...) and add noise.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function safe_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return Untrusted_Text::clean( (string) strtok( $url, '?#' ), 500 );
		}

		$clean = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' );

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$utm = array_filter(
				$query,
				static fn( $value, $key ): bool => is_string( $value ) && str_starts_with( (string) $key, 'utm_' ),
				ARRAY_FILTER_USE_BOTH
			);

			if ( $utm ) {
				$clean .= '?' . http_build_query( $utm );
			}
		}

		return Untrusted_Text::clean( $clean, 500 );
	}

	/**
	 * Mask a personal value according to its role.
	 *
	 * @param Field_Role $role  Role.
	 * @param string     $value Value.
	 * @return string
	 */
	private static function mask( Field_Role $role, string $value ): string {
		return match ( $role ) {
			Field_Role::Email => Masker::email( $value ),
			Field_Role::Phone => Masker::phone( $value ),
			default           => Masker::name( $value ),
		};
	}
}
