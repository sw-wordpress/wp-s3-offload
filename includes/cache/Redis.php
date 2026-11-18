<?php
/**
 * Redis cache for the S3 stream wrapper (used when WP_REDIS_HOST is defined).
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload\Cache;

use Aws\CacheInterface;

class Redis implements CacheInterface {

	private \Redis $redis;

	public function __construct() {
		$this->redis = new \Redis();
		$this->redis->pconnect( (string) constant( 'WP_REDIS_HOST' ), 6379 );
	}

	private function getCacheFilePath( string $key ): string {
		$prefix = defined( 'WP_REDIS_PREFIX' ) ? (string) constant( 'WP_REDIS_PREFIX' ) : '';

		return $prefix . sha1( $key ) . '.cache';
	}

	/**
	 * @param string $key Cache key.
	 * @return mixed Cached value or null when missing/expired.
	 */
	public function get( $key ) {
		$file = $this->getCacheFilePath( (string) $key );

		$data = $this->redis->get( $file );
		if ( false === $data || ! is_string( $data ) ) {
			return null;
		}

		$content = json_decode( $data, true );
		if ( ! is_array( $content ) || ! isset( $content['expire'], $content['content'] ) ) {
			return null;
		}

		$expiration = (int) $content['expire'];
		if ( 0 !== $expiration && $expiration < time() ) {
			$this->redis->del( $file );
			return null;
		}

		return $content['content'];
	}

	/**
	 * @param string   $key   Cache key.
	 * @param mixed    $value Value to cache.
	 * @param int|null $ttl   Optional lifetime in seconds (defaults to one hour).
	 * @throws \RuntimeException When encoding or writing fails.
	 */
	public function set( $key, $value, $ttl = null ): void {
		$file       = $this->getCacheFilePath( (string) $key );
		$expiration = null !== $ttl ? time() + (int) $ttl : 0;

		$data_string = wp_json_encode(
			array(
				'expire'  => $expiration,
				'content' => $value,
			)
		);
		if ( false === $data_string ) {
			throw new \RuntimeException( sprintf( 'Failed to encode cache value for key: %s', (string) $key ) );
		}

		$ttl_seconds = null !== $ttl ? max( 1, (int) $ttl ) : 3600;
		if ( $this->redis->setex( $file, $ttl_seconds, $data_string ) !== true ) {
			throw new \RuntimeException( sprintf( 'Failed to write cache file: %s', $file ) );
		}
	}

	/**
	 * @param string $key Cache key.
	 * @throws \RuntimeException When deletion fails.
	 */
	public function remove( $key ): void {
		$file = $this->getCacheFilePath( (string) $key );

		if ( $this->redis->del( $file ) === false ) {
			throw new \RuntimeException( sprintf( 'Failed to remove cache file: %s', $file ) );
		}
	}
}
