<?php
/**
 * Field role detection.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what a form field holds from its type and label.
 *
 * Form plugins name field types differently ("tel" in Contact Form 7 and
 * Elementor, "phone" in WPForms). This maps them all to one Field_Role.
 * When the type is plain text, the label decides: a text field labelled
 * "Your name" is a name. Labels are matched in English; emails and phone
 * numbers inside any other field are still caught by
 * Masker::protect_free_text() when contact details are hidden or masked.
 */
final class Field_Roles {

	/**
	 * Field types that tell us the role directly.
	 */
	private const BY_TYPE = array(
		'email'    => Field_Role::Email,
		'tel'      => Field_Role::Phone,
		'phone'    => Field_Role::Phone,
		'name'     => Field_Role::Name,
		'address'  => Field_Role::Address,
		'textarea' => Field_Role::Message,
	);

	/**
	 * Label patterns, checked in order when the type does not decide.
	 */
	private const BY_LABEL = array(
		'/e-?mail/i'                                    => Field_Role::Email,
		'/phone|mobile|\btel\b|whats\s*app|contact\s*number/i' => Field_Role::Phone,
		'/address|street|postcode|post\s*code|zip|pin\s*code/i' => Field_Role::Address,
		'/\bname\b|first|last|surname|full[-_ ]?name/i' => Field_Role::Name,
		'/message|comment|enquiry|inquiry|question|details|requirement/i' => Field_Role::Message,
	);

	/**
	 * The role of a field.
	 *
	 * @param string $type  The plugin's field type, e.g. "email", "text", "textarea".
	 * @param string $label The field's label or name, e.g. "Your email".
	 * @return Field_Role
	 */
	public static function detect( string $type, string $label ): Field_Role {
		$type = strtolower( trim( $type ) );

		if ( isset( self::BY_TYPE[ $type ] ) ) {
			return self::BY_TYPE[ $type ];
		}

		// Dropdowns, checkboxes and numbers are answers to fixed options, never contact details.
		if ( in_array( $type, array( 'select', 'radio', 'checkbox', 'number', 'acceptance', 'date', 'date-time', 'rating', 'number-slider' ), true ) ) {
			return Field_Role::Other;
		}

		foreach ( self::BY_LABEL as $pattern => $role ) {
			if ( preg_match( $pattern, $label ) ) {
				return $role;
			}
		}

		return Field_Role::Other;
	}

	/**
	 * A readable label from a field's machine name: "your-message" becomes "Message".
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function label_from_name( string $name ): string {
		$label = preg_replace( '/^your[-_]/i', '', $name );
		$label = trim( str_replace( array( '-', '_' ), ' ', (string) $label ) );

		return '' !== $label ? ucfirst( $label ) : $name;
	}
}
