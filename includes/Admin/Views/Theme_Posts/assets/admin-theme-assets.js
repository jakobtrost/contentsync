var contentSync = contentSync || {};

contentSync.themeAssets = new function() {


	/**
	 * Start the switch theme dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openSwitchTemplateTheme = function( elem ) {
		contentSync.overlay.confirm( 'switch_template_theme', '', contentSync.themeAssets.switchTemplateTheme, [ elem ] );
	};

	/**
	 * Switch the theme of a template or template part
	 * 
	 * @param {object} elem 
	 */
	this.switchTemplateTheme = function( elem ) {

		const mode    = 'switch_template_theme';
		const postId  = $( elem ).data( 'post_id' );
		
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': {
					post_id: postId,
					switch_references_in_content: document.getElementById( 'switch_references_in_content' ).checked
				}
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// use for development
					// contentSync.overlay.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentSync.overlay.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					const msg = response.split( 'error::' )[ 1 ];
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};


	/**
	 * Start the switch theme dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openSwitchGlobalStyles = function( elem, templateTheme ) {
		contentSync.overlay.confirm( 'switch_global_styles', templateTheme, contentSync.themeAssets.switchGlobalStyles, [ elem ] );
	};

	/**
	 * Switch the theme of a template or template part
	 * 
	 * @param {object} elem 
	 */
	this.switchGlobalStyles = function( elem ) {

		const mode    = 'switch_global_styles';
		const postId  = $( elem ).data( 'post_id' );
		
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': {
					post_id: postId
				}
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// use for development
					// contentSync.overlay.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentSync.overlay.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					const msg = response.split( 'error::' )[ 1 ];
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};


	/**
	 * Start the rename dialog (row action)
	 * 
	 * @param {object} elem 
	 */
	this.openRenameTemplate = function( elem ) {
		
		const postTitle = $( elem ).data( 'post_title' );
		const postName  = $( elem ).data( 'post_name' );

		$( '#rename_template_form input[name="new_post_title"]' ).val( postTitle );
		$( '#rename_template_form input[name="new_post_name"]' ).val( postName );

		contentSync.overlay.confirm( 'rename_template', '', contentSync.themeAssets.renameTemplate, [ elem ] );
	};

	/**
	 * Rename a template, template part or global style.
	 * 
	 * @param {object} elem 
	 */
	this.renameTemplate = function( elem ) {

		const mode    = 'rename_template';
		const postId  = $( elem ).data( 'post_id' );
		const data   = {
			post_id: postId,
			post_title: $( '#rename_template_form input[name="new_post_title"]' ).val(),
			post_name: $( '#rename_template_form input[name="new_post_name"]' ).val()
		};

		console.log( data );
		
		$.post(
			greyd.ajax_url, {
				'action': 'contentsync_ajax',
				'_ajax_nonce': greyd.nonce,
				'mode': mode,
				'data': data
			}, 
			function( response ) {
				console.log( response );
				
				// successfull
				if ( response.indexOf( 'success::' ) > -1 ) {

					// use for development
					// contentSync.overlay.triggerOverlay( true, { "type": "success", "css": mode } );return;

					// trigger overlay
					contentSync.overlay.triggerOverlay( true, { 'type': 'reload', 'css': mode } );
				}
				else if ( response.indexOf( 'error::' ) > -1 ) {
					const msg = response.split( 'error::' )[ 1 ];
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': msg } );
				}
				else {
					contentSync.overlay.triggerOverlay( true, { 'type': 'fail', 'css': mode, 'replace': response } );
				}
			}
		);
	};
};