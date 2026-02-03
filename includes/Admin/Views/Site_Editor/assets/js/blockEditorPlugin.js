/**
 * This script is loaded in the editor.
 */
( function ( wp ) {

	// site editor only
	// if ( !_.has( wp, 'editSite' ) ) return null;

	var { createElement: el } = wp.element;
	var { __, _n, sprintf } = wp.i18n;

	const renderPlugin = function () {

		/**
		 * Post reference.
		 * not an ID, but in the form of theme-name//template-name, e. g. greyd-wp//home
		 */
		let lastPostReference = null;
		[ contentSync.blockEditorTools.postReference, contentSync.blockEditorTools.setPostReference ] = wp.element.useState( null );
		
		/**
		 * Contentsync post data.
		 */
		[ contentSync.blockEditorTools.data, contentSync.blockEditorTools.setData ] = wp.element.useState( {
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
				&& currentPostReference !== contentSync.blockEditorTools.postReference
				&& currentPostReference !== lastPostReference
			) {

				lastPostReference = currentPostReference;
				contentSync.blockEditorTools.setPostReference( currentPostReference ); // triggers a re-render
			}
		}, [ 'core/editor' ] );

		/**
		 * If reference is set, get post data.
		 */
		if ( contentSync.blockEditorTools.postReference !== null ) {
			contentSync.blockEditorTools.getData( contentSync.blockEditorTools.postReference );
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
		} = contentSync.blockEditorTools.data;

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
								contentSync.exportPost( e.target, post.id, post.title );
							}
						}, __( 'Convert to synced post', 'contentsync' ) ),

						el( 'hr' )
					],

					// find similar posts
					similarPosts === null && el( wp.components.Button, {
						isSecondary: true,
						onClick: () => {
							contentSync.blockEditorTools.getSimilarPosts( post.id );
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
													contentSync.overwritePost( e.target );
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
					contentSync.blockEditorTools.renderStatusBox( 'error', post.error?.message ),
					post.currentUserCan && el( wp.components.Button, {
						isSecondary: true,
						onClick: function( e ) {
							contentSync.repairPost( e.target, post.id );
						}
					}, __( 'Repair', 'contentsync' ) )
				],

				// root post
				( 'root' === post.status && !hasUnsolvedError ) && [

					contentSync.blockEditorTools.renderStatusBox( post.status, __( 'Root post', 'contentsync' ) ),

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
									value: contentSync.blockEditorTools.data.canonicalUrl,
									onChange: ( e ) => {
										setOptionsChanged( true );
										contentSync.blockEditorTools.setData( prev => ( {
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
								const optionValue = contentSync.blockEditorTools.data.options[ option.name ] || false;
								
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
												contentSync.blockEditorTools.setData( prev => ( {
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
										contentSync.blockEditorTools.saveOptions( post.id, contentSync.blockEditorTools.data.options, contentSync.blockEditorTools.data.canonicalUrl );
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
								contentSync.unexportPost( e.target, post.gid );
							},
						}, __( 'Unlink', 'contentsync' ) )
					] )
				],

				// linked post
				( 'linked' === post.status && !hasUnsolvedError ) && [

					contentSync.blockEditorTools.renderStatusBox( post.status, __( 'Linked post', 'contentsync' ) ),

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
								contentSync.unimportPost( e.target, post.id );
							}
						}, __( 'Convert to local post', 'contentsync' ) )
					] )
				],

				// debug info
				el( 'hr' ),
				el( 'p', {}, __( 'Status: ' + post.status, 'contentsync' ) ),
				el( 'p', {}, __( 'Global ID: ' + post.gid, 'contentsync' ) ),
				el( 'p', {}, __( 'Post ID: ' + post.id, 'contentsync' ) ),
			]
		] );
	};

	/**
	 * Register as plugin.
	 */
	wp.plugins.registerPlugin( 'contentsync', {
		render: function () {
			return el(
				wp.editor?.PluginSidebar ?? wp.editSite.PluginSidebar,
				{
					name: 'contentsync',
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
									d: 'M14.9361 10.9003C14.6902 10.8235 14.4289 10.9545 14.344 11.1884L13.5386 13.4088C13.4498 13.6538 13.5872 13.9194 13.838 14.0174C13.8784 14.0332 13.918 14.0491 13.9569 14.0651C14.4191 14.2532 14.7687 14.4814 15.0057 14.7493C15.2487 15.0172 15.3702 15.3479 15.3702 15.7412C15.3701 16.163 15.2369 16.5365 14.9702 16.8614C14.7035 17.1863 14.3272 17.4401 13.8413 17.6225C13.3613 17.8049 12.7953 17.896 12.1435 17.896C11.4798 17.896 10.8841 17.7992 10.3567 17.6054C9.83526 17.4059 9.4173 17.1123 9.10321 16.7246C9.1007 16.7214 9.09832 16.718 9.09582 16.7148L9.74869 16.3539C10.0051 16.2121 10.1469 15.9362 10.1084 15.6551C10.0697 15.3739 9.85811 15.1425 9.57207 15.0691L5.90443 14.1279C5.71687 14.0797 5.51698 14.105 5.34881 14.198C5.18071 14.2909 5.058 14.4441 5.00772 14.6237L4.02496 18.1364C3.94837 18.4103 4.05151 18.7018 4.28644 18.8744C4.52137 19.047 4.84187 19.0671 5.09829 18.9253L5.86152 18.503C6.46734 19.3144 7.29606 19.9302 8.34761 20.3503C9.43801 20.7835 10.7182 21 12.1878 21C13.6693 21 14.9348 20.7863 15.9837 20.3587C17.0385 19.9255 17.8446 19.3184 18.4016 18.5375C18.9646 17.7508 19.249 16.8215 19.2549 15.7498C19.249 15.0202 19.1097 14.3731 18.8371 13.8087C18.5704 13.2444 18.1941 12.754 17.7082 12.3379C17.2223 11.9217 16.6474 11.5712 15.9837 11.2861C15.6509 11.1432 15.3017 11.0146 14.9361 10.9003ZM12.2324 3C10.905 3.00001 9.71976 3.22244 8.67679 3.66705C7.63379 4.1117 6.81284 4.7302 6.21429 5.52257C5.62169 6.31496 5.32835 7.24143 5.33427 8.30175C5.32836 9.59577 5.76992 10.6248 6.65884 11.3886C7.34585 11.979 8.22583 12.4486 9.29867 12.7972C9.53395 12.8736 9.78782 12.7577 9.88455 12.5385L10.8485 10.3545C10.9544 10.1143 10.8331 9.8408 10.5858 9.73174C10.5505 9.71618 10.5203 9.702 10.4983 9.69087C10.3629 9.6251 10.2356 9.55547 10.1167 9.48176C9.85006 9.31075 9.63962 9.10822 9.48554 8.87452C9.33743 8.64081 9.26942 8.36709 9.28127 8.0536C9.28128 7.67738 9.39384 7.34098 9.61902 7.04457C9.85014 6.74818 10.1821 6.51724 10.6146 6.35194C11.0472 6.18097 11.5776 6.09558 12.2057 6.09558C13.1301 6.09559 13.8622 6.28642 14.4015 6.66835C14.6059 6.81313 14.7766 6.98021 14.9137 7.16921L14.2513 7.53561C13.9949 7.67744 13.853 7.95325 13.8916 8.2344C13.9303 8.51554 14.1419 8.74676 14.4279 8.82019L18.0956 9.76141C18.2831 9.80952 18.483 9.78431 18.6512 9.69133C18.8193 9.59833 18.942 9.44519 18.9923 9.26557L19.975 5.75292C20.0517 5.47898 19.9484 5.18777 19.7135 5.01509C19.4786 4.84249 19.1581 4.82242 18.9017 4.96418L18.0643 5.42692C17.5067 4.68046 16.7424 4.09357 15.7704 3.66705C14.7629 3.22241 13.5835 3.00001 12.2324 3Z'
								} )
							] );
						}
					} ),
					title: __( 'Content Sync', 'contentsync' )
				},
				renderPlugin()
			);
		},
	} );

} )(
	window.wp
);
