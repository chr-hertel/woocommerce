<?php
/**
 * Coupons query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\CouponAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce coupons query ability.
 */
class CouponsQuery extends AbstractDomainAbility implements AbilityDefinition {

	use CouponAbilityTrait;

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/coupons-query';
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
			'label'               => __( 'Query coupons', 'woocommerce' ),
			'description'         => __( 'Find coupons by ID, exact code in any status, or filters, with whether each can be redeemed now.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => self::get_input_schema(),
			'output_schema'       => self::get_collection_output_schema( 'coupons', self::get_coupon_output_schema() ),
			'execute_callback'    => array( __CLASS__, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_query_coupons' ),
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
	 * Query coupons.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 *
	 * @since 11.4.0
	 */
	public static function execute( array $input ) {
		$single_id = (int) ( $input['id'] ?? 0 );
		if ( 0 === $single_id && ! empty( $input['code'] ) ) {
			$single_id = self::find_coupon_id_by_code( (string) $input['code'] );
			if ( 0 === $single_id ) {
				return self::collection( array(), 0, 1, 1 );
			}
		}

		if ( $single_id > 0 ) {
			$coupon = self::get_coupon_from_input( array( 'id' => $single_id ) );

			return is_wp_error( $coupon ) ? $coupon : self::collection( array( $coupon ), 1, 1, 1 );
		}

		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 10 );
		$status   = $input['status'] ?? 'any';
		$args     = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'any' === $status ? self::get_coupon_status_slugs() : array( $status ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}
		if ( ! empty( $input['discount_type'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Coupons are few; the REST coupons endpoint filters the same way.
			$args['meta_query'] = array(
				array(
					'key'   => 'discount_type',
					'value' => $input['discount_type'],
				),
			);
		}

		$query   = new \WP_Query( $args );
		$coupons = array_map(
			static function ( $id ) {
				return new \WC_Coupon( $id instanceof \WP_Post ? $id->ID : (int) $id );
			},
			$query->posts
		);

		return self::collection( $coupons, (int) $query->max_num_pages, $page, $per_page );
	}

	/**
	 * Check coupon read access.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_coupons( $input = array() ): bool {
		return self::check_coupon_permission( $input, 'read' );
	}

	/**
	 * Build the collection output.
	 *
	 * @param array<int, \WC_Coupon> $coupons  Coupons.
	 * @param int                    $pages    Total pages.
	 * @param int                    $page     Page.
	 * @param int                    $per_page Coupons per page.
	 * @return array
	 */
	private static function collection( array $coupons, int $pages, int $page, int $per_page ): array {
		return array(
			'coupons'     => array_map( array( __CLASS__, 'format_coupon_for_response' ), $coupons ),
			'total_pages' => $pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get the ability input schema.
	 *
	 * @return array
	 */
	private static function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'code'          => array(
					'type'        => 'string',
					'description' => __( 'Exact coupon code, case-insensitive. Finds drafts and scheduled coupons too.', 'woocommerce' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Match words in coupon codes and descriptions.', 'woocommerce' ),
				),
				'status'        => array(
					'type'    => 'string',
					'enum'    => array_merge( self::get_coupon_status_slugs(), array( 'any' ) ),
					'default' => 'any',
				),
				'discount_type' => array(
					'type' => 'string',
					'enum' => array_keys( wc_get_coupon_types() ),
				),
				'page'          => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'      => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}
}
