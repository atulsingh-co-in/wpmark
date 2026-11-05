<?php
/**
 * Tests for page builders and the page design tools.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Builders\Block_Editor;
use WPMark\Builders\Builders;
use WPMark\Builders\Elementor;
use WPMark\Builders\Form_Embeds;

/**
 * Reading how a site builds pages: outlines, templates and the design guide.
 */
class Test_Builders extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * A landing page in block markup: a named hero (cover with a heading,
	 * text and a button), a features row, an FAQ, a list, a form and some
	 * decoration.
	 */
	private const LANDING_BLOCKS = <<<'HTML'
<!-- wp:cover {"url":"https://example.org/hero.jpg","id":12,"metadata":{"name":"Hero"}} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background" src="https://example.org/hero.jpg"/><div class="wp-block-cover__inner-container">
<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Launch day is here</h1><!-- /wp:heading -->
<!-- wp:paragraph --><p>The <strong>fastest</strong> way to plan&nbsp;campaigns.</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.org/demo">Book a demo</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div></div>
<!-- /wp:cover -->

<!-- wp:columns --><div class="wp-block-columns">
<!-- wp:column --><div class="wp-block-column">
<!-- wp:image {"id":34} --><figure class="wp-block-image"><img src="https://example.org/a.png" alt="Calendar view" class="wp-image-34"/></figure><!-- /wp:image -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Plan</h3><!-- /wp:heading -->
<!-- wp:spacer --><div style="height:20px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->
</div><!-- /wp:column -->
</div><!-- /wp:columns -->

<!-- wp:details --><details class="wp-block-details"><summary>Is there a free trial?</summary><!-- wp:paragraph --><p>Yes, 14 days.</p><!-- /wp:paragraph --></details><!-- /wp:details -->

<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Fast</li><!-- /wp:list-item --><!-- wp:list-item --><li>Friendly</li><!-- /wp:list-item --></ul><!-- /wp:list -->

<!-- wp:shortcode -->[wpforms id="77"]<!-- /wp:shortcode -->

<!-- wp:html --><div>custom</div><!-- /wp:html -->
HTML;

	/**
	 * The same kind of page, as Elementor stores it.
	 *
	 * @return array
	 */
	private static function landing_elementor(): array {
		return array(
			array(
				'id'       => 'a1b2c3d',
				'elType'   => 'container',
				'settings' => array(
					'_title'           => 'Hero',
					'background_image' => array(
						'id'  => 12,
						'url' => 'https://example.org/hero.jpg',
					),
				),
				'elements' => array(
					array(
						'id'         => 'h1h1h1h',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title'       => "Launch day\u{200B} is here",
							'header_size' => 'h1',
						),
					),
					array(
						'id'         => 'tx1tx1t',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p>The fastest way to plan campaigns.</p>' ),
					),
					array(
						'id'         => 'bt1bt1b',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array(
							'text' => 'Book a demo',
							'link' => array( 'url' => 'https://example.org/demo' ),
						),
					),
					array(
						'id'         => 'sp1sp1s',
						'elType'     => 'widget',
						'widgetType' => 'spacer',
						'settings'   => array(),
					),
				),
			),
			array(
				'id'       => 'b2c3d4e',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col1col',
						'elType'   => 'column',
						'settings' => array(),
						'elements' => array(
							array(
								'id'         => 'ic1ic1i',
								'elType'     => 'widget',
								'widgetType' => 'icon-list',
								'settings'   => array(
									'icon_list' => array(
										array( 'text' => 'Fast' ),
										array(
											'text' => 'Friendly',
											'link' => array( 'url' => 'javascript:alert(1)' ),
										),
									),
								),
							),
							array(
								'id'         => 'ac1ac1a',
								'elType'     => 'widget',
								'widgetType' => 'accordion',
								'settings'   => array(
									'tabs' => array(
										array(
											'tab_title'   => 'Is there a free trial?',
											'tab_content' => '<p>Yes, 14 days.</p>',
										),
									),
								),
							),
							array(
								'id'         => 'sc1sc1s',
								'elType'     => 'widget',
								'widgetType' => 'shortcode',
								'settings'   => array( 'shortcode' => '[contact-form-7 id="5" title="Contact"]' ),
							),
							array(
								'id'         => 'xx1xx1x',
								'elType'     => 'widget',
								'widgetType' => 'some-addon-widget',
								'settings'   => array(),
							),
						),
					),
				),
			),
			array(
				'id'         => 'gl1gl1g',
				'elType'     => 'widget',
				'widgetType' => 'global',
				'templateID' => 99,
				'settings'   => array(),
			),
		);
	}

	/**
	 * Some tests describe a site without Elementor.
	 */
	private function skip_with_elementor(): void {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Describes a site without Elementor.' );
		}
	}

	/**
	 * Slots of an outline section keyed by slot key.
	 *
	 * @param array $section Section.
	 * @return array
	 */
	private static function slots( array $section ): array {
		return array_column( $section['slots'], null, 'key' );
	}

	/**
	 * Block markup becomes sections and slots with stable keys; styling stays out.
	 */
	public function test_block_outline(): void {
		$sections = ( new Block_Editor() )->outline_markup( self::LANDING_BLOCKS );

		$this->assertCount( 6, $sections );
		$this->assertSame( array( '0', '1', '2', '3', '4', '5' ), array_column( $sections, 'key' ) );

		$hero = self::slots( $sections[0] );
		$this->assertSame( 'Hero', $sections[0]['label'], 'The name given in the editor wins.' );
		$this->assertSame( 'background', $hero['0:background']['kind'] );
		$this->assertSame( 12, $hero['0:background']['image_id'] );
		$this->assertSame(
			array(
				'key'   => '0.0',
				'kind'  => 'heading',
				'text'  => 'Launch day is here',
				'level' => 1,
			),
			$hero['0.0']
		);
		$this->assertSame( 'The fastest way to plan campaigns.', $hero['0.1']['text'], 'Formatting and entities removed.' );
		$this->assertSame( 'Book a demo', $hero['0.2.0']['text'] );
		$this->assertSame( 'https://example.org/demo', $hero['0.2.0']['link'] );

		$features = self::slots( $sections[1] );
		$this->assertSame( 'Plan', $sections[1]['label'], 'Unnamed sections use their first heading.' );
		$this->assertSame( 34, $features['1.0.0']['image_id'] );
		$this->assertSame( 'Calendar view', $features['1.0.0']['alt'] );
		$this->assertSame( array( 'spacer' ), $sections[1]['kept_as_is'] );

		$faq = self::slots( $sections[2] )['2'];
		$this->assertSame( 'faq', $faq['kind'] );
		$this->assertSame( 'Is there a free trial?', $faq['items'][0]['question'] );
		$this->assertSame( 'Yes, 14 days.', $faq['items'][0]['answer'] );

		$this->assertSame( array( array( 'text' => 'Fast' ), array( 'text' => 'Friendly' ) ), self::slots( $sections[3] )['3']['items'] );
		$this->assertSame(
			array(
				'key'    => '4',
				'kind'   => 'form',
				'plugin' => 'wpforms',
				'form'   => '77',
			),
			self::slots( $sections[4] )['4']
		);
		$this->assertSame( array(), $sections[5]['slots'] );
		$this->assertSame( array( 'html' ), $sections[5]['kept_as_is'] );
	}

	/**
	 * A synced pattern is shared with other pages, so it is locked.
	 */
	public function test_synced_pattern_is_locked(): void {
		$synced   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Newsletter sign-up',
				'post_content' => '<!-- wp:paragraph --><p>Join us</p><!-- /wp:paragraph -->',
			)
		);
		$sections = ( new Block_Editor() )->outline_markup( '<!-- wp:block {"ref":' . $synced . '} /-->' );

		$this->assertTrue( $sections[0]['locked'] );
		$this->assertSame( 'Newsletter sign-up', $sections[0]['label'] );
		$this->assertSame( array(), $sections[0]['slots'] );
	}

	/**
	 * A page from the classic editor is one locked block of text.
	 */
	public function test_classic_content(): void {
		$sections = ( new Block_Editor() )->outline_markup( '<p>Old page</p>' );

		$this->assertCount( 1, $sections );
		$this->assertTrue( $sections[0]['locked'] );
		$this->assertSame( 'Old page', $sections[0]['slots'][0]['text'] );
		$this->assertSame( array(), ( new Block_Editor() )->outline_markup( '' ) );
	}

	/**
	 * Elementor's layout becomes the same outline, keyed by element ids.
	 */
	public function test_elementor_outline(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, Elementor::EDIT_MODE_META, 'builder' );
		update_post_meta( $page, Elementor::DATA_META, wp_slash( (string) wp_json_encode( self::landing_elementor() ) ) );

		$elementor = new Elementor();
		$this->assertTrue( $elementor->owns( get_post( $page ) ) );

		$sections = $elementor->outline( get_post( $page ) );
		$this->assertSame( array( 'a1b2c3d', 'b2c3d4e', 'gl1gl1g' ), array_column( $sections, 'key' ) );

		$hero = self::slots( $sections[0] );
		$this->assertSame( 'Hero', $sections[0]['label'] );
		$this->assertSame( 12, $hero['a1b2c3d:background']['image_id'] );
		$this->assertSame( 'Launch day is here', $hero['h1h1h1h']['text'], 'Hidden characters removed.' );
		$this->assertSame( 1, $hero['h1h1h1h']['level'] );
		$this->assertSame( 'The fastest way to plan campaigns.', $hero['tx1tx1t']['text'] );
		$this->assertSame( 'https://example.org/demo', $hero['bt1bt1b']['link'] );
		$this->assertSame( array( 'spacer' ), $sections[0]['kept_as_is'] );

		$features = self::slots( $sections[1] );
		$this->assertSame( array( array( 'text' => 'Fast' ), array( 'text' => 'Friendly' ) ), $features['ic1ic1i']['items'], 'Unsafe links are dropped.' );
		$this->assertSame( 'Yes, 14 days.', $features['ac1ac1a']['items'][0]['answer'] );
		$this->assertSame( 'contact-form-7', $features['sc1sc1s']['plugin'] );
		$this->assertSame( '5', $features['sc1sc1s']['form'] );
		$this->assertSame( array( 'some-addon-widget' ), $sections[1]['kept_as_is'] );

		$this->assertTrue( $sections[2]['locked'], 'Global widgets are shared with other pages.' );
		$this->assertSame( array( 'global widget' ), $sections[2]['kept_as_is'] );
	}

	/**
	 * Broken Elementor data gives an empty outline, not an error.
	 */
	public function test_elementor_outline_with_broken_data(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, Elementor::DATA_META, '{not json' );

		$this->assertSame( array(), ( new Elementor() )->outline( get_post( $page ) ) );
	}

	/**
	 * Without Elementor, every page is a block editor page and new pages use blocks.
	 */
	public function test_builder_choice_without_elementor(): void {
		$this->skip_with_elementor();

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, Elementor::EDIT_MODE_META, 'builder' );

		$this->assertSame( array( 'blocks' ), array_keys( Builders::available() ) );
		$this->assertSame( 'blocks', Builders::for_post( get_post( $page ) )->id() );
		$this->assertSame( 'blocks', Builders::recommended()->id() );
	}

	/**
	 * Form shortcodes are recognised.
	 */
	public function test_form_shortcodes(): void {
		$this->assertSame(
			array(
				'plugin' => 'gravity-forms',
				'form'   => '3',
			),
			Form_Embeds::from_shortcode( 'Before [gravityform id="3" title="false"] after' )
		);
		$this->assertNull( Form_Embeds::from_shortcode( '[gallery ids="1,2"]' ) );
	}

	/**
	 * The design guide describes the theme; with no Elementor pages, it recommends the block editor.
	 */
	public function test_design_guide(): void {
		$result = $this->run_as( 'design-guide', null, 'editor' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'notice', $result );
		$this->assertSame( 'blocks', $result['recommended_builder'] );
		$blocks = array_column( $result['builders'], null, 'builder' )['blocks'];
		$this->assertArrayHasKey( 'colors', $blocks );
		$this->assertSame( '', $blocks['page_templates'][0]['template'] );
		$this->assertSame( array(), $result['forms'] );
		$this->assertContains( 'heading', $result['slot_kinds'] );
	}

	/**
	 * Block editor templates: reusable sections and pages, not Elementor pages or drafts.
	 */
	public function test_find_templates(): void {
		$section   = self::factory()->post->create(
			array(
				'post_type'  => 'wp_block',
				'post_title' => 'Pricing table',
			)
		);
		$page      = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Webinar landing page',
			)
		);
		$elementor = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Built with Elementor',
			)
		);
		update_post_meta( $elementor, Elementor::EDIT_MODE_META, 'builder' );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Unfinished landing page',
				'post_status' => 'draft',
			)
		);

		$result = $this->run_as( 'find-templates', array( 'builder' => 'blocks' ), 'editor' );
		$refs   = array_column( array_column( $result['templates'], 'ref' ), 'id' );

		$this->assertContains( $section, $refs );
		$this->assertContains( $page, $refs );
		$this->assertNotContains( $elementor, $refs );
		$this->assertSame( count( $result['templates'] ), $result['count'] );

		$search = $this->run_as(
			'find-templates',
			array(
				'search'  => 'webinar',
				'builder' => 'blocks',
			),
			'editor'
		);
		$this->assertSame( array( $page ), array_column( array_column( $search['templates'], 'ref' ), 'id' ) );
	}

	/**
	 * Asking for a builder the site doesn't have is a friendly error.
	 */
	public function test_find_templates_unknown_builder(): void {
		$this->skip_with_elementor();

		$this->assert_error_code( 'wpmark_invalid_input', $this->run_as( 'find-templates', array( 'builder' => 'elementor' ) ) );
	}

	/**
	 * Page structure by id, with the outline and the page template.
	 */
	public function test_page_structure(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Launch',
				'post_content' => self::LANDING_BLOCKS,
			)
		);

		$result = $this->run_as( 'page-structure', array( 'id' => $page ), 'contributor' );

		$this->assertSame( 'blocks', $result['builder'] );
		$this->assertSame( 'Launch', $result['title'] );
		$this->assertCount( 6, $result['sections'] );
		$this->assertArrayHasKey( 'notice', $result );
	}

	/**
	 * Someone else's draft, and unknown ids, give the same answer.
	 */
	public function test_page_structure_hides_unreadable(): void {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);

		$hidden  = $this->run_as( 'page-structure', array( 'id' => $draft ), 'contributor' );
		$missing = $this->run_as( 'page-structure', array( 'id' => 999999 ), 'contributor' );

		$this->assert_error_code( 'wpmark_invalid_input', $hidden );
		$this->assertSame( $missing->get_error_message(), $hidden->get_error_message() );
	}

	/**
	 * Published reusable sections are design material anyone connected may read.
	 */
	public function test_page_structure_of_reusable_section(): void {
		$section = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Ready?</h2><!-- /wp:heading -->',
			)
		);

		$result = $this->run_as( 'page-structure', array( 'id' => $section ), 'contributor' );

		$this->assertSame( 'Ready?', $result['sections'][0]['slots'][0]['text'] );
	}

	/**
	 * Block patterns by name; unknown names and empty input are friendly errors.
	 */
	public function test_page_structure_of_pattern(): void {
		register_block_pattern(
			'wpmark-test/cta',
			array(
				'title'   => 'Call to action',
				'content' => '<!-- wp:heading --><h2 class="wp-block-heading">Start today</h2><!-- /wp:heading -->',
			)
		);

		$result = $this->run_as( 'page-structure', array( 'pattern' => 'wpmark-test/cta' ) );
		unregister_block_pattern( 'wpmark-test/cta' );

		$this->assertSame( 'Start today', $result['sections'][0]['slots'][0]['text'] );
		$this->assert_error_code( 'wpmark_invalid_input', $this->run_as( 'page-structure', array( 'pattern' => 'nope/nothing' ) ) );
		$this->assert_error_code( 'wpmark_invalid_input', $this->run_as( 'page-structure', array() ) );
	}

	/**
	 * Subscribers cannot use the design tools.
	 */
	public function test_subscriber_refused(): void {
		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'design-guide', null, 'subscriber' ) );
	}
}
