<?php

namespace PhotoPress\modules\metadata;

defined( 'ABSPATH' ) || exit;

use photopress_module;
use pp_api;
use PhotoPress\jobs\ImageQuery;
use PhotoPress\modules\search\search;
use photopress_util;

/**
 * Registers the Core Image Taxonomies
 *
 *
 */
class metadata extends photopress_module {
	
	/** The taxonomies registered, [ id, slug, hierarchical ], for maybeFlushRewriteRules(). */
	private $rewrites = [];

	/** Images whose license JSON-LD this request has printed, so none is printed twice. */
	private $licensed = [];
	
	public $label = 'Metadata'; 

	/**
	 * Why the license could not be written into an image's file, on upload
	 * or by the license job: message and time. Deleted once it is written.
	 */
	const LICENSE_ERROR_META = '_photopress_license_error';

	/**
	 * What embedLicense() made of this request's upload, for the image made
	 * from it afterwards: the file it is moved to (file), and why the
	 * license is not in it (error), null when it is.
	 */
	private static $licenseResult = null;
	
	public function definePublicHooks() {
		
		add_filter( 'max_srcset_image_width', [$this, 'setMaxSrcsetSize'], 10,2);
		
		// add additional meta-data to images
		add_filter( 'wp_read_image_metadata', [$this, 'storeMoreMetaData'], 10, 5);
		
		// the description switch, for sites saved before it existed
		add_action( 'init', [ self::class, 'addDescriptionSwitch' ] );
		
		add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
		
		// the description of an image whose file was replaced (see MediaRest)
		add_filter( 'photopress_attachment_description', [ $this, 'descriptionForReplacedFile' ], 10, 3 );
		
		// A new image's terms, alt text and description from its metadata;
		// and an image's, when it is given a new file (MediaRest, or Enable
		// Media Replace through EnableMediaReplace), unless the client asked
		// for them to be left alone. Alt text and description follow their
		// own switches; the terms are set only while the image taxonomies
		// are on, as they are registered only then (setTaxonomyTerms).
		add_action( 'add_attachment', [ $this, 'addAttachment' ] );
		add_action( 'photopress_attachment_file_replaced', [ $this, 'fileReplaced' ], 10, 4 );
		
		// re-reading the metadata of every image, as a background job. Each
		// image's terms, alt text and description are written, and its pages
		// purged from the page cache: a few at a time, resting between
		// batches and while the server is busy, on a shared database.
		\PhotoPress\jobs\Jobs::register( 'metadata.reprocess', [
			'label'       => __( 'Re-read image metadata' ),
			'description' => __( 'Reads every image\'s embedded metadata again, as on upload: its image taxonomies, alt text and description. For after changing the taxonomies or templates.' ),
			'count'       => [ ImageQuery::class, 'count' ],
			'items'       => [ ImageQuery::class, 'next' ],
			'process'     => [ $this, 'reprocessImage' ],
			'finish'      => [ self::class, 'startQueuedReprocess' ],
			'batch_items' => 10,
			'pace'        => true,
		] );
		
		// the alt text, description or embedded license of every image again
		\PhotoPress\jobs\Jobs::register( 'metadata.alt_text', [
			'label'       => __( 'Reprocess alt text' ),
			'count'       => [ ImageQuery::class, 'count' ],
			'items'       => [ ImageQuery::class, 'next' ],
			'process'     => [ $this, 'reprocessAltText' ],
			'batch_items' => 10,
			'pace'        => true,
		] );
		
		\PhotoPress\jobs\Jobs::register( 'metadata.description', [
			'label'       => __( 'Reprocess descriptions' ),
			'count'       => [ ImageQuery::class, 'count' ],
			'items'       => [ ImageQuery::class, 'next' ],
			'process'     => [ $this, 'reprocessDescription' ],
			'batch_items' => 10,
			'pace'        => true,
		] );
		
		\PhotoPress\jobs\Jobs::register( 'metadata.license', [
			'label'         => __( 'Reprocess licensing metadata' ),
			'count'         => [ ImageQuery::class, 'count' ],
			'items'         => [ ImageQuery::class, 'next' ],
			'process'       => [ $this, 'embedLicenseInImage' ],
			'batch_seconds' => 10,
			'batch_items'   => 5,
			'pace'          => true,
		] );
		
		// embed license meta-data in all uploaded images even if it already exists.
		if ( self::licensingEnabled() ) {
			
			add_filter( 'pre_move_uploaded_file', [ $this, 'embedLicense' ], 1, 4 );
			// whether it was, on the image once it exists
			add_action( 'add_attachment', [ self::class, 'licenseOnUpload' ] );
			add_action( 'photopress_attachment_file_replaced', [ self::class, 'licenseOnReplace' ] );
			// the license JSON-LD of the images in image blocks and galleries
			add_filter( 'render_block', [ $this, 'addLicensingToContent' ], 11, 2 );
		}
		
		// an image whose license could not be written, in the media library
		add_filter( 'attachment_fields_to_edit', [ self::class, 'licenseErrorField' ], 10, 2 );
		
		// the license JSON-LD of the images an image page, search or archive shows
		add_action( 'wp_footer', [ $this, 'printLicensingSchemaForPage' ] );
		
		// stop wordpress from stripping image meta from resized images.
		add_filter ('image_strip_meta', function() {
			
			return pp_api::getOption( 'core', 'metadata', 'strip_metadata_from_resized_image');
		});

		
		// registers display widgets
		add_action( 'widgets_init', [ $this, 'registerWidgets' ] );

		// Queries flagged photopress_public_images list only public images.
		add_filter( 'posts_where', [ self::class, 'onlyPublicImages' ], 10, 2 );
		
		// The block counterpart of the taxonomy widget, for block themes, which
		// have no widget areas.
		photopress_util::registerBlockManifest( __DIR__ . '/blocks', 'metadata' );
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
			
			// Images on the archive pages of image taxonomies.
			add_action( 'pre_get_posts', [ $this, 'makeImagesVisibleToTaxQueries' ] );
			
		} else {

			// Switched off: the rules built again without their URLs, once.
			$this->maybeFlushRewriteRules();
		}
	}	
	
	/**
	 * What a change to the parent keywords or the prefix separators moves:
	 * the taxonomies to fill again (the changed parent keywords and
	 * Keywords), and the images with terms in a changed parent keyword's
	 * taxonomy or Keywords terms starting with one of its names ("person:
	 * Jane", or "People" from an unmatched People|Jane), before and after the
	 * change. All parent keywords when the separators changed.
	 *
	 * @return array{ids: int[], taxonomies: string[]}
	 */
	public static function changeScope( $old, $new ): array {

		global $wpdb;

		$model = static function ( $value ) {
			$value = (array) $value;
			return TaxonomyModel::build( (array) ( $value['custom_taxonomies'] ?? [] ), (string) ( $value['custom_taxonomies_tag_delimiter'] ?? '' ) );
		};

		$before = $model( $old );
		$after  = $model( $new );
		$all    = $before->separators !== $after->separators;
		$byId   = static fn( TaxonomyModel $m ) => array_column( $m->parents, null, 'id' );
		$then   = $byId( $before );
		$now    = $byId( $after );

		$changed = [];
		$names   = [];

		foreach ( array_unique( array_merge( array_keys( $then ), array_keys( $now ) ) ) as $id ) {

			if ( ! $all && ( $then[ $id ] ?? null ) === ( $now[ $id ] ?? null ) ) {
				continue;
			}

			$changed[] = $id;

			foreach ( [ $then[ $id ] ?? null, $now[ $id ] ?? null ] as $parent ) {
				foreach ( $parent['names'] ?? [] as $name ) {
					$names[ $name ] = true;
				}
			}
		}

		if ( ! $changed ) {
			return [ 'ids' => [], 'taxonomies' => [] ];
		}

		$keywords   = $after->standard['keywords']['id'] ?? null;
		$taxonomies = array_values( array_intersect( $changed, array_keys( $now ) ) );

		if ( $keywords ) {
			$taxonomies[] = $keywords;
		}

		$where = [ 'tt.taxonomy IN (' . implode( ',', array_fill( 0, count( $changed ), '%s' ) ) . ')' ];
		$args  = $changed;
		$from  = $after->keywordsId ?? $before->keywordsId;

		if ( $from && $names ) {
			$where[] = '( tt.taxonomy = %s AND ( ' . implode( ' OR ', array_fill( 0, count( $names ), 't.name LIKE %s' ) ) . ' ) )';
			$args[]  = $from;
			foreach ( array_keys( $names ) as $name ) {
				$args[] = $wpdb->esc_like( $name ) . '%';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE " . implode( ' OR ', $where ) . ' ORDER BY tr.object_id', $args ) );

		return [ 'ids' => array_map( 'intval', (array) $ids ), 'taxonomies' => $taxonomies ];
	}

	/**
	 * For the Image Taxonomies settings, before saving a change: the images
	 * it would move and the taxonomies to fill again (changeScope), against
	 * the saved settings.
	 */
	public static function restChangeScope( \WP_REST_Request $request ) {

		$saved    = (array) get_option( photopress_util::getModuleOptionKey( 'core', 'metadata' ), [] );
		$proposed = (array) $request['settings'] + $saved;

		return self::changeScope( $saved, $proposed );
	}

	/**
	 * Re-reads image taxonomies from the files, in the background: of the
	 * images in ids, or of every image (all). taxonomies: only these;
	 * alt text and descriptions stay. skip (the default): images whose files
	 * have nothing for a taxonomy keep their terms there. While a re-read
	 * runs, this one waits for it (startQueuedReprocess).
	 */
	public static function restReprocess( \WP_REST_Request $request ) {

		$ids = array_values( array_filter( array_map( 'intval', (array) $request['ids'] ) ) );

		if ( ! $ids && ! $request['all'] ) {
			return [ 'job' => null, 'queued' => false ];
		}

		return self::reprocess( [
			'ids'        => $ids,
			'taxonomies' => array_values( array_map( 'sanitize_key', (array) $request['taxonomies'] ) ),
			'force'      => ! $request['skip'],
		] );
	}

	/** Starts a re-read, or queues it while another runs. */
	public static function reprocess( array $args ) {

		$job = \PhotoPress\jobs\Jobs::start( 'metadata.reprocess', $args );

		if ( is_wp_error( $job ) && 'photopress_job_running' === $job->get_error_code() ) {
			$queue   = (array) get_option( 'photopress_reprocess_queue', [] );
			$queue[] = $args;
			update_option( 'photopress_reprocess_queue', $queue, false );
			return [ 'job' => null, 'queued' => true ];
		}

		return is_wp_error( $job ) ? $job : [ 'job' => $job, 'queued' => false ];
	}

	/** When a re-read finishes, the next one queued. */
	public static function startQueuedReprocess() {

		$queue = (array) get_option( 'photopress_reprocess_queue', [] );
		$next  = array_shift( $queue );

		if ( $queue ) {
			update_option( 'photopress_reprocess_queue', $queue, false );
		} else {
			delete_option( 'photopress_reprocess_queue' );
		}

		if ( $next ) {
			self::reprocess( (array) $next );
		}
	}

	/**
	 * Sites saved before the description had its own switch: on where a
	 * description template is set, as the template alone turned it on.
	 */
	public static function addDescriptionSwitch() {

		$key   = photopress_util::getModuleOptionKey( 'core', 'metadata' );
		$saved = get_option( $key );

		if ( is_array( $saved ) && ! array_key_exists( 'description_enable', $saved ) ) {
			$saved['description_enable'] = '' !== trim( (string) ( $saved['description_template'] ?? '' ) );
			update_option( $key, $saved );
		}
	}

	/**
	 * Whether licensing is on (embedding on upload, the reprocess job and the
	 * JSON-LD): its switch on and all three of its settings filled in, so no
	 * file or page gets half of the licensing information.
	 */
	public static function licensingEnabled() {

		if ( ! pp_api::getOption( 'core', 'metadata', 'embed_licensor_enable' ) ) {
			return false;
		}

		foreach ( [ 'licensor_name', 'licensor_url', 'web_statement_of_rights' ] as $key ) {
			if ( '' === trim( (string) pp_api::getOption( 'core', 'metadata', $key ) ) ) {
				return false;
			}
		}

		return true;
	}

	public function registerRestRoutes() {

		register_rest_route( 'photopress/v1', '/image-taxonomies', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'taxonomyStatus' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( 'photopress/v1', '/image-taxonomies/scope', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'restChangeScope' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'args'                => [ 'settings' => [ 'type' => 'object', 'required' => true ] ],
		] );

		register_rest_route( 'photopress/v1', '/image-taxonomies/reprocess', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'restReprocess' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'args'                => [
				'ids'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'default' => [] ],
				'all'        => [ 'type' => 'boolean', 'default' => false ],
				'taxonomies' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'default' => [] ],
				'skip'       => [ 'type' => 'boolean', 'default' => true ],
			],
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
			'prefixes' => $model->keywordsId ? self::unclaimedPrefixes( $model ) : [],
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
		$args = array_merge( [ $model->keywordsId ], array_map( static function ( $s ) use ( $wpdb ) {
			return '%' . $wpdb->esc_like( $s ) . '%';
		}, $separators ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.name, tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND ( $like )", $args ) );

		$taken = [];

		foreach ( $model->parents as $parent ) {
			foreach ( $parent['names'] as $name ) {
				$taken[ $name ] = true;
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

	/**
	 * Rebuilds the rewrite rules when the taxonomies' URLs differ from those
	 * they were last built for: a taxonomy added, renamed or turned off in
	 * the settings, or registered differently by an update of PhotoPress.
	 */
	private function maybeFlushRewriteRules() {

		$built = md5( wp_json_encode( $this->rewrites ) );

		if ( get_option( 'photopress_taxonomy_rewrites' ) === $built ) {
			return;
		}

		// Once every plugin has registered its post types and taxonomies, so
		// the rules rebuilt include theirs.
		add_action( 'wp_loaded', static function () use ( $built ) {
			update_option( 'photopress_taxonomy_rewrites', $built );
			delete_option( 'photopress_flush_rewrite_rules' );
			flush_rewrite_rules( false );
		} );
	}
	
	public function defineAdminHooks() {
		
	
	}
	
	/**
	 * render_block, while licensing is on: the license JSON-LD of the images
	 * of an image block or a legacy PhotoPress Gallery.
	 */
	public function addLicensingToContent( $content, $block ) {

		if ( ! in_array( $block['blockName'] ?? '', [ 'photopress/gallery', 'core/image' ], true ) ) {
			return $content;
		}

		$ids = self::imageIdsIn( $content );

		if ( ! $ids ) {
			return $content;
		}

		self::primeImages( $ids );

		return $content . $this->renderLicensingSchema( array_filter( $ids, 'get_post' ) );
	}

	/**
	 * The data-* attributes the full-screen slideshow reads, added to the
	 * images of a gallery that opens it (modules/slideshow): its file, shape,
	 * caption, title, description and page. Nothing else reads them.
	 *
	 * @param string $content A gallery's HTML.
	 */
	public static function addAttributesToImagesInContent( $content ) {

		$ids = self::imageIdsIn( $content );

		if ( ! $ids ) {
			return $content;
		}

		self::primeImages( $ids );

		$attributes_by_id = [];
		$parents          = [];

		foreach ( $ids as $id ) {

			$attachment = get_post( $id );

			if ( $attachment ) {
				$parents[] = (int) $attachment->post_parent;
			}
		}

		// The posts the images are attached to, for their pages' addresses.
		self::primeImages( array_filter( $parents ), false );

		foreach ( $ids as $id ) {

			$attachment = get_post( $id );

			if ( $attachment ) {
				$attributes_by_id[ $id ] = self::addAttributesToImages( [], $attachment );
			}
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

			// Those the post already has, kept but made safe: the slideshow
			// shows them as HTML, and post content can carry any data-*.
			foreach ( self::SLIDESHOW_ATTRIBUTES as $name => $kind ) {

				$value = $p->get_attribute( $name );

				if ( is_string( $value ) ) {
					$p->set_attribute( $name, self::safeAttribute( $value, $kind ) );
				}
			}

			$id = self::attachmentIdOf( $p );

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

		return $p->get_updated_html();
	}

	/** The data-* attributes the slideshow reads, and what each holds. */
	const SLIDESHOW_ATTRIBUTES = [
		'data-caption'           => 'html',
		'data-image-title'       => 'html',
		'data-image-description' => 'html',
		'data-orig-file'         => 'url',
		'data-attachment-url'    => 'url',
		'data-aspectratio'       => 'number',
	];

	/**
	 * An attribute's value as the slideshow may use it: HTML that post
	 * content may hold, a web address, or a number.
	 */
	public static function safeAttribute( $value, $kind ) {

		if ( 'url' === $kind ) {
			return (string) esc_url_raw( $value, [ 'http', 'https' ] );
		}

		if ( 'number' === $kind ) {
			return is_numeric( $value ) ? (string) (float) $value : '';
		}

		return wp_kses_post( $value );
	}

	/**
	 * The attachment IDs of the images in some HTML, from their wp-image-N
	 * classes or data-id attributes.
	 *
	 * @return int[]
	 */
	public static function imageIdsIn( $content ) {

		$p   = new \WP_HTML_Tag_Processor( (string) $content );
		$ids = [];

		while ( $p->next_tag( 'img' ) ) {

			$id = self::attachmentIdOf( $p );

			if ( $id ) {
				$ids[ $id ] = true;
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Posts, with their metadata, loaded into the cache in one query, as
	 * many as are not there already: the images of a gallery, rather than
	 * each image's as its block is drawn.
	 *
	 * @param int[] $ids
	 * @param bool  $meta Their metadata too.
	 */
	public static function primeImages( array $ids, $meta = true ) {

		$missing = self::uncached( $ids, $meta );

		if ( ! $missing ) {
			return;
		}

		new \WP_Query( [
			'post__in'               => $missing,
			'post_type'              => 'any',
			'post_status'            => 'any',
			'posts_per_page'         => count( $missing ),
			'orderby'                => 'post__in',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => $meta,
			'update_post_term_cache' => false,
		] );
	}
	
	/**
	 * Those of the posts not in the cache, or, with $meta, whose metadata is not.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function uncached( array $ids, $meta = true ) {

		return array_values( array_filter( array_unique( array_map( 'intval', $ids ) ), static function ( $id ) use ( $meta ) {
			return $id > 0 && ( false === wp_cache_get( $id, 'posts' ) || ( $meta && false === wp_cache_get( $id, 'post_meta' ) ) );
		} ) );
	}

	/**
	 * The attachment id of the img the processor is on, from its wp-image-N
	 * class or data-id attribute.
	 */
	private static function attachmentIdOf( \WP_HTML_Tag_Processor $p ) {

		if ( preg_match( '/\bwp-image-(\d+)\b/', (string) $p->get_attribute( 'class' ), $m ) ) {
			return absint( $m[1] );
		}

		return absint( $p->get_attribute( 'data-id' ) );
	}

	public static function addAttributesToImages( $attr, $attachment = null ) {
		
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
	
	/**
	 * wp_footer: the license JSON-LD of the image an image page shows, or of
	 * the images in search results or an archive, from the main query, so
	 * any theme gets it. Images in post content get theirs with their block
	 * (addLicensingToContent).
	 */
	public function printLicensingSchemaForPage() {
		
		echo $this->renderLicensingSchema( $this->licensableImagesOfPage() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from json_encode
	}
	
	/** The images printLicensingSchemaForPage() gives JSON-LD for. @return int[] */
	public function licensableImagesOfPage() {
		
		global $wp_query;
		
		if ( ! self::licensingEnabled() || ! ( is_attachment() || is_search() || is_archive() ) ) {
			return [];
		}
		
		$ids = [];
		
		foreach ( (array) ( $wp_query->posts ?? [] ) as $post ) {
			if ( is_object( $post ) && 'attachment' === ( $post->post_type ?? '' ) && wp_attachment_is_image( $post ) ) {
				$ids[] = $post->ID;
			}
		}
		
		return $ids;
	}
	
	/**
	 * The license JSON-LD of the images, less those the page has it for
	 * already: an image block's comes first, and the footer's
	 * (printLicensingSchemaForPage) adds only what is missing, as on an
	 * archive whose images a Taxonomy Gallery shows.
	 *
	 * @param int[] $attachment_ids
	 */
	public function renderLicensingSchema( $attachment_ids ) {
		
		$attachment_ids = array_values( array_diff( array_map( 'intval', (array) $attachment_ids ), $this->licensed ) );
		
		if ( [] === $attachment_ids ) {
			return '';
		}
		
		$this->licensed = array_merge( $this->licensed, $attachment_ids );
		
		$licensing_schema = $this->generateLicensingSchema( $attachment_ids );
		
		$content = "\n";
		$content .= sprintf('<script type="application/ld+json">%s</script>', wp_json_encode( $licensing_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) );
		
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

		// The widgets block editor offers the Image Metadata block in their
		// place; placed ones still show, and transform into it.
		add_filter( 'widget_types_to_hide_from_legacy_widget_block', static fn( $types ) => array_merge( (array) $types, [ 'xmpdisplaywidget', 'exifdisplaywidget' ] ) );
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
			
			'alt_text_from_file'	=> [
				
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Alt text written in the file',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Use the alt text written in the file, when it has one, in place of the template.',
					'label_for'								=> 'Use the alt text written in the file.',
					'error_message'							=> ''
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
			
			'description_enable'	=> [
				
				'default_value'							=> false,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Description from metadata',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Sets each image\'s description from the description template.',
					'label_for'								=> 'Description from metadata.',
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
	 * Whether the site has terms in photos_people, the People parent keyword
	 * of the defaults before 1.10. Worked out once and remembered.
	 */
	public static function hasOldPeople() {

		$known = get_option( 'photopress_old_people' );

		if ( ! $known ) {

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$known = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", 'photos_people' ) ) ? 'yes' : 'no';

			update_option( 'photopress_old_people', $known );
		}

		return 'yes' === $known;
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
				'id'			=> 'photos_keywords',
				'pluralLabel' 	=> 'keywords',
				'singularLabel'	=> 'keyword',
				'tag'			=> 'dc:subject',
				'parseTagValue'	=> false
			],
			
		];	
		
		// Before 1.10 the defaults had a People parent keyword. A site that
		// never saved its taxonomy settings, so runs on these, keeps it while
		// it has People terms; new sites start with the standard ones only.
		if ( self::hasOldPeople() ) {
			array_splice( $taxonomies, -1, 0, [ [
				'id'			=> 'photos_people',
				'pluralLabel' 	=> 'people',
				'singularLabel'	=> 'person',
				'tag'			=> 'dc:subject',
				'parseTagValue'	=> true
			] ] );
		}
		
		return apply_filters( 'photopress/taxonomies/defaultDefinitions', $taxonomies );

	}
	
	public function registerTaxonomies() {
	
		$taxonomies = pp_api::getOption('core', 'metadata', 'custom_taxonomies');
		$model      = TaxonomyModel::build( (array) $taxonomies, (string) pp_api::getOption( 'core', 'metadata', 'custom_taxonomies_tag_delimiter' ) );
		// The Pages & Search tab's Image taxonomy pages switch.
		$archives   = search::enabled( 'taxonomy_pages' );
		foreach ($taxonomies as $tax ) {
			
			// Turned off in the settings.
			if ( ! empty( $tax['disabled'] ) ) {
				continue;
			}
			
			$id = (string) $tax[ 'id' ];

			// One of WordPress's own (category, post_tag…): left as it is,
			// as registering would replace it.
			if ( '' === $id || ( taxonomy_exists( $id ) && ! empty( get_taxonomy( $id )->_builtin ) ) ) {
				continue;
			}

			$upper_plural = ucwords( $tax[ 'pluralLabel' ] );
			$upper_singular = ucwords( $tax[ 'singularLabel' ] );
			
			// Keywords' and a parent keyword's terms sit under one another,
			// with URLs to match: /person/family/jane. /person/jane still
			// finds Jane, as WordPress reads the last part of a term path.
			$nested = $model->isNested( $id );
			$slug   = sanitize_title( $tax[ 'singularLabel' ] );
			// Not public: for the admin only, with no archive or term pages,
			// menus or tag clouds; its terms screen and Media Library column stay.
			$public = empty( $tax['private'] );
			// Archive pages: a public taxonomy's, unless they are off for all.
			// Its terms are still shown, unlinked (ImageTaxonomies).
			$pages  = $public && $archives;
			
			if ( $pages ) {
				$this->rewrites[] = [ $id, $slug, $nested ];
			}
			
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
					
					'query_var' => $pages ? $id : false, 
					// In REST for the editor and core blocks, but only for users
					// who can edit posts; see ImageTaxonomyRest.
					'show_in_rest'          => true,
					'rest_controller_class' => TermsController::class,
					// As the settings screen shows it: "Acme job" is /acme-job/.
					'rewrite' => $pages ? array('slug' => $slug, 'hierarchical' => $nested, 'ep_mask' => EP_PERMALINK  ) : false,
					'update_count_callback'	=> '_update_generic_term_count',
					'show_admin_column' => true,
					'public'	=> $public,
					'publicly_queryable' => $pages,
					'show_ui' => true,
					'show_in_menu' => true,
					'show_in_nav_menus' => $pages,
					'show_tagcloud' => $pages,
				)
			);
		}

	}
	
	/**
	 * Reads an image's metadata into its terms, description and alt text.
	 * $force: empty terms the file has nothing for (see setTaxonomyTerms).
	 */
	public function addAttachment( $id, $force = false, $only = null ) {
		
		//extract metadata from file	
		$file = get_attached_file( $id );
		
		// Images only (not PDFs, video or audio), and not an attachment made
		// without a file, as some plugins make them.
		if ( ! $file || ! is_file( $file ) || ! wp_attachment_is_image( $id ) ) {
			return;
		}
		
		$md = new XmpReader();
		$md->loadFromFile( $file );
		
		// set the taxonomy terms
		$this->setTaxonomyTerms( $id, $md, $force, $only );
		
		// Only some taxonomies: the alt text and description stay.
		if ( $only ) {
			return;
		}
		
		$this->applyDescription( $id, $md );
		$this->applyAltText( $id, $md );
	}
	
	/**
	 * Sets an image's description from the description template, when the
	 * description is on. $keep: a template whose fields the file has none
	 * of leaves it alone, where otherwise it is emptied.
	 */
	public function applyDescription( $id, $md, $keep = false ) {
		
		$description = $this->generateDescription( $md );
		
		if ( $keep && '' === $description ) {
			return;
		}
		
		if ( null !== $description && $description !== get_post_field( 'post_content', $id ) ) {
			wp_update_post( [ 'ID' => $id, 'post_content' => $description ] );
		}
	}
	
	/**
	 * Sets an image's alt text from the alt text template, when alt text is
	 * on. A template whose tags the file has none of leaves it alone, unless
	 * $empty, which removes it.
	 */
	public function applyAltText( $id, $md, $empty = false ) {
		
		if ( ! pp_api::getOption('core', 'metadata', 'alt_text_enable') ) {
			return;
		}
		
		$alt = $this->generateAltText( $md );
		
		// Nothing to say; leave any alt text that was supplied alone.
		if ( '' === $alt ) {
			if ( $empty ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			}
			return;
		}
		
		// Adds the row if there is none. It returns false when the value is
		// unchanged, which the add_post_meta() fallback here used to treat
		// as missing and add a duplicate row.
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	
	/**
	 * One image of the metadata.alt_text job. $args['force']: remove the alt
	 * text of an image whose file has none of the template's fields.
	 *
	 * @return true|\WP_Error
	 */
	public function reprocessAltText( $id, $args = [] ) {
		
		$md = self::readerFor( $id );
		
		if ( is_wp_error( $md ) ) {
			return $md;
		}
		
		$this->applyAltText( $id, $md, ! empty( $args['force'] ) );
		
		return true;
	}
	
	/**
	 * One image of the metadata.description job. Without $args['force'], an
	 * image whose file has none of the template's fields keeps its
	 * description.
	 *
	 * @return true|\WP_Error
	 */
	public function reprocessDescription( $id, $args = [] ) {
		
		$md = self::readerFor( $id );
		
		if ( is_wp_error( $md ) ) {
			return $md;
		}
		
		$this->applyDescription( $id, $md, empty( $args['force'] ) );
		
		return true;
	}
	
	/** The metadata of an image's file, or a WP_Error when it is missing. */
	private static function readerFor( $id ) {
		
		$file = get_attached_file( $id );
		
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'photopress_no_file', sprintf( __( 'The file of image %d is missing.' ), $id ) );
		}
		
		$md = new XmpReader();
		$md->loadFromFile( $file );
		
		return $md;
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
	 * The description from the description template, or null when the
	 * description is off or no template is set (it is then not touched).
	 */
	public function generateDescription( $md ) {
		
		$template = trim( (string) pp_api::getOption( 'core', 'metadata', 'description_template' ) );
		
		if ( ! pp_api::getOption( 'core', 'metadata', 'description_enable' ) || '' === $template ) {
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
	 * One image of the metadata.reprocess job. $args['force']: empty terms
	 * the file has nothing for. $args['taxonomies']: fill only these, and
	 * leave its alt text and description as they are.
	 *
	 * @return true|\WP_Error
	 */
	public function reprocessImage( $id, $args = [] ) {
		
		$file = get_attached_file( $id );
		
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'photopress_no_file', sprintf( __( 'The file of image %d is missing.' ), $id ) );
		}
		
		$this->addAttachment( $id, ! empty( $args['force'] ), ! empty( $args['taxonomies'] ) ? (array) $args['taxonomies'] : null );
		
		return true;
	}
	
	/**
	 * Generates the Alt Text of an image based on meta-data template.
	 *
	 * $md	object	XmpReader Meta-data object
	 */
	public function generateAltText( $md ) {
		
		// The alt text written in the file (IPTC Alt Text (Accessibility), as
		// Lightroom Classic and Photoshop write it), in place of the template.
		if ( pp_api::getOption( 'core', 'metadata', 'alt_text_from_file' ) ) {
			
			$alt = self::altTextValue( $md->getXmp( 'Iptc4xmpCore:AltTextAccessibility' ) );
			
			if ( '' !== $alt ) {
				return $alt;
			}
		}
		
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
	
	/**
	 * What writes the license settings into an XMP packet, or null when
	 * licensing is off or not all of them are set.
	 */
	private static function licenseMerge() {

		if ( ! self::licensingEnabled() ) {
			return null;
		}

		$wsr           = pp_api::getOption( 'core', 'metadata', 'web_statement_of_rights' );
		$licensor_name = pp_api::getOption( 'core', 'metadata', 'licensor_name' );
		$licensor_url  = pp_api::getOption( 'core', 'metadata', 'licensor_url' );

		return static function ( $existing ) use ( $wsr, $licensor_name, $licensor_url ) {
			return self::mergeLicenseIntoXmp( $existing, $wsr, $licensor_name, $licensor_url );
		};
	}

	/**
	 * One image of the metadata.license job: writes the license into the
	 * XMP of each of its files (the original, the scaled image and every
	 * size) without re-encoding them, then updates its metadata so plugins
	 * that copy files elsewhere, such as WP Offload Media, upload them again.
	 * A file that cannot be written that way is left as it is.
	 *
	 * @return true|\WP_Error
	 */
	public function embedLicenseInImage( $id ) {

		$merge = self::licenseMerge();

		if ( ! $merge ) {
			return new \WP_Error( 'photopress_no_license', __( 'Licensing is off, or not all of its settings are filled in.' ) );
		}

		$files = self::imageFiles( $id );

		if ( ! $files ) {
			return new \WP_Error( 'photopress_no_file', sprintf( __( 'The file of image %d is missing.' ), $id ) );
		}

		$failed  = [];
		$changed = false;

		foreach ( $files as $path ) {

			$written = XmpFile::update( $path, $merge, $file_changed );
			$changed = $changed || $file_changed;

			if ( true !== $written ) {
				$failed[] = wp_basename( $path ) . ': ' . $written->get_error_message();
			}
		}

		// Only when a file changed: run again, nothing is copied anew.
		if ( $changed ) {
			wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );

			/** This action is documented in modules/images/images.php */
			do_action( 'photopress_image_files_rewritten', $id );
		}

		self::recordLicense( $id, $failed ? implode( ' ', $failed ) : null );

		return $failed ? new \WP_Error( 'photopress_license_not_written', implode( ' ', $failed ) ) : true;
	}

	/**
	 * The files of an image on this server: its file, the original it was
	 * scaled from, and each size.
	 *
	 * @return string[]
	 */
	public static function imageFiles( $id ) {

		$file = get_attached_file( $id );

		if ( ! $file ) {
			return [];
		}

		$dir   = dirname( $file );
		$meta  = wp_get_attachment_metadata( $id );
		$files = [ $file ];

		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . '/' . $meta['original_image'];
		}

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . '/' . $size['file'];
			}
		}

		return array_values( array_filter( array_unique( $files ), 'file_exists' ) );
	}

	/** Whether a MIME type is a raster image: image/*, but not SVG. */
	public static function isRasterImage( string $type ): bool {

		return 0 === strpos( $type, 'image/' ) && 'image/svg+xml' !== $type;
	}

	public function embedLicense( $move, $file, $newfile, $type ) {

		$path  = isset( $file['tmp_name'] ) ? $file['tmp_name'] : null;
		$merge = self::licenseMerge();

		self::$licenseResult = null;

		// Nothing configured, nothing to do. Raster images only: the Imagick
		// fallback below would re-encode anything else it can read, turning a
		// PDF or an SVG into pixels.
		if ( ! $path || ! is_readable( $path ) || ! $merge || ! self::isRasterImage( (string) $type ) ) {

			return $move;
		}

		$wsr           = pp_api::getOption( 'core', 'metadata', 'web_statement_of_rights' );
		$licensor_name = pp_api::getOption( 'core', 'metadata', 'licensor_name' );
		$licensor_url  = pp_api::getOption( 'core', 'metadata', 'licensor_url' );

		// Written into the file's metadata block, without decoding the image,
		// so the pixels stay as they were exported.
		$written = XmpFile::update( $path, $merge );

		if ( true === $written ) {
			self::$licenseResult = [ 'file' => $newfile, 'error' => null ];
			return $move;
		}

		// A file XmpFile could not write (a full disk, a read-only folder) is
		// left as it is: Imagick would fail the same way.
		if ( 'photopress_xmp_unwritable' === $written->get_error_code() ) {

			photopress_util::debug( 'License not embedded: ' . $written->get_error_message() );
			self::$licenseResult = [ 'file' => $newfile, 'error' => $written->get_error_message() ];

			return $move;
		}

		/*
		 * Otherwise Imagick, which re-encodes the image: a format XmpFile
		 * does not write, or a file it could not write safely.
		 */
		if ( ! class_exists( 'Imagick' ) ) {

			photopress_util::debug( 'License not embedded: ' . $written->get_error_message() . ' Imagick is unavailable.' );
			self::$licenseResult = [ 'file' => $newfile, 'error' => $written->get_error_message() . ' ' . __( 'Imagick, which could write it otherwise, is not installed.' ) ];

			return $move;
		}

		photopress_util::debug( 'Embedding the license with Imagick: ' . $written->get_error_message() );

		try {

			$im = new \Imagick( $path );

			$profiles = $im->getImageProfiles( '*', false );
			$existing = in_array( 'xmp', $profiles, true ) ? $im->getImageProfile( 'xmp' ) : '';

			$im->setImageProfile( 'xmp', self::mergeLicenseIntoXmp( $existing, $wsr, $licensor_name, $licensor_url ) );
			$replaced = self::replaceFile( $path, static function ( $tmp ) use ( $im ) {
				$im->writeImage( $tmp );
			} );
			$im->clear();

			if ( true !== $replaced ) {
				photopress_util::debug( 'Could not embed license meta-data: ' . $replaced->get_error_message() );
			}

			self::$licenseResult = [ 'file' => $newfile, 'error' => true === $replaced ? null : $replaced->get_error_message() ];

		} catch ( \Exception $e ) {

			// Never let a metadata problem block the upload itself.
			photopress_util::debug( 'Could not embed license meta-data: ' . $e->getMessage() );
			self::$licenseResult = [ 'file' => $newfile, 'error' => $e->getMessage() ];
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
	 * add_attachment: what embedLicense() made of the upload, recorded on the
	 * image made from it (recordLicense()).
	 */
	public static function licenseOnUpload( $id ) {

		if ( self::$licenseResult && get_attached_file( $id, true ) === self::$licenseResult['file'] ) {
			self::recordLicense( $id, self::$licenseResult['error'] );
			self::$licenseResult = null;
		}
	}

	/**
	 * photopress_attachment_file_replaced: what embedLicense() made of the
	 * new file, the one upload of a replace, recorded on the image.
	 */
	public static function licenseOnReplace( $id ) {

		if ( self::$licenseResult ) {
			self::recordLicense( $id, self::$licenseResult['error'] );
			self::$licenseResult = null;
		}
	}

	/**
	 * Records why an image's license could not be written into its file
	 * (LICENSE_ERROR_META), or with a null $error that it was, deleting
	 * what was recorded before.
	 */
	public static function recordLicense( $id, $error ) {

		if ( null === $error ) {
			delete_post_meta( $id, self::LICENSE_ERROR_META );
			return;
		}

		update_post_meta( $id, self::LICENSE_ERROR_META, [ 'message' => (string) $error, 'time' => time() ] );
	}

	/**
	 * attachment_fields_to_edit: on an image whose license could not be
	 * written into its file, a notice saying so and why, in the media
	 * library's attachment details and on its edit screen.
	 */
	public static function licenseErrorField( $fields, $post ) {

		$error = get_post_meta( $post->ID, self::LICENSE_ERROR_META, true );

		if ( ! is_array( $error ) || empty( $error['message'] ) ) {
			return $fields;
		}

		$fields['photopress_license_error'] = [
			'label' => __( 'License' ),
			'input' => 'html',
			'html'  => '<p class="description" style="color:#b32d2e">' . esc_html( sprintf(
				/* translators: 1: date, 2: why */
				__( 'The license could not be written into this image\'s file (%1$s): %2$s Run Reprocess licensing metadata to try again.' ),
				wp_date( get_option( 'date_format' ), (int) ( $error['time'] ?? 0 ) ),
				$error['message']
			) ) . '</p>',
		];

		return $fields;
	}

	/**
	 * Replaces the file with the image $write writes to the path it is
	 * given, a temporary file in the same folder. Moved into place only once
	 * it is a nonempty file getimagesize() reads; on any failure the file is
	 * left as it was and the temporary file removed.
	 *
	 * @param string   $path  The file.
	 * @param callable $write function( string $tmp ), which may throw.
	 * @return true|\WP_Error
	 */
	public static function replaceFile( $path, callable $write ) {

		$dir = dirname( $path );
		$tmp = @tempnam( $dir, 'pp-license-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// tempnam() falls back to the system's temporary folder, from which a
		// rename would not be atomic.
		if ( $tmp && realpath( dirname( $tmp ) ) !== realpath( $dir ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$tmp = false;
		}

		if ( ! $tmp ) {
			return new \WP_Error( 'photopress_unwritable', 'A temporary file could not be written next to the file.' );
		}

		try {
			call_user_func( $write, $tmp );
		} catch ( \Throwable $e ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'photopress_unwritable', $e->getMessage() );
		}

		clearstatcache( true, $tmp );

		if ( ! @filesize( $tmp ) || ! @getimagesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'photopress_unwritable', 'The written file is not an image.' );
		}

		@chmod( $tmp, fileperms( $path ) & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'photopress_unwritable', 'The written file could not be moved into place.' );
		}

		return true;
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
	 * $force. $only: just these taxonomies.
	 */
	public function setTaxonomyTerms( $id, $md, $force = false, $only = null ) {

		$model   = TaxonomyModel::fromSettings();
		$present = $force ? [] : TermRouter::present( $md, $model );

		wp_defer_term_counting( true );

		foreach ( TermRouter::route( $md, $model ) as $tax_id => $terms ) {

			if ( ! taxonomy_exists( $tax_id ) || ( $only && ! in_array( $tax_id, $only, true ) ) || ( ! $force && empty( $present[ $tax_id ] ) ) ) {
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

	/**
	 * pre_get_posts: the main query of an image taxonomy's archive page
	 * lists images, whose status is inherit, where WordPress would look for
	 * published posts and find none. Only images WordPress counts as public
	 * (get_post_status()): unattached, or attached to a published post.
	 * Other queries on the page, and other taxonomies' archives, are left
	 * alone.
	 *
	 * @param \WP_Query $query
	 */
	public function makeImagesVisibleToTaxQueries( $query ) {

		if ( ! $query->is_main_query() || ! $query->is_tax() || '' !== (string) $query->get( 'post_status' ) ) {
			return;
		}

		$taxonomies = array_keys( (array) ( $query->tax_query->queried_terms ?? [] ) );

		if ( ! $taxonomies || ! self::areImageTaxonomies( $taxonomies ) ) {
			return;
		}

		$query->set( 'post_status', 'inherit' );
		$query->set( self::PUBLIC_IMAGES, true );
	}

	/**
	 * Whether each taxonomy is for images alone.
	 *
	 * @param string[] $taxonomies
	 */
	public static function areImageTaxonomies( array $taxonomies ) {

		foreach ( $taxonomies as $name ) {

			$taxonomy = get_taxonomy( $name );

			if ( ! $taxonomy || [ 'attachment' ] !== array_values( (array) $taxonomy->object_type ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A query var: the query lists only images WordPress counts as public
	 * (get_post_status()), unattached or attached to a published post. Set
	 * on image taxonomy archives, by the Taxonomy Gallery, and on searches
	 * that list images (search module). Other post types are left alone.
	 */
	const PUBLIC_IMAGES = 'photopress_public_images';

	/**
	 * posts_where, for queries with PUBLIC_IMAGES set.
	 */
	public static function onlyPublicImages( $where, $query ) {

		global $wpdb;

		if ( ! $query instanceof \WP_Query || ! $query->get( self::PUBLIC_IMAGES ) ) {
			return $where;
		}

		// Only images: a search can list posts with them.
		return $where . " AND ( {$wpdb->posts}.post_type <> 'attachment' OR {$wpdb->posts}.post_parent = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->posts} AS photopress_parent WHERE photopress_parent.ID = {$wpdb->posts}.post_parent AND photopress_parent.post_status = 'publish' ) )";
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