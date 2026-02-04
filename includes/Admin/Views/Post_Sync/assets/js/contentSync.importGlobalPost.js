var contentSync = contentSync || {};

contentSync.importGlobalPost = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'import-global-post-modal',
		title: __( 'Import Global Post', 'contentsync' ),
		formInputs: [
			{
				type: 'custom',
				content: '<div id="import-global-post-conflicts" class="post-conflicts-container">' +
					'<div class="posts-conflicts-inner-container">' +
						'<span>' + __( 'Select a file to import', 'contentsync' ) + '</span>' +
					'</div>' +
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
	 * Button element that triggered the modal
	 * @type {HTMLElement}
	 */
	this.buttonElement = null;

	/**
	 * Global post ID
	 * @type {string}
	 */
	this.gid = '';

	/**
	 * ================================================
	 * CHECK IMPORT
	 * ================================================
	 */

	/**
	 * REST handler instance
	 */
	this.checkImportRestHandler = new contentSync.RestHandler( {
		restPath: 'linked-posts/check-import',
		onSuccess: ( data, fullResponse ) => this.onCheckImportSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onCheckImportError( message, fullResponse ),
	} );

	/**
	 * Open modal
	 * 
	 * @param {HTMLElement} elem - Element that triggered the modal
	 */
	this.openModal = ( elem ) => {
		this.buttonElement = elem;
		this.gid = elem.dataset.gid;
		this.Modal.open();
		this.checkImport();
	};

	/**
	 * Check if the global post can be imported
	 */
	this.checkImport = () => {

		const conflictsContainer = document.getElementById( 'import-global-post-conflicts' );
		conflictsContainer.innerHTML = '<div class="components-flex">' +
			'<span>' + __( 'Checking import...', 'contentsync' ) + '</span>' +
			'<span class="spinner is-active"></span>' +
		'</div>';

		this.checkImportRestHandler.send( { gid: this.gid } );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {Array} data - Array of posts with conflicts
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onCheckImportSuccess = ( data, fullResponse ) => {
		console.log( 'importGlobalPost.onCheckImportSuccess: ', data, fullResponse );

		if ( data.length > 0 ) {
			this.Modal.setDescription( fullResponse.message );
			this.buildConflictOptions( data );
		} else {
			const conflictsContainer = document.getElementById( 'import-global-post-conflicts' );
			conflictsContainer.innerHTML = '';
			this.Modal.setDescription( fullResponse.message );
		}

		this.Modal.toggleSubmitButtonDisabled( false );
	};

	/**
	 * Build the conflict options
	 * @param {Array} posts - Array of posts with conflicts
	 */
	this.buildConflictOptions = ( posts ) => {
		console.log( 'importGlobalPost.buildConflictOptions: ', posts );

		const conflictsContainer = document.getElementById( 'import-global-post-conflicts' );
		conflictsContainer.innerHTML = '';

		const innerContainer = document.createElement( 'div' );
		innerContainer.className = 'posts-conflicts-inner-container';

		posts.forEach( ( post ) => {
			conflictPost = document.createElement( 'div' );
			conflictPost.className = 'post-conflict';
			conflictPost.innerHTML = '<span>' + post.post_link + '</span>' +
				'<select name="conflicts[' + post.ID + ']">' +
					'<option value="keep">' + __( 'Add as duplicate', 'contentsync' ) + '</option>' +
					'<option value="replace">' + __( 'Overwrite existing', 'contentsync' ) + '</option>' +
					'<option value="skip">' + __( 'Use existing', 'contentsync' ) + '</option>' +
				'</select>';
			innerContainer.appendChild( conflictPost );
		} );

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
		restPath: 'linked-posts/import',
		onSuccess: ( data, fullResponse ) => this.onImportSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onImportError( message, fullResponse ),
	} );

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const conflicts = this.Modal.getFormData();
		console.log( 'importGlobalPost.onModalSubmit: ', conflicts );

		this.importRestHandler.send( {
			gid: this.gid,
			conflicts: conflicts
		} );
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