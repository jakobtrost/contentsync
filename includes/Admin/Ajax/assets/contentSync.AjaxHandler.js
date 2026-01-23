/**
 * Ajax Handler Class
 * 
 * Provides a simple abstraction for WordPress AJAX requests.
 * Handles URL, nonce, action routing, and response parsing automatically.
 */
class AjaxHandler {

	/**
	 * Configuration object
	 * @type {Object}
	 */
	config = null;

	/**
	 * Action name
	 * @type {string}
	 */
	action = null;

	/**
	 * AJAX URL
	 * @type {string}
	 */
	ajaxUrl = null;

	/**
	 * Nonce
	 * @type {string}
	 */
	nonce = null;

	/**
	 * Default request options
	 * @type {Object}
	 */
	defaultRequestOptions = null;

	/**
	 * Request options
	 * @type {Object}
	 */
	requestOptions = null;

	/**
	 * Constructor
	 * @param {Object} config - Configuration object
	 * @param {string} config.action - Required: The action name (e.g., 'post_export')
	 * @param {Function} [config.onSend] - Optional: Callback before sending request (data)
	 * @param {Function} [config.onSuccess] - Optional: Callback on success (parsedMessage, fullResponse)
	 * @param {Function} [config.onError] - Optional: Callback on error (parsedMessage, fullResponse)
	 * @param {Object} [config.request] - Optional: Additional jQuery.ajax() options
	 */
	constructor( config ) {
		// Validate required config
		if ( !config || !config.action ) {
			throw new Error( 'AjaxHandler: action is required' );
		}

		// Store config
		this.config = config;
		this.action = config.action;

		// Get localized data (from AjaxHandler_Enqueue_Hooks)
		if ( typeof contentSyncAjaxData === 'undefined' ) {
			throw new Error( 'AjaxHandler: contentSyncAjaxData is not defined. Make sure the script is enqueued properly.' );
		}

		this.ajaxUrl = contentSyncAjaxData.url;
		this.nonce = contentSyncAjaxData.nonce;

		// Default request options (similar to jQuery.post behavior)
		this.defaultRequestOptions = {
			type: 'POST',
			processData: true,
			contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
			cache: false,
		};

		// Merge user-provided request options with defaults
		this.requestOptions = Object.assign( {}, this.defaultRequestOptions, config.request || {} );
	}

	/**
	 * Send AJAX request
	 * @param {Object|FormData} data - Data to send with the request
	 * @returns {jqXHR} jQuery XHR object
	 */
	send( data ) {
		// Call onSend callback if provided
		if ( this.config.onSend && typeof this.config.onSend === 'function' ) {
			this.config.onSend.call( this, data );
		}

		// Prepare request data
		const requestData = this.prepareRequestData( data );

		// Build jQuery.ajax options
		const ajaxOptions = this.buildAjaxOptions( requestData );

		// Send the request
		return $.ajax( ajaxOptions );
	}

	/**
	 * Prepare request data for WordPress AJAX
	 * @param {Object|FormData} data - User-provided data
	 * @returns {Object|FormData} Prepared data
	 */
	prepareRequestData( data ) {
		// If data is FormData, append WordPress AJAX parameters
		if ( data instanceof FormData ) {
			data.append( 'action', 'contentSync_ajax' );
			data.append( '_ajax_nonce', this.nonce );
			data.append( 'mode', this.action );

			// User data should be appended as 'data' key
			// Note: If user wants to send files, they should append to FormData before calling send()
			return data;
		}

		// For regular objects, build standard WordPress AJAX payload
		return {
			action: 'contentSync_ajax',
			_ajax_nonce: this.nonce,
			mode: this.action,
			data: data || {},
		};
	}

	/**
	 * Build jQuery.ajax options
	 * @param {Object|FormData} requestData - Prepared request data
	 * @returns {Object} jQuery.ajax options
	 */
	buildAjaxOptions( requestData ) {
		const options = {
			url: this.ajaxUrl,
			data: requestData,
			...this.requestOptions,
		};

		// If using FormData, ensure processData and contentType are false
		if ( requestData instanceof FormData ) {
			options.processData = false;
			options.contentType = false;
		}

		// Add success handler
		options.success = ( response, textStatus, jqXHR ) => {
			this.handleSuccess( response, textStatus, jqXHR );
		};

		// Add error handler
		options.error = ( jqXHR, textStatus, errorThrown ) => {
			this.handleError( jqXHR, textStatus, errorThrown );
		};

		return options;
	}

	/**
	 * Handle successful AJAX response
	 * @param {string} response - Response from server
	 * @param {string} textStatus - Status text
	 * @param {jqXHR} jqXHR - jQuery XHR object
	 */
	handleSuccess( response, textStatus, jqXHR ) {
		// Parse response (format: 'success::message' or 'error::message')
		const parsed = this.parseResponse( response );

		if ( parsed.type === 'success' ) {
			// Call onSuccess callback if provided
			// Unified signature: (parsedMessage, fullResponse)
			if ( this.config.onSuccess && typeof this.config.onSuccess === 'function' ) {
				this.config.onSuccess.call( this, parsed.message, response );
			}
		} else if ( parsed.type === 'error' ) {
			// Treat error response as error
			this.handleError( jqXHR, 'error', parsed.message, response );
		} else {
			// Unknown response format - treat as error
			console.warn( 'AjaxHandler: Unknown response format:', response );
			if ( this.config.onError && typeof this.config.onError === 'function' ) {
				this.config.onError.call( this, response, response );
			}
		}
	}

	/**
	 * Handle AJAX error
	 * @param {jqXHR} jqXHR - jQuery XHR object
	 * @param {string} textStatus - Status text
	 * @param {string} errorThrown - Error message
	 * @param {string} [responseText] - Optional response text (if already parsed from success handler)
	 */
	handleError( jqXHR, textStatus, errorThrown, responseText ) {
		// Get full response text
		const fullResponse = responseText || ( jqXHR && jqXHR.responseText ) || '';
		
		// Try to parse response if available
		let errorMessage = errorThrown || textStatus;
		
		if ( fullResponse ) {
			const parsed = this.parseResponse( fullResponse );
			if ( parsed.type === 'error' ) {
				errorMessage = parsed.message;
			} else if ( parsed.type === 'success' ) {
				// This shouldn't happen, but handle it gracefully
				this.handleSuccess( fullResponse, textStatus, jqXHR );

				return;
			} else {
				errorMessage = fullResponse;
			}
		}

		// Call onError callback if provided
		// Unified signature: (parsedMessage, fullResponse)
		if ( this.config.onError && typeof this.config.onError === 'function' ) {
			this.config.onError.call( this, errorMessage, fullResponse );
		}
	}

	/**
	 * Parse WordPress AJAX response
	 * @param {string} response - Raw response string
	 * @returns {Object} Parsed response with type and message
	 */
	parseResponse( response ) {
		if ( typeof response !== 'string' ) {
			return { type: 'unknown', message: response };
		}

		// Check for success format: 'success::message'
		if ( response.indexOf( 'success::' ) > -1 ) {
			const message = response.split( 'success::' )[ 1 ];

			return { type: 'success', message: message };
		}

		// Check for error format: 'error::message'
		if ( response.indexOf( 'error::' ) > -1 ) {
			const message = response.split( 'error::' )[ 1 ];

			return { type: 'error', message: message };
		}

		// Unknown format
		return { type: 'unknown', message: response };
	}
}

// Make available globally under contentSync namespace
var contentSync = contentSync || {};
contentSync.AjaxHandler = AjaxHandler;
