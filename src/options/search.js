/**
 * The Pages & Search settings tab: whether images and image taxonomy terms
 * have pages of their own on the site (modules/search), with PhotoPress's
 * templates for them in block themes; images in search results; and the
 * page cache and search index.
 */
const { __, _n, sprintf } = wp.i18n;
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

// Whether Relevanssi is the site's search, and its index; the page cache
// plugin, and whether PhotoPress clears its pages (modules/search).
const RELEVANSSI = window.photopressRelevanssi || { active: false };
const PAGE_CACHE = window.photopressPageCache || { plugin: '', purges: false };

/** The page cache in use, and whether PhotoPress clears its pages. */
function pageCache() {
	if ( PAGE_CACHE.purges ) {
		return sprintf( __( 'Pages are cached by %s. PhotoPress clears the pages of images and image taxonomy terms when they change.' ), PAGE_CACHE.plugin );
	}

	return PAGE_CACHE.plugin
		? sprintf( __( 'Pages are cached by %s. PhotoPress doesn’t clear its pages; they are refreshed as its settings say.' ), PAGE_CACHE.plugin )
		: __( 'No page cache plugin is active.' );
}

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
					title={ __( 'Images in search results' ) }
					description={ __( 'Find images when visitors search the site, by their titles, captions, alt text and terms.' ) }
					info={ [
						<p key="0">{ __( 'A block theme shows them with PhotoPress’s Image Search template: the images in a Taxonomy Gallery, and the posts and pages found listed apart. A classic theme lists them in its own search results. With Relevanssi, PhotoPress has it index images and their terms, and its settings show the same.' ) }</p>,
					] }
					checked={ this.getSetting( 'images_in_search' ) }
					onChange={ ( value ) => this.persistSetting( 'images_in_search', value ) }
				>
					<SwitchRow
						label={ __( 'Posts and pages too' ) }
						help={ __( 'Turned off, a search finds images alone.' ) }
						checked={ this.getSetting( 'posts_in_search' ) }
						onChange={ ( value ) => this.persistSetting( 'posts_in_search', value ) }
					/>
				</FeatureSection>

				<FeatureSection
					title={ __( 'Page cache and search index' ) }
					className="photopress-search-cache"
					description={ pageCache() }
					info={ PAGE_CACHE.purges && [
						<p key="0">{ __( 'Proxy Cache Purge clears a post’s pages when the post is saved, but not an image’s, or a term’s. PhotoPress clears an image’s page when the image is edited or deleted or its terms change, while attachment pages are on, and every page of a term’s archive when its images change or it is renamed, while image taxonomy pages are on.' ) }</p>,
					] }
				>
					{ RELEVANSSI.active ? (
						<div className="photopress-search-relevanssi">
							<p>
								{ RELEVANSSI.images
									? sprintf( _n( 'Relevanssi is the site’s search; its index has %s image.', 'Relevanssi is the site’s search; its index has %s images.', RELEVANSSI.images ), RELEVANSSI.images.toLocaleString() )
									: __( 'Relevanssi is the site’s search, and its index has no images yet.' ) }
							</p>
							<JobPanel
								type="search.index"
								label={ __( 'Adding images to the search index' ) }
								compact
								startable={ !! this.getSetting( 'images_in_search' ) }
								startLabel={ __( 'Re-index all images' ) }
								refresh={ this.state.settings.images_in_search }
							/>
							{ ! this.getSetting( 'images_in_search' ) && (
								<p className="components-base-control__help">{ __( 'Turn on Images in search results to add images to the index.' ) }</p>
							) }
						</div>
					) : (
						<p>{ __( 'The site’s search is WordPress’s own, which keeps no index.' ) }</p>
					) }
				</FeatureSection>
			</Fragment>
		);
	}
}

export default SearchSettings;
