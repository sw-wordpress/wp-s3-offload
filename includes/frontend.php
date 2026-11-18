<?php
/**
 * Frontend filters: serve offloaded media from S3 URLs.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload;

class Frontend {

	public function load_hooks(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'change_file_url_in_media' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'calculate_image_srcset' ), 10, 5 );

		add_filter( 'wp_image_editors', array( $this, 'filter_editors' ), 9 );
	}

	private function is_upload_to_s3( int $post_id ): bool {
		$s3_public_url = get_post_meta( $post_id, 's3_public_url', true );

		return ! empty( $s3_public_url );
	}

	/**
	 * Prefer the S3-aware Imagick editor.
	 *
	 * @param string[] $editors Available editor class names.
	 * @return string[]
	 */
	public function filter_editors( array $editors ): array {
		$position = array_search( 'WP_Image_Editor_Imagick', $editors, true );
		if ( false !== $position ) {
			unset( $editors[ $position ] );
		}

		array_unshift( $editors, ImageEditorImagick::class );

		return $editors;
	}

	/**
	 * Point srcset sources at the offloaded S3 URLs.
	 *
	 * @param array<int, mixed>|false                                             $sources       Srcset sources.
	 * @param array{0: int, 1: int}                                               $size_array    Requested size.
	 * @param string                                                              $image_src     Image URL.
	 * @param array<string, mixed>                                                $image_meta    Attachment metadata.
	 * @param int                                                                 $attachment_id Attachment post ID. Required by the hook signature.
	 * @return array<int, mixed>|false
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature requires $attachment_id.
	public function calculate_image_srcset( $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		if ( ! empty( $image_meta['s3_public_url'] ) && is_string( $image_meta['s3_public_url'] ) && isset( $image_meta['file'] ) && is_string( $image_meta['file'] ) ) {
			if ( ! isset( $image_meta['sizes'] ) || ! is_array( $image_meta['sizes'] ) ) {
				$image_meta['sizes'] = array();
			}

			$image_meta['sizes']['full'] = array(
				'file'   => basename( $image_meta['file'] ),
				'width'  => isset( $image_meta['width'] ) ? (int) $image_meta['width'] : 0,
				'height' => isset( $image_meta['height'] ) ? (int) $image_meta['height'] : 0,
				's3'     => array(
					'url' => $image_meta['s3_public_url'],
				),
			);
		}

		if ( ! isset( $image_meta['sizes'] ) || ! is_array( $image_meta['sizes'] ) ) {
			return $sources;
		}

		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) || ! isset( $source['url'] ) || ! is_string( $source['url'] ) ) {
				continue;
			}

			$img_basename = basename( $source['url'] );

			foreach ( $image_meta['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['s3'] ) || ! isset( $size['file'] ) ) {
					continue;
				}

				if ( $size['file'] === $img_basename && is_array( $size['s3'] ) && isset( $size['s3']['url'] ) && is_string( $size['s3']['url'] ) ) {
					$sources[ $index ]['url'] = $size['s3']['url'];
					break;
				}
			}
		}

		return $sources;
	}

	/**
	 * Replace the attachment URL with the public S3 URL.
	 *
	 * @param string|false $url           Attachment URL.
	 * @param int          $attachment_id Attachment post ID.
	 * @return string|false
	 */
	public function change_file_url_in_media( string|false $url, int $attachment_id ): string|false {
		if ( ! $this->is_upload_to_s3( $attachment_id ) ) {
			return $url;
		}

		$s3_public_url = get_post_meta( $attachment_id, 's3_public_url', true );

		return is_string( $s3_public_url ) ? $s3_public_url : $url;
	}
}
