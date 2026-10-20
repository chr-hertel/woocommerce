<?php
/**
 * CustomersQueryTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the customers-query ability.
 */
class CustomersQueryTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should return a customer by ID with contact details and spend.
	 */
	public function test_returns_customer_by_id(): void {
		$customer = $this->create_customer( 'jane' );

		$result = $this->run_ability( 'woocommerce/customers-query', array( 'include' => array( $customer->get_id() ) ) );

		$this->assertNotWPError( $result );
		$item = $result['customers'][0];
		$this->assertSame( 'jane@example.com', $item['email'] );
		$this->assertSame( 'Main St 1', $item['billing']['address_1'] );
		$this->assertSame( 'US', $item['billing']['country'] );
		$this->assertSame( 0, $item['order_count'] );
	}

	/**
	 * @testdox Should find customers by email.
	 */
	public function test_finds_customer_by_email(): void {
		$wanted = $this->create_customer( 'jane' );
		$this->create_customer( 'john' );

		$result = $this->run_ability( 'woocommerce/customers-query', array( 'email' => 'jane@example.com' ) );

		$this->assertSame( array( $wanted->get_id() ), array_column( $result['customers'], 'id' ) );
	}

	/**
	 * @testdox Should keep only non-identifying fields while personal data is withheld.
	 */
	public function test_withholds_personal_data(): void {
		$customer = $this->create_customer( 'jane' );
		$this->withhold_personal_data();

		$item = $this->run_ability( 'woocommerce/customers-query', array( 'include' => array( $customer->get_id() ) ) )['customers'][0];

		foreach ( array( 'email', 'first_name', 'last_name', 'username' ) as $key ) {
			$this->assertNull( $item[ $key ], "{$key} should be withheld." );
		}
		$this->assertNull( $item['billing']['address_1'] );
		$this->assertNull( $item['billing']['phone'] );
		$this->assertSame( 'US', $item['billing']['country'] );
		$this->assertStringNotContainsString( 'jane', (string) wp_json_encode( $item ) );
	}

	/**
	 * @testdox Should refuse lookups by search or email while personal data is withheld.
	 *
	 * @testWith ["search", "jane"]
	 *           ["email", "jane@example.com"]
	 *
	 * @param string $param Lookup input.
	 * @param string $value Lookup value.
	 */
	public function test_refuses_lookups_when_withheld( string $param, string $value ): void {
		$this->create_customer( 'jane' );
		$this->withhold_personal_data();

		$result = $this->run_ability( 'woocommerce/customers-query', array( $param => $value ) );

		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_ability_customer_data_withheld', $result->get_error_code() );
	}

	/**
	 * @testdox Should add extension fields to each customer while the feature is on.
	 */
	public function test_adds_extension_fields(): void {
		$customer = $this->create_customer( 'jane' );
		$this->add_test_field(
			'customer',
			static function ( \WC_Customer $customer ) {
				return 'tier-' . $customer->get_id();
			}
		);

		$item = $this->run_ability( 'woocommerce/customers-query', array( 'include' => array( $customer->get_id() ) ) )['customers'][0];

		$this->assertSame( 'tier-' . $customer->get_id(), $item['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should refuse a user who cannot list users.
	 */
	public function test_requires_user_permissions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertNotWPError( $this->run_ability( 'woocommerce/customers-query' ), 'Shop managers can read customers.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertWPError( $this->run_ability( 'woocommerce/customers-query' ) );
	}

	/**
	 * Create a customer with a billing address.
	 *
	 * @param string $name Username and email local part.
	 * @return \WC_Customer
	 */
	private function create_customer( string $name ): \WC_Customer {
		$customer = new \WC_Customer();
		$customer->set_username( $name );
		$customer->set_email( "{$name}@example.com" );
		$customer->set_password( 'secret' );
		$customer->set_first_name( ucfirst( $name ) );
		$customer->set_billing_address_1( 'Main St 1' );
		$customer->set_billing_phone( '555-0100' );
		$customer->set_billing_country( 'US' );
		$customer->save();

		return $customer;
	}
}
