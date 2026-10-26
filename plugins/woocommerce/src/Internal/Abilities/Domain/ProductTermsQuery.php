<?php
/**
 * Product terms query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\QueryAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\ProductResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists product categories, tags and brands. One
 * ability with a taxonomy input costs less context than one per taxonomy.
 */
class ProductTermsQuery extends QueryAbility {

	public const ABILITY_NAME = 'woocommerce/product-terms-query';

	/**
	 * Taxonomies keyed by the `taxonomy` input value.
	 */
	private const TAXONOMIES = array(
		'category' => 'product_cat',
		'tag'      => 'product_tag',
		'brand'    => 'product_brand',
	);

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query product categories, tags and brands', 'woocommerce' ),
			'description'         => __( 'List product categories, tags or brands, or look them up by ID, to resolve names to the term IDs that product filters and writes take.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => self::build_input_schema(),
			'permission_callback' => array( __CLASS__, 'can_query_terms' ),
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
		return ProductResource::TERM;
	}

	/**
	 * Key of the list in the output.
	 *
	 * @return string
	 */
	protected function get_collection_key(): string {
		return 'terms';
	}

	/**
	 * Find the terms of one taxonomy for the input.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	protected function query( array $input ) {
		$taxonomy = self::get_taxonomy_from_input( $input );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'woocommerce_product_taxonomy_unavailable',
				__( 'This product taxonomy is not available on the store.', 'woocommerce' ),
				array( 'status' => 404 )
			);
		}

		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$args     = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => (bool) ( $input['hide_empty'] ?? false ),
			'orderby'    => $input['orderby'] ?? 'name',
			'order'      => strtoupper( $input['order'] ?? 'asc' ),
		);

		if ( ! empty( $input['include'] ) ) {
			$args['include'] = array_map( 'absint', $input['include'] );
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}
		if ( isset( $input['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$args['parent'] = absint( $input['parent'] );
		}
		if ( 'id' === $args['orderby'] ) {
			$args['orderby'] = 'term_id';
		}

		$extension_args = $this->get_extension_query_args( $input );
		if ( is_wp_error( $extension_args ) ) {
			return $extension_args;
		}
		$args = self::add_extension_term_args( $args, $extension_args );

		$total = wp_count_terms( $args );
		$total = is_wp_error( $total ) ? 0 : (int) $total;
		$terms = get_terms(
			array_merge(
				$args,
				array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				)
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array(
			'items'       => $terms,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Check term read access for the taxonomy in the input.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_terms( $input = array() ): bool {
		$taxonomy = self::get_taxonomy_from_input( is_array( $input ) ? $input : array() );

		return taxonomy_exists( $taxonomy ) && wc_rest_check_product_term_permissions( $taxonomy, 'read' );
	}

	/**
	 * Turn the post query arguments that QueryAbility builds from extension filters into get_terms() arguments.
	 *
	 * @param array $args           get_terms() arguments.
	 * @param array $extension_args Arguments from get_extension_query_args().
	 * @return array
	 */
	private static function add_extension_term_args( array $args, array $extension_args ): array {
		if ( ! empty( $extension_args['meta_query'] ) ) {
			$args['meta_query'] = $extension_args['meta_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		if ( isset( $extension_args['post__in'] ) ) {
			$args['include'] = isset( $args['include'] ) ? array_values( array_intersect( $args['include'], $extension_args['post__in'] ) ) : $extension_args['post__in'];
			if ( empty( $args['include'] ) ) {
				$args['include'] = array( 0 );
			}
		}
		if ( isset( $extension_args['post__not_in'] ) ) {
			$args['exclude'] = $extension_args['post__not_in'];
		}
		return $args;
	}

	/**
	 * Taxonomy from the input, product categories when the input names none.
	 *
	 * @param array $input Ability input.
	 * @return string
	 */
	private static function get_taxonomy_from_input( array $input ): string {
		$key = is_string( $input['taxonomy'] ?? null ) ? $input['taxonomy'] : 'category';

		return self::TAXONOMIES[ $key ] ?? '';
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
				'taxonomy'   => array(
					'type'    => 'string',
					'enum'    => array_keys( self::TAXONOMIES ),
					'default' => 'category',
				),
				'include'    => array(
					'type'        => 'array',
					'description' => __( 'Return only these term IDs.', 'woocommerce' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Match term names and slugs.', 'woocommerce' ),
				),
				'parent'     => array(
					'type'        => 'integer',
					'description' => __( 'Only direct children of this category ID; 0 for top-level categories. Ignored for tags.', 'woocommerce' ),
					'minimum'     => 0,
				),
				'hide_empty' => array(
					'type'        => 'boolean',
					'description' => __( 'Leave out terms that no product uses.', 'woocommerce' ),
					'default'     => false,
				),
				'orderby'    => array(
					'type'    => 'string',
					'enum'    => array( 'name', 'id', 'count' ),
					'default' => 'name',
				),
				'order'      => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'asc',
				),
				'page'       => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'   => array(
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
}
