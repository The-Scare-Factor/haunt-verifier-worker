<?php
/**
 * Review dashboard: summary landing, crawl triggers (all / by region / one-click
 * Indiana pilot), and the High / Medium / Low review queues with expandable
 * rows, Before/After clarity, citations, inline edit fields, and Ajax actions.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Dashboard {

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! HLV_Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to view the verifier.', 'haunt-listing-verifier' ) );
		}

		$notice = self::maybe_handle_trigger();
		?>
		<div class="wrap hlv-dashboard">
			<h1><?php esc_html_e( 'Haunt Verifier — Review Queue', 'haunt-listing-verifier' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice <?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! HLV_Worker_Client::is_configured() ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: settings URL */
						wp_kses_post( __( 'The verifier worker is not configured yet. <a href="%s">Add the worker URL and secret in Settings</a>.', 'haunt-listing-verifier' ) ),
						esc_url( admin_url( 'admin.php?page=hlv-settings' ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php self::render_triggers(); ?>
			<?php self::render_clear_results(); ?>
			<?php self::render_summary(); ?>
			<?php self::render_queues(); ?>
			<?php self::render_archive(); ?>
		</div>
		<?php
	}

	/**
	 * Handle a posted crawl trigger.
	 *
	 * @return array|null Notice { type, message } or null.
	 */
	private static function maybe_handle_trigger() {
		if ( empty( $_POST['hlv_action'] ) || 'start_crawl' !== $_POST['hlv_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}
		if ( ! HLV_Capabilities::current_user_can() || ! check_admin_referer( 'hlv_start_crawl' ) ) {
			return array(
				'type'    => 'notice-error',
				'message' => __( 'Security check failed.', 'haunt-listing-verifier' ),
			);
		}

		$scope        = isset( $_POST['hlv_scope'] ) ? sanitize_key( $_POST['hlv_scope'] ) : 'all';
		$region_terms = isset( $_POST['hlv_region_terms'] ) ? array_map( 'intval', (array) $_POST['hlv_region_terms'] ) : array();
		$image_notes  = isset( $_POST['hlv_image_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hlv_image_instructions'] ) ) : '';

		// One-click Indiana pilot resolves the Indiana region term.
		if ( 'indiana_pilot' === $scope ) {
			$term  = get_term_by( 'name', 'Indiana', HLV_Field_Map::REGION_TAXONOMY );
			$scope = 'region';
			$region_terms = $term ? array( (int) $term->term_id ) : array();
			if ( empty( $region_terms ) ) {
				return array(
					'type'    => 'notice-error',
					'message' => __( 'Could not find an "Indiana" region term.', 'haunt-listing-verifier' ),
				);
			}
		}

		// Processing mode is per run: batch is half price but asynchronous, so an
		// on-demand region sweep can still be run interactively when you want the
		// findings in front of you now.
		$mode = isset( $_POST['hlv_mode'] ) ? sanitize_key( $_POST['hlv_mode'] ) : 'batch';
		if ( ! in_array( $mode, array( 'batch', 'interactive' ), true ) ) {
			$mode = 'batch';
		}

		$overrides = array(
			'mode'               => $mode,
			'image_instructions' => $image_notes,
		);
		// "Force re-check" turns off the unchanged-skip so every in-scope listing
		// is re-classified (e.g. after a prompt/rules change, when the page content
		// itself hasn't moved).
		if ( ! empty( $_POST['hlv_force_recheck'] ) ) {
			$overrides['skip_unchanged'] = false;
		}

		$result = HLV_Crawl_Triggers::start_crawl(
			array(
				'scope'                => $scope,
				'region_terms'         => $region_terms,
				'run_config_overrides' => $overrides,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'type'    => 'notice-error',
				'message' => $result->get_error_message(),
			);
		}

		$message = 'batch' === $mode
			? sprintf(
				/* translators: 1: listing count, 2: run id */
				__( 'Batch crawl queued for %1$d listings (run %2$s). The worker crawls them now and submits the AI checks at the 50%% batch rate — findings usually land within the hour. This page shows the run status while it waits.', 'haunt-listing-verifier' ),
				(int) $result['count'],
				$result['run_id']
			)
			: sprintf(
				/* translators: 1: listing count, 2: run id */
				__( 'Crawl queued for %1$d listings (run %2$s) at full price. Findings appear below as the worker processes them.', 'haunt-listing-verifier' ),
				(int) $result['count'],
				$result['run_id']
			);

		return array(
			'type'    => 'notice-success',
			'message' => $message,
		);
	}

	/**
	 * Crawl trigger controls.
	 */
	private static function render_triggers() {
		$regions = get_terms(
			array(
				'taxonomy'   => HLV_Field_Map::REGION_TAXONOMY,
				'hide_empty' => false,
			)
		);
		?>
		<div class="hlv-card hlv-triggers">
			<h2><?php esc_html_e( 'Start a crawl', 'haunt-listing-verifier' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'hlv_start_crawl' ); ?>
				<input type="hidden" name="hlv_action" value="start_crawl">
				<p>
					<label><input type="radio" name="hlv_scope" value="indiana_pilot" checked> <strong><?php esc_html_e( 'Indiana pilot', 'haunt-listing-verifier' ); ?></strong> <?php esc_html_e( '(recommended first run)', 'haunt-listing-verifier' ); ?></label><br>
					<label><input type="radio" name="hlv_scope" value="region"> <?php esc_html_e( 'Selected region(s)', 'haunt-listing-verifier' ); ?></label>
					<?php if ( ! is_wp_error( $regions ) && $regions ) : ?>
						<select name="hlv_region_terms[]" multiple size="4" style="min-width:200px;vertical-align:middle;">
							<?php foreach ( $regions as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?> (<?php echo (int) $term->count; ?>)</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<br>
					<label><input type="radio" name="hlv_scope" value="all"> <?php esc_html_e( 'Entire Listings CPT', 'haunt-listing-verifier' ); ?></label>
				</p>
				<p>
					<label for="hlv_image_instructions"><?php esc_html_e( 'Per-run image guidance (optional):', 'haunt-listing-verifier' ); ?></label><br>
					<textarea id="hlv_image_instructions" name="hlv_image_instructions" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Watch for 2026 season dates and new-owner announcements on flyers.', 'haunt-listing-verifier' ); ?>"></textarea>
				</p>
				<p>
					<strong><?php esc_html_e( 'Processing mode', 'haunt-listing-verifier' ); ?></strong><br>
					<label><input type="radio" name="hlv_mode" value="batch" checked> <?php esc_html_e( 'Batch — half price, results usually within an hour', 'haunt-listing-verifier' ); ?></label><br>
					<label><input type="radio" name="hlv_mode" value="interactive"> <?php esc_html_e( 'Interactive — full price, findings appear as each listing finishes', 'haunt-listing-verifier' ); ?></label><br>
					<span class="description"><?php esc_html_e( 'Both modes crawl identically and produce identical findings; only the AI billing rate and the wait differ. Use interactive for a handful of listings you want to watch, batch for anything bigger.', 'haunt-listing-verifier' ); ?></span>
				</p>
				<p>
					<label><input type="checkbox" name="hlv_force_recheck" value="1"> <strong><?php esc_html_e( 'Force re-check every listing', 'haunt-listing-verifier' ); ?></strong></label><br>
					<span class="description"><?php esc_html_e( 'Normally listings whose website/social haven\'t changed are skipped to save cost. Tick this to re-read and re-classify ALL of them anyway — use it after changing the AI\'s rules. Costs more.', 'haunt-listing-verifier' ); ?></span>
				</p>
				<?php submit_button( __( 'Start crawl', 'haunt-listing-verifier' ), 'primary', 'submit', false, HLV_Worker_Client::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * "Clean slate" control: bulk-clear results before re-running with changed
	 * instructions, so a new run comes back without old suggestions mixed in.
	 */
	private static function render_clear_results() {
		if ( ! HLV_Worker_Client::is_configured() ) {
			return;
		}
		$last_run = get_option( 'hlv_last_run_id', '' );
		?>
		<div class="hlv-card hlv-clear-results">
			<h2><?php esc_html_e( 'Clear results', 'haunt-listing-verifier' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Deletes results outright and resets those listings\' crawl state, so the next run re-reads them from scratch and produces brand-new records. Use this after changing the AI\'s instructions, so the next queue isn\'t muddied by findings the old rules produced. Nothing on your live listings is touched.', 'haunt-listing-verifier' ); ?>
			</p>
			<p>
				<label for="hlv_purge_what"><?php esc_html_e( 'Clear:', 'haunt-listing-verifier' ); ?></label>
				<select id="hlv_purge_what">
					<option value="status:pending"><?php esc_html_e( 'All results still awaiting review', 'haunt-listing-verifier' ); ?></option>
					<option value="confidence:low"><?php esc_html_e( 'Low-confidence results only', 'haunt-listing-verifier' ); ?></option>
					<option value="confidence:medium"><?php esc_html_e( 'Medium-confidence results only', 'haunt-listing-verifier' ); ?></option>
					<option value="confidence:high"><?php esc_html_e( 'High-confidence results only', 'haunt-listing-verifier' ); ?></option>
					<option value="status:ignored"><?php esc_html_e( 'Everything in Past findings', 'haunt-listing-verifier' ); ?></option>
					<?php if ( $last_run ) : ?>
						<option value="run:<?php echo esc_attr( $last_run ); ?>"><?php esc_html_e( 'Everything from the last run', 'haunt-listing-verifier' ); ?></option>
					<?php endif; ?>
				</select>
				<button type="button" class="button button-link-delete hlv-purge"><?php esc_html_e( 'Clear these results', 'haunt-listing-verifier' ); ?></button>
				<span class="hlv-detail-status hlv-purge-status" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Past findings — results parked with "Ignore", kept for a later look.
	 */
	private static function render_archive() {
		if ( ! HLV_Worker_Client::is_configured() ) {
			return;
		}
		$findings = HLV_Worker_Client::get_findings(
			array(
				'status'   => 'ignored',
				'per_page' => 50,
			)
		);
		if ( is_wp_error( $findings ) ) {
			return;
		}
		$items = isset( $findings['items'] ) ? $findings['items'] : array();
		?>
		<div class="hlv-card hlv-queue hlv-archive">
			<h2><?php esc_html_e( 'Past findings', 'haunt-listing-verifier' ); ?> <span class="hlv-count">(<?php echo count( $items ); ?>)</span></h2>
			<p class="description"><?php esc_html_e( 'Results you set aside. They stay out of the queues above until you restore one, or until the haunt\'s own website/social changes.', 'haunt-listing-verifier' ); ?></p>
			<?php if ( empty( $items ) ) : ?>
				<p class="hlv-empty"><?php esc_html_e( 'Nothing has been ignored yet.', 'haunt-listing-verifier' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $items as $f ) : ?>
						<?php self::render_finding_row( (array) $f, 'archive' ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Run summary card (counts + token spend split for the last run).
	 */
	private static function render_summary() {
		$run_id = get_option( 'hlv_last_run_id', '' );
		if ( ! $run_id || ! HLV_Worker_Client::is_configured() ) {
			return;
		}
		$run = HLV_Worker_Client::get_run( $run_id );
		if ( is_wp_error( $run ) ) {
			return;
		}
		// Once a run finishes, mirror its breadcrumbs into post meta (once per run).
		if ( isset( $run['status'] ) && 'done' === $run['status'] ) {
			self::maybe_sync_breadcrumbs( $run_id );
		}
		$s = isset( $run['summary'] ) ? $run['summary'] : array();
		?>
		<div class="hlv-card hlv-summary">
			<h2>
				<?php esc_html_e( 'Last run', 'haunt-listing-verifier' ); ?> <code><?php echo esc_html( $run_id ); ?></code>
				<?php if ( ! empty( $run['mode'] ) && 'batch' === $run['mode'] ) : ?>
					<span class="hlv-badge"><?php esc_html_e( 'Batch — 50% rate', 'haunt-listing-verifier' ); ?></span>
				<?php endif; ?>
			</h2>

			<?php if ( isset( $run['status'] ) && 'awaiting_batch' === $run['status'] ) : ?>
				<p class="hlv-batch-waiting">
					<?php
					$submitted = ! empty( $run['batch_submitted_at'] ) ? strtotime( $run['batch_submitted_at'] ) : 0;
					if ( $submitted ) {
						printf(
							/* translators: %s: human-readable time difference, e.g. "12 mins" */
							esc_html__( 'Crawling is done and the AI checks are with Anthropic — submitted %s ago. Most batches finish within an hour (24 hours at the outside). Findings appear here automatically; nothing further to do.', 'haunt-listing-verifier' ),
							esc_html( human_time_diff( $submitted, time() ) )
						);
					} else {
						esc_html_e( 'Crawling is done and the AI checks are with Anthropic. Most batches finish within an hour. Findings appear here automatically.', 'haunt-listing-verifier' );
					}
					?>
				</p>
			<?php endif; ?>

			<ul class="hlv-stats">
				<?php
				$stats = array(
					'status'          => __( 'Status', 'haunt-listing-verifier' ),
					'listings'        => __( 'Listings', 'haunt-listing-verifier' ),
					'fetched_ok'      => __( 'Fetched OK', 'haunt-listing-verifier' ),
					'dead_urls'       => __( 'Dead URLs', 'haunt-listing-verifier' ),
					'images_analyzed' => __( 'Images analyzed', 'haunt-listing-verifier' ),
					'suggested'       => __( 'Suggested changes', 'haunt-listing-verifier' ),
					'no_signal'       => __( 'No-signal / manual', 'haunt-listing-verifier' ),
					'skipped'         => __( 'Skipped (unchanged)', 'haunt-listing-verifier' ),
					'errors'          => __( 'Errors', 'haunt-listing-verifier' ),
				);
				foreach ( $stats as $key => $label ) {
					$val = isset( $run[ $key ] ) ? $run[ $key ] : ( isset( $s[ $key ] ) ? $s[ $key ] : '—' );
					printf( '<li><span class="hlv-stat-label">%s</span><span class="hlv-stat-val">%s</span></li>', esc_html( $label ), esc_html( is_scalar( $val ) ? $val : '—' ) );
				}
				$text_tokens = isset( $s['text_tokens'] ) ? (int) $s['text_tokens'] : 0;
				$img_tokens  = isset( $s['image_tokens'] ) ? (int) $s['image_tokens'] : 0;
				printf(
					'<li><span class="hlv-stat-label">%s</span><span class="hlv-stat-val">%s</span></li>',
					esc_html__( 'Tokens (text / image)', 'haunt-listing-verifier' ),
					esc_html( number_format_i18n( $text_tokens ) . ' / ' . number_format_i18n( $img_tokens ) )
				);
				?>
			</ul>
		</div>
		<?php
	}

	/**
	 * After a run completes, pull its per-listing breadcrumbs from the worker and
	 * mirror them into post meta. Guarded so each run syncs at most once (the
	 * sync-id list is capped so the option can't grow without bound).
	 *
	 * @param string $run_id Run identifier.
	 */
	private static function maybe_sync_breadcrumbs( $run_id ) {
		$synced = (array) get_option( 'hlv_synced_runs', array() );
		if ( in_array( $run_id, $synced, true ) ) {
			return;
		}
		$res = HLV_Worker_Client::get_run_breadcrumbs( $run_id );
		if ( is_wp_error( $res ) || empty( $res['items'] ) ) {
			return;
		}
		foreach ( $res['items'] as $crumb ) {
			if ( ! empty( $crumb['listing_id'] ) ) {
				HLV_Listing_Writer::write_mirror( (int) $crumb['listing_id'], (array) $crumb );
			}
		}
		$synced[] = $run_id;
		update_option( 'hlv_synced_runs', array_slice( $synced, -50 ), false );
	}

	/**
	 * The confidence queues (action needed), plus a demoted "Confirmations"
	 * section for no-change findings (notifications only, below the tiers).
	 */
	private static function render_queues() {
		if ( ! HLV_Worker_Client::is_configured() ) {
			return;
		}
		$tiers = array(
			'high'   => __( 'High confidence', 'haunt-listing-verifier' ),
			'medium' => __( 'Medium confidence', 'haunt-listing-verifier' ),
			'low'    => __( 'Low confidence / needs a look', 'haunt-listing-verifier' ),
		);
		$confirmations = array();

		foreach ( $tiers as $tier => $label ) {
			$findings = HLV_Worker_Client::get_findings(
				array(
					'confidence' => $tier,
					'status'     => 'pending',
					'per_page'   => 50,
				)
			);
			if ( is_wp_error( $findings ) ) {
				printf(
					'<div class="hlv-card hlv-queue hlv-queue-%s"><h2>%s</h2><p class="hlv-error">%s</p></div>',
					esc_attr( $tier ),
					esc_html( $label ),
					esc_html( $findings->get_error_message() )
				);
				continue;
			}
			$items      = isset( $findings['items'] ) ? $findings['items'] : array();
			$actionable = array();
			foreach ( $items as $f ) {
				if ( self::is_confirmation( $f ) ) {
					$confirmations[] = $f;
				} else {
					$actionable[] = $f;
				}
			}
			self::render_queue_card( $tier, $label, $actionable );
		}

		self::render_confirmations( $confirmations );
	}

	/**
	 * Render one confidence-tier card of action-needed findings.
	 *
	 * @param string $tier  Tier slug.
	 * @param string $label Tier label.
	 * @param array  $items Findings.
	 */
	private static function render_queue_card( $tier, $label, array $items ) {
		?>
		<div class="hlv-card hlv-queue hlv-queue-<?php echo esc_attr( $tier ); ?>">
			<h2><?php echo esc_html( $label ); ?> <span class="hlv-count">(<?php echo count( $items ); ?>)</span></h2>
			<?php if ( empty( $items ) ) : ?>
				<p class="hlv-empty"><?php esc_html_e( 'Nothing needs action in this tier.', 'haunt-listing-verifier' ); ?></p>
			<?php else : ?>
				<table class="widefat hlv-findings">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Listing', 'haunt-listing-verifier' ); ?></th>
							<th><?php esc_html_e( 'What changed', 'haunt-listing-verifier' ); ?></th>
							<th class="hlv-col-sources"><?php esc_html_e( 'Sources', 'haunt-listing-verifier' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $finding ) { self::render_finding_row( $finding, 'queue' ); } ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Demoted notifications: no-change confirmations, below the tiers, collapsed.
	 *
	 * @param array $items Confirmation findings.
	 */
	private static function render_confirmations( array $items ) {
		?>
		<div class="hlv-card hlv-queue hlv-queue-confirm">
			<h2><?php esc_html_e( 'Confirmations — no action needed', 'haunt-listing-verifier' ); ?> <span class="hlv-count">(<?php echo count( $items ); ?>)</span></h2>
			<p class="description"><?php esc_html_e( 'The AI checked these and found nothing to change. Open one only if you want to make a correction; otherwise dismiss it.', 'haunt-listing-verifier' ); ?></p>
			<?php if ( empty( $items ) ) : ?>
				<p class="hlv-empty"><?php esc_html_e( 'No confirmations pending.', 'haunt-listing-verifier' ); ?></p>
			<?php else : ?>
				<table class="widefat hlv-findings">
					<tbody>
						<?php foreach ( $items as $finding ) { self::render_finding_row( $finding, 'confirmation' ); } ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Is this finding a no-change confirmation (nothing to apply, no status
	 * action, no conflict, not an unreadable/no-signal manual case)?
	 *
	 * @param array $f Finding.
	 * @return bool
	 */
	private static function is_confirmation( array $f ) {
		$status = isset( $f['operating_status'] ) ? $f['operating_status'] : '';
		if ( in_array( $status, array( 'closed_permanently', 'closed_for_season', 'relocated' ), true ) ) {
			return false;
		}
		if ( ! empty( $f['durable_fact_conflict'] ) || ! empty( $f['no_signal'] ) ) {
			return false; // conflicts and unreadable cases still need a human look.
		}
		foreach ( (array) ( isset( $f['findings'] ) ? $f['findings'] : array() ) as $d ) {
			$field = isset( $d['field'] ) ? $d['field'] : '';
			$label = isset( $d['change_label'] ) ? $d['change_label'] : '';
			if ( 'no_change' !== $label && ! in_array( $field, array( '', 'status', 'other' ), true ) ) {
				return false; // has an applyable suggestion.
			}
		}
		return true;
	}

	/**
	 * Render a single expandable finding row.
	 *
	 * @param array  $f    Finding payload from the worker.
	 * @param string $mode 'queue' | 'confirmation' | 'archive'.
	 */
	private static function render_finding_row( array $f, $mode = 'queue' ) {
		$listing_id = isset( $f['listing_id'] ) ? (int) $f['listing_id'] : 0;
		$title      = $listing_id ? get_the_title( $listing_id ) : ( isset( $f['name'] ) ? $f['name'] : '' );
		$is_premium = ! empty( $f['is_premium'] );
		$conflict   = ! empty( $f['durable_fact_conflict'] );
		$labels     = isset( $f['labels'] ) ? (array) $f['labels'] : array();
		$findings   = isset( $f['findings'] ) ? (array) $f['findings'] : array();
		$op_status  = isset( $f['operating_status'] ) ? $f['operating_status'] : '';
		$row_id     = 'hlv-f-' . ( isset( $f['finding_id'] ) ? $f['finding_id'] : $listing_id );
		$admin_note = $listing_id ? (string) get_post_meta( $listing_id, HLV_Field_Map::META['internal_notes'], true ) : '';
		$note_flag  = ! empty( $f['admin_note_relevant'] );
		?>
		<tr class="hlv-finding-row" data-finding="<?php echo esc_attr( isset( $f['finding_id'] ) ? $f['finding_id'] : '' ); ?>" data-listing="<?php echo esc_attr( $listing_id ); ?>" data-run="<?php echo esc_attr( isset( $f['run_id'] ) ? $f['run_id'] : '' ); ?>" data-opstatus="<?php echo esc_attr( $op_status ); ?>">
			<td>
				<button type="button" class="button-link hlv-expand" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>">
					<span class="dashicons dashicons-arrow-right"></span>
				</button>
				<strong><?php echo esc_html( $title ); ?></strong>
				<?php if ( $is_premium ) : ?><span class="hlv-badge hlv-badge-premium" title="<?php esc_attr_e( 'Paying customer — extra caution', 'haunt-listing-verifier' ); ?>"><?php esc_html_e( 'Premium', 'haunt-listing-verifier' ); ?></span><?php endif; ?>
				<?php if ( $listing_id ) : ?><br><a href="<?php echo esc_url( get_edit_post_link( $listing_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit listing', 'haunt-listing-verifier' ); ?></a><?php endif; ?>
			</td>
			<td>
				<?php
				foreach ( $labels as $label ) {
					printf( '<span class="hlv-badge hlv-label">%s</span> ', esc_html( self::label_text( $label ) ) );
				}
				if ( $conflict ) {
					printf( '<span class="hlv-badge hlv-badge-conflict">%s</span> ', esc_html__( 'Website/Social conflict', 'haunt-listing-verifier' ) );
				}
				if ( $note_flag ) {
					printf( '<span class="hlv-badge hlv-badge-note">%s</span>', esc_html__( 'Admin note', 'haunt-listing-verifier' ) );
				}
				?>
			</td>
			<td class="hlv-col-sources"><?php echo esc_html( isset( $f['sources'] ) ? implode( ', ', (array) $f['sources'] ) : '' ); ?></td>
			<td><span class="hlv-row-status" aria-live="polite"></span></td>
		</tr>
		<tr class="hlv-finding-detail" id="<?php echo esc_attr( $row_id ); ?>" hidden>
			<td colspan="4">
				<?php if ( '' !== $admin_note ) : ?>
					<div class="hlv-admin-note <?php echo $note_flag ? 'is-relevant' : ''; ?>">
						<strong><?php esc_html_e( 'Admin note:', 'haunt-listing-verifier' ); ?></strong>
						<span class="hlv-admin-note-text"><?php echo esc_html( $admin_note ); ?></span>
						<label class="hlv-clear-note-wrap"><input type="checkbox" class="hlv-clear-note"> <?php esc_html_e( 'Clear this note when I act below', 'haunt-listing-verifier' ); ?></label>
					</div>
				<?php endif; ?>

				<?php
				if ( empty( $findings ) ) {
					echo '<p class="hlv-no-detail">' . esc_html__( 'The AI found nothing to change here.', 'haunt-listing-verifier' ) . '</p>';
				} else {
					foreach ( $findings as $detail ) {
						self::render_detail( $listing_id, (array) $detail, $f, $mode );
					}
				}
				?>

				<div class="hlv-row-level-actions">
					<?php if ( 'archive' === $mode ) : ?>
						<button type="button" class="button button-primary hlv-restore-finding"><?php esc_html_e( 'Restore to review queue', 'haunt-listing-verifier' ); ?></button>
						<button type="button" class="button button-link-delete hlv-delete-finding"><?php esc_html_e( 'Delete this result', 'haunt-listing-verifier' ); ?></button>
					<?php else : ?>
						<?php if ( 'queue' === $mode ) : ?>
							<?php if ( in_array( $op_status, array( 'closed_permanently', 'closed_for_season', 'relocated' ), true ) ) : ?>
								<button type="button" class="button hlv-unpublish"><?php esc_html_e( 'Unpublish (revert to draft)', 'haunt-listing-verifier' ); ?></button>
							<?php endif; ?>
							<button type="button" class="button hlv-defer" data-reason="deferred_manual"><?php esc_html_e( 'Defer (manual look)', 'haunt-listing-verifier' ); ?></button>
						<?php else : ?>
							<button type="button" class="button button-primary hlv-dismiss"><?php esc_html_e( 'Dismiss — looks good', 'haunt-listing-verifier' ); ?></button>
						<?php endif; ?>

						<?php // Result-level actions. Neither touches the live listing. ?>
						<button type="button" class="button hlv-ignore-finding" title="<?php esc_attr_e( 'Park this result in Past findings. It stays out of the queue until the haunt\'s sources change, and you can restore it any time.', 'haunt-listing-verifier' ); ?>"><?php esc_html_e( 'Ignore (save for later)', 'haunt-listing-verifier' ); ?></button>
						<button type="button" class="button hlv-delete-finding" title="<?php esc_attr_e( 'Delete this result and force a fresh check of this listing on the next crawl.', 'haunt-listing-verifier' ); ?>"><?php esc_html_e( 'Delete this result', 'haunt-listing-verifier' ); ?></button>

						<button type="button" class="button button-link-delete hlv-delete"><?php esc_html_e( 'Delete listing', 'haunt-listing-verifier' ); ?></button>
					<?php endif; ?>
					<span class="hlv-detail-status hlv-row-actions-status" aria-live="polite"></span>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one field-level finding detail (Before/After + actions).
	 *
	 * @param int    $listing_id Listing ID.
	 * @param array  $d          Finding detail.
	 * @param array  $f          Parent finding (for compound suggested_address).
	 * @param string $mode       'queue' | 'confirmation' | 'archive'. Only 'queue'
	 *                           renders per-field action buttons; the archive is a
	 *                           read-only look back at what was found.
	 */
	private static function render_detail( $listing_id, array $d, array $f, $mode ) {
		$field        = isset( $d['field'] ) ? $d['field'] : '';
		$label        = isset( $d['change_label'] ) ? $d['change_label'] : '';
		$scope        = isset( $d['event_scope'] ) ? $d['event_scope'] : 'none';
		$suggest      = isset( $d['suggested_value'] ) ? (string) $d['suggested_value'] : '';
		$is_no_change = ( 'no_change' === $label );
		$is_offseason = ( 'off_season_holiday' === $scope );
		$applyable    = ! $is_no_change && ( $is_offseason || ! in_array( $field, array( '', 'status', 'other' ), true ) );
		$current      = self::current_value( $listing_id, $field );
		$resolved     = isset( $f['resolved_fields'] ) ? (array) $f['resolved_fields'] : array();
		$already      = ( '' !== $field && in_array( $field, $resolved, true ) );
		?>
		<div class="hlv-detail<?php echo $already ? ' hlv-is-done' : ''; ?>" data-field="<?php echo esc_attr( $field ); ?>" data-scope="<?php echo esc_attr( $scope ); ?>">
			<p class="hlv-detail-head">
				<strong><?php echo esc_html( self::field_label( $field ) ); ?></strong>
				<span class="hlv-chip"><?php echo esc_html( self::label_text( $label ) ); ?></span>
				<?php if ( ! empty( $d['finding_type'] ) ) : ?><em class="hlv-ft"><?php echo esc_html( $d['finding_type'] ); ?></em><?php endif; ?>
			</p>

			<?php
			if ( $applyable && ! $is_offseason ) {
				if ( 'address' === $field ) {
					self::render_address_fields( $listing_id, $f );
				} elseif ( 'dates_hours' === $field ) {
					self::render_dates_fields( $current, $d );
				} else {
					?>
					<div class="hlv-beforeafter">
						<div class="hlv-before"><span class="hlv-ba-tag"><?php esc_html_e( 'Currently', 'haunt-listing-verifier' ); ?></span> <code class="hlv-ba-val"><?php echo '' !== $current ? esc_html( $current ) : esc_html__( '(empty)', 'haunt-listing-verifier' ); ?></code></div>
						<div class="hlv-after"><span class="hlv-ba-tag hlv-ba-tag-new"><?php esc_html_e( 'Change to', 'haunt-listing-verifier' ); ?></span> <input type="text" class="regular-text hlv-suggested" value="<?php echo esc_attr( $suggest ); ?>"></div>
					</div>
					<?php
				}
			} elseif ( '' !== $suggest && ! $is_no_change ) {
				echo '<p class="hlv-note-only">' . esc_html( $suggest ) . '</p>';
			}
			?>

			<?php if ( $is_offseason ) : ?>
				<div class="hlv-offseason" data-holiday="<?php echo esc_attr( isset( $d['event_holiday'] ) ? $d['event_holiday'] : '' ); ?>" data-start="<?php echo esc_attr( isset( $d['event_start'] ) ? $d['event_start'] : '' ); ?>" data-end="<?php echo esc_attr( isset( $d['event_end'] ) ? $d['event_end'] : '' ); ?>" data-info="<?php echo esc_attr( $suggest ); ?>">
					<p class="hlv-offseason-note">
						<?php esc_html_e( 'Off-season / holiday event — will be added as an Off-Season Event row, not the main dates/hours.', 'haunt-listing-verifier' ); ?>
						<br><strong><?php echo esc_html( isset( $d['event_holiday'] ) ? $d['event_holiday'] : '' ); ?></strong>
						<?php echo esc_html( trim( ( isset( $d['event_start'] ) ? $d['event_start'] : '' ) . ' – ' . ( isset( $d['event_end'] ) ? $d['event_end'] : '' ), ' –' ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $d['conflicting_value'] ) ) : ?>
				<p class="hlv-conflict-note"><?php esc_html_e( 'Conflicting (social) value:', 'haunt-listing-verifier' ); ?> <code><?php echo esc_html( $d['conflicting_value'] ); ?></code></p>
			<?php endif; ?>

			<?php self::render_evidence( $d ); ?>

			<?php if ( 'queue' === $mode && $already ) : ?>
				<p class="hlv-actions"><span class="hlv-detail-status hlv-ok"><?php esc_html_e( 'Already handled ✓', 'haunt-listing-verifier' ); ?></span></p>
			<?php elseif ( 'queue' === $mode ) : ?>
				<p class="hlv-actions">
					<?php if ( $is_offseason ) : ?>
						<button type="button" class="button button-primary hlv-apply-offseason"><?php esc_html_e( 'Add off-season event', 'haunt-listing-verifier' ); ?></button>
					<?php elseif ( $applyable ) : ?>
						<button type="button" class="button button-primary hlv-apply"><?php esc_html_e( 'Apply change', 'haunt-listing-verifier' ); ?></button>
					<?php endif; ?>
					<?php if ( ! empty( $d['image_url'] ) && 'dates_hours' !== $field ) : ?>
						<button type="button" class="button hlv-add-image" data-image-url="<?php echo esc_url( $d['image_url'] ); ?>"><?php esc_html_e( 'Add flyer to Dates & Hours Image', 'haunt-listing-verifier' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button hlv-dismiss"><?php esc_html_e( 'Dismiss (no change needed)', 'haunt-listing-verifier' ); ?></button>
					<button type="button" class="button hlv-reject" data-reason="rejected_false_positive"><?php esc_html_e( 'Reject (AI got it wrong)', 'haunt-listing-verifier' ); ?></button>
					<span class="hlv-detail-status" aria-live="polite"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the editable address grid (Before/After per component).
	 *
	 * @param int   $listing_id Listing ID.
	 * @param array $f          Finding (carries suggested_address).
	 */
	private static function render_address_fields( $listing_id, array $f ) {
		$sugg = isset( $f['suggested_address'] ) && is_array( $f['suggested_address'] ) ? $f['suggested_address'] : array();
		$cur  = array(
			'street'  => get_post_meta( $listing_id, HLV_Field_Map::META['street_address'], true ),
			'city'    => get_post_meta( $listing_id, HLV_Field_Map::META['city'], true ),
			'state'   => HLV_Field_Map::get_state_name( $listing_id ),
			'zip'     => get_post_meta( $listing_id, HLV_Field_Map::META['zip'], true ),
			'country' => get_post_meta( $listing_id, HLV_Field_Map::META['country_select'], true ),
		);
		$parts = array(
			'street'  => __( 'Street', 'haunt-listing-verifier' ),
			'city'    => __( 'City', 'haunt-listing-verifier' ),
			'state'   => __( 'State', 'haunt-listing-verifier' ),
			'zip'     => __( 'ZIP', 'haunt-listing-verifier' ),
			'country' => __( 'Country', 'haunt-listing-verifier' ),
		);
		echo '<div class="hlv-address-grid">';
		foreach ( $parts as $key => $plabel ) {
			$s = isset( $sugg[ $key ] ) ? (string) $sugg[ $key ] : '';
			$c = isset( $cur[ $key ] ) ? (string) $cur[ $key ] : '';
			printf(
				'<label class="hlv-addr-field"><span class="hlv-addr-label">%s</span><small class="hlv-was">%s %s</small><input type="text" class="hlv-addr" data-part="%s" value="%s"></label>',
				esc_html( $plabel ),
				esc_html__( 'now:', 'haunt-listing-verifier' ),
				'' !== $c ? esc_html( $c ) : '—',
				esc_attr( $key ),
				esc_attr( '' !== $s ? $s : $c )
			);
		}
		echo '</div>';
	}

	/**
	 * Render the dates/hours editor (text + required expiration + optional image).
	 *
	 * @param string $current Current dates/hours text.
	 * @param array  $d       Finding detail.
	 */
	private static function render_dates_fields( $current, array $d ) {
		$text = isset( $d['suggested_value'] ) ? (string) $d['suggested_value'] : '';
		$exp  = isset( $d['dates_hours_expiration'] ) ? self::iso_date( (string) $d['dates_hours_expiration'] ) : '';
		$img  = isset( $d['image_url'] ) ? (string) $d['image_url'] : '';
		?>
		<div class="hlv-beforeafter hlv-beforeafter-stack">
			<div class="hlv-before"><span class="hlv-ba-tag"><?php esc_html_e( 'Currently', 'haunt-listing-verifier' ); ?></span> <code class="hlv-ba-val"><?php echo '' !== $current ? esc_html( $current ) : esc_html__( '(empty)', 'haunt-listing-verifier' ); ?></code></div>
		</div>
		<p><label><strong><?php esc_html_e( 'New dates / hours text:', 'haunt-listing-verifier' ); ?></strong><br>
			<textarea class="large-text hlv-dates-text" rows="3"><?php echo esc_textarea( $text ); ?></textarea></label></p>
		<p class="hlv-dates-exp-wrap"><label><strong><?php esc_html_e( 'Hide on the site after (last event date):', 'haunt-listing-verifier' ); ?></strong>
			<input type="date" class="hlv-dates-expiration" value="<?php echo esc_attr( $exp ); ?>"></label>
			<span class="description"><?php esc_html_e( 'Required — the front-end hides the dates block after this date.', 'haunt-listing-verifier' ); ?></span></p>
		<?php if ( '' !== $img ) : ?>
			<p class="hlv-image-cite">
				<label><input type="checkbox" class="hlv-include-image" data-image-url="<?php echo esc_url( $img ); ?>" checked> <?php esc_html_e( 'Also add this flyer to the "Dates and Hours Image" field', 'haunt-listing-verifier' ); ?></label>
				<a href="<?php echo esc_url( $img ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( '(view image)', 'haunt-listing-verifier' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the evidence/citation block for a finding detail.
	 *
	 * @param array $d Finding detail.
	 */
	private static function render_evidence( array $d ) {
		if ( empty( $d['evidence_snippet'] ) ) {
			return;
		}
		?>
		<blockquote class="hlv-evidence">
			<?php echo esc_html( $d['evidence_snippet'] ); ?>
			<?php if ( ! empty( $d['evidence_url'] ) ) : ?>
				<br><a href="<?php echo esc_url( $d['evidence_url'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( $d['evidence_url'] ); ?></a>
			<?php endif; ?>
			<span class="hlv-provenance"><?php echo esc_html( trim( ( isset( $d['source'] ) ? $d['source'] : '' ) . ' · ' . ( isset( $d['modality'] ) ? $d['modality'] : '' ), ' ·' ) ); ?></span>
		</blockquote>
		<?php
	}

	/**
	 * Read the listing's CURRENT stored value for a finding's field, for the
	 * Before/After display.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $field      Field token.
	 * @return string
	 */
	private static function current_value( $listing_id, $field ) {
		if ( ! $listing_id ) {
			return '';
		}
		switch ( $field ) {
			case 'name':
				return (string) get_the_title( $listing_id );
			case 'state':
				return (string) HLV_Field_Map::get_state_name( $listing_id );
			case 'address':
				$parts = array(
					get_post_meta( $listing_id, HLV_Field_Map::META['street_address'], true ),
					get_post_meta( $listing_id, HLV_Field_Map::META['city'], true ),
					HLV_Field_Map::get_state_name( $listing_id ),
					get_post_meta( $listing_id, HLV_Field_Map::META['zip'], true ),
				);
				return implode( ', ', array_filter( array_map( 'strval', $parts ) ) );
			case 'dates_hours':
				return (string) get_post_meta( $listing_id, HLV_Field_Map::META['dates_hours_text'], true );
			default:
				return isset( HLV_Field_Map::META[ $field ] )
					? (string) get_post_meta( $listing_id, HLV_Field_Map::META[ $field ], true )
					: '';
		}
	}

	/**
	 * Human-readable label for the field being edited.
	 *
	 * @param string $field Field token.
	 * @return string
	 */
	private static function field_label( $field ) {
		$map = array(
			'name'            => __( 'Listing name', 'haunt-listing-verifier' ),
			'address'         => __( 'Address', 'haunt-listing-verifier' ),
			'state'           => __( 'State', 'haunt-listing-verifier' ),
			'website'         => __( 'Website', 'haunt-listing-verifier' ),
			'facebook'        => 'Facebook',
			'instagram'       => 'Instagram',
			'tiktok'          => 'TikTok',
			'youtube_channel' => __( 'YouTube', 'haunt-listing-verifier' ),
			'x_twitter'       => 'X / Twitter',
			'dates_hours'     => __( 'Dates / Hours', 'haunt-listing-verifier' ),
			'status'          => __( 'Operating status', 'haunt-listing-verifier' ),
			'other'           => __( 'Note', 'haunt-listing-verifier' ),
		);
		return isset( $map[ $field ] ) ? $map[ $field ] : ucwords( str_replace( '_', ' ', (string) $field ) );
	}

	/**
	 * Coerce any stored date to YYYY-MM-DD for a date input.
	 *
	 * @param string $value Date string.
	 * @return string
	 */
	private static function iso_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	/**
	 * Human-readable change-label text.
	 *
	 * @param string $label Machine label.
	 * @return string
	 */
	private static function label_text( $label ) {
		$map = array(
			'name_change'             => __( 'Name Change', 'haunt-listing-verifier' ),
			'bad_website'             => __( 'Bad Website', 'haunt-listing-verifier' ),
			'new_address'             => __( 'New Address', 'haunt-listing-verifier' ),
			'likely_closed'           => __( 'Likely Closed', 'haunt-listing-verifier' ),
			'closed_for_season'       => __( 'Closed for Season', 'haunt-listing-verifier' ),
			'relocated'               => __( 'Relocated', 'haunt-listing-verifier' ),
			'renamed'                 => __( 'Renamed', 'haunt-listing-verifier' ),
			'website_social_conflict' => __( 'Website/Social Conflict', 'haunt-listing-verifier' ),
			'time_sensitive_notice'   => __( 'Time-Sensitive Notice', 'haunt-listing-verifier' ),
			'no_change'               => __( 'No change', 'haunt-listing-verifier' ),
			'unreadable'              => __( 'Unreadable', 'haunt-listing-verifier' ),
		);
		return isset( $map[ $label ] ) ? $map[ $label ] : ucwords( str_replace( '_', ' ', (string) $label ) );
	}
}
