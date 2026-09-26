<?php
/**
 * In-memory form source for tests.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WPMark\Forms\Entry;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Form;
use WPMark\Forms\Form_Source;

/**
 * A form plugin that exists only in memory, so tests can control exactly
 * which forms and entries there are without installing a real one.
 */
final class Fake_Form_Source implements Form_Source {

	/**
	 * Create a fake source.
	 *
	 * @param string  $id               Source ID.
	 * @param bool    $available        What is_available() returns.
	 * @param bool    $supports_entries What supports_entries() returns.
	 * @param Form[]  $forms            Forms to list.
	 * @param Entry[] $entries          Entries to serve, in any order.
	 */
	public function __construct(
		private string $id = 'fake',
		private bool $available = true,
		private bool $supports_entries = true,
		private array $forms = array(),
		private array $entries = array(),
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Fake Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_entries(): bool {
		return $this->supports_entries;
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_forms(): array {
		return $this->forms;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Entry_Query $query Which entries to return.
	 */
	public function get_entries( Entry_Query $query ): array {
		return array_slice( $this->matching( $query ), $query->offset, $query->limit );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Entry_Query $query Which entries to count.
	 */
	public function count_entries( Entry_Query $query ): int {
		return count( $this->matching( $query ) );
	}

	/**
	 * Entries matching the query's filters, newest first, before paging.
	 *
	 * @param Entry_Query $query The query.
	 * @return Entry[]
	 */
	private function matching( Entry_Query $query ): array {
		$entries = array_filter(
			$this->entries,
			static fn( Entry $entry ): bool =>
				( null === $query->form_id || $entry->form_id === $query->form_id )
				&& ( null === $query->since || $entry->submitted_at >= $query->since )
				&& ( null === $query->until || $entry->submitted_at < $query->until )
		);

		usort( $entries, static fn( Entry $a, Entry $b ): int => $b->submitted_at <=> $a->submitted_at );

		return $entries;
	}
}
