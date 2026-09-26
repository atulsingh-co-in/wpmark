<?php
/**
 * Tests for the Contact Form 7 + Flamingo adapter.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WPMark\Forms\Entry;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Flamingo_Form_Source;
use WPMark\Forms\Form_Source;

/**
 * Stores the contract fixture exactly as Flamingo 2.x does (checked against
 * its source), then runs the shared adapter checks.
 */
class Test_Flamingo_Form_Source extends Form_Source_Contract_Test_Case {

	/**
	 * Register Flamingo's post type, spam status and channel taxonomy.
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type( 'flamingo_inbound', array( 'public' => false ) );
		register_post_status( 'flamingo-spam', array( 'public' => false ) );
		register_taxonomy( 'flamingo_inbound_channel', 'flamingo_inbound', array( 'hierarchical' => true ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function make_source(): Form_Source {
		$data   = self::fixture( 'flamingo' );
		$parent = wp_insert_term( 'Contact Form 7', 'flamingo_inbound_channel', array( 'slug' => 'contact-form-7' ) );

		$channels = array();
		$tags     = array(
			'contact' => '[text* your-name] [email* your-email] [textarea your-message] [submit "Send"]',
			'quote'   => '[number budget]',
		);

		foreach ( $data['forms'] as $form ) {
			$term = wp_insert_term( $form->title, 'flamingo_inbound_channel', array( 'parent' => $parent['term_id'] ) );

			$channels[ $form->id ]                  = (int) $term['term_id'];
			$this->ids[ (string) $term['term_id'] ] = $form->id;

			// The Contact Form 7 form that feeds this channel.
			$cf7 = self::factory()->post->create( array( 'post_type' => 'wpcf7_contact_form' ) );
			update_post_meta( $cf7, '_form', $tags[ $form->id ] );
			update_post_meta( $cf7, '_flamingo', array( 'channel' => $channels[ $form->id ] ) );
		}

		foreach ( $data['entries'] as $entry ) {
			$this->ids[ (string) $this->insert( $entry, $channels[ $entry->form_id ], 'publish' ) ] = $entry->id;
		}

		// Spam must never be reported.
		$this->insert( $data['entries'][0], $channels['contact'], 'flamingo-spam' );

		return new class() extends Flamingo_Form_Source {
			/**
			 * Pretend Flamingo is active.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};
	}

	/**
	 * The contact form's parent channel ("Contact Form 7") is not listed as a form.
	 */
	public function test_parent_channel_is_not_a_form(): void {
		$titles = wp_list_pluck( $this->make_source()->list_forms(), 'title' );

		$this->assertNotContains( 'Contact Form 7', $titles );
	}

	/**
	 * Spam messages are skipped.
	 */
	public function test_skips_spam(): void {
		$this->assertSame( 6, $this->make_source()->count_entries( new Entry_Query() ) );
	}

	/**
	 * Field labels come from Contact Form 7 field names, and the page is kept.
	 */
	public function test_labels_and_page(): void {
		$source = $this->make_source();
		$entry  = $source->get_entries( new Entry_Query( limit: 1 ) )[0];
		$labels = wp_list_pluck( $entry->fields, 'label' );

		$this->assertSame( array( 'Name', 'Email', 'Message' ), $labels );
		$this->assertSame( 'https://example.org/contact/', $entry->page_url );
	}

	/**
	 * Store one entry the way Flamingo does.
	 *
	 * @param Entry  $entry   Fixture entry.
	 * @param int    $channel Channel term ID.
	 * @param string $status  Post status.
	 * @return int Post ID.
	 */
	private function insert( Entry $entry, int $channel, string $status ): int {
		$names = array(
			'Name'    => 'your-name',
			'Email'   => 'your-email',
			'Message' => 'your-message',
			'Budget'  => 'budget',
		);

		$local = $entry->submitted_at->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		$id    = self::factory()->post->create(
			array(
				'post_type'     => 'flamingo_inbound',
				'post_status'   => $status,
				'post_title'    => 'Message',
				'post_content'  => '192.0.2.1 Mozilla/5.0',
				'post_date'     => $local,
				'post_date_gmt' => $entry->submitted_at->format( 'Y-m-d H:i:s' ),
			)
		);

		$fields = array();

		foreach ( $entry->fields as $field ) {
			$fields[ $names[ $field->label ] ] = null;
			update_post_meta( $id, '_field_' . $names[ $field->label ], $field->value );
		}

		update_post_meta( $id, '_fields', $fields );
		update_post_meta(
			$id,
			'_meta',
			array(
				'url'        => (string) $entry->page_url,
				'remote_ip'  => '192.0.2.1',
				'user_agent' => 'Mozilla/5.0',
			)
		);
		wp_set_object_terms( $id, $channel, 'flamingo_inbound_channel' );

		return $id;
	}
}
