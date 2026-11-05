<?php
/**
 * Tests for the agent-ready features.
 *
 * @package WPMark
 */

namespace WPMark\Tests;

use WP_UnitTestCase;
use WPMark\Admin\Connection_Doctor;
use WPMark\Agents\Agent_Readiness;
use WPMark\Agents\Llms_Txt;
use WPMark\Agents\Robots;
use WPMark\Agents\WebMCP;
use WPMark\Settings;

/**
 * Reading robots.txt, the readiness checks, llms.txt and WebMCP.
 */
class Test_Agents extends WP_UnitTestCase {

	use Runs_Abilities;

	/**
	 * Fake responses for requests from the site to itself, by path and agent.
	 *
	 * @var callable|null
	 */
	private $responder = null;

	/**
	 * Answer self-requests from $this->responder.
	 */
	public function set_up(): void {
		parent::set_up();

		WebMCP::reset();
		Llms_Txt::clear();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				return $this->responder ? ( $this->responder )( (string) wp_parse_url( $url, PHP_URL_PATH ), (string) ( $args['user-agent'] ?? '' ) ) : new \WP_Error( 'offline', 'No network in tests' );
			},
			10,
			3
		);
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int    $code    Status.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @return array
	 */
	private static function response( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The rules in robots.txt are read the way crawlers read them.
	 */
	public function test_robots_rules(): void {
		$robots = new Robots(
			"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\n" .
			"User-agent: GPTBot\nUser-agent: ClaudeBot # training\nDisallow: /\n\n" .
			"User-agent: PerplexityBot\nDisallow: /\nAllow: /$\n\n" .
			"User-agent: CCBot\nDisallow:\n\n" .
			"Sitemap: https://example.org/sitemap.xml\n"
		);

		$this->assertTrue( $robots->allows( 'OAI-SearchBot', '/' ), 'No group of its own: follows "*".' );
		$this->assertFalse( $robots->allows( 'OAI-SearchBot', '/wp-admin/options.php' ) );
		$this->assertTrue( $robots->allows( 'OAI-SearchBot', '/wp-admin/admin-ajax.php' ), 'The longer Allow wins.' );
		$this->assertFalse( $robots->allows( 'gptbot', '/' ), 'Names are not case-sensitive.' );
		$this->assertFalse( $robots->allows( 'ClaudeBot', '/pricing' ), 'Consecutive User-agent lines share a group.' );
		$this->assertTrue( $robots->allows( 'PerplexityBot', '/' ), '"$" anchors the end.' );
		$this->assertFalse( $robots->allows( 'PerplexityBot', '/pricing' ) );
		$this->assertTrue( $robots->allows( 'CCBot', '/anything' ), 'An empty Disallow allows everything.' );
		$this->assertTrue( ( new Robots( '' ) )->allows( 'GPTBot' ) );
	}

	/**
	 * Wildcards in paths.
	 */
	public function test_robots_wildcards(): void {
		$robots = new Robots( "User-agent: *\nDisallow: /*.pdf$\nDisallow: /private*/\n" );

		$this->assertFalse( $robots->allows( 'Claude-User', '/files/brochure.pdf' ) );
		$this->assertTrue( $robots->allows( 'Claude-User', '/files/brochure.pdf?x=1' ) );
		$this->assertFalse( $robots->allows( 'Claude-User', '/private-area/page' ) );
	}

	/**
	 * Blocking AI search is a problem; blocking only training is a choice.
	 *
	 * @dataProvider robots_cases
	 *
	 * @param string $robots  robots.txt.
	 * @param string $status  Expected status.
	 * @param string $message Text expected in the message or fix.
	 */
	public function test_crawlers_check( string $robots, string $status, string $message ): void {
		$this->responder = static fn( string $path ): array => '/robots.txt' === $path ? self::response( 200, $robots ) : self::response( 404 );

		$check = Agent_Readiness::crawlers();

		$this->assertSame( $status, $check['status'] );
		$this->assertStringContainsString( $message, $check['message'] . ' ' . $check['fix'] );
	}

	/**
	 * Cases for the robots.txt check.
	 *
	 * @return array
	 */
	public function robots_cases(): array {
		return array(
			'open'               => array( "User-agent: *\nDisallow:\n", Connection_Doctor::GOOD, 'every major AI crawler' ),
			'training only'      => array( "User-agent: GPTBot\nDisallow: /\n", Connection_Doctor::GOOD, 'business choice' ),
			'search blocked'     => array( "User-agent: OAI-SearchBot\nDisallow: /\n", Connection_Doctor::PROBLEM, 'OAI-SearchBot' ),
			'everything blocked' => array( "User-agent: *\nDisallow: /\n", Connection_Doctor::PROBLEM, 'Claude-User' ),
			'cloudflare managed' => array( "# BEGIN Cloudflare Managed content\nUser-agent: ClaudeBot\nDisallow: /\nUser-agent: Claude-User\nDisallow: /\n", Connection_Doctor::PROBLEM, 'Cloudflare dashboard' ),
		);
	}

	/**
	 * Without network, the check reads what WordPress itself would serve.
	 */
	public function test_crawlers_check_offline_and_discouraged(): void {
		$this->assertSame( Connection_Doctor::GOOD, Agent_Readiness::crawlers( false )['status'] );

		update_option( 'blog_public', '0' );

		$this->assertSame( Connection_Doctor::PROBLEM, Agent_Readiness::search_engines()['status'] );
		$this->assertSame( Connection_Doctor::PROBLEM, Agent_Readiness::crawlers( false )['status'] );
	}

	/**
	 * A firewall that turns AI assistants away is found, and Cloudflare named.
	 */
	public function test_firewall_check(): void {
		$this->responder = static fn( string $path, string $agent ): array => str_contains( $agent, 'Claude-User' ) ? self::response( 403, 'Forbidden', array( 'cf-ray' => 'abc' ) ) : self::response( 200, '<html></html>', array( 'cf-ray' => 'abc' ) );

		$check = Agent_Readiness::firewall();

		$this->assertSame( Connection_Doctor::PROBLEM, $check['status'] );
		$this->assertStringContainsString( 'Claude-User', $check['message'] );
		$this->assertStringContainsString( 'Cloudflare', $check['fix'] );

		$this->responder = static fn(): array => self::response( 200, '<html></html>' );
		$this->assertSame( Connection_Doctor::GOOD, Agent_Readiness::firewall()['status'] );

		$this->responder = null;
		$this->assertSame( Connection_Doctor::INFO, Agent_Readiness::firewall()['status'], 'No answer: cannot tell.' );
	}

	/**
	 * Structured data on the home page.
	 */
	public function test_structured_data_check(): void {
		$this->responder = static fn(): array => self::response( 200, '<script type="application/ld+json">{}</script>' );
		$this->assertSame( Connection_Doctor::GOOD, Agent_Readiness::structured_data()['status'] );

		$this->responder = static fn(): array => self::response( 200, '<html></html>' );
		$this->assertSame( Connection_Doctor::WARNING, Agent_Readiness::structured_data()['status'] );
	}

	/**
	 * The llms.txt summary lists public pages and posts, never drafts, protected or hidden ones.
	 */
	public function test_llms_txt_content(): void {
		// Filters, not update_option(): Elementor, when loaded, reacts to a new site name.
		add_filter( 'option_blogname', static fn(): string => 'Acme Marketing' );
		add_filter( 'option_blogdescription', static fn(): string => 'Campaigns that work' );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Pricing [plans]',
				'post_content' => 'Three plans for teams of every size.',
				'post_excerpt' => '',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Launch checklist',
				'post_content' => 'Ten steps.',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Secret draft',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Members only',
				'post_password' => 'x',
			)
		);

		$text = Llms_Txt::build();

		$this->assertStringStartsWith( "# Acme Marketing\n\n> Campaigns that work\n", $text );
		$this->assertStringContainsString( '- [Pricing plans](' . get_permalink( $page ) . '): Three plans for teams of every size.', $text );
		$this->assertStringContainsString( '## Latest posts', $text );
		$this->assertStringContainsString( 'Launch checklist', $text );
		$this->assertStringNotContainsString( 'Secret draft', $text );
		$this->assertStringNotContainsString( 'Members only', $text );
	}

	/**
	 * Served only when switched on, and the readiness check reports it.
	 */
	public function test_llms_txt_switch(): void {
		$this->assertFalse( Llms_Txt::is_served() );
		$this->assertSame( Connection_Doctor::INFO, Agent_Readiness::llms_txt( false )['status'] );

		Settings::save_agent_features( true, false );

		$this->assertTrue( Llms_Txt::is_served() );
		$this->assertSame( Connection_Doctor::GOOD, Agent_Readiness::llms_txt( false )['status'] );
	}

	/**
	 * The search form and contact forms get WebMCP labels; nothing submits itself.
	 */
	public function test_webmcp_forms(): void {
		add_filter( 'option_blogname', static fn(): string => 'Acme' );

		$search = WebMCP::search_form( '<form role="search" method="get" action="/"><label>Search<input type="search" name="s"></label><button type="submit">Go</button></form>' );
		$this->assertStringContainsString( 'toolname="search-site"', $search );
		$this->assertStringContainsString( 'toolparamdescription="Words to search for."', $search );
		$this->assertStringNotContainsString( 'toolautosubmit', $search );

		$form = WebMCP::shortcode_form(
			'<div><form action="/contact" method="post"><input type="hidden" name="_wpcf7" value="5"><input type="text" name="your-name" aria-required="true"><input type="email" name="your-email" placeholder="Work email" required><input type="text" name="trap" tabindex="-1"><textarea name="your-message"></textarea><input type="submit" value="Send"></form></div>',
			'contact-form-7',
			array( 'title' => 'Book a demo' )
		);

		$this->assertStringContainsString( 'toolname="send-message-book-a-demo"', $form );
		$this->assertStringContainsString( 'Send a message to Acme with its &quot;Book a demo&quot; form.', $form );
		$this->assertMatchesRegularExpression( '/<input toolparamdescription="Your name \(required\)"[^>]*name="your-name"/', $form );
		$this->assertStringContainsString( 'toolparamdescription="Work email (required)"', $form );
		$this->assertStringContainsString( '<textarea toolparamdescription="Your message" name="your-message">', $form );
		$this->assertSame( 3, substr_count( $form, 'toolparamdescription' ), 'Hidden fields, spam traps and buttons are left alone.' );
		$this->assertStringNotContainsString( 'toolautosubmit', $form );

		$second = WebMCP::shortcode_form( '<form><input name="x"></form>', 'contact-form-7', array( 'title' => 'Book a demo' ) );
		$this->assertStringContainsString( 'toolname="send-message-book-a-demo-2"', $second, 'Names are unique on a page.' );

		$this->assertSame( '<p>gallery</p>', WebMCP::shortcode_form( '<p>gallery</p>', 'gallery', array() ) );
	}

	/**
	 * The front-end filters are only added when WebMCP is switched on.
	 */
	public function test_webmcp_switch(): void {
		remove_all_filters( 'get_search_form' );
		WebMCP::register();
		$this->assertFalse( has_filter( 'get_search_form', array( WebMCP::class, 'search_form' ) ) );

		Settings::save_agent_features( false, true );
		WebMCP::register();
		$this->assertSame( 20, has_filter( 'get_search_form', array( WebMCP::class, 'search_form' ) ) );
	}

	/**
	 * The AI visibility tool returns the checks and each crawler's access.
	 */
	public function test_tool(): void {
		$this->responder = static fn( string $path ): array => '/robots.txt' === $path ? self::response( 200, "User-agent: GPTBot\nDisallow: /\n" ) : self::response( 200, '<html></html>' );

		$result   = $this->run_as( 'agent-readiness', null, 'editor' );
		$crawlers = array_column( $result['crawlers'], null, 'crawler' );

		$this->assertFalse( $crawlers['GPTBot']['allowed'] );
		$this->assertSame( 'training', $crawlers['GPTBot']['purpose'] );
		$this->assertTrue( $crawlers['Claude-User']['allowed'] );
		$this->assertContains( 'ai_firewall', array_column( $result['checks'], 'id' ) );
		$this->assertSame( 0, $result['summary']['problems'] );
	}
}
