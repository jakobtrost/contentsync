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
	 * @type {HTMLElement|null}
	 */
	this.buttonElement = null;

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
	 * On button click, usually triggered from the global list table
	 * 
	 * @param {HTMLElement} elem - Element that triggered the button
	 *   @property {string} dataset.post_id - Post ID
	 *   @property {string} dataset.post_title - Post title
	 *   @property {string} dataset.gid - Global post ID
	 *   @property {string} dataset.status - Synced status
	 */
	this.onButtonClick = ( elem ) => {
		this.buttonElement = elem;

		let post = {
			id: elem.dataset.post_id,
			title: elem.dataset.post_title,
			gid: elem.dataset.gid,
			status: elem.dataset.status,
		};

		this.openModal( post );
	};

	/**
	 * Open modal
	 * 
	 * @param {Object} post - Post data
	 *   @property {number} id - Post ID
	 *   @property {string} title - Post title
	 *   @property {string} gid - Global post ID
	 *   @property {string} status - Synced status
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
			contentSync.tools.addSnackBar( {
				text: __( 'The post was converted to a local post and synchronization was disabled successfully.', 'contentsync' ),
				type: 'success'
			} );

			if ( this.buttonElement ) {
				// find closest 'tr' element
				const tr = this.buttonElement.closest( 'tr' );
				if ( tr ) {
					tr.remove();
				}
			}
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