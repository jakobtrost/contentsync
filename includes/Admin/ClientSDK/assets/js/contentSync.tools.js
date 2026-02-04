var contentSync = contentSync || {};
const __ = typeof wp?.i18n?.__ === 'function' ? wp.i18n.__ : ( text ) => text;

contentSync.tools = new function() {

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
		
		const hr = document.querySelector( 'hr.wp-header-end' );
		if ( ! hr ) { return; }

		const button = document.createElement( 'button' );
		button.className = 'button-ghost page-title-action';
		if ( args.className ) {
			button.className += ' ' + args.className;
		}

		if ( args.id ) {
			button.id = args.id;
		}

		if ( args.onclick ) {
			button.onclick = args.onclick;
		}

		button.textContent = label;
		hr.insertAdjacentElement( 'beforebegin', button );
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
		let list = document.querySelector( '.components-snackbar-list' );
		if ( ! list ) {
			list = document.createElement( 'div' );
			list.className = 'components-snackbar-list components-editor-notices__snackbar contentsync-snackbar-list';
			list.setAttribute( 'tabindex', '-1' );
			document.body.appendChild( list );
		}

		// creating a new SnackBar instance adds the HTML to the DOM
		new contentSync.SnackBar( options );
	};

	/**
	 * Make the contentsync status box (JS port of Admin_Render::make_admin_icon_status_box).
	 * Returns a DOM element that can be appended (e.g. someWrapper.appendChild(statusBox)).
	 *
	 * @param {string} status   - One of: root, linked, unlinked, error, info, export, success, import, started, failed, init, purple, green, blue, red, yellow, warning
	 * @param {string} text     - Optional label text; if empty, a default per status is used when available
	 * @param {boolean} showIcon - Whether to show the status icon (default true)
	 * @return {HTMLElement}    - A <span> element with class contentsync-info-box and contentsync-status
	 */
	this.makeAdminIconStatusBox = function( status, text, showIcon ) {

		status    = status || 'root';
		text      = ( text !== undefined && text !== null ) ? String( text ) : '';
		showIcon  = showIcon !== false;

		const titleMap = {
			root:   __( 'Global synced post', 'contentsync' ),
			linked: __( 'Global linked post', 'contentsync' ),
			error:  __( 'Error', 'contentsync' ),
			info:   __( 'Info', 'contentsync' )
		};
		const textMap = {
			failed:  __( 'Failed', 'contentsync' ),
			success: __( 'Completed', 'contentsync' ),
			started: __( 'Started', 'contentsync' ),
			init:    __( 'Scheduled', 'contentsync' )
		};
		const colorMap = {
			root:    'purple', export: 'purple', purple: 'purple',
			success: 'green',  import: 'green',  green: 'green',
			info:    'blue',   started: 'blue',  blue: 'blue',
			error:   'red',    failed: 'red',    red: 'red',
			warning: 'yellow', yellow: 'yellow'
		};
		// Only these icons exist under assets/icon/
		const iconMap = {
			root: 'root', linked: 'linked', unlinked: 'unlinked',
			error: 'error', failed: 'error', red: 'error',
			info: 'info', started: 'info', blue: 'info', success: 'info', import: 'info', init: 'info',
			export: 'root', purple: 'root', green: 'info', warning: 'info', yellow: 'info'
		};

		const title = titleMap[ status ] || null;
		const color = colorMap[ status ] !== undefined ? colorMap[ status ] : '';
		if ( text === '' ) {
			text = textMap[ status ] || '';
		}

		const iconSlug = iconMap[ status ] !== undefined ? iconMap[ status ] : 'info';
		const iconUrl  = contentSyncToolsData ? ( contentSyncToolsData?.iconsPath + 'icon-' + iconSlug + '.svg' ) : '';

		const span = document.createElement( 'span' );
		span.className = 'contentsync-info-box contentsync-status' + ( color ? ' ' + color : '' );
		if ( title ) {
			span.setAttribute( 'data-title', title.replace( /\s/g, '\u00A0' ) );
		}

		if ( showIcon && iconUrl ) {
			const img = document.createElement( 'img' );
			img.src = iconUrl;
			img.setAttribute( 'style', 'width:auto;height:16px;' );
			span.appendChild( img );
		}

		if ( text ) {
			const textNode = document.createElement( 'span' );
			textNode.textContent = text;
			span.appendChild( textNode );
		}

		return span;
	};
};