<?php
/**
 * ProductTermsQueryTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the product-terms-query ability.
 */
class ProductTermsQueryTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should list product categories when no taxonomy is given.
	 */
	public function test_lists_categories_by_default(): void {
		$parent = wp_insert_term( 'Clothing', 'product_cat' );
		wp_insert_term( 'Shirts', 'product_cat', array( 'parent' => $parent['term_id'] ) );

		$result = $this->run_ability( 'woocommerce/product-terms-query', array( 'search' => 'Shirts' ) );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['terms'] );
		$this->assertSame( 'shirts', $result['terms'][0]['slug'] );
		$this->assertSame( $parent['term_id'], $result['terms'][0]['parent'] );
	}

	/**
	 * @testdox Should return the terms of one taxonomy by ID, which replaces the get reads.
	 *
	 * @testWith ["tag", "product_tag"]
	 *           ["brand", "product_brand"]
	 *
	 * @param string $taxonomy_input Taxonomy input value.
	 * @param string $taxonomy       Taxonomy.
	 */
	public function test_returns_terms_by_id( string $taxonomy_input, string $taxonomy ): void {
		$wanted = wp_insert_term( 'Wanted', $taxonomy );
		wp_insert_term( 'Other', $taxonomy );

		$result = $this->run_ability(
			'woocommerce/product-terms-query',
			array(
				'taxonomy' => $taxonomy_input,
				'include'  => array( $wanted['term_id'] ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array( $wanted['term_id'] ), array_column( $result['terms'], 'id' ) );
	}

	/**
	 * @testdox Should paginate with the number of matching terms.
	 */
	public function test_paginates(): void {
		foreach ( array( 'A', 'B', 'C' ) as $name ) {
			wp_insert_term( "Paged {$name}", 'product_tag' );
		}

		$result = $this->run_ability(
			'woocommerce/product-terms-query',
			array(
				'taxonomy' => 'tag',
				'search'   => 'Paged',
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$this->assertSame( 2, $result['total_pages'] );
		$this->assertSame( array( 'Paged C' ), array_column( $result['terms'], 'name' ) );
	}

	/**
	 * @testdox Should refuse a user who cannot manage product terms.
	 */
	public function test_requires_term_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->run_ability( 'woocommerce/product-terms-query' );

		$this->assertWPError( $result );
	}

	/**
	 * @testdox Should add extension fields to each term only while the feature is on.
	 */
	public function test_adds_extension_fields(): void {
		wp_insert_term( 'Extended', 'product_cat' );
		$this->add_test_field(
			'product_term',
			static function ( \WP_Term $term ) {
				return 'code-' . $term->slug;
			}
		);

		$result = $this->run_ability( 'woocommerce/product-terms-query', array( 'search' => 'Extended' ) );

		$this->assertSame( 'code-extended', $result['terms'][0]['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should return no extension fields while the feature is off.
	 */
	public function test_output_unchanged_with_feature_off(): void {
		wp_insert_term( 'Plain', 'product_cat' );

		$result = $this->run_ability( 'woocommerce/product-terms-query', array( 'search' => 'Plain' ) );

		$this->assertArrayNotHasKey( 'extensions', $result['terms'][0] );
		$this->assertArrayNotHasKey( 'extensions', wp_get_ability( 'woocommerce/product-terms-query' )->get_output_schema()['properties']['terms']['items']['properties'] );
	}
}
