/**
 * Watching a pending payment confirm.
 *
 * Stripe returns the buyer before its webhook has necessarily landed, so the panel they arrive on often says `pending`, and this asks `Payment_Status_Route` whether that is still true and reloads the page when it is not.
 *
 * **A reload, not a repaint.** Three things on the order page come off the status, the pill, this panel, and the "Access this created" section that exists only once the webhook has granted, so redrawing the panel alone would put "You're in" above a pending pill and no access.
 *
 * **It never reports a failure.** The buyer has paid either way, so running out of attempts changes the wording and nothing else, and a request that fails is tried again until the ceiling, because one proxy hiccup used to end the watching for good and leave them on a spinner that would never resolve.
 *
 * Everything it needs is on the panel: PHP renders no attributes at all unless the payment is pending, owned and real, so there is nothing here to decide.
 */

/**
 * @param {Document|Element} scope Where to look for the panel.
 */
export default function initPaymentStatus( scope ) {
	const panel = scope.querySelector( '[data-gatedmedia-poll]' );

	if ( ! panel ) {
		return;
	}

	const url = panel.dataset.gatedmediaUrl;
	const nonce = panel.dataset.gatedmediaNonce;
	const interval = parseInt( panel.dataset.gatedmediaInterval, 10 );
	const ceiling = parseInt( panel.dataset.gatedmediaAttempts, 10 );

	if ( ! url || ! nonce || ! interval || ! ceiling ) {
		return;
	}

	let attempts = 0;
	let timer = null;

	/** Out of attempts: say so gently and leave the spinner turning. */
	const standDown = () => {
		const message = panel.querySelector( '[data-gatedmedia-message]' );
		const waiting = panel.dataset.gatedmediaWaiting;

		if ( message && waiting ) {
			message.textContent = waiting;
		}
	};

	const stop = () => {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
	};

	/** Nothing came back. Try again, or stand down if that was the last go. */
	const retry = () => {
		if ( attempts >= ceiling ) {
			stop();
			standDown();
			return;
		}

		timer = window.setTimeout( ask, interval );
	};

	const ask = async () => {
		attempts += 1;

		let status;

		try {
			const response = await window.fetch( url, {
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin',
			} );

			if ( ! response.ok ) {
				retry();
				return;
			}

			( { status } = await response.json() );
		} catch {
			// A blip. The payment is unaffected, so keep watching.
			retry();
			return;
		}

		if ( status && status !== 'pending' ) {
			stop();
			window.location.reload();
			return;
		}

		if ( attempts >= ceiling ) {
			stop();
			standDown();
			return;
		}

		timer = window.setTimeout( ask, interval );
	};

	timer = window.setTimeout( ask, interval );

	// A page restored from the back/forward cache has a stale status on it and a timer that may already have stood down, so reload rather than resume.
	window.addEventListener( 'pageshow', ( event ) => {
		if ( event.persisted ) {
			stop();
			window.location.reload();
		}
	} );
}
