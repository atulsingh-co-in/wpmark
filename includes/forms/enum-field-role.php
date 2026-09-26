<?php
/**
 * Field role.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * What a form field holds, whatever the form plugin calls it.
 *
 * The roles exist for privacy. Name, Email, Phone and Address are personal data (see
 * Entry_Field::is_personal()), so a tool can drop them unless it truly needs
 * them. Message is what the visitor asked, which is what most marketing
 * questions are about.
 */
enum Field_Role: string {

	/**
	 * The visitor's name. Personal data.
	 */
	case Name = 'name';

	/**
	 * An email address. Personal data.
	 */
	case Email = 'email';

	/**
	 * A phone number. Personal data.
	 */
	case Phone = 'phone';

	/**
	 * A postal address. Personal data.
	 */
	case Address = 'address';

	/**
	 * Free text the visitor wrote: the enquiry itself.
	 */
	case Message = 'message';

	/**
	 * Anything else: dropdowns, checkboxes, company name, budget…
	 */
	case Other = 'other';
}
