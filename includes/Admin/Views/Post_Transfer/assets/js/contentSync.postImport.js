var contentSync = contentSync || {};

contentSync.postImport = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'import-post-modal',
		title: __( 'Post Import', 'contentsync' ),
		formInputs: [
			{
				type: 'file',
				name: 'import_file',
				label: __( 'Select file', 'contentsync' ),
				description: __( 'Select the file to import.', 'contentsync' ),
				value: ''
			},
			{
				type: 'custom',
				content: '<div id="import-post-conflicts" class="post-conflicts-container">' +
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
	 * ================================================
	 * CHECK FILE
	 * ================================================
	 */

	/**
	 * REST handler instance
	 */
	this.checkFileRestHandler = new contentSync.RestHandler( {
		restPath: 'post-import/check',
		onSuccess: ( data, fullResponse ) => this.onCheckFileSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onCheckFileError( message, fullResponse ),
	} );

	/**
	 * Open modal
	 * 
	 * @param {HTMLElement} elem - Element that triggered the modal
	 */
	this.openModal = ( elem ) => {
		this.Modal.open();
	};

	/**
	 * Add the page title action button
	 */
	this.init = () => {
		contentSync.tools.addPageTitleAction(
			'⬇ ' + __( 'Import', 'contentsync' ),
			{
				onclick: 'contentSync.postImport.startUploadFileDialog( this );'
			}
		);
	};

	/**
	 * Start the client-side upload file dialog
	 */
	this.startUploadFileDialog = () => {
		
		this.Modal.open();

		const fileInput = document.getElementById( 'import_file__input' );

		fileInput.addEventListener( 'change', () => {
			this.checkFile( fileInput );
		} );

		fileInput.click();
	};

	/**
	 * After the user selected a file, check it' contents and validate it
	 * @param {File} file - File object
	 */
	this.checkFile = ( fileInput ) => {

		const conflictsContainer = document.getElementById( 'import-post-conflicts' );
		conflictsContainer.innerHTML = '<div class="components-flex">' +
			'<span>' + __( 'Checking file...', 'contentsync' ) + '</span>' +
			'<span class="spinner is-active"></span>' +
		'</div>';

		// create FormData object
		const formData = new FormData();
		formData.append( 'file', fileInput.files[ 0 ] );
		// console.log( 'formData: ', formData );

		this.checkFileRestHandler.send( formData );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {Array} data - Array of posts with conflicts
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onCheckFileSuccess = ( data, fullResponse ) => {
		console.log( 'postImport.onCheckFileSuccess: ', data, fullResponse );

		if ( data.length > 0 ) {
			this.Modal.setDescription( __( 'Attention: Some content in the file already appears to exist on this site. Choose what to do with it.', 'contentsync' ) );
			this.buildConflictOptions( data );
		} else {
			const conflictsContainer = document.getElementById( 'import-post-conflicts' );
			conflictsContainer.innerHTML = '';
			this.Modal.setDescription( __( 'No conflicts found.', 'contentsync' ) );
		}

		this.Modal.toggleSubmitButtonDisabled( false );
	};

	/**
	 * Build the conflict options
	 * @param {Array} posts - Array of posts with conflicts
	 */
	this.buildConflictOptions = ( posts ) => {
		console.log( 'postImport.buildConflictOptions: ', posts );

		const conflictsContainer = document.getElementById( 'import-post-conflicts' );
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
	this.onCheckFileError = ( message, fullResponse ) => {
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
		restPath: 'post-import',
		onSuccess: ( data, fullResponse ) => this.onImportSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onImportError( message, fullResponse ),
	} );

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const formData = this.Modal.getFormData();

		let fileObject = formData.get( 'import_file' ); 
		if ( fileObject ) {
			let fileName = fileObject.name;
			formData.append( 'filename', fileName );
			formData.delete( 'import_file' );
		}

		this.importRestHandler.send( formData );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {string} responseData - Export file URL (from response.data)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onImportSuccess = ( responseData, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		this.Modal.close();

		console.log( 'postImport.onImportSuccess: ', responseData, fullResponse );
		contentSync.tools.addSnackBar( {
			text: fullResponse.message?.length > 0 ? fullResponse.message : __( 'Posts imported successfully', 'contentsync' ),
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
			text: __( 'Error importing posts: %s', 'contentsync' ).replace( '%s', message ),
			type: 'error'
		} );
	};
};