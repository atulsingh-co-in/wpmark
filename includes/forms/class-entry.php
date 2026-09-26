<?php
/**
 * Entry record.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One form submission, the same shape whichever plugin saved it.
 */
final class Entry {

	/**
	 * Create an entry record.
	 *
	 * @param string            $source       ID of the Form_Source it came from.
	 * @param string            $id           The entry's ID within that source.
	 * @param string            $form_id      ID of the form it was submitted through.
	 * @param DateTimeImmutable $submitted_at When it was submitted, in UTC.
	 * @param Entry_Field[]     $fields       The answers, in form order.
	 * @param string|null       $page_url     The page the form was submitted from,
	 *                                        when the plugin records it.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $id,
		public readonly string $form_id,
		public readonly DateTimeImmutable $submitted_at,
		public readonly array $fields,
		public readonly ?string $page_url = null,
	) {}

	/**
	 * The fields with a given role, e.g. only the messages.
	 *
	 * @param Field_Role $role Role to keep.
	 * @return Entry_Field[]
	 */
	public function fields_with_role( Field_Role $role ): array {
		return array_values(
			array_filter(
				$this->fields,
				static fn( Entry_Field $field ): bool => $field->role === $role
			)
		);
	}

	/**
	 * The fields that are not personal data (no name, email, phone or address).
	 *
	 * @return Entry_Field[]
	 */
	public function fields_without_personal_data(): array {
		return array_values(
			array_filter(
				$this->fields,
				static fn( Entry_Field $field ): bool => ! $field->is_personal()
			)
		);
	}
}
