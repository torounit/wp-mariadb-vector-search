import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { AvailableModel, SettingsApiResponse } from './types';

interface Props {
	availableModels: AvailableModel[];
	currentProvider: string;
	currentModel: string;
	currentDims: number | null;
	onSaved: ( dims: number, needRebuild: boolean ) => void;
}

export function ModelSelector( {
	availableModels,
	currentProvider,
	currentModel,
	currentDims,
	onSaved,
}: Props ) {
	const currentValue =
		currentProvider && currentModel
			? `${ currentProvider }:${ currentModel }`
			: '';

	const [ selected, setSelected ] = useState( currentValue );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ dimsInput, setDimsInput ] = useState(
		String( currentDims ?? '' )
	);

	if ( availableModels.length === 0 ) {
		return (
			<p>
				{ __(
					'No embedding models available. Configure an AI provider in Settings › AI Connector.',
					'wp-mariadb-vector-search'
				) }
			</p>
		);
	}

	const options = availableModels.map( ( m ) => ( {
		value: `${ m.provider }:${ m.model }`,
		label: m.dimensions
			? `[${ m.provider }] ${ m.label } (${ m.dimensions }-dim)`
			: `[${ m.provider }] ${ m.label }`,
	} ) );

	// Resolve the currently selected model's known dimensions for preview.
	const selectedColon = selected.indexOf( ':' );
	const selectedProvider =
		selectedColon >= 0 ? selected.slice( 0, selectedColon ) : '';
	const selectedModelId =
		selectedColon >= 0 ? selected.slice( selectedColon + 1 ) : '';
	const selectedModel = availableModels.find(
		( m ) => m.provider === selectedProvider && m.model === selectedModelId
	);
	const selectedDims = selectedModel?.dimensions ?? null;
	const isModelChanged = selected !== currentValue;

	// Effective dimensions: catalog value (authoritative) > user input > current saved.
	const effectiveDims = selectedDims ?? ( parseInt( dimsInput, 10 ) || null );
	const dimWillChange =
		effectiveDims !== null && effectiveDims !== currentDims;
	const dimUnknown = selectedDims === null;

	function handleModelChange( value: string ) {
		setSelected( value );
		// Pre-fill dimensions from catalog when the new model has known dims.
		const colon = value.indexOf( ':' );
		const prov = colon >= 0 ? value.slice( 0, colon ) : '';
		const mod = colon >= 0 ? value.slice( colon + 1 ) : '';
		const m = availableModels.find(
			( am ) => am.provider === prov && am.model === mod
		);
		if ( m?.dimensions ) {
			setDimsInput( String( m.dimensions ) );
		}
	}

	async function handleSave() {
		if ( selectedColon < 0 ) {
			return;
		}
		if ( ! effectiveDims ) {
			setError(
				__(
					'Please enter the embedding dimensions for this model.',
					'wp-mariadb-vector-search'
				)
			);
			return;
		}

		setIsSaving( true );
		setError( null );

		try {
			const result = await apiFetch< SettingsApiResponse >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					wp_mariadb_vector_search_settings: {
						provider: selectedProvider,
						model: selectedModelId,
						dimensions: effectiveDims,
					},
				},
			} );
			// The Settings API returns the updated option.
			// We need to determine if dimensions changed to trigger a rebuild.
			const newDims = result.wp_mariadb_vector_search_settings.dimensions ?? null;
			const needRebuild = newDims !== null && newDims !== currentDims;

			onSaved( newDims ?? effectiveDims ?? currentDims ?? 0, needRebuild );
		} catch ( err ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'Unknown error.', 'wp-mariadb-vector-search' );
			setError( message );
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<>
			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }
			{ dimWillChange && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This model uses different dimensions than the current table. Saving will require a table rebuild.',
						'wp-mariadb-vector-search'
					) }{ ' ' }
					{ `(${ currentDims } → ${ effectiveDims })` }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Model', 'wp-mariadb-vector-search' ) }
				value={ selected }
				options={ options }
				onChange={ handleModelChange }
			/>
			<TextControl
				label={ __( 'Embedding dimensions', 'wp-mariadb-vector-search' ) }
				type="number"
				value={ selectedDims !== null ? String( selectedDims ) : dimsInput }
				onChange={ ( v ) => {
					if ( selectedDims === null ) {
						setDimsInput( v );
					}
				} }
				readOnly={ selectedDims !== null }
				help={
					selectedDims !== null
						? __(
								'Dimensions are set by the selected model.',
								'wp-mariadb-vector-search'
						  )
						: __(
								'Enter the embedding dimensions for this model (check model documentation).',
								'wp-mariadb-vector-search'
						  )
				}
			/>
			<Button
				variant="primary"
				onClick={ () => void handleSave() }
				disabled={ isSaving || selected === '' }
			>
				{ isSaving ? (
					<Spinner />
				) : (
					__( 'Save model', 'wp-mariadb-vector-search' )
				) }
			</Button>
		</>
	);
}
