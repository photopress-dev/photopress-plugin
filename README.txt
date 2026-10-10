=== PhotoPress  ===

Contributors: padams
Donate link: https://github.com/sponsors/padams
Tags: photos, images, gallery, gallery block, masonry, meta-data, photopress, image taxonomies, EXIF, XMP, IPTC, gutenberg
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Requires at least: 6.6
Tested up to: 7.1
Stable tag: 1.9.0

Making WordPress work for photographers with beautiful image galleries, slideshows, meta-data tools, and more.

== Description ==

PHOTOPRESS is an integrated suite of image management and gallery presentation features that photographers can use to build photography centric websites. The plugin allows you to create beautiful designed image galleries as well as extract, store and publish any EXIF/IPTC/XMP meta-data embedded in your images. 

The broader goal of PHOTOPRESS is to make WordPress easy to use for photographers by bringing critical image management and presentation features together into a single, modern, and free plugin. Features currently include:

= GALLERY LAYOUTS =

Masonry, Rows and Mosaic layouts for the WordPress Gallery block, as block variations:

* Masonry: columns of a width you set, each image at its own height
* Rows: rows of a height you set, each image at its own width
* Mosaic: rows that fill the gallery's width, each image at its own proportions
* They look the same in the editor as on the site
* Open the full-screen slideshow when an image is clicked
* Hide captions option

= FULL-SCREEN SLIDESHOW =

* Opens over the whole window on the image that was clicked
* Click the right half for the next image, the left half for the previous one; the arrow keys work too
* Thumbnail navigation option, with adjustable height
* Configurable caption display (image title, caption and/or description, and a link to the attachment page)
* Caption box below or to the right of the image, with adjustable padding

= GALLERY SLIDESHOW BLOCK =

* A slideshow of a gallery on the same page, placed anywhere on the page
* Clicking an image in the gallery shows it in the slideshow
* Press the right side of the slideshow for the next image, the left side for the previous one
* Slide or fade transitions, and autoplay
* Fits the window's height, less an offset you set
* Captions below, left or right of the image, with adjustable padding
* Caption max width, a share of the slideshow's width (75% by default) that keeps the full width on phones
* Option to hide the gallery and show only the slideshow
* Works with dynamic galleries (WordPress 7.1)

= CHILD PAGES BLOCK =

* Dynamic Gutenberg block
* Create a gallery of child pages, from their featured images (useful as an index of gallery pages)

= IMAGE META-DATA MANAGEMENT =

* Define unlimited custom image taxonomies
* Extract embedded EXIF, IPTC, and XMP meta-data from image files and store in taxonomies
* Create and extract "child taxonomies" from embedded meta-data fields
* Image Taxonomies block: an image's terms, such as keywords, people, places and camera, for block themes
* Display Exif Widget
* Display Image Taxonomy Terms Widget
* Generate custom image ALT text using meta-data templates
* Generate image descriptions using meta-data templates
* Embed Licensing info (Licensor, Licensor URL, Web Statement of Rights) into images files during upload
* Re-read the meta-data of every image in the background, with its progress on the settings page, after changing taxonomies or templates

= OFFLOAD MEDIA =

With WP Offload Media installed, the Offload Media settings tab shows:

* Whether Offload Media is active, and where images are stored and served from
* How long browsers and the CDN keep offloaded images (by default 1 day, then 1 hour while checking for a new one)
* A button to clear the CloudFront cache

== Screenshots ==

1. Masonry layout for the Gallery block
2. Rows layout for the Gallery block
3. Mosaic layout for the Gallery block
4. Full-screen slideshow, opened from a gallery: click either half to move through the images
5. Slideshow settings
6. Gallery Slideshow block: press either side of the slideshow to move through a gallery on the same page
7. Gallery Slideshow captions at 75% of the slideshow's width on a desktop, and the full width on a phone
8. Child Pages block: a gallery of a page's child pages
9. Image Taxonomies block on an image's page
10. Store embedded image meta-data into custom image taxonomies
11. Generate image alt text and descriptions with meta-data templates
12. Embed licensing meta-data into uploaded images
13. Re-read the meta-data of every image in the background, with its progress
14. Offload Media tab: where images are stored and served from, their cache lifetime, and clearing the CloudFront cache

== Changelog ==

= 1.9.0 =

* Image Sizes tab: the quality JPEG and WebP sizes are saved at, 92 by
  default. WordPress's own is 82, which can show on detailed photographs;
  sizes made after updating are at 92 unless set otherwise. Every
  registered image size is listed, from WordPress, the theme and plugins,
  with a switch to stop making it. "Regenerate image sizes" makes every
  image's sizes again from its original in the background, a few images at
  a time, resting between batches and waiting while the server is busy.
* License embedding writes the license into an image's metadata without
  re-encoding the image, for JPEG, PNG, WebP, GIF, TIFF, HEIC and AVIF, so
  uploads keep the pixels they were exported with. Imagick is used only
  where that is not possible, and is no longer required.
* License embedding replaces a Web Statement the file already has, rather
  than adding a second, and keeps the file's Web Statement or Licensor when
  no setting replaces it.
* Masonry and Mosaic galleries no longer move as a page loads: they are
  laid out before the page is first shown. Masonry no longer loads the
  masonry and imagesloaded scripts.
* Gallery images, and Gallery Slideshow images, download a file of the
  size they are shown at, rather than one for the full width of the window.
* The full-screen slideshow no longer enlarges an image beyond its own
  size, and shows images that have only one size.
* Gallery block: the Columns setting is hidden under the Masonry, Rows and
  Mosaic layouts, which set their own, and a gallery can be switched back
  to the plain gallery from the block's variation switcher.

= 1.8.0 =

* Gallery Slideshow: a Caption max width setting, a share of the
  slideshow's width, 75% by default. Captions stay at least about 50
  characters wide, and the full width on phones. Existing slideshows get
  the default; set it to 0 for captions as wide as the slideshow. Where the
  window's height limits an image, the image gives up the height a
  narrower caption needs.
* Gallery Slideshow: works with dynamic galleries (WordPress 7.1), showing
  the images attached to the post.
* Meta-data tab: "Re-read image metadata" re-reads every image's
  taxonomies, alt text and description from its file. It runs in the
  background in batches, with its progress and a Cancel button on the tab.
* Offload Media tab, where WP Offload Media is installed: its status, where
  files are stored and served from, how long browsers and the CDN keep
  offloaded images (by default 1 day, then 1 hour while checking for a new
  one), and a button to clear the CloudFront cache.
* Fixed: the Slideshow tab had no Caption Padding field, and the Meta-data
  tab no Description template field.

= 1.7.3 =

* Fixed: in the Mosaic gallery layout, some images hung over the row below
  at wider window widths. Each image now fills its place in the row exactly,
  at its own proportions.

= 1.7.2 =

* Fixed: in the Gallery Slideshow, the previous image briefly showed behind
  the new one after clicking to the next slide.
* Gallery Slideshow: the slideshow keeps one height for every slide, as tall
  as its tallest image at full width, so the page below does not move. That
  height, caption included, now stays within the visible window (on phones,
  the height visible with the browser bars shown). Shorter images are
  centered in it.
* Fixed: the full-screen slideshow's Caption Padding setting had no field on
  the Slideshow settings tab.

= 1.7.1 =

* Fixed: on sites with a page cache, pages cached before updating to 1.7.0
  could load the new slideshow script with the old page, and clicking a
  gallery image no longer opened the full-screen slideshow until the cache
  expired. Those pages now work as they did before the update.

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
  license information was configured. This was a 1.5.1 regression; images that
  failed to upload can simply be uploaded again.

= 1.5.1 =

* License embedding no longer requires the ExifTool binary. It now uses Imagick,
  which WordPress already relies on for image handling. This removes about 26MB
  from the plugin and means license embedding works on hosts that disable exec().
* Existing metadata is preserved when license information is written. Titles,
  captions, keywords, ratings and any other XMP a photographer had embedded are
  kept intact; previously the license fields were the only thing that could be
  written without risking the rest.
* Fixed: the Web Statement of Rights setting was ignored and a placeholder value
  was embedded instead. If you have that setting configured, re-upload affected
  images to correct them.
* Fixed: a settings field using the "none" control registered without a valid
  type.
* Fixed: a PHP 8 deprecation notice raised when embedding license metadata.

== Development ==

Our development motto is "do no harm" which means that we leverage the patterns outlined in WordPress Core and the Gutenberg editor as opposed to creating proprietary features that impede the overall usability of WordPress.

PHOTOPRESS is actively developed on [Github](https://github.com/photopress-dev/photopress-plugin). Please file any bugs, feature, or support requests on Github!
