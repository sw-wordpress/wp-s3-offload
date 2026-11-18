<?php
/**
 * S3 syncer: uploads media, rewrites URLs and registers the s3:// stream wrapper.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload;

use Aws\CacheInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\StreamWrapper;
use WP_Error;
use WPS3\S3\Offload\Cache\File;
use WPS3\S3\Offload\Cache\Redis;

class Syncer {

	private S3Client $s3;

	/**
	 * Plugin options derived from the WPS3_* constants.
	 *
	 * @var array{type: string, aws_key: string, aws_secret: string, bucket: string, region: string, folder: string, url_prefix: string, endpoint: string, pathstyle: bool}
	 */
	private array $options;

	private static bool $no_cache_mode = true;

	private static bool $registered_stream = false;

	/**
	 * Resolved s3:// paths per attachment ID.
	 *
	 * @var array<int, string>
	 */
	private static array $s3_data_cache = array();

	private static string $base_dir = '';

	public function __construct() {
		$this->options = $this->getOptions();

		require_once WPS3_PLUGIN_BASE_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

		$this->s3 = new S3Client(
			array(
				'version'                 => 'latest',
				'region'                  => $this->options['region'],
				'bucket'                  => $this->options['bucket'],
				'endpoint'                => $this->options['endpoint'],
				'use_path_style_endpoint' => $this->options['pathstyle'],

				'credentials'             => array(
					'key'    => $this->options['aws_key'],
					'secret' => $this->options['aws_secret'],
				),
				'signatureVersion'        => 'v4',
			)
		);

		$upload_dir     = wp_get_upload_dir();
		self::$base_dir = $upload_dir['basedir'];
	}

	/**
	 * Copy a local file into the bucket via the s3:// stream wrapper.
	 *
	 * @param string $local_filename  Local source path.
	 * @param string $target_filename Target key within the bucket.
	 * @return WP_Error|null Error on failure, null on success.
	 */
	public function upload( string $local_filename, string $target_filename ): ?WP_Error {
		$this->registerStreamWrapper();

		$copy_result = copy( $local_filename, 's3://' . $this->options['bucket'] . '/' . $target_filename );

		if ( ! $copy_result ) {
			return new WP_Error( 'unable-to-copy-to-s3', 'Unable to copy the temp image to S3' );
		}

		return null;
	}

	public function load_hooks(): void {
		add_filter( 'get_attached_file', array( $this, 'get_attached_file' ), 1, 2 );
	}

	/**
	 * Restore a missing local file from its public S3 URL (filter callback).
	 *
	 * @param array<string, mixed>|false $metadata      Attachment metadata.
	 * @param int                        $attachment_id Attachment post ID.
	 * @return array<string, mixed>|false
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature requires $attachment_id.
	public function wp_get_attachment_metadata( $metadata, int $attachment_id ) {
		if (
			is_array( $metadata ) &&
			isset( $metadata['s3_public_url'], $metadata['file'] ) &&
			is_string( $metadata['s3_public_url'] ) && '' !== $metadata['s3_public_url'] &&
			is_string( $metadata['file'] )
		) {
			$target_file = self::$base_dir . DIRECTORY_SEPARATOR . $metadata['file'];

			if ( ! is_file( $target_file ) ) {
				$response = wp_remote_get( $metadata['s3_public_url'] );

				if ( ! is_wp_error( $response ) ) {
					// Local restore of an offloaded file; WP_Filesystem is not available here.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $target_file, $response['body'] );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Resolve the attached file to an s3:// path when the file is offloaded.
	 *
	 * @param string|false $file          Local file path.
	 * @param int          $attachment_id Attachment post ID.
	 * @return string|false
	 */
	public function get_attached_file( $file, int $attachment_id ) {
		if ( ! empty( $attachment_id ) && ! empty( self::$s3_data_cache[ $attachment_id ] ) ) {
			return self::$s3_data_cache[ $attachment_id ];
		}

		if ( ! empty( $file ) && file_exists( $file ) ) {
			$s3_public_url = get_post_meta( $attachment_id, 's3_public_url', true );
		} else {
			$s3_public_url = false;
		}

		if ( ! empty( $file ) && ( ! empty( $s3_public_url ) || ! file_exists( $file ) ) ) {
			$this->registerStreamWrapper();

			$rel_path = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

			self::$s3_data_cache[ $attachment_id ] = 's3://' . $this->options['bucket'] . '/' . $rel_path;

			return self::$s3_data_cache[ $attachment_id ];
		}

		return $file;
	}

	/**
	 * Point the upload directory at the bucket (filter callback).
	 *
	 * @param array{path: string, url: string, basedir: string, baseurl: string, subdir: string, error: string|false} $dir Upload dir data.
	 * @return array{path: string, url: string, basedir: string, baseurl: string, subdir: string, error: string|false}
	 */
	public function upload_dir( array $dir ): array {
		$folder = trim( $this->options['folder'], '/' );
		if ( ! empty( $folder ) ) {
			$folder .= '/';
		}

		$this->registerStreamWrapper();

		$subdir = (string) $dir['subdir'];

		$dir['path']    = 's3://' . rtrim( $this->options['bucket'] . '/' . $folder, '/' ) . $subdir;
		$dir['path']    = rtrim( $dir['path'], '/' );
		$dir['url']     = trim( $this->options['url_prefix'], ' /' ) . '/' . $folder . trim( $subdir, '/ ' );
		$dir['basedir'] = 's3://' . rtrim( $this->options['bucket'] . '/' . $folder, '/' );
		$dir['baseurl'] = trim( $this->options['url_prefix'], ' /' ) . '/' . $folder;

		return $dir;
	}

	/**
	 * Read the plugin configuration from the WPS3_* constants.
	 *
	 * @return array{type: string, aws_key: string, aws_secret: string, bucket: string, region: string, folder: string, url_prefix: string, endpoint: string, pathstyle: bool}
	 */
	private function getOptions(): array {
		$endpoint = defined( 'WPS3_ENDPOINT' ) ? (string) constant( 'WPS3_ENDPOINT' ) : '';

		if ( ! defined( 'WPS3_URL_PREFIX' ) ) {
			if ( defined( 'WPS3_PATHSTYLE' ) && (bool) constant( 'WPS3_PATHSTYLE' ) ) {
				define( 'WPS3_URL_PREFIX', rtrim( $endpoint, '/' ) . '/' . (string) constant( 'WPS3_BUCKET' ) );
			} else {
				define( 'WPS3_URL_PREFIX', str_replace( 'https://', 'https://' . (string) constant( 'WPS3_BUCKET' ) . '.', $endpoint ) );
			}
		}

		return array(
			'type'       => defined( 'WPS3_PROVIDER' ) ? (string) constant( 'WPS3_PROVIDER' ) : 'aws',
			'aws_key'    => (string) constant( 'WPS3_KEY' ),
			'aws_secret' => (string) constant( 'WPS3_SECRET' ),
			'bucket'     => (string) constant( 'WPS3_BUCKET' ),
			'region'     => (string) constant( 'WPS3_REGION' ),
			'folder'     => defined( 'WPS3_FOLDER' ) ? (string) constant( 'WPS3_FOLDER' ) : '',

			'url_prefix' => rtrim( (string) constant( 'WPS3_URL_PREFIX' ), '/' ) . '/',

			'endpoint'   => $endpoint,
			'pathstyle'  => defined( 'WPS3_PATHSTYLE' ) && (bool) constant( 'WPS3_PATHSTYLE' ),
		);
	}

	public function getCacheHandler(): CacheInterface {
		if ( defined( 'WP_REDIS_HOST' ) ) {
			require_once WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'Redis.php';

			return new Redis();
		}

		require_once WPS3_PLUGIN_BASE_DIR . 'includes' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'File.php';

		$hash     = sha1( (string) getcwd() );
		$temp_dir = sys_get_temp_dir();

		return new File( $temp_dir . DIRECTORY_SEPARATOR . 'wp-' . $hash );
	}

	public function registerStreamWrapper(): void {
		if ( false === self::$registered_stream ) {
			StreamWrapper::register(
				$this->s3,
				's3',
				$this->getCacheHandler(),
				true
			);

			self::$registered_stream = true;
		}
	}

	/**
	 * Delete an object from the bucket.
	 *
	 * @param string $key Object key.
	 * @throws S3Exception When S3 rejects the deletion.
	 */
	public function delete_attachment( string $key ): void {
		$this->s3->deleteObject(
			array(
				'Bucket' => $this->options['bucket'],
				'Key'    => $key,
			)
		);
	}

	/**
	 * Sync after an upload and point the attachment guid at the S3 URL.
	 *
	 * @param int                  $attachment_id Attachment post ID.
	 * @param array<string, mixed> $metadata      Fresh attachment metadata.
	 * @return array<string, mixed>
	 */
	public function syncAfterUpload( int $attachment_id, array $metadata ): array {
		global $wpdb;

		$metadata = $this->sync( $attachment_id, $metadata );

		if ( ! empty( $metadata['s3_public_url'] ) && is_string( $metadata['s3_public_url'] ) ) {
			// Direct guid update: wp_update_post would fire unrelated hooks on every upload.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->posts, array( 'guid' => $metadata['s3_public_url'] ), array( 'ID' => $attachment_id ) );
		}

		return $metadata;
	}

	/**
	 * Upload a local file to the bucket.
	 *
	 * @param string      $local_filename  Local source path.
	 * @param string      $target_filekey  Target key within the bucket.
	 * @param string|null $mimetype        MIME type; detected from the file when null.
	 * @throws \RuntimeException When the local file cannot be read.
	 */
	public function uploadToS3( string $local_filename, string $target_filekey, ?string $mimetype = null ): void {
		if ( ! is_file( $local_filename ) ) {
			return;
		}

		if ( null === $mimetype ) {
			$mimetype = mime_content_type( $local_filename );
			if ( false === $mimetype ) {
				$mimetype = 'application/octet-stream';
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote URL.
		$body = file_get_contents( $local_filename );
		if ( false === $body ) {
			throw new \RuntimeException( sprintf( 'Unable to read local file: %s', $local_filename ) );
		}

		$this->s3->putObject(
			array(
				'Bucket'      => $this->options['bucket'],
				'Key'         => $target_filekey,
				'Body'        => $body,
				'ContentType' => $mimetype,
			)
		);
	}

	/**
	 * Upload, fetch and delete a test object to verify the connection.
	 *
	 * @return bool
	 * @throws \Exception When the round trip fails.
	 */
	public function testConnection(): bool {
		$test_string = 'TEST' . sha1( (string) wp_rand( 100000, 999999 ) );
		$filename    = wp_rand( 100000, 999999 );

		$testfile = tempnam( sys_get_temp_dir(), 'S3Test' );
		if ( false === $testfile ) {
			throw new \Exception( 'Unable to create temporary test file' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local temp file.
		file_put_contents( $testfile, $test_string );

		$target_key = 'test/testfile' . $filename;
		$this->uploadToS3( $testfile, $target_key );
		wp_delete_file( $testfile );

		$new_url = $this->s3->getObjectUrl( $this->options['bucket'], $target_key );

		$response = wp_remote_get( $new_url );
		$body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );

		if ( trim( $body ) !== $test_string ) {
			throw new \Exception( 'Upload test not successful' );
		}

		$this->s3->deleteObject(
			array(
				'Bucket' => $this->options['bucket'],
				'Key'    => $target_key,
			)
		);

		return true;
	}

	/**
	 * Verify bucket access with a minimal list request.
	 *
	 * @throws S3Exception When S3 rejects the request.
	 */
	public function checkConnection(): void {
		$this->s3->listObjects(
			array(
				'Bucket'  => $this->options['bucket'],
				'MaxKeys' => 1,
			)
		);
	}

	/**
	 * Called to sync an existing media file.
	 *
	 * @param int                       $attachment_id The attachment to sync.
	 * @param array<string, mixed>|null $metadata      If set after upload, these metadata are used instead of postmeta.
	 * @return array<string, mixed> Updated attachment metadata.
	 * @throws S3Exception When S3 rejects an upload.
	 */
	public function sync( int $attachment_id, ?array $metadata = null ): array {
		$old_guid = get_the_guid( $attachment_id );

		$content_replacement_instance = ContentReplacement::getInstance();

		$this->registerStreamWrapper();

		$folder = trim( $this->options['folder'], '/' );
		if ( ! empty( $folder ) ) {
			$folder .= '/';
		}

		if ( empty( $metadata ) ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		if ( self::$no_cache_mode ) {
			// No cache: transfer again.
			unset( $metadata['s3'] );
		}

		$old_url = (string) wp_get_attachment_url( $attachment_id );
		$updated = false;

		$meta_file = isset( $metadata['file'] ) && is_string( $metadata['file'] ) ? $metadata['file'] : '';

		if ( '' !== $meta_file ) {
			$url_basename = str_replace( $meta_file, '', $old_url );
		} else {
			$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$url_basename  = dirname( $attached_file );
		}

		$filename = '';

		if ( ! isset( $metadata['s3'] ) ) {
			if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && '' !== $meta_file ) {
				$dirname  = dirname( $meta_file );
				$filename = $dirname . DIRECTORY_SEPARATOR . $metadata['original_image'];
			} elseif ( '' !== $meta_file ) {
				$filename = $meta_file;
			} else {
				$filename = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			}

			if (
				is_file( WP_CONTENT_DIR . '/uploads/' . $filename ) === false &&
				is_file( WP_CONTENT_DIR . '/uploads/' . str_replace( '-scaled', '', $filename ) ) === true
			) {
				return $metadata;
			}

			$this->uploadToS3(
				WP_CONTENT_DIR . '/uploads/' . $filename,
				$folder . $filename
			);

			$new_url = $this->s3->getObjectUrl( $this->options['bucket'], $folder . $filename );

			$type = wp_check_filetype_and_ext( WP_CONTENT_DIR . '/uploads/' . $filename, basename( $filename ) );

			$metadata['s3_public_url'] = $new_url;
			update_post_meta( $attachment_id, 's3_public_url', $metadata['s3_public_url'] );

			$content_replacement_instance->replace( $old_guid, $new_url );
			$content_replacement_instance->replace( $old_url, $new_url );

			$metadata['s3'] = array(
				'url'  => trim( $this->options['url_prefix'], ' /' ) . '/' . $filename,
				'key'  => $filename,
				'mime' => $type,
			);

			$updated = true;
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			if ( '' === $filename ) {
				if ( '' !== $meta_file ) {
					$filename = $meta_file;
				} else {
					$filename = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
				}
			}

			foreach ( $metadata['sizes'] as $size_key => $size_data ) {
				if ( ! is_array( $size_data ) || empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
					continue;
				}

				$dirname = dirname( $filename );
				$old_url = $url_basename . $dirname . '/' . $size_data['file'];

				if ( self::$no_cache_mode ) {
					// No cache: transfer again.
					unset( $size_data['s3'] );
				}

				if ( ! isset( $size_data['s3'] ) ) {
					$size_mimetype = ! empty( $size_data['mime-type'] ) && is_string( $size_data['mime-type'] ) ? $size_data['mime-type'] : null;

					$this->uploadToS3(
						WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $dirname . DIRECTORY_SEPARATOR . $size_data['file'],
						$folder . $dirname . DIRECTORY_SEPARATOR . $size_data['file'],
						$size_mimetype
					);

					$new_url = $this->s3->getObjectUrl( $this->options['bucket'], $folder . $dirname . DIRECTORY_SEPARATOR . $size_data['file'] );

					$content_replacement_instance->replace( $old_url, $new_url );

					$size_data['s3'] = array(
						'url' => $new_url,
						'key' => $dirname . DIRECTORY_SEPARATOR . $size_data['file'],
					);

					$metadata['sizes'][ $size_key ] = $size_data;

					$updated = true;
				}
			}
		}

		if ( $updated ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return $metadata;
	}
}
