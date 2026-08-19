/**
 * §6.12 Contents list — editor.
 *
 * Each line is typed in the list itself; the sidebar carries only the icons
 * and the ordering, which cannot be typed.
 *
 * It is a statement of contents, not a list you can act on — no rules between
 * rows, no right-hand column, no actions. That is the whole difference from a
 * Row (§6.2), and it is why this is not a list of Rows.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { IconControl } from '../../assets/js/editor/controls';

export default function Edit( { attributes, setAttributes } ) {
	const { items, note } = attributes;
	const list = Array.isArray( items ) ? items : [];

	const blockProps = useBlockProps();

	const update = ( index, patch ) =>
		setAttributes( {
			items: list.map( ( item, i ) =>
				i === index ? { ...item, ...patch } : item
			),
		} );

	const remove = ( index ) =>
		setAttributes( { items: list.filter( ( item, i ) => i !== index ) } );

	const move = ( index, by ) => {
		const target = index + by;

		if ( target < 0 || target >= list.length ) {
			return;
		}

		const next = [ ...list ];
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		setAttributes( { items: next } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Items', 'gated-media-access' ) }>
					{ 0 === list.length && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'With no items this renders nothing.',
								'gated-media-access'
							) }
						</Notice>
					) }

					{ list.map( ( item, index ) => (
						<div
							key={ index }
							style={ {
								border: '1px solid #ddd',
								padding: '12px',
								marginBottom: '12px',
							} }
						>
							<IconControl
								label={ sprintf(
									/* translators: %d: position in the list. */
									__( 'Item %d icon', 'gated-media-access' ),
									index + 1
								) }
								value={ item.icon || '' }
								onChange={ ( value ) =>
									update( index, { icon: value } )
								}
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
								items: [ ...list, { text: '', icon: 'i-doc' } ],
							} )
						}
					>
						{ __( 'Add an item', 'gated-media-access' ) }
					</Button>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ul className="gatedmedia-contents">
					{ list.map( ( item, index ) => (
						<li className="gatedmedia-contents__item" key={ index }>
							{ item.icon && (
								<svg
									className="gatedmedia-icon gatedmedia-contents__icon"
									aria-hidden="true"
								>
									<use href={ `#${ item.icon }` } />
								</svg>
							) }
							<RichText
								tagName="span"
								value={ item.text || '' }
								onChange={ ( value ) =>
									update( index, { text: value } )
								}
								placeholder={ __(
									'12 documents',
									'gated-media-access'
								) }
								allowedFormats={ [] }
							/>
						</li>
					) ) }
				</ul>

				<RichText
					tagName="p"
					className="gatedmedia-contents__note"
					value={ note }
					onChange={ ( value ) => setAttributes( { note: value } ) }
					placeholder={ __(
						'Optional note — e.g. contents as they were on the order date',
						'gated-media-access'
					) }
					allowedFormats={ [] }
				/>
			</div>
		</>
	);
}
