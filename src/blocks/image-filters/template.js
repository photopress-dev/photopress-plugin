/**
 * The image taxonomies visitors see, as REST lists them: those for the admin
 * only are never shown, so never offered.
 *
 * @param {Object[]} taxonomies From getTaxonomies().
 * @return {Object[]} Those offered.
 */
export const offeredTaxonomies = ( taxonomies ) => ( taxonomies || [] ).filter( ( t ) => false !== t.photopress_public );

/**
 * Image Filters' first filters: one per taxonomy offered but those hidden,
 * in their order.
 *
 * @param {Object[]} taxonomies       From getTaxonomies().
 * @param {string[]} hiddenTaxonomies Hidden by the block as first made.
 * @return {Array[]} An InnerBlocks template.
 */
export const filterTemplate = ( taxonomies, hiddenTaxonomies = [] ) =>
	offeredTaxonomies( taxonomies )
		.filter( ( t ) => ! hiddenTaxonomies.includes( t.slug ) )
		.map( ( t ) => [ 'photopress/image-filter', { taxonomy: t.slug } ] );
