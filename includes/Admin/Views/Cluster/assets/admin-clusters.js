/**
 * Admin features for the 'cluster' feature
 */
var contentSync = contentSync || {};

contentSync.clusters = new ( function () {
	this.debug = true;

	this.init = () => {
		this.wizard.init();
		this.multiselect.init();
		this.conditions.init();
		this.deletionOverlay.init();
		this.checkPostTypes.init();
	};

	this.wizard = new ( function () {
		this.debug = true;

		this.init = () => {
			// check if url contents page=contentsync_cluster

			const { __ } = wp.i18n;

			if ( location.href.indexOf( 'admin.php?page=contentsync_clusters' ) === -1 ) return;

			contentSync.overlay.addPageTitleAction( __( 'Add Cluster', 'contentsync' ), {
				onclick: 'contentSync.clusters.wizard.openWizard()',
			} );

			const wizard = document.querySelector( '#greyd-wizard.cluster_wizard' );
			if ( !wizard ) return;

			wizard.querySelector( '.button.create' ).addEventListener( 'click', ( e ) => {
				const clusterID = this.createCluster(
					wizard.querySelector( 'input[name="create_cluster"]' ).value
				).then( ( clusterID ) => {
					if ( clusterID > 0 ) {
						const url = location.href.split( 'wp-admin' );
						const editURL =
							url[ 0 ] +
							contentsync_admin.admin_url_base +
							'?page=contentsync_clusters&cluster_id=' +
							clusterID;
						wizard.querySelector( '.finish_wizard' ).setAttribute( 'href', editURL );
					} else {
						greyd.wizard.setState( 0 );
					}
				} );
			} );
		};

		this.openWizard = () => {
			greyd.wizard.initnew();
			greyd.wizard.setState( 1 );
		};

		this.createCluster = async ( title ) => {
			const body = new FormData();
			const data = {
				title: title,
			};

			// append the necessary data for the admin ajax call
			body.append( 'action', 'contentsync_create_cluster' );
			body.append( '_ajax_nonce', greyd.nonce ?? wizzard_details.nonce );
			body.append( 'data', JSON.stringify( data ) );

			const clusterID = await fetch( greyd.ajax_url, {
				method: 'POST',
				body: body,
				// credentials: 'same-origin',
			} )
				.then( ( response ) => response.json() )
				.then( ( data ) => {
					if ( data ) {
						if ( this.debug ) console.log( data );
						if ( data.success && data.data.cluster_id > 0 ) {
							greyd.wizard.setState( 11 );

							return data.data.cluster_id;
						}
					}
				} )
				.catch( ( error ) => {
					if ( this.debug ) console.log( error );
				} );

			return clusterID;
		};
	} )();

	this.deletionOverlay = new ( function () {
		this.debug = false;

		this.init = () => {
			if ( location.href.indexOf( 'admin.php?page=contentsync_clusters' ) === -1 ) return;

			// add events
			const deleteButtons = document.querySelectorAll( '.row-actions .delete' );
			deleteButtons.forEach( ( button ) => {
				button.addEventListener( 'click', ( e ) => {
					const title = button.closest( '.title' ).querySelector( '.row-title' );
					contentSync.overlay.confirm(
						'contentsync_delete_cluster',
						title.textContent,
						this.deleteCluster,
						[ button ]
					);
				} );
			} );
		};

		this.getData = ( button ) => {
			const id = button.querySelector( 'a' ).getAttribute( 'data-cluster-id' );

			// get mode from form
			const form = document.querySelector( '#contentsync_cluster_deletion_form' );
			const selectedRadio = form.querySelector(
				'input[name="contentsync_cluster_deletion_mode"]:checked'
			);
			const mode = selectedRadio ? selectedRadio.value : '';

			const data = {
				id: id,
				mode: mode,
			};

			return data;
		};

		this.deleteCluster = ( elem ) => {
			const data = this.getData( elem );

			const body = new FormData();
			body.append( 'action', 'contentsync_delete_cluster' );
			body.append( '_ajax_nonce', greyd.nonce );
			body.append( 'data', JSON.stringify( data ) );

			fetch( greyd.ajax_url, {
				method: 'POST',
				body: body,
				// credentials: 'same-origin',
			} )
				.then( ( response ) => response.json() )
				.then( ( data ) => {
					if ( data ) {
						if ( this.debug ) console.log( data );
						if ( data.success ) {
							contentSync.overlay.triggerOverlay( true, {
								type: 'reload',
								css: 'contentsync_delete_cluster',
							} );
						} else {
							contentSync.overlay.triggerOverlay( true, {
								type: 'fail',
								css: 'contentsync_delete_cluster',
								replace: data,
							} );
						}
					}
				} );
		};
	} )();

	this.multiselect = new ( function () {
		this.init = function () {
			if ( $( '#contentsync_cluster_settings' ).length == 0 ) return;

			// const sites = $( "#contentsync_cluster_settings input[name='cluster_destinations']" );
			// sites.on( "change", this.updateOptions );
			// this.updateOptions();

			$( '.settings_input_option.autotags' ).each( function () {
				contentSync.clusters.multiselect.setAutotags( this );
			} );

			if ( $( '.tag_suggestions' ).length > 0 ) {
				contentSync.clusters.multiselect.initAutotags();
			}
		};

		/**
		 * Handle the autotag inputs.
		 * 
		 * @param {object} el DOM element
		 */
		this.setAutotags = function ( el ) {
			const wrapper = el;

			let tagsData = {};
			let suggestions = '';

			const input = wrapper.querySelector( 'input' );
			if ( !input ) return;
			let data = input.getAttribute( 'data-tags' );

			const value = wrapper.querySelector( 'input' ).value.split( ',' );
			// console.log(value);

			data = JSON.parse( data );

			// loop through the object
			if ( !data ) return;

			$.each( data, function ( key, values ) {
				suggestions += '<span class="tag_suggest">' + key + '</span>';
				$.each( values, function ( id, title ) {
					title = decodeURIComponent( title ).replace( /\+/g, ' ' );
					let display = 'data-available=\'yes\'';
					if ( value.includes( id.toString() ) )
						display = 'data-available=\'no\' style=\'display:none\'';
					suggestions +=
						'<span class="tag_suggest option" data-value="' +
						id +
						'" ' +
						display +
						'>' +
						title +
						'</span>';
					tagsData[ id ] = title;
				} );
			} );

			let tags = '';
			let newValue = [];

			for ( let val of value ) {
				if ( typeof tagsData[ val ] !== 'undefined' ) {
					newValue.push( val );
					const title = decodeURIComponent( tagsData[ val ] ).replace( /\+/g, ' ' );
					tags +=
						'<span class="tag" data-value="' +
						val +
						'">' +
						title +
						'<span class="tag_close dashicons dashicons-no-alt"></span></span>';
				}
			}

			wrapper.querySelector( 'input' ).value = newValue.join( ',' );
			// console.log(tags_data);

			var markup = '<div class="tagarea">';
			markup += '<span class="tags">' + tags + '</span>';
			markup += '<input class="tag_input" type="text" size="3">';
			markup += '<span class="tag_suggestions">' + suggestions + '</span>';
			markup += '</div>';
			wrapper.insertAdjacentHTML( 'beforeend', markup );

			this.initEvents( wrapper );
		};

		this.updateOptions = function ( data, wrapper ) {
			const tags = wrapper.querySelector( '.tags' );
			const input = wrapper.querySelector( '.settings_input_option_value' );
			const suggestions = wrapper.querySelector( '.tag_suggestions' );

			// clear all tags and values
			input.value = '';
			suggestions.innerHTML = '';

			// remove old tag area
			const tagArea = wrapper.querySelector( '.tagarea' );
			if ( tagArea ) {
				tagArea.remove();
			}

			console.log( data );

			input.setAttribute( 'data-tags', JSON.stringify( data ) );

			// set autotags
			this.setAutotags( wrapper );

			input.dispatchEvent( new Event( 'change' ) );
		};

		this.initEvents = function ( wrapper ) {
			$( wrapper )
				.find( '.tagarea' )
				.on( 'click', function () {
					$( this ).find( '.tag_input' ).focus();
				} );
			$( wrapper )
				.find( '.tag_input' )
				.on( 'focus', function () {
					var inputpos = $( this ).offset();
					var inputheight = $( this ).height();
					var parentpos = $( this ).closest( '.settings_input' ).offset();
					$( this )
						.siblings( '.tag_suggestions' )
						.css( 'top', inputpos.top - parentpos.top + inputheight + 'px' );
					$( this )
						.siblings( '.tag_suggestions' )
						.css( 'left', inputpos.left - parentpos.left + 'px' );
					$( this ).siblings( '.tag_suggestions' ).css( 'display', 'block' );

					const filterTableRow = $( this ).get( 0 ).closest( '.filter_table_row' );

					if ( filterTableRow ) {
						// const tagSuggestionHeight = filterTableRow.querySelector('.tag_suggestions').getBoundingClientRect().height;
						// const rowHeight = filterTableRow.getBoundingClientRect().height ;
						// contentSync.clusters.conditions.updateHeight(filterTableRow, rowHeight);

						const sub = filterTableRow.closest( '.sub' );
						const th = sub.querySelector( 'table' ).getBoundingClientRect().height;
						contentSync.clusters.conditions.updateHeight( sub, th );
					}
				} );

			$( wrapper )
				.find( '.tag_input' )
				.on( 'keypress', function ( e ) {
					var redExp = new RegExp( '[a-zA-Z0-9- ]' );
					var key = String.fromCharCode( !event.charCode ? event.which : event.charCode );
					if ( !redExp.test( key ) ) return false;

					return true;
				} );
			$( wrapper )
				.find( '.tag_input' )
				.on( 'keyup', function ( e ) {
					var x = e || window.event;
					var key = x.keyCode || x.which;
					console.log( key );
					switch ( key ) {
					// case 188: // comma
					//     // $(this).val('');
					//     break;
					// case 40:
					// case 38: //down/up arrow
					//     break;
					// case 13: //enter
					//     break;
					case 27: //esc
						$( this ).siblings( '.tag_suggestions' ).css( 'display', 'none' );
						break;
					default:
						var val = $( this ).val().toLowerCase().replace( ' ', '' );
						$( this ).siblings( '.tag_suggestions' ).css( 'display', 'block' );
						$( this )
							.siblings( '.tag_suggestions' )
							.find( '.tag_suggest.option' )
							.each( function () {
								if ( $( this ).data( 'available' ) == 'yes' ) {
									var option = $( this ).html().toLowerCase().replace( ' ', '' );
									option += $( this ).data( 'value' ).toString();
									if ( option.indexOf( val ) > -1 )
										$( this ).css( 'display', 'block' );
									else $( this ).css( 'display', 'none' );
								}
							} );
						$( this ).attr( 'size', $( this ).val().length + 1 ); //increase size
						break;
					}
				} );

			$( wrapper )
				.find( '.tag_suggest.option' )
				.on( 'click', function ( e ) {
					// console.log("add tag");
					// console.log($(this));
					var id = $( this ).data( 'value' );
					var title = decodeURIComponent( $( this ).html() ).replace( /\+/g, ' ' );
					var tags = $( this ).closest( '.autotags' ).find( '.tags' );
					var input = $( this ).closest( '.autotags' ).find( '.settings_input_option_value' );

					var is_cat =
						( typeof id === 'string' && id.indexOf( 'cat_' ) ) === 0
							? theme_wordings.vc.tags.category + ': '
							: '';
					var new_tag =
						'<span class="tag" data-value="' +
						id +
						'">' +
						is_cat +
						title +
						'<span class="tag_close dashicons dashicons-no-alt"></span></span>';
					tags.append( new_tag );
					var new_value = [];
					tags.find( '.tag' ).each( function () {
						new_value.push( $( this ).data( 'value' ) );
					} );
					input.val( new_value.join( ',' ) );
					input.trigger( 'change' );

					$( this ).parent().siblings( '.tag_input' ).val( '' );
					$( this ).parent().siblings( '.tag_input' ).attr( 'size', 3 );
					$( this ).css( 'display', 'none' );
					$( this ).data( 'available', 'no' );
					$( this )
						.siblings( '.tag_suggest.option' )
						.each( function () {
							if ( $( this ).data( 'available' ) == 'yes' ) {
								$( this ).css( 'display', 'block' );
							}
						} );
				} );
		};

		/**
		 * Init all the autotags inputs.
		 */
		this.initAutotags = function () {
			$( document ).on( 'mouseup', function ( e ) {
				var suggestions = $( '.tag_suggestions' );
				if (
					!suggestions.is( e.target ) && // if the target of the click isn't the container...
					suggestions.has( e.target ).length === 0
				) {
					// ... nor a descendant of the container
					suggestions.css( 'display', 'none' );
				}
			} );
			$( document ).on( 'click', '.tag_close', function ( e ) {
				contentSync.clusters.multiselect.tagRemove( this );
			} );

			$( document ).on( 'click', '.tag', function ( e ) {
				$( this ).parent().siblings( '.tag_suggestions' ).css( 'display', 'none' );
			} );
		};

		this.tagRemove = function ( el ) {
			const tag = el.closest( '.tag' );
			const tagsWrapper = el.closest( '.tags' );
			const wrapper = tagsWrapper.closest( '.settings_input_option.autotags' );
			const input = wrapper.querySelector( '.settings_input_option_value' );

			const newValue = [];

			tag.remove();
			const tags = tagsWrapper.querySelectorAll( '.tag' );
			tags.forEach( ( tag ) => {
				newValue.push( tag.getAttribute( 'data-value' ) );
			} );

			const suggestions = wrapper.querySelector( '.tag_suggestions' );
			const options = suggestions.querySelectorAll( '.tag_suggest.option' );
			options.forEach( ( option ) => {
				if ( newValue.includes( option.getAttribute( 'data-value' ) ) ) {
					option.style.display = 'none';
				} else {
					option.style.display = 'block';
				}
			} );

			input.value = newValue.join( ',' );
			input.dispatchEvent( new Event( 'change' ) );
		};

		this.clearAutotags = function ( wrapper ) {
			const input = wrapper.querySelector( '.settings_input_option_value' );
			const tagsWrapper = wrapper.querySelector( '.tags' );
			const tagsCloseButtons = tagsWrapper.querySelectorAll( '.tag_close' );
			tagsCloseButtons.forEach( ( button ) => {
				this.tagRemove( button );
			} );
		};
	} )();

	this.conditions = new ( function () {
		this.init = () => {
			if ( $( '#contentsync_cluster_conditions' ).length == 0 ) return;

			this.addTriggers();

			const addConditionButton = document.querySelector( '.add_condition' );

			addConditionButton.addEventListener( 'click', ( e ) => {
				this.addRow();
			} );

			const enableReviewsAuthorSelect = document.getElementById(
				'enable_reviews_author_select'
			);
			const enableReviewsCheckbox = document.getElementById( 'enable_reviews' );

			if ( !enableReviewsCheckbox.checked ) {
				enableReviewsAuthorSelect.style.display = 'none';
			}

			enableReviewsCheckbox.addEventListener( 'change', ( e ) => {
				//show checkbox for author selection if checkbox is checked
				if ( enableReviewsCheckbox.checked ) {
					enableReviewsAuthorSelect.style.display = 'block';
				} else {
					enableReviewsAuthorSelect.style.display = 'none';
				}
			} );

			const dateModeSelect = document.querySelectorAll(
				'.filter_section[data-mode="date"] .date_mode'
			);
			dateModeSelect.forEach( ( select ) => {
				this.handleDateModeChange( select );
			} );

			const sub = document.querySelectorAll( '.sub' );
			sub.forEach( ( row ) => {
				if ( !row.classList.contains( 'open' ) ) {
					row.style.height = null;
				}
			} );
		};

		this.removeTriggers = function () {
			/*
			 * Condition Rows
			 */
			const editConditionButton = document.querySelectorAll( '.edit_condition' );
			editConditionButton.forEach( ( button ) => {
				button.removeEventListener( 'click', ( e ) => {
					this.toggleRow( button.closest( '.row_container' ) );
				} );
			} );

			const deleteConditionButton = document.querySelectorAll( '.delete_condition' );
			deleteConditionButton.forEach( ( button ) => {
				button.removeEventListener( 'click', ( e ) => {
					this.deleteRow( button );
				} );
			} );

			const duplicateConditionButton = document.querySelectorAll( '.duplicate_condition' );
			duplicateConditionButton.forEach( ( button ) => {
				button.removeEventListener( 'click', ( e ) => {
					this.duplicateRow( button );
				} );
			} );

			/*
			 * Condition Settings
			 */

			const blogSelect = document.querySelectorAll( '.select_blog_wrapper select' );
			blogSelect.forEach( ( select ) => {
				select.removeEventListener( 'change', ( e ) => {
					this.handleBlogChange( select );
				} );
			} );

			const posttypeSelect = document.querySelectorAll( '.posttype_select_wrapper select' );
			posttypeSelect.forEach( ( select ) => {
				select.removeEventListener( 'change', ( e ) => {
					this.handlePosttypeChange( select );
				} );
			} );

			const dateModeSelect = document.querySelectorAll(
				'.filter_section[data-mode="date"] .date_mode'
			);
			dateModeSelect.forEach( ( select ) => {
				select.removeEventListener( 'change', ( e ) => {
					this.handleDateModeChange( select );
				} );
			} );

			const taxSelect = document.querySelectorAll( 'select.taxonomy_select' );
			taxSelect.forEach( ( select ) => {
				select.removeEventListener( 'change', ( e ) => {
					this.handleTaxonomyChange( select );
				} );
			} );
		};

		this.addTriggers = function () {
			this.removeTriggers();

			/*
			 * Condition Rows
			 */
			const editConditionButton = document.querySelectorAll( '.edit_condition' );
			editConditionButton.forEach( ( button ) => {
				button.addEventListener( 'click', ( e ) => {
					this.toggleRow( button.closest( '.row_container' ) );
				} );
			} );

			const deleteConditionButton = document.querySelectorAll( '.delete_condition' );
			deleteConditionButton.forEach( ( button ) => {
				button.addEventListener( 'click', ( e ) => {
					this.deleteRow( button );
				} );
			} );

			const duplicateConditionButton = document.querySelectorAll( '.duplicate_condition' );
			duplicateConditionButton.forEach( ( button ) => {
				button.addEventListener( 'click', ( e ) => {
					this.duplicateRow( button );
				} );
			} );

			/*
			 * Condition Settings
			 */

			const blogSelect = document.querySelectorAll( '.blog_select_wrapper select' );
			blogSelect.forEach( ( select ) => {
				select.addEventListener( 'change', ( e ) => {
					this.handleBlogChange( select );
				} );
			} );

			const posttypeSelect = document.querySelectorAll( '.posttype_select_wrapper select' );
			posttypeSelect.forEach( ( select ) => {
				select.addEventListener( 'change', ( e ) => {
					this.handlePosttypeChange( select );
				} );
			} );

			// const makePostsGlobalCheckbox = document.querySelectorAll('.make_posts_global_automatically');
			// makePostsGlobalCheckbox.forEach( (checkbox) => {
			// 	checkbox.addEventListener('change', (e) => {
			// 		this.toggleMakePostsGlobal(checkbox);
			// 	});
			// });

			const dateModeSelect = document.querySelectorAll(
				'.filter_section[data-mode="date"] .date_mode'
			);
			dateModeSelect.forEach( ( select ) => {
				select.addEventListener( 'change', ( e ) => {
					this.handleDateModeChange( select );
				} );
			} );

			const taxSelect = document.querySelectorAll( 'select.taxonomy_select' );
			taxSelect.forEach( ( select ) => {
				select.addEventListener( 'change', ( e ) => {
					this.handleTaxonomyChange( select );
				} );
			} );
		};

		this.addRow = () => {
			const into = $( '#contentsync_sortable' );
			const row = document.querySelector( '.row_container.hidden' );
			const openRow = document.querySelector( '.row_container.open' );
			row.querySelector( 'input[name$="[ID]"]' ).value = 'new';

			const clone = row.cloneNode( true );
			clone.querySelector( 'input[name$="[ID]"]' ).value = 'hidden';

			const newRow = into.append( clone );

			contentSync.clusters.conditions.toggleRow( openRow );
			row.classList.remove( 'hidden' );

			// close
			contentSync.clusters.conditions.toggleRow( row );
			contentSync.clusters.conditions.addTriggers();
			contentSync.clusters.conditions.updateRowNumbers();
		};

		this.deleteRow = ( button ) => {
			const row = button.closest( '.row_container' );
			const confirmed = confirm( 'Are you sure you want to delete the condition?' );
			if ( confirmed ) {
				row.remove();
				contentSync.clusters.conditions.updateRowNumbers();
				contentSync.clusters.checkPostTypes.checkAll();
			}
		};

		this.updateRowNumbers = () => {
			const rows = document.querySelectorAll( '.conditions_table .sub' );
			let i = 0;

			rows.forEach( ( row ) => {
				const n = row.getAttribute( 'data-num' );
				const inputs = row.querySelectorAll( '*[name*="conditions[' + n + ']"]' );

				inputs.forEach( ( input ) => {
					const name = input.getAttribute( 'name' );

					input.setAttribute(
						'name',
						name.replace( /(conditions\[)(.*?)(\]\[)/g, 'conditions[' + i + '][' )
					);
				} );
				row.setAttribute( 'data-num', i );
				i++;
			} );
		};

		this.toggleRow = ( row ) => {
			if ( !row ) return;

			const sub = row.querySelector( '.sub' );
			const sh = sub.getBoundingClientRect().height;
			const th = sub.querySelector( 'table' ).getBoundingClientRect().height;

			let height = sh <= 2 ? th : 0;
			// console.log(height);

			contentSync.clusters.conditions.updateHeight( sub, height );

			if ( height > 0 ) {
				row.classList.add( 'open' );
			} else {
				row.classList.remove( 'open' );
			}
		};

		this.updateHeight = ( sub, rowHeight ) => {
			rowHeight =
				typeof rowHeight === 'undefined'
					? sub.querySelector( 'table' ).getBoundingClientRect().height
					: rowHeight;
			sub.style.height = rowHeight + 'px';
		};

		this.handleBlogChange = ( select ) => {
			const wrapper = select.closest( '.blog_select_wrapper' );

			const data = JSON.parse( wrapper.getAttribute( 'data-all-blogdata' ) );
			const blogID = select.value;
			const posttypes = data[ blogID ].post_types;
			const sub = wrapper.closest( '.sub' );
			const posttypeSelect = sub.querySelector( '.posttype_select_wrapper select' );

			this.updatePosttypeOptions( posttypes, posttypeSelect );
			this.handleTaxonomyChange( sub.querySelector( 'select.taxonomy_select' ) );
		};

		this.handlePosttypeChange = ( select ) => {
			const sub = select.closest( '.sub' );
			const dataWrapper = sub.querySelector( '.blog_select_wrapper' );
			const data = JSON.parse( dataWrapper.getAttribute( 'data-all-blogdata' ) );
			const blogSelect = sub.querySelector( '.blog_select_wrapper select' );
			const blogID = blogSelect.value;
			const posttype = select.value;
			// console.log(data);

			this.updateTaxonomyOptions(
				data[ blogID ].post_types[ posttype ].taxonomies,
				sub.querySelector( '.taxonomy_select' )
			);
			this.handleTaxonomyChange( sub.querySelector( 'select.taxonomy_select' ) );
		};

		this.handleTaxonomyChange = ( select ) => {
			const inputSection = select.closest( '.input_section' );
			const wrapper = inputSection.parentElement;
			const autotagsSelect = wrapper.querySelector( '.settings_input_option.autotags' );

			contentSync.clusters.multiselect.clearAutotags( autotagsSelect );

			const taxonomy = select.value;
			const sub = select.closest( '.sub' );
			const dataWrapper = sub.querySelector( '.blog_select_wrapper' );
			const data = JSON.parse( dataWrapper.getAttribute( 'data-all-blogdata' ) );
			const selectedPosttype = sub.querySelector( '.posttype_select_wrapper select' ).value;
			const blogID = sub.querySelector( '.blog_select_wrapper select' ).value;

			let termsData = {};
			termsData.Terms = {};
			if (
				typeof data[ blogID ].post_types[ selectedPosttype ].taxonomies !== 'undefined' &&
				typeof data[ blogID ].post_types[ selectedPosttype ].taxonomies[ taxonomy ] !==
					'undefined'
			) {
				termsData.Terms =
					data[ blogID ].post_types[ selectedPosttype ].taxonomies[ taxonomy ].terms;
			}

			contentSync.clusters.multiselect.updateOptions( termsData, autotagsSelect );
		};

		this.handleDateModeChange = ( fieldset ) => {
			const sub = fieldset.closest( '.sub' );

			const filterSection = fieldset.closest( '.filter_section' );

			// get value of selected radiobutton in fieldset
			const selectedRadioButton = fieldset.querySelector( 'input[type="radio"]:checked' );
			const inputSections = filterSection.querySelectorAll(
				'.input_section[data-date-mode="' + selectedRadioButton.value + '"]'
			);
			const allSections = filterSection.querySelectorAll( '.input_section[data-date-mode]' );

			allSections.forEach( ( section ) => {
				section.classList.add( 'hidden' );
			} );

			inputSections.forEach( ( section ) => {
				section.classList.remove( 'hidden' );
			} );

			const th = sub.querySelector( 'table' ).getBoundingClientRect().height;
			contentSync.clusters.conditions.updateHeight( sub, th );
		};

		this.updatePosttypeOptions = ( data, select ) => {
			// clear all options
			select.innerHTML = '';
			for ( let key in data ) {
				const option = document.createElement( 'option' );
				option.value = data[ key ].slug;
				option.textContent = data[ key ].title;
				select.appendChild( option );
			}
		};

		this.updateTaxonomyOptions = ( data, select ) => {
			// get first select option
			const firstOption = select.querySelector( 'option' );
			select.innerHTML = '';
			select.appendChild( firstOption );

			for ( let key in data ) {
				const option = document.createElement( 'option' );
				option.value = key;
				option.textContent = key;
				select.appendChild( option );
			}
		};
	} )();

	this.checkPostTypes = new ( function () {
		this.init = () => {
			document.addEventListener( 'change', ( e ) => {
				// console.log(e.target);
				if (
					e.target.classList.contains( 'cluster_blog_select' ) ||
					e.target.classList.contains( 'cluster_post_type_select' ) ||
					e.target.classList.contains( 'make_posts_global_automatically' )
				) {
					// console.log(e.target.value);
					contentSync.clusters.checkPostTypes.checkAll();
				}
			} );
		};

		this.checkAll = () => {
			var pts = document.querySelectorAll( '.cluster_post_type_select' );
			// console.log(pts);
			for ( var i = 0; i < pts.length; i++ ) {
				contentSync.clusters.checkPostTypes.check( pts[ i ] );
			}
		};

		this.check = ( el ) => {
			// get notice or abort
			var notice = el?.nextElementSibling;
			// console.log(notice);
			if ( !notice ) {
				return;
			}

			notice.style.display = 'none';

			// get selected blog (source site)
			var blog = el.closest( '.cluster_edit_table' )?.querySelector( '.blog_select_wrapper' );
			var blogIndex = blog?.querySelector( 'option:checked' );
			// console.log(blog, blogIndex);
			if ( !el.value || !blogIndex.value ) {
				return;
			}

			// get blog data and check if posttype is dynamic and global
			var data = JSON.parse( blog.dataset.allBlogdata );
			// console.log(data[blogIndex.value].post_types[el.value]);
			var posttypeData = data?.[ blogIndex.value ]?.post_types?.[ el.value ];
			if ( posttypeData && posttypeData.is_dynamic && !posttypeData.is_global ) {
				var messages = JSON.parse( notice.dataset.messages );
				var msg = messages.not_global + '<br>' + messages.no_condition;
				// search if 'tp_posttypes' from this blog is in cluster
				var conditions = document.querySelectorAll( '.blog_select_wrapper select' );
				for ( var i = 0; i < conditions.length; i++ ) {
					// console.log(conditions[i].value);
					if ( conditions[ i ].value == blogIndex.value ) {
						var wrapper = conditions[ i ].closest( '.cluster_edit_table' );
						if (
							wrapper?.querySelector( '.posttype_select_wrapper select' )?.value ==
							'tp_posttypes'
						) {
							msg = messages.not_global + '<br>' + messages.not_all;
							if (
								wrapper.querySelector( '.make_posts_global_automatically' )?.checked
							) {
								msg = '';
							}
						}
					}
				}

				if ( msg != '' ) {
					// show message
					msg = msg
						.split( '__pt__' )
						.join( posttypeData.title )
						.split( '__blog__' )
						.join( blogIndex.textContent );
					// console.warn(msg);
					notice.innerHTML = msg;
					notice.style.display = 'block';
				}

				if ( el.closest( '.row_container' ).classList.contains( 'open' ) ) {
					// update height
					contentSync.clusters.conditions.updateHeight( el.closest( '.sub' ) );
				}
			}
		};
	} )();
} )();

document.addEventListener( 'DOMContentLoaded', () => {
	contentSync.clusters.init();
} );
