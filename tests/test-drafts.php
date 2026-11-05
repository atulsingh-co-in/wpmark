<?php
/**
 * Tests for creating and updating drafts.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Drafts\Drafts;
use WPMark\Mcp_Server;
use WPMark\OAuth\Bearer_Auth;
use WPMark\OAuth\OAuth;
use WPMark\OAuth\Store;
use WPMark\Settings;

/**
 * The drafts tools with the block editor, the permissions around them,
 * and the rules that keep them safe.
 */
class Test_Drafts extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * A landing page to copy from: a hero (cover with heading, text and a
	 * button), a features list and an FAQ.
	 */
	private const TEMPLATE = <<<'HTML'
<!-- wp:cover {"url":"https://example.org/old.jpg","id":1,"metadata":{"name":"Hero"}} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-1" alt="" src="https://example.org/old.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Old launch</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Old text.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.org/old">Old button</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Old one</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:details -->
<details class="wp-block-details"><summary>Old question?</summary><!-- wp:paragraph -->
<p>Old answer.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->
HTML;

	/**
	 * The template page.
	 *
	 * @var int
	 */
	private int $template;

	/**
	 * An image in the media library.
	 *
	 * @var int
	 */
	private int $image;

	/**
	 * Drafts on, a template page and an image.
	 */
	public function set_up(): void {
		parent::set_up();

		Settings::save_drafts( true );
		Bearer_Auth::reset();

		$this->template = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Old launch',
				'post_content' => self::TEMPLATE,
			)
		);
		$this->image    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $this->image, '_wp_attachment_image_alt', 'A field of flowers' );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		Bearer_Auth::reset();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	/**
	 * A draft copied from the template, with new content in every slot.
	 *
	 * @param array $extra More input.
	 * @return array|\WP_Error
	 */
	private function create_from_template( array $extra = array() ) {
		return $this->run_as(
			'create-draft',
			array_merge(
				array(
					'title'    => 'Spring launch',
					'sections' => array(
						array(
							'from'  => array(
								'id'      => $this->template,
								'section' => '0',
							),
							'fills' => array(
								'0:background' => array( 'image_id' => $this->image ),
								'0.0'          => 'Spring is <strong>here</strong><script>alert(1)</script>',
								'0.1'          => array( 'text' => 'Plan every campaign in one place.' ),
								'0.2.0'        => array(
									'text' => 'Book a demo',
									'link' => '/demo',
								),
							),
						),
						array(
							'from'  => array(
								'id'      => $this->template,
								'section' => '1',
							),
							'fills' => array(
								'1' => array( 'items' => array( 'Fast', array( 'text' => 'Friendly' ) ) ),
							),
						),
						array(
							'from'  => array(
								'id'      => $this->template,
								'section' => '2',
							),
							'fills' => array(
								'2' => array(
									'items' => array(
										array(
											'question' => 'Is there a trial?',
											'answer'   => 'Yes, 14 days.',
										),
									),
								),
							),
						),
					),
				),
				$extra
			),
			'editor'
		);
	}

	/**
	 * Copying a template keeps its design and puts the new content in its slots.
	 */
	public function test_create_from_template(): void {
		$result = $this->create_from_template();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$post = get_post( $result['id'] );

		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'Spring launch', $post->post_title );
		$this->assertSame( 'blocks', $result['builder'] );
		$this->assertStringContainsString( 'preview=true', $result['preview_url'] );

		$content = $post->post_content;
		$this->assertStringContainsString( '<h1 class="wp-block-heading">Spring is <strong>here</strong>alert(1)</h1>', $content, 'Scripts are removed.' );
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringContainsString( '<p>Plan every campaign in one place.</p>', $content );
		$this->assertStringContainsString( 'href="' . home_url( '/demo' ) . '">Book a demo</a>', $content );
		$this->assertStringContainsString( 'wp-image-' . $this->image, $content );
		$this->assertStringContainsString( '"id":' . $this->image, $content );
		$this->assertStringNotContainsString( 'old.jpg', $content );
		$this->assertStringContainsString( '<li>Friendly</li>', $content );
		$this->assertStringContainsString( '<summary>Is there a trial?</summary>', $content );
		$this->assertStringNotContainsString( 'Old', $content );

		// It reads back as the same structure with the new content.
		$hero = array_column( $result['sections'][0]['slots'], null, 'key' );
		$this->assertSame( 'Spring is here alert(1)', str_replace( 'herealert', 'here alert', $hero['0.0']['text'] ) );
		$this->assertSame( array(), $result['notes'] );

		$record = get_post_meta( $post->ID, Drafts::META, true );
		$this->assertSame( 'blocks', $record['builder'] );
		$this->assertSame( get_current_user_id(), $record['user'] );
	}

	/**
	 * Every block written is valid block markup: it parses back to the same blocks.
	 */
	public function test_markup_round_trips(): void {
		$result = $this->run_as(
			'create-draft',
			array(
				'title'    => 'Composed',
				'sections' => array(
					array(
						'compose' => array(
							'columns' => array(
								array(
									array(
										'kind'  => 'heading',
										'text'  => 'Why us',
										'level' => 2,
									),
									array(
										'kind' => 'text',
										'text' => "Line one\nLine two",
									),
									array(
										'kind' => 'button',
										'text' => 'Start',
										'link' => 'https://example.org/start',
									),
									array(
										'kind' => 'button',
										'text' => 'Learn more',
									),
								),
								array(
									array(
										'kind'     => 'image',
										'image_id' => $this->image,
									),
									array(
										'kind'  => 'list',
										'items' => array( array( 'text' => 'Fast' ) ),
									),
								),
							),
						),
					),
					array(
						'compose' => array(
							'items' => array(
								array(
									'kind'  => 'faq',
									'items' => array(
										array(
											'question' => 'Q1?',
											'answer'   => 'A1.',
										),
										array(
											'question' => 'Q2?',
											'answer'   => 'A2.',
										),
									),
								),
								array(
									'kind'  => 'testimonial',
									'quote' => 'It works.',
									'name'  => 'Asha',
									'role'  => 'CMO',
								),
								array(
									'kind' => 'video',
									'url'  => 'https://www.youtube.com/watch?v=abc',
								),
								array( 'kind' => 'sparkles' ),
							),
						),
					),
				),
			),
			'editor'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$content = get_post( $result['id'] )->post_content;

		$this->assertSame( $content, serialize_blocks( parse_blocks( $content ) ), 'Round trip.' );
		$this->assertStringContainsString( '<!-- wp:columns -->', $content );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Why us</h2>', $content );
		$this->assertSame( 1, substr_count( $content, '<!-- wp:buttons -->' ), 'Neighbouring buttons share a row.' );
		$this->assertStringContainsString( '<a class="wp-block-button__link wp-element-button">Learn more</a>', $content );
		$this->assertStringContainsString( '<figure class="wp-block-image size-large"><img src="', $content );
		$this->assertStringContainsString( 'alt="A field of flowers" class="wp-image-' . $this->image . '"/></figure>', $content );
		$this->assertSame( 2, substr_count( $content, '<!-- wp:details -->' ) );
		$this->assertStringContainsString( '<cite>Asha, CMO</cite></blockquote>', $content );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc"} -->', $content );
		$this->assertStringContainsString( 'sparkles', implode( ' ', $result['notes'] ) );
	}

	/**
	 * Block markup from the AI: custom HTML, scripts, other shortcodes and outside images go.
	 */
	public function test_native_blocks_are_cleaned(): void {
		$markup = '<!-- wp:heading --><h2 class="wp-block-heading" onclick="steal()">Hello</h2><!-- /wp:heading -->'
			. '<!-- wp:html --><script>steal()</script><!-- /wp:html -->'
			. '<!-- wp:shortcode -->[some_plugin_shortcode]<!-- /wp:shortcode -->'
			. '<!-- wp:image {"id":999999} --><figure class="wp-block-image"><img src="https://evil.example/x.png" alt=""/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';

		$result = $this->run_as(
			'create-draft',
			array(
				'title'    => 'Native',
				'sections' => array( array( 'blocks' => $markup ) ),
			),
			'administrator'
		);

		$content = get_post( $result['id'] )->post_content;

		$this->assertStringContainsString( 'Hello</h2>', $content );
		$this->assertStringContainsString( '<p>Kept</p>', $content );
		$this->assertStringNotContainsString( 'onclick', $content, 'Even for administrators.' );
		$this->assertStringNotContainsString( 'script', $content );
		$this->assertStringNotContainsString( 'some_plugin_shortcode', $content );
		$this->assertStringNotContainsString( 'evil.example', $content );
		$this->assertCount( 4, $result['notes'] );
	}

	/**
	 * Unknown slot keys and links that are not web links are reported, not fatal.
	 */
	public function test_notes_for_unused_slots_and_bad_links(): void {
		$result = $this->run_as(
			'create-draft',
			array(
				'title'    => 'Notes',
				'sections' => array(
					array(
						'from'  => array(
							'id'      => $this->template,
							'section' => '0',
						),
						'fills' => array(
							'0.2.0' => array(
								'text' => 'Go',
								'link' => 'javascript:alert(1)',
							),
							'9.9'   => 'Nowhere',
						),
					),
				),
			),
			'editor'
		);

		$notes = implode( ' ', $result['notes'] );
		$this->assertStringContainsString( '9.9', $notes );
		$this->assertStringContainsString( 'javascript:alert(1)', $notes );
		$this->assertStringNotContainsString( 'javascript:', get_post( $result['id'] )->post_content );
	}

	/**
	 * Changing slots of a draft keeps the rest and adds a revision.
	 */
	public function test_update_fills(): void {
		$created = $this->create_from_template();

		$updated = $this->run_as(
			'update-draft',
			array(
				'id'    => $created['id'],
				'title' => 'Spring launch 2',
				'fills' => array( '0.0' => array( 'text' => 'Summer is here' ) ),
			),
			'editor'
		);

		$post = get_post( $created['id'] );
		$this->assertSame( 'Spring launch 2', $post->post_title );
		$this->assertStringContainsString( 'Summer is here', $post->post_content );
		$this->assertStringContainsString( 'Plan every campaign in one place.', $post->post_content, 'Other slots stay.' );
		$this->assertSame( 'draft', $updated['status'] );
		$this->assertNotEmpty( wp_get_post_revisions( $post->ID ) );
	}

	/**
	 * A whole page can be copied into a new draft with some slots changed;
	 * the original stays as it is.
	 */
	public function test_copy_whole_page(): void {
		wp_publish_post( $this->template );
		set_post_thumbnail( $this->template, $this->image );
		$original = get_post( $this->template )->post_content;
		$saved    = array();

		add_action(
			'wpmark_draft_saved',
			static function ( int $id, $existing, array $input, $source ) use ( &$saved ): void {
				$saved = array( $id, $existing, $source ? $source->ID : 0 );
			},
			10,
			4
		);

		$result = $this->run_as(
			'create-draft',
			array(
				'title'   => 'Launch in Pune',
				'copy_of' => $this->template,
				'fills'   => array( '0.0' => 'Now in Pune' ),
			),
			'editor'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$copy = get_post( $result['id'] );

		$this->assertSame( 'draft', $copy->post_status );
		$this->assertSame( 'page', $copy->post_type );
		$this->assertStringContainsString( 'Now in Pune', $copy->post_content );
		$this->assertStringContainsString( 'Old answer.', $copy->post_content, 'Slots not filled are copied as they are.' );
		$this->assertSame( $this->image, (int) get_post_thumbnail_id( $copy ) );
		$this->assertSame( $original, get_post( $this->template )->post_content, 'The original is not changed.' );
		$this->assertSame( $this->template, get_post_meta( $copy->ID, Drafts::META, true )['copy_of'] );
		$this->assertSame( array( $copy->ID, null, $this->template ), $saved );

		// A page the person cannot read cannot be copied.
		$private = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_author' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			)
		);
		$this->allow_drafts( 'author' );
		$refused = $this->run_as(
			'create-draft',
			array(
				'title'   => 'Copy',
				'copy_of' => $private,
			),
			'author'
		);
		$this->assert_error_code( 'wpmark_invalid_input', $refused );
	}

	/**
	 * Published pages and pages a person made are never changed.
	 */
	public function test_update_refuses_published_and_human_pages(): void {
		$created = $this->create_from_template();
		wp_publish_post( $created['id'] );

		$published = $this->run_as(
			'update-draft',
			array(
				'id'    => $created['id'],
				'title' => 'Changed',
			),
			'administrator'
		);
		$human     = $this->run_as(
			'update-draft',
			array(
				'id'    => self::factory()->post->create(
					array(
						'post_type'   => 'page',
						'post_status' => 'draft',
					)
				),
				'title' => 'Changed',
			),
			'administrator'
		);

		$this->assert_error_code( 'wpmark_invalid_input', $published );
		$this->assertStringContainsString( 'no longer a draft', $published->get_error_message() );
		$this->assert_error_code( 'wpmark_invalid_input', $human );
		$this->assertNotSame( 'Changed', get_the_title( $created['id'] ) );
	}

	/**
	 * Nothing is ever published, even when the AI asks.
	 */
	public function test_never_publishes(): void {
		$result = $this->create_from_template( array( 'status' => 'publish' ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'Unknown input is refused by the schema.' );

		$created = $this->create_from_template();
		$this->assertSame( 'draft', get_post_status( $created['id'] ) );
	}

	/**
	 * With drafts off, the tools are not offered and refuse to run.
	 */
	public function test_drafts_switched_off(): void {
		Settings::save_drafts( false );

		$this->assertNotContains( 'wpmark/create-draft', Mcp_Server::tool_names() );
		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'create-draft', array( 'title' => 'x', 'sections' => array( array( 'compose' => array() ) ) ), 'administrator' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.

		Settings::save_drafts( true );
		$this->assertContains( 'wpmark/create-draft', Mcp_Server::tool_names() );
	}

	/**
	 * Roles without "Can create drafts" are refused; contributors cannot create pages.
	 */
	public function test_role_permissions(): void {
		$this->assert_error_code( 'ability_invalid_permissions', $this->refused_as( 'list-drafts', array(), 'author' ) );

		$this->allow_drafts( 'contributor' );

		$page = $this->run_as(
			'create-draft',
			array(
				'title'    => 'Page',
				'sections' => array( array( 'compose' => array( 'items' => array( array( 'kind' => 'text', 'text' => 'Hi' ) ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
			),
			'contributor'
		);
		$post = $this->run_as(
			'create-draft',
			array(
				'type'     => 'post',
				'title'    => 'Post',
				'sections' => array( array( 'compose' => array( 'items' => array( array( 'kind' => 'text', 'text' => 'Hi' ) ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
			),
			'contributor'
		);

		$this->assert_error_code( 'wpmark_invalid_input', $page );
		$this->assertSame( 'draft', get_post_status( $post['id'] ) );
	}

	/**
	 * An app that signed in without the drafts box ticked cannot create drafts.
	 */
	public function test_oauth_scope_required(): void {
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		Store::install();

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$fields = array(
			'client_id' => 'wpmark_test_claude',
			'user_id'   => $editor,
			'family'    => wp_generate_password( 32, false ),
			'scope'     => OAuth::SCOPE,
		);
		Store::add_client(
			array(
				'client_id'     => 'wpmark_test_claude',
				'client_name'   => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/cb' ),
				'auth_method'   => 'none',
				'secret_hash'   => null,
			)
		);

		$_SERVER['REQUEST_URI']        = '/wp-json/wpmark/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Store::issue( 'access', $fields, HOUR_IN_SECONDS );
		wp_set_current_user( (int) Bearer_Auth::authenticate( false ) );

		$ability = wp_get_ability( 'wpmark/list-drafts' );
		$denied  = $ability->check_permissions( array() );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'wpmark_drafts_not_granted', $denied->get_error_code() );

		Bearer_Auth::reset();
		$fields['scope']               = OAuth::SCOPE . ' ' . OAuth::DRAFTS_SCOPE;
		$fields['family']              = wp_generate_password( 32, false );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Store::issue( 'access', $fields, HOUR_IN_SECONDS );
		wp_set_current_user( (int) Bearer_Auth::authenticate( false ) );

		$this->assertTrue( $ability->check_permissions( array() ) );
		$this->assertSame( 'Claude', Bearer_Auth::app_name() );
	}

	/**
	 * Too many drafts in an hour are refused.
	 */
	public function test_rate_limit(): void {
		add_filter( 'wpmark_drafts_per_hour', static fn(): int => 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$input = array(
			'title'    => 'One',
			'sections' => array( array( 'compose' => array( 'items' => array( array( 'kind' => 'text', 'text' => 'Hi' ) ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short test data.
		);

		$this->assertIsArray( wp_get_ability( 'wpmark/create-draft' )->execute( $input ) );
		$this->assert_error_code( 'wpmark_invalid_input', wp_get_ability( 'wpmark/create-draft' )->execute( $input ) );
	}

	/**
	 * Copying from a page the person cannot read is refused like a missing one.
	 */
	public function test_unreadable_source(): void {
		$this->allow_drafts( 'author' );

		$private = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_author'  => self::factory()->user->create( array( 'role' => 'administrator' ) ),
				'post_content' => self::TEMPLATE,
			)
		);
		$input   = static fn( int $id ): array => array(
			'type'     => 'post',
			'title'    => 'Copy',
			'sections' => array(
				array(
					'from' => array(
						'id'      => $id,
						'section' => '0',
					),
				),
			),
		);

		$hidden  = $this->run_as( 'create-draft', $input( $private ), 'author' );
		$missing = $this->run_as( 'create-draft', $input( 999999 ), 'author' );

		$this->assert_error_code( 'wpmark_invalid_input', $hidden );
		$this->assertSame( $missing->get_error_message(), $hidden->get_error_message() );
		$this->assertIsArray( $this->run_as( 'create-draft', $input( $this->template ), 'author' ), 'Published pages can be copied.' );
	}

	/**
	 * Tick "Can create drafts" for a role.
	 *
	 * @param string $slug Role.
	 */
	private function allow_drafts( string $slug ): void {
		$choices = array_map(
			static fn( array $role ): array => array(
				'enabled' => $role['enabled'],
				'leads'   => $role['leads']->value,
				'drafts'  => $role['drafts'],
			),
			Settings::roles()
		);

		$choices[ $slug ]['drafts'] = true;
		Settings::save_roles( $choices );
	}

	/**
	 * The drafts list shows WPMark drafts with their status; the media list shows images.
	 */
	public function test_lists(): void {
		$created = $this->create_from_template();
		wp_set_current_user( get_post( $created['id'] )->post_author );

		$drafts = wp_get_ability( 'wpmark/list-drafts' )->execute( array() );
		$this->assertSame( $created['id'], $drafts['drafts'][0]['id'] );
		$this->assertTrue( $drafts['drafts'][0]['can_update'] );

		$media = $this->run_as( 'list-media', array( 'search' => 'canola' ), 'author' );
		$this->assertSame( $this->image, $media['images'][0]['id'] );
		$this->assertSame( 'A field of flowers', $media['images'][0]['alt'] );
	}

	/**
	 * WPMark drafts are labelled in the Pages list.
	 */
	public function test_post_state_label(): void {
		$created = $this->create_from_template();

		$states = Drafts::post_states( array(), get_post( $created['id'] ) );

		$this->assertArrayHasKey( 'wpmark_draft', $states );
		$this->assertSame( array(), Drafts::post_states( array(), get_post( $this->template ) ) );
	}
}
