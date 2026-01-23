/**
 * Modal Class
 * 
 * Creates WordPress-style modal dialogs from configuration objects.
 * Supports dynamic content, form inputs, notices, and callback functions.
 */
class Modal {

	/**
	 * Modal config
	 * @type {Object}
	 */
	config = null;

	/**
	 * Modal DOM element
	 * @type {HTMLElement}
	 */
	modalElement = null;

	/**
	 * Event listeners
	 * @type {Object}
	 */
	boundHandlers = {};

	/**
	 * Static counter for header IDs (allows multiple modals on the same page)
	 * @type {number}
	 */
	static headerIdCounter = 0;

	/**
	 * Constructor
	 * @param {Object} config - Configuration object
	 */
	constructor( config ) {
		// Validate required config properties
		if ( !config || !config.id || !config.title ) {
			throw new Error( 'Modal config must include id and title' );
		}

		// Store config
		this.config = config;
		
		// Store reference to modal DOM element (initially null)
		this.modalElement = null;
		
		// Store event listeners for cleanup
		this.boundHandlers = {};
	}

	/**
	 * Render the complete modal HTML structure
	 * @returns {HTMLElement} The modal wrapper element
	 */
	render() {
		const modalId = this.config.id;
		const headerId = `components-modal-header-${Modal.headerIdCounter++}`;

		// Create wrapper
		const wrapper = document.createElement( 'div' );
		wrapper.className = 'components-modal__screen-overlay contentSync-modal';
		wrapper.id = `${modalId}__wrapper`;

		// Create frame
		const frame = document.createElement( 'div' );
		frame.className = 'components-modal__frame has-size-small';
		frame.id = modalId;
		frame.setAttribute( 'role', 'dialog' );
		frame.setAttribute( 'aria-labelledby', headerId );
		frame.setAttribute( 'tabindex', '-1' );

		// Create content
		const content = document.createElement( 'div' );
		content.className = 'components-modal__content';
		content.setAttribute( 'role', 'document' );

		// Create header
		const header = this.renderHeader( headerId );
		content.appendChild( header );

		// Create main content wrapper
		const mainContent = document.createElement( 'div' );

		// Create description
		if ( this.config.description ) {
			const description = this.renderDescription();
			mainContent.appendChild( description );
		}

		// Create panel body
		const panelBody = document.createElement( 'div' );
		panelBody.className = 'components-panel__body';

		// Create form
		const form = this.renderForm();
		panelBody.appendChild( form );

		// Create notices
		if ( this.config.notice ) {
			const notices = this.renderNotices();
			panelBody.appendChild( notices );
		}

		mainContent.appendChild( panelBody );
		content.appendChild( mainContent );

		// Create footer
		const footer = this.renderFooter();
		content.appendChild( footer );

		frame.appendChild( content );
		wrapper.appendChild( frame );

		return wrapper;
	}

	/**
	 * Render the modal header
	 * @param {string} headerId - ID for the header heading
	 * @returns {HTMLElement} Header element
	 */
	renderHeader( headerId ) {
		const header = document.createElement( 'div' );
		header.className = 'components-modal__header';

		// Heading container
		const headingContainer = document.createElement( 'div' );
		headingContainer.className = 'components-modal__header-heading-container';

		const heading = document.createElement( 'h1' );
		heading.id = headerId;
		heading.className = 'components-modal__header-heading';
		heading.textContent = this.config.title;

		headingContainer.appendChild( heading );
		header.appendChild( headingContainer );

		// Spacer
		const spacer = document.createElement( 'div' );
		spacer.className = 'components-spacer';
		header.appendChild( spacer );

		// Close button
		const closeButton = document.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'components-button is-compact has-icon';
		closeButton.setAttribute( 'aria-label', 'Close' );
		closeButton.dataset.action = 'close';

		// Close button SVG
		const closeSvg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		closeSvg.setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		closeSvg.setAttribute( 'viewBox', '0 0 24 24' );
		closeSvg.setAttribute( 'width', '24' );
		closeSvg.setAttribute( 'height', '24' );
		closeSvg.setAttribute( 'aria-hidden', 'true' );
		closeSvg.setAttribute( 'focusable', 'false' );

		const closePath = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		closePath.setAttribute( 'd', 'm13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z' );

		closeSvg.appendChild( closePath );
		closeButton.appendChild( closeSvg );
		header.appendChild( closeButton );

		return header;
	}

	/**
	 * Render the modal description
	 * @returns {HTMLElement} Description element
	 */
	renderDescription() {
		const description = document.createElement( 'p' );
		description.className = 'components-modal__description';
		description.textContent = this.config.description || '';

		return description;
	}

	/**
	 * Render the form element
	 * @returns {HTMLElement} Form element
	 */
	renderForm() {
		const form = document.createElement( 'form' );
		form.id = `${this.config.id}__form`;
		form.className = 'components-panel__body-form';
		form.onsubmit = function() { return false; };

		// Render form inputs
		if ( this.config.formInputs && this.config.formInputs.length > 0 ) {
			this.config.formInputs.forEach( input => {
				const row = document.createElement( 'div' );
				row.className = 'components-panel__row';

				if ( input.type === 'checkbox' ) {
					row.appendChild( this.renderCheckboxInput( input ) );
				} else if ( input.type === 'custom' ) {
					row.appendChild( this.renderCustomInput( input ) );
				}

				form.appendChild( row );
			} );
		}

		return form;
	}

	/**
	 * Render a checkbox input
	 * @param {Object} input - Checkbox input config
	 * @returns {HTMLElement} Checkbox control element
	 */
	renderCheckboxInput( input ) {
		const control = document.createElement( 'div' );
		control.className = 'components-base-control components-checkbox-control';

		// Input container
		const inputContainer = document.createElement( 'span' );
		inputContainer.className = 'components-checkbox-control__input-container';

		// Checkbox input
		const checkbox = document.createElement( 'input' );
		checkbox.id = input.name;
		checkbox.className = 'components-checkbox-control__input';
		checkbox.type = 'checkbox';
		checkbox.name = input.name;
		if ( input.value !== undefined ) {
			checkbox.value = input.value;
		}

		if ( input.checked !== undefined ) {
			checkbox.checked = input.checked;
		} else if ( input.value !== undefined ) {
			// Default to checked if value is provided
			checkbox.checked = true;
		}

		// Checkmark SVG
		const checkSvg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		checkSvg.setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		checkSvg.setAttribute( 'viewBox', '0 0 24 24' );
		checkSvg.setAttribute( 'width', '24' );
		checkSvg.setAttribute( 'height', '24' );
		checkSvg.setAttribute( 'role', 'presentation' );
		checkSvg.setAttribute( 'class', 'components-checkbox-control__checked' );
		checkSvg.setAttribute( 'aria-hidden', 'true' );
		checkSvg.setAttribute( 'focusable', 'false' );

		const checkPath = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		checkPath.setAttribute( 'd', 'M16.5 7.5 10 13.9l-2.5-2.4-1 1 3.5 3.6 7.5-7.6z' );

		checkSvg.appendChild( checkPath );
		inputContainer.appendChild( checkbox );
		inputContainer.appendChild( checkSvg );

		// Label
		const label = document.createElement( 'label' );
		label.className = 'components-checkbox-control__label';
		label.setAttribute( 'for', input.name );

		const strong = document.createElement( 'strong' );
		strong.textContent = input.label || '';

		const small = document.createElement( 'small' );
		small.textContent = input.description || '';

		label.appendChild( strong );
		label.appendChild( small );

		control.appendChild( inputContainer );
		control.appendChild( label );

		return control;
	}

	/**
	 * Render a custom input
	 * @param {Object} input - Custom input config
	 * @returns {HTMLElement} Custom control element
	 */
	renderCustomInput( input ) {
		const control = document.createElement( 'div' );
		control.className = 'components-base-control components-custom-control';

		const container = document.createElement( 'div' );
		container.className = 'components-custom-control__input-container';
		container.innerHTML = input.content || '';

		control.appendChild( container );

		return control;
	}

	/**
	 * Render notices
	 * @returns {HTMLElement} Notices container element
	 */
	renderNotices() {
		const noticesContainer = document.createElement( 'div' );
		noticesContainer.className = 'components-panel__body-notices';

		if ( !this.config.notice ) {
			return noticesContainer;
		}

		// Map notice type to styling and icon (matching Admin_Render::make_admin_info_box)
		const noticeType = this.config.notice.type || 'info';
		let styling = '';
		let iconClass = 'dashicons-info';

		if ( noticeType === 'success' || noticeType === 'green' ) {
			styling = 'success';
			iconClass = 'dashicons-yes';
		} else if ( noticeType === 'warning' || noticeType === 'orange' ) {
			styling = 'warning';
			iconClass = 'dashicons-warning';
		} else if ( noticeType === 'alert' || noticeType === 'red' || noticeType === 'danger' || noticeType === 'error' ) {
			styling = 'alert';
			iconClass = 'dashicons-warning';
		} else if ( noticeType === 'new' ) {
			styling = 'new';
			iconClass = 'dashicons-megaphone';
		} else {
			// Default to info
			styling = '';
			iconClass = 'dashicons-info';
		}

		// Create info box structure matching Admin_Render::make_admin_info_box
		const notice = document.createElement( 'div' );
		notice.className = `contentSync-info-box ${styling}`.trim();

		// Icon span
		const iconSpan = document.createElement( 'span' );
		iconSpan.className = `dashicons ${iconClass}`;
		notice.appendChild( iconSpan );

		// Content div
		const contentDiv = document.createElement( 'div' );
		const textSpan = document.createElement( 'span' );
		textSpan.textContent = this.config.notice.text || '';
		contentDiv.appendChild( textSpan );
		notice.appendChild( contentDiv );

		noticesContainer.appendChild( notice );

		return noticesContainer;
	}

	/**
	 * Render the footer with buttons
	 * @returns {HTMLElement} Footer element
	 */
	renderFooter() {
		const footer = document.createElement( 'div' );
		footer.className = 'components-panel__footer components-flex';

		// Cancel button
		const cancelButton = document.createElement( 'button' );
		cancelButton.type = 'button';
		cancelButton.className = 'components-button components-flex-item is-tertiary';
		if ( this.config.buttons && this.config.buttons.cancel && this.config.buttons.cancel.classes ) {
			cancelButton.className = `components-button components-flex-item ${this.config.buttons.cancel.classes}`;
		}

		cancelButton.textContent = ( this.config.buttons && this.config.buttons.cancel && this.config.buttons.cancel.text ) || 'Cancel';
		cancelButton.dataset.action = 'cancel';
		footer.appendChild( cancelButton );

		// Confirm button
		const submitButton = document.createElement( 'button' );
		submitButton.type = 'button';
		submitButton.className = 'components-button components-flex-item is-primary';
		if ( this.config.buttons && this.config.buttons.submit && this.config.buttons.submit.classes ) {
			submitButton.className = `components-button components-flex-item ${this.config.buttons.submit.classes}`;
		}

		submitButton.textContent = ( this.config.buttons && this.config.buttons.submit && this.config.buttons.submit.text ) || 'Confirm';
		submitButton.dataset.action = 'submit';
		footer.appendChild( submitButton );

		return footer;
	}

	/**
	 * Open the modal
	 */
	open() {
		// Check if modal already exists in DOM, remove if present (prevent duplicates)
		const existingModal = document.getElementById( `${this.config.id}__wrapper` );
		if ( existingModal ) {
			existingModal.remove();
		}

		// Create entire HTML structure
		this.modalElement = this.render();

		// Append to document body
		document.body.appendChild( this.modalElement );

		// Focus modal frame
		const frame = this.modalElement.querySelector( '.components-modal__frame' );
		if ( frame ) {
			frame.focus();
		}

		// Add event listeners
		this.attachEventListeners();

		// Call onOpen callback if provided
		if ( this.config.onOpen && typeof this.config.onOpen === 'function' ) {
			this.config.onOpen.call( this );
		}
	}

	/**
	 * Close the modal
	 */
	close() {
		if ( !this.modalElement ) {
			return;
		}

		// Remove event listeners
		this.detachEventListeners();

		// Remove modal from DOM (destroy HTML structure)
		this.modalElement.remove();
		this.modalElement = null;

		// Call onClose callback if provided
		if ( this.config.onClose && typeof this.config.onClose === 'function' ) {
			this.config.onClose.call( this );
		}
	}

	/**
	 * Attach event listeners
	 */
	attachEventListeners() {
		if ( !this.modalElement ) {
			return;
		}

		// Escape key handler
		this.boundHandlers.handleEscape = ( e ) => {
			if ( e.key === 'Escape' ) {
				this.close();
			}
		};

		document.addEventListener( 'keydown', this.boundHandlers.handleEscape );

		// Overlay click handler (close when clicking outside frame)
		this.boundHandlers.handleOverlayClick = ( e ) => {
			const frame = this.modalElement.querySelector( '.components-modal__frame' );
			if ( frame && !frame.contains( e.target ) ) {
				this.close();
			}
		};

		this.modalElement.addEventListener( 'click', this.boundHandlers.handleOverlayClick );

		// Close button handler
		const closeButton = this.modalElement.querySelector( '[data-action="close"]' );
		if ( closeButton ) {
			this.boundHandlers.handleClose = () => this.close();
			closeButton.addEventListener( 'click', this.boundHandlers.handleClose );
		}

		// Cancel button handler
		const cancelButton = this.modalElement.querySelector( '[data-action="cancel"]' );
		if ( cancelButton ) {
			this.boundHandlers.handleCancel = () => {
				if ( this.config.onCancel && typeof this.config.onCancel === 'function' ) {
					this.config.onCancel.call( this );
				}

				this.close();
			};

			cancelButton.addEventListener( 'click', this.boundHandlers.handleCancel );
		}

		// Confirm button handler
		const submitButton = this.modalElement.querySelector( '[data-action="submit"]' );
		if ( submitButton ) {
			this.boundHandlers.handleConfirm = () => {
				if ( this.config.onConfirm && typeof this.config.onConfirm === 'function' ) {
					this.config.onConfirm.call( this );
				}
			};

			submitButton.addEventListener( 'click', this.boundHandlers.handleConfirm );
		}
	}

	/**
	 * Detach event listeners
	 */
	detachEventListeners() {
		// Remove document-level listeners
		if ( this.boundHandlers.handleEscape ) {
			document.removeEventListener( 'keydown', this.boundHandlers.handleEscape );
		}

		// Remove element-level listeners
		if ( this.modalElement ) {
			if ( this.boundHandlers.handleOverlayClick ) {
				this.modalElement.removeEventListener( 'click', this.boundHandlers.handleOverlayClick );
			}

			const closeButton = this.modalElement.querySelector( '[data-action="close"]' );
			if ( closeButton && this.boundHandlers.handleClose ) {
				closeButton.removeEventListener( 'click', this.boundHandlers.handleClose );
			}

			const cancelButton = this.modalElement.querySelector( '[data-action="cancel"]' );
			if ( cancelButton && this.boundHandlers.handleCancel ) {
				cancelButton.removeEventListener( 'click', this.boundHandlers.handleCancel );
			}

			const submitButton = this.modalElement.querySelector( '[data-action="submit"]' );
			if ( submitButton && this.boundHandlers.handleConfirm ) {
				submitButton.removeEventListener( 'click', this.boundHandlers.handleConfirm );
			}
		}

		// Clear bound handlers
		this.boundHandlers = {};
	}

	/**
	 * Set description text dynamically
	 * @param {string} text - New description text
	 */
	setDescription( text ) {
		if ( !this.modalElement ) {
			return;
		}

		const descriptionParagraph = this.modalElement.querySelector( '.components-modal__description' );
		if ( descriptionParagraph ) {
			descriptionParagraph.textContent = text;
		}
	}

	/**
	 * Get form data
	 * @returns {Object} Form data object with field names as keys
	 */
	getFormData() {
		if ( !this.modalElement ) {
			return {};
		}

		const form = this.modalElement.querySelector( `#${this.config.id}__form` );
		if ( !form ) {
			return {};
		}

		const formData = {};
		const inputs = form.querySelectorAll( 'input[type="checkbox"], input[type="text"], input[type="number"], select, textarea' );

		inputs.forEach( input => {
			if ( input.type === 'checkbox' ) {
				formData[ input.name ] = input.checked;
			} else {
				formData[ input.name ] = input.value;
			}
		} );

		return formData;
	}

	/**
	 * Toggle the busy state of the submit button
	 */
	toggleConfirmButtonBusy( busy = true ) {
		const submitButton = this.modalElement.querySelector( '[data-action="submit"]' );
		if ( submitButton ) {
			submitButton.disabled = busy;
			if ( busy ) {
				submitButton.classList.add( 'is-busy' );
			} else {
				submitButton.classList.remove( 'is-busy' );
			}
		}
	}
}

var contentSync = contentSync || {};

contentSync.Modal = Modal;
