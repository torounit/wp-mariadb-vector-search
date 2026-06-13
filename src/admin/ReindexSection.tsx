import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReindexResponse } from './types';

interface Props {
	isSupported: boolean;
	installed: boolean;
	dimChanged: boolean;
	curDims: number | null;
	tableDims: number | null;
	onReindexed: ( rebuilt: boolean ) => void;
}

export function ReindexSection( {
	isSupported,
	installed,
	dimChanged,
	curDims,
	tableDims,
	onReindexed,
}: Props ) {
	const [ force, setForce ] = useState( false );
	const [ confirmRebuild, setConfirmRebuild ] = useState( false );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	if ( ! isSupported ) {
		return null;
	}

	async function handleSubmit() {
		setIsSubmitting( true );
		setError( null );

		try {
			const result = await apiFetch< ReindexResponse >( {
				path: '/wp-abilities/v1/abilities/wp-mariadb-vector-search/reindex/run',
				method: 'POST',
				data: {
					input: {
						force,
						confirm_rebuild: confirmRebuild,
					},
				},
			} );
			const rebuilt =
				( result as { rebuilt?: boolean } )?.rebuilt ??
				( result as { result?: ReindexResponse } )?.result?.rebuilt ??
				( result as { output?: ReindexResponse } )?.output?.rebuilt ??
				( result as { data?: ReindexResponse } )?.data?.rebuilt ??
				false;
			onReindexed( rebuilt );
		} catch ( err ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'Unknown error.', 'wp-mariadb-vector-search' );
			setError( message );
		} finally {
			setIsSubmitting( false );
		}
	}

	return (
		<>
			<h2>{ __( 'Reindex', 'wp-mariadb-vector-search' ) }</h2>
			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }
			{ dimChanged && (
				<>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Selected model is',
							'wp-mariadb-vector-search'
						) }{ ' ' }
						{ curDims }
						{ '-dim but the table is ' }
						{ tableDims }
						{ __(
							'-dim. Reindexing will recreate the table and delete all existing vectors.',
							'wp-mariadb-vector-search'
						) }
					</Notice>
					<CheckboxControl
						label={ __(
							'I understand that all existing vectors will be deleted.',
							'wp-mariadb-vector-search'
						) }
						checked={ confirmRebuild }
						onChange={ setConfirmRebuild }
					/>
				</>
			) }
			{ ! dimChanged && ! installed && (
				<p className="description">
					{ __(
						'The embeddings table will be created and a full reindex will be scheduled.',
						'wp-mariadb-vector-search'
					) }
				</p>
			) }
			{ ! dimChanged && installed && (
				<CheckboxControl
					label={ __(
						'Force reindex (re-embed even unchanged posts)',
						'wp-mariadb-vector-search'
					) }
					checked={ force }
					onChange={ setForce }
				/>
			) }
			<Button
				variant={ dimChanged ? 'secondary' : 'primary' }
				isDestructive={ dimChanged }
				onClick={ () => void handleSubmit() }
				disabled={
					isSubmitting || ( dimChanged && ! confirmRebuild )
				}
			>
				{ isSubmitting ? (
					<Spinner />
				) : (
					__( 'Reindex all posts', 'wp-mariadb-vector-search' )
				) }
			</Button>
		</>
	);
}
