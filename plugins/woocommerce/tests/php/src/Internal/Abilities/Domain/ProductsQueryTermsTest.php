<?php
/**
 * ProductsQueryTermsTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Categories, tags and brands in the products-query ability.
 */
class ProductsQueryTermsTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should return the categories, tags and brands of each product.
	 */
	public function test_returns_product_terms(): void {
		$category = wp_insert_term( 'Hats & Caps', 'product_cat' );
		$tag      = wp_insert_term( 'Summer', 'product_tag' );
		$brand    = wp_insert_term( 'Acme', 'product_brand' );
		$product  = \WC_Helper_Product::create_simple_product();
		$product->set_category_ids( array( $category['term_id'] ) );
		$product->set_tag_ids( array( $tag['term_id'] ) );
		$product->set_brand_ids( array( $brand['term_id'] ) );
		$product->save();

		$result = $this->run_ability( 'woocommerce/products-query', array( 'id' => $product->get_id() ) );

		$this->assertNotWPError( $result );
		$item = $result['products'][0];
		$this->assertSame( $category['term_id'], $item['categories'][0]['id'] );
		$this->assertSame( 'Hats & Caps', $item['categories'][0]['name'], 'Term names should be decoded.' );
		$this->assertSame( 'summer', $item['tags'][0]['slug'] );
		$this->assertSame( 'Acme', $item['brands'][0]['name'] );
	}

	/**
	 * @testdox Should return only products that match a category, tag or brand filter.
	 *
	 * @testWith ["category", "product_cat", "set_category_ids"]
	 *           ["tag", "product_tag", "set_tag_ids"]
	 *           ["brand", "product_brand", "set_brand_ids"]
	 *
	 * @param string $filter   Input filter.
	 * @param string $taxonomy Taxonomy.
	 * @param string $setter   Product setter.
	 */
	public function test_filters_by_term( string $filter, string $taxonomy, string $setter ): void {
		$term    = wp_insert_term( 'Matching ' . $filter, $taxonomy );
		$matched = \WC_Helper_Product::create_simple_product();
		$matched->$setter( array( $term['term_id'] ) );
		$matched->save();
		\WC_Helper_Product::create_simple_product();

		$result = $this->run_ability( 'woocommerce/products-query', array( $filter => array( $term['term_id'] ) ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( $matched->get_id() ), array_column( $result['products'], 'id' ) );
	}
}
