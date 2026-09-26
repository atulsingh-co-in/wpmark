<?php
/**
 * Tests for the WPForms adapter.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WPMark\Forms\Entry_Query;
use WPMark\Forms\Form_Source;
use WPMark\Forms\WPForms_Form_Source;

/**
 * Stores the contract fixture in a copy of WPForms Pro's entries table,
 * then runs the shared adapter checks.
 *
 * WPForms Pro is a paid plugin and cannot be installed in CI, so this copy
 * of its table is what the adapter is tested against. Check against a real
 * WPForms Pro site before relying on it.
 */
class Test_WPForms_Form_Source extends Form_Source_Contract_Test_Case {

	use Plugin_Tables;

	/**
	 * Create WPForms Pro's entries table.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::create_tables(
			array(
				'CREATE TABLE IF NOT EXISTS {prefix}wpforms_entries (
					entry_id bigint(20) NOT NULL AUTO_INCREMENT,
					form_id bigint(20) NOT NULL,
					post_id bigint(20) NOT NULL,
					user_id bigint(20) NOT NULL DEFAULT 0,
					status varchar(30) NOT NULL DEFAULT "",
					type varchar(30) NOT NULL DEFAULT "",
					viewed tinyint(1) DEFAULT 0,
					starred tinyint(1) DEFAULT 0,
					fields longtext NOT NULL,
					meta longtext NOT NULL,
					date datetime NOT NULL,
					date_modified datetime NOT NULL,
					ip_address varchar(128) NOT NULL DEFAULT "",
					user_agent varchar(256) NOT NULL DEFAULT "",
					user_uuid varchar(36) NOT NULL DEFAULT "",
					PRIMARY KEY (entry_id)
				)',
			)
		);
	}

	/**
	 * Remove it again.
	 */
	public static function tear_down_after_class(): void {
		self::drop_tables( array( 'wpforms_entries' ) );

		parent::tear_down_after_class();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function make_source(): Form_Source {
		global $wpdb;

		$data  = self::fixture( 'wpforms' );
		$forms = array();

		foreach ( $data['forms'] as $form ) {
			$id                        = self::factory()->post->create(
				array(
					'post_type'  => 'wpforms',
					'post_title' => $form->title,
				)
			);
			$forms[ $form->id ]        = $id;
			$this->ids[ (string) $id ] = $form->id;
		}

		$types = array(
			'Name'    => 'name',
			'Email'   => 'email',
			'Message' => 'textarea',
			'Budget'  => 'number',
		);

		$rows   = $data['entries'];
		$rows[] = $data['entries'][0]; // Inserted again below as spam.

		foreach ( $rows as $index => $entry ) {
			$fields = array();

			foreach ( $entry->fields as $n => $field ) {
				$fields[ $n + 1 ] = array(
					'name'  => $field->label,
					'value' => $field->value,
					'id'    => $n + 1,
					'type'  => $types[ $field->label ],
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			$wpdb->insert(
				$wpdb->prefix . 'wpforms_entries',
				array(
					'form_id'       => $forms[ $entry->form_id ],
					'post_id'       => 0,
					'status'        => count( $data['entries'] ) === $index ? 'spam' : '',
					'fields'        => wp_json_encode( $fields ),
					'meta'          => '',
					'date'          => $entry->submitted_at->format( 'Y-m-d H:i:s' ),
					'date_modified' => $entry->submitted_at->format( 'Y-m-d H:i:s' ),
					'ip_address'    => '192.0.2.1',
				)
			);

			$this->ids[ (string) $wpdb->insert_id ] = $entry->id;
		}

		return new class() extends WPForms_Form_Source {
			/**
			 * Pretend WPForms is active.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Pretend it is a paid edition.
			 *
			 * @return bool
			 */
			protected function is_pro(): bool {
				return true;
			}
		};
	}

	/**
	 * Spam entries are skipped.
	 */
	public function test_skips_spam(): void {
		$this->assertSame( 6, $this->make_source()->count_entries( new Entry_Query() ) );
	}

	/**
	 * WPForms Lite keeps no entries, so the adapter says so.
	 */
	public function test_lite_has_no_entries(): void {
		$lite = new class() extends WPForms_Form_Source {
			/**
			 * Pretend it is WPForms Lite.
			 *
			 * @return bool
			 */
			protected function is_pro(): bool {
				return false;
			}
		};

		$this->assertFalse( $lite->supports_entries() );
	}
}
