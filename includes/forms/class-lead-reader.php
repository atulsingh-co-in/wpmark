<?php
/**
 * Lead reader.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

use DateTimeImmutable;
use DateTimeZone;
use WPMark\Abilities\Invalid_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Reads leads across every active form plugin as one newest-first list.
 *
 * A site can run several form plugins at once. The tools should not have
 * to care, so this merges them.
 */
final class Lead_Reader {

	/**
	 * Furthest into the list a request may page, to keep requests quick.
	 */
	public const MAX_DEPTH = 500;

	/**
	 * Form sources that save leads, optionally just one.
	 *
	 * @param string $source_id Source ID, or "" for all.
	 * @return Form_Source[]
	 * @throws Invalid_Input When the source is unknown or keeps no leads.
	 */
	public static function sources( string $source_id = '' ): array {
		$sources = array_filter(
			Form_Sources::available(),
			static fn( Form_Source $source ): bool => $source->supports_entries()
		);

		if ( '' === $source_id ) {
			return array_values( $sources );
		}

		if ( ! isset( $sources[ $source_id ] ) ) {
			throw new Invalid_Input(
				sprintf(
					/* translators: 1: requested source, 2: list of sources. */
					__( 'No form plugin "%1$s" with saved leads was found. Available: %2$s.', 'wpmark' ),
					$source_id,
					$sources ? implode( ', ', array_keys( $sources ) ) : __( 'none', 'wpmark' )
				)
			);
		}

		return array( $sources[ $source_id ] );
	}

	/**
	 * Form plugins that are active but keep no copy of submissions.
	 *
	 * @return array<int, array{source: string, name: string, how_to_fix: string}>
	 */
	public static function sources_without_saved_leads(): array {
		$missing = array();

		foreach ( Form_Sources::available() as $source ) {
			if ( ! $source->supports_entries() ) {
				$missing[] = array(
					'source'     => $source->get_id(),
					'name'       => $source->get_label(),
					'how_to_fix' => self::how_to_fix( $source->get_id() ),
				);
			}
		}

		return $missing;
	}

	/**
	 * A page of leads, newest first, across the given sources.
	 *
	 * @param Form_Source[] $sources Sources to read.
	 * @param Entry_Query   $query   Filters and paging.
	 * @return array{entries: Entry[], total: int}
	 * @throws Invalid_Input When paging too deep.
	 */
	public static function read( array $sources, Entry_Query $query ): array {
		if ( $query->offset + $query->limit > self::MAX_DEPTH ) {
			throw new Invalid_Input(
				sprintf(
					/* translators: %d: maximum number of leads. */
					__( 'Only the most recent %d leads can be paged through. Narrow the date range with since and until instead.', 'wpmark' ),
					self::MAX_DEPTH
				)
			);
		}

		$total = 0;

		foreach ( $sources as $source ) {
			$total += $source->count_entries( $query );
		}

		if ( 1 === count( $sources ) ) {
			return array(
				'entries' => $sources[0]->get_entries( $query ),
				'total'   => $total,
			);
		}

		// Several sources: take the newest (offset + limit) from each, merge, then page.
		$merged = array();

		foreach ( $sources as $source ) {
			$merged = array_merge( $merged, self::newest( $source, $query, $query->offset + $query->limit ) );
		}

		usort( $merged, static fn( Entry $a, Entry $b ): int => $b->submitted_at <=> $a->submitted_at );

		return array(
			'entries' => array_slice( $merged, $query->offset, $query->limit ),
			'total'   => $total,
		);
	}

	/**
	 * The newest N entries from one source, fetched in batches.
	 *
	 * @param Form_Source $source Source.
	 * @param Entry_Query $query  Filters (its paging is ignored).
	 * @param int         $count  How many.
	 * @return Entry[]
	 */
	public static function newest( Form_Source $source, Entry_Query $query, int $count ): array {
		$entries = array();

		for ( $offset = 0; $offset < $count; $offset += Entry_Query::MAX_LIMIT ) {
			$batch = $source->get_entries(
				new Entry_Query(
					$query->form_id,
					$query->since,
					$query->until,
					min( Entry_Query::MAX_LIMIT, $count - $offset ),
					$offset
				)
			);

			$entries = array_merge( $entries, $batch );

			if ( count( $batch ) < Entry_Query::MAX_LIMIT ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Form titles by source and form ID, for labelling leads.
	 *
	 * @param Form_Source[] $sources Sources.
	 * @return array<string, string> "source|form_id" => title.
	 */
	public static function form_titles( array $sources ): array {
		$titles = array();

		foreach ( $sources as $source ) {
			foreach ( $source->list_forms() as $form ) {
				$titles[ $source->get_id() . '|' . $form->id ] = $form->title;
			}
		}

		return $titles;
	}

	/**
	 * Turn a "YYYY-MM-DD" date in the site's timezone into a UTC time.
	 *
	 * @param string $date    Date as typed.
	 * @param bool   $end_of  True for the end of that day (exclusive), false for its start.
	 * @param string $name    Input name, for the error message.
	 * @return DateTimeImmutable|null Null when $date is empty.
	 * @throws Invalid_Input When the date is not valid.
	 */
	public static function parse_date( string $date, bool $end_of, string $name ): ?DateTimeImmutable {
		$date = trim( $date );

		if ( '' === $date ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new Invalid_Input(
				sprintf(
					/* translators: %s: input name. */
					__( '"%s" must be a date written as YYYY-MM-DD, for example 2026-01-31.', 'wpmark' ),
					$name
				)
			);
		}

		if ( $end_of ) {
			$parsed = $parsed->modify( '+1 day' );
		}

		return $parsed->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * What to do when a form plugin keeps no copy of submissions.
	 *
	 * @param string $source_id Source ID.
	 * @return string
	 */
	private static function how_to_fix( string $source_id ): string {
		$fixes = array(
			'wpforms'   => __( 'WPForms Lite only emails submissions. A paid WPForms licence saves them on the site so WPMark can read them.', 'wpmark' ),
			'elementor' => __( 'Turn on "Collect Submissions" in Elementor → Settings → Features, or check that Elementor Pro is up to date.', 'wpmark' ),
		);

		return $fixes[ $source_id ] ?? __( 'This form plugin does not keep a copy of submissions WPMark can read.', 'wpmark' );
	}
}
