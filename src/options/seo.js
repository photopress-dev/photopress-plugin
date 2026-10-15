/**
 * The SEO settings tab (modules/seo): the sitemap in use, which PhotoPress
 * lists the pages of images and image taxonomy terms in, and archives a
 * photography site rarely needs, to disable.
 */
const { __, sprintf } = wp.i18n;
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

// The sitemap in use, which PhotoPress adds image and term pages to
// (modules/search/ImageSitemap.php), shared by modules/seo/seo.php.
const SITEMAP = window.photopressSitemap || { sitemap: 'other' };

const SITEMAP_TEXT = {
	core: () => __( 'WordPress’s sitemap is in use. PhotoPress adds a sitemap of its images to it, each with the page it is shown on: its own while attachment pages are on. WordPress lists image taxonomy terms itself while image taxonomy pages are on.' ),
	jetpack: ( built ) => sprintf( __( 'Jetpack’s sitemap is in use, last built %s ago. PhotoPress adds image taxonomy terms’ archive pages to it while image taxonomy pages are on, and lists each image with its own page while attachment pages are on.' ), built ),
	yoast: ( built ) => sprintf( __( 'Yoast SEO’s sitemap is in use, last built %s ago. It lists image taxonomy terms itself, and PhotoPress adds the images on each term’s archive page. While attachment pages are on and Yoast SEO lists media pages, PhotoPress adds the pages of images not attached to a post, which Yoast SEO leaves out.' ), built ),
	other: () => __( 'Another plugin has turned off WordPress’s sitemap. PhotoPress adds image and term pages to Jetpack’s and Yoast SEO’s, and shows which is in use here once it has been built.' ),
	none: () => __( 'Search engines are discouraged from indexing this site (Settings › Reading), so it has no sitemap.' ),
};

class SeoSettings extends Component {
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
				archives_off: false,
				author_archives_off: true,
				date_archives_off: true,
				format_archives_off: true,
				...this.props.data,
			},
			dirtyFields: [],
		};

		this.settingsSchema = {};
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
					title={ __( 'Sitemap' ) }
					className="photopress-seo-sitemap"
					description={ __( 'The pages of images and image taxonomy terms are listed in the site’s sitemap for search engines, whichever plugin builds it. Whether they have pages is set on the Pages & Search tab.' ) }
				>
					<p data-sitemap={ SITEMAP.sitemap }>
						{ ( SITEMAP_TEXT[ SITEMAP.sitemap ] || SITEMAP_TEXT.other )( SITEMAP.built ) }
						{ SITEMAP.url && (
							<>
								{ ' ' }
								<a href={ SITEMAP.url } target="_blank" rel="noreferrer">{ __( 'View the sitemap' ) }</a>
							</>
						) }
					</p>
				</FeatureSection>

				<FeatureSection
					title={ __( 'Unneeded archives' ) }
					description={ __( 'Disable archives a photography site rarely needs. Their addresses redirect to the home page.' ) }
					info={ [
						<p key="0">{ __( 'A disabled archive answers with a permanent redirect to the home page, its feeds and later pages too, so search engines drop its addresses. WordPress’s and Yoast SEO’s sitemaps leave out authors while author archives are disabled, and post formats while theirs are. An author archive’s address shows the author’s login name, as does ?author=1, which WordPress redirects to it.' ) }</p>,
					] }
					checked={ this.getSetting( 'archives_off' ) }
					onChange={ ( value ) => this.persistSetting( 'archives_off', value ) }
				>
					<SwitchRow
						label={ __( 'Author archives' ) }
						help={ __( 'Each author’s posts, at an address that shows the author’s login name.' ) }
						checked={ this.getSetting( 'author_archives_off' ) }
						onChange={ ( value ) => this.persistSetting( 'author_archives_off', value ) }
					/>
					<SwitchRow
						label={ __( 'Date archives' ) }
						help={ __( 'Posts by year, month and day.' ) }
						checked={ this.getSetting( 'date_archives_off' ) }
						onChange={ ( value ) => this.persistSetting( 'date_archives_off', value ) }
					/>
					<SwitchRow
						label={ __( 'Post format archives' ) }
						help={ __( 'Posts of one format, such as Gallery or Image.' ) }
						checked={ this.getSetting( 'format_archives_off' ) }
						onChange={ ( value ) => this.persistSetting( 'format_archives_off', value ) }
					/>
				</FeatureSection>
			</Fragment>
		);
	}
}

export default SeoSettings;
