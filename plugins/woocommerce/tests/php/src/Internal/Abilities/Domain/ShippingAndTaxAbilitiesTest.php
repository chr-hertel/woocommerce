<?php
/**
 * ShippingAndTaxAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityResources;

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
		$this->assertSame( array( 'instance_id', 'method_id', 'title', 'enabled', 'settings' ), array_keys( $item['methods'][0] ) );
		$this->assertSame( 'flat_rate', $item['methods'][0]['method_id'] );
		$this->assertSame( '4.50', ( (array) $item['methods'][0]['settings'] )['cost'] );
	}

	/**
	 * @testdox Should leave settings that hold credentials out of a shipping method.
	 */
	public function test_method_settings_leave_out_secrets(): void {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Portugal' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		update_option(
			"woocommerce_flat_rate_{$instance_id}_settings",
			array(
				'cost'    => '4.50',
				'api_key' => 'sk_live_shipping',
			)
		);

		$result = $this->run_ability( 'woocommerce/shipping-zones-query', array( 'include' => array( $zone->get_id() ) ) );

		$settings = (array) $result['zones'][0]['methods'][0]['settings'];
		$this->assertSame( '4.50', $settings['cost'] );
		$this->assertArrayNotHasKey( 'api_key', $settings );
	}

	/**
	 * @testdox Should add extension fields to each zone and to each of its methods while the feature is on.
	 */
	public function test_zones_and_methods_take_extension_fields(): void {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Portugal' );
		$zone->save();
		$zone->add_shipping_method( 'flat_rate' );
		$this->add_test_field(
			'shipping_zone',
			static function ( \WC_Shipping_Zone $zone ) {
				return 'zone-' . $zone->get_id();
			}
		);
		add_action(
			'woocommerce_extensible_abilities_init',
			static function () {
				AbilityResources::register_field(
					array(
						'resource'     => 'shipping_method',
						'namespace'    => 'test-ext',
						'field'        => 'code',
						'schema'       => array( 'type' => 'string' ),
						'get_callback' => static fn( \WC_Shipping_Method $method ) => 'method-' . $method->id,
					)
				);
			}
		);

		$result = $this->run_ability( 'woocommerce/shipping-zones-query', array( 'include' => array( $zone->get_id() ) ) );

		$this->assertSame( 'zone-' . $zone->get_id(), $result['zones'][0]['extensions']['test-ext']['code'] );
		$this->assertSame( 'method-flat_rate', $result['zones'][0]['methods'][0]['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should list tax rates of every class and filter them by country.
	 */
	public function test_tax_rates_filter_by_country(): void {
		$this->insert_german_and_french_rates();

		$result = $this->run_ability( 'woocommerce/tax-rates-query', array( 'country' => 'de' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'MwSt', 'MwSt reduced' ), array_column( $result['rates'], 'name' ) );
		$this->assertSame( 'standard', $result['rates'][0]['class'] );
		$this->assertSame( 'reduced-rate', $result['rates'][1]['class'] );
		$this->assertSame( array( '10115', '10117' ), $result['rates'][1]['postcodes'] );
		$this->assertSame( 1, $result['total_pages'] );
	}

	/**
	 * @testdox Should narrow tax rates to the IDs an extension filter returns.
	 */
	public function test_tax_rates_apply_extension_id_filter(): void {
		$reduced = $this->insert_german_and_french_rates();
		update_option( 'woocommerce_feature_' . AbilityResources::FEATURE_ID . '_enabled', 'yes' );
		add_action(
			'woocommerce_extensible_abilities_init',
			static function () use ( $reduced ) {
				AbilityResources::register_query_filter(
					array(
						'resource'  => 'tax_rate',
						'namespace' => 'test-ext',
						'filter'    => 'reduced',
						'schema'    => array( 'type' => 'boolean' ),
						'callback'  => static fn() => array( 'include_ids' => array( $reduced ) ),
					)
				);
			}
		);

		$result = $this->run_ability( 'woocommerce/tax-rates-query', array( 'filters' => array( 'extensions' => array( 'test-ext' => array( 'reduced' => true ) ) ) ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'MwSt reduced' ), array_column( $result['rates'], 'name' ) );
	}

	/**
	 * @testdox Should refuse shipping and tax reads to a user who cannot manage WooCommerce.
	 */
	public function test_requires_settings_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/shipping-zones-query' ) );
		$this->assertWPError( $this->run_ability( 'woocommerce/tax-rates-query' ) );
	}

	/**
	 * Insert a standard and a reduced German rate and a standard French rate.
	 *
	 * @return int ID of the reduced German rate.
	 */
	private function insert_german_and_french_rates(): int {
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
		return (int) $reduced;
	}
}
