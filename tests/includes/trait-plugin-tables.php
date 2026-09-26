<?php
/**
 * Real database tables for adapter tests.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

/**
 * Creates another plugin's tables for a test class, and removes them after.
 *
 * The WordPress test framework turns CREATE TABLE into temporary tables,
 * which SHOW TABLES cannot see. The adapters check their tables exist with
 * SHOW TABLES, so these tables are created as real ones, once per class,
 * before the per-test transaction starts. Rows inserted by each test are
 * still rolled back as usual.
 */
trait Plugin_Tables {

	/**
	 * Create tables from CREATE TABLE statements (use {prefix} for the table prefix).
	 *
	 * @param string[] $statements SQL.
	 */
	protected static function create_tables( array $statements ): void {
		global $wpdb;

		foreach ( $statements as $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed test schema.
			$wpdb->query( str_replace( '{prefix}', $wpdb->prefix, $sql ) );
		}
	}

	/**
	 * Drop tables (names without prefix).
	 *
	 * @param string[] $tables Table names.
	 */
	protected static function drop_tables( array $tables ): void {
		global $wpdb;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test cleanup.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
	}
}
