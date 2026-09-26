<?php
/**
 * Entry field record.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * One answer in a form submission.
 */
final class Entry_Field {

	/**
	 * Create a field record.
	 *
	 * @param string     $label The field's label on the form, e.g. "Your message".
	 * @param string     $value What the visitor entered. Untrusted, exactly as typed.
	 *                          Multiple choices are joined with ", ".
	 * @param Field_Role $role  What kind of information this is.
	 */
	public function __construct(
		public readonly string $label,
		public readonly string $value,
		public readonly Field_Role $role,
	) {}

	/**
	 * Whether this field holds personal data: a name, email, phone number or address.
	 *
	 * @return bool
	 */
	public function is_personal(): bool {
		return in_array( $this->role, array( Field_Role::Name, Field_Role::Email, Field_Role::Phone, Field_Role::Address ), true );
	}
}
