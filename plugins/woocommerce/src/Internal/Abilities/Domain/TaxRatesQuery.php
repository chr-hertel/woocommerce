<?php
/**
 * Tax rates query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\QueryAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\TaxRateResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists tax rates.
 */
class TaxRatesQuery extends QueryAbility {

	public const ABILITY_NAME = 'woocommerce/tax-rates-query';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query tax rates', 'woocommerce' ),
			'description'         => __( 'List the tax rates of every tax class, in the order WooCommerce matches them.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'country'  => array(
						'type'        => 'string',
						'description' => __( 'Two-letter country code. Rates for every country are included too.', 'woocommerce' ),
						'pattern'     => '^[A-Za-z]{2}$',
					),
					'class'    => array(
						'type'        => 'string',
						'description' => __( 'Tax class slug; standard for the standard rates.', 'woocommerce' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'permission_callback' => array( __CLASS__, 'can_query_taxes' ),
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
		return TaxRateResource::NAME;
	}

	/**
	 * Key of the list in the output.
	 *
	 * @return string
	 */
	protected function get_collection_key(): string {
		return 'rates';
	}

	/**
	 * Find a page of rates. The rates are read per class and paged in memory, so extension
	 * filters can only narrow them by ID.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	protected function query( array $input ) {
		$extension_args = $this->get_extension_query_args( $input );
		if ( is_wp_error( $extension_args ) ) {
			return $extension_args;
		}
		if ( isset( $extension_args['meta_query'] ) ) {
			return new \WP_Error(
				'woocommerce_ability_extension_filter_failed',
				__( 'Tax rate filters can only return IDs.', 'woocommerce' ),
				array( 'status' => 500 )
			);
		}

		$classes = array_merge( array( 'standard' ), \WC_Tax::get_tax_class_slugs() );
		if ( isset( $input['class'] ) ) {
			$classes = array_intersect( $classes, array( sanitize_title( (string) $input['class'] ) ) );
		}
		$country = isset( $input['country'] ) ? strtoupper( (string) $input['country'] ) : '';

		$rates = array();
		foreach ( $classes as $tax_class ) {
			foreach ( (array) \WC_Tax::get_rates_for_tax_class( 'standard' === $tax_class ? '' : $tax_class ) as $rate ) {
				if (
					$rate instanceof \stdClass
					&& ( '' === $country || '' === $rate->tax_rate_country || $country === $rate->tax_rate_country )
					&& ( ! isset( $extension_args['post__in'] ) || in_array( (int) $rate->tax_rate_id, $extension_args['post__in'], true ) )
					&& ! in_array( (int) $rate->tax_rate_id, $extension_args['post__not_in'] ?? array(), true )
				) {
					$rates[] = $rate;
				}
			}
		}

		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 50 );

		return array(
			'items'       => array_slice( $rates, ( $page - 1 ) * $per_page, $per_page ),
			'total_pages' => (int) ceil( count( $rates ) / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Check tax settings read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_taxes(): bool {
		return wc_rest_check_manager_permissions( 'settings', 'read' );
	}
}
