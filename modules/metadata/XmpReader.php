<?php 

namespace PhotoPress\modules\metadata;
use pp_api;
	
class XmpReader {
	
	
	// raw xmp array
	var $xmp		= [];
	// flattened xmp array
	var $flat_xmp;
	var $iptc 		= [];
	var $exif 		= [];
	var $labels 	= [];
	var $exif_tags	= [
		'title',
		'ImageDescription',
		'Artist',
		'Author',
		'Copyright',
		'FNumber',
		'Make',
		'Model',
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
	
	public function __construct() {
		
		$this->init();
	}
	
	private function init() {
		
		add_filter( 'photopress_metadata_tag_value', [ $this, 'registerShortcuts' ], 0, 3 );
	}
	
	public function registerShortcuts( $value = '', $tag, $xmp ) {
		
		switch ( $tag ) {
			
			case 'photopress:camera':
			
				return $this->getCamera();
				
				
			case 'photopress:stringOfKeywords':
			
				$keywords = $this->getXmp('dc:subject');
				$nkeywords = [];
				$child_taxonomy_delimiter = pp_api::getOption('core', 'metadata', 'custom_taxonomies_tag_delimiter') ?: ':';
				
				// drop keywords uses as child taxonomies.
				
				if ( $keywords ) {
					
					foreach ( $keywords as $k => $v ) {
						
						if ( ! strpos($v, $child_taxonomy_delimiter ) ) {
							
							$nkeywords[] = $v;
						}
					}
					
					return implode( ', ', $nkeywords); 
				}
		}
	}
	
	function getXmp( $name ) {
		
		// allows or tag name aliases
		$name = apply_filters( 'photopress_metadata_tag_name', $name );
		
		$all_xmp = $this->getAllXmp();
		
		$nvalue = '';
		
		if ( $all_xmp ) {
			
			// allows for shortcut tags
			$nvalue = apply_filters( 'photopress_metadata_tag_value', $nvalue, $name, $all_xmp );
			
			// if no shortcut is returned then try to pull the value from the xmp
			if ( ! $nvalue ) {
			
				if ( $all_xmp ) {
				
					if (is_array( $name ) ) {
					
						$names = array_flip( $name );
						
						$somevalues = array_intersect_key($all_xmp, $names );
						
						$nvalue = array();
						
						foreach ($somevalues as $k => $v) {
							
							$val = $this->formatKeyValue($k, $v);
						}
						
						$nvalue[$k] = $val;
						
					} else {
					
						if (array_key_exists($name, $all_xmp)) {
							
							$nvalue = $all_xmp[$name];
							
							// just in case the value is a loner value in an array
							if (is_array($nvalue) && (count($nvalue) < 2)) {
							
								$nvalue = $nvalue[0];
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
		if ( $bucket ) {
			$url = $bucket['Iptc4xmpCore:CiUrlWork'];
		}
		
		return $url;
	}

	function getRightsStatement() {
			
		// get rights statement
		$rights = $this->getXmp('xmpRights:UsageTerms');
		if ( $rights ) {
			$rights = $rights[0];
		}
				 
		return $rights;
	}
	
	function getCamera() {
		
		$camera = '';
		
		if ( $this->getExif('Make') && $this->getExif('Model') ) {
		
			$camera = $this->getExif('Make') . ' ' . $this->getExif('Model');
		}
		
		if (! $camera ) {
			
			$this->getXmp('tiff:Model');
		}
		
		return $camera;
	}
	
	function getLens() {

		return $this->getXmp('aux:Lens');
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
		
		$md = unserialize($str);
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
	
	function flattenXmp($xmp) {
	
		$nxmp = array();
		
		if ( $xmp ) {
		
			foreach ($xmp as $k => $v) {
			
				if ($k === 'rdf:Description') {
					$nxmp = array_merge($v, $nxmp);
				} else {
					$nxmp[$k] = $v;
				}
			}
		}
		
		return $nxmp;
	}
	
	function extractXmp($file) {

		$xml_array = array();
		//TODO:Require a lot of memory, could be better
		ob_start();
		@readfile($file);
		$source = ob_get_contents();
		ob_end_clean();
		$source;
		$start = strpos( $source, "<x:xmpmeta"   );
		$end   = strpos( $source, "</x:xmpmeta>" );
		if ((!$start === false) && (!$end === false)) {
			$lenght = $end - $start;
			$xmp_data = substr($source, $start, $lenght+12 );
			unset($source);
			//print_r($xmp_data);
			$xml_array = $this->XMP2Array($xmp_data);
		} 
		
		unset($source);
		return $xml_array;
	}
	
	function XMP2array($data) {
				
		$parser = xml_parser_create();
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0); // Dont mess with my cAsE sEtTings
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1); // Dont bother with empty info
		xml_parse_into_struct($parser, $data, $values);
		xml_parser_free($parser);
		//print_r($values);
		
		$xmlarray			= array();	// The XML array
		$xmp_array  		= array();	// The returned array
		$stack        		= array();	// tmp array used for stacking
		$list_array   		= array();	// tmp array for list elements
		$list_element 		= false;	// rdf:li indicator
		$temp_attr 			= array();
		$last_open_tag 		= '';
		
		foreach($values as $val) {
			
		  	if($val['type'] === "open") {
		  		
		  			
			      	if ( array_key_exists('attributes', $val) &&  $val['attributes'] ) {
			      		$temp_attr[$val['tag']] = $val['attributes'];
			      	} else {
			      		array_push($stack, $val['tag']);
			      	}	
			      	$last_open_tag = $val['tag'];
		  		
		      	
		      	
		    } elseif($val['type'] === "close") {
		    	// reset the compared stack
		    	if ($list_element == false) {
		    		if ( ! array_key_exists('value', $stack) || !$stack['value']) {
		    			if (array_key_exists($val['tag'], $temp_attr)) {
		    				$xmlarray[$val['tag']] = $temp_attr[$val['tag']];
		    			}
		      				
		      		}
		      	}
		      	$last_open_tag = '';
		      	array_pop($stack);
		      	// reset the rdf:li indicator & array
		      	$list_element = false;
		      	$list_array   = array();
		      	
		    } elseif($val['type'] === "complete") {
				if ($val['tag'] === "rdf:li") {
					// first go one element back
					if ($list_element == false)
						array_pop($stack);
						
					$list_element = true;
					// save it in our temp array
					if (array_key_exists('value', $val)) {
						$list_array[] = $val['value'];
					}
					//print_r( $val['value']);
					// in the case it's a list element we seralize it
					//$value = implode(",", $list_array);
					$this->setArrayValue($xmlarray, $stack, $list_array);

					
		      	} else {
		      		array_push($stack, $val['tag']);
		      		if (array_key_exists('value', $val)) {
		      			$this->setArrayValue($xmlarray, $stack, $val['value']);
		      		} elseif (array_key_exists('attributes', $val)){
		      			$xmlarray[$val['tag']] = $val['attributes'];
		      		}
		      		array_pop($stack);
		      	}
		    }
		    
		} // foreach
		
		// cut off the useless tags
		$strip_keys = array('x:xmpmeta','rdf:RDF');
		
		foreach ($strip_keys as $k) {
			unset($xmlarray[$k]);
		}
		
		return $xmlarray;
	}
		
	function setArrayValue(&$array, $stack, $value) {
	
		if ($stack) {
			$key = array_shift($stack);
			//print $key;
			//TODO:Review this, reports sometimes a error "Fatal error: Only variables can be passed by reference" (PHP 5.2.6)
			
	    	$this->setArrayValue($array[$key], $stack, $value);
	    	
	    	return $array;
	  	} else {
	    	$array = $value;
	    	
	    	
	  	}
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
		
		if ( array_key_exists( $str, $this->labels ) ) {
		
			return $this->labels[ $str ];
			
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
		
		// These are not in spec but Photoshop CS seems to use them
		"xap:Advisory" 						=> "Externally Editied Properties",
		"xap:BaseURL" 						=> "Base URL for relative URL's",
		"xap:CreateDate" 					=> "Original Creation Date",
		"xap:CreatorTool" 					=> "Creator Tool",
		"xap:Identifier" 					=> "Identifier(s)",
		"xap:MetadataDate" 					=> "Metadata Last Modify Date",
		"xap:ModifyDate" 					=> "Resource Last Modify Date",
		"xap:Nickname" 						=> "Nickname",
		"xap:Thumbnails" 					=> "Thumbnails",
		"xapidq:Scheme" 					=> "Identification Scheme",
		
		"xapRights:Certificate"			 	=> "Certificate",
		"xapRights:Copyright" 				=> "Copyright",
		"xapRights:Marked" 					=> "Marked",
		"xapRights:Owner" 					=> "Owner",
		"xapRights:UsageTerms" 				=> "Legal Terms of Usage",
		"xapRights:WebStatement" 			=> "Web Page describing rights statement (Owner URL)",
		
		"xapMM:ContainedResources" 			=> "Contained Resources",
		"xapMM:ContributorResources" 		=> "Contributor Resources",
		"xapMM:DerivedFrom" 				=> "Derived From",
		"xapMM:DocumentID" 					=> "Document ID",
		"xapMM:History" 					=> "History",
		"xapMM:LastURL" 					=> "Last Written URL",
		"xapMM:ManagedFrom"		 			=> "Managed From",
		"xapMM:Manager" 					=> "Asset Management System",
		"xapMM:ManageTo" 					=> "Manage To",
		"xapMM:xmpMM:ManageUI" 				=> "Managed Resource URI",
		"xapMM:ManagerVariant" 				=> "Particular Variant of Asset Management System",
		"xapMM:RenditionClass" 				=> "Rendition Class",
		"xapMM:RenditionParams"		 		=> "Rendition Parameters",
		"xapMM:RenditionOf" 				=> "Rendition Of",
		"xapMM:SaveID" 						=> "Save ID",
		"xapMM:VersionID" 					=> "Version ID",
		"xapMM:Versions" 					=> "Versions",
		
		"xapBJ:JobRef" 						=> "Job Reference",
		
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
		"exif:CFAPattern" 					=> "Colour Filter Array Pattern",
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
		
		"xapGImg:height"	 				=> "Height",
		"xapGImg:width" 					=> "Width",
		"xapGImg:format" 					=> "Format",
		"xapGImg:image" 					=> "Image",
		
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