<?php
/**
 * Decision log — the "AI said X / admin did Y" record that powers the
 * feedback/eval loop. The worker's staging DB is authoritative, but we ALSO
 * keep a durable local audit table so the history survives a worker DB
 * relocation/reset. Every approval/rejection writes here AND forwards to the
 * worker (best-effort).
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Decision_Log {

	/** Canonical reason-code taxonomy (kept in sync with the worker). */
	const REASON_CODES = array(
		'accepted_as_is',
		'edited_then_accepted',
		'rejected_false_positive',
		'rejected_seasonal_offseason',
		'rejected_stale_social',
		'rejected_duplicate_already_handled',
		'rejected_insufficient_evidence',
		'deferred_manual',
		'confirmed_closed',
		'confirmed_renamed',
		'confirmed_moved',
			'dismissed_no_change',
		'ignored_for_later',
		'deleted_for_recheck',
	);

	/**
	 * Local audit table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'hlv_decision_log';
	}

	/**
	 * Create/upgrade the local audit table (idempotent; runs on activation).
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			finding_id VARCHAR(64) NOT NULL DEFAULT '',
			run_id VARCHAR(64) NOT NULL DEFAULT '',
			listing_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field VARCHAR(32) NOT NULL DEFAULT '',
			action VARCHAR(32) NOT NULL DEFAULT '',
			reason_code VARCHAR(48) NOT NULL DEFAULT '',
			suggested_value LONGTEXT NULL,
			final_value LONGTEXT NULL,
			note TEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY listing_id (listing_id),
			KEY run_id (run_id),
			KEY reason_code (reason_code)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Record a decision locally and forward it to the worker.
	 *
	 * @param array $decision {
	 *     @type string $finding_id
	 *     @type string $run_id
	 *     @type int    $listing_id
	 *     @type string $field
	 *     @type string $action          accepted|rejected|edited|deferred
	 *     @type string $reason_code     one of REASON_CODES
	 *     @type mixed  $suggested_value
	 *     @type mixed  $final_value
	 *     @type string $note
	 *     @type bool   $push            Forward to the worker. Default true. Pass
	 *                                   false when the worker already logged this
	 *                                   decision itself (ignore) or when the
	 *                                   finding it references is gone (delete) —
	 *                                   the local audit row is still written.
	 * }
	 * @return int|false Local row ID, or false on failure.
	 */
	public static function record( array $decision ) {
		global $wpdb;

		$reason = isset( $decision['reason_code'] ) ? sanitize_key( $decision['reason_code'] ) : '';
		if ( ! in_array( $reason, self::REASON_CODES, true ) ) {
			$reason = '';
		}

		$row = array(
			'finding_id'      => isset( $decision['finding_id'] ) ? sanitize_text_field( $decision['finding_id'] ) : '',
			'run_id'          => isset( $decision['run_id'] ) ? sanitize_text_field( $decision['run_id'] ) : '',
			'listing_id'      => isset( $decision['listing_id'] ) ? (int) $decision['listing_id'] : 0,
			'field'           => isset( $decision['field'] ) ? sanitize_key( $decision['field'] ) : '',
			'action'          => isset( $decision['action'] ) ? sanitize_key( $decision['action'] ) : '',
			'reason_code'     => $reason,
			'suggested_value' => isset( $decision['suggested_value'] ) ? wp_json_encode( $decision['suggested_value'] ) : null,
			'final_value'     => isset( $decision['final_value'] ) ? wp_json_encode( $decision['final_value'] ) : null,
			'note'            => isset( $decision['note'] ) ? sanitize_textarea_field( $decision['note'] ) : '',
			'user_id'         => get_current_user_id(),
			'created_at'      => current_time( 'mysql', true ),
		);

		$inserted = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$local_id = $inserted ? (int) $wpdb->insert_id : false;

		// Forward to the worker (authoritative store). Best-effort: a worker
		// outage must not lose the local audit record.
		if ( isset( $decision['push'] ) && ! $decision['push'] ) {
			return $local_id;
		}

		$forward          = $row;
		$forward['user']  = wp_get_current_user()->user_login;
		$forward['suggested_value'] = isset( $decision['suggested_value'] ) ? $decision['suggested_value'] : null;
		$forward['final_value']     = isset( $decision['final_value'] ) ? $decision['final_value'] : null;
		$result = HLV_Worker_Client::post_decision( $forward );
		if ( is_wp_error( $result ) ) {
			// Mark for later replay; surfaced in the dashboard health note.
			$pending   = get_option( 'hlv_pending_decisions', array() );
			$pending[] = $forward;
			update_option( 'hlv_pending_decisions', $pending, false );
		}

		return $local_id;
	}
}
