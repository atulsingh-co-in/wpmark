<?php
/**
 * Content reader.
 *
 * @package WPMark
 */

namespace WPMark\Content;

use WP_Post;
use WPMark\Abilities\Invalid_Input;
use WPMark\Output\Untrusted_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Turns posts and pages into plain facts the tools can report.
 *
 * Works for the block editor, the classic editor and Elementor: Elementor
 * keeps a plain copy of each page's text in the post content, which is
 * what this reads. Shortcodes are removed rather than run, so reading a
 * page never triggers other plugins' code.
 */
final class Content_Reader {

	/**
	 * Statuses the tools accept. "publish" is the default everywhere.
	 */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * Content types a visitor could see: posts, pages and public custom types.
	 *
	 * @return array<string, string> Slug => label.
	 */
	public static function types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $type ) {
			if ( 'attachment' !== $slug ) {
				$types[ $slug ] = $type->labels->singular_name;
			}
		}

		return $types;
	}

	/**
	 * WP_Query arguments for a type and status, respecting who is asking.
	 *
	 * Published content: everyone who can use WPMark. Private: only what
	 * the person could read in WordPress. Drafts, pending and scheduled:
	 * editors see everyone's, others only their own.
	 *
	 * @param string $type   Content type slug, or "any".
	 * @param string $status One of STATUSES.
	 * @return array WP_Query arguments.
	 * @throws Invalid_Input When the type or status is unknown.
	 */
	public static function query_args( string $type, string $status ): array {
		$types = self::types();

		if ( 'any' !== $type && ! isset( $types[ $type ] ) ) {
			throw new Invalid_Input(
				sprintf(
					/* translators: 1: requested type, 2: list of valid types. */
					__( 'There is no content type called "%1$s". Use one of: %2$s, or "any".', 'wpmark' ),
					$type,
					implode( ', ', array_keys( $types ) )
				)
			);
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new Invalid_Input(
				sprintf(
					/* translators: %s: list of valid statuses. */
					__( 'Status must be one of: %s.', 'wpmark' ),
					implode( ', ', self::STATUSES )
				)
			);
		}

		$args = array(
			'post_type'           => 'any' === $type ? array_keys( $types ) : $type,
			'post_status'         => $status,
			'perm'                => 'readable',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		if ( ! in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		return $args;
	}

	/**
	 * Whether the current person may read this post through WPMark.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function can_read( WP_Post $post ): bool {
		if ( ! isset( self::types()[ $post->post_type ] ) ) {
			return false;
		}

		if ( 'publish' === $post->post_status ) {
			return empty( $post->post_password ) || current_user_can( 'edit_post', $post->ID );
		}

		if ( 'private' === $post->post_status ) {
			return current_user_can( 'read_post', $post->ID );
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * The post's text, without HTML or shortcodes.
	 *
	 * @param WP_Post $post       Post.
	 * @param int     $max_length Longest result, in characters.
	 * @return array{text: string, truncated: bool}
	 */
	public static function text( WP_Post $post, int $max_length ): array {
		return Untrusted_Text::clean_with_flag( strip_shortcodes( $post->post_content ), $max_length );
	}

	/**
	 * Number of words in the post.
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	public static function word_count( WP_Post $post ): int {
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

		return count( preg_split( '/[\s\p{Z}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * The post's headings in order, as an outline.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{level: int, text: string}>
	 */
	public static function headings( WP_Post $post ): array {
		preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $post->post_content, $matches, PREG_SET_ORDER );

		$headings = array();

		foreach ( array_slice( $matches, 0, 100 ) as $match ) {
			$text = Untrusted_Text::clean( $match[2], 200 );

			if ( '' !== $text ) {
				$headings[] = array(
					'level' => (int) $match[1],
					'text'  => $text,
				);
			}
		}

		return $headings;
	}

	/**
	 * A short plain-text excerpt: the author's own excerpt, or the opening words.
	 *
	 * @param WP_Post $post  Post.
	 * @param int     $words How many words.
	 * @return string
	 */
	public static function excerpt( WP_Post $post, int $words = 40 ): string {
		$source = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );

		return Untrusted_Text::clean( wp_trim_words( wp_strip_all_tags( $source ), $words, '…' ), 600 );
	}

	/**
	 * Names of the post's categories and tags.
	 *
	 * @param WP_Post $post Post.
	 * @return array{categories: string[], tags: string[]}
	 */
	public static function terms( WP_Post $post ): array {
		$names = static function ( string $taxonomy ) use ( $post ): array {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				return array();
			}

			$terms = get_the_terms( $post, $taxonomy );

			return is_array( $terms ) ? array_map( static fn( $term ): string => Untrusted_Text::clean( $term->name, 100 ), $terms ) : array();
		};

		return array(
			'categories' => $names( 'category' ),
			'tags'       => $names( 'post_tag' ),
		);
	}

	/**
	 * Whole months since the post was last changed.
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	public static function months_since_update( WP_Post $post ): int {
		$updated = strtotime( $post->post_modified_gmt . ' UTC' );

		return max( 0, (int) floor( ( time() - $updated ) / ( 30 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * A date from the database as ISO 8601 in UTC, or null when unset.
	 *
	 * @param string $gmt_date MySQL date in UTC.
	 * @return string|null
	 */
	public static function iso_date( string $gmt_date ): ?string {
		if ( '' === $gmt_date || str_starts_with( $gmt_date, '0000' ) ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $gmt_date . ' UTC' ) );
	}

	/**
	 * The standard facts about a post that every content tool reports.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function summary( WP_Post $post ): array {
		return array(
			'id'           => $post->ID,
			'type'         => $post->post_type,
			'title'        => Untrusted_Text::clean( get_the_title( $post ), 300 ),
			'url'          => (string) get_permalink( $post ),
			'status'       => $post->post_status,
			'published_at' => self::iso_date( $post->post_date_gmt ),
			'updated_at'   => self::iso_date( $post->post_modified_gmt ),
			'word_count'   => self::word_count( $post ),
			'categories'   => self::terms( $post )['categories'],
		);
	}

	/**
	 * Find a post by ID or by its address on the site.
	 *
	 * @param int    $id  Post ID, or 0.
	 * @param string $url Full URL or path, or "".
	 * @return WP_Post|null
	 */
	public static function find( int $id, string $url ): ?WP_Post {
		if ( $id > 0 ) {
			return get_post( $id );
		}

		$found = url_to_postid( $url );

		return $found ? get_post( $found ) : null;
	}
}
