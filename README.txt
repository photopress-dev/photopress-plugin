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
* Each image downloaded at the width its layout shows it, not the width of the window
* An image deleted from the Media Library is taken out of the galleries that show it
* A Gallery settings tab with a switch for each layout and for removing deleted images, all on by default

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

= CHILD PAGES GALLERY =

* A Gallery block variation in any PhotoPress layout: the published pages under the page, in their page order, each as its featured image or else the first image of its gallery, linked to it with its title (useful as an index of gallery pages)
* The page is cleared from the page cache when a page under it is published, moved, renamed, reordered or given another image
* The older Child Pages block still shows where it was used; it is no longer offered, and turns into a Child Pages Gallery

= IMAGE METADATA MANAGEMENT =

* Image taxonomies filled from each photo's embedded EXIF, IPTC and XMP metadata on upload, each with its own archive pages
* Standard Metadata: camera, lens, city, state, country and keywords, read from wherever cameras and photo software record them, with camera names cleaned up and keyword hierarchies kept as nested terms. Each can be renamed or turned off
* Hierarchical Keyword Metadata: keywords under a parent keyword (Genre › Portraiture in Lightroom or Capture One, or genre: Portraiture) go to the parent keyword's own taxonomy, each level nested under the one above
* The parent keywords found in your photos that have no taxonomy are listed, each with a button to add one
* Custom Metadata: any other metadata field as a taxonomy, chosen from a list of IPTC fields, each with what it holds and where photo software shows it
* Adding or changing a taxonomy offers to reprocess just the images it affects, in the background, with the progress on the settings page
* A taxonomy can be for the admin only: its terms are kept and shown in the Media Library, with no archive pages on the site
* Image Metadata block: an image's terms, such as keywords, people, places and camera, and its exposure details from the file (aperture, shutter speed, ISO, focal length, date taken), in the order you choose
* The Display Taxonomies and Display Image Exif widgets, for classic widget areas; in the block widgets editor, placed ones transform into the Image Metadata block
* Alt text from the Alt Text (Accessibility) written in the file by Lightroom Classic or Photoshop, or from a template of words and metadata fields
* Image descriptions from a template of words and metadata fields
* Licensing metadata (Licensor, Licensor URL, Web Statement of Rights): embedded into image files on upload, or into every image already uploaded, and added as structured data (JSON-LD) to the pages that show them, with any theme
* Save and reprocess all images: applies a new template or licensing to the images already uploaded, in the background

= IMAGE TEMPLATES =

For block themes that have no templates of their own for them (WordPress 6.7 or later):

* Image archives: a keyword's, a camera's or any image taxonomy's archive page shows its images in the Masonry layout, with links to more pages
* Image pages: the image, its caption and description, its taxonomy terms and its exposure details
* Both are edited in the Site Editor, like the theme's own templates, and each is there while its kind of page is on in the Pages & Search tab
* Taxonomy Gallery, a Gallery block variation in any PhotoPress layout: on an image archive template, the archive's images; anywhere else, the latest images or those of a keyword, camera or other term. And the Attachment Image block
* An archive of an image taxonomy looks for a taxonomy-attachment template, as themes built on the Frame framework do

= PAGES & SEARCH =

* Attachment pages switch: each image has a page of its own, or its page address and links to it go to the image file (WordPress's own setting)
* Image taxonomy pages switch: each keyword, camera or other term has an archive page of its images, or none, with the terms still shown on image pages, unlinked
* Both on by default; while each is on, block themes get PhotoPress's template for those pages
* Images in search results switch (off by default): visitors' searches find images by their titles, captions, alt text and terms, with posts and pages too unless turned off. Block themes get an Image Search template of blocks, to order as you like: the search box, a Search Summary (the terms and the number of images found), the images in a Taxonomy Gallery in rows with each one's file name and title under it, with numbered pages, and the posts and pages found in a Search Posts block
* With Relevanssi, PhotoPress has it index images, their alt text and the image taxonomies (its settings show the same), keeps an image's entry current as its terms change or are renamed or deleted, and adds existing images to its index in the background
* Page cache and search index: whether Proxy Cache Purge and Relevanssi are active, installed or not installed, as the Offload Media tab shows WP Offload Media, with a Re-index all images button. Proxy Cache Purge purges a post's pages when it is saved, but not an image's, and nothing when terms change. Through its documented hooks, PhotoPress has it purge an image when the image is edited or deleted or its terms change: its page (while attachment pages are on) and every page of its terms' archives (while image taxonomy pages are on). A term renamed or deleted has its images and archive purged, or, with more than 25 images, the whole cache emptied. A post whose gallery gains or loses an image, and a replaced image with its files and the posts that show it, are purged too

= IMAGE SIZES =

* The quality JPEG and WebP sizes are saved at, 92 by default (WordPress's own is 82)
* Every image size registered by WordPress, the theme and plugins, with a switch to stop making the ones nothing uses
* Save and regenerate: makes again only what each image needs, in the background, a few images at a time and waiting while the server is busy

= OFFLOAD MEDIA =

With WP Offload Media installed, the Offload Media settings tab shows:

* Whether Offload Media is active, and where images are stored and served from
* How long browsers and the CDN cache offloaded images, 1 day by default
* A replaced image cleared from CloudFront a few seconds later, so the new one shows, and a button to clear the whole CDN
* An option to delete a replaced image's old files from cloud storage
* An image replaced with Enable Media Replace is treated as one replaced in PhotoPress: its terms and alt text read from the new file, cleared from CloudFront, and purged from the page cache with the posts that show it

== Frequently Asked Questions ==

= What are the image taxonomies' IDs? =

A taxonomy's ID is the name WordPress stores its terms under. Themes (taxonomy-{ID}.php templates), search plugins such as Relevanssi, the Image Metadata block and the widget refer to taxonomies by it. It never changes, even when the taxonomy is renamed.

The six Standard Metadata taxonomies always have these IDs: photos_camera, photos_lens, photos_city, photos_state, photos_country and photos_keywords.

Every other taxonomy, added in the settings, gets pp_ and its singular name: pp_genre for Genre, pp_person for a People parent keyword. Taxonomies made by earlier versions keep the IDs they have, such as photos_people for People.

== Screenshots ==

1. Masonry layout for the Gallery block
2. Rows layout for the Gallery block
3. Mosaic layout for the Gallery block
4. Full-screen slideshow, opened from a gallery: click either half to move through the images
5. Slideshow settings
6. Gallery Slideshow block: press either side of the slideshow to move through a gallery on the same page
7. Gallery Slideshow captions at 75% of the slideshow's width on a desktop, and the full width on a phone
8. Child Pages Gallery: a page's child pages, in the Rows layout
9. Image Metadata block on an image's page
10. Image taxonomies filled from the photos' metadata: Standard Metadata, Hierarchical Keyword Metadata and Custom Metadata
11. A parent keyword found in the photos given a taxonomy of its own, and the images it affects reprocessed
12. Alt text and descriptions from the photos' metadata
13. Licensing metadata embedded into images
14. Image Sizes tab: the quality images are saved at, and the sizes made
15. Offload Media tab: where images are stored and served from, their cache lifetime, and clearing replaced images from CloudFront
16. Gallery tab: the layouts offered in the editor, and removing deleted images from galleries
17. Image Archives template: a keyword's images in the Masonry layout
18. Pages & Search tab: attachment pages, image taxonomy pages, the sitemap in use, images in search results, and the page cache and search index
19. Search results with images: the Image Search template

== Changelog ==

= 1.10.0 =

* Metadata tab: Image Taxonomies, Alt Text, Description and Licensing, each
  a card with its switch. Each has Save, and Save and reprocess all images
  to apply a change to the images already uploaded, with the progress shown
  as soon as it is clicked.
* Image taxonomies: Standard Metadata, Hierarchical Keyword Metadata and
  Custom Metadata, each in a table with its term count and archive pages.
  Taxonomies are added and edited on screens of their own, with a preview of
  what a photo gets.
* Hierarchical Keyword Metadata: the parent keywords found in your photos
  that have no taxonomy are listed, each with a button to add one, or to add
  it to a taxonomy whose parent keyword it looks like. A parent keyword can
  be written more than one way (Also written as).
* Custom Metadata: the field is chosen from a list of IPTC fields, grouped
  as photo software shows them, with what each holds and a link to the IPTC
  guide. Parts of structured fields, such as the city of the location shown,
  can be chosen too, and coded fields are filed in words.
* A taxonomy can be made admin only with its Public switch: it has no
  archive or term pages, and the Image Taxonomies block and widget leave it
  out, while its terms are kept and shown in the Media Library and under
  Media. The tables' Public column shows which are.
* Each taxonomy's term count links to its terms under Media.
* Saving a taxonomy change offers to reprocess just the images it affects.
  Terms of images whose files have nothing for a taxonomy are kept unless
  Empty the terms is checked under Advanced options.
* Alt text and description templates: words typed around the photo's
  metadata fields, each shown as a pill and added from a list, with an
  example of what a photo gets.
* Alt text: the Alt Text (Accessibility) that Lightroom Classic and
  Photoshop write into the file is used when it has one, in place of the
  template.
* New sites start without a People parent keyword. A new taxonomy gets an
  ID and archive URL no other has.
* Image Sizes tab: Save, or Save and regenerate the images a change affects.
  Each image's record of the quality it was made at means a regenerate does
  only what it needs: a size turned on is made alone, and a new quality
  makes every size again from the original. A size turned off applies to new
  uploads; those already made are kept.
* Offload Media tab: plainer wording. Offloaded images are cached for the
  set time, then shown for up to 5 more minutes while a new copy is checked
  for; that second setting is gone.
* Settings: a switch saves only its own setting.
* Gallery block: an image deleted from the Media Library is taken out of
  every gallery that shows it, in an edit of each post, rather than left as
  a broken image. Galleries in the format of WordPress before 5.9 are left
  as they are.
* Gallery tab: switches for the Masonry, Rows and Mosaic layouts and for
  removing deleted images from galleries, all on by default. A layout turned off is no longer offered in the editor;
  galleries that already use it keep it.
* Pages & Search tab: switches for attachment pages (WordPress's own
  setting; off, an image's page and links to it go to the file) and for
  image taxonomy archive pages (off, terms are shown unlinked). Both are on
  by default, and while each is on, block themes get PhotoPress's template
  for those pages.
* Images in search results (Pages & Search tab, off by default): searches
  find images by their titles, captions, alt text and terms, and posts and
  pages too unless turned off. In block themes, the Image Search template
  shows the search terms and count (Search Summary block), the images in a
  Taxonomy Gallery with each one's file name and title under it, paged, and
  the posts and pages in a Search Posts block. The Taxonomy Gallery and
  Search Posts have optional headings. With Relevanssi, PhotoPress drives its index: images, alt
  text and the image taxonomies, an image indexed again when its terms
  change, a renamed or deleted term's images indexed again, and a background
  job that adds existing images when images are turned on.
* Page cache and search index (Pages & Search tab): whether Proxy Cache
  Purge and Relevanssi are active, installed or not installed, with a
  Re-index all images button. Through Proxy Cache Purge's documented hooks,
  PhotoPress has it purge an image when the image is edited or deleted or
  its terms change: its page, while attachment pages are on, and every page
  of its terms' archives, while image taxonomy pages are on. A term renamed
  or deleted has its images and archive purged, or, with more than 25
  images, the whole cache emptied. A post whose gallery gains or loses an
  image, and a replaced image with its files and the posts that show it,
  are purged too.
* Sitemap (Pages & Search tab): images' pages and image taxonomy terms'
  archive pages are listed in whichever sitemap the site uses, through its
  documented hooks, and the tab shows which. WordPress's: public images
  (unattached, or attached to a published post) while attachment pages are
  on; it lists terms itself. Jetpack's: term archives, and each image with
  its own page, or, without attachment pages, an unattached image with its
  first term's archive. Yoast SEO's: the images on each term's archive
  page, and, while it lists media pages, a sitemap of unattached images'
  pages, which it leaves out. Pages that are turned off are left out.
* Enable Media Replace: an image it replaces is treated as one replaced in
  PhotoPress (its terms and alt text read from the new file, CloudFront,
  the page cache), through its enable-media-replace-upload-done action.
* Relevanssi: posts in which a replaced image's links or text were updated
  are indexed again.
* Child Pages Gallery, a Gallery block variation: the published pages under
  the page, each as its featured image or else the first image of its
  gallery, linked with its title, in any PhotoPress layout; its page is
  cleared from the page cache as they change. The Child Pages block it
  supersedes now lists published child pages only (not drafts or attached
  images), with their images' srcset, and opens them in the same tab.
* Gallery layouts: each image's sizes attribute is the width its layout
  shows it at (a Masonry column; a Rows or Mosaic image at the row height),
  so browsers download smaller files; with lazy loading, WordPress's
  sizes="auto" goes before it.
* Image templates for block themes: image taxonomy archives show their
  images in the Masonry layout (Taxonomy Gallery, a Gallery block variation
  whose layout can be changed), and image pages show
  the image, its caption and description, its terms and its exposure details
  (Attachment Image block). A theme's own taxonomy-attachment or attachment
  template is used instead, and each is there while its kind of page is on
  in the Pages & Search tab.
* Image Metadata block (formerly Image Taxonomies): also shows the exposure
  details WordPress read from the file, in any order among the taxonomies.
  Rows line up in two columns.
* Widgets: in the block widgets editor, the Display Taxonomies and Display
  Image Exif widgets are no longer offered, and placed ones transform into
  the Image Metadata block. The Exif widget shows the details it is set to
  (it always showed ISO, aperture and camera), labelled and formatted
  (f/2.8, 1/250 s), and only on image pages.
* Fixed: on the archive pages of any custom taxonomy, including other
  plugins', every query on the page listed draft and private posts.
  Image taxonomy archives now list only public images: unattached, or
  attached to a published post.
* Fixed: Masonry, Rows and Mosaic galleries of one or two images were laid
  out in WordPress's columns instead.

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
