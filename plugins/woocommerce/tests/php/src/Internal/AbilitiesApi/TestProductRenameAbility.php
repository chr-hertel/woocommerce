<?php
/**
 * TestProductRenameAbility class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\AbilitiesApi;

use Automattic\WooCommerce\Abilities\ActionableAbility;

/**
 * An extension's write ability that renames a product.
 */
class TestProductRenameAbility extends ActionableAbility {

	/**
	 * Resource that the ability changes.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return 'product';
	}

	/**
	 * Load the product.
	 *
	 * @param array $input Ability input.
	 * @return \WC_Product|\WP_Error
	 */
	public function load( array $input ) {
		$product = wc_get_product( $input['id'] );
		return $product ? $product : new \WP_Error( 'test_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	/**
	 * Rename the product in memory.
	 *
	 * @param \WC_Product $subject Product.
	 * @param array       $input   Ability input.
	 * @return null
	 */
	public function change( $subject, array $input ) {
		$subject->set_name( $input['name'] );
		return null;
	}

	/**
	 * The renamed product's ID with its extension fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array(
			'product' => array(
				'$ref'   => 'product',
				'fields' => array( 'id', 'extensions' ),
			),
		);
	}
}
