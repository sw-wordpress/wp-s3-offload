<?php
/**
 * @wordpress-plugin
 * Plugin Name:       WP Media S3 offloading
 * Update URI:        https://github.com/sw-wordpress/wp-s3-offload
 * Plugin URI:        https://github.com/sw-wordpress/wp-s3-offload
 * Version:           1.2.5
 * Description:		  Upload and manage files on S3 Storage to Offload media
 * Author:            Stefan Warnat
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-s3-offload
 * Domain Path:       /languages
 */

use WPS3\S3\Offload\Loader;

define( 'WPS3_PLUGIN_BASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS3_PLUGIN_BASE_URL', plugin_dir_url( __FILE__ ) );

require_once(WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'loader.php');

$loader = new Loader();
$loader->run();