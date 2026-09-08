/**
 * The Filter block, editor side.
 *
 * Drawn client-side, both controls at once, because they are the same list shown two ways, a dropdown above 782px and chips below it, and search is never dropped on narrow, which is easier to keep true with both in front of you.
 *
 * The types are filled at render time: `files/render.php` passes the list and the block is not in the inserter, so the repeater draws the preview rather than offering a site a list to edit.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	Button,
	SelectControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { searchLabel, typeLabel, types, active } = attributes;
	const list = Array.isArray( types ) ? types : [];

	const blockProps = useBlockProps();

	const update = ( index, patch ) =>
		setAttributes( {
			types: list.map( ( type, i ) =>
				i === index ? { ...type, ...patch } : type
			),
		} );

	const remove = ( index ) =>
		setAttributes( { types: list.filter( ( type, i ) => i !== index ) } );

	const move = ( index, by ) => {
		const target = index + by;

		if ( target < 0 || target >= list.length ) {
			return;
		}

		const next = [ ...list ];
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		setAttributes( { types: next } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Search', 'gated-media-access' ) }>
					<TextControl
						label={ __( 'Search label', 'gated-media-access' ) }
						value={ searchLabel }
						onChange={ ( value ) =>
							setAttributes( { searchLabel: value } )
						}
						placeholder={ __(
							'Search files',
							'gated-media-access'
						) }
						help={ __(
							'Used as the placeholder and read aloud. Shows at every width, never dropped on narrow.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __(
							'Label for the type filter',
							'gated-media-access'
						) }
						value={ typeLabel }
						onChange={ ( value ) =>
							setAttributes( { typeLabel: value } )
						}
						placeholder={ __(
							'Filter by type',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Types', 'gated-media-access' ) }>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'One list, drawn twice. Editing here changes both the dropdown and the chips.',
							'gated-media-access'
						) }
					</Notice>

					{ list.map( ( type, index ) => (
						<div
							key={ index }
							style={ {
								border: '1px solid #ddd',
								padding: '12px',
								marginBottom: '12px',
							} }
						>
							<TextControl
								label={ sprintf(
									/* translators: %d: position in the list. */
									__(
										'Type %d, shown',
										'gated-media-access'
									),
									index + 1
								) }
								value={ type.label || '' }
								onChange={ ( value ) =>
									update( index, { label: value } )
								}
								placeholder="PDF"
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __(
									'Stored as',
									'gated-media-access'
								) }
								value={ type.value || '' }
								onChange={ ( value ) =>
									update( index, { value } )
								}
								placeholder="pdf"
								help={ __(
									'What the filter matches on.',
									'gated-media-access'
								) }
								__nextHasNoMarginBottom
							/>
							<div style={ { display: 'flex', gap: '4px' } }>
								<Button
									size="small"
									variant="tertiary"
									disabled={ 0 === index }
									onClick={ () => move( index, -1 ) }
								>
									{ __( 'Up', 'gated-media-access' ) }
								</Button>
								<Button
									size="small"
									variant="tertiary"
									disabled={ index === list.length - 1 }
									onClick={ () => move( index, 1 ) }
								>
									{ __( 'Down', 'gated-media-access' ) }
								</Button>
								<Button
									size="small"
									isDestructive
									onClick={ () => remove( index ) }
								>
									{ __( 'Remove', 'gated-media-access' ) }
								</Button>
							</div>
						</div>
					) ) }

					<Button
						variant="secondary"
						onClick={ () =>
							setAttributes( {
								types: [ ...list, { value: '', label: '' } ],
							} )
						}
					>
						{ __( 'Add a type', 'gated-media-access' ) }
					</Button>
				</PanelBody>

				{ list.length > 0 && (
					<PanelBody
						title={ __( 'Starting state', 'gated-media-access' ) }
						initialOpen={ false }
					>
						<SelectControl
							label={ __(
								'Selected to begin with',
								'gated-media-access'
							) }
							value={ active }
							options={ list.map( ( type ) => ( {
								label: type.label || type.value,
								value: type.value,
							} ) ) }
							onChange={ ( value ) =>
								setAttributes( { active: value } )
							}
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				<div className="gatedmedia-filter">
					<div className="gatedmedia-filter__search gatedmedia-field">
						<input
							className="gatedmedia-field__input"
							type="search"
							placeholder={
								searchLabel ||
								__( 'Search files', 'gated-media-access' )
							}
							disabled
							readOnly
						/>
					</div>
					{ list.length > 0 && (
						<select className="gatedmedia-filter__type" disabled>
							{ list.map( ( type, index ) => (
								<option key={ index }>
									{ type.label || type.value }
								</option>
							) ) }
						</select>
					) }
				</div>

				{ list.length > 0 && (
					<div
						className="gatedmedia-type-chips"
						style={ { display: 'flex' } }
					>
						{ list.map( ( type, index ) => (
							<span
								key={ index }
								className={ `gatedmedia-type-chips__chip${
									type.value === active ? ' is-active' : ''
								}` }
							>
								{ type.label || type.value }
							</span>
						) ) }
					</div>
				) }
			</div>
		</>
	);
}
