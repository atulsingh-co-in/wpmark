<?php
/**
 * Reading robots.txt.
 *
 * @package WPMark
 */

namespace WPMark\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a robots.txt the way search engines and AI crawlers do (RFC 9309),
 * to answer one question: may this crawler read the site?
 *
 * A crawler follows the group of rules whose User-agent line names it,
 * or the "*" group when none does. Within a group, the longest matching
 * Allow or Disallow rule wins; on a tie, Allow wins.
 */
final class Robots {

	/**
	 * The AI crawlers and agents that matter for visibility, by company.
	 *
	 * "search" crawlers fetch pages so AI answers can quote and link to
	 * them, and "user" agents fetch a page when someone asks an AI about
	 * it: blocking these hides the site from AI answers. "training"
	 * crawlers collect text to train future models: blocking them is a
	 * business choice with no effect on being found today.
	 */
	public const AGENTS = array(
		'OAI-SearchBot'      => array( 'OpenAI (ChatGPT search)', 'search' ),
		'ChatGPT-User'       => array( 'OpenAI (ChatGPT, when a person asks)', 'user' ),
		'GPTBot'             => array( 'OpenAI (training)', 'training' ),
		'Claude-SearchBot'   => array( 'Anthropic (Claude search)', 'search' ),
		'Claude-User'        => array( 'Anthropic (Claude, when a person asks)', 'user' ),
		'ClaudeBot'          => array( 'Anthropic (training)', 'training' ),
		'PerplexityBot'      => array( 'Perplexity (search)', 'search' ),
		'Perplexity-User'    => array( 'Perplexity (when a person asks)', 'user' ),
		'Google-Extended'    => array( 'Google (Gemini training and grounding)', 'training' ),
		'Applebot-Extended'  => array( 'Apple (Apple Intelligence training)', 'training' ),
		'meta-externalagent' => array( 'Meta (training)', 'training' ),
		'CCBot'              => array( 'Common Crawl (used to train many models)', 'training' ),
	);

	/**
	 * Groups of rules, each with its user agents.
	 *
	 * @var array<int, array{agents: string[], rules: array<int, array{allow: bool, path: string}>}>
	 */
	private array $groups = array();

	/**
	 * Parse a robots.txt.
	 *
	 * @param string $text The file's contents.
	 */
	public function __construct( string $text ) {
		$current = null;

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );

			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'user-agent' === $field ) {
				// Consecutive User-agent lines share one group.
				if ( null === $current || $this->groups[ $current ]['rules'] ) {
					$this->groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current        = count( $this->groups ) - 1;
				}

				$this->groups[ $current ]['agents'][] = strtolower( $value );
			} elseif ( null !== $current && in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				// An empty Disallow allows everything: it adds no rule.
				if ( '' !== $value ) {
					$this->groups[ $current ]['rules'][] = array(
						'allow' => 'allow' === $field,
						'path'  => $value,
					);
				}
			}
		}
	}

	/**
	 * Whether a crawler may fetch a path.
	 *
	 * @param string $agent Crawler token, e.g. "GPTBot".
	 * @param string $path  Path, e.g. "/".
	 * @return bool
	 */
	public function allows( string $agent, string $path = '/' ): bool {
		$rules = $this->rules_for( strtolower( $agent ) );
		$best  = null;

		foreach ( $rules as $rule ) {
			$length = self::match_length( $rule['path'], $path );

			if ( null === $length ) {
				continue;
			}

			if ( null === $best || $length > $best['length'] || ( $length === $best['length'] && $rule['allow'] ) ) {
				$best = array(
					'length' => $length,
					'allow'  => $rule['allow'],
				);
			}
		}

		return null === $best || $best['allow'];
	}

	/**
	 * Whether a group names this crawler specifically (not just "*").
	 *
	 * @param string $agent Crawler token.
	 * @return bool
	 */
	public function names( string $agent ): bool {
		$agent = strtolower( $agent );

		foreach ( $this->groups as $group ) {
			if ( in_array( $agent, $group['agents'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The rules a crawler follows: its own groups, else the "*" groups.
	 *
	 * @param string $agent Lower-case crawler token.
	 * @return array
	 */
	private function rules_for( string $agent ): array {
		$own = array();
		$any = array();

		foreach ( $this->groups as $group ) {
			if ( in_array( $agent, $group['agents'], true ) ) {
				$own = array_merge( $own, $group['rules'] );
			} elseif ( in_array( '*', $group['agents'], true ) ) {
				$any = array_merge( $any, $group['rules'] );
			}
		}

		return $this->names( $agent ) ? $own : $any;
	}

	/**
	 * How specific a rule is for a path, or null when it does not match.
	 * Supports "*" (any characters) and "$" (end of the path).
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    Path being checked.
	 * @return int|null
	 */
	private static function match_length( string $pattern, string $path ): ?int {
		$anchored = str_ends_with( $pattern, '$' );
		$body     = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex    = '#^' . str_replace( '\*', '.*', preg_quote( $body, '#' ) ) . ( $anchored ? '$' : '' ) . '#';

		return preg_match( $regex, $path ) ? strlen( $pattern ) : null;
	}
}
