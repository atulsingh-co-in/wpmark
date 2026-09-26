<?php
/**
 * Tests for the Elementor Forms adapter.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WPMark\Forms\Elementor_Form_Source;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Form_Source;

/**
 * Stores the contract fixture in copies of Elementor Pro's submissions
 * tables and page data, then runs the shared adapter checks.
 *
 * Elementor Pro is a paid plugin and cannot be installed in CI, so these
 * copies are what the adapter is tested against. Check against a real
 * Elementor Pro site before relying on it.
 */
class Test_Elementor_Form_Source extends Form_Source_Contract_Test_Case {

	use Plugin_Tables;

	/**
	 * Create Elementor Pro's submission tables.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::create_tables(
			array(
				'CREATE TABLE IF NOT EXISTS {prefix}e_submissions (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					type varchar(60),
					hash_id varchar(60) NOT NULL DEFAULT "",
					main_meta_id bigint(20) unsigned NOT NULL DEFAULT 0,
					post_id bigint(20) unsigned NOT NULL,
					referer varchar(500) NOT NULL DEFAULT "",
					referer_title varchar(300),
					element_id varchar(20) NOT NULL,
					form_name varchar(60) NOT NULL,
					campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
					user_id bigint(20) unsigned,
					user_ip varchar(46) NOT NULL DEFAULT "",
					user_agent text,
					actions_count int(11) DEFAULT 0,
					actions_succeeded_count int(11) DEFAULT 0,
					status varchar(20) NOT NULL DEFAULT "new",
					is_read tinyint(1) NOT NULL DEFAULT 0,
					meta text,
					created_at_gmt datetime NOT NULL,
					updated_at_gmt datetime NOT NULL,
					created_at datetime NOT NULL,
					updated_at datetime NOT NULL,
					PRIMARY KEY (id)
				)',
				'CREATE TABLE IF NOT EXISTS {prefix}e_submissions_values (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
					`key` varchar(60),
					value longtext,
					PRIMARY KEY (id)
				)',
			)
		);
	}

	/**
	 * Remove them again.
	 */
	public static function tear_down_after_class(): void {
		self::drop_tables( array( 'e_submissions', 'e_submissions_values' ) );

		parent::tear_down_after_class();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function make_source(): Form_Source {
		global $wpdb;

		$data    = self::fixture( 'elementor' );
		$widgets = array(
			'contact' => array(
				'element' => 'a1b2c3d',
				'fields'  => array(
					array( 'name', 'Name', 'text' ),
					array( 'email', 'Email', 'email' ),
					array( 'message', 'Message', 'textarea' ),
				),
			),
			'quote'   => array(
				'element' => 'e4f5a6b',
				'fields'  => array( array( 'budget', 'Budget', 'number' ) ),
			),
		);

		$pages = array();

		foreach ( $data['forms'] as $form ) {
			$widget = $widgets[ $form->id ];
			$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );

			// A form widget nested in a section and column, as Elementor saves it.
			$layout = array(
				array(
					'id'       => 'sec' . $form->id,
					'elements' => array(
						array(
							'id'       => 'col' . $form->id,
							'elements' => array(
								array(
									'id'         => $widget['element'],
									'widgetType' => 'form',
									'settings'   => array(
										'form_name'   => $form->title,
										'form_fields' => array_map(
											static fn( array $f ): array => array(
												'custom_id'   => $f[0],
												'field_label' => $f[1],
												'field_type'  => $f[2],
											),
											$widget['fields']
										),
									),
								),
							),
						),
					),
				),
			);
			update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $layout ) ) );

			$pages[ $form->id ]                            = $page;
			$this->ids[ $page . ':' . $widget['element'] ] = $form->id;
		}

		$keys = array(
			'Name'    => 'name',
			'Email'   => 'email',
			'Message' => 'message',
			'Budget'  => 'budget',
		);

		$rows   = $data['entries'];
		$rows[] = $data['entries'][0]; // Inserted again below as trashed.

		foreach ( $rows as $index => $entry ) {
			$time = $entry->submitted_at->format( 'Y-m-d H:i:s' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			$wpdb->insert(
				$wpdb->prefix . 'e_submissions',
				array(
					'type'           => 'submission',
					'post_id'        => $pages[ $entry->form_id ],
					'referer'        => (string) $entry->page_url,
					'element_id'     => $widgets[ $entry->form_id ]['element'],
					'form_name'      => 'contact' === $entry->form_id ? 'Contact us' : 'Get a quote',
					'user_ip'        => '192.0.2.1',
					'status'         => count( $data['entries'] ) === $index ? 'trash' : 'new',
					'created_at_gmt' => $time,
					'updated_at_gmt' => $time,
					'created_at'     => $time,
					'updated_at'     => $time,
				)
			);

			$submission = $wpdb->insert_id;

			foreach ( $entry->fields as $field ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
				$wpdb->insert(
					$wpdb->prefix . 'e_submissions_values',
					array(
						'submission_id' => $submission,
						'key'           => $keys[ $field->label ],
						'value'         => $field->value,
					)
				);
			}

			$this->ids[ (string) $submission ] = $entry->id;
		}

		return new class() extends Elementor_Form_Source {
			/**
			 * Pretend Elementor Pro is active.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};
	}

	/**
	 * Trashed submissions are skipped.
	 */
	public function test_skips_trash(): void {
		$this->assertSame( 6, $this->make_source()->count_entries( new Entry_Query() ) );
	}

	/**
	 * Labels come from the form widget's settings on the page.
	 */
	public function test_labels_from_widget(): void {
		$entry = $this->make_source()->get_entries( new Entry_Query( limit: 1 ) )[0];

		$this->assertSame( array( 'Name', 'Email', 'Message' ), wp_list_pluck( $entry->fields, 'label' ) );
		$this->assertSame( 'https://example.org/contact/', $entry->page_url );
	}
}
