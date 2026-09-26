<?php
/**
 * Form record.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * One form on the site, the same shape whichever plugin made it.
 */
final class Form {

	/**
	 * Create a form record.
	 *
	 * @param string $source ID of the Form_Source it came from, e.g. "wpforms".
	 * @param string $id     The form's ID within that source. A string, because
	 *                       some plugins do not use numbers (Elementor forms
	 *                       live inside a page and are identified by both).
	 * @param string $title  The form's name as the site owner set it.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $id,
		public readonly string $title,
	) {}
}
