<?php
/**
 * Coupon update ability class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ActionableAbility;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\CouponAbilityTrait;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\DomainAbilityHelpers;
use Automattic\WooCommerce\Internal\Abilities\Resources\CouponResource;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce coupon update ability. It runs the steps of ActionableAbility.
 */
class CouponUpdate extends ActionableAbility {

	use DomainAbilityHelpers;
	use CouponAbilityTrait;

	public const ABILITY_NAME = 'woocommerce/coupon-update';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Update coupon', 'woocommerce' ),
			'description'         => __( 'Change fields of an existing coupon. Fields left out keep their values.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					self::get_coupon_writable_properties_schema()
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'permission_callback' => array( __CLASS__, 'can_update_coupon' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
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
	 * Load the coupon to change.
	 *
	 * @param array $input Ability input.
	 * @return \WC_Data|\WP_Error
	 */
	public function load( array $input ) {
		if ( empty( array_diff( array_keys( $input ), array( 'id' ) ) ) ) {
			return new \WP_Error(
				'woocommerce_coupon_update_no_fields',
				__( 'At least one coupon field is required to update a coupon.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		return $this->load_resource( self::get_id_from_input( $input ) );
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
	 * Check coupon edit access.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_update_coupon( $input = array() ): bool {
		return self::get_id_from_input( $input ) > 0 && self::check_coupon_permission( $input, 'edit' );
	}
}
