<?php
/**
 * S3-aware Imagick image editor.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload;

use Imagick;
use WP_Error;
use WP_Image_Editor_Imagick;

class ImageEditorImagick extends WP_Image_Editor_Imagick {

	/**
	 * @var ?string
	 */
	protected $file;

	/**
	 * @var array{width?: int, height?: int}
	 */
	protected $size;

	/**
	 * @var ?string
	 */
	protected $remote_filename = null;

	/**
	 * Hold on to a reference of all temp local files.
	 *
	 * These are cleaned up on __destruct.
	 *
	 * @var string[]
	 */
	protected $temp_files_to_cleanup = array();

	/**
	 * Whether the image has been loaded successfully.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Loads image from $this->file into new Imagick Object.
	 *
	 * @return true|WP_Error True if loaded; WP_Error on failure.
	 */
	public function load() {
		if ( $this->loaded ) {
			return true;
		}

		if ( $this->file && ! is_file( $this->file ) && ! preg_match( '|^https?://|', $this->file ) ) {
			return new WP_Error( 'error_loading_image', __( 'File doesn&#8217;t exist?', 'wp-s3-offload' ), $this->file );
		}

		$file_extension = strtolower( pathinfo( (string) $this->file, PATHINFO_EXTENSION ) );
		$upload_dir     = wp_upload_dir();

		if ( ! $this->file || strpos( $this->file, $upload_dir['basedir'] ) === 0 ) {
			$result       = parent::load();
			$this->loaded = ( true === $result );

			return $result;
		}

		$temp_base = tempnam( get_temp_dir(), 's3-uploads' );
		if ( false === $temp_base ) {
			return new WP_Error( 'error_loading_image', __( 'Unable to create temporary file.', 'wp-s3-offload' ), $this->file );
		}

		$temp_filename                 = $temp_base . '.' . $file_extension;
		$this->temp_files_to_cleanup[] = $temp_filename;

		if ( ! copy( $this->file, $temp_filename ) ) {
			return new WP_Error( 'error_loading_image', __( 'Unable to copy remote image to a temporary file.', 'wp-s3-offload' ), $this->file );
		}

		$this->remote_filename = $this->file;
		$this->file            = $temp_filename;

		$result = parent::load();

		$this->file = $this->remote_filename;

		$this->loaded = ( true === $result );

		return $result;
	}

	/**
	 * Imagick by default can't handle s3:// paths for saving images.
	 * Save to a local temp file first, then upload it to the s3:// path.
	 *
	 * @param Imagick $image     Imagick instance.
	 * @param ?string $filename  Target filename.
	 * @param ?string $mime_type Target MIME type.
	 * @return WP_Error|array{path: string, file: string, width: int, height: int, mime-type: string}
	 */
	// phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- overrides WP_Image_Editor_Imagick::_save().
	public function _save( $image, $filename = null, $mime_type = null ) {
		/**
		 * @var ?string $filename
		 * @var string $extension
		 * @var string $mime_type
		 */
		list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

		if ( ! $filename ) {
			$filename = $this->generate_filename( null, null, $extension );
		}

		$upload_dir = wp_upload_dir();

		$temp_filename = tempnam( get_temp_dir(), 's3-uploads' );

		$s3_target_filename = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $filename );
		$s3_target_filename = (string) preg_replace( '#s3\://[^/]+/#', '', $s3_target_filename );

		/**
		 * @var WP_Error|array{path: string, file: string, width: int, height: int, mime-type: string}
		 */
		$parent_call = parent::_save( $image, $temp_filename ?: $filename, $mime_type );

		if ( is_wp_error( $parent_call ) ) {
			if ( $temp_filename ) {
				wp_delete_file( $temp_filename );
			}

			return $parent_call;
		}

		$save = $parent_call;

		$syncer = Loader::getSyncer();
		if ( null === $syncer ) {
			if ( $temp_filename ) {
				wp_delete_file( $temp_filename );
			}

			return new WP_Error( 'unable-to-copy-to-s3', 'S3 syncer is not configured' );
		}

		$upload_result = $syncer->upload( $save['path'], $s3_target_filename );

		wp_delete_file( $save['path'] );
		if ( $temp_filename ) {
			wp_delete_file( $temp_filename );
		}

		if ( is_wp_error( $upload_result ) ) {
			return $upload_result;
		}

		return array(
			'path'      => $filename,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, not a plugin hook.
			'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
			'width'     => $this->size['width'] ?? 0,
			'height'    => $this->size['height'] ?? 0,
			'mime-type' => $mime_type,
		);
	}

	public function __destruct() {
		array_map( 'wp_delete_file', $this->temp_files_to_cleanup );
		parent::__destruct();
	}
}
