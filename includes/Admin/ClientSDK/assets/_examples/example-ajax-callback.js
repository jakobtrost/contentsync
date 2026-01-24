document.addEventListener( 'DOMContentLoaded', () => {

	const onSend = function( data ) {
		console.log( 'onSend', data );
	};

	const onSuccess = function( message, response ) {
		console.log( 'onSuccess', message, response );
	};

	const onError = function( response ) {
		console.log( 'onError', response );
	};
	
	let config = {

		// required
		action: 'post_export',

		// callbacks (optional, but recommended)
		onSend: onSend,
		onSuccess: onSuccess,
		onError: onError,

		// additional request params (optional)
		request: {
			type: 'POST',
			processData: false,
			contentType: false,
			cache: false,
		},
	};

	const ajaxHandler = new contentSync.AjaxHandler( config );

	const data = {
		post_id: 1,
	};

	ajaxHandler.send( data );
} );