<?php
/**
 * Payment gateways query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ResourceAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\PaymentGatewayResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists payment gateways. Gateways come from memory
 * as one unpaged list, so it is a ResourceAbility, not a QueryAbility.
 */
class PaymentGatewaysQuery extends ResourceAbility {

	public const ABILITY_NAME = 'woocommerce/payment-gateways-query';

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
	 * Resource the ability returns.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return PaymentGatewayResource::NAME;
	}

	/**
	 * List payment gateways. This is the execute callback of the ability.
	 *
	 * @internal
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function query_gateways( $input = null ) {
		$input    = is_array( $input ) ? $input : array();
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

		$gateways = array_filter(
			$gateways,
			static fn( $gateway ) => $gateway instanceof \WC_Payment_Gateway && ( empty( $input['enabled_only'] ) || 'yes' === $gateway->enabled )
		);

		return $this->prepare_output( array( 'gateways' => array_values( $gateways ) ) );
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

	/**
	 * Use the gateway list as the execute callback.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		$args['execute_callback'] = array( $this, 'query_gateways' );
		return parent::prepare_properties( $args );
	}

	/**
	 * The gateways as the resource, in checkout order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array(
			'gateways' => array(
				'type'  => 'array',
				'items' => array( '$ref' => PaymentGatewayResource::NAME ),
			),
		);
	}
}
