/* global PlugSeal, jQuery */
( function ( $ ) {
	'use strict';

	// Plugin selection.
	$( document ).on( 'click', '.plugseal-plugin-item', function () {
		var slug = $( this ).data( 'slug' );

		$( '.plugseal-plugin-item' ).removeClass( 'is-selected' ).attr( 'aria-selected', 'false' );
		$( this ).addClass( 'is-selected' ).attr( 'aria-selected', 'true' );

		$( '.plugseal-plugin-detail' ).attr( 'hidden', true );
		$( '#plugseal-select-hint' ).attr( 'hidden', true );
		$( '.plugseal-plugin-detail[data-slug="' + slug + '"]' ).removeAttr( 'hidden' );
	} );

	// Permission toggle.
	$( document ).on( 'click', '.plugseal-toggle', function () {
		var $btn    = $( this );
		var slug    = $btn.data( 'slug' );
		var perm    = $btn.data( 'perm' );
		var allowed = '1' === String( $btn.data( 'allowed' ) );
		var newVal  = allowed ? '0' : '1';

		$btn.prop( 'disabled', true );

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
			var $badge = $item.find( '.plugseal-badge' );
			var hasDenied = $( '.plugseal-plugin-detail[data-slug="' + slug + '"]' )
				.find( '.plugseal-toggle.is-denied' ).length > 0;

			if ( hasDenied && ! $badge.length ) {
				$item.append( '<span class="plugseal-badge badge-restricted">' + 'restricted' + '</span>' );
			} else if ( ! hasDenied && $badge.length ) {
				$badge.remove();
			}

		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

}( jQuery ) );
