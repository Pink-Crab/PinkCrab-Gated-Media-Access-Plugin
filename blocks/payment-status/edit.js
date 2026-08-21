/**
 * §7.8 Payment status — editor.
 *
 * Composed by the orders view rather than placed by hand, so the editor view
 * is a preview of the four states with a toolbar to switch between them. The
 * wording follows the state unless it is overtyped, matching render.php.
 */

import { useBlockProps, BlockControls, RichText } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarDropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STATES = [
	{
		value: 'pending',
		title: __( 'Confirming', 'gated-media-access' ),
		icon: 'clock',
		symbol: '',
		heading: __( 'Confirming your payment', 'gated-media-access' ),
		message: __(
			'This usually takes a few seconds. Reload the page to check again.',
			'gated-media-access'
		),
	},
	{
		value: 'complete',
		title: __( 'Complete', 'gated-media-access' ),
		icon: 'yes',
		symbol: 'i-success',
		heading: __( "You're in", 'gated-media-access' ),
		message: __( 'Your access is ready.', 'gated-media-access' ),
	},
	{
		value: 'refunded',
		title: __( 'Refunded', 'gated-media-access' ),
		icon: 'undo',
		symbol: 'i-refund',
		heading: __( 'This order was refunded', 'gated-media-access' ),
		message: __(
			'The access it created has been withdrawn.',
			'gated-media-access'
		),
	},
	{
		value: 'failed',
		title: __( 'Failed', 'gated-media-access' ),
		icon: 'warning',
		symbol: 'i-error',
		heading: __( 'Payment not completed', 'gated-media-access' ),
		message: __(
			"We couldn't take your payment, and you have not been charged.",
			'gated-media-access'
		),
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { status, heading, message } = attributes;
	const current =
		STATES.find( ( option ) => option.value === status ) || STATES[ 0 ];

	const blockProps = useBlockProps( {
		className: `gatedmedia-payment-status gatedmedia-payment-status--${ current.value }`,
	} );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ current.icon }
						label={ __( 'Payment state', 'gated-media-access' ) }
						text={ current.title }
						controls={ STATES.map( ( option ) => ( {
							title: option.title,
							icon: option.icon,
							isActive: option.value === status,
							onClick: () =>
								setAttributes( { status: option.value } ),
						} ) ) }
					/>
				</ToolbarGroup>
			</BlockControls>

			<div { ...blockProps }>
				{ 'pending' === current.value ? (
					<div className="gatedmedia-spinner" aria-hidden="true" />
				) : (
					<svg
						className="gatedmedia-icon gatedmedia-payment-status__icon"
						aria-hidden="true"
					>
						<use href={ `#${ current.symbol }` } />
					</svg>
				) }

				<RichText
					tagName="h2"
					className="gatedmedia-heading gatedmedia-heading--page"
					value={ heading }
					onChange={ ( next ) => setAttributes( { heading: next } ) }
					placeholder={ current.heading }
					allowedFormats={ [] }
				/>

				<RichText
					tagName="p"
					className="gatedmedia-text"
					value={ message }
					onChange={ ( next ) => setAttributes( { message: next } ) }
					placeholder={ current.message }
					allowedFormats={ [] }
				/>
			</div>
		</>
	);
}
