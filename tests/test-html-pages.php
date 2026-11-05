<?php
/**
 * Tests for custom-designed pages (the AI's own HTML and CSS).
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Builders\Block_Editor;
use WPMark\Drafts\Draft_Content;
use WPMark\Drafts\Html_Page;
use WPMark\OAuth\Bearer_Auth;
use WPMark\Settings;

/**
 * Cleaning the HTML, limiting the CSS to the page, saving, changing and
 * showing custom-designed pages.
 */
class Test_Html_Pages extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * An image in the media library.
	 *
	 * @var int
	 */
	private int $image;

	/**
	 * Drafts on, and an image.
	 */
	public function set_up(): void {
		parent::set_up();

		Settings::save_drafts( true );
		Settings::save_custom_design( true );
		Bearer_Auth::reset();
		$this->image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		Settings::save_custom_design( false );
		Settings::save_drafts( false );
		parent::tear_down();
	}

	/**
	 * Code, form fields, outside images and frames, and other shortcodes are removed.
	 */
	public function test_html_is_cleaned(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$content = new Draft_Content();
		$library = (string) wp_get_attachment_url( $this->image );
		$html    = '<section class="hero" onclick="steal()"><h1>Launch</h1><script>alert(1)</script>'
			. '<style>.hero{padding:80px 0}</style>'
			. '<img src="' . $library . '" alt="Field"><img src="https://evil.example/x.png">'
			. '<form action="https://evil.example"><input name="email"></form>'
			. '<iframe src="https://www.youtube.com/embed/abc123"></iframe><iframe src="https://evil.example/frame"></iframe>'
			. '<a href="javascript:alert(1)">Bad</a><a class="button" href="/demo">Book a demo</a>'
			. '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M0 0h24v24H0z"/></svg>'
			. '[gallery ids="1"][wpforms id="999"]</section>';

		$prepared = Html_Page::prepare( $html, '', $content );
		$clean    = implode( '', array_column( $prepared['parts'], 'value' ) );

		$this->assertStringContainsString( '<h1>Launch</h1>', $clean );
		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringNotContainsString( 'onclick', $clean );
		$this->assertStringNotContainsString( 'steal', $clean );
		$this->assertStringNotContainsString( '<form', $clean );
		$this->assertStringNotContainsString( '<input', $clean );
		$this->assertStringNotContainsString( 'evil.example', $clean );
		$this->assertStringNotContainsString( 'javascript:', $clean );
		$this->assertStringContainsString( 'wp-image-' . $this->image, $clean, 'Library images are kept and marked for responsive sizes.' );
		$this->assertStringContainsString( 'https://www.youtube.com/embed/abc123', $clean );
		$this->assertStringContainsString( '<svg', $clean );
		$this->assertStringContainsString( '&#91;gallery', $clean, 'Other shortcodes never run.' );
		$this->assertSame( array( 'html' ), array_unique( array_column( $prepared['parts'], 'type' ) ), 'An unknown form is left out.' );
		$this->assertStringContainsString( '.wpmark-page .hero{padding:80px 0}', $prepared['css'], 'CSS in style tags joins the page CSS.' );

		$notes = implode( ' ', $content->notes() );
		$this->assertStringContainsString( 'Removed scripts', $notes );
		$this->assertStringContainsString( 'media library', $notes );
		$this->assertStringContainsString( 'YouTube and Vimeo', $notes );
		$this->assertStringContainsString( 'not one of this site\'s forms', $notes );
	}

	/**
	 * Every CSS rule is limited to the page; outside files and fonts go.
	 */
	public function test_css_is_limited_to_the_page(): void {
		$content = new Draft_Content();
		$library = (string) wp_get_attachment_url( $this->image );
		$css     = '@import url("https://fonts.example/f.css"); /* note */ :root{--brand:#123} body{margin:0} h1, .hero p{color:var(--wpmark-color-primary)}'
			. ' .wpmark-page .card{padding:2rem} @media (max-width: 768px){.grid{display:block} @supports (display:grid){.grid{display:grid}}}'
			. ' @font-face{font-family:X;src:url(https://fonts.example/x.woff2)} .bg{background:url(https://evil.example/a.png)} .ok{background-image:url(' . $library . ')}'
			. ' @keyframes fade{from{opacity:0}to{opacity:1}} .x{color:red}</style><script>alert(1)</script>';

		$clean = Html_Page::clean_css( $css, $content );

		$this->assertStringContainsString( '.wpmark-page{--brand:#123}', $clean );
		$this->assertStringContainsString( '.wpmark-page{margin:0}', $clean );
		$this->assertStringContainsString( '.wpmark-page h1,.wpmark-page .hero p{color:var(--wpmark-color-primary)}', $clean );
		$this->assertStringContainsString( '.wpmark-page .card{padding:2rem}', $clean );
		$this->assertStringContainsString( '@media (max-width: 768px){.wpmark-page .grid{display:block}@supports (display:grid){.wpmark-page .grid{display:grid}}}', $clean );
		$this->assertStringContainsString( '@keyframes fade{from{opacity:0}to{opacity:1}}', $clean );
		$this->assertStringContainsString( '.wpmark-page .ok{background-image:url(' . $library . ')}', $clean );
		$this->assertStringNotContainsString( '@import', $clean );
		$this->assertStringNotContainsString( '@font-face', $clean );
		$this->assertStringNotContainsString( 'evil.example', $clean );
		$this->assertStringNotContainsString( '<', $clean, 'It can never close its style tag.' );
		$this->assertStringNotContainsString( 'note', $clean, 'Comments are removed.' );

		$notes = implode( ' ', $content->notes() );
		$this->assertStringContainsString( '@import', $notes );
		$this->assertStringContainsString( '@font-face', $notes );
	}

	/**
	 * A custom-designed draft: saved in blocks, with its CSS, layout and template; design notes come back.
	 */
	public function test_create_and_change_html_page(): void {
		$result = $this->run_as(
			'create-draft',
			array(
				'title'  => 'Spring webinar',
				'html'   => '<section class="hero"><h2>Join us</h2><p>Live on 1 December.</p><a class="button" href="/register">Register</a></section>',
				'css'    => '.hero{padding:6rem 1rem;background:#1e3a8a;color:#fff}',
				'layout' => 'blank',
			),
			'editor'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$post = get_post( $result['id'] );

		$this->assertSame( 'draft', $post->post_status );
		$this->assertStringContainsString( '<!-- wp:group {"align":"full","className":"wpmark-page"', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
		$this->assertSame( '.wpmark-page .hero{padding:6rem 1rem;background:#1e3a8a;color:#fff}', get_post_meta( $post->ID, Html_Page::CSS_META, true ) );
		$this->assertSame( 'blank', get_post_meta( $post->ID, Html_Page::LAYOUT_META, true ) );
		$this->assertSame( 'wpmark-blank.php', get_page_template_slug( $post ), 'The blank template on classic themes.' );
		$this->assertStringContainsString( 'Join us', $result['html_page']['html'] );

		// WordPress renders it as the AI wrote it, inside the page's wrapper.
		$rendered = do_blocks( $post->post_content );
		$this->assertMatchesRegularExpression( '#^<div class="wp-block-group alignfull wpmark-page">(<div class="wp-block-group__inner-container[^"]*">)?<section class="hero"><h2>Join us</h2>#', $rendered );
		$this->assertStringContainsString( '<a class="button" href="/register">Register</a>', $rendered );

		$notes = implode( ' ', $result['notes'] );
		$this->assertStringContainsString( 'no main heading', $notes );
		$this->assertStringContainsString( '@media', $notes );
		$this->assertStringContainsString( 'its own colours', $notes );

		// New CSS alone keeps the HTML; the layout can change too.
		$updated = $this->run_as(
			'update-draft',
			array(
				'id'     => $post->ID,
				'css'    => '.hero{padding:4rem 1rem;color:var(--wpmark-color-primary)} @media (max-width:768px){.hero{padding:2rem 1rem}}',
				'layout' => 'site',
			),
			'administrator'
		);

		$this->assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		$this->assertStringContainsString( 'Join us', get_post( $post->ID )->post_content );
		$this->assertStringContainsString( 'padding:4rem', get_post_meta( $post->ID, Html_Page::CSS_META, true ) );
		$this->assertSame( 'site', get_post_meta( $post->ID, Html_Page::LAYOUT_META, true ) );
		$this->assertSame( '', get_page_template_slug( $post->ID ) );
	}

	/**
	 * Copying a custom-designed page keeps its HTML, CSS and layout.
	 */
	public function test_copy_keeps_design(): void {
		$original = $this->run_as(
			'create-draft',
			array(
				'title'  => 'Pune',
				'html'   => '<section><h1>Now in Pune</h1></section>',
				'css'    => 'h1{font-size:3rem}',
				'layout' => 'blank',
			),
			'editor'
		);
		wp_publish_post( $original['id'] );

		$copy = $this->run_as(
			'create-draft',
			array(
				'title'   => 'Mumbai',
				'copy_of' => $original['id'],
			),
			'editor'
		);

		$this->assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->assertStringContainsString( 'Now in Pune', $copy['html_page']['html'] );
		$this->assertSame( '.wpmark-page h1{font-size:3rem}', $copy['html_page']['css'] );
		$this->assertSame( 'blank', $copy['html_page']['layout'] );
		$this->assertSame( 'wpmark-blank.php', get_page_template_slug( $copy['id'] ) );
	}

	/**
	 * CSS and layout change custom-designed pages only.
	 */
	public function test_css_needs_html_page(): void {
		$draft = $this->run_as(
			'create-draft',
			array(
				'title'    => 'Plain',
				'sections' => array( array( 'compose' => array( 'items' => array( array( 'kind' => 'heading', 'text' => 'Hi' ) ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
			),
			'editor'
		);

		$this->assertNull( $draft['html_page'] );

		$refused = $this->run_as(
			'update-draft',
			array(
				'id'  => $draft['id'],
				'css' => 'h1{color:red}',
			),
			'administrator'
		);

		$this->assert_error_code( 'wpmark_invalid_input', $refused );
	}

	/**
	 * The page's head gets the site's variables, a reset and its CSS; other pages get nothing.
	 */
	public function test_css_is_printed_on_the_page(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $page, Html_Page::CSS_META, '.wpmark-page h1{color:var(--wpmark-color-primary)}' );
		update_post_meta( $page, Html_Page::LAYOUT_META, 'site' );

		$this->go_to( get_permalink( $page ) );
		ob_start();
		Html_Page::print_css();
		$css = (string) ob_get_clean();

		$this->assertStringContainsString( '<style id="wpmark-page-css">', $css );
		$this->assertStringContainsString( '.wpmark-page h1{color:var(--wpmark-color-primary)}', $css );
		$this->assertStringContainsString( 'box-sizing:border-box', $css );
		$this->assertStringContainsString( 'width:100vw', $css, 'Full width on classic themes.' );

		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
		ob_start();
		Html_Page::print_css();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Classic themes get a blank landing page template, without header or footer.
	 */
	public function test_blank_template_on_classic_themes(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $page, '_wp_page_template', 'wpmark-blank.php' );

		$this->assertArrayHasKey( 'wpmark-blank.php', Html_Page::classic_templates( array() ) );

		$this->go_to( get_permalink( $page ) );
		$this->assertSame( WPMARK_DIR . 'templates/blank-page.php', Html_Page::classic_template_file( 'page.php' ) );
		$this->assertFileExists( WPMARK_DIR . 'templates/blank-page.php' );

		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
		$this->assertSame( 'page.php', Html_Page::classic_template_file( 'page.php' ) );
	}

	/**
	 * The site's colours and fonts become variables linked to the theme.
	 */
	public function test_css_variables(): void {
		add_filter(
			'wp_theme_json_data_theme',
			static fn( $data ) => $data->update_with(
				array(
					'version'  => 3,
					'settings' => array(
						'color'      => array(
							'palette' => array(
								array(
									'slug'  => 'primary',
									'name'  => 'Primary',
									'color' => '#1e3a8a',
								),
							),
						),
						'typography' => array(
							'fontFamilies' => array(
								array(
									'slug'       => 'heading',
									'name'       => 'Heading',
									'fontFamily' => 'Inter, sans-serif',
								),
							),
						),
					),
				)
			)
		);
		wp_clean_theme_json_cache();

		$variables = Html_Page::css_variables( new Block_Editor() );

		$this->assertSame( 'var(--wp--preset--color--primary, #1e3a8a)', $variables['--wpmark-color-primary']['value'] );
		$this->assertSame( 'var(--wp--preset--font-family--heading, Inter, sans-serif)', $variables['--wpmark-font-heading']['value'] );

		$guide  = $this->run_as( 'design-guide', null, 'editor' );
		$blocks = array_column( $guide['builders'], null, 'builder' )['blocks'];
		$this->assertArrayHasKey( '--wpmark-color-primary', $blocks['css_variables'] );
		$this->assertStringContainsString( 'custom design', $guide['how_to_build'] );

		wp_clean_theme_json_cache();
	}
}
