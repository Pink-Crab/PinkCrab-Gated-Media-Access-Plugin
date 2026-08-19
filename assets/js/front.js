/**
 * Front end — build/js/front.js
 *
 * The interactive surface of the account area, and it is deliberately small.
 * Everything in §7 is server rendered; this enhances what is already on the
 * page and nothing here is required for a view to be readable or usable.
 *
 * Enqueued conditionally, alongside build/css/front.css.
 */

import { onReady } from './shared/dom';
import initNotices from './modules/notices';

onReady( () => {
	initNotices( document );
} );
