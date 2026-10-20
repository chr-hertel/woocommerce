<?php
/**
 * SystemStatusAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the system-status-get and system-status-tools-query abilities.
 */
class SystemStatusAbilitiesTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should return versions, plugins and features without a log path, database prefix or table names.
	 */
	public function test_status_leaves_out_server_details(): void {
		global $wpdb;

		$result = $this->run_ability( 'woocommerce/system-status-get' );

		$this->assertNotWPError( $result );
		$this->assertSame( WC()->version, $result['woocommerce_version'] );
		$this->assertContains( $result['order_storage'], array( 'hpos', 'posts' ) );
		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( $wpdb->prefix . 'woocommerce_', $json, 'No table names.' );
		$this->assertStringNotContainsString( '"' . $wpdb->prefix . '"', $json, 'No database prefix.' );
		$this->assertStringNotContainsString( wp_normalize_path( WP_CONTENT_DIR ), wp_normalize_path( $json ), 'No server paths, such as the log directory.' );
	}

	/**
	 * @testdox Should list the status tools without running them.
	 */
	public function test_tools_are_listed(): void {
		set_transient( 'wc_test_transient_kept', 'yes' );

		$result = $this->run_ability( 'woocommerce/system-status-tools-query' );

		$this->assertContains( 'clear_transients', array_column( $result['tools'], 'id' ) );
		$this->assertSame( 'yes', get_transient( 'wc_test_transient_kept' ) );
	}

	/**
	 * @testdox Should refuse a user who cannot manage WooCommerce.
	 */
	public function test_requires_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/system-status-get' ) );
		$this->assertWPError( $this->run_ability( 'woocommerce/system-status-tools-query' ) );
	}
}
