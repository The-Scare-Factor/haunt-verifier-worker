<?php
/**
 * Settings: worker connection, model posture, image aggressiveness, no-signal
 * handling, auto-approve gating, allowed roles, and the cron schedule. The
 * Anthropic + Google keys are NOT stored here — they live in the worker's
 * Sevalla environment. WP holds only the worker URL + HMAC secret.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Settings {

	const OPTION = 'hlv_settings';

	/**
	 * Default settings (also the lazy fallback for any missing key).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Worker connection.
			'worker_url'           => '',
			'worker_secret'        => '', // Prefer the HLV_WORKER_SECRET wp-config constant.

			// Tiered model posture (configurable, never hardcoded in the worker).
			'text_model'           => 'claude-haiku-4-5',
			'image_model'          => 'claude-opus-4-8',

			// Image intelligence.
			'image_aggressiveness' => 'plausible', // off | weak_signal_only | plausible | all
			'max_flyers'           => 4,

			// Cost / no-signal.
			'no_signal_mode'       => 'manual_queue', // manual_queue | cheap_pass
			'concurrency'          => 6,

			// Auto-approve (OFF by default; conservative when enabled).
			'auto_approve_enabled' => 0,
			'auto_approve_classes' => array( 'no_change' ), // whitelist; "no change" only
			'auto_approve_min_conf'=> 'high',

			// Access.
			'allowed_roles'        => array(), // extra roles beyond administrator

			// Cron.
			'cron_enabled'         => 0,
			'cron_recurrence'      => 'weekly',
			'cron_scope'           => 'all',  // all | region
			'cron_region_terms'    => array(),
		);
	}

	/**
	 * Read merged settings.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Register settings + the weekly cron recurrence used by the scheduler.
	 */
	public static function init() {
		register_setting( 'hlv_settings_group', self::OPTION, array( __CLASS__, 'sanitize' ) );
		// Side effects run AFTER WordPress saves the option — never inside the
		// sanitize callback, which would recurse. add_option covers the first
		// save; update_option covers every later save.
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'after_save' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'after_save' ) );
	}

	/**
	 * Re-sync role grants and reschedule cron after the settings are persisted.
	 * Runs post-save so HLV_Settings::get() reflects the new values.
	 */
	public static function after_save() {
		HLV_Capabilities::sync_from_settings();
		HLV_Crawl_Triggers::reschedule();
	}

	/**
	 * Add a 'weekly' cron recurrence if the install lacks one.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'haunt-listing-verifier' ),
			);
		}
		return $schedules;
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = self::get();

		$out['worker_url']    = isset( $input['worker_url'] ) ? esc_url_raw( trim( $input['worker_url'] ) ) : $out['worker_url'];
		if ( isset( $input['worker_secret'] ) && '' !== trim( $input['worker_secret'] ) ) {
			$out['worker_secret'] = sanitize_text_field( $input['worker_secret'] );
		}

		$models = array( 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8', 'claude-fable-5' );
		$out['text_model']  = ( isset( $input['text_model'] ) && in_array( $input['text_model'], $models, true ) ) ? $input['text_model'] : $d['text_model'];
		$out['image_model'] = ( isset( $input['image_model'] ) && in_array( $input['image_model'], $models, true ) ) ? $input['image_model'] : $d['image_model'];

		$aggr = array( 'off', 'weak_signal_only', 'plausible', 'all' );
		$out['image_aggressiveness'] = ( isset( $input['image_aggressiveness'] ) && in_array( $input['image_aggressiveness'], $aggr, true ) ) ? $input['image_aggressiveness'] : $d['image_aggressiveness'];
		$out['max_flyers']           = isset( $input['max_flyers'] ) ? max( 0, min( 10, (int) $input['max_flyers'] ) ) : $d['max_flyers'];

		$out['no_signal_mode'] = ( isset( $input['no_signal_mode'] ) && in_array( $input['no_signal_mode'], array( 'manual_queue', 'cheap_pass' ), true ) ) ? $input['no_signal_mode'] : $d['no_signal_mode'];
		$out['concurrency']    = isset( $input['concurrency'] ) ? max( 1, min( 24, (int) $input['concurrency'] ) ) : $d['concurrency'];

		$out['auto_approve_enabled'] = empty( $input['auto_approve_enabled'] ) ? 0 : 1;
		$out['auto_approve_min_conf']= ( isset( $input['auto_approve_min_conf'] ) && in_array( $input['auto_approve_min_conf'], array( 'high', 'medium' ), true ) ) ? $input['auto_approve_min_conf'] : 'high';
		$out['auto_approve_classes'] = isset( $input['auto_approve_classes'] ) ? array_map( 'sanitize_key', (array) $input['auto_approve_classes'] ) : $d['auto_approve_classes'];

		$valid_roles           = array_keys( get_editable_roles() );
		$roles                 = isset( $input['allowed_roles'] ) ? array_map( 'sanitize_key', (array) $input['allowed_roles'] ) : array();
		$out['allowed_roles']  = array_values( array_intersect( $roles, $valid_roles ) );

		$out['cron_enabled']      = empty( $input['cron_enabled'] ) ? 0 : 1;
		$out['cron_recurrence']   = isset( $input['cron_recurrence'] ) ? sanitize_key( $input['cron_recurrence'] ) : $d['cron_recurrence'];
		$out['cron_scope']        = ( isset( $input['cron_scope'] ) && in_array( $input['cron_scope'], array( 'all', 'region' ), true ) ) ? $input['cron_scope'] : $d['cron_scope'];
		$out['cron_region_terms'] = isset( $input['cron_region_terms'] ) ? array_map( 'intval', (array) $input['cron_region_terms'] ) : array();

		// IMPORTANT: do NOT call update_option() here. This method IS the
		// sanitize_option_{$option} callback, so writing the option inside it
		// re-enters sanitize() and recurses until memory is exhausted (fatal).
		// WordPress persists the value we RETURN; the role-grant + cron side
		// effects run on the add_option/update_option hooks set up in init().
		return $out;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! HLV_Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to manage the verifier.', 'haunt-listing-verifier' ) );
		}
		$s        = self::get();
		$secret_const = defined( 'HLV_WORKER_SECRET' ) && HLV_WORKER_SECRET;
		$ping     = null;
		if ( isset( $_GET['hlv_ping'] ) && check_admin_referer( 'hlv_ping' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ping = HLV_Worker_Client::ping();
		}
		?>
		<div class="wrap hlv-settings">
			<h1><?php esc_html_e( 'Haunt Verifier — Settings', 'haunt-listing-verifier' ); ?></h1>

			<?php if ( null !== $ping ) : ?>
				<div class="notice <?php echo is_wp_error( $ping ) ? 'notice-error' : 'notice-success'; ?>">
					<p><?php echo is_wp_error( $ping ) ? esc_html( $ping->get_error_message() ) : esc_html__( 'Worker reachable.', 'haunt-listing-verifier' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'hlv_settings_group' ); ?>

				<h2><?php esc_html_e( 'Worker connection', 'haunt-listing-verifier' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Anthropic & Google keys live in the worker\'s Sevalla environment — never here.', 'haunt-listing-verifier' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="hlv_worker_url"><?php esc_html_e( 'Worker URL', 'haunt-listing-verifier' ); ?></label></th>
						<td><input type="url" id="hlv_worker_url" name="<?php echo esc_attr( self::OPTION ); ?>[worker_url]" value="<?php echo esc_attr( $s['worker_url'] ); ?>" class="regular-text" placeholder="https://haunt-verifier-worker.sevalla.app"></td>
					</tr>
					<tr>
						<th><label for="hlv_worker_secret"><?php esc_html_e( 'HMAC shared secret', 'haunt-listing-verifier' ); ?></label></th>
						<td>
							<?php if ( $secret_const ) : ?>
								<em><?php esc_html_e( 'Set via the HLV_WORKER_SECRET constant in wp-config.php (recommended).', 'haunt-listing-verifier' ); ?></em>
							<?php else : ?>
								<input type="password" id="hlv_worker_secret" name="<?php echo esc_attr( self::OPTION ); ?>[worker_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['worker_secret'] ? esc_attr__( '•••••• (leave blank to keep)', 'haunt-listing-verifier' ) : ''; ?>">
								<p class="description"><?php esc_html_e( 'Better: define HLV_WORKER_SECRET in wp-config.php instead of storing it here.', 'haunt-listing-verifier' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'hlv_ping', '1' ), 'hlv_ping' ) ); ?>"><?php esc_html_e( 'Test connection', 'haunt-listing-verifier' ); ?></a>
				</p>

				<h2><?php esc_html_e( 'AI model posture (tiered)', 'haunt-listing-verifier' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Text model', 'haunt-listing-verifier' ); ?></th>
						<td><?php self::model_select( 'text_model', $s['text_model'] ); ?>
							<p class="description"><?php esc_html_e( 'Cheap pass for plain-text classification.', 'haunt-listing-verifier' ); ?></p></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Image / escalation model', 'haunt-listing-verifier' ); ?></th>
						<td><?php self::model_select( 'image_model', $s['image_model'] ); ?>
							<p class="description"><?php esc_html_e( 'Vision model for flyers & low-confidence escalation (Opus 4.8 recommended; Fable 5 for the hardest stylized art).', 'haunt-listing-verifier' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Image analysis & cost', 'haunt-listing-verifier' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Image aggressiveness', 'haunt-listing-verifier' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[image_aggressiveness]">
								<?php
								$opts = array(
									'off'              => __( 'Off — never read images', 'haunt-listing-verifier' ),
									'weak_signal_only' => __( 'Only when text signal is weak/missing', 'haunt-listing-verifier' ),
									'plausible'        => __( 'Read plausible flyers (recommended)', 'haunt-listing-verifier' ),
									'all'              => __( 'Read every plausible image', 'haunt-listing-verifier' ),
								);
								foreach ( $opts as $val => $label ) {
									printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['image_aggressiveness'], $val, false ), esc_html( $label ) );
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Max flyers per source', 'haunt-listing-verifier' ); ?></th>
						<td><input type="number" min="0" max="10" name="<?php echo esc_attr( self::OPTION ); ?>[max_flyers]" value="<?php echo esc_attr( $s['max_flyers'] ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'No-signal handling', 'haunt-listing-verifier' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[no_signal_mode]" value="manual_queue" <?php checked( $s['no_signal_mode'], 'manual_queue' ); ?>> <?php esc_html_e( 'Route to manual queue, no AI call (saves tokens)', 'haunt-listing-verifier' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[no_signal_mode]" value="cheap_pass" <?php checked( $s['no_signal_mode'], 'cheap_pass' ); ?>> <?php esc_html_e( 'Spend one cheap AI pass anyway', 'haunt-listing-verifier' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Worker concurrency', 'haunt-listing-verifier' ); ?></th>
						<td><input type="number" min="1" max="24" name="<?php echo esc_attr( self::OPTION ); ?>[concurrency]" value="<?php echo esc_attr( $s['concurrency'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Auto-approve (off by default)', 'haunt-listing-verifier' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Enable auto-approve', 'haunt-listing-verifier' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_approve_enabled]" value="1" <?php checked( $s['auto_approve_enabled'], 1 ); ?>> <?php esc_html_e( 'Allow auto-approval for whitelisted change classes only', 'haunt-listing-verifier' ); ?></label>
							<p class="description"><?php esc_html_e( 'Website/social durable-fact conflicts can never auto-approve. Default whitelist: "no change" confirmations only.', 'haunt-listing-verifier' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Access', 'haunt-listing-verifier' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Additional roles', 'haunt-listing-verifier' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Administrators always have access. Grant additional roles here.', 'haunt-listing-verifier' ); ?></p>
							<?php
							foreach ( get_editable_roles() as $role_key => $role ) {
								if ( 'administrator' === $role_key ) {
									continue;
								}
								printf(
									'<label style="display:inline-block;margin-right:1em;"><input type="checkbox" name="%1$s[allowed_roles][]" value="%2$s" %3$s> %4$s</label>',
									esc_attr( self::OPTION ),
									esc_attr( $role_key ),
									checked( in_array( $role_key, $s['allowed_roles'], true ), true, false ),
									esc_html( translate_user_role( $role['name'] ) )
								);
							}
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Scheduled crawl', 'haunt-listing-verifier' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Enable cron', 'haunt-listing-verifier' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[cron_enabled]" value="1" <?php checked( $s['cron_enabled'], 1 ); ?>> <?php esc_html_e( 'Run a recurring crawl (uses the Batch API for the 50% discount)', 'haunt-listing-verifier' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Recurrence', 'haunt-listing-verifier' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[cron_recurrence]">
								<?php
								foreach ( array( 'weekly' => __( 'Weekly', 'haunt-listing-verifier' ), 'monthly' => __( 'Monthly', 'haunt-listing-verifier' ) ) as $val => $label ) {
									// 'monthly' may not exist on all installs; the scheduler falls back gracefully.
									printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['cron_recurrence'], $val, false ), esc_html( $label ) );
								}
								?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a model <select>.
	 *
	 * @param string $key     Setting key.
	 * @param string $current Current value.
	 */
	private static function model_select( $key, $current ) {
		$models = array(
			'claude-haiku-4-5'  => 'Claude Haiku 4.5 (cheapest)',
			'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
			'claude-opus-4-8'   => 'Claude Opus 4.8 (vision, recommended)',
			'claude-fable-5'    => 'Claude Fable 5 (most capable)',
		);
		echo '<select name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $models as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $current, $val, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}

add_action( 'admin_init', array( 'HLV_Settings', 'init' ) );
// The cron schedule must be available in non-admin (WP-Cron) context too.
add_filter( 'cron_schedules', array( 'HLV_Settings', 'add_weekly_schedule' ) );
