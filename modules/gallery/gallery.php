<?php

namespace PhotoPress\modules\gallery;
use photopress_module;
use WP_HTML_Tag_Processor;

/**
 * Gallery Module
 *
 * Registers the legacy photopress/gallery block, and the PhotoPress layouts
 * (masonry, rows, mosaic) that run on core/gallery.
 */
class gallery extends photopress_module {

	const LAYOUTS = [ 'masonry', 'rows', 'mosaic' ];

	public function definePublicHooks() {

		register_block_type( 'photopress/gallery', [

			// Enqueue blocks.style.build.css on both frontend & backend.
			'style'         		=> 'photopress-frontend',
			// Enqueue blocks.build.js in the editor only.
			'editor_script' 		=> 'photopress-editor',
			// Enqueue blocks.editor.build.css in the editor only.
			'editor_style'  		=> 'photopress-editor'
		]);

		add_filter( 'register_block_type_args', [ $this, 'addGalleryAttributes' ], 10, 2 );
		add_filter( 'render_block_core/gallery', [ $this, 'renderGalleryLayout' ], 10, 3 );
	}

	/**
	 * Registers the PhotoPress attributes on core/gallery. Keep in step with
	 * ATTRIBUTES in src/variations/gallery-layouts.js.
	 */
	public function addGalleryAttributes( $args, $name ) {

		if ( 'core/gallery' !== $name ) {
			return $args;
		}

		$args['attributes'] = array_merge( $args['attributes'] ?? [], [
			'photopressLayout'      => [ 'type' => 'string' ],
			'photopressColumnWidth' => [ 'type' => 'number', 'default' => 300 ],
			'photopressRowHeight'   => [ 'type' => 'number', 'default' => 300 ],
			'photopressSlideshow'   => [ 'type' => 'boolean', 'default' => false ],
		] );

		return $args;
	}

	/**
	 * Adds the layout classes and variables to a core/gallery that has a
	 * PhotoPress layout, and the per-image hooks the slideshow uses. Nothing
	 * here is saved in the post.
	 *
	 * @param string    $content  The rendered gallery.
	 * @param array     $block    The parsed block.
	 * @param \WP_Block $instance The block instance; its attributes include defaults.
	 */
	public function renderGalleryLayout( $content, $block, $instance = null ) {

		$attrs  = $instance ? $instance->attributes : ( $block['attrs'] ?? [] );
		$layout = $attrs['photopressLayout'] ?? '';

		if ( ! in_array( $layout, self::LAYOUTS, true ) ) {
			return $content;
		}

		$column_width = (int) ( $attrs['photopressColumnWidth'] ?? 300 );
		$row_height   = (int) ( $attrs['photopressRowHeight'] ?? 300 );

		$p = new WP_HTML_Tag_Processor( $content );

		if ( ! $p->next_tag( [ 'tag_name' => 'figure', 'class_name' => 'wp-block-gallery' ] ) ) {
			return $content;
		}

		$p->add_class( 'photopress-layout' );
		$p->add_class( 'photopress-layout-' . $layout );
		$p->set_attribute( 'data-pp-column-width', (string) $column_width );
		$p->set_attribute( 'data-pp-row-height', (string) $row_height );

		$style = rtrim( trim( (string) $p->get_attribute( 'style' ) ), ';' );
		$vars  = sprintf( '--pp-column-width:%dpx;--pp-row-height:%dpx', $column_width, $row_height );
		$p->set_attribute( 'style', $style ? $style . ';' . $vars : $vars );

		// The slideshow opens on a click on .photopress-gallery-item, reads the
		// slide position from the clicked image, and finds items by data-id.
		$position = 0;

		while ( $p->next_tag( [ 'tag_name' => 'figure', 'class_name' => 'wp-block-image' ] ) ) {

			$p->set_bookmark( 'item' );
			$p->add_class( 'photopress-gallery-item' );
			$p->set_attribute( 'data-position', (string) $position );

			if ( $p->next_tag( 'img' ) ) {

				$p->set_attribute( 'data-position', (string) $position );
				$id    = $p->get_attribute( 'data-id' );
				$ratio = $this->aspectRatio( $p );

				$p->seek( 'item' );

				if ( $id ) {
					$p->set_attribute( 'data-id', $id );
				}

				// Mosaic grows each image in proportion to its shape.
				if ( $ratio ) {
					$item_style = rtrim( trim( (string) $p->get_attribute( 'style' ) ), ';' );
					$ratio_var  = sprintf( '--pp-ar:%.4F', $ratio );
					$p->set_attribute( 'style', $item_style ? $item_style . ';' . $ratio_var : $ratio_var );
				}
			}

			$p->release_bookmark( 'item' );
			$position++;
		}

		wp_enqueue_style( 'photopress-frontend' );

		if ( 'rows' !== $layout ) {
			$this->enqueueLayoutScript();
		}

		return $p->get_updated_html();
	}

	/**
	 * Width / height of the image the processor is on, from the attachment's
	 * stored dimensions -- the same source the editor uses, so both lay the
	 * mosaic out identically. Falls back to the metadata module's
	 * data-aspectratio, which is rounded to two places.
	 */
	private function aspectRatio( WP_HTML_Tag_Processor $p ) {

		$id = (int) $p->get_attribute( 'data-id' );

		if ( ! $id && preg_match( '/\bwp-image-(\d+)\b/', (string) $p->get_attribute( 'class' ), $m ) ) {
			$id = (int) $m[1];
		}

		$meta = $id ? wp_get_attachment_metadata( $id ) : false;

		if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return $meta['width'] / $meta['height'];
		}

		return max( 0, (float) $p->get_attribute( 'data-aspectratio' ) );
	}

	/**
	 * The script that runs masonry and mosaic; see src/frontend/gallery-layouts.js.
	 */
	private function enqueueLayoutScript() {

		$asset = \photopress_util::getBuildAsset( 'gallery-layouts.build' );

		wp_enqueue_script(
			'photopress-gallery-layouts',
			plugins_url( 'dist/gallery-layouts.build.js', dirname( dirname( __FILE__ ) ) ),
			array_unique( array_merge( [ 'masonry' ], $asset['dependencies'] ) ),
			$asset['version'],
			true
		);
	}
}

?>
