/**
 * Greyd Theme Global Styles.
 * 
 * This style is loaded in the editor.
 */
( function ( wp ) {

	if ( !_.has( wp, 'editSite' ) ) return null;

	var { createElement: el } = wp.element;
	var { __, _n, sprintf } = wp.i18n;

	/**
	 * Wrap in a render function.
	 */
	const greydGlobalContent = function () {

		/**
		 * Post reference.
		 * not an ID, but in the form of theme-name//template-name, e. g. greyd-wp//home
		 */
		let lastPostReference = null;
		[ contentsync.editor.postReference, contentsync.editor.setPostReference ] = wp.element.useState( null );
		
		/**
		 * Contentsync post data.
		 */
		[ contentsync.editor.data, contentsync.editor.setData ] = wp.element.useState( {
			postReference: null,
			post: {
				id: -1, // -1 = loading, 0 = no post found, > 0 = post found
				title: '',
				gid: '',
				status: '',
			},
			similarPosts: null,
			options: {
				append_nested: true,
				resolve_menus: false,
				whole_posttype: false,
				translations: false
			},
			canonicalUrl: '',
			showEditOptions: false
		} );

		[ optionsChanged, setOptionsChanged ] = wp.element.useState( false );

		/**
		 * Subscribe to when the post reference changes.
		 */
		wp.data.subscribe( () => {

			const currentPostReference = wp.data.select( 'core/editor' ).getCurrentPostId();
			if (
				currentPostReference !== null
				&& currentPostReference !== contentsync.editor.postReference
				&& currentPostReference !== lastPostReference
			) {

				lastPostReference = currentPostReference;
				contentsync.editor.setPostReference( currentPostReference ); // triggers a re-render
			}
		}, [ 'core/editor' ] );

		/**
		 * If reference is set, get post data.
		 */
		if ( contentsync.editor.postReference !== null ) {
			contentsync.editor.getData( contentsync.editor.postReference );
		}

		/**
		 * Vars
		 */
		const {
			post = {
				id: -1,
				title: '',
				gid: '',
				status: ''
			},
			similarPosts
		} = contentsync.editor.data;

		const hasUnsolvedError = post?.error && !post.error?.repaired;


		/**
		 * Rendering
		 */

		// build post connection list
		let postConnectionCount = 0;
		let postConnectionList = null;
		if ( post?.connectionMap && Object.keys( post.connectionMap ).length > 0 ) {
			postConnectionList = el( 'ul', {
				className: 'contentsync_box_list'
			}, Object.keys( post.connectionMap ).map( ( blogId ) => {
				const postConnection = post.connectionMap[ blogId ];
				// is numeric?
				if ( !isNaN( blogId ) ) {
					postConnectionCount++;

					return el( 'li', {}, [
						postConnection?.nice + ' (',
						el( 'a', {
							href: postConnection?.edit,
							target: '_blank'
						}, __( 'To the post', 'contentsync' ) ),
						')'
					] );
				}
				else {
					return Object.keys( postConnection ).map( ( remoteBlogId ) => {
						postConnectionCount++;
						const remotePostConnection = postConnection[ remoteBlogId ];

						return el( 'li', {}, [
							remotePostConnection?.nice + ' (',
							el( 'a', {
								href: remotePostConnection?.edit,
								target: '_blank'
							}, __( 'To the post', 'contentsync' ) ),
							')'
						] );
					} );
				}
			} ) );
		}

		return el( wp.components.PanelBody, {}, [

			// -1 = loading spinner (default)
			post.id < 0 && el( wp.components.Spinner ),

			// 0 = no post found
			post.id === 0 && el( 'p', {}, __( 'No post found.', 'contentsync' ) ),

			// > 0 = post data found
			post.id > 0 && [

				// not a synced post
				_.isEmpty( post.status ) && [
					post.currentUserCan && [
						el( 'p', {}, __( 'Do you want this post to be available throughout all connected sites?', 'contentsync' ) ),
						el( wp.components.Button, {
							isPrimary: true,
							onClick: function( e ) {
								contentsync.exportPost( e.target, post.id, post.title );
							}
						}, __( 'Convert to global content', 'contentsync' ) ),

						el( 'hr' )
					],

					// find similar posts
					similarPosts === null && el( wp.components.Button, {
						isSecondary: true,
						onClick: () => {
							contentsync.editor.getSimilarPosts( post.id );
						},
						'data-post-id': post.id
					}, __( 'Find similar posts', 'contentsync' ) ),

					// display similar posts
					similarPosts !== null && [

						similarPosts.length === 0 && el( 'p', {}, __( 'No similar posts found.', 'contentsync' ) ),
		
						similarPosts.length > 0 && [

							el( 'p', {}, __( 'Similar posts are available globally:', 'contentsync' ) ),
							el(
								'ul', {
									className: 'contentsync_box_list'
								},
								similarPosts.map( ( similarPost ) => {
									return el( 'li', {}, [
										el( 'span', { className: 'flex' }, [
											el( 'a', {
												href: similarPost.post_links.edit,
												target: '_blank'
											}, similarPost.post_title ),
											el( 'span', {
												className: 'button button-ghost tiny',
												onClick: function( e ) {
													contentsync.overwritePost( e.target );
												},
												'data-post_id': post.id,
												'data-gid': similarPost.meta.synced_post_id
											}, __( 'Use', 'contentsync' ) )
										] ),
										el( 'small', {}, similarPost.post_links.nice )
									] );
								} )
							)
						]
					]
				],

				// error
				hasUnsolvedError && [
					contentsync.editor.renderStatusBox( 'error', post.error?.message ),
					post.currentUserCan && el( wp.components.Button, {
						isSecondary: true,
						onClick: function( e ) {
							contentsync.repairPost( e.target, post.id );
						}
					}, __( 'Repair', 'contentsync' ) )
				],

				// root post
				( 'root' === post.status && !hasUnsolvedError ) && [

					contentsync.editor.renderStatusBox( post.status, __( 'Root post', 'contentsync' ) ),

					// Content Sync Options
					post.currentUserCan && el( 'div', { className: 'contentsync-options-section' }, [
						// // Options header
						// el( 'h3', { 
						// 	className: 'contentsync-options-title'
						// }, __( 'Options', 'contentsync' ) ),

						// Options list
						el( 'div', { className: 'contentsync-options-list' }, [

							// Global Canonical URL
							el( 'div', { 
								className: 'contentsync-canonical-section'
							}, [
								el( 'label', { 
									className: 'contentsync-canonical-label'
								}, __( 'Global Canonical URL', 'contentsync' ) ),
								el( 'input', {
									type: 'text',
									value: contentsync.editor.data.canonicalUrl,
									onChange: ( e ) => {
										setOptionsChanged( true );
										contentsync.editor.setData( prev => ( {
											...prev,
											canonicalUrl: e.target.value
										} ) );
									},
									className: 'contentsync-canonical-input'
								} )
							] ),

							// Dynamic options based on available options
							post?.availableOptions && Object.keys( post.availableOptions ).map( ( key ) => {
								const option = post.availableOptions[ key ];
								const optionValue = contentsync.editor.data.options[ option.name ] || false;
								
								return el( 'div', { 
									key: option.name,
									className: 'contentsync-option-item'
								}, [
									el( 'label', { 
										className: 'contentsync-option-label'
									}, [
										el( 'input', {
											type: 'checkbox',
											checked: optionValue,
											onChange: ( e ) => {
												setOptionsChanged( true );
												contentsync.editor.setData( prev => ( {
													...prev,
													options: {
														...prev.options,
														[ option.name ]: e.target.checked
													}
												} ) );
											},
											className: 'contentsync-option-checkbox'
										} ),
										el( 'div', { 
											className: 'contentsync-option-content'
										}, [
											el( 'div', { 
												className: 'contentsync-option-title'
											}, option.title ),
											option.descr && el( 'div', { 
												className: 'contentsync-option-description'
											}, option.descr )
										] )
									] )
								] );
							} ),

							// Save button
							optionsChanged && el( 'div', { 
								className: 'contentsync-save-button-container'
							}, [
								el( wp.components.Button, {
									isPrimary: true,
									onClick: () => {
										// setOptionsChanged( false );
										contentsync.editor.saveOptions( post.id, contentsync.editor.data.options, contentsync.editor.data.canonicalUrl );
									},
									className: 'contentsync-save-button'
								}, __( 'Save Options', 'contentsync' ) )
							] )
						] )
					] ),

					// post connections
					(
						postConnectionCount === 0
							? el( 'p', {}, __( 'This post has not been published to other sites yet.', 'contentsync' ) )
							: [
								el( 'p', {}, sprintf(
									_n(
										'This post is in use on 1 other site.',
										'This post is in use on %s other sites.',
										postConnectionCount,
										'contentsync'
									),
									postConnectionCount
								) ),
								postConnectionList,
							]
					),

					// cluster
					post?.cluster && post?.cluster?.length > 0 && [
						el( 'p', {}, __( 'This post is part of a cluster.', 'contentsync' ) ),
						el( 'ul', {
							className: 'contentsync_box_list'
						}, post.cluster.map( ( cluster ) => {
							return el( 'li', {}, [
								el( 'strong', {}, cluster.title ),
								el( 'ul', {
									style: {
										margin: '12px 0 0 4px'
									}
								}, cluster.destination_ids.map( ( destination ) => {
									if ( !destination ) return null;

									return el( 'li', {}, [
										sprintf( __( 'Site: %s', 'contentsync' ), '' ),
										el( 'a', {
											href: destination?.site_url,
											target: '_blank'
										}, destination?.blogname )
									] );
								} ) )
							] );
						} ) )
					],

					post.currentUserCan && el( 'div', { className: 'contentsync-gray-box' }, [
						el( 'p', {}, __( 'No longer make this post available globally?', 'contentsync' ) ),
						el( wp.components.Button, {
							// className: 'button button-ghost',
							isSecondary: true,
							onClick: function( e ) {
								contentsync.unexportPost( e.target, post.gid );
							},
						}, __( 'Unlink', 'contentsync' ) )
					] )
				],

				// linked post
				( 'linked' === post.status && !hasUnsolvedError ) && [

					contentsync.editor.renderStatusBox( post.status, __( 'Linked post', 'contentsync' ) ),

					el( 'p', {}, [
						sprintf(
							__( 'This post is synced from the site %s', 'contentsync' ),
							''
						),
						el( 'strong', {}, post?.links?.nice )
					] ),

					post?.canonical?.length > 0 && el( 'p', {}, [
						sprintf(
							__( 'The canonical URL of this post was also set in the source post: %s', 'contentsync' ),
							''
						),
						el( 'code', { style: { 'word-break': 'break-word' } }, post?.canonical )
					] ),

					post.currentUserCan && el( 'p', {}, [
						el( 'a', {
							href: post?.links?.edit,
							target: '_blank'
						}, __( 'Go to the original post', 'contentsync' ) )
					] ),

					post.currentUserCan && el( 'div', { className: 'contentsync-gray-box' }, [
						el( 'p', {}, __( 'Edit this post?', 'contentsync' ) ),
						el( wp.components.Button, {
							className: 'contentsync-action-button',
							isSecondary: true,
							onClick: function( e ) {
								contentsync.unimportPost( e.target, post.id );
							}
						}, __( 'Convert to local post', 'contentsync' ) )
					] )
				],

				// // debug info
				// el( 'hr' ),
				// el( 'p', {}, __( 'Status: ' + post.status, 'contentsync' ) ),
				// el( 'p', {}, __( 'Global ID: ' + post.gid, 'contentsync' ) ),
				// el( 'p', {}, __( 'Post ID: ' + post.id, 'contentsync' ) ),
			]
		] );
	};

	/**
	 * Register as plugin.
	 */
	wp.plugins.registerPlugin( 'greyd-global-content', {
		render: function () {
			return el(
				wp.editor?.PluginSidebar ?? wp.editSite.PluginSidebar,
				{
					name: 'greyd-global-content',
					icon: el( wp.components.Icon, {
						icon: () => {
							return el( 'svg', {
								width: '24',
								height: '24',
								viewBox: '0 0 24 24',
								fill: 'none',
								xmlns: 'http://www.w3.org/2000/svg'
							}, [
								el( 'path', {
									d: 'M12.0387 3.12854C9.67558 3.12854 7.40929 4.06727 5.73834 5.73822C4.06739 7.40917 3.12866 9.67546 3.12866 12.0385C3.12866 14.4017 4.06739 16.6679 5.73834 18.3389C7.40929 20.0098 9.67558 20.9486 12.0387 20.9486C14.4018 20.9486 16.668 20.0098 18.339 18.3389C20.0099 16.6679 20.9487 14.4017 20.9487 12.0385C20.9487 9.67546 20.0099 7.40917 18.339 5.73822C16.668 4.06727 14.4018 3.12854 12.0387 3.12854ZM19.4637 9.54374C19.3295 9.98277 19.1065 10.3895 18.8085 10.7387C18.5104 11.0878 18.1437 11.3719 17.7312 11.5732C17.5114 10.7481 17.1053 9.98432 16.544 9.34072C15.9828 8.69711 15.2814 8.1908 14.4939 7.86074C14.622 7.42147 14.8984 7.04016 15.276 6.78164C14.8503 6.50444 14.286 6.36584 13.9494 6.85094C13.4247 7.53404 13.9494 8.44484 14.1573 8.83094V8.96954C13.6076 8.63613 13.1778 8.13702 12.9297 7.54394C11.9731 7.51322 11.0269 7.74977 10.1973 8.22704C10.1111 7.66809 10.1656 7.09649 10.3557 6.56384C10.7132 6.59797 11.0736 6.54357 11.4051 6.40544C11.7366 6.26732 12.029 6.04971 12.2565 5.77184C12.7119 5.25704 12.1278 4.60364 11.6724 4.20764H12.0288C13.3765 4.19855 14.7036 4.53969 15.8799 5.19764C16.5447 5.68903 17.0915 6.32265 17.4803 7.05228C17.8691 7.7819 18.0901 8.58912 18.1272 9.41504C18.3648 9.41504 18.8202 8.87054 19.0281 8.50424C19.1984 8.83985 19.3439 9.18734 19.4637 9.54374ZM12.0387 19.8002C10.0092 17.741 12.2862 16.0877 11.0487 14.6126C10.1379 13.7711 8.78156 14.3552 7.96976 13.3949C7.83272 12.6789 7.89196 11.9393 8.1412 11.2543C8.39044 10.5693 8.82039 9.96458 9.38546 9.50414C9.90026 9.06854 13.3455 8.51414 14.7513 9.72194C15.5735 10.4301 16.1519 11.3792 16.4046 12.4345C16.8589 12.4689 17.3133 12.369 17.7114 12.1474C18.1173 15.0977 14.5929 18.8201 12.0387 19.8002ZM8.22716 5.19764C8.60552 5.05339 9.02116 5.03931 9.40841 5.15764C9.79566 5.27596 10.1325 5.51995 10.3656 5.85104C9.94976 6.22724 9.43496 6.47474 8.88056 6.56384C8.90103 6.27233 8.96442 5.98544 9.06866 5.71244L8.22716 5.19764Z'
								} )
							] );
						}
					} ),
					title: __( 'Content Sync', 'contentsync' )
				},
				greydGlobalContent()
			);
		},
	} );

} )(
	window.wp,
	greyd.components
);
