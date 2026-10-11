/**
 * A feature on a settings page, as a card: its name, with an info button at
 * the right for a longer explanation; a larger switch at the left with a
 * sentence saying what it does; and, when it is on, its settings below.
 */
import { useState } from '@wordpress/element';
import { Button, FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { info as infoIcon } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

export default function FeatureSection( { title, description, info, checked, onChange, disabled = false, className = '', children } ) {
	const id = 'photopress-feature-' + useInstanceId( FeatureSection );
	const [ open, setOpen ] = useState( false );

	return (
		<section className={ `photopress-feature ${ className }` } aria-labelledby={ id + '-title' }>
			<header className="photopress-feature__header">
				<h2 id={ id + '-title' }>{ title }</h2>
				{ info && (
					<Button
						icon={ infoIcon }
						label={ sprintf( __( 'About %s' ), title ) }
						aria-expanded={ open }
						aria-controls={ id + '-info' }
						onClick={ () => setOpen( ! open ) }
						className="photopress-feature__info-button"
					/>
				) }
			</header>

			{ info && open && <div id={ id + '-info' } className="photopress-feature__info">{ info }</div> }

			<div className="photopress-feature__switch">
				<FormToggle
					id={ id }
					checked={ !! checked }
					disabled={ disabled }
					aria-label={ title }
					aria-describedby={ id + '-description' }
					onChange={ ( event ) => onChange( event.target.checked ) }
				/>
				<label htmlFor={ id } id={ id + '-description' }>{ description }</label>
			</div>

			{ checked && <div className="photopress-feature__settings">{ children }</div> }
		</section>
	);
}
