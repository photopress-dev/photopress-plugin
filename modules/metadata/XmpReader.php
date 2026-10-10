<?php 

namespace PhotoPress\modules\metadata;
use pp_api;
	
class XmpReader {
	
	const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
	const XML_NS = 'http://www.w3.org/XML/1998/namespace';
	
	/**
	 * Namespace URI => the prefix properties are named with, whatever prefix
	 * the file itself uses. Unknown namespaces keep the file's prefix.
	 */
	const NAMESPACES = [
		'http://purl.org/dc/elements/1.1/'                     => 'dc',
		'http://ns.adobe.com/xap/1.0/'                         => 'xmp',
		'http://ns.adobe.com/xap/1.0/rights/'                  => 'xmpRights',
		'http://ns.adobe.com/xap/1.0/mm/'                      => 'xmpMM',
		'http://ns.adobe.com/xap/1.0/bj/'                      => 'xmpBJ',
		'http://ns.adobe.com/xap/1.0/t/pg/'                    => 'xmpTPg',
		'http://ns.adobe.com/xap/1.0/g/'                       => 'xmpG',
		'http://ns.adobe.com/xap/1.0/g/img/'                   => 'xmpGImg',
		'http://ns.adobe.com/xmp/Identifier/qual/1.0/'         => 'xmpidq',
		'http://ns.adobe.com/xmp/note/'                        => 'xmpNote',
		'http://ns.adobe.com/xmp/1.0/DynamicMedia/'            => 'xmpDM',
		'http://ns.adobe.com/xap/1.0/sType/ResourceEvent#'     => 'stEvt',
		'http://ns.adobe.com/xap/1.0/sType/ResourceRef#'       => 'stRef',
		'http://ns.adobe.com/xap/1.0/sType/Dimensions#'        => 'stDim',
		'http://ns.adobe.com/xap/1.0/sType/Version#'           => 'stVer',
		'http://ns.adobe.com/xap/1.0/sType/Job#'               => 'stJob',
		'http://ns.adobe.com/xmp/sType/Area#'                  => 'stArea',
		'http://ns.adobe.com/photoshop/1.0/'                   => 'photoshop',
		'http://ns.adobe.com/tiff/1.0/'                        => 'tiff',
		'http://ns.adobe.com/exif/1.0/'                        => 'exif',
		'http://ns.adobe.com/exif/1.0/aux/'                    => 'aux',
		'http://cipa.jp/exif/1.0/'                             => 'exifEX',
		'http://ns.adobe.com/pdf/1.3/'                         => 'pdf',
		'http://ns.adobe.com/camera-raw-settings/1.0/'         => 'crs',
		'http://ns.adobe.com/lightroom/1.0/'                   => 'lr',
		'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/'          => 'Iptc4xmpCore',
		'http://iptc.org/std/Iptc4xmpExt/2008-02-29/'          => 'Iptc4xmpExt',
		'http://ns.useplus.org/ldf/xmp/1.0/'                   => 'plus',
		'http://www.metadataworkinggroup.com/schemas/regions/' => 'mwg-rs',
		'http://ns.microsoft.com/photo/1.0/'                   => 'MicrosoftPhoto',
		'http://www.digikam.org/ns/1.0/'                       => 'digiKam',
		'http://ns.google.com/photos/1.0/panorama/'            => 'GPano',
	];
	
	/**
	 * Older or tool-specific prefixes for namespaces in NAMESPACES. A tag
	 * requested with one of these (in settings, say) is looked up under the
	 * prefix the properties are named with.
	 */
	const PREFIX_ALIASES = [
		'xap'       => 'xmp',
		'xapRights' => 'xmpRights',
		'xapMM'     => 'xmpMM',
		'xapBJ'     => 'xmpBJ',
		'xapTPg'    => 'xmpTPg',
		'xapG'      => 'xmpG',
		'xapGImg'   => 'xmpGImg',
		'xapidq'    => 'xmpidq',
		'lightroom' => 'lr',
	];
	
	// xmp properties; see extractXmp()
	var $xmp		= [];
	// flattened xmp array
	var $flat_xmp;
	var $iptc 		= [];
	var $exif 		= [];
	var $labels 	= [];
	// rdf:Alt values by property and language; see getAlternatives()
	var $alternatives = [];
	var $exif_tags	= [
		'title',
		'ImageDescription',
		'Artist',
		'Author',
		'Copyright',
		'FNumber',
		'Make',
		'Model',
		// LensModel, which PHP's EXIF reader does not name.
		'UndefinedTag:0xA434',
		'DateTimeDigitized',
		'FocalLength',
		'ISOSpeedRatings',
		'ExposureTime',
		'DateTimeOriginal',
		'ImageWidth',
		'ImageLength',
		'Orientation',
		'XResolution',
		'YResolution',
		'FocalLength',
		'Flash',
		'MeteringMode',
		'ExposureProgram'
	];
	
	/**
	 * Values for the photopress:* shortcut tags, from this reader's image.
	 *
	 * Called directly by getXmp(). It used to be added to the global
	 * photopress_metadata_tag_value filter by every reader's constructor, so
	 * each image processed in a request added another callback answering from
	 * its own image.
	 */
	public function registerShortcuts( $value, $tag, $xmp ) {
		
		switch ( $tag ) {
			
			case 'photopress:camera':
			
				return $this->getCamera();
				
				
			case 'photopress:stringOfKeywords':
			
				// A single keyword comes back unwrapped.
				$keywords = (array) $this->getXmp('dc:subject');
				$nkeywords = [];
				$separators = TaxonomyModel::separators( (string) pp_api::getOption('core', 'metadata', 'custom_taxonomies_tag_delimiter') ) ?: [ ':' ];
				
				foreach ( $keywords as $v ) {
					
					$v = trim( (string) $v );
					
					// A prefixed keyword ("person:Bob") contributes its
					// value, after the first separator in it. These used to be
					// dropped, which left nothing when every keyword is
					// prefixed.
					$found = null;
					
					foreach ( $separators as $separator ) {
						$pos = strpos( $v, $separator );
						if ( $pos && ( ! $found || $pos < $found[0] ) ) {
							$found = [ $pos, $separator ];
						}
					}
					
					if ( $found ) {
						$v = trim( substr( $v, $found[0] + strlen( $found[1] ) ) );
					}
					
					if ( '' !== $v ) {
						$nkeywords[] = $v;
					}
				}
				
				return implode( ', ', array_unique( $nkeywords ) );
		}
	}
	
	function getXmp( $name ) {
		
		// allows or tag name aliases
		$name = apply_filters( 'photopress_metadata_tag_name', $name );
		
		$all_xmp = $this->getAllXmp();
		
		$nvalue = '';
		
		if ( $all_xmp ) {
			
			// allows for shortcut tags
			$nvalue = $this->registerShortcuts( $nvalue, $name, $all_xmp );
			$nvalue = apply_filters( 'photopress_metadata_tag_value', $nvalue, $name, $all_xmp );
			
			// if no shortcut is returned then try to pull the value from the xmp
			if ( ! $nvalue ) {
			
				if ( $all_xmp ) {
				
					if (is_array( $name ) ) {
					
						$names = array_flip( $name );
						
						$somevalues = array_intersect_key($all_xmp, $names );
						
						$nvalue = array();
						
						foreach ($somevalues as $k => $v) {
							
							$nvalue[$k] = $this->formatKeyValue($k, $v);
						}
						
					} else {
					
						$name = $this->canonicalName( $name );
						
						if (array_key_exists($name, $all_xmp)) {
							
							$nvalue = $all_xmp[$name];
							
							// A list of one is returned as its value. Not a
							// structure, whose keys are field names.
							if ( is_array( $nvalue ) && ( [] === $nvalue || array_keys( $nvalue ) === [ 0 ] ) ) {
							
								$nvalue = $nvalue ? $nvalue[0] : '';
							}			
							
							$nvalue = $this->formatKeyValue($name, $nvalue);	
						}
					}
				}
			}
			
			return $nvalue;	
		}
	}

	
	function get ( $keys ) {
	
		$pairs = array();
	
		if ( ! is_array( $keys ) ) {
			
			$keys = array( $keys );
		}
		
		foreach ( $keys as $key ) {
			
			list ( $family, $attr ) = explode( ':', trim( $key ) );
			
			if ( $family === 'exif') {
				
				$value = $this->getExif( $attr );
				
			} elseif ( $family === 'iptc') {
			
				$value = $this->getIptc( $attr );
			
			} elseif ( $family === 'photopress') {
				
				$method = 'get'.ucwords($attr);
				if ( method_exists( $this, $method) ) {
				
					$value = $this->$method( $attr );
				} else {
					
					$value = 'not found';
				}
			} else {
				
				$value = $this->getXmp( $attr );
			}
			
			$pairs[ $this->getLabel( $key ) ] = $value;	
		}
		
		return $pairs;
	}
		
	function getRawXmpValues() {
		
		return $this->xmp;
	}
	
	function getTitle() {
	
		$title = $this->getXmp( 'dc:title' );
		
		if ( ! $title ) {
			$title = $this->getIptc( 'title' );
		}
		
		return $title;
	}
	
	function getIptc( $name ) {
		
		if ( isset( $this->iptc[$name] ) ) {
			return $this->iptc[$name];
		}
	}
	
	function getExif( $name ) {
		
		if ( isset( $this->exif[$name] ) ) {
			return $this->formatKeyValue('exif:'.$name, $this->exif[$name]);
		}
	}
	
	function getCaption() {
		return $this->getXmp('dc:description');
	}
	
	function getKeywords() {
		return $this->getXmp('dc:subject');
	}
	
	function getGeoValues() {
		
		return array('city' => $this->getXmp('photoshop:City'),
					 'state' => $this->getXmp('photoshop:State'),
					 'country' => $this->getXmp('photoshop:Country')
					);
	}
	
	function getCity() {
	
		return $this->getXmp('photoshop:City');
	}
	
	function getState() {
	
		return $this->getXmp('photoshop:State');
	}
	
	function getCountry() {
	
		return $this->getXmp('photoshop:Country');
	}
	
	function getShutterSpeed() {
		
		$ss = $this->getExif('ExposureTime');
		
		if ( ! $ss ) {
			$ss = $this->getXmp('exif:ExposureTime');
		}
		return $ss;
	}
	
	function getCreationDate() {
		
		return $this->getXmp('exif:DateTimeDigitized');
	}
	
	function getCopyrightHolder() {
	
		// get copyright holder
		$copyright = $this->getXmp( 'dc:creator' );
		if ( ! $copyright ) {
			$copyright = $this->getExif( 'Copyright' );
		}
		
		return $copyright;
	}
	
	function getContactUrl() {
		// get creator URL
		$bucket = $this->getXmp('Iptc4xmpCore:CreatorContactInfo');
		$url = '';
		if ( is_array( $bucket ) && isset( $bucket['Iptc4xmpCore:CiUrlWork'] ) ) {
			$url = $bucket['Iptc4xmpCore:CiUrlWork'];
		}
		
		return $url;
	}

	function getRightsStatement() {
			
		// get rights statement
		$rights = $this->getXmp('xmpRights:UsageTerms');
		// A single value comes back unwrapped; a list as an array.
		if ( is_array( $rights ) ) {
			$rights = reset( $rights );
		}
				 
		return $rights;
	}
	
	/** The camera, from wherever it is recorded, cleaned up (StandardMetadata). */
	function getCamera() {
		
		return StandardMetadata::camera( $this );
	}
	
	/** The lens model, from wherever it is recorded (StandardMetadata). */
	function getLens() {

		return StandardMetadata::lens( $this );
	}
	
	function getAllXmp() {
		
		return $this->flat_xmp;
	}
	
	function getAllMetaData() {
		
		$meta = array();
		$meta['xmp'] = $this->flat_xmp;
		$meta['exif'] = $this->exif;
		$meta['iptc'] = $this->iptc;
		
		return $meta;
	}
		
	function displayAllXmp() {
	
		return $this->makeXmpHtml($this->getAllXmp());
	}
	
	function displayXmp($values, $template = '') {
		
		$nvalues = $this->getXmp($values);
		
		return $this->displayMeta($values, $template);
	}
	
	function displayMeta($values, $class = '', $template = '', $container_template = '' ) {
		
		if ( $values ) {
		
			if ( is_array( $values ) ) {
				
				return $this->makeXmpHtml( $values, $class, $template, $container_template );
				
			} else {
			
				return $this->makeXmpHtml( array( $values => $values ), $class, $template, $container_template );
			}
		}
	}
	
	function render( $values, $class = '', $template = '', $container_template = '' ) {
		
		return $this->displayMeta( $values, $class, $template, $container_template );
	}
	
	function makeXmpHtml( $values, $class = '', $template = '', $container_template = '' ) {
		
		if ( $values ) {
		
			if ( ! $class ) {
				
				$class = 'table-display';
			}
		
			if ( ! $template ) {
			
				$container_template = '<dl class="'. $class .'">%s</dl>';
			
				$template = '<DT>%s:</DT><DD>%s</DD>';
			}
			
			$md = '';
		
			foreach ($values as $k => $v) {
			
				$i = 0;
							
				if ($v) {
				
					if (is_array($v)) {
						$v = implode(', ', $v);
					}
					
					$md .= sprintf($template, $this->getLabel($k), $v);
					$i++;
				}
			}
			
			if ( $i > 0 ) {
			
				$md = sprintf( $container_template, $md );
			}
			
			return $md;
			
		} else {
		
			return false;
		}
					
	}
	
	function readExif( $file ) {
		
		$exif = @exif_read_data( $file );
		$exif2 = array();
	
		if ($exif) {
					
			foreach ( $this->exif_tags as $k ) {
				
				if ( isset( $exif[$k] ) ) {
					
					$exif2[$k] = trim($exif[$k]);
				} else {
					$exif2[$k] = '';
				}
			}
		}
		
		return $exif2;
		
	}
		
	function loadFromFile($file) {
		
		$this->xmp = $this->extractXmp($file);
		$this->flat_xmp = $this->flattenXmp($this->xmp);
		//$this->iptc = wp_read_image_metadata( $file );
		//print_r($xml_array);
		$this->exif = $this->readExif( $file );			
	}
	
	function loadFromSerializedString($str) {
		
		// Never instantiate objects from the string.
		$md = unserialize( $str, [ 'allowed_classes' => false ] );
		$this->flat_xmp = $md;
	}
	
	function loadFromArray($array) {
		
		if (isset( $array['xmp'] ) ) {
		
			$this->flat_xmp = $array['xmp'];
		}
		
		if (isset( $array['exif'] ) ) {
		
			$this->exif = $array['exif'];
		}
		
		if (isset( $array['iptc'] ) ) {
		
			$this->iptc = $array['iptc'];
		}
	}
	
	/**
	 * Kept for callers that pass the old nested form. extractXmp() now
	 * returns flat properties, which pass through unchanged.
	 */
	function flattenXmp( $xmp ) {

		$nxmp = array();

		foreach ( (array) $xmp as $k => $v ) {

			if ( $k === 'rdf:Description' && is_array( $v ) ) {
				$nxmp = array_merge( $v, $nxmp );
			} else {
				$nxmp[ $k ] = $v;
			}
		}

		return $nxmp;
	}

	/**
	 * The XMP properties of a file, as a flat array of "prefix:Name" => value.
	 *
	 * Values: a string for a simple property; a list for rdf:Bag and rdf:Seq;
	 * the x-default text for rdf:Alt (all languages via getAlternatives());
	 * an array of "prefix:Field" => value for a structure. Prefixes are those
	 * in NAMESPACES whatever the file uses, so xap:CreatorTool is read as
	 * xmp:CreatorTool.
	 */
	function extractXmp( $file ) {

		$this->alternatives = [];

		$fh = @fopen( $file, 'rb' );

		if ( ! $fh ) {
			return array();
		}

		$packets = null;

		if ( $this->readBytes( $fh, 2 ) === "\xFF\xD8" ) {

			$packets = $this->readJpegPackets( $fh );

		} elseif ( stream_is_local( $fh ) ) {

			// Another format: from where the format keeps it (XmpFile), which
			// also reads compressed packets. Local files only, as it seeks
			// about the file.
			$packet = XmpFile::read( $file );

			if ( is_string( $packet ) && '' !== $packet ) {
				$packets = [ 'main' => $packet, 'extended' => [], 'complete' => true ];
			}
		}

		// Not one of those formats, or one whose structure could not be
		// followed: search the file instead.
		if ( ! $packets || ( ! $packets['main'] && ! $packets['complete'] ) ) {
			rewind( $fh );
			$packets = [ 'main' => $this->scanForPacket( $fh ), 'extended' => [] ];
		}

		fclose( $fh );

		if ( ! $packets['main'] ) {
			return array();
		}

		$props = $this->parsePacket( $packets['main'] );

		// Extended XMP: a JPEG whose metadata exceeds one 64KB segment moves
		// part of it to further segments, named by the GUID in the main packet.
		$guid = isset( $props['xmpNote:HasExtendedXMP'] ) ? (string) $props['xmpNote:HasExtendedXMP'] : '';

		if ( $guid && isset( $packets['extended'][ $guid ] ) ) {
			$props += $this->parsePacket( $packets['extended'][ $guid ] );
		}

		return $props;
	}

	/**
	 * Reads the XMP segments of a JPEG, stopping at the image data. Returns
	 * the standard packet and the reassembled Extended XMP packets by GUID.
	 */
	private function readJpegPackets( $fh ) {

		$standard = "http://ns.adobe.com/xap/1.0/\0";
		$extension = "http://ns.adobe.com/xmp/extension/\0";
		$main = null;
		$chunks = [];
		$complete = false;

		while ( ! feof( $fh ) ) {

			$byte = fread( $fh, 1 );

			if ( $byte !== "\xFF" ) {
				break;
			}

			// Markers may be preceded by any number of 0xFF fill bytes.
			do {
				$type = fread( $fh, 1 );
			} while ( $type === "\xFF" );

			if ( $type === '' || $type === false ) {
				break;
			}

			$type = ord( $type );

			// Markers without a length.
			if ( $type === 0x01 || ( $type >= 0xD0 && $type <= 0xD8 ) ) {
				continue;
			}

			// Start of scan or end of image: no metadata follows.
			if ( $type === 0xDA || $type === 0xD9 ) {
				$complete = true;
				break;
			}

			$length = $this->readBytes( $fh, 2 );

			if ( strlen( $length ) < 2 ) {
				break;
			}

			$length = unpack( 'n', $length )[1] - 2;

			if ( $length < 0 ) {
				break;
			}

			if ( $type !== 0xE1 ) {
				fseek( $fh, $length, SEEK_CUR );
				continue;
			}

			$data = $length ? $this->readBytes( $fh, $length ) : '';

			if ( null === $main && strncmp( $data, $standard, strlen( $standard ) ) === 0 ) {

				$main = substr( $data, strlen( $standard ) );

			} elseif ( strncmp( $data, $extension, strlen( $extension ) ) === 0 && strlen( $data ) > strlen( $extension ) + 40 ) {

				// GUID (32 bytes), full length (4), offset of this chunk (4), data.
				$body = substr( $data, strlen( $extension ) );
				$guid = substr( $body, 0, 32 );
				$total = unpack( 'N', substr( $body, 32, 4 ) )[1];
				$offset = unpack( 'N', substr( $body, 36, 4 ) )[1];

				$chunks[ $guid ]['total'] = $total;
				$chunks[ $guid ]['parts'][ $offset ] = substr( $body, 40 );
			}
		}

		$extended = [];

		foreach ( $chunks as $guid => $chunk ) {

			ksort( $chunk['parts'] );
			$packet = implode( '', $chunk['parts'] );

			// Only a complete packet is usable.
			if ( strlen( $packet ) === $chunk['total'] ) {
				$extended[ $guid ] = $packet;
			}
		}

		return [ 'main' => $main, 'extended' => $extended, 'complete' => $complete ];
	}

	/**
	 * Reads exactly $length bytes unless the file ends first. A single fread()
	 * may return less than asked for on streams other than plain local files,
	 * such as the s3:// wrappers of media offload plugins.
	 */
	private function readBytes( $fh, $length ) {

		$data = '';

		while ( strlen( $data ) < $length && ! feof( $fh ) ) {

			$chunk = fread( $fh, $length - strlen( $data ) );

			if ( $chunk === false || $chunk === '' ) {
				break;
			}

			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Finds the first XMP packet in a file of any other format (TIFF, DNG,
	 * PNG, WebP, HEIC ...) by reading 64KB chunks until the packet ends, rather
	 * than loading the whole file. Recognizes x:xmpmeta, the older x:xapmeta,
	 * and a bare rdf:RDF.
	 */
	private function scanForPacket( $fh ) {

		$open = '/<(x:xmpmeta|x:xapmeta|rdf:RDF)[\s>\/]/';
		$keep = 16; // longest opening tag, so one split across chunks is still found
		$buf = '';
		$tag = null;

		while ( ! feof( $fh ) ) {

			$chunk = fread( $fh, 65536 );

			if ( $chunk === false || $chunk === '' ) {
				break;
			}

			$buf .= $chunk;

			if ( null === $tag ) {

				if ( ! preg_match( $open, $buf, $m, PREG_OFFSET_CAPTURE ) ) {
					$buf = substr( $buf, -$keep );
					continue;
				}

				$tag = $m[1][0];
				$buf = substr( $buf, $m[0][1] );
			}

			$end = strpos( $buf, '</' . $tag . '>' );

			if ( $end !== false ) {
				return substr( $buf, 0, $end + strlen( $tag ) + 3 );
			}
		}

		return null;
	}

	/**
	 * Kept for backward compatibility; see parsePacket().
	 */
	function XMP2array( $data ) {

		return $this->parsePacket( $data );
	}

	/**
	 * Parses an XMP packet into flat properties; see extractXmp().
	 */
	function parsePacket( $data ) {

		/*
		 * Drop the <?xpacket ... ?> processing instructions and anything else
		 * outside the root element, which a packet read from a TIFF may have.
		 * NOTE: a block comment on purpose -- a `//` comment containing ?>
		 * ends PHP mode.
		 */
		$data = preg_replace( '/<\?xpacket[^>]*\?>/', '', (string) $data );
		$data = trim( $data, " \t\r\n\0\xEF\xBB\xBF" );

		if ( '' === $data ) {
			return array();
		}

		$doc = new \DOMDocument();

		// No network access and no entity expansion.
		if ( ! @$doc->loadXML( $data, LIBXML_NONET ) ) {
			return array();
		}

		$props = [];

		foreach ( $doc->getElementsByTagNameNS( self::RDF_NS, 'Description' ) as $description ) {

			// Top-level descriptions only; nested ones are structure values.
			$parent = $description->parentNode;

			if ( ! $parent || $parent->namespaceURI !== self::RDF_NS || $parent->localName !== 'RDF' ) {
				continue;
			}

			// Tools may split properties across several descriptions; the
			// first value of a property wins.
			$props += $this->readProperties( $description );
		}

		return $props;
	}

	/**
	 * The properties of an rdf:Description, or the fields of a structure:
	 * its non-RDF attributes and its child elements.
	 */
	private function readProperties( \DOMElement $el ) {

		$props = [];

		foreach ( $el->attributes as $attr ) {

			if ( ! $this->isRdfSyntax( $attr->namespaceURI ) ) {
				$props[ $this->propertyName( $attr ) ] = $attr->value;
			}
		}

		foreach ( $el->childNodes as $child ) {

			if ( $child instanceof \DOMElement && ! $this->isRdfSyntax( $child->namespaceURI ) ) {
				$props[ $this->propertyName( $child ) ] = $this->readValue( $child );
			}
		}

		return $props;
	}

	/**
	 * The value of a property element or of an rdf:li.
	 */
	private function readValue( \DOMElement $el ) {

		// <ns:prop rdf:resource="http://..."/>
		if ( $el->hasAttributeNS( self::RDF_NS, 'resource' ) ) {
			return $el->getAttributeNS( self::RDF_NS, 'resource' );
		}

		// <ns:prop rdf:parseType="Resource"> fields as child elements </ns:prop>
		if ( $el->getAttributeNS( self::RDF_NS, 'parseType' ) === 'Resource' ) {
			return $this->readProperties( $el );
		}

		$children = [];

		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$children[] = $child;
			}
		}

		if ( ! $children ) {

			// <ns:prop ns:field="..."/>: a structure written as attributes.
			$fields = $this->readProperties( $el );

			if ( $fields && trim( $el->textContent ) === '' ) {
				return $fields;
			}

			// Whitespace alone is no value.
			return trim( $el->textContent ) === '' ? '' : $el->textContent;
		}

		$first = $children[0];

		if ( $first->namespaceURI === self::RDF_NS ) {

			switch ( $first->localName ) {

				case 'Bag':
				case 'Seq':
					$items = [];
					foreach ( $first->childNodes as $li ) {
						if ( $li instanceof \DOMElement && $li->namespaceURI === self::RDF_NS && $li->localName === 'li' ) {
							$items[] = $this->readValue( $li );
						}
					}
					return $items;

				case 'Alt':
					return $this->readAlternative( $el, $first );

				// <ns:prop><rdf:Description> fields </rdf:Description></ns:prop>
				case 'Description':
					return $this->readProperties( $first );

				// A qualified value: <rdf:value> plus qualifier elements.
				case 'value':
					return $this->readValue( $first );
			}
		}

		// Child elements without RDF syntax: a structure.
		return $this->readProperties( $el );
	}

	/**
	 * Language alternatives (rdf:Alt). Returns the x-default text, or the
	 * first one; all of them are kept for getAlternatives().
	 */
	private function readAlternative( \DOMElement $prop, \DOMElement $alt ) {

		$values = [];

		foreach ( $alt->childNodes as $li ) {

			if ( $li instanceof \DOMElement && $li->namespaceURI === self::RDF_NS && $li->localName === 'li' ) {

				$lang = $li->getAttributeNS( self::XML_NS, 'lang' ) ?: 'x-default';
				$value = $this->readValue( $li );

				if ( ! isset( $values[ $lang ] ) ) {
					$values[ $lang ] = is_array( $value ) ? $value : (string) $value;
				}
			}
		}

		$this->alternatives[ $this->propertyName( $prop ) ] = $values;

		if ( isset( $values['x-default'] ) ) {
			return $values['x-default'];
		}

		return $values ? reset( $values ) : '';
	}

	/**
	 * All language versions of an rdf:Alt property, by language code.
	 */
	function getAlternatives( $name ) {

		return isset( $this->alternatives[ $name ] ) ? $this->alternatives[ $name ] : [];
	}

	/**
	 * A tag name with a legacy prefix (xap:CreatorTool) under the prefix
	 * properties are named with (xmp:CreatorTool).
	 */
	function canonicalName( $name ) {

		$parts = explode( ':', (string) $name, 2 );

		if ( count( $parts ) === 2 && isset( self::PREFIX_ALIASES[ $parts[0] ] ) ) {
			return self::PREFIX_ALIASES[ $parts[0] ] . ':' . $parts[1];
		}

		return $name;
	}

	private function propertyName( \DOMNode $node ) {

		$prefix = isset( self::NAMESPACES[ $node->namespaceURI ] )
			? self::NAMESPACES[ $node->namespaceURI ]
			: ( $node->prefix ? $node->prefix : 'ns' );

		return $prefix . ':' . $node->localName;
	}

	private function isRdfSyntax( $uri ) {

		return ! $uri || in_array( $uri, [ self::RDF_NS, self::XML_NS, 'http://www.w3.org/2000/xmlns/', 'adobe:ns:meta/' ], true );
	}

	function formatKeyValue($key, $value) {
		
		return $value;
	}
	
	static function frac2dec($str) {
		
		if ( strpos($str, '/') ) {
		
			@list( $n, $d ) = explode( '/', $str );
			if ( !empty($d) ) {
				return $n / $d;
			}
		
		}
		
		return $str;
	}
	
	/**
	 * Convert the exif date format to a unix timestamp.
	 *
	 * @param string $str
	 * @return int
	 */
	function date2ts($str) {
		@list( $date, $time ) = explode( ' ', trim($str) );
		@list( $y, $m, $d ) = explode( ':', $date );
	
		return strtotime( "{$y}-{$m}-{$d} {$time}" );
	}
	
	function getLabel($str) {
	
		$this->labels = $this->getAlllabels();	
		$key = $this->canonicalName( $str );
		
		if ( array_key_exists( $key, $this->labels ) ) {
		
			return $this->labels[ $key ];
			
		} else {
		
			return $str;
		}
		
	}
	
	function getAllLabels() {
		
		return array(

		"dc:contributor" 					=> "Other Contributor(s)",
		"dc:coverage" 						=> "Coverage (scope)",
		"dc:creator" 						=> "Creator(s) (Authors)",
		"dc:date" 							=> "Date",
		"dc:description"			 		=> "Caption",
		"dc:format" 						=> "MIME Data Format",
		"dc:identifier" 					=> "Unique Resource Identifer",
		"dc:language" 						=> "Language(s)",
		"dc:publisher" 						=> "Publisher(s)",
		"dc:relation" 						=> "Relations to other documents",
		"dc:rights" 						=> "Rights Statement",
		"dc:source" 						=> "Source (from which this Resource is derived)",
		"dc:subject" 						=> "Keywords",
		"dc:title" 							=> "Title",
		"dc:type" 							=> "Resource Type",
		
		"aux:Lens" 							=> "Lens",
		
		"xmp:Advisory" 						=> "Externally Editied Properties",
		"xmp:BaseURL" 						=> "Base URL for relative URL's",
		"xmp:CreateDate"			 		=> "Original Creation Date",
		"xmp:CreatorTool" 					=> "Creator Tool",
		"xmp:Identifier" 					=> "Identifier(s)",
		"xmp:MetadataDate" 					=> "Metadata Last Modify Date",
		"xmp:ModifyDate" 					=> "Resource Last Modify Date",
		"xmp:Nickname" 						=> "Nickname",
		"xmp:Thumbnails"			 		=> "Thumbnails",
		
		"xmpidq:Scheme" 					=> "Identification Scheme",
		
		// Legacy xap* prefixes are read under their xmp* names; see PREFIX_ALIASES.
		"xmpidq:Scheme" 					=> "Identification Scheme",
		
		"xmpRights:Certificate"			 	=> "Certificate",
		"xmpRights:Copyright" 				=> "Copyright",
		"xmpRights:Marked" 					=> "Marked",
		"xmpRights:Owner" 					=> "Owner",
		"xmpRights:UsageTerms" 				=> "Legal Terms of Usage",
		"xmpRights:WebStatement" 			=> "Web Page describing rights statement (Owner URL)",
		
		"xmpMM:ContainedResources" 			=> "Contained Resources",
		"xmpMM:ContributorResources" 		=> "Contributor Resources",
		"xmpMM:DerivedFrom" 				=> "Derived From",
		"xmpMM:DocumentID" 					=> "Document ID",
		"xmpMM:History" 					=> "History",
		"xmpMM:LastURL" 					=> "Last Written URL",
		"xmpMM:ManagedFrom"		 			=> "Managed From",
		"xmpMM:Manager" 					=> "Asset Management System",
		"xmpMM:ManageTo" 					=> "Manage To",
		"xmpMM:ManageUI" 				=> "Managed Resource URI",
		"xmpMM:ManagerVariant" 				=> "Particular Variant of Asset Management System",
		"xmpMM:RenditionClass" 				=> "Rendition Class",
		"xmpMM:RenditionParams"		 		=> "Rendition Parameters",
		"xmpMM:RenditionOf" 				=> "Rendition Of",
		"xmpMM:SaveID" 						=> "Save ID",
		"xmpMM:VersionID" 					=> "Version ID",
		"xmpMM:Versions" 					=> "Versions",
		
		"xmpBJ:JobRef" 						=> "Job Reference",
		
		"xmpTPg:MaxPageSize"	 			=> "Largest Page Size",
		"xmpTPg:NPages" 					=> "Number of pages",
		
		"pdf:Keywords" 						=> "Keywords",
		"pdf:PDFVersion"			 		=> "PDF file version",
		"pdf:Producer" 						=> "PDF Creation Tool",
		
		"photoshop:AuthorsPosition" 		=> "Authors Position",
		"photoshop:CaptionWriter"			=> "Caption Writer",
		"photoshop:Category" 				=> "Category",
		"photoshop:City" 					=> "City",
		"photoshop:Country" 				=> "Country",
		"photoshop:Credit" 					=> "Credit",
		"photoshop:DateCreated" 			=> "Creation Date",
		"photoshop:Headline" 				=> "Headline",
		"photoshop:History" 				=> "History", // Not in XMP spec
		"photoshop:Instructions" 			=> "Instructions",
		"photoshop:Source" 					=> "Source",
		"photoshop:State" 					=> "State",
		"photoshop:SupplementalCategories" 	=> "Supplemental Categories",
		"photoshop:TransmissionReference" 	=> "Technical (Transmission) Reference",
		"photoshop:Urgency" => "Urgency",
		
		"tiff:ImageWidth" 					=> "Image Width",
		"tiff:ImageLength" 					=> "Image Height",
		"tiff:BitsPerSample" 				=> "Bits Per Sample",
		"tiff:Compression" 					=> "Compression",
		"tiff:PhotometricInterpretation" 	=> "Photometric Interpretation",
		"tiff:Orientation" 					=> "Orientation",
		"tiff:SamplesPerPixel" 				=> "Samples Per Pixel",
		"tiff:PlanarConfiguration" 			=> "Planar Configuration",
		"tiff:YCbCrSubSampling" 			=> "YCbCr Sub-Sampling",
		"tiff:YCbCrPositioning" 			=> "YCbCr Positioning",
		"tiff:XResolution" 					=> "X Resolution",
		"tiff:YResolution" 					=> "Y Resolution",
		"tiff:ResolutionUnit" 				=> "Resolution Unit",
		"tiff:TransferFunction" 			=> "Transfer Function",
		"tiff:WhitePoint" 					=> "White Point",
		"tiff:PrimaryChromaticities" 		=> "Primary Chromaticities",
		"tiff:YCbCrCoefficients" 			=> "YCbCr Coefficients",
		"tiff:ReferenceBlackWhite" 			=> "Black & White Reference",
		"tiff:DateTime" 					=> "Date & Time",
		"tiff:ImageDescription" 			=> "Image Description",
		"tiff:Make" 						=> "Make",
		"tiff:Model" 						=> "Camera",
		"tiff:Software" 					=> "Software",
		"tiff:Artist" 						=> "Artist",
		"tiff:Copyright" 					=> "Copyright",
		
		"exif:ExifVersion" 					=> "Exif Version",
		"exif:FlashpixVersion" 				=> "Flash pix Version",
		"exif:ColorSpace" 					=> "Color Space",
		"exif:ComponentsConfiguration" 		=> "Components Configuration",
		"exif:CompressedBitsPerPixel" 		=> "Compressed Bits Per Pixel",
		"exif:PixelXDimension" 				=> "Pixel X Dimension",
		"exif:PixelYDimension" 				=> "Pixel Y Dimension",
		"exif:MakerNote" 					=> "Maker Note",
		"exif:UserComment"					=> "User Comment",
		"exif:RelatedSoundFile" 			=> "Related Sound File",
		"exif:DateTimeOriginal" 			=> "Date & Time of Original",
		"exif:DateTimeDigitized" 			=> "Taken On",
		"exif:ExposureTime" 				=> "Shutter Speed",
		"exif:FNumber" 						=> "Aperture",
		"exif:ExposureProgram" 				=> "Exposure Program",
		"exif:SpectralSensitivity" 			=> "Spectral Sensitivity",
		"exif:ISOSpeedRatings" 				=> "ISO Speed",
		"exif:OECF" 						=> "Opto-Electronic Conversion Function",
		"exif:ShutterSpeedValue" 			=> "Shutter Speed Value",
		"exif:ApertureValue" 				=> "Aperture Value",
		"exif:BrightnessValue" 				=> "Brightness Value",
		"exif:ExposureBiasValue" 			=> "Exposure Bias Value",
		"exif:MaxApertureValue" 			=> "Max Aperture Value",
		"exif:SubjectDistance" 				=> "Subject Distance",
		"exif:MeteringMode" 				=> "Metering Mode",
		"exif:LightSource" 					=> "Light Source",
		"exif:Flash" 						=> "Flash",
		"exif:FocalLength" 					=> "Focal Length",
		"exif:SubjectArea" 					=> "Subject Area",
		"exif:FlashEnergy" 					=> "Flash Energy",
		"exif:SpatialFrequencyResponse" 	=> "Spatial Frequency Response",
		"exif:FocalPlaneXResolution" 		=> "Focal Plane X Resolution",
		"exif:FocalPlaneYResolution" 		=> "Focal Plane Y Resolution",
		"exif:FocalPlaneResolutionUnit" 	=> "Focal Plane Resolution Unit",
		"exif:SubjectLocation" 				=> "Subject Location",
		"exif:SensingMethod" 				=> "Sensing Method",
		"exif:FileSource" 					=> "File Source",
		"exif:SceneType" 					=> "Scene Type",
		"exif:CFAPattern" 					=> "Color Filter Array Pattern",
		"exif:CustomRendered"				=> "Custom Rendered",
		"exif:ExposureMode" 				=> "Exposure Mode",
		"exif:WhiteBalance" 				=> "White Balance",
		"exif:DigitalZoomRatio" 			=> "Digital Zoom Ratio",
		"exif:FocalLengthIn35mmFilm" 		=> "Focal Length In 35mm Film",
		"exif:SceneCaptureType" 			=> "Scene Capture Type",
		"exif:GainControl" 					=> "Gain Control",
		"exif:Contrast" 					=> "Contrast",
		"exif:Saturation" 					=> "Saturation",
		"exif:Sharpness" 					=> "Sharpness",
		"exif:DeviceSettingDescription" 	=> "Device Setting Description",
		"exif:SubjectDistanceRange" 		=> "Subject Distance Range",
		"exif:ImageUniqueID" 				=> "Image Unique ID",
		"exif:GPSVersionID" 				=> "GPS Version ID",
		"exif:GPSLatitude" 					=> "GPS Latitude",
		"exif:GPSLongitude" 				=> "GPS Longitude",
		"exif:GPSAltitudeRef" 				=> "GPS Altitude Reference",
		"exif:GPSAltitude" 					=> "GPS Altitude",
		"exif:GPSTimeStamp" 				=> "GPS Time Stamp",
		"exif:GPSSatellites" 				=> "GPS Satellites",
		"exif:GPSStatus" 					=> "GPS Status",
		"exif:GPSMeasureMode" 				=> "GPS Measure Mode",
		"exif:GPSDOP" 						=> "GPS Degree Of Precision",
		"exif:GPSSpeedRef" 					=> "GPS Speed Reference",
		"exif:GPSSpeed" 					=> "GPS Speed",
		"exif:GPSTrackRef" 					=> "GPS Track Reference",
		"exif:GPSTrack" 					=> "GPS Track",
		"exif:GPSImgDirectionRef" 			=> "GPS Image Direction Reference",
		"exif:GPSImgDirection" 				=> "GPS Image Direction",
		"exif:GPSMapDatum" 					=> "GPS Map Datum",
		"exif:GPSDestLatitude" 				=> "GPS Destination Latitude",
		"exif:GPSDestLongitude" 			=> "GPS Destination Longitude",
		"exif:GPSDestBearingRef" 			=> "GPS Destination Bearing Reference",
		"exif:GPSDestBearing" 				=> "GPS Destination Bearing",
		"exif:GPSDestDistanceRef" 			=> "GPS Destination Distance Reference",
		"exif:GPSDestDistance" 				=> "GPS Destination Distance",
		"exif:GPSProcessingMethod" 			=> "GPS Processing Method",
		"exif:GPSAreaInformation" 			=> "GPS Area Information",
		"exif:GPSDifferential" 				=> "GPS Differential",
		// Exif Flash
		"exif:Fired" 						=> "Fired",
		"exif:Return" 						=> "Return",
		"exif:Mode" 						=> "Mode",
		"exif:Function" 					=> "Function",
		"exif:RedEyeMode" 					=> "Red Eye Mode",
		// Exif OECF/SFR
		"exif:Columns" 						=> "Columns",
		"exif:Rows" 						=> "Rows",
		"exif:Names" 						=> "Names",
		"exif:Values" 						=> "Values",
		"exif:Settings" 					=> "Settings",
		
		"stDim:w" 							=> "Width",
		"stDim:h" 							=> "Height",
		"stDim:unit" 						=> "Units",
		
		"xmpGImg:height"	 				=> "Height",
		"xmpGImg:width" 					=> "Width",
		"xmpGImg:format" 					=> "Format",
		"xmpGImg:image" 					=> "Image",
		
		"stEvt:action" 						=> "Action",
		"stEvt:instanceID" 					=> "Instance ID",
		"stEvt:parameters" 					=> "Parameters",
		"stEvt:softwareAgent" 				=> "Software Agent",
		"stEvt:when" 						=> "When",
		
		"stRef:instanceID" 					=> "Instance ID",
		"stRef:documentID" 					=> "Document ID",
		"stRef:versionID" 					=> "Version ID",
		"stRef:renditionClass" 				=> "Rendition Class",
		"stRef:renditionParams" 			=> "Rendition Parameters",
		"stRef:manager" 					=> "Asset Management System",
		"stRef:managerVariant" 				=> "Particular Variant of Asset Management System",
		"stRef:manageTo" 					=> "Manage To",
		"stRef:manageUI" 					=> "Managed Resource URI",
		
		"stVer:comments" 					=> "",
		"stVer:event" 						=> "",
		"stVer:modifyDate" 					=> "",
		"stVer:modifier" 					=> "",
		"stVer:version" 					=> "",
		
		"stJob:name" 						=> "Job Name",
		"stJob:id" 							=> "Unique Job ID",
		"stJob:url" 						=> "URL for External Job Management File",
		
		"photopress:camera"					=> "Camera"
				
		);
	}
		
}

?>