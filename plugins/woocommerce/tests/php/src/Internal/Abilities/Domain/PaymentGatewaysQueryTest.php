<?php
/**
 * PaymentGatewaysQueryTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the payment-gateways-query ability.
 */
class PaymentGatewaysQueryTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should never return a configured gateway's API password or signature.
	 */
	public function test_leaves_out_credentials(): void {
		update_option(
			'woocommerce_bacs_settings',
			array(
				'enabled'       => 'yes',
				'title'         => 'Bank transfer',
				'api_password'  => 'hunter2',
				'api_signature' => 'sig-abc',
			)
		);
		WC()->payment_gateways()->init();

		$result = $this->run_ability( 'woocommerce/payment-gateways-query', array( 'id' => 'bacs' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Bank transfer', $result['gateways'][0]['title'] );
		$this->assertTrue( $result['gateways'][0]['enabled'] );
		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'hunter2', $json );
		$this->assertStringNotContainsString( 'sig-abc', $json );
	}

	/**
	 * @testdox Should return the same gateway fields while personal data is withheld, because none of them is personal.
	 */
	public function test_withholding_personal_data_changes_nothing(): void {
		$this->withhold_personal_data();

		$ability = wp_get_ability( 'woocommerce/payment-gateways-query' );
		$result  = $ability->execute( array( 'id' => 'bacs' ) );

		$expected = array( 'id', 'title', 'description', 'method_title', 'enabled' );
		$this->assertSame( $expected, array_keys( $ability->get_output_schema()['properties']['gateways']['items']['properties'] ) );
		$this->assertSame( $expected, array_keys( $result['gateways'][0] ) );
	}

	/**
	 * @testdox Should return extension fields of a gateway under extensions.
	 */
	public function test_returns_extension_fields(): void {
		$this->add_test_field( 'payment_gateway', static fn( \WC_Payment_Gateway $gateway ) => 'code-' . $gateway->id );

		$result = $this->run_ability( 'woocommerce/payment-gateways-query', array( 'id' => 'bacs' ) );

		$this->assertSame( 'code-bacs', $result['gateways'][0]['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should return an error for an unknown gateway.
	 */
	public function test_unknown_gateway(): void {
		$result = $this->run_ability( 'woocommerce/payment-gateways-query', array( 'id' => 'nope' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_payment_gateway_not_found', $result->get_error_code() );
	}

	/**
	 * @testdox Should list only enabled gateways when asked.
	 */
	public function test_lists_enabled_only(): void {
		update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes' ) );
		update_option( 'woocommerce_cheque_settings', array( 'enabled' => 'no' ) );
		WC()->payment_gateways()->init();

		$result = $this->run_ability( 'woocommerce/payment-gateways-query', array( 'enabled_only' => true ) );

		$ids = array_column( $result['gateways'], 'id' );
		$this->assertContains( 'bacs', $ids );
		$this->assertNotContains( 'cheque', $ids );
	}

	/**
	 * @testdox Should refuse a user who cannot manage WooCommerce.
	 */
	public function test_requires_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/payment-gateways-query' ) );
	}
}
