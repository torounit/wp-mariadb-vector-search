import apiFetch from '@wordpress/api-fetch';
import { Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Page } from '@wordpress/admin-ui';
import { ModelSelector } from './ModelSelector';
import { ReindexSection } from './ReindexSection';
import { StatusSection } from './StatusSection';
import type { StatusResponse } from './types';

type AppStatus =
	| { phase: 'loading' }
	| { phase: 'error'; message: string }
	| { phase: 'ready'; data: StatusResponse };

export function VectorSearchApp() {
	const [ status, setStatus ] = useState< AppStatus >( { phase: 'loading' } );
	const [ successMessage, setSuccessMessage ] = useState< string | null >(
		null
	);

	async function loadStatus() {
		setStatus( { phase: 'loading' } );
		try {
			const data = await apiFetch< StatusResponse >( {
				path: '/wp-abilities/v1/abilities/wp-mariadb-vector-search/get-status',
			} );
			setStatus( { phase: 'ready', data } );
		} catch ( err ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'Failed to load status.', 'wp-mariadb-vector-search' );
			setStatus( { phase: 'error', message } );
		}
	}

	useEffect( () => {
		void loadStatus();
	}, [] );

	return (
		<Page
			hasPadding
			title={ __( 'Vector Search', 'wp-mariadb-vector-search' ) }>
			{ successMessage && (
				<Notice
					status="success"
					onRemove={ () => setSuccessMessage( null ) }
				>
					{ successMessage }
				</Notice>
			) }

			{ status.phase === 'loading' && <Spinner /> }

			{ status.phase === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ status.message }
				</Notice>
			) }

			{ status.phase === 'ready' && (
				<>
					<StatusSection
						isSupported={ status.data.is_supported }
						installed={ status.data.installed }
						indexed={ status.data.indexed }
						tableDims={ status.data.table_dims }
						progress={ status.data.progress }
					/>

					<h2>
						{ __(
							'Embedding Model',
							'wp-mariadb-vector-search'
						) }
					</h2>
					<ModelSelector
						availableModels={ status.data.available_models }
						currentProvider={ status.data.settings.provider }
						currentModel={ status.data.settings.model }
						currentDims={ status.data.settings.dimensions }
						onSaved={ ( _dims, needRebuild ) => {
							setSuccessMessage(
								needRebuild
									? __(
											'Model saved. The table dimension has changed — please run Reindex all posts to recreate the table.',
											'wp-mariadb-vector-search'
									  )
									: __(
											'Model saved. Please run Reindex all posts to apply the new model.',
											'wp-mariadb-vector-search'
									  )
							);
							void loadStatus();
						} }
					/>

					<ReindexSection
						isSupported={ status.data.is_supported }
						installed={ status.data.installed }
						dimChanged={ status.data.dim_changed }
						curDims={ status.data.settings.dimensions }
						tableDims={ status.data.table_dims }
						onReindexed={ ( rebuilt ) => {
							setSuccessMessage(
								rebuilt
									? __(
											'Table rebuilt. Reindex has been scheduled.',
											'wp-mariadb-vector-search'
									  )
									: __(
											'Backfill has been scheduled.',
											'wp-mariadb-vector-search'
									  )
							);
							void loadStatus();
						} }
					/>
				</>
			) }
		</Page>
	);
}
