<?php
/**
 * Writing drafts with the block editor.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_HTML_Tag_Processor;
use WP_Post;
use WPMark\Drafts\Draft_Content;
use WPMark\Drafts\Html_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Builds drafts out of WordPress blocks, the way the block editor saves them.
 *
 * Blocks are kept as WordPress's parser returns them (name, attributes,
 * inner blocks, HTML) and written back with serialize_blocks(). New blocks
 * use the exact markup the block editor itself saves, so the editor opens
 * the draft without "this block contains unexpected content" warnings, and
 * colours are the theme's presets, so the page follows the theme.
 */
final class Block_Writer implements Writer {

	/**
	 * Blocks a native section may contain. Custom HTML and the classic
	 * editor block are left out: they could carry scripts or styles.
	 */
	private const ALLOWED_PREFIXES = array( 'core/', 'wpforms/', 'contact-form-7/' );

	/**
	 * Core blocks that are not allowed in a native section.
	 */
	private const BLOCKED = array( 'core/html', 'core/freeform', 'core/legacy-widget', 'core/widget-group', 'core/template-part', 'core/post-content', 'core/navigation' );

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function sections( WP_Post $post ): array {
		return self::split( $post->post_content );
	}

	/**
	 * A block pattern's sections, by the pattern's name.
	 *
	 * @param string $name Pattern name.
	 * @return array<string, array>|null
	 */
	public function pattern_sections( string $name ): ?array {
		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $name );

		return $pattern ? self::split( (string) ( $pattern['content'] ?? '' ) ) : null;
	}

	/**
	 * Block markup as sections: each top-level block is one.
	 *
	 * @param string $markup Block markup.
	 * @return array<string, array>
	 */
	private static function split( string $markup ): array {
		$sections = array();

		foreach ( Block_Editor::meaningful( parse_blocks( $markup ) ) as $index => $block ) {
			$sections[ (string) $index ] = array( $block );
		}

		return $sections;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array         $nodes   Blocks of one section.
	 * @param string        $key     Section key.
	 * @param array         $fills   Slot key => new content.
	 * @param Draft_Content $content Content checks.
	 * @param string[]      $applied Slot keys filled so far.
	 */
	public function fill( array $nodes, string $key, array $fills, Draft_Content $content, array &$applied ): array {
		// A section is one top-level block, so its key is the block's path.
		return array( $this->fill_block( $nodes[0], $key, $fills, $content, $applied ) );
	}

	/**
	 * Fill one block and the blocks inside it, following the same paths as
	 * Block_Editor::outline_markup().
	 *
	 * @param array         $block   Parsed block.
	 * @param string        $path    Its slot key.
	 * @param array         $fills   Slot key => new content.
	 * @param Draft_Content $content Content checks.
	 * @param string[]      $applied Slot keys filled so far.
	 * @return array
	 */
	private function fill_block( array $block, string $path, array $fills, Draft_Content $content, array &$applied ): array {
		$name = (string) $block['blockName'];

		foreach ( array( ':background', ':media' ) as $suffix ) {
			if ( array_key_exists( $path . $suffix, $fills ) ) {
				$block     = $this->set_media( $block, $fills[ $path . $suffix ], $content );
				$applied[] = $path . $suffix;
			}
		}

		// Slots are blocks without inner blocks, plus lists, FAQs and quotes, which are filled whole.
		$is_slot = ! $block['innerBlocks'] || in_array( $name, array( 'core/list', 'core/details', 'core/quote' ), true );

		if ( $is_slot && array_key_exists( $path, $fills ) ) {
			$filled = $this->set_slot( $block, $fills[ $path ], $content );

			if ( null !== $filled ) {
				$applied[] = $path;
				return $filled;
			}
		}

		$inner = Block_Editor::meaningful( $block['innerBlocks'] );

		if ( ! $inner ) {
			return $block;
		}

		// Inner blocks are numbered without the whitespace between them, as in the outline.
		$position = 0;
		foreach ( $block['innerBlocks'] as $index => $child ) {
			if ( null === $child['blockName'] && '' === trim( (string) $child['innerHTML'] ) ) {
				continue;
			}

			$block['innerBlocks'][ $index ] = $this->fill_block( $child, $path . '.' . $position, $fills, $content, $applied );
			++$position;
		}

		return $block;
	}

	/**
	 * Put new content into one slot block.
	 *
	 * @param array         $block   Parsed block.
	 * @param mixed         $value   New content: text, or an object of fields.
	 * @param Draft_Content $content Content checks.
	 * @return array|null The changed block, or null when this block is not a slot.
	 */
	private function set_slot( array $block, $value, Draft_Content $content ): ?array {
		switch ( (string) $block['blockName'] ) {
			case 'core/heading':
				$level = (int) self::field( $value, 'level' );
				$block = $this->set_inner( $block, $content->rich( self::field( $value, 'text' ) ) );
				return $level >= 1 && $level <= 6 ? self::set_heading_level( $block, $level ) : $block;

			case 'core/paragraph':
				return $this->set_inner( $block, $content->rich( self::field( $value, 'text' ) ) );

			case 'core/button':
				$text = $content->plain( self::field( $value, 'text' ) );
				$html = '' === $text ? $block['innerHTML'] : (string) preg_replace_callback( '#(<a\b[^>]*>)(.*?)(</a>)#s', static fn( array $m ): string => $m[1] . esc_html( $text ) . $m[3], $block['innerHTML'], 1 );
				$link = $content->link( self::field( $value, 'link' ) );

				if ( '' !== $link ) {
					$tags = new WP_HTML_Tag_Processor( $html );
					if ( $tags->next_tag( 'a' ) ) {
						$tags->set_attribute( 'href', $link );
						$html = $tags->get_updated_html();
					}
				}

				return self::with_html( $block, $html );

			case 'core/image':
				$image = $content->image( self::field( $value, 'image_id' ) );
				if ( ! $image ) {
					return $block;
				}

				$block['attrs']['id'] = $image['id'];
				$tags                 = new WP_HTML_Tag_Processor( $block['innerHTML'] );
				if ( $tags->next_tag( 'img' ) ) {
					$tags->set_attribute( 'src', $image['url'] );
					$tags->set_attribute( 'alt', self::alt( $value, $image, $content ) );
					self::set_image_class( $tags, $image['id'] );
				}

				return self::with_html( $block, $tags->get_updated_html() );

			case 'core/list':
				$items = array();
				foreach ( (array) self::field( $value, 'items' ) as $item ) {
					$text = $content->rich( self::field( $item, 'text' ) );
					$link = $content->link( self::field( $item, 'link' ) );
					if ( '' !== $text ) {
						$items[] = self::list_item( '' !== $link ? '<a href="' . esc_url( $link ) . '">' . $text . '</a>' : $text );
					}
				}

				return $items ? self::with_inner_blocks( $block, $items ) : $block;

			case 'core/details':
				$item     = (array) ( ( (array) self::field( $value, 'items' ) )[0] ?? array() );
				$question = $content->plain( self::field( $item, 'question' ) );
				$answer   = $content->rich( self::field( $item, 'answer' ) );

				if ( '' !== $question ) {
					$block['innerContent'][0] = (string) preg_replace_callback( '#(<summary[^>]*>)(.*?)(</summary>)#s', static fn( array $m ): string => $m[1] . esc_html( $question ) . $m[3], (string) $block['innerContent'][0], 1 );
				}

				if ( '' !== $answer ) {
					$block = self::with_inner_blocks( $block, array( self::paragraph( $answer ) ) );
				}

				return $block;

			case 'core/quote':
				$quote = $content->rich( self::field( $value, 'quote' ) );
				$cite  = self::cite( $value, $content );
				$block = '' !== $quote ? self::with_inner_blocks( $block, array( self::paragraph( $quote ) ) ) : $block;

				if ( '' !== $cite ) {
					$last                           = count( $block['innerContent'] ) - 1;
					$tail                           = (string) preg_replace( '#<cite[^>]*>.*?</cite>#s', '', (string) $block['innerContent'][ $last ] );
					$block['innerContent'][ $last ] = (string) preg_replace( '#</blockquote>#', '<cite>' . $cite . '</cite></blockquote>', $tail, 1 );
					$block['innerHTML']             = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
				}

				return $block;

			case 'core/pullquote':
				$quote = $content->rich( self::field( $value, 'quote' ) );
				$cite  = self::cite( $value, $content );
				$html  = $block['innerHTML'];
				$html  = '' === $quote ? $html : (string) preg_replace( '#<p[^>]*>.*?</p>#s', '<p>' . $quote . '</p>', $html, 1 );
				$html  = '' === $cite ? $html : (string) preg_replace( '#<cite[^>]*>.*?</cite>#s', '<cite>' . $cite . '</cite>', $html, 1 );
				return self::with_html( $block, $html );

			case 'core/video':
				$url = $content->video( self::field( $value, 'url' ) );
				return '' === $url ? $block : self::replace_url( $block, (string) ( $block['attrs']['src'] ?? '' ), $url );

			case 'core/embed':
				$url = $content->video( self::field( $value, 'url' ) );
				if ( '' === $url ) {
					return $block;
				}
				$old                   = (string) ( $block['attrs']['url'] ?? '' );
				$block['attrs']['url'] = $url;
				return self::replace_url( $block, $old, $url );

			case 'core/shortcode':
			case 'wpforms/form-selector':
			case 'contact-form-7/contact-form-selector':
				$form = Form_Embeds::find( (string) self::field( $value, 'plugin' ), (string) self::field( $value, 'form' ) );
				if ( ! $form ) {
					$content->note( __( 'Kept the original form: the form asked for was not found. Find forms with wpmark-design-guide.', 'wpmark' ) );
					return $block;
				}
				return self::shortcode( $form['shortcode'] );
		}

		return null;
	}

	/**
	 * Change the picture of a cover or media-and-text block.
	 *
	 * @param array         $block   Parsed block.
	 * @param mixed         $value   New content with image_id.
	 * @param Draft_Content $content Content checks.
	 * @return array
	 */
	private function set_media( array $block, $value, Draft_Content $content ): array {
		$image = $content->image( self::field( $value, 'image_id' ), 'full' );

		if ( ! $image ) {
			return $block;
		}

		if ( 'core/media-text' === $block['blockName'] ) {
			$old                        = (string) ( $block['attrs']['mediaUrl'] ?? '' );
			$block['attrs']['mediaId']  = $image['id'];
			$block['attrs']['mediaUrl'] = $image['url'];
		} else {
			$old                   = (string) ( $block['attrs']['url'] ?? '' );
			$block['attrs']['id']  = $image['id'];
			$block['attrs']['url'] = $image['url'];
		}

		foreach ( $block['innerContent'] as $index => $chunk ) {
			if ( ! is_string( $chunk ) ) {
				continue;
			}

			if ( '' !== $old ) {
				$chunk = str_replace( array( $old, esc_url( $old ) ), $image['url'], $chunk );
			}

			$tags = new WP_HTML_Tag_Processor( $chunk );
			while ( $tags->next_tag( 'img' ) ) {
				$tags->set_attribute( 'src', $image['url'] );
				self::set_image_class( $tags, $image['id'] );
			}

			$block['innerContent'][ $index ] = $tags->get_updated_html();
		}

		$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The section is a group block (full width when it has a background
	 * colour), with columns when more than one column is given.
	 *
	 * @param array         $spec    items or columns, background.
	 * @param Draft_Content $content Content checks.
	 */
	public function compose( array $spec, Draft_Content $content ): array {
		$columns = isset( $spec['columns'] ) && is_array( $spec['columns'] ) ? array_slice( $spec['columns'], 0, 4 ) : array( (array) ( $spec['items'] ?? array() ) );
		$columns = array_values( array_filter( $columns, 'is_array' ) );

		$inner = array();
		if ( count( $columns ) > 1 ) {
			$column_blocks = array();
			foreach ( $columns as $items ) {
				$column_blocks[] = self::container( 'core/column', array(), '<div class="wp-block-column">', '</div>', $this->items( $items, $content ) );
			}
			$inner[] = self::container( 'core/columns', array(), '<div class="wp-block-columns">', '</div>', $column_blocks );
		} else {
			$inner = $this->items( $columns[0] ?? array(), $content );
		}

		if ( ! $inner ) {
			return array();
		}

		$attrs   = array();
		$classes = array( 'wp-block-group' );
		$color   = sanitize_key( (string) ( $spec['background'] ?? '' ) );

		if ( '' !== $color ) {
			if ( in_array( $color, self::color_slugs(), true ) ) {
				$attrs['align']           = 'full';
				$attrs['backgroundColor'] = $color;
				array_push( $classes, 'alignfull', 'has-' . $color . '-background-color', 'has-background' );
			} else {
				/* translators: %s: Colour name. */
				$content->note( sprintf( __( 'Left out the background colour "%s": it is not one of the theme\'s colours (see wpmark-design-guide).', 'wpmark' ), $color ) );
			}
		}

		$attrs['layout'] = array( 'type' => 'constrained' );

		return array( self::container( 'core/group', $attrs, '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">', '</div>', $inner ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A full-width group holding Custom HTML blocks, with a shortcode block
	 * for each form.
	 *
	 * @param array<int, array{type: string, value: string}> $parts Cleaned HTML and forms.
	 */
	public function html( array $parts ): array {
		$blocks = array_map(
			static fn( array $part ): array => 'form' === $part['type'] ? self::shortcode( $part['value'] ) : self::block( 'core/html', array(), $part['value'] ),
			$parts
		);

		return array(
			self::container(
				'core/group',
				array(
					'align'     => 'full',
					'className' => Html_Page::SCOPE,
					'layout'    => array( 'type' => 'default' ),
				),
				'<div class="wp-block-group alignfull ' . Html_Page::SCOPE . '">',
				'</div>',
				$blocks
			),
		);
	}

	/**
	 * Blocks for a list of simple items. Buttons next to each other share
	 * one row.
	 *
	 * @param array         $items   Items, each with a kind.
	 * @param Draft_Content $content Content checks.
	 * @return array
	 */
	private function items( array $items, Draft_Content $content ): array {
		$blocks  = array();
		$buttons = array();

		$flush = static function () use ( &$blocks, &$buttons ): void {
			if ( $buttons ) {
				$blocks[] = self::container( 'core/buttons', array(), '<div class="wp-block-buttons">', '</div>', $buttons );
				$buttons  = array();
			}
		};

		foreach ( array_slice( $items, 0, 40 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$kind = (string) ( $item['kind'] ?? '' );

			if ( 'button' === $kind ) {
				$text = $content->plain( $item['text'] ?? '' );
				$link = $content->link( $item['link'] ?? '' );
				if ( '' !== $text ) {
					$href      = '' !== $link ? ' href="' . esc_url( $link ) . '"' : '';
					$buttons[] = self::block( 'core/button', array(), '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . esc_html( $text ) . '</a></div>' );
				}
				continue;
			}

			$flush();
			$block = $this->item( $kind, $item, $content );

			if ( $block ) {
				$blocks[] = $block;
			}
		}

		$flush();

		return $blocks;
	}

	/**
	 * The block for one simple item.
	 *
	 * @param string        $kind    Item kind.
	 * @param array         $item    Item fields.
	 * @param Draft_Content $content Content checks.
	 * @return array|null
	 */
	private function item( string $kind, array $item, Draft_Content $content ): ?array {
		switch ( $kind ) {
			case 'heading':
				$text  = $content->rich( $item['text'] ?? '' );
				$level = max( 1, min( 6, (int) ( $item['level'] ?? 2 ) ) );
				return '' === $text ? null : self::block( 'core/heading', 2 === $level ? array() : array( 'level' => $level ), '<h' . $level . ' class="wp-block-heading">' . $text . '</h' . $level . '>' );

			case 'text':
				$text = $content->rich( $item['text'] ?? '' );
				return '' === $text ? null : self::paragraph( $text );

			case 'image':
				$image = $content->image( $item['image_id'] ?? 0 );
				if ( ! $image ) {
					return null;
				}
				return self::block(
					'core/image',
					array(
						'id'              => $image['id'],
						'sizeSlug'        => 'large',
						'linkDestination' => 'none',
					),
					'<figure class="wp-block-image size-large"><img src="' . esc_url( $image['url'] ) . '" alt="' . esc_attr( self::alt( $item, $image, $content ) ) . '" class="wp-image-' . $image['id'] . '"/></figure>'
				);

			case 'list':
				$list = array();
				foreach ( (array) ( $item['items'] ?? array() ) as $entry ) {
					$text = $content->rich( self::field( $entry, 'text' ) );
					if ( '' !== $text ) {
						$list[] = self::list_item( $text );
					}
				}
				return $list ? self::container( 'core/list', array(), '<ul class="wp-block-list">', '</ul>', $list ) : null;

			case 'faq':
				$blocks = array();
				foreach ( (array) ( $item['items'] ?? array() ) as $entry ) {
					$question = $content->plain( self::field( $entry, 'question' ) );
					$answer   = $content->rich( self::field( $entry, 'answer' ) );
					if ( '' !== $question && '' !== $answer ) {
						$blocks[] = self::container( 'core/details', array(), '<details class="wp-block-details"><summary>' . esc_html( $question ) . '</summary>', '</details>', array( self::paragraph( $answer ) ) );
					}
				}
				// Several questions become a group of details blocks.
				return $blocks ? ( 1 === count( $blocks ) ? $blocks[0] : self::container( 'core/group', array( 'layout' => array( 'type' => 'constrained' ) ), '<div class="wp-block-group">', '</div>', $blocks ) ) : null;

			case 'testimonial':
				$quote = $content->rich( $item['quote'] ?? '' );
				$cite  = self::cite( $item, $content );
				return '' === $quote ? null : self::container( 'core/quote', array(), '<blockquote class="wp-block-quote">', ( '' !== $cite ? '<cite>' . $cite . '</cite>' : '' ) . '</blockquote>', array( self::paragraph( $quote ) ) );

			case 'video':
				$url = $content->video( $item['url'] ?? '' );
				return '' === $url ? null : self::block( 'core/embed', array( 'url' => $url ), '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $url ) . "\n" . '</div></figure>' );

			case 'form':
				$form = Form_Embeds::find( (string) ( $item['plugin'] ?? '' ), (string) ( $item['form'] ?? '' ) );
				if ( ! $form ) {
					$content->note( __( 'Left out a form: it was not found. Use one of the forms listed by wpmark-design-guide.', 'wpmark' ) );
					return null;
				}
				return self::shortcode( $form['shortcode'] );
		}

		/* translators: %s: Item kind. */
		$content->note( sprintf( __( 'Left out an item of kind "%s": use heading, text, button, image, list, faq, testimonial, video or form.', 'wpmark' ), mb_substr( $kind, 0, 40 ) ) );

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Takes block markup, as the block editor's code editor shows it.
	 * Blocks that could carry scripts (custom HTML, classic) are removed;
	 * everything else goes through WordPress's own filters for post HTML
	 * and block attributes, whatever the person's role.
	 *
	 * @param mixed         $value   Block markup.
	 * @param Draft_Content $content Content checks.
	 */
	public function native( $value, Draft_Content $content ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		// The HTML through WordPress's post filter, then the blocks' own attributes.
		$markup = filter_block_content( wp_kses_post( mb_substr( $value, 0, 200000 ) ), 'post' );
		$blocks = array();

		foreach ( Block_Editor::meaningful( parse_blocks( $markup ) ) as $block ) {
			$clean = $this->clean_native( $block, $content );
			if ( null !== $clean ) {
				$blocks[] = $clean;
			}
		}

		return $blocks;
	}

	/**
	 * Remove disallowed blocks and images that are not in the media library.
	 *
	 * @param array         $block   Parsed block.
	 * @param Draft_Content $content Content checks.
	 * @return array|null
	 */
	private function clean_native( array $block, Draft_Content $content ): ?array {
		$name = (string) $block['blockName'];

		$allowed = '' !== $name && ! in_array( $name, self::BLOCKED, true ) && (bool) array_filter( self::ALLOWED_PREFIXES, static fn( string $prefix ): bool => str_starts_with( $name, $prefix ) );

		if ( ! $allowed ) {
			/* translators: %s: Block name. */
			$content->note( sprintf( __( 'Removed a "%s" block: it is not allowed in drafts.', 'wpmark' ), '' === $name ? 'classic' : $name ) );
			return null;
		}

		if ( 'core/shortcode' === $name && ! Form_Embeds::from_shortcode( (string) $block['innerHTML'] ) ) {
			$content->note( __( 'Removed a shortcode: only form shortcodes are allowed in drafts.', 'wpmark' ) );
			return null;
		}

		if ( 'core/image' === $name ) {
			$image = $content->image( $block['attrs']['id'] ?? 0 );
			if ( ! $image ) {
				$content->note( __( 'Removed an image that is not in the media library. Use wpmark-list-media to find images.', 'wpmark' ) );
				return null;
			}
			$block = $this->set_slot( $block, array( 'image_id' => $image['id'] ), $content ) ?? $block;
		}

		if ( in_array( $name, array( 'core/cover', 'core/media-text' ), true ) ) {
			$id = (int) ( $block['attrs']['id'] ?? $block['attrs']['mediaId'] ?? 0 );
			if ( $id ) {
				$block = $this->set_media( $block, array( 'image_id' => $id ), $content );
			}
		}

		$children = array();
		foreach ( $block['innerBlocks'] as $child ) {
			$clean = $this->clean_native( $child, $content );
			if ( null !== $clean ) {
				$children[] = $clean;
			}
		}

		if ( count( $children ) !== count( $block['innerBlocks'] ) ) {
			$block = self::with_inner_blocks( $block, $children );
		}

		return $block;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post|null $existing Draft to update, or null.
	 * @param array        $post     Post fields.
	 * @param array|null   $nodes    Blocks, or null to keep the content.
	 */
	public function save( ?WP_Post $existing, array $post, ?array $nodes ) {
		if ( null !== $nodes ) {
			$post['post_content'] = serialize_blocks( $nodes );
		}

		$post['post_status'] = 'draft';

		if ( $existing ) {
			$post['ID'] = $existing->ID;
			return wp_update_post( wp_slash( $post ), true );
		}

		return wp_insert_post( wp_slash( $post ), true );
	}

	// Helpers for building blocks the way the block editor saves them.

	/**
	 * A block without inner blocks.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @param string $html  Saved HTML.
	 * @return array
	 */
	private static function block( string $name, array $attrs, string $html ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * A block that holds other blocks.
	 *
	 * @param string $name   Block name.
	 * @param array  $attrs  Attributes.
	 * @param string $open   HTML before the inner blocks.
	 * @param string $close  HTML after them.
	 * @param array  $blocks Inner blocks.
	 * @return array
	 */
	private static function container( string $name, array $attrs, string $open, string $close, array $blocks ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $blocks,
			'innerHTML'    => $open . $close,
			'innerContent' => array_merge( array( $open ), array_fill( 0, count( $blocks ), null ), array( $close ) ),
		);
	}

	/**
	 * A paragraph.
	 *
	 * @param string $html Safe inline HTML.
	 * @return array
	 */
	private static function paragraph( string $html ): array {
		return self::block( 'core/paragraph', array(), '<p>' . $html . '</p>' );
	}

	/**
	 * A list item.
	 *
	 * @param string $html Safe inline HTML.
	 * @return array
	 */
	private static function list_item( string $html ): array {
		return self::block( 'core/list-item', array(), '<li>' . $html . '</li>' );
	}

	/**
	 * A shortcode block.
	 *
	 * @param string $shortcode Shortcode.
	 * @return array
	 */
	private static function shortcode( string $shortcode ): array {
		return self::block( 'core/shortcode', array(), $shortcode );
	}

	/**
	 * Replace a block's inner blocks, keeping its own HTML around them.
	 *
	 * @param array $block  Parsed block.
	 * @param array $blocks New inner blocks.
	 * @return array
	 */
	private static function with_inner_blocks( array $block, array $blocks ): array {
		$strings = array_values( array_filter( $block['innerContent'], 'is_string' ) );
		$open    = $strings[0] ?? '';
		$close   = count( $strings ) > 1 ? (string) end( $strings ) : '';

		$block['innerBlocks']  = array_values( $blocks );
		$block['innerContent'] = array_merge( array( $open ), array_fill( 0, count( $blocks ), null ), array( $close ) );
		$block['innerHTML']    = $open . $close;

		return $block;
	}

	/**
	 * Replace a block's HTML (blocks without inner blocks).
	 *
	 * @param array  $block Parsed block.
	 * @param string $html  New HTML.
	 * @return array
	 */
	private static function with_html( array $block, string $html ): array {
		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );

		return $block;
	}

	/**
	 * Replace what is inside a block's outer tag, e.g. a heading's text.
	 *
	 * @param array  $block Parsed block.
	 * @param string $html  New inner HTML; "" leaves the block as it is.
	 * @return array
	 */
	private function set_inner( array $block, string $html ): array {
		if ( '' === $html ) {
			return $block;
		}

		$new = (string) preg_replace_callback(
			'#^(\s*<([a-z0-9]+)\b[^>]*>)(.*)(</\2>\s*)$#is',
			static fn( array $m ): string => $m[1] . $html . $m[4],
			$block['innerHTML'],
			1
		);

		return self::with_html( $block, $new );
	}

	/**
	 * Change a heading block's level.
	 *
	 * @param array $block Heading block.
	 * @param int   $level 1 to 6.
	 * @return array
	 */
	private static function set_heading_level( array $block, int $level ): array {
		if ( 2 === $level ) {
			unset( $block['attrs']['level'] );
		} else {
			$block['attrs']['level'] = $level;
		}

		$html = (string) preg_replace( '#^(\s*)<h[1-6]\b#i', '$1<h' . $level, $block['innerHTML'] );
		$html = (string) preg_replace( '#</h[1-6]>(\s*)$#i', '</h' . $level . '>$1', $html );

		return self::with_html( $block, $html );
	}

	/**
	 * Swap an address inside a block's HTML.
	 *
	 * @param array  $block Parsed block.
	 * @param string $old   Old address.
	 * @param string $replacement New address.
	 * @return array
	 */
	private static function replace_url( array $block, string $old, string $replacement ): array {
		if ( '' === $old ) {
			return $block;
		}

		foreach ( $block['innerContent'] as $index => $chunk ) {
			if ( is_string( $chunk ) ) {
				$block['innerContent'][ $index ] = str_replace( array( $old, esc_url( $old ) ), esc_url( $replacement ), $chunk );
			}
		}

		if ( isset( $block['attrs']['src'] ) ) {
			$block['attrs']['src'] = $replacement;
		}

		$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/**
	 * Point an img tag's "wp-image-N" class at another image.
	 *
	 * @param WP_HTML_Tag_Processor $tags At an img tag.
	 * @param int                   $id   Attachment ID.
	 */
	private static function set_image_class( WP_HTML_Tag_Processor $tags, int $id ): void {
		foreach ( (array) $tags->class_list() as $class ) {
			if ( preg_match( '/^wp-image-\d+$/', $class ) ) {
				$tags->remove_class( $class );
			}
		}

		$tags->add_class( 'wp-image-' . $id );
	}

	/**
	 * An image's alt text: as given, else the media library's.
	 *
	 * @param mixed         $value   Slot value.
	 * @param array         $image   Image from Draft_Content::image().
	 * @param Draft_Content $content Content checks.
	 * @return string
	 */
	private static function alt( $value, array $image, Draft_Content $content ): string {
		$alt = $content->plain( self::field( $value, 'alt' ) );

		return '' !== $alt ? $alt : $image['alt'];
	}

	/**
	 * The citation for a quote: "Name, Role".
	 *
	 * @param mixed         $value   Slot value.
	 * @param Draft_Content $content Content checks.
	 * @return string Escaped.
	 */
	private static function cite( $value, Draft_Content $content ): string {
		$parts = array_filter( array( $content->plain( self::field( $value, 'name' ) ), $content->plain( self::field( $value, 'role' ) ) ) );

		return esc_html( implode( ', ', $parts ) );
	}

	/**
	 * One field of a slot value. A plain string stands for its text.
	 *
	 * @param mixed  $value Slot value.
	 * @param string $name  Field name.
	 * @return mixed
	 */
	private static function field( $value, string $name ) {
		if ( is_array( $value ) ) {
			return $value[ $name ] ?? null;
		}

		return is_scalar( $value ) && in_array( $name, array( 'text', 'quote' ), true ) ? $value : null;
	}

	/**
	 * The theme's colour slugs.
	 *
	 * @return string[]
	 */
	private static function color_slugs(): array {
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$slugs   = array();

		foreach ( (array) $palette as $presets ) {
			foreach ( (array) $presets as $preset ) {
				if ( isset( $preset['slug'] ) ) {
					$slugs[] = sanitize_key( $preset['slug'] );
				}
			}
		}

		return $slugs;
	}
}
