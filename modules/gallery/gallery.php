<?php

namespace PhotoPress\modules\gallery;
use photopress_module;
use pp_api;
use WP_HTML_Tag_Processor;

/**
 * Gallery Module
 *
 * Registers the legacy photopress/gallery block, and the PhotoPress layouts
 * (masonry, rows, mosaic) that run on core/gallery.
 */
class gallery extends photopress_module {

	public $label = 'Gallery';

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

		// The Gallery Slideshow block. Its script is the block's viewScript, so it
		// loads only on pages where the block renders.
		$asset = \photopress_util::getBuildAsset( 'gallery-slideshow.build' );
		wp_register_script(
			'photopress-gallery-slideshow',
			plugins_url( 'dist/gallery-slideshow.build.js', dirname( dirname( __FILE__ ) ) ),
			$asset['dependencies'],
			$asset['version'],
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);
		register_block_type( __DIR__ . '/blocks/gallery-slideshow', [
			'render_callback' => [ GallerySlideshow::class, 'render' ],
		] );

		GalleryAdd::addHooks();

		if ( pp_api::getOption( 'core', 'gallery', 'remove_deleted_images' ) ) {
			GalleryRemove::addHooks();
		}

		add_action( 'enqueue_block_editor_assets', [ $this, 'shareEnabledLayouts' ] );

		ImageTemplates::addHooks( (bool) pp_api::getOption( 'core', 'gallery', 'image_templates' ) );

		add_filter( 'register_block_type_args', [ $this, 'addGalleryAttributes' ], 10, 2 );
		add_filter( 'render_block_core/gallery', [ $this, 'renderGalleryLayout' ], 10, 3 );
		add_filter( 'render_block_core/gallery', [ GallerySlideshow::class, 'hideSourceGallery' ], 10, 2 );
	}

	/**
	 * The layouts turned on in the Gallery tab, as offered in the editor.
	 * A gallery that already has one turned off keeps it.
	 */
	public static function enabledLayouts() {

		return array_values( array_filter( self::LAYOUTS, static fn( $layout ) => (bool) pp_api::getOption( 'core', 'gallery', 'layout_' . $layout ) ) );
	}

	/**
	 * enqueue_block_editor_assets: the layouts the editor offers; see
	 * src/variations/gallery-layouts.js.
	 */
	public function shareEnabledLayouts() {

		wp_add_inline_script( 'photopress-editor', 'window.photopressGalleryLayouts = ' . wp_json_encode( self::enabledLayouts() ) . ';', 'before' );
	}

	public function registerOptions() {

		$options = [];

		foreach ( [ 'masonry' => 'Masonry', 'rows' => 'Rows', 'mosaic' => 'Mosaic' ] as $layout => $title ) {

			$options[ 'layout_' . $layout ] = [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => $title . ' layout',
					'page_name'     => 'gallery',
					'section'       => 'general',
					'description'   => 'Offer the ' . $title . ' layout for the Gallery block in the editor.',
					'label_for'     => $title . ' layout',
					'error_message' => '',
				],
			];
		}

		$options['remove_deleted_images'] = [
			'default_value' => true,
			'field'         => [
				'type'          => 'boolean',
				'title'         => 'Remove deleted images from galleries',
				'page_name'     => 'gallery',
				'section'       => 'general',
				'description'   => 'Remove images from galleries that are deleted from the Media Library.',
				'label_for'     => 'Remove deleted images from galleries',
				'error_message' => '',
			],
		];

		$options['image_templates'] = [
			'default_value' => true,
			'field'         => [
				'type'          => 'boolean',
				'title'         => 'Image templates',
				'page_name'     => 'gallery',
				'section'       => 'general',
				'description'   => 'In block themes, templates for image taxonomy archives and image pages, where the theme has none.',
				'label_for'     => 'Image templates',
				'error_message' => '',
			],
		];

		return $options;
	}

	public function registerSettingsPages() {

		return [

			'gallery' => [

				'parent_slug'         => 'photopress-core-base',
				'title'               => 'Gallery',
				'menu_title'          => 'Gallery',
				'required_capability' => 'manage_options',
				'menu_slug'           => 'photopress-gallery',
				'description'         => 'The Gallery block\'s PhotoPress layouts, galleries of deleted images, and templates for image archives and pages.',
				'sections'            => [
					'general' => [
						'id'          => 'general',
						'title'       => 'General',
						'description' => '',
					],
				],
				'noPhpRender'         => true,
			],
		];
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
			'photopressHideCaptions' => [ 'type' => 'boolean', 'default' => false ],
			// Taxonomy Gallery (ImageTemplates): "archive", the archive page's
			// images; "query", those of a taxonomy term, or the latest.
			'photopressSource'      => [ 'type' => 'string' ],
			'photopressTaxonomy'    => [ 'type' => 'string' ],
			'photopressTerm'        => [ 'type' => 'number' ],
			'photopressCount'       => [ 'type' => 'number', 'default' => 12 ],
			'photopressOrder'       => [ 'type' => 'string', 'default' => 'date-desc' ],
		] );

		return $args;
	}

	/**
	 * Applies the PhotoPress options of a core/gallery: the layout classes and
	 * variables, the per-image hooks the slideshow and mosaic use, and hidden
	 * captions. The slideshow and hidden captions also work on a gallery
	 * without a PhotoPress layout. Nothing here is saved in the post.
	 *
	 * @param string    $content  The rendered gallery.
	 * @param array     $block    The parsed block.
	 * @param \WP_Block $instance The block instance; its attributes include defaults.
	 */
	public function renderGalleryLayout( $content, $block, $instance = null ) {

		$attrs         = $instance ? $instance->attributes : ( $block['attrs'] ?? [] );
		$layout        = in_array( $attrs['photopressLayout'] ?? '', self::LAYOUTS, true ) ? $attrs['photopressLayout'] : '';
		$slideshow     = ! empty( $attrs['photopressSlideshow'] );
		$hide_captions = ! empty( $attrs['photopressHideCaptions'] );

		if ( ! $layout && ! $slideshow && ! $hide_captions ) {
			return $content;
		}

		$column_width = (int) ( $attrs['photopressColumnWidth'] ?? 300 );
		$row_height   = (int) ( $attrs['photopressRowHeight'] ?? 300 );

		$p = new WP_HTML_Tag_Processor( $content );

		if ( ! $p->next_tag( [ 'tag_name' => 'figure', 'class_name' => 'wp-block-gallery' ] ) ) {
			return $content;
		}

		if ( $hide_captions ) {
			$p->add_class( 'photopress-hide-captions' );
		}

		if ( $layout ) {

			$p->add_class( 'photopress-layout' );
			$p->add_class( 'photopress-layout-' . $layout );
			$p->set_attribute( 'data-pp-column-width', (string) $column_width );
			$p->set_attribute( 'data-pp-row-height', (string) $row_height );

			$style = rtrim( trim( (string) $p->get_attribute( 'style' ) ), ';' );
			$vars  = sprintf( '--pp-column-width:%dpx;--pp-row-height:%dpx', $column_width, $row_height );
			$p->set_attribute( 'style', $style ? $style . ';' . $vars : $vars );
		}

		// The slideshow opens on a click on .photopress-gallery-item, reads the
		// slide position from the clicked image, and finds items by data-id.
		$position = 0;

		while ( ( $layout || $slideshow ) && $p->next_tag( [ 'tag_name' => 'figure', 'class_name' => 'wp-block-image' ] ) ) {

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

		if ( ! in_array( $layout, [ 'masonry', 'mosaic' ], true ) ) {
			return $p->get_updated_html();
		}

		$this->enqueueLayoutScript();

		return $this->inlineLayout( $p->get_updated_html() );
	}

	/**
	 * The gallery with the scripts that lay it out before the page is first
	 * painted with it (src/frontend/gallery-layouts-inline.js), so it does
	 * not move when the page's scripts run.
	 *
	 * Before the first such gallery on the page: the layout code, and the
	 * photopress-js class on <html>, under which the CSS keeps a gallery
	 * hidden until it is laid out (the browser may paint before the script
	 * after a gallery runs). After each gallery: a call that lays it out and
	 * shows it.
	 */
	private function inlineLayout( $gallery ) {

		static $printed = false;

		if ( is_feed() ) {
			return $gallery;
		}

		$before = '';

		if ( ! $printed ) {
			$file = dirname( dirname( __DIR__ ) ) . '/dist/gallery-layouts-inline.build.js';
			$code = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';

			if ( '' !== $code ) {
				$before  = wp_get_inline_script_tag( "document.documentElement.classList.add('photopress-js');\n" . $code );
				$printed = true;
			}
		}

		return $before . $gallery . wp_get_inline_script_tag( 'window.photopressLayout&&photopressLayout(document.currentScript.previousElementSibling);' );
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
	 * The script that keeps masonry and mosaic galleries laid out as their
	 * width changes; see src/frontend/gallery-layouts.js. Rows is CSS alone.
	 */
	private function enqueueLayoutScript() {

		$asset = \photopress_util::getBuildAsset( 'gallery-layouts.build' );

		wp_enqueue_script(
			'photopress-gallery-layouts',
			plugins_url( 'dist/gallery-layouts.build.js', dirname( dirname( __FILE__ ) ) ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}
}

?>
