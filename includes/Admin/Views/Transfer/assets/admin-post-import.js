var contentsync = contentsync || {};

contentsync.postImport = new function() {

	/**
	 * Open browser upload dialog (on import button click)
	 */
	this.openImport = function() {
		$( '#post_import_form input[type="file"]' ).trigger( 'click' );
	};

	/**
	 * Trigger check_import overlay (on file input click)
	 */
	this.init = function() {
		const $form  = $( '#post_import_form' );
		const $input = $form.find( 'input[type="file"]' );
		
		$input.on( 'change', function ( e ) {

			if ( $( this ).val() ) {
				this.checkPostImport( $( this ) );
			}
		} );
	};

	this.checkPostImport = function( $input ) {
		
		const mode = 'check_post_import';
				
		// trigger overlay 'check_file'
		contentsync.overlay.triggerOverlay( true, { 'type': 'check_file', 'css': mode } );

		// create formData
		const formData = new FormData();
		formData.append( 'action', 'contentsync_ajax' );
		formData.append( '_ajax_nonce', greyd.nonce );
		formData.append( 'mode', mode );

		// append file data
		const fileData = $input[ 0 ].files[ 0 ];
		formData.append( 'data', fileData );

		// send to admin ajax
		$.ajax( {
			'type': 'POST',
			'url': greyd.ajax_url,
			'processData': false,
			'contentType': false,
			'cache': false,
			'data': formData,
			'error': function( xhr, textStatus, errorThrown ) {
				console.warn( textStatus+': '+errorThrown, xhr );
				$input.val( '' );
				contentsync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': errorThrown+': <code>'+xhr.responseText+'</code>' } );
			},
			'success': function( response ) {
				$input.val( '' );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// get response: conflicting posts
					let result = response.split( 'success::' )[ 1 ];
					result = result.indexOf( '[' ) === 0 ? JSON.parse( result ) : result;
					console.log( result );

					contentsync.postImport.buildConflictOptions( $( '#post_import_form' ), result );
					
					/**
					 * trigger overlay 'confirm'
					 * 
					 * callback: this.postImport( filename )
					 */
					contentsync.overlay.confirm( mode, '', contentsync.postImport.postImport, [ fileData.name ] );
				}
				// complete with error
				else if ( response.indexOf( 'error::' ) > -1 ) {
					const msg = response.split( 'error::' )[ 1 ];
					console.log( msg );
					contentsync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				// unknown state
				else {
					console.log( response );
					contentsync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		} );
	};

	/**
	 * Build the conflict selects inside the wrapper
	 * 
	 * @param {object} wrapper  HTML wrapper jQuery object
	 * @param {mixed} posts     Either an array of posts or a string.
	 */
	this.buildConflictOptions = function( wrapper, posts ) {

		const $new        = wrapper.find( '.new' );
		const $conflicts  = wrapper.find( '.conflicts' );

		if ( typeof posts === 'object' || Array.isArray( posts ) ) {

			const $list   = $conflicts.find( '.inner_content' );
			const options = decodeURIComponent( $list.data( 'options' ) ).replace( /\+/g, ' ' );

			$new.hide();
			$list.html( '' );

			posts.forEach( ( post ) => {

				$list.append( `<div><span>${post.post_link}</span><select name="${post.ID}">${options}</select></div>` );

				if ( post.post_type == 'tp_posttypes' ) {
					$list.find( `select[name="${post.ID}"] option[value="keep"]` ).attr( 'disabled', true );
				}
				else if ( post.ID == 'multioption' ) {
					const $select = $list.find( `select[name="${post.ID}"]` );
					$select.removeAttr( 'name' );
					$select.parent().addClass( 'multioption' );
					$select.on( 'change', ( e ) => {
						const val = $( e.target ).val();
						$list.find( 'select' ).each( function(){
							$( this ).val( val );
						} );
					} );
				}
			} );
			$conflicts.show();
		}
		else {
			const $postTitle = $new.find( '.post_title' );
			
			$conflicts.hide();
			$postTitle.html( posts );
			$new.show();
		}
	};

	/**
	 * Trigger post_import (on check_import overlay confirm)
	 * 
	 * @param {string} filename 
	 */
	this.postImport = function( filename ) {

		const mode       = 'post_import';
		const $form      = $( '#post_import_form' );
		const formData   = $form.serializeArray().reduce( function( obj, item ) {
			obj[ item.name ] = item.value;

			return obj;
		}, {} );

		const data = {
			filename: filename,
			conflicts: formData
		};
		console.log( data );

		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': data
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {
					const msg = response.split( 'success::' )[ 1 ];

					// use for development
					// contentsync.overlay.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentsync.overlay.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				// complete with error
				else if ( response.indexOf( 'error::' ) > -1 ) {
					const msg = response.split( 'error::' )[ 1 ];
					contentsync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				// unknown state
				else {
					contentsync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};
};

document.addEventListener( 'DOMContentLoaded', () => {
	contentsync.postImport.init();
} );