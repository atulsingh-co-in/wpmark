<?php
/**
 * Elementor.
 *
 * @package WPMark
 */

namespace WPMark\Builders;

use WP_Post;
use WPMark\Content\Content_Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Pages built with Elementor (free, and Elementor Pro's extra widgets).
 *
 * Elementor keeps a page's layout as JSON in the "_elementor_data" post
 * meta: a tree of containers (or the older sections and columns) holding
 * widgets, each with an id and its settings. The site's look lives in the
 * active "kit": global colours and fonts that widgets point to, so a page
 * built with them changes when the site's style changes.
 *
 * This class only reads that structure. Writing goes through Elementor's
 * own document API, never by editing the JSON by hand, so Elementor keeps
 * its styles, caches and revisions correct.
 */
final class Elementor implements Builder {

	/**
	 * Post meta that marks a post as built with Elementor.
	 */
	public const EDIT_MODE_META = '_elementor_edit_mode';

	/**
	 * Post meta holding the page layout.
	 */
	public const DATA_META = '_elementor_data';

	/**
	 * Post meta holding a saved template's type.
	 */
	public const TEMPLATE_TYPE_META = '_elementor_template_type';

	/**
	 * Saved template types that can start a page or a section of one.
	 */
	private const TEMPLATE_TYPES = array( 'page', 'section', 'container' );

	/**
	 * Free widgets WPMark can fill. Others are kept as they are.
	 */
	public const FREE_WIDGETS = array( 'heading', 'text-editor', 'button', 'image', 'icon-box', 'image-box', 'icon-list', 'testimonial', 'accordion', 'toggle', 'tabs', 'counter', 'video', 'shortcode', 'spacer', 'divider' );

	/**
	 * Elementor Pro widgets WPMark can read.
	 */
	public const PRO_WIDGETS = array( 'call-to-action', 'price-table', 'form' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return $this->has_pro() ? __( 'Elementor Pro', 'wpmark' ) : __( 'Elementor', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Whether Elementor Pro is active.
	 *
	 * @return bool
	 */
	public function has_pro(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function owns( WP_Post $post ): bool {
		return 'builder' === get_post_meta( $post->ID, self::EDIT_MODE_META, true );
	}

	/**
	 * Whether any published page or post is built with Elementor.
	 *
	 * @return bool
	 */
	public function is_in_use(): bool {
		$found = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row.
				'meta_key'       => self::EDIT_MODE_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One row.
				'meta_value'     => 'builder',
			)
		);

		return (bool) $found;
	}

	/**
	 * {@inheritDoc}
	 */
	public function design(): array {
		$colors = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			foreach ( $this->kit_setting( $group ) as $color ) {
				if ( empty( $color['_id'] ) ) {
					continue;
				}

				$colors[] = array(
					'id'    => sanitize_key( $color['_id'] ),
					'name'  => Outline::text( $color['title'] ?? $color['_id'] ),
					'value' => (string) ( $color['color'] ?? '' ),
				);
			}
		}

		$fonts = array();
		foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
			foreach ( $this->kit_setting( $group ) as $font ) {
				if ( empty( $font['_id'] ) ) {
					continue;
				}

				$fonts[] = array(
					'id'     => sanitize_key( $font['_id'] ),
					'name'   => Outline::text( $font['title'] ?? $font['_id'] ),
					'family' => Outline::text( $font['typography_font_family'] ?? '' ),
					'weight' => (string) ( $font['typography_font_weight'] ?? '' ),
				);
			}
		}

		$width = $this->kit_setting( 'container_width' );

		return array(
			'builder'        => $this->id(),
			'version'        => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'pro'            => $this->has_pro(),
			'layout'         => $this->uses_containers() ? 'containers' : 'sections and columns',
			'colors'         => $colors,
			'fonts'          => $fonts,
			'content_width'  => isset( $width['size'] ) ? $width['size'] . ( $width['unit'] ?? 'px' ) : '',
			'page_templates' => array(
				array(
					'template' => '',
					'name'     => __( 'Default template (the theme\'s header and footer, page width)', 'wpmark' ),
				),
				array(
					'template' => 'elementor_header_footer',
					'name'     => __( 'Elementor Full Width (the theme\'s header and footer, full width)', 'wpmark' ),
				),
				array(
					'template' => 'elementor_canvas',
					'name'     => __( 'Elementor Canvas (no header or footer: a stand-alone landing page)', 'wpmark' ),
				),
			),
			'widgets'        => $this->has_pro() ? array_merge( self::FREE_WIDGETS, self::PRO_WIDGETS ) : self::FREE_WIDGETS,
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

		$templates = $this->posts(
			'elementor_library',
			$search,
			$limit,
			array(
				'key'     => self::TEMPLATE_TYPE_META,
				'value'   => self::TEMPLATE_TYPES,
				'compare' => 'IN',
			)
		);

		foreach ( $templates as $post ) {
			$found[] = array(
				'ref'        => array( 'id' => $post->ID ),
				'kind'       => 'saved template',
				'type'       => (string) get_post_meta( $post->ID, self::TEMPLATE_TYPE_META, true ),
				'title'      => Outline::text( $post->post_title ),
				'builder'    => $this->id(),
				'updated_at' => Content_Reader::iso_date( $post->post_modified_gmt ),
			);
		}

		$pages = $this->posts(
			'page',
			$search,
			$limit,
			array(
				'key'   => self::EDIT_MODE_META,
				'value' => 'builder',
			)
		);

		foreach ( $pages as $post ) {
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
	 * Each top-level container (or section) is a section of the outline,
	 * keyed by its Elementor element id. Each widget that can be filled is
	 * a slot keyed by its own element id.
	 *
	 * @param WP_Post $post Post.
	 */
	public function outline( WP_Post $post ): array {
		$sections = array();

		foreach ( array_slice( self::elements( $post ), 0, Outline::MAX_SECTIONS ) as $index => $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}

			$slots = array();
			$other = array();
			$this->walk( $element, $slots, $other );

			$sections[] = Outline::section(
				self::key( $element['id'] ),
				(string) ( $element['settings']['_title'] ?? '' ),
				$slots,
				$other,
				$index + 1,
				self::is_global( $element )
			);
		}

		return $sections;
	}

	/**
	 * {@inheritDoc}
	 */
	public function writer(): Writer {
		return new Elementor_Writer( $this );
	}

	/**
	 * A post's Elementor elements, decoded.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function elements( WP_Post $post ): array {
		$data = get_post_meta( $post->ID, self::DATA_META, true );

		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Collect the slots inside an element.
	 *
	 * @param array    $element Elementor element.
	 * @param array    $slots   Slots found so far.
	 * @param string[] $other   Kept-as-is widget names found so far.
	 */
	private function walk( array $element, array &$slots, array &$other ): void {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$key      = self::key( $element['id'] ?? '' );

		if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
			$background = $settings['background_image'] ?? array();
			if ( ! empty( $background['id'] ) || ! empty( $background['url'] ) ) {
				$slots[] = Outline::slot(
					$key . ':background',
					'background',
					array(
						'image_id'  => (int) ( $background['id'] ?? 0 ),
						'image_url' => Outline::link( $background['url'] ?? '' ),
					)
				);
			}

			foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$this->walk( $child, $slots, $other );
				}
			}
			return;
		}

		$widget = (string) ( $element['widgetType'] ?? '' );
		$slot   = $this->widget_slot( $widget, $key, $settings );

		if ( $slot ) {
			$slots[] = $slot;
		} else {
			$other[] = 'global' === $widget ? 'global widget' : $widget;
		}
	}

	/**
	 * The slot for one widget, or null when it is kept as it is.
	 *
	 * @param string $widget   Widget type.
	 * @param string $key      Slot key.
	 * @param array  $settings Widget settings.
	 * @return array|null
	 */
	private function widget_slot( string $widget, string $key, array $settings ): ?array {
		$link = static fn( $value ): string => Outline::link( is_array( $value ) ? ( $value['url'] ?? '' ) : '' );
		$text = static fn( string $name ): string => Outline::text( $settings[ $name ] ?? '' );

		switch ( $widget ) {
			case 'heading':
				return Outline::slot(
					$key,
					'heading',
					array(
						'text'  => $text( 'title' ),
						'level' => max( 1, min( 6, (int) ltrim( (string) ( $settings['header_size'] ?? 'h2' ), 'h' ) ) ),
					)
				);

			case 'text-editor':
				return Outline::slot( $key, 'text', array( 'text' => $text( 'editor' ) ) );

			case 'button':
				return Outline::slot(
					$key,
					'button',
					array(
						'text' => $text( 'text' ),
						'link' => $link( $settings['link'] ?? null ),
					)
				);

			case 'image':
				return Outline::slot(
					$key,
					'image',
					array(
						'image_id'  => (int) ( $settings['image']['id'] ?? 0 ),
						'image_url' => Outline::link( $settings['image']['url'] ?? '' ),
					)
				);

			case 'icon-box':
			case 'image-box':
				return Outline::slot(
					$key,
					'card',
					array(
						'title'    => $text( 'title_text' ),
						'text'     => $text( 'description_text' ),
						'link'     => $link( $settings['link'] ?? null ),
						'image_id' => (int) ( $settings['image']['id'] ?? 0 ),
					)
				);

			case 'call-to-action':
				return Outline::slot(
					$key,
					'card',
					array(
						'title'       => $text( 'title' ),
						'text'        => $text( 'description' ),
						'button_text' => $text( 'button' ),
						'link'        => $link( $settings['link'] ?? null ),
						'image_id'    => (int) ( $settings['bg_image']['id'] ?? 0 ),
					)
				);

			case 'icon-list':
				return Outline::slot(
					$key,
					'list',
					array(
						'items' => self::items(
							$settings['icon_list'] ?? array(),
							static fn( array $item ): array => array_filter(
								array(
									'text' => Outline::text( $item['text'] ?? '' ),
									'link' => $link( $item['link'] ?? null ),
								)
							)
						),
					)
				);

			case 'testimonial':
				return Outline::slot(
					$key,
					'testimonial',
					array(
						'quote' => $text( 'testimonial_content' ),
						'name'  => $text( 'testimonial_name' ),
						'role'  => $text( 'testimonial_job' ),
					)
				);

			case 'accordion':
			case 'toggle':
			case 'tabs':
				return Outline::slot(
					$key,
					'faq',
					array(
						'items' => self::items(
							$settings['tabs'] ?? array(),
							static fn( array $item ): array => array(
								'question' => Outline::text( $item['tab_title'] ?? '' ),
								'answer'   => Outline::text( $item['tab_content'] ?? '' ),
							)
						),
					)
				);

			case 'counter':
				return Outline::slot(
					$key,
					'counter',
					array(
						'title'  => $text( 'title' ),
						'number' => (string) ( $settings['ending_number'] ?? '' ),
						'suffix' => $text( 'suffix' ),
					)
				);

			case 'price-table':
				return Outline::slot(
					$key,
					'price',
					array(
						'title'       => $text( 'heading' ),
						'subtitle'    => $text( 'sub_heading' ),
						'price'       => $text( 'price' ),
						'features'    => array_column( self::items( $settings['features_list'] ?? array(), static fn( array $item ): array => array( 'text' => Outline::text( $item['item_text'] ?? '' ) ) ), 'text' ),
						'button_text' => $text( 'button_text' ),
						'link'        => $link( $settings['link'] ?? null ),
					)
				);

			case 'video':
				$type = (string) ( $settings['video_type'] ?? 'youtube' );
				return Outline::slot( $key, 'video', array( 'url' => Outline::link( $settings[ $type . '_url' ] ?? '' ) ) );

			case 'form':
				return Outline::slot(
					$key,
					'form',
					array(
						'plugin' => 'elementor-pro',
						'form'   => $text( 'form_name' ),
					)
				);

			case 'shortcode':
				$form = Form_Embeds::from_shortcode( (string) ( $settings['shortcode'] ?? '' ) );
				return $form ? Outline::slot( $key, 'form', $form ) : null;
		}

		return null;
	}

	/**
	 * Whether an element is a global widget: one shared by many pages.
	 *
	 * @param array $element Elementor element.
	 * @return bool
	 */
	private static function is_global( array $element ): bool {
		$type   = $element['elType'] ?? '';
		$widget = $element['widgetType'] ?? '';

		return 'widget' === $type && 'global' === $widget;
	}

	/**
	 * Map the items of a repeater setting, skipping anything malformed.
	 *
	 * @param mixed    $items Repeater value.
	 * @param callable $map   Item to output.
	 * @return array
	 */
	private static function items( $items, callable $map ): array {
		$mapped = array();

		foreach ( is_array( $items ) ? array_slice( $items, 0, 30 ) : array() as $item ) {
			if ( is_array( $item ) ) {
				$mapped[] = $map( $item );
			}
		}

		return $mapped;
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

	/**
	 * Whether the site lays pages out with flexbox containers (Elementor's
	 * current way) or with the older sections and columns.
	 *
	 * @return bool
	 */
	public function uses_containers(): bool {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
			return (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' );
		}

		return 'inactive' !== get_option( 'elementor_experiment-container', 'default' );
	}

	/**
	 * One setting of the active kit (the site's global style), with
	 * Elementor's own defaults when Elementor is running.
	 *
	 * @param string $name Setting name.
	 * @return array
	 */
	private function kit_setting( string $name ): array {
		$kit_id = (int) get_option( 'elementor_active_kit' );

		// Elementor's kit object fills in its defaults, but only works when the kit exists.
		if ( $kit_id && get_post( $kit_id ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			try {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
				if ( $kit && method_exists( $kit, 'get_settings' ) ) {
					$value = $kit->get_settings( $name );
					return is_array( $value ) ? $value : array();
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall back to the saved settings below.
			}
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );

		return is_array( $settings[ $name ] ?? null ) ? $settings[ $name ] : array();
	}

	/**
	 * Published posts of one type matching a meta condition.
	 *
	 * @param string $post_type Post type.
	 * @param string $search    Words to look for, or "".
	 * @param int    $limit     Most results.
	 * @param array  $meta      One meta_query clause.
	 * @return WP_Post[]
	 */
	private function posts( string $post_type, string $search, int $limit, array $meta ): array {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'has_password'   => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, limited list.
			'meta_query'     => array( $meta ),
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return get_posts( $args );
	}
}
