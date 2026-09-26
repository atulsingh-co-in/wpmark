<?php
/**
 * WPForms form source.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WPForms entries.
 *
 * Only WPForms Pro (any paid licence) saves entries on the site, in the
 * {prefix}wpforms_entries table. WPForms Lite emails submissions and keeps
 * no local copy, so on Lite the forms are listed but supports_entries() is
 * false and the tools say so plainly.
 *
 * Each entry row holds the answers as JSON in its "fields" column:
 * { "1": { "name": "Email", "value": "...", "id": 1, "type": "email" }, ... }
 * Spam, trashed, partial and abandoned entries are skipped. The IP address
 * and browser columns are never read.
 */
class WPForms_Form_Source implements Form_Source {

	use Database_Tables;

	/**
	 * Columns WPMark needs. If a WPForms update renames any, reading stops safely.
	 */
	private const COLUMNS = array( 'entry_id', 'form_id', 'post_id', 'status', 'fields', 'date' );

	/**
	 * Entry statuses that are not real leads.
	 */
	private const SKIPPED_STATUSES = array( 'spam', 'trash', 'partial', 'abandoned' );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'WPForms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'WPFORMS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_entries(): bool {
		return $this->is_pro() && $this->table_has_columns( $this->table(), self::COLUMNS );
	}

	/**
	 * Whether a paid WPForms edition is active. Only paid editions save entries.
	 *
	 * @return bool
	 */
	protected function is_pro(): bool {
		return function_exists( 'wpforms' ) && is_object( wpforms() ) && method_exists( wpforms(), 'is_pro' ) && wpforms()->is_pro();
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_forms(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Forms, not posts; sites have a handful.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_map(
			fn( \WP_Post $post ): Form => new Form( $this->get_id(), (string) $post->ID, $post->post_title ),
			$posts
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Entry_Query $query Which entries to return.
	 */
	public function get_entries( Entry_Query $query ): array {
		global $wpdb;

		[ $where, $params ] = $this->where( $query );
		$params[]           = $query->limit;
		$params[]           = $query->offset;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE is built only from placeholders by where(); WPForms' own table; table name is our prefix plus a fixed name; values are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, form_id, post_id, fields, date FROM {$this->table()} WHERE {$where} ORDER BY date DESC, entry_id DESC LIMIT %d OFFSET %d",
				$params
			)
		);
		// phpcs:enable

		return array_map( array( $this, 'to_entry' ), (array) $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Entry_Query $query Which entries to count.
	 */
	public function count_entries( Entry_Query $query ): int {
		global $wpdb;

		[ $where, $params ] = $this->where( $query );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE is built only from placeholders by where(); As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}", $params )
		);
		// phpcs:enable
	}

	/**
	 * The entries table.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpforms_entries';
	}

	/**
	 * WHERE clause and its values for a query.
	 *
	 * @param Entry_Query $query The query.
	 * @return array{0: string, 1: array}
	 */
	private function where( Entry_Query $query ): array {
		$where  = array( 'status NOT IN (' . implode( ',', array_fill( 0, count( self::SKIPPED_STATUSES ), '%s' ) ) . ')' );
		$params = self::SKIPPED_STATUSES;

		if ( null !== $query->form_id ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $query->form_id;
		}

		// WPForms saves entry dates in UTC.
		if ( null !== $query->since ) {
			$where[]  = 'date >= %s';
			$params[] = $query->since->format( 'Y-m-d H:i:s' );
		}

		if ( null !== $query->until ) {
			$where[]  = 'date < %s';
			$params[] = $query->until->format( 'Y-m-d H:i:s' );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Turn a database row into an Entry.
	 *
	 * @param object $row Row with entry_id, form_id, post_id, fields, date.
	 * @return Entry
	 */
	private function to_entry( object $row ): Entry {
		$answers = json_decode( (string) $row->fields, true );
		$fields  = array();

		foreach ( is_array( $answers ) ? $answers : array() as $answer ) {
			$value = is_array( $answer['value'] ?? null ) ? implode( ', ', array_map( 'strval', $answer['value'] ) ) : (string) ( $answer['value'] ?? '' );

			if ( '' === trim( $value ) ) {
				continue;
			}

			$label    = (string) ( $answer['name'] ?? '' );
			$fields[] = new Entry_Field( $label, $value, Field_Roles::detect( (string) ( $answer['type'] ?? 'text' ), $label ) );
		}

		$page = (int) $row->post_id ? get_permalink( (int) $row->post_id ) : false;

		return new Entry(
			$this->get_id(),
			(string) $row->entry_id,
			(string) $row->form_id,
			new DateTimeImmutable( (string) $row->date, new DateTimeZone( 'UTC' ) ),
			$fields,
			$page ? $page : null
		);
	}
}
