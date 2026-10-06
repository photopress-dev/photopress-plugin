=== PhotoPress  ===

Contributors: padams
Donate link: http://www.photopressdev.com
Tags: photos, images, gallery, gallery block, masonry, meta-data, photopress, image taxonomies, EXIF, XMP, IPTC, gutenberg
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Requires at least: 6.6
Tested up to: 7.0
Stable tag: 1.7.0

Making WordPress work for photographers with beautiful image galleries, slideshows, meta-data tools, and more.

== Description ==

PHOTOPRESS is an integrated suite of image management and gallery presentation features that photographers can use to build photography centric websites. The plugin allows you to create beautiful designed image galleries as well as extract, store and publish any EXIF/IPTC/XMP meta-data embedded in your images. 

The broader goal of PHOTOPRESS is to make WordPress easy to use for photographers by bringing critical image management and presentation features together into a single, modern, and free plugin. Features currently include:

= GALLERY BLOCK =

* Native Gutenberg live editing
* Grid style
* Masonry style
* Justified style
* Mosaic Style
* Adjustable gutter spacing
* Uniform image cropping option
* Hide captions option
* Adjustable image heights/column widths
* Inline image reordering
* Dynamic responsive images
* Link to PhotoPress slideshow

= CHILD PAGES BLOCK =

* Dynamic Gutenberg block
* Create a gallery of child pages (useful as an index of gallery pages)

= IMAGE META-DATA MANAGEMENT =

* Define unlimited custom image taxonomies
* Extract embedded EXIF, IPTC, and XMP meta-data from image files and store in taxonomies
* Create and extract "child taxonomies" from embedded meta-data fields
* Display Exif Widget
* Display Image Taxonomy Terms Widget
* Generate custom image ALT text using meta-data templates
* Embed Licensing info (Licensor, Licensor URL, Web Statement of Rights) into images files during upload

= SLIDESHOWS =

* Light-boxed full page slideshows
* Thumbnail navigation option
* configurable caption display (can use image title, caption, and/or description)
* Two caption layouts to choose from 

== Screenshots ==

1. Customizable Gallery Block with multiple styles
2. Full-screen Slideshows with customizable caption placement
3. Store embedded image meta-data into custom image taxonomies
4. Generate image ALT text with meta-data templates
5. Embed Licensing meta-data into uploaded images

== Changelog ==

= 1.7.0 =

* New: Masonry, Rows and Mosaic layouts for the WordPress Gallery block, as
  block variations. They look the same in the editor as on the site. Existing
  PhotoPress galleries keep working, and can be converted from the block's
  Transform menu.
* New: the full-screen slideshow can be turned on for any Gallery block, and
  image captions can be hidden.
* New: Gallery Slideshow block, an inline slideshow of a gallery on the same
  page. Clicking an image in the gallery shows it in the slideshow, with a
  button to return to the gallery. Captions can go below, left or right of
  the image, with adjustable padding. "Hide the gallery" shows visitors only
  the slideshow.
* New: Image Taxonomies block, the block version of the "Display Taxonomies"
  widget, for block themes. Choose which taxonomies to show and whether terms
  link to their archives.
* Full-screen slideshow: opens at once on the image that was clicked, rather
  than after about two seconds. With a mouse, clicking anywhere on the left or
  right half goes back or forward, with a large arrow showing which. A new
  Caption Padding setting. Wide images no longer run under the arrows.
* Full-screen slideshow fixes: several slideshow galleries on one page now
  work independently; clicking an image in a second gallery opens that image;
  galleries without the slideshow are no longer taken over; arrow keys only
  act while the slideshow is open; closing no longer leaves an invisible layer
  over the page; images with a single size no longer show as broken
  thumbnails.
* Metadata: a rewritten XMP reader that keeps values it used to lose (some
  locations, credits, licensing and contact details) and reads Extended XMP.
* Alt text on upload no longer produces ". ." when a template's fields are
  empty, and hierarchical keywords such as "person:Name" contribute their
  value.
* Settings are validated by type when saved, and a failed save shows why.
* Security: slideshow settings are escaped in the page. The image taxonomies
  are available to the block editor for users who can edit posts, and remain
  hidden from the public REST API.
* Fixed: a fatal error when the plugin was installed from source without
  building its scripts.

= 1.6.0 =

* Requires WordPress 6.6 or later.
* Uploads use far less memory and time reading image metadata. Only the part
  of the file that holds the metadata is read, rather than the whole image.
* The block editor scripts are rebuilt with the current WordPress tooling. The
  editor script is about 25 times smaller, and it now uses the copies of React
  and the WordPress libraries already loaded by WordPress.
* Fixed: the camera name was left blank when it was recorded only in XMP
  metadata and not in EXIF.
* Fixed: metadata was ignored in files that begin with an XMP packet, such as
  .xmp sidecar files.
* Fixed: a PHP 8 deprecation notice raised on every upload.

= 1.5.2 =

* Fixed: uploads failed with "The server cannot process the image" whenever
  licence information was configured. This was a 1.5.1 regression; images that
  failed to upload can simply be uploaded again.

= 1.5.1 =

* Licence embedding no longer requires the ExifTool binary. It now uses Imagick,
  which WordPress already relies on for image handling. This removes about 26MB
  from the plugin and means licence embedding works on hosts that disable exec().
* Existing metadata is preserved when licence information is written. Titles,
  captions, keywords, ratings and any other XMP a photographer had embedded are
  kept intact; previously the licence fields were the only thing that could be
  written without risking the rest.
* Fixed: the Web Statement of Rights setting was ignored and a placeholder value
  was embedded instead. If you have that setting configured, re-upload affected
  images to correct them.
* Fixed: a settings field using the "none" control registered without a valid
  type.
* Fixed: a PHP 8 deprecation notice raised when embedding licence metadata.

== Development ==

Our development motto is "do no harm" which means that we leverage the patterns outlined in WordPress Core and the Gutenberg editor as opposed to creating proprietary features that impede the overall usability of WordPress.

PHOTOPRESS is actively developed on [Github](https://github.com/photopress-dev/photopress-plugin). Please file any bugs, feature, or support requests on Github!

== Donate or Purchase Premium Support! ==

PHOTOPRESS core is free. However, we ask that you [purchase a support membership](http://www.photopressdev.com). Even if you don't need the support, this purchase helps fund the development of this project. Donations to the project are also appreciated.