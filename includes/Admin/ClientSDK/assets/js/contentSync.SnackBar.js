/**
 * SnackBar – WordPress-style snackbar (vanilla JS). Each instance is one snackbar.
 * Use contentSync.tools.addSnackBar(options) to create and show a snackbar.
 */
var contentSync = contentSync || {};

/**
 * @class SnackBar
 * @param {Object} options
 * @param {string} [options.text] - Main text (alias: options.text)
 * @param {Object} [options.link] - Optional link: { text, url, target, rel }
 * @param {string} [options.type] - success|info|warning|error (icon: ✅⚠️❌ or none for info)
 * @param {number} [options.timeout=5000] - ms after which to dismiss; 0 = no auto-dismiss
 */
class SnackBar {

	/**
	 * @type {string}
	 */
	text = '';

	/**
	 * @type {Object}
	 * @property {string} text
	 * @property {string} url
	 * @property {string} target
	 * @property {string} rel
	 * @property {string} onclick
	 */
	link = {};

	/**
	 * @type {string}
	 */
	type = 'info';

	/**
	 * @type {number}
	 */
	timeout = 5000;

	/**
	 * @type {Object}
	 */
	wrapper = null;

	/**
	 * Creating a new SnackBar instance automatically adds the HTML to the DOM
	 *
	 * @param {Object} options                  SnackBar options object
	 *   @param {string} options.text           Main text (alias: options.text)
	 *   @param {Object} options.link           Optional link object:
	 *     @param {string} options.link.text    Link text
	 *     @param {string} options.link.url     Link URL
	 *     @param {string} options.link.target  Link target
	 *     @param {string} options.link.rel     Link rel
	 *     @param {string} options.link.onclick Link onclick
	 *   @param {string} options.type           success|info|warning|error
	 *   @param {number} options.timeoutms      milliseconds after which to dismiss (default: 5000); 0 = no auto-dismiss
	 */
	constructor( options ) {
		this.text = options.text || '';
		this.link = options.link || {};
		this.type = options.type || 'info';
		this.timeout = options.timeout !== undefined ? options.timeout : 5000;

		console.log( 'SnackBar constructor', options );

		this.render();
	}

	/**
	 * Render the SnackBar HTML
	 */
	render() {
		const iconMap = { success: '✅', warning: '⚠️', error: '❌', info: null };
		const icon = iconMap[ this.type ] !== undefined ? iconMap[ this.type ] : iconMap.info;

		const list = document.querySelector( '.components-snackbar-list' );
		if ( ! list ) { return; }

		// Outer wrapper: opacity animated for fade-out
		const wrapper = document.createElement( 'div' );
		wrapper.style.height = 'auto';
		wrapper.style.opacity = '1';

		var noticeContainer = document.createElement( 'div' );
		noticeContainer.className = 'components-snackbar-list__notice-container';

		var snackbar = document.createElement( 'div' );
		snackbar.className = 'components-snackbar';
		snackbar.setAttribute( 'tabindex', '0' );
		snackbar.setAttribute( 'role', 'button' );
		snackbar.setAttribute( 'aria-label', 'Dismiss this notice' );

		var content = document.createElement( 'div' );
		content.className = 'components-snackbar__content';
		if ( icon ) {
			content.classList.add( 'components-snackbar__content-with-icon' );
			var iconSpan = document.createElement( 'span' );
			iconSpan.className = 'components-snackbar__icon';
			iconSpan.textContent = icon;
			content.appendChild( iconSpan );
		}

		content.appendChild( document.createTextNode( this.text ) );

		if ( this.link && ( this.link.url || this.link.onclick ) ) {
			var a = document.createElement( 'a' );
			a.className = 'components-external-link components-snackbar__action';
			if ( this.link?.url ) {
				a.href = this.link?.url;
				a.target = this.link?.target || '';
				a.rel = this.link?.rel || '';
			}

			var linkContents = document.createElement( 'span' );
			linkContents.className = 'components-external-link__contents';
			linkContents.textContent = this.link.text || '';
			a.appendChild( linkContents );

			content.appendChild( a );

			if ( this.link?.onclick ) {
				a.addEventListener( 'click', () => {
					this.link?.onclick();
					setTimeout( () => this.dismiss(), 100 );
				} );
			}
		}

		snackbar.appendChild( content );
		noticeContainer.appendChild( snackbar );
		wrapper.appendChild( noticeContainer );
		list.appendChild( wrapper );

		// Store reference to wrapper
		this.wrapper = wrapper;

		snackbar.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( 'a' ) ) { return; }

			this.dismiss();
		} );

		if ( this.timeout > 0 ) {
			setTimeout( () => this.dismiss(), this.timeout );
		}
	}

	dismiss() {
		if ( this.wrapper && this.wrapper.parentNode ) {
			this.wrapper.style.transition = 'opacity 0.15s ease';
			this.wrapper.style.opacity = '0';
			setTimeout( () => {
				this.wrapper.remove();
			}, 150 );
		}
	}
};

contentSync.SnackBar = SnackBar;