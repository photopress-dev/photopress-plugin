<?php

namespace PhotoPress\jobs;

use WP_REST_Request;
use WP_REST_Server;

/**
 * /photopress/v1/jobs: the job types, starting and canceling jobs, and their
 * progress, for administrators.
 */
class JobsRest {

	public static function registerRoutes() {

		$admin = static fn() => current_user_can( 'manage_options' );

		register_rest_route( 'photopress/v1', '/jobs', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'list' ],
				'permission_callback' => $admin,
				'args'                => [ 'type' => [ 'type' => 'string' ] ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'start' ],
				'permission_callback' => $admin,
				'args'                => [
					'type' => [ 'type' => 'string', 'required' => true ],
					'args' => [ 'type' => 'object', 'default' => [] ],
				],
			],
		] );

		register_rest_route( 'photopress/v1', '/jobs/types', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'types' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( 'photopress/v1', '/jobs/(?P<id>[a-f0-9]{12})', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get' ],
				'permission_callback' => $admin,
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ self::class, 'cancel' ],
				'permission_callback' => $admin,
			],
		] );
	}

	public static function list( WP_REST_Request $request ) {

		return array_map( [ self::class, 'present' ], Jobs::recent( $request['type'] ?: null ) );
	}

	public static function start( WP_REST_Request $request ) {

		$job = Jobs::start( $request['type'], (array) $request['args'] );

		return is_wp_error( $job ) ? $job : self::present( $job );
	}

	public static function types() {

		$types = [];

		foreach ( Jobs::types() as $type => $definition ) {
			$types[] = [ 'type' => $type, 'label' => $definition['label'], 'description' => $definition['description'] ];
		}

		return $types;
	}

	/**
	 * Progress, for a settings page watching the job; runs a batch if one is
	 * due (see Jobs::nudge()).
	 */
	public static function get( WP_REST_Request $request ) {

		$job = Jobs::nudge( $request['id'] );

		return $job ? self::present( $job ) : new \WP_Error( 'photopress_job_not_found', __( 'No such job.' ), [ 'status' => 404 ] );
	}

	public static function cancel( WP_REST_Request $request ) {

		$job = Jobs::cancel( $request['id'] );

		return is_wp_error( $job ) ? $job : self::present( $job );
	}

	/**
	 * A job as the REST API returns it: without its cursor and arguments.
	 */
	public static function present( array $job ) {

		return array_intersect_key( $job, array_flip( [ 'id', 'type', 'label', 'total', 'done', 'failed', 'errors', 'status', 'created', 'updated', 'finished' ] ) );
	}
}
