( function () {
	'use strict';

	// One delegated handler serves the edit screen and every media modal that opens later.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.shakewell-media-replace__button' );
		if ( ! button ) {
			return;
		}

		var box = button.closest( '.shakewell-media-replace' );
		var input = box.querySelector( '.shakewell-media-replace__file' );
		var status = box.querySelector( '.shakewell-media-replace__status' );
		if ( ! input.files.length ) {
			input.focus();
			return;
		}

		var data = new FormData();
		data.append( 'action', 'shakewell_media_replace' );
		data.append( 'id', box.dataset.id );
		data.append( 'nonce', box.dataset.nonce );
		data.append( 'file', input.files[ 0 ] );

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		status.textContent = box.dataset.busy;

		fetch( window.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				status.textContent = result.data && result.data.message ? result.data.message : box.dataset.failed;
				if ( result.success ) {
					refreshPreview( box.dataset.id, result.data.url );
					input.value = '';
				}
			} )
			.catch( function () {
				status.textContent = box.dataset.failed;
			} )
			.finally( function () {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			} );
	} );

	// The address is unchanged, so the browser would keep showing the old picture: add a version to it.
	function refreshPreview( id, url ) {
		var base = url.split( '?' )[ 0 ];
		var stem = base.replace( /\.[^./]+$/, '' );
		document.querySelectorAll( 'img' ).forEach( function ( img ) {
			if ( img.src.split( '?' )[ 0 ].indexOf( stem ) === 0 ) {
				img.src = img.src.split( '?' )[ 0 ] + '?ver=' + url.split( 'ver=' )[ 1 ];
			}
		} );
		if ( window.wp && window.wp.media && window.wp.media.attachment ) {
			window.wp.media.attachment( id ).fetch();
		}
	}
}() );
