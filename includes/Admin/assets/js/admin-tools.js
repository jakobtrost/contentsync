/**
 * Content Sync - Admin JS
 */
( function () {

	if ( typeof $ === 'undefined' ) $ = jQuery;
	$( function () {
		contentsync.init();
		contentsync.tools.init();
	} );

} )( jQuery );

var contentsync = new function () {

	this.init = function () {

		if ( $( 'body' ).hasClass( 'toplevel_page_global_contents' ) ) {
			// do stuff on the overview page...
			this.checkForErrorPosts();
			// bulk import
			this.importBulkInit();
		}

		// edit.php of root post
		if ( $( 'body' ).hasClass( 'contentsync_root' ) ) {
			var post_id = $( '#post_ID' ).val();
			if ( post_id && post_id.length > 0 ) {
				this.checkRootConnections( post_id );
			}
		}

		// look for similar posts
		if ( $( '#contentsync_similar_posts' ).length ) {
			var post_id = $( '#post_ID' ).val();
			if ( post_id && post_id.length > 0 ) {
				this.checkSimilarPosts( post_id );
			}
		}

		// connection options
		if ( $( '.contentsync_connection_options' ).length ) {
			this.initConnectionOptions();
		}


	};

	/**
	 * Post data to admin ajax
	 */
	this.ajax = function ( action, data ) {

		data.action = action;
		var form = $( 'form#' + action + '_form' );
		if ( form.length > 0 ) {
			data.form_data = form.serializeArray().reduce( function ( obj, item ) {
				obj[ item.name ] = item.value;

				return obj;
			}, {} );
		}

		$.post(
			greyd.ajax_url ?? wizzard_details.ajax_url,
			{
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
				'mode': 'global_action',
				'data': data
			},
			function ( response ) {
				console.log( response );

				let mode = response.indexOf( 'error::' ) > -1 ? 'fail' : 'reload';

				/**
				 * Inside the editor, we don't need to reload the page for some actions.
				 */
				if (
					mode === 'reload'
					&& typeof contentsync.editor.postReference !== 'undefined'
					&& contentsync.editor.postReference !== null
				) {
					const actionsWithoutReload = [ 'contentsync_export', 'contentsync_unexport', 'contentsync_unimport', 'contentsync_repair' ];
					if ( actionsWithoutReload.indexOf( action ) > -1 ) {
						contentsync.editor.getData( contentsync.editor.postReference, true );
						mode = 'success';
					}
				}

				// use for development
				// mode = mode === 'reload' ? 'success': 'fail';

				contentsync.tools.triggerOverlay( true, { 'type': mode, 'css': action } );
			}
		);

	};

	/**
	 * Export post as synced post
	 */
	this.exportPost = function ( elem, postId, postTitle ) {

		console.log( elem );

		var action = 'contentsync_export';
		var post_id = typeof postId === 'undefined' ? $( elem ).data( 'post_id' ) : postId;

		if ( typeof postTitle === 'undefined' ) {
			// Try to get post title from closest 'tr' element (post list page)
			postTitle = $( elem ).closest( 'tr' ).find( '.row-title' ).text();
			
			// If not found, try to get from post title input field (edit page)
			if ( !postTitle || postTitle.trim() === '' ) {
				postTitle = (
					$( '#title' ).val()
					|| $( '#post-title-0' ).val()
					|| $( 'input[name="post_title"]' ).val()
					|| $( '.editor-post-title__input' ).val()
					|| $( 'h1.wp-block-post-title' ).text()
					|| $( '.wp-block-post-title' ).text().first().text()
				);
			}
		}

		contentsync.checkUnsavedChanges();
		contentsync.tools.confirm( action, postTitle, contentsync.ajax, [ action, { 'post_id': post_id } ] );
	};

	/**
	 * Remove global connection of exported post
	 */
	this.unexportPost = function ( elem, gid ) {

		var action = 'contentsync_unexport';
		var gid    = typeof gid === 'undefined' ? $( elem ).data( 'gid' ) : gid;

		contentsync.checkUnsavedChanges();
		contentsync.tools.confirm( action, '', contentsync.ajax, [ action, { 'gid': gid } ] );
	};

	/**
	 * Check for conflicts before importing a post
	 */
	this.checkImport = function ( elem ) {

		var action = 'contentsync_import';
		var gid = $( elem ).data( 'gid' );
		var postType = $( elem ).data( 'post_type' );
		var form = $( '#contentsync_import_form' );
		this.checkImportOverlay( action, gid, postType, form );

	};

	this.checkImportOverlay = function( action, gid, postType, form ) {

		contentsync.tools.triggerOverlay( true, { 'type': 'check_post', 'css': action } );

		$.post(
			greyd.ajax_url ?? wizzard_details.ajax_url,
			{
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
				'mode': 'global_action',
				'data': {
					'gid': gid,
					'action': 'contentsync_check_synced_post_import',
				}
			},
			function ( response ) {
				console.log( response );

				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// get response: conflicting posts
					var result = response.split( 'success::' )[ 1 ];
					try {
						result = result.indexOf( '[' ) === 0 ? JSON.parse( result ) : result;
						console.log( result );
					} catch ( e ) {
						console.error( e );
					}

					console.log( contentsync.postExport );

					// display conflicts
					contentsync.buildConflictOptions( form, result, true );

					/**
					 * trigger overlay 'confirm'
					 * 
					 * callback: this.importPost( gid );
					 */
					contentsync.tools.confirm( action, '', contentsync.importPost, [ gid, postType ] );
				}
				// complete with error
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': msg } );
				}
				// unknown state
				else {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': response } );
				}
			}
		);

		// contentsync.tools.confirm( action, '', contentsync.ajax, [ action, { 'gid': gid } ] );
	};

	/**
	 * Actually import the post
	 */
	this.importPost = function ( gid, postType ) {

		var action = 'contentsync_import';
		var data = { gid: gid, action: action };
		var form = $( 'form#' + action + '_form' );
		if ( form.length > 0 ) {
			data.form_data = form.serializeArray().reduce( function ( obj, item ) {
				obj[ item.name ] = item.value;

				return obj;
			}, {} );
		}

		contentsync.ajax( action, data );
	};


	/**
	 * Bulk Import
	 */
	this.importBulkInit = function() {
		
		if ( $( '#posts-filter' ).length == 0 ) {
			// escape
			return;
		}

		// hook bulk submit
		$( '#posts-filter' ).on( 'submit', ( e ) => {
			// console.log(e);
			const data = [ ...new FormData( e.target ).entries() ];
			
			if (
				Object.fromEntries( data ).action == 'import' ||
				Object.fromEntries( data ).action2 == 'import'
			) {
				// console.log("trigger bulk import");
				var posts = [];
				data.forEach( entry => {
					if ( entry[ 0 ] == 'gids[]' ) {
						const cb = document.querySelector( 'input[value=\''+entry[ 1 ]+'\']' );
						const anchor = cb.parentElement.parentElement.querySelector( '.root a' );
						const title = decodeURIComponent( cb.dataset.title );
						posts.push( {
							gid: entry[ 1 ],
							post_title: title,
							post_type: cb.dataset.pt,
							relationship: cb.dataset.rel,
							post_link: anchor?.href ? '<a href="'+anchor.href+'" target="_blank">'+title + '(' + cb.dataset.pt + ')</a>' : title + '(' + cb.dataset.pt + ')',
						} );
					}
				} );
				// console.log(posts);
				if ( posts.length == 0 ) {
					// console.info("no posts selected");
				}
				else if ( posts.length == 1 ) {
					if ( posts[ 0 ].relationship != 'import' ) {
						// console.log("import single post:", posts[0]);
						this.checkImportOverlay( 'contentsync_import', posts[ 0 ].gid, posts[ 0 ].post_type, $( '#contentsync_import_form' ) );
					}
					else {
						// console.info("selected post is already imported:", posts[0]);
						contentsync.tools.triggerOverlay( true, { 'type': 'imported', 'css': 'contentsync_import_bulk', 'replace': posts[ 0 ].title } );
						contentsync.tools.fadeOutOverlay();
					}
				}
				else {
					console.log( 'import posts:', posts );
					this.checkImportBulkOverlay( 'contentsync_import_bulk', posts, $( '#contentsync_import_bulk_form' ) );
				}

				// stop submit
				e.preventDefault();
			}
			
			// // stop all submit (dev)
			// e.preventDefault();
		} );

	};

	/**
	 * Check for conflicts before importing posts
	 */
	this.checkImportBulkOverlay = function( action, posts, form ) {

		contentsync.tools.triggerOverlay( true, { 'type': 'check_post', 'css': action } );
		// clear old items
		form.find( '.inner_content.item' ).remove();

		$.post(
			greyd.ajax_url ?? wizzard_details.ajax_url,
			{
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
				'mode': 'global_action',
				'data': {
					'posts': posts,
					'action': 'contentsync_check_synced_post_import_bulk',
				}
			},
			function ( response ) {
				// console.log( response );
				var error = false;

				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// parse response: conflicting posts
					let result = response.split( 'success::' )[ 1 ];
					let conflicts = {};
					try {
						if ( result.indexOf( '[' ) === 0 ) {
							result = JSON.parse( result );
							// console.log( result );
							result.forEach( ( res, i ) => {
								let postConflicts = res?.conflict?.indexOf( '[' ) === 0 ? JSON.parse( res?.conflict ) : res?.conflict;
								console.log( 'postConflicts', postConflicts );
								if ( typeof postConflicts !== 'string' ) {
									conflicts[ res.gid ] = postConflicts;
								}
							} );
						}
						
					} catch ( e ) {
						console.error( e );
						error = true;
					}

					if ( typeof result === 'string' ) {
						error = true;
					}

					if ( !error ) {
						console.log( 'posts', posts );
						console.log( 'conflicts', conflicts );

						let hasConflicts = false;
						let clear = true;

						// get response data and attach to posts
						posts.forEach( ( post, i ) => {

							// display conflicts
							// contentsync.buildConflictOptions( form, [ post ], false );

							let postsForConflict = [];

							if ( conflicts[ post.gid ] ) {

								// add gid to each conflict
								conflicts[ post.gid ].forEach( ( conflict, i ) => {
									conflict.gid = post.gid;
									postsForConflict.push( conflict );
								} );
								hasConflicts = true;
							}
							else {
								postsForConflict.push( post );
							}

							// display conflicts
							contentsync.buildConflictOptions( form, postsForConflict, clear );
							clear = false;
						} );

						if ( ! hasConflicts ) {
							form.find( '.conflicts > p' ).hide();
							form.find( '.conflicts .multioption' ).remove();
							form.find( '.new' ).show();
						} else {
							form.find( '.conflicts > p' ).show();
							form.find( '.new' ).hide();
						}

						/**
						 * trigger overlay 'confirm'
						 * 
						 * callback: this.importBulk( posts, form );
						 */
						contentsync.tools.confirm( action, '', contentsync.importBulk, [ posts, form ] );
					}

				}

				// complete with error
				if ( error || response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': msg } );
				}
				// unknown state
				else if ( response.indexOf( 'success::' ) == -1 ) {
					contentsync.tools.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': response } );
				}
			}
		);

	};

	/**
	 * Perform bulk import by using a 'import queue'.
	 * Each item is an ajax call, triggered by the one before.
	 */
	this.importQueue = [];
	this.importBulk = function( posts, form ) {

		// get form data (all conflict options)
		const data = form.serializeArray().reduce( function ( obj, item ) {
			obj[ item.name ] = item.value;

			return obj;
		}, {} );
		// console.log(posts);
		// console.log(data);

		// copy form items to loader log
		const loaderContent = $( '.contentsync_overlay .loading .import_bulk .inner_content' );
		loaderContent.children().remove();
		const innerContent = form.find( '.inner_content' );
		innerContent.children( ':not(.multioption)' ).each( ( i,item ) => {
			var copy = $( item ).clone();
			copy.find( 'select' ).each( ( i, select ) => {
				// js first char to uppercase
				let text = data[ select.name ];
				text = text.charAt( 0 ).toUpperCase() + text.slice( 1 );
				$( select ).parent().append( '<span class="relationship">'+text+'</span>' );
				$( select ).remove();
			} );
			loaderContent.append( copy );
		} );

		// empty queue
		contentsync.importQueue = [];

		// make import queue
		posts.forEach( ( item, i ) => {
			if ( item.relationship == 'import' ) {
				// skip if already imported
				contentsync.importQueue.push( {
					gid: item.gid,
					action: 'skip',
					callback: () => {
						loaderContent.find( '[data-gid="'+item.gid+'"]' ).each( ( i, item ) => {
							$( item ).find( '.relationship' ).html( innerContent.data( 'import' ) );
						} );

						return true;
					}
				} );
			}
			else {

				// import post
				contentsync.importQueue.push( {
					gid: item.gid,
					ajax_data: {
						gid: item.gid,
						action: 'contentsync_import',
						form_data: data
					},
					callback: ( response ) => {
						// console.log( response );
						if ( response.indexOf( 'success::' ) > -1 ) {
							loaderContent.find( '[data-gid="'+item.gid+'"]' ).each( ( i, item ) => {
								$( item ).find( '.relationship' ).html( innerContent.data( 'success' ) );
							} );

							return true;
						}
						else {
							var msg = false;
							// if ( response.indexOf( 'error::' ) > -1 ) {
							// 	var msg = response.split( 'error::' )[1];
							// 	loaderContent.find( '[data-gid="'+item.gid+'"] .res .fail' ).attr( "title", msg );
							// }
							console.warn( item.gid+' import failed:', msg );
							loaderContent.find( '[data-gid="'+item.gid+'"]' ).each( ( i, item ) => {
								$( item ).find( '.relationship' ).html( innerContent.data( 'fail' ) );
							} );

							return response;
						}
					}
				} );
			}
		} );

		// start queue
		// console.log(contentsync.importQueue);
		contentsync.importBulkNext( 0 );

	};

	this.importBulkNext = function( index ) {

		if ( contentsync.importQueue.length == index ) {
			// finish queue
			contentsync.importBulkFinish();

			return;
		}

		var item = contentsync.importQueue[ index ];
		if ( item.ajax_data ) {
			$.post(
				greyd.ajax_url ?? wizzard_details.ajax_url,
				{
					'action': 'contentsync_ajax',
					'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
					'mode': 'global_action',
					'data': item.ajax_data
				},
				function ( response ) {
					contentsync.importQueue[ index ].result = item.callback( response );
					// next
					contentsync.importBulkNext( index+1 );
				}
			);
		}
		else {
			if ( item.action && item.action == 'skip' ) {
				contentsync.importQueue[ index ].result = item.callback();
			}

			// next
			contentsync.importBulkNext( index+1 );
		}

	};

	this.importBulkFinish = function() {
	
		// success or fail (some failed)
		var mode = 'success';
		contentsync.importQueue.forEach( item => {
			// console.log(item);
			if ( item.result !== true ) {
				mode = 'fail';
			}
		} );

		// copy loader items to result log
		const loaderContent = $( '.contentsync_overlay .loading .import_bulk .inner_content' );
		const resultContent = $( '.contentsync_overlay .'+mode+' .import_bulk .inner_content' );
		resultContent.children().remove();
		loaderContent.children( ':not(.multioption)' ).each( ( i,item ) => {
			var copy = $( item ).clone();
			resultContent.append( copy );
		} );

		// add OK button
		var button = '<div class="flex flex-end"><a class="button button-primary huge" href="javascript:window.location.reload()">OK</a></div>';
		$( '.success .success_mark' ).parent().append( button );
		$( '.fail .color_light.escape' ).parent().append( button );
		$( '.fail .color_light.escape' ).remove();

		// show result
		contentsync.tools.triggerOverlay( true, { 'type': mode, 'css': 'contentsync_import_bulk' } );
		clearTimeout( contentsync.tools.overlayTimeout );
		
	};


	/**
	 * Remove connection on imported post (make static)
	 */
	this.unimportPost = function ( elem, postId ) {

		var action = 'contentsync_unimport';
		var post_id = typeof postId === 'undefined' ? $( elem ).data( 'post_id' ) : postId;

		contentsync.tools.confirm( action, '', contentsync.ajax, [ action, { 'post_id': post_id } ] );
	};

	/**
	 * Overwrite local post with synced post
	 */
	this.overwritePost = function ( elem, postId ) {

		var action = 'contentsync_overwrite';
		var data = {
			'post_id': typeof postId === 'undefined' ? $( elem ).data( 'post_id' ) : postId,
			'gid': $( elem ).data( 'gid' )
		};
		var replace = $( elem ).prev().text();

		contentsync.checkUnsavedChanges();
		contentsync.tools.confirm( action, replace, contentsync.ajax, [ action, data ] );
	};

	/**
	 * Repair post
	 * 
	 * @param {DOMElement} elem event target
	 * @param {int} postId
	 */
	this.repairPost = function ( elem, postId ) {

		var action = 'contentsync_repair';
		var data = $( elem ).data();

		if ( typeof postId !== 'undefined' ) {
			data[ 'post_id' ] = postId;
		}

		contentsync.tools.confirm( action, '', contentsync.ajax, [ action, data ] );
	};
	
	/**
	 * Trash post
	 */
	this.trashPost = function ( elem ) {

		var action = 'contentsync_trash';

		contentsync.tools.confirm( action, '', contentsync.ajax, [ action, $( elem ).data() ] );
	};

	/**
	 * Delete synced posts
	 */
	this.deletePost = function ( elem ) {

		var action = 'contentsync_delete';
		var gid = $( elem ).data( 'gid' );

		contentsync.tools.confirm( action, '', contentsync.ajax, [ action, { 'gid': gid } ] );
	};



	/**
	 * Check for unsaved changes and display notice inside overlay if necessary
	 */
	this.checkUnsavedChanges = function () {

		var showNotice = false;

		if ( typeof composer !== 'undefined' && typeof vc !== 'undefined' ) {
			showNotice = composer.init_content !== vc.storage.getContent();
		}
		else if ( typeof wp !== 'undefined' && typeof wp.data !== 'undefined' && typeof wp.data.select !== 'undefined' ) {
			let selectBlockEditor = wp.data.select( 'core/editor' );
			showNotice = selectBlockEditor && selectBlockEditor.hasChangedContent();
		}

		if ( showNotice ) {
			$( '#overlay .confirm > .depending.contentsync_unsaved' ).show();
		} else {
			$( '#overlay .confirm > .depending.contentsync_unsaved' ).hide();
		}

	};

	/**
	 * Check for missing connections of a root post via ajax
	 */
	this.checkRootConnections = function ( post_id ) {
		$.post(
			greyd.ajax_url ?? wizzard_details.ajax_url,
			{
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
				'mode': 'global_action',
				'data': {
					'action': 'contentsync_check_post_connections',
					'post_id': post_id
				}
			},
			function ( response ) {
				console.log( response );
			}
		);
	};

	/**
	 * Check similar posts of a normal post
	 * @param {int} post_id WP_Post ID
	 */
	this.checkSimilarPosts = function ( post_id ) {
		$.post(
			greyd.ajax_url ?? wizzard_details.ajax_url,
			{
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
				'mode': 'global_action',
				'data': {
					'action': 'contentsync_similar_posts',
					'post_id': post_id
				}
			},
			function ( response ) {
				// console.log(response);

				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					var similar_posts = null;
					try {
						similar_posts = JSON.parse( response.split( 'success::' )[ 1 ] );
						// console.log( similar_posts );
					} catch ( e ) {
						console.error( e );
						$( '#contentsync_similar_posts' ).children().addClass( 'hidden' );
						$( '#contentsync_similar_posts' ).children( '.not_found' ).removeClass( 'hidden' );

						return;
					}

					var i = 0;
					var wrapper = $( '#contentsync_similar_posts' ).children( '.found' );
					var list = wrapper.find( 'ul' );
					var data_item = list.data( 'item' );

					// append list item
					for ( var gid in similar_posts ) {
						var post = similar_posts[ gid ];
						var item = data_item.replace(
							'{{post_title}}', post?.post_title
						).replace(
							'{{post_id}}', post_id // current post_id set as function param
						).replace(
							'{{gid}}', gid // array key
						).replace(
							'{{href}}', post?.post_links?.edit
						).replace(
							'{{nice_url}}', post?.post_links?.nice
						);
						list.append( item );
						i++;
					}

					// show singular or plural text
					if ( i < 2 ) {
						wrapper.find( '.singular' ).removeClass( 'hidden' );
					} else {
						wrapper.find( '.plural' ).removeClass( 'hidden' );
					}

					// show found div
					$( '#contentsync_similar_posts' ).children().addClass( 'hidden' );
					wrapper.removeClass( 'hidden' );

					// show overlay warning
					$( '.export_warning_similar_posts' ).removeClass( 'hidden' );
				}
				// complete with error OR unknown state
				else {
					$( '#contentsync_similar_posts' ).children().addClass( 'hidden' );
					$( '#contentsync_similar_posts' ).children( '.not_found' ).removeClass( 'hidden' );
				}
			}
		);
	};

	/**
	 * Mae export options editable for root posts
	 */
	this.initConnectionOptions = function () {

		var timeout = null;
		var saving = false;

		// show the button when an option is changed
		$( '.contentsync_connection_options label' ).on( 'click', function () {
			if ( !saving ) {
				$( this ).siblings( '.button' ).removeClass( 'hidden' );
			}
		} );

		// save the options
		$( '.contentsync_connection_options .button' ).on( 'click', function () {

			// not multiple actions at once
			if ( saving ) {
				console.log( 'saving in progress...' );

				return;
			} else {
				saving = true;
			}

			var button = $( this );
			var wrapper = button.closest( '.contentsync_connection_options' );
			var site_url = wrapper.data( 'site_url' );
			var contents = wrapper.find( 'input[name=\'contents\']' ).is( ':checked' );
			var search = wrapper.find( 'input[name=\'search\']' ).is( ':checked' );

			button.addClass( 'loading' );

			$.post(
				greyd.ajax_url ?? wizzard_details.ajax_url,
				{
					'action': 'contentsync_ajax',
					'_ajax_nonce': greyd.nonce ?? wizzard_details.nonce,
					'mode': 'global_action',
					'data': {
						'action': 'contentsync_update_site_connections',
						'site_url': site_url,
						'contents': contents,
						'search': search,
					}
				},
				function ( response ) {
					console.log( response );

					var success = response.indexOf( 'success::' ) > -1;

					button.addClass( success ? 'success' : 'fail button-danger' );

					// reset
					clearTimeout( timeout );
					timeout = setTimeout( function () {
						button.removeClass( 'success fail button-danger loading' );
						if ( success ) button.addClass( 'hidden' );
						saving = false;
					}, 1500 );
				}
			);
		} );
	};

	/**
	 * Check for posts with errors on the overview page
	 */
	this.checkForErrorPosts = function () {

		const elem = $( 'ul.subsubsub li.errors .js_check_errors' );

		// don't look for errors on the error page itself
		if ( elem.length === 0 ) return;

		const data = elem.data();

		$.post(
			greyd.ajax_url ?? wizzard_details.ajax_url,
			{
				action: 'contentsync_ajax',
				_ajax_nonce: greyd.nonce ?? wizzard_details.nonce,
				mode: 'global_action',
				data: {
					action: 'contentsync_error_posts',
					...data
				}
			},
			function ( response ) {
				console.log( response );

				elem.children( '.loading' ).remove();

				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					var posts = null;
					try {
						posts = JSON.parse( response.split( 'success::' )[ 1 ] );
						console.log( posts );
					} catch ( e ) {
						console.error( e );

						// do something on error...
						return;
					}

					var length = '?';
					if ( Array.isArray( posts ) ) {
						length = posts.length;
					} else if ( typeof posts === 'object' && posts ) {
						length = Object.values( posts ).length;
					}

					elem.find( '.errors_found' ).removeClass( 'hidden' );
					elem.find( '.count' ).text( '(' + length + ')' );
				}
				// complete with error OR unknown state
				else {
					elem.find( '.no_errors' ).removeClass( 'hidden' );
				}
			}
		);
	};

	/**
	 * Build the conflict selects inside the wrapper
	 * 
	 * @see contentsync_hub/features/post-export/assets/js/post-export.js
	 * 
	 * @param {object} wrapper  HTML wrapper jQuery object
	 * @param {mixed} posts     Either an array of posts or a string.
	 */
	this.buildConflictOptions = function ( wrapper, posts, clear ) {

		// if ( typeof contentsync.postExport !== 'undefined' ) {
		// 	return contentsync.postExport.buildConflictOptions( wrapper, posts );
		// }
		// else if ( typeof post_export !== 'undefined' ) {
		// 	return post_export.buildConflictOptions( wrapper, posts );
		// }

		var $new = wrapper.find( '.new' );
		var $conflicts = wrapper.find( '.conflicts' );

		if ( typeof posts === 'object' || Array.isArray( posts ) ) {

			var $list = $conflicts.find( '.inner_content' );
			var options = decodeURIComponent( $list.data( 'options' ) ).replace( /\+/g, ' ' );

			$new.hide();

			if ( clear ) $list.html( '' );

			// add multioption select
			if ( $list.find( '.multioption' ).length == 0 ) {

				$list.prepend(
					'<div class="multioption">' + $list.data( 'multioption' ) + '<select name="multioption">' + options + '</select>'
				);
				$list.find( 'select[name="multioption"]' ).on( 'change', function () {
					var val = $( this ).val();
					$list.find( 'select' ).each( function () {
						$( this ).val( val );
					} );
				} );
			}

			// console.log( posts );

			$.each( posts, function ( k, post ) {
				
				if ( post.ID == 'multioption' ) {
					return;
				}

				let left = '<span>' + post?.post_link + '</span>';

				let right = '';
				if ( post?.relationship ) {
					console.log( 'post.relationship', post?.relationship );
					right = '<span class="relationship">' + $list.data( post?.relationship ) + '</span>';
				} else {
					right = '<select name="' + post?.ID + '">' + options + '</select>';
				}

				$list.append( '<div data-gid="' + ( post?.gid ?? '' ) + '">' + left + right + '</div>' );

				if ( post.post_type == 'tp_posttypes' ) {
					$list.find( 'select[name="' + post.ID + '"] option[value="keep"]' ).attr( 'disabled', true );
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
	 * Approve post review
	 */
	this.openReviewApprove = function ( elem, post_id, review_id ) {

		const action = 'contentsync_review_approve';

		contentsync.checkUnsavedChanges();
		contentsync.tools.confirm( action, '', contentsync.ajax, [ action, { 'review_id': review_id, 'post_id': post_id } ] );
	};

	/**
	 * Deny post review
	 */
	this.openReviewDeny = function ( elem, post_id, review_id ) {

		const action = 'contentsync_review_deny';

		contentsync.checkUnsavedChanges();
		contentsync.tools.confirm( action, '', contentsync.reviewDeny, [ post_id, review_id ] );
	};

	this.reviewDeny = function ( post_id, review_id ) {

		console.log( 'reviewDeny', post_id, review_id );

		const action = 'contentsync_review_deny';

		const message = document.getElementById( 'review_message_deny' ).value;

		contentsync.ajax( action, {
			'review_id': review_id,
			'post_id': post_id,
			'message': message
		} );
	};

	/**
	 * Revert post review
	 */
	this.openReviewRevert = function ( elem, post_id, review_id ) {

		var action = 'contentsync_review_revert';

		contentsync.checkUnsavedChanges();
		contentsync.tools.confirm( action, '', contentsync.revertReview, [ post_id, review_id ] );
	};

	this.revertReview = function ( post_id, review_id ) {
		
		console.log( 'revertReview', post_id, review_id );

		const action = 'contentsync_review_revert';

		const message = document.getElementById( 'review_message_revert' ).value;

		contentsync.ajax( action, {
			'review_id': review_id,
			'post_id': post_id,
			'message': message
		} );

	};

	/**
	 * Site editor functions.
	 */
	this.editor = new function () {

		/**
		 * fallback for setData
		 */
		this.setData = ( data ) => {
			console.log( 'setData not initialized via useState()', data );
		};

		this.data = {
			postReference: null,
			post: {
				id: -1,
				title: '',
				gid: '',
				status: '',
			},
			similarPosts: null
		};

		/**
		 * Set Notice based on response.
		 * 
		 * @param {Array} notice
		 */
		this.setNotice = ( notice ) => {
	
			if ( notice && notice.length > 0 ) {
				wp.data.dispatch( 'core/notices' ).removeNotices(
					wp.data.select( 'core/notices' ).getNotices().map( ( n ) => n.id )
				);

				// convert action onClick strings into function
				notice[ 2 ]?.actions.forEach( action => {
					if ( typeof action.onClick === 'string' ) {
						action.onClick = new Function( action.onClick );
					}
				} );

				wp.data.dispatch( 'core/notices' ).createNotice(
					notice[ 0 ],
					notice[ 1 ],
					notice[ 2 ]
				);
			}
			// remove all notices
			else {
				wp.data.dispatch( 'core/notices' ).removeNotices(
					wp.data.select( 'core/notices' ).getNotices().map( ( n ) => n.id )
				);
			}
		};
	
		/**
		 * Set Post Data
		 */
		this.getData = ( postReference, forceReload ) => {
	
			if ( typeof forceReload === 'undefined' || !forceReload ) {
				if ( postReference === null || contentsync.editor?.data?.postReference === postReference ) {
					return;
				}
			}

			if ( typeof wp?.apiFetch === 'undefined' ) {
				console.error( 'wp.apiFetch not defined' );

				return;
			}
	
			wp.apiFetch( {
				path: '/contentsync/v1/get_post_info',
				method: 'POST',
				data: {
					postReference: postReference
				},
			} ).then( ( res ) => {
				
				const response = JSON.parse( res );
				// console.log( 'response from: /contentsync/v1/get_post_info', response );
	
				if ( response?.status === 200 ) {
					if ( response?.data?.post?.status === 'linked' ) {
						document.body.classList.add( 'contentsync-locked' );
					} else {
						document.body.classList.remove( 'contentsync-locked' );
					}

					contentsync.editor.setNotice( response?.data?.notice );
					console.log( 'setData:', response?.data );
					contentsync.editor.setData( {
						postReference: postReference,
						post: response?.data?.post,
						similarPosts: null,
						options: response?.data?.post?.options || {
							append_nested: true,
							resolve_menus: false,
							whole_posttype: false,
							translations: false
						},
						canonicalUrl: response?.data?.post?.canonicalUrl || '',
						showEditOptions: false
					} );
				} else {
					document.body.classList.remove( 'contentsync-locked' );
					contentsync.editor.setNotice( [] );
					contentsync.editor.setData( {
						postReference: postReference,
						post: {
							id: 0,
							title: '',
							gid: '',
							status: '',
						},
						similarPosts: null,
						options: {
							append_nested: true,
							resolve_menus: false,
							whole_posttype: false,
							translations: false
						},
						canonicalUrl: '',
						showEditOptions: false
					} );
				}
	
			} ).catch( ( err ) => {
				document.body.classList.remove( 'contentsync-locked' );
				console.error( 'apiFetch error: ', err );
				contentsync.editor.setData( {
					postReference: postReference,
					post: {
						id: 0,
						title: '',
						gid: '',
						status: '',
					},
					similarPosts: null,
					options: {
						append_nested: true,
						resolve_menus: false,
						whole_posttype: false,
						translations: false
					},
					canonicalUrl: '',
					showEditOptions: false
				} );
			} );
		};

		/**
		 * Save Contentsync options
		 * 
		 * @param {int} post_id
		 * @param {object} options
		 * @param {string} canonical_url
		 */
		this.saveOptions = function( post_id, options, canonical_url ) {

			if ( typeof wp?.apiFetch === 'undefined' ) {
				console.error( 'wp.apiFetch not defined' );

				return;
			}

			wp.apiFetch( {
				path: '/contentsync/v1/save_options',
				method: 'POST',
				data: {
					post_id: post_id,
					options: options,
					canonical_url: canonical_url
				},
			} ).then( ( res ) => {
				
				const response = JSON.parse( res );
				console.log( 'saveOptions response:', response );

				if ( response?.status === 200 ) {
					greyd?.tools?.showSnackbar( 'Options saved successfully', 'success', true );
				} else {
					greyd?.tools?.showSnackbar( response?.message || 'Failed to save options', 'error', true );
				}

			} ).catch( ( err ) => {
				console.error( 'saveOptions error: ', err );
				greyd?.tools?.showSnackbar( 'Failed to save options', 'error', true );
			} );
		};

		/**
		 * Get similar posts
		 * 
		 * @param {int} post_id
		 * @param {function} callback
		 */
		this.getSimilarPosts = function ( postId ) {
	
			$.post(
				greyd.ajax_url,
				{
					'action': 'contentsync_ajax',
					'_ajax_nonce': greyd.nonce,
					'mode': 'global_action',
					'data': {
						'action': 'contentsync_similar_posts',
						'post_id': postId
					}
				},
				function ( response ) {
					// console.log(response);
	
					let similarPosts = [];
	
					// successfull
					if ( response.indexOf( 'success::' ) > -1 ) {
						try {
							similarPosts = Object.values( JSON.parse( response.split( 'success::' )[ 1 ] ) );
						} catch ( e ) {
							console.error( e );

							return;
						}
					}

					contentsync.editor.setData( {
						...contentsync.editor.data,
						similarPosts: similarPosts
					} );
				}
			);
		};

		this.renderStatusBox = function ( status, text ) {
			const {
				createElement: el,
			} = wp.element;
			const {
				__
			} = wp.i18n;

			status = status === 'root' ? 'export' : ( status === 'linked' ? 'import' : status );
			let titles = {
				export: __( 'Root post', 'contentsync' ),
				import: __( 'Linked post', 'contentsync' ),
				error: __( 'Error', 'contentsync' ),
			};
			let title = titles[ status ] ?? status;
			let color = 'red';
			if ( status === 'export' ) {
				color = 'purple';
			} else if ( status === 'import' ) {
				color = 'green';
			} else if ( status === 'info' ) {
				color = 'blue';
			}

			let icons = {
				export: el( 'svg', {
					width: '24',
					height: '24',
					viewBox: '0 0 24 24',
					fill: 'none',
					xmlns: 'http://www.w3.org/2000/svg'
				}, el( 'path', {
					d: 'M21 4.15431V12.8116C21 13.3166 20.7655 13.6713 20.2966 13.8758C20.1403 13.9359 19.99 13.9659 19.8457 13.9659C19.521 13.9659 19.2505 13.8517 19.0341 13.6232L16.4369 11.0261L6.80561 20.6573C6.57715 20.8858 6.30661 21 5.99399 21C5.68136 21 5.41082 20.8858 5.18237 20.6573L3.34269 18.8176C3.11423 18.5892 3 18.3186 3 18.006C3 17.6934 3.11423 17.4228 3.34269 17.1944L12.9739 7.56313L10.3768 4.96593C10.004 4.61723 9.91984 4.19639 10.1242 3.70341C10.3287 3.23447 10.6834 3 11.1884 3H19.8457C20.1583 3 20.4289 3.11423 20.6573 3.34269C20.8858 3.57114 21 3.84168 21 4.15431Z',
					fill: 'currentColor'
				} ) ),
				import: el( 'svg', {
					width: '24',
					height: '24',
					viewBox: '0 0 24 24',
					fill: 'none',
					xmlns: 'http://www.w3.org/2000/svg'
				}, el( 'path', {
					d: 'M14.7583 9.24184C17.0922 11.5781 17.0601 15.3238 14.7724 17.6242C14.7681 17.6289 14.763 17.634 14.7583 17.6387L12.1333 20.2637C9.81807 22.5789 6.05131 22.5786 3.73643 20.2637C1.42119 17.9489 1.42119 14.1817 3.73643 11.8668L5.18588 10.4174C5.57026 10.033 6.23221 10.2885 6.25205 10.8317C6.27736 11.5239 6.40151 12.2195 6.63057 12.8911C6.70815 13.1185 6.65272 13.3701 6.48279 13.54L5.97158 14.0512C4.87682 15.146 4.84248 16.9285 5.92647 18.034C7.02115 19.1504 8.82045 19.157 9.92354 18.0539L12.5485 15.4293C13.6497 14.3281 13.6451 12.5482 12.5485 11.4516C12.404 11.3073 12.2583 11.1952 12.1446 11.1169C12.0641 11.0616 11.9977 10.9883 11.9506 10.9028C11.9034 10.8173 11.877 10.722 11.8732 10.6245C11.8578 10.2117 12.004 9.78633 12.3302 9.46016L13.1526 8.6377C13.3683 8.42204 13.7066 8.39555 13.9567 8.57009C14.2431 8.77007 14.5113 8.99485 14.7583 9.24184V9.24184ZM20.2636 3.73631C17.9487 1.42139 14.1819 1.42108 11.8667 3.73631L9.2417 6.3613C9.23701 6.36599 9.23194 6.37107 9.22764 6.37575C6.9399 8.67622 6.90783 12.4219 9.2417 14.7582C9.48869 15.0051 9.75692 15.2299 10.0433 15.4299C10.2934 15.6044 10.6317 15.5779 10.8474 15.3623L11.6698 14.5398C11.996 14.2136 12.1422 13.7883 12.1267 13.3755C12.123 13.278 12.0965 13.1826 12.0494 13.0971C12.0023 13.0116 11.9358 12.9383 11.8554 12.8831C11.7416 12.8048 11.596 12.6927 11.4514 12.5484C10.3548 11.4518 10.3502 9.67184 11.4514 8.57063L14.0764 5.94603C15.1795 4.84294 16.9788 4.84958 18.0735 5.96595C19.1575 7.07142 19.1232 8.85399 18.0284 9.94875L17.5172 10.46C17.3473 10.6299 17.2918 10.8814 17.3694 11.1089C17.5985 11.7805 17.7226 12.476 17.7479 13.1683C17.7678 13.7115 18.4297 13.9669 18.8141 13.5826L20.2635 12.1331C22.5788 9.81832 22.5788 6.05114 20.2636 3.73631V3.73631Z',
					fill: 'currentColor'
				} ) ),
				error: el( 'svg', {
					width: '24',
					height: '24',
					viewBox: '0 0 24 24',
					fill: 'none',
					xmlns: 'http://www.w3.org/2000/svg'
				}, el( 'path', {
					d: 'M12 21C14.3869 21 16.6761 20.0518 18.364 18.364C20.0518 16.6761 21 14.3869 21 12C21 9.61305 20.0518 7.32387 18.364 5.63604C16.6761 3.94821 14.3869 3 12 3C9.61305 3 7.32387 3.94821 5.63604 5.63604C3.94821 7.32387 3 9.61305 3 12C3 14.3869 3.94821 16.6761 5.63604 18.364C7.32387 20.0518 9.61305 21 12 21V21ZM10.65 16.05C10.65 15.692 10.7922 15.3486 11.0454 15.0954C11.2986 14.8422 11.642 14.7 12 14.7C12.358 14.7 12.7014 14.8422 12.9546 15.0954C13.2078 15.3486 13.35 15.692 13.35 16.05C13.35 16.408 13.2078 16.7514 12.9546 17.0046C12.7014 17.2578 12.358 17.4 12 17.4C11.642 17.4 11.2986 17.2578 11.0454 17.0046C10.7922 16.7514 10.65 16.408 10.65 16.05ZM11.1144 7.338C11.152 7.13049 11.2612 6.94277 11.4231 6.80759C11.5849 6.6724 11.7891 6.59835 12 6.59835C12.2109 6.59835 12.4151 6.6724 12.5769 6.80759C12.7388 6.94277 12.848 7.13049 12.8856 7.338L12.9 7.5V12L12.8856 12.162C12.848 12.3695 12.7388 12.5572 12.5769 12.6924C12.4151 12.8276 12.2109 12.9016 12 12.9016C11.7891 12.9016 11.5849 12.8276 11.4231 12.6924C11.2612 12.5572 11.152 12.3695 11.1144 12.162L11.1 12V7.5L11.1144 7.338Z',
					fill: 'currentColor'
				} ) ),
			};

			return el(
				'span', {
					'data-title': title,
					'class': 'contentsync_info_box ' + color + ' contentsync_status',
				},
				icons[ status ] ?? '',
				text ? el( 'span', null, text ) : ''
			);
		};
	};
};

contentsync.tools = new function() {

	this.overlayTimeout;

	this.init = function() {

		// add trigger to escape-button
		$( '.contentsync_overlay .button[role=\'escape\']' ).on( 'click', function() {
			contentsync.tools.triggerOverlay( false, {
				id: $( this ).closest( '.contentsync_overlay' ).attr( 'id' )
			} );
		} );

		// greyd info popup
		$( '.contentsync_popup_wrapper' ).on( 'click', function( e ){
			contentsync.tools.togglePopup( e, $( this ) );
		} );

		// tabs
		$( '.block_tabs .block_tab' ).on( 'click', function(){
			if ( $( this ).hasClass( 'active' ) ) return;

			$( this ).siblings( '.block_tab.active' ).removeClass( 'active' );
			$( this ).addClass( 'active' );
		} );
	};

	this.togglePopup = function( e, clicked ) {
		// console.log('Pop Up!');
		e.stopPropagation();
		var $toggle = clicked.children( '.toggle' );
		var dialog  = clicked.children( 'dialog' );
		if ( dialog.length ) {
			if ( dialog[ 0 ].open ) dialog[ 0 ].close();
			else dialog[ 0 ].showModal();
		} else {
			$toggle.toggleClass( 'checked dashicons-info dashicons-no' );
			$( document ).one( 'click', function() {
				$toggle.removeClass( 'checked dashicons-no' ).addClass( 'dashicons-info' );
			} );
		}
	};

	this.toggleElemByClass = function( cls ) {
		toggle = $( '.toggle_'+cls );
		if ( toggle.length !== 0 ) toggle.toggleClass( 'hidden' );
	};

	this.toggleRadioByClass = function( cls, val ) {
		$( '[class*=\''+cls+'\']:not(.'+cls+'_'+val+')' ).addClass( 'hidden' );
		$( '.'+cls+'_'+val ).removeClass( 'hidden' );
	};

	/**
	 * Show an overlay for different states and actions.
	 *
	 * Works best with overlays build with the function:
	 * @see \tp\managementgeneral->render_overlay() (inc/general.php)
	 * use @filter contentsync_overlay_contents to apply contents.
	 *
	 * @param {bool} show   Whether to show or hide the overlay
	 * @param {object} atts   Whether to show or hide the overlay
	 *      @property {string} type     CSS-class of wrapper (direct child of the overlay) to be shown
	 *      @property {string} css      CSS-class of direct child of the wrapper to be shown
	 *      @property {string} replace  Content inside '.replace' will be replaced by the given string
	 *      @property {string} id       ID of the overlay (default: 'overlay').
	 */
	this.triggerOverlay = function( show, atts ) {
		show = typeof show !== 'undefined' ? show : true;
		atts = atts ? atts : {};

		var type    = typeof atts.type !== 'undefined'      ? atts.type     : 'loading';
		var css     = typeof atts.css !== 'undefined'       ? atts.css      : 'init';
		var replace = typeof atts.replace !== 'undefined'   ? atts.replace  : '';
		var id      = typeof atts.id !== 'undefined'        ? atts.id       : 'overlay';

		console.log( show, type, css, replace, id );

		clearTimeout( contentsync.tools.overlayTimeout );
		var overlay = $( '.contentsync_overlay#'+id );
		if ( overlay.length === 0 ) return false;

		if ( show === true ) {

			// show type
			var wrapper = overlay.children( '.'+type+', .always' );
			wrapper.siblings().addClass( 'hidden' );
			wrapper.each( function() {
				$( this ).removeClass( 'hidden' );
			} );

			// replace elements
			if ( replace.length > 0 ) {
				wrapper.find( '.replace' ).html( replace );
			}

			// show elements
			wrapper.find( '.depending' ).addClass( 'hidden' );
			// if multiple classes are defined, show each

			if ( css && css.length ) {
				String( css ).split( ' ' ).forEach( k => {
					wrapper.find( '.depending.'+k ).removeClass( 'hidden' );
				} );
				// show elements were both classes need to be set
				wrapper.find( '.depending.' + css.split( ' ' ).join( '-' ) ).removeClass( 'hidden' );
			}


			/**
			 * here we check if there are 2 overlays on the page (on hub page),
			 * we then loop through the wrappers and search for wrapper where only hidden containers are.
			 * Now we know that the overlay is not needed and can finally hide it
			 */
			if ( overlay.length === 2 ) {
				// console.log("found 2 overlays");
				wrapper.each( function() {
					if ( $( this ).children( '.depending' ).not( '.hidden' ).length == 0 ) {
						// console.log("found not needed overlay --> hiding it");
						$( this ).parent().addClass( 'hidden' );
					} else {
						$( this ).parent().removeClass( 'hidden' );
					}
				} );
			} else {
				// finally show overlay
				overlay.removeClass( 'hidden' );
			}


			// hide overlay...
			if ( type === 'success' ) {
				contentsync.tools.fadeOutOverlay();
			} else if ( type === 'fail' ) {
				// contentsync.tools.fadeOutOverlay(5000);
			}
			// ...or reload page
			else if ( type === 'reload' ) {
				contentsync.tools.reloadPage();
			}
		}
		else {
			$( '.contentsync_overlay' ).addClass( 'hidden' );
			clearTimeout( contentsync.tools.overlayTimeout );
		}
	};

	/**
	 * Confirm action via overlay
	 *
	 * @param string css        passed to the overlay function
	 * @param string replace    passed to the overlay function
	 * @param string callback   name of function to be called after confirmation
	 * @param array  args       arguments to be applied to the callback-function
	 */
	this.confirm = function( css, replace, callback, args, id ) {
		var overlayArgs = {
			type: 'confirm',
			css: css,
			replace: replace,
			id: id
		};
		this.triggerOverlay( true, overlayArgs );
		$( '.contentsync_overlay .button[role=\'confirm\']' ).off( 'click' ).on( 'click', function() {
			if ( typeof callback === 'function' ) {
				callback.apply( this, args );
			}

			overlayArgs.type = 'loading';
			contentsync.tools.triggerOverlay( true, overlayArgs );
		} );
	};

	/**
	 * Decide between two action via overlay
	 *
	 * @param {string} css             passed to the overlay function
	 * @param {object[]} callbacks     array of objects with callback and args
	 *  @property {function} callback  name of function to be called after confirmation
	 *  @property {array} args         arguments to be applied to the callback-function
	 */
	this.decide = function( css, callbacks, deprecated1, deprecated2, deprecated3 ) {
		this.triggerOverlay( true, { 'type': 'decision', 'css': css, 'replace': '' } );

		// console.log( typeof callbacks, callbacks, deprecated1, deprecated2, deprecated3 );

		var callback1, args1, callback2, args2;
		if ( typeof callbacks === 'object' ) {
			if ( callbacks?.callback ) {
				callbacks = [ callbacks ];
			}

			callback1 = callbacks[ 0 ]?.callback;
			args1     = callbacks[ 0 ]?.args;
			if ( callbacks[ 1 ]?.callback ) {
				callback2 = callbacks[ 1 ]?.callback;
				args2     = callbacks[ 1 ]?.args;
			}
		} else {
			callback1 = callbacks;
			callback2 = deprecated1;
			args1     = deprecated2;
			args2     = deprecated3;
		}

		$( '.contentsync_overlay .button[role=\'decision\'][decision=\'0\']' ).off( 'click' ).on( 'click', function() {
			if ( typeof callback1 === 'function' ) callback1.apply( this, args1 );
		} );
		$( '.contentsync_overlay .button[role=\'decision\'][decision=\'1\']' ).off( 'click' ).on( 'click', function() {
			if ( typeof callback2 === 'function' ) callback2.apply( this, args2 );
		} );
	};

	this.fadeOutOverlay = function( time ) {
		time = time ? time : 2400;
		clearTimeout( contentsync.tools.overlayTimeout );
		contentsync.tools.overlayTimeout = setTimeout( function() {
			contentsync.tools.triggerOverlay( false );
		}, time );
	};

	this.reloadPage = function( time ) {
		var form = $( 'form#reload_page' );
		if ( form.length === 0 ) {
			form = $( '#wpfooter' ).after( '<form method=\'post\' id=\'reload_page\'></form>' );
		}

		time = time ? time : 1500;
		clearTimeout( contentsync.tools.overlayTimeout );
		contentsync.tools.overlayTimeout = setTimeout( function() {
			$( 'form#reload_page' ).submit();
		}, time );
	};

	this.addPageTitleAction = function( label, args ) {
		if ( typeof label === 'string' ) {
			var css     = args.css ? args.css : '';
			var url     = args.url ? ' href=\''+args.url+'\'' : '';
			var id      = args.id  ? ' id=\''+args.id+'\'' : '';
			var onclick = args.onclick ? ' onclick=\''+args.onclick+'\'' : '';
			$( 'hr.wp-header-end' ).before( '<a class=\'button-ghost page-title-action '+css+'\''+id+url+onclick+'>'+label+'</a>' );
		}
	};

	this.toggleOverlay = function( id ) {
		$( '.contentsync_overlay_v2'+( id ? '#'+id : '' ) ).toggleClass( 'is-active' );
	};

	this.openOverlay = function( id ) {
		$( '.contentsync_overlay_v2'+( id ? '#'+id : '' ) ).addClass( 'is-active' );
	};

	this.closeOverlay = function( id ) {
		$( '.contentsync_overlay_v2'+( id ? '#'+id : '' ) ).removeClass( 'is-active' );
	};
};