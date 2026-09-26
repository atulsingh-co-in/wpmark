<?php
/**
 * Database table checks for form sources.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a form source confirm a plugin's tables look as expected before
 * reading them.
 *
 * Plugins change their tables between versions. Checking first means a
 * change turns into a clear "WPMark cannot read this version" message on
 * the health check, instead of a database error in the middle of a chat.
 */
trait Database_Tables {

	/**
	 * Column lists already checked, per table.
	 *
	 * @var array<string, string[]>
	 */
	private array $columns = array();

	/**
	 * Whether a table exists and has every listed column.
	 *
	 * @param string   $table    Full table name.
	 * @param string[] $required Column names.
	 * @return bool
	 */
	protected function table_has_columns( string $table, array $required ): bool {
		global $wpdb;

		if ( ! isset( $this->columns[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
			$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check; table name is our prefix plus a fixed name.
			$this->columns[ $table ] = $exists ? (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) : array();
		}

		return ! array_diff( $required, $this->columns[ $table ] );
	}
}
