/**
 * wp-admin — build/js/admin.js
 *
 * Nothing yet. The admin screens are core list tables, core metaboxes and the
 * Settings API (architecture.md §9), so most of them need no JS of ours at all.
 *
 * It is wired up now rather than later so that the first screen needing a
 * behaviour has somewhere to put it, and so front and admin share the modules
 * in ./shared from the outset instead of growing two copies.
 */

import { onReady } from './shared/dom';

onReady( () => {
	// Intentionally empty.
} );
