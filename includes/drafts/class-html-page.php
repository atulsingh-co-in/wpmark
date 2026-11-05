<?php
/**
 * Custom-designed pages: the AI's own HTML and CSS, made safe.
 *
 * @package WPMark
 */

namespace WPMark\Drafts;

use WP_HTML_Tag_Processor;
use WP_Post;
use WPMark\Builders\Builder;
use WPMark\Builders\Builders;
use WPMark\Builders\Elementor;
use WPMark\Builders\Form_Embeds;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an AI app design a page freely, in HTML and CSS, the way a web
 * designer would, while keeping the site safe and its look intact.
 *
 * Building pages from a builder's ready-made pieces keeps them simple, but
 * a designed landing page needs layout and styling those pieces cannot
 * give. Here the AI sends the page's HTML and CSS, and WPMark:
 *
 * - Cleans the HTML: no scripts, code, forms or outside images. Images come
 *   from the media library, videos from YouTube or Vimeo, and forms are the
 *   site's own (Contact Form 7, WPForms, Gravity Forms shortcodes).
 * - Keeps the CSS inside the page: every rule is limited to the page's own
 *   wrapper (.wpmark-page), so it can never restyle the site's header,
 *   footer or menus. Outside fonts, imports and files are removed.
 * - Gives the CSS the site's colours and fonts as variables
 *   (var(--wpmark-color-primary)), linked to the theme or Elementor kit, so
 *   the page changes with the brand.
 * - Places the page in the site: full width with the site's header and
 *   footer ("site"), or a blank landing page without them ("blank").
 * - Returns design notes: missing mobile rules, no main heading, images
 *   without descriptions.
 *
 * The page is saved in the builder's own format: a block group holding
 * Custom HTML blocks, or an Elementor container holding HTML widgets, with
 * form blocks or widgets between them. The CSS is kept with the page and
 * printed in its head.
 */
final class Html_Page {

	/**
	 * Post meta: the page's cleaned CSS.
	 */
	public const CSS_META = '_wpmark_css';

	/**
	 * Post meta: "site" or "blank".
	 */
	public const LAYOUT_META = '_wpmark_layout';

	/**
	 * The class on the page's wrapper, which every CSS rule is limited to.
	 */
	public const SCOPE = 'wpmark-page';

	/**
	 * Where the page sits: with the site's header and footer, or without.
	 */
	public const LAYOUTS = array( 'site', 'blank' );

	/**
	 * The blank page template WPMark adds (block themes use the name
	 * without ".php").
	 */
	public const BLANK_TEMPLATE = 'wpmark-blank';

	/**
	 * Most characters of HTML and of CSS.
	 */
	public const MAX_HTML = 200000;
	public const MAX_CSS  = 60000;

	/**
	 * Form shortcodes that may be placed in the page.
	 */
	private const FORM_SHORTCODES = '/\[(?:contact-form-7|wpforms|gravityform)\b[^\]]*\]/i';

	/**
	 * Video players that may be embedded.
	 */
	private const VIDEO_HOSTS = '#^https://(?:www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)#i';

	/**
	 * Add the hooks: the page's CSS, and the blank page template.
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print_css' ), 20 );
		add_action( 'init', array( self::class, 'register_block_template' ) );
		add_filter( 'theme_page_templates', array( self::class, 'classic_templates' ) );
		add_filter( 'template_include', array( self::class, 'classic_template_file' ) );
	}

	/**
	 * Clean the AI's HTML and CSS and split the page around its forms.
	 *
	 * @param string        $html    The page's HTML (the body only).
	 * @param string        $css     The page's CSS.
	 * @param Draft_Content $content Notes.
	 * @return array{parts: array<int, array{type: string, value: string}>, css: string}
	 */
	public static function prepare( string $html, string $css, Draft_Content $content ): array {
		if ( strlen( $html ) > self::MAX_HTML ) {
			/* translators: %d: Most characters. */
			$content->note( sprintf( __( 'The HTML was cut to %d characters. Keep pages shorter, or split long content into separate pages.', 'wpmark' ), self::MAX_HTML ) );
			$html = substr( $html, 0, self::MAX_HTML );
		}

		// CSS put in <style> tags inside the HTML joins the page's CSS.
		$html = (string) preg_replace_callback(
			'#<style\b[^>]*>(.*?)</style\s*>#is',
			static function ( array $found ) use ( &$css ): string {
				$css .= "\n" . $found[1];
				return '';
			},
			$html
		);

		// Code, and anything that would run or embed other pages, goes with its contents.
		$removed = 0;
		$html    = (string) preg_replace( '#<(script|noscript|template|object|embed|applet)\b[^>]*>.*?</\1\s*>#is', '', $html, -1, $removed );
		if ( $removed ) {
			$content->note( __( 'Removed scripts and embedded code: pages built through WPMark cannot run code.', 'wpmark' ) );
		}

		$parts  = array();
		$offset = 0;

		preg_match_all( self::FORM_SHORTCODES, $html, $matches, PREG_OFFSET_CAPTURE );

		foreach ( $matches[0] as $match ) {
			$parts[] = array(
				'type'  => 'html',
				'value' => substr( $html, $offset, $match[1] - $offset ),
			);
			$parts[] = array(
				'type'  => 'form',
				'value' => $match[0],
			);
			$offset  = $match[1] + strlen( $match[0] );
		}

		$parts[] = array(
			'type'  => 'html',
			'value' => substr( $html, $offset ),
		);

		$clean = array();
		foreach ( $parts as $part ) {
			$value = 'form' === $part['type'] ? self::form( $part['value'], $content ) : self::clean_html( $part['value'], $content );

			if ( '' !== trim( $value ) ) {
				$clean[] = array(
					'type'  => $part['type'],
					'value' => $value,
				);
			}
		}

		return array(
			'parts' => $clean,
			'css'   => self::clean_css( $css, $content ),
		);
	}

	/**
	 * The site's own form, written the way its plugin expects.
	 *
	 * @param string        $shortcode The form shortcode from the AI.
	 * @param Draft_Content $content   Notes.
	 * @return string The shortcode, or "" when it is not one of the site's forms.
	 */
	private static function form( string $shortcode, Draft_Content $content ): string {
		$found = Form_Embeds::from_shortcode( $shortcode );
		$form  = $found ? Form_Embeds::find( $found['plugin'], $found['form'] ) : null;

		if ( ! $form ) {
			/* translators: %s: Shortcode. */
			$content->note( sprintf( __( 'Left out the form %s: it is not one of this site\'s forms (see "forms" in wpmark-design-guide).', 'wpmark' ), mb_substr( $shortcode, 0, 80 ) ) );
			return '';
		}

		return $form['shortcode'];
	}

	/**
	 * Clean a piece of HTML.
	 *
	 * @param string        $html    HTML.
	 * @param Draft_Content $content Notes.
	 * @return string
	 */
	public static function clean_html( string $html, Draft_Content $content ): string {
		$html = wp_kses( $html, self::allowed_tags(), array( 'http', 'https', 'mailto', 'tel' ) );

		// Images: only from the media library.
		$html = (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $found ) use ( $content ): string {
				return self::clean_image( $found[0], $content );
			},
			$html
		);

		// Embedded players: only YouTube and Vimeo.
		$html = (string) preg_replace_callback(
			'#<iframe\b[^>]*>(?:\s*</iframe\s*>)?#i',
			static function ( array $found ) use ( $content ): string {
				$tags = new WP_HTML_Tag_Processor( $found[0] );
				$tags->next_tag();
				$src = (string) $tags->get_attribute( 'src' );

				if ( ! preg_match( self::VIDEO_HOSTS, $src ) ) {
					$content->note( __( 'Left out an embedded frame: only YouTube and Vimeo videos can be embedded.', 'wpmark' ) );
					return '';
				}

				$tags->set_attribute( 'loading', 'lazy' );

				return $tags->get_updated_html() . ( str_contains( strtolower( $found[0] ), '</iframe' ) ? '' : '</iframe>' );
			},
			$html
		);

		// Other shortcodes never run: they show as written.
		return str_replace( '[', '&#91;', $html );
	}

	/**
	 * Keep an image only if it is in the media library, marked so WordPress
	 * adds its responsive sizes.
	 *
	 * @param string        $tag     The img tag.
	 * @param Draft_Content $content Notes.
	 * @return string
	 */
	private static function clean_image( string $tag, Draft_Content $content ): string {
		$tags = new WP_HTML_Tag_Processor( $tag );
		$tags->next_tag();

		$src = (string) $tags->get_attribute( 'src' );
		$id  = self::attachment_id( $src );

		if ( ! $id || ! current_user_can( 'read_post', $id ) ) {
			/* translators: %s: Image address. */
			$content->note( sprintf( __( 'Left out the image %s: images must already be in the media library (wpmark-list-media). Use a CSS colour or gradient as a placeholder instead.', 'wpmark' ), mb_substr( $src, 0, 100 ) ) );
			return '';
		}

		if ( null === $tags->get_attribute( 'alt' ) ) {
			$tags->set_attribute( 'alt', (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		}

		$tags->remove_attribute( 'srcset' );
		$tags->remove_attribute( 'sizes' );
		$tags->add_class( 'wp-image-' . $id );

		if ( null === $tags->get_attribute( 'loading' ) ) {
			$tags->set_attribute( 'loading', 'lazy' );
		}

		return $tags->get_updated_html();
	}

	/**
	 * The media library image an address points to, including its smaller sizes.
	 *
	 * @param string $src Image address.
	 * @return int Attachment ID, or 0.
	 */
	private static function attachment_id( string $src ): int {
		$base = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );

		if ( '' === $src || '' === $base || ! str_starts_with( preg_replace( '#^https?:#', '', $src ), preg_replace( '#^https?:#', '', $base ) ) ) {
			return 0;
		}

		$id = attachment_url_to_postid( $src );

		if ( ! $id ) {
			// A smaller size, such as photo-300x200.jpg.
			$id = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $src ) );
		}

		return $id && wp_attachment_is_image( $id ) ? (int) $id : 0;
	}

	/**
	 * The HTML tags and attributes a page may use: WordPress's own list for
	 * post content, plus page structure, simple icons (SVG) and video
	 * frames, without form fields (forms come from the site's form plugin).
	 *
	 * @return array
	 */
	public static function allowed_tags(): array {
		$tags   = wp_kses_allowed_html( 'post' );
		$global = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'lang'             => true,
			'dir'              => true,
			'aria-label'       => true,
			'aria-hidden'      => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'data-*'           => true,
		);

		foreach ( array( 'form', 'input', 'select', 'option', 'textarea', 'optgroup' ) as $field ) {
			unset( $tags[ $field ] );
		}

		foreach ( array( 'section', 'header', 'footer', 'nav', 'main', 'article', 'aside', 'figure', 'figcaption', 'details', 'summary', 'time', 'mark', 'small' ) as $tag ) {
			$tags[ $tag ] = array_merge( $tags[ $tag ] ?? array(), $global );
		}

		$svg = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'opacity'         => true,
			'transform'       => true,
		);

		$tags['svg']      = array_merge(
			$global,
			$svg,
			array(
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'preserveaspectratio' => true,
				'focusable'           => true,
			)
		);
		$tags['g']        = array_merge( $global, $svg );
		$tags['path']     = array_merge( $global, $svg, array( 'd' => true ) );
		$tags['circle']   = array_merge( $global, $svg, array_fill_keys( array( 'cx', 'cy', 'r' ), true ) );
		$tags['ellipse']  = array_merge( $global, $svg, array_fill_keys( array( 'cx', 'cy', 'rx', 'ry' ), true ) );
		$tags['rect']     = array_merge( $global, $svg, array_fill_keys( array( 'x', 'y', 'width', 'height', 'rx', 'ry' ), true ) );
		$tags['line']     = array_merge( $global, $svg, array_fill_keys( array( 'x1', 'y1', 'x2', 'y2' ), true ) );
		$tags['polyline'] = array_merge( $global, $svg, array( 'points' => true ) );
		$tags['polygon']  = array_merge( $global, $svg, array( 'points' => true ) );

		$tags['iframe'] = array_merge(
			$global,
			array_fill_keys( array( 'src', 'width', 'height', 'allow', 'allowfullscreen', 'loading', 'frameborder', 'referrerpolicy' ), true )
		);

		foreach ( $tags as $tag => $attributes ) {
			$tags[ $tag ] = array_merge( $global, is_array( $attributes ) ? $attributes : array() );
		}

		return $tags;
	}

	/**
	 * Clean the AI's CSS and limit every rule to the page.
	 *
	 * @param string        $css     CSS.
	 * @param Draft_Content $content Notes.
	 * @return string
	 */
	public static function clean_css( string $css, Draft_Content $content ): string {
		if ( strlen( $css ) > self::MAX_CSS ) {
			/* translators: %d: Most characters. */
			$content->note( sprintf( __( 'The CSS was cut to %d characters.', 'wpmark' ), self::MAX_CSS ) );
			$css = substr( $css, 0, self::MAX_CSS );
		}

		// No comments, no "<" (so it can never close its style tag), nothing that runs code in old browsers.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = str_replace( '<', '', $css );
		$css = (string) preg_replace( '/(expression\s*\(|javascript:|behavior\s*:|-moz-binding)/i', '', $css );

		return trim( self::scope_rules( $css, $content, 0 ) );
	}

	/**
	 * Limit a list of CSS rules to the page, inside @media and @supports too.
	 *
	 * @param string        $css     CSS rules.
	 * @param Draft_Content $content Notes.
	 * @param int           $depth   How deep inside @media rules.
	 * @return string
	 */
	private static function scope_rules( string $css, Draft_Content $content, int $depth ): string {
		$out    = '';
		$length = strlen( $css );
		$start  = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( ';' === $char ) {
				// A statement such as @import or @charset: never kept.
				$statement = trim( substr( $css, $start, $i - $start ) );
				if ( str_starts_with( $statement, '@import' ) ) {
					$content->note( __( 'Removed @import: pages use the site\'s own fonts and files. Use the font variables from wpmark-design-guide.', 'wpmark' ) );
				}
				$start = $i + 1;
				continue;
			}

			if ( '{' !== $char ) {
				continue;
			}

			$prelude = trim( substr( $css, $start, $i - $start ) );
			$end     = self::closing_brace( $css, $i );
			$body    = substr( $css, $i + 1, $end - $i - 1 );
			$i       = $end;
			$start   = $end + 1;
			$lower   = strtolower( $prelude );

			if ( '' === $prelude ) {
				continue;
			}

			if ( preg_match( '/^@(media|supports|container|layer)\b/', $lower ) ) {
				if ( $depth < 3 ) {
					$out .= $prelude . '{' . self::scope_rules( $body, $content, $depth + 1 ) . '}';
				}
			} elseif ( preg_match( '/^@(-webkit-)?keyframes\b/', $lower ) ) {
				$out .= $prelude . '{' . self::keyframes( $body, $content ) . '}';
			} elseif ( str_starts_with( $lower, '@font-face' ) ) {
				$content->note( __( 'Removed @font-face: pages use the site\'s own fonts. Use the font variables from wpmark-design-guide.', 'wpmark' ) );
			} elseif ( ! str_starts_with( $lower, '@' ) ) {
				$declarations = self::declarations( $body, $content );
				if ( '' !== $declarations ) {
					$out .= self::scope_selectors( $prelude ) . '{' . $declarations . '}';
				}
			}
		}

		return $out;
	}

	/**
	 * Where the block that opens at a position closes.
	 *
	 * @param string $css  CSS.
	 * @param int    $open Position of "{".
	 * @return int Position of the matching "}", or the end.
	 */
	private static function closing_brace( string $css, int $open ): int {
		$depth  = 0;
		$length = strlen( $css );

		for ( $i = $open; $i < $length; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] && 0 === --$depth ) {
				return $i;
			}
		}

		return $length;
	}

	/**
	 * Put the page's class in front of each selector. Selectors for the whole
	 * document (html, body, :root) become the page itself.
	 *
	 * @param string $selectors Comma-separated selectors.
	 * @return string
	 */
	private static function scope_selectors( string $selectors ): string {
		$scope  = '.' . self::SCOPE;
		$scoped = array();

		foreach ( preg_split( '/,(?![^(]*\))/', $selectors ) as $selector ) {
			$selector = trim( (string) $selector );

			if ( '' === $selector ) {
				continue;
			}

			$selector = (string) preg_replace( '/^(?::root|html|body)(?=$|[\s.#:\[>+~])\s*(?:(?::root|html|body)(?=$|[\s.#:\[>+~])\s*)*/i', $scope . ' ', $selector );
			$selector = trim( $selector );

			$scoped[] = str_starts_with( $selector, $scope ) ? $selector : $scope . ' ' . $selector;
		}

		return implode( ',', array_unique( $scoped ) );
	}

	/**
	 * Keep a rule's declarations, without outside files.
	 *
	 * @param string        $body    Declarations.
	 * @param Draft_Content $content Notes.
	 * @return string
	 */
	private static function declarations( string $body, Draft_Content $content ): string {
		$kept = array();

		foreach ( explode( ';', str_replace( array( '{', '}' ), '', $body ) ) as $declaration ) {
			$declaration = trim( $declaration );

			if ( ! str_contains( $declaration, ':' ) ) {
				continue;
			}

			if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)/i', $declaration, $urls ) ) {
				foreach ( $urls[1] as $url ) {
					if ( ! self::attachment_id( trim( $url ) ) ) {
						$content->note( __( 'Removed a CSS background or file that is not in the media library. Use media library images (wpmark-list-media), or CSS colours and gradients.', 'wpmark' ) );
						continue 2;
					}
				}
			}

			$kept[] = $declaration;
		}

		return implode( ';', $kept );
	}

	/**
	 * Keep an animation's steps, without outside files.
	 *
	 * @param string        $body    Keyframes.
	 * @param Draft_Content $content Notes.
	 * @return string
	 */
	private static function keyframes( string $body, Draft_Content $content ): string {
		$out = '';

		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $body, $steps, PREG_SET_ORDER ) ) {
			foreach ( $steps as $step ) {
				$out .= trim( $step[1] ) . '{' . self::declarations( $step[2], $content ) . '}';
			}
		}

		return $out;
	}

	/**
	 * Design notes for the AI, so it can improve the page.
	 *
	 * @param array         $parts   The cleaned page.
	 * @param string        $css     The cleaned CSS.
	 * @param Draft_Content $content Notes.
	 */
	public static function review( array $parts, string $css, Draft_Content $content ): void {
		$html = implode( '', array_column( $parts, 'value' ) );
		$h1   = preg_match_all( '/<h1\b/i', $html );

		if ( 0 === $h1 ) {
			$content->note( __( 'Design note: the page has no main heading (h1). Give it one, at the top.', 'wpmark' ) );
		} elseif ( $h1 > 1 ) {
			$content->note( __( 'Design note: the page has more than one main heading (h1). Keep one; use h2 for section headings.', 'wpmark' ) );
		}

		if ( '' !== $css && ! preg_match( '/@media\b/i', $css ) ) {
			$content->note( __( 'Design note: the CSS has no @media rules. Add rules for phones (for example @media (max-width: 768px)) so columns stack and text stays readable.', 'wpmark' ) );
		}

		if ( preg_match( '/<img\b(?![^>]*\balt="[^"]+")[^>]*>/i', $html ) ) {
			$content->note( __( 'Design note: some images have no description (alt text). Describe each image, or use alt="" for decoration.', 'wpmark' ) );
		}

		if ( '' !== $css && preg_match( '/#[0-9a-f]{3,8}\b|rgba?\(/i', $css ) && ! str_contains( $css, 'var(--wpmark-' ) ) {
			$content->note( __( 'Design note: the CSS uses its own colours. Use the site\'s colour and font variables (var(--wpmark-color-…), var(--wpmark-font-…) from wpmark-design-guide) so the page matches the brand.', 'wpmark' ) );
		}
	}

	/**
	 * The page template for a layout.
	 *
	 * @param Builder $builder   The page's builder.
	 * @param string  $layout    "site" or "blank".
	 * @param string  $post_type "page" or "post".
	 * @return string Template, or "" for the default.
	 */
	public static function template( Builder $builder, string $layout, string $post_type ): string {
		if ( 'page' !== $post_type ) {
			return '';
		}

		if ( 'elementor' === $builder->id() ) {
			return 'blank' === $layout ? 'elementor_canvas' : 'elementor_header_footer';
		}

		if ( 'blank' !== $layout ) {
			return '';
		}

		return wp_is_block_theme() ? self::BLANK_TEMPLATE : self::BLANK_TEMPLATE . '.php';
	}

	/**
	 * The site's colours and fonts as CSS variables the page can use, each
	 * linked to the theme or the Elementor kit.
	 *
	 * @param Builder $builder The page's builder.
	 * @return array<string, array{value: string, means: string}> Variable name => its value and what it is.
	 */
	public static function css_variables( Builder $builder ): array {
		$design    = $builder->design();
		$variables = array();

		foreach ( (array) ( $design['colors'] ?? array() ) as $color ) {
			$key = sanitize_key( (string) ( $color['slug'] ?? $color['id'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$fallback = preg_replace( '/[^#a-z0-9(),.%\s-]/i', '', (string) ( $color['value'] ?? '' ) );

			$variables[ '--wpmark-color-' . $key ] = array(
				'value' => 'elementor' === $builder->id()
					? 'var(--e-global-color-' . $key . ( '' !== $fallback ? ', ' . $fallback : '' ) . ')'
					: 'var(--wp--preset--color--' . $key . ( '' !== $fallback ? ', ' . $fallback : '' ) . ')',
				'means' => (string) ( $color['name'] ?? $key ) . ( '' !== $fallback ? ' (' . $fallback . ')' : '' ),
			);
		}

		foreach ( (array) ( $design['fonts'] ?? array() ) as $font ) {
			$key = sanitize_key( (string) ( $font['slug'] ?? $font['id'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$family = preg_replace( '/[^a-z0-9 ,\'"-]/i', '', (string) ( $font['family'] ?? $font['value'] ?? '' ) );

			$variables[ '--wpmark-font-' . $key ] = array(
				'value' => 'elementor' === $builder->id()
					? 'var(--e-global-typography-' . $key . '-font-family' . ( '' !== $family ? ', "' . trim( $family, '"\'' ) . '"' : '' ) . '), sans-serif'
					: 'var(--wp--preset--font-family--' . $key . ( '' !== $family ? ', ' . $family : '' ) . ')',
				'means' => (string) ( $font['name'] ?? $key ) . ( '' !== $family ? ' (' . $family . ')' : '' ),
			);
		}

		return $variables;
	}

	/**
	 * Whether a post is a custom-designed page.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_html_page( WP_Post $post ): bool {
		return metadata_exists( 'post', $post->ID, self::LAYOUT_META );
	}

	/**
	 * A custom-designed page's HTML, CSS and layout, so the AI can change it.
	 *
	 * @param WP_Post $post Post.
	 * @return array{html: string, css: string, layout: string}|null Null for other pages.
	 */
	public static function read( WP_Post $post ): ?array {
		if ( ! self::is_html_page( $post ) ) {
			return null;
		}

		$parts = 'elementor' === Builders::for_post( $post )->id()
			? self::elementor_parts( Elementor::elements( $post ) )
			: self::block_parts( parse_blocks( $post->post_content ) );

		return array(
			'html'   => implode( "\n", $parts ),
			'css'    => (string) get_post_meta( $post->ID, self::CSS_META, true ),
			'layout' => (string) get_post_meta( $post->ID, self::LAYOUT_META, true ),
		);
	}

	/**
	 * The HTML and forms of a block page, in order.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function block_parts( array $blocks ): array {
		$parts = array();

		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'] ?? '', array( 'core/html', 'core/shortcode' ), true ) ) {
				$parts[] = trim( (string) $block['innerHTML'] );
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$parts = array_merge( $parts, self::block_parts( $block['innerBlocks'] ) );
			}
		}

		return $parts;
	}

	/**
	 * The HTML and forms of an Elementor page, in order.
	 *
	 * @param array $elements Elements.
	 * @return string[]
	 */
	private static function elementor_parts( array $elements ): array {
		$parts = array();

		foreach ( $elements as $element ) {
			$widget = (string) ( $element['widgetType'] ?? '' );

			if ( 'html' === $widget ) {
				$parts[] = trim( (string) ( $element['settings']['html'] ?? '' ) );
			} elseif ( 'shortcode' === $widget ) {
				$parts[] = trim( (string) ( $element['settings']['shortcode'] ?? '' ) );
			} elseif ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$parts = array_merge( $parts, self::elementor_parts( $element['elements'] ) );
			}
		}

		return $parts;
	}

	/**
	 * Print a custom-designed page's CSS in its head: the site's colour and
	 * font variables, a small reset, full width for the "site" layout, and
	 * the page's own CSS.
	 */
	public static function print_css(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || ! self::is_html_page( $post ) ) {
			return;
		}

		echo "<style id=\"wpmark-page-css\">\n" . self::page_css( $post ) . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS cleaned when saved; cannot contain "<".
	}

	/**
	 * All the CSS of a custom-designed page.
	 *
	 * @param WP_Post $post Page.
	 * @return string
	 */
	public static function page_css( WP_Post $post ): string {
		$builder   = Builders::for_post( $post );
		$scope     = '.' . self::SCOPE;
		$variables = '';

		foreach ( self::css_variables( $builder ) as $name => $variable ) {
			$variables .= $name . ':' . $variable['value'] . ';';
		}

		$css = $scope . '{' . $variables . 'box-sizing:border-box}'
			. $scope . ' *,' . $scope . ' *::before,' . $scope . ' *::after{box-sizing:inherit}'
			. $scope . ' img,' . $scope . ' svg,' . $scope . ' video{max-width:100%;height:auto}';

		// Classic themes have no full-width alignment: stretch the page to the window.
		if ( 'blocks' === $builder->id() && 'blank' !== get_post_meta( $post->ID, self::LAYOUT_META, true ) && ! wp_is_block_theme() ) {
			$css .= $scope . '{width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}body{overflow-x:clip}';
		}

		$own = (string) get_post_meta( $post->ID, self::CSS_META, true );

		// Saved CSS cannot hold "<", so it cannot close the style tag.
		return str_replace( '<', '', $css . "\n" . $own );
	}

	/**
	 * Add the blank template to block themes.
	 */
	public static function register_block_template(): void {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}

		register_block_template(
			'wpmark//' . self::BLANK_TEMPLATE,
			array(
				'title'       => __( 'Blank landing page (WPMark)', 'wpmark' ),
				'description' => __( 'The page alone, without the site\'s header or footer.', 'wpmark' ),
				'content'     => '<!-- wp:post-content {"layout":{"type":"default"}} /-->',
				'post_types'  => array( 'page' ),
			)
		);
	}

	/**
	 * Add the blank template to classic themes.
	 *
	 * @param array $templates File => name.
	 * @return array
	 */
	public static function classic_templates( $templates ): array {
		$templates = is_array( $templates ) ? $templates : array();

		if ( ! wp_is_block_theme() ) {
			$templates[ self::BLANK_TEMPLATE . '.php' ] = __( 'Blank landing page (WPMark)', 'wpmark' );
		}

		return $templates;
	}

	/**
	 * Show pages that use the blank template with WPMark's own file.
	 *
	 * @param string $template Template file.
	 * @return string
	 */
	public static function classic_template_file( $template ) {
		if ( is_singular( 'page' ) && ! wp_is_block_theme() && self::BLANK_TEMPLATE . '.php' === get_page_template_slug( get_queried_object_id() ) ) {
			return WPMARK_DIR . 'templates/blank-page.php';
		}

		return $template;
	}
}
