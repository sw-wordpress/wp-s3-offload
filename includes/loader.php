<?php
/**
 * Plugin loader: wires up admin and frontend components.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload;

class Loader {

	private static ?Syncer $syncer = null;

	public static function getSyncer(): ?Syncer {
		return self::$syncer;
	}

	public function run(): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			require_once WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'content-replacement.php';
			require_once WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'admin.php';

			$admin = new Admin();
			$admin->load_hooks();
		}

		if ( defined( 'WPS3_KEY' ) && defined( 'WPS3_SECRET' ) && defined( 'WPS3_BUCKET' ) ) {
			require_once WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'syncer.php';
			require_once WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'frontend.php';

			$frontend = new Frontend();
			$frontend->load_hooks();

			self::$syncer = new Syncer();
			self::$syncer->load_hooks();
		}
	}
}
