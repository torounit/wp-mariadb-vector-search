<?php
/**
 * Admin Tools page — status, embedding model selection, manual reindex and rebuild.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Registers the Tools > Vector Search admin page.
 */
class Admin {

	const PAGE_SLUG    = 'wp-mariadb-vector-search';
	const REINDEX_CAP  = 'manage_options';
	const ACTION_KEY   = 'wp_mariadb_vector_search_reindex';
	const SAVE_MODEL   = 'wp_mariadb_vector_search_save_model';
	const SETTINGS_KEY = 'wp_mariadb_vector_search_settings';

	/**
	 * Constructor.
	 *
	 * @param Cron_Backfill    $backfill   Backfill runner.
	 * @param Repository       $repository Embeddings table wrapper.
	 * @param Model_Catalog    $catalog    Embedding model catalog.
	 * @param Embedding_Client $client   Embedding client (used for dimension probe).
	 */
	public function __construct(
		private Cron_Backfill $backfill,
		private Repository $repository,
		private Model_Catalog $catalog,
		private Embedding_Client $client,
	) {}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_KEY, array( $this, 'handle_reindex' ) );
		add_action( 'admin_post_' . self::SAVE_MODEL, array( $this, 'handle_save_model' ) );
	}

	/**
	 * Register the Tools submenu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_management_page(
			__( 'Vector Search', 'wp-mariadb-vector-search' ),
			__( 'Vector Search', 'wp-mariadb-vector-search' ),
			self::REINDEX_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the unified reindex form submission.
	 *
	 * Behaviour depends on the relationship between the saved dimensions and the
	 * current table schema:
	 *
	 *  - Dimensions match (normal case): non-destructive backfill. The table is left
	 *    intact; each post's rows are replaced individually.
	 *  - Dimensions differ: destructive rebuild. Requires the confirm_rebuild checkbox.
	 *    Drops and recreates the table, then schedules a full force reindex.
	 *  - Table not yet installed: creates the table at the saved dimensions and
	 *    schedules a full reindex. No confirmation needed (nothing to lose).
	 *
	 * @return void
	 */
	public function handle_reindex(): void {
		if ( ! current_user_can( self::REINDEX_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-mariadb-vector-search' ) );
		}

		check_admin_referer( self::ACTION_KEY );

		$settings    = get_option( self::SETTINGS_KEY, array() );
		$saved_dims  = is_array( $settings ) && isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : Plugin::DEFAULT_DIMENSIONS;
		$installed   = Schema::is_installed();
		$table_dims  = $installed ? $this->repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && $table_dims !== $saved_dims;

		if ( $dim_changed ) {
			// Dimensions differ: destructive rebuild requires confirmation.
			if ( ! isset( $_POST['confirm_rebuild'] ) || '1' !== $_POST['confirm_rebuild'] ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'          => self::PAGE_SLUG,
							'rebuild_error' => 'no_confirm',
						),
						admin_url( 'tools.php' )
					)
				);
				exit;
			}

			Schema::drop();
			delete_option( Schema::DB_VERSION_OPTION );
			Schema::install( $saved_dims );
			$this->backfill->schedule( true );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'rebuilt' => '1',
					),
					admin_url( 'tools.php' )
				)
			);
			exit;
		}

		if ( ! $installed ) {
			// First install: create the table and schedule a full reindex.
			Schema::install( $saved_dims );
			$this->backfill->schedule( true );
		} else {
			// Normal non-destructive reindex.
			$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
			$this->backfill->schedule( $force );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'scheduled' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the save model form submission.
	 *
	 * Saves the selected provider/model to settings after probing the actual
	 * embedding dimension. Does NOT touch the database table — use Reindex for that.
	 *
	 * @return void
	 */
	public function handle_save_model(): void {
		if ( ! current_user_can( self::REINDEX_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-mariadb-vector-search' ) );
		}

		check_admin_referer( self::SAVE_MODEL );

		$raw = isset( $_POST['embedding_model'] ) ? sanitize_text_field( wp_unslash( $_POST['embedding_model'] ) ) : '';

		// Parse "provider:model".
		$colon = strpos( $raw, ':' );
		if ( false === $colon ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => self::PAGE_SLUG,
						'model_error' => 'invalid',
					),
					admin_url( 'tools.php' )
				)
			);
			exit;
		}

		$provider = substr( $raw, 0, $colon );
		$model    = substr( $raw, $colon + 1 );

		// Validate against catalog.
		$available = $this->catalog->get_available_models();
		$valid     = array_filter(
			$available,
			static fn( $m ) => $m['provider'] === $provider && $m['model'] === $model
		);
		if ( empty( $valid ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => self::PAGE_SLUG,
						'model_error' => 'invalid',
					),
					admin_url( 'tools.php' )
				)
			);
			exit;
		}

		// Temporarily save provider/model so Embedding_Client picks it up for the probe.
		$existing_settings = get_option( self::SETTINGS_KEY, array() );
		$probe_settings    = array_merge(
			is_array( $existing_settings ) ? $existing_settings : array(),
			array(
				'provider' => $provider,
				'model'    => $model,
			)
		);
		update_option( self::SETTINGS_KEY, $probe_settings );

		// Probe: generate one embedding and measure its dimension.
		$probe_result = $this->client->embed( array( 'dimension probe' ) );
		if ( is_wp_error( $probe_result ) ) {
			// Roll back settings.
			update_option( self::SETTINGS_KEY, $existing_settings );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => self::PAGE_SLUG,
						'model_error' => 'probe',
						'probe_msg'   => rawurlencode( $probe_result->get_error_message() ),
					),
					admin_url( 'tools.php' )
				)
			);
			exit;
		}

		$new_dimensions = count( $probe_result[0] ?? array() );

		// Confirm-save with dimensions.
		$final_settings = array_merge(
			is_array( $existing_settings ) ? $existing_settings : array(),
			array(
				'provider'   => $provider,
				'model'      => $model,
				'dimensions' => $new_dimensions,
			)
		);
		update_option( self::SETTINGS_KEY, $final_settings );

		// Determine whether a Rebuild is needed (table dimension differs).
		$table_dims   = $this->repository->get_column_dimensions();
		$need_rebuild = ( null === $table_dims || $table_dims !== $new_dimensions );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'model_saved'  => '1',
					'need_rebuild' => $need_rebuild ? '1' : '0',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$is_supported = Schema::is_vector_supported();
		$installed    = Schema::is_installed();
		$schema_ready = $is_supported && $installed;
		$progress     = $this->backfill->get_progress();
		$indexed      = $schema_ready ? $this->repository->count_indexed() : 0;
		$settings     = get_option( self::SETTINGS_KEY, array() );
		$cur_provider = is_array( $settings ) ? (string) ( $settings['provider'] ?? '' ) : '';
		$cur_model    = is_array( $settings ) ? (string) ( $settings['model'] ?? '' ) : '';
		$cur_dims     = is_array( $settings ) && isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : null;
		$cur_selected = '' !== $cur_provider && '' !== $cur_model ? $cur_provider . ':' . $cur_model : '';
		$available    = $this->catalog->get_available_models();
		$table_dims   = $schema_ready ? $this->repository->get_column_dimensions() : null;
		$dim_changed  = $installed && null !== $table_dims && null !== $cur_dims && $table_dims !== $cur_dims;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MariaDB Vector Search', 'wp-mariadb-vector-search' ); ?></h1>

			<?php // phpcs:disable WordPress.Security.NonceVerification ?>
			<?php if ( isset( $_GET['scheduled'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Backfill has been scheduled.', 'wp-mariadb-vector-search' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['model_saved'] ) ) : ?>
				<?php $need_rebuild = isset( $_GET['need_rebuild'] ) ? sanitize_key( wp_unslash( $_GET['need_rebuild'] ) ) : '0'; ?>
				<div class="notice <?php echo '1' === $need_rebuild ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
					<p>
					<?php if ( '1' === $need_rebuild ) : ?>
						<?php esc_html_e( 'Model saved. The table dimension has changed — please run Reindex all posts to recreate the table.', 'wp-mariadb-vector-search' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Model saved. Please run Reindex all posts to apply the new model.', 'wp-mariadb-vector-search' ); ?>
					<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['model_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<?php if ( 'probe' === $_GET['model_error'] ) : ?>
						<p>
							<?php esc_html_e( 'Model save failed: could not generate a test embedding.', 'wp-mariadb-vector-search' ); ?>
							<?php if ( isset( $_GET['probe_msg'] ) ) : ?>
								<?php $probe_msg = rawurldecode( sanitize_text_field( wp_unslash( (string) $_GET['probe_msg'] ) ) ); ?>
								<br><em><?php echo esc_html( $probe_msg ); ?></em>
							<?php endif; ?>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Invalid model selection.', 'wp-mariadb-vector-search' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rebuilt'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Table rebuilt. Reindex has been scheduled.', 'wp-mariadb-vector-search' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rebuild_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Please confirm before rebuilding.', 'wp-mariadb-vector-search' ); ?></p>
				</div>
			<?php endif; ?>
			<?php // phpcs:enable WordPress.Security.NonceVerification ?>

			<h2><?php esc_html_e( 'Status', 'wp-mariadb-vector-search' ); ?></h2>
			<table class="widefat striped" style="max-width:600px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'MariaDB VECTOR support', 'wp-mariadb-vector-search' ); ?></td>
						<td>
							<?php if ( $is_supported ) : ?>
								<span style="color:green">&#10003; <?php esc_html_e( 'Available', 'wp-mariadb-vector-search' ); ?></span>
							<?php else : ?>
								<span style="color:red">&#10007; <?php esc_html_e( 'Not available (MariaDB 11.7+ required)', 'wp-mariadb-vector-search' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Indexed posts', 'wp-mariadb-vector-search' ); ?></td>
						<td><?php echo esc_html( (string) $indexed ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Table dimensions', 'wp-mariadb-vector-search' ); ?></td>
						<td><?php echo esc_html( null !== $table_dims ? (string) $table_dims : '—' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Backfill status', 'wp-mariadb-vector-search' ); ?></td>
						<td>
							<?php if ( $progress ) : ?>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: processed posts, 2: total posts */
										__( 'Running: %1$d / %2$d posts', 'wp-mariadb-vector-search' ),
										$progress['done'],
										$progress['total']
									)
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Idle', 'wp-mariadb-vector-search' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Embedding Model', 'wp-mariadb-vector-search' ); ?></h2>
			<?php if ( empty( $available ) ) : ?>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL to the AI Connector settings page */
							__( 'No embedding models available. Configure an AI provider in <a href="%s">Settings &rsaquo; General &rsaquo; AI Connector</a>.', 'wp-mariadb-vector-search' ),
							esc_url( admin_url( 'options-general.php' ) )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_MODEL ); ?>">
					<?php wp_nonce_field( self::SAVE_MODEL ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="embedding_model"><?php esc_html_e( 'Model', 'wp-mariadb-vector-search' ); ?></label>
							</th>
							<td>
								<select id="embedding_model" name="embedding_model">
									<?php foreach ( $available as $m ) : ?>
											<option value="<?php echo esc_attr( $m['provider'] . ':' . $m['model'] ); ?>" <?php selected( $cur_selected, $m['provider'] . ':' . $m['model'] ); ?>>
											<?php echo esc_html( '[' . $m['provider'] . '] ' . $m['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( null !== $cur_dims ) : ?>
									<p class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: current dimension count */
												__( 'Current saved dimensions: %d', 'wp-mariadb-vector-search' ),
												$cur_dims
											)
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save model', 'wp-mariadb-vector-search' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( $is_supported ) : ?>
				<h2><?php esc_html_e( 'Reindex', 'wp-mariadb-vector-search' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_KEY ); ?>">
					<?php wp_nonce_field( self::ACTION_KEY ); ?>
					<?php if ( $dim_changed ) : ?>
						<p class="notice notice-warning inline">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: saved model dimensions, 2: current table dimensions */
									__( 'Selected model is %1$d-dim but the table is %2$d-dim. Reindexing will recreate the table and delete all existing vectors.', 'wp-mariadb-vector-search' ),
									$cur_dims,
									$table_dims
								)
							);
							?>
						</p>
						<p>
							<label>
								<input type="checkbox" name="confirm_rebuild" value="1">
								<?php esc_html_e( 'I understand that all existing vectors will be deleted.', 'wp-mariadb-vector-search' ); ?>
							</label>
						</p>
					<?php elseif ( ! $installed ) : ?>
						<p class="description">
							<?php
							$install_dims = null !== $cur_dims ? $cur_dims : Plugin::DEFAULT_DIMENSIONS;
							echo esc_html(
								sprintf(
									/* translators: %d: number of vector dimensions */
									__( 'The embeddings table will be created at %d dimensions.', 'wp-mariadb-vector-search' ),
									$install_dims
								)
							);
							?>
						</p>
					<?php else : ?>
						<p>
							<label>
								<input type="checkbox" name="force" value="1">
								<?php esc_html_e( 'Force reindex (re-embed even unchanged posts)', 'wp-mariadb-vector-search' ); ?>
							</label>
						</p>
					<?php endif; ?>
					<?php submit_button( __( 'Reindex all posts', 'wp-mariadb-vector-search' ), $dim_changed ? 'delete' : 'primary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
