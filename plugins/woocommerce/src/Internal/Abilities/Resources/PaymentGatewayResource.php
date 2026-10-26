<?php
/**
 * Payment gateway resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * The `payment_gateway` resource: the fields that abilities return for a gateway.
 * Gateway settings hold credentials, so no field reads them. Gateways are not
 * WC_Data, so it has no loader and no object permission check.
 *
 * @since 11.4.0
 */
class PaymentGatewayResource {

	public const NAME = 'payment_gateway';

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
	 * Fields of a gateway, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_fields(): array {
		return array(
			'id'           => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WC_Payment_Gateway $gateway ) => (string) $gateway->id,
			),
			'title'        => array(
				'schema'       => array(
					'type'        => 'string',
					'description' => __( 'Title shown to customers at checkout.', 'woocommerce' ),
				),
				'get_callback' => static fn( \WC_Payment_Gateway $gateway ) => wp_strip_all_tags( (string) $gateway->get_title() ),
			),
			'description'  => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WC_Payment_Gateway $gateway ) => wp_strip_all_tags( (string) $gateway->get_description() ),
			),
			'method_title' => array(
				'schema'       => array(
					'type'        => 'string',
					'description' => __( 'Name of the payment method, such as the provider.', 'woocommerce' ),
				),
				'get_callback' => static fn( \WC_Payment_Gateway $gateway ) => wp_strip_all_tags( (string) $gateway->get_method_title() ),
			),
			'enabled'      => array(
				'schema'       => array( 'type' => 'boolean' ),
				'get_callback' => static fn( \WC_Payment_Gateway $gateway ) => 'yes' === $gateway->enabled,
			),
		);
	}
}
