<?php
/**
 * Tax rate resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * The `tax_rate` resource. Its subject is a row of WC_Tax::get_rates_for_tax_class(),
 * which carries its `postcode` and `city` lists.
 *
 * @since 11.4.0
 */
class TaxRateResource {

	public const NAME = 'tax_rate';

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
	 * Fields of a rate, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_fields(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return array(
			'id'        => array(
				'schema'       => array( 'type' => 'integer' ),
				'get_callback' => static fn( \stdClass $rate ) => (int) $rate->tax_rate_id,
			),
			'country'   => array(
				'schema'       => array(
					'type'        => 'string',
					'description' => __( 'Empty for every country.', 'woocommerce' ),
				),
				'get_callback' => static fn( \stdClass $rate ) => (string) $rate->tax_rate_country,
			),
			'state'     => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \stdClass $rate ) => (string) $rate->tax_rate_state,
			),
			'postcodes' => array(
				'schema'       => $list,
				'get_callback' => static fn( \stdClass $rate ) => array_values( (array) ( $rate->postcode ?? array() ) ),
			),
			'cities'    => array(
				'schema'       => $list,
				'get_callback' => static fn( \stdClass $rate ) => array_values( (array) ( $rate->city ?? array() ) ),
			),
			'rate'      => array(
				'schema'       => array(
					'type'        => 'string',
					'description' => __( 'Percentage as a decimal string.', 'woocommerce' ),
				),
				'get_callback' => static fn( \stdClass $rate ) => (string) $rate->tax_rate,
			),
			'name'      => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \stdClass $rate ) => (string) $rate->tax_rate_name,
			),
			'priority'  => array(
				'schema'       => array( 'type' => 'integer' ),
				'get_callback' => static fn( \stdClass $rate ) => (int) $rate->tax_rate_priority,
			),
			'compound'  => array(
				'schema'       => array( 'type' => 'boolean' ),
				'get_callback' => static fn( \stdClass $rate ) => (bool) $rate->tax_rate_compound,
			),
			'shipping'  => array(
				'schema'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the rate also applies to shipping.', 'woocommerce' ),
				),
				'get_callback' => static fn( \stdClass $rate ) => (bool) $rate->tax_rate_shipping,
			),
			'class'     => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \stdClass $rate ) => '' === (string) $rate->tax_rate_class ? 'standard' : (string) $rate->tax_rate_class,
			),
		);
	}
}
