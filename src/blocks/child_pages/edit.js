/**
 * External dependencies
 */
import classnames from 'classnames';
import includes from 'lodash/includes';
import { find, isUndefined, pickBy, some } from 'lodash';

/**
 * Internal dependencies
 */
import InspectorControls from './inspector';
import icons from './icons';
import icon from './icon';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { Component, RawHTML, Fragment } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { dateI18n, format, __experimentalGetSettings } from '@wordpress/date';
import { withSelect } from '@wordpress/data';
import { BlockControls, RichText, BlockIcon } from '@wordpress/block-editor';
import {
	Placeholder,
	Spinner,
	Toolbar,
	TextControl,
	Button,
	Disabled,
	ServerSideRender,
} from '@wordpress/components';

/**
 * Module Constants
 */
const CATEGORIES_LIST_QUERY = {
	per_page: -1,
};

const TokenList = wp.tokenList;

const styleOptions = [
	{
		name: 'stacked',
		/* translators: block style */
		label: __( 'Stacked', 'coblocks' ),
		icon: icons.styleStacked,
		isDefault: true,
	},
	{
		name: 'horizontal',
		/* translators: block style */
		label: __( 'Horizontal', 'coblocks' ),
		icon: icons.styleHorizontalImageRight,
		iconAlt: icons.styleHorizontalImageLeft,
	},
];

/**
 * Returns the active style from the given className.
 *
 * @param {Array} styles Block style variations.
 * @param {string} className  Class name
 *
 * @return {Object?} The active style.
 */
function getActiveStyle( styles, className ) {
	for ( const style of new TokenList( className ).values() ) {
		if ( style.indexOf( 'is-style-' ) === -1 ) {
			continue;
		}

		const potentialStyleName = style.substring( 9 );
		const activeStyle = find( styles, { name: potentialStyleName } );

		if ( activeStyle ) {
			return activeStyle;
		}
	}

	return find( styles, 'isDefault' );
}

/**
 * Replaces the active style in the block's className.
 *
 * @param {string}  className   Class name.
 * @param {Object?} activeStyle The replaced style.
 * @param {Object}  newStyle    The replacing style.
 *
 * @return {string} The updated className.
 */
function replaceActiveStyle( className, activeStyle, newStyle ) {
	const list = new TokenList( className );

	if ( activeStyle ) {
		list.remove( 'is-style-' + activeStyle.name );
	}

	list.add( 'is-style-' + newStyle.name );

	return list.value;
}

class PostsEdit extends Component {
	
	constructor() {
		
		super( ...arguments );
		//console.log(this.props);
		this.state = {
			categoriesList: [],
			editing: ! this.props.attributes.externalRssUrl,
			lastColumnValue: null,

			stackedDefaultColumns: 2,
			horizontalDefaultColumns: 1,
			userModifiedColumn: false,
		};
		

		if (
			( this.props.className.includes( 'is-style-stacked' ) && this.props.attributes.columns !== this.state.stackedDefaultColumns ) ||
			( this.props.className.includes( 'is-style-horizontal' ) && this.props.attributes.columns !== this.state.horizontalDefaultColumns )
		) {
			this.state.userModifiedColumn = true;
		}

		this.onUserModifiedColumn = this.onUserModifiedColumn.bind( this );
		this.onSubmitURL = this.onSubmitURL.bind( this );
		this.updateStyle = this.updateStyle.bind( this );
	
		// makes the pges display as soon as block is loaded.
		this.props.setAttributes( { postFeedType: 'internal' });
		
	}

	componentDidMount() {
		
		const { className } = this.props;
		const activeStyle = getActiveStyle( styleOptions, className );

		this.updateStyle( activeStyle );

		this.isStillMounted = true;
		this.fetchRequest = apiFetch( {
			path: addQueryArgs( '/wp/v2/categories', CATEGORIES_LIST_QUERY ),
		} ).then(
			( categoriesList ) => {
				if ( this.isStillMounted ) {
					this.setState( { categoriesList } );
				}
			}
		).catch(
			() => {
				if ( this.isStillMounted ) {
					this.setState( { categoriesList: [] } );
				}
			}
		);
	}

	componentWillUnmount() {
		this.isStillMounted = false;
	}

	componentDidUpdate( prevProps ) {
		const { displayPostContent, displayPostLink } = this.props.attributes;
		if ( displayPostLink && ! displayPostContent ) {
			this.props.setAttributes( {
				displayPostLink: false,
			} );
		}

		if ( this.props.className !== prevProps.className ) {
			if ( this.props.className.includes( 'is-style-stacked' ) ) {
				this.props.setAttributes( { columns: this.state.userModifiedColumn ? this.props.attributes.columns : this.state.stackedDefaultColumns } );
			}

			if ( this.props.className.includes( 'is-style-horizontal' ) ) {
				this.props.setAttributes( { columns: this.state.userModifiedColumn ? this.props.attributes.columns : this.state.horizontalDefaultColumns } );
			}
		}
	}

	onUserModifiedColumn() {
		this.setState( { userModifiedColumn: true } );
	}

	onSubmitURL( event ) {
		event.preventDefault();

		const { externalRssUrl } = this.props.attributes;
		if ( externalRssUrl ) {
			this.setState( { editing: false } );
		}
	}

	updateStyle( style ) {
		const { setAttributes, attributes, className } = this.props;

		const activeStyle = getActiveStyle( styleOptions, className );
		const updatedClassName = replaceActiveStyle(
			attributes.className,
			activeStyle,
			style
		);

		setAttributes( { className: updatedClassName } );
	}

	render() {
		
		const {
			attributes,
			setAttributes,
			className,
			latestPosts,
		} = this.props;
		
		const { categoriesList } = this.state;

		const activeStyle = getActiveStyle( styleOptions, className );

		const {
			displayPostContent,
			displayPostDate,
			displayPostLink,
			postLink,
			postFeedType,
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
		
		const editToolbarControls = [
			{
				icon: 'edit',
				title: __( 'Edit RSS URL', 'coblocks' ),
				onClick: () => this.setState( { editing: true } ),
			},
		];

		const hasPosts = Array.isArray( latestPosts ) && latestPosts.length;

		const displayPosts = Array.isArray( latestPosts ) && latestPosts.length > postsToShow ? latestPosts.slice( 0, postsToShow ) : latestPosts;

		const hasFeaturedImage = some( displayPosts, 'featured_media_object' );

		const toolbarControls = [ {
			
			icon: icons.imageLeft,
			title: __( 'Image on left', 'coblocks' ),
			isActive: listPosition === 'left',
			onClick: () => setAttributes( { listPosition: 'left' } ),
			
		}, {
			
			icon: icons.imageRight,
			title: __( 'Image on right', 'coblocks' ),
			isActive: listPosition === 'right',
			onClick: () => setAttributes( { listPosition: 'right' } ),
			
		} ];

		if ( ! hasPosts && postFeedType === 'internal' ) {
			
			return (
				
				<Fragment>
					<InspectorControls
						{ ...this.props }
						attributes={ attributes }
						hasPosts={ hasPosts }
						hasFeaturedImage={ hasFeaturedImage }
						editing={ this.state.editing }
						activeStyle={ activeStyle }
						styleOptions={ styleOptions }
						onUpdateStyle={ this.updateStyle }
						categoriesList={ categoriesList }
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
					onUserModifiedColumn={ this.onUserModifiedColumn }
					attributes={ attributes }
					hasPosts={ hasPosts }
					hasFeaturedImage={ hasFeaturedImage }
					editing={ this.state.editing }
					activeStyle={ activeStyle }
					styleOptions={ styleOptions }
					onUpdateStyle={ this.updateStyle }
					categoriesList={ categoriesList }
					postCount={ latestPosts && latestPosts.length }
					
				/>
				
				{ postFeedType === 'internal' &&

					<div className={ className }>
					
						<div className={ classnames( 'wp-block-photopress-childpages__inner' ) }>
						
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
									
									<div key={ i } className="wp-block-photopress-childpages__item" style={ { padding: padding } }>
										
										
										{ featuredImageUrl &&
											<div className="wp-block-photopress-childpages__image">
											<Disabled>
												<a href={ post.link } target="_blank" rel="noreferrer noopener" alt={ titleTrimmed }>
													<img src={featuredImageUrl} style= { {height: constrain_dim, width: constrain_dim } } />
												</a>
												</Disabled>
											</div>
										}
										
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
										
										
										
									</div>
								);
							} ) }
						</div>
					</div>
				}
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