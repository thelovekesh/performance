<?php
/**
 * Tests for webp-uploads module.
 *
 * @package performance-lab
 * @group   webp-uploads
 */

class WebP_Uploads_Tests extends WP_UnitTestCase {
	/**
	 * Create the original image mime type when the image is uploaded
	 *
	 * @dataProvider provider_image_with_default_behaviors_during_upload
	 *
	 * @test
	 */
	public function it_should_create_the_original_image_mime_type_when_the_image_is_uploaded(
		$file_location,
		$expected_mime,
		$targeted_mime
	) {
		$attachment_id = $this->import_attachment( $file_location );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertIsArray( $metadata );
		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertArrayHasKey( 'sources', $properties );
			$this->assertIsArray( $properties['sources'] );
			$this->assertArrayHasKey( $expected_mime, $properties['sources'] );
			$this->assertArrayHasKey( 'filesize', $properties['sources'][ $expected_mime ] );
			$this->assertArrayHasKey( 'file', $properties['sources'][ $expected_mime ] );
			$this->assertGreaterThan(
				0,
				wp_next_scheduled(
					'webp_uploads_create_image',
					array(
						$attachment_id,
						$size_name,
						$targeted_mime,
					)
				)
			);
		}
	}

	public function provider_image_with_default_behaviors_during_upload() {
		yield 'JPEG image' => array(
			'leafs.jpg',
			'image/jpeg',
			'image/webp',
		);

		yield 'WebP image' => array(
			'balloons.webp',
			'image/webp',
			'image/jpeg',
		);
	}

	/**
	 * Not create the sources property if no transform is provided
	 *
	 * @test
	 */
	public function it_should_not_create_the_sources_property_if_no_transform_is_provided() {
		add_filter( 'webp_uploads_supported_image_mime_transforms', '__return_empty_array' );

		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );

		$this->assertIsArray( $metadata );
		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertArrayNotHasKey( 'sources', $properties );
			$this->assertFalse(
				wp_next_scheduled(
					'webp_uploads_create_image',
					array(
						$attachment_id,
						$size_name,
						'image/webp',
					)
				)
			);
		}
	}

	/**
	 * Create the sources property when no transform is available
	 *
	 * @test
	 */
	public function it_should_create_the_sources_property_when_no_transform_is_available() {
		add_filter(
			'webp_uploads_supported_image_mime_transforms',
			function () {
				return array( 'image/jpeg' => array() );
			}
		);

		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );

		$this->assertIsArray( $metadata );
		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertArrayHasKey( 'sources', $properties );
			$this->assertIsArray( $properties['sources'] );
			$this->assertArrayHasKey( 'image/jpeg', $properties['sources'] );
			$this->assertArrayHasKey( 'filesize', $properties['sources']['image/jpeg'] );
			$this->assertArrayHasKey( 'file', $properties['sources']['image/jpeg'] );
			$this->assertFalse(
				wp_next_scheduled(
					'webp_uploads_create_image',
					array(
						$attachment_id,
						$size_name,
						'image/webp',
					)
				)
			);
		}
	}

	/**
	 * Not create the sources property if the mime is not specified on the transforms images
	 *
	 * @test
	 */
	public function it_should_not_create_the_sources_property_if_the_mime_is_not_specified_on_the_transforms_images() {
		add_filter(
			'webp_uploads_supported_image_mime_transforms',
			function () {
				return array( 'image/jpeg' => array() );
			}
		);

		$attachment_id = $this->import_attachment( 'balloons.webp' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );

		$this->assertIsArray( $metadata );
		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertArrayNotHasKey( 'sources', $properties );
			$this->assertFalse(
				wp_next_scheduled(
					'webp_uploads_create_image',
					array(
						$attachment_id,
						$size_name,
						'image/webp',
					)
				)
			);
		}
	}

	/**
	 * Prevent processing an image with corrupted metadata
	 *
	 * @dataProvider provider_with_modified_metadata
	 *
	 * @test
	 */
	public function it_should_prevent_processing_an_image_with_corrupted_metadata(
		callable $callback,
		$size
	) {
		$attachment_id = $this->import_attachment( 'balloons.webp' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		wp_update_attachment_metadata( $attachment_id, $callback( $metadata ) );
		$result = webp_uploads_generate_image_size( $attachment_id, $size, 'image/webp' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'image_mime_type_invalid_metadata', $result->get_error_code() );
	}

	public function provider_with_modified_metadata() {
		yield 'using a size that does not exists' => array(
			function ( $metadata ) {
				return $metadata;
			},
			'not-existing-size',
		);

		yield 'removing an existing metadata simulating that the image size still does not exists' => array(
			function ( $metadata ) {
				unset( $metadata['sizes']['medium'] );

				return $metadata;
			},
			'medium',
		);

		yield 'when the specified size is not a valid array' => array(
			function ( $metadata ) {
				$metadata['sizes']['medium'] = null;

				return $metadata;
			},
			'medium',
		);
	}

	/**
	 * Prevent to create an image size when attached file does not exists
	 *
	 * @test
	 */
	public function it_should_prevent_to_create_an_image_size_when_attached_file_does_not_exists() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$file = get_attached_file( $attachment_id );

		$this->assertFileExists( $file );
		wp_delete_file( $file );
		$this->assertFileDoesNotExist( $file );

		$result = webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/webp' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'image_file_size_not_found', $result->get_error_code() );
	}

	/**
	 * Prevent to create a subsize if the image editor does not exists
	 *
	 * @test
	 */
	public function it_should_prevent_to_create_a_subsize_if_the_image_editor_does_not_exists() {
		// Make sure no editor is available.
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		add_filter( 'wp_image_editors', '__return_empty_array' );
		$result = webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/webp' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'image_no_editor', $result->get_error_code() );
	}

	/**
	 * Prevent to upload a mime that is not supported by WordPress
	 *
	 * @test
	 */
	public function it_should_prevent_to_upload_a_mime_that_is_not_supported_by_wordpress() {
		// Make sure no editor is available.
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$result = webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/avif' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'image_mime_type_invalid', $result->get_error_code() );
	}

	/**
	 * Prevent to process an image when the editor does not support the format
	 *
	 * @test
	 */
	public function it_should_prevent_to_process_an_image_when_the_editor_does_not_support_the_format() {
		// Make sure no editor is available.
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		add_filter(
			'wp_image_editors',
			function () {
				return array( 'WP_Image_Doesnt_Support_WebP' );
			}
		);
		$result = webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/webp' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'image_mime_type_not_supported', $result->get_error_code() );
	}

	/**
	 * Create a WebP version with all the required properties
	 *
	 * @test
	 */
	public function it_should_create_a_webp_version_with_all_the_required_properties() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertArrayHasKey( 'sources', $metadata['sizes']['thumbnail'] );
		$this->assertArrayHasKey( 'image/jpeg', $metadata['sizes']['thumbnail']['sources'] );
		$this->assertArrayHasKey( 'filesize', $metadata['sizes']['thumbnail']['sources']['image/jpeg'] );
		$this->assertArrayHasKey( 'file', $metadata['sizes']['thumbnail']['sources']['image/jpeg'] );
		$this->assertArrayNotHasKey( 'image/webp', $metadata['sizes']['medium']['sources'] );

		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'thumbnail', 'image/webp' ) );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertArrayHasKey( 'image/webp', $metadata['sizes']['thumbnail']['sources'] );
		$this->assertArrayHasKey( 'filesize', $metadata['sizes']['thumbnail']['sources']['image/webp'] );
		$this->assertArrayHasKey( 'file', $metadata['sizes']['thumbnail']['sources']['image/webp'] );
		$file = $metadata['sizes']['thumbnail']['sources']['image/jpeg']['file'];
		$this->assertStringEndsNotWith( '.jpeg', $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] );
		$this->assertStringEndsWith( '.webp', $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] );
	}

	/**
	 * Create the sources property when the property does not exists
	 *
	 * @test
	 */
	public function it_should_create_the_sources_property_when_the_property_does_not_exists() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		unset( $metadata['sizes']['medium']['sources'] );
		// Use `update_post_meta` instead of `wp_update_attachment_metadata` to avoid trigger a hook when the postmeta is updated.
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );

		$this->assertArrayNotHasKey( 'sources', $metadata['sizes']['medium'] );
		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/webp' ) );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertArrayHasKey( 'sources', $metadata['sizes']['medium'] );
		$this->assertArrayNotHasKey( 'image/jpeg', $metadata['sizes']['medium']['sources'] );
		$this->assertArrayHasKey( 'image/webp', $metadata['sizes']['medium']['sources'] );
		$this->assertArrayHasKey( 'filesize', $metadata['sizes']['medium']['sources']['image/webp'] );
		$this->assertArrayHasKey( 'file', $metadata['sizes']['medium']['sources']['image/webp'] );
	}

	/**
	 * Create an image with the dimensions from the metadata instead of the dimensions of the sizes
	 *
	 * @test
	 */
	public function it_should_create_an_image_with_the_dimensions_from_the_metadata_instead_of_the_dimensions_of_the_sizes() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$metadata                                 = wp_get_attachment_metadata( $attachment_id );
		$metadata['sizes']['thumbnail']['width']  = 200;
		$metadata['sizes']['thumbnail']['height'] = 200;
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'thumbnail', 'image/webp' ) );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertArrayHasKey( 'image/webp', $metadata['sizes']['thumbnail']['sources'] );
		$this->assertStringEndsWith( '200x200.webp', $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] );
	}

	/**
	 * Remove `scaled` suffix from the generated filename
	 *
	 * @test
	 */
	public function it_should_remove_scaled_suffix_from_the_generated_filename() {
		// The leafs image is 1080 pixels wide with this filter we ensure a -scaled version is created.
		add_filter(
			'big_image_size_threshold',
			function () {
				return 850;
			}
		);

		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );

		$this->assertStringEndsWith( '-scaled.jpg', get_attached_file( $attachment_id ) );
		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/webp' ) );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertArrayHasKey( 'image/webp', $metadata['sizes']['medium']['sources'] );
		$this->assertStringEndsNotWith( '-scaled.webp', $metadata['sizes']['medium']['sources']['image/webp']['file'] );
		$this->assertStringEndsWith( '-300x200.webp', $metadata['sizes']['medium']['sources']['image/webp']['file'] );
	}

	/**
	 * Remove the generated webp images when the attachment is deleted
	 *
	 * @test
	 */
	public function it_should_remove_the_generated_webp_images_when_the_attachment_is_deleted() {
		// Make sure no editor is available.
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$file    = get_attached_file( $attachment_id, true );
		$dirname = pathinfo( $file, PATHINFO_DIRNAME );

		$this->assertIsString( $file );
		$this->assertFileExists( $file );

		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'thumbnail', 'image/webp' ) );
		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'medium', 'image/webp' ) );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$sizes    = array( 'thumbnail', 'medium' );

		foreach ( $sizes as $size_name ) {
			$this->assertArrayHasKey( 'image/webp', $metadata['sizes'][ $size_name ]['sources'] );
			$this->assertArrayHasKey( 'file', $metadata['sizes'][ $size_name ]['sources']['image/webp'] );
			$this->assertFileExists(
				path_join( $dirname, $metadata['sizes'][ $size_name ]['sources']['image/webp']['file'] )
			);
		}

		wp_delete_attachment( $attachment_id );

		foreach ( $sizes as $size_name ) {
			$this->assertFileDoesNotExist(
				path_join( $dirname, $metadata['sizes'][ $size_name ]['sources']['image/webp']['file'] )
			);
		}
	}

	/**
	 * Remove the attached WebP version if the attachment is force deleted but empty trash day is not defined
	 *
	 * @test
	 */
	public function it_should_remove_the_attached_webp_version_if_the_attachment_is_force_deleted_but_empty_trash_day_is_not_defined() {
		// Make sure no editor is available.
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$file    = get_attached_file( $attachment_id, true );
		$dirname = pathinfo( $file, PATHINFO_DIRNAME );

		$this->assertIsString( $file );
		$this->assertFileExists( $file );

		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'thumbnail', 'image/webp' ) );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertFileExists(
			path_join( $dirname, $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] )
		);

		wp_delete_attachment( $attachment_id, true );

		$this->assertFileDoesNotExist(
			path_join( $dirname, $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] )
		);
	}

	/**
	 * Remove the WebP version of the image if the image is force deleted and empty trash days is set to zero
	 *
	 * @test
	 */
	public function it_should_remove_the_webp_version_of_the_image_if_the_image_is_force_deleted_and_empty_trash_days_is_set_to_zero() {
		// Make sure no editor is available.
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$file    = get_attached_file( $attachment_id, true );
		$dirname = pathinfo( $file, PATHINFO_DIRNAME );

		$this->assertIsString( $file );
		$this->assertFileExists( $file );

		$this->assertTrue( webp_uploads_generate_image_size( $attachment_id, 'thumbnail', 'image/webp' ) );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertFileExists(
			path_join( $dirname, $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] )
		);

		define( 'EMPTY_TRASH_DAYS', 0 );

		wp_delete_attachment( $attachment_id, true );

		$this->assertFileDoesNotExist(
			path_join( $dirname, $metadata['sizes']['thumbnail']['sources']['image/webp']['file'] )
		);
	}

	/**
	 * Update the sources property of all image sizes when an edited is applied to an image
	 *
	 * @test
	 */
	public function it_should_update_the_sources_property_of_all_image_sizes_when_an_edited_is_applied_to_an_image() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );

		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertArrayHasKey( 'sources', $properties );
			$this->assertArrayHasKey( 'image/jpeg', $properties['sources'] );
			$this->assertArrayHasKey( 'file', $properties['sources']['image/jpeg'] );
			$this->assertSame( $properties['file'], $properties['sources']['image/jpeg']['file'] );
			$this->assertDoesNotMatchRegularExpression(
				$this->edited_filename_regex(),
				$properties['sources']['image/jpeg']['file']
			);
		}

		$operation        = ( new WP_Image_Edit( $attachment_id ) )->flip_right()->all()->save();
		$updated_metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertSame( 'Image saved', $operation->msg );
		$this->assertNotSame( $metadata, $updated_metadata );

		foreach ( $updated_metadata['sizes'] as $size_name => $properties ) {
			$this->assertArrayHasKey( 'sources', $properties );
			$this->assertArrayHasKey( 'image/jpeg', $properties['sources'] );
			$this->assertArrayHasKey( 'file', $properties['sources']['image/jpeg'] );
			$this->assertSame( $properties['file'], $properties['sources']['image/jpeg']['file'] );
			$this->assertMatchesRegularExpression(
				$this->edited_filename_regex(),
				$properties['sources']['image/jpeg']['file']
			);
		}
	}

	/**
	 * Update only the thumbnail source when the image is edited
	 *
	 * @test
	 */
	public function it_should_update_only_the_thumbnail_source_when_the_image_is_edited() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		$operation     = ( new WP_Image_Edit( $attachment_id ) )->flip_vertical()->only_thumbnail()->save();
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertSame( 'Image saved', $operation->msg );

		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertSame( $properties['sources']['image/jpeg']['file'], $properties['file'] );
			if ( 'thumbnail' === $size_name ) {
				$this->assertMatchesRegularExpression(
					$this->edited_filename_regex(),
					$properties['sources']['image/jpeg']['file']
				);
			} else {
				$this->assertDoesNotMatchRegularExpression(
					$this->edited_filename_regex(),
					$properties['sources']['image/jpeg']['file']
				);
			}
		}
	}

	/**
	 * Update all sizes except the thumbnail when the image is edited
	 *
	 * @test
	 */
	public function it_should_update_all_sizes_except_the_thumbnail_when_the_image_is_edited() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );
		// Prevent additional resources by removing the need to create additional resources for this image.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$operation = ( new WP_Image_Edit( $attachment_id ) )->flip_right()->all_except_thumbnail()->save();

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertSame( 'Image saved', $operation->msg );

		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertSame( $properties['sources']['image/jpeg']['file'], $properties['file'] );
			if ( 'thumbnail' === $size_name ) {
				$this->assertDoesNotMatchRegularExpression(
					$this->edited_filename_regex(),
					$properties['sources']['image/jpeg']['file']
				);
			} else {
				$this->assertMatchesRegularExpression(
					$this->edited_filename_regex(),
					$properties['sources']['image/jpeg']['file']
				);
			}
		}
	}

	/**
	 * Schedule the generation of a WebP image when all sizes are edited
	 *
	 * @test
	 */
	public function it_should_schedule_the_generation_of_a_webp_image_when_all_sizes_are_edited() {
		$attachment_id = $this->import_attachment( 'leafs.jpg' );

		$operation = ( new WP_Image_Edit( $attachment_id ) )->rotate_left()->all()->save();
		$metadata  = wp_get_attachment_metadata( $attachment_id );

		$this->assertSame( 'Image saved', $operation->msg );

		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$this->assertGreaterThan(
				0,
				wp_next_scheduled(
					'webp_uploads_create_image',
					array(
						$attachment_id,
						$size_name,
						'image/webp',
					)
				)
			);
		}
	}

	/**
	 * Should schedule the generation of a WebP image only to a thumbnail image
	 *
	 * @test
	 */
	public function it_should_should_schedule_the_generation_of_a_webp_image_only_to_a_thumbnail_image() {
		$attachment_id = $this->import_attachment( 'car.jpeg' );
		// Make sure any remaining job is removed to avoid confusion with upcoming tests.
		wp_unschedule_hook( 'webp_uploads_create_image' );
		$operation = ( new WP_Image_Edit( $attachment_id ) )->rotate_left()->only_thumbnail()->save();
		$metadata  = wp_get_attachment_metadata( $attachment_id );

		$this->assertSame( 'Image saved', $operation->msg );

		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$scheduled = wp_next_scheduled(
				'webp_uploads_create_image',
				array(
					$attachment_id,
					$size_name,
					'image/webp',
				)
			);
			if ( 'thumbnail' === $size_name ) {
				$this->assertGreaterThan( 0, $scheduled, $size_name );
			} else {
				$this->assertFalse( $scheduled, $size_name );
			}
		}
	}

	/**
	 * Schedule the generation of webp image to all images sizes except the thumbnail
	 *
	 * @test
	 */
	public function it_should_schedule_the_generation_of_webp_image_to_all_images_sizes_except_the_thumbnail() {
		$attachment_id = $this->import_attachment( 'car.jpeg' );
		// Make sure any remaining job is removed to avoid confusion with upcoming tests.
		wp_unschedule_hook( 'webp_uploads_create_image' );

		$operation = ( new WP_Image_Edit( $attachment_id ) )->rotate_right()->all_except_thumbnail()->save();

		$this->assertSame( 'Image saved', $operation->msg );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		foreach ( $metadata['sizes'] as $size_name => $properties ) {
			$scheduled = wp_next_scheduled(
				'webp_uploads_create_image',
				array(
					$attachment_id,
					$size_name,
					'image/webp',
				)
			);
			if ( 'thumbnail' === $size_name ) {
				$this->assertFalse( $scheduled, $size_name );
			} else {
				$this->assertGreaterThan( 0, $scheduled, $size_name );
			}
		}
	}

	/**
	 * Create an upload an attachment from the test directory, specifically located
	 * at /tests/testdata/modules/images/
	 *
	 * @param string $file_name File name with extension to be loaded.
	 *
	 * @return int|WP_Error
	 */
	protected function import_attachment( $file_name ) {
		return $this->factory->attachment->create_upload_object(
			TESTS_PLUGIN_DIR . "/tests/testdata/modules/images/{$file_name}"
		);
	}

	protected function edited_filename_regex() {
		return '/-e[0-9]{13}-/';
	}
}
