<?php
/**
 * HMAC-signed REST client to the Python/FastAPI worker on Sevalla.
 *
 * WordPress holds NO provider keys (Anthropic/Google live in the worker's
 * environment). WP stores only the worker base URL and a shared HMAC secret,
 * and signs every request body so the worker can verify it came from us.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Worker_Client {

	/** Default per-request timeout (seconds). Crawl creation returns fast (it just enqueues). */
	const TIMEOUT = 20;

	/**
	 * Whether the worker connection is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$s = HLV_Settings::get();
		return ! empty( $s['worker_url'] ) && ! empty( self::secret() );
	}

	/**
	 * The HMAC shared secret. Prefer a wp-config constant over the DB option.
	 *
	 * @return string
	 */
	private static function secret() {
		if ( defined( 'HLV_WORKER_SECRET' ) && HLV_WORKER_SECRET ) {
			return (string) HLV_WORKER_SECRET;
		}
		$s = HLV_Settings::get();
		return isset( $s['worker_secret'] ) ? (string) $s['worker_secret'] : '';
	}

	/**
	 * Base URL of the worker (no trailing slash).
	 *
	 * @return string
	 */
	private static function base_url() {
		$s = HLV_Settings::get();
		return isset( $s['worker_url'] ) ? untrailingslashit( esc_url_raw( $s['worker_url'] ) ) : '';
	}

	/**
	 * Sign a raw request body with HMAC-SHA256.
	 *
	 * @param string $timestamp Unix timestamp string (also signed, replay guard).
	 * @param string $body      Raw JSON body.
	 * @return string Hex signature.
	 */
	private static function sign( $timestamp, $body ) {
		return hash_hmac( 'sha256', $timestamp . "\n" . $body, self::secret() );
	}

	/**
	 * Perform a signed request against the worker.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with '/'.
	 * @param array|null $body   Body to JSON-encode, or null.
	 * @param int        $timeout Optional override.
	 * @return array|WP_Error Decoded response array on success.
	 */
	public static function request( $method, $path, $body = null, $timeout = self::TIMEOUT ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'hlv_not_configured', __( 'The verifier worker URL/secret is not configured.', 'haunt-listing-verifier' ) );
		}

		$json      = null === $body ? '' : wp_json_encode( $body );
		$timestamp = (string) time();

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => (int) $timeout,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'X-HLV-Timestamp'  => $timestamp,
				'X-HLV-Signature'  => self::sign( $timestamp, $json ),
				'X-HLV-Plugin-Ver' => HLV_VERSION,
			),
		);
		if ( '' !== $json ) {
			$args['body'] = $json;
		}

		$response = wp_remote_request( self::base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['detail'] ) ? $data['detail'] : $raw;
			return new WP_Error(
				'hlv_worker_http_' . $code,
				/* translators: 1: HTTP status code, 2: worker error message */
				sprintf( __( 'Worker returned HTTP %1$d: %2$s', 'haunt-listing-verifier' ), $code, $message ),
				array( 'status' => $code )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Enqueue a crawl. The manifest carries scope, run config, the compiled
	 * prompt, and the paged listing snapshots.
	 *
	 * @param array $manifest Job manifest.
	 * @return array|WP_Error { run_id, queued }
	 */
	public static function create_crawl( array $manifest ) {
		return self::request( 'POST', '/crawls', $manifest );
	}

	/**
	 * Interactive single-listing check (non-batch, returns the finding inline).
	 *
	 * @param array $snapshot   Listing snapshot from HLV_Field_Map::snapshot().
	 * @param array $run_config Per-run config (image aggressiveness, models, etc.).
	 * @param string $prompt    Compiled system prompt.
	 * @return array|WP_Error The finding.
	 */
	public static function check_listing( array $snapshot, array $run_config, $prompt ) {
		return self::request(
			'POST',
			'/listings/check',
			array(
				'listing'    => $snapshot,
				'run_config' => $run_config,
				'prompt'     => (string) $prompt,
			),
			120 // Interactive classification can take longer than enqueue.
		);
	}

	/**
	 * Read findings for the dashboard, filtered by confidence/status.
	 *
	 * @param array $args Query args ( confidence, run_id, page, per_page ).
	 * @return array|WP_Error { items, total }
	 */
	public static function get_findings( array $args = array() ) {
		$query = http_build_query( $args );
		return self::request( 'GET', '/findings' . ( $query ? '?' . $query : '' ) );
	}

	/**
	 * Read a run summary (counts + token spend split).
	 *
	 * @param string $run_id Run identifier.
	 * @return array|WP_Error
	 */
	public static function get_run( $run_id ) {
		return self::request( 'GET', '/runs/' . rawurlencode( $run_id ) );
	}

	/**
	 * Read per-listing verified state for a run, to mirror into WP post meta.
	 *
	 * @param string $run_id Run identifier.
	 * @return array|WP_Error { items: [ { listing_id, last_crawled_at, last_status, finding_hash } ] }
	 */
	public static function get_run_breadcrumbs( $run_id ) {
		return self::request( 'GET', '/runs/' . rawurlencode( $run_id ) . '/breadcrumbs' );
	}

	/**
	 * Record an admin decision back to the worker's decision log.
	 *
	 * @param array $decision Decision payload.
	 * @return array|WP_Error
	 */
	public static function post_decision( array $decision ) {
		return self::request( 'POST', '/decisions', $decision );
	}

	/**
	 * Park a finding in the Past findings archive.
	 *
	 * Keeps the record AND the listing's breadcrumb, so the same suggestion will
	 * not come back until the haunt's own sources actually change. Reversible
	 * via restore_finding().
	 *
	 * @param string $finding_id Finding identifier.
	 * @param array  $args       Optional { field, reason_code, note, user }.
	 * @return array|WP_Error
	 */
	public static function ignore_finding( $finding_id, array $args = array() ) {
		return self::request( 'POST', '/findings/' . rawurlencode( $finding_id ) . '/ignore', $args );
	}

	/**
	 * Move an archived finding back into the active review queue.
	 *
	 * @param string $finding_id Finding identifier.
	 * @return array|WP_Error
	 */
	public static function restore_finding( $finding_id ) {
		return self::request( 'POST', '/findings/' . rawurlencode( $finding_id ) . '/restore', array() );
	}

	/**
	 * Delete a finding outright and force its listing to be re-checked.
	 *
	 * Unlike ignore, this resets the listing's crawl breadcrumb, so the next run
	 * re-reads and re-classifies it rather than skipping it as unchanged.
	 *
	 * @param string $finding_id Finding identifier.
	 * @return array|WP_Error { deleted, listing_ids }
	 */
	public static function delete_finding( $finding_id ) {
		return self::request( 'POST', '/findings/' . rawurlencode( $finding_id ) . '/delete', array() );
	}

	/**
	 * Bulk-delete findings and force those listings to be re-checked.
	 *
	 * The "clean slate" control: wipe results produced under old instructions so
	 * the next run comes back with nothing stale mixed in. At least one filter is
	 * required — the worker rejects an unfiltered purge.
	 *
	 * @param array $filters { status, confidence, run_id, listing_ids }.
	 * @return array|WP_Error { deleted, listing_ids }
	 */
	public static function purge_findings( array $filters ) {
		return self::request( 'POST', '/findings/purge', $filters, 60 );
	}

	/**
	 * Lightweight health/connectivity probe for the settings page.
	 *
	 * @return array|WP_Error
	 */
	public static function ping() {
		return self::request( 'GET', '/health', null, 10 );
	}
}
