<?php
/**
 * Coupon create ability class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ActionableAbility;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\CouponAbilityTrait;
use Automattic\WooCommerce\Internal\Abilities\Resources\CouponResource;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce coupon create ability. It runs the steps of ActionableAbility.
 */
class CouponCreate extends ActionableAbility {

	use CouponAbilityTrait;

	public const ABILITY_NAME = 'woocommerce/coupon-create';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Create coupon', 'woocommerce' ),
			'description'         => __( 'Create a coupon. It is published unless a status is given.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => self::get_coupon_writable_properties_schema(),
				'required'             => array( 'code' ),
				'additionalProperties' => false,
			),
			'permission_callback' => array( __CLASS__, 'can_create_coupon' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		);
	}

	/**
	 * Resource that the ability changes.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return CouponResource::NAME;
	}

	/**
	 * A new, unsaved coupon.
	 *
	 * @param array $input Ability input.
	 * @return \WC_Coupon
	 */
	public function load( array $input ) {
		unset( $input );
		$coupon = new \WC_Coupon();
		$coupon->set_status( 'publish' );

		return $coupon;
	}

	/**
	 * Set the coupon properties in memory.
	 *
	 * @param \WC_Coupon $subject Coupon.
	 * @param array      $input   Ability input.
	 * @return null|\WP_Error
	 */
	public function change( $subject, array $input ) {
		return self::set_coupon_props_from_input( $subject, $input );
	}

	/**
	 * Check coupon create access.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_create_coupon( $input = array() ): bool {
		unset( $input );
		return self::check_coupon_permission( array(), 'create' );
	}
}
