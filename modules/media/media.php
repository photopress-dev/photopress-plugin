<?php

namespace PhotoPress\modules\media;
use photopress_module;

/**
 * Media Module
 *
 * REST routes that let publishing tools find the images they published and
 * give them new files. See MediaRest.
 */
class media extends photopress_module {

	public $label = 'Media';

	public function definePublicHooks() {

		MediaRest::addHooks();
	}
}
