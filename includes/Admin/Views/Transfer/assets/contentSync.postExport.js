var contentSync = contentSync || {};
const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

contentSync.postExport = new function() {

	/**
	 * Modal instance
	 */
	this.Modal = new contentSync.Modal( {
		id: 'export-post-modal',
		title: __( 'Post Export', 'contentsync' ),
		description: __( 'Do you want to export the post %s?', 'contentsync' ).replace( '%s', '<u>%s</u>' ),
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
		onSubmit: () => this.onModalSubmit()
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
		console.log( 'openModal', elem.dataset );
		this.postTitle = elem.dataset.post_title;
		this.postId = parseInt( elem.dataset.post_id );

		console.log( 'postTitle', this.postTitle );
		console.log( 'postId', this.postId );
		console.log( 'Modal', this.Modal );

		this.Modal.open();
		this.Modal.setDescription( this.Modal.config.description.replace( '%s', this.postTitle ) );
	};

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

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
		this.Modal.toggleSubmitButtonBusy( false );
		console.log( 'onSuccess', message, response );

		this.Modal.close();

		contentSync.tools.addSnackBar( {
			text: __( 'Post exported successfully, The file will be downloaded automatically, if not, click the link', 'contentsync' ),
			link: {
				text: __( 'Download file', 'contentsync' ),
				url: response.download_link,
				target: '_self',
				rel: 'external noreferrer noopener'
			},
			type: 'success',
			timeout: 10000,
		} );
	};

	/**
	 * When the AJAX request is unsuccessful
	 * 
	 * @param {string} message - Error message
	 * @param {mixed} response - Response from server
	 */
	this.onError = ( message, response ) => {
		console.log( 'onError', message, response );
		this.Modal.toggleSubmitButtonBusy( false );

		contentSync.tools.addSnackBar( {
			text: __( 'Error exporting post: %s', 'contentsync' ).replace( '%s', message ),
			type: 'error'
		} );
	};
};