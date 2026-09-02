/* global HLV, jQuery */
( function ( $ ) {
	'use strict';

	/** The finding-row that owns the element. */
	function rowOf( $el ) {
		return $el.closest( '.hlv-finding-detail' ).prev( '.hlv-finding-row' );
	}

	/** Shared context (listing/finding/run/op-status + clear-note state). */
	function baseCtx( $el ) {
		var $row = rowOf( $el );
		return {
			listing_id: $row.data( 'listing' ) || 0,
			finding_id: $row.data( 'finding' ) || '',
			run_id: $row.data( 'run' ) || '',
			op_status: $row.data( 'opstatus' ) || '',
			clear_note: $el.closest( '.hlv-finding-detail' ).find( '.hlv-clear-note' ).is( ':checked' ) ? 1 : 0
		};
	}

	/** Nearest status element to show feedback next to the pressed button. */
	function statusFor( $el ) {
		var $s = $el.closest( '.hlv-detail, .hlv-row-level-actions' ).find( '.hlv-detail-status' ).first();
		if ( ! $s.length ) {
			$s = rowOf( $el ).find( '.hlv-row-status' );
		}
		return $s;
	}

	/**
	 * Retire the acted-on element. A button inside a single suggestion (.hlv-detail)
	 * only greys THAT suggestion — so handling one change never hides a listing's
	 * other unreviewed changes. Once every suggestion is handled, the whole finding
	 * is greyed. A listing-level button greys the whole finding immediately.
	 */
	function retire( $btn ) {
		var $detail = $btn.closest( '.hlv-detail' );
		if ( $detail.length ) {
			$detail.addClass( 'hlv-is-done' ).find( 'button' ).prop( 'disabled', true );
			var $wrap = $btn.closest( '.hlv-finding-detail' );
			if ( $wrap.find( '.hlv-detail' ).length && 0 === $wrap.find( '.hlv-detail' ).not( '.hlv-is-done' ).length ) {
				rowOf( $btn ).addClass( 'hlv-is-done' );
			}
		} else {
			var $finding = $btn.closest( '.hlv-finding-detail' );
			$finding.addClass( 'hlv-is-done' ).find( 'button' ).not( '.hlv-expand' ).prop( 'disabled', true );
			rowOf( $btn ).addClass( 'hlv-is-done' );
		}
	}

	/**
	 * POST to admin-ajax with the shared nonce; show feedback next to the action.
	 *
	 * @param {string}   action  Action slug.
	 * @param {Object}   data    Payload.
	 * @param {jQuery}   $status Feedback target.
	 * @param {jQuery}   $button Button to disable while running.
	 * @param {Function} done    Called on success.
	 */
	function post( action, data, $status, $button, done ) {
		$status.text( HLV.i18n.applying ).removeClass( 'hlv-ok hlv-bad' );
		if ( $button ) {
			$button.prop( 'disabled', true );
		}
		$.post(
			HLV.ajaxUrl,
			$.extend( { action: action, nonce: HLV.nonce }, data )
		).done( function ( res ) {
			if ( res && res.success ) {
				var msg = ( res.data && res.data.message ) || HLV.i18n.applied;
				if ( res.data && res.data.note_cleared ) {
					msg += ' ' + HLV.i18n.noteCleared;
				}
				$status.text( msg ).addClass( 'hlv-ok' );
				if ( done ) {
					done();
				}
			} else {
				$status.text( ( res && res.data && res.data.message ) || HLV.i18n.failed ).addClass( 'hlv-bad' );
				if ( $button ) {
					$button.prop( 'disabled', false );
				}
			}
		} ).fail( function ( xhr ) {
			var msg = HLV.i18n.failed;
			if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				msg = xhr.responseJSON.data.message;
			}
			$status.text( msg ).addClass( 'hlv-bad' );
			if ( $button ) {
				$button.prop( 'disabled', false );
			}
		} );
	}

	$( function () {
		// Expand / collapse a finding's detail row.
		$( document ).on( 'click', '.hlv-expand', function () {
			var $btn    = $( this );
			var $detail = $( '#' + $btn.attr( 'aria-controls' ) );
			var open    = $detail.is( ':visible' );
			$detail.prop( 'hidden', open );
			$btn.attr( 'aria-expanded', ! open );
			$btn.find( '.dashicons' )
				.toggleClass( 'dashicons-arrow-right', open )
				.toggleClass( 'dashicons-arrow-down', ! open );
		} );

		// Apply a field change (scalar, compound address, or dates/hours).
		$( document ).on( 'click', '.hlv-apply', function () {
			var $btn    = $( this );
			var $detail = $btn.closest( '.hlv-detail' );
			var field   = $detail.data( 'field' );
			var ctx     = baseCtx( $btn );
			var data    = {
				listing_id: ctx.listing_id,
				finding_id: ctx.finding_id,
				run_id: ctx.run_id,
				field: field,
				op_status: ctx.op_status,
				clear_admin_note: ctx.clear_note
			};

			if ( 'address' === field ) {
				var addr = {};
				$detail.find( '.hlv-addr' ).each( function () {
					addr[ $( this ).data( 'part' ) ] = $( this ).val();
				} );
				data.address = addr;
				data.edited  = 1;
			} else if ( 'dates_hours' === field ) {
				data.dates_text       = $detail.find( '.hlv-dates-text' ).val() || '';
				data.dates_expiration = $detail.find( '.hlv-dates-expiration' ).val() || '';
				var $inc = $detail.find( '.hlv-include-image' );
				data.dates_image_url  = ( $inc.length && $inc.is( ':checked' ) ) ? $inc.data( 'image-url' ) : '';
				data.edited           = 1;
			} else {
				var $input = $detail.find( '.hlv-suggested' );
				var val    = $input.length ? $input.val() : '';
				data.value  = val;
				data.edited = ( $input.length && val !== $input[ 0 ].defaultValue ) ? 1 : 0;
			}

			post( 'hlv_apply', data, statusFor( $btn ), $btn, function () { retire( $btn ); } );
		} );

		// Add a discovered off-season/holiday event as a repeater row.
		$( document ).on( 'click', '.hlv-apply-offseason', function () {
			var $btn = $( this );
			var $os  = $btn.closest( '.hlv-detail' ).find( '.hlv-offseason' );
			var ctx  = baseCtx( $btn );
			post(
				'hlv_apply_offseason',
				{
					listing_id: ctx.listing_id,
					finding_id: ctx.finding_id,
					run_id: ctx.run_id,
					holiday: $os.data( 'holiday' ) || '',
					start: $os.data( 'start' ) || '',
					end: $os.data( 'end' ) || '',
					info: $os.data( 'info' ) || ''
				},
				statusFor( $btn ),
				$btn,
				function () { retire( $btn ); }
			);
		} );

		// Sideload a cited flyer into the Dates & Hours Image field (button only).
		$( document ).on( 'click', '.hlv-add-image', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			post(
				'hlv_sideload_image',
				{ listing_id: ctx.listing_id, finding_id: ctx.finding_id, run_id: ctx.run_id, image_url: $btn.data( 'image-url' ) },
				statusFor( $btn ),
				$btn,
				function () { $btn.prop( 'disabled', true ); }
			);
		} );

		// Dismiss (no change needed). Per-suggestion when inside a .hlv-detail;
		// listing-level when it's the row's confirmation button.
		$( document ).on( 'click', '.hlv-dismiss', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			post(
				'hlv_dismiss',
				{
					listing_id: ctx.listing_id,
					finding_id: ctx.finding_id,
					run_id: ctx.run_id,
					field: $btn.closest( '.hlv-detail' ).data( 'field' ) || '',
					op_status: ctx.op_status
				},
				statusFor( $btn ),
				$btn,
				function () { retire( $btn ); }
			);
		} );

		// Reject (AI wrong) is per-suggestion; Defer (manual look) is listing-level.
		$( document ).on( 'click', '.hlv-reject, .hlv-defer', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			post(
				'hlv_decision',
				{
					listing_id: ctx.listing_id,
					finding_id: ctx.finding_id,
					run_id: ctx.run_id,
					field: $btn.closest( '.hlv-detail' ).data( 'field' ) || '',
					reason_code: $btn.data( 'reason' ),
					op_status: ctx.op_status
				},
				statusFor( $btn ),
				$btn,
				function () { retire( $btn ); }
			);
		} );

		// Unpublish (revert to draft) with a closure reason — listing-level.
		$( document ).on( 'click', '.hlv-unpublish', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			var why  = window.prompt( HLV.i18n.closureReason, '' );
			if ( null === why ) {
				return;
			}
			post(
				'hlv_unpublish',
				{ listing_id: ctx.listing_id, finding_id: ctx.finding_id, run_id: ctx.run_id, reason: why, status: 'draft' },
				statusFor( $btn ),
				$btn,
				function () { retire( $btn ); }
			);
		} );

		// Delete (trash) a listing — listing-level.
		$( document ).on( 'click', '.hlv-delete', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			if ( ! window.confirm( HLV.i18n.confirmDel ) ) {
				return;
			}
			post(
				'hlv_delete',
				{ listing_id: ctx.listing_id, finding_id: ctx.finding_id, run_id: ctx.run_id },
				statusFor( $btn ),
				$btn,
				function () { retire( $btn ); }
			);
		} );

		/**
		 * Drop a finding's two <tr>s out of the table. Used by the result-level
		 * actions, where the row has genuinely left this list — unlike retire(),
		 * which greys a row that has been handled but still belongs here.
		 */
		function removeRow( $btn ) {
			var $row    = rowOf( $btn );
			var $detail = $btn.closest( '.hlv-finding-detail' );
			$detail.remove();
			$row.remove();
		}

		// Ignore a result — park it in Past findings. The listing is untouched and
		// its crawl breadcrumb is kept, so this suggestion stays gone until the
		// haunt's own sources change.
		$( document ).on( 'click', '.hlv-ignore-finding', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			post(
				'hlv_ignore_finding',
				{ listing_id: ctx.listing_id, finding_id: ctx.finding_id, run_id: ctx.run_id },
				statusFor( $btn ),
				$btn,
				function () { removeRow( $btn ); }
			);
		} );

		// Restore an archived result to the active queue.
		$( document ).on( 'click', '.hlv-restore-finding', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			post(
				'hlv_restore_finding',
				{ listing_id: ctx.listing_id, finding_id: ctx.finding_id, run_id: ctx.run_id },
				statusFor( $btn ),
				$btn,
				function () { removeRow( $btn ); }
			);
		} );

		// Delete a result — forces a fresh check of that listing next crawl.
		$( document ).on( 'click', '.hlv-delete-finding', function () {
			var $btn = $( this );
			var ctx  = baseCtx( $btn );
			if ( ! window.confirm( HLV.i18n.confirmDelFinding ) ) {
				return;
			}
			post(
				'hlv_delete_finding',
				{ listing_id: ctx.listing_id, finding_id: ctx.finding_id, run_id: ctx.run_id },
				statusFor( $btn ),
				$btn,
				function () { removeRow( $btn ); }
			);
		} );

		// Bulk "clean slate" clear, from the Clear results card.
		$( document ).on( 'click', '.hlv-purge', function () {
			var $btn   = $( this );
			var choice = String( $( '#hlv_purge_what' ).val() || '' );
			var sep    = choice.indexOf( ':' );
			if ( sep < 1 ) {
				return;
			}
			if ( ! window.confirm( HLV.i18n.confirmPurge ) ) {
				return;
			}

			var key  = choice.slice( 0, sep );
			var val  = choice.slice( sep + 1 );
			var data = {};
			if ( 'run' === key ) {
				data.run_id = val;
			} else {
				data[ key ] = val;
			}

			var $status = $( '.hlv-purge-status' );
			$status.text( HLV.i18n.working );
			post( 'hlv_purge_findings', data, $status, $btn, function () {
				// The queues on screen are now stale — reload so what's shown is
				// what's actually left.
				window.location.reload();
			} );
		} );
	} );
}( jQuery ) );
