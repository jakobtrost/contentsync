/**
 * Admin features for the 'distribution' feature
 *
 * @since 2.17.0
 */
var greyd = greyd || {};

greyd.distribution = new ( function () {
	this.debug = true;

	this.init = () => {
		this.queue.init();
	};

	this.queue = new ( function () {

		this.debug = true;
		this.stuckItems = distributionData.stuck_items || { count: 0, items: [] };
		this.currentIndex = 0;
		this.isProcessing = false;
		this.isPaused = false;
		this.successCount = 0;
		this.errorCount = 0;

		this.init = () => {
			// check if url contents page=contentsync_queue
			if ( location.href.indexOf( 'admin.php?page=contentsync_queue' ) === -1 ) return;

			// Initialize queue functionality
			this.initRunAllStuck();
			this.initPauseButton();
			this.initStopButton();
		};

		this.initRunAllStuck = () => {
			const runAllButton = document.querySelector( '#contentsync-run-all-stuck' );
			if ( !runAllButton ) return;

			runAllButton.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				this.startProcessing();
			} );
		};

		this.initPauseButton = () => {
			const pauseButton = document.querySelector( '#contentsync-pause-stuck' );
			if ( !pauseButton ) return;

			pauseButton.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				this.togglePause();
			} );
		};

		this.initStopButton = () => {
			const stopButton = document.querySelector( '#contentsync-stop-stuck' );
			if ( !stopButton ) return;

			stopButton.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				this.stopProcessing();
			} );
		};

		this.togglePause = () => {
			this.isPaused = !this.isPaused;
			const pauseButton = document.querySelector( '#contentsync-pause-stuck' );
			if ( pauseButton ) {
				pauseButton.textContent = this.isPaused
					? distributionData.i18n.resume
					: distributionData.i18n.pause;
			}

			// If resuming, continue processing
			if ( !this.isPaused ) {
				this.processNextItem();
			}
		};

		this.stopProcessing = () => {
			this.isProcessing = false;
			this.isPaused = false;
			// Remove beforeunload event listener
			window.removeEventListener( 'beforeunload', this.handleBeforeUnload );
			this.showSummary();
		};

		this.startProcessing = () => {
			if ( this.isProcessing || this.stuckItems.items.length === 0 ) return;

			this.isProcessing = true;
			this.isPaused = false;
			this.currentIndex = 0;
			this.successCount = 0;
			this.errorCount = 0;
			this.showProgressUI();
			this.updateProgress();

			// Add beforeunload event listener
			window.addEventListener( 'beforeunload', this.handleBeforeUnload );

			// Show the log details element
			const logDetails = document.querySelector( '.contentsync-progress-log-details' );
			if ( logDetails ) {
				logDetails.classList.add( 'visible' );
			}

			this.processNextItem();
		};

		this.showProgressUI = () => {
			const progressUI = document.querySelector( '.contentsync-distribution-progress' );
			const pauseButton = document.querySelector( '#contentsync-pause-stuck' );
			const stopButton = document.querySelector( '#contentsync-stop-stuck' );
			const runButton = document.querySelector( '#contentsync-run-all-stuck' );

			if ( progressUI ) progressUI.style.display = 'block';
			if ( pauseButton ) pauseButton.style.display = 'inline-block';
			if ( stopButton ) stopButton.style.display = 'inline-block';
			if ( runButton ) runButton.style.display = 'none';
		};

		this.updateProgress = () => {
			const total = this.stuckItems.items.length;
			const processed = this.successCount + this.errorCount;
			const progress = ( processed / total ) * 100;

			const progressBar = document.querySelector( '.contentsync-progress-bar' );
			const progressCount = document.querySelector( '.contentsync-progress-count' );

			if ( progressBar ) {
				progressBar.style.width = `${progress}%`;
			}

			if ( progressCount ) {
				progressCount.textContent = distributionData.i18n.progressLabel
					.replace( '%s', processed )
					.replace( '%s', total );
			}
		};

		this.addLogEntry = ( message, type = 'success' ) => {
			const logContainer = document.querySelector( '.contentsync-progress-log' );
			if ( !logContainer ) return;

			const entry = document.createElement( 'div' );
			entry.className = `contentsync-log-entry ${type}`;
			entry.textContent = message;

			logContainer.appendChild( entry );
			logContainer.scrollTop = logContainer.scrollHeight;
		};

		this.showSummary = () => {
			const summaryContainer = document.querySelector( '.contentsync-distribution-summary' );
			const summaryText = document.querySelector( '.contentsync-summary-text' );
			const progressUI = document.querySelector( '.contentsync-distribution-progress' );
			const pauseButton = document.querySelector( '#contentsync-pause-stuck' );
			const stopButton = document.querySelector( '#contentsync-stop-stuck' );

			if ( summaryContainer ) summaryContainer.style.display = 'block';
			if ( progressUI ) progressUI.style.display = 'none';
			if ( pauseButton ) pauseButton.style.display = 'none';
			if ( stopButton ) stopButton.style.display = 'none';

			if ( summaryText ) {
				const total = this.successCount + this.errorCount;
				const remaining = this.stuckItems.items.length - total;

				if ( this.currentIndex < this.stuckItems.items.length ) {
					// Processing was stopped before completion
					if ( this.errorCount === 0 ) {
						summaryText.textContent = distributionData.i18n.summaryAllSuccess
							.replace( '%s', total )
							.replace( '%s', this.stuckItems.items.length );
					} else {
						summaryText.textContent = distributionData.i18n.summarySomeFailed
							.replace( '%s', this.successCount )
							.replace( '%s', total )
							.replace( '%s', this.errorCount )
							.replace( '%s', remaining );
					}
				} else {
					// Processing completed normally
					if ( this.errorCount === 0 ) {
						summaryText.textContent = distributionData.i18n.summaryAllSuccess
							.replace( '%s', total )
							.replace( '%s', this.stuckItems.items.length );
					} else {
						summaryText.textContent = distributionData.i18n.summarySomeFailed
							.replace( '%s', this.successCount )
							.replace( '%s', total )
							.replace( '%s', this.errorCount )
							.replace( '%s', remaining );
					}
				}
			}
		};

		this.processNextItem = async () => {
			if ( !this.isProcessing ) return;
			if ( this.isPaused ) return;

			if ( this.currentIndex >= this.stuckItems.items.length ) {
				this.isProcessing = false;
				this.showSummary();

				return;
			}

			const item = this.stuckItems.items[ this.currentIndex ];

			try {
				const result = await this.processItem( item.id );

				if ( this.debug ) console.log( `Processed item ${item.id}:`, result );

				if ( result.success ) {
					this.successCount++;
					this.addLogEntry( `Item ${item.id}: ${result.data.message}`, 'success' );
					this.currentIndex++;
					this.updateProgress();
					this.processNextItem();
				} else {
					this.errorCount++;
					this.addLogEntry(
						`Item ${item.id}: ${
							result.data.message || distributionData.i18n.failedToProcess
						}`,
						'failed'
					);
					this.currentIndex++;
					this.updateProgress();
					this.processNextItem();
				}

				updateTableRowStatus( item.id, result.success ? 'success' : 'failed' );
				updateStatusCounts( result.success ? 'success' : 'failed' );
			} catch ( error ) {
				if ( this.debug ) console.error( error );
				this.errorCount++;
				this.addLogEntry(
					`Item ${item.id}: ${distributionData.i18n.errorProcessingItem}`,
					'failed'
				);
				this.currentIndex++;
				this.updateProgress();
				this.processNextItem();
				updateTableRowStatus( item.id, 'failed' );
				updateStatusCounts( 'failed' );
			}
		};

		this.processItem = async ( itemId ) => {
			const body = new FormData();
			body.append( 'action', 'contentsync_run_distribution_item' );
			body.append( '_ajax_nonce', distributionData.nonce );
			body.append( 'item_id', itemId );

			const response = await fetch( distributionData.ajax_url, {
				method: 'POST',
				body: body,
			} );

			return await response.json();
		};

		this.handleBeforeUnload = ( e ) => {
			if ( this.isProcessing && !this.isPaused ) {
				// Standard way to show confirmation dialog
				e.preventDefault();
				e.returnValue = '';

				return '';
			}
		};
	} )();
} )();

function updateTableRowStatus( itemId, status ) {
	// Find the checkbox for this item
	const checkbox = document.querySelector(
		'input[type="checkbox"][name="post[]"][value="' + itemId + '"]'
	);
	if ( !checkbox ) return;

	// Find the row
	const row = checkbox.closest( 'tr' );
	if ( !row ) return;

	// Find the status cell
	const statusCell = row.querySelector( 'td.status.column-status .contentsync_status' );
	if ( !statusCell ) return;

	// Update the status span
	if ( status === 'success' ) {
		statusCell.className = 'contentsync_info_box green contentsync_status';
		statusCell.setAttribute( 'data-title', 'success' );
		statusCell.innerHTML =
			'<span>' + ( distributionData.i18n.completed || 'Completed' ) + '</span>';
	} else if ( status === 'failed' ) {
		statusCell.className = 'contentsync_info_box red contentsync_status';
		statusCell.setAttribute( 'data-title', 'failed' );
		statusCell.innerHTML = '<span>' + ( distributionData.i18n.failed || 'Failed' ) + '</span>';
	}
}

function updateStatusCounts( resultStatus ) {
	// Helper to get the count element for a given status
	function getCountElement( statusClass ) {
		return document.querySelector( '.subsubsub li.' + statusClass + ' .count' );
	}

	// Always decrease 'Scheduled' (init) by 1
	const scheduledCountEl = getCountElement( 'init' );
	if ( scheduledCountEl ) {
		let count = parseInt( scheduledCountEl.textContent.replace( /[()]/g, '' ), 10 );
		if ( count > 0 ) scheduledCountEl.textContent = `(${count - 1})`;
	}

	// Increase either 'Completed' (success) or 'Failed' (failed)
	if ( resultStatus === 'success' || resultStatus === 'failed' ) {
		const statusCountEl = getCountElement( resultStatus );
		if ( statusCountEl ) {
			let count = parseInt( statusCountEl.textContent.replace( /[()]/g, '' ), 10 );
			statusCountEl.textContent = `(${count + 1})`;
		}
	}
}

document.addEventListener( 'DOMContentLoaded', () => {
	greyd.distribution.init();
} );
