<?php

namespace PhotoPress\modules\metadata;
use photopress_module;
use pp_api;
use photopress_util;

/**
 * Registers the Core Image Taxonomies
 *
 *
 */
class metadata extends photopress_module {
	
	public $label = 'Meta-data'; 
	
	public function definePublicHooks() {
		
		add_filter( 'max_srcset_image_width', [$this, 'setMaxSrcsetSize'], 10,2);
		
		// add additional meta-data to images
		add_filter( 'wp_read_image_metadata', [$this, 'storeMoreMetaData'], 10, 5);
		
		add_action( 'update_option_' . photopress_util::getModuleOptionKey( 'core', 'metadata' ), [ $this, 'taxonomiesChanged' ], 10, 2 );
		
		add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
		
		// the description of an image whose file was replaced (see MediaRest)
		add_filter( 'photopress_attachment_description', [ $this, 'descriptionForReplacedFile' ], 10, 3 );
		
		// re-reading the metadata of every image, as a background job
		\PhotoPress\jobs\Jobs::register( 'metadata.reprocess', [
			'label'       => __( 'Re-read image metadata' ),
			'description' => __( 'Reads every image\'s embedded metadata again, as on upload: its image taxonomies, alt text and description. For after changing the taxonomies or templates.' ),
			'count'       => [ self::class, 'countImages' ],
			'items'       => [ self::class, 'nextImages' ],
			'process'     => [ $this, 'reprocessImage' ],
		] );
		
		// add additional attributes to images
		//add_filter( 'wp_get_attachment_image_attributes', [$this, 'addAttributesToImages' ], 11, 2 );
		add_filter( 'render_block', [ $this, 'addAttributesToImagesInContent' ], 11, 3 );
		
		// embed license meta-data in all uploaded images even if it already exists.
		if ( pp_api::getOption('core', 'metadata', 'embed_licensor_enable') ) {
			
			add_filter( 'pre_move_uploaded_file', [ $this, 'embedLicense' ], 1, 4 );
		}
		
		add_filter( 'frame/attachment/image_markup', [ $this, 'addLicenseToImageMarkup'], 10, 2 );
		
		// stop wordpress from stripping image meta from resized images.
		add_filter ('image_strip_meta', function() {
			
			return pp_api::getOption( 'core', 'metadata', 'strip_metadata_from_resized_image');
		});

		
		// registers display widgets
		add_action( 'widgets_init', [ $this, 'registerWidgets' ] );
		
		// The block counterpart of the taxonomy widget, for block themes, which
		// have no widget areas.
		register_block_type( __DIR__ . '/blocks/image-taxonomies', [
			'render_callback' => [ ImageTaxonomies::class, 'renderBlock' ],
		] );
		ImageTaxonomyRest::addHooks();
		
		if ( pp_api::getOption( 'core', 'metadata', 'custom_taxonomies_enable' ) ) {
			
			// registers the actual taxonomies
			 $this->registerTaxonomies();
			 $this->maybeFlushRewriteRules();
			
			//registers the attachment page sidebar
			register_sidebar(
			
				[
				  'name' => 'Image Page',
				  'id' => 'photopress-image-primary',
				  'description' => 'Widgets in this area will be shown on single image pages.',
				  'before_widget' => '<section id="%1$s" class="widget %2$s">',
				  'after_widget' => '</section>'
				]
			);
			
			/**
			 * Taxonomy handler for when new images are uploaded
			 */
			add_action('add_attachment', [ $this, 'addAttachment' ] );
			
			/**
			 * Handler for extracting meta data from image file and storing it as
			 * part of the Post's meta data.
			 */
			//add_filter('wp_generate_attachment_metadata', 'papt_storeNewMeta',1,2);
			
			add_action('enable-media-replace-upload-done', [ $this, 'updateAttachment' ], 1, 2 );

			/**
			 * Handler for when PhotoPress gives an image a new file (see
			 * MediaRest): its terms and alt text come from the new file,
			 * unless the client asked for them to be left alone.
			 */
			add_action( 'photopress_attachment_file_replaced', [ $this, 'fileReplaced' ], 10, 4 );
						
			// needed to show attachments on taxonomy pages
			add_filter( 'pre_get_posts', [ $this, 'makeImagesVisibleToTaxQueries' ] );
			
		}
	}	
	
	/**
	 * A taxonomy added, renamed or made nested has new URLs, which WordPress
	 * knows only after its rewrite rules are rebuilt. Marked when the setting
	 * changes; rebuilt on the next request, once the taxonomies are registered.
	 */
	public function taxonomiesChanged( $old, $new ) {

		$shape = static function ( $value ) {
			return array_map( static function ( $tax ) {
				$tax = (array) $tax;
				return [ $tax['id'] ?? '', $tax['singularLabel'] ?? '', ! empty( $tax['nested'] ) && ! empty( $tax['parseTagValue'] ), ! empty( $tax['disabled'] ) ];
			}, (array) ( ( (array) $value )['custom_taxonomies'] ?? [] ) );
		};

		if ( $shape( $old ) !== $shape( $new ) ) {
			update_option( 'photopress_flush_rewrite_rules', 1 );
		}
	}

	public function registerRestRoutes() {

		register_rest_route( 'photopress/v1', '/image-taxonomies', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'taxonomyStatus' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		] );
	}

	/**
	 * For the Image Taxonomies settings: each taxonomy's number of terms, and
	 * the prefixes in Keywords no parent keyword takes ("organization:
	 * Automattic"), with how many photos have them and a few of their values.
	 */
	public static function taxonomyStatus() {

		$model  = TaxonomyModel::fromSettings();
		$counts = [];

		foreach ( $model->taxonomyIds() as $id ) {
			if ( taxonomy_exists( $id ) ) {
				$counts[ $id ] = (int) wp_count_terms( [ 'taxonomy' => $id, 'hide_empty' => false ] );
			}
		}

		return [
			'counts'   => $counts,
			'prefixes' => isset( $model->standard['keywords'] ) ? self::unclaimedPrefixes( $model ) : [],
		];
	}

	/**
	 * Keywords terms written "prefix: value" whose prefix is no parent
	 * keyword, by prefix, most photos first.
	 */
	public static function unclaimedPrefixes( TaxonomyModel $model ) {

		global $wpdb;

		$separators = $model->separators;

		if ( ! $separators ) {
			return [];
		}

		$like = implode( ' OR ', array_fill( 0, count( $separators ), 't.name LIKE %s' ) );
		$args = array_merge( [ $model->standard['keywords']['id'] ], array_map( static function ( $s ) use ( $wpdb ) {
			return '%' . $wpdb->esc_like( $s ) . '%';
		}, $separators ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.name, tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND ( $like )", $args ) );

		$taken = [];

		foreach ( $model->parents as $parent ) {
			foreach ( $parent['names'] as $name ) {
				$taken[ implode( '|', $name ) ] = true;
			}
		}

		$found = [];

		foreach ( (array) $rows as $row ) {

			$name = html_entity_decode( $row->name, ENT_QUOTES, 'UTF-8' );

			foreach ( $separators as $separator ) {

				$pos = strpos( $name, $separator );

				if ( ! $pos ) {
					continue;
				}

				$prefix = TaxonomyModel::lower( trim( substr( $name, 0, $pos ) ) );
				$value  = trim( substr( $name, $pos + strlen( $separator ) ) );

				if ( '' !== $value && ! isset( $taken[ $prefix ] ) ) {
					$found[ $prefix ]['prefix'] = $prefix;
					$found[ $prefix ]['photos'] = ( $found[ $prefix ]['photos'] ?? 0 ) + (int) $row->count;
					$found[ $prefix ]['examples'][] = $value;
				}
				break;
			}
		}

		foreach ( $found as $prefix => $item ) {
			$found[ $prefix ]['examples'] = array_slice( array_values( array_unique( $item['examples'] ) ), 0, 3 );
		}

		usort( $found, static fn( $a, $b ) => $b['photos'] <=> $a['photos'] );

		return array_slice( $found, 0, 20 );
	}

		private function maybeFlushRewriteRules() {

		if ( get_option( 'photopress_flush_rewrite_rules' ) ) {
			delete_option( 'photopress_flush_rewrite_rules' );
			flush_rewrite_rules( false );
		}
	}
	
	public function defineAdminHooks() {
		
	
	}
	
	/**
	 * Adds data-* attributes required by img tags in post HTML
	 * content. To be used by 'the_content' filter.
	 *
	 *
	 * @param string $content HTML content of the post
	 * @return string Modified HTML content of the post
	 */
	public function addAttributesToImagesInContent( $content, $block ) {
		
		$allowedBlocks = ['photopress/gallery', 'core/image'];
		
		if( ! in_array( $block['blockName'], $allowedBlocks ) ) {
			
			return $content;
  		}
		
		
		// Attachment ids from wp-image-N classes or data-id attributes.
		$p   = new \WP_HTML_Tag_Processor( $content );
		$ids = [];

		while ( $p->next_tag( 'img' ) ) {

			$id = $this->attachmentIdOf( $p );

			if ( $id ) {
				$ids[ $id ] = true;
			}
		}

		if ( empty( $ids ) ) {
			
			return $content;
		}
		
		$attachments = get_posts(
			
			[
				'include'          => array_keys( $ids ),
				'post_type'        => 'any',
				'post_status'      => 'any',
				'suppress_filters' => false,
			]
		);
		
		$attributes_by_id  = [];
		$licensable_images = [];
		
		foreach ( $attachments as $attachment ) {
			
			$attributes_by_id[ $attachment->ID ] = $this->addAttributesToImages( [], $attachment );
			
			// add licensable image
			$licensable_images[] = $attachment->ID;
		}

		/*
		 * Add each attribute only where the tag does not already have it, so
		 * values the block itself saved (a gallery image's data-caption, for
		 * one) win over the media library's. Splicing the attributes in front
		 * of the existing ones made the media library's win, as the first of
		 * two duplicate attributes is the one browsers keep.
		 */
		$p = new \WP_HTML_Tag_Processor( $content );

		while ( $p->next_tag( 'img' ) ) {

			$id = $this->attachmentIdOf( $p );

			if ( ! $id || empty( $attributes_by_id[ $id ] ) ) {
				continue;
			}

			foreach ( $attributes_by_id[ $id ] as $name => $value ) {

				if ( null === $p->get_attribute( $name ) ) {
					// The builder escapes its values; the processor escapes again.
					$p->set_attribute( $name, html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5 ) );
				}
			}
		}

		$content = $p->get_updated_html();
		
		// add licensable images
		$content .= $this->renderLicensingSchema( $licensable_images );
	
		return $content;
	}
	
	/**
	 * The attachment id of the img the processor is on, from its wp-image-N
	 * class or data-id attribute.
	 */
	private function attachmentIdOf( \WP_HTML_Tag_Processor $p ) {

		if ( preg_match( '/\bwp-image-(\d+)\b/', (string) $p->get_attribute( 'class' ), $m ) ) {
			return absint( $m[1] );
		}

		return absint( $p->get_attribute( 'data-id' ) );
	}

	public function addAttributesToImages( $attr, $attachment = null ) {
		
		$attachment_id = intval( $attachment->ID );
		
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
		
			return $attr;
		}

		$orig_file       = wp_get_attachment_image_src( $attachment_id, 'full' );
		$orig_file       = isset( $orig_file[0] ) ? $orig_file[0] : wp_get_attachment_url( $attachment_id );
		
		//$attachment       = get_post( $attachment_id );
		$attachment_title 	= wptexturize( $attachment->post_title );
		$attachment_caption = wptexturize( $attachment->post_excerpt );
		$attachment_desc  	= wpautop( wptexturize( $attachment->post_content ) );
		$attachment_url		= get_attachment_link( $attachment_id );
		
		$attr[ 'data-orig-file' ]         	= esc_attr( $orig_file );
		$attr[ 'data-attachment-url' ]		= esc_attr( $attachment_url );
		
		// failsafe needed by slideshow in case
		// guttenebrg does not set the caption for a gallery
		
		if ( ! array_key_exists('data-aspectratio', $attr) ) {
			
			$meta = wp_get_attachment_metadata( $attachment_id );
			$attr['data-aspectratio']  = isset( $meta['width'] ) ? round( intval( $meta['width'] ) / intval( $meta['height'] ), 2 ) : '';
		}
		
		if ( ! array_key_exists('data-caption', $attr) ) {
			
			$attr[ 'data-caption' ]     	    = esc_attr( htmlspecialchars( $attachment_caption ) );
		}
		
		if ( ! array_key_exists('data-image-title', $attr) ) {
		
			$attr[ 'data-image-title' ]       	= esc_attr( htmlspecialchars( $attachment_title ) );
		}
		
		if ( ! array_key_exists('data-image-description', $attr) ) {
			
			$attr[ 'data-image-description' ] 	= esc_attr( htmlspecialchars( $attachment_desc ) );	
		}
		
		// No srcset: WordPress adds it later, with the sizes that tells the
		// browser how wide the image is shown. Given here, it stopped
		// WordPress adding either, and without sizes browsers take every
		// image for the width of the window.

		return $attr;
	}
	
	public function addLicenseToImageMarkup( $markup, $attachment_id ) {
		
		$markup .= $this->renderLicensingSchema( $attachment_id );
		
		return $markup;
	}
	
	public function renderLicensingSchema( $attachment_ids ) {
		
		$licensing_schema = $this->generateLicensingSchema( $attachment_ids );
		
		$content = "\n";
		$content .= sprintf('<script type="application/ld+json">%s</script>', json_encode( $licensing_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		
		return $content;
	}
	
	// can take an array of Ids or a single id
	public function generateLicensingSchema( $attachment_ids = [] ) {
		
		$schema_block = [];
		
		if ( is_array( $attachment_ids ) ) {
			
			foreach ( $attachment_ids as $id ) {
				
				$schema_block[] = $this->generateImageLicenseSchema( $id );
			}
			
		} else {
			
			$schema_block = $this->generateImageLicenseSchema( $attachment_ids );
		}
		
		return $schema_block;
	}
	
	public function generateImageLicenseSchema( $attachment_id ) {
		
		$schema = [
			
			"@context"				=> "https://schema.org/",
			"@type"					=> "ImageObject",
			"contentUrl" 			=> wp_get_attachment_url( $attachment_id ),
			"license" 				=> pp_api::getOption( 'core', 'metadata', 'web_statement_of_rights'),
			"acquireLicensePage" 	=> pp_api::getOption( 'core', 'metadata', 'licensor_url')
			
		];
		
		return $schema;
	}
	
	public function storeMoreMetaData( $meta, $file, $image_type, $iptc, $exif ) {
		
		// WordPress takes the title and caption from IPTC or EXIF only. Files
		// with XMP but no IPTC give theirs from dc:title and dc:description,
		// on upload and when a file is replaced alike.
		if ( empty( $meta['title'] ) || empty( $meta['caption'] ) ) {
			
			$md = new XmpReader();
			$md->loadFromFile( $file );
			
			foreach ( [ 'title' => 'dc:title', 'caption' => 'dc:description' ] as $key => $tag ) {
				
				$value = $md->getXmp( $tag );
				
				if ( empty( $meta[ $key ] ) && is_string( $value ) && '' !== trim( $value ) ) {
					$meta[ $key ] = trim( $value );
				}
			}
		}
		
		//pp_api::debug($meta);
		//pp_api::debug($image_type);
		//pp_api::debug($iptc);
		//pp_api::debug($exif);
		
		if ( array_key_exists('Make', $exif) && $exif['Make'] && array_key_exists('Model', $exif) && $exif['Model'] ) {
		
			$meta['camera'] = $exif['Make'] . ' ' . $exif['Model'];
		}
		
		if ( array_key_exists( 'LightSource', $exif ) ) {
		
			$meta['LightSource'] = $this->lookupLightSource( $exif['LightSource'] ) ;
		}
		
		return $meta;
	}
	
	// Lookup the LightSource value
	// see: https://exiftool.org/TagNames/EXIF.html#LightSource
	public function lookupLightSource( $code ) {
		
		$values = [
			
			0 => 'Unknown',
			1 => 'Daylight',
			2 => 'Fluorescent',
			3 => 'Tungsten (incandescent light)',
			4 => 'Flash',
			9 => 'Fine weather',
			10 => 'Cloudy weather',
			11 => 'Shade',
			12 => 'Daylight fluorescent (D 5700 - 7100K)',
			13 => 'Day white fluorescent (N 4600 - 5400K)',
			14 => 'Cool white fluorescent (W 3900 - 4500K)',
			15 => 'White fluorescent (WW 3200 - 3700K)',
			17 => 'Standard light A',
			18 => 'Standard light B',
			19 => 'Standard light C',
			20 => 'D55',
			21 => 'D65',
			22 => 'D75',
			23 => 'D50',
			24 => 'ISO studio tungsten',
			255 => 'Other light source'
		];
		
		if (array_key_exists( $code, $values ) ) {
		
			return $values[ $code ]; 
		
		} else {
			
			return $values[0];
		}
	}
	
	public function registerWidgets() {
		
		register_widget( 'PhotoPress\modules\metadata\XmpDisplayWidget' );
		register_widget( 'PhotoPress\modules\metadata\ExifDisplayWidget' );
	}
		
	public function registerOptions() {	

		return [
		
			'custom_taxonomies_enable'				=> [
			
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Enable Image Meta Data',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Enable Image Meta Data.',
					'label_for'								=> 'Enable Image Meta Data.',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
			
			'embed_licensor_enable' => [
				
				'default_value'							=> false,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Embed License in image file.',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Custom image taxonomies.',
					'label_for'								=> 'Custom image taxonomies.',
					'error_message'							=> ''		
				]	
			],
			
			'web_statement_of_rights'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'url',
					'title'									=> 'Web Statement of Rights',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The URL of the image license on the web.',
					'label_for'								=> 'The URL of the image license on the web.',
					'error_message'							=> ''		
				]	
			],
			
			'licensor_name'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'Licensor Name',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The legal name of the licensor of your images.',
					'label_for'								=> 'The legal name of the licensor of your images.',
					'error_message'							=> ''		
				]	
			],
			
			'licensor_url'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'url',
					'title'									=> 'Licensor Url',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The url where someone may obtain a license for your images.',
					'label_for'								=> 'The url where someone may obtain a license for your images.',
					'error_message'							=> ''		
				]	
			],
						
			'custom_taxonomies' => [
				
				'default_value'							=> $this->getDefaultTaxonomyDefinitions(),
				'field'									=> [
					'type'									=> 'none',
					'title'									=> 'Custom Meta Data Taxonomies',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Custom image taxonomies.',
					'label_for'								=> 'Custom image taxonomies.',
					'error_message'							=> ''		
				]	
			],
			
			'custom_taxonomies_tag_delimiter'	=> [
				
				'default_value'							=> ':',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP Value Delimiter',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Delimiter used to parse sub taxonomies from XMP values.',
					'label_for'								=> 'Delimiter used to parse sub taxonomies from XMP values',
					'error_message'							=> ''		
				]
			],
			
			'alt_text_enable'				=> [
			
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Populate Alt Text With Meta-data ',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Enable meta-data driven alt text.',
					'label_for'								=> 'Enable meta-data driven alt text.',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
			
			'alt_text_template'	=> [
				
				'default_value'							=> '[photoshop:Headline]. [photopress:stringOfKeywords].',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP template for Alt Text',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP tag template used to populate image alt text.',
					'label_for'								=> 'The XMP tag template used to populate image alt text.',
					'error_message'							=> ''		
				]
			],
			
			'description_template'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP template for Description',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP tag template used to set an image\'s description on upload and when its file is replaced, e.g. [photoshop:Headline]. Leave empty to leave descriptions alone.',
					'label_for'								=> 'The XMP tag template used to set image descriptions.',
					'error_message'							=> ''		
				]
			],
			
			// depricated
			'alt_text_tag'	=> [
				
				'default_value'							=> 'photoshop:Headline',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP Template for Alt Text',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP template used to populate image alt text.',
					'label_for'								=> 'The XMP template used to populate image alt text.',
					'error_message'							=> ''		
				]
			],
			
			'strip_metadata_from_resized_image'				=> [
			
				'default_value'							=> false,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Strip Meta-data From Resized Images',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Strip the embedded meta-data from resized images generated by WordPress.',
					'label_for'								=> 'Strip the embedded meta-data from resized images generated by WordPress',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
						
		];
		
	}
	
	public function registerSettingsPages() {
		
		$pages = [];
		
		$pages['metadata'] = [
			
			'parent_slug'					=> 'photopress-core-base',
			'title'							=> 'Image Meta Data',
			'menu_title'					=> 'Image Meta Data',
			'required_capability'			=> 'manage_options',
			'menu_slug'						=> 'photopress-metadata',
			'description'					=> 'Settings that control the meta data of images.',
			'sections'						=> [
				'general'						=> [
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control how meta data of images.'
				]
			],
			'noPhpRender'						=> true
		];
		
		return $pages;
	}
	
	/**
	 * The id of the default People taxonomy: pp_person, or photos_people,
	 * its id before 1.10, on a site whose People terms are stored under that
	 * and that never saved its taxonomy settings (so runs on these defaults).
	 * Worked out once and remembered.
	 */
	public static function peopleId() {

		$id = get_option( 'photopress_people_id' );

		if ( ! $id ) {

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", 'photos_people' ) );
			$id  = $old ? 'photos_people' : 'pp_person';

			update_option( 'photopress_people_id', $id );
		}

		return $id;
	}

		public function getDefaultTaxonomyDefinitions() {
		
		$taxonomies = [
			
			[
				'id'			=> 'photos_camera',
				'pluralLabel' 	=> 'cameras',
				'singularLabel'	=> 'camera',
				'tag'			=> 'photopress:camera',
				'parseTagValue'	=> false
			],
			
			[
				'id'			=> 'photos_lens',
				'pluralLabel'	=> 'lenses',
				'singularLabel'	=> 'lens',
				'tag'			=> 'aux:Lens',
				'parseTagValue'	=> false
			],
	
			[
				'id'			=> 'photos_city',
				'pluralLabel' 	=> 'cities',
				'singularLabel'	=> 'city',
				'tag'			=> 'photoshop:City',
				'parseTagValue'	=> false
			],
	
			[
				'id'			=> 'photos_state',
				'pluralLabel' 	=> 'states',
				'singularLabel'	=> 'state',
				'tag'			=> 'photoshop:State',
				'parseTagValue'	=> false
			],
			
			[
				'id'			=> 'photos_country',
				'pluralLabel' 	=> 'countries',
				'singularLabel'	=> 'country',
				'tag'			=> 'photoshop:Country',
				'parseTagValue'	=> false
			],
			
			[
				// pp_ and its singular name, as for any taxonomy added in the
				// settings; see peopleId().
				'id'			=> self::peopleId(),
				'pluralLabel' 	=> 'people',
				'singularLabel'	=> 'person',
				'tag'			=> 'dc:subject',
				'parseTagValue'	=> true,
				'names'			=> [ 'people', 'person' ]
			],
			
			[
				'id'			=> 'photos_keywords',
				'pluralLabel' 	=> 'keywords',
				'singularLabel'	=> 'keyword',
				'tag'			=> 'dc:subject',
				'parseTagValue'	=> false
			],
			
		];	
		
		return apply_filters( 'photopress/taxonomies/defaultDefinitions', $taxonomies );

	}
	
	public function registerTaxonomies() {
	
		$taxonomies = pp_api::getOption('core', 'metadata', 'custom_taxonomies');		
		//print_r($taxonomies);
		foreach ($taxonomies as $tax ) {
			
			// Turned off in the settings.
			if ( ! empty( $tax['disabled'] ) ) {
				continue;
			}
			
			$id = $tax[ 'id' ];
			$upper_plural = ucwords( $tax[ 'pluralLabel' ] );
			$upper_singular = ucwords( $tax[ 'singularLabel' ] );
			
			// A nested parent keyword's terms sit under one another, with
			// URLs to match: /person/family/jane.
			$nested = ! empty( $tax['nested'] ) && ! empty( $tax['parseTagValue'] );
			
			register_taxonomy( $id, 'attachment', array(
				
					'hierarchical' => $nested, 
					'labels' => array(
						
						'name'             				=> __( $upper_plural , 'taxonomy general name' ),
						'singular_name'     			=> __( $upper_singular, 'taxonomy singular name' ),
						'search_items'      			=> __( 'Search ' . $upper_plural ),
						'popular_items'					=> __( 'Popular ' . $upper_plural ),
						'all_items'         			=> __( 'All ' . $upper_plural ),
						'parent_item'       			=> null,
						'parent_item_colon' 			=> null,
						'edit_item'         			=> __( 'Edit ' . $upper_singular ),
						'update_item'       			=> __( 'Update ' . $upper_singular ),
						'add_new_item'      			=> __( 'Add New ' . $upper_singular  ),
						'new_item_name'     		 	=> __( 'New ' . $upper_singular . ' Name' ),
						'separate_items_with_commas' 	=> __( 'Separate ' . $tax[ 'pluralLabel' ] . ' with commas.' ),
						'add_or_remove_items'        	=> __( 'Add or remove ' . $upper_plural ),
						'choose_from_most_used'      	=> __( 'Choose from the most used '. $tax[ 'pluralLabel' ]),
						'not_found'                  	=> __( 'No ' . $tax[ 'pluralLabel' ] . ' found.' ),
						'menu_name'         			=> __( $upper_plural )
					),
					
					'query_var' => $id, 
					// In REST for the editor and core blocks, but only for users
					// who can edit posts; see ImageTaxonomyRest.
					'show_in_rest'          => true,
					'rest_controller_class' => TermsController::class,
					// As the settings screen shows it: "Acme job" is /acme-job/.
					'rewrite' => array('slug' => sanitize_title( $tax[ 'singularLabel' ] ), 'hierarchical' => $nested, 'ep_mask' => EP_PERMALINK  ),
					'update_count_callback'	=> '_update_generic_term_count',
					'show_admin_column' => true,
					'public'	=> true 
				)
			);
		}

	}
	
	/**
	 * Reads an image's metadata into its terms, description and alt text.
	 * $force: empty terms the file has nothing for (see setTaxonomyTerms).
	 */
	public function addAttachment( $id, $force = false ) {
		
		//extract metadata from file	
		$file = get_attached_file( $id );
		$md = new XmpReader();
		$md->loadFromFile( $file );
		
		// set the taxonomy terms
		$this->setTaxonomyTerms( $id, $md, $force );
		
		// set the description, when a template is configured
		$description = $this->generateDescription( $md );
		
		if ( null !== $description && $description !== get_post_field( 'post_content', $id ) ) {
			wp_update_post( [ 'ID' => $id, 'post_content' => $description ] );
		}
		
		// set ALT text of image
		
		if ( pp_api::getOption('core', 'metadata', 'alt_text_enable') ) {
		
			$alt = $this->generateAltText( $md );
			
			// Nothing to say; leave any alt text that was supplied alone.
			if ( '' === $alt ) {
				return;
			}
			
			// Adds the row if there is none. It returns false when the value is
			// unchanged, which the add_post_meta() fallback here used to treat
			// as missing and add a duplicate row.
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
	}
	
	/**
	 * photopress_attachment_file_replaced: reads the new file's metadata, as
	 * for an upload, when the client asked for it (the default).
	 */
	public function fileReplaced( $id, $replacements = [], $updated = [], $options = [] ) {
		
		if ( isset( $options['reprocess_metadata'] ) && ! $options['reprocess_metadata'] ) {
			return;
		}
		
		$this->addAttachment( $id );
	}
	
	/**
	 * Handler for updating the image meta when the file is replaced
	 */
	public function updateAttachment( $url ) {
		
		$id = attachment_url_to_postid( $url );
		$this->addAttachment( $id );
	}
	
	/**
	 * The description from the description template, or null when no
	 * template is set (the description is then not touched).
	 */
	public function generateDescription( $md ) {
		
		$template = trim( (string) pp_api::getOption( 'core', 'metadata', 'description_template' ) );
		
		if ( '' === $template ) {
			return null;
		}
		
		return self::fillAltTemplate( $template, function ( $tag ) use ( $md ) {
			return $md->getXmp( $tag );
		} );
	}
	
	/**
	 * photopress_attachment_description: the description of an image given
	 * a new file, from the template; null to leave it alone.
	 */
	public function descriptionForReplacedFile( $description, $id, $file ) {
		
		$md = new XmpReader();
		$md->loadFromFile( $file );
		
		return $this->generateDescription( $md ) ?? $description;
	}
	
	/**
	 * The images, for the metadata.reprocess job: all of them, or those in
	 * $args['ids'].
	 */
	public static function countImages( $args = [] ) {
		
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" . self::onlyIds( $args ) );
	}
	
	/**
	 * The next images after $after, by ID, for the metadata.reprocess job.
	 */
	public static function nextImages( $after, $limit, $args = [] ) {
		
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d" . self::onlyIds( $args ) . ' ORDER BY ID LIMIT %d',
			(int) $after,
			(int) $limit
		) ) );
	}
	
	/**
	 * " AND ID IN (...)" for a job limited to some images; integers only.
	 */
	private static function onlyIds( $args ) {
		
		$ids = array_filter( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) );
		
		return $ids ? ' AND ID IN (' . implode( ',', $ids ) . ')' : '';
	}
	
	/**
	 * One image of the metadata.reprocess job. $args['force']: empty terms
	 * the file has nothing for.
	 *
	 * @return true|\WP_Error
	 */
	public function reprocessImage( $id, $args = [] ) {
		
		$file = get_attached_file( $id );
		
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'photopress_no_file', sprintf( __( 'The file of image %d is missing.' ), $id ) );
		}
		
		$this->addAttachment( $id, ! empty( $args['force'] ) );
		
		return true;
	}
	
	/**
	 * Generates the Alt Text of an image based on meta-data template.
	 *
	 * $md	object	XmpReader Meta-data object
	 */
	public function generateAltText( $md ) {
		
		$template = (string) pp_api::getOption('core', 'metadata', 'alt_text_template');
		
		$alt = self::fillAltTemplate( $template, function ( $tag ) use ( $md ) {
			return $md->getXmp( $tag );
		} );
		
		// The template's tags are all missing from this image.
		if ( '' === $alt ) {
			
			foreach ( [ 'dc:description', 'dc:title' ] as $tag ) {
				
				$alt = self::altTextValue( $md->getXmp( $tag ) );
				
				if ( '' !== $alt ) {
					break;
				}
			}
		}

		return $alt;
	}
	
	/**
	 * Replaces each [tag] in the template with the image's value, then removes
	 * the separators that empty tags leave behind, so that
	 * "[photoshop:Headline]. [dc:title]." gives "Bob." rather than
	 * ". Bob.", and nothing at all when every tag is empty.
	 *
	 * @param string   $template The alt text template.
	 * @param callable $lookup   Returns the value of a tag.
	 */
	public static function fillAltTemplate( $template, $lookup ) {
		
		$found = false;
		
		$alt = preg_replace_callback( '/\[([^\]]+)\]/', function ( $m ) use ( $lookup, &$found ) {
			
			$value = self::altTextValue( $lookup( $m[1] ) );
			$found = $found || '' !== $value;
			
			return $value;
		}, $template );
		
		if ( ! $found ) {
			return '';
		}
		
		$sep = '[.,;:|\x{2013}\x{2014}-]';
		$alt = preg_replace( "/($sep)(\s*$sep)+/u", '$1', $alt );   // ". ." -> "."
		$alt = preg_replace( "/^[\s.,;:|\x{2013}\x{2014}-]+/u", '', $alt ); // leading ". "
		$alt = preg_replace( '/\s+/u', ' ', $alt );
		
		return trim( $alt );
	}
	
	/**
	 * A tag value as plain text: lists are joined, markup is removed.
	 */
	private static function altTextValue( $value ) {
		
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		
		return trim( wp_strip_all_tags( (string) $value ) );
	}
	
	public function embedLicense( $move, $file, $newfile, $type ) {

		$path = isset( $file['tmp_name'] ) ? $file['tmp_name'] : null;

		$wsr           = pp_api::getOption( 'core', 'metadata', 'web_statement_of_rights' );
		$licensor_name = pp_api::getOption( 'core', 'metadata', 'licensor_name' );
		$licensor_url  = pp_api::getOption( 'core', 'metadata', 'licensor_url' );

		$has_licensor = ( $licensor_name && $licensor_url );

		// Nothing configured, nothing to do.
		if ( ! $path || ! is_readable( $path ) || ( ! $wsr && ! $has_licensor ) ) {

			return $move;
		}

		$merge = static function ( $existing ) use ( $wsr, $licensor_name, $licensor_url ) {
			return self::mergeLicenseIntoXmp( $existing, $wsr, $licensor_name, $licensor_url );
		};

		// Written into the file's metadata block, without decoding the image,
		// so the pixels stay as they were exported.
		$written = XmpFile::update( $path, $merge );

		if ( true === $written ) {
			return $move;
		}

		/*
		 * Otherwise Imagick, which re-encodes the image: a format XmpFile
		 * does not write, or a file it could not write safely.
		 */
		if ( ! class_exists( 'Imagick' ) ) {

			photopress_util::debug( 'License not embedded: ' . $written->get_error_message() . ' Imagick is unavailable.' );

			return $move;
		}

		photopress_util::debug( 'Embedding the license with Imagick: ' . $written->get_error_message() );

		try {

			$im = new \Imagick( $path );

			$profiles = $im->getImageProfiles( '*', false );
			$existing = in_array( 'xmp', $profiles, true ) ? $im->getImageProfile( 'xmp' ) : '';

			$im->setImageProfile( 'xmp', self::mergeLicenseIntoXmp( $existing, $wsr, $licensor_name, $licensor_url ) );
			$im->writeImage( $path );
			$im->clear();

		} catch ( \Exception $e ) {

			// Never let a metadata problem block the upload itself.
			photopress_util::debug( 'Could not embed license meta-data: ' . $e->getMessage() );
		}

		/*
		 * Pass through whatever we were given. This filter's return value decides
		 * whether WordPress performs the move; returning null unconditionally (as
		 * this did) would discard another plugin's decision and move the file a
		 * second time. Normally $move is null and the two are identical.
		 */
		return $move;
	}

	/**
	 * Splice the license fields into an existing XMP packet.
	 *
	 * The packet replaces the file's packet rather than merging with it, so
	 * one that contains only the license would discard dc:title, dc:subject
	 * keywords, xmp:Rating, creator and any Lightroom settings the
	 * photographer had embedded.
	 *
	 * Verified against a real upload: EXIF/IPTC/ICC profiles untouched, every
	 * XMP element preserved with the same multiplicity, no text content lost,
	 * idempotent on re-upload, and safe on a malformed or absent packet.
	 */
	protected static function mergeLicenseIntoXmp( $existing, $web_statement, $licensor_name, $licensor_url ) {

	    $doc = new \DOMDocument();
	    $doc->preserveWhiteSpace = false;
	    $doc->formatOutput       = false;

	    /*
	     * The packet is wrapped in <?xpacket …?> processing instructions and may
	     * carry a UTF-8 BOM. Strip both before parsing; they are re-added on write.
	     *
	     * NOTE: this is a block comment on purpose. A `//` comment containing the
	     * sequence ?> terminates PHP mode right there -- the parser leaves code mode
	     * mid-function and reports a bogus 'unclosed {' at end of file.
	     */
	    $body = trim( (string) $existing );
	    $body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
	    $body = preg_replace( '/<\?xpacket[^>]*\?>/', '', $body );
	    $body = trim( $body );

	    $loaded = false;
	    if ( $body !== '' ) {
	        $loaded = @$doc->loadXML( $body, LIBXML_NONET );
	    }

	    if ( ! $loaded ) {
	        // No usable XMP: start a minimal well-formed packet.
	        $doc = new \DOMDocument( '1.0', 'UTF-8' );
	        $meta = $doc->createElementNS( 'adobe:ns:meta/', 'x:xmpmeta' );
	        $doc->appendChild( $meta );
	        $rdf = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:RDF' );
	        $meta->appendChild( $rdf );
	    }

	    $xp = new \DOMXPath( $doc );
	    $xp->registerNamespace( 'x', 'adobe:ns:meta/' );
	    $xp->registerNamespace( 'rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );

	    $rdf = $xp->query( '//rdf:RDF' )->item( 0 );
	    if ( ! $rdf ) {
	        $root = $doc->documentElement;
	        $rdf  = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:RDF' );
	        $root->appendChild( $rdf );
	    }

	    // Reuse an existing rdf:Description if there is one; XMP allows several,
	    // and creating another is legal but noisier.
	    $desc = $xp->query( './rdf:Description', $rdf )->item( 0 );
	    if ( ! $desc ) {
	        $desc = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:Description' );
	        $desc->setAttributeNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:about', '' );
	        $rdf->appendChild( $desc );
	    }

	    // Drop the file's copies of the properties being written, wherever they
	    // sit and in either form (an element, or an attribute of
	    // rdf:Description), so re-uploading does not stack duplicates. A
	    // property with no setting keeps the file's value.
	    $xp->registerNamespace( 'xmpRights', 'http://ns.adobe.com/xap/1.0/rights/' );
	    $xp->registerNamespace( 'plus', 'http://ns.useplus.org/ldf/xmp/1.0/' );
	    $owned = [];
	    if ( $web_statement ) {
	        $owned[] = 'xmpRights:WebStatement';
	    }
	    if ( $licensor_name && $licensor_url ) {
	        $owned[] = 'plus:Licensor';
	    }
	    foreach ( $owned as $name ) {
	        foreach ( iterator_to_array( $xp->query( "//$name | //@$name" ) ) as $n ) {
	            if ( $n instanceof \DOMAttr ) {
	                $n->ownerElement->removeAttributeNode( $n );
	            } else {
	                $n->parentNode->removeChild( $n );
	            }
	        }
	    }

	    if ( $web_statement ) {
	        $el = $doc->createElementNS( 'http://ns.adobe.com/xap/1.0/rights/', 'xmpRights:WebStatement' );
	        $el->appendChild( $doc->createTextNode( $web_statement ) );
	        $desc->appendChild( $el );
	    }

	    if ( $licensor_name && $licensor_url ) {
	        $lic = $doc->createElementNS( 'http://ns.useplus.org/ldf/xmp/1.0/', 'plus:Licensor' );
	        $seq = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:Seq' );
	        $li  = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:li' );
	        $li->setAttributeNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:parseType', 'Resource' );
	        $n = $doc->createElementNS( 'http://ns.useplus.org/ldf/xmp/1.0/', 'plus:LicensorName' );
	        $n->appendChild( $doc->createTextNode( $licensor_name ) );
	        $u = $doc->createElementNS( 'http://ns.useplus.org/ldf/xmp/1.0/', 'plus:LicensorURL' );
	        $u->appendChild( $doc->createTextNode( $licensor_url ) );
	        $li->appendChild( $n );
	        $li->appendChild( $u );
	        $seq->appendChild( $li );
	        $lic->appendChild( $seq );
	        $desc->appendChild( $lic );
	    }

	    $xml = $doc->saveXML( $doc->documentElement );

	    return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
	         . $xml
	         . "\n<?xpacket end=\"w\"?>";
	}

	/**
	 * Gives an image the terms its metadata calls for (TermRouter), in place
	 * of those it had, so a keyword removed from the file goes. Where the
	 * file has nothing at all for a taxonomy (no keywords, no location), its
	 * terms are kept, as the file may have had its metadata stripped, unless
	 * $force.
	 */
	public function setTaxonomyTerms( $id, $md, $force = false ) {

		$model   = TaxonomyModel::fromSettings();
		$present = $force ? [] : TermRouter::present( $md, $model );

		wp_defer_term_counting( true );

		foreach ( TermRouter::route( $md, $model ) as $tax_id => $terms ) {

			if ( ! taxonomy_exists( $tax_id ) || ( ! $force && empty( $present[ $tax_id ] ) ) ) {
				continue;
			}

			if ( $model->isNested( $tax_id ) ) {

				$ids = [];

				foreach ( $terms as $path ) {
					$ids = array_merge( $ids, self::termPath( $tax_id, $path ) );
				}

				$terms = array_values( array_unique( $ids ) );
			}

			wp_set_object_terms( $id, $terms, $tax_id, false );
		}

		wp_defer_term_counting( false );
	}

	/**
	 * The ids of a path of nested terms (Family, then Jane under it), each
	 * made if it does not exist. The image gets every level, so a search for
	 * any of them finds it.
	 */
	public static function termPath( $taxonomy, array $path ) {

		$parent = 0;
		$ids    = [];

		foreach ( $path as $name ) {

			$term = term_exists( $name, $taxonomy, $parent );

			if ( ! $term ) {

				$term = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent ] );

				if ( is_wp_error( $term ) ) {

					$existing = $term->get_error_data( 'term_exists' );

					if ( ! $existing ) {
						break;
					}

					$term = [ 'term_id' => $existing ];
				}
			}

			$parent = (int) $term['term_id'];
			$ids[]  = $parent;
		}

		return $ids;
	}

	public function makeImagesVisibleToTaxQueries( $query ) {
		
		if ( is_tax() ) {
		
			$query->set( 'post_status', 'all' );
		}
		
		return $query;

	}
	
	public function sortImageSrcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		//print_r($sources);
		ksort( $sources );
		
		return $sources;
	}
	
	public function setMaxSrcsetSize( $max_width, $size_array ) {
		
		return 3000;
	}
}

?>