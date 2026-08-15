/**
 * §6.4 — dismissing a notice.
 *
 * The close button is rendered by PHP only on notices that are actually
 * dismissible, so this attaches to whatever is there and invents nothing. A
 * notice with no close button is simply never dismissible — §7.5's forced
 * completion prompt depends on that.
 */

import { all } from '../shared/dom';

/**
 * @param {ParentNode} scope Where to look for notices.
 */
export default function initNotices( scope ) {
	all( '.gatedmedia-notice__dismiss', scope ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const notice = button.closest( '.gatedmedia-notice' );

			if ( ! notice ) {
				return;
			}

			notice.remove();
		} );
	} );
}
