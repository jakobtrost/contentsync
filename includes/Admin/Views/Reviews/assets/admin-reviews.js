var contentSync = contentSync || {};

contentSync.reviews = new function () {
	/**
	 * Approve post review
	 */
	this.openReviewApprove = function ( elem, postId, reviewId ) {

		const action = 'contentsync_review_approve';

		contentSync.checkUnsavedChanges();
		contentSync.overlay.confirm(
			action,
			'',
			contentSync.ajax,
			[
				action,
				{
					'review_id': reviewId,
					'post_id': postId
				}
			]
		);
	};

	/**
	 * Deny post review
	 */
	this.openReviewDeny = function ( elem, postId, reviewId ) {

		const action = 'contentsync_review_deny';

		contentSync.checkUnsavedChanges();
		contentSync.overlay.confirm(
			action,
			'',
			contentSync.reviews.reviewDeny,
			[ postId, reviewId ]
		);
	};

	this.reviewDeny = function ( postId, reviewId ) {

		console.log( 'reviewDeny', postId, reviewId );

		const action = 'contentsync_review_deny';

		const message = document.getElementById( 'review_message_deny' ).value;

		contentSync.ajax( action, {
			'review_id': reviewId,
			'post_id': postId,
			'message': message
		} );
	};

	/**
	 * Revert post review
	 */
	this.openReviewRevert = function ( elem, postId, reviewId ) {

		var action = 'contentsync_review_revert';

		contentSync.checkUnsavedChanges();
		contentSync.overlay.confirm( action, '', contentSync.revertReview, [ postId, reviewId ] );
	};

	this.revertReview = function ( postId, reviewId ) {
		
		console.log( 'revertReview', postId, reviewId );

		const action = 'contentsync_review_revert';

		const message = document.getElementById( 'review_message_revert' ).value;

		contentSync.ajax( action, {
			'review_id': reviewId,
			'post_id': postId,
			'message': message
		} );

	};
};