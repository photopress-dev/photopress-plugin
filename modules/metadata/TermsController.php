<?php

namespace PhotoPress\modules\metadata;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Terms_Controller;

/**
 * REST controller of the image taxonomies: WordPress's terms controller, with
 * reading limited to users who can edit posts, and an admin-only taxonomy's
 * to those who can upload images. See ImageTaxonomyRest.
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

		$taxonomy = get_taxonomy( $this->taxonomy );

		// An admin-only taxonomy (its Public switch off): for those who can
		// add images, not every contributor.
		if ( ! ImageTaxonomyRest::canRead() || ( $taxonomy && empty( $taxonomy->public ) && isset( $taxonomy->public ) && ! current_user_can( 'upload_files' ) ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view these terms.' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}
}
