<?php
/**
 * Capability management. Every dashboard action and Ajax handler is gated on
 * HLV_Capabilities::CAP. For now it is granted to Administrators only; a
 * settings option can grant it to additional roles (Editor, etc.) later.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Capabilities {

	/** The custom capability all verifier actions require. */
	const CAP = 'manage_haunt_verifier';

	/**
	 * Grant the capability to Administrators on activation, plus any extra
	 * roles already saved in settings.
	 */
	public static function add_caps() {
		$roles = array_merge( array( 'administrator' ), self::extra_roles() );
		foreach ( array_unique( $roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	/**
	 * Remove the capability from every role (used when re-syncing role grants).
	 */
	public static function remove_caps() {
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::CAP );
			}
		}
	}

	/**
	 * Re-apply grants from settings. Call after the allowed-roles setting changes.
	 */
	public static function sync_from_settings() {
		self::remove_caps();
		self::add_caps();
	}

	/**
	 * Additional roles (besides administrator) granted the cap, from settings.
	 *
	 * @return string[]
	 */
	private static function extra_roles() {
		$settings = get_option( 'hlv_settings', array() );
		$roles    = isset( $settings['allowed_roles'] ) ? (array) $settings['allowed_roles'] : array();
		return array_filter( array_map( 'sanitize_key', $roles ) );
	}

	/**
	 * Convenience guard used throughout the admin layer.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( self::CAP );
	}
}
