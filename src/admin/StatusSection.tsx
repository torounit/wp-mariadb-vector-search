import { __ } from '@wordpress/i18n';
import type { Progress, StatusResponse } from './types';

interface Props {
	isSupported: StatusResponse[ 'is_supported' ];
	installed: StatusResponse[ 'installed' ];
	indexed: StatusResponse[ 'indexed' ];
	tableDims: StatusResponse[ 'table_dims' ];
	progress: Progress | null;
}

export function StatusSection( {
	isSupported,
	installed,
	indexed,
	tableDims,
	progress,
}: Props ) {
	return (
		<>
			<h2>{ __( 'Status', 'wp-mariadb-vector-search' ) }</h2>
			<table className="widefat striped" style={ { maxWidth: '600px' } }>
				<tbody>
					<tr>
						<td>
							{ __( 'MariaDB VECTOR support', 'wp-mariadb-vector-search' ) }
						</td>
						<td>
							{ isSupported ? (
								<span style={ { color: 'green' } }>
									{ '✓ ' }
									{ __( 'Available', 'wp-mariadb-vector-search' ) }
								</span>
							) : (
								<span style={ { color: 'red' } }>
									{ '✗ ' }
									{ __(
										'Not available (MariaDB 11.7+ required)',
										'wp-mariadb-vector-search'
									) }
								</span>
							) }
						</td>
					</tr>
					<tr>
						<td>{ __( 'Indexed posts', 'wp-mariadb-vector-search' ) }</td>
						<td>{ installed ? indexed : '—' }</td>
					</tr>
					<tr>
						<td>{ __( 'Table dimensions', 'wp-mariadb-vector-search' ) }</td>
						<td>{ tableDims ?? '—' }</td>
					</tr>
					<tr>
						<td>{ __( 'Backfill status', 'wp-mariadb-vector-search' ) }</td>
						<td>
							{ progress
								? `${ progress.done } / ${ progress.total } posts`
								: __( 'Idle', 'wp-mariadb-vector-search' ) }
						</td>
					</tr>
				</tbody>
			</table>
		</>
	);
}
