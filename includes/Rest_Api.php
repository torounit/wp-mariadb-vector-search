<?php
/**
 * REST API endpoints for the admin page.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Registers REST API routes for the Vector Search admin page.
 *
 * Namespace: wp-mariadb-vector-search/v1
 * All endpoints require manage_options capability.
 */
class Rest_Api {

	const NAMESPACE = 'wp-mariadb-vector-search/v1';

	/**
	 * Constructor.
	 *
	 * @param Cron_Backfill    $backfill   Backfill runner.
	 * @param Repository       $repository Embeddings table wrapper.
	 * @param Model_Catalog    $catalog    Embedding model catalog.
	 * @param Embedding_Client $client     Embedding client for dimension probe.
	 */
	public function __construct(
		private Cron_Backfill $backfill,
		private Repository $repository,
		private Model_Catalog $catalog,
		private Embedding_Client $client,
	) {}

	/**
	 * Register REST API hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/save-model',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_model' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reindex',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reindex' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'force'           => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'confirm_rebuild' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Permission callback — requires manage_options.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'wp-mariadb-vector-search' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /status — return current plugin state.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status(): \WP_REST_Response {
		$is_supported = Schema::is_vector_supported();
		$installed    = Schema::is_installed();
		$schema_ready = $is_supported && $installed;

		$settings     = get_option( Admin::SETTINGS_KEY, array() );
		$cur_provider = is_array( $settings ) ? (string) ( $settings['provider'] ?? '' ) : '';
		$cur_model    = is_array( $settings ) ? (string) ( $settings['model'] ?? '' ) : '';
		$cur_dims     = is_array( $settings ) && isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : null;

		$table_dims  = $schema_ready ? $this->repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && null !== $cur_dims && $table_dims !== $cur_dims;

		return new \WP_REST_Response(
			array(
				'is_supported'     => $is_supported,
				'installed'        => $installed,
				'indexed'          => $schema_ready ? $this->repository->count_indexed() : 0,
				'table_dims'       => $table_dims,
				'progress'         => $this->backfill->get_progress(),
				'settings'         => array(
					'provider'   => $cur_provider,
					'model'      => $cur_model,
					'dimensions' => $cur_dims,
				),
				'available_models' => $this->catalog->get_available_models(),
				'dim_changed'      => $dim_changed,
			)
		);
	}

	/**
	 * POST /save-model — probe the selected model and persist settings.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_model( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$provider = (string) $request->get_param( 'provider' );
		$model    = (string) $request->get_param( 'model' );

		// Validate against the catalog.
		$available = $this->catalog->get_available_models();
		$valid     = array_filter(
			$available,
			static fn( $m ) => $m['provider'] === $provider && $m['model'] === $model
		);
		if ( empty( $valid ) ) {
			return new \WP_Error(
				'invalid_model',
				__( 'Invalid model selection.', 'wp-mariadb-vector-search' ),
				array( 'status' => 400 )
			);
		}

		$existing_settings = get_option( Admin::SETTINGS_KEY, array() );
		$probe_settings    = array_merge(
			is_array( $existing_settings ) ? $existing_settings : array(),
			array(
				'provider' => $provider,
				'model'    => $model,
			)
		);
		update_option( Admin::SETTINGS_KEY, $probe_settings );

		$probe_result = $this->client->embed( array( 'dimension probe' ) );
		if ( is_wp_error( $probe_result ) ) {
			update_option( Admin::SETTINGS_KEY, $existing_settings );
			return new \WP_Error(
				'probe_failed',
				$probe_result->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$new_dimensions = count( $probe_result[0] ?? array() );
		$final_settings = array_merge(
			is_array( $existing_settings ) ? $existing_settings : array(),
			array(
				'provider'   => $provider,
				'model'      => $model,
				'dimensions' => $new_dimensions,
			)
		);
		update_option( Admin::SETTINGS_KEY, $final_settings );

		$table_dims   = $this->repository->get_column_dimensions();
		$need_rebuild = ( null === $table_dims || $table_dims !== $new_dimensions );

		return new \WP_REST_Response(
			array(
				'dimensions'   => $new_dimensions,
				'need_rebuild' => $need_rebuild,
			)
		);
	}

	/**
	 * POST /reindex — schedule a backfill or rebuild the table.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reindex( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$force           = (bool) $request->get_param( 'force' );
		$confirm_rebuild = (bool) $request->get_param( 'confirm_rebuild' );

		$settings   = get_option( Admin::SETTINGS_KEY, array() );
		$saved_dims = is_array( $settings ) && isset( $settings['dimensions'] )
			? (int) $settings['dimensions']
			: Plugin::DEFAULT_DIMENSIONS;

		$installed   = Schema::is_installed();
		$table_dims  = $installed ? $this->repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && $table_dims !== $saved_dims;

		if ( $dim_changed ) {
			if ( ! $confirm_rebuild ) {
				return new \WP_Error(
					'confirm_required',
					__( 'Please confirm before rebuilding.', 'wp-mariadb-vector-search' ),
					array( 'status' => 400 )
				);
			}

			Schema::drop();
			delete_option( Schema::DB_VERSION_OPTION );
			Schema::install( $saved_dims );
			$this->backfill->schedule( true );

			return new \WP_REST_Response( array( 'rebuilt' => true ) );
		}

		if ( ! $installed ) {
			Schema::install( $saved_dims );
			$this->backfill->schedule( true );
		} else {
			$this->backfill->schedule( $force );
		}

		return new \WP_REST_Response( array( 'rebuilt' => false ) );
	}
}
