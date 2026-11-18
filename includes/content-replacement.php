<?php
/**
 * In-memory content cache and URL replacement across posts and post meta.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload;

class ContentReplacement {

	private static ?ContentReplacement $instance = null;

	/**
	 * Namespaced content cache: namespace => key => {value: mixed, changed: bool, extra: array}.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $cache = array();

	public static function getInstance(): ContentReplacement {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->preFillContentCache();

		$files = glob( __DIR__ . '/content-replacement/*.php' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				require_once $file;
			}
		}

		do_action( 'wps3_replacement_contentcache', $this );
	}

	/**
	 * Register content for later URL replacement.
	 *
	 * @param string               $namespace Cache namespace (e.g. 'posts', 'revslider').
	 * @param string               $key       Entry key within the namespace.
	 * @param mixed                $content   Content (string or structured array).
	 * @param array<string, mixed> $options Extra options for the namespace handler.
	 */
	public static function addContentCache( string $namespace, string $key, $content, array $options = array() ): void {
		if ( empty( self::$cache[ $namespace ] ) ) {
			self::$cache[ $namespace ] = array();
		}

		self::$cache[ $namespace ][ $key ] = array(
			'value'   => $content,
			'changed' => false,
			'extra'   => $options,
		);
	}

	public function preFillContentCache(): void {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish,draft',
				'posts_per_page' => -1,
			)
		);

		foreach ( $pages as $page ) {
			self::addContentCache( 'posts', 'post-' . $page->ID, $page->post_content );
		}
	}

	public function processReplacedContent(): void {
		foreach ( self::$cache as $namespace => $keys ) {
			do_action( 'wps3_replacement_process_' . $namespace, $keys );
		}
	}

	/**
	 * Recursively replace URLs in array values.
	 *
	 * @param array<mixed> $array   Structured content.
	 * @param string       $old_url URL to replace.
	 * @param string       $new_url Replacement URL.
	 * @return array<mixed>
	 */
	private function do_replace_in_array( array $array, string $old_url, string $new_url ): array {
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				// Recursive call when the value is an array.
				$array[ $key ] = $this->do_replace_in_array( $value, $old_url, $new_url );
			} elseif ( is_string( $value ) ) {
				// Replace when the value is a string.
				$array[ $key ] = str_replace( $old_url, $new_url, $value );
			}
		}
		return $array;
	}

	/**
	 * Replace URLs in a structured cache entry (filter callback).
	 *
	 * @param array<string, mixed> $array   Cache entry with a 'value' key.
	 * @param string               $old_url URL to replace.
	 * @param string               $new_url Replacement URL.
	 * @return array<string, mixed>
	 */
	public function replace_array( $array, string $old_url, string $new_url ): array {
		if ( ! isset( $array['value'] ) || ! is_array( $array['value'] ) ) {
			return $array;
		}

		$old            = $array;
		$array['value'] = $this->do_replace_in_array( $array['value'], $old_url, $new_url );

		if ( $old !== $array ) {
			$array['changed'] = true;
		}

		return $array;
	}

	/**
	 * Replace a URL in all cached content and in matching post meta.
	 *
	 * @param string $old_url URL to replace.
	 * @param string $new_url Replacement URL.
	 */
	public function replace( string $old_url, string $new_url ): void {
		foreach ( self::$cache as $namespace => $keys ) {
			foreach ( $keys as $key => $data ) {
				if ( isset( $data['value'] ) && is_string( $data['value'] ) ) {
					if ( strpos( $data['value'], $old_url ) !== false ) {
						self::$cache[ $namespace ][ $key ]['value']   = str_replace( $old_url, $new_url, $data['value'] );
						self::$cache[ $namespace ][ $key ]['changed'] = true;
					}
				} else {
					$replacement = apply_filters( 'wps3_replacement_replace_' . $namespace, $data, $old_url, $new_url );
					if ( is_array( $replacement ) ) {
						self::$cache[ $namespace ][ $key ] = $replacement;
					}
				}
			}
		}

		do_action( 'wps3_replacement_replace', $old_url, $new_url );

		global $wpdb;

		// Bulk search across all post meta: there is no WP API for searching meta_value,
		// and the result is processed immediately (no caching needed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_metas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", '%' . $old_url . '%' ), ARRAY_A );

		if ( ! is_array( $post_metas ) ) {
			return;
		}

		foreach ( $post_metas as $post_meta ) {
			$post_id  = (int) $post_meta['post_id'];
			$meta_key = (string) $post_meta['meta_key'];

			$value = get_post_meta( $post_id, $meta_key, true );

			$value = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			if ( false === $value ) {
				continue;
			}

			$value = str_replace( $old_url, $new_url, $value );
			$value = json_decode( $value, true );

			update_post_meta( $post_id, $meta_key, $value );
		}
	}
}
