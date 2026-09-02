<?php
/**
 * Ajax handlers for the review dashboard. Every handler enforces the nonce and
 * the manage_haunt_verifier capability, writes live data only via
 * HLV_Listing_Writer, and records the decision via HLV_Decision_Log.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Ajax {

	/**
	 * Register handlers.
	 */
	public static function init() {
		add_action( 'wp_ajax_hlv_apply', array( __CLASS__, 'apply' ) );
		add_action( 'wp_ajax_hlv_unpublish', array( __CLASS__, 'unpublish' ) );
		add_action( 'wp_ajax_hlv_delete', array( __CLASS__, 'delete' ) );
		add_action( 'wp_ajax_hlv_decision', array( __CLASS__, 'decision' ) );
		add_action( 'wp_ajax_hlv_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'wp_ajax_hlv_sideload_image', array( __CLASS__, 'sideload_image' ) );
		add_action( 'wp_ajax_hlv_apply_offseason', array( __CLASS__, 'apply_offseason' ) );
		add_action( 'wp_ajax_hlv_clear_admin_note', array( __CLASS__, 'clear_admin_note' ) );
		add_action( 'wp_ajax_hlv_ignore_finding', array( __CLASS__, 'ignore_finding' ) );
		add_action( 'wp_ajax_hlv_restore_finding', array( __CLASS__, 'restore_finding' ) );
		add_action( 'wp_ajax_hlv_delete_finding', array( __CLASS__, 'delete_finding' ) );
		add_action( 'wp_ajax_hlv_purge_findings', array( __CLASS__, 'purge_findings' ) );
	}

	/**
	 * Shared guard: verify nonce + capability, or send a 403 JSON error.
	 */
	private static function guard() {
		if ( ! check_ajax_referer( 'hlv_ajax', 'nonce', false ) || ! HLV_Capabilities::current_user_can() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'haunt-listing-verifier' ) ), 403 );
		}
	}

	/**
	 * Common request fields shared by the action handlers.
	 *
	 * @return array { listing_id, finding_id, run_id }
	 */
	private static function context() {
		return array(
			'listing_id' => isset( $_POST['listing_id'] ) ? (int) $_POST['listing_id'] : 0,
			'finding_id' => isset( $_POST['finding_id'] ) ? sanitize_text_field( wp_unslash( $_POST['finding_id'] ) ) : '',
			'run_id'     => isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '',
		);
	}

	/**
	 * Apply a single approved field change to the live listing.
	 */
	public static function apply() {
		self::guard();
		$ctx    = self::context();
		$field  = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
		$edited = ! empty( $_POST['edited'] );

		if ( ! $ctx['listing_id'] || '' === $field ) {
			wp_send_json_error( array( 'message' => __( 'Missing listing or field.', 'haunt-listing-verifier' ) ), 400 );
		}

		// Compound fields carry structured data; scalar fields carry `value`.
		if ( 'address' === $field ) {
			$value = isset( $_POST['address'] ) && is_array( $_POST['address'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['address'] ) )
				: array();
			$log_value = implode( ', ', array_filter( (array) $value ) );
		} elseif ( 'dates_hours' === $field ) {
			$value = array(
				'text'       => isset( $_POST['dates_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dates_text'] ) ) : '',
				'expiration' => isset( $_POST['dates_expiration'] ) ? sanitize_text_field( wp_unslash( $_POST['dates_expiration'] ) ) : '',
				'image_url'  => isset( $_POST['dates_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['dates_image_url'] ) ) : '',
			);
			$log_value = $value['text'];
		} else {
			$value     = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
			$log_value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}

		$result = HLV_Listing_Writer::apply_field( $ctx['listing_id'], $field, $value );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => $field,
					'action'      => 'accepted',
					'reason_code' => $edited ? 'edited_then_accepted' : 'accepted_as_is',
					'final_value' => $log_value,
				)
			)
		);
		HLV_Listing_Writer::update_breadcrumb( $ctx['listing_id'], isset( $_POST['op_status'] ) ? sanitize_key( $_POST['op_status'] ) : '', 'accepted' );

		// If the action fulfilled what an Admin Note was tracking, clear it.
		$note_cleared = false;
		if ( ! empty( $_POST['clear_admin_note'] ) ) {
			$note_cleared = ! is_wp_error( HLV_Listing_Writer::clear_admin_note( $ctx['listing_id'] ) );
		}

		wp_send_json_success(
			array(
				'message'      => __( 'Applied to the live listing.', 'haunt-listing-verifier' ),
				'note_cleared' => $note_cleared,
			)
		);
	}

	/**
	 * Unpublish (revert to draft/pending) with a closure reason.
	 */
	public static function unpublish() {
		self::guard();
		$ctx    = self::context();
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'draft';

		$result = HLV_Listing_Writer::unpublish( $ctx['listing_id'], $reason, $status );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => 'status',
					'action'      => 'accepted',
					'reason_code' => 'confirmed_closed',
					'final_value' => $status,
					'note'        => $reason,
				)
			)
		);
		HLV_Listing_Writer::update_breadcrumb( $ctx['listing_id'], 'closed', 'accepted' );

		wp_send_json_success( array( 'message' => __( 'Listing reverted to draft.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Delete (trash) a listing.
	 */
	public static function delete() {
		self::guard();
		$ctx   = self::context();
		$force = ! empty( $_POST['force'] );

		$result = HLV_Listing_Writer::delete_listing( $ctx['listing_id'], $force );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => 'status',
					'action'      => 'accepted',
					'reason_code' => 'confirmed_closed',
					'final_value' => $force ? 'deleted' : 'trashed',
				)
			)
		);

		wp_send_json_success( array( 'message' => __( 'Listing deleted.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Record a non-applying decision (reject / defer) without touching the listing.
	 */
	public static function decision() {
		self::guard();
		$ctx    = self::context();
		$reason = isset( $_POST['reason_code'] ) ? sanitize_key( $_POST['reason_code'] ) : '';
		$action = ( 0 === strpos( $reason, 'rejected_' ) ) ? 'rejected' : 'deferred';
		$field  = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => $field,
					'action'      => $action,
					'reason_code' => $reason,
					'note'        => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				)
			)
		);
		HLV_Listing_Writer::update_breadcrumb( $ctx['listing_id'], isset( $_POST['op_status'] ) ? sanitize_key( $_POST['op_status'] ) : '', $action );

		wp_send_json_success( array( 'message' => __( 'Recorded.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Dismiss a finding as "no change needed" — records the decision (which
	 * removes it from the pending queue) without touching the listing.
	 */
	public static function dismiss() {
		self::guard();
		$ctx = self::context();

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '',
					'action'      => 'dismissed',
					'reason_code' => 'dismissed_no_change',
					'note'        => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				)
			)
		);
		HLV_Listing_Writer::update_breadcrumb( $ctx['listing_id'], isset( $_POST['op_status'] ) ? sanitize_key( $_POST['op_status'] ) : '', 'dismissed' );

		wp_send_json_success( array( 'message' => __( 'Dismissed — no change needed.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Sideload a cited flyer/calendar image into the listing's Dates & Hours Image.
	 */
	public static function sideload_image() {
		self::guard();
		$ctx       = self::context();
		$image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';

		if ( ! $ctx['listing_id'] || '' === $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Missing listing or image URL.', 'haunt-listing-verifier' ) ), 400 );
		}

		$result = HLV_Listing_Writer::sideload_dates_hours_image( $ctx['listing_id'], $image_url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => 'dates_hours_image',
					'action'      => 'accepted',
					'reason_code' => 'accepted_as_is',
					'final_value' => 'attachment:' . (int) $result,
				)
			)
		);

		wp_send_json_success( array( 'message' => __( 'Image added to the Dates & Hours Image field.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Apply a discovered off-season/holiday event as a new repeater row.
	 */
	public static function apply_offseason() {
		self::guard();
		$ctx     = self::context();
		$holiday = isset( $_POST['holiday'] ) ? sanitize_text_field( wp_unslash( $_POST['holiday'] ) ) : '';
		$start   = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end     = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';
		$info    = isset( $_POST['info'] ) ? sanitize_textarea_field( wp_unslash( $_POST['info'] ) ) : '';

		if ( ! $ctx['listing_id'] || ( '' === $start && '' === $end ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing listing or event dates.', 'haunt-listing-verifier' ) ), 400 );
		}

		$result = HLV_Listing_Writer::add_off_season_event( $ctx['listing_id'], $holiday, $start, $end, $info );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => 'off_season_events',
					'action'      => 'accepted',
					'reason_code' => 'accepted_as_is',
					'final_value' => trim( $holiday . ' ' . $start . '–' . $end ),
				)
			)
		);

		wp_send_json_success( array( 'message' => __( 'Off-season event added.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Ignore a result — park it in the Past findings archive.
	 *
	 * Nothing on the live listing changes and the listing keeps its crawl
	 * breadcrumb, so the same suggestion stays out of the queue until the haunt's
	 * own website/social actually changes. Restorable from the archive.
	 */
	public static function ignore_finding() {
		self::guard();
		$ctx = self::context();

		if ( '' === $ctx['finding_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Missing finding.', 'haunt-listing-verifier' ) ), 400 );
		}

		$user   = wp_get_current_user();
		$result = HLV_Worker_Client::ignore_finding(
			$ctx['finding_id'],
			array(
				'field'       => isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '',
				'reason_code' => 'ignored_for_later',
				'note'        => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				'user'        => $user ? $user->user_login : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => '',
					'action'      => 'ignored',
					'reason_code' => 'ignored_for_later',
					'push'        => false, // the ignore endpoint already logged it worker-side
				)
			)
		);

		wp_send_json_success( array( 'message' => __( 'Moved to Past findings.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Restore an archived result to the active review queue.
	 */
	public static function restore_finding() {
		self::guard();
		$ctx = self::context();

		if ( '' === $ctx['finding_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Missing finding.', 'haunt-listing-verifier' ) ), 400 );
		}

		$result = HLV_Worker_Client::restore_finding( $ctx['finding_id'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Restored to the review queue.', 'haunt-listing-verifier' ) ) );
	}

	/**
	 * Delete a result and force the crawler to re-check that listing.
	 *
	 * This removes the record AND resets the listing's crawl breadcrumb, so the
	 * next run re-reads it from scratch instead of skipping it as unchanged. Use
	 * it when a result was produced under instructions you have since changed.
	 */
	public static function delete_finding() {
		self::guard();
		$ctx = self::context();

		if ( '' === $ctx['finding_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Missing finding.', 'haunt-listing-verifier' ) ), 400 );
		}

		$result = HLV_Worker_Client::delete_finding( $ctx['finding_id'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		HLV_Decision_Log::record(
			array_merge(
				$ctx,
				array(
					'field'       => '',
					'action'      => 'deleted',
					'reason_code' => 'deleted_for_recheck',
					'push'        => false, // the finding no longer exists worker-side
				)
			)
		);

		wp_send_json_success(
			array( 'message' => __( 'Result deleted — this listing will be re-checked on the next crawl.', 'haunt-listing-verifier' ) )
		);
	}

	/**
	 * Bulk-clear results so a re-run starts from a clean slate.
	 *
	 * Accepts the same filters the dashboard offers (status / confidence tier /
	 * a specific run). Every affected listing has its breadcrumb reset, so the
	 * next crawl re-classifies rather than skipping.
	 */
	public static function purge_findings() {
		self::guard();

		$filters = array();
		$status  = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
		$conf    = isset( $_POST['confidence'] ) ? sanitize_key( $_POST['confidence'] ) : '';
		$run_id  = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

		if ( in_array( $status, array( 'pending', 'ignored', 'superseded', 'resolved', 'deferred' ), true ) ) {
			$filters['status'] = $status;
		}
		if ( in_array( $conf, array( 'high', 'medium', 'low' ), true ) ) {
			$filters['confidence'] = $conf;
		}
		if ( '' !== $run_id ) {
			$filters['run_id'] = $run_id;
		}

		if ( empty( $filters ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Choose what to clear — an unfiltered purge is not allowed.', 'haunt-listing-verifier' ) ),
				400
			);
		}

		$result = HLV_Worker_Client::purge_findings( $filters );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$deleted = isset( $result['deleted'] ) ? (int) $result['deleted'] : 0;
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of results cleared */
					_n(
						'%d result cleared. Those listings will be re-checked on the next crawl.',
						'%d results cleared. Those listings will be re-checked on the next crawl.',
						$deleted,
						'haunt-listing-verifier'
					),
					$deleted
				),
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * Clear the listing's Internal (admin) note on demand.
	 */
	public static function clear_admin_note() {
		self::guard();
		$ctx = self::context();

		$result = HLV_Listing_Writer::clear_admin_note( $ctx['listing_id'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Admin note cleared.', 'haunt-listing-verifier' ) ) );
	}
}

// Register wp_ajax_* handlers at load. admin-ajax.php does NOT fire admin_init,
// so hooking there would miss the dispatch — register directly instead.
HLV_Ajax::init();
