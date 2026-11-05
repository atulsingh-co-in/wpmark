<?php
/**
 * Writing drafts with Elementor.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_Error;
use WP_Post;
use WPMark\Drafts\Draft_Content;
use WPMark\Drafts\Html_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Builds drafts out of Elementor elements, saved through Elementor's own
 * document API, the same way the Elementor editor and its template import
 * do:
 *
 * - A new page is created with Elementor's documents manager (create()),
 *   then its elements are saved with Document::save(). Elementor then
 *   validates every element against its registered widgets, refreshes
 *   the page's CSS and keeps its revisions, exactly as after an edit.
 * - Elements use Elementor's template format (elType, widgetType,
 *   settings, elements), the format of Elementor's own template export.
 * - New sections use the site's layout model (flexbox containers, or the
 *   older sections and columns) and the kit's global colours, so they
 *   follow the site's style; widget typography follows the kit by default.
 * - Element ids are made unique within the page, as Elementor does when it
 *   imports a template or pastes a copied section.
 */
final class Elementor_Writer implements Writer {

	/**
	 * Widgets never accepted in a native section: they could carry code.
	 */
	private const BLOCKED_WIDGETS = array( 'html', 'code-highlight', 'template', 'sitemap' );

	/**
	 * Settings never accepted from the AI: custom code and attributes.
	 */
	private const BLOCKED_SETTINGS = array( 'custom_css', '_attributes', 'custom_attributes', '_element_custom_width', 'html' );

	/**
	 * Most elements accepted in one native section.
	 */
	private const MAX_ELEMENTS = 400;

	/**
	 * Element ids already used in the page being built.
	 *
	 * @var array<string, true>
	 */
	private array $used_ids = array();

	/**
	 * Elements accepted so far in a native section.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Constructor.
	 *
	 * @param Elementor $elementor The Elementor builder, for the site's design.
	 */
	public function __construct( private readonly Elementor $elementor ) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function sections( WP_Post $post ): array {
		$sections = array();

		foreach ( Elementor::elements( $post ) as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$sections[ self::key( $element['id'] ) ] = array( $element );
			}
		}

		return $sections;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array         $nodes   Elements of one section.
	 * @param string        $key     Section key (unused: Elementor keys are element ids).
	 * @param array         $fills   Slot key => new content.
	 * @param Draft_Content $content Content checks.
	 * @param string[]      $applied Slot keys filled so far.
	 */
	public function fill( array $nodes, string $key, array $fills, Draft_Content $content, array &$applied ): array {
		unset( $key );

		foreach ( $nodes as $index => $element ) {
			$nodes[ $index ] = $this->fill_element( $element, $fills, $content, $applied );
		}

		return $nodes;
	}

	/**
	 * Fill one element and the elements inside it.
	 *
	 * @param array         $element Element.
	 * @param array         $fills   Slot key => new content.
	 * @param Draft_Content $content Content checks.
	 * @param string[]      $applied Slot keys filled so far.
	 * @return array
	 */
	private function fill_element( array $element, array $fills, Draft_Content $content, array &$applied ): array {
		$key      = self::key( $element['id'] ?? '' );
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();

		if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
			if ( array_key_exists( $key . ':background', $fills ) ) {
				$image = $content->image( self::field( $fills[ $key . ':background' ], 'image_id' ), 'full' );
				if ( $image ) {
					$settings['background_background'] = 'classic';
					$settings['background_image']      = self::media( $image );
					$applied[]                         = $key . ':background';
				}
			}

			$element['settings'] = $settings;

			foreach ( (array) ( $element['elements'] ?? array() ) as $index => $child ) {
				if ( is_array( $child ) ) {
					$element['elements'][ $index ] = $this->fill_element( $child, $fills, $content, $applied );
				}
			}

			return $element;
		}

		if ( array_key_exists( $key, $fills ) ) {
			$filled = $this->fill_widget( (string) ( $element['widgetType'] ?? '' ), $settings, $fills[ $key ], $content );

			if ( null !== $filled ) {
				$element['settings'] = $filled;
				$applied[]           = $key;
			}
		}

		return $element;
	}

	/**
	 * New settings for one widget.
	 *
	 * @param string        $widget   Widget type.
	 * @param array         $settings Current settings.
	 * @param mixed         $value    New content.
	 * @param Draft_Content $content  Content checks.
	 * @return array|null Null when the widget is not a slot.
	 */
	private function fill_widget( string $widget, array $settings, $value, Draft_Content $content ): ?array {
		$set = static function ( array $values ) use ( &$settings ): void {
			foreach ( $values as $name => $new ) {
				if ( '' !== $new && null !== $new ) {
					$settings[ $name ] = $new;
				}
			}
		};

		switch ( $widget ) {
			case 'heading':
				$level = (int) self::field( $value, 'level' );
				$set(
					array(
						'title'       => $content->rich( self::field( $value, 'text' ) ),
						'header_size' => $level >= 1 && $level <= 6 ? 'h' . $level : '',
					)
				);
				return $settings;

			case 'text-editor':
				$set( array( 'editor' => self::paragraphs( $content->rich( self::field( $value, 'text' ) ) ) ) );
				return $settings;

			case 'button':
				$set( array( 'text' => $content->plain( self::field( $value, 'text' ) ) ) );
				$settings = $this->set_link( $settings, 'link', self::field( $value, 'link' ), $content );
				return $settings;

			case 'image':
				$image = $content->image( self::field( $value, 'image_id' ) );
				if ( $image ) {
					$settings['image'] = self::media( $image, $content->plain( self::field( $value, 'alt' ) ) );
				}
				return $settings;

			case 'icon-box':
			case 'image-box':
				$set(
					array(
						'title_text'       => $content->plain( self::field( $value, 'title' ) ),
						'description_text' => $content->rich( self::field( $value, 'text' ) ),
					)
				);
				$settings = $this->set_link( $settings, 'link', self::field( $value, 'link' ), $content );
				$image    = 'image-box' === $widget ? $content->image( self::field( $value, 'image_id' ) ) : null;
				if ( $image ) {
					$settings['image'] = self::media( $image );
				}
				return $settings;

			case 'call-to-action':
				$set(
					array(
						'title'       => $content->plain( self::field( $value, 'title' ) ),
						'description' => $content->rich( self::field( $value, 'text' ) ),
						'button'      => $content->plain( self::field( $value, 'button_text' ) ),
					)
				);
				$settings = $this->set_link( $settings, 'link', self::field( $value, 'link' ), $content );
				$image    = $content->image( self::field( $value, 'image_id' ), 'full' );
				if ( $image ) {
					$settings['bg_image'] = self::media( $image );
				}
				return $settings;

			case 'icon-list':
				$items = array();
				foreach ( (array) self::field( $value, 'items' ) as $item ) {
					$text = $content->plain( self::field( $item, 'text' ) );
					if ( '' !== $text ) {
						$items[] = array_filter(
							array(
								'text' => $text,
								'link' => self::link_value( $content->link( self::field( $item, 'link' ) ) ),
							)
						);
					}
				}
				if ( $items ) {
					$settings['icon_list'] = $this->repeater( (array) ( $settings['icon_list'] ?? array() ), $items );
				}
				return $settings;

			case 'testimonial':
				$set(
					array(
						'testimonial_content' => $content->rich( self::field( $value, 'quote' ) ),
						'testimonial_name'    => $content->plain( self::field( $value, 'name' ) ),
						'testimonial_job'     => $content->plain( self::field( $value, 'role' ) ),
					)
				);
				return $settings;

			case 'accordion':
			case 'toggle':
			case 'tabs':
				$items = array();
				foreach ( (array) self::field( $value, 'items' ) as $item ) {
					$question = $content->plain( self::field( $item, 'question' ) );
					$answer   = $content->rich( self::field( $item, 'answer' ) );
					if ( '' !== $question && '' !== $answer ) {
						$items[] = array(
							'tab_title'   => $question,
							'tab_content' => self::paragraphs( $answer ),
						);
					}
				}
				if ( $items ) {
					$settings['tabs'] = $this->repeater( (array) ( $settings['tabs'] ?? array() ), $items );
				}
				return $settings;

			case 'counter':
				$number = self::field( $value, 'number' );
				$set(
					array(
						'title'         => $content->plain( self::field( $value, 'title' ) ),
						'ending_number' => is_numeric( $number ) ? 0 + $number : '',
						'suffix'        => $content->plain( self::field( $value, 'suffix' ), 20 ),
					)
				);
				return $settings;

			case 'price-table':
				$set(
					array(
						'heading'     => $content->plain( self::field( $value, 'title' ) ),
						'sub_heading' => $content->plain( self::field( $value, 'subtitle' ) ),
						'price'       => $content->plain( self::field( $value, 'price' ), 20 ),
						'button_text' => $content->plain( self::field( $value, 'button_text' ) ),
					)
				);
				$features = array();
				foreach ( (array) self::field( $value, 'features' ) as $feature ) {
					$text = $content->plain( is_array( $feature ) ? ( $feature['text'] ?? '' ) : $feature );
					if ( '' !== $text ) {
						$features[] = array( 'item_text' => $text );
					}
				}
				if ( $features ) {
					$settings['features_list'] = $this->repeater( (array) ( $settings['features_list'] ?? array() ), $features );
				}
				return $this->set_link( $settings, 'link', self::field( $value, 'link' ), $content );

			case 'video':
				$url = $content->video( self::field( $value, 'url' ) );
				if ( '' !== $url ) {
					$type                       = str_contains( (string) wp_parse_url( $url, PHP_URL_HOST ), 'vimeo' ) ? 'vimeo' : 'youtube';
					$settings['video_type']     = $type;
					$settings[ $type . '_url' ] = $url;
				}
				return $settings;

			case 'shortcode':
				$form = Form_Embeds::find( (string) self::field( $value, 'plugin' ), (string) self::field( $value, 'form' ) );
				if ( $form ) {
					$settings['shortcode'] = $form['shortcode'];
				} else {
					$content->note( __( 'Kept the original form: the form asked for was not found. Find forms with wpmark-design-guide.', 'wpmark' ) );
				}
				return $settings;

			case 'form':
				$content->note( __( 'Kept the Elementor form as it is: its fields and where it sends entries are changed in Elementor.', 'wpmark' ) );
				return $settings;
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array         $spec    items or columns, background (a kit colour id).
	 * @param Draft_Content $content Content checks.
	 */
	public function compose( array $spec, Draft_Content $content ): array {
		$columns = isset( $spec['columns'] ) && is_array( $spec['columns'] ) ? array_slice( $spec['columns'], 0, 4 ) : array( (array) ( $spec['items'] ?? array() ) );
		$columns = array_values( array_filter( $columns, 'is_array' ) );
		$widgets = array_map( fn( array $items ): array => $this->widgets( $items, $content ), $columns );
		$widgets = array_values( array_filter( $widgets ) );

		if ( ! $widgets ) {
			return array();
		}

		$settings = $this->background( (string) ( $spec['background'] ?? '' ), $content );
		$count    = count( $widgets );

		if ( $this->elementor->uses_containers() ) {
			if ( 1 === $count ) {
				return array( $this->element( 'container', $settings + array( 'content_width' => 'boxed' ), $widgets[0] ) );
			}

			$inner = array();
			foreach ( $widgets as $column ) {
				$inner[] = $this->element(
					'container',
					array(
						'content_width' => 'full',
						'width'         => array(
							'unit' => '%',
							'size' => round( 100 / $count, 3 ),
						),
					),
					$column,
					true
				);
			}

			return array(
				$this->element(
					'container',
					$settings + array(
						'content_width'  => 'boxed',
						'flex_direction' => 'row',
					),
					$inner
				),
			);
		}

		$inner = array();
		foreach ( $widgets as $column ) {
			$inner[] = $this->element( 'column', array( '_column_size' => (int) round( 100 / $count ) ), $column );
		}

		return array( $this->element( 'section', $settings, $inner ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A full-width container (or section and column) without padding or
	 * gaps, holding HTML widgets, with a shortcode widget for each form.
	 *
	 * @param array<int, array{type: string, value: string}> $parts Cleaned HTML and forms.
	 */
	public function html( array $parts ): array {
		$widgets = array_map(
			fn( array $part ): array => 'form' === $part['type']
				? $this->widget( 'shortcode', array( 'shortcode' => $part['value'] ) )
				: $this->widget( 'html', array( 'html' => $part['value'] ) ),
			$parts
		);

		$none = array(
			'unit'     => 'px',
			'top'      => '0',
			'right'    => '0',
			'bottom'   => '0',
			'left'     => '0',
			'isLinked' => true,
		);

		if ( $this->elementor->uses_containers() ) {
			return array(
				$this->element(
					'container',
					array(
						'content_width' => 'full',
						'css_classes'   => Html_Page::SCOPE,
						'padding'       => $none,
						'flex_gap'      => array(
							'unit'     => 'px',
							'size'     => 0,
							'column'   => '0',
							'row'      => '0',
							'isLinked' => true,
						),
					),
					$widgets
				),
			);
		}

		return array(
			$this->element(
				'section',
				array(
					'layout'      => 'full_width',
					'gap'         => 'no',
					'css_classes' => Html_Page::SCOPE,
				),
				array( $this->element( 'column', array( '_column_size' => 100 ), $widgets ) )
			),
		);
	}

	/**
	 * Elementor widgets for a list of simple items.
	 *
	 * @param array         $items   Items, each with a kind.
	 * @param Draft_Content $content Content checks.
	 * @return array
	 */
	private function widgets( array $items, Draft_Content $content ): array {
		$widgets = array();

		foreach ( array_slice( $items, 0, 40 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$widget = $this->widget_for( (string) ( $item['kind'] ?? '' ), $item, $content );
			if ( $widget ) {
				$widgets[] = $widget;
			}
		}

		return $widgets;
	}

	/**
	 * The widget for one simple item.
	 *
	 * @param string        $kind    Item kind.
	 * @param array         $item    Item fields.
	 * @param Draft_Content $content Content checks.
	 * @return array|null
	 */
	private function widget_for( string $kind, array $item, Draft_Content $content ): ?array {
		switch ( $kind ) {
			case 'heading':
				$text = $content->rich( $item['text'] ?? '' );
				return '' === $text ? null : $this->widget(
					'heading',
					array(
						'title'       => $text,
						'header_size' => 'h' . max( 1, min( 6, (int) ( $item['level'] ?? 2 ) ) ),
					)
				);

			case 'text':
				$text = $content->rich( $item['text'] ?? '' );
				return '' === $text ? null : $this->widget( 'text-editor', array( 'editor' => self::paragraphs( $text ) ) );

			case 'button':
				$text = $content->plain( $item['text'] ?? '' );
				return '' === $text ? null : $this->widget(
					'button',
					array(
						'text' => $text,
						'link' => self::link_value( $content->link( $item['link'] ?? '' ) ),
					)
				);

			case 'image':
				$image = $content->image( $item['image_id'] ?? 0 );
				return $image ? $this->widget(
					'image',
					array(
						'image'      => self::media( $image, $content->plain( $item['alt'] ?? '' ) ),
						'image_size' => 'large',
					)
				) : null;

			case 'list':
				$list = array();
				foreach ( (array) ( $item['items'] ?? array() ) as $entry ) {
					$text = $content->plain( self::field( $entry, 'text' ) );
					if ( '' !== $text ) {
						$list[] = array(
							'_id'           => $this->new_id(),
							'text'          => $text,
							'selected_icon' => array(
								'value'   => 'fas fa-check',
								'library' => 'fa-solid',
							),
						);
					}
				}
				return $list ? $this->widget( 'icon-list', array( 'icon_list' => $list ) ) : null;

			case 'faq':
				$tabs = array();
				foreach ( (array) ( $item['items'] ?? array() ) as $entry ) {
					$question = $content->plain( self::field( $entry, 'question' ) );
					$answer   = $content->rich( self::field( $entry, 'answer' ) );
					if ( '' !== $question && '' !== $answer ) {
						$tabs[] = array(
							'_id'         => $this->new_id(),
							'tab_title'   => $question,
							'tab_content' => self::paragraphs( $answer ),
						);
					}
				}
				return $tabs ? $this->widget( 'accordion', array( 'tabs' => $tabs ) ) : null;

			case 'testimonial':
				$quote = $content->rich( $item['quote'] ?? '' );
				return '' === $quote ? null : $this->widget(
					'testimonial',
					array(
						'testimonial_content' => $quote,
						'testimonial_name'    => $content->plain( $item['name'] ?? '' ),
						'testimonial_job'     => $content->plain( $item['role'] ?? '' ),
					)
				);

			case 'video':
				$url = $content->video( $item['url'] ?? '' );
				if ( '' === $url ) {
					return null;
				}
				$type = str_contains( (string) wp_parse_url( $url, PHP_URL_HOST ), 'vimeo' ) ? 'vimeo' : 'youtube';
				return $this->widget(
					'video',
					array(
						'video_type'   => $type,
						$type . '_url' => $url,
					)
				);

			case 'form':
				$form = Form_Embeds::find( (string) ( $item['plugin'] ?? '' ), (string) ( $item['form'] ?? '' ) );
				if ( ! $form ) {
					$content->note( __( 'Left out a form: it was not found. Use one of the forms listed by wpmark-design-guide.', 'wpmark' ) );
					return null;
				}
				return $this->widget( 'shortcode', array( 'shortcode' => $form['shortcode'] ) );
		}

		/* translators: %s: Item kind. */
		$content->note( sprintf( __( 'Left out an item of kind "%s": use heading, text, button, image, list, faq, testimonial, video or form.', 'wpmark' ), mb_substr( $kind, 0, 40 ) ) );

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Accepts Elementor's template format: the JSON of a template exported
	 * from Elementor (its "content"), a list of elements, or one element.
	 *
	 * @param mixed         $value   Elementor JSON, decoded or as a string.
	 * @param Draft_Content $content Content checks.
	 */
	public function native( $value, Draft_Content $content ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		// An exported template keeps its elements under "content", next to "version" and "type".
		if ( isset( $value['content'] ) && is_array( $value['content'] ) ) {
			$value = $value['content'];
		} elseif ( isset( $value['elType'] ) ) {
			$value = array( $value );
		}

		$this->count = 0;
		$elements    = array();

		foreach ( $value as $element ) {
			$clean = is_array( $element ) ? $this->clean_element( $element, $content, 0 ) : null;
			if ( $clean ) {
				$elements[] = $clean;
			}
		}

		return $elements;
	}

	/**
	 * Check one element of a native section.
	 *
	 * @param array         $element Element.
	 * @param Draft_Content $content Content checks.
	 * @param int           $depth   Nesting depth.
	 * @return array|null
	 */
	private function clean_element( array $element, Draft_Content $content, int $depth ): ?array {
		$type = (string) ( $element['elType'] ?? '' );

		if ( $depth > 12 || ++$this->count > self::MAX_ELEMENTS ) {
			$content->note( __( 'Left out part of the Elementor content: it was too large or too deeply nested.', 'wpmark' ) );
			return null;
		}

		if ( ! in_array( $type, array( 'container', 'section', 'column', 'widget' ), true ) ) {
			/* translators: %s: Elementor element type. */
			$content->note( sprintf( __( 'Removed an Elementor element of type "%s".', 'wpmark' ), mb_substr( $type, 0, 40 ) ) );
			return null;
		}

		$clean = array(
			'id'       => self::key( $element['id'] ?? '' ),
			'elType'   => $type,
			'settings' => $this->clean_settings( is_array( $element['settings'] ?? null ) ? $element['settings'] : array(), $content ),
			'elements' => array(),
		);

		if ( ! empty( $element['isInner'] ) ) {
			$clean['isInner'] = true;
		}

		if ( 'widget' === $type ) {
			$widget = sanitize_key( (string) ( $element['widgetType'] ?? '' ) );

			if ( ! $this->widget_allowed( $widget, $clean['settings'] ) ) {
				/* translators: %s: Elementor widget name. */
				$content->note( sprintf( __( 'Removed an Elementor "%s" widget: it is not available or not allowed in drafts.', 'wpmark' ), $widget ) );
				return null;
			}

			$clean['widgetType'] = $widget;
			return $clean;
		}

		foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
			$child = is_array( $child ) ? $this->clean_element( $child, $content, $depth + 1 ) : null;
			if ( $child ) {
				$clean['elements'][] = $child;
			}
		}

		return $clean;
	}

	/**
	 * Whether a widget may be used in a draft.
	 *
	 * @param string $widget   Widget name.
	 * @param array  $settings Its settings.
	 * @return bool
	 */
	private function widget_allowed( string $widget, array $settings ): bool {
		if ( '' === $widget || in_array( $widget, self::BLOCKED_WIDGETS, true ) || str_starts_with( $widget, 'wp-widget-' ) ) {
			return false;
		}

		if ( 'shortcode' === $widget && ! Form_Embeds::from_shortcode( (string) ( $settings['shortcode'] ?? '' ) ) ) {
			return false;
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			return null !== \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget );
		}

		return true;
	}

	/**
	 * Clean an element's settings: safe text, allowed links, and images
	 * from the media library only.
	 *
	 * @param array         $settings Settings.
	 * @param Draft_Content $content  Content checks.
	 * @return array
	 */
	private function clean_settings( array $settings, Draft_Content $content ): array {
		$clean = array();

		foreach ( $settings as $name => $value ) {
			$name = (string) $name;

			if ( in_array( $name, self::BLOCKED_SETTINGS, true ) || str_contains( $name, 'custom_css' ) ) {
				continue;
			}

			$clean[ $name ] = $this->clean_value( $value, $content );
		}

		return $clean;
	}

	/**
	 * Clean one setting value, recursively.
	 *
	 * @param mixed         $value   Value.
	 * @param Draft_Content $content Content checks.
	 * @return mixed
	 */
	private function clean_value( $value, Draft_Content $content ) {
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}

		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : null;
		}

		// A media setting: { id, url }. Only images from the library.
		if ( array_key_exists( 'url', $value ) && array_key_exists( 'id', $value ) && ! array_key_exists( 'is_external', $value ) ) {
			if ( '' === (string) $value['url'] && ! $value['id'] ) {
				return $value;
			}

			$image = $content->image( $value['id'] );

			return $image ? self::media( $image ) : array(
				'url' => '',
				'id'  => '',
			);
		}

		// A link setting: { url, is_external, nofollow }.
		if ( array_key_exists( 'url', $value ) && is_string( $value['url'] ) ) {
			$value['url'] = '' === $value['url'] ? '' : $content->link( $value['url'] );
			unset( $value['custom_attributes'] );
		}

		foreach ( $value as $key => $inner ) {
			$value[ $key ] = $this->clean_value( $inner, $content );
		}

		return $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post|null $existing Draft to update, or null.
	 * @param array        $post     Post fields.
	 * @param array|null   $nodes    Elements, or null to keep the layout.
	 */
	public function save( ?WP_Post $existing, array $post, ?array $nodes ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
			return new WP_Error( 'wpmark_no_elementor', __( 'Elementor is not active on this site, so the draft cannot be built with it.', 'wpmark' ) );
		}

		$documents = \Elementor\Plugin::$instance->documents;

		if ( null !== $nodes ) {
			$this->used_ids = array();
			$nodes          = array_map( fn( array $element ): array => $this->unique_ids( $element ), $nodes );
		}

		$post['post_status'] = 'draft';

		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$updated    = wp_update_post( wp_slash( $post ), true );

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$document = $documents->get( $existing->ID, false );
		} else {
			$type     = 'page' === ( $post['post_type'] ?? 'page' ) ? 'wp-page' : 'wp-post';
			$document = $documents->create( $type, $post );

			if ( is_wp_error( $document ) ) {
				return $document;
			}
		}

		if ( ! $document ) {
			return new WP_Error( 'wpmark_elementor_save', __( 'Elementor could not open the draft.', 'wpmark' ) );
		}

		if ( null !== $nodes && ! $document->save( array( 'elements' => $nodes ) ) ) {
			return new WP_Error( 'wpmark_elementor_save', __( 'Elementor did not save the draft. Check that your role may edit pages with Elementor.', 'wpmark' ) );
		}

		$id = (int) $document->get_main_id();

		// Elementor keeps the status; make sure nothing changed it.
		if ( 'draft' !== get_post_status( $id ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}

		return $id;
	}

	/**
	 * A new element.
	 *
	 * @param string $type     elType.
	 * @param array  $settings Settings.
	 * @param array  $elements Children.
	 * @param bool   $inner    Whether it sits inside another container.
	 * @return array
	 */
	private function element( string $type, array $settings, array $elements, bool $inner = false ): array {
		$element = array(
			'id'       => $this->new_id(),
			'elType'   => $type,
			'settings' => $settings,
			'elements' => $elements,
		);

		if ( $inner ) {
			$element['isInner'] = true;
		}

		return $element;
	}

	/**
	 * A new widget.
	 *
	 * @param string $type     widgetType.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private function widget( string $type, array $settings ): array {
		return array(
			'id'         => $this->new_id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Settings for a section's background in one of the kit's colours.
	 *
	 * @param string        $color   Kit colour id, e.g. "primary".
	 * @param Draft_Content $content Content checks.
	 * @return array
	 */
	private function background( string $color, Draft_Content $content ): array {
		$color = sanitize_key( $color );

		if ( '' === $color ) {
			return array();
		}

		if ( ! in_array( $color, array_column( $this->elementor->design()['colors'], 'id' ), true ) ) {
			/* translators: %s: Colour name. */
			$content->note( sprintf( __( 'Left out the background colour "%s": it is not one of the site\'s Elementor global colours (see wpmark-design-guide).', 'wpmark' ), $color ) );
			return array();
		}

		return array(
			'background_background' => 'classic',
			'__globals__'           => array( 'background_color' => 'globals/colors?id=' . $color ),
		);
	}

	/**
	 * Set a link setting, keeping its other options.
	 *
	 * @param array         $settings Settings.
	 * @param string        $name     Setting name.
	 * @param mixed         $value    New address.
	 * @param Draft_Content $content  Content checks.
	 * @return array
	 */
	private function set_link( array $settings, string $name, $value, Draft_Content $content ): array {
		$url = $content->link( $value );

		if ( '' !== $url ) {
			$settings[ $name ] = array_merge( is_array( $settings[ $name ] ?? null ) ? $settings[ $name ] : array(), self::link_value( $url ) );
		}

		return $settings;
	}

	/**
	 * Resize a repeater (list items, FAQ items) to the new items, keeping
	 * each item's styling (icons, colours) from the existing ones.
	 *
	 * @param array $existing Current items.
	 * @param array $items    New values for each item.
	 * @return array
	 */
	private function repeater( array $existing, array $items ): array {
		$existing = array_values( array_filter( $existing, 'is_array' ) );
		$result   = array();

		foreach ( array_slice( $items, 0, 30 ) as $index => $values ) {
			$base = $existing[ $index ] ?? ( $existing ? end( $existing ) : array() );
			unset( $base['link'] );

			$item = array_merge( $base, $values );

			$item['_id'] = isset( $existing[ $index ]['_id'] ) ? $existing[ $index ]['_id'] : $this->new_id();
			$result[]    = $item;
		}

		return $result;
	}

	/**
	 * Give every element in a tree an id not yet used in this page.
	 *
	 * @param array $element Element.
	 * @return array
	 */
	private function unique_ids( array $element ): array {
		$id = self::key( $element['id'] ?? '' );

		if ( '' === $id || isset( $this->used_ids[ $id ] ) ) {
			$id = $this->new_id();
		}

		$this->used_ids[ $id ] = true;
		$element['id']         = $id;

		foreach ( (array) ( $element['elements'] ?? array() ) as $index => $child ) {
			if ( is_array( $child ) ) {
				$element['elements'][ $index ] = $this->unique_ids( $child );
			}
		}

		return $element;
	}

	/**
	 * A new element id, in Elementor's format (a short hexadecimal string).
	 *
	 * @return string
	 */
	private function new_id(): string {
		do {
			$id = dechex( wp_rand( 0x1000000, 0xfffffff ) );
		} while ( isset( $this->used_ids[ $id ] ) );

		$this->used_ids[ $id ] = true;

		return $id;
	}

	/**
	 * An Elementor media setting.
	 *
	 * @param array  $image Image from Draft_Content::image().
	 * @param string $alt   Alt text, or "" for the library's.
	 * @return array
	 */
	private static function media( array $image, string $alt = '' ): array {
		return array(
			'id'     => $image['id'],
			'url'    => $image['url'],
			'alt'    => '' !== $alt ? $alt : $image['alt'],
			'source' => 'library',
		);
	}

	/**
	 * An Elementor link setting.
	 *
	 * @param string $url Address, or "".
	 * @return array
	 */
	private static function link_value( string $url ): array {
		return '' === $url ? array() : array(
			'url'         => $url,
			'is_external' => '',
			'nofollow'    => '',
		);
	}

	/**
	 * Text as paragraphs, as Elementor's text editor stores it.
	 *
	 * @param string $html Safe inline HTML, possibly with line breaks.
	 * @return string
	 */
	private static function paragraphs( string $html ): string {
		return '' === $html ? '' : trim( wpautop( $html ) );
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
	 * An Elementor element id as a safe key.
	 *
	 * @param mixed $id Element id.
	 * @return string
	 */
	private static function key( $id ): string {
		return is_scalar( $id ) ? (string) preg_replace( '/[^a-z0-9]/i', '', (string) $id ) : '';
	}
}
