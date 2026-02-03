var contentSync = contentSync || {};

contentSync.blockEditorTools = new (function () {
	/** REST base path for editor endpoints (localized via contentSyncEditorData.restBasePath). */
	const editorRestBasePath =
		typeof contentSyncEditorData !== 'undefined' && contentSyncEditorData.restBasePath
			? contentSyncEditorData.restBasePath
			: 'contentsync/v1/admin';

	/**
	 * fallback for setData
	 */
	this.setData = (data) => {
		console.log('setData not initialized via useState()', data);
	};

	this.data = {
		postReference: null,
		post: {
			id: -1,
			title: '',
			gid: '',
			status: '',
		},
		similarPosts: null,
	};

	/**
	 * Set Notice based on response.
	 *
	 * @param {Array} notice
	 */
	this.setNotice = (notice) => {
		if (notice && notice.length > 0) {
			wp.data.dispatch('core/notices').removeNotices(
				wp.data
					.select('core/notices')
					.getNotices()
					.map((n) => n.id)
			);

			// convert action onClick strings into function
			notice[2]?.actions.forEach((action) => {
				if (typeof action.onClick === 'string') {
					action.onClick = new Function(action.onClick);
				}
			});

			wp.data.dispatch('core/notices').createNotice(notice[0], notice[1], notice[2]);
		}
		// remove all notices
		else {
			wp.data.dispatch('core/notices').removeNotices(
				wp.data
					.select('core/notices')
					.getNotices()
					.map((n) => n.id)
			);
		}
	};

	/**
	 * Set Post Data
	 */
	this.getData = (postReference, forceReload) => {
		if (typeof forceReload === 'undefined' || !forceReload) {
			if (
				postReference === null ||
				contentSync.blockEditorTools?.data?.postReference === postReference
			) {
				return;
			}
		}

		if (typeof wp?.apiFetch === 'undefined') {
			console.error('wp.apiFetch not defined');

			return;
		}

		wp.apiFetch({
			path: '/' + editorRestBasePath + '/editor/get-post-data',
			method: 'POST',
			data: {
				postReference: postReference,
			},
		})
			.then((response) => {
				// wp.apiFetch returns parsed JSON; response is { status, message, data }

				if (response?.status === 200) {
					if (response?.data?.post?.status === 'linked') {
						document.body.classList.add('contentsync-locked');
					} else {
						document.body.classList.remove('contentsync-locked');
					}

					contentSync.blockEditorTools.setNotice(response?.data?.notice);
					console.log('setData:', response?.data);
					contentSync.blockEditorTools.setData({
						postReference: postReference,
						post: response?.data?.post,
						similarPosts: null,
						options: response?.data?.post?.options || {
							append_nested: true,
							resolve_menus: false,
							translations: false,
						},
						canonicalUrl: response?.data?.post?.canonicalUrl || '',
						showEditOptions: false,
					});
				} else {
					document.body.classList.remove('contentsync-locked');
					contentSync.blockEditorTools.setNotice([]);
					contentSync.blockEditorTools.setData({
						postReference: postReference,
						post: {
							id: 0,
							title: '',
							gid: '',
							status: '',
						},
						similarPosts: null,
						options: {
							append_nested: true,
							resolve_menus: false,
							translations: false,
						},
						canonicalUrl: '',
						showEditOptions: false,
					});
				}
			})
			.catch((err) => {
				document.body.classList.remove('contentsync-locked');
				console.error('apiFetch error: ', err);
				contentSync.blockEditorTools.setData({
					postReference: postReference,
					post: {
						id: 0,
						title: '',
						gid: '',
						status: '',
					},
					similarPosts: null,
					options: {
						append_nested: true,
						resolve_menus: false,
						translations: false,
					},
					canonicalUrl: '',
					showEditOptions: false,
				});
			});
	};

	/**
	 * Save Contentsync options
	 *
	 * @param {int} post_id
	 * @param {object} options
	 * @param {string} canonical_url
	 */
	this.saveOptions = function (post_id, options, canonical_url) {
		if (typeof wp?.apiFetch === 'undefined') {
			console.error('wp.apiFetch not defined');

			return;
		}

		wp.apiFetch({
			path: '/' + editorRestBasePath + '/editor/save-options',
			method: 'POST',
			data: {
				post_id: post_id,
				options: options,
				canonical_url: canonical_url,
			},
		})
			.then((response) => {
				// wp.apiFetch returns parsed JSON; response is { status, message, data }
				console.log('saveOptions response:', response);

				if (response?.status === 200) {
					greyd?.tools?.showSnackbar('Options saved successfully', 'success', true);
				} else {
					greyd?.tools?.showSnackbar(
						response?.message || 'Failed to save options',
						'error',
						true
					);
				}
			})
			.catch((err) => {
				console.error('saveOptions error: ', err);
				greyd?.tools?.showSnackbar('Failed to save options', 'error', true);
			});
	};

	/**
	 * Get similar posts
	 *
	 * @param {int} post_id
	 * @param {function} callback
	 */
	this.getSimilarPosts = function (postId) {
		$.post(
			greyd.ajax_url,
			{
				action: 'contentsync_ajax',
				_ajax_nonce: greyd.nonce,
				mode: 'global_action',
				data: {
					action: 'contentsync_similar_posts',
					post_id: postId,
				},
			},
			function (response) {
				// console.log(response);

				let similarPosts = [];

				// successfull
				if (response.indexOf('success::') > -1) {
					try {
						similarPosts = Object.values(JSON.parse(response.split('success::')[1]));
					} catch (e) {
						console.error(e);

						return;
					}
				}

				contentSync.blockEditorTools.setData({
					...contentSync.blockEditorTools.data,
					similarPosts: similarPosts,
				});
			}
		);
	};

	const ExportIcon = () => (
		<svg
			width="24"
			height="24"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
		>
			<path
				d="M21 4.15431V12.8116C21 13.3166 20.7655 13.6713 20.2966 13.8758C20.1403 13.9359 19.99 13.9659 19.8457 13.9659C19.521 13.9659 19.2505 13.8517 19.0341 13.6232L16.4369 11.0261L6.80561 20.6573C6.57715 20.8858 6.30661 21 5.99399 21C5.68136 21 5.41082 20.8858 5.18237 20.6573L3.34269 18.8176C3.11423 18.5892 3 18.3186 3 18.006C3 17.6934 3.11423 17.4228 3.34269 17.1944L12.9739 7.56313L10.3768 4.96593C10.004 4.61723 9.91984 4.19639 10.1242 3.70341C10.3287 3.23447 10.6834 3 11.1884 3H19.8457C20.1583 3 20.4289 3.11423 20.6573 3.34269C20.8858 3.57114 21 3.84168 21 4.15431Z"
				fill="currentColor"
			/>
		</svg>
	);

	const ImportIcon = () => (
		<svg
			width="24"
			height="24"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
		>
			<path
				d="M14.7583 9.24184C17.0922 11.5781 17.0601 15.3238 14.7724 17.6242C14.7681 17.6289 14.763 17.634 14.7583 17.6387L12.1333 20.2637C9.81807 22.5789 6.05131 22.5786 3.73643 20.2637C1.42119 17.9489 1.42119 14.1817 3.73643 11.8668L5.18588 10.4174C5.57026 10.033 6.23221 10.2885 6.25205 10.8317C6.27736 11.5239 6.40151 12.2195 6.63057 12.8911C6.70815 13.1185 6.65272 13.3701 6.48279 13.54L5.97158 14.0512C4.87682 15.146 4.84248 16.9285 5.92647 18.034C7.02115 19.1504 8.82045 19.157 9.92354 18.0539L12.5485 15.4293C13.6497 14.3281 13.6451 12.5482 12.5485 11.4516C12.404 11.3073 12.2583 11.1952 12.1446 11.1169C12.0641 11.0616 11.9977 10.9883 11.9506 10.9028C11.9034 10.8173 11.877 10.722 11.8732 10.6245C11.8578 10.2117 12.004 9.78633 12.3302 9.46016L13.1526 8.6377C13.3683 8.42204 13.7066 8.39555 13.9567 8.57009C14.2431 8.77007 14.5113 8.99485 14.7583 9.24184V9.24184ZM20.2636 3.73631C17.9487 1.42139 14.1819 1.42108 11.8667 3.73631L9.2417 6.3613C9.23701 6.36599 9.23194 6.37107 9.22764 6.37575C6.9399 8.67622 6.90783 12.4219 9.2417 14.7582C9.48869 15.0051 9.75692 15.2299 10.0433 15.4299C10.2934 15.6044 10.6317 15.5779 10.8474 15.3623L11.6698 14.5398C11.996 14.2136 12.1422 13.7883 12.1267 13.3755C12.123 13.278 12.0965 13.1826 12.0494 13.0971C12.0023 13.0116 11.9358 12.9383 11.8554 12.8831C11.7416 12.8048 11.596 12.6927 11.4514 12.5484C10.3548 11.4518 10.3502 9.67184 11.4514 8.57063L14.0764 5.94603C15.1795 4.84294 16.9788 4.84958 18.0735 5.96595C19.1575 7.07142 19.1232 8.85399 18.0284 9.94875L17.5172 10.46C17.3473 10.6299 17.2918 10.8814 17.3694 11.1089C17.5985 11.7805 17.7226 12.476 17.7479 13.1683C17.7678 13.7115 18.4297 13.9669 18.8141 13.5826L20.2635 12.1331C22.5788 9.81832 22.5788 6.05114 20.2636 3.73631V3.73631Z"
				fill="currentColor"
			/>
		</svg>
	);

	const ErrorIcon = () => (
		<svg
			width="24"
			height="24"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
		>
			<path
				d="M12 21C14.3869 21 16.6761 20.0518 18.364 18.364C20.0518 16.6761 21 14.3869 21 12C21 9.61305 20.0518 7.32387 18.364 5.63604C16.6761 3.94821 14.3869 3 12 3C9.61305 3 7.32387 3.94821 5.63604 5.63604C3.94821 7.32387 3 9.61305 3 12C3 14.3869 3.94821 16.6761 5.63604 18.364C7.32387 20.0518 9.61305 21 12 21V21ZM10.65 16.05C10.65 15.692 10.7922 15.3486 11.0454 15.0954C11.2986 14.8422 11.642 14.7 12 14.7C12.358 14.7 12.7014 14.8422 12.9546 15.0954C13.2078 15.3486 13.35 15.692 13.35 16.05C13.35 16.408 13.2078 16.7514 12.9546 17.0046C12.7014 17.2578 12.358 17.4 12 17.4C11.642 17.4 11.2986 17.2578 11.0454 17.0046C10.7922 16.7514 10.65 16.408 10.65 16.05ZM11.1144 7.338C11.152 7.13049 11.2612 6.94277 11.4231 6.80759C11.5849 6.6724 11.7891 6.59835 12 6.59835C12.2109 6.59835 12.4151 6.6724 12.5769 6.80759C12.7388 6.94277 12.848 7.13049 12.8856 7.338L12.9 7.5V12L12.8856 12.162C12.848 12.3695 12.7388 12.5572 12.5769 12.6924C12.4151 12.8276 12.2109 12.9016 12 12.9016C11.7891 12.9016 11.5849 12.8276 11.4231 12.6924C11.2612 12.5572 11.152 12.3695 11.1144 12.162L11.1 12V7.5L11.1144 7.338Z"
				fill="currentColor"
			/>
		</svg>
	);

	this.renderStatusBox = function (status, text) {
		const { __ } = wp.i18n;

		status = status === 'root' ? 'export' : status === 'linked' ? 'import' : status;
		const titles = {
			export: __('Root post', 'contentsync'),
			import: __('Linked post', 'contentsync'),
			error: __('Error', 'contentsync'),
		};
		const title = titles[status] ?? status;
		let color = 'red';
		if (status === 'export') {
			color = 'purple';
		} else if (status === 'import') {
			color = 'green';
		} else if (status === 'info') {
			color = 'blue';
		}

		const IconComponent =
			status === 'export' ? ExportIcon : status === 'import' ? ImportIcon : ErrorIcon;

		return (
			<span
				data-title={title}
				className={'contentsync-info-box ' + color + ' contentsync-status'}
			>
				<IconComponent />
				{text ? <span>{text}</span> : ''}
			</span>
		);
	};
})();
