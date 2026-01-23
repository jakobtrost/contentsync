var contentsync = contentsync || {};
const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

contentsync.exportPostModal = new function() {

	/**
	 * Modal config
	 */
	this.config = {
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
			confirm: {
				text: __( 'Export now', 'contentsync' )
			}
		},
		onOpen: () => {
			this.setDescription();
		},
		onConfirm: () => {
			this.exportPostData();
		}
	};

	/**
	 * Modal instance
	 */
	this.modal = new contentsync.Modal( this.config );

	/**
	 * Set description
	 */
	this.setDescription = () => {
		let postTitle = document.querySelector( '.row-title' ).innerText;
		this.modal.setDescription( this.config.description.replace( '%s', postTitle ) );
	};

	/**
	 * Export post data
	 */
	this.exportPostData = () => {
		console.log( 'exportPostData' );
		this.modal.toggleConfirmButtonBusy( true );
	};
};

document.addEventListener( 'DOMContentLoaded', () => {

	// open on load
	contentsync.exportPostModal.modal.open();
} );