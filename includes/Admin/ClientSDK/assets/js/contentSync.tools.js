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
		if ( typeof label === 'string' ) {
			let className = args.className ? args.className : '';
			let url       = args.url ? ` href='${args.url}'` : '';
			let id        = args.id  ? ` id='${args.id}'` : '';
			let onclick   = args.onclick ? ` onclick='${args.onclick}'` : '';
			document.querySelector( 'hr.wp-header-end' ).insertAdjacentHTML( 'beforebegin', `<button class='button-ghost page-title-action ${className}'${id}${url}${onclick}>${label}</button>` );
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

		var data   = typeof contentSyncToolsData !== 'undefined' ? contentSyncToolsData : {};
		var assets = data.assetsPath || '';

		var titleMap = {
			root:   __( 'Global synced post', 'contentsync' ),
			linked: __( 'Global linked post', 'contentsync' ),
			error:  __( 'Error', 'contentsync' ),
			info:   __( 'Info', 'contentsync' )
		};
		var textMap = {
			failed:  __( 'Failed', 'contentsync' ),
			success: __( 'Completed', 'contentsync' ),
			started: __( 'Started', 'contentsync' ),
			init:    __( 'Scheduled', 'contentsync' )
		};
		var colorMap = {
			root:    'purple', export: 'purple', purple: 'purple',
			success: 'green',  import: 'green',  green: 'green',
			info:    'blue',   started: 'blue',  blue: 'blue',
			error:   'red',    failed: 'red',    red: 'red',
			warning: 'yellow', yellow: 'yellow'
		};
		// Only these icons exist under assets/icon/
		var iconMap = {
			root: 'root', linked: 'linked', unlinked: 'unlinked',
			error: 'error', failed: 'error', red: 'error',
			info: 'info', started: 'info', blue: 'info', success: 'info', import: 'info', init: 'info',
			export: 'root', purple: 'root', green: 'info', warning: 'info', yellow: 'info'
		};

		var title = titleMap[ status ] || null;
		var color = colorMap[ status ] !== undefined ? colorMap[ status ] : '';
		if ( text === '' ) {
			text = textMap[ status ] || '';
		}

		var iconSlug = iconMap[ status ] !== undefined ? iconMap[ status ] : 'info';
		var iconUrl  = assets ? ( assets.replace( /\/?$/, '' ) + '/icon/icon-' + iconSlug + '.svg' ) : '';

		var span = document.createElement( 'span' );
		span.className = 'contentsync-info-box contentsync-status' + ( color ? ' ' + color : '' );
		if ( title ) {
			span.setAttribute( 'data-title', title.replace( /\s/g, '\u00A0' ) );
		}

		if ( showIcon && iconUrl ) {
			var img = document.createElement( 'img' );
			img.src = iconUrl;
			img.setAttribute( 'style', 'width:auto;height:16px;' );
			span.appendChild( img );
		}

		if ( text ) {
			var textNode = document.createElement( 'span' );
			textNode.textContent = text;
			span.appendChild( textNode );
		}

		return span;
	};
};