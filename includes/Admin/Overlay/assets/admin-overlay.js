var contentsync = contentsync || {};

contentsync.overlay = new function() {

	this.overlayTimeout;

	this.init = function() {

		// add trigger to escape-button
		$( '.contentsync_overlay .button[role=\'escape\']' ).on( 'click', function() {
			contentsync.overlay.triggerOverlay( false, {
				id: $( this ).closest( '.contentsync_overlay' ).attr( 'id' )
			} );
		} );

		// greyd info popup
		$( '.contentsync_popup_wrapper' ).on( 'click', function( e ){
			contentsync.overlay.togglePopup( e, $( this ) );
		} );

		// tabs
		$( '.block_tabs .block_tab' ).on( 'click', function(){
			if ( $( this ).hasClass( 'active' ) ) return;

			$( this ).siblings( '.block_tab.active' ).removeClass( 'active' );
			$( this ).addClass( 'active' );
		} );
	};

	this.togglePopup = function( e, clicked ) {
		// console.log('Pop Up!');
		e.stopPropagation();
		var $toggle = clicked.children( '.toggle' );
		var dialog  = clicked.children( 'dialog' );
		if ( dialog.length ) {
			if ( dialog[ 0 ].open ) dialog[ 0 ].close();
			else dialog[ 0 ].showModal();
		} else {
			$toggle.toggleClass( 'checked dashicons-info dashicons-no' );
			$( document ).one( 'click', function() {
				$toggle.removeClass( 'checked dashicons-no' ).addClass( 'dashicons-info' );
			} );
		}
	};

	this.toggleElemByClass = function( cls ) {
		toggle = $( '.toggle_'+cls );
		if ( toggle.length !== 0 ) toggle.toggleClass( 'hidden' );
	};

	this.toggleRadioByClass = function( cls, val ) {
		$( '[class*=\''+cls+'\']:not(.'+cls+'_'+val+')' ).addClass( 'hidden' );
		$( '.'+cls+'_'+val ).removeClass( 'hidden' );
	};

	/**
	 * Show an overlay for different states and actions.
	 *
	 * Works best with overlays build with the function:
	 * @see \tp\managementgeneral->render_overlay() (inc/general.php)
	 * use @filter contentsync_overlay_contents to apply contents.
	 *
	 * @param {bool} show   Whether to show or hide the overlay
	 * @param {object} atts   Whether to show or hide the overlay
	 *      @property {string} type     CSS-class of wrapper (direct child of the overlay) to be shown
	 *      @property {string} css      CSS-class of direct child of the wrapper to be shown
	 *      @property {string} replace  Content inside '.replace' will be replaced by the given string
	 *      @property {string} id       ID of the overlay (default: 'overlay').
	 */
	this.triggerOverlay = function( show, atts ) {
		show = typeof show !== 'undefined' ? show : true;
		atts = atts ? atts : {};

		var type    = typeof atts.type !== 'undefined'      ? atts.type     : 'loading';
		var css     = typeof atts.css !== 'undefined'       ? atts.css      : 'init';
		var replace = typeof atts.replace !== 'undefined'   ? atts.replace  : '';
		var id      = typeof atts.id !== 'undefined'        ? atts.id       : 'overlay';

		console.log( show, type, css, replace, id );

		clearTimeout( contentsync.overlay.overlayTimeout );
		var overlay = $( '.contentsync_overlay#'+id );
		if ( overlay.length === 0 ) return false;

		if ( show === true ) {

			// show type
			var wrapper = overlay.children( '.'+type+', .always' );
			wrapper.siblings().addClass( 'hidden' );
			wrapper.each( function() {
				$( this ).removeClass( 'hidden' );
			} );

			// replace elements
			if ( replace.length > 0 ) {
				wrapper.find( '.replace' ).html( replace );
			}

			// show elements
			wrapper.find( '.depending' ).addClass( 'hidden' );
			// if multiple classes are defined, show each

			if ( css && css.length ) {
				String( css ).split( ' ' ).forEach( k => {
					wrapper.find( '.depending.'+k ).removeClass( 'hidden' );
				} );
				// show elements were both classes need to be set
				wrapper.find( '.depending.' + css.split( ' ' ).join( '-' ) ).removeClass( 'hidden' );
			}


			/**
			 * here we check if there are 2 overlays on the page (on hub page),
			 * we then loop through the wrappers and search for wrapper where only hidden containers are.
			 * Now we know that the overlay is not needed and can finally hide it
			 */
			if ( overlay.length === 2 ) {
				// console.log("found 2 overlays");
				wrapper.each( function() {
					if ( $( this ).children( '.depending' ).not( '.hidden' ).length == 0 ) {
						// console.log("found not needed overlay --> hiding it");
						$( this ).parent().addClass( 'hidden' );
					} else {
						$( this ).parent().removeClass( 'hidden' );
					}
				} );
			} else {
				// finally show overlay
				overlay.removeClass( 'hidden' );
			}


			// hide overlay...
			if ( type === 'success' ) {
				contentsync.overlay.fadeOutOverlay();
			} else if ( type === 'fail' ) {
				// contentsync.overlay.fadeOutOverlay(5000);
			}
			// ...or reload page
			else if ( type === 'reload' ) {
				contentsync.overlay.reloadPage();
			}
		}
		else {
			$( '.contentsync_overlay' ).addClass( 'hidden' );
			clearTimeout( contentsync.overlay.overlayTimeout );
		}
	};

	/**
	 * Confirm action via overlay
	 *
	 * @param string css        passed to the overlay function
	 * @param string replace    passed to the overlay function
	 * @param string callback   name of function to be called after confirmation
	 * @param array  args       arguments to be applied to the callback-function
	 */
	this.confirm = function( css, replace, callback, args, id ) {
		var overlayArgs = {
			type: 'confirm',
			css: css,
			replace: replace,
			id: id
		};
		this.triggerOverlay( true, overlayArgs );
		$( '.contentsync_overlay .button[role=\'confirm\']' ).off( 'click' ).on( 'click', function() {
			if ( typeof callback === 'function' ) {
				callback.apply( this, args );
			}

			overlayArgs.type = 'loading';
			contentsync.overlay.triggerOverlay( true, overlayArgs );
		} );
	};

	/**
	 * Decide between two action via overlay
	 *
	 * @param {string} css             passed to the overlay function
	 * @param {object[]} callbacks     array of objects with callback and args
	 *  @property {function} callback  name of function to be called after confirmation
	 *  @property {array} args         arguments to be applied to the callback-function
	 */
	this.decide = function( css, callbacks, deprecated1, deprecated2, deprecated3 ) {
		this.triggerOverlay( true, { 'type': 'decision', 'css': css, 'replace': '' } );

		// console.log( typeof callbacks, callbacks, deprecated1, deprecated2, deprecated3 );

		var callback1, args1, callback2, args2;
		if ( typeof callbacks === 'object' ) {
			if ( callbacks?.callback ) {
				callbacks = [ callbacks ];
			}

			callback1 = callbacks[ 0 ]?.callback;
			args1     = callbacks[ 0 ]?.args;
			if ( callbacks[ 1 ]?.callback ) {
				callback2 = callbacks[ 1 ]?.callback;
				args2     = callbacks[ 1 ]?.args;
			}
		} else {
			callback1 = callbacks;
			callback2 = deprecated1;
			args1     = deprecated2;
			args2     = deprecated3;
		}

		$( '.contentsync_overlay .button[role=\'decision\'][decision=\'0\']' ).off( 'click' ).on( 'click', function() {
			if ( typeof callback1 === 'function' ) callback1.apply( this, args1 );
		} );
		$( '.contentsync_overlay .button[role=\'decision\'][decision=\'1\']' ).off( 'click' ).on( 'click', function() {
			if ( typeof callback2 === 'function' ) callback2.apply( this, args2 );
		} );
	};

	this.fadeOutOverlay = function( time ) {
		time = time ? time : 2400;
		clearTimeout( contentsync.overlay.overlayTimeout );
		contentsync.overlay.overlayTimeout = setTimeout( function() {
			contentsync.overlay.triggerOverlay( false );
		}, time );
	};

	this.reloadPage = function( time ) {
		var form = $( 'form#reload_page' );
		if ( form.length === 0 ) {
			form = $( '#wpfooter' ).after( '<form method=\'post\' id=\'reload_page\'></form>' );
		}

		time = time ? time : 1500;
		clearTimeout( contentsync.overlay.overlayTimeout );
		contentsync.overlay.overlayTimeout = setTimeout( function() {
			$( 'form#reload_page' ).submit();
		}, time );
	};

	this.addPageTitleAction = function( label, args ) {
		if ( typeof label === 'string' ) {
			var css     = args.css ? args.css : '';
			var url     = args.url ? ' href=\''+args.url+'\'' : '';
			var id      = args.id  ? ' id=\''+args.id+'\'' : '';
			var onclick = args.onclick ? ' onclick=\''+args.onclick+'\'' : '';
			$( 'hr.wp-header-end' ).before( '<a class=\'button-ghost page-title-action '+css+'\''+id+url+onclick+'>'+label+'</a>' );
		}
	};

	this.toggleOverlay = function( id ) {
		$( '.contentsync_overlay_v2'+( id ? '#'+id : '' ) ).toggleClass( 'is-active' );
	};

	this.openOverlay = function( id ) {
		$( '.contentsync_overlay_v2'+( id ? '#'+id : '' ) ).addClass( 'is-active' );
	};

	this.closeOverlay = function( id ) {
		$( '.contentsync_overlay_v2'+( id ? '#'+id : '' ) ).removeClass( 'is-active' );
	};
};

document.addEventListener( 'DOMContentLoaded', () => {
	contentsync.overlay.init();
} );