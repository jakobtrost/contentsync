var contentSync = contentSync || {};

contentSync.utils = new function() {

	/**
	 * Add a page title action
	 *
	 * @param {string} label - The label of the action
	 * @param {Object} args - The arguments of the action
	 * @param {string} args.className - The class name of the action
	 * @param {string} args.url - The URL of the action
	 * @param {string} args.id - The ID of the action
	 * @param {string} args.onclick - The onclick of the action
	 */
	this.addPageTitleAction = function( label, args ) {
		if ( typeof label === 'string' ) {
			let className = args.className ? args.className : '';
			let url       = args.url ? ` href='${args.url}'` : '';
			let id        = args.id  ? ` id='${args.id}'` : '';
			let onclick   = args.onclick ? ` onclick='${args.onclick}'` : '';
			$( 'hr.wp-header-end' ).before( `<a class='button-ghost page-title-action ${className}'${id}${url}${onclick}>${label}</a>` );
		}
	};

	/**
	 * Check if current editor context has unsaved changes.
	 *
	 * @return {boolean} - True if there are unsaved changes, false otherwise
	 */
	this.hasUnsavedChanges = function () {

		let hasUnsavedChanges = false;

		if ( typeof wp !== 'undefined' && typeof wp.data !== 'undefined' && typeof wp.data.select !== 'undefined' ) {
			let selectBlockEditor = wp.data.select( 'core/editor' );
			hasUnsavedChanges = ( selectBlockEditor && selectBlockEditor.hasChangedContent() ) ? true : false;
		}

		return hasUnsavedChanges;
	};

	/**
	 * Create a new SnackBar instance with the given options.
	 *
	 * @param {Object} options - Passed to contentSync.SnackBar: text|text, link, type, timeout
	 */
	this.addSnackBar = function( options ) {

		console.log( 'addSnackBar', options );

		// Ensure .components-snackbar-list exists (create and append to document.body if not)
		var list = document.querySelector( '.components-snackbar-list' );
		if ( ! list ) {
			list = document.createElement( 'div' );
			list.className = 'components-snackbar-list components-editor-notices__snackbar contentsync-snackbar-list';
			list.setAttribute( 'tabindex', '-1' );
			document.body.appendChild( list );
		}

		// creating a new SnackBar instance adds the HTML to the DOM
		new contentSync.SnackBar( options );
	};
};