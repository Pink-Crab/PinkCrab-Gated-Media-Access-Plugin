/**
 * §6.14 Coupon field — editor.
 *
 * Drawn client-side in whichever of its states is on, so the difference is
 * visible rather than described: waiting for a code, rejected, or applied.
 *
 * Applied is the one worth seeing — §6.14 says the input and its button are
 * **replaced** by a confirmation line, not annotated with a tick.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { label, applyLabel, code, applied, discount, error, removeHref } =
		attributes;
	const isApplied = !! applied && '' !== code;
	const blockProps = useBlockProps( { className: 'gatedmedia-coupon' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'State', 'gated-media-access' ) }>
					<ToggleControl
						label={ __(
							'A coupon has been applied',
							'gated-media-access'
						) }
						checked={ !! applied }
						onChange={ ( value ) =>
							setAttributes( { applied: value } )
						}
						help={ __(
							'Replaces the input and its button with a confirmation line and a Remove link.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>

					{ !! applied && '' === code && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Applied needs a code to confirm. Without one this falls back to the input.',
								'gated-media-access'
							) }
						</Notice>
					) }

					<TextControl
						label={ __( 'Code', 'gated-media-access' ) }
						value={ code }
						onChange={ ( value ) =>
							setAttributes( { code: value } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				{ isApplied ? (
					<PanelBody
						title={ __( 'Confirmation', 'gated-media-access' ) }
					>
						<TextControl
							label={ __(
								'What it took off',
								'gated-media-access'
							) }
							value={ discount }
							onChange={ ( value ) =>
								setAttributes( { discount: value } )
							}
							placeholder="£12.25"
							help={ __(
								'Written out and already formatted — wording, not an amount to calculate.',
								'gated-media-access'
							) }
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __(
								'Remove link goes to',
								'gated-media-access'
							) }
							value={ removeHref }
							onChange={ ( value ) =>
								setAttributes( { removeHref: value } )
							}
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				) : (
					<PanelBody
						title={ __( 'Rejection', 'gated-media-access' ) }
					>
						<TextControl
							label={ __(
								'Error message',
								'gated-media-access'
							) }
							value={ error }
							onChange={ ( value ) =>
								setAttributes( { error: value } )
							}
							placeholder={ __(
								'That code is not recognised.',
								'gated-media-access'
							) }
							help={ __(
								'Puts the input into its invalid state — red border, red label, message beneath.',
								'gated-media-access'
							) }
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				{ isApplied ? (
					<p className="gatedmedia-coupon__applied">
						<svg
							className="gatedmedia-icon gatedmedia-icon--small"
							aria-hidden="true"
						>
							<use href="#i-success" />
						</svg>
						<span>
							{ code }
							{ '' !== discount ? ` — ${ discount }` : '' }
						</span>
						<span className="gatedmedia-text-link">
							{ __( 'Remove', 'gated-media-access' ) }
						</span>
					</p>
				) : (
					<div className="gatedmedia-coupon__row">
						<div
							className={ `gatedmedia-field${
								'' !== error ? ' is-invalid' : ''
							}` }
						>
							<RichText
								tagName="label"
								className="gatedmedia-field__label"
								value={ label }
								onChange={ ( value ) =>
									setAttributes( { label: value } )
								}
								placeholder={ __(
									'Coupon code',
									'gated-media-access'
								) }
								allowedFormats={ [] }
							/>
							<input
								className="gatedmedia-field__input"
								value={ code }
								disabled
								readOnly
							/>
							{ '' !== error && (
								<p className="gatedmedia-field__message">
									{ error }
								</p>
							) }
						</div>
						<div className="gatedmedia-button gatedmedia-button--secondary">
							<RichText
								tagName="span"
								value={ applyLabel }
								onChange={ ( value ) =>
									setAttributes( { applyLabel: value } )
								}
								placeholder={ __(
									'Apply',
									'gated-media-access'
								) }
								allowedFormats={ [] }
							/>
						</div>
					</div>
				) }
			</div>
		</>
	);
}
