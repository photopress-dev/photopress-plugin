<?php

namespace PhotoPress\modules\media;

defined( 'ABSPATH' ) || exit;

/**
 * A Replace file button with an image's details, in the media modal and on
 * the image's edit screen. It sends the chosen file to Replace File
 * (MediaRest), which reads the new file's metadata as for an upload: there
 * is no way to ask otherwise from here.
 */
class ReplaceFileButton {

	const HANDLE = 'photopress-replace-file';

	public static function addHooks() {

		add_filter( 'attachment_fields_to_edit', [ self::class, 'field' ], 10, 2 );

		// The media modal is filled in by Ajax, after the page has loaded.
		add_action( 'wp_enqueue_media', [ self::class, 'enqueue' ] );
	}

	/**
	 * attachment_fields_to_edit: the button, for images that whoever is
	 * looking may replace (MediaRest::canReplace).
	 */
	public static function field( $fields, $post ) {

		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$fields['photopress_replace_file'] = [
			'label' => __( 'File' ),
			'input' => 'html',
			'html'  => sprintf(
				'<span class="photopress-replace-file" data-id="%d"><button type="button" class="button">%s</button> <input type="file" accept="image/*" hidden> <span class="photopress-replace-file__status" role="status"></span></span>',
				$post->ID,
				esc_html__( 'Replace file' )
			),
			'helps' => __( 'Upload a new file for this image. Its address stays the same, and its title, caption, description, alt text and terms are read from the new file.' ),
		];

		// The edit screen prints the fields in its body: the script follows
		// in the footer.
		self::enqueue();

		return $fields;
	}

	public static function enqueue() {

		if ( wp_script_is( self::HANDLE ) ) {
			return;
		}

		$asset = \photopress_util::getBuildAsset( 'replace-file.build' );

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( '/', __FILE__ ) . '../../dist/replace-file.build.js',
			array_unique( array_merge( [ 'wp-i18n' ], $asset['dependencies'] ) ),
			$asset['version'],
			true
		);
	}
}
