<?php
/**
 * Shipping zone resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;
use Automattic\WooCommerce\Internal\Abilities\SecretFields;

defined( 'ABSPATH' ) || exit;

/**
 * The `shipping_zone` resource, and the `shipping_method` resource that its
 * `methods` field refers to. Methods are WC_Shipping_Method objects, not WC_Data.
 *
 * @extends DataResource<\WC_Shipping_Zone>
 *
 * @since 11.4.0
 */
class ShippingZoneResource extends DataResource {

	public const NAME = 'shipping_zone';

	public const METHOD = 'shipping_method';

	/**
	 * Resource name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Class of a zone.
	 *
	 * @return class-string<\WC_Shipping_Zone>
	 */
	public function get_data_class(): string {
		return \WC_Shipping_Zone::class;
	}

	/**
	 * Register the zone and the shipping methods it holds.
	 */
	public function register(): void {
		parent::register();
		AbilityResources::register( self::METHOD );
		foreach ( self::get_method_fields() as $field => $args ) {
			$args['resource'] = self::METHOD;
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Zones are settings, not posts.
	 *
	 * @param int    $id      Zone ID.
	 * @param string $context Permission context, such as `read` or `edit`.
	 * @return bool
	 */
	public function can( int $id, string $context ): bool {
		unset( $id );
		return wc_rest_check_manager_permissions( 'settings', $context );
	}

	/**
	 * Look up a zone.
	 *
	 * @param int $id Zone ID.
	 * @return \WC_Data|null|false
	 */
	protected function find( int $id ) {
		$zone = \WC_Shipping_Zones::get_zone( $id );
		return $zone instanceof \WC_Shipping_Zone ? $zone : null;
	}

	/**
	 * Messages for a missing zone ID and a zone that is not found.
	 *
	 * @return array{id_required: string, not_found: string}
	 */
	protected function get_messages(): array {
		return array(
			'id_required' => __( 'Shipping zone ID is required.', 'woocommerce' ),
			'not_found'   => __( 'Shipping zone not found.', 'woocommerce' ),
		);
	}

	/**
	 * Fields of a zone, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_fields(): array {
		return array(
			'id'        => $this->prop( 'id', array( 'type' => 'integer' ) ),
			'name'      => $this->prop( 'zone_name', array( 'type' => 'string' ) ),
			'order'     => $this->prop( 'zone_order', array( 'type' => 'integer' ) ),
			'locations' => $this->prop(
				'zone_locations',
				array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'code' => array(
								'type'        => 'string',
								'description' => __( 'Country code, COUNTRY:STATE, postcode pattern or continent code, by type.', 'woocommerce' ),
							),
							'type' => array(
								'type' => 'string',
								'enum' => array( 'country', 'state', 'postcode', 'continent' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				array(
					'get' => static fn( array $locations ) => array_map(
						static fn( $location ) => array(
							'code' => (string) $location->code,
							'type' => (string) $location->type,
						),
						array_values( $locations )
					),
				)
			),
			'methods'   => $this->prop(
				'shipping_methods',
				array(
					'type'  => 'array',
					'items' => array( '$ref' => self::METHOD ),
				)
			),
		);
	}

	/**
	 * Fields of a shipping method, in output order. Settings keep scalar values only, without secrets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_method_fields(): array {
		return array(
			'instance_id' => array(
				'schema'       => array( 'type' => 'integer' ),
				'get_callback' => static fn( \WC_Shipping_Method $method ) => (int) $method->get_instance_id(),
			),
			'method_id'   => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WC_Shipping_Method $method ) => (string) $method->id,
			),
			'title'       => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WC_Shipping_Method $method ) => (string) $method->get_title(),
			),
			'enabled'     => array(
				'schema'       => array( 'type' => 'boolean' ),
				'get_callback' => static fn( \WC_Shipping_Method $method ) => 'yes' === $method->enabled,
			),
			'settings'    => array(
				'schema'       => array(
					'type'                 => 'object',
					'description'          => __( 'Method settings such as cost or min_amount, as stored.', 'woocommerce' ),
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'get_callback' => static function ( \WC_Shipping_Method $method ) {
					$settings = array();
					foreach ( (array) $method->instance_settings as $key => $value ) {
						$type = (string) ( $method->instance_form_fields[ $key ]['type'] ?? '' );
						if ( is_scalar( $value ) && 'title' !== $key && ! SecretFields::is_secret( (string) $key, $type ) ) {
							$settings[ $key ] = (string) $value;
						}
					}
					return (object) $settings;
				},
			),
		);
	}
}
