<?php
/**
 * Product review resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * The `product_review` resource: the fields that abilities return for a review.
 * Reviews are WP_Comment objects, not WC_Data, so it has no loader and no
 * object permission check, and abilities check access themselves.
 *
 * @since 11.4.0
 */
class ProductReviewResource {

	public const NAME = 'product_review';

	/**
	 * Register the resource and its fields.
	 *
	 * @since 11.4.0
	 */
	public function register(): void {
		AbilityResources::register( self::NAME );
		foreach ( self::get_fields() as $field => $args ) {
			$args['resource'] = self::NAME;
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Fields of a review, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_fields(): array {
		return array(
			'id'             => array(
				'schema'       => array( 'type' => 'integer' ),
				'get_callback' => static fn( \WP_Comment $review ) => (int) $review->comment_ID,
			),
			'product_id'     => array(
				'schema'       => array( 'type' => 'integer' ),
				'get_callback' => static fn( \WP_Comment $review ) => (int) $review->comment_post_ID,
			),
			'product_name'   => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WP_Comment $review ) => get_the_title( (int) $review->comment_post_ID ),
			),
			'date_created'   => array(
				'schema'       => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'date-time',
				),
				'get_callback' => static fn( \WP_Comment $review ) => '0000-00-00 00:00:00' === $review->comment_date_gmt ? null : mysql_to_rfc3339( $review->comment_date_gmt ),
			),
			'rating'         => array(
				'schema'       => array(
					'type'        => 'integer',
					'description' => __( 'Stars from 1 to 5, or 0 when the review has no rating.', 'woocommerce' ),
				),
				'get_callback' => static fn( \WP_Comment $review ) => (int) get_comment_meta( (int) $review->comment_ID, 'rating', true ),
			),
			'verified'       => array(
				'schema'       => array( 'type' => 'boolean' ),
				'get_callback' => static fn( \WP_Comment $review ) => wc_review_is_from_verified_owner( (int) $review->comment_ID ),
			),
			'status'         => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static function ( \WP_Comment $review ): string {
					$status = wp_get_comment_status( $review );
					return 'unapproved' === $status ? 'hold' : (string) $status;
				},
			),
			'review'         => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WP_Comment $review ) => wp_strip_all_tags( $review->comment_content ),
			),
			'reviewer'       => array(
				'schema'        => array( 'type' => 'string' ),
				'get_callback'  => static fn( \WP_Comment $review ) => $review->comment_author,
				'personal_data' => true,
			),
			'reviewer_email' => array(
				'schema'        => array( 'type' => 'string' ),
				'get_callback'  => static fn( \WP_Comment $review ) => $review->comment_author_email,
				'personal_data' => true,
			),
		);
	}
}
