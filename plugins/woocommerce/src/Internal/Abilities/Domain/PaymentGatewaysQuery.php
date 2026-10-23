<?php
/**
 * Payment gateways query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists payment gateways. Only the fields below
 * leave the store: gateway settings hold credentials, so none of them are read.
 */
class PaymentGatewaysQuery extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/payment-gateways-query';
	}

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query payment gateways', 'woocommerce' ),
			'description'         => __( 'List the payment gateways in checkout order and whether each is enabled. Settings and credentials are never returned.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'           => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9_-]+$',
					),
					'enabled_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'gateways' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'           => array( 'type' => 'string' ),
								'title'        => array(
									'type'        => 'string',
									'description' => __( 'Title shown to customers at checkout.', 'woocommerce' ),
								),
								'description'  => array( 'type' => 'string' ),
								'method_title' => array(
									'type'        => 'string',
									'description' => __( 'Name of the payment method, such as the provider.', 'woocommerce' ),
								),
								'enabled'      => array( 'type' => 'boolean' ),
							),
							'additionalProperties' => false,
						),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( __CLASS__, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_query_gateways' ),
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
	 * List payment gateways.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 *
	 * @since 11.4.0
	 */
	public static function execute( array $input ) {
		$gateways = WC()->payment_gateways()->payment_gateways();

		if ( isset( $input['id'] ) ) {
			if ( ! isset( $gateways[ $input['id'] ] ) ) {
				return new \WP_Error(
					'woocommerce_payment_gateway_not_found',
					__( 'Payment gateway not found.', 'woocommerce' ),
					array( 'status' => 404 )
				);
			}
			$gateways = array( $gateways[ $input['id'] ] );
		}

		$output = array();
		foreach ( $gateways as $gateway ) {
			if ( ! $gateway instanceof \WC_Payment_Gateway ) {
				continue;
			}
			$enabled = 'yes' === $gateway->enabled;
			if ( ! empty( $input['enabled_only'] ) && ! $enabled ) {
				continue;
			}
			$output[] = array(
				'id'           => (string) $gateway->id,
				'title'        => wp_strip_all_tags( (string) $gateway->get_title() ),
				'description'  => wp_strip_all_tags( (string) $gateway->get_description() ),
				'method_title' => wp_strip_all_tags( (string) $gateway->get_method_title() ),
				'enabled'      => $enabled,
			);
		}

		return array( 'gateways' => $output );
	}

	/**
	 * Check payment gateway read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_gateways(): bool {
		return wc_rest_check_manager_permissions( 'payment_gateways', 'read' );
	}
}
