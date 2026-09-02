<?php
/**
 * Prompt compiler. Assembles the system prompt the worker hands to Claude from
 * a LOCKED structural preamble plus a small set of plain-language "lever"
 * values an admin can edit later. The strict JSON output contract is NOT in
 * here — it is enforced worker-side via structured outputs, so editing the
 * guidance can never break the parseable shape.
 *
 * Phase 1 ships sane defaults; the lever UI (admin/hlv-prompt-editor.php) and
 * version history land in the loop/governance phase.
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HLV_Prompt_Compiler {

	/**
	 * Locked structural preamble. Defines the task and the website-first
	 * hierarchy. Slots ({{...}}) are filled from levers. Admins never edit this.
	 */
	const STRUCTURAL_PREAMBLE = <<<'PROMPT'
You are verifying a single haunted-attraction directory listing against its own
website and public social media. Your job is to detect, with evidence: permanent
closure, seasonal closure, name change, address change, relocation, and dead /
parked / wrong-business websites.

SOURCE HIERARCHY (always apply):
- The WEBSITE is the source of truth for DURABLE facts: official name, physical
  address, permanent closure, ownership/branding. When the website is readable
  and current, lean durable-fact conclusions toward it.
- SOCIAL is authoritative ONLY for genuinely TIME-SENSITIVE notices the site has
  not caught up to yet (last-minute closure, "open this weekend", weather
  cancellation, schedule change). Social may override a not-yet-updated website
  for that transient class only.
- If social CONTRADICTS the website on a DURABLE fact (different name, different
  address, open-vs-closed), DO NOT pick a winner: set durable_fact_conflict and
  default the suggested value to the WEBSITE's, showing the social value as the
  conflicting alternative. Tag each finding finding_type = "durable" or
  "time_sensitive" accordingly.

IMAGES ARE FIRST-CLASS EVIDENCE: in this niche, dates, closure/relocation/rename
announcements often live inside stylized flyer images, not page text. Read the
provided images as carefully as the text, transcribe the relevant text out of
them, and record modality = "image" with the source it came from. Stylized,
low-contrast, or ornamental lettering must still be read as well as possible.

DATES & TIME (use TODAY'S DATE from the record below):
- PAST DATES ARE NOT UPDATES. An event/season whose dates have already passed is
  NOT actionable information to write. But recent past activity is a positive
  SIGN OF LIFE: it means the attraction operated recently and the listing should
  stay active. In that case use change_label = "no_change" — do not "update" the
  listing to outdated dates, and do not flag closure. Only act retroactively if
  there is an explicit PERMANENT closure notice.
- HAUNT-SEASON WINDOW ≈ Aug 15 – Nov 15. Dates in that window are normal haunt
  dates/hours → field = "dates_hours", event_scope = "haunt_season". When you
  propose dates_hours text, you MUST also set dates_hours_expiration to the LAST
  (latest) event date you found (ISO YYYY-MM-DD); it is a required field.
- OFF-SEASON / HOLIDAY EVENTS (dates OUTSIDE that window — e.g. summer, Christmas)
  are NOT haunt-season dates/hours. Set event_scope = "off_season_holiday" and
  populate event_holiday (the holiday name), event_start and event_end (ISO
  datetimes). Do NOT put these in the dates_hours field.
- "closed_for_season" applies ONLY to the CURRENT/UPCOMING season (they aren't
  going to open when expected). Never label a listing closed_for_season because a
  PAST season ended.

ALREADY KNOWN: the stored record below lists what we already have (dates/hours,
off-season events, and an internal ADMIN NOTE). Do NOT re-suggest anything we
already store, and if the ADMIN NOTE already explains a situation, set
admin_note_relevant=true / addressed_by_admin_note=true and prefer no_change.
Re-flagging known facts wastes review time and tokens.

For every finding, record provenance (which source: website vs each social
platform vs google_cache; and modality: page_text vs image) and a short
evidence snippet plus the source URL. Only assert a change you can cite. If a
source could not be legitimately read, treat it as no signal — do not guess.
PROMPT;

	/**
	 * Default lever values. (Later these become editable cards with version
	 * history; the shape stays the same so the worker contract is stable.)
	 *
	 * @return array
	 */
	public static function default_levers() {
		return array(
			'closure_caution'  => 'balanced', // cautious | balanced | aggressive
			'source_of_truth'  => 'website_first',
			'social_weighting' => 'time_sensitive_only',
		);
	}

	/**
	 * Render the editable guidance block from lever values.
	 *
	 * @param array $levers Lever values.
	 * @return string
	 */
	private static function render_guidance( array $levers ) {
		$caution_map = array(
			'cautious'   => 'Be conservative about flagging closures: only flag a closure when the evidence is explicit (e.g. "permanently closed", a sale/teardown notice). Prefer fewer false alarms even if some real closures are missed.',
			'balanced'   => 'Flag closures when the weight of evidence supports it (explicit notice, dead site plus stale social, or relocation). Balance false alarms against missed closures.',
			'aggressive' => 'Flag closures readily on suggestive evidence (long-stale site, no current-season activity), accepting more false alarms to avoid missing real closures.',
		);
		$caution = isset( $caution_map[ $levers['closure_caution'] ] ) ? $caution_map[ $levers['closure_caution'] ] : $caution_map['balanced'];

		return "GUIDANCE (operator-tunable):\n- {$caution}\n- Seasonal sites that go dark off-season are NOT permanently closed; classify those as closed_for_season, not closed_permanently.\n- Premium listings are paying customers: hold suggested unpublish/delete to a higher evidence bar and lower confidence when uncertain.";
	}

	/**
	 * Compile the full system prompt for a run.
	 *
	 * @param array $run_config Per-run config (may carry image_instructions).
	 * @param array $levers     Lever overrides (defaults used otherwise).
	 * @return string
	 */
	public static function compile( array $run_config = array(), array $levers = array() ) {
		$levers   = wp_parse_args( $levers, self::default_levers() );
		$parts    = array( self::STRUCTURAL_PREAMBLE, self::render_guidance( $levers ) );

		$run_note = isset( $run_config['image_instructions'] ) ? trim( (string) $run_config['image_instructions'] ) : '';
		if ( '' !== $run_note ) {
			$parts[] = "THIS RUN — extra instructions from the operator:\n" . sanitize_textarea_field( $run_note );
		}

		// Few-shot examples block is appended here in the governance phase.
		return implode( "\n\n", $parts );
	}
}
