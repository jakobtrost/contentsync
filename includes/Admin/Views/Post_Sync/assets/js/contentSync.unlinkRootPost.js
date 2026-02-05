var contentSync = contentSync || {};

contentSync.unlinkRootPost = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'unlink-root-post-modal',
		title: __( 'Disable sync', 'contentsync' ),
		description: __( 'Do you want to disable global synchronization for the post %s?', 'contentsync' ).replace( '%s', '<u>%s</u>' ),
		formInputs: [
			{
				type: 'checkbox',
				name: 'unlink_connected_posts',
				label: __( 'Unlink connected posts', 'contentsync' ),
				description: __( 'All posts that are connected to this post will be converted to local posts.', 'contentsync' ),
				value: 1
			}
		],
		buttons: {
			cancel: {
				text: __( 'Cancel', 'contentsync' )
			},
			submit: {
				text: __( 'Disable sync', 'contentsync' ),
				className: 'is-primary is-destructive'
			}
		},
		onSubmit: () => this.onModalSubmit()
	} );

	/**
	 * REST handler instance
	 */
	this.RestHandler = new contentSync.RestHandler( {
		restPath: 'root-posts/unlink',
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
			gid: this.post.gid
		};

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
		
		if ( ! responseData ) {
			return this.onError( __( 'Error disabling global synchronization: No global post ID found', 'contentsync' ), fullResponse );
		}

		if ( typeof contentSync.blockEditorTools !== 'undefined' ) {
			contentSync.blockEditorTools.getData( this.post.id, true, ( post ) => {
				if ( post ) {
					contentSync.blockEditorTools.showSnackbar( __( 'The global synchronization for the post was disabled successfully.', 'contentsync' ), 'success' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( __( 'The global synchronization for the post was disabled successfully.', 'contentsync' ), 'success' );
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
			contentSync.blockEditorTools.showSnackbar( __( 'Error disabling global synchronization: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		} else {
			contentSync.tools.addSnackBar( __( 'Error disabling global synchronization: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		}
	};
};