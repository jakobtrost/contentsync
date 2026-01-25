var contentSync = contentSync || {};

contentSync.postExport = new function() {

	/**
	 * Start the export dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openExport = function( elem ) {

		const td = $( elem ).closest( 'td.title' );

		let title = '';
		if ( td.find( '.filename' ).length ) {
			title = td.find( '.filename' ).clone().children().remove().end().text();
		} else {
			title = td.find( 'strong a' ).text();
		}

		contentSync.overlay.confirm( 'post_export', title.trim(), contentSync.postExport.exportPost, [ elem ] );
	}; 

	/**
	 * Export a post (row action)
	 * 
	 * @param {object} elem 
	 */
	this.exportPost = function( elem ) {

		const mode = 'post_export';
		const postId     = $( elem ).data( 'post_id' );
		const $form       = $( '#post_export_form' );
		const formData    = $form.serializeArray().reduce( function( obj, item ) {
			obj[ item.name ] = item.value;

			return obj;
		}, {} );
		formData.post_id = postId;
		console.log( formData );
		
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': formData
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// trigger overlay
					contentSync.overlay.triggerOverlay( true, { 'type': 'success', 'css': mode } );
				
					let $link      = $( 'a#post_export_download' );
					if ( $link.length === 0 ) {
						$( '#wpfooter' ).after( '<a id="post_export_download"></a>' );
						$link = $( 'a#post_export_download' );
					}

					const file     = response.split( 'success::' )[ 1 ];
					const filename = file.match( /\/[^\/]+.zip/ ) ? file.match( /\/[^\/]+.zip/ )[ 0 ].replace( '/', '' ) : '';

					$link.attr( {
						'href': file,
						'download': filename
					} );
					$link[ 0 ].click();
					$form[ 0 ].reset();
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					const msg = response.split( 'error::' )[ 1 ];
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};
};