<?php
/**
 * Crawl triggers — manual (all / region-filtered) and scheduled cron. WP never
 * crawls; it only composes a job manifest and POSTs it to the worker. WP cron
 * just fires that quick POST, so nothing heavy runs inside a PHP web request.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Crawl_Triggers {

	/** Cron hook for scheduled crawls. */
	const CRON_HOOK = 'hlv_scheduled_crawl';

	/** Max listing snapshots pushed per manifest POST (chunked for large scopes). */
	const SNAPSHOT_CHUNK = 250;

	/**
	 * Register cron hook handling.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Build the per-run config from saved settings plus per-run overrides.
	 *
	 * @param array $overrides Per-run overrides (e.g. image_instructions, mode).
	 * @return array
	 */
	public static function build_run_config( array $overrides = array() ) {
		$s = HLV_Settings::get();

		$defaults = array(
			'mode'                => 'interactive',                 // interactive | batch
			'text_model'          => $s['text_model'],
			'image_model'         => $s['image_model'],
			'image_aggressiveness'=> $s['image_aggressiveness'],    // off | weak_signal_only | plausible | all
			'image_instructions'  => '',                            // free-text guidance for this run
			'max_flyers'          => (int) $s['max_flyers'],
			'no_signal_mode'      => $s['no_signal_mode'],          // manual_queue | cheap_pass
			'concurrency'         => (int) $s['concurrency'],
			'skip_unchanged'      => true,                          // re-runs skip listings whose sources are byte-identical
		);

		return wp_parse_args( $overrides, $defaults );
	}

	/**
	 * Collect in-scope listing IDs.
	 *
	 * @param array $args { scope: 'all'|'region', region_terms: int[] }
	 * @return int[]
	 */
	public static function collect_listing_ids( array $args ) {
		$query_args = array(
			'post_type'      => HLV_Field_Map::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( ! empty( $args['scope'] ) && 'region' === $args['scope'] && ! empty( $args['region_terms'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => HLV_Field_Map::REGION_TAXONOMY,
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', (array) $args['region_terms'] ),
				),
			);
		}

		$q = new WP_Query( $query_args );
		return array_map( 'intval', $q->posts );
	}

	/**
	 * Start a crawl: create the run on the worker, then push snapshot chunks.
	 *
	 * @param array $args { scope, region_terms, run_config_overrides }
	 * @return array|WP_Error { run_id, count }
	 */
	public static function start_crawl( array $args ) {
		$ids = self::collect_listing_ids( $args );
		if ( empty( $ids ) ) {
			return new WP_Error( 'hlv_empty_scope', __( 'No published listings matched this scope.', 'haunt-listing-verifier' ) );
		}

		$run_config = self::build_run_config( isset( $args['run_config_overrides'] ) ? (array) $args['run_config_overrides'] : array() );
		$prompt     = HLV_Prompt_Compiler::compile( $run_config );

		// 1) Create the run (metadata only — fast).
		$created = HLV_Worker_Client::create_crawl(
			array(
				'mode'        => $run_config['mode'],
				'run_config'  => $run_config,
				'prompt'      => $prompt,
				'scope'       => array(
					'type'         => isset( $args['scope'] ) ? $args['scope'] : 'all',
					'region_terms' => isset( $args['region_terms'] ) ? array_map( 'intval', (array) $args['region_terms'] ) : array(),
					'count'        => count( $ids ),
				),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$run_id = isset( $created['run_id'] ) ? $created['run_id'] : '';

		// 2) Push listing snapshots in chunks.
		foreach ( array_chunk( $ids, self::SNAPSHOT_CHUNK ) as $chunk ) {
			$snapshots = array();
			foreach ( $chunk as $id ) {
				$snapshots[] = HLV_Field_Map::snapshot( $id );
			}
			$pushed = HLV_Worker_Client::request(
				'POST',
				'/runs/' . rawurlencode( $run_id ) . '/listings',
				array( 'listings' => $snapshots ),
				60
			);
			if ( is_wp_error( $pushed ) ) {
				return $pushed;
			}
		}

		// 3) Mark the run ready to process.
		HLV_Worker_Client::request( 'POST', '/runs/' . rawurlencode( $run_id ) . '/start', array() );

		update_option( 'hlv_last_run_id', $run_id, false );
		return array(
			'run_id' => $run_id,
			'count'  => count( $ids ),
		);
	}

	/**
	 * Scheduled cron entry point. Uses the saved cron scope + the saved
	 * image-analysis config (per the "apply saved config to cron" toggle).
	 */
	public static function run_scheduled() {
		$s = HLV_Settings::get();
		if ( empty( $s['cron_enabled'] ) ) {
			return;
		}
		self::start_crawl(
			array(
				'scope'                => $s['cron_scope'],
				'region_terms'         => $s['cron_region_terms'],
				'run_config_overrides' => array(
					'mode' => 'batch', // Scheduled runs use the 50%-discount Batch path.
				),
			)
		);
	}

	/**
	 * (Re)schedule the cron event from settings.
	 */
	public static function reschedule() {
		$s = HLV_Settings::get();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( ! empty( $s['cron_enabled'] ) && ! empty( $s['cron_recurrence'] ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $s['cron_recurrence'], self::CRON_HOOK );
		}
	}
}
