<?php
/**
 * PersonalDataTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities;

use Automattic\WooCommerce\Abilities\AbilityResources;
use Automattic\WooCommerce\Internal\Abilities\PersonalData;
use Automattic\WooCommerce\Tests\Internal\Abilities\Domain\DomainAbilityTestCase;

/**
 * Personal data in Core's order abilities, with the filter on and off.
 */
class PersonalDataTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should share personal data by default and withhold it for any filter value other than true.
	 */
	public function test_is_shared_fails_closed(): void {
		$this->assertTrue( PersonalData::is_shared( 'order' ) );

		add_filter( PersonalData::FILTER, '__return_null' );

		$this->assertFalse( PersonalData::is_shared( 'order' ), 'A broken callback should withhold personal data.' );
	}

	/**
	 * @testdox Should return the billing email of an order while personal data is shared.
	 */
	public function test_orders_query_returns_billing_email_by_default(): void {
		$order = $this->create_order_with_email();

		$result = $this->run_ability( 'woocommerce/orders-query', array( 'id' => $order->get_id() ) );

		$this->assertSame( 'jane@example.com', $result['orders'][0]['billing_email'] );
		$this->assertArrayHasKey( 'billing_email', $this->get_order_item_schema()['properties'] );
	}

	/**
	 * @testdox Should leave the billing email and personal extension fields out of the output and the output schema while personal data is withheld.
	 */
	public function test_orders_query_withholds_personal_data(): void {
		$order = $this->create_order_with_email();
		$this->add_test_field(
			'order',
			static function ( \WC_Order $order ) {
				return $order->get_billing_phone();
			},
			array( 'personal_data' => true )
		);
		$this->withhold_personal_data();

		$result = $this->run_ability( 'woocommerce/orders-query', array( 'id' => $order->get_id() ) );

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'billing_email', $result['orders'][0] );
		$this->assertArrayNotHasKey( 'extensions', $result['orders'][0], 'A personal extension field should be left out.' );
		$this->assertStringNotContainsString( 'jane@example.com', (string) wp_json_encode( $result ) );
		$schema = $this->get_order_item_schema();
		$this->assertArrayNotHasKey( 'billing_email', $schema['properties'], 'The schema should not tell that the billing email exists.' );
		$this->assertArrayNotHasKey( 'extensions', $schema['properties'], 'The schema should not tell that the personal extension field exists.' );
	}

	/**
	 * @testdox Should keep extension fields that are not personal data while personal data is withheld.
	 */
	public function test_orders_query_keeps_other_extension_fields(): void {
		$order = $this->create_order_with_email();
		$this->add_test_field(
			'order',
			static function () {
				return 'gift';
			}
		);
		$this->withhold_personal_data();

		$result = $this->run_ability( 'woocommerce/orders-query', array( 'id' => $order->get_id() ) );

		$this->assertSame( 'gift', $result['orders'][0]['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should withhold personal data also while the extensible abilities feature is off.
	 */
	public function test_withholds_personal_data_while_extensible_abilities_feature_is_off(): void {
		update_option( 'woocommerce_feature_' . AbilityResources::FEATURE_ID . '_enabled', 'no' );
		$order = $this->create_order_with_email();
		$this->withhold_personal_data();

		$result = $this->run_ability( 'woocommerce/orders-query', array( 'id' => $order->get_id() ) );

		$this->assertArrayNotHasKey( 'billing_email', $result['orders'][0] );
		$this->assertArrayNotHasKey( 'billing_email', $this->get_order_item_schema()['properties'] );
	}

	/**
	 * @testdox Should withhold the billing email from a write ability that formats the order through the shared formatter.
	 */
	public function test_order_write_ability_withholds_personal_data(): void {
		$order = $this->create_order_with_email();
		$this->withhold_personal_data();

		$result = $this->run_ability(
			'woocommerce/order-update-status',
			array(
				'id'     => $order->get_id(),
				'status' => 'completed',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'billing_email', $result['order'] );
	}

	/**
	 * @testdox Should refuse an extension field whose personal_data flag is not a boolean, instead of sharing it.
	 */
	public function test_extension_field_with_invalid_personal_data_flag_is_refused(): void {
		$this->setExpectedIncorrectUsage( AbilityResources::class . '::register_field' );
		$order = $this->create_order_with_email();
		$this->add_test_field(
			'order',
			static function ( \WC_Order $order ) {
				return $order->get_billing_phone();
			},
			array( 'personal_data' => 'yes' )
		);

		$result = $this->run_ability( 'woocommerce/orders-query', array( 'id' => $order->get_id() ) );

		$this->assertArrayNotHasKey( 'extensions', $result['orders'][0] );
	}

	/**
	 * Output schema of one order in woocommerce/orders-query.
	 *
	 * @return array<string, mixed>
	 */
	private function get_order_item_schema(): array {
		return wp_get_ability( 'woocommerce/orders-query' )->get_output_schema()['properties']['orders']['items'];
	}

	/**
	 * Create an order with a billing email.
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_email(): \WC_Order {
		$order = \WC_Helper_Order::create_order();
		$order->set_billing_email( 'jane@example.com' );
		$order->set_billing_phone( '555-0100' );
		$order->save();

		return $order;
	}
}
