/**
 * Shared DOM helpers.
 *
 * Imported by both front.js and admin.js, and Webpack copies these into each bundle rather than emitting a third, because two small duplicated helpers cost less than a shared chunk both entries would have to declare a dependency on.
 */

/**
 * Runs a callback once the DOM is parsed, whether or not that has happened.
 *
 * Scripts are enqueued in the footer, so readyState is usually past loading already and the listener would never fire.
 *
 * @param {Function} callback Invoked once.
 */
export function onReady( callback ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', callback, {
			once: true,
		} );
		return;
	}

	callback();
}

/**
 * querySelectorAll as a real array.
 *
 * @param {string}           selector CSS selector.
 * @param {Document|Element} scope    Defaults to document.
 * @return {Element[]} Matching elements.
 */
export function all( selector, scope = document ) {
	return Array.from( scope.querySelectorAll( selector ) );
}
