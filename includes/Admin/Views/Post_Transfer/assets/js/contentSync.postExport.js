var contentSync = contentSync || {};

contentSync.postExport = new function() {

	/**
	 * i18n function
	 */
	const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

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
			text: __( 'Posts in query loops are not included in the import and must be exported separately.', 'contentsync' ),
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
	 * REST handler instance
	 */
	this.RestHandler = new contentSync.RestHandler( {
		restPath: 'post-export',
		onSuccess: ( data, fullResponse ) => this.onSuccess( data, fullResponse ),
		onError: ( message, fullResponse ) => this.onError( message, fullResponse ),
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
		this.postTitle = elem.dataset.post_title;
		this.postId = parseInt( elem.dataset.post_id );

		this.Modal.open();
		this.Modal.setDescription( this.Modal.config.description.replace( '%s', this.postTitle ) );
	};

	/**
	 * On modal submit
	 */
	this.onModalSubmit = () => {
		this.Modal.toggleSubmitButtonBusy( true );

		const fd = this.Modal.getFormData();
		const data = {
			post_id: this.postId,
			nested: fd.nested || fd.append_nested || 0,
			resolve_menus: fd.menus || 0,
			translations: 0,
		};

		this.RestHandler.send( data );
	};

	/**
	 * When the REST request is successful
	 *
	 * @param {string} responseData - Export file URL (from response.data)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onSuccess = ( responseData, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		this.Modal.close();

		const downloadUrl = typeof responseData === 'string' ? responseData : false;
		
		if ( !downloadUrl ) {
			return this.onError( __( 'Error exporting post: No download URL found', 'contentsync' ), fullResponse );
		}

		// create a link element
		const link = document.createElement( 'a' );
		link.href = downloadUrl;
		link.download = downloadUrl.split( '/' ).pop();
		link.target = '_self';
		link.rel = 'external noreferrer noopener';
		link.click();

		contentSync.tools.addSnackBar( {
			text: __( 'The post was exported successfully. The file will download automatically. If not, click the link.', 'contentsync' ),
			link: {
				text: __( 'Download file', 'contentsync' ),
				url: downloadUrl,
				target: '_self',
				rel: 'external noreferrer noopener'
			},
			type: 'success'
		} );
	};

	/**
	 * When the REST request is unsuccessful
	 *
	 * @param {string} message - Error message (from response.message)
	 * @param {Object} fullResponse - Full REST response { status, message, data }
	 */
	this.onError = ( message, fullResponse ) => {
		this.Modal.toggleSubmitButtonBusy( false );
		contentSync.tools.addSnackBar( {
			text: __( 'Error exporting post: %s', 'contentsync' ).replace( '%s', message ),
			type: 'error'
		} );
	};
};