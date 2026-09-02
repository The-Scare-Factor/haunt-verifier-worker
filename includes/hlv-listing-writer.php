<?php
/**
 * Applies admin-approved changes to a LIVE listing. This is the only place in
 * the system that writes production listing data — the worker never does.
 *
 * Uses ACF field keys (more robust than meta_keys for ACF writes) from the
 * central field map. Closure = revert to draft/pending (reversible), never
 * trash; the admin clears those manually once a closure is confirmed permanent.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Listing_Writer {

	/**
	 * Apply a single approved field change.
	 *
	 * @param int    $listing_id Listing post ID.
	 * @param string $field      One of: name, website, facebook, instagram, tiktok,
	 *                           youtube_channel, x_twitter, street_address, city,
	 *                           zip, country_select, state.
	 * @param mixed  $value      New value.
	 * @return true|WP_Error
	 */
	public static function apply_field( $listing_id, $field, $value ) {
		$listing_id = (int) $listing_id;
		if ( get_post_type( $listing_id ) !== HLV_Field_Map::POST_TYPE ) {
			return new WP_Error( 'hlv_bad_listing', __( 'Not a listing.', 'haunt-listing-verifier' ) );
		}

		if ( 'name' === $field ) {
			$new_title = sanitize_text_field( $value );
			$result    = wp_update_post(
				array(
					'ID'         => $listing_id,
					'post_title' => $new_title,
					// Keep the slug in step with the name (was a manual step before).
					'post_name'  => sanitize_title( $new_title ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Align Yoast's focus keyphrase with the new name; the SEO title is
			// template-driven, so we leave it to regenerate.
			update_post_meta( $listing_id, '_yoast_wpseo_focuskw', $new_title );
			return true;
		}

		if ( 'state' === $field ) {
			return self::set_state_region( $listing_id, $value );
		}

		// Compound address change → apply each present component (value is the
		// structured suggested_address array).
		if ( 'address' === $field ) {
			return is_array( $value )
				? self::apply_address( $listing_id, $value )
				: new WP_Error( 'hlv_bad_address', __( 'An address change needs structured address data.', 'haunt-listing-verifier' ) );
		}

		// Dates/hours change → text + the conditionally-required expiration date
		// (+ optional flyer image). Value is { text, expiration, image_url }.
		if ( 'dates_hours' === $field ) {
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'hlv_bad_dates', __( 'A dates/hours change needs structured data.', 'haunt-listing-verifier' ) );
			}
			return self::write_dates_hours(
				$listing_id,
				isset( $value['text'] ) ? $value['text'] : '',
				isset( $value['expiration'] ) ? $value['expiration'] : '',
				isset( $value['image_url'] ) ? $value['image_url'] : null
			);
		}

		// Status / informational findings aren't applied as a field write — they
		// flow through the row-level Unpublish/Defer actions instead.
		if ( in_array( $field, array( 'status', 'other' ), true ) ) {
			return new WP_Error( 'hlv_not_applyable', __( 'This finding is informational — use Unpublish or Defer instead of Apply.', 'haunt-listing-verifier' ) );
		}

		if ( ! isset( HLV_Field_Map::FIELD_KEY[ $field ] ) ) {
			return new WP_Error( 'hlv_unknown_field', __( 'Unknown field.', 'haunt-listing-verifier' ) );
		}

		$value = self::sanitize_value( $field, $value );

		if ( function_exists( 'update_field' ) ) {
			update_field( HLV_Field_Map::FIELD_KEY[ $field ], $value, $listing_id );
		} else {
			update_post_meta( $listing_id, HLV_Field_Map::META[ $field ], $value );
		}
		return true;
	}

	/**
	 * Apply a full suggested address (components present in the array are written).
	 *
	 * @param int   $listing_id Listing post ID.
	 * @param array $address    { street, city, state, zip, country }
	 * @return true|WP_Error First error encountered, or true.
	 */
	public static function apply_address( $listing_id, array $address ) {
		$map = array(
			'street'  => 'street_address',
			'city'    => 'city',
			'zip'     => 'zip',
			'country' => 'country_select',
			'state'   => 'state',
		);
		foreach ( $map as $in => $field ) {
			if ( ! isset( $address[ $in ] ) || '' === $address[ $in ] ) {
				continue;
			}
			$result = self::apply_field( $listing_id, $field, $address[ $in ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Set the listing's state by resolving a state NAME to a `region` term.
	 * The `state` ACF field is a taxonomy field, so we set the term, not text.
	 *
	 * @param int    $listing_id Listing post ID.
	 * @param string $state_name State name (e.g. "Ohio").
	 * @return true|WP_Error
	 */
	public static function set_state_region( $listing_id, $state_name ) {
		$state_name = sanitize_text_field( $state_name );
		$term       = get_term_by( 'name', $state_name, HLV_Field_Map::REGION_TAXONOMY );
		if ( ! $term ) {
			// Don't silently create a region term — surface it for human handling.
			return new WP_Error(
				'hlv_unknown_region',
				/* translators: %s: state name */
				sprintf( __( 'No matching "%s" region term exists; create it before applying.', 'haunt-listing-verifier' ), $state_name )
			);
		}

		$result = wp_set_object_terms( $listing_id, (int) $term->term_id, HLV_Field_Map::REGION_TAXONOMY, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Keep the ACF taxonomy field's stored value in sync.
		if ( function_exists( 'update_field' ) ) {
			update_field( HLV_Field_Map::FIELD_KEY['state'], (int) $term->term_id, $listing_id );
		}
		return true;
	}

	/**
	 * Unpublish a listing (closed/relocated) by reverting to draft or pending.
	 * Reversible by design — never trash here.
	 *
	 * @param int    $listing_id Listing post ID.
	 * @param string $reason     Closure reason (stored for queryability).
	 * @param string $status     'draft' or 'pending'.
	 * @return true|WP_Error
	 */
	public static function unpublish( $listing_id, $reason = '', $status = 'draft' ) {
		$status = in_array( $status, array( 'draft', 'pending' ), true ) ? $status : 'draft';
		$result = wp_update_post(
			array(
				'ID'          => (int) $listing_id,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['closure_reason'], sanitize_text_field( $reason ) );
		return true;
	}

	/**
	 * Delete a listing. Defaults to trash (recoverable); force only on explicit
	 * confirmation in the UI.
	 *
	 * @param int  $listing_id Listing post ID.
	 * @param bool $force      Permanently delete if true.
	 * @return true|WP_Error
	 */
	public static function delete_listing( $listing_id, $force = false ) {
		$result = $force ? wp_delete_post( (int) $listing_id, true ) : wp_trash_post( (int) $listing_id );
		return $result ? true : new WP_Error( 'hlv_delete_failed', __( 'Could not delete the listing.', 'haunt-listing-verifier' ) );
	}

	/**
	 * Update the breadcrumb mirror in post meta after a decision is applied.
	 *
	 * @param int    $listing_id   Listing post ID.
	 * @param string $status       operating_status from the finding.
	 * @param string $decision     accepted|rejected|edited|pending.
	 * @param string $finding_hash Fingerprint of the suggestion set.
	 */
	public static function update_breadcrumb( $listing_id, $status, $decision, $finding_hash = '' ) {
		update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['last_verified'], time() );
		update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['last_status'], sanitize_text_field( $status ) );
		update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['last_decision'], sanitize_key( $decision ) );
		if ( '' !== $finding_hash ) {
			update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['finding_hash'], sanitize_text_field( $finding_hash ) );
		}
	}

	/**
	 * Mirror a worker breadcrumb into post meta after a crawl. This populates the
	 * list-table "last verified" column for every crawled listing (not just the
	 * ones an admin actioned) and lets cron skip unchanged listings without a
	 * worker round-trip. Never touches live listing fields.
	 *
	 * @param int   $listing_id Listing post ID.
	 * @param array $crumb      { last_crawled_at (ISO8601), last_status, finding_hash }.
	 */
	public static function write_mirror( $listing_id, array $crumb ) {
		$listing_id = (int) $listing_id;
		if ( get_post_type( $listing_id ) !== HLV_Field_Map::POST_TYPE ) {
			return;
		}
		if ( ! empty( $crumb['last_crawled_at'] ) ) {
			$ts = strtotime( (string) $crumb['last_crawled_at'] );
			if ( $ts ) {
				update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['last_verified'], $ts );
			}
		}
		if ( isset( $crumb['last_status'] ) && '' !== $crumb['last_status'] ) {
			update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['last_status'], sanitize_text_field( $crumb['last_status'] ) );
		}
		if ( isset( $crumb['finding_hash'] ) && '' !== $crumb['finding_hash'] ) {
			update_post_meta( $listing_id, HLV_Field_Map::PLUGIN_META['finding_hash'], sanitize_text_field( $crumb['finding_hash'] ) );
		}
	}

	/**
	 * Write the dates/hours text plus the conditionally-required expiration date
	 * (and optionally a cited flyer image). ACF requires the expiration whenever
	 * the text is set, and the front-end uses it to auto-hide the dates block
	 * after the season ends — so we ALWAYS set it to the last event date.
	 *
	 * @param int         $listing_id Listing post ID.
	 * @param string      $text       Dates/hours/ticket text.
	 * @param string      $expiration Last event date (any parseable date string).
	 * @param string|null $image_url  Optional flyer/calendar image to sideload.
	 * @return true|WP_Error
	 */
	public static function write_dates_hours( $listing_id, $text, $expiration, $image_url = null ) {
		$listing_id = (int) $listing_id;
		if ( get_post_type( $listing_id ) !== HLV_Field_Map::POST_TYPE ) {
			return new WP_Error( 'hlv_bad_listing', __( 'Not a listing.', 'haunt-listing-verifier' ) );
		}

		self::set_acf( 'dates_hours_text', sanitize_textarea_field( $text ), $listing_id );

		$exp = self::normalize_acf_date( $expiration );
		if ( '' === $exp ) {
			// Never leave the required field empty (save would fail); fall back to
			// a safe end-of-season default the admin can correct.
			$exp = gmdate( 'Ymd', strtotime( 'November 15 this year' ) );
		}
		self::set_acf( 'dates_hours_expiration', $exp, $listing_id );

		if ( ! empty( $image_url ) ) {
			$att = self::sideload_dates_hours_image( $listing_id, $image_url );
			if ( is_wp_error( $att ) ) {
				return $att;
			}
		}
		return true;
	}

	/**
	 * Sideload a cited image into the media library and set it as the listing's
	 * "Dates and Hours Image". The image is attached to the listing post because
	 * the ACF field's library is "uploadedTo"; size is capped at the field's
	 * 0.5 MB limit.
	 *
	 * @param int    $listing_id Listing post ID.
	 * @param string $image_url  Remote image URL.
	 * @return int|WP_Error Attachment ID on success.
	 */
	public static function sideload_dates_hours_image( $listing_id, $image_url ) {
		$listing_id = (int) $listing_id;
		$image_url  = esc_url_raw( (string) $image_url );
		if ( '' === $image_url ) {
			return new WP_Error( 'hlv_no_image', __( 'No image URL supplied.', 'haunt-listing-verifier' ) );
		}
		if ( get_post_type( $listing_id ) !== HLV_Field_Map::POST_TYPE ) {
			return new WP_Error( 'hlv_bad_listing', __( 'Not a listing.', 'haunt-listing-verifier' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( (int) filesize( $tmp ) > 512000 ) { // 0.5 MB — the ACF field's max_size.
			wp_delete_file( $tmp );
			return new WP_Error( 'hlv_image_too_large', __( 'That image is over the 0.5 MB limit for the Dates & Hours Image field. Resize it and add it manually.', 'haunt-listing-verifier' ) );
		}

		$type = wp_check_filetype( wp_parse_url( $image_url, PHP_URL_PATH ) );
		$name = ! empty( $type['ext'] )
			? 'dates-hours-' . $listing_id . '-' . time() . '.' . $type['ext']
			: 'dates-hours-' . $listing_id . '-' . time() . '.jpg';

		$file_array = array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		);

		// post_parent = listing so the field's "uploadedTo" library includes it.
		$attachment_id = media_handle_sideload( $file_array, $listing_id );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		self::set_acf( 'dates_hours_image', (int) $attachment_id, $listing_id );
		return (int) $attachment_id;
	}

	/**
	 * Clear the listing's Internal (admin) note — used after an action fulfills
	 * what the note was tracking.
	 *
	 * @param int $listing_id Listing post ID.
	 * @return true|WP_Error
	 */
	public static function clear_admin_note( $listing_id ) {
		$listing_id = (int) $listing_id;
		if ( get_post_type( $listing_id ) !== HLV_Field_Map::POST_TYPE ) {
			return new WP_Error( 'hlv_bad_listing', __( 'Not a listing.', 'haunt-listing-verifier' ) );
		}
		self::set_acf( 'internal_notes', '', $listing_id );
		return true;
	}

	/**
	 * Append an off-season/holiday event as a new row in the off_season_events
	 * repeater (only on human Apply). Resolves the holiday_tag term by name and
	 * never creates unknown terms.
	 *
	 * @param int    $listing_id Listing post ID.
	 * @param string $holiday    holiday_tag term name.
	 * @param string $start      Event start (parseable datetime).
	 * @param string $end        Event end (parseable datetime).
	 * @param string $info       Short event info text.
	 * @return true|WP_Error
	 */
	public static function add_off_season_event( $listing_id, $holiday, $start, $end, $info = '' ) {
		$listing_id = (int) $listing_id;
		if ( get_post_type( $listing_id ) !== HLV_Field_Map::POST_TYPE ) {
			return new WP_Error( 'hlv_bad_listing', __( 'Not a listing.', 'haunt-listing-verifier' ) );
		}
		if ( ! function_exists( 'add_row' ) ) {
			return new WP_Error( 'hlv_no_acf_pro', __( 'ACF Pro is required to add off-season event rows.', 'haunt-listing-verifier' ) );
		}

		$sub = HLV_Field_Map::OFF_SEASON['sub'];
		$row = array(
			$sub['start']      => self::normalize_acf_datetime( $start ),
			$sub['end']        => self::normalize_acf_datetime( $end ),
			$sub['info_basic'] => sanitize_textarea_field( $info ),
		);

		$term = $holiday ? get_term_by( 'name', sanitize_text_field( $holiday ), HLV_Field_Map::OFF_SEASON['holiday_tax'] ) : false;
		if ( $term ) {
			$row[ $sub['holiday'] ] = (int) $term->term_id;
		}

		$ok = add_row( HLV_Field_Map::OFF_SEASON['repeater'], $row, $listing_id );
		return $ok ? true : new WP_Error( 'hlv_offseason_failed', __( 'Could not add the off-season event row.', 'haunt-listing-verifier' ) );
	}

	/**
	 * Write an ACF field by our field-map token (prefers field key; falls back to
	 * meta_key when ACF is unavailable).
	 *
	 * @param string $token      Field-map token.
	 * @param mixed  $value      Value to write.
	 * @param int    $listing_id Listing post ID.
	 */
	private static function set_acf( $token, $value, $listing_id ) {
		if ( function_exists( 'update_field' ) && isset( HLV_Field_Map::FIELD_KEY[ $token ] ) ) {
			update_field( HLV_Field_Map::FIELD_KEY[ $token ], $value, $listing_id );
		} elseif ( isset( HLV_Field_Map::META[ $token ] ) ) {
			update_post_meta( $listing_id, HLV_Field_Map::META[ $token ], $value );
		}
	}

	/**
	 * Normalize any parseable date to the ACF date_picker storage format (Ymd).
	 *
	 * @param string $value Date string.
	 * @return string 'Ymd' or '' if unparseable.
	 */
	private static function normalize_acf_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Ymd', $ts ) : '';
	}

	/**
	 * Normalize any parseable datetime to the ACF date_time_picker storage
	 * format (Y-m-d H:i:s).
	 *
	 * @param string $value Datetime string.
	 * @return string 'Y-m-d H:i:s' or '' if unparseable.
	 */
	private static function normalize_acf_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}

	/**
	 * Field-appropriate sanitization for live writes.
	 *
	 * @param string $field Field slug.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_value( $field, $value ) {
		$url_fields = array( 'website', 'facebook', 'instagram', 'tiktok', 'youtube_channel', 'x_twitter' );
		if ( in_array( $field, $url_fields, true ) ) {
			return esc_url_raw( trim( (string) $value ) );
		}
		return sanitize_text_field( $value );
	}
}
