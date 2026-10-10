/**
 * The metadata fields offered for Custom Metadata, by group: text fields a
 * photographer fills in, or a camera records, that make sense to browse by.
 * Labels are the IPTC Photo Metadata Standard's, as Lightroom, Capture One
 * and Photo Mechanic show them; each field has the names a taxonomy of it
 * would take, and a likely value for the example.
 *
 * Camera, lens, city, state, country and keywords are Standard Metadata, so
 * not here.
 */
import { __ } from '@wordpress/i18n';

const field = ( tag, label, singular, plural, example ) => ( { tag, label, singular, plural, example } );

export const FIELD_GROUPS = [
	{
		label: __( 'Description' ),
		fields: [
			field( 'Iptc4xmpExt:Event', __( 'Event' ), __( 'Event' ), __( 'Events' ), 'Maker Faire' ),
			field( 'photoshop:Category', __( 'Category' ), __( 'Category' ), __( 'Categories' ), 'Travel' ),
			field( 'photoshop:SupplementalCategories', __( 'Supplemental Categories' ), __( 'Supplemental category' ), __( 'Supplemental categories' ), 'Landscape' ),
			field( 'Iptc4xmpCore:IntellectualGenre', __( 'Intellectual Genre' ), __( 'Genre' ), __( 'Genres' ), 'Profile' ),
			field( 'Iptc4xmpCore:Scene', __( 'Scene Code' ), __( 'Scene' ), __( 'Scenes' ), '011900' ),
			field( 'Iptc4xmpCore:SubjectCode', __( 'Subject Code' ), __( 'Subject' ), __( 'Subjects' ), '15000000' ),
			field( 'photoshop:Headline', __( 'Headline' ), __( 'Headline' ), __( 'Headlines' ), 'Sunset over the bay' ),
			field( 'dc:title', __( 'Title' ), __( 'Title' ), __( 'Titles' ), 'Golden Hour' ),
			field( 'xmp:Label', __( 'Color Label' ), __( 'Label' ), __( 'Labels' ), 'Red' ),
			field( 'xmp:Rating', __( 'Rating' ), __( 'Rating' ), __( 'Ratings' ), '5' ),
		],
	},
	{
		label: __( 'People and organizations' ),
		fields: [
			field( 'Iptc4xmpExt:PersonInImage', __( 'Person Shown in the Image' ), __( 'Person' ), __( 'People' ), 'Jane Smith' ),
			field( 'Iptc4xmpExt:OrganisationInImageName', __( 'Organization Featured in the Image' ), __( 'Organization' ), __( 'Organizations' ), 'Acme Corporation' ),
			field( 'dc:creator', __( 'Creator' ), __( 'Creator' ), __( 'Creators' ), 'Jane Smith' ),
			field( 'photoshop:AuthorsPosition', __( 'Creator’s Job Title' ), __( 'Job title' ), __( 'Job titles' ), 'Staff Photographer' ),
			field( 'photoshop:CaptionWriter', __( 'Description Writer' ), __( 'Description writer' ), __( 'Description writers' ), 'John Doe' ),
			field( 'dc:contributor', __( 'Contributor' ), __( 'Contributor' ), __( 'Contributors' ), 'John Doe' ),
			field( 'dc:publisher', __( 'Publisher' ), __( 'Publisher' ), __( 'Publishers' ), 'Acme Press' ),
		],
	},
	{
		label: __( 'Location' ),
		fields: [
			field( 'Iptc4xmpCore:Location', __( 'Sublocation' ), __( 'Sublocation' ), __( 'Sublocations' ), 'Golden Gate Park' ),
			field( 'Iptc4xmpCore:CountryCode', __( 'Country Code' ), __( 'Country code' ), __( 'Country codes' ), 'US' ),
		],
	},
	{
		label: __( 'Rights and workflow' ),
		fields: [
			field( 'photoshop:Credit', __( 'Credit Line' ), __( 'Credit' ), __( 'Credits' ), 'Jane Smith Photography' ),
			field( 'photoshop:Source', __( 'Source' ), __( 'Source' ), __( 'Sources' ), 'Acme Agency' ),
			field( 'dc:rights', __( 'Copyright Notice' ), __( 'Copyright notice' ), __( 'Copyright notices' ), '© 2024 Jane Smith' ),
			field( 'xmpRights:Owner', __( 'Copyright Owner' ), __( 'Copyright owner' ), __( 'Copyright owners' ), 'Jane Smith' ),
			field( 'xmpRights:UsageTerms', __( 'Rights Usage Terms' ), __( 'Usage terms' ), __( 'Usage terms' ), 'Editorial use only' ),
			field( 'photoshop:Instructions', __( 'Instructions' ), __( 'Instruction' ), __( 'Instructions' ), 'Embargoed until June 1' ),
			field( 'photoshop:TransmissionReference', __( 'Job Identifier' ), __( 'Job' ), __( 'Jobs' ), '2024-0612' ),
		],
	},
	{
		label: __( 'Camera and software' ),
		fields: [
			field( 'tiff:Make', __( 'Camera Make' ), __( 'Camera make' ), __( 'Camera makes' ), 'Fujifilm' ),
			field( 'tiff:Model', __( 'Camera Model' ), __( 'Camera model' ), __( 'Camera models' ), 'X100V' ),
			field( 'exifEX:LensMake', __( 'Lens Make' ), __( 'Lens make' ), __( 'Lens makes' ), 'Fujifilm' ),
			field( 'xmp:CreatorTool', __( 'Creator Tool' ), __( 'Software' ), __( 'Software' ), 'Capture One 23' ),
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
