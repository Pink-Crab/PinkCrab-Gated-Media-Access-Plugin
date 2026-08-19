/**
 * §6.7 Price, inline — editor.
 *
 * Drawn client-side, so what you see updates as you type rather than after a
 * round trip. Zero shows as "Free" here exactly as it will on the page, which
 * is the rule most worth seeing rather than being told.
 *
 * Amounts are entered in pounds and stored in pence (specification.md §1a).
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	MoneyControl,
	formatMinor,
	CURRENCIES,
} from '../../assets/js/editor/controls';

export default function Edit( { attributes, setAttributes } ) {
	const { amount, original, currency, notApplicable } = attributes;
	// Block-level host with the inline price inside, matching render.php.
	const blockProps = useBlockProps( { className: 'gatedmedia-inline-host' } );
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
							'For access an administrator granted. Renders an em dash.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>

					{ ! notApplicable && (
						<>
							<MoneyControl
								label={ __(
									'Amount paid',
									'gated-media-access'
								) }
								value={ amount }
								onChange={ ( value ) =>
									setAttributes( { amount: value } )
								}
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
									'Struck through beside the amount paid. Ignored unless it is higher.',
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
				<span className="gatedmedia-price">
					{ showsOriginal && (
						<span className="gatedmedia-price__original">
							{ formatMinor( original, currency ) }
						</span>
					) }
					{ notApplicable ? '—' : formatMinor( amount, currency ) }
				</span>
			</div>
		</>
	);
}
