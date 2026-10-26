<?php
/**
 * CouponAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityResources;

/**
 * Tests for the coupons-query, coupon-create and coupon-update abilities.
 */
class CouponAbilitiesTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should find a draft coupon by exact code, which wc_get_coupon_id_by_code() misses.
	 */
	public function test_query_finds_code_in_any_status(): void {
		$coupon = $this->create_coupon( 'SPRING', array( 'status' => 'draft' ) );

		$result = $this->run_ability( 'woocommerce/coupons-query', array( 'code' => 'spring' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( $coupon->get_id() ), array_column( $result['coupons'], 'id' ) );
		$this->assertFalse( $result['coupons'][0]['redeemable'] );
		$this->assertSame( 'not_published', $result['coupons'][0]['not_redeemable_reason'] );
	}

	/**
	 * @testdox Should return an empty page for an unknown code.
	 */
	public function test_query_returns_empty_for_unknown_code(): void {
		$result = $this->run_ability( 'woocommerce/coupons-query', array( 'code' => 'nope' ) );

		$this->assertSame( array(), $result['coupons'] );
	}

	/**
	 * @testdox Should explain why a coupon cannot be redeemed.
	 *
	 * @testWith ["expired"]
	 *           ["usage_limit_reached"]
	 *           ["scheduled"]
	 *
	 * @param string $reason Expected reason.
	 */
	public function test_query_explains_not_redeemable( string $reason ): void {
		$props  = array(
			'expired'             => array( 'date_expires' => time() - DAY_IN_SECONDS ),
			'usage_limit_reached' => array(
				'usage_limit' => 1,
				'usage_count' => 1,
			),
			'scheduled'           => array(
				'status'       => 'future',
				'date_created' => time() + DAY_IN_SECONDS,
			),
		);
		$coupon = $this->create_coupon( 'C' . $reason, $props[ $reason ] );

		$item = $this->run_ability( 'woocommerce/coupons-query', array( 'id' => $coupon->get_id() ) )['coupons'][0];

		$this->assertSame( $reason, $item['not_redeemable_reason'] );
		$this->assertSame( 'scheduled' === $reason, null !== $item['starts_at'] );
	}

	/**
	 * @testdox Should report the day before a midnight expiry as the last valid day.
	 */
	public function test_query_reports_last_valid_day_for_midnight_expiry(): void {
		$coupon = $this->create_coupon( 'MIDNIGHT', array( 'date_expires' => '2099-03-10T00:00:00' ) );

		$item = $this->run_ability( 'woocommerce/coupons-query', array( 'id' => $coupon->get_id() ) )['coupons'][0];

		$this->assertTrue( $item['redeemable'] );
		$this->assertSame( '2099-03-09', $item['last_valid_day'] );
	}

	/**
	 * @testdox Should filter by discount type and return restrictions.
	 */
	public function test_query_filters_by_discount_type(): void {
		$percent = $this->create_coupon(
			'PCT',
			array(
				'discount_type'      => 'percent',
				'product_categories' => array( 7 ),
				'exclude_sale_items' => true,
			)
		);
		$this->create_coupon( 'FIXED', array( 'discount_type' => 'fixed_cart' ) );

		$result = $this->run_ability( 'woocommerce/coupons-query', array( 'discount_type' => 'percent' ) );

		$this->assertSame( array( $percent->get_id() ), array_column( $result['coupons'], 'id' ) );
		$this->assertSame( array( 7 ), $result['coupons'][0]['product_categories'] );
		$this->assertSame( array(), $result['coupons'][0]['email_restrictions'] );
		$this->assertTrue( $result['coupons'][0]['exclude_sale_items'] );
	}

	/**
	 * @testdox Should leave email restrictions out of the output and the schema while personal data is withheld.
	 */
	public function test_query_withholds_email_restrictions(): void {
		$coupon = $this->create_coupon( 'VIP', array( 'email_restrictions' => array( 'jane@example.com' ) ) );
		$this->withhold_personal_data();

		$item = $this->run_ability( 'woocommerce/coupons-query', array( 'id' => $coupon->get_id() ) )['coupons'][0];

		$this->assertArrayNotHasKey( 'email_restrictions', $item );
		$this->assertArrayNotHasKey( 'email_restrictions', wp_get_ability( 'woocommerce/coupons-query' )->get_output_schema()['properties']['coupons']['items']['properties'] );
	}

	/**
	 * @testdox Should create a published coupon and refuse a code that another coupon uses.
	 */
	public function test_create_publishes_and_refuses_taken_code(): void {
		$result = $this->run_ability(
			'woocommerce/coupon-create',
			array(
				'code'          => 'NEW10',
				'discount_type' => 'percent',
				'amount'        => '10',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'publish', $result['coupon']['status'] );
		$this->assertSame( '10', $result['coupon']['amount'] );

		$again = $this->run_ability( 'woocommerce/coupon-create', array( 'code' => 'new10' ) );
		$this->assertWPError( $again );
		$this->assertSame( 'woocommerce_coupon_code_exists', $again->get_error_code() );
	}

	/**
	 * @testdox Should update only the given fields.
	 */
	public function test_update_changes_given_fields(): void {
		$coupon = $this->create_coupon( 'KEEP', array( 'amount' => '5' ) );

		$result = $this->run_ability(
			'woocommerce/coupon-update',
			array(
				'id'            => $coupon->get_id(),
				'free_shipping' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['coupon']['free_shipping'] );
		$this->assertSame( '5', $result['coupon']['amount'] );
	}

	/**
	 * @testdox Should refuse an update without fields.
	 */
	public function test_update_requires_a_field(): void {
		$coupon = $this->create_coupon( 'EMPTY' );

		$this->assertWPError( $this->run_ability( 'woocommerce/coupon-update', array( 'id' => $coupon->get_id() ) ) );
	}

	/**
	 * @testdox Should refuse coupon abilities to a user without coupon capabilities.
	 */
	public function test_requires_coupon_capabilities(): void {
		$coupon = $this->create_coupon( 'LOCKED' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/coupons-query' ) );
		$this->assertWPError( $this->run_ability( 'woocommerce/coupon-create', array( 'code' => 'X' ) ) );
		$this->assertWPError(
			$this->run_ability(
				'woocommerce/coupon-update',
				array(
					'id'     => $coupon->get_id(),
					'amount' => '1',
				)
			)
		);
	}

	/**
	 * @testdox Should read and save an extension field with the coupon in one write.
	 */
	public function test_extension_field_round_trip(): void {
		$this->add_test_field(
			'coupon',
			static function ( \WC_Coupon $coupon ) {
				$code = $coupon->get_meta( '_test_code' );
				return '' === $code ? null : $code;
			},
			array(
				'update_callback' => static function ( \WC_Coupon $coupon, $value ) {
					$coupon->update_meta_data( '_test_code', $value );
				},
			)
		);

		$result = $this->run_ability(
			'woocommerce/coupon-create',
			array(
				'code'       => 'EXT',
				'extensions' => array( 'test-ext' => array( 'code' => 'abc' ) ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'abc', $result['coupon']['extensions']['test-ext']['code'] );
		$this->assertSame( 'abc', ( new \WC_Coupon( $result['coupon']['id'] ) )->get_meta( '_test_code' ) );
	}

	/**
	 * @testdox Should save an update that only sends extension fields.
	 */
	public function test_update_accepts_only_extension_fields(): void {
		$this->add_test_field(
			'coupon',
			static fn( \WC_Coupon $coupon ) => $coupon->get_meta( '_test_code' ) ? $coupon->get_meta( '_test_code' ) : null,
			array(
				'update_callback' => static function ( \WC_Coupon $coupon, $value ) {
					$coupon->update_meta_data( '_test_code', $value );
				},
			)
		);
		$coupon = $this->create_coupon( 'EXTONLY' );

		$result = $this->run_ability(
			'woocommerce/coupon-update',
			array(
				'id'         => $coupon->get_id(),
				'extensions' => array( 'test-ext' => array( 'code' => 'xyz' ) ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'xyz', ( new \WC_Coupon( $coupon->get_id() ) )->get_meta( '_test_code' ) );
	}

	/**
	 * @testdox Should save nothing when a validator refuses the changed coupon.
	 */
	public function test_validator_refuses_change(): void {
		update_option( 'woocommerce_feature_' . AbilityResources::FEATURE_ID . '_enabled', 'yes' );
		add_action(
			'woocommerce_extensible_abilities_init',
			static function () {
				AbilityResources::register_validator(
					array(
						'resource'  => 'coupon',
						'namespace' => 'test-ext',
						'callback'  => static function ( \WC_Coupon $coupon ) {
							return 'percent' === $coupon->get_discount_type() && (float) $coupon->get_amount() > 50
								? new \WP_Error( 'test_discount_too_high', 'Too generous.' )
								: null;
						},
					)
				);
			}
		);
		$coupon = $this->create_coupon(
			'CAPPED',
			array(
				'discount_type' => 'percent',
				'amount'        => '10',
			)
		);

		$result = $this->run_ability(
			'woocommerce/coupon-update',
			array(
				'id'     => $coupon->get_id(),
				'amount' => '80',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'test_discount_too_high', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( '10', ( new \WC_Coupon( $coupon->get_id() ) )->get_amount() );
	}

	/**
	 * @testdox Should apply an extension query filter to the coupons query.
	 */
	public function test_query_applies_extension_query_filter(): void {
		$tagged = $this->create_coupon( 'TAGGED' );
		$other  = $this->create_coupon( 'OTHER' );
		$tagged->update_meta_data( '_test_campaign', 'spring' );
		$tagged->save();
		update_option( 'woocommerce_feature_' . AbilityResources::FEATURE_ID . '_enabled', 'yes' );
		add_action(
			'woocommerce_extensible_abilities_init',
			static function () use ( $other ) {
				AbilityResources::register_query_filter(
					array(
						'resource'  => 'coupon',
						'namespace' => 'test-ext',
						'filter'    => 'campaign',
						'schema'    => array( 'type' => 'string' ),
						'callback'  => static fn( string $campaign ) => array(
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test fixture.
							'meta_query'  => array(
								array(
									'key'   => '_test_campaign',
									'value' => $campaign,
								),
							),
							'exclude_ids' => array( $other->get_id() ),
						),
					)
				);
			}
		);

		$result = $this->run_ability(
			'woocommerce/coupons-query',
			array( 'filters' => array( 'extensions' => array( 'test-ext' => array( 'campaign' => 'spring' ) ) ) )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array( $tagged->get_id() ), array_column( $result['coupons'], 'id' ) );
	}

	/**
	 * @testdox Should leave the schemas without extensions while the feature is off.
	 */
	public function test_schemas_unchanged_with_feature_off(): void {
		$this->assertFalse( AbilityResources::is_enabled() );

		$this->assertArrayNotHasKey( 'extensions', wp_get_ability( 'woocommerce/coupon-create' )->get_input_schema()['properties'] );
		$this->assertArrayNotHasKey( 'extensions', wp_get_ability( 'woocommerce/coupons-query' )->get_output_schema()['properties']['coupons']['items']['properties'] );
	}

	/**
	 * Create a coupon.
	 *
	 * @param string $code  Coupon code.
	 * @param array  $props Props to set, keyed by setter suffix.
	 * @return \WC_Coupon
	 */
	private function create_coupon( string $code, array $props = array() ): \WC_Coupon {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_status( 'publish' );
		foreach ( $props as $prop => $value ) {
			$coupon->{"set_{$prop}"}( $value );
		}
		$coupon->save();

		return $coupon;
	}
}
