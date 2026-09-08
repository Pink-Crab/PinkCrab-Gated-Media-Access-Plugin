/**
 * Dismissing a notice.
 *
 * The close button is rendered by PHP only on notices that are dismissible, so this attaches to whatever is there and invents nothing, and a notice with no close button is never dismissible, which is what forced profile completion depends on.
 */

import { all } from '../shared/dom';

/**
 * @param {Document|Element} scope Where to look for notices.
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
