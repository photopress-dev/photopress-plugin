/**
 * External dependencies
 */
import classnames from 'classnames';
import { find, isUndefined, pickBy, some } from 'lodash';

/**
 * Internal dependencies
 */
import InspectorControls from './inspector';
import icon from './icon';


/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { Component, RawHTML, Fragment } from '@wordpress/element';
import { withSelect } from '@wordpress/data';
import { BlockControls, RichText, BlockIcon } from '@wordpress/block-editor';
import {
	Placeholder,
	Spinner,
	Toolbar,
	TextControl,
	Button,
	Disabled
} from '@wordpress/components';

class PostsEdit extends Component {
	
	constructor() {
		
		super( ...arguments );
		
		this.state = {
			
		};

	}

	componentDidMount() {
				
	}

	componentWillUnmount() {
	
	}

	componentDidUpdate( prevProps ) {
		
	}

	render() {
		
		const {
			attributes,
			setAttributes,
			className,
			latestPosts,
		} = this.props;
		
		const { categoriesList } = this.state;

		const {
			align,
			imageCrop,
			displayPostContent,
			displayPostDate,
			postLink,
			externalRssUrl,
			columns,
			padding,
			postsToShow,
			excerptLength,
			listPosition,
			imageSize,
			imageStyle,
			gutter,
		} = attributes;
		
		

		const hasPosts = Array.isArray( latestPosts ) && latestPosts.length;

		const displayPosts = Array.isArray( latestPosts ) && latestPosts.length > postsToShow ? latestPosts.slice( 0, postsToShow ) : latestPosts;

		const hasFeaturedImage = some( displayPosts, 'featured_media_object' );

		if ( ! hasPosts  ) {
			
			return (
				
				<Fragment>
					<InspectorControls
						{ ...this.props }
						attributes={ attributes }
						hasPosts={ hasPosts }
						hasFeaturedImage={ hasFeaturedImage }
						postCount={ latestPosts && latestPosts.length }
					/>
					<Placeholder
						icon={ <BlockIcon icon={ icon } /> }
						label={ __( 'Child Pages', 'photopress' ) }
					>
						{ ! Array.isArray( latestPosts ) ?
							<Spinner /> :
							<Fragment>
								{ __( 'No child pages found for this page. Add some child pages. ', 'photopress' ) }
								
							</Fragment>
						}
					</Placeholder>
				</Fragment>
			);
		}

		return (
			
			<Fragment>
			
				<InspectorControls
					{ ...this.props }
					attributes={ attributes }
					hasPosts={ hasPosts }
					hasFeaturedImage={ hasFeaturedImage }
					postCount={ latestPosts && latestPosts.length }
					
				/>
				
				<figure className={'photopress-gallery photopress-childpages'}>
		
					<ul 
						className={ classnames( 'photopress-gallery-columns', {
							[ `align${ align }` ]: align,
							[ `columns-${ columns }` ]: columns,
							'is-cropped': imageCrop,
						} ) }
						style={ {"--pp-gallery-gutter": padding + 'px'} } 
					>
					
						{ displayPosts.map( ( post, i ) => {
							
							const mediaWidth = post.featured_media_object ? post.featured_media_object.media_details.sizes[ attributes.imageSize ].width  : null;
							
							const mediaHeight = post.featured_media_object ? post.featured_media_object.media_details.sizes[ attributes.imageSize ].height  : null;
							
							let constrain_dim = 100;
							
							if ( mediaHeight > mediaWidth ) {
								
								constrain_dim = mediaHeight;
								
							} else {
								
								constrain_dim = mediaWidth;
							}
							
							constrain_dim = constrain_dim + 'px';
							
							const featuredImageUrl = post.featured_media_object ? post.featured_media_object.source_url : null;
							
							const contentClasses = classnames( 'wp-block-photopress-childpages__content');

							const titleTrimmed = post.title.rendered.trim();

							return (
								
								<li key={ i } className="photopress-gallery-item ">
									
									{ featuredImageUrl &&
										
										<figure className="photopress-gallery-item__figure flex-column">
										
											<Disabled>
											
												<a href={ post.link } target="_blank" rel="noreferrer noopener" alt={ titleTrimmed }>
													
													<img 
														className={ 'wp-image' }
														src={featuredImageUrl} 
														style= { {minHeight: constrain_dim, minWidth: constrain_dim, maxHeight: constrain_dim, maxWidth: constrain_dim } } 
													/>
													
												</a>
												
											</Disabled>
											
											<div className={ contentClasses }>
											
												<Disabled>									
												
													<a href={ post.link } target="_blank" rel="noreferrer noopener" alt={ titleTrimmed }>
														{ titleTrimmed ? (
															<RawHTML>
																{ titleTrimmed }
															</RawHTML>
														) :
															/* translators: placeholder when a post has no title */
															__( '(no title)', 'photopress' )
														}
													</a>
												
												</Disabled>
												
											</div>
											
										</figure>
									}
									
								</li>
							);
							
						} ) }
						
					</ul>
					
				</figure>
				
			</Fragment>
		);
	}
}

export default compose( [
	withSelect( ( select, props ) => {

		const { postsToShow, order, orderBy, categories, padding } = props.attributes;	
		const { getCurrentPostId } = select( 'core/editor' );
		const { getEntityRecords } = select( 'core' );
		const latestPostsQuery = pickBy( {
			categories,
			order,
			orderby: orderBy,
			per_page: postsToShow,
			parent: getCurrentPostId()
		}, ( value ) => ! isUndefined( value ) );
		
		let latestPosts = getEntityRecords( 'postType', 'page', latestPostsQuery );
		if ( latestPosts ) {
			latestPosts = latestPosts.map( ( post ) => {
				return {
					...post,
					featured_media_object: post.featured_media && select( 'core' ).getMedia( post.featured_media ),
					
				};
			} );
		}

		return {
			latestPosts,
		};
	} ),
] )( PostsEdit );