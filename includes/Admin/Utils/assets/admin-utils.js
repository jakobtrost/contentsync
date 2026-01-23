var contentSync = contentSync || {};

contentSync.utils = new function() {

	this.addPageTitleAction = function( label, args ) {
		if ( typeof label === 'string' ) {
			let className = args.className ? args.className : '';
			let url       = args.url ? ` href='${args.url}'` : '';
			let id        = args.id  ? ` id='${args.id}'` : '';
			let onclick   = args.onclick ? ` onclick='${args.onclick}'` : '';
			$( 'hr.wp-header-end' ).before( `<a class='button-ghost page-title-action ${className}'${id}${url}${onclick}>${label}</a>` );
		}
	};

	this.hasUnsavedChanges = function () {

		let hasUnsavedChanges = false;

		if ( typeof wp !== 'undefined' && typeof wp.data !== 'undefined' && typeof wp.data.select !== 'undefined' ) {
			let selectBlockEditor = wp.data.select( 'core/editor' );
			hasUnsavedChanges = ( selectBlockEditor && selectBlockEditor.hasChangedContent() ) ? true : false;
		}

		return hasUnsavedChanges;
	};
};