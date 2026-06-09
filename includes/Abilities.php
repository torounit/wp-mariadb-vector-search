<?php
/**
 * Abilities registration for the plugin.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Handles registration of Abilities and Categories.
 */
class Abilities {

	const CATEGORY_SLUG      = 'wp-mariadb-vector-search';
	const ABILITY_GET_STATUS = 'wp-mariadb-vector-search/get-status';
	const ABILITY_REINDEX    = 'wp-mariadb-vector-search/reindex';

	/**
	 * Register abilities and categories.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public static function register_categories(): void {
		wp_register_ability_category(
			self::CATEGORY_SLUG,
			array(
				'label'       => __( 'Vector Search', 'wp-mariadb-vector-search' ),
				'description' => __( 'Abilities for MariaDB Vector Search.', 'wp-mariadb-vector-search' ),
			)
		);
	}

	/**
	 * Register abilities.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		// Register get-status ability.
		wp_register_ability(
			self::ABILITY_GET_STATUS,
			array(
				'label'               => __( 'Get Status', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Retrieves the current plugin status.', 'wp-mariadb-vector-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_status' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register reindex ability.
		wp_register_ability(
			self::ABILITY_REINDEX,
			array(
				'label'               => __( 'Reindex', 'wp-mariadb-vector-search' ),
				'description'         => __( 'Triggers a full reindex of all posts.', 'wp-mariadb-vector-search' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => array( 'show_in_rest' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'force'           => array(
							'type'        => 'boolean',
							'description' => __( 'Force a full rebuild.', 'wp-mariadb-vector-search' ),
							'default'     => false,
						),
						'confirm_rebuild' => array(
							'type'        => 'boolean',
							'description' => __( 'Confirm the rebuild process.', 'wp-mariadb-vector-search' ),
							'default'     => false,
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_reindex' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Callback for get-status ability.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return array
	 */
	public static function execute_get_status( ?array $input = null ): array {
		unset( $input );

		$repository   = new Repository();
		$catalog      = Model_Catalog::create();
		$is_supported = Schema::is_vector_supported();
		$installed    = Schema::is_installed();
		$schema_ready = $is_supported && $installed;

		$settings     = get_option( Admin::SETTINGS_KEY, array() );
		$cur_provider = is_array( $settings ) ? (string) ( $settings['provider'] ?? '' ) : '';
		$cur_model    = is_array( $settings ) ? (string) ( $settings['model'] ?? '' ) : '';
		$cur_dims     = is_array( $settings ) && isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : null;

		$table_dims  = $schema_ready ? $repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && null !== $cur_dims && $table_dims !== $cur_dims;
		$progress    = get_transient( Cron_Backfill::PROGRESS_KEY );

		return array(
			'is_supported'     => $is_supported,
			'installed'        => $installed,
			'indexed'          => $schema_ready ? $repository->count_indexed() : 0,
			'table_dims'       => $table_dims,
			'progress'         => $progress ? $progress : null,
			'settings'         => array(
				'provider'   => $cur_provider,
				'model'      => $cur_model,
				'dimensions' => $cur_dims,
			),
			'available_models' => $catalog->get_available_models(),
			'dim_changed'      => $dim_changed,
		);
	}

	/**
	 * Callback for reindex ability.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute_reindex( ?array $input = null ): array|\WP_Error {
		$force = is_array( $input ) && isset( $input['force'] )
			? (bool) $input['force']
			: false;

		$confirm_rebuild = is_array( $input ) && isset( $input['confirm_rebuild'] )
			? (bool) $input['confirm_rebuild']
			: false;

		$settings   = get_option( Admin::SETTINGS_KEY, array() );
		$saved_dims = is_array( $settings ) && isset( $settings['dimensions'] )
			? (int) $settings['dimensions']
			: Plugin::DEFAULT_DIMENSIONS;

		$repository  = new Repository();
		$installed   = Schema::is_installed();
		$table_dims  = $installed ? $repository->get_column_dimensions() : null;
		$dim_changed = $installed && null !== $table_dims && $table_dims !== $saved_dims;

		$indexer  = new Indexer( new Embedding_Client(), $repository );
		$backfill = new Cron_Backfill( $indexer );

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
			$backfill->schedule( true );

			return array( 'rebuilt' => true );
		}

		if ( ! $installed ) {
			Schema::install( $saved_dims );
			$backfill->schedule( true );
		} else {
			$backfill->schedule( $force );
		}

		return array( 'rebuilt' => false );
	}
}
