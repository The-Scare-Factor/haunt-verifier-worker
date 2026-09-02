<?php
/**
 * Central field map — the single source of truth for everything the verifier
 * reads from and writes to a listing. Every other module references these
 * constants instead of hardcoding meta_keys, so a future ACF rename is a
 * one-file change.
 *
 * Verified against:
 *   - Tylers-Code/global-setup.php (CPT + taxonomy registration)
 *   - ACF Field Group JSONs/Listings ACF Field Group.json (group_tsf_listings_all)
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Field_Map {

	/** Custom post type key. */
	const POST_TYPE = 'listing';

	/** Geographic taxonomy crawls filter by. Terms are US state names. */
	const REGION_TAXONOMY = 'region';

	/**
	 * Identity/status meta_keys the crawler reads and (on approval) writes.
	 * NOTE: the listing NAME is the WP post_title, not an ACF field.
	 */
	const META = array(
		// Website + social (all ACF `url` fields).
		'website'             => 'website',
		'facebook'            => 'facebook',
		'instagram'           => 'instagram',
		'tiktok'              => 'tiktok',
		'youtube_channel'     => 'youtube_channel',
		'x_twitter'           => 'x_twitter',

		// Address components.
		'street_address'      => 'street_address',
		'city'                => 'city',
		// `state` is an ACF TAXONOMY field bound to the `region` taxonomy:
		// its meta stores region term IDs, not a text string. Compare against
		// the term NAME and write the term, never raw text.
		'state'               => 'state',
		'zip'                 => 'zip',
		'country_select'      => 'country_select',
		'latitude'            => 'latitude',
		'longitude'           => 'longitude',

		// Status / identity.
		'is_premium_listing'  => 'is_premium_listing',
		'hide_this_post'      => 'hide_this_post',
		'listing_type'        => 'listing_type',
		'haunt_type_primary'  => 'haunt_type_primary',

		// Dates / hours (Round 2). The expiration date is conditionally REQUIRED
		// by ACF whenever the text/image/iframe is set, and it toggles front-end
		// visibility — the writer must always set it alongside the text.
		'dates_hours_text'        => 'dates_hours_tickets_text',
		'dates_hours_expiration'  => 'dates_hours_tickets_expiration_date',
		'dates_hours_image'       => 'dates_hours_image',
		'calendar_iframe'         => 'calendar_iframe',

		// Admin/internal context the crawler reads (and may clear on action).
		'internal_notes'          => 'internal_notes',
	);

	/**
	 * Off-season events repeater + its sub-fields (Round 2). Holiday/off-season
	 * event dates discovered by the crawler are written here as a new row, not
	 * into the main dates/hours field.
	 */
	const OFF_SEASON = array(
		'repeater'    => 'off_season_events',
		'holiday_tax' => 'holiday_tag',          // taxonomy bound to the holiday sub-field
		'sub'         => array(
			'holiday'    => 'haunt_holidaytags',
			'start'      => 'os_event_start_date_time',
			'end'        => 'os_event_end_date_time',
			'info_basic' => 'off-season_event_info_basic',
		),
	);

	/**
	 * ACF field keys, parallel to META, for update_field() calls that prefer
	 * field keys (more robust than meta_keys for ACF writes).
	 */
	const FIELD_KEY = array(
		'website'             => 'field_67fa1cc848a61',
		'facebook'            => 'field_67fa1eb648a63',
		'instagram'           => 'field_67fa1ee948a64',
		'tiktok'              => 'field_67fa1f0048a65',
		'youtube_channel'     => 'field_67fa1f5148a67',
		'x_twitter'           => 'field_67fa1f2c48a66',
		'street_address'      => 'field_67f9ddaee96c3',
		'city'                => 'field_67f9dfd5e96c4',
		'state'               => 'field_67f9e203e96c5',
		'zip'                 => 'field_67f9e56ce96c6',
		'country_select'      => 'field_67f9e587e96c7',
		'latitude'            => 'field_67f9f1fa366a0',
		'longitude'           => 'field_67f9f2ff366a1',
		'is_premium_listing'  => 'field_67fa29cbd2c4a',
		'hide_this_post'      => 'field_67fa2969d2c49',
		'listing_type'        => 'field_67f9dc73e96c2',
		'haunt_type_primary'  => 'field_67f9f61d366a2',

		// Dates / hours + admin (Round 2).
		'dates_hours_text'        => 'field_67fa235bdc52d',
		'dates_hours_expiration'  => 'field_67fa2524dc531',
		'dates_hours_image'       => 'field_67fa24d8dc530',
		'calendar_iframe'         => 'field_67fa23e3dc52e',
		'internal_notes'          => 'field_67fa28bad2c47',
	);

	/** The six URL fields the worker fetches, keyed by source slug. */
	const URL_FIELDS = array(
		'website'         => 'website',
		'social_facebook' => 'facebook',
		'social_instagram'=> 'instagram',
		'social_tiktok'   => 'tiktok',
		'social_youtube'  => 'youtube_channel',
		'social_x'        => 'x_twitter',
	);

	/** Plugin-owned post meta (breadcrumb mirror + closure bookkeeping). */
	const PLUGIN_META = array(
		'last_verified'  => '_hlv_last_verified',  // unix ts of last successful crawl
		'last_status'    => '_hlv_last_status',    // operating_status from last finding
		'last_decision'  => '_hlv_last_decision',  // accepted|rejected|edited|pending
		'closure_reason' => '_hlv_closure_reason', // why a listing was set to draft/pending
		'finding_hash'   => '_hlv_finding_hash',   // fingerprint of last suggestion set
	);

	/**
	 * Resolve the human-readable state name for a listing from its `region`
	 * term (the `state` ACF taxonomy field).
	 *
	 * @param int $post_id Listing post ID.
	 * @return string Comma-joined region term names, or '' if none.
	 */
	public static function get_state_name( $post_id ) {
		$terms = get_the_terms( $post_id, self::REGION_TAXONOMY );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Build the per-listing snapshot the worker needs to crawl + classify.
	 * This is exactly the data sent in a job manifest — no extra PII.
	 *
	 * @param int $post_id Listing post ID.
	 * @return array
	 */
	public static function snapshot( $post_id ) {
		$urls = array();
		foreach ( self::URL_FIELDS as $source => $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $value ) ) {
				$urls[ $source ] = esc_url_raw( $value );
			}
		}

		return array(
			'listing_id'   => (int) $post_id,
			'name'         => get_the_title( $post_id ),
			'is_premium'   => (bool) get_post_meta( $post_id, self::META['is_premium_listing'], true ),
			'address'      => array(
				'street'  => get_post_meta( $post_id, self::META['street_address'], true ),
				'city'    => get_post_meta( $post_id, self::META['city'], true ),
				'state'   => self::get_state_name( $post_id ),
				'zip'     => get_post_meta( $post_id, self::META['zip'], true ),
				'country' => get_post_meta( $post_id, self::META['country_select'], true ),
			),
			'urls'         => $urls,
			// What we already know — so the worker doesn't re-flag known facts,
			// can reason about past vs. upcoming, and can show Before/After.
			'existing'     => array(
				'internal_notes'      => (string) get_post_meta( $post_id, self::META['internal_notes'], true ),
				'dates_hours_text'    => (string) get_post_meta( $post_id, self::META['dates_hours_text'], true ),
				'dates_hours_expires' => (string) get_post_meta( $post_id, self::META['dates_hours_expiration'], true ),
				'off_season_events'   => self::get_off_season_events( $post_id ),
			),
			'finding_hash' => (string) get_post_meta( $post_id, self::PLUGIN_META['finding_hash'], true ),
		);
	}

	/**
	 * Read existing off-season event rows (best effort) so the worker won't
	 * re-suggest dates we already have. Returns a list of
	 * { holiday, start, end, info } rows.
	 *
	 * @param int $post_id Listing post ID.
	 * @return array[]
	 */
	public static function get_off_season_events( $post_id ) {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}
		$rows = get_field( self::OFF_SEASON['repeater'], $post_id );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$holiday = isset( $row[ self::OFF_SEASON['sub']['holiday'] ] ) ? $row[ self::OFF_SEASON['sub']['holiday'] ] : '';
			if ( is_object( $holiday ) && isset( $holiday->name ) ) {
				$holiday = $holiday->name; // taxonomy return_format = object
			} elseif ( is_array( $holiday ) ) {
				$holiday = isset( $holiday['name'] ) ? $holiday['name'] : '';
			}
			$out[] = array(
				'holiday' => (string) $holiday,
				'start'   => isset( $row[ self::OFF_SEASON['sub']['start'] ] ) ? (string) $row[ self::OFF_SEASON['sub']['start'] ] : '',
				'end'     => isset( $row[ self::OFF_SEASON['sub']['end'] ] ) ? (string) $row[ self::OFF_SEASON['sub']['end'] ] : '',
				'info'    => isset( $row[ self::OFF_SEASON['sub']['info_basic'] ] ) ? (string) $row[ self::OFF_SEASON['sub']['info_basic'] ] : '',
			);
		}
		return $out;
	}
}
