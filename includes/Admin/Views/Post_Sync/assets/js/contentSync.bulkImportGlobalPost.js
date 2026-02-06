var contentSync = contentSync || {};

contentSync.bulkImportGlobalPost = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'bulk-import-global-post-modal',
		title: __( 'Import Global Posts', 'contentsync' ),
		formInputs: [
			{
				type: 'custom',
				content: '<div id="bulk-import-global-post-conflicts" class="post-conflicts-container">' +
					'<div class="posts-conflicts-inner-container"></div>' +
				'</div>'
			}
		],
		buttons: {
			cancel: {
				text: __( 'Cancel', 'contentsync' )
			},
			submit: {
				text: __( 'Import now', 'contentsync' ),
				attributes: {
					disabled: true
				}
			}
		},
		onSubmit: () => this.onModalSubmit()
	} );

	/**
	 * Array of posts
	 * @type {array}
	 *   @property {string} gid - Global post ID
	 *   @property {string} post_title - Post title
	 *   @property {string} post_type - Post type
	 *   @property {string} relationship - Relationship
	 */
	this.posts = [];

	/**
	 * ================================================
	 * CHECK IMPORT
	 * ================================================
	 */

	/**
	 * REST handler instance
	 */
	this.checkImportRestHandler = new contentSync.RestHandler( {
		restPath: 'linked-posts/check-import-bulk',
		onSuccess: ( data, fullResponse ) => this.onCheckImportSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onCheckImportError( message, fullResponse ),
	} );

	/**
	 * Initialize the submit listener for the bulk import form
	 */
	this.initSubmitListener = () => {

		const filterForm = document.getElementById( 'posts-filter' );

		if ( ! filterForm ) {
			// escape
			return;
		}

		console.log( 'bulkImportGlobalPost.initSubmitListener' );

		// hook bulk submit
		filterForm.addEventListener( 'submit', ( e ) => {
			console.log( 'bulkImportGlobalPost.initSubmitListener', e );

			const data = [ ...new FormData( e.target ).entries() ];
			console.log( 'data', data );
			
			if (
				Object.fromEntries( data ).action == 'import' ||
				Object.fromEntries( data ).action2 == 'import'
			) {
				e.preventDefault();

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
						} );
					}
				} );
				console.log( posts );
				if ( posts.length == 0 ) {
					console.info( 'no posts selected' );
				}
				else if ( posts.length == 1 ) {
					if ( posts[ 0 ].relationship !== 'linked' ) {
						console.log( 'import single post:', posts[ 0 ] );
						contentSync.importGlobalPost.openModal( posts[ 0 ].gid );
					}
					else {
						console.info( 'selected post is already imported:', posts[ 0 ] );
						contentSync.tools.addSnackBar( {
							text: __( 'Selected post "%s" is already imported', 'contentsync' ).replace( '%s', posts[ 0 ].post_title ),
							type: 'info'
						} );
					}
				}
				else {
					console.log( 'import posts:', posts );
					this.openModal( posts );
				}
			}
		} );
	};

	/**
	 * Open modal
	 * 
	 * @param {array} posts - Array of posts
	 *   @property {string} gid - Global post ID
	 *   @property {string} post_title - Post title
	 *   @property {string} post_type - Post type
	 *   @property {string} relationship - Relationship
	 */
	this.openModal = ( posts ) => {
		this.posts = posts;
		this.Modal.open();
		this.checkImport();
	};

	/**
	 * Check if the global post can be imported
	 */
	this.checkImport = () => {

		const conflictsContainer = document.getElementById( 'bulk-import-global-post-conflicts' );
		conflictsContainer.innerHTML = '<div class="components-flex">' +
			'<span>' + __( 'Checking import...', 'contentsync' ) + '</span>' +
			'<span class="spinner is-active"></span>' +
		'</div>';

		this.checkImportRestHandler.send( { posts: this.posts } );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {Array} data - Array of posts with conflicts
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onCheckImportSuccess = ( data, fullResponse ) => {
		console.log( 'importGlobalPost.onCheckImportSuccess: ', data, fullResponse );

		// Convert the data to an array of posts
		let posts = [];
		if ( typeof data === 'object' && Object.keys( data ).length > 0 ) {
			posts = Object.values( data );
		} else if ( Array.isArray( data ) ) {
			posts = data;
		}

		if ( posts.length > 0 ) {
			this.Modal.setDescription( fullResponse.message );
			this.buildConflictOptions( posts );
		} else {
			const conflictsContainer = document.getElementById( 'bulk-import-global-post-conflicts' );
			conflictsContainer.innerHTML = '';
			this.Modal.setDescription( fullResponse.message );
		}

		this.Modal.toggleSubmitButtonDisabled( false );
	};

	/**
	 * Build the conflict options
	 *
	 * @param {Prepared_Post[]} posts            The prepared posts with conflicts
	 *   @property {WP_Post} existing_post       The conflicting post, with some additional properties:
	 *      @property {int} original_post_id     The original post ID
	 *      @property {string} post_link         The link to the conflicting post
	 *      @property {string} conflict_action   Optional: Predefined conflict action (skip|replace|keep)
	 *                                           If a post is already synced to this site, the conflict action will be set to 'skip'.
	 *                                           @see \Contentsync\Post_Sync\Synced_Post_Hooks::adjust_conflict_action_on_import_check()
	 *      @property {string} conflict_message  Optional: Predefined conflict message
	 *                                           If a post is already synced to this site, the conflict message will be set to 'Already synced.'.
	 *                                           @see \Contentsync\Post_Sync\Synced_Post_Hooks::adjust_conflict_action_on_import_check()
	 */
	this.buildConflictOptions = ( posts ) => {
		console.log( 'importGlobalPost.buildConflictOptions: ', posts );

		const conflictsContainer = document.getElementById( 'bulk-import-global-post-conflicts' );
		conflictsContainer.innerHTML = '';

		const innerContainer = document.createElement( 'div' );
		innerContainer.className = 'posts-conflicts-inner-container';

		let hasConflict = false;
		let multiOptionContainer;

		if ( posts.length > 1 ) {
			multiOptionContainer = document.createElement( 'div' );
			multiOptionContainer.className = 'post-conflict multiselect';
			multiOptionContainer.innerHTML = '<div class="post-conflict-title">' + __( 'Multiselect' ) + '</div>' +
				'<select class="post-conflict-action">' +
					'<option value="keep">' + __( 'Add as duplicate', 'contentsync' ) + '</option>' +
					'<option value="replace">' + __( 'Overwrite existing', 'contentsync' ) + '</option>' +
				'</select>';

			innerContainer.appendChild( multiOptionContainer );

			// on change, change the value of all the other select elements
			multiOptionContainer.addEventListener( 'change', ( e ) => {
				const value = e.target.value;
				const selectElements = innerContainer.querySelectorAll( 'select.post-conflict-action[name^="conflicts' );
				selectElements.forEach( ( select ) => {
					select.value = value;
				} );
			} );
		}

		let i = 0;
		posts.forEach( ( post ) => {
			
			let optionContainer = document.createElement( 'div' );
			optionContainer.className = 'post-conflict';

			// there is an existing post, the conflict action is already set
			if ( post?.existing_post?.conflict_action ) {
				optionContainer.innerHTML = '<div class="post-conflict-title">' + post.existing_post.post_link + '</div>' +
				'<input type="hidden" name="conflicts[' + i + '][existing_post_id]" value="' + post.existing_post.ID + '" />' +
				'<input type="hidden" name="conflicts[' + i + '][original_post_id]" value="' + post.existing_post.original_post_id + '" />' +
				'<input type="hidden" name="conflicts[' + i + '][conflict_action]" value="' + post.existing_post?.conflict_action + '" />' +
					'<div class="post-conflict-action no-conflict">' +
						'<i>' + ( post.existing_post?.conflict_message ?? __( 'No conflict', 'contentsync' ) ) + '</i>' +
					'</div>';
				i++;
			}
			// there is an existing post, the conflict action needs to be set by the user
			else if ( post?.existing_post ) {
				hasConflict = true;
				optionContainer.innerHTML = '<div class="post-conflict-title">' + post.existing_post.post_link + '</div>' +
					'<input type="hidden" name="conflicts[' + i + '][existing_post_id]" value="' + post.existing_post.ID + '" />' +
					'<input type="hidden" name="conflicts[' + i + '][original_post_id]" value="' + post.existing_post.original_post_id + '" />' +
					'<select class="post-conflict-action" name="conflicts[' + i + '][conflict_action]">' +
						'<option value="keep">' + __( 'Add as duplicate', 'contentsync' ) + '</option>' +
						'<option value="replace">' + __( 'Overwrite existing', 'contentsync' ) + '</option>' +
					'</select>';
				i++;
			}
			// there is no existing post, no conflict action needed
			else {
				const postLink = post?.post_link ?? ( post?.post_title + ' (' + post?.post_type + ')' );
				optionContainer.innerHTML = '<div class="post-conflict-title">' + postLink + '</div>' +
					'<div class="post-conflict-action no-conflict">' +
						'<i>' + ( post.existing_post?.conflict_message ?? __( 'No conflict', 'contentsync' ) ) + '</i>' +
					'</div>';
			}

			innerContainer.appendChild( optionContainer );
		} );

		if ( ! hasConflict && multiOptionContainer ) {
			multiOptionContainer.remove();
		}

		conflictsContainer.appendChild( innerContainer );
	};

	/**
	 * When the REST request is unsuccessful
	 *
	 * @param {string} message - Error message (from response.message)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onCheckImportError = ( message, fullResponse ) => {
		contentSync.tools.addSnackBar( {
			text: __( 'Error checking file: %s', 'contentsync' ).replace( '%s', message ),
			type: 'error'
		} );
	};

	/**
	 * ================================================
	 * IMPORT
	 * ================================================
	 */

	/**
	 * REST handler instance
	 */
	this.importRestHandler = new contentSync.RestHandler( {
		restPath: 'linked-posts/import-bulk',
		onSuccess: ( data, fullResponse ) => this.onImportSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onImportError( message, fullResponse ),
	} );

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const formData = this.Modal.getFormData();
		// loop through the posts and add the gids to the form data
		this.posts.forEach( post => {
			formData.append( 'gids[]', post.gid );
		} );

		this.importRestHandler.send( formData );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {boolean} responseData - True if the global post was imported successfully (from response.data)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onImportSuccess = ( responseData, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		this.Modal.close();

		console.log( 'importGlobalPost.onImportSuccess: ', responseData, fullResponse );
		contentSync.tools.addSnackBar( {
			text: fullResponse.message?.length > 0 ? fullResponse.message : __( 'Global post imported successfully', 'contentsync' ),
			type: 'success',
			// add a refresh window link
			link: {
				text: __( 'Refresh window', 'contentsync' ),
				url: window.location.href,
				target: '_self'
			}
		} );
	};

	/**
	 * When the REST request is unsuccessful
	 *
	 * @param {string} message - Error message (from response.message)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onImportError = ( message, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );

		contentSync.tools.addSnackBar( {
			text: __( 'Error importing global post: %s', 'contentsync' ).replace( '%s', message ),
			type: 'error'
		} );
	};
};