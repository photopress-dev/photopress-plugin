/**
 * External dependencies
 */
import { get, pick } from 'lodash';

export function defaultColumnsNumber( attributes ) {
	return Math.min( 3, attributes.images.length );
}

export const pickRelevantMediaFiles = ( image, sizeSlug = 'large' ) => {
	
	const imageProps = pick( image, [ 'alt', 'id', 'link', 'caption', 'aspectRatio' ] );
	
	imageProps.url =
		get( image, [ 'sizes', sizeSlug, 'url' ] ) ||
		get( image, [ 'media_details', 'sizes', sizeSlug, 'source_url' ] ) ||
		image.url;
	const fullUrl =
		get( image, [ 'sizes', 'full', 'url' ] ) ||
		get( image, [ 'media_details', 'sizes', 'full', 'source_url' ] );
	if ( fullUrl ) {
		imageProps.fullUrl = fullUrl;
	}
	
	
	const width = 
		get( image, [ 'sizes', 'full', 'width' ] ) ||
		get( image, [ 'media_details', 'sizes', 'full', 'width' ] );
		
	const height = 
		get( image, [ 'sizes', 'full', 'height' ] ) ||
		get( image, [ 'media_details', 'sizes', 'full', 'height' ] );
			
	if ( width && height ) {
		
		imageProps.aspectRatio = Math.floor( ( width / height ) * 100) / 100;
	}
	
	return imageProps;
};
