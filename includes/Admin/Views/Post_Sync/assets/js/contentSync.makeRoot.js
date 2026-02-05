var contentSync = contentSync || {};

contentSync.makeRoot = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'make-root-modal',
		title: __( 'Make post global', 'contentsync' ),
		description: __( 'Do you want to make the post %s globally synced?', 'contentsync' ).replace( '%s', '<u>%s</u>' ),
		formInputs: [
			{
				type: 'checkbox',
				name: 'append_nested',
				label: __( 'Make nested content global', 'contentsync' ),
				description: __( 'Templates, media, etc. are also made global so that used images, backgrounds, etc. will be displayed correctly on the destination websites.', 'contentsync' ),
				value: 1
			},
			{
				type: 'checkbox',
				name: 'resolve_menus',
				label: __( 'Resolve menus', 'contentsync' ),
				description: __( 'All menus will be converted to static links.', 'contentsync' ),
				value: 1
			}
		],
		buttons: {
			cancel: {
				text: __( 'Cancel', 'contentsync' )
			},
			submit: {
				text: __( 'Make global', 'contentsync' )
			}
		},
		onSubmit: () => this.onModalSubmit()
	} );

	/**
	 * REST handler instance
	 */
	this.RestHandler = new contentSync.RestHandler( {
		restPath: 'unsynced-posts/make_root',
		onSuccess: ( data, fullResponse ) => this.onSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onError( message, fullResponse ),
	} );

	/**
	 * Current selected post Id, defined on user click on a post 'Make global' button.
	 * 
	 * @type {number}
	 */
	this.postId = 0;

	/**
	 * Current selected post Title, defined on user click on a post 'Export' <a>-element.
	 * 
	 * @type {string}
	 */
	this.postTitle = '';

	/**
	 * Button element that triggered the modal
	 * 
	 * @type {HTMLElement}
	 */
	this.buttonElement = null;

	/**
	 * Open modal
	 * 
	 * @param {number} postId - Post ID
	 * @param {string} postTitle - Post title
	 * @param {HTMLElement} elem - Element that triggered the modal (optional)
	 */
	this.openModal = ( postId, postTitle, elem ) => {
		this.postId = parseInt( postId );
		this.postTitle = postTitle;
		this.buttonElement = elem || null;

		this.Modal.open();
		this.Modal.setDescription( this.Modal.config.description.replace( '%s', '<u>' + this.postTitle + '</u>' ) );
	};

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const fd = this.Modal.getFormData();
		const data = {
			post_id: this.postId,
			append_nested: fd.append_nested || 0,
			resolve_menus: fd.resolve_menus || 0,
			translations: 0,
		};

		console.log( 'data:', data );

		this.RestHandler.send( data );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {string} responseData - Global post ID (from response.data)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onSuccess = ( responseData, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		this.Modal.close();

		const gid = typeof responseData === 'string' ? responseData : false;
		
		if ( !gid ) {
			return this.onError( __( 'Error making post global: No global post ID found', 'contentsync' ), fullResponse );
		}

		if ( typeof contentSync.blockEditorTools !== 'undefined' ) {
			contentSync.blockEditorTools.getData( this.postId, true, ( post ) => {
				if ( post ) {
					contentSync.blockEditorTools.showSnackbar( __( 'The post was made global successfully.', 'contentsync' ), 'success' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( __( 'The post was made global successfully.', 'contentsync' ), 'success' );
		}

		if ( this.buttonElement ) {
			let buttonParent = this.buttonElement.parentElement;
			buttonParent.removeChild( this.buttonElement );
			buttonParent.appendChild( contentSync.tools.makeAdminIconStatusBox( 'root' ) );
		}
	};

	/**
	 * When the REST request is unsuccessful
	 *
	 * @param {string} message - Error message (from response.message)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onError = ( message, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );

		if ( typeof contentSync.blockEditorTools !== 'undefined' ) {
			contentSync.blockEditorTools.getData( this.postId, true, ( post ) => {
				if ( post ) {
					contentSync.blockEditorTools.showSnackbar( __( 'Error making post global: %s', 'contentsync' ).replace( '%s', message ), 'error' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( __( 'Error making post global: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		}
	};
};