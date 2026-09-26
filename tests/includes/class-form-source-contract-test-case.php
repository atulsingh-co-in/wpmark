<?php
/**
 * Shared checks every form source must pass.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;
use WPMark\Forms\Entry;
use WPMark\Forms\Entry_Field;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Field_Role;
use WPMark\Forms\Form;
use WPMark\Forms\Form_Source;

/**
 * The rules from the Form_Source interface, written as tests.
 *
 * To test a new adapter, extend this class and implement make_source() to
 * set up the form plugin with the forms and entries described there. Every
 * adapter then gets the same checks, so they all behave the same way for
 * the tools.
 *
 * Real plugins choose their own IDs. An adapter's test records which real
 * ID belongs to each fixture name ("contact", "c1"...) in $ids, and the
 * checks translate them back.
 */
abstract class Form_Source_Contract_Test_Case extends WP_UnitTestCase {

	/**
	 * Real ID => fixture name, for forms and entries. Empty means they match.
	 *
	 * @var array<string, string>
	 */
	protected array $ids = array();

	/**
	 * Build the source under test, holding exactly this data:
	 *
	 * - Form "contact" titled "Contact us" and form "quote" titled "Get a quote".
	 * - Five entries on "contact", one per day from 2026-01-01 to 2026-01-05
	 *   at 09:00 UTC, IDs "c1" to "c5", each with a Name, Email and Message field.
	 * - One entry on "quote" on 2026-01-03 at 12:00 UTC, ID "q1".
	 *
	 * @return Form_Source
	 */
	abstract protected function make_source(): Form_Source;

	/**
	 * The test data described in make_source(), as records. Adapters for
	 * real plugins can use it to know what to insert.
	 *
	 * @param string $source Source ID to stamp on each record.
	 * @return array{forms: Form[], entries: Entry[]}
	 */
	public static function fixture( string $source ): array {
		$utc     = new DateTimeZone( 'UTC' );
		$entries = array();

		for ( $day = 1; $day <= 5; $day++ ) {
			$entries[] = new Entry(
				$source,
				'c' . $day,
				'contact',
				new DateTimeImmutable( "2026-01-0{$day} 09:00:00", $utc ),
				array(
					new Entry_Field( 'Name', "Visitor {$day}", Field_Role::Name ),
					new Entry_Field( 'Email', "visitor{$day}@example.com", Field_Role::Email ),
					new Entry_Field( 'Message', "Question number {$day}", Field_Role::Message ),
				),
				'https://example.org/contact/'
			);
		}

		$entries[] = new Entry(
			$source,
			'q1',
			'quote',
			new DateTimeImmutable( '2026-01-03 12:00:00', $utc ),
			array( new Entry_Field( 'Budget', '5000', Field_Role::Other ) )
		);

		return array(
			'forms'   => array(
				new Form( $source, 'contact', 'Contact us' ),
				new Form( $source, 'quote', 'Get a quote' ),
			),
			'entries' => $entries,
		);
	}

	/**
	 * The ID follows the documented format.
	 */
	public function test_id_is_lowercase_slug(): void {
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $this->make_source()->get_id() );
	}

	/**
	 * Every form is listed, stamped with this source's ID.
	 */
	public function test_lists_all_forms(): void {
		$source = $this->make_source();
		$forms  = $source->list_forms();

		$names = $this->ids( $forms );
		sort( $names );

		$this->assertSame( array( 'contact', 'quote' ), $names );

		foreach ( $forms as $form ) {
			$this->assertSame( $source->get_id(), $form->source );
		}
	}

	/**
	 * Entries come back newest first.
	 */
	public function test_entries_are_newest_first(): void {
		$entries = $this->make_source()->get_entries( new Entry_Query() );

		$this->assertSame( array( 'c5', 'c4', 'q1', 'c3', 'c2', 'c1' ), $this->ids( $entries ) );
	}

	/**
	 * Limit and offset page through entries without overlap.
	 */
	public function test_limit_and_offset_page_through_entries(): void {
		$source = $this->make_source();

		$page_one = $source->get_entries( new Entry_Query( limit: 2 ) );
		$page_two = $source->get_entries( new Entry_Query( limit: 2, offset: 2 ) );

		$this->assertSame( array( 'c5', 'c4' ), $this->ids( $page_one ) );
		$this->assertSame( array( 'q1', 'c3' ), $this->ids( $page_two ) );
	}

	/**
	 * Entries can be narrowed to one form.
	 */
	public function test_filters_by_form(): void {
		$entries = $this->make_source()->get_entries( new Entry_Query( form_id: $this->real_id( 'quote' ) ) );

		$this->assertSame( array( 'q1' ), $this->ids( $entries ) );
	}

	/**
	 * Entries can be narrowed to a date range: since is inclusive, until is exclusive.
	 */
	public function test_filters_by_date_range(): void {
		$utc   = new DateTimeZone( 'UTC' );
		$query = new Entry_Query(
			since: new DateTimeImmutable( '2026-01-02 09:00:00', $utc ),
			until: new DateTimeImmutable( '2026-01-04 09:00:00', $utc )
		);

		$this->assertSame( array( 'q1', 'c3', 'c2' ), $this->ids( $this->make_source()->get_entries( $query ) ) );
	}

	/**
	 * Counting ignores limit and offset but respects filters.
	 */
	public function test_count_ignores_paging(): void {
		$source = $this->make_source();

		$this->assertSame( 6, $source->count_entries( new Entry_Query( limit: 1, offset: 3 ) ) );
		$this->assertSame( 5, $source->count_entries( new Entry_Query( form_id: $this->real_id( 'contact' ) ) ) );
	}

	/**
	 * Entries carry the form ID, a UTC time and roles on every field.
	 */
	public function test_entries_are_complete(): void {
		$entries = $this->make_source()->get_entries( new Entry_Query( form_id: $this->real_id( 'contact' ), limit: 1 ) );
		$entry   = $entries[0];

		$this->assertSame( 'contact', $this->ids[ $entry->form_id ] ?? $entry->form_id );
		$this->assertSame( '2026-01-05 09:00:00', $entry->submitted_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'UTC', $entry->submitted_at->getTimezone()->getName() );
		$this->assertCount( 1, $entry->fields_with_role( Field_Role::Message ) );
		$this->assertSame( 'Question number 5', $entry->fields_with_role( Field_Role::Message )[0]->value );
		$this->assertSame( 'Visitor 5', $entry->fields_with_role( Field_Role::Name )[0]->value );
		$this->assertSame( 'visitor5@example.com', $entry->fields_with_role( Field_Role::Email )[0]->value );
	}

	/**
	 * The real ID for a fixture name.
	 *
	 * @param string $name Fixture name, e.g. "contact".
	 * @return string
	 */
	protected function real_id( string $name ): string {
		$real = array_search( $name, $this->ids, true );

		return false === $real ? $name : (string) $real;
	}

	/**
	 * IDs of a list of forms or entries, in order.
	 *
	 * @param array<Form|Entry> $items Records.
	 * @return string[]
	 */
	private function ids( array $items ): array {
		return array_map( fn( $item ): string => $this->ids[ $item->id ] ?? $item->id, $items );
	}
}
