<?php
/**
 * Product reviews query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce\Abilities\ExtensibleAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that reads product reviews, with a rating summary
 * and the products ranked by rating.
 */
class ProductReviewsQuery extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Comment statuses keyed by the `status` input value.
	 */
	private const STATUSES = array(
		'approved' => '1',
		'hold'     => '0',
		'spam'     => 'spam',
		'trash'    => 'trash',
	);

	/**
	 * Most products the ranking returns. Unrated products drop out first.
	 */
	private const RANKING_LIMIT = 50;

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/product-reviews-query';
	}

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query product reviews', 'woocommerce' ),
			'description'         => __( 'Read product reviews with a rating summary. Without a search it also ranks the reviewed products from worst to best rated.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => self::get_input_schema(),
			'output_schema'       => self::get_output_schema(),
			'execute_callback'    => array( __CLASS__, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_query_reviews' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		);
	}

	/**
	 * Query product reviews.
	 *
	 * @param array $input Ability input.
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function execute( array $input ): array {
		$product_ids = array_values( array_unique( array_map( 'absint', $input['product_ids'] ?? array() ) ) );
		$status      = $input['status'] ?? 'approved';
		$min_rating  = (int) ( $input['min_rating'] ?? 1 );
		$max_rating  = max( $min_rating, (int) ( $input['max_rating'] ?? 5 ) );
		$per_page    = (int) ( $input['per_page'] ?? 20 );
		$search      = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
		$has_range   = isset( $input['min_rating'] ) || isset( $input['max_rating'] );

		$args = array(
			'post_type' => 'product',
			'type'      => 'review',
			'parent'    => 0,
			'status'    => 'all' === $status ? 'all' : self::STATUSES[ $status ],
			'post__in'  => $product_ids,
			'orderby'   => 'comment_date_gmt',
			'order'     => 'DESC',
			'number'    => $per_page,
		);
		if ( $has_range ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Ratings live only in comment meta.
			$args['meta_query'] = array(
				array(
					'key'     => 'rating',
					'value'   => array( $min_rating, $max_rating ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				),
			);
		}

		// WP_Comment_Query searches author names and emails too; the search stays on review text so it cannot find a person.
		$restrict_search = static function ( array $clauses ) use ( $search ): array {
			global $wpdb;
			$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->comments}.comment_content LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
			return $clauses;
		};
		if ( '' !== $search ) {
			add_filter( 'comments_clauses', $restrict_search );
		}
		$reviews  = get_comments( $args );
		$matching = (int) get_comments( array_merge( $args, array( 'count' => true ) ) );
		remove_filter( 'comments_clauses', $restrict_search );

		$per_item = self::get_rating_totals( $args['status'], $product_ids );
		$output   = array(
			'reviews' => array_map(
				static function ( \WP_Comment $review ): array {
					return self::format_review_for_response( $review );
				},
				array_values(
					array_filter(
						is_array( $reviews ) ? $reviews : array(),
						static function ( $review ): bool {
							return $review instanceof \WP_Comment;
						}
					)
				)
			),
			'summary' => array_merge( self::summarize( $per_item ), array( 'matching_reviews' => $matching ) ),
		);

		if ( '' === $search ) {
			$output['by_product'] = self::rank( $per_item );
		}
		if ( ! empty( $product_ids ) ) {
			$output['products_without_reviews'] = array_values( array_diff( $product_ids, array_column( $per_item, 'product_id' ) ) );
		}

		return $output;
	}

	/**
	 * Check review read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_reviews(): bool {
		return wc_rest_check_product_reviews_permissions( 'read' );
	}

	/**
	 * Format a review for ability output.
	 *
	 * @param \WP_Comment $review Review.
	 * @return array
	 */
	private static function format_review_for_response( \WP_Comment $review ): array {
		$status = wp_get_comment_status( $review );
		$status = 'unapproved' === $status ? 'hold' : (string) $status;

		return ExtensibleAbilities::add_fields_to_object(
			array(
				'id'             => (int) $review->comment_ID,
				'product_id'     => (int) $review->comment_post_ID,
				'product_name'   => get_the_title( (int) $review->comment_post_ID ),
				'date_created'   => '0000-00-00 00:00:00' === $review->comment_date_gmt ? null : mysql_to_rfc3339( $review->comment_date_gmt ),
				'rating'         => (int) get_comment_meta( (int) $review->comment_ID, 'rating', true ),
				'verified'       => wc_review_is_from_verified_owner( (int) $review->comment_ID ),
				'status'         => $status,
				'review'         => wp_strip_all_tags( $review->comment_content ),
				'reviewer'       => $review->comment_author,
				'reviewer_email' => $review->comment_author_email,
			),
			'product_review',
			$review
		);
	}

	/**
	 * Rating summary over the per-product totals.
	 *
	 * @param array $per_item Totals from get_rating_totals().
	 * @return array
	 */
	private static function summarize( array $per_item ): array {
		$total = array_sum( array_column( $per_item, 'review_count' ) );
		$rated = array_sum( array_column( $per_item, 'rated_count' ) );
		$sum   = array_sum( array_column( $per_item, 'rating_sum' ) );

		return array(
			'average_rating' => $rated > 0 ? round( $sum / $rated, 2 ) : 0.0,
			'total_reviews'  => (int) $total,
			'total_products' => count( $per_item ),
		);
	}

	/**
	 * Review and rating totals per reviewed product, for a status and product set, regardless of rating range and search.
	 *
	 * @param string          $status      Comment status, or `all`.
	 * @param array<int, int> $product_ids Products, or empty for every product.
	 * @return array
	 */
	private static function get_rating_totals( string $status, array $product_ids ): array {
		global $wpdb;

		$where = "p.post_type = 'product' AND c.comment_type = 'review' AND c.comment_parent = 0";
		if ( 'all' !== $status ) {
			$where .= $wpdb->prepare( ' AND c.comment_approved = %s', $status );
		}
		if ( ! empty( $product_ids ) ) {
			$where .= ' AND c.comment_post_ID IN (' . implode( ',', array_map( 'absint', $product_ids ) ) . ')';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared parts and integers.
		$rows = $wpdb->get_results(
			"SELECT c.comment_post_ID AS product_id, COUNT(*) AS review_count,
				SUM( NULLIF( CAST( m.meta_value AS UNSIGNED ), 0 ) ) AS rating_sum,
				COUNT( NULLIF( CAST( m.meta_value AS UNSIGNED ), 0 ) ) AS rated_count
			FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
			LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating'
			WHERE {$where}
			GROUP BY c.comment_post_ID",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): array {
				$rated = (int) $row['rated_count'];
				return array(
					'product_id'     => (int) $row['product_id'],
					'review_count'   => (int) $row['review_count'],
					'rated_count'    => $rated,
					'rating_sum'     => (int) $row['rating_sum'],
					'average_rating' => $rated > 0 ? round( (int) $row['rating_sum'] / $rated, 2 ) : 0.0,
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Reviewed products ranked worst-rated first, ties broken by more reviews. Unrated products go last.
	 *
	 * @param array $rows Totals from get_rating_totals().
	 * @return array
	 */
	private static function rank( array $rows ): array {
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( ( 0.0 === $a['average_rating'] ) !== ( 0.0 === $b['average_rating'] ) ) {
					return 0.0 === $a['average_rating'] ? 1 : -1;
				}
				return array( $a['average_rating'], $b['review_count'] ) <=> array( $b['average_rating'], $a['review_count'] );
			}
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'product_id'     => $row['product_id'],
					'product_name'   => get_the_title( $row['product_id'] ),
					'review_count'   => $row['review_count'],
					'average_rating' => $row['average_rating'],
				);
			},
			array_slice( $rows, 0, self::RANKING_LIMIT )
		);
	}

	/**
	 * Get the ability input schema.
	 *
	 * @return array
	 */
	private static function get_input_schema(): array {
		$rating = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 5,
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_ids' => array(
					'type'     => 'array',
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'maxItems' => 200,
				),
				'search'      => array(
					'type'        => 'string',
					'description' => __( 'Match words in the review text only, never reviewer names or emails.', 'woocommerce' ),
				),
				'status'      => array(
					'type'    => 'string',
					'enum'    => array_merge( array_keys( self::STATUSES ), array( 'all' ) ),
					'default' => 'approved',
				),
				'min_rating'  => $rating,
				'max_rating'  => $rating,
				'per_page'    => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * Get the ability output schema.
	 *
	 * @return array
	 */
	private static function get_output_schema(): array {
		$nullable_string = array(
			'type'        => array( 'string', 'null' ),
			'description' => __( 'Null when the store does not share customer data.', 'woocommerce' ),
		);
		$id_list         = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'reviews'                  => array(
					'type'        => 'array',
					'description' => __( 'Newest matching reviews first.', 'woocommerce' ),
					'items'       => ExtensibleAbilities::add_fields_schema(
						array(
							'type'                 => 'object',
							'properties'           => array(
								'id'             => array( 'type' => 'integer' ),
								'product_id'     => array( 'type' => 'integer' ),
								'product_name'   => array( 'type' => 'string' ),
								'date_created'   => array(
									'type'   => array( 'string', 'null' ),
									'format' => 'date-time',
								),
								'rating'         => array(
									'type'        => 'integer',
									'description' => __( 'Stars from 1 to 5, or 0 when the review has no rating.', 'woocommerce' ),
								),
								'verified'       => array( 'type' => 'boolean' ),
								'status'         => array( 'type' => 'string' ),
								'review'         => array( 'type' => 'string' ),
								'reviewer'       => $nullable_string,
								'reviewer_email' => $nullable_string,
							),
							'additionalProperties' => false,
						),
						'product_review'
					),
				),
				'summary'                  => array(
					'type'                 => 'object',
					'description'          => __( 'Totals for the status and products, ignoring the rating range and search.', 'woocommerce' ),
					'properties'           => array(
						'average_rating'   => array( 'type' => 'number' ),
						'total_reviews'    => array( 'type' => 'integer' ),
						'matching_reviews' => array(
							'type'        => 'integer',
							'description' => __( 'Reviews that match every filter, of which `reviews` holds the newest.', 'woocommerce' ),
						),
						'total_products'   => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'by_product'               => array(
					'type'        => 'array',
					'description' => __( 'Reviewed products, worst-rated first, then unrated ones. Only without a search.', 'woocommerce' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'product_id'     => array( 'type' => 'integer' ),
							'product_name'   => array( 'type' => 'string' ),
							'review_count'   => array( 'type' => 'integer' ),
							'average_rating' => array( 'type' => 'number' ),
						),
						'additionalProperties' => false,
					),
				),
				'products_without_reviews' => array_merge(
					$id_list,
					array( 'description' => __( 'Requested products with no review in the status. Only when product_ids is given.', 'woocommerce' ) )
				),
			),
			'additionalProperties' => false,
		);
	}
}
