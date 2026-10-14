[![PHOTOPRESS](https://github.com/photopress-dev/photopress-plugin/blob/master/.wordpress-org/banner-772x250.jpg)](https://wordpress.org/plugins/photopress/)

# PhotoPress: Making WordPress Work for Photographers

An integrated suite of image management and presentation features for WordPress that photographers can use to build beautiful photography centric websites.


## The Goal

The goal of PhotoPress is to make WordPress easy and compelling enough for photographers to use it as their primary web publishing platform instead of turning to other proprietary software/platforms. PhotoPress brings a variety of critical image management and presentation features together into a single, modern, and modular plugin. 

Our development motto is "do no harm" which means that we leverage the patterns outlined in WordPress Core and the Gutenberg editor as opposed to creating proprietary features that impede the overall usability of WordPress.

## Features

### Gallery Layouts

PhotoPress adds Masonry, Rows and Mosaic layouts to the WordPress Gallery block, as block variations.

- Masonry arranges images in columns of a width you set. Each image keeps its own height.
- Rows arranges images in rows of a height you set. Each image keeps its own width.
- Mosaic fills each row to the gallery's width. Each image keeps its own proportions.
- The layouts look the same in the editor as on the site.
- A gallery can open the full-screen slideshow when an image is clicked.
- A gallery can hide its image captions.
- Each image is downloaded at the width its layout shows it, not the width of the window.
- An image deleted from the Media Library is removed from the galleries that show it.
- The Gallery settings tab has a switch for each layout and one for removing deleted images. All are on by default.

![Masonry layout](.wordpress-org/screenshot-1.png)

![Rows layout](.wordpress-org/screenshot-2.png)

![Mosaic layout](.wordpress-org/screenshot-3.png)

![The Gallery tab](.wordpress-org/screenshot-16.png)

### Full-Screen Slideshow

- The slideshow opens over the whole window, on the image that was clicked. It is the Gallery Slideshow block's slideshow, shown full screen.
- Click or tap the right half of the image for the next image and the left half for the previous one. You can also swipe or use the arrow keys.
- A row of thumbnails sits under the image. You can set its height. Click a thumbnail to show that image.
- The caption box can show the image's caption, title and description, and a link to its page. Each is turned on or off separately.
- The caption box sits below or to the right of the image. You can set its padding.

![Full-screen slideshow](.wordpress-org/screenshot-4.gif)

![Slideshow settings](.wordpress-org/screenshot-5.png)

### Gallery Slideshow Block

- The block shows a slideshow of a gallery on the same page. You can place it anywhere on the page.
- Clicking an image in the gallery shows that image in the slideshow.
- Press or tap the right side of the slideshow for the next image and the left side for the previous one. You can also swipe.
- A row of thumbnails can sit under the slideshow. You can set its height.
- Images change with a slide or a fade. Autoplay is available, and it pauses while the pointer is over the slideshow.
- The slideshow fits the window's height, less an offset you set.
- Captions sit below, to the left of or to the right of the image. You can set their padding.
- You can limit the caption's width to a share of the slideshow's width, 75% by default. On phones the caption keeps the full width.
- You can hide the gallery and show only the slideshow.
- The block works with dynamic galleries (WordPress 7.1).

![Gallery Slideshow block](.wordpress-org/screenshot-6.gif)

![Caption max width on a desktop and a phone](.wordpress-org/screenshot-7.png)

### Child Pages Gallery

- This Gallery block variation shows the published pages under a page, in their page order. It works in any PhotoPress layout, and makes a good index of gallery pages.
- Each page appears as its featured image, or else the first image of its gallery, linked to the page with its title.
- Updating PhotoPress converts the older Child Pages block to a Child Pages Gallery.

![Child Pages Gallery](.wordpress-org/screenshot-8.png)

### Metadata

- PhotoPress fills image taxonomies from each photo's embedded EXIF, IPTC and XMP metadata when it is uploaded. Each taxonomy has its own archive pages.
- Standard Metadata covers camera, lens, city, state, country and keywords. PhotoPress reads them from wherever cameras and photo software record them. It cleans up camera names and keeps keyword hierarchies as nested terms. You can rename or turn off each one.
- Hierarchical Keyword Metadata handles keywords under a parent keyword, such as Genre › Portraiture in Lightroom or Capture One, or genre: Portraiture. These keywords go to the parent keyword's own taxonomy, with each level nested under the one above.
- Custom Metadata turns any other metadata field into a taxonomy. You choose the field from a list of IPTC fields, which says what each holds and where photo software shows it.
- When you add or change a taxonomy, PhotoPress offers to reprocess only the images it affects. The work runs in the background, and the settings page shows its progress.
- A taxonomy can be for the admin only. Its terms are kept and shown in the Media Library, and the site has no archive pages for it.
- The Image Metadata block shows an image's terms, such as keywords, people, places and camera. It also shows exposure details from the file: aperture, shutter speed, ISO, focal length and date taken. You choose the order.
- The Display Taxonomies and Display Image Exif widgets work in classic widget areas. In the block widgets editor, placed widgets transform into the Image Metadata block.
- Alt text comes from the Alt Text (Accessibility) field that Lightroom Classic and Photoshop write into the file. Without it, alt text comes from a template of words and metadata fields.
- Image descriptions come from a template of words and metadata fields.
- Licensing metadata (Licensor, Licensor URL, Web Statement of Rights) is embedded into image files on upload, or into every image already uploaded. It is also added as structured data (JSON-LD) to the pages that show the images, with any theme.
- Save and reprocess all images applies a new template or licensing to the images already uploaded, in the background.

![Image Metadata block](.wordpress-org/screenshot-9.png)

![Image taxonomies](.wordpress-org/screenshot-10.png)

![A parent keyword found in the photos given a taxonomy, and its images reprocessed](.wordpress-org/screenshot-11.gif)

![Alt text and description templates](.wordpress-org/screenshot-12.png)

![Licensing](.wordpress-org/screenshot-13.png)

### Image Templates

These templates are for block themes that have none of their own for these pages (WordPress 6.7 or later).

- An image archive page, such as a keyword's or a camera's, shows its images in the Masonry layout, with links to more pages.
- An image page shows the image, its caption and description, its taxonomy terms and its exposure details.
- You edit both templates in the Site Editor, like the theme's own. Each is available while its kind of page is turned on in the Pages & Search tab.
- The Taxonomy Gallery variation of the Gallery block works in any PhotoPress layout. On an image archive template it shows the archive's images. Anywhere else it shows the latest images, or those of a keyword, camera or other term.
- The Attachment Image block shows an image page's image, at the size you choose, with its caption.
- An image taxonomy archive uses a taxonomy-attachment template when the theme has one, as themes built on the Frame framework do.

![A keyword's images in the Image Archives template](.wordpress-org/screenshot-17.png)

### Pages & Search

- With the Attachment pages switch on, each image has a page of its own. With it off, the page's address and links to it go to the image file. This is WordPress's own setting.
- With the Image taxonomy pages switch on, each keyword, camera or other term has an archive page of its images. With it off, there are no archive pages, and image pages show the terms unlinked.
- Attachment pages follow WordPress's setting until you save the switch. Image taxonomy pages are on by default. While each is on, block themes get PhotoPress's template for those pages.
- The Images in search results switch is off by default. When it is on, visitors' searches find images by their titles, captions, alt text and terms. Posts and pages are found too, unless you turn them off.
- Block themes get an Image Search template made of blocks you can rearrange. It has the search box, a Search Summary with the search terms and the number of images found, and the images in a Taxonomy Gallery. The gallery shows each image's file name and title and has numbered pages. A Search Posts block lists the posts and pages found.
- With Relevanssi, turning images on ticks Media, the image taxonomies and image files in Relevanssi's indexing settings. Turning images off unticks only what PhotoPress ticked. PhotoPress has Relevanssi index each image's alt text. It indexes an image again when its terms change, are renamed or are deleted. It adds existing images to the index in the background.
- The Page cache and search index card shows whether Proxy Cache Purge and Relevanssi are active, installed or not installed. It has a Re-index all images button.
- PhotoPress lists images' pages and image taxonomy archive pages in whichever sitemap the site uses, through that sitemap's documented hooks. The tab shows which sitemap is in use.
  - WordPress's sitemap lists terms itself but leaves out images. PhotoPress adds public images (unattached, or attached to a published post) while attachment pages are on.
  - To Jetpack's sitemap, PhotoPress adds term archives and lists each image with its own page. Without attachment pages, it lists an unattached image with its first term's archive, where Jetpack would give the file.
  - To Yoast SEO's sitemap, PhotoPress adds the images on each term's archive page. While Yoast SEO lists media pages, PhotoPress adds a sitemap of unattached images' pages, which Yoast SEO leaves out.
  - Pages that are turned off are left out of every sitemap.

![The Pages & Search tab](.wordpress-org/screenshot-18.png)

![Search results with images](.wordpress-org/screenshot-19.png)

### Page Cache

This feature applies only to a site with a Varnish page cache and the [Proxy Cache Purge](https://wordpress.org/plugins/varnish-http-purge/) plugin. Without them, PhotoPress sends no purges.

Proxy Cache Purge purges a post's pages when the post is saved. It does not purge an image's pages, and it purges nothing when terms change. PhotoPress fills those gaps through Proxy Cache Purge's documented hooks.

- When an image is edited or deleted or its terms change, PhotoPress purges the image's page (while attachment pages are on) and every page of its terms' archives (while image taxonomy pages are on).
- When a term is renamed or deleted, its images and archive are purged. If it has more than 25 images, the whole cache is emptied instead.
- A background job over images, such as re-reading their metadata, empties the cache once when it ends instead of purging as it goes.
- A post whose gallery gains or loses an image is purged. So are a replaced image, its files and the posts that show it, including images replaced with Enable Media Replace.
- A page with a Child Pages Gallery is purged when a page under it is published, moved, renamed, reordered or given another image.
- A page with a Taxonomy Gallery is purged when the images it shows may have changed.

### Image Sizes

- You set the quality JPEG and WebP sizes are saved at. The default is 92. WordPress's own default is 82.
- The tab lists every image size registered by WordPress, the theme and plugins. Each has a switch to stop making sizes nothing uses.
- Save and regenerate makes again only what each image needs. The work runs in the background, a few images at a time, and waits while the server is busy.

![The Image Sizes tab](.wordpress-org/screenshot-14.png)

### Offload Media

With [WP Offload Media](https://wordpress.org/plugins/amazon-s3-and-cloudfront/) installed, the Offload Media settings tab shows the following.

- Whether Offload Media is active, and where images are stored and served from.
- How long browsers and the CDN cache offloaded images. The default is 1 day.
- A replaced image is cleared from CloudFront a few seconds later, so the new one shows. A button clears the whole CDN.
- An option deletes a replaced image's old files from cloud storage.
- An image replaced with Enable Media Replace is treated like one replaced in PhotoPress. Its terms and alt text are read from the new file, and it is cleared from CloudFront.

![The Offload Media tab](.wordpress-org/screenshot-15.png)

## Requirements

* WordPress 6.6
* PHP 7.0+

The plugin is coded to work on PHP 5.6+, but only 7.0+ is officially supported.

## Installation

The latest release of PhotoPress can be installed from the [WordPress plugin repository](https://wordpress.org/plugins/photopress/). 

## Development 

To contribute to PhotoPress you need to:

1. Clone the repository
2. Download and install [Composer](https://getcomposer.org/) for managing PHP dependencies.
3. Run `composer install`
4. Install [Node.js](https://nodejs.org/) 22 or later
5. Run `npm install`, then `npm run build` (or `npm start` to rebuild as you edit)

## Documentation

Documentation for PhotoPress is maintained on the [wiki](https://github.com/photopress-dev/photopress-plugin/wiki).  Please feel free to add to the wiki if you use the plugin.


## Copyright and License

This project is licensed under the [GNU GPL](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html), version 2 or later.

2011&thinsp;&ndash;&thinsp;2020 &copy; [Peter Adams](http://peteradams.org).
