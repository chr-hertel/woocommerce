<?php
/**
 * SettingsAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the store-settings-get and settings-query abilities.
 */
class SettingsAbilitiesTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should return the curated store settings without the merchant's email.
	 */
	public function test_store_settings_leave_out_store_email(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'US', 'CA' ) );
		update_option(
			'woocommerce_onboarding_profile',
			array(
				'industry'    => array( 'fashion' ),
				'store_email' => 'owner@example.com',
			)
		);

		$result = $this->run_ability( 'woocommerce/store-settings-get' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'CA', $result['address']['state_code'] );
		$this->assertSame( array( 'US', 'CA' ), $result['selling_locations']['countries'] );
		$this->assertSame( 'same_as_selling', $result['shipping_locations']['scope'] );
		$this->assertSame( array( 'fashion' ), $result['onboarding_profile']['industry'] );
		$this->assertArrayNotHasKey( 'store_email', $result['onboarding_profile'] );
	}

	/**
	 * @testdox Should list settings groups without a group, and a group's settings with one.
	 */
	public function test_settings_query_lists_groups_and_settings(): void {
		update_option( 'woocommerce_store_city', 'Lisbon' );

		$groups   = $this->run_ability( 'woocommerce/settings-query' );
		$settings = $this->run_ability(
			'woocommerce/settings-query',
			array(
				'group' => 'general',
				'ids'   => array( 'woocommerce_store_city' ),
			)
		);

		$this->assertContains( 'general', array_column( $groups['groups'], 'id' ) );
		$this->assertSame( array( 'Lisbon' ), array_column( $settings['settings'], 'value' ) );
	}

	/**
	 * @testdox Should never return a password-type or secret-named setting, also in an extension's group.
	 */
	public function test_settings_query_leaves_out_secrets(): void {
		update_option( 'test_ext_api_key', 'sk_live_123' );
		update_option( 'test_ext_pass', 'hunter2' );
		update_option( 'test_ext_mode', 'live' );
		add_filter(
			'woocommerce_settings_groups',
			static function ( array $groups ): array {
				$groups[] = array(
					'id'    => 'test-ext',
					'label' => 'Test extension',
				);
				return $groups;
			}
		);
		add_filter(
			'woocommerce_settings-test-ext',
			static function (): array {
				return array(
					array(
						'id'         => 'test_ext_api_key',
						'option_key' => 'test_ext_api_key',
						'label'      => 'API key',
						'type'       => 'text',
					),
					array(
						'id'         => 'test_ext_pass',
						'option_key' => 'test_ext_pass',
						'label'      => 'Login',
						'type'       => 'password',
					),
					array(
						'id'         => 'test_ext_mode',
						'option_key' => 'test_ext_mode',
						'label'      => 'Mode',
						'type'       => 'text',
					),
				);
			}
		);

		$result = $this->run_ability( 'woocommerce/settings-query', array( 'group' => 'test-ext' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'test_ext_mode' ), array_column( $result['settings'], 'id' ) );
		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'sk_live_123', $json );
		$this->assertStringNotContainsString( 'hunter2', $json );
	}

	/**
	 * @testdox Should refuse settings to a user who cannot manage WooCommerce.
	 */
	public function test_requires_settings_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/store-settings-get' ) );
		$this->assertWPError( $this->run_ability( 'woocommerce/settings-query' ) );
	}
}
