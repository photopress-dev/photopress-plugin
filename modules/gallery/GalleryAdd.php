<?php

namespace PhotoPress\modules\gallery;

use PhotoPress\modules\media\MediaRest;
use WP_Error;
use WP_HTML_Tag_Processor;
use WP_Post;
use WP_REST_Request;

/**
 * Adds an image to the core/gallery of a post, for publishing tools (such as
 * the Capture One plugin) that send it with the image:
 *
 *     photopress_gallery[post]=123              the post with the gallery
 *     photopress_gallery[gallery]=spring-2026   its anchor, when it has several
 *     photopress_gallery[replace]=true          remove the gallery's images first
 *
 * on POST /wp/v2/media (a new image, a REST field registered here) or on
 * POST /photopress/v1/media/<id>/file (a replacement). The result is in the
 * response's photopress_gallery: mode, added, attached and gallery, plus code
 * and message when the image was not added; with replace, also replaced, and
 * code and message when the gallery was not replaced. The upload or replacement stands
 * either way. A target that is not a post the user can edit is refused before
 * anything is uploaded.
 *
 * A static gallery keeps its images in the post as image blocks. The new one
 * copies the format of the gallery's last image (size, link kind, lightbox,
 * classes) and takes everything that belongs to an image from the new one:
 * its id, file, alt text, caption and link. A size or crop set for the copied
 * image is not carried over.
 *
 * With replace, the image takes the place of all the gallery's images, in
 * the format of its last one; a publishing tool sends it with the first image
 * of a set and adds the rest without it. The images taken out stay in the
 * media library. A dynamic gallery is not replaced: its images are the
 * post's attachments, and detaching them would move their attachment pages.
 *
 * A dynamic gallery (WordPress 7.1, core/attached-media) shows the images
 * attached to the post, so the image is added by attaching it. Only a new
 * image is attached: attaching one already published moves its attachment
 * page, whose URL includes the parent post.
 */
class GalleryAdd {

	const FIELD = 'photopress_gallery';

	/**
	 * Attributes of the copied image block that are about that image's own
	 * size or crop, or carry its bindings.
	 */
	const IMAGE_ATTRS_NOT_COPIED = [ 'id', 'url', 'alt', 'caption', 'title', 'href', 'width', 'height', 'aspectRatio', 'scale', 'focalPoint', 'metadata' ];

	/**
	 * Targets sent with new images, by attachment ID, until the request ends.
	 *
	 * @var array[]
	 */
	protected static $pending = [];

	public static function addHooks() {

		add_action( 'rest_api_init', [ self::class, 'registerField' ] );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'addPending' ], 10, 3 );
		add_action( 'add_attachment', [ self::class, 'attachmentAdded' ] );
		add_action( 'attachment_updated', [ self::class, 'attachmentUpdated' ], 10, 3 );
	}

	/**
	 * The argument schema of a gallery target, for the media field and the
	 * replace route.
	 */
	public static function schema() {

		return [
			'description' => __( 'Add the image to the gallery of this post. post is the post ID; gallery is the gallery\'s HTML anchor, needed when the post has more than one; replace removes the gallery\'s images first.', 'photopress' ),
			'type'        => 'object',
			'properties'  => [
				'post'    => [
					'type'     => 'integer',
					'required' => true,
				],
				'gallery' => [
					'type' => 'string',
				],
				'replace' => [
					'type' => 'boolean',
				],
			],
			'additionalProperties' => false,
			'context'     => [ 'edit' ],
			'arg_options' => [
				'validate_callback' => [ self::class, 'validateTarget' ],
			],
		];
	}

	public static function registerField() {

		register_rest_field( 'attachment', self::FIELD, [
			'get_callback'    => null,
			'update_callback' => [ self::class, 'remember' ],
			'schema'          => self::schema(),
		] );
	}

	/**
	 * The post must exist, have content, and be one the user can edit.
	 * Checked with the rest of the request, before anything is uploaded.
	 *
	 * @return true|WP_Error
	 */
	public static function validateTarget( $value, $request, $param ) {

		$valid = rest_validate_value_from_schema( $value, array_diff_key( self::schema(), [ 'arg_options' => 1 ] ), $param );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post = get_post( (int) $value['post'] );

		if ( ! $post || in_array( $post->post_type, [ 'attachment', 'revision' ], true ) || ! post_type_supports( $post->post_type, 'editor' ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The gallery\'s post does not exist.', 'photopress' ), [ 'status' => 400 ] );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'rest_cannot_edit', __( 'Sorry, you are not allowed to edit the gallery\'s post.', 'photopress' ), [ 'status' => rest_authorization_required_code() ] );
		}

		return true;
	}

	/**
	 * update_callback of the media field. Nothing is done yet: on an upload
	 * the image's sizes are made after the fields are saved, and the gallery
	 * needs them. See addPending().
	 */
	public static function remember( $value, $attachment, $field, $request ) {

		if ( is_array( $value ) && ! empty( $value['post'] ) ) {
			self::$pending[ (int) $attachment->ID ] = [
				'target'   => $value,
				'creating' => 'POST' === $request->get_method() && ! isset( $request['id'] ),
			];
		}

		return true;
	}

	/**
	 * rest_request_after_callbacks: once core has finished with the image,
	 * adds it to the gallery and puts the result in the response.
	 */
	public static function addPending( $response, $handler, $request ) {

		if ( ! self::$pending || is_wp_error( $response ) || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();
		$id   = (int) ( $data['id'] ?? 0 );

		if ( ! isset( self::$pending[ $id ] ) ) {
			return $response;
		}

		$pending = self::$pending[ $id ];
		unset( self::$pending[ $id ] );

		$data[ self::FIELD ] = self::add( $id, $pending['target'], $pending['creating'] );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Adds an image to a post's gallery.
	 *
	 * @param int   $id     Attachment ID.
	 * @param array $target post, and optionally gallery (an anchor) and replace.
	 * @param bool  $is_new Whether the image was uploaded by this request, and
	 *                      so may be attached without moving a published page.
	 * @return array mode, added, attached, gallery; code and message when not added.
	 */
	public static function add( $id, array $target, $is_new ) {

		$post_id = (int) ( $target['post'] ?? 0 );
		$anchor  = (string) ( $target['gallery'] ?? '' );
		$replace = ! empty( $target['replace'] );
		$post    = get_post( $post_id );
		$result  = [ 'mode' => null, 'added' => false, 'attached' => false, 'gallery' => $anchor ];

		if ( ! $post ) {
			return self::failed( $result, 'photopress_gallery_no_post', __( 'The gallery\'s post does not exist.', 'photopress' ) );
		}

		$blocks  = parse_blocks( $post->post_content );
		$gallery = self::chooseGallery( $blocks, $anchor );

		if ( is_wp_error( $gallery ) ) {
			return self::failed( $result, $gallery );
		}

		$result['gallery'] = $gallery['anchor'];

		if ( ! empty( $gallery['block']['attrs']['dynamicContent'] ) ) {

			$result = self::addToDynamic( $id, $post, $gallery['block'], $is_new, $result );

			// A reason the image was not added comes first.
			if ( $replace && ! isset( $result['code'] ) ) {
				$result['code']    = 'photopress_gallery_replace_dynamic';
				$result['message'] = __( 'The gallery shows the images attached to the post, so it was not replaced: removing them would detach them, which changes their attachment page URLs.', 'photopress' );
			}

			if ( $replace ) {
				$result['replaced'] = false;
			}

			return $result;
		}

		$result['mode'] = 'static';

		if ( $replace ) {
			$result['replaced'] = false;
		} elseif ( self::contains( $gallery['block'], $id, $post->ID ) ) {
			return $result;
		}

		require_once ABSPATH . 'wp-admin/includes/post.php';

		$locked_by = wp_check_post_lock( $post->ID );

		if ( $locked_by ) {
			$user = get_userdata( $locked_by );
			return self::failed( $result, 'photopress_gallery_post_locked', sprintf(
				/* translators: %s: a user's display name. */
				__( '%s is editing the post, so the gallery was not changed.', 'photopress' ),
				$user ? $user->display_name : __( 'Someone', 'photopress' )
			) );
		}

		$added = $replace ? self::replaceImages( $gallery['block'], $id ) : self::insertImage( $gallery['block'], $id );

		if ( is_wp_error( $added ) ) {
			return self::failed( $result, $added );
		}

		self::replaceBlock( $blocks, $gallery['path'], $added );

		// An edit of the post: a revision, a new modified date, and edit_post
		// for page caches.
		$saved = wp_update_post( wp_slash( [ 'ID' => $post->ID, 'post_content' => serialize_blocks( $blocks ) ] ), true );

		if ( is_wp_error( $saved ) ) {
			return self::failed( $result, $saved );
		}

		$result['added'] = true;

		if ( $replace ) {
			$result['replaced'] = true;
		}

		return $result;
	}

	/**
	 * A dynamic gallery shows the post's attached images.
	 */
	protected static function addToDynamic( $id, WP_Post $post, array $gallery, $is_new, array $result ) {

		$result['mode'] = 'dynamic';
		$source = $gallery['attrs']['dynamicContent']['source'] ?? '';

		if ( 'core/attached-media' !== $source ) {
			return self::failed( $result, 'photopress_gallery_unknown_source', sprintf(
				/* translators: %s: a gallery source name. */
				__( 'The gallery shows images from "%s", which PhotoPress cannot add to.', 'photopress' ),
				$source
			) );
		}

		$parent = (int) get_post_field( 'post_parent', $id );

		// A new image the upload attached here (core's post field) is in.
		if ( $parent === (int) $post->ID ) {
			$result['added'] = $result['attached'] = (bool) $is_new;
			return $result;
		}

		if ( ! $is_new ) {
			return self::failed( $result, 'photopress_gallery_reparent', $parent
				? __( 'The image is attached to another post. Adding it to this post\'s gallery would attach it here instead, which changes its attachment page URL.', 'photopress' )
				: __( 'The image is not attached to a post. Adding it to this post\'s gallery would attach it, which changes its attachment page URL.', 'photopress' )
			);
		}

		// attachmentUpdated() purges the post.
		$saved = wp_update_post( [ 'ID' => (int) $id, 'post_parent' => (int) $post->ID ], true );

		if ( is_wp_error( $saved ) ) {
			return self::failed( $result, $saved );
		}

		$result['added'] = true;
		$result['attached'] = true;

		return $result;
	}

	/**
	 * The gallery to add to: the one with the anchor, or the post's only one.
	 *
	 * @return array|WP_Error block, path (indexes down the block tree) and anchor.
	 */
	public static function chooseGallery( array $blocks, $anchor ) {

		$galleries = self::galleries( $blocks );

		if ( '' !== $anchor ) {

			foreach ( $galleries as $gallery ) {

				if ( $gallery['anchor'] === $anchor ) {
					return $gallery;
				}
			}

			return new WP_Error( 'photopress_gallery_not_found', sprintf(
				/* translators: %s: an HTML anchor. */
				__( 'The post has no gallery with the anchor "%s".', 'photopress' ),
				$anchor
			), [ 'anchors' => array_column( $galleries, 'anchor' ) ] );
		}

		if ( ! $galleries ) {
			return new WP_Error( 'photopress_gallery_none', __( 'The post has no gallery.', 'photopress' ) );
		}

		if ( count( $galleries ) > 1 ) {

			$anchors = array_values( array_filter( array_column( $galleries, 'anchor' ) ) );

			return new WP_Error( 'photopress_gallery_ambiguous', $anchors
				/* translators: %s: a list of HTML anchors. */
				? sprintf( __( 'The post has more than one gallery; name one by its anchor: %s.', 'photopress' ), implode( ', ', $anchors ) )
				: __( 'The post has more than one gallery, and none has an anchor to name it by.', 'photopress' ),
				[ 'anchors' => $anchors ] );
		}

		return $galleries[0];
	}

	/**
	 * The post's core/gallery blocks, at any depth.
	 */
	public static function galleries( array $blocks, array $path = [] ) {

		$found = [];

		foreach ( $blocks as $index => $block ) {

			$here = array_merge( $path, [ $index ] );

			if ( 'core/gallery' === ( $block['blockName'] ?? '' ) ) {
				$found[] = [ 'block' => $block, 'path' => $here, 'anchor' => GallerySlideshow::anchorOf( $block ) ];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::galleries( $block['innerBlocks'], $here ) );
			}
		}

		return $found;
	}

	/**
	 * Whether a static gallery already shows the image: an image block with
	 * its id, or with its wp-image-<id> class should the id attribute be
	 * missing.
	 */
	public static function contains( array $gallery, $id, $post_id = 0 ) {

		if ( in_array( (int) $id, GallerySlideshow::imageIds( $gallery, $post_id ), true ) ) {
			return true;
		}

		foreach ( $gallery['innerBlocks'] ?? [] as $inner ) {

			if ( 'core/image' === ( $inner['blockName'] ?? '' ) && preg_match( '/\bwp-image-' . (int) $id . '\b/', (string) ( $inner['innerHTML'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The gallery with an image block for the image after its last one.
	 *
	 * @return array|WP_Error
	 */
	public static function insertImage( array $gallery, $id ) {

		$last = self::lastImage( $gallery );

		if ( null === $last ) {
			return ! empty( $gallery['attrs']['ids'] )
				? new WP_Error( 'photopress_gallery_old_format', __( 'The gallery is in the format of WordPress before 5.9. Open the post in the editor once to update it.', 'photopress' ) )
				: new WP_Error( 'photopress_gallery_empty', __( 'The gallery has no image to copy the format of. Add the first image in the editor.', 'photopress' ) );
		}

		$block = self::imageBlockFrom( $gallery['innerBlocks'][ $last ], $id );

		if ( is_wp_error( $block ) ) {
			return $block;
		}

		array_splice( $gallery['innerBlocks'], $last + 1, 0, [ $block ] );

		// innerContent holds the gallery's own markup with a null where each
		// inner block goes. A new null after the last image's, with the
		// whitespace that separates the existing ones.
		$nulls = array_keys( $gallery['innerContent'], null, true );
		$position = $nulls[ $last ] ?? end( $nulls );
		$separator = "\n\n";

		if ( count( $nulls ) > 1 ) {
			$between = array_slice( $gallery['innerContent'], $nulls[0] + 1, $nulls[1] - $nulls[0] - 1 );
			$separator = implode( '', $between );
		}

		array_splice( $gallery['innerContent'], $position + 1, 0, [ $separator, null ] );

		return $gallery;
	}

	/**
	 * The gallery with an image block for the image in place of all its
	 * image blocks, in the format of its last one.
	 *
	 * @return array|WP_Error
	 */
	public static function replaceImages( array $gallery, $id ) {

		$added = self::insertImage( $gallery, $id );

		if ( is_wp_error( $added ) ) {
			return $added;
		}

		// insertImage() put it after the last image.
		$new   = self::lastImage( $gallery ) + 1;
		$inner = [];

		foreach ( $added['innerBlocks'] as $index => $block ) {

			if ( $index === $new || 'core/image' !== ( $block['blockName'] ?? '' ) ) {
				$inner[] = $block;
			}
		}

		// The gallery's own markup before, between and after its inner blocks.
		$content   = $added['innerContent'];
		$nulls     = array_keys( $content, null, true );
		$separator = implode( '', array_slice( $content, $nulls[0] + 1, $nulls[1] - $nulls[0] - 1 ) );
		$markup    = [ implode( '', array_slice( $content, 0, $nulls[0] ) ) ];

		foreach ( $inner as $index => $block ) {
			array_push( $markup, ...( $index ? [ $separator, null ] : [ null ] ) );
		}

		$markup[] = implode( '', array_slice( $content, end( $nulls ) + 1 ) );

		$added['innerBlocks']  = $inner;
		$added['innerContent'] = $markup;

		return $added;
	}

	/**
	 * The index of the gallery's last image block, or null.
	 */
	protected static function lastImage( array $gallery ) {

		$last = null;

		foreach ( $gallery['innerBlocks'] ?? [] as $index => $inner ) {

			if ( 'core/image' === ( $inner['blockName'] ?? '' ) ) {
				$last = $index;
			}
		}

		return $last;
	}

	/**
	 * An image block for the image, in the format of $template: its size,
	 * link kind, lightbox, classes and markup, with the image's own id,
	 * file, alt text, caption and link. Size and crop set for the template's
	 * image are left out.
	 *
	 * @return array|WP_Error
	 */
	public static function imageBlockFrom( array $template, $id ) {

		// The new id where the template's was: the editor saves attributes in
		// the block's order.
		$attrs = array_key_exists( 'id', $template['attrs'] ?? [] ) ? [] : [ 'id' => (int) $id ];

		foreach ( $template['attrs'] ?? [] as $name => $value ) {

			if ( 'id' === $name ) {
				$attrs['id'] = (int) $id;
			} elseif ( ! in_array( $name, self::IMAGE_ATTRS_NOT_COPIED, true ) ) {
				$attrs[ $name ] = $value;
			}
		}

		$size  = (string) ( $attrs['sizeSlug'] ?? 'full' );
		$src   = wp_get_attachment_image_url( $id, $size ) ?: wp_get_attachment_url( $id );
		$file  = wp_get_attachment_image_src( $id, $size ) ?: [ $src, 0, 0 ];

		if ( ! $src ) {
			return new WP_Error( 'photopress_gallery_no_file', __( 'The image has no file to show.', 'photopress' ) );
		}

		$link = '';

		switch ( $attrs['linkDestination'] ?? '' ) {
			case 'media':
				$link = (string) wp_get_attachment_url( $id );
				break;
			case 'attachment':
				$link = (string) get_attachment_link( $id );
				break;
			case 'custom':
				// A link chosen for that image.
				$attrs['linkDestination'] = 'none';
				break;
		}

		$html = (string) ( $template['innerHTML'] ?? '' );

		if ( ! $link ) {
			$html = preg_replace( '#<a\b[^>]*>\s*(<img\b[^>]*>)\s*</a>#s', '$1', $html );
			unset( $attrs['linkTarget'], $attrs['rel'], $attrs['linkClass'] );
		}

		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag() ) {

			switch ( $p->get_tag() ) {

				case 'FIGURE':
					$p->remove_class( 'is-resized' );
					break;

				case 'A':
					$p->set_attribute( 'href', $link );
					break;

				case 'IMG':
					$p->set_attribute( 'src', $src );

					// Collected first: removing a class while walking the list skips one.
					foreach ( iterator_to_array( $p->class_list(), false ) as $class ) {

						if ( preg_match( '/^wp-image-\d+$/', $class ) ) {
							$p->remove_class( $class );
						}
					}

					$p->add_class( 'wp-image-' . (int) $id );

					if ( null !== $p->get_attribute( 'data-id' ) ) {
						$p->set_attribute( 'data-id', (string) (int) $id );
					}

					// Older galleries saved these on each image.
					if ( null !== $p->get_attribute( 'data-full-url' ) ) {
						$p->set_attribute( 'data-full-url', (string) wp_get_attachment_url( $id ) );
					}

					if ( null !== $p->get_attribute( 'data-link' ) ) {
						$p->set_attribute( 'data-link', (string) get_attachment_link( $id ) );
					}

					// The image's own dimensions where the template gives its.
					foreach ( [ 'width' => 1, 'height' => 2 ] as $name => $index ) {

						if ( null === $p->get_attribute( $name ) ) {
							continue;
						}

						if ( empty( $file[ $index ] ) ) {
							$p->remove_attribute( $name );
						} else {
							$p->set_attribute( $name, (string) (int) $file[ $index ] );
						}
					}

					foreach ( [ 'title', 'srcset', 'sizes' ] as $name ) {
						$p->remove_attribute( $name );
					}

					// Border styles are format; the size and crop are that image's.
					$style = self::styleWithout( (string) $p->get_attribute( 'style' ), [ 'width', 'height', 'aspect-ratio', 'object-fit', 'object-position' ] );

					if ( '' === $style ) {
						$p->remove_attribute( 'style' );
					} else {
						$p->set_attribute( 'style', $style );
					}
					break;
			}
		}

		$alt     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$caption = (string) wp_get_attachment_caption( $id );
		// The editor writes void tags as "<img .../>"; removing the last
		// attribute leaves the space before it.
		$html    = MediaRest::imageBlockWith( preg_replace( '#(<img\b[^>]*?)\s+/>#', '$1/>', $p->get_updated_html() ), $alt, $caption );

		return [
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * An inline style without the given properties, in the form the block
	 * editor saves ("a:b;c:d").
	 */
	public static function styleWithout( $style, array $properties ) {

		$kept = [];

		foreach ( explode( ';', $style ) as $declaration ) {

			$parts = explode( ':', $declaration, 2 );

			if ( 2 === count( $parts ) && ! in_array( strtolower( trim( $parts[0] ) ), $properties, true ) ) {
				$kept[] = trim( $parts[0] ) . ':' . trim( $parts[1] );
			}
		}

		return implode( ';', $kept );
	}

	/**
	 * Puts $block at $path in the block tree.
	 */
	protected static function replaceBlock( array &$blocks, array $path, array $block ) {

		$index = array_shift( $path );

		if ( ! $path ) {
			$blocks[ $index ] = $block;
			return;
		}

		self::replaceBlock( $blocks[ $index ]['innerBlocks'], $path, $block );
	}

	/**
	 * add_attachment: an image uploaded into a post with a dynamic gallery
	 * changes what the post shows, but WordPress fires nothing for the post.
	 */
	public static function attachmentAdded( $id ) {

		$parent = (int) get_post_field( 'post_parent', $id );

		if ( $parent && self::hasDynamicGallery( $parent ) ) {
			MediaRest::announceEdit( $parent );
		}
	}

	/**
	 * attachment_updated: an image attached to, moved between or detached
	 * from posts with dynamic galleries.
	 */
	public static function attachmentUpdated( $id, $after, $before ) {

		if ( (int) $after->post_parent === (int) $before->post_parent ) {
			return;
		}

		foreach ( [ (int) $before->post_parent, (int) $after->post_parent ] as $parent ) {

			if ( $parent && self::hasDynamicGallery( $parent ) ) {
				MediaRest::announceEdit( $parent );
			}
		}
	}

	public static function hasDynamicGallery( $post_id ) {

		$content = (string) get_post_field( 'post_content', $post_id );

		return false !== strpos( $content, '<!-- wp:gallery' ) && false !== strpos( $content, '"dynamicContent"' );
	}

	/**
	 * @param array           $result
	 * @param string|WP_Error $code
	 * @param string          $message
	 */
	protected static function failed( array $result, $code, $message = '' ) {

		if ( is_wp_error( $code ) ) {
			$data = $code->get_error_data();
			$message = $code->get_error_message();
			$code = $code->get_error_code();

			if ( ! empty( $data['anchors'] ) ) {
				$result['anchors'] = $data['anchors'];
			}
		}

		return $result + [ 'code' => $code, 'message' => $message ];
	}
}
