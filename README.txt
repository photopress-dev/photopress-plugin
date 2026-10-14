=== PhotoPress  ===

Contributors: padams
Donate link: https://github.com/sponsors/padams
Tags: photos, images, gallery, gallery block, masonry, meta-data, photopress, image taxonomies, EXIF, XMP, IPTC, gutenberg
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Requires at least: 6.6
Tested up to: 7.1
Stable tag: 2.0.0

Making WordPress work for photographers with beautiful image galleries, slideshows, meta-data tools, and more.

== Description ==

PHOTOPRESS is an integrated suite of image management and gallery presentation features that photographers can use to build photography centric websites. The plugin allows you to create beautiful designed image galleries as well as extract, store and publish any EXIF/IPTC/XMP meta-data embedded in your images. 

The broader goal of PHOTOPRESS is to make WordPress easy to use for photographers by bringing critical image management and presentation features together into a single, modern, and free plugin. Features currently include:

= GALLERY LAYOUTS =

PhotoPress adds Masonry, Rows and Mosaic layouts to the WordPress Gallery block.

* Masonry arranges images in columns of a width you set. Each image keeps its own height.
* Rows arranges images in rows of a height you set. Each image keeps its own width.
* Mosaic fills each row to the gallery's width. Each image keeps its own proportions.
* Any gallery can open the full-screen slideshow when an image is clicked.
* Images load at the size the layout shows them, so pages load faster.
* An image deleted from the Media Library is removed from the galleries that show it.

Documentation: [Gallery Layouts](https://github.com/photopress-dev/photopress-plugin/wiki/Gallery-Layouts).

= FULL-SCREEN SLIDESHOW =

* Clicking an image in a gallery opens it in a slideshow that fills the window.
* Visitors move through the images by clicking either half of the image, swiping or using the arrow keys.
* It can show a row of thumbnails, and the image's caption, title, description and a link to its page.

Documentation: [Full-Screen Slideshow](https://github.com/photopress-dev/photopress-plugin/wiki/Full-Screen-Slideshow).

= GALLERY SLIDESHOW BLOCK =

* The Gallery Slideshow block shows a gallery on the same page as a slideshow, placed anywhere on the page.
* Clicking an image in the gallery shows it in the slideshow.
* It can show thumbnails, captions and autoplay, with a slide or fade between images.
* It can hide the gallery so only the slideshow shows.

Documentation: [Gallery Slideshow Block](https://github.com/photopress-dev/photopress-plugin/wiki/Gallery-Slideshow-Block).

= CHILD PAGES GALLERY =

* The Child Pages Gallery block shows the pages under a page as a gallery, each page as an image linked to it. It makes a good index of gallery pages.
* Updating PhotoPress converts the older Child Pages block to a Child Pages Gallery.

Documentation: [Child Pages Gallery](https://github.com/photopress-dev/photopress-plugin/wiki/Child-Pages-Gallery).

= IMAGE METADATA MANAGEMENT =

* Image taxonomies: PhotoPress files each uploaded photo under its camera, lens, location, keywords and other metadata, with an archive page for each.
* You choose which metadata fields become taxonomies, including keyword hierarchies from Lightroom or Capture One and any IPTC field.
* The Image Metadata block shows an image's terms and exposure details on its page.
* Alt text and descriptions are written from each photo's metadata when it is uploaded.
* Licensing metadata is embedded in your image files and published as structured data on the pages that show them.
* Changes can be applied to the images already in the Media Library, in the background.

Documentation: [Image Taxonomies](https://github.com/photopress-dev/photopress-plugin/wiki/Image-Taxonomies), [Alt Text and Descriptions](https://github.com/photopress-dev/photopress-plugin/wiki/Alt-Text-and-Descriptions), [Licensing](https://github.com/photopress-dev/photopress-plugin/wiki/Licensing).

= IMAGE TEMPLATES =

PhotoPress adds two templates to block themes: Image Archives and Image Pages. They need WordPress 6.7 or later. If the theme has its own template for these pages, the theme's is used.

* Image Archives shows a keyword's, camera's or other term's images in a gallery, with links to more pages.
* Image Pages shows an image with its caption, description, terms and exposure details.
* You can edit both templates in the Site Editor.
* The Taxonomy Gallery block shows the latest images, or the images of a chosen term, in any PhotoPress layout.

Documentation: [Image Templates and Blocks](https://github.com/photopress-dev/photopress-plugin/wiki/Image-Templates-and-Blocks), [Taxonomy Gallery](https://github.com/photopress-dev/photopress-plugin/wiki/Taxonomy-Gallery).

= IMAGE AND TAXONOMY PAGES =

* Attachment pages: a switch that publishes, or stops publishing, a page for each image in the Media Library.
* Image taxonomy pages: a switch that publishes, or stops publishing, an archive page of images for each keyword, camera and other term.

Documentation: [Attachment and Taxonomy Pages](https://github.com/photopress-dev/photopress-plugin/wiki/Attachment-and-Taxonomy-Pages).

= SEARCH =

* Images in search results: a switch that adds images to the site's search results, found by their titles, captions, alt text and terms.
* Block themes get an Image Search template that shows the images found in a gallery and lists the matching posts and pages below it.
* With [Relevanssi](https://wordpress.org/plugins/relevanssi/), PhotoPress has Relevanssi index images with their alt text and taxonomy terms.

Documentation: [Image Search](https://github.com/photopress-dev/photopress-plugin/wiki/Image-Search).

= SITEMAPS =

* PhotoPress adds image pages and image taxonomy archive pages to the site's sitemap, whether it is WordPress's own, [Jetpack](https://wordpress.org/plugins/jetpack/)'s or [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/)'s.

Documentation: [Sitemaps](https://github.com/photopress-dev/photopress-plugin/wiki/Sitemaps).

= PAGE CACHE =

On a site with a Varnish page cache and the [Proxy Cache Purge](https://wordpress.org/plugins/varnish-http-purge/) plugin, PhotoPress purges the cached pages that show an image, a gallery or an image taxonomy term when it changes.

Documentation: [Page Cache](https://github.com/photopress-dev/photopress-plugin/wiki/Page-Cache).

= IMAGE SIZES =

* You set the quality WordPress saves JPEG and WebP images at.
* You can turn off image sizes your site does not use, so WordPress stops making them.
* Save and regenerate updates the images already uploaded, in the background.

Documentation: [Image Sizes](https://github.com/photopress-dev/photopress-plugin/wiki/Image-Sizes).

= OFFLOAD MEDIA =

These features need the [WP Offload Media](https://wordpress.org/plugins/amazon-s3-and-cloudfront/) plugin.

* You set how long browsers and the CDN cache offloaded images.
* A replaced image is cleared from CloudFront, so visitors see the new file.
* A replaced image's old files can be deleted from cloud storage.

Documentation: [Offload Media and CDN](https://github.com/photopress-dev/photopress-plugin/wiki/Offload-Media-and-CDN).

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

= 2.0.0 =

PhotoPress 2.0 removes the legacy Child Pages block. After the update, a background job converts each one on your site into a Child Pages Gallery.

**Metadata**

* The Metadata tab has a card for each of Image Taxonomies, Alt Text, Description and Licensing. Each card has its own switch.
* Each card has a "Save and reprocess all images" button that applies a change to the images you have already uploaded.
* Reprocessing works through ten images at a time and rests between batches. It rests longer while the server is busy.
* Image taxonomies are listed in three tables: Standard Metadata, Hierarchical Keyword Metadata and Custom Metadata. Each taxonomy's term count links to its terms under Media.
* You add and edit a taxonomy on its own screen, which previews the terms a photo would get.
* A Custom Metadata taxonomy can read any IPTC field. You choose the field from a list grouped the way photo software shows it.
* A taxonomy's Public switch makes it admin only. Its terms stay in the Media Library, but it has no public pages.
* After you change a taxonomy, PhotoPress offers to reprocess only the images the change affects.
* Keyword hierarchies are kept as nested terms. Parent keywords are matched in any case, and camera names are cleaned up. Reprocessed images may get different terms than before.
* You build the alt text and description templates from the photo's metadata fields, and the screen shows an example of the result.
* Alt text uses the Alt Text (Accessibility) field that Lightroom Classic and Photoshop write, when the file has one.
* Alt text and descriptions are set on upload and on replace, even when the image taxonomies are off.
* The license is embedded in uploads and given in image pages' structured data only while Licensing is on and its name, URL and web statement are filled in. In 1.9.0 the structured data was given with the switch off.
* An image whose license could not be written into its file says so, and why, in the Media Library.
* New sites start without a People parent keyword.

**Galleries**

* Child Pages Gallery is a new Gallery block variation. It shows the pages under a page, in any PhotoPress layout.
* The legacy Child Pages block is removed. The converted galleries keep its columns, image size, crop, alignment and spacing. Pages that have no image are left out.
* A Child Pages block the job does not reach, such as one in a theme's template file, is shown as a Child Pages Gallery.
* The Gallery tab has a switch for each of the Masonry, Rows and Mosaic layouts. A layout you turn off is no longer offered in the editor, and galleries that already use it keep it.
* When you delete an image from the Media Library, PhotoPress removes it from every gallery that shows it. A post that someone has open in the editor is left until they are done. You can turn this off in the Gallery tab.
* Each gallery image downloads a file of the size its layout shows it at.
* Rows galleries no longer reflow as their images load.
* Masonry, Rows and Mosaic galleries of one or two images now use their layout.

**Slideshows**

* The full-screen slideshow now uses the Gallery Slideshow block's script. It no longer needs jQuery or Owl Carousel, and its script is 5 KB compressed instead of about 59 KB.
* The full-screen image takes the whole width of the screen. Click, tap or swipe either half to move.
* The thumbnails are a scrolling row under the image. The last image leads back to the first.
* The full-screen slideshow settings have separate switches for the caption, title, description and a link to the image's page.
* A Ctrl, Cmd, Shift or Alt click on a linked image opens the link.
* The Gallery Slideshow block can show a row of thumbnails, at a height you choose.
* Gallery Slideshow autoplay pauses while the mouse pointer is over the slideshow.
* Fixed: links in slideshow captions could not be clicked.

**Image and Taxonomy Pages**

* The Pages & Search tab has a switch that publishes, or stops publishing, a page for each image in the Media Library. A second switch does the same for the archive pages of image taxonomy terms.
* Block themes get PhotoPress templates for these pages. A taxonomy archive shows its images in a Taxonomy Gallery. An image page shows the image, its caption and description, its terms and its exposure details.
* A theme's own templates for these pages are used instead of PhotoPress's when the theme has them.
* The Image Metadata block, formerly Image Taxonomies, also shows the exposure details WordPress read from the file.
* In the block widgets editor, the old Display Taxonomies and Display Image Exif widgets turn into Image Metadata blocks.
* Fixed: the archive pages of any custom taxonomy, including other plugins' taxonomies, listed draft and private posts. Image taxonomy archives now list only public images.

**Search**

* Images in search results is a new switch in the Pages & Search tab, off by default. While it is on, searches find images by their titles, captions, alt text and terms.
* In block themes, the Image Search template shows the matching images in a gallery and the matching posts and pages below them.
* With [Relevanssi](https://wordpress.org/plugins/relevanssi/), PhotoPress turns on Media, the image taxonomies and image files in Relevanssi's indexing settings. Turning images off turns off only what PhotoPress turned on.
* With Relevanssi, an image is indexed again when its terms change. A background job adds the existing images, and the Pages & Search tab has a Re-index all images button.
* With Relevanssi, the posts in which a replaced image's links or text were updated are indexed again.

**Sitemaps**

* Image pages and image taxonomy archive pages are listed in the sitemap your site uses: WordPress's, [Jetpack](https://wordpress.org/plugins/jetpack/)'s or [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/)'s. The Pages & Search tab shows which.

**Page Cache**

* With a Varnish page cache and [Proxy Cache Purge](https://wordpress.org/plugins/varnish-http-purge/), PhotoPress purges the pages that show an image when the image changes. These are its own page, its terms' archives, and the posts whose galleries show it.
* The whole cache is emptied once when a background job ends, and once after PhotoPress is updated.

**Image Sizes and Files**

* The Image Sizes tab has Save, and Save and regenerate. A regenerate makes only what the change needs.
* Regenerating an image's sizes saves the image only once every size is made. If one fails, the image is left as it was.
* Replacing an image's file keeps the old files until the new sizes are made. If a size fails, the image is put back as it was.
* An image replaced with [Enable Media Replace](https://wordpress.org/plugins/enable-media-replace/) is handled like one replaced in PhotoPress. Its terms and alt text are read from the new file.
* ImageMagick's memory is capped whenever WordPress makes image sizes. The `photopress_imagick_limits` filter changes the caps.

**Offload Media**

* The regenerate and license jobs clear the files they rewrote from CloudFront when they end.
* A failed CloudFront invalidation is tried again five minutes later.
* The setting for how long an expired image may still be shown is gone. It is 5 minutes.
* On a network, only a network admin can clear everything from the CDN, and at most once in ten minutes.

**Background Jobs**

* Fixed: background jobs stopped after their first batch unless their settings page was open. Each batch now queues the next.
* An image that stops a batch, by running out of memory or time, is recorded as failed and skipped.
* Paced jobs wait while less than 300 MB of memory is available.
* The progress panel says when a job is waiting for a busy server or resting between batches.
* A job can safely run again. The license job leaves files that already have the license as they are.

**Performance**

* Action Scheduler is loaded only while PhotoPress has background work, so an idle site skips its every-minute WP-Cron run.
* A gallery's images are loaded in one query.
* Search without Relevanssi, random-order galleries, sitemaps, image deletion and term changes are faster on large sites.
* JPEG metadata is read faster.
* PhotoPress's settings scripts load only on its own screens. The block editor no longer warns about an old block API version.

**Security**

* The full-screen slideshow cleans the caption, title and description it shows, so they cannot run scripts.
* Replacing an image's file updates only the posts the user may edit.
* An admin-only taxonomy's terms are readable over the REST API only by users who can upload images.
* Damaged or crafted image files no longer exhaust PHP's memory or time while PhotoPress reads their metadata.
* When the license is embedded with Imagick, the upload is replaced only by a complete image.
* PHP files exit when requested directly.

**Settings and Lifecycle**

* A switch saves only its own setting. Two switches turned in quick succession are both saved.
* Deactivating PhotoPress unschedules its background work.
* Deleting PhotoPress deletes its settings and records. Images, their terms and their metadata stay.

**Developers**

* Adding an uploaded image to a Taxonomy or Child Pages Gallery over REST returns the error `photopress_gallery_generated`, because those galleries choose their images when the page is viewed.

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
