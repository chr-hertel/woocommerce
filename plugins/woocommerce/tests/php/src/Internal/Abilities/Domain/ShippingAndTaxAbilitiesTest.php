<?php
/**
 * ShippingAndTaxAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the shipping-zones-query and tax-rates-query abilities.
 */
class ShippingAndTaxAbilitiesTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should return each zone with its locations and methods, plus the catch-all zone.
	 */
	public function test_zones_include_locations_and_methods(): void {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Portugal' );
		$zone->add_location( 'PT', 'country' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		update_option( "woocommerce_flat_rate_{$instance_id}_settings", array( 'cost' => '4.50' ) );

		$result = $this->run_ability( 'woocommerce/shipping-zones-query' );

		$this->assertNotWPError( $result );
		$by_id = array_column( $result['zones'], null, 'id' );
		$this->assertArrayHasKey( 0, $by_id, 'The catch-all zone should be listed.' );
		$item = $by_id[ $zone->get_id() ];
		$this->assertSame(
			array(
				'code' => 'PT',
				'type' => 'country',
			),
			$item['locations'][0]
		);
		$this->assertSame( 'flat_rate', $item['methods'][0]['method_id'] );
		$this->assertSame( '4.50', ( (array) $item['methods'][0]['settings'] )['cost'] );
	}

	/**
	 * @testdox Should add extension fields to each zone while the feature is on.
	 */
	public function test_zones_take_extension_fields(): void {
		$this->add_test_field(
			'shipping_zone',
			static function ( \WC_Shipping_Zone $zone ) {
				return 'zone-' . $zone->get_id();
			}
		);

		$result = $this->run_ability( 'woocommerce/shipping-zones-query', array( 'include' => array( 0 ) ) );

		$this->assertSame( 'zone-0', $result['zones'][0]['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should list tax rates of every class and filter them by country.
	 */
	public function test_tax_rates_filter_by_country(): void {
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'DE',
				'tax_rate'         => '19.0000',
				'tax_rate_name'    => 'MwSt',
				'tax_rate_class'   => '',
			)
		);
		$reduced = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'DE',
				'tax_rate'         => '7.0000',
				'tax_rate_name'    => 'MwSt reduced',
				'tax_rate_class'   => 'reduced-rate',
			)
		);
		\WC_Tax::_update_tax_rate_postcodes( $reduced, '10115; 10117' );
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'FR',
				'tax_rate'         => '20.0000',
				'tax_rate_name'    => 'TVA',
				'tax_rate_class'   => '',
			)
		);

		$result = $this->run_ability( 'woocommerce/tax-rates-query', array( 'country' => 'de' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'MwSt', 'MwSt reduced' ), array_column( $result['rates'], 'name' ) );
		$this->assertSame( 'reduced-rate', $result['rates'][1]['class'] );
		$this->assertSame( array( '10115', '10117' ), $result['rates'][1]['postcodes'] );
	}

	/**
	 * @testdox Should refuse shipping and tax reads to a user who cannot manage WooCommerce.
	 */
	public function test_requires_settings_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/shipping-zones-query' ) );
		$this->assertWPError( $this->run_ability( 'woocommerce/tax-rates-query' ) );
	}
}
