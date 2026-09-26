<?php
/**
 * Tests for the form records and query.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use WP_UnitTestCase;
use WPMark\Forms\Entry;
use WPMark\Forms\Entry_Field;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Field_Role;

/**
 * Entry, Entry_Field and Entry_Query.
 */
class Test_Form_Records extends WP_UnitTestCase {

	/**
	 * Names, emails, phone numbers and addresses count as personal data. Nothing else does.
	 */
	public function test_personal_roles(): void {
		$this->assertTrue( $this->field( Field_Role::Name )->is_personal() );
		$this->assertTrue( $this->field( Field_Role::Email )->is_personal() );
		$this->assertTrue( $this->field( Field_Role::Phone )->is_personal() );
		$this->assertTrue( $this->field( Field_Role::Address )->is_personal() );
		$this->assertFalse( $this->field( Field_Role::Message )->is_personal() );
		$this->assertFalse( $this->field( Field_Role::Other )->is_personal() );
	}

	/**
	 * An entry can hand back just its non-personal fields, in order.
	 */
	public function test_entry_drops_personal_fields(): void {
		$message = $this->field( Field_Role::Message );
		$budget  = $this->field( Field_Role::Other );
		$entry   = $this->entry( $this->field( Field_Role::Name ), $message, $this->field( Field_Role::Email ), $budget );

		$this->assertSame( array( $message, $budget ), $entry->fields_without_personal_data() );
	}

	/**
	 * An entry can hand back the fields with one role.
	 */
	public function test_entry_filters_by_role(): void {
		$first  = $this->field( Field_Role::Message );
		$second = $this->field( Field_Role::Message );
		$entry  = $this->entry( $first, $this->field( Field_Role::Email ), $second );

		$this->assertSame( array( $first, $second ), $entry->fields_with_role( Field_Role::Message ) );
		$this->assertSame( array(), $entry->fields_with_role( Field_Role::Phone ) );
	}

	/**
	 * A query with no arguments asks for the latest entries from every form.
	 */
	public function test_query_defaults(): void {
		$query = new Entry_Query();

		$this->assertNull( $query->form_id );
		$this->assertNull( $query->since );
		$this->assertNull( $query->until );
		$this->assertSame( Entry_Query::DEFAULT_LIMIT, $query->limit );
		$this->assertSame( 0, $query->offset );
	}

	/**
	 * The largest allowed limit is accepted.
	 */
	public function test_query_accepts_max_limit(): void {
		$this->assertSame( Entry_Query::MAX_LIMIT, ( new Entry_Query( limit: Entry_Query::MAX_LIMIT ) )->limit );
	}

	/**
	 * Out-of-range values are rejected when the query is built.
	 *
	 * @dataProvider invalid_queries
	 *
	 * @param array $args Constructor arguments.
	 */
	public function test_query_rejects_invalid_values( array $args ): void {
		$this->expectException( InvalidArgumentException::class );

		new Entry_Query( ...$args );
	}

	/**
	 * Invalid query arguments.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function invalid_queries(): array {
		return array(
			'limit of zero'        => array( array( 'limit' => 0 ) ),
			'limit over maximum'   => array( array( 'limit' => Entry_Query::MAX_LIMIT + 1 ) ),
			'negative offset'      => array( array( 'offset' => -1 ) ),
			'empty form ID'        => array( array( 'form_id' => '' ) ),
			'since after until'    => array(
				array(
					'since' => new DateTimeImmutable( '2026-02-01' ),
					'until' => new DateTimeImmutable( '2026-01-01' ),
				),
			),
			'since equal to until' => array(
				array(
					'since' => new DateTimeImmutable( '2026-01-01' ),
					'until' => new DateTimeImmutable( '2026-01-01' ),
				),
			),
		);
	}

	/**
	 * Make a field with a role.
	 *
	 * @param Field_Role $role Role.
	 * @return Entry_Field
	 */
	private function field( Field_Role $role ): Entry_Field {
		return new Entry_Field( ucfirst( $role->value ), 'value', $role );
	}

	/**
	 * Make an entry holding some fields.
	 *
	 * @param Entry_Field ...$fields Fields.
	 * @return Entry
	 */
	private function entry( Entry_Field ...$fields ): Entry {
		return new Entry( 'fake', '1', 'contact', new DateTimeImmutable( '2026-01-01' ), $fields );
	}
}
