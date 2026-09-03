/**
 * The block editor's control for the gated access status.
 *
 * WordPress registers custom post statuses server-side, but the editor's own
 * status control offers a fixed list — Draft, Pending, Private, Published,
 * Scheduled — and does not read the registry. So a status nobody can select is
 * a status nobody can use, and this is the control that makes it selectable.
 *
 * Saving it works without any of this: the REST schema's `status` enum is
 * `get_post_stati( [ 'internal' => false ] )` and `handle_status_param()`
 * passes any registered status straight through. Only the choosing was
 * missing.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo, store as editorStore } from '@wordpress/editor';
import { CheckboxControl, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const GATED = 'gatedmedia_gated';

/**
 * What the status was before it was gated, so unsetting it has somewhere to go.
 *
 * Publish is the only safe answer: the post was reachable before, and dropping
 * it to draft would unpublish something on a checkbox.
 */
const UNGATED = 'publish';

function GatedStatus() {
	const status = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'status' ),
		[]
	);

	const { editPost } = useDispatch( editorStore );

	const isGated = status === GATED;

	return (
		<PluginPostStatusInfo className="gatedmedia-status">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Gated access', 'gated-media-access' ) }
				help={ __(
					'Reachable only at its private link, and only by someone granted access. Its ordinary URL stops working.',
					'gated-media-access'
				) }
				checked={ isGated }
				onChange={ ( next ) =>
					editPost( { status: next ? GATED : UNGATED } )
				}
			/>

			{ isGated && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Turning this off republishes the post, but does not remove its access restriction — that stays until you remove it yourself.',
						'gated-media-access'
					) }
				</Notice>
			) }
		</PluginPostStatusInfo>
	);
}

registerPlugin( 'gatedmedia-gated-status', { render: GatedStatus } );
