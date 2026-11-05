<?php
/**
 * Drafts created by AI apps.
 *
 * @package WPMark
 */

namespace WPMark\Drafts;

use WP_Error;
use WP_Post;
use WP_User;
use WPMark\Builders\Block_Writer;
use WPMark\Builders\Builder;
use WPMark\Builders\Builders;
use WPMark\Content\Content_Reader;
use WPMark\OAuth\Bearer_Auth;
use WPMark\Seo\Seo_Sources;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates drafts, and enforces the rules that make that safe.
 *
 * The rules, checked here on every write whatever the tool or settings:
 *
 * 1. Never publish: everything is saved as a draft, and a person publishes
 *    it in WordPress.
 * 2. Only update drafts WPMark created, and only while they are still
 *    drafts. Published content, and anything a person made, is never
 *    changed.
 * 3. Never delete or trash anything.
 * 4. Never upload: images come from the media library.
 * 5. WordPress's own permissions apply: creating a page needs a role that
 *    can edit pages, and so on.
 * 6. A limit on drafts per hour per person, so a runaway app cannot flood
 *    the site.
 *
 * Every draft records which app and person created it, shown under
 * WPMark → Drafts by AI and as a label in the Pages and Posts lists.
 */
final class Drafts {

	/**
	 * Post meta marking a draft WPMark created: app, user, builder, times.
	 */
	public const META = '_wpmark_draft';

	/**
	 * Default most creates and updates per person per hour.
	 */
	public const PER_HOUR = 30;

	/**
	 * Most sections in one draft.
	 */
	public const MAX_SECTIONS = 30;

	/**
	 * Create a draft.
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error Result for the AI.
	 */
	public static function create( array $input ) {
		if ( ! empty( $input['copy_of'] ) ) {
			return self::create_copy( $input );
		}

		$type = 'post' === ( $input['type'] ?? 'page' ) ? 'post' : 'page';

		if ( ! current_user_can( 'page' === $type ? 'edit_pages' : 'edit_posts' ) ) {
			return new WP_Error(
				'wpmark_cannot_create',
				'page' === $type
					? __( 'Your WordPress role cannot create pages. Create a post instead, or ask an administrator.', 'wpmark' )
					: __( 'Your WordPress role cannot create posts.', 'wpmark' )
			);
		}

		$builder = self::builder_for_new( (string) ( $input['builder'] ?? '' ) );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		if ( ( empty( $input['sections'] ) || ! is_array( $input['sections'] ) ) && ! self::has_html( $input ) ) {
			return new WP_Error( 'wpmark_invalid_input', __( 'Give the page\'s "html" (and "css") for a custom design, at least one section, or the id of a page to copy in "copy_of".', 'wpmark' ) );
		}

		return self::write( null, $builder, $type, $input );
	}

	/**
	 * Create a draft that copies a whole page or post, with new content in
	 * some of its slots ("fills"); everything else stays as it is.
	 *
	 * The copy keeps the original's builder, page template, excerpt and
	 * featured image unless the input changes them. The original is never
	 * changed.
	 *
	 * @param array $input Tool input, with copy_of: the id to copy.
	 * @return array|WP_Error Result for the AI.
	 */
	private static function create_copy( array $input ) {
		$source = get_post( (int) $input['copy_of'] );

		if ( ! $source || ! in_array( $source->post_type, array( 'page', 'post' ), true ) || ! self::can_copy( $source ) ) {
			return new WP_Error( 'wpmark_invalid_input', __( 'No page or post you can read has the id given in "copy_of".', 'wpmark' ) );
		}

		if ( ! current_user_can( 'page' === $source->post_type ? 'edit_pages' : 'edit_posts' ) ) {
			return new WP_Error( 'wpmark_cannot_create', __( 'Your WordPress role cannot create this kind of content.', 'wpmark' ) );
		}

		$input = array_merge(
			array(
				'title'   => $source->post_title,
				'excerpt' => $source->post_excerpt,
			),
			array_filter(
				array(
					'page_template'     => 'page' === $source->post_type ? (string) get_page_template_slug( $source ) : '',
					'featured_image_id' => (int) get_post_thumbnail_id( $source ),
				)
			),
			$input
		);

		return self::write( null, Builders::for_post( $source ), $source->post_type, $input, $source );
	}

	/**
	 * Update a draft WPMark created.
	 *
	 * @param array $input Tool input, with the draft's id.
	 * @return array|WP_Error Result for the AI.
	 */
	public static function update( array $input ) {
		$post = get_post( (int) ( $input['id'] ?? 0 ) );

		if ( ! $post || ! self::is_wpmark_draft( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'wpmark_invalid_input', __( 'No draft created through WPMark that you can edit has that id. WPMark only updates its own drafts, and only until they are published. Use wpmark-list-drafts to find them.', 'wpmark' ) );
		}

		if ( 'draft' !== $post->post_status ) {
			return new WP_Error( 'wpmark_not_draft', __( 'This page is no longer a draft (it was published, scheduled or sent for review), so WPMark will not change it. Create a new draft instead.', 'wpmark' ) );
		}

		return self::write( $post, Builders::for_post( $post ), $post->post_type, $input );
	}

	/**
	 * Build the content and save the draft.
	 *
	 * @param WP_Post|null $existing Draft to update, or null.
	 * @param Builder      $builder  Builder to use.
	 * @param string       $type     "page" or "post".
	 * @param array        $input    Tool input.
	 * @param WP_Post|null $source   Page or post a new draft copies whole, or null.
	 * @return array|WP_Error
	 */
	private static function write( ?WP_Post $existing, Builder $builder, string $type, array $input, ?WP_Post $source = null ) {
		$limit = self::check_rate();

		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$content = new Draft_Content();
		$writer  = $builder->writer();
		$nodes   = null;
		$design  = null;

		if ( ( self::has_html( $input ) || isset( $input['css'] ) || isset( $input['layout'] ) ) && ! Settings::custom_design_enabled() ) {
			return new WP_Error( 'wpmark_invalid_input', __( 'Custom page design (your own HTML and CSS) is an experimental feature that is switched off on this site. Build the page from the site\'s own pages and templates with "sections" or "copy_of" instead.', 'wpmark' ) );
		}

		if ( $existing && ! self::has_html( $input ) && ( isset( $input['css'] ) || isset( $input['layout'] ) ) && ! Html_Page::is_html_page( $existing ) ) {
			return new WP_Error( 'wpmark_invalid_input', __( '"css" and "layout" change custom-designed pages only. To redesign this draft, send its "html" too.', 'wpmark' ) );
		}

		if ( self::has_html( $input ) ) {
			$design = Html_Page::prepare( $input['html'], (string) ( $input['css'] ?? '' ), $content );
			$nodes  = $design['parts'] ? $writer->html( $design['parts'] ) : array();
		} elseif ( $existing && isset( $input['css'] ) ) {
			// New CSS for a custom-designed page, keeping its HTML.
			$current = (array) Html_Page::read( $existing );
			$design  = array(
				'parts' => array(
					array(
						'type'  => 'html',
						'value' => (string) ( $current['html'] ?? '' ),
					),
				),
				'css'   => Html_Page::clean_css( (string) $input['css'], $content ),
			);
		} elseif ( ! empty( $input['sections'] ) && is_array( $input['sections'] ) ) {
			$nodes = self::sections( $builder, $input['sections'], $content );
		} elseif ( $source ) {
			$nodes = self::fill_existing( $source, $builder, is_array( $input['fills'] ?? null ) ? $input['fills'] : array(), $content );
		} elseif ( $existing && ! empty( $input['fills'] ) && is_array( $input['fills'] ) ) {
			$nodes = self::fill_existing( $existing, $builder, $input['fills'], $content );
		}

		if ( is_wp_error( $nodes ) ) {
			return $nodes;
		}

		if ( array() === $nodes ) {
			return new WP_Error( 'wpmark_invalid_input', implode( ' ', array_merge( array( __( 'Nothing usable was given for the page.', 'wpmark' ) ), $content->notes() ) ) );
		}

		$post = self::post_fields( $existing, $type, $input, $content );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$designed = null !== $design || isset( $input['layout'] ) || ( $source && Html_Page::is_html_page( $source ) );

		// Custom-designed pages get their template from their layout, below.
		if ( $designed ) {
			unset( $post['page_template'] );
		}

		$id = $writer->save( $existing, $post, $nodes );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$id = (int) $id;

		if ( $designed ) {
			self::save_design( $id, $existing, $source, $builder, $type, $input, $design, $content );
		}

		if ( $source && 'elementor' === $builder->id() ) {
			// Elementor's page settings (such as a hidden title) belong to the layout.
			$settings = get_post_meta( $source->ID, '_elementor_page_settings', true );
			if ( is_array( $settings ) && $settings ) {
				update_post_meta( $id, '_elementor_page_settings', wp_slash( $settings ) );
			}
		}

		self::after_save( $id, $existing, $builder, $input, $content, $source );

		/**
		 * Fires after an AI app created or updated a draft through WPMark.
		 *
		 * @param int          $id       The draft.
		 * @param WP_Post|null $existing The draft before an update, or null when new.
		 * @param array        $input    The tool input.
		 * @param WP_Post|null $source   The page or post a new draft copies whole, or null.
		 */
		do_action( 'wpmark_draft_saved', $id, $existing, $input, $source );

		return self::result( get_post( $id ), $content->notes() );
	}

	/**
	 * Whether the input holds a custom design.
	 *
	 * @param array $input Tool input.
	 * @return bool
	 */
	private static function has_html( array $input ): bool {
		return isset( $input['html'] ) && is_string( $input['html'] ) && '' !== trim( $input['html'] );
	}

	/**
	 * Keep a custom-designed page's CSS and layout, and choose its template.
	 *
	 * @param int           $id       Saved draft.
	 * @param WP_Post|null  $existing The draft before an update, or null.
	 * @param WP_Post|null  $source   A page copied whole, or null.
	 * @param Builder       $builder  Its builder.
	 * @param string        $type     "page" or "post".
	 * @param array         $input    Tool input.
	 * @param array|null    $design   Cleaned parts and CSS, or null to keep them.
	 * @param Draft_Content $content  Notes.
	 */
	private static function save_design( int $id, ?WP_Post $existing, ?WP_Post $source, Builder $builder, string $type, array $input, ?array $design, Draft_Content $content ): void {
		$from   = $existing ? $existing : $source;
		$layout = (string) ( $input['layout'] ?? ( $from ? get_post_meta( $from->ID, Html_Page::LAYOUT_META, true ) : '' ) );
		$layout = in_array( $layout, Html_Page::LAYOUTS, true ) ? $layout : 'site';
		$css    = null !== $design ? $design['css'] : (string) ( $from ? get_post_meta( $from->ID, Html_Page::CSS_META, true ) : '' );

		update_post_meta( $id, Html_Page::CSS_META, wp_slash( $css ) );
		update_post_meta( $id, Html_Page::LAYOUT_META, $layout );

		$template = Html_Page::template( $builder, $layout, $type );
		update_post_meta( $id, '_wp_page_template', '' !== $template ? $template : 'default' );

		if ( null !== $design && $design['parts'] ) {
			Html_Page::review( $design['parts'], $css, $content );
		}
	}

	/**
	 * Native nodes for every requested section.
	 *
	 * @param Builder       $builder  Builder of the draft.
	 * @param array         $sections Section requests.
	 * @param Draft_Content $content  Content checks.
	 * @return array|WP_Error
	 */
	private static function sections( Builder $builder, array $sections, Draft_Content $content ) {
		$writer = $builder->writer();
		$nodes  = array();

		if ( count( $sections ) > self::MAX_SECTIONS ) {
			/* translators: %d: Most sections allowed. */
			return new WP_Error( 'wpmark_invalid_input', sprintf( __( 'A draft can have at most %d sections.', 'wpmark' ), self::MAX_SECTIONS ) );
		}

		foreach ( array_values( $sections ) as $number => $section ) {
			$section = is_array( $section ) ? $section : array();
			$label   = $number + 1;

			if ( isset( $section['from'] ) ) {
				$found = self::from( $builder, (array) $section['from'], $label );
				if ( is_wp_error( $found ) ) {
					return $found;
				}

				$applied = array();
				$fills   = is_array( $section['fills'] ?? null ) ? $section['fills'] : array();
				$nodes   = array_merge( $nodes, $writer->fill( $found['nodes'], $found['key'], $fills, $content, $applied ) );
				self::note_unused( $fills, $applied, $content, $label );
			} elseif ( isset( $section['compose'] ) ) {
				$nodes = array_merge( $nodes, $writer->compose( (array) $section['compose'], $content ) );
			} elseif ( isset( $section[ $builder->id() ] ) ) {
				$nodes = array_merge( $nodes, $writer->native( $section[ $builder->id() ], $content ) );
			} else {
				return new WP_Error(
					'wpmark_invalid_input',
					sprintf(
						/* translators: 1: Section number, 2: Page builder ID. */
						__( 'Section %1$d needs "from" (a section of a template or page, with "fills"), "compose" (simple items), or "%2$s" (content in the page builder\'s own format).', 'wpmark' ),
						$label,
						$builder->id()
					)
				);
			}
		}

		return $nodes;
	}

	/**
	 * Find the section a request copies.
	 *
	 * @param Builder $builder Builder of the draft.
	 * @param array   $from    id or pattern, and section.
	 * @param int     $label   Section number, for messages.
	 * @return array{nodes: array, key: string}|WP_Error
	 */
	private static function from( Builder $builder, array $from, int $label ) {
		$key      = (string) ( $from['section'] ?? '' );
		$sections = null;

		if ( ! empty( $from['pattern'] ) ) {
			if ( 'blocks' !== $builder->id() ) {
				/* translators: %d: Section number. */
				return new WP_Error( 'wpmark_invalid_input', sprintf( __( 'Section %d copies a block pattern, but this draft is built with Elementor. Use an Elementor template or page instead.', 'wpmark' ), $label ) );
			}

			$writer   = $builder->writer();
			$sections = $writer instanceof Block_Writer ? $writer->pattern_sections( (string) $from['pattern'] ) : null;
		} else {
			$source = get_post( (int) ( $from['id'] ?? 0 ) );

			if ( $source && self::can_copy( $source ) ) {
				if ( Builders::for_post( $source )->id() !== $builder->id() ) {
					return new WP_Error(
						'wpmark_invalid_input',
						sprintf(
							/* translators: 1: Section number, 2: Builder of the source, 3: Builder of the draft. */
							__( 'Section %1$d copies from a page built with %2$s, but this draft is built with %3$s. Copy from a template of the same builder, or use "compose".', 'wpmark' ),
							$label,
							Builders::for_post( $source )->label(),
							$builder->label()
						)
					);
				}

				$sections = $builder->writer()->sections( $source );
			}
		}

		if ( null === $sections || ! isset( $sections[ $key ] ) ) {
			return new WP_Error(
				'wpmark_invalid_input',
				/* translators: %d: Section number. */
				sprintf( __( 'Section %d: the template, page or section key was not found, or you cannot read it. Check it with wpmark-page-structure.', 'wpmark' ), $label )
			);
		}

		return array(
			'nodes' => $sections[ $key ],
			'key'   => $key,
		);
	}

	/**
	 * Fill slots of an existing draft, keeping its sections.
	 *
	 * @param WP_Post       $post    Draft.
	 * @param Builder       $builder Its builder.
	 * @param array         $fills   Slot key => content.
	 * @param Draft_Content $content Content checks.
	 * @return array
	 */
	private static function fill_existing( WP_Post $post, Builder $builder, array $fills, Draft_Content $content ): array {
		$writer  = $builder->writer();
		$nodes   = array();
		$applied = array();

		foreach ( $writer->sections( $post ) as $key => $section ) {
			$nodes = array_merge( $nodes, $writer->fill( $section, (string) $key, $fills, $content, $applied ) );
		}

		self::note_unused( $fills, $applied, $content, 0 );

		return $nodes;
	}

	/**
	 * Note slot keys that matched nothing.
	 *
	 * @param array         $fills   Slot key => content.
	 * @param string[]      $applied Keys used.
	 * @param Draft_Content $content Content checks.
	 * @param int           $label   Section number, or 0 for the whole draft.
	 */
	private static function note_unused( array $fills, array $applied, Draft_Content $content, int $label ): void {
		$unused = array_diff( array_map( 'strval', array_keys( $fills ) ), $applied );

		if ( ! $unused ) {
			return;
		}

		$keys = implode( ', ', array_slice( $unused, 0, 10 ) );

		$content->note(
			$label
				/* translators: 1: Section number, 2: Slot keys. */
				? sprintf( __( 'Section %1$d: no slot matched these keys, so they were not used: %2$s. Check the keys with wpmark-page-structure.', 'wpmark' ), $label, $keys )
				/* translators: %s: Slot keys. */
				: sprintf( __( 'No slot matched these keys, so they were not used: %s. Check the keys with wpmark-page-structure.', 'wpmark' ), $keys )
		);
	}

	/**
	 * The post fields to save.
	 *
	 * @param WP_Post|null  $existing Draft being updated, or null.
	 * @param string        $type     "page" or "post".
	 * @param array         $input    Tool input.
	 * @param Draft_Content $content  Content checks.
	 * @return array|WP_Error
	 */
	private static function post_fields( ?WP_Post $existing, string $type, array $input, Draft_Content $content ) {
		$post  = array( 'post_type' => $type );
		$title = $content->plain( $input['title'] ?? '', 200 );

		if ( '' !== $title ) {
			$post['post_title'] = $title;
		} elseif ( ! $existing ) {
			return new WP_Error( 'wpmark_invalid_input', __( 'Give the draft a title.', 'wpmark' ) );
		}

		if ( ! empty( $input['slug'] ) ) {
			$post['post_name'] = sanitize_title( (string) $input['slug'] );
		}

		if ( isset( $input['excerpt'] ) ) {
			$post['post_excerpt'] = $content->plain( $input['excerpt'], 500 );
		}

		if ( isset( $input['page_template'] ) && 'page' === $type ) {
			$template = (string) $input['page_template'];

			if ( '' === $template || in_array( $template, self::page_templates(), true ) ) {
				$post['page_template'] = $template;
			} else {
				/* translators: %s: Page template name. */
				$content->note( sprintf( __( 'Used the default page template: "%s" is not one of this site\'s page templates (see wpmark-design-guide).', 'wpmark' ), mb_substr( $template, 0, 100 ) ) );
			}
		}

		if ( ! $existing ) {
			$post['post_author'] = get_current_user_id();
		}

		return $post;
	}

	/**
	 * Featured image, SEO settings, the WPMark record and the rate count.
	 *
	 * @param int           $id       Saved draft.
	 * @param WP_Post|null  $existing The draft before, or null when new.
	 * @param Builder       $builder  Its builder.
	 * @param array         $input    Tool input.
	 * @param Draft_Content $content  Content checks.
	 * @param WP_Post|null  $copied   Page or post a new draft copies whole, or null.
	 */
	private static function after_save( int $id, ?WP_Post $existing, Builder $builder, array $input, Draft_Content $content, ?WP_Post $copied ): void {
		if ( ! empty( $input['featured_image_id'] ) ) {
			$image = $content->image( $input['featured_image_id'] );
			if ( $image ) {
				set_post_thumbnail( $id, $image['id'] );
			}
		}

		$seo    = is_array( $input['seo'] ?? null ) ? $input['seo'] : array();
		$source = Seo_Sources::active();

		if ( $seo && $source ) {
			$source->save_draft_meta(
				$id,
				$content->plain( $seo['title'] ?? '', 200 ),
				$content->plain( $seo['description'] ?? '', 400 ),
				$content->plain( $seo['focus_keyword'] ?? '', 100 )
			);
		} elseif ( $seo ) {
			$content->note( __( 'The SEO title and description were not saved: no supported SEO plugin (Rank Math) is active.', 'wpmark' ) );
		}

		$record = $existing ? (array) get_post_meta( $id, self::META, true ) : array();
		$app    = Bearer_Auth::app_name();

		update_post_meta(
			$id,
			self::META,
			array_merge(
				$record,
				array(
					'app'     => $record['app'] ?? ( '' !== $app ? $app : __( 'App with a connection key', 'wpmark' ) ),
					'user'    => $record['user'] ?? get_current_user_id(),
					'builder' => $builder->id(),
					'copy_of' => $record['copy_of'] ?? ( $copied ? $copied->ID : 0 ),
					'created' => $record['created'] ?? time(),
					'updated' => time(),
				)
			)
		);

		self::count_write();
	}

	/**
	 * What the AI gets back about a draft.
	 *
	 * @param WP_Post  $post  Draft.
	 * @param string[] $notes Notes about what was changed or left out.
	 * @return array
	 */
	public static function result( WP_Post $post, array $notes = array() ): array {
		$builder = Builders::for_post( $post );

		return array(
			'id'          => $post->ID,
			'type'        => $post->post_type,
			'title'       => get_the_title( $post ),
			'status'      => $post->post_status,
			'builder'     => $builder->id(),
			'edit_url'    => self::edit_url( $post ),
			'preview_url' => (string) get_preview_post_link( $post ),
			'notes'       => $notes,
			'sections'    => $builder->outline( $post ),
			'html_page'   => Html_Page::read( $post ),
			'next_step'   => __( 'The draft is saved and not published. Share the preview link so the person can check it, make any changes they ask for with wpmark-update-draft, and remind them to publish it themselves in WordPress when they are happy.', 'wpmark' ),
		);
	}

	/**
	 * Drafts created through WPMark that the current person may see.
	 *
	 * @param string $search Words to look for, or "".
	 * @param int    $limit  Most results.
	 * @return array[]
	 */
	public static function list( string $search, int $limit ): array {
		$args = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => array( 'draft', 'pending', 'future', 'publish', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Limited list of WPMark drafts.
			'meta_key'       => self::META,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$drafts = array();

		foreach ( get_posts( $args ) as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$record   = (array) get_post_meta( $post->ID, self::META, true );
			$drafts[] = array(
				'id'          => $post->ID,
				'type'        => $post->post_type,
				'title'       => Content_Reader::summary( $post )['title'],
				'status'      => $post->post_status,
				'can_update'  => 'draft' === $post->post_status,
				'builder'     => (string) ( $record['builder'] ?? Builders::for_post( $post )->id() ),
				'app'         => (string) ( $record['app'] ?? '' ),
				'updated_at'  => Content_Reader::iso_date( $post->post_modified_gmt ),
				'edit_url'    => self::edit_url( $post ),
				'preview_url' => (string) get_preview_post_link( $post ),
			);
		}

		return $drafts;
	}

	/**
	 * Whether a post was created through WPMark.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_wpmark_draft( WP_Post $post ): bool {
		return in_array( $post->post_type, array( 'page', 'post' ), true ) && is_array( get_post_meta( $post->ID, self::META, true ) );
	}

	/**
	 * Where a person edits the draft: Elementor's editor for Elementor pages.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function edit_url( WP_Post $post ): string {
		if ( 'elementor' === Builders::for_post( $post )->id() ) {
			return admin_url( 'post.php?post=' . $post->ID . '&action=elementor' );
		}

		return (string) get_edit_post_link( $post->ID, 'raw' );
	}

	/**
	 * Whether the current person may copy sections from a post.
	 *
	 * @param WP_Post $post Post, template or reusable section.
	 * @return bool
	 */
	public static function can_copy( WP_Post $post ): bool {
		if ( in_array( $post->post_type, array( 'elementor_library', 'wp_block' ), true ) ) {
			return ( 'publish' === $post->post_status && empty( $post->post_password ) ) || current_user_can( 'edit_post', $post->ID );
		}

		return Content_Reader::can_read( $post );
	}

	/**
	 * The page templates a draft may use, across the site's builders.
	 *
	 * @return string[]
	 */
	public static function page_templates(): array {
		$templates = array();

		foreach ( Builders::available() as $builder ) {
			$templates = array_merge( $templates, array_column( $builder->design()['page_templates'] ?? array(), 'template' ) );
		}

		return array_values( array_unique( $templates ) );
	}

	/**
	 * The builder for a new draft: the one asked for, or the recommended one.
	 *
	 * @param string $id Builder ID, or "".
	 * @return Builder|WP_Error
	 */
	private static function builder_for_new( string $id ) {
		if ( '' === $id ) {
			return Builders::recommended();
		}

		$builder = Builders::get( $id );

		return $builder ? $builder : new WP_Error(
			'wpmark_invalid_input',
			/* translators: %s: Page builder ID. */
			sprintf( __( 'The page builder "%s" is not active on this site. Use wpmark-design-guide to see which builders are.', 'wpmark' ), $id )
		);
	}

	/**
	 * Most writes per person per hour.
	 *
	 * @return int
	 */
	private static function per_hour(): int {
		/**
		 * Filters how many drafts one person's AI apps may create or update per hour.
		 *
		 * @param int $limit Default 30.
		 */
		return max( 1, (int) apply_filters( 'wpmark_drafts_per_hour', self::PER_HOUR ) );
	}

	/**
	 * Refuse when the person's apps wrote too often in the last hour.
	 *
	 * @return true|WP_Error
	 */
	private static function check_rate() {
		if ( count( self::recent_writes() ) >= self::per_hour() ) {
			return new WP_Error(
				'wpmark_too_many_drafts',
				/* translators: %d: Limit per hour. */
				sprintf( __( 'Your AI apps have created or changed %d drafts in the last hour, the most allowed. Please wait a while, or make several changes in one request.', 'wpmark' ), self::per_hour() )
			);
		}

		return true;
	}

	/**
	 * Count one write for the current person.
	 */
	private static function count_write(): void {
		$writes   = self::recent_writes();
		$writes[] = time();

		set_transient( self::rate_key(), $writes, HOUR_IN_SECONDS );
	}

	/**
	 * Times of the current person's writes in the last hour.
	 *
	 * @return int[]
	 */
	private static function recent_writes(): array {
		$writes = get_transient( self::rate_key() );
		$cutoff = time() - HOUR_IN_SECONDS;

		return array_values( array_filter( is_array( $writes ) ? $writes : array(), static fn( $time ): bool => (int) $time > $cutoff ) );
	}

	/**
	 * Transient key for the current person's write count.
	 *
	 * @return string
	 */
	private static function rate_key(): string {
		return 'wpmark_draft_writes_' . get_current_user_id();
	}

	/**
	 * Label WPMark drafts in the Pages and Posts lists.
	 *
	 * @param string[] $states Post states.
	 * @param WP_Post  $post   Post.
	 * @return string[]
	 */
	public static function post_states( array $states, WP_Post $post ): array {
		if ( self::is_wpmark_draft( $post ) ) {
			$record                 = (array) get_post_meta( $post->ID, self::META, true );
			$states['wpmark_draft'] = sprintf(
				/* translators: %s: App name, such as Claude. */
				__( 'Created by AI (%s)', 'wpmark' ),
				esc_html( (string) ( $record['app'] ?? '' ) )
			);
		}

		return $states;
	}

	/**
	 * Who created a draft, for the admin list.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_User|null
	 */
	public static function creator( WP_Post $post ): ?WP_User {
		$record = (array) get_post_meta( $post->ID, self::META, true );
		$user   = get_userdata( (int) ( $record['user'] ?? 0 ) );

		return $user ? $user : null;
	}
}
