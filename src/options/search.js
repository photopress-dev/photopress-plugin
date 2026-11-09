/**
 * The Pages & Search settings tab: whether images and image taxonomy terms
 * have pages of their own on the site (modules/search), with PhotoPress's
 * templates for them in block themes; images in search results; and the page
 * cache and search index. The sitemap they are listed in is on the SEO tab.
 */
const { __ } = wp.i18n;
import { Component, Fragment } from '@wordpress/element';
import { Notice } from '@wordpress/components';

import {
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting,
} from '../shared/options.js';
import FeatureSection from '../shared/feature-section.js';
import SwitchRow from '../shared/switch-row.js';
import JobPanel from '../shared/jobs.js';
import PluginState from '../shared/plugin-state.js';

// Proxy Cache Purge and Relevanssi: active, inactive or missing
// (modules/search).
const RELEVANSSI = window.photopressRelevanssi || { plugin: 'missing' };
const PAGE_CACHE = window.photopressPageCache || { plugin: 'missing' };

const PAGE_CACHE_TEXT = {
	active: __( 'PhotoPress has it purge an image when the image is edited or deleted or its terms change: its page, while attachment pages are on, and every page of its terms’ archives, while image taxonomy pages are on. A term renamed or deleted has its images and archive purged, or, with more than 25 images, the whole cache emptied. A background job over images, such as re-reading their metadata, empties it once when it ends.' ),
	inactive: __( 'Activate it to clear the pages of images and image taxonomy terms from the page cache when they change.' ),
	missing: __( 'Proxy Cache Purge clears pages from a Varnish or NGINX page cache when posts change. Once it’s active, PhotoPress has it clear the pages of images and image taxonomy terms too.' ),
};

const RELEVANSSI_TEXT = {
	inactive: __( 'Activate it to search the site with its index.' ),
	missing: __( 'Relevanssi replaces WordPress’s search with one that ranks results by relevance. Once it’s active, PhotoPress has it index images and their terms.' ),
};

class SearchSettings extends Component {
	constructor() {
		super( ...arguments );

		this.settingsGroup = this.props.settingsGroup;
		this.persistSetting = persistSetting.bind( this );
		this.saveSettings = saveSettings.bind( this );
		this.getSetting = getSetting.bind( this );
		this.setSetting = setSetting.bind( this );
		this.deleteSetting = deleteSetting.bind( this );
		this.getError = getError.bind( this );
		this.setError = setError.bind( this );

		this.state = {
			isAPILoaded: false,
			isAPISaving: false,
			errors: {},
			settings: {
				attachment_pages: true,
				taxonomy_pages: true,
				images_in_search: false,
				posts_in_search: true,
				prevent_empty_search: true,
				...this.props.data,
			},
			dirtyFields: [],
		};

		this.settingsSchema = {};
	}

	// The settings page keeps them for the other tabs: the Metadata tab shows
	// no archive pages while they are off.
	componentDidUpdate( prevProps, prevState ) {
		if ( this.props.onChange && prevState.settings !== this.state.settings ) {
			this.props.onChange( this.state.settings );
		}
	}

	render() {
		return (
			<Fragment>
				{ this.getError( 'save' ) && (
					<Notice status="error" isDismissible={ false }>
						<p>{ __( 'The settings were not saved:' ) } { this.getError( 'save' ) }</p>
					</Notice>
				) }

				<FeatureSection
					title={ __( 'Attachment pages' ) }
					description={ __( 'Give each image a page of its own, with its caption, description, terms and exposure details.' ) }
					info={ [
						<p key="0">{ __( 'This is WordPress’s own Attachment pages setting. Turned off, an image’s page address and links to it go to the image file, leaving fewer pages for search engines and other crawlers. In a block theme without its own template, the pages use PhotoPress’s Image Pages template, which can be edited in the Site Editor.' ) }</p>,
					] }
					checked={ this.getSetting( 'attachment_pages' ) }
					onChange={ ( value ) => this.persistSetting( 'attachment_pages', value ) }
				/>

				<FeatureSection
					title={ __( 'Image taxonomy pages' ) }
					description={ __( 'Give each term of an image taxonomy, such as a keyword or a camera, an archive page of its images.' ) }
					info={ [
						<p key="0">{ __( 'Turned off, the terms are kept and the Image Metadata block still shows them, without links; to keep one taxonomy off the site, turn off its Public switch in the Metadata tab instead. In a block theme without its own template, the pages use PhotoPress’s Image Archives template, a Taxonomy Gallery of their images, which can be edited in the Site Editor.' ) }</p>,
					] }
					checked={ this.getSetting( 'taxonomy_pages' ) }
					onChange={ ( value ) => this.persistSetting( 'taxonomy_pages', value ) }
				/>

				<FeatureSection
					title={ __( 'Image search' ) }
					description={ __( 'Allow visitors to find images by searching their titles, captions, alt text, and terms.' ) }
					info={ [
						<p key="0">{ __( 'In a block theme, search results show the images found in a gallery. With Include posts and pages on, matching posts and pages are listed below it. To change this layout, edit the Image Search template in the Site Editor.' ) }</p>,
						<p key="1">{ __( 'In a classic theme, images appear in the theme’s own search results.' ) }</p>,
						<p key="2">{ __( 'If Relevanssi is active, PhotoPress also sets Relevanssi to index images and their terms. Turning this off undoes only the changes PhotoPress made.' ) }</p>,
					] }
					checked={ this.getSetting( 'images_in_search' ) }
					onChange={ ( value ) => this.persistSetting( 'images_in_search', value ) }
				>
					<SwitchRow
						label={ __( 'Include posts and pages' ) }
						help={ __( 'Include links to relevant posts and pages in search results.' ) }
						checked={ this.getSetting( 'posts_in_search' ) }
						onChange={ ( value ) => this.persistSetting( 'posts_in_search', value ) }
					/>
					<SwitchRow
						label={ __( 'Prevent empty search queries' ) }
						help={ __( 'A search with no words finds nothing, instead of listing every image.' ) }
						checked={ this.getSetting( 'prevent_empty_search' ) }
						onChange={ ( value ) => this.persistSetting( 'prevent_empty_search', value ) }
					/>
				</FeatureSection>

				<FeatureSection
					title={ __( 'Page cache and search index' ) }
					className="photopress-search-cache"
					description={ __( 'PhotoPress keeps the site’s page cache and search index up to date as images and terms change, through these plugins.' ) }
				>
					<div className="photopress-plugin-status" data-plugin="varnish-http-purge">
						<PluginState name={ __( 'Proxy Cache Purge' ) } state={ PAGE_CACHE.plugin } />
						<p>{ PAGE_CACHE_TEXT[ PAGE_CACHE.plugin ] }</p>
					</div>

					<div className="photopress-plugin-status" data-plugin="relevanssi">
						<PluginState name={ __( 'Relevanssi' ) } state={ RELEVANSSI.plugin } />
						{ 'active' === RELEVANSSI.plugin ? (
							<>
								{ !! this.getSetting( 'images_in_search' ) && (
									<p>{ __( 'PhotoPress has it index images and their terms as they’re added and changed, and again when a term is renamed or deleted.' ) }</p>
								) }
								<JobPanel
									type="search.index"
									label={ __( 'Adding images to the search index' ) }
									compact
									startable={ !! this.getSetting( 'images_in_search' ) }
									startLabel={ __( 'Re-index all images' ) }
									refresh={ this.state.settings.images_in_search }
								/>
								{ ! this.getSetting( 'images_in_search' ) && (
									<p className="components-base-control__help">{ __( 'Turn on Image search to add images to the index.' ) }</p>
								) }
							</>
						) : (
							<p>{ RELEVANSSI_TEXT[ RELEVANSSI.plugin ] }</p>
						) }
					</div>
				</FeatureSection>
			</Fragment>
		);
	}
}

export default SearchSettings;
