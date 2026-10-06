<?php

namespace PhotoPress\modules\metadata;

use WP_Error;
use WP_REST_Terms_Controller;

/**
 * REST controller of the image taxonomies: WordPress's terms controller, with
 * reading limited to users who can edit posts. See ImageTaxonomyRest.
 */
class TermsController extends WP_REST_Terms_Controller {

	public function get_items_permissions_check( $request ) {

		return $this->gate( parent::get_items_permissions_check( $request ) );
	}

	public function get_item_permissions_check( $request ) {

		return $this->gate( parent::get_item_permissions_check( $request ) );
	}

	private function gate( $allowed ) {

		if ( true !== $allowed ) {
			return $allowed;
		}

		if ( ! ImageTaxonomyRest::canRead() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view these terms.' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}
}
