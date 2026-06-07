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

	const CATEGORY_SLUG = 'wp-mariadb-vector-search';
	const ABILITY_GET_STATUS = 'wp-mariadb-vector-search/get-status';
	const ABILITY_REINDEX = 'wp-mariadb-vector-search/reindex';

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
				'label'              => __( 'Get Status', 'wp-mariadb-vector-search' ),
				'description'        => __( 'Retrieves the current plugin status.', 'wp-mariadb-vector-search' ),
				'category'           => self::CATEGORY_SLUG,
				'meta'               => array( 'show_in_rest' => true ),
				'execute_callback'   => array( __CLASS__, 'execute_get_status' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Register reindex ability.
		wp_register_ability(
			self::ABILITY_REINDEX,
			array(
				'label'              => __( 'Reindex', 'wp-mariadb-vector-search' ),
				'description'        => __( 'Triggers a full reindex of all posts.', 'wp-mariadb-vector-search' ),
				'category'           => self::CATEGORY_SLUG,
				'meta'               => array( 'show_in_rest' => true ),
				'execute_callback'   => array( __CLASS__, 'execute_reindex' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Callback for get-status ability.
	 *
	 * @param array|null $input
	 * @return array
	 */
	public static function execute_get_status( ?array $input ): array {
		// Dummy data for Green phase.
		return array(
			'is_supported'      => true,
			'installed'         => true,
			'indexed'           => 0,
			'table_dims'        => 1536,
			'progress'          => null,
			'settings'          => array(
				'provider'  => 'openai',
				'model'     => 'text-embedding-3-small',
				'dimensions' => 1536,
			),
			'available_models'  => array(),
			'dim_changed'       => false,
		);
	}

	/**
	 * Callback for reindex ability.
	 *
	 * @param array|null $input
	 * @return array
	 */
	public static function execute_reindex( ?array $input ): array {
		// Dummy result for Green phase.
		return array(
			'rebuilt' => true,
		);
	}
}
