<?php
/**
 * Agent readiness tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use WPMark\Admin\Connection_Doctor;
use WPMark\Agents\Agent_Readiness;
use WPMark\Agents\Robots;

defined( 'ABSPATH' ) || exit;

/**
 * Whether AI search and AI agents can find, read and use the site.
 *
 * Marketing job 4 (audit SEO): being found in AI answers is the new SEO.
 */
final class Agent_Readiness_Check extends Ability {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'agent-readiness';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Check AI visibility', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Checks whether ChatGPT, Claude, Perplexity and other AI tools can find, read and use your site, and what to fix.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Checks whether AI search engines and AI assistants can find, read and use this website, and says what to fix. Use it when someone asks "can ChatGPT see our site?", "why doesn\'t AI mention us?" or "is our site ready for AI agents?". It checks: the WordPress setting that hides a site from search engines; which AI crawlers robots.txt blocks (search and assistant crawlers such as OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User and PerplexityBot, which decide whether the site appears in AI answers, versus training crawlers such as GPTBot and ClaudeBot, which are a business choice); whether a firewall or CDN such as Cloudflare turns AI assistants away (by test requests from the site to itself); structured data (schema.org) on the home page; the sitemap; an llms.txt summary; and whether forms and search are described for AI agents in browsers (WebMCP, experimental). Each check has a status (good, warning, problem, info), what was found and, when needed, the fix in plain language. Present problems first, explain that blocking training crawlers is a choice, and never change anything. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 */
	protected function run( array $input ): array {
		unset( $input );

		$checks = Agent_Readiness::checks();
		$robots = new Robots( Agent_Readiness::robots_text() );
		$counts = array_count_values( array_column( $checks, 'status' ) );

		$crawlers = array();
		foreach ( Robots::AGENTS as $agent => $about ) {
			$crawlers[] = array(
				'crawler' => $agent,
				'company' => $about[0],
				'purpose' => $about[1],
				'allowed' => $robots->allows( $agent ),
			);
		}

		return $this->respond(
			array(
				'summary'  => array(
					'problems' => $counts[ Connection_Doctor::PROBLEM ] ?? 0,
					'warnings' => $counts[ Connection_Doctor::WARNING ] ?? 0,
					'good'     => $counts[ Connection_Doctor::GOOD ] ?? 0,
				),
				'checks'   => $checks,
				'crawlers' => $crawlers,
			)
		);
	}
}
