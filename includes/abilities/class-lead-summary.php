<?php
/**
 * Lead summary tool.
 *
 * @package WPMark
 */

namespace WPMark\Abilities;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WPMark\Forms\Entry_Query;
use WPMark\Forms\Form_Sources;
use WPMark\Forms\Lead_Reader;
use WPMark\Output\Untrusted_Text;
use WPMark\Privacy\Lead_Access;
use WPMark\Privacy\Lead_Presenter;
use WPMark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Counts leads by form, page, campaign and week: which pages bring enquiries.
 *
 * Marketing job 2 (review incoming leads). Counts only, never contents.
 */
final class Lead_Summary extends Ability {

	/**
	 * Most leads counted in one call.
	 */
	private const SCAN_LIMIT = 2000;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'lead-summary';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Lead summary', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function summary(): string {
		return __( 'Counts leads by form, landing page, campaign and week, to show which pages bring enquiries.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Counts this website\'s form submissions (leads) for a period, default the last 30 days, and breaks them down by form, by the page they were sent from (landing page performance), by campaign (utm_source / utm_medium / utm_campaign tags, when present in the page address) and by week. Use it to answer "which pages or campaigns bring us enquiries?", "are leads going up or down?" or "which form is used most?". Returns counts only: no names, contact details or messages, so it works for anyone allowed to see leads. Dates are YYYY-MM-DD in the site\'s timezone. Counts up to 2,000 leads per call and says if there were more. To read the leads themselves, use wpmark-list-leads. Read-only.', 'wpmark' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_properties(): array {
		return array(
			'since'  => array(
				'type'        => 'string',
				'description' => __( 'Start date, YYYY-MM-DD. Default: 30 days ago.', 'wpmark' ),
			),
			'until'  => array(
				'type'        => 'string',
				'description' => __( 'End date (included), YYYY-MM-DD. Default: today.', 'wpmark' ),
			),
			'source' => array(
				'type'        => 'string',
				'description' => __( 'Only one form plugin: "elementor", "wpforms" or "flamingo". Default: all.', 'wpmark' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return (bool) Form_Sources::available();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason(): string {
		return __( 'No supported form plugin is active on this site (Elementor Pro, WPForms, or Contact Form 7 with Flamingo).', 'wpmark' );
	}

	/**
	 * Only people whose role may see leads.
	 *
	 * @return true|WP_Error
	 */
	protected function extra_permission() {
		if ( Lead_Access::None === Settings::lead_access_for( wp_get_current_user() ) ) {
			return new WP_Error(
				'wpmark_no_lead_access',
				__( 'Your role is not allowed to see leads. A site administrator can change this under WPMark → Access & privacy.', 'wpmark' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Validated input.
	 * @throws Invalid_Input When the dates are wrong.
	 */
	protected function run( array $input ): array {
		$utc   = new DateTimeZone( 'UTC' );
		$since = Lead_Reader::parse_date( (string) ( $input['since'] ?? '' ), false, 'since' )
			?? ( new DateTimeImmutable( 'today -30 days', wp_timezone() ) )->setTimezone( $utc );
		$until = Lead_Reader::parse_date( (string) ( $input['until'] ?? '' ), true, 'until' )
			?? ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->setTimezone( $utc );

		if ( $since >= $until ) {
			throw new Invalid_Input( __( '"since" must be on or before "until".', 'wpmark' ) );
		}

		$sources = Lead_Reader::sources( trim( (string) ( $input['source'] ?? '' ) ) );
		$titles  = Lead_Reader::form_titles( $sources );
		$query   = new Entry_Query( null, $since, $until );

		$total   = 0;
		$scanned = 0;
		$forms   = array();
		$pages   = array();
		$tags    = array();
		$weeks   = array();

		foreach ( $sources as $source ) {
			$total += $source->count_entries( $query );

			foreach ( Lead_Reader::newest( $source, $query, self::SCAN_LIMIT - $scanned ) as $entry ) {
				++$scanned;

				$form_key           = $entry->source . '|' . $entry->form_id;
				$forms[ $form_key ] = ( $forms[ $form_key ] ?? 0 ) + 1;

				if ( null !== $entry->page_url ) {
					$url            = Lead_Presenter::safe_url( $entry->page_url );
					$page           = strtok( $url, '?' );
					$pages[ $page ] = ( $pages[ $page ] ?? 0 ) + 1;
					$campaign       = $this->campaign( $url );

					if ( null !== $campaign ) {
						$tags[ $campaign ] = ( $tags[ $campaign ] ?? 0 ) + 1;
					}
				}

				$week           = $entry->submitted_at->setTimezone( wp_timezone() )->modify( 'monday this week' )->format( 'Y-m-d' );
				$weeks[ $week ] = ( $weeks[ $week ] ?? 0 ) + 1;
			}
		}

		arsort( $forms );
		arsort( $pages );
		arsort( $tags );
		ksort( $weeks );

		return $this->respond(
			array(
				'period'                           => array(
					'since' => $since->setTimezone( wp_timezone() )->format( 'Y-m-d' ),
					'until' => $until->setTimezone( wp_timezone() )->modify( '-1 day' )->format( 'Y-m-d' ),
				),
				'total_leads'                      => $total,
				'counted'                          => $scanned,
				'capped'                           => $scanned < $total,
				'by_form'                          => $this->rows(
					$forms,
					'form',
					static fn( string $key ): array => array(
						'source'  => strtok( $key, '|' ),
						'form_id' => substr( $key, strpos( $key, '|' ) + 1 ),
						'form'    => Untrusted_Text::clean( $titles[ $key ] ?? '', 200 ),
					)
				),
				'by_page'                          => array_slice( $this->rows( $pages, 'page_url' ), 0, 20 ),
				'by_campaign'                      => array_slice( $this->rows( $tags, 'campaign' ), 0, 20 ),
				'by_week'                          => $this->rows( $weeks, 'week_starting' ),
				'form_plugins_without_saved_leads' => Lead_Reader::sources_without_saved_leads(),
			)
		);
	}

	/**
	 * "source / medium / campaign" from a page address's utm tags, if any.
	 *
	 * @param string $url Cleaned address.
	 * @return string|null
	 */
	private function campaign( string $url ): ?string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $query ) {
			return null;
		}

		parse_str( $query, $utm );
		$parts = array_filter(
			array(
				$utm['utm_source'] ?? '',
				$utm['utm_medium'] ?? '',
				$utm['utm_campaign'] ?? '',
			),
			static fn( $part ): bool => is_string( $part ) && '' !== $part
		);

		return $parts ? Untrusted_Text::clean( implode( ' / ', $parts ), 200 ) : null;
	}

	/**
	 * Turn a key => count map into rows.
	 *
	 * @param array<string, int> $counts Counts.
	 * @param string             $name   Name of the key column.
	 * @param callable|null      $expand Turns a key into several columns instead.
	 * @return array<int, array>
	 */
	private function rows( array $counts, string $name, ?callable $expand = null ): array {
		$rows = array();

		foreach ( $counts as $key => $count ) {
			$row          = $expand ? $expand( (string) $key ) : array( $name => (string) $key );
			$row['leads'] = $count;
			$rows[]       = $row;
		}

		return $rows;
	}
}
