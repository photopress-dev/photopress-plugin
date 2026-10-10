/**
 * The metadata fields offered for Custom Metadata, by group: text fields a
 * photographer fills in, or a camera records, that make sense to browse by.
 * Labels are the IPTC Photo Metadata Standard's, as Lightroom, Capture One
 * and Photo Mechanic show them; each field has the names a taxonomy of it
 * would take, a likely value for the example, what it is in a line, and
 * for an IPTC field its section of the IPTC guide.
 *
 * Camera, lens, city, state, country and keywords are Standard Metadata, so
 * not here.
 */
import { __ } from '@wordpress/i18n';

const field = ( tag, label, singular, plural, example, about = '', anchor = '' ) => ( { tag, label, singular, plural, example, about, anchor } );

/** The IPTC Photo Metadata User Guide, which explains the IPTC fields. */
export const GUIDE_URL = 'https://www.iptc.org/std/photometadata/documentation/userguide/';

/** A field's section of the guide, or '' for a field it does not cover. */
export const guideUrl = ( f ) => ( f.anchor ? GUIDE_URL + '#' + f.anchor : '' );

export const FIELD_GROUPS = [
	{
		label: __( 'Description' ),
		fields: [
			field( 'Iptc4xmpExt:Event', __( 'Event' ), __( 'Event' ), __( 'Events' ), 'Maker Faire', __( 'The event the photo was taken at, such as a festival or conference.' ), '_event' ),
			field( 'photoshop:Category', __( 'Category' ), __( 'Category' ), __( 'Categories' ), 'Travel', __( 'A category code from older newswire workflows, often three letters.' ) ),
			field( 'photoshop:SupplementalCategories', __( 'Supplemental Categories' ), __( 'Supplemental category' ), __( 'Supplemental categories' ), 'Landscape', __( 'Further categories from older newswire workflows.' ) ),
			field( 'Iptc4xmpCore:IntellectualGenre', __( 'Intellectual Genre' ), __( 'Genre' ), __( 'Genres' ), 'Profile', __( 'The nature of the photo’s content, such as profile, interview or obituary.' ), '_intellectual_genre_legacy' ),
			field( 'Iptc4xmpCore:Scene', __( 'Scene Code' ), __( 'Scene' ), __( 'Scenes' ), '011900', __( 'IPTC codes for the kind of scene, such as 011900 for action.' ), '_iptc_scene_code' ),
			field( 'Iptc4xmpCore:SubjectCode', __( 'Subject Code' ), __( 'Subject' ), __( 'Subjects' ), '15000000', __( 'IPTC codes for the photo’s subject, such as 15000000 for sport.' ), '_iptc_subject_code_legacy' ),
			field( 'photoshop:Headline', __( 'Headline' ), __( 'Headline' ), __( 'Headlines' ), 'Sunset over the bay', __( 'A short synopsis of what the photo shows.' ), '_headline' ),
			field( 'dc:title', __( 'Title' ), __( 'Title' ), __( 'Titles' ), 'Golden Hour', __( 'A short name for the photo.' ), '_title' ),
			field( 'xmp:Label', __( 'Color Label' ), __( 'Label' ), __( 'Labels' ), 'Red', __( 'The color label set in Lightroom, Capture One or Bridge.' ) ),
			field( 'xmp:Rating', __( 'Rating' ), __( 'Rating' ), __( 'Ratings' ), '5', __( 'The star rating set in Lightroom, Capture One or Bridge, from 1 to 5.' ) ),
		],
	},
	{
		label: __( 'People and organizations' ),
		fields: [
			field( 'Iptc4xmpExt:PersonInImage', __( 'Person Shown in the Image' ), __( 'Person' ), __( 'People' ), 'Jane Smith', __( 'The names of the people shown in the photo.' ), '_person_shown_in_the_image' ),
			field( 'Iptc4xmpExt:OrganisationInImageName', __( 'Organization Featured in the Image' ), __( 'Organization' ), __( 'Organizations' ), 'Acme Corporation', __( 'The names of organizations or companies featured in the photo.' ), '_organisations_including_companies_featured_by_the_image' ),
			field( 'dc:creator', __( 'Creator' ), __( 'Creator' ), __( 'Creators' ), 'Jane Smith', __( 'The name of the photographer.' ), '_creator_free_text' ),
			field( 'photoshop:AuthorsPosition', __( 'Creator’s Job Title' ), __( 'Job title' ), __( 'Job titles' ), 'Staff Photographer', __( 'The photographer’s job title, such as Staff Photographer.' ), '_creators_job_title' ),
			field( 'photoshop:CaptionWriter', __( 'Description Writer' ), __( 'Description writer' ), __( 'Description writers' ), 'John Doe', __( 'Who wrote the photo’s description.' ), '_description_writer' ),
			field( 'dc:contributor', __( 'Contributor' ), __( 'Contributor' ), __( 'Contributors' ), 'John Doe', __( 'Others who contributed to the photo.' ) ),
			field( 'dc:publisher', __( 'Publisher' ), __( 'Publisher' ), __( 'Publishers' ), 'Acme Press', __( 'Who published the photo.' ) ),
		],
	},
	{
		label: __( 'Location' ),
		fields: [
			field( 'Iptc4xmpCore:Location', __( 'Sublocation' ), __( 'Sublocation' ), __( 'Sublocations' ), 'Golden Gate Park', __( 'A place within the city, such as a park, venue or street.' ), '_locations' ),
			field( 'Iptc4xmpCore:CountryCode', __( 'Country Code' ), __( 'Country code' ), __( 'Country codes' ), 'US', __( 'The two- or three-letter code of the country the photo was taken in.' ), '_locations' ),
		],
	},
	{
		label: __( 'Rights and workflow' ),
		fields: [
			field( 'photoshop:Credit', __( 'Credit Line' ), __( 'Credit' ), __( 'Credits' ), 'Jane Smith Photography', __( 'How the photo is to be credited when it is published.' ), '_credit_line' ),
			field( 'photoshop:Source', __( 'Source' ), __( 'Source' ), __( 'Sources' ), 'Acme Agency', __( 'Who supplied the photo, such as an agency.' ), '_source_supply_chain' ),
			field( 'dc:rights', __( 'Copyright Notice' ), __( 'Copyright notice' ), __( 'Copyright notices' ), '© 2024 Jane Smith', __( 'The copyright notice, such as © 2024 Jane Smith.' ), '_copyright_notice' ),
			field( 'xmpRights:UsageTerms', __( 'Rights Usage Terms' ), __( 'Usage terms' ), __( 'Usage terms' ), 'Editorial use only', __( 'How the photo may be used, such as editorial use only.' ), '_rights_usage_terms' ),
			field( 'photoshop:Instructions', __( 'Instructions' ), __( 'Instruction' ), __( 'Instructions' ), 'Embargoed until June 1', __( 'Instructions for whoever receives the photo, such as an embargo.' ), '_instructions' ),
			field( 'photoshop:TransmissionReference', __( 'Job Identifier' ), __( 'Job' ), __( 'Jobs' ), '2024-0612', __( 'An identifier of the job or assignment the photo is from.' ), '_job_identifier' ),
		],
	},
	{
		label: __( 'Camera and software' ),
		fields: [
			field( 'tiff:Make', __( 'Camera Make' ), __( 'Camera make' ), __( 'Camera makes' ), 'Fujifilm', __( 'The camera’s manufacturer, such as Fujifilm.' ) ),
			field( 'tiff:Model', __( 'Camera Model' ), __( 'Camera model' ), __( 'Camera models' ), 'X100V', __( 'The camera’s model, such as X100V.' ) ),
			field( 'exifEX:LensMake', __( 'Lens Make' ), __( 'Lens make' ), __( 'Lens makes' ), 'Fujifilm', __( 'The lens’s manufacturer.' ) ),
			field( 'xmp:CreatorTool', __( 'Creator Tool' ), __( 'Software' ), __( 'Software' ), 'Capture One 23', __( 'The software that last saved the file, such as Capture One.' ) ),
		],
	},
];

/** The fields most taxonomies are made of, listed first. */
export const COMMON_FIELDS = [
	'Iptc4xmpExt:Event',
	'Iptc4xmpExt:PersonInImage',
	'Iptc4xmpExt:OrganisationInImageName',
	'photoshop:Category',
	'Iptc4xmpCore:IntellectualGenre',
	'Iptc4xmpCore:Location',
	'dc:creator',
	'photoshop:Credit',
];

const BY_TAG = Object.fromEntries( FIELD_GROUPS.flatMap( ( group ) => group.fields ).map( ( f ) => [ f.tag, f ] ) );

/**
 * A field by its tag. One not listed (as a taxonomy made by an earlier
 * version may have) is named by its tag.
 */
export function fieldOf( tag ) {
	return BY_TAG[ tag ] || field( tag, tag, tag, tag, '' );
}
