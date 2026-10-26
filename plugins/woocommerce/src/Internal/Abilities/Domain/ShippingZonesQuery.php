<?php
/**
 * Shipping zones query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ResourceAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\ShippingZoneResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists shipping zones with their locations and methods.
 */
class ShippingZonesQuery extends ResourceAbility {

	public const ABILITY_NAME = 'woocommerce/shipping-zones-query';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query shipping zones', 'woocommerce' ),
			'description'         => __( 'List shipping zones in matching order, with the regions each covers and its shipping methods. Zone 0 covers every location no other zone matches.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'include' => array(
						'type'        => 'array',
						'description' => __( 'Return only these zone IDs.', 'woocommerce' ),
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'permission_callback' => array( __CLASS__, 'can_query_shipping' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		);
	}

	/**
	 * Resource the ability returns.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return ShippingZoneResource::NAME;
	}

	/**
	 * List shipping zones, the catch-all zone last. This is the execute callback of the ability.
	 *
	 * @internal
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public function query_zones( $input = null ): array {
		$zones = array();
		foreach ( \WC_Shipping_Zones::get_zones( 'admin' ) as $zone_data ) {
			$zones[] = new \WC_Shipping_Zone( (int) $zone_data['id'] );
		}
		$zones[] = new \WC_Shipping_Zone( 0 );

		if ( isset( $input['include'] ) ) {
			$include = array_map( 'intval', (array) $input['include'] );
			$zones   = array_filter(
				$zones,
				static function ( \WC_Shipping_Zone $zone ) use ( $include ): bool {
					return in_array( $zone->get_id(), $include, true );
				}
			);
		}

		return $this->prepare_output( array( 'zones' => array_values( $zones ) ) );
	}

	/**
	 * Check shipping settings read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_shipping(): bool {
		return wc_rest_check_manager_permissions( 'settings', 'read' );
	}

	/**
	 * Use the zone list as the execute callback.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		$args['execute_callback'] = array( $this, 'query_zones' );
		return parent::prepare_properties( $args );
	}

	/**
	 * The zones as the resource.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array(
			'zones' => array(
				'type'  => 'array',
				'items' => array( '$ref' => ShippingZoneResource::NAME ),
			),
		);
	}
}
