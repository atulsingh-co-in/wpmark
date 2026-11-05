<?php
/**
 * The WordPress block editor.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_Block_Patterns_Registry;
use WP_HTML_Tag_Processor;
use WP_Post;
use WPMark\Content\Content_Reader;
use WPMark\Drafts\Html_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Pages built with WordPress's own editor (blocks). Every site has it, so
 * it is the fallback when no other page builder is in use.
 *
 * The site's look comes from the theme's global styles (theme.json):
 * colour palette, fonts, font sizes and spacing. Blocks that use these
 * presets follow the theme automatically, including when it changes.
 */
final class Block_Editor implements Builder {

	/**
	 * Blocks that hold other blocks and have nothing to fill themselves.
	 */
	private const CONTAINERS = array( 'core/group', 'core/columns', 'core/column', 'core/buttons', 'core/row', 'core/stack', 'core/grid' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'blocks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'WordPress block editor', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function owns( WP_Post $post ): bool {
		return has_blocks( $post->post_content );
	}

	/**
	 * {@inheritDoc}
	 */
	public function design(): array {
		$settings = wp_get_global_settings();

		return array(
			'builder'         => $this->id(),
			'block_theme'     => wp_is_block_theme(),
			'colors'          => self::presets( $settings['color']['palette'] ?? array(), 'color' ),
			'fonts'           => self::presets( $settings['typography']['fontFamilies'] ?? array(), 'fontFamily' ),
			'font_sizes'      => self::presets( $settings['typography']['fontSizes'] ?? array(), 'size' ),
			'spacing'         => self::presets( $settings['spacing']['spacingSizes'] ?? array(), 'size' ),
			'content_width'   => (string) ( $settings['layout']['contentSize'] ?? '' ),
			'wide_width'      => (string) ( $settings['layout']['wideSize'] ?? '' ),
			'page_templates'  => self::page_templates(),
			'building_blocks' => array( 'heading', 'paragraph', 'buttons', 'image', 'cover', 'columns', 'group', 'list', 'quote', 'media-text', 'details (FAQ)', 'separator', 'spacer' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $search Words to look for in titles, or "".
	 * @param int    $limit  Most results per kind.
	 */
	public function templates( string $search, int $limit ): array {
		$found = array();

		foreach ( self::posts( 'wp_block', $search, $limit ) as $post ) {
			$found[] = array(
				'ref'        => array( 'id' => $post->ID ),
				'kind'       => 'reusable section',
				'title'      => Outline::text( $post->post_title ),
				'builder'    => $this->id(),
				'updated_at' => Content_Reader::iso_date( $post->post_modified_gmt ),
			);
		}

		foreach ( array_slice( self::theme_patterns( $search ), 0, $limit ) as $pattern ) {
			$found[] = array(
				'ref'        => array( 'pattern' => $pattern['name'] ),
				'kind'       => 'theme pattern',
				'title'      => Outline::text( $pattern['title'] ?? $pattern['name'] ),
				'builder'    => $this->id(),
				'categories' => array_values( (array) ( $pattern['categories'] ?? array() ) ),
			);
		}

		foreach ( self::posts( 'page', $search, $limit, true ) as $post ) {
			$found[] = array(
				'ref'        => array( 'id' => $post->ID ),
				'kind'       => 'page',
				'title'      => Outline::text( get_the_title( $post ) ),
				'url'        => (string) get_permalink( $post ),
				'builder'    => $this->id(),
				'updated_at' => Content_Reader::iso_date( $post->post_modified_gmt ),
			);
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function outline( WP_Post $post ): array {
		return $this->outline_markup( $post->post_content );
	}

	/**
	 * {@inheritDoc}
	 */
	public function writer(): Writer {
		return new Block_Writer();
	}

	/**
	 * The outline of a theme pattern, found by its name.
	 *
	 * @param string $name Pattern name, e.g. "twentytwentyfive/cta-centered".
	 * @return array|null Sections, or null when there is no such pattern.
	 */
	public function outline_pattern( string $name ): ?array {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $name );

		return $pattern ? $this->outline_markup( (string) ( $pattern['content'] ?? '' ) ) : null;
	}

	/**
	 * The outline of some block markup.
	 *
	 * Each top-level block is a section. Its key is its position on the page
	 * ("0", "1"…) and each slot's key is the path to it ("2.0.1": third
	 * section, first block inside it, second block inside that).
	 *
	 * @param string $markup Block markup.
	 * @return array
	 */
	public function outline_markup( string $markup ): array {
		if ( ! has_blocks( $markup ) ) {
			$text = Outline::text( $markup );

			// Content from the classic editor: one block of text, kept as it is.
			return '' === $text ? array() : array(
				Outline::section( '0', __( 'Classic content', 'wpmark' ), array( Outline::slot( '0', 'text', array( 'text' => $text ) ) ), array(), 1, true ),
			);
		}

		$sections = array();

		foreach ( array_slice( self::meaningful( parse_blocks( $markup ) ), 0, Outline::MAX_SECTIONS ) as $index => $block ) {
			$key   = (string) $index;
			$slots = array();
			$other = array();
			$name  = (string) ( $block['attrs']['metadata']['name'] ?? '' );

			if ( 'core/block' === $block['blockName'] ) {
				$synced     = get_post( (int) ( $block['attrs']['ref'] ?? 0 ) );
				$sections[] = Outline::section( $key, $synced ? $synced->post_title : '', array(), array( 'synced pattern' ), $index + 1, true );
				continue;
			}

			$this->walk( $block, $key, $slots, $other );
			$sections[] = Outline::section( $key, $name, $slots, $other, $index + 1 );
		}

		return $sections;
	}

	/**
	 * Collect the slots inside a block.
	 *
	 * @param array    $block Parsed block.
	 * @param string   $path  The block's key.
	 * @param array    $slots Slots found so far.
	 * @param string[] $other Kept-as-is block names found so far.
	 */
	private function walk( array $block, string $path, array &$slots, array &$other ): void {
		$name = (string) $block['blockName'];
		$html = (string) $block['innerHTML'];

		switch ( $name ) {
			case 'core/heading':
				$slots[] = Outline::slot(
					$path,
					'heading',
					array(
						'text'  => Outline::text( $html ),
						'level' => (int) ( $block['attrs']['level'] ?? 2 ),
					)
				);
				return;

			case 'core/paragraph':
				$slots[] = Outline::slot( $path, 'text', array( 'text' => Outline::text( $html ) ) );
				return;

			case 'core/button':
				$slots[] = Outline::slot(
					$path,
					'button',
					array(
						'text' => Outline::text( $html ),
						'link' => Outline::link( self::attribute( $html, 'a', 'href' ) ),
					)
				);
				return;

			case 'core/image':
				$slots[] = Outline::slot(
					$path,
					'image',
					array(
						'image_id'  => (int) ( $block['attrs']['id'] ?? 0 ),
						'image_url' => Outline::link( self::attribute( $html, 'img', 'src' ) ),
						'alt'       => Outline::text( self::attribute( $html, 'img', 'alt' ) ),
					)
				);
				return;

			case 'core/list':
				$items = array();
				foreach ( self::meaningful( $block['innerBlocks'] ) as $item ) {
					$items[] = array( 'text' => Outline::text( $item['innerHTML'] ) );
				}
				$slots[] = Outline::slot( $path, 'list', array( 'items' => $items ) );
				return;

			case 'core/quote':
			case 'core/pullquote':
				$slots[] = Outline::slot(
					$path,
					'testimonial',
					array(
						'quote' => Outline::text( self::without_cite( $html . implode( '', array_column( $block['innerBlocks'], 'innerHTML' ) ) ) ),
						'name'  => Outline::text( preg_match( '#<cite[^>]*>(.*?)</cite>#s', $html, $cite ) ? $cite[1] : '' ),
					)
				);
				return;

			case 'core/details':
				$slots[] = Outline::slot(
					$path,
					'faq',
					array(
						'items' => array(
							array(
								'question' => Outline::text( preg_match( '#<summary[^>]*>(.*?)</summary>#s', $html, $summary ) ? $summary[1] : '' ),
								'answer'   => Outline::text( implode( "\n", array_column( $block['innerBlocks'], 'innerHTML' ) ) ),
							),
						),
					)
				);
				return;

			case 'core/video':
				$slots[] = Outline::slot( $path, 'video', array( 'url' => Outline::link( self::attribute( $html, 'video', 'src' ) ) ) );
				return;

			case 'core/embed':
				$slots[] = Outline::slot( $path, 'video', array( 'url' => Outline::link( $block['attrs']['url'] ?? '' ) ) );
				return;

			case 'core/shortcode':
				$form = Form_Embeds::from_shortcode( $html );
				if ( $form ) {
					$slots[] = Outline::slot( $path, 'form', $form );
				} else {
					$other[] = 'shortcode';
				}
				return;

			case 'wpforms/form-selector':
				$slots[] = Outline::slot(
					$path,
					'form',
					array(
						'plugin' => 'wpforms',
						'form'   => (string) ( $block['attrs']['formId'] ?? '' ),
					)
				);
				return;

			case 'contact-form-7/contact-form-selector':
				$slots[] = Outline::slot(
					$path,
					'form',
					array(
						'plugin' => 'contact-form-7',
						'form'   => (string) ( $block['attrs']['id'] ?? '' ),
					)
				);
				return;

			case 'core/block':
				$other[] = 'synced pattern';
				return;
		}

		if ( 'core/cover' === $name ) {
			$slots[] = Outline::slot(
				$path . ':background',
				'background',
				array(
					'image_id'  => (int) ( $block['attrs']['id'] ?? 0 ),
					'image_url' => Outline::link( $block['attrs']['url'] ?? '' ),
				)
			);
		} elseif ( 'core/media-text' === $name ) {
			$slots[] = Outline::slot(
				$path . ':media',
				'image',
				array(
					'image_id'  => (int) ( $block['attrs']['mediaId'] ?? 0 ),
					'image_url' => Outline::link( $block['attrs']['mediaUrl'] ?? '' ),
				)
			);
		} elseif ( ! in_array( $name, self::CONTAINERS, true ) && ! $block['innerBlocks'] ) {
			$other[] = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
			return;
		}

		foreach ( self::meaningful( $block['innerBlocks'] ) as $index => $inner ) {
			$this->walk( $inner, $path . '.' . $index, $slots, $other );
		}
	}

	/**
	 * Blocks that are not just whitespace between other blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Re-indexed from 0.
	 */
	public static function meaningful( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static fn( array $block ): bool => null !== $block['blockName'] || '' !== trim( (string) $block['innerHTML'] )
			)
		);
	}

	/**
	 * Read one attribute of the first matching tag in some HTML.
	 *
	 * @param string $html      HTML.
	 * @param string $tag       Tag name.
	 * @param string $attribute Attribute name.
	 * @return string
	 */
	private static function attribute( string $html, string $tag, string $attribute ): string {
		$processor = new WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag( $tag ) ) {
			return '';
		}

		$value = $processor->get_attribute( $attribute );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * A quote's HTML without its citation.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function without_cite( string $html ): string {
		return (string) preg_replace( '#<cite[^>]*>.*?</cite>#s', '', $html );
	}

	/**
	 * Theme.json presets as name, slug and value. The theme's own come
	 * first; WordPress's defaults only when the theme has none.
	 *
	 * @param array  $by_origin Presets keyed by origin (theme, custom, default).
	 * @param string $value_key Which property holds the value.
	 * @return array<int, array{name: string, slug: string, value: string}>
	 */
	private static function presets( array $by_origin, string $value_key ): array {
		$chosen = array_merge( $by_origin['theme'] ?? array(), $by_origin['custom'] ?? array() );

		if ( ! $chosen ) {
			$chosen = $by_origin['default'] ?? array();
		}

		$presets = array();
		foreach ( $chosen as $preset ) {
			if ( ! isset( $preset['slug'] ) ) {
				continue;
			}

			$presets[] = array(
				'name'  => Outline::text( $preset['name'] ?? $preset['slug'] ),
				'slug'  => sanitize_key( $preset['slug'] ),
				'value' => is_scalar( $preset[ $value_key ] ?? null ) ? (string) $preset[ $value_key ] : '',
			);
		}

		return $presets;
	}

	/**
	 * The page templates a new page can use.
	 *
	 * @return array<int, array{template: string, name: string}>
	 */
	private static function page_templates(): array {
		$templates = array(
			array(
				'template' => '',
				'name'     => __( 'Default template', 'wpmark' ),
			),
		);

		if ( wp_is_block_theme() ) {
			foreach ( get_block_templates( array( 'post_type' => 'page' ) ) as $template ) {
				if ( ! empty( $template->is_custom ) || Html_Page::BLANK_TEMPLATE === $template->slug ) {
					$templates[] = array(
						'template' => $template->slug,
						'name'     => Outline::text( $template->title ),
					);
				}
			}

			return $templates;
		}

		foreach ( wp_get_theme()->get_page_templates( null, 'page' ) as $file => $name ) {
			$templates[] = array(
				'template' => (string) $file,
				'name'     => Outline::text( $name ),
			);
		}

		return $templates;
	}

	/**
	 * Patterns the theme (or a plugin) offers, but not WordPress's own or
	 * those downloaded from the WordPress.org pattern directory: those are
	 * generic, while the theme's match the site.
	 *
	 * @param string $search Words to look for in titles, or "".
	 * @return array[]
	 */
	private static function theme_patterns( string $search ): array {
		$patterns = array();

		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$source = (string) ( $pattern['source'] ?? '' );
			$name   = (string) ( $pattern['name'] ?? '' );

			if ( 'core' === $source || str_starts_with( $source, 'pattern-directory' ) || str_starts_with( $name, 'core/' ) ) {
				continue;
			}

			if ( isset( $pattern['inserter'] ) && false === $pattern['inserter'] ) {
				continue;
			}

			if ( '' !== $search && false === stripos( (string) ( $pattern['title'] ?? '' ) . ' ' . $name, $search ) ) {
				continue;
			}

			$patterns[] = $pattern;
		}

		return $patterns;
	}

	/**
	 * Published posts of one type, optionally only those not built with Elementor.
	 *
	 * @param string $post_type      Post type.
	 * @param string $search         Words to look for, or "".
	 * @param int    $limit          Most results.
	 * @param bool   $without_others Skip posts built with another page builder.
	 * @return WP_Post[]
	 */
	private static function posts( string $post_type, string $search, int $limit, bool $without_others = false ): array {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'has_password'   => false,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( $without_others ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, limited list.
			$args['meta_query'] = array(
				array(
					'key'     => Elementor::EDIT_MODE_META,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		return get_posts( $args );
	}
}
