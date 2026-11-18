<?php
/**
 * Content replacement handler for Slider Revolution slides.
 *
 * @package WPS3_S3_Offload
 */

namespace WPS3\S3\Offload\ContentReplacement;

use WPS3\S3\Offload\ContentReplacement;

class RevolutionSlider {

	public function prefill(): void {
		if ( ! class_exists( '\RevSliderFront' ) ) {
			return;
		}

		global $wpdb;

		// Slider Revolution stores slides in custom tables; direct queries are the only option.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slides = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . \RevSliderFront::TABLE_SLIDES ), ARRAY_A );

		if ( is_array( $slides ) ) {
			foreach ( $slides as $slide ) {
				ContentReplacement::addContentCache(
					'revslider',
					'wp_revslider_slides-' . (string) $slide['id'],
					array(
						'params' => isset( $slide['params'] ) && is_string( $slide['params'] ) ? json_decode( $slide['params'], true ) : null,
						'layers' => isset( $slide['layers'] ) && is_string( $slide['layers'] ) ? json_decode( $slide['layers'], true ) : null,
					)
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slides = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . \RevSliderFront::TABLE_SLIDES . '7' ), ARRAY_A );

		if ( is_array( $slides ) ) {
			foreach ( $slides as $slide ) {
				ContentReplacement::addContentCache(
					'revslider',
					'wp_revslider_slides7-' . (string) $slide['id'],
					array(
						'params' => isset( $slide['params'] ) && is_string( $slide['params'] ) ? json_decode( $slide['params'], true ) : null,
						'layers' => isset( $slide['layers'] ) && is_string( $slide['layers'] ) ? json_decode( $slide['layers'], true ) : null,
					)
				);
			}
		}
	}

	/**
	 * Persist replaced slide parameters and layers.
	 *
	 * @param array<string, array<string, mixed>> $keys Cache entries keyed by slide table and ID.
	 */
	public function process_posts( $keys ): void {
		if ( ! class_exists( '\RevSliderFront' ) ) {
			return;
		}

		global $wpdb;

		foreach ( $keys as $key => $data ) {
			if ( false === $data['changed'] ) {
				continue;
			}

			if ( ! isset( $data['value'] ) || ! is_array( $data['value'] ) ) {
				continue;
			}

			$params = wp_json_encode( $data['value']['params'] ?? null );
			$layers = wp_json_encode( $data['value']['layers'] ?? null );

			if ( false === $params || false === $layers ) {
				continue;
			}

			$key = (string) $key;

			if ( strpos( $key, 'wp_revslider_slides-' ) !== false ) {
				$slider_id = (int) str_replace( 'wp_revslider_slides-', '', $key );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . \RevSliderFront::TABLE_SLIDES,
					array(
						'params' => $params,
						'layers' => $layers,
					),
					array( 'id' => $slider_id )
				);
			}

			if ( strpos( $key, 'wp_revslider_slides7-' ) !== false ) {
				$slider_id = (int) str_replace( 'wp_revslider_slides7-', '', $key );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . \RevSliderFront::TABLE_SLIDES . '7',
					array(
						'params' => $params,
						'layers' => $layers,
					),
					array( 'id' => $slider_id )
				);
			}
		}
	}
}

add_action( 'wps3_replacement_contentcache', array( new RevolutionSlider(), 'prefill' ) );
add_action( 'wps3_replacement_process_revslider', array( new RevolutionSlider(), 'process_posts' ) );

add_filter( 'wps3_replacement_replace_revslider', array( ContentReplacement::getInstance(), 'replace_array' ), 10, 3 );
