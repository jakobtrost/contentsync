/**
 * Script for post export and import
 */
( function() { 

	jQuery( function() {

		if ( typeof $ === 'undefined' ) $ = jQuery;

		contentsync.postExport.init();
		
		console.log( 'Post Export Scripts: loaded' );
	} );

} )( jQuery );

var contentsync = contentsync || {};

contentsync.postExport = new function() {

	this.init = function() {
		this.importInit();
	};

	/**
	 * Start the export dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openExport = function( elem ) {
		var td          = $( elem ).closest( 'td.title' );
		if ( td.find( '.filename' ).length ) {
			var title = td.find( '.filename' ).clone().children().remove().end().text();
		}
		else {
			var title = td.find( 'strong a' ).text();
		}

		contentsync.tools.confirm( 'post_export', title.trim(), contentsync.postExport.export, [ elem ] );
	}; 

	/**
	 * Export a post (row action)
	 * 
	 * @param {object} elem 
	 */
	this.export = function( elem ) {

		var mode        = 'post_export';
		var post_id     = $( elem ).data( 'post_id' );
		var form        = $( '#post_export_form' );
		var data        = form.serializeArray().reduce( function( obj, item ) {
			obj[ item.name ] = item.value;

			return obj;
		}, {} );
		data.post_id = post_id;
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

					// trigger overlay
					contentsync.tools.triggerOverlay( true, { 'type': 'success', 'css': mode } );
				
					// download file
					var file    = response.split( 'success::' )[ 1 ];
					var link    = $( 'a#post_export_download' );
					var filename = file.match( /\/[^\/]+.zip/ ) ? file.match( /\/[^\/]+.zip/ )[ 0 ].replace( '/', '' ) : '';
					if ( link.length === 0 ) {
						$( '#wpfooter' ).after( '<a id="post_export_download"></a>' );
						link = $( 'a#post_export_download' );
					}

					link.attr( {
						'href': file,
						'download': filename
					} );
					link[ 0 ].click();
					form[ 0 ].reset();
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};

	/**
	 * Open browser upload dialog (on import button click)
	 */
	this.openImport = function() {
		$( '#post_import_form input[type="file"]' ).trigger( 'click' );
	};

	/**
	 * Trigger check_import overlay (on file input click)
	 */
	this.importInit = function() {
		
		var mode        = 'post_import';
		var form        = $( '#post_import_form' );
		var file_input  = form.find( 'input[type="file"]' );
		
		file_input.on( 'change', function ( e ) {

			if ( $( this ).val() ) {
				
				// trigger overlay 'check_file'
				contentsync.tools.triggerOverlay( true, { 'type': 'check_file', 'css': mode } );

				// create formData
				var data = new FormData();
				data.append( 'action', 'contentsync_ajax' );
				data.append( '_ajax_nonce', greyd.nonce );
				data.append( 'mode', 'check_post_import' );

				// append file data
				var file_data = file_input[ 0 ].files[ 0 ];
				data.append( 'data', file_data );

				// send to admin ajax
				$.ajax( {
					'type': 'POST',
					'url': greyd.ajax_url,
					'processData': false,
					'contentType': false,
					'cache': false,
					'data': data,
					'error': function( xhr, textStatus, errorThrown ) {
						console.warn( textStatus+': '+errorThrown, xhr );
						file_input.val( '' );
						contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': errorThrown+': <code>'+xhr.responseText+'</code>' } );
					},
					'success': function( response ) {
						file_input.val( '' );
						
						// successfull
						if ( response.indexOf( 'success::' ) > -1 ) {

							// get response: conflicting posts
							var result = response.split( 'success::' )[ 1 ];
							result = result.indexOf( '[' ) === 0 ? JSON.parse( result ) : result;
							console.log( result );

							contentsync.postExport.buildConflictOptions( form, result );
							
							/**
							 * trigger overlay 'confirm'
							 * 
							 * callback: this.import( filename )
							 */
							contentsync.tools.confirm( mode, '', contentsync.postExport.import, [ file_data.name ] );
						}
						// complete with error
						else if ( response.indexOf( 'error::' ) > -1 ) {
							var msg = response.split( 'error::' )[ 1 ];
							console.log( msg );
							contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
						}
						// unknown state
						else {
							console.log( response );
							contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
						}
					}
				} );
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

		var $new        = wrapper.find( '.new' );
		var $conflicts  = wrapper.find( '.conflicts' );

		if ( typeof posts === 'object' || Array.isArray( posts ) ) {

			var $list   = $conflicts.find( '.inner_content' );
			var options = decodeURIComponent( $list.data( 'options' ) ).replace( /\+/g, ' ' );

			$new.hide();
			$list.html( '' );

			$.each( posts, function( k, post ) {

				$list.append( '<div><span>'+post.post_link+'</span><select name="'+post.ID+'">'+options+'</select></div>' );

				if ( post.post_type == 'tp_posttypes' ) {
					$list.find( 'select[name="'+post.ID+'"] option[value="keep"]' ).attr( 'disabled', true );
				}
				else if ( post.ID == 'multioption' ) {
					var $select = $list.find( 'select[name="'+post.ID+'"]' );
					$select.removeAttr( 'name' );
					$select.parent().addClass( 'multioption' );
					$select.on( 'change', function() {
						var val = $( this ).val();
						$list.find( 'select' ).each( function(){
							$( this ).val( val );
						} );
					} );
				}
			} );
			$conflicts.show();
		}
		else {
			var $post_title = $new.find( '.post_title' );
			
			$conflicts.hide();
			$post_title.html( posts );
			$new.show();
		}
	};

	/**
	 * Trigger post_import (on check_import overlay confirm)
	 * 
	 * @param {string} filename 
	 */
	this.import = function( filename ) {

		var mode        = 'post_import';
		var form        = $( '#post_import_form' );
		var form_data   = form.serializeArray().reduce( function( obj, item ) {
			obj[ item.name ] = item.value;

			return obj;
		}, {} );
		var data = {
			filename: filename,
			conflicts: form_data
		};
		console.log( data );

		let isPosttype = false;

		const urlParams = new URLSearchParams( window.location.search );
		if ( urlParams.has( 'post_type' ) && urlParams.get( 'post_type' ) == 'tp_posttypes' ) {
			isPosttype = true;
		}
		else if ( data.filename && data.filename.indexOf( 'posts_and_posttype' ) > -1 ) {
			isPosttype = true;
		}
		
		contentsync.postExport.postImport( data, isPosttype );
	};

	/**
	 * Import a post / posttype.
	 * 
	 * If @param isPosttype is set, we import the posttype first, and trigger
	 * a second ajax call to import the posts after. This ensures that all
	 * taxonomies etc. are setup correctly.
	 * 
	 * @param {object} data Data containing filename & conflicts
	 * @param {bool} isPosttype Whether we import only the posttype first
	 */
	this.postImport = function( data, isPosttype ) {

		const mode = 'post_import';

		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': isPosttype ? 'posttype_import' : 'post_import',
				'data': data
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {
					var msg = response.split( 'success::' )[ 1 ];

					if ( isPosttype ) {

						// on succes we usually get the new conflicts array
						try {
							data.conflicts = JSON.parse( msg );
						} catch( e ) {
							console.error( e );
							contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': 'Error parsing JSON. See console for details.' } );

							return;
						}

						console.log( data );
						contentsync.postExport.postImport( data, false );

					} else {

						// use for development
						// contentsync.tools.triggerOverlay( true, { "type": "success", "css": mode } );return;
	
						// trigger overlay
						contentsync.tools.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
					}
				}
				// complete with error
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				// unknown state
				else {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};


	/**
	 * Start the switch theme dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openSwitchTemplateTheme = function( elem ) {
		contentsync.tools.confirm( 'switch_template_theme', '', contentsync.postExport.switchTemplateTheme, [ elem ] );
	};

	/**
	 * Switch the theme of a template or template part
	 * 
	 * @param {object} elem 
	 */
	this.switchTemplateTheme = function( elem ) {

		const mode    = 'switch_template_theme';
		const post_id = $( elem ).data( 'post_id' );
		
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': {
					post_id: post_id,
					switch_references_in_content: document.getElementById( 'switch_references_in_content' ).checked
				}
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// use for development
					// contentsync.tools.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentsync.tools.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};


	/**
	 * Start the switch theme dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openSwitchGlobalStyles = function( elem, templateTheme ) {
		contentsync.tools.confirm( 'switch_global_styles', templateTheme, contentsync.postExport.switchGlobalStyles, [ elem ] );
	};

	/**
	 * Switch the theme of a template or template part
	 * 
	 * @param {object} elem 
	 */
	this.switchGlobalStyles = function( elem ) {

		const mode    = 'switch_global_styles';
		const post_id = $( elem ).data( 'post_id' );
		
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': {
					post_id: post_id
				}
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// use for development
					// contentsync.tools.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentsync.tools.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};


	/**
	 * Start the rename dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openRenameTemplate = function( elem ) {
		
		const post_title = $( elem ).data( 'post_title' );
		const post_name  = $( elem ).data( 'post_name' );

		$( '#rename_template_form input[name="new_post_title"]' ).val( post_title );
		$( '#rename_template_form input[name="new_post_name"]' ).val( post_name );

		contentsync.tools.confirm( 'rename_template', '', contentsync.postExport.renameTemplate, [ elem ] );
	};

	/**
	 * Rename a template, template part or global style.
	 * 
	 * @param {object} elem 
	 */
	this.renameTemplate = function( elem ) {

		const mode    = 'rename_template';
		const post_id = $( elem ).data( 'post_id' );
		const data   = {
			post_id: post_id,
			post_title: $( '#rename_template_form input[name="new_post_title"]' ).val(),
			post_name: $( '#rename_template_form input[name="new_post_name"]' ).val()
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

					// use for development
					// contentsync.tools.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentsync.tools.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};
};