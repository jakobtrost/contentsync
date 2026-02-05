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
	 * @param {Object} data - Object of posts with conflicts (PHP Array keyed by post ID)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onCheckFileSuccess = ( data, fullResponse ) => {
		console.log( 'postImport.onCheckFileSuccess: ', data, fullResponse );

		// Convert the data to an array of posts
		let posts = [];
		if ( typeof data === 'object' && Object.keys( data ).length > 0 ) {
			posts = Object.values( data );
		} else if ( Array.isArray( data ) ) {
			posts = data;
		}

		if ( posts.length > 0 ) {
			this.Modal.setDescription( __( 'The following posts will be imported to your site. Some might have conflicts with existing posts on your site. Choose what to do with them.', 'contentsync' ) );
			this.buildConflictOptions( posts );
		} else {
			const conflictsContainer = document.getElementById( 'import-post-conflicts' );
			conflictsContainer.innerHTML = '';
			this.Modal.setDescription( __( 'No conflicts found.', 'contentsync' ) );
		}

		this.Modal.toggleSubmitButtonDisabled( false );
	};

	/**
	 * Build the conflict options.
	 * 
	 * This build form inputs that create this data structure:
	 * 
	 * conflicts: [
	 *   0 => array(
	 *     'existing_post_id' => 123,
	 *     'original_post_id' => 456,
	 *     'conflict_action' => 'keep'
	 *   ),
	 *   1 => array(
	 *     'existing_post_id' => 789,
	 *     'original_post_id' => 101,
	 *     'conflict_action' => 'replace'
	 *   )
	 * ]
	 * 
	 * This data structure is then sent to the server in the POST request.
	 * 
	 * @see \Contentsync\Api\Admin_Endpoints\Post_Import_Endpoint::import()
	 * 
	 * @param {Array} posts - Array of posts with conflicts
	 */
	this.buildConflictOptions = ( posts ) => {
		console.log( 'postImport.buildConflictOptions: ', posts );

		const conflictsContainer = document.getElementById( 'import-post-conflicts' );
		conflictsContainer.innerHTML = '';

		const innerContainer = document.createElement( 'div' );
		innerContainer.className = 'posts-conflicts-inner-container';

		if ( posts.length > 1 ) {
			let multiOptionContainer = document.createElement( 'div' );
			multiOptionContainer.className = 'post-conflict multiselect';
			multiOptionContainer.innerHTML = '<div class="post-conflict-title">' + __( 'Multiselect' ) + '</div>' +
				'<select class="post-conflict-action">' +
					'<option value="keep">' + __( 'Add as duplicate', 'contentsync' ) + '</option>' +
					'<option value="replace">' + __( 'Overwrite existing', 'contentsync' ) + '</option>' +
					'<option value="skip">' + __( 'Use existing', 'contentsync' ) + '</option>' +
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

			if ( post?.existing_post ) {
				optionContainer.innerHTML = '<div class="post-conflict-title">' + post.existing_post.post_link + '</div>' +
					'<input type="hidden" name="conflicts[' + i + '][existing_post_id]" value="' + post.existing_post.ID + '" />' +
					'<input type="hidden" name="conflicts[' + i + '][original_post_id]" value="' + post.existing_post.original_post_id + '" />' +
					'<select class="post-conflict-action" name="conflicts[' + i + '][conflict_action]">' +
						'<option value="keep">' + __( 'Add as duplicate', 'contentsync' ) + '</option>' +
						'<option value="replace">' + __( 'Overwrite existing', 'contentsync' ) + '</option>' +
						'<option value="skip">' + __( 'Use existing', 'contentsync' ) + '</option>' +
					'</select>';
			} else {
				const postLink = post?.post_link ?? ( post?.post_title + ' (' + post?.post_type + ')' );
				optionContainer.innerHTML = '<div class="post-conflict-title">' + postLink + '</div>' +
					'<div class="post-conflict-action no-conflict">' +
						'<i>' + __( 'No conflict', 'contentsync' ) + '</i>' +
					'</div>';
			}

			innerContainer.appendChild( optionContainer );
			i++;
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