/**
 * Content Sync AJAX Wrapper
 *
 * Unified AJAX request handler for all Content Sync operations.
 * Provides consistent request/response handling, error management, and overlay integration.
 *
 * @package Contentsync
 */

( function ( $ ) {
	'use strict';

	/**
	 * AJAX Wrapper Object
	 */
	var AjaxWrapper = {

		/**
		 * Default configuration
		 */
		defaults: {
			ajaxUrl: null,
			nonce: null,
			debug: false,
		},

		/**
		 * Initialize the wrapper
		 *
		 * @param {object} config Configuration object.
		 */
		init: function ( config ) {
			this.defaults = $.extend( {}, this.defaults, config );
		},

		/**
		 * Make an AJAX request
		 *
		 * @param {string|object} actionOrOptions Action name (string) or options object.
		 * @param {object} data Optional data object (if first param is string).
		 * @param {string|jQuery} form Optional form selector or jQuery object (if first param is string).
		 * @param {object} options Optional additional options (if first param is string).
		 * @returns {jQuery.Deferred} jQuery deferred object.
		 */
		request: function ( actionOrOptions, data, form, options ) {
			var self = this;
			var config;

			// Handle different call signatures
			if ( typeof actionOrOptions === 'string' ) {
				// Simple signature: request(action, data, form, options)
				config = $.extend( {}, {
					action: actionOrOptions,
					data: data || {},
					form: form,
				}, options || {} );
			} else {
				// Options object signature: request({action: '...', data: {...}, ...})
				config = $.extend( {}, actionOrOptions );
			}

			// Set defaults
			config = $.extend( {
				mode: config.action, // Default mode to action name
				overlay: {},
				download: false,
				parseJSON: false,
				noReload: false,
				onSuccess: null,
				onError: null,
				onComplete: null,
			}, config );

			// Get AJAX URL and nonce
			var ajaxUrl = config.ajaxUrl || this.defaults.ajaxUrl || ( typeof greyd !== 'undefined' ? greyd.ajax_url : null ) || ( typeof wizzard_details !== 'undefined' ? wizzard_details.ajax_url : null );
			var nonce = config.nonce || this.defaults.nonce || ( typeof greyd !== 'undefined' ? greyd.nonce : null ) || ( typeof wizzard_details !== 'undefined' ? wizzard_details.nonce : null );

			if ( ! ajaxUrl || ! nonce ) {
				console.error( 'Content Sync AJAX: ajaxUrl or nonce not defined' );
				return $.Deferred().reject( 'Configuration error' );
			}

			// Prepare data
			var requestData = $.extend( {}, config.data || {} );

			// Serialize form data if form is provided
			if ( config.form ) {
				var $form = typeof config.form === 'string' ? $( config.form ) : config.form;
				if ( $form.length > 0 ) {
					var formData = $form.serializeArray().reduce( function ( obj, item ) {
						obj[ item.name ] = item.value;
						return obj;
					}, {} );
					requestData.form_data = formData;
				}
			}

			// For actions that use 'global_action' mode, set the action in data
			if ( config.mode === 'global_action' || ! config.mode ) {
				requestData.action = config.action;
			}

			// Create deferred object
			var deferred = $.Deferred();

			// Prepare AJAX options
			var ajaxOptions = {
				type: 'POST',
				url: ajaxUrl,
				data: {
					action: 'contentsync_ajax',
					_ajax_nonce: nonce,
					mode: config.mode || 'global_action',
					data: requestData,
				},
				success: function ( response ) {
					self._handleResponse( response, config, deferred );
				},
				error: function ( xhr, status, error ) {
					self._handleError( error, config, deferred );
				},
			};

			// Handle file uploads
			if ( config.fileInput ) {
				var fileInput = typeof config.fileInput === 'string' ? $( config.fileInput )[ 0 ] : config.fileInput;
				if ( fileInput && fileInput.files && fileInput.files.length > 0 ) {
					var formData = new FormData();
					formData.append( 'action', 'contentsync_ajax' );
					formData.append( '_ajax_nonce', nonce );
					formData.append( 'mode', config.mode || 'global_action' );
					formData.append( 'data', fileInput.files[ 0 ] );

					ajaxOptions = {
						type: 'POST',
						url: ajaxUrl,
						processData: false,
						contentType: false,
						cache: false,
						data: formData,
						success: function ( response ) {
							self._handleResponse( response, config, deferred );
						},
						error: function ( xhr, status, error ) {
							self._handleError( error, config, deferred );
						},
					};
				}
			}

			// Make the request
			$.ajax( ajaxOptions );

			return deferred.promise();
		},

		/**
		 * Handle AJAX response
		 *
		 * @param {string} response Raw response string.
		 * @param {object} config Request configuration.
		 * @param {jQuery.Deferred} deferred Deferred object.
		 * @private
		 */
		_handleResponse: function ( response, config, deferred ) {
			var self = this;

			if ( this.defaults.debug ) {
				console.log( 'Content Sync AJAX Response:', response );
			}

			// Parse response
			var isError = response.indexOf( 'error::' ) > -1;
			var isSuccess = response.indexOf( 'success::' ) > -1;

			if ( isError ) {
				var errorMsg = response.split( 'error::' )[ 1 ];
				this._handleError( errorMsg, config, deferred );
				return;
			}

			if ( ! isSuccess ) {
				this._handleError( response, config, deferred );
				return;
			}

			// Extract success message/data
			var successData = response.split( 'success::' )[ 1 ];

			// Parse JSON if requested
			if ( config.parseJSON ) {
				try {
					successData = JSON.parse( successData );
				} catch ( e ) {
					console.error( 'Content Sync AJAX: Failed to parse JSON response', e );
					this._handleError( 'Error parsing JSON response', config, deferred );
					return;
				}
			}

			// Handle file download
			if ( config.download && successData ) {
				this._triggerDownload( successData );
			}

			// Determine overlay type
			var overlayType = this._determineOverlayType( config, isSuccess );

			// Trigger overlay if configured
			if ( config.overlay !== false && typeof contentsync !== 'undefined' && contentsync.tools && contentsync.tools.triggerOverlay ) {
				var overlayConfig = $.extend( {
					type: overlayType,
					css: config.action || config.mode,
				}, config.overlay );

				contentsync.tools.triggerOverlay( true, overlayConfig );
			}

			// Call custom success handler
			if ( config.onSuccess ) {
				config.onSuccess( successData, response );
			}

			// Resolve deferred
			deferred.resolve( successData, response );

			// Call complete handler
			if ( config.onComplete ) {
				config.onComplete( successData, response );
			}
		},

		/**
		 * Handle AJAX error
		 *
		 * @param {string} error Error message.
		 * @param {object} config Request configuration.
		 * @param {jQuery.Deferred} deferred Deferred object.
		 * @private
		 */
		_handleError: function ( error, config, deferred ) {
			// Trigger error overlay if configured
			if ( config.overlay !== false && typeof contentsync !== 'undefined' && contentsync.tools && contentsync.tools.triggerOverlay ) {
				var overlayConfig = $.extend( {
					type: 'fail',
					css: config.action || config.mode,
					replace: error,
				}, config.overlay );

				contentsync.tools.triggerOverlay( true, overlayConfig );
			}

			// Call custom error handler
			if ( config.onError ) {
				config.onError( error );
			}

			// Reject deferred
			deferred.reject( error );
		},

		/**
		 * Determine overlay type based on action and context
		 *
		 * @param {object} config Request configuration.
		 * @param {boolean} isSuccess Whether request was successful.
		 * @returns {string} Overlay type.
		 * @private
		 */
		_determineOverlayType: function ( config, isSuccess ) {
			if ( ! isSuccess ) {
				return 'fail';
			}

			// Check if we're in the editor and action should not reload
			if (
				typeof contentsync !== 'undefined' &&
				contentsync.editor &&
				contentsync.editor.postReference !== null &&
				! config.noReload
			) {
				const actionsWithoutReload = [ 'contentsync_export', 'contentsync_unexport', 'contentsync_unimport', 'contentsync_repair' ];
				if ( actionsWithoutReload.indexOf( config.action ) > -1 ) {
					contentsync.editor.getData( contentsync.editor.postReference, true );
					return 'success';
				}
			}

			// Default to reload for successful requests
			return config.overlay.type || 'reload';
		},

		/**
		 * Trigger file download
		 *
		 * @param {string} fileUrl File URL to download.
		 * @private
		 */
		_triggerDownload: function ( fileUrl ) {
			var link = $( 'a#post_export_download' );
			if ( link.length === 0 ) {
				$( '#wpfooter' ).after( '<a id="post_export_download"></a>' );
				link = $( 'a#post_export_download' );
			}

			var filename = fileUrl.match( /\/[^\/]+.zip/ ) ? fileUrl.match( /\/[^\/]+.zip/ )[ 0 ].replace( '/', '' ) : '';

			link.attr( {
				href: fileUrl,
				download: filename,
			} );

			link[ 0 ].click();
		},
	};

	// Expose to global scope
	if ( typeof contentsync === 'undefined' ) {
		window.contentsync = {};
	}

	contentsync.ajaxWrapper = AjaxWrapper;

	// Auto-initialize if greyd or wizzard_details is available
	$( document ).ready( function () {
		if ( typeof greyd !== 'undefined' || typeof wizzard_details !== 'undefined' ) {
			AjaxWrapper.init( {
				ajaxUrl: typeof greyd !== 'undefined' ? greyd.ajax_url : ( typeof wizzard_details !== 'undefined' ? wizzard_details.ajax_url : null ),
				nonce: typeof greyd !== 'undefined' ? greyd.nonce : ( typeof wizzard_details !== 'undefined' ? wizzard_details.nonce : null ),
			} );
		}
	} );

} )( jQuery );
