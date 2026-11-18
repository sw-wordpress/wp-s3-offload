<?php
/**
 * Content replacement handler for post content.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload\ContentReplacement;

class Posts {

	/**
	 * Persist replaced post content.
	 *
	 * @param array<string, array<string, mixed>> $keys Cache entries keyed by "post-{ID}".
	 */
	public function process_posts( $keys ): void {
		foreach ( $keys as $key => $data ) {
			if ( false === $data['changed'] ) {
				continue;
			}

			if ( ! is_string( $data['value'] ) ) {
				continue;
			}

			$post_id = (int) str_replace( 'post-', '', (string) $key );

			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $data['value'],
				)
			);
		}
	}
}

add_action( 'wps3_replacement_process_posts', array( new Posts(), 'process_posts' ) );
