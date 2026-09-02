<?php
/**
 * Plugin orchestrator. Wires hooks, loads i18n, and exposes the admin pages.
 * Kept thin — feature logic lives in the dedicated classes.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Plugin {

	/** Top-level admin menu slug. */
	const MENU_SLUG = 'hlv-dashboard';

	/** @var HLV_Plugin|null */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return HLV_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		HLV_Crawl_Triggers::init();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'manage_' . HLV_Field_Map::POST_TYPE . '_posts_columns', array( $this, 'add_list_column' ) );
			add_action( 'manage_' . HLV_Field_Map::POST_TYPE . '_posts_custom_column', array( $this, 'render_list_column' ), 10, 2 );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'haunt-listing-verifier', false, dirname( HLV_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Register the admin menu (Dashboard + Settings).
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Haunt Verifier', 'haunt-listing-verifier' ),
			__( 'Haunt Verifier', 'haunt-listing-verifier' ),
			HLV_Capabilities::CAP,
			self::MENU_SLUG,
			array( 'HLV_Dashboard', 'render' ),
			'dashicons-search',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Review Queue', 'haunt-listing-verifier' ),
			__( 'Review Queue', 'haunt-listing-verifier' ),
			HLV_Capabilities::CAP,
			self::MENU_SLUG,
			array( 'HLV_Dashboard', 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Verifier Settings', 'haunt-listing-verifier' ),
			__( 'Settings', 'haunt-listing-verifier' ),
			HLV_Capabilities::CAP,
			'hlv-settings',
			array( 'HLV_Settings', 'render_page' )
		);
	}

	/**
	 * Enqueue dashboard assets only on our screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'hlv-' ) && false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'hlv-admin', HLV_PLUGIN_URL . 'admin/css/hlv-admin.css', array(), HLV_VERSION );
		wp_enqueue_script( 'hlv-admin', HLV_PLUGIN_URL . 'admin/js/hlv-admin.js', array( 'jquery' ), HLV_VERSION, true );
		wp_localize_script(
			'hlv-admin',
			'HLV',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hlv_ajax' ),
				'i18n'    => array(
					'applying'   => __( 'Applying…', 'haunt-listing-verifier' ),
					'applied'    => __( 'Applied', 'haunt-listing-verifier' ),
					'failed'     => __( 'Failed', 'haunt-listing-verifier' ),
					'confirmDel' => __( 'Delete this listing? This moves it to Trash.', 'haunt-listing-verifier' ),
					'noteCleared'   => __( '(admin note cleared)', 'haunt-listing-verifier' ),
					'closureReason' => __( 'Closure reason (stored on the listing):', 'haunt-listing-verifier' ),
					'confirmDelFinding' => __( 'Delete this result? The listing itself is untouched, but this record goes away and the listing gets a fresh check on the next crawl.', 'haunt-listing-verifier' ),
					'confirmPurge'      => __( 'Clear these results? The listings themselves are untouched, but every matching record is deleted and those listings will be re-checked on the next crawl.', 'haunt-listing-verifier' ),
					'working'           => __( 'Working…', 'haunt-listing-verifier' ),
				),
			)
		);
	}

	/**
	 * Add a "Last verified" column to the listing list table (breadcrumb mirror).
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_list_column( $columns ) {
		$columns['hlv_verified'] = __( 'Last verified', 'haunt-listing-verifier' );
		return $columns;
	}

	/**
	 * Render the breadcrumb column.
	 *
	 * @param string $column  Column slug.
	 * @param int    $post_id Listing ID.
	 */
	public function render_list_column( $column, $post_id ) {
		if ( 'hlv_verified' !== $column ) {
			return;
		}
		$ts = (int) get_post_meta( $post_id, HLV_Field_Map::PLUGIN_META['last_verified'], true );
		if ( ! $ts ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		$status = get_post_meta( $post_id, HLV_Field_Map::PLUGIN_META['last_status'], true );
		echo esc_html(
			sprintf(
				/* translators: 1: human time diff, 2: operating status */
				__( '%1$s ago (%2$s)', 'haunt-listing-verifier' ),
				human_time_diff( $ts ),
				$status ? $status : 'unknown'
			)
		);
	}
}
