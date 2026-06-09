import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
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
	const dimWillChange =
		selectedDims !== null && isModelChanged && selectedDims !== currentDims;
	const dimUnknown = selectedDims === null && isModelChanged;

	async function handleSave() {
		if ( selectedColon < 0 ) {
			return;
		}

		setIsSaving( true );
		setError( null );

		try {
			const payload: Record< string, unknown > = {
				provider: selectedProvider,
				model: selectedModelId,
			};
			if ( selectedDims !== null ) {
				payload.dimensions = selectedDims;
			}
			const result = await apiFetch< SettingsApiResponse >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { wp_mariadb_vector_search_settings: payload },
			} );
			// The Settings API returns the updated option.
			// We need to determine if dimensions changed to trigger a rebuild.
			const newDims = result.wp_mariadb_vector_search_settings.dimensions ?? null;
			const needRebuild = newDims !== null && newDims !== currentDims;

			onSaved( newDims ?? selectedDims ?? currentDims ?? 0, needRebuild );
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
					{ `(${ currentDims } → ${ selectedDims })` }
				</Notice>
			) }
			{ dimUnknown && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Dimensions for this model are unknown. If it uses different dimensions than the current table, a rebuild will be required. Check the model documentation.',
						'wp-mariadb-vector-search'
					) }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Model', 'wp-mariadb-vector-search' ) }
				value={ selected }
				options={ options }
				onChange={ setSelected }
			/>
			{ currentDims !== null && (
				<p className="description">
					{ `${ __( 'Current saved dimensions:', 'wp-mariadb-vector-search' ) } ${ currentDims }` }
				</p>
			) }
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
