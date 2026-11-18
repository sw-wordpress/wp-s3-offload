<?php
/**
 * Local file cache for the S3 stream wrapper.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload\Cache;

use Aws\CacheInterface;

class File implements CacheInterface {

	private string $cache_dir;

	public function __construct( string $cacheDir ) {
		// Runtime cache in the system temp dir: direct filesystem calls are intentional,
		// WP_Filesystem is not initialized (and may require credentials) in this context.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		if ( ! is_dir( $cacheDir ) && ! mkdir( $cacheDir, 0777, true ) && ! is_dir( $cacheDir ) ) {
			throw new \RuntimeException( sprintf( 'Unable to create cache directory: %s', $cacheDir ) );
		}

		$this->cache_dir = rtrim( $cacheDir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
	}

	private function getCacheFilePath( string $key ): string {
		return $this->cache_dir . sha1( $key ) . '.cache';
	}

	/**
	 * @param string $key Cache key.
	 * @return mixed Cached value or null when missing/expired.
	 */
	public function get( $key ) {
		$file = $this->getCacheFilePath( (string) $key );
		if ( ! file_exists( $file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache file, not a remote URL.
		$data = file_get_contents( $file );
		if ( false === $data ) {
			return null;
		}

		$content = json_decode( $data, true );
		if ( ! is_array( $content ) || ! isset( $content['expire'], $content['content'] ) ) {
			return null;
		}

		$expiration = (int) $content['expire'];
		if ( 0 !== $expiration && $expiration < time() ) {
			wp_delete_file( $file );
			return null;
		}

		return $content['content'];
	}

	/**
	 * @param string   $key   Cache key.
	 * @param mixed    $value Value to cache.
	 * @param int|null $ttl Optional lifetime in seconds.
	 * @throws \RuntimeException When encoding or writing fails.
	 */
	public function set( $key, $value, $ttl = null ): void {
		$file       = $this->getCacheFilePath( (string) $key );
		$expiration = null !== $ttl ? time() + (int) $ttl : 0;

		$data = array(
			'expire'  => $expiration,
			'content' => $value,
		);

		$data_string = wp_json_encode( $data );
		if ( false === $data_string ) {
			throw new \RuntimeException( sprintf( 'Failed to encode cache value for key: %s', (string) $key ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local cache file.
		if ( file_put_contents( $file, $data_string ) === false ) {
			throw new \RuntimeException( sprintf( 'Failed to write cache file: %s', $file ) );
		}
	}

	/**
	 * @param string $key Cache key.
	 */
	public function remove( $key ): void {
		$file = $this->getCacheFilePath( (string) $key );

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}
}
