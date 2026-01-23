var contentSync = contentSync || {};

contentSync.postSync = new function() {

	this.init = function () {

		if ( $( 'body' ).hasClass( 'toplevel_page_contentsync' ) ) {
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
					&& typeof contentSync.siteEditor.postReference !== 'undefined'
					&& contentSync.siteEditor.postReference !== null
				) {
					const actionsWithoutReload = [ 'contentsync_export', 'contentsync_unexport', 'contentsync_unimport', 'contentsync_repair' ];
					if ( actionsWithoutReload.indexOf( action ) > -1 ) {
						contentSync.siteEditor.getData( contentSync.siteEditor.postReference, true );
						mode = 'success';
					}
				}

				// use for development
				// mode = mode === 'reload' ? 'success': 'fail';

				contentSync.overlay.triggerOverlay( true, { 'type': mode, 'css': action } );
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

		contentSync.checkUnsavedChanges();
		contentSync.overlay.confirm( action, postTitle, contentSync.ajax, [ action, { 'post_id': post_id } ] );
	};

	/**
	 * Remove global connection of exported post
	 */
	this.unexportPost = function ( elem, gid ) {

		var action = 'contentsync_unexport';
		var gid    = typeof gid === 'undefined' ? $( elem ).data( 'gid' ) : gid;

		contentSync.checkUnsavedChanges();
		contentSync.overlay.confirm( action, '', contentSync.ajax, [ action, { 'gid': gid } ] );
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

		contentSync.overlay.triggerOverlay( true, { 'type': 'check_post', 'css': action } );

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

					console.log( contentSync.postExport );

					// display conflicts
					contentSync.buildConflictOptions( form, result, true );

					/**
					 * trigger overlay 'confirm'
					 * 
					 * callback: this.importPost( gid );
					 */
					contentSync.overlay.confirm( action, '', contentSync.importPost, [ gid, postType ] );
				}
				// complete with error
				else if ( response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': msg } );
				}
				// unknown state
				else {
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': response } );
				}
			}
		);

		// contentSync.overlay.confirm( action, '', contentSync.ajax, [ action, { 'gid': gid } ] );
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

		contentSync.ajax( action, data );
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
						contentSync.overlay.triggerOverlay( true, { 'type': 'imported', 'css': 'contentsync_import_bulk', 'replace': posts[ 0 ].title } );
						contentSync.overlay.fadeOutOverlay();
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

		contentSync.overlay.triggerOverlay( true, { 'type': 'check_post', 'css': action } );
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
							// contentSync.buildConflictOptions( form, [ post ], false );

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
							contentSync.buildConflictOptions( form, postsForConflict, clear );
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
						contentSync.overlay.confirm( action, '', contentSync.importBulk, [ posts, form ] );
					}

				}

				// complete with error
				if ( error || response.indexOf( 'error::' ) > -1 ) {
					var msg = response.split( 'error::' )[ 1 ];
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': msg } );
				}
				// unknown state
				else if ( response.indexOf( 'success::' ) == -1 ) {
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': action, 'replace': response } );
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
		contentSync.importQueue = [];

		// make import queue
		posts.forEach( ( item, i ) => {
			if ( item.relationship == 'import' ) {
				// skip if already imported
				contentSync.importQueue.push( {
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
				contentSync.importQueue.push( {
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
		// console.log(contentSync.importQueue);
		contentSync.importBulkNext( 0 );

	};

	this.importBulkNext = function( index ) {

		if ( contentSync.importQueue.length == index ) {
			// finish queue
			contentSync.importBulkFinish();

			return;
		}

		var item = contentSync.importQueue[ index ];
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
					contentSync.importQueue[ index ].result = item.callback( response );
					// next
					contentSync.importBulkNext( index+1 );
				}
			);
		}
		else {
			if ( item.action && item.action == 'skip' ) {
				contentSync.importQueue[ index ].result = item.callback();
			}

			// next
			contentSync.importBulkNext( index+1 );
		}

	};

	this.importBulkFinish = function() {
	
		// success or fail (some failed)
		var mode = 'success';
		contentSync.importQueue.forEach( item => {
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
		contentSync.overlay.triggerOverlay( true, { 'type': mode, 'css': 'contentsync_import_bulk' } );
		clearTimeout( contentSync.overlay.overlayTimeout );
		
	};

	/**
	 * Remove connection on imported post (make static)
	 */
	this.unimportPost = function ( elem, postId ) {

		var action = 'contentsync_unimport';
		var post_id = typeof postId === 'undefined' ? $( elem ).data( 'post_id' ) : postId;

		contentSync.overlay.confirm( action, '', contentSync.ajax, [ action, { 'post_id': post_id } ] );
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

		contentSync.checkUnsavedChanges();
		contentSync.overlay.confirm( action, replace, contentSync.ajax, [ action, data ] );
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

		contentSync.overlay.confirm( action, '', contentSync.ajax, [ action, data ] );
	};
	
	/**
	 * Trash post
	 */
	this.trashPost = function ( elem ) {

		var action = 'contentsync_trash';

		contentSync.overlay.confirm( action, '', contentSync.ajax, [ action, $( elem ).data() ] );
	};

	/**
	 * Delete synced posts
	 */
	this.deletePost = function ( elem ) {

		var action = 'contentsync_delete';
		var gid = $( elem ).data( 'gid' );

		contentSync.overlay.confirm( action, '', contentSync.ajax, [ action, { 'gid': gid } ] );
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
	 * Make export options editable for root posts
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
	 * @see contentsync/features/post-export/assets/js/post-export.js
	 * 
	 * @param {object} wrapper  HTML wrapper jQuery object
	 * @param {mixed} posts     Either an array of posts or a string.
	 */
	this.buildConflictOptions = function ( wrapper, posts, clear ) {

		// if ( typeof contentSync.postExport !== 'undefined' ) {
		// 	return contentSync.postExport.buildConflictOptions( wrapper, posts );
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
};

document.addEventListener( 'DOMContentLoaded', () => {
	contentSync.postSync.init();
} );