/**
 * The price block, editor side.
 *
 * Drawn client-side. Which of the four forms you get follows from the amounts rather than a mode switch, so the block shows it directly instead of the panel describing it.
 *
 * Amounts are entered in pounds and stored in pence.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	MoneyControl,
	formatMinor,
	CURRENCIES,
} from '../../assets/js/editor/controls';

export default function Edit( { attributes, setAttributes } ) {
	const { amount, original, currency, term, notApplicable } = attributes;
	const blockProps = useBlockProps( { className: 'gatedmedia-price-block' } );
	const showsOriginal = original > amount && ! notApplicable;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Price', 'gated-media-access' ) }>
					<ToggleControl
						label={ __( 'No price applies', 'gated-media-access' ) }
						checked={ !! notApplicable }
						onChange={ ( value ) =>
							setAttributes( { notApplicable: value } )
						}
						help={ __(
							'For access an administrator granted, which has no order behind it.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>

					{ ! notApplicable && (
						<>
							<MoneyControl
								label={ __(
									'Amount payable',
									'gated-media-access'
								) }
								value={ amount }
								onChange={ ( value ) =>
									setAttributes( { amount: value } )
								}
								help={ __(
									'Zero renders as the word "Free", never as 0.00.',
									'gated-media-access'
								) }
							/>
							<MoneyControl
								label={ __(
									'Price before discount',
									'gated-media-access'
								) }
								value={ original }
								onChange={ ( value ) =>
									setAttributes( { original: value } )
								}
								help={ __(
									'Only shown when higher than the amount payable, so an equal value cannot read as a fake discount.',
									'gated-media-access'
								) }
							/>
							<SelectControl
								label={ __( 'Currency', 'gated-media-access' ) }
								value={ currency }
								options={ CURRENCIES.map( ( code ) => ( {
									label: code,
									value: code,
								} ) ) }
								onChange={ ( value ) =>
									setAttributes( { currency: value } )
								}
								__nextHasNoMarginBottom
							/>
						</>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<p className="gatedmedia-price-block__line">
					{ showsOriginal && (
						<span className="gatedmedia-price-block__original">
							{ formatMinor( original, currency ) }
						</span>
					) }
					<span className="gatedmedia-price-block__amount">
						{ notApplicable
							? '-'
							: formatMinor( amount, currency ) }
					</span>
				</p>
				<RichText
					tagName="p"
					className="gatedmedia-price-block__term"
					value={ term }
					onChange={ ( value ) => setAttributes( { term: value } ) }
					placeholder={ __( 'One year', 'gated-media-access' ) }
					allowedFormats={ [] }
				/>
			</div>
		</>
	);
}
