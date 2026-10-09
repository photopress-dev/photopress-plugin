import { test, expect, beforeEach, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { hideSetting, keepSettingHidden } from '../../src/variations/hidden-setting';

/**
 * The block sidebar as core/gallery draws it: tools panel items, each with a
 * labelled control; and, outside the sidebar, the canvas.
 */
function sidebar() {
	document.body.innerHTML = `
		<div class="editor-canvas"><div class="components-tools-panel-item"><label>Columns</label></div></div>
		<div class="block-editor-block-inspector">
			<div class="components-tools-panel">
				<div class="components-tools-panel-item" id="columns"><label class="components-base-control__label">Columns</label><input type="range"></div>
				<div class="components-tools-panel-item" id="resolution"><label>Resolution</label><select></select></div>
			</div>
		</div>`;
}

const shown = ( id ) => document.getElementById( id ).style.display !== 'none';

beforeEach( sidebar );

test( 'hides only the item with that label, in the block sidebar', () => {
	hideSetting( document, 'Columns', true );

	expect( shown( 'columns' ) ).toBe( false );
	expect( shown( 'resolution' ) ).toBe( true );
	expect( document.querySelector( '.editor-canvas .components-tools-panel-item' ).style.display ).toBe( '' );

	hideSetting( document, 'Columns', false );
	expect( shown( 'columns' ) ).toBe( true );
} );

test( 'keeps it hidden as the sidebar is redrawn, and shows it again when stopped', async () => {
	vi.spyOn( window, 'requestAnimationFrame' ).mockImplementation( ( callback ) => {
		callback();
		return 1;
	} );

	const stop = keepSettingHidden( document, 'Columns' );
	expect( shown( 'columns' ) ).toBe( false );

	// The sidebar drawn again, as when another block is selected and back.
	sidebar();
	await Promise.resolve();
	expect( shown( 'columns' ) ).toBe( false );

	stop();
	expect( shown( 'columns' ) ).toBe( true );
} );
