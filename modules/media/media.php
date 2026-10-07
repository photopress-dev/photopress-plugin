<?php

namespace PhotoPress\modules\media;
use photopress_module;

/**
 * Media Module
 *
 * A REST route that lets publishing tools give an image a new file, keeping
 * the image. See MediaRest.
 */
class media extends photopress_module {

	public $label = 'Media';

	public function definePublicHooks() {

		MediaRest::addHooks();
	}
}
