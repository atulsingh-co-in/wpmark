<?php
/**
 * Elementor Forms form source.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Elementor Pro form submissions.
 *
 * The Form widget is part of Elementor Pro, which saves submissions (when
 * "Collect Submissions" is on, the default) in two tables:
 * - {prefix}e_submissions: one row per submission, with the page it was
 *   sent from (post_id, referer), the widget's element_id and form_name.
 * - {prefix}e_submissions_values: one row per answer (key, value).
 *
 * An Elementor form has no ID of its own: it is a widget on a page. So a
 * form here is "<page ID>:<element ID>", and field labels and types are
 * read from the widget's settings on that page. The IP address and browser
 * columns are never read. Trashed submissions are skipped.
 */
class Elementor_Form_Source implements Form_Source {

	use Database_Tables;

	/**
	 * Columns WPMark needs in each table.
	 */
	private const SUBMISSION_COLUMNS = array( 'id', 'post_id', 'element_id', 'form_name', 'referer', 'status', 'created_at_gmt' );
	private const VALUE_COLUMNS      = array( 'submission_id', 'key', 'value' );

	/**
	 * Field labels and types per form, read from Elementor's page data.
	 *
	 * @var array<string, array<string, array{label: string, type: string}>>
	 */
	private array $field_settings = array();

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Elementor Forms', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_entries(): bool {
		return $this->table_has_columns( $this->table(), self::SUBMISSION_COLUMNS )
			&& $this->table_has_columns( $this->table() . '_values', self::VALUE_COLUMNS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Lists forms that have received at least one submission. Finding forms
	 * that never received one would mean reading every page's layout.
	 */
	public function list_forms(): array {
		global $wpdb;

		if ( ! $this->supports_entries() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Elementor's own table; fixed name.
		$rows = $wpdb->get_results( "SELECT post_id, element_id, MAX(form_name) AS form_name FROM {$this->table()} WHERE status <> 'trash' GROUP BY post_id, element_id ORDER BY form_name" );

		return array_map(
			fn( object $row ): Form => new Form(
				$this->get_id(),
				(int) $row->post_id . ':' . $row->element_id,
				'' !== (string) $row->form_name ? (string) $row->form_name : __( 'Untitled form', 'wpmark' )
			),
			(array) $rows
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE is built only from placeholders by where(); Elementor's own table; fixed name; values are placeholders.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, element_id, referer, created_at_gmt FROM {$this->table()} WHERE {$where} ORDER BY created_at_gmt DESC, id DESC LIMIT %d OFFSET %d",
				$params
			)
		);
		// phpcs:enable

		if ( ! $rows ) {
			return array();
		}

		$values = $this->values( array_map( static fn( object $row ): int => (int) $row->id, $rows ) );

		return array_map(
			fn( object $row ): Entry => $this->to_entry( $row, $values[ (int) $row->id ] ?? array() ),
			$rows
		);
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
	 * The submissions table.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'e_submissions';
	}

	/**
	 * WHERE clause and its values for a query.
	 *
	 * @param Entry_Query $query The query.
	 * @return array{0: string, 1: array}
	 */
	private function where( Entry_Query $query ): array {
		$where  = array( 'status <> %s' );
		$params = array( 'trash' );

		if ( null !== $query->form_id ) {
			$parts    = explode( ':', $query->form_id, 2 );
			$where[]  = 'post_id = %d AND element_id = %s';
			$params[] = (int) $parts[0];
			$params[] = $parts[1] ?? '';
		}

		if ( null !== $query->since ) {
			$where[]  = 'created_at_gmt >= %s';
			$params[] = $query->since->format( 'Y-m-d H:i:s' );
		}

		if ( null !== $query->until ) {
			$where[]  = 'created_at_gmt < %s';
			$params[] = $query->until->format( 'Y-m-d H:i:s' );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Answers for several submissions in one query.
	 *
	 * @param int[] $ids Submission IDs.
	 * @return array<int, array<int, array{key: string, value: string}>>
	 */
	private function values( array $ids ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE is built only from placeholders by where(); As above.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT submission_id, `key`, value FROM {$this->table()}_values WHERE submission_id IN ({$placeholders}) ORDER BY id",
				$ids
			)
		);
		// phpcs:enable

		$values = array();

		foreach ( $rows as $row ) {
			$values[ (int) $row->submission_id ][] = array(
				'key'   => (string) $row->key,
				'value' => (string) $row->value,
			);
		}

		return $values;
	}

	/**
	 * Turn a submission row and its answers into an Entry.
	 *
	 * @param object $row    Submission row.
	 * @param array  $values Answers.
	 * @return Entry
	 */
	private function to_entry( object $row, array $values ): Entry {
		$form_id  = (int) $row->post_id . ':' . $row->element_id;
		$settings = $this->field_settings( (int) $row->post_id, (string) $row->element_id );
		$fields   = array();

		foreach ( $values as $answer ) {
			if ( '' === trim( $answer['value'] ) ) {
				continue;
			}

			$field    = $settings[ $answer['key'] ] ?? null;
			$label    = $field && '' !== $field['label'] ? $field['label'] : Field_Roles::label_from_name( $answer['key'] );
			$fields[] = new Entry_Field( $label, $answer['value'], Field_Roles::detect( $field['type'] ?? 'text', $label . ' ' . $answer['key'] ) );
		}

		return new Entry(
			$this->get_id(),
			(string) $row->id,
			$form_id,
			new DateTimeImmutable( (string) $row->created_at_gmt, new DateTimeZone( 'UTC' ) ),
			$fields,
			'' !== (string) $row->referer ? (string) $row->referer : null
		);
	}

	/**
	 * Field labels and types for a form widget, from the page's Elementor data.
	 *
	 * @param int    $post_id    Page the form is on.
	 * @param string $element_id The form widget's ID.
	 * @return array<string, array{label: string, type: string}> Field ID => settings.
	 */
	private function field_settings( int $post_id, string $element_id ): array {
		$key = $post_id . ':' . $element_id;

		if ( isset( $this->field_settings[ $key ] ) ) {
			return $this->field_settings[ $key ];
		}

		$data   = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		$widget = is_array( $data ) ? $this->find_element( $data, $element_id ) : null;
		$fields = array();

		foreach ( (array) ( $widget['settings']['form_fields'] ?? array() ) as $field ) {
			$id = (string) ( $field['custom_id'] ?? '' );

			if ( '' !== $id ) {
				$fields[ $id ] = array(
					'label' => (string) ( $field['field_label'] ?? '' ),
					'type'  => (string) ( $field['field_type'] ?? 'text' ),
				);
			}
		}

		$this->field_settings[ $key ] = $fields;

		return $fields;
	}

	/**
	 * Find an element by ID anywhere in Elementor's nested page data.
	 *
	 * @param array  $elements Elements.
	 * @param string $id       Element ID.
	 * @return array|null
	 */
	private function find_element( array $elements, string $id ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ( $element['id'] ?? '' ) === $id ) {
				return $element;
			}

			$found = $this->find_element( (array) ( $element['elements'] ?? array() ), $id );

			if ( $found ) {
				return $found;
			}
		}

		return null;
	}
}
