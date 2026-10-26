<?php
/**
 * Coupon ability trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain\Traits;

use Automattic\WooCommerce\Abilities\AbilityResources;
use Automattic\WooCommerce\Internal\Abilities\Resources\CouponResource;

defined( 'ABSPATH' ) || exit;

/**
 * Shared coupon helpers for WooCommerce domain abilities.
 */
trait CouponAbilityTrait {

	/**
	 * Find a coupon by code in any status. wc_get_coupon_id_by_code() only finds published coupons.
	 *
	 * @param string $code Coupon code.
	 * @return int Coupon ID, or 0.
	 */
	protected static function find_coupon_id_by_code( string $code ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => CouponResource::get_status_slugs(),
				'title'          => wc_format_coupon_code( $code ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Writable coupon fields, shared by create and update.
	 *
	 * @return array
	 */
	protected static function get_coupon_writable_properties_schema(): array {
		$amount  = array(
			'type'    => 'string',
			'pattern' => '^([0-9]+(\.[0-9]+)?)?$',
		);
		$id_list = array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		return array(
			'code'                        => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'status'                      => array(
				'type' => 'string',
				'enum' => CouponResource::get_status_slugs(),
			),
			'discount_type'               => array(
				'type' => 'string',
				'enum' => array_keys( wc_get_coupon_types() ),
			),
			'amount'                      => $amount,
			'description'                 => array( 'type' => 'string' ),
			'date_expires'                => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Expiry in the store timezone, Y-m-d or ISO 8601. Null removes it.', 'woocommerce' ),
			),
			'usage_limit'                 => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'usage_limit_per_user'        => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'individual_use'              => array( 'type' => 'boolean' ),
			'free_shipping'               => array( 'type' => 'boolean' ),
			'exclude_sale_items'          => array( 'type' => 'boolean' ),
			'minimum_amount'              => $amount,
			'maximum_amount'              => $amount,
			'product_ids'                 => $id_list,
			'excluded_product_ids'        => $id_list,
			'product_categories'          => $id_list,
			'excluded_product_categories' => $id_list,
			'email_restrictions'          => array(
				'type'  => 'array',
				'items' => array(
					'type'   => 'string',
					'format' => 'email',
				),
			),
		);
	}

	/**
	 * Set coupon properties from ability input, in memory.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @param array      $input  Ability input.
	 * @return null|\WP_Error
	 */
	protected static function set_coupon_props_from_input( \WC_Coupon $coupon, array $input ): ?\WP_Error {
		if ( isset( $input['code'] ) ) {
			$code        = wc_format_coupon_code( (string) $input['code'] );
			$existing_id = '' === $code ? 0 : self::find_coupon_id_by_code( $code );
			if ( '' === $code || ( $existing_id > 0 && $existing_id !== $coupon->get_id() ) ) {
				return new \WP_Error(
					'woocommerce_coupon_code_exists',
					__( 'Another coupon already uses this code.', 'woocommerce' ),
					array( 'status' => 400 )
				);
			}
			$coupon->set_code( $code );
		}

		$setters = array(
			'discount_type'               => 'set_discount_type',
			'amount'                      => 'set_amount',
			'date_expires'                => 'set_date_expires',
			'usage_limit'                 => 'set_usage_limit',
			'usage_limit_per_user'        => 'set_usage_limit_per_user',
			'individual_use'              => 'set_individual_use',
			'free_shipping'               => 'set_free_shipping',
			'exclude_sale_items'          => 'set_exclude_sale_items',
			'minimum_amount'              => 'set_minimum_amount',
			'maximum_amount'              => 'set_maximum_amount',
			'product_ids'                 => 'set_product_ids',
			'excluded_product_ids'        => 'set_excluded_product_ids',
			'product_categories'          => 'set_product_categories',
			'excluded_product_categories' => 'set_excluded_product_categories',
			'email_restrictions'          => 'set_email_restrictions',
			'status'                      => 'set_status',
		);

		try {
			foreach ( $setters as $field => $setter ) {
				if ( array_key_exists( $field, $input ) ) {
					$coupon->$setter( $input[ $field ] );
				}
			}
		} catch ( \WC_Data_Exception $exception ) {
			return new \WP_Error( $exception->getErrorCode(), $exception->getMessage(), array( 'status' => 400 ) );
		}

		if ( isset( $input['description'] ) ) {
			$coupon->set_description( wp_kses_post( (string) $input['description'] ) );
		}

		return null;
	}

	/**
	 * Check a coupon permission for an ability input payload.
	 *
	 * @param mixed  $input   Ability input.
	 * @param string $context Permission context, such as `read`, `create` or `edit`.
	 * @return bool
	 */
	protected static function check_coupon_permission( $input, string $context ): bool {
		$resource = AbilityResources::get( CouponResource::NAME );
		$resource = $resource instanceof CouponResource ? $resource : new CouponResource();

		return $resource->can( is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0, $context );
	}
}
