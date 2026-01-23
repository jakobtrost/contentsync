var contentSync = contentSync || {};
const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

contentSync.postExport = new function() {

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'export-post-modal',
		title: __( 'Post Export', 'contentsync' ),
		description: __( 'Do you want to export "%s"?', 'contentsync' ),
		formInputs: [
			{
				type: 'checkbox',
				name: 'nested',
				label: __( 'Export nested content', 'contentsync' ),
				description: __( 'Templates, media, etc. are added to the download so that used images, backgrounds, etc. will be displayed correctly on the target website.', 'contentsync' ),
				value: 1
			},
			{
				type: 'checkbox',
				name: 'menus',
				label: __( 'Resolve menus', 'contentsync' ),
				description: __( 'All menus will be converted to static links.', 'contentsync' ),
				value: 1
			}
		],
		notice: {
			text: __( 'Posts in query loops are not included in the import. Posts and Post Types must be exported separately.', 'contentsync' ),
			type: 'info',
		},
		buttons: {
			cancel: {
				text: __( 'Cancel', 'contentsync' )
			},
			submit: {
				text: __( 'Export now', 'contentsync' )
			}
		},
		onConfirm: () => {
			this.exportPostData();
		}
	} );

	/**
	 * AJAX handler instance
	 */
	this.AjaxHandler = new contentSync.AjaxHandler( {
		action: 'post_export',
		onSuccess: this.onSuccess,
		onError: this.onError,
	} );

	/**
	 * Current selected post Id, defined on user click on a post 'Export' <a>-element.
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
	 * Open modal
	 * 
	 * @param {HTMLElement} elem - Element that triggered the modal
	 */
	this.openModal = ( elem ) => {
		this.postTitle = toString( elem.dataset.postTitle );
		this.postId = parseInt( elem.dataset.postId );
		this.Modal.setDescription( this.Modal.config.description.replace( '%s', this.postTitle ) );
		this.Modal.open();
	};

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleConfirmButtonBusy( true );

		const data = {
			post_id: this.postId,
			post_title: this.postTitle,
			form_data: this.Modal.getFormData()
		};
		console.log( 'onModalSubmit', data );

		this.AjaxHandler.send( data );
	};

	/**
	 * When the AJAX request is successful
	 * 
	 * @param {string} message - Success message
	 * @param {mixed} response - Response from server
	 */
	this.onSuccess = ( message, response ) => {
		this.Modal.toggleConfirmButtonBusy( false );
		console.log( 'onSuccess', message, response );

		this.Modal.close();

		// @todo: make snackbar work
	};

	/**
	 * When the AJAX request is unsuccessful
	 * 
	 * @param {string} message - Error message
	 * @param {mixed} response - Response from server
	 */
	this.onError = ( message, response ) => {
		console.log( 'onError', message, response );
		this.Modal.toggleConfirmButtonBusy( false );

		// @todo: show snackbar "Error exporting post: %s"
	};
};