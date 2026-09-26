<?php
/**
 * Contact Form 7 + Flamingo form source.
 *
 * @package WPMark
 */

namespace WPMark\Forms;

use DateTimeImmutable;
use DateTimeZone;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads messages saved by Flamingo, the free companion plugin that stores
 * Contact Form 7 submissions (Contact Form 7 on its own only emails them).
 *
 * How Flamingo stores a message:
 * - One post of type "flamingo_inbound". Status "publish", or
 *   "flamingo-spam" for spam, which is skipped.
 * - Each form is a "channel": a term in the flamingo_inbound_channel
 *   taxonomy named after the form.
 * - Each field's value is post meta "_field_<name>"; the list of field
 *   names is in "_fields"; the page it came from is in "_meta"['url'].
 *
 * The post content also holds the visitor's IP address and browser, so it
 * is never read.
 */
class Flamingo_Form_Source implements Form_Source {

	private const POST_TYPE = 'flamingo_inbound';
	private const TAXONOMY  = 'flamingo_inbound_channel';

	/**
	 * Field types per channel, from the matching Contact Form 7 form.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $types = array();

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'flamingo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Contact Form 7 (saved by Flamingo)', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return class_exists( 'Flamingo_Inbound_Message' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_entries(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_forms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		// Parent terms such as "Contact Form 7" only group the real forms.
		$parents = array_filter( wp_list_pluck( $terms, 'parent' ) );
		$forms   = array();

		foreach ( $terms as $term ) {
			if ( ! in_array( $term->term_id, $parents, true ) ) {
				$forms[] = new Form( $this->get_id(), (string) $term->term_id, $term->name );
			}
		}

		return $forms;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Entry_Query $query Which entries to return.
	 */
	public function get_entries( Entry_Query $query ): array {
		$wp_query = new WP_Query(
			array_merge(
				$this->query_args( $query ),
				array(
					'posts_per_page' => $query->limit,
					'offset'         => $query->offset,
					'no_found_rows'  => true,
				)
			)
		);

		return array_map( array( $this, 'to_entry' ), $wp_query->posts );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Entry_Query $query Which entries to count.
	 */
	public function count_entries( Entry_Query $query ): int {
		$wp_query = new WP_Query(
			array_merge(
				$this->query_args( $query ),
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			)
		);

		return (int) $wp_query->found_posts;
	}

	/**
	 * WP_Query arguments for a query, before paging.
	 *
	 * @param Entry_Query $query The query.
	 * @return array
	 */
	private function query_args( Entry_Query $query ): array {
		$args = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'publish',
			'orderby'          => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'suppress_filters' => true,
		);

		if ( null !== $query->form_id ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering by form is the point.
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => (int) $query->form_id,
				),
			);
		}

		// Inclusive bounds; "until" is exclusive, so stop one second before it.
		$dates = array();

		if ( null !== $query->since ) {
			$dates['after'] = $query->since->format( 'Y-m-d H:i:s' );
		}

		if ( null !== $query->until ) {
			$dates['before'] = $query->until->modify( '-1 second' )->format( 'Y-m-d H:i:s' );
		}

		if ( $dates ) {
			$args['date_query'] = array(
				array_merge(
					array(
						'column'    => 'post_date_gmt',
						'inclusive' => true,
					),
					$dates
				),
			);
		}

		return $args;
	}

	/**
	 * Turn a Flamingo post into an Entry.
	 *
	 * @param \WP_Post $post Flamingo message.
	 * @return Entry
	 */
	private function to_entry( \WP_Post $post ): Entry {
		$terms   = wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$form_id = is_array( $terms ) && $terms ? (int) end( $terms ) : 0;
		$types   = $form_id ? $this->field_types( $form_id ) : array();
		$names   = get_post_meta( $post->ID, '_fields', true );
		$fields  = array();

		foreach ( array_keys( is_array( $names ) ? $names : array() ) as $name ) {
			$value = get_post_meta( $post->ID, sanitize_key( '_field_' . $name ), true );
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;

			if ( '' === trim( $value ) ) {
				continue;
			}

			$label    = Field_Roles::label_from_name( (string) $name );
			$fields[] = new Entry_Field( $label, $value, Field_Roles::detect( $types[ $name ] ?? 'text', (string) $name ) );
		}

		$meta = get_post_meta( $post->ID, '_meta', true );

		return new Entry(
			$this->get_id(),
			(string) $post->ID,
			(string) $form_id,
			new DateTimeImmutable( $post->post_date_gmt, new DateTimeZone( 'UTC' ) ),
			$fields,
			is_array( $meta ) && ! empty( $meta['url'] ) ? (string) $meta['url'] : null
		);
	}

	/**
	 * Field types for a channel, read from the Contact Form 7 form that feeds it.
	 *
	 * Contact Form 7 remembers its channel in its "_flamingo" post meta, and
	 * describes fields as form tags such as [email* your-email].
	 *
	 * @param int $channel_id Channel term ID.
	 * @return array<string, string> Field name => type.
	 */
	private function field_types( int $channel_id ): array {
		if ( isset( $this->types[ $channel_id ] ) ) {
			return $this->types[ $channel_id ];
		}

		$types = array();
		$forms = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'post_status'    => 'any',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Contact Form 7 forms; sites have a handful.
				'no_found_rows'  => true,
			)
		);

		foreach ( $forms as $form ) {
			$link = get_post_meta( $form->ID, '_flamingo', true );

			if ( is_array( $link ) && (int) ( $link['channel'] ?? 0 ) === $channel_id ) {
				preg_match_all( '/\[\s*([a-z_]+)\*?\s+([A-Za-z0-9_\-]+)/', (string) get_post_meta( $form->ID, '_form', true ), $tags, PREG_SET_ORDER );

				foreach ( $tags as $tag ) {
					$types[ $tag[2] ] = $tag[1];
				}
				break;
			}
		}

		$this->types[ $channel_id ] = $types;

		return $types;
	}
}
