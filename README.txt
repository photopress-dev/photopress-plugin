=== PhotoPress  ===

Contributors: padams
Donate link: https://github.com/sponsors/padams
Tags: photos, images, gallery, gallery block, masonry, meta-data, photopress, image taxonomies, EXIF, XMP, IPTC, gutenberg
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Requires at least: 6.6
Tested up to: 7.1
Stable tag: 1.10.0

Making WordPress work for photographers with beautiful image galleries, slideshows, meta-data tools, and more.

== Description ==

PHOTOPRESS is an integrated suite of image management and gallery presentation features that photographers can use to build photography centric websites. The plugin allows you to create beautiful designed image galleries as well as extract, store and publish any EXIF/IPTC/XMP meta-data embedded in your images. 

The broader goal of PHOTOPRESS is to make WordPress easy to use for photographers by bringing critical image management and presentation features together into a single, modern, and free plugin. Features currently include:

= GALLERY LAYOUTS =

PhotoPress adds Masonry, Rows and Mosaic layouts to the WordPress Gallery block, as block variations.

* Masonry arranges images in columns of a width you set. Each image keeps its own height.
* Rows arranges images in rows of a height you set. Each image keeps its own width.
* Mosaic fills each row to the gallery's width. Each image keeps its own proportions.
* The layouts look the same in the editor as on the site.
* A gallery can open the full-screen slideshow when an image is clicked.
* A gallery can hide its image captions.
* Each image is downloaded at the width its layout shows it, not the width of the window.
* An image deleted from the Media Library is removed from the galleries that show it.
* The Gallery settings tab has a switch for each layout and one for removing deleted images. All are on by default.

= FULL-SCREEN SLIDESHOW =

* The slideshow opens over the whole window, on the image that was clicked. It is the Gallery Slideshow block's slideshow, shown full screen.
* Click or tap the right half of the image for the next image and the left half for the previous one. You can also swipe or use the arrow keys.
* A row of thumbnails sits under the image. You can set its height. Click a thumbnail to show that image.
* The caption box can show the image's caption, title and description, and a link to its page. Each is turned on or off separately.
* The caption box sits below or to the right of the image. You can set its padding.

= GALLERY SLIDESHOW BLOCK =

* The block shows a slideshow of a gallery on the same page. You can place it anywhere on the page.
* Clicking an image in the gallery shows that image in the slideshow.
* Press or tap the right side of the slideshow for the next image and the left side for the previous one. You can also swipe.
* A row of thumbnails can sit under the slideshow. You can set its height.
* Images change with a slide or a fade. Autoplay is available, and it pauses while the pointer is over the slideshow.
* The slideshow fits the window's height, less an offset you set.
* Captions sit below, to the left of or to the right of the image. You can set their padding.
* You can limit the caption's width to a share of the slideshow's width, 75% by default. On phones the caption keeps the full width.
* You can hide the gallery and show only the slideshow.
* The block works with dynamic galleries (WordPress 7.1).

= CHILD PAGES GALLERY =

* This Gallery block variation shows the published pages under a page, in their page order. It works in any PhotoPress layout, and makes a good index of gallery pages.
* Each page appears as its featured image, or else the first image of its gallery, linked to the page with its title.
* Updating PhotoPress converts the older Child Pages block to a Child Pages Gallery.

= IMAGE METADATA MANAGEMENT =

* PhotoPress fills image taxonomies from each photo's embedded EXIF, IPTC and XMP metadata when it is uploaded. Each taxonomy has its own archive pages.
* Standard Metadata covers camera, lens, city, state, country and keywords. PhotoPress reads them from wherever cameras and photo software record them. It cleans up camera names and keeps keyword hierarchies as nested terms. You can rename or turn off each one.
* Hierarchical Keyword Metadata handles keywords under a parent keyword, such as Genre › Portraiture in Lightroom or Capture One, or genre: Portraiture. These keywords go to the parent keyword's own taxonomy, with each level nested under the one above.
* Custom Metadata turns any other metadata field into a taxonomy. You choose the field from a list of IPTC fields, which says what each holds and where photo software shows it.
* When you add or change a taxonomy, PhotoPress offers to reprocess only the images it affects. The work runs in the background, and the settings page shows its progress.
* A taxonomy can be for the admin only. Its terms are kept and shown in the Media Library, and the site has no archive pages for it.
* The Image Metadata block shows an image's terms, such as keywords, people, places and camera. It also shows exposure details from the file: aperture, shutter speed, ISO, focal length and date taken. You choose the order.
* Alt text comes from the Alt Text (Accessibility) field that Lightroom Classic and Photoshop write into the file. Without it, alt text comes from a template of words and metadata fields.
* Image descriptions come from a template of words and metadata fields.
* Licensing metadata (Licensor, Licensor URL, Web Statement of Rights) is embedded into image files on upload, or into every image already uploaded. It is also added as structured data (JSON-LD) to the pages that show the images, with any theme.
* Save and reprocess all images applies a new template or licensing to the images already uploaded, in the background.

= IMAGE TEMPLATES =

These templates are for block themes that have none of their own for these pages (WordPress 6.7 or later).

* An image archive page, such as a keyword's or a camera's, shows its images in the Masonry layout, with links to more pages.
* An image page shows the image, its caption and description, its taxonomy terms and its exposure details.
* You edit both templates in the Site Editor, like the theme's own. Each is available while its kind of page is turned on in the Pages & Search tab.
* The Taxonomy Gallery variation of the Gallery block works in any PhotoPress layout. On an image archive template it shows the archive's images. Anywhere else it shows the latest images, or those of a keyword, camera or other term.
* The Attachment Image block shows an image page's image, at the size you choose, with its caption.
* An image taxonomy archive uses a taxonomy-attachment template when the theme has one, as themes built on the Frame framework do.

= PAGES & SEARCH =

* With the Attachment pages switch on, each image has a page of its own. With it off, the page's address and links to it go to the image file. This is WordPress's own setting.
* With the Image taxonomy pages switch on, each keyword, camera or other term has an archive page of its images. With it off, there are no archive pages, and image pages show the terms unlinked.
* Attachment pages follow WordPress's setting until you save the switch. Image taxonomy pages are on by default. While each is on, block themes get PhotoPress's template for those pages.
* The Images in search results switch is off by default. When it is on, visitors' searches find images by their titles, captions, alt text and terms. Posts and pages are found too, unless you turn them off.
* Block themes get an Image Search template made of blocks you can rearrange. It has the search box, a Search Summary with the search terms and the number of images found, and the images in a Taxonomy Gallery. The gallery shows each image's file name and title and has numbered pages. A Search Posts block lists the posts and pages found.
* With Relevanssi, turning images on ticks Media, the image taxonomies and image files in Relevanssi's indexing settings. Turning images off unticks only what PhotoPress ticked. PhotoPress has Relevanssi index each image's alt text. It indexes an image again when its terms change, are renamed or are deleted. It adds existing images to the index in the background.
* The Page cache and search index card shows whether Proxy Cache Purge and Relevanssi are active, installed or not installed. It has a Re-index all images button.
* PhotoPress lists images' pages and image taxonomy archive pages in whichever sitemap the site uses, through that sitemap's documented hooks. The tab shows which sitemap is in use.
  * WordPress's sitemap lists terms itself but leaves out images. PhotoPress adds public images (unattached, or attached to a published post) while attachment pages are on.
  * To Jetpack's sitemap, PhotoPress adds term archives and lists each image with its own page. Without attachment pages, it lists an unattached image with its first term's archive, where Jetpack would give the file.
  * To Yoast SEO's sitemap, PhotoPress adds the images on each term's archive page. While Yoast SEO lists media pages, PhotoPress adds a sitemap of unattached images' pages, which Yoast SEO leaves out.
  * Pages that are turned off are left out of every sitemap.

= PAGE CACHE =

This feature applies only to a site with a Varnish page cache and the Proxy Cache Purge plugin. Without them, PhotoPress sends no purges.

Proxy Cache Purge purges a post's pages when the post is saved. It does not purge an image's pages, and it purges nothing when terms change. PhotoPress fills those gaps through Proxy Cache Purge's documented hooks.

* When an image is edited or deleted or its terms change, PhotoPress purges the image's page (while attachment pages are on) and every page of its terms' archives (while image taxonomy pages are on).
* When a term is renamed or deleted, its images and archive are purged. If it has more than 25 images, the whole cache is emptied instead.
* A background job over images, such as re-reading their metadata, empties the cache once when it ends instead of purging as it goes.
* A post whose gallery gains or loses an image is purged. So are a replaced image, its files and the posts that show it, including images replaced with Enable Media Replace.
* A page with a Child Pages Gallery is purged when a page under it is published, moved, renamed, reordered or given another image.
* A page with a Taxonomy Gallery is purged when the images it shows may have changed.

= IMAGE SIZES =

* You set the quality JPEG and WebP sizes are saved at. The default is 92. WordPress's own default is 82.
* The tab lists every image size registered by WordPress, the theme and plugins. Each has a switch to stop making sizes nothing uses.
* Save and regenerate makes again only what each image needs. The work runs in the background, a few images at a time, and waits while the server is busy.

= OFFLOAD MEDIA =

With WP Offload Media installed, the Offload Media settings tab shows the following.

* Whether Offload Media is active, and where images are stored and served from.
* How long browsers and the CDN cache offloaded images. The default is 1 day.
* A replaced image is cleared from CloudFront a few seconds later, so the new one shows. A button clears the whole CDN.
* An option deletes a replaced image's old files from cloud storage.
* An image replaced with Enable Media Replace is treated like one replaced in PhotoPress. Its terms and alt text are read from the new file, and it is cleared from CloudFront.

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
* Reprocessing images' metadata, alt text or descriptions works ten images
  at a time and rests between batches, longer while the server is busy.
* Licensing: the license is embedded in uploads and given in image pages'
  structured data only while Licensing is switched on and its name, URL and
  web statement of rights are all filled in. 1.9.0 gave the structured data
  with the switch off.
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
* Keywords and parent keyword taxonomies keep keyword hierarchies as nested
  terms, parent keywords are matched in any case, and camera names are
  cleaned up. Images uploaded or reprocessed may get different terms than
  before.
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
  image taxonomy archive pages (off, terms are shown unlinked). Attachment
  pages follow WordPress's setting until the switch is saved; image taxonomy
  pages are on by default. While each is on, block themes get PhotoPress's
  template for those pages.
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
  images, the whole cache emptied. A background job over images, such as
  re-reading their metadata, empties it once when it ends, rather than
  purging as it goes. A post whose gallery gains or loses an
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
* Enable Media Replace: an image it replaces is now treated like one
  replaced in PhotoPress. Its terms and alt text are read from the new
  file, and it is cleared from CloudFront. With Proxy Cache Purge, it is
  also purged from the page cache. PhotoPress uses Enable Media Replace's
  enable-media-replace-upload-done action.
* Relevanssi: posts in which a replaced image's links or text were updated
  are indexed again.
* Child Pages Gallery: a new Gallery block variation that shows the
  published pages under a page, in any PhotoPress layout. Each page
  appears as its featured image, or else the first image of its gallery,
  linked to the page with its title.
* The legacy Child Pages block is removed. On the first request after the
  update, a background job converts each one saved in a post, template or
  synced pattern into a Child Pages Gallery. The gallery keeps the block's
  columns, image size, crop, alignment and spacing. A block the job does
  not reach, such as one in a theme's template file or in content imported
  later, is shown as that gallery. Unlike the old block, the gallery leaves
  out child pages that have no image.
* Full-screen slideshow: the Gallery Slideshow block's slideshow, full
  screen, and the same script, loaded only on pages that have either. The
  image takes the whole width, with no arrow columns beside it: a click,
  press or tap on either half moves, as in the block, and so does a swipe.
  It had needed jQuery, jQuery Migrate, imagesLoaded and Owl Carousel,
  which is no longer maintained: some 59 KB compressed, now 5 KB. Its
  thumbnails are a row that scrolls, the current one brought to the middle;
  a click on one shows it, and the last image goes round to the first.
* Gallery Slideshow block: Show thumbnails, a row under the slideshow with
  a height to choose, as the full-screen slideshow has.
* Both slideshows show the chevron for a moment where a tap lands, the way
  it moved.
* Pages load only what they use: the data the slideshow reads is added only
  to the images of galleries that open it, and a gallery's images are
  loaded in one query, not one each. Action Scheduler is loaded only while
  PhotoPress has background work, or when it schedules some, so an idle
  site skips it and its every-minute WP-Cron run. Where another plugin
  bundles Action Scheduler, the newest copy is still the one used.
* With Proxy Cache Purge, the cache is emptied once after PhotoPress is
  updated, so no cached page asks for the scripts an earlier version had.
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
* Fixed: background jobs (re-reading metadata, regenerating images, the
  search index, term changes) stopped after their first batch unless their
  settings page was open, and blocked new jobs of their kind. Each batch now
  queues the next; a job left without its next batch, as by a batch that
  ran out of memory, is given one; and a job whose kind is no longer
  available (a plugin it needs switched off) is stopped, saying why. A CDN
  invalidation that failed is tried again five minutes later, as intended.
* Deactivating PhotoPress unschedules its background work, and the every-
  minute WP-Cron run of Action Scheduler when no other plugin uses it.
  Activating it again rebuilds the image taxonomies' URLs, as does turning
  the image taxonomies off. Deleting PhotoPress deletes its settings and
  records; images, their terms and metadata stay.
* Security: the data the full-screen slideshow shows of an image (caption,
  title, description, page link) is cleaned before it is shown, including
  what post content carries itself, so it cannot run scripts. Replacing an
  image's file puts its caption and alt text only into posts the user may
  edit, and looks for references to it by file name rather than any text
  containing its name. An admin-only taxonomy's terms are readable over the
  REST API only by users who can add images, and anonymous media responses
  no longer list image taxonomy terms as classes. PHP files exit when
  requested directly.
* Faster on large sites: deleting an image looks only through posts with
  block content; renaming or deleting a term loads its images together; the
  sitemap loads only the images it lists for each term.
* Fixed: links in a slideshow's captions could not be clicked.
* Image files that claim more than they hold (a damaged or crafted file)
  no longer exhaust PHP's memory or time while PhotoPress reads their
  metadata: reads stop at the end of the file, a metadata packet over 16 MB
  is not read, and compressed metadata is inflated no further than that.
  When the license is embedded with Imagick, the upload is replaced only by
  a complete image, so a full disk can no longer truncate it.
* A background job's image that stops a batch (out of memory or time) is
  recorded as failed and skipped, rather than tried again for ever; paced
  jobs also wait while less than 300 MB of memory is available.
* Background jobs are safe to run again: the license job leaves a file
  that has the license already as it is (no rewrite, no new upload to the
  CDN, no growth of a TIFF), and two starts of one job at once make one.
* Faster on large sites without Relevanssi: image search finds a word's
  terms once rather than for every image; a gallery in random order picks
  from its images' IDs, kept for ten minutes, rather than sorting them all
  on each page view; sitemaps list images without sorting whole rows.
* On a network, only a network admin can clear everything from the CDN,
  and anyone at most once in ten minutes. An upload that adds to a gallery
  tells a user who may not upload nothing about the post. A taxonomy's ID
  is never one of WordPress's own, nor longer than WordPress allows.
* ImageMagick's memory is capped (256 MB, then a 512 MB map, then disk in
  uploads/photopress-tmp) whenever WordPress makes image sizes, on upload
  and replace as in the regenerate job; filterable as
  photopress_imagick_limits.
* Regenerating every size of an image saves its file and metadata only once
  all are made: a failure, or a request that dies midway, leaves the image
  as it was, and files made under new names are removed on a failure.
* Replacing an image's file keeps the old files as backups until the new
  sizes are made. If WordPress skips a size (as when short of memory), or
  the request fails, the image is put back as it was and the request says
  which sizes failed; a replace that died midway is undone by the next.
* An image whose license could not be written into its file says so, and
  why, in the Media Library; the record is cleared once the license is
  written.
* Faster reading of JPEG metadata past long runs of fill bytes.
* Fixed: Masonry, Rows and Mosaic galleries of one or two images were laid
  out in WordPress's columns instead.
* Fixed: the block editor warned that the PhotoPress Gallery and Child Pages
  blocks used an old block API version. PhotoPress's settings scripts now
  load only on its own screens.
* Page cache, with a Varnish cache and Proxy Cache Purge: a page with a
  Taxonomy Gallery is now purged when the images it shows may have
  changed. For a gallery of a term, that is when an image joins or leaves
  the term, or one of its images is edited or deleted. For a gallery of
  the latest images, it is when any image is uploaded, edited or deleted.
  A background job finds the galleries in posts saved before the update.
* Gallery Slideshow block: autoplay now pauses while the mouse pointer is
  over the slideshow, as its setting always said.
* Full-screen slideshow: a Ctrl, Cmd, Shift or Alt click on a linked image
  now opens the link, as it does elsewhere in the browser.
* Full-screen slideshow settings: Caption, Title, Description and Link to
  the image's page are now four separate switches. The caption box shows
  whichever are on.
* Taxonomy Gallery: Hide captions no longer hides File name and title.
* Child Pages Gallery: the heading now defaults to "Pages".
* Adding an uploaded image to a gallery over REST: Taxonomy and Child Pages
  Galleries are no longer counted, since their images are chosen when the
  page is viewed. Naming one by its anchor returns the error
  photopress_gallery_generated.
* Removing a deleted image from galleries: a post another user has open in
  the editor is now left alone until they are done. PhotoPress tries again
  every five minutes, for up to a day.
* CDN: the regenerate and license jobs now clear the files they rewrote
  from CloudFront when they end, whether done or cancelled. They clear
  each image, each folder or everything, using as few paths as CloudFront
  allows. Before, the license job cleared nothing, and the regenerate job
  cleared everything only when it finished.
* Alt text and descriptions are now set on upload and replace even with
  the image taxonomies switched off.
* Background jobs: the progress panel now says when a job is waiting for a
  busy server or resting between batches.
* Settings: two switches turned in quick succession are now both saved.
* Rows layout: each image's width is now set from its proportions before
  it loads, so the rows no longer reflow as the images arrive.
* Relevanssi: turning Images in search results on now ticks Media, the
  image taxonomies visitors see and image files in Relevanssi's indexing
  settings. PhotoPress saves them the way Relevanssi's own Indexing tab
  does. Turning images off unticks only what PhotoPress ticked. Before,
  PhotoPress added these settings each time Relevanssi read them. They
  showed as ticked on Relevanssi's settings page, and saving that page
  kept them even after images were turned off.

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
