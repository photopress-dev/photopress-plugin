<?php

namespace PhotoPress\modules\media;

use WP_Error;

/**
 * The AWS calls PhotoPress makes, finding a CloudFront distribution by its
 * domain, invalidating paths, and deleting files Offload Media no longer
 * tracks, through the AWS SDK that WP Offload Media bundles.
 * The invalidations are for Offload Media's CDN, so the SDK is there whenever
 * they are needed; available() says whether it is.
 *
 * Credentials are looked for in this order:
 *
 *   1. PHOTOPRESS_AWS_ACCESS_KEY_ID / PHOTOPRESS_AWS_SECRET_ACCESS_KEY in wp-config.php;
 *   2. the server's own IAM role, on EC2;
 *   3. Offload Media's: AS3CF_SETTINGS, AS3CF_AWS_* or AWS_* constants.
 *
 * Offload Media's credentials come last as they often only allow S3.
 */
class CloudFront {

	const SDK = '\\DeliciousBrains\\WP_Offload_Media\\Aws3\\Aws\\';

	public static function available() {

		return class_exists( self::SDK . 'CloudFront\\CloudFrontClient' ) && class_exists( self::SDK . 'Credentials\\CredentialProvider' );
	}

	/**
	 * Credentials and where they came from. For CloudFront, Offload Media's
	 * come last, as they often only allow S3; for S3, they come first, as
	 * they are the ones made for its bucket.
	 *
	 * @param string $service "cloudfront" or "s3".
	 * @return array|null key, secret, token, source.
	 */
	public static function credentials( $service = 'cloudfront' ) {

		$sources = 's3' === $service
			? [ 'offloadMediaCredentials', 'photopressCredentials', 'instanceRoleCredentials' ]
			: [ 'photopressCredentials', 'instanceRoleCredentials', 'offloadMediaCredentials' ];

		foreach ( $sources as $source ) {

			$credentials = self::$source();

			if ( $credentials ) {
				return $credentials;
			}
		}

		return null;
	}

	protected static function photopressCredentials() {

		if ( defined( 'PHOTOPRESS_AWS_ACCESS_KEY_ID' ) && defined( 'PHOTOPRESS_AWS_SECRET_ACCESS_KEY' ) ) {
			return [
				'key'    => PHOTOPRESS_AWS_ACCESS_KEY_ID,
				'secret' => PHOTOPRESS_AWS_SECRET_ACCESS_KEY,
				'token'  => defined( 'PHOTOPRESS_AWS_SESSION_TOKEN' ) ? PHOTOPRESS_AWS_SESSION_TOKEN : null,
				'source' => 'photopress',
			];
		}

		return null;
	}

	protected static function offloadMediaCredentials() {

		$settings = defined( 'AS3CF_SETTINGS' ) ? maybe_unserialize( AS3CF_SETTINGS ) : [];

		if ( ! empty( $settings['access-key-id'] ) && ! empty( $settings['secret-access-key'] ) ) {
			return [ 'key' => $settings['access-key-id'], 'secret' => $settings['secret-access-key'], 'token' => null, 'source' => 'offload-media' ];
		}

		foreach ( [ 'AS3CF_AWS', 'AWS' ] as $prefix ) {
			if ( defined( "{$prefix}_ACCESS_KEY_ID" ) && defined( "{$prefix}_SECRET_ACCESS_KEY" ) ) {
				return [ 'key' => constant( "{$prefix}_ACCESS_KEY_ID" ), 'secret' => constant( "{$prefix}_SECRET_ACCESS_KEY" ), 'token' => null, 'source' => 'offload-media' ];
			}
		}

		return null;
	}

	/**
	 * The EC2 instance's role credentials, from the SDK's instance profile
	 * provider, cached until shortly before they expire. Off EC2 there are
	 * none; that is remembered for an hour so no request waits on it again.
	 */
	protected static function instanceRoleCredentials() {

		$cached = get_transient( 'photopress_aws_role_credentials' );

		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		if ( ! self::available() ) {
			return null;
		}

		$provider = self::SDK . 'Credentials\\CredentialProvider';

		try {
			$found = call_user_func( $provider::instanceProfile( [ 'timeout' => 1, 'retries' => 0 ] ) )->wait();
		} catch ( \Throwable $e ) {
			set_transient( 'photopress_aws_role_credentials', [], HOUR_IN_SECONDS );
			return null;
		}

		$credentials = [ 'key' => $found->getAccessKeyId(), 'secret' => $found->getSecretKey(), 'token' => $found->getSecurityToken(), 'source' => 'instance-role' ];
		$expires = $found->getExpiration();
		set_transient( 'photopress_aws_role_credentials', $credentials, $expires ? max( 60, $expires - time() - 5 * MINUTE_IN_SECONDS ) : HOUR_IN_SECONDS );

		return $credentials;
	}

	/**
	 * The ID of the distribution serving $domain: one with it as an alias,
	 * or whose own domain it is.
	 *
	 * @return string|null|WP_Error Null when there is none.
	 */
	public static function findDistribution( $domain ) {

		return self::call( static function ( $client ) use ( $domain ) {

			$marker = null;

			do {
				$list = $client->listDistributions( array_filter( [ 'MaxItems' => '100', 'Marker' => $marker ] ) )['DistributionList'];

				foreach ( $list['Items'] ?? [] as $distribution ) {

					$names = array_merge( [ $distribution['DomainName'] ], $distribution['Aliases']['Items'] ?? [] );

					if ( in_array( strtolower( $domain ), array_map( 'strtolower', $names ), true ) ) {
						return $distribution['Id'];
					}
				}

				$marker = ! empty( $list['IsTruncated'] ) ? $list['NextMarker'] : null;
			} while ( $marker );

			return null;
		} );
	}

	/**
	 * @param string[] $paths Paths beginning with /, * allowed at the end.
	 * @return string|WP_Error The invalidation's ID.
	 */
	public static function invalidate( $distribution, array $paths ) {

		return self::call( static function ( $client ) use ( $distribution, $paths ) {

			$result = $client->createInvalidation( [
				'DistributionId'    => $distribution,
				'InvalidationBatch' => [
					'Paths'           => [ 'Quantity' => count( $paths ), 'Items' => array_values( $paths ) ],
					'CallerReference' => 'photopress-' . wp_generate_uuid4(),
				],
			] );

			return $result['Invalidation']['Id'];
		} );
	}

	/**
	 * Deletes objects from a bucket, in batches of 1,000 (S3's limit).
	 *
	 * @param string[] $keys
	 * @return true|WP_Error
	 */
	public static function deleteObjects( $bucket, $region, array $keys ) {

		return self::call( static function ( $client ) use ( $bucket, $keys ) {

			foreach ( array_chunk( array_values( $keys ), 1000 ) as $chunk ) {
				$client->deleteObjects( [
					'Bucket' => $bucket,
					'Delete' => [ 'Objects' => array_map( static fn( $key ) => [ 'Key' => $key ], $chunk ), 'Quiet' => true ],
				] );
			}

			return true;
		}, 's3', $region ?: 'us-east-1' );
	}

	/**
	 * Runs $call with a CloudFront (or S3) client; an AWS error as a WP_Error.
	 */
	protected static function call( callable $call, $service = 'cloudfront', $region = 'us-east-1' ) {

		if ( ! self::available() ) {
			return new WP_Error( 'photopress_aws_sdk_missing', __( 'The AWS SDK bundled with WP Offload Media was not found.' ) );
		}

		$credentials = self::credentials( $service );

		if ( ! $credentials ) {
			return new WP_Error( 'photopress_aws_no_credentials', __( 'No AWS credentials found.' ) );
		}

		$client_class = 's3' === $service ? self::SDK . 'S3\\S3Client' : self::SDK . 'CloudFront\\CloudFrontClient';
		$credentials_class = self::SDK . 'Credentials\\Credentials';

		try {
			$client = new $client_class( [
				'version'     => 's3' === $service ? '2006-03-01' : '2020-05-31',
				'region'      => $region,
				'credentials' => new $credentials_class( $credentials['key'], $credentials['secret'], $credentials['token'] ),
			] );

			return $call( $client );

		} catch ( \Throwable $e ) {
			$message = method_exists( $e, 'getAwsErrorMessage' ) && $e->getAwsErrorMessage() ? $e->getAwsErrorMessage() : $e->getMessage();
			$code = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
			return new WP_Error( 'photopress_aws_error', sprintf( '%s (%s): %s', 's3' === $service ? 'S3' : 'CloudFront', $credentials['source'], $message ), [ 'aws_code' => $code ] );
		}
	}
}
