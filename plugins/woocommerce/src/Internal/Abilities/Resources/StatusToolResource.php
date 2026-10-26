<?php
/**
 * Status tool resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * The `status_tool` resource: a maintenance tool under WooCommerce > Status > Tools.
 * Its subject is the tool's array from the tools controller with its key as `id`.
 *
 * @since 11.4.0
 */
class StatusToolResource {

	public const NAME = 'status_tool';

	/**
	 * Register the resource and its fields.
	 *
	 * @since 11.4.0
	 */
	public function register(): void {
		AbilityResources::register( self::NAME );
		foreach ( self::get_fields() as $field => $args ) {
			$args['resource'] = self::NAME;
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Fields of a tool, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_fields(): array {
		return array(
			'id'          => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( array $tool ) => (string) $tool['id'],
			),
			'name'        => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( array $tool ) => wp_strip_all_tags( (string) ( $tool['name'] ?? '' ) ),
			),
			'description' => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( array $tool ) => wp_strip_all_tags( (string) ( $tool['desc'] ?? '' ) ),
			),
		);
	}
}
