var contentSync = contentSync || {};

contentSync.unlinkLinkedPost = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'unlink-linked-post-modal',
		title: __( 'Convert to local post', 'contentsync' ),
		description: __( 'Do you want to convert the post %s to a local post and thereafter disable synchronization?', 'contentsync' ).replace( '%s', '<u>%s</u>' ),
		buttons: {
			cancel: {
				text: __( 'Cancel', 'contentsync' )
			},
			submit: {
				text: __( 'Unlink post', 'contentsync' )
			}
		},
		onSubmit: () => this.onModalSubmit()
	} );

	/**
	 * REST handler instance
	 */
	this.RestHandler = new contentSync.RestHandler( {
		restPath: 'linked-posts/unlink',
		onSuccess: ( data, fullResponse ) => this.onSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onError( message, fullResponse ),
	} );

	/**
	 * Current selected post
	 * 
	 * @type {Object}
	 */
	this.post = {
		id: -1,
		title: '',
		gid: '',
		status: '',
	};

	/**
	 * Open modal
	 * 
	 * @param {HTMLElement} elem - Element that triggered the modal
	 */
	this.openModal = ( post ) => {
		this.post = post;
		this.Modal.open();
		this.Modal.setDescription( this.Modal.config.description.replace( '%s', '<u>' + post.title + '</u>' ) );
	};

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const data = {
			post_id: this.post.id
		};

		this.RestHandler.send( data );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {string} responseData - Local post ID (from response.data)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onSuccess = ( responseData, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		this.Modal.close();
		
		if ( ! responseData ) {
			return this.onError( __( 'Error converting to local post: No local post ID found', 'contentsync' ), fullResponse );
		}

		if ( typeof contentSync.blockEditorTools !== 'undefined' ) {
			contentSync.blockEditorTools.getData( this.post.id, true, ( post ) => {
				if ( post ) {
					contentSync.blockEditorTools.showSnackbar( __( 'The post was converted to a local post and synchronization was disabled successfully.', 'contentsync' ), 'success' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( __( 'The post was converted to a local post and synchronization was disabled successfully.', 'contentsync' ), 'success' );
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
			contentSync.blockEditorTools.showSnackbar( __( 'Error converting to local post: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		} else {
			contentSync.tools.addSnackBar( __( 'Error converting to local post: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		}
	};
};