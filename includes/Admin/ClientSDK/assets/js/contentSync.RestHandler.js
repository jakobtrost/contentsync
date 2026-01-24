/**
 * Rest Handler Class
 *
 * Sends requests to the Content Sync admin REST API (contentsync/v1/admin/*).
 * Uses wp.apiFetch when available, otherwise fetch. For FormData (file uploads)
 * uses jQuery.ajax. Expects response shape: { status, message, data }.
 * Calls onSuccess( data, fullResponse ) or onError( message, fullResponse ).
 */
class RestHandler {

	/**
	 * Configuration object
	 * @type {Object}
	 */
	config = null;

	/**
	 * REST path segment under contentsync/v1/admin (e.g. 'post-export')
	 * @type {string}
	 */
	restPath = null;

	/**
	 * Base path for admin endpoints (from contentSyncRestData)
	 * @type {string}
	 */
	basePath = null;

	/**
	 * REST root URL (from contentSyncRestData, for fetch/jQuery fallback)
	 * @type {string}
	 */
	restRoot = null;

	/**
	 * REST nonce (from contentSyncRestData)
	 * @type {string}
	 */
	nonce = null;

	/**
	 * Request options (merged with defaults)
	 * @type {Object}
	 */
	requestOptions = null;

	/**
	 * Constructor
	 * @param {Object} config - Configuration object
	 * @param {string} config.restPath - Required: path under contentsync/v1/admin (e.g. 'post-export')
	 * @param {Function} [config.onSend] - Optional: callback before sending (data)
	 * @param {Function} [config.onSuccess] - Optional: (responseData, fullResponse)
	 * @param {Function} [config.onError] - Optional: (message, fullResponse)
	 * @param {Object} [config.request] - Optional: extra fetch/ajax options
	 */
	constructor( config ) {
		if ( ! config || ! config.restPath ) {
			throw new Error( 'RestHandler: restPath is required' );
		}

		this.config = config;
		this.restPath = config.restPath;

		if ( typeof contentSyncRestData === 'undefined' ) {
			throw new Error( 'RestHandler: contentSyncRestData is not defined. Make sure the script is enqueued properly.' );
		}

		this.basePath = contentSyncRestData.basePath || 'contentsync/v1/admin';
		this.restRoot = contentSyncRestData.restRoot || '';
		this.nonce = contentSyncRestData.nonce || '';

		this.requestOptions = Object.assign( {}, config.request || {} );
	}

	/**
	 * Full REST path (basePath + / + restPath)
	 * @returns {string}
	 */
	getPath() {
		const base = ( this.basePath || '' ).replace( /\/$/, '' );
		const path = ( this.restPath || '' ).replace( /^\//, '' );

		return base + '/' + path;
	}

	/**
	 * Full URL for fetch/jQuery (restRoot + path)
	 * @returns {string}
	 */
	getUrl() {
		const root = ( this.restRoot || '' ).replace( /\/$/, '' );
		const path = this.getPath();

		return root + '/' + path;
	}

	/**
	 * Send request to the admin REST endpoint
	 * @param {Object|FormData} data - JSON object or FormData (for file uploads)
	 * @returns {Promise|jqXHR}
	 */
	send( data ) {
		if ( this.config.onSend && typeof this.config.onSend === 'function' ) {
			this.config.onSend.call( this, data );
		}

		if ( data instanceof FormData ) {
			return this._sendFormData( data );
		}

		return this._sendJson( data || {} );
	}

	/**
	 * Send JSON body via wp.apiFetch or fetch
	 * @param {Object} data
	 * @private
	 */
	_sendJson( data ) {
		const path = this.getPath();
		const url = this.getUrl();

		const handleSuccess = ( body ) => {
			const status = body && typeof body.status === 'number' ? body.status : 0;
			const data = body && body.hasOwnProperty( 'data' ) ? body.data : body;
			const isSuccess = status >= 200 && status < 300;

			if ( isSuccess ) {
				if ( this.config.onSuccess && typeof this.config.onSuccess === 'function' ) {
					this.config.onSuccess.call( this, data, body );
				}
			} else {
				const msg = ( body && body.message ) ? body.message : 'Request failed';
				if ( this.config.onError && typeof this.config.onError === 'function' ) {
					this.config.onError.call( this, msg, body );
				}
			}
		};

		const handleError = ( err ) => {
			const msg = ( err && err.message ) ? err.message : ( err && err.code ) ? err.code : 'Request failed';
			console.error( 'Rest API Error: ', msg, err );
			const full = err || {};
			if ( this.config.onError && typeof this.config.onError === 'function' ) {
				this.config.onError.call( this, msg, full );
			}
		};

		if ( typeof wp !== 'undefined' && wp.apiFetch ) {
			return wp.apiFetch( {
				path,
				method: 'POST',
				data,
				parse: true,
			} ).then( ( body ) => {
				handleSuccess( body );

				return body;
			} ).catch( handleError );
		}

		return fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify( data ),
			credentials: 'same-origin',
			...this.requestOptions,
		} ).then( ( res ) => res.json() ).then( ( body ) => {
			const status = body && typeof body.status === 'number' ? body.status : 0;
			if ( status >= 400 || status < 200 ) {
				handleError( { message: ( body && body.message ) || 'Request failed', ...( body || {} ) } );
			} else {
				handleSuccess( body );
			}

			return body;
		} ).catch( handleError );
	}

	/**
	 * Send FormData via jQuery.ajax (for file uploads)
	 * @param {FormData} data
	 * @private
	 */
	_sendFormData( data ) {
		const url = this.getUrl();
		const self = this;

		if ( data instanceof FormData ) {
			for ( const [ key, value ] of data.entries() ) {
				console.log( 'formData[', key, ']: ', value );
			}
		} else {
			console.log( 'data: ', data );
		}

		return jQuery.ajax( {
			url,
			method: 'POST',
			data,
			processData: false,
			contentType: false,
			beforeSend( xhr ) {
				if ( self.nonce ) {
					xhr.setRequestHeader( 'X-WP-Nonce', self.nonce );
				}
			},
			...this.requestOptions,
		} ).then( function( body ) {
			if ( typeof body === 'string' ) {
				try {
					body = JSON.parse( body );
				} catch ( e ) {
					if ( self.config.onError && typeof self.config.onError === 'function' ) {
						self.config.onError.call( self, body || 'Invalid response', body );
					}

					return;
				}
			}

			const status = body && typeof body.status === 'number' ? body.status : 0;
			const data = body && body.hasOwnProperty( 'data' ) ? body.data : body;
			const isSuccess = status >= 200 && status < 300;
			if ( isSuccess ) {
				if ( self.config.onSuccess && typeof self.config.onSuccess === 'function' ) {
					self.config.onSuccess.call( self, data, body );
				}
			} else {
				const msg = ( body && body.message ) ? body.message : 'Request failed';
				if ( self.config.onError && typeof self.config.onError === 'function' ) {
					self.config.onError.call( self, msg, body );
				}
			}
		} ).fail( function( jqXHR ) {
			let msg = ( jqXHR && jqXHR.statusText ) ? jqXHR.statusText : 'Request failed';
			let full = jqXHR || {};
			if ( jqXHR && jqXHR.responseJSON ) {
				full = jqXHR.responseJSON;
				if ( full.message ) {
					msg = full.message;
				}
			} else if ( jqXHR && jqXHR.responseText ) {
				try {
					const parsed = JSON.parse( jqXHR.responseText );
					if ( parsed && parsed.message ) {
						msg = parsed.message;
					}

					full = parsed;
				} catch ( e ) {
					// ignore
				}
			}

			if ( self.config.onError && typeof self.config.onError === 'function' ) {
				self.config.onError.call( self, msg, full );
			}
		} );
	}
}

// Expose on contentSync
var contentSync = contentSync || {};
contentSync.RestHandler = RestHandler;
