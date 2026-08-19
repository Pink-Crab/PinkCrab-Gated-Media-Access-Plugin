/**
 * §6.8 Form field — editor.
 *
 * The label and the line beneath are typed where they appear. The input itself
 * is shown but not typeable: its value is what a *visitor* would enter, not
 * what an editor writes, so it is a preview rather than a control.
 *
 * Setting an error is what puts the field into its invalid state — there is no
 * separate toggle, so the two cannot disagree. Error and help share one line
 * and never both show.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const TYPES = [
	{ label: __( 'Text', 'gated-media-access' ), value: 'text' },
	{ label: __( 'Email', 'gated-media-access' ), value: 'email' },
	{ label: __( 'Telephone', 'gated-media-access' ), value: 'tel' },
	{ label: __( 'Password', 'gated-media-access' ), value: 'password' },
	{ label: __( 'Search', 'gated-media-access' ), value: 'search' },
	{ label: __( 'Number', 'gated-media-access' ), value: 'number' },
	{ label: __( 'URL', 'gated-media-access' ), value: 'url' },
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		name,
		label,
		type,
		value,
		message,
		error,
		placeholder,
		autocomplete,
		disabled,
		required,
		multiline,
		labelHidden,
	} = attributes;

	const invalid = '' !== error;
	const shown = invalid ? error : message;

	const blockProps = useBlockProps( {
		className: [ 'gatedmedia-field', invalid ? 'is-invalid' : '' ]
			.filter( Boolean )
			.join( ' ' ),
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'The field', 'gated-media-access' ) }>
					<TextControl
						label={ __( 'Name', 'gated-media-access' ) }
						value={ name }
						onChange={ ( next ) => setAttributes( { name: next } ) }
						help={ __(
							'What the form submits it as, and its id. Without one, nothing renders.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
					{ ! multiline && (
						<SelectControl
							label={ __(
								'Kind of input',
								'gated-media-access'
							) }
							value={ type }
							options={ TYPES }
							onChange={ ( next ) =>
								setAttributes( { type: next } )
							}
							help={ __(
								'Decides the keyboard a phone shows, and what the browser validates.',
								'gated-media-access'
							) }
							__nextHasNoMarginBottom
						/>
					) }
					<ToggleControl
						label={ __( 'Multiple lines', 'gated-media-access' ) }
						checked={ !! multiline }
						onChange={ ( next ) =>
							setAttributes( { multiline: next } )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Hide the label visually',
							'gated-media-access'
						) }
						checked={ !! labelHidden }
						onChange={ ( next ) =>
							setAttributes( { labelHidden: next } )
						}
						help={ __(
							'Still read aloud. For a field whose purpose is obvious, like a search box.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'What a visitor sees', 'gated-media-access' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Prefilled value', 'gated-media-access' ) }
						value={ value }
						onChange={ ( next ) =>
							setAttributes( { value: next } )
						}
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Placeholder', 'gated-media-access' ) }
						value={ placeholder }
						onChange={ ( next ) =>
							setAttributes( { placeholder: next } )
						}
						help={ __(
							'Not a substitute for the label — it disappears as soon as someone types.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Validation', 'gated-media-access' ) }>
					<TextControl
						label={ __( 'Error', 'gated-media-access' ) }
						value={ error }
						onChange={ ( next ) =>
							setAttributes( { error: next } )
						}
						help={ __(
							'Setting this turns the field red, marks it invalid for screen readers, and shows it in place of the help text.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Behaviour', 'gated-media-access' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Required', 'gated-media-access' ) }
						checked={ !! required }
						onChange={ ( next ) =>
							setAttributes( { required: next } )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Read only', 'gated-media-access' ) }
						checked={ !! disabled }
						onChange={ ( next ) =>
							setAttributes( { disabled: next } )
						}
						help={ __(
							'Greyed and not submitted. The profile email uses this — it identifies the account.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Autofill hint', 'gated-media-access' ) }
						value={ autocomplete }
						onChange={ ( next ) =>
							setAttributes( { autocomplete: next } )
						}
						help={ __(
							'An HTML autocomplete token — given-name, family-name, postal-code, tel.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<RichText
					tagName="label"
					className={ [
						'gatedmedia-field__label',
						labelHidden ? 'gatedmedia-visually-hidden' : '',
					]
						.filter( Boolean )
						.join( ' ' ) }
					value={ label }
					onChange={ ( next ) => setAttributes( { label: next } ) }
					placeholder={ __( 'Label', 'gated-media-access' ) }
					allowedFormats={ [] }
				/>

				{ multiline ? (
					<textarea
						className="gatedmedia-field__input"
						value={ value }
						placeholder={ placeholder }
						disabled
						readOnly
					/>
				) : (
					<input
						className="gatedmedia-field__input"
						type={ type }
						value={ value }
						placeholder={ placeholder }
						disabled
						readOnly
					/>
				) }

				<RichText
					tagName="p"
					className="gatedmedia-field__message"
					value={ shown }
					onChange={ ( next ) =>
						invalid
							? setAttributes( { error: next } )
							: setAttributes( { message: next } )
					}
					placeholder={ __(
						'Help text — or set an error below',
						'gated-media-access'
					) }
					allowedFormats={ [] }
				/>
			</div>
		</>
	);
}
