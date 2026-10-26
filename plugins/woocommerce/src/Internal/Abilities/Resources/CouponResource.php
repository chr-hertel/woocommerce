<?php
/**
 * Coupon resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

defined( 'ABSPATH' ) || exit;

/**
 * The `coupon` resource: the fields that abilities return for a coupon, and
 * how a coupon is loaded and checked.
 *
 * @extends DataResource<\WC_Coupon>
 *
 * @since 11.4.0
 */
class CouponResource extends DataResource {

	public const NAME = 'coupon';

	/**
	 * Resource name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Class of a coupon.
	 *
	 * @return class-string<\WC_Coupon>
	 */
	public function get_data_class(): string {
		return \WC_Coupon::class;
	}

	/**
	 * Check a permission against the `shop_coupon` post type, which the resource name does not match.
	 *
	 * @param int    $id      Coupon ID, or 0 for a check that is not about one coupon.
	 * @param string $context Permission context, such as `read` or `edit`.
	 * @return bool
	 */
	public function can( int $id, string $context ): bool {
		return wc_rest_check_post_permissions( 'shop_coupon', $context, $id );
	}

	/**
	 * Look up a coupon. WC_Coupon reads any post ID without complaint, so the post type is checked first.
	 *
	 * @param int $id Coupon ID.
	 * @return \WC_Coupon|null
	 */
	protected function find( int $id ) {
		return 'shop_coupon' === get_post_type( $id ) ? new \WC_Coupon( $id ) : null;
	}

	/**
	 * Messages for a missing coupon ID and a coupon that is not found.
	 *
	 * @return array{id_required: string, not_found: string}
	 */
	protected function get_messages(): array {
		return array(
			'id_required' => __( 'Coupon ID is required.', 'woocommerce' ),
			'not_found'   => __( 'Coupon not found.', 'woocommerce' ),
		);
	}

	/**
	 * Post status slugs that the abilities read and write.
	 *
	 * @since 11.4.0
	 *
	 * @return array<int, string>
	 */
	public static function get_status_slugs(): array {
		return array( 'publish', 'draft', 'pending', 'private', 'future' );
	}

	/**
	 * Why a coupon cannot be redeemed right now, or null when it can. Mirrors the checks of WC_Discounts.
	 *
	 * @since 11.4.0
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return string|null
	 */
	public static function get_not_redeemable_reason( \WC_Coupon $coupon ): ?string {
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
	 * Fields of a coupon, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_fields(): array {
		$date      = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
		$id_list   = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);
		$int_list  = static fn( array $ids ) => array_map( 'intval', $ids );
		$limit     = static fn( $value ) => $value ? (int) $value : null;
		$iso       = static fn( ?\WC_DateTime $value ) => $value ? $value->format( 'c' ) : null;
		$amount    = static function ( string $getter ) {
			return static function ( \WC_Coupon $coupon ) use ( $getter ) {
				$value = $coupon->$getter( 'edit' );
				return '' === $value ? null : wc_format_decimal( $value );
			};
		};
		$nullable  = array( 'type' => array( 'integer', 'null' ) );
		$decimal   = array( 'type' => array( 'string', 'null' ) );
		$boolean   = array( 'type' => 'boolean' );
		$id_fields = array();
		foreach ( array( 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories' ) as $prop ) {
			$id_fields[ $prop ] = $this->prop( $prop, $id_list, array( 'get' => $int_list ) );
		}

		return array_merge(
			array(
				'id'                    => $this->prop( 'id', array( 'type' => 'integer' ) ),
				'code'                  => $this->prop( 'code', array( 'type' => 'string' ) ),
				'status'                => $this->prop( 'status', array( 'type' => 'string' ), array( 'get' => 'strval' ) ),
				'discount_type'         => $this->prop(
					'discount_type',
					array(
						'type' => 'string',
						'enum' => array_keys( wc_get_coupon_types() ),
					)
				),
				'amount'                => $this->prop( 'amount', array( 'type' => 'string' ), array( 'get' => 'wc_format_decimal' ) ),
				'description'           => $this->prop( 'description', array( 'type' => 'string' ) ),
				'redeemable'            => array(
					'schema'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a customer can use the coupon now, before cart rules such as minimum spend.', 'woocommerce' ),
					),
					'get_callback' => static fn( \WC_Coupon $coupon ) => null === self::get_not_redeemable_reason( $coupon ),
				),
				'not_redeemable_reason' => array(
					'schema'       => array(
						'type' => array( 'string', 'null' ),
						'enum' => array( 'expired', 'usage_limit_reached', 'scheduled', 'not_published', null ),
					),
					'get_callback' => array( self::class, 'get_not_redeemable_reason' ),
				),
				'date_expires'          => $this->prop( 'date_expires', $date, array( 'get' => $iso ) ),
				'last_valid_day'        => array(
					'schema'       => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Last store-local day the coupon works. A coupon expiring at midnight stops working at the start of that day.', 'woocommerce' ),
						'format'      => 'date',
					),
					'get_callback' => array( self::class, 'get_last_valid_day' ),
				),
				'starts_at'             => array(
					'schema'       => array_merge( $date, array( 'description' => __( 'When a scheduled coupon becomes usable.', 'woocommerce' ) ) ),
					'get_callback' => static fn( \WC_Coupon $coupon ) => 'future' === $coupon->get_status() ? $iso( $coupon->get_date_created() ) : null,
				),
				'usage_count'           => $this->prop( 'usage_count', array( 'type' => 'integer' ) ),
				'usage_limit'           => $this->prop( 'usage_limit', $nullable, array( 'get' => $limit ) ),
				'usage_limit_per_user'  => $this->prop( 'usage_limit_per_user', $nullable, array( 'get' => $limit ) ),
				'individual_use'        => $this->prop( 'individual_use', $boolean ),
				'free_shipping'         => $this->prop( 'free_shipping', $boolean ),
				'exclude_sale_items'    => $this->prop( 'exclude_sale_items', $boolean ),
				'minimum_amount'        => array(
					'schema'       => $decimal,
					'get_callback' => $amount( 'get_minimum_amount' ),
				),
				'maximum_amount'        => array(
					'schema'       => $decimal,
					'get_callback' => $amount( 'get_maximum_amount' ),
				),
			),
			$id_fields,
			array(
				'email_restrictions' => $this->prop(
					'email_restrictions',
					array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					array(
						'get'           => 'array_values',
						'personal_data' => true,
					)
				),
				'date_created'       => $this->prop( 'date_created', $date, array( 'get' => $iso ) ),
				'date_modified'      => $this->prop( 'date_modified', $date, array( 'get' => $iso ) ),
			)
		);
	}

	/**
	 * The last store-local day a coupon works. A coupon that expires at midnight cannot be used on that day, so it is the day before.
	 *
	 * @since 11.4.0
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return string|null
	 */
	public static function get_last_valid_day( \WC_Coupon $coupon ): ?string {
		$expires = $coupon->get_date_expires();
		if ( ! $expires ) {
			return null;
		}
		if ( '00:00:00' !== $expires->date( 'H:i:s' ) ) {
			return $expires->date( 'Y-m-d' );
		}
		$day = wp_date( 'Y-m-d', $expires->getTimestamp() - DAY_IN_SECONDS );
		return false === $day ? null : $day;
	}
}
