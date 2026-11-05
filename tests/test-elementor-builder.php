<?php
/**
 * Tests that need the real Elementor plugin.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Builders\Builders;
use WPMark\Builders\Elementor;
use WPMark\Settings;

/**
 * The Elementor builder on a site running Elementor.
 *
 * Skipped unless WPMARK_TEST_ELEMENTOR_DIR points at a copy of Elementor
 * (see tests/README.md).
 */
class Test_Elementor_Builder extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * Skip without Elementor.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Needs Elementor: set WPMARK_TEST_ELEMENTOR_DIR.' );
		}

		// Elementor asks its own servers for news; the test site has no network.
		set_transient( 'elementor_remote_info_api_data_' . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), HOUR_IN_SECONDS );

		// A real site gets its default kit (global colours and fonts) when Elementor is activated.
		$kit = \Elementor\Plugin::$instance->kits_manager->create_new_kit( 'Default Kit' );
		update_option( 'elementor_active_kit', $kit );
	}

	/**
	 * Create a page built with Elementor.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function elementor_page( string $title ): int {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => $title,
			)
		);
		update_post_meta( $page, Elementor::EDIT_MODE_META, 'builder' );
		update_post_meta(
			$page,
			Elementor::DATA_META,
			wp_slash(
				(string) wp_json_encode(
					array(
						array(
							'id'       => 'c0ffee1',
							'elType'   => 'container',
							'settings' => array(),
							'elements' => array(
								array(
									'id'         => 'beef001',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => $title ),
								),
							),
						),
					)
				)
			)
		);

		return $page;
	}

	/**
	 * Elementor is offered, and recommended once the site's pages use it.
	 */
	public function test_recommended_when_in_use(): void {
		$this->assertArrayHasKey( 'elementor', Builders::available() );
		$this->assertSame( 'blocks', Builders::recommended()->id(), 'Installed but unused: blocks.' );

		$page = $this->elementor_page( 'Home' );

		$this->assertSame( 'elementor', Builders::recommended()->id() );
		$this->assertSame( 'elementor', Builders::for_post( get_post( $page ) )->id() );
	}

	/**
	 * The design guide includes Elementor's global colours, fonts and page templates.
	 */
	public function test_design_guide(): void {
		$this->elementor_page( 'Home' );

		$result = $this->run_as( 'design-guide', null, 'editor' );
		$guide  = array_column( $result['builders'], null, 'builder' )['elementor'];

		$this->assertSame( 'elementor', $result['recommended_builder'] );
		$this->assertSame( ELEMENTOR_VERSION, $guide['version'] );
		$this->assertContains( 'elementor_canvas', array_column( $guide['page_templates'], 'template' ) );
		$this->assertContains( 'heading', $guide['widgets'] );
		$this->assertArrayHasKey( 'blocks', array_column( $result['builders'], null, 'builder' ), 'The theme\'s styles are listed too.' );
	}

	/**
	 * Saved templates and Elementor pages are listed; the page's structure reads back.
	 */
	public function test_templates_and_structure(): void {
		$template = self::factory()->post->create(
			array(
				'post_type'  => 'elementor_library',
				'post_title' => 'Product launch',
			)
		);
		update_post_meta( $template, Elementor::TEMPLATE_TYPE_META, 'page' );
		update_post_meta( $template, Elementor::EDIT_MODE_META, 'builder' );
		$popup = self::factory()->post->create(
			array(
				'post_type'  => 'elementor_library',
				'post_title' => 'Exit popup',
			)
		);
		update_post_meta( $popup, Elementor::TEMPLATE_TYPE_META, 'popup' );
		$page = $this->elementor_page( 'Webinar' );

		$result = $this->run_as( 'find-templates', array( 'builder' => 'elementor' ), 'editor' );
		$refs   = array_column( array_column( $result['templates'], 'ref' ), 'id' );

		$this->assertContains( $template, $refs );
		$this->assertContains( $page, $refs );
		$this->assertNotContains( $popup, $refs, 'Popups, headers and footers are not page templates.' );

		$structure = $this->run_as( 'page-structure', array( 'id' => $page ), 'editor' );

		$this->assertSame( 'elementor', $structure['builder'] );
		$this->assertSame( 'Webinar', $structure['sections'][0]['slots'][0]['text'] );
	}

	/**
	 * Draft from an Elementor page: copied through Elementor's document API,
	 * with new content in the slots and fresh element ids.
	 *
	 * Runs as an administrator: Elementor 4.0's own content sanitizer reads
	 * "widgetType" on containers when anyone else saves a heading, a PHP
	 * warning that the test suite treats as a failure (the same happens in
	 * Elementor's editor).
	 */
	public function test_draft_from_elementor_page(): void {
		Settings::save_drafts( true );
		$page = $this->elementor_page( 'Webinar' );

		$result = $this->run_as(
			'create-draft',
			array(
				'title'         => 'Product launch',
				'page_template' => 'elementor_canvas',
				'sections'      => array(
					array(
						'from'  => array(
							'id'      => $page,
							'section' => 'c0ffee1',
						),
						'fills' => array( 'beef001' => array( 'text' => 'Launch <em>day</em>', 'level' => 1 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
					),
					array(
						'compose' => array(
							'background' => 'primary',
							'columns'    => array(
								array( array( 'kind' => 'heading', 'text' => 'Fast' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
								array(
									array(
										'kind'  => 'faq',
										'items' => array(
											array(
												'question' => 'Free?',
												'answer'   => 'Yes.',
											),
										),
									),
								),
							),
						),
					),
				),
			),
			'administrator'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'elementor', $result['builder'] );
		$this->assertSame( 'draft', get_post_status( $result['id'] ) );
		$this->assertSame( 'elementor_canvas', get_page_template_slug( $result['id'] ) );
		$this->assertStringContainsString( 'action=elementor', $result['edit_url'] );
		$this->assertSame( 'builder', get_post_meta( $result['id'], Elementor::EDIT_MODE_META, true ) );

		$elements = Elementor::elements( get_post( $result['id'] ) );
		$this->assertCount( 2, $elements );
		$this->assertSame( 'Launch <em>day</em>', $elements[0]['elements'][0]['settings']['title'] );
		$this->assertSame( 'h1', $elements[0]['elements'][0]['settings']['header_size'] );

		$composed = $elements[1];
		$this->assertSame( 'container', $composed['elType'] );
		$this->assertSame( 'globals/colors?id=primary', $composed['settings']['__globals__']['background_color'] );
		$this->assertCount( 2, $composed['elements'] );
		$this->assertSame( 'accordion', $composed['elements'][1]['elements'][0]['widgetType'] );

		$ids = array();
		array_walk_recursive(
			$elements,
			static function ( $value, $key ) use ( &$ids ): void {
				if ( 'id' === $key && is_string( $value ) ) {
					$ids[] = $value;
				}
			}
		);
		$this->assertSame( $ids, array_unique( $ids ), 'Element ids are unique in the page.' );

		// Change the heading again; the rest stays.
		$this->run_as(
			'update-draft',
			array(
				'id'    => $result['id'],
				'fills' => array( $elements[0]['elements'][0]['id'] => 'Launch week' ),
			),
			'administrator'
		);
		$after = Elementor::elements( get_post( $result['id'] ) );
		$this->assertSame( 'Launch week', $after[0]['elements'][0]['settings']['title'] );
		$this->assertCount( 2, $after );
	}

	/**
	 * Copying a whole Elementor page keeps its builder, layout and page settings.
	 */
	public function test_copy_elementor_page(): void {
		Settings::save_drafts( true );
		$page = $this->elementor_page( 'Webinar' );
		update_post_meta( $page, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );

		$result = $this->run_as(
			'create-draft',
			array(
				'title'   => 'Webinar in Hindi',
				'copy_of' => $page,
				'fills'   => array( 'beef001' => 'Webinar in Hindi' ),
			),
			'administrator'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'elementor', $result['builder'] );

		$elements = Elementor::elements( get_post( $result['id'] ) );
		$this->assertSame( 'Webinar in Hindi', $elements[0]['elements'][0]['settings']['title'] );
		$this->assertSame( array( 'hide_title' => 'yes' ), get_post_meta( $result['id'], '_elementor_page_settings', true ) );
		$this->assertSame( 'Webinar', Elementor::elements( get_post( $page ) )[0]['elements'][0]['settings']['title'], 'The original is not changed.' );
	}

	/**
	 * A custom-designed page in Elementor: a full-width container of HTML
	 * widgets with the form between them, in Elementor's full-width or canvas template.
	 */
	public function test_html_page(): void {
		Settings::save_drafts( true );
		Settings::save_custom_design( true );
		$this->elementor_page( 'Home' );

		$result = $this->run_as(
			'create-draft',
			array(
				'title' => 'Webinar',
				'html'  => '<section class="hero"><h1>Webinar</h1></section>[wpforms id="5"]<section class="faq"><h2>FAQ</h2></section>',
				'css'   => '.hero{padding:5rem 0}',
			),
			'administrator'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'elementor', $result['builder'] );
		$this->assertSame( 'elementor_header_footer', get_page_template_slug( $result['id'] ) );

		$elements = Elementor::elements( get_post( $result['id'] ) );
		$this->assertCount( 1, $elements );
		$this->assertSame( 'wpmark-page', $elements[0]['settings']['css_classes'] );
		$this->assertSame( 'full', $elements[0]['settings']['content_width'] );
		$this->assertSame( 'html', $elements[0]['elements'][0]['widgetType'] );
		$this->assertStringContainsString( '<h1>Webinar</h1>', $elements[0]['elements'][0]['settings']['html'] );
		$this->assertStringContainsString( 'FAQ', $result['html_page']['html'] );

		$css = \WPMark\Drafts\Html_Page::css_variables( new Elementor() );
		$this->assertStringStartsWith( 'var(--e-global-color-primary', $css['--wpmark-color-primary']['value'] );

		$this->run_as(
			'update-draft',
			array(
				'id'     => $result['id'],
				'layout' => 'blank',
			),
			'administrator'
		);
		$this->assertSame( 'elementor_canvas', get_page_template_slug( $result['id'] ) );
	}

	/**
	 * Elementor's template format from the AI: HTML widgets, custom code and
	 * outside images are removed; the rest is saved.
	 */
	public function test_native_elementor_template(): void {
		Settings::save_drafts( true );
		$this->elementor_page( 'Home' );

		$template = array(
			'version' => '0.4',
			'type'    => 'page',
			'content' => array(
				array(
					'id'       => 'aaa1111',
					'elType'   => 'container',
					'settings' => array( 'custom_css' => 'body{display:none}' ),
					'elements' => array(
						array(
							'id'         => 'bbb2222',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Hello<script>x()</script>' ),
						),
						array(
							'id'         => 'ccc3333',
							'elType'     => 'widget',
							'widgetType' => 'html',
							'settings'   => array( 'html' => '<script>x()</script>' ),
						),
						array(
							'id'         => 'ddd4444',
							'elType'     => 'widget',
							'widgetType' => 'image',
							'settings'   => array(
								'image' => array(
									'id'  => 999999,
									'url' => 'https://evil.example/x.png',
								),
							),
						),
					),
				),
			),
		);

		$result = $this->run_as(
			'create-draft',
			array(
				'title'    => 'From template',
				'sections' => array( array( 'elementor' => $template ) ),
			),
			'administrator'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$saved = (string) wp_json_encode( Elementor::elements( get_post( $result['id'] ) ) );

		$this->assertStringContainsString( 'Hello', $saved );
		$this->assertStringNotContainsString( 'script', $saved );
		$this->assertStringNotContainsString( 'custom_css', $saved );
		$this->assertStringNotContainsString( 'evil.example', $saved );
		$this->assertStringNotContainsString( '"html"', $saved );
		$this->assertNotEmpty( $result['notes'] );
	}
}
