/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;
import { Component, Fragment, useState } from '@wordpress/element';


import {
	BaseControl,
	Button,
	ExternalLink,
	Panel,
	PanelBody,
	PanelRow,
	Placeholder,
	Spinner,
	ToggleControl,
	Notice,
	Disabled,
	CheckboxControl,
	SelectControl,
	TextControl,
	RangeControl
} from '@wordpress/components';

import {
	
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting
	
} from '../shared/options.js';
/**
 * Metadata Options Component class
 */
class SlideshowSettings extends Component {
	
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
			settings: this.props.data
		};
	}
	
	componentDidMount() {
		
		
	}
			
	render() {
		
		const MyNotice = () => (
		    <Notice status="error">
		        <p>An error occurred: <code>{ '' }</code>.</p>
		    </Notice>
		);
		
		const rows = [];
		
		const enable = () => (
			
			<BaseControl
				label={ __( '' ) }
				help={ '' }		
				id="'slideshow_enable'"
				className=""
			>
			
				{ this.getError('slideshow') &&
					
					<Notice status="error">
				        <p>An error occurred: <code>{ this.getError('slideshow') }</code>.</p>
				    </Notice>	
									
				}
				
			
				<ToggleControl
					id={'slideshow_enable'}
					label={ __( 'Enable Slideshows' ) }
					help={ 'Display image slideshow when gallery images are clicked.' }
					checked={ this.getSetting('enable') || false }
					onChange={ ( value ) => this.persistSetting( 'enable', value ) }
				/>
				<hr/>
				<ToggleControl
					id={'showThumbnails'}
					label={ __( 'Show Thumbnails' ) }
					help={ 'Display image thumbnails along bottom of slideshow lightbox.' }
					checked={ this.getSetting('showThumbnails') || false }
					onChange={ ( value ) => this.persistSetting( 'showThumbnails', value ) }
				/>
				<hr/>
				
				<RangeControl
					label={ __( 'Thumbnail Height' ) }
					value={ this.getSetting('thumbnailHeight') }
					onChange={ ( value ) => this.persistSetting( 'thumbnailHeight', value ) }
					min={ 75 }
					max={ 200 }
					step={ 10 }		
				/>
				<hr/>
				<ToggleControl
					id={'showCaptions'}
					label={ __( 'Show Caption Info' ) }
					help={ 'Display image thumbnails along bottom of slideshow lightbox.' }
					checked={ this.getSetting('showCaptions') || false }
					onChange={ ( value ) => this.persistSetting( 'showCaptions', value ) }
				/>
				
				<SelectControl
					label={ __( 'Caption Info Box Position' ) }
					value={ this.getSetting('detail_position') }
					onChange={ ( value ) => this.persistSetting( 'detail_position', value ) }
					options={ 
						[			
							{ value: 'bottom', label: __( 'Bottom of image' ) },
							{ value: 'right', label: __( 'Right of image' ) }
						]
					}
					help={"Controls where the caption/info box is displayed."}
				/>
							
				<CheckboxControl
		            heading=""
		            label="Display image title in caption info"
		            help=""
		            checked={ this.getSetting('showTitleInCaption') }
		            onChange={ ( value ) => this.persistSetting( 'showTitleInCaption', value ) }
		        />
		    
				<CheckboxControl
		            heading=""
		            label="Display image description in caption info"
		            help=""
		            checked={ this.getSetting('showDescriptionInCaption') }
		            onChange={ ( value ) => this.persistSetting( 'showDescriptionInCaption', value ) }
		        />

				
				<hr/>		
				
				
				
			</BaseControl>	

		);
				
		// push render constants into rows array for final rendering. Order matters.
		rows.push( 
			enable
			
		);
		

		return (
			
			<PanelBody title={ __( 'Slideshows' ) }>
						
					{ rows.map( ( val, idx ) => {
					
						let row = val();
						return (
						
						 <PanelRow key={`component-${idx}`}>{row}</PanelRow> 
						 
						 );
						
					})}
					
					
								
			</PanelBody>	
		
		);
	}
}	

export default SlideshowSettings;
