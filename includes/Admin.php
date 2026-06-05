<?php
/**
 * Admin Tools page — status + manual reindex trigger.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Registers the Tools > Vector Search admin page.
 */
class Admin {

	const PAGE_SLUG   = 'wp-mariadb-vector-search';
	const REINDEX_CAP = 'manage_options';
	const ACTION_KEY  = 'wp_mariadb_vector_search_reindex';

	/**
	 * Constructor.
	 *
	 * @param Cron_Backfill $backfill   Backfill runner.
	 * @param Repository    $repository Embeddings table wrapper.
	 */
	public function __construct(
		private Cron_Backfill $backfill,
		private Repository $repository,
	) {}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_KEY, array( $this, 'handle_reindex' ) );
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
	 * Handle the reindex form submission.
	 *
	 * @return void
	 */
	public function handle_reindex(): void {
		if ( ! current_user_can( self::REINDEX_CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-mariadb-vector-search' ) );
		}

		check_admin_referer( self::ACTION_KEY );

		$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
		$this->backfill->schedule( $force );

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
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$is_supported = Schema::is_vector_supported();
		$schema_ready = $is_supported && Schema::is_installed();
		$progress     = $this->backfill->get_progress();
		$indexed      = $schema_ready ? $this->repository->count_indexed() : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MariaDB Vector Search', 'wp-mariadb-vector-search' ); ?></h1>

			<?php if ( isset( $_GET['scheduled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Backfill has been scheduled.', 'wp-mariadb-vector-search' ); ?></p>
				</div>
			<?php endif; ?>

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

			<?php if ( $schema_ready ) : ?>
				<h2><?php esc_html_e( 'Reindex', 'wp-mariadb-vector-search' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_KEY ); ?>">
					<?php wp_nonce_field( self::ACTION_KEY ); ?>
					<p>
						<label>
							<input type="checkbox" name="force" value="1">
							<?php esc_html_e( 'Force reindex (re-embed even unchanged posts)', 'wp-mariadb-vector-search' ); ?>
						</label>
					</p>
					<?php submit_button( __( 'Reindex all posts', 'wp-mariadb-vector-search' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
