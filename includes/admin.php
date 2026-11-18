<?php
/**
 * Admin integration: options page, media sync actions and workarounds.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload;

use WP_Post;
use WP_Query;

class Admin {

	/**
	 * Active plugins, refreshed on admin_init.
	 *
	 * @var string[]
	 */
	private static array $active_plugins = array();

	public function load_hooks(): void {
		add_action( 'admin_init', array( $this, 'admin_init' ), 999 );
		add_filter( 'media_row_actions', array( $this, 'media_row_actions' ), 10, 3 );

		add_action( 'admin_post_s3_sync', array( $this, 'admin_post_s3_sync' ), 10, 0 );
		add_action( 'admin_post_s3_sync_batch', array( $this, 'admin_post_s3_sync_batch' ), 10, 0 );
		add_action( 'admin_post_s3_test_connection', array( $this, 'admin_post_s3_test_connection' ), 10, 0 );

		add_action( 'delete_attachment', array( $this, 'handle_delete_media' ), 10 );

		// Attach js to sync media in library.
		add_action( 'admin_enqueue_scripts', array( $this, 'register_wp_admin_scripts' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'wp_generate_attachment_metadata' ), 10, 3 );

		$this->checkConfiguration();
	}

	public function admin_init(): void {
		$this->apply_workarounds();
	}

	public function admin_menu(): void {
		add_options_page( 'WP S3 Media', 'WP S3 Media', 'manage_options', 'wp-s3-media-offloading', array( $this, 'options_page' ) );
	}

	public function is_plugin_active( string $plugin ): bool {
		return in_array( $plugin, self::$active_plugins, true ) || is_plugin_active_for_network( $plugin );
	}

	public function apply_workarounds(): void {
		$active_plugins       = get_option( 'active_plugins', array() );
		self::$active_plugins = is_array( $active_plugins ) ? $active_plugins : array();

		$plugin = 'wp-optimize/wp-optimize.php';
		if ( $this->is_plugin_active( $plugin ) && function_exists( 'Updraft_Smush_Manager' ) ) {
			$manager = \Updraft_Smush_Manager();
			remove_filter( 'manage_media_columns', array( $manager, 'manage_media_columns' ) );
		}

		$plugin = 'shortpixel-image-optimiser/wp-shortpixel.php';
		if ( $this->is_plugin_active( $plugin ) ) {
			// Enable the offloading mode for plugin.
			add_filter( 'shortpixel/file/virtual/heavy_features', '__return_false' );
		}
	}

	/**
	 * Sync a freshly uploaded attachment to S3.
	 *
	 * @param array<string, mixed>|false $metadata      Attachment metadata.
	 * @param int                        $attachment_id Attachment post ID.
	 * @param string                     $type          Context: 'create' or 'update'. Required by the hook signature.
	 * @return array<string, mixed>|false
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature requires $type.
	public function wp_generate_attachment_metadata( $metadata, int $attachment_id, string $type ) {
		$syncer = Loader::getSyncer();
		if ( null === $syncer || ! is_array( $metadata ) ) {
			return $metadata;
		}

		return $syncer->syncAfterUpload( $attachment_id, $metadata );
	}

	public function options_page(): void {
		$mandatory_constants = array( 'WPS3_KEY', 'WPS3_SECRET', 'WPS3_BUCKET', 'WPS3_REGION' );

		$missing = array();
		foreach ( $mandatory_constants as $constant ) {
			if ( defined( $constant ) === false ) {
				$missing[] = $constant;
			}
		}

		if ( ! empty( $missing ) ) {
			?>
			<div class="error notice" style="background-color:#d63638; color:white;">
				<p><strong><?php esc_html_e( 'Missing required configuration keys', 'wp-s3-offload' ); ?></strong></p>
				<p>
					<?php
					esc_html_e( 'Please define at least the following configuration variables:', 'wp-s3-offload' );
					echo ' ' . esc_html( implode( ', ', $missing ) );
					?>
				</p>
				<pre style="background-color:#eee; color:#555;padding:10px; border:1px solid #555;">
define('WPS3_KEY', 'ACCESSID');
define('WPS3_SECRET', 'SECRETKEY');
define('WPS3_BUCKET', 'BUCKETNAME');
define('WPS3_REGION', 'eu-de');

define('WPS3_ENDPOINT', 'S3-ENDPOINT');
// define('WPS3_URL_PREFIX', 'CUSTOM-BUCKET-URL');
					</pre>
				<a href="https://github.com/sw-wordpress/wp-s3-offload#setup" target="_blank"><?php esc_html_e( 'read documentation', 'wp-s3-offload' ); ?></a>
			</div>
			<?php
			return;
		}

		try {
			$syncer_obj = new Syncer();
			$syncer_obj->checkConnection();
		} catch ( \Exception $exp ) {
			?>
			<div class="error notice">
				<p><strong><?php esc_html_e( 'Error during S3 connection check', 'wp-s3-offload' ); ?></strong></p>
				<p><?php echo esc_html( $exp->getMessage() ); ?></p>
			</div>
			<?php
			return;
		}

		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => -1,
			'post_status'    => 'inherit',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finding not-yet-offloaded attachments is the purpose of this page.
			'meta_query'     => array(
				array(
					'key'     => 's3_public_url',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$the_query = new WP_Query( $args );

		$missing_number = (int) $the_query->found_posts;

		require_once WPS3_PLUGIN_BASE_DIR . 'templates/admin-options.php';
	}

	private function checkConfiguration(): void {
		$mandatory_constants = array( 'WPS3_KEY', 'WPS3_SECRET', 'WPS3_BUCKET', 'WPS3_REGION' );

		$missing = array();
		foreach ( $mandatory_constants as $constant ) {
			if ( defined( $constant ) === false ) {
				$missing[] = $constant;
			}
		}

		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				function () use ( $missing ) {
					?>
				<div class="error notice">
					<p><strong><?php esc_html_e( 'Missing required configuration keys', 'wp-s3-offload' ); ?></strong></p>
					<p>
						<?php
						esc_html_e( 'Please define at least the following configuration variables:', 'wp-s3-offload' );
						echo ' ' . esc_html( implode( ', ', $missing ) );
						?>
					</p>
					<a href="https://github.com/sw-wordpress/wp-s3-offload#setup" target="_blank"><?php esc_html_e( 'read documentation', 'wp-s3-offload' ); ?></a>
				</div>
					<?php
				}
			);
		}
	}

	public function register_wp_admin_scripts(): void {
		wp_enqueue_script( 'wp-s3-offloading-admin', plugins_url( 'resources/js/admin.js', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'wp-s3-offload.php' ), array( 'jquery' ), '1.0.0', true );

		wp_localize_script(
			'wp-s3-offloading-admin',
			'wps3_ajax',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'title'   => get_the_title(),
				'nonce'   => wp_create_nonce( 'wps3_ajax' ),
			)
		);
	}

	public function handle_delete_media( int $attachment_id ): void {
		if ( $this->is_upload_to_s3( $attachment_id ) ) {
			$syncer_obj = new Syncer();

			$metadata = wp_get_attachment_metadata( $attachment_id );

			if ( is_array( $metadata ) ) {
				if ( isset( $metadata['s3']['key'] ) && is_string( $metadata['s3']['key'] ) ) {
					$syncer_obj->delete_attachment( $metadata['s3']['key'] );
				}

				if ( isset( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size ) {
						if ( isset( $size['s3']['key'] ) && is_string( $size['s3']['key'] ) ) {
							$syncer_obj->delete_attachment( $size['s3']['key'] );
						}
					}
				}
			}

			delete_post_meta( $attachment_id, 's3_public_url' );
		}
	}

	private function is_upload_to_s3( int $post_id ): bool {
		$s3_public_url = get_post_meta( $post_id, 's3_public_url', true );

		return ! empty( $s3_public_url );
	}

	/**
	 * Add a "Sync Storage" row action to not-yet-offloaded media.
	 *
	 * @param string[] $actions  Existing row actions.
	 * @param WP_Post  $post     Attachment post.
	 * @param bool     $detached Whether the list is detached. Required by the hook signature.
	 * @return string[]
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature requires $detached.
	public function media_row_actions( array $actions, WP_Post $post, bool $detached ): array {
		$s3_public_url = get_post_meta( $post->ID, 's3_public_url', true );
		if ( empty( $s3_public_url ) ) {
			$actions['s3sync'] = sprintf(
				'<a href="#" class="s3-sync-file-btn" data-url="%s">%s</a>',
				esc_url( wp_nonce_url( 'admin-post.php?action=s3_sync&post=' . $post->ID, 'sync-s3-' . $post->ID ) ),
				esc_html__( 'Sync Storage', 'wp-s3-offload' )
			);
		}

		return $actions;
	}

	public function admin_post_s3_test_connection(): void {
		check_admin_referer( 'wps3_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to test the S3 connection.', 'wp-s3-offload' ) );
		}

		try {
			$syncer_obj = new Syncer();
			$syncer_obj->testConnection();
		} catch ( \Exception $exp ) {
			echo esc_html( $exp->getMessage() );
			exit();
		}

		echo 'OK';
	}

	public function admin_post_s3_sync_batch(): void {
		check_admin_referer( 'wps3_ajax', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You are not allowed to sync media.', 'wp-s3-offload' ) );
		}

		$response = array(
			'done' => array(),
		);

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => 5,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- batch migration selects not-yet-offloaded attachments.
				'meta_query'     => array(
					array(
						'key'     => 's3_public_url',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$syncer_obj = new Syncer();

		foreach ( $attachments as $attachment ) {
			$response['done'][] = array(
				'id'   => $attachment->ID,
				'name' => $attachment->post_name,
			);

			$syncer_obj->sync( $attachment->ID );
		}

		$content_replacement_instance = ContentReplacement::getInstance();
		$content_replacement_instance->processReplacedContent();

		wp_send_json( $response );
	}

	public function admin_post_s3_sync(): void {
		$attachment_id = 0;
		if ( isset( $_REQUEST['post'] ) && is_scalar( $_REQUEST['post'] ) ) {
			$attachment_id = absint( wp_unslash( (string) $_REQUEST['post'] ) );
		}

		check_admin_referer( 'sync-s3-' . $attachment_id );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You are not allowed to sync media.', 'wp-s3-offload' ) );
		}

		$syncer_obj = new Syncer();
		$syncer_obj->sync( $attachment_id );
		$content_replacement_instance = ContentReplacement::getInstance();
		$content_replacement_instance->processReplacedContent();
	}
}
