/**
 * Front end, built to build/js/front.js.
 *
 * The interactive surface of the account area, and deliberately small: every view is server rendered, so this enhances what is already on the page and nothing here is required for a view to be readable or usable.
 *
 * Enqueued conditionally, alongside build/css/front.css.
 */

import { onReady } from './shared/dom';
import initNotices from './modules/notices';
import initPaymentStatus from './modules/payment-status';
import initFilter from './modules/filter';

onReady( () => {
	initNotices( document );
	initPaymentStatus( document );
	initFilter( document );
} );
