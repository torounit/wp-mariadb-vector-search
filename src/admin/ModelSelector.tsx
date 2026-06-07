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
		label: `[${ m.provider }] ${ m.label }`,
	} ) );

	async function handleSave() {
		const colon = selected.indexOf( ':' );
		if ( colon < 0 ) {
			return;
		}
		const provider = selected.slice( 0, colon );
		const model = selected.slice( colon + 1 );

		setIsSaving( true );
		setError( null );

		try {
			const result = await apiFetch< SettingsApiResponse >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					wp_mariadb_vector_search_settings: {
						provider,
						model,
					},
				},
			} );
			// The Settings API returns the updated option.
			// We need to determine if dimensions changed to trigger a rebuild.
			const newDims = result.wp_mariadb_vector_search_settings.dimensions;
			const needRebuild = newDims !== currentDims;

			onSaved( newDims ?? 0, needRebuild );
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
