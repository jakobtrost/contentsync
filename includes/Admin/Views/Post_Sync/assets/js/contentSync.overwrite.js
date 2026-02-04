var contentSync = contentSync || {};

contentSync.overwrite = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'overwrite-modal',
		title: __( 'Overwrite post', 'contentsync' ),
		description: __( 'Do you want to overwrite the current post with the global post %s?', 'contentsync' ).replace( '%s', '<u>%s</u>' ),
		buttons: {
			cancel: { text: __( 'Cancel', 'contentsync' ) },
			submit: { text: __( 'Overwrite', 'contentsync' ) },
		},
		onSubmit: () => this.onModalSubmit(),
	} );

	/**
	 * REST handler instance
	 */
	this.RestHandler = new contentSync.RestHandler( {
		restPath: 'unsynced-posts/overwrite',
		onSuccess: ( data, fullResponse ) => this.onSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onError( message, fullResponse ),
	} );

	/**
	 * Current selected post Id, defined on user click on a post 'Overwrite' button.
	 * 
	 * @type {number}
	 */
	this.postId = 0;

	/**
	 * Current selected global post Id, defined on user click on a post 'Overwrite' button.
	 * 
	 * @type {string}
	 */
	this.gid = '';

	/**
	 * Open modal
	 * 
	 * @param {string} gid - Global post ID
	 * @param {Object} post - Post object
	 */
	this.openModal = ( gid, post ) => {
		this.postId = parseInt( post.ID ?? post.id );
		this.gid = gid;
		this.Modal.open();
		this.Modal.setDescription( this.Modal.config.description.replace(
			'%s',
			'<a href="' + post.post_links?.edit + '" target="_blank" rel="noopener noreferrer">' + post.post_title + '</a>'
		) );
	};

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const data = {
			post_id: this.postId,
			gid: this.gid,
		};

		console.log( 'data:', data );

		this.RestHandler.send( data );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {boolean} responseData - True if the post was overwritten successfully (from response.data)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onSuccess = ( responseData, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		this.Modal.close();

		if ( !responseData ) {
			return this.onError( __( 'Error overwriting post: No response data', 'contentsync' ), fullResponse );
		}

		if ( typeof contentSync.blockEditorTools !== 'undefined' ) {
			contentSync.blockEditorTools.getData( this.postId, true, ( post ) => {
				if ( post ) {
					contentSync.blockEditorTools.showSnackbar( __( 'The post was overwritten successfully.', 'contentsync' ), 'success' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( __( 'The post was overwritten successfully.', 'contentsync' ), 'success' );
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
					contentSync.blockEditorTools.showSnackbar( __( 'Error overwriting post: %s', 'contentsync' ).replace( '%s', message ), 'error' );
				}
			} );
		} else {
			contentSync.tools.addSnackBar( __( 'Error overwriting post: %s', 'contentsync' ).replace( '%s', message ), 'error' );
		}
	};
};