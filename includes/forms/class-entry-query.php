<?php
/**
 * Entry query.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Which entries to fetch from a Form_Source.
 *
 * Checked when it is created, so every adapter can trust its values.
 */
final class Entry_Query {

	/**
	 * The most entries one request may return. Keeps responses small enough
	 * for an AI to read and stops one request from loading a whole table.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * How many entries a request returns when it does not say.
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * Create a query.
	 *
	 * @param string|null            $form_id Only entries from this form. Null for all forms.
	 * @param DateTimeImmutable|null $since   Only entries submitted at or after this time.
	 * @param DateTimeImmutable|null $until   Only entries submitted before this time.
	 * @param int                    $limit   How many to return, 1 to MAX_LIMIT.
	 * @param int                    $offset  How many to skip, for paging. 0 or more.
	 *
	 * @throws InvalidArgumentException When a value is out of range.
	 */
	public function __construct(
		public readonly ?string $form_id = null,
		public readonly ?DateTimeImmutable $since = null,
		public readonly ?DateTimeImmutable $until = null,
		public readonly int $limit = self::DEFAULT_LIMIT,
		public readonly int $offset = 0,
	) {
		// Exception messages are for developers and are not shown to users, so they are not translated.
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			throw new InvalidArgumentException( 'limit must be between 1 and ' . self::MAX_LIMIT . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text developer message, never rendered as HTML.
		}

		if ( $offset < 0 ) {
			throw new InvalidArgumentException( 'offset cannot be negative.' );
		}

		if ( null !== $since && null !== $until && $since >= $until ) {
			throw new InvalidArgumentException( 'since must be earlier than until.' );
		}

		if ( '' === $form_id ) {
			throw new InvalidArgumentException( 'form_id cannot be empty. Use null for all forms.' );
		}
	}
}
