<?php
/**
 * Coupon ability trait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain\Traits;

use Automattic\WooCommerce\Abilities\ExtensibleAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared coupon helpers for WooCommerce domain ability definitions.
 */
trait CouponAbilityTrait {

	/**
	 * Coupon post statuses the abilities read and write.
	 *
	 * @return array<int, string>
	 */
	protected static function get_coupon_status_slugs(): array {
		return array( 'publish', 'draft', 'pending', 'private', 'future' );
	}

	/**
	 * Get a coupon from ability input.
	 *
	 * @param array $input Ability input.
	 * @return \WC_Coupon|\WP_Error
	 */
	protected static function get_coupon_from_input( array $input ) {
		$coupon_id = (int) ( $input['id'] ?? 0 );

		if ( $coupon_id < 1 || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			return new \WP_Error(
				'woocommerce_coupon_not_found',
				__( 'Coupon not found.', 'woocommerce' ),
				array( 'status' => 404 )
			);
		}

		return new \WC_Coupon( $coupon_id );
	}

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
				'post_status'    => self::get_coupon_status_slugs(),
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
	 * Why a coupon cannot be redeemed right now, or null when it can. Mirrors the checks of WC_Discounts.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return string|null
	 */
	protected static function get_coupon_not_redeemable_reason( \WC_Coupon $coupon ): ?string {
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return 'expired';
		}

		$usage_limit = (int) $coupon->get_usage_limit();
		if ( $usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit ) {
			return 'usage_limit_reached';
		}

		if ( 'future' === $coupon->get_status() ) {
			return 'scheduled';
		}

		return 'publish' === $coupon->get_status() ? null : 'not_published';
	}

	/**
	 * Format a coupon for ability output.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return array
	 */
	protected static function format_coupon_for_response( \WC_Coupon $coupon ): array {
		$expires = $coupon->get_date_expires();
		$reason  = self::get_coupon_not_redeemable_reason( $coupon );
		$created = $coupon->get_date_created();

		// A coupon that expires at midnight cannot be used on that day, so the last valid day is the day before.
		$last_valid_day = null;
		if ( $expires ) {
			$last_valid_day = '00:00:00' === $expires->date( 'H:i:s' )
				? wp_date( 'Y-m-d', $expires->getTimestamp() - DAY_IN_SECONDS )
				: $expires->date( 'Y-m-d' );
		}

		return ExtensibleAbilities::add_fields_to_object(
			array(
				'id'                          => $coupon->get_id(),
				'code'                        => $coupon->get_code(),
				'status'                      => (string) $coupon->get_status(),
				'discount_type'               => $coupon->get_discount_type(),
				'amount'                      => wc_format_decimal( $coupon->get_amount() ),
				'description'                 => $coupon->get_description(),
				'redeemable'                  => null === $reason,
				'not_redeemable_reason'       => $reason,
				'date_expires'                => $expires ? $expires->format( 'c' ) : null,
				'last_valid_day'              => $last_valid_day,
				'starts_at'                   => 'future' === $coupon->get_status() && $created ? $created->format( 'c' ) : null,
				'usage_count'                 => $coupon->get_usage_count(),
				'usage_limit'                 => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
				'usage_limit_per_user'        => $coupon->get_usage_limit_per_user() ? (int) $coupon->get_usage_limit_per_user() : null,
				'individual_use'              => $coupon->get_individual_use(),
				'free_shipping'               => $coupon->get_free_shipping(),
				'exclude_sale_items'          => $coupon->get_exclude_sale_items(),
				'minimum_amount'              => '' === $coupon->get_minimum_amount( 'edit' ) ? null : wc_format_decimal( $coupon->get_minimum_amount( 'edit' ) ),
				'maximum_amount'              => '' === $coupon->get_maximum_amount( 'edit' ) ? null : wc_format_decimal( $coupon->get_maximum_amount( 'edit' ) ),
				'product_ids'                 => array_map( 'intval', $coupon->get_product_ids() ),
				'excluded_product_ids'        => array_map( 'intval', $coupon->get_excluded_product_ids() ),
				'product_categories'          => array_map( 'intval', $coupon->get_product_categories() ),
				'excluded_product_categories' => array_map( 'intval', $coupon->get_excluded_product_categories() ),
				'email_restrictions'          => array_values( $coupon->get_email_restrictions() ),
				'date_created'                => $created ? $created->format( 'c' ) : null,
				'date_modified'               => $coupon->get_date_modified() ? $coupon->get_date_modified()->format( 'c' ) : null,
			),
			'coupon',
			$coupon
		);
	}

	/**
	 * Get the schema for a single coupon in a response.
	 *
	 * @return array
	 */
	protected static function get_coupon_output_schema(): array {
		$date     = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
		$id_list  = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);
		$decimal  = array( 'type' => array( 'string', 'null' ) );
		$nullable = array( 'type' => array( 'integer', 'null' ) );

		return ExtensibleAbilities::add_fields_schema(
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'                          => array( 'type' => 'integer' ),
					'code'                        => array( 'type' => 'string' ),
					'status'                      => array( 'type' => 'string' ),
					'discount_type'               => array(
						'type' => 'string',
						'enum' => array_keys( wc_get_coupon_types() ),
					),
					'amount'                      => array( 'type' => 'string' ),
					'description'                 => array( 'type' => 'string' ),
					'redeemable'                  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a customer can use the coupon now, before cart rules such as minimum spend.', 'woocommerce' ),
					),
					'not_redeemable_reason'       => array(
						'type' => array( 'string', 'null' ),
						'enum' => array( 'expired', 'usage_limit_reached', 'scheduled', 'not_published', null ),
					),
					'date_expires'                => $date,
					'last_valid_day'              => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Last store-local day the coupon works. A coupon expiring at midnight stops working at the start of that day.', 'woocommerce' ),
						'format'      => 'date',
					),
					'starts_at'                   => array_merge( $date, array( 'description' => __( 'When a scheduled coupon becomes usable.', 'woocommerce' ) ) ),
					'usage_count'                 => array( 'type' => 'integer' ),
					'usage_limit'                 => $nullable,
					'usage_limit_per_user'        => $nullable,
					'individual_use'              => array( 'type' => 'boolean' ),
					'free_shipping'               => array( 'type' => 'boolean' ),
					'exclude_sale_items'          => array( 'type' => 'boolean' ),
					'minimum_amount'              => $decimal,
					'maximum_amount'              => $decimal,
					'product_ids'                 => $id_list,
					'excluded_product_ids'        => $id_list,
					'product_categories'          => $id_list,
					'excluded_product_categories' => $id_list,
					'email_restrictions'          => array(
						'type'        => array( 'array', 'null' ),
						'description' => __( 'Null when the store does not share customer data.', 'woocommerce' ),
						'items'       => array( 'type' => 'string' ),
					),
					'date_created'                => $date,
					'date_modified'               => $date,
				),
				'additionalProperties' => false,
			),
			'coupon'
		);
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
				'enum' => self::get_coupon_status_slugs(),
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
		$coupon_id = is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0;

		return wc_rest_check_post_permissions( 'shop_coupon', $context, $coupon_id );
	}
}
