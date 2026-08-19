/* global PlugSeal, jQuery */
( function ( $ ) {
	'use strict';

	// Plugin selection.
	$( document ).on( 'click', '.plugseal-plugin-item', function () {
		var slug = $( this ).data( 'slug' );

		$( '.plugseal-plugin-item' ).removeClass( 'is-selected' );
		$( this ).addClass( 'is-selected' );

		$( '.plugseal-plugin-detail' ).attr( 'hidden', true );
		$( '#plugseal-select-hint' ).attr( 'hidden', true );

		var $detail = $( '.plugseal-plugin-detail[data-slug="' + slug + '"]' );
		$detail.removeAttr( 'hidden' );

		// Move focus to the panel heading for keyboard navigation.
		$detail.find( 'h2' ).trigger( 'focus' );
	} );

	// Permission toggle.
	$( document ).on( 'click', '.plugseal-toggle', function () {
		var $btn    = $( this );
		var slug    = $btn.data( 'slug' );
		var perm    = $btn.data( 'perm' );
		var allowed = '1' === String( $btn.data( 'allowed' ) );
		var newVal  = allowed ? '0' : '1';

		$btn.prop( 'disabled', true ).attr( 'aria-busy', 'true' );

		$.post( PlugSeal.ajaxurl, {
			action : 'plugseal_set_override',
			nonce  : PlugSeal.nonce,
			slug   : slug,
			perm   : perm,
			value  : newVal
		} ).done( function ( res ) {
			if ( ! res.success ) {
				return;
			}

			var isAllowed = res.data.allowed;

			$btn
				.data( 'allowed', isAllowed ? '1' : '0' )
				.text( isAllowed ? PlugSeal.i18n.allowed : PlugSeal.i18n.denied )
				.removeClass( 'is-allowed is-denied' )
				.addClass( isAllowed ? 'is-allowed' : 'is-denied' );

			// Update the sidebar badge.
			var $item = $( '.plugseal-plugin-item[data-slug="' + slug + '"]' );
			var deniedCount = $( '.plugseal-plugin-detail[data-slug="' + slug + '"]' )
				.find( '.plugseal-toggle.is-denied' ).length;
			var $badge = $item.find( '.plugseal-count-badge' );

			if ( deniedCount > 0 ) {
				if ( $badge.length ) {
					$badge.text( deniedCount );
				} else {
					$item.append( '<span class="plugseal-count-badge">' + deniedCount + '</span>' );
				}
			} else if ( $badge.length ) {
				$badge.remove();
			}

		} ).always( function () {
			$btn.prop( 'disabled', false ).attr( 'aria-busy', 'false' );
		} );
	} );

	// Reset to defaults button.
	$( document ).on( 'click', '.plugseal-reset-btn', function () {
		var $btn = $( this );
		var slug = $btn.data( 'slug' );
		var name = $( '.plugseal-plugin-item[data-slug="' + slug + '"]' )
			.find( '.plugseal-plugin-name' ).text() || slug;

		if ( ! window.confirm( PlugSeal.i18n.reset_confirm.replace( '%s', name ) ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).attr( 'aria-busy', 'true' );

		$.post( PlugSeal.ajaxurl, {
			action : 'plugseal_reset_plugin',
			nonce  : PlugSeal.nonce,
			slug   : slug
		} ).done( function ( res ) {
			if ( ! res.success ) return;

			// Reset all toggles to allowed.
			$( '.plugseal-plugin-detail[data-slug="' + slug + '"]' )
				.find( '.plugseal-toggle' )
				.each( function () {
					$( this )
						.data( 'allowed', '1' )
						.text( PlugSeal.i18n.allowed )
						.removeClass( 'is-denied' )
						.addClass( 'is-allowed' );
				} );

			// Remove count badge from sidebar.
			$( '.plugseal-plugin-item[data-slug="' + slug + '"]' )
				.find( '.plugseal-count-badge' ).remove();

		} ).always( function () {
			$btn.prop( 'disabled', false ).attr( 'aria-busy', 'false' );
		} );
	} );

}( jQuery ) );
