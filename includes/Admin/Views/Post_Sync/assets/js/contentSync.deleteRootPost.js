var contentSync = contentSync || {};

contentSync.deleteRootPost = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'delete-root-post-modal',
		title: __( 'Delete synced post', 'contentsync' ),
		description: __( 'Do you want to delete the synced post %s?', 'contentsync' ).replace( '%s', '<u>%s</u>' ),
		notice: {
			text: __( 'The synced post is permanently deleted on all sites. This cannot be undone. If you want to make the posts static instead, select "Unlink".', 'contentsync' ),
			type: 'info',
		},
		buttons: {
			cancel: {
				text: __( 'Cancel', 'contentsync' )
			},
			submit: {
				text: __( 'Delete everywhere', 'contentsync' ),
				className: 'is-primary is-destructive',
			}
		},
		onSubmit: () => this.onModalSubmit()
	} );

	/**
	 * REST handler instance
	 */
	this.RestHandler = new contentSync.RestHandler( {
		restPath: 'root-posts/delete',
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
			gid: this.post.gid
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
			return this.onError( __( 'Error deleting synced post: No global post ID found', 'contentsync' ), fullResponse );
		}

		if ( typeof contentSync.blockEditorTools !== 'undefined' ) {
			contentSync.blockEditorTools.getData( this.post.id, true, ( post ) => {
				if ( post ) {
					contentSync.blockEditorTools.showSnackbar( __( 'The synced post was permanently deleted on all sites successfully.', 'contentsync' ), 'success' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( {
				text: __( 'The synced post was permanently deleted on all sites successfully.', 'contentsync' ),
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
			contentSync.blockEditorTools.showSnackbar( __( 'Error deleting synced post: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		} else {
			contentSync.tools.addSnackBar( __( 'Error deleting synced post: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		}
	};
};