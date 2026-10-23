<?php
/**
 * ProductReviewsQueryTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

/**
 * Tests for the product-reviews-query ability.
 */
class ProductReviewsQueryTest extends DomainAbilityTestCase {

	/**
	 * @testdox Should return reviews with reviewer details, a summary and a worst-first ranking.
	 */
	public function test_returns_reviews_summary_and_ranking(): void {
		$good = \WC_Helper_Product::create_simple_product();
		$bad  = \WC_Helper_Product::create_simple_product();
		$this->add_review( $good->get_id(), 5, 'Love it' );
		$this->add_review( $bad->get_id(), 1, 'Broke fast' );
		$this->add_review( $bad->get_id(), 2, 'Meh' );

		$result = $this->run_ability( 'woocommerce/product-reviews-query' );

		$this->assertNotWPError( $result );
		$this->assertCount( 3, $result['reviews'] );
		$this->assertSame( 'jane@example.com', $result['reviews'][0]['reviewer_email'] );
		$this->assertSame( 3, $result['summary']['total_reviews'] );
		$this->assertSame( 2.67, $result['summary']['average_rating'] );
		$this->assertSame( $bad->get_id(), $result['by_product'][0]['product_id'], 'The worst-rated product should rank first.' );
		$this->assertSame( 1.5, $result['by_product'][0]['average_rating'] );
	}

	/**
	 * @testdox Should filter by rating range and list requested products without reviews.
	 */
	public function test_filters_by_rating_and_lists_unreviewed_products(): void {
		$reviewed   = \WC_Helper_Product::create_simple_product();
		$unreviewed = \WC_Helper_Product::create_simple_product();
		$this->add_review( $reviewed->get_id(), 1, 'Bad' );
		$this->add_review( $reviewed->get_id(), 5, 'Good' );

		$result = $this->run_ability(
			'woocommerce/product-reviews-query',
			array(
				'product_ids' => array( $reviewed->get_id(), $unreviewed->get_id() ),
				'max_rating'  => 2,
			)
		);

		$this->assertSame( array( 'Bad' ), array_column( $result['reviews'], 'review' ) );
		$this->assertSame( 1, $result['summary']['matching_reviews'] );
		$this->assertSame( 2, $result['summary']['total_reviews'], 'The summary ignores the rating range.' );
		$this->assertSame( array( $unreviewed->get_id() ), $result['products_without_reviews'] );
	}

	/**
	 * @testdox Should search review text but never reviewer names or emails.
	 */
	public function test_search_matches_review_text_only(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$this->add_review( $product->get_id(), 4, 'Sturdy and warm' );

		$by_text   = $this->run_ability( 'woocommerce/product-reviews-query', array( 'search' => 'sturdy' ) );
		$by_author = $this->run_ability( 'woocommerce/product-reviews-query', array( 'search' => 'jane' ) );

		$this->assertCount( 1, $by_text['reviews'] );
		$this->assertArrayNotHasKey( 'by_product', $by_text );
		$this->assertCount( 0, $by_author['reviews'] );
	}

	/**
	 * @testdox Should leave out reviewer details while personal data is withheld.
	 */
	public function test_withholds_reviewer(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$this->add_review( $product->get_id(), 3, 'Fine' );
		$this->withhold_personal_data();

		$review = $this->run_ability( 'woocommerce/product-reviews-query' )['reviews'][0];

		$this->assertNull( $review['reviewer'] );
		$this->assertNull( $review['reviewer_email'] );
	}

	/**
	 * @testdox Should add extension fields to each review while the feature is on.
	 */
	public function test_adds_extension_fields(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$this->add_review( $product->get_id(), 3, 'Fine' );
		$this->add_test_field(
			'product_review',
			static function ( \WP_Comment $review ) {
				return 'r' . $review->comment_ID;
			}
		);

		$review = $this->run_ability( 'woocommerce/product-reviews-query' )['reviews'][0];

		$this->assertSame( 'r' . $review['id'], $review['extensions']['test-ext']['code'] );
	}

	/**
	 * @testdox Should refuse a user who cannot moderate comments.
	 */
	public function test_requires_moderate_comments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertWPError( $this->run_ability( 'woocommerce/product-reviews-query' ) );
	}

	/**
	 * Add an approved review.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $rating     Rating.
	 * @param string $text       Review text.
	 */
	private function add_review( int $product_id, int $rating, string $text ): void {
		static $offset = 0;
		++$offset;
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Jane Doe',
				'comment_author_email' => 'jane@example.com',
				'comment_content'      => $text,
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 10 * $offset ),
			)
		);
		update_comment_meta( $id, 'rating', $rating );
	}
}
