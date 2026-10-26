<?php
/**
 * Coupons query ability class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\QueryAbility;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\CouponAbilityTrait;
use Automattic\WooCommerce\Internal\Abilities\Resources\CouponResource;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce coupons query ability.
 */
class CouponsQuery extends QueryAbility {

	use CouponAbilityTrait;

	public const ABILITY_NAME = 'woocommerce/coupons-query';

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
			'input_schema'        => self::build_input_schema(),
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
	 * Resource the ability returns.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return CouponResource::NAME;
	}

	/**
	 * Key of the list in the output.
	 *
	 * @return string
	 */
	protected function get_collection_key(): string {
		return 'coupons';
	}

	/**
	 * Find the coupons for an ID, an exact code or the filters.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	protected function query( array $input ) {
		$single_id = (int) ( $input['id'] ?? 0 );
		if ( 0 === $single_id && ! empty( $input['code'] ) ) {
			$single_id = self::find_coupon_id_by_code( (string) $input['code'] );
			if ( 0 === $single_id ) {
				return self::page( array(), 0, 1, 1 );
			}
		}

		if ( $single_id > 0 ) {
			$coupon = $this->load_resource( $single_id );

			return is_wp_error( $coupon ) ? $coupon : self::page( array( $coupon ), 1, 1, 1 );
		}

		$extension_args = $this->get_extension_query_args( $input );
		if ( is_wp_error( $extension_args ) ) {
			return $extension_args;
		}

		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 10 );
		$status   = $input['status'] ?? 'any';
		$args     = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'any' === $status ? CouponResource::get_status_slugs() : array( $status ),
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

		$query = new \WP_Query( array_merge_recursive( $args, $extension_args ) );

		return self::page(
			array_map( static fn( $id ) => new \WC_Coupon( $id instanceof \WP_Post ? $id->ID : (int) $id ), $query->posts ),
			(int) $query->max_num_pages,
			$page,
			$per_page
		);
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
	 * A page of coupons.
	 *
	 * @param array<int, \WC_Data> $coupons  Coupons.
	 * @param int                  $pages    Total pages.
	 * @param int                  $page     Page.
	 * @param int                  $per_page Coupons per page.
	 * @return array
	 */
	private static function page( array $coupons, int $pages, int $page, int $per_page ): array {
		return array(
			'items'       => $coupons,
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
	private static function build_input_schema(): array {
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
					'enum'    => array_merge( CouponResource::get_status_slugs(), array( 'any' ) ),
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
