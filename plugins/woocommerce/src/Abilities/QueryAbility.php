<?php
/**
 * Query ability class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * An ability that returns a page of objects of one resource. The subclass finds
 * the objects, and this class formats them and wraps them with the page details.
 * The input schema gets the query filters that AbilityResources::register_query_filter()
 * adds to the resource.
 *
 * The API is experimental while the `extensible_abilities` feature exists.
 *
 * @since 11.4.0
 */
abstract class QueryAbility extends ResourceAbility {

	/**
	 * Query arguments that a filter callback can return.
	 */
	private const QUERY_ARG_KEYS = array( 'meta_query', 'tax_query', 'include_ids', 'exclude_ids' );

	/**
	 * Key of the list in the output, such as `orders`.
	 *
	 * @since 11.4.0
	 *
	 * @return string
	 */
	abstract protected function get_collection_key(): string;

	/**
	 * Find the objects for the input.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{items: array<int, mixed>, total_pages: int, page: int, per_page: int}|\WP_Error
	 */
	abstract protected function query( array $input );

	/**
	 * The `fields` or `exclude` lists that leave more out of each object for the input.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	protected function get_format_args( array $input ): array {
		unset( $input );
		return array();
	}

	/**
	 * Arguments that the query filters of the resource add for the input.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function get_extension_query_args( array $input ) {
		$resource_name = $this->get_resource();
		$query_args    = array();
		$include       = null;
		$exclude       = array();
		if ( ! AbilityResources::is_enabled() ) {
			return $query_args;
		}

		foreach ( AbilityResources::get_query_filters( $resource_name ) as $namespace => $filters ) {
			foreach ( $filters as $filter => $args ) {
				if ( ! is_array( $input['filters']['extensions'][ $namespace ] ?? null ) || ! array_key_exists( $filter, $input['filters']['extensions'][ $namespace ] ) ) {
					continue;
				}

				$value = rest_sanitize_value_from_schema( $input['filters']['extensions'][ $namespace ][ $filter ], $args['schema'], $filter );
				if ( is_wp_error( $value ) ) {
					$value->add_data( array( 'status' => 400 ) );
					return $value;
				}
				try {
					$clauses = call_user_func( $args['callback'], $value );
				} catch ( \Throwable $e ) {
					wc_get_logger()->error(
						sprintf( 'Ability filter "%s.%s" of "%s" failed: %s', $namespace, $filter, $resource_name, $e->getMessage() ),
						array( 'source' => 'extensible-abilities' )
					);
					$clauses = null;
				}
				if ( is_wp_error( $clauses ) ) {
					return $clauses;
				}
				if (
					! is_array( $clauses )
					|| ! empty( array_diff_key( $clauses, array_flip( self::QUERY_ARG_KEYS ) ) )
					|| ( 'product' !== $resource_name && isset( $clauses['tax_query'] ) )
					|| in_array( false, array_map( 'is_array', $clauses ), true )
				) {
					return new \WP_Error(
						'woocommerce_ability_extension_filter_failed',
						/* translators: %s: filter name, such as subscriptions.subscribable. */
						sprintf( __( 'The "%s" filter failed.', 'woocommerce' ), $namespace . '.' . $filter ),
						array( 'status' => 500 )
					);
				}
				if ( isset( $clauses['include_ids'] ) ) {
					$filter_ids = self::get_filter_ids( $clauses['include_ids'], $namespace . '.' . $filter );
					if ( is_wp_error( $filter_ids ) ) {
						return $filter_ids;
					}
					$include = null === $include ? $filter_ids : array_values( array_intersect( $include, $filter_ids ) );
					unset( $clauses['include_ids'] );
				}
				if ( isset( $clauses['exclude_ids'] ) ) {
					$filter_ids = self::get_filter_ids( $clauses['exclude_ids'], $namespace . '.' . $filter );
					if ( is_wp_error( $filter_ids ) ) {
						return $filter_ids;
					}
					$exclude = array_merge( $exclude, $filter_ids );
					unset( $clauses['exclude_ids'] );
				}
				foreach ( $clauses as $key => $clause ) {
					if ( empty( $clause ) ) {
						continue;
					}
					$query_args[ $key ][] = $clause;
				}
			}
		}
		if ( null !== $include ) {
			$include                = array_values( array_diff( $include, $exclude ) );
			$query_args['post__in'] = empty( $include ) ? array( 0 ) : $include;
		} elseif ( ! empty( $exclude ) ) {
			$query_args['post__not_in'] = array_values( array_unique( $exclude ) );
		}
		return $query_args;
	}

	/**
	 * The IDs that a filter returned, as integers.
	 *
	 * @param array  $ids    IDs from the filter callback.
	 * @param string $filter Filter name, such as `subscriptions.related_to`.
	 * @return int[]|\WP_Error An error when an ID is not a positive integer.
	 */
	private static function get_filter_ids( array $ids, string $filter ) {
		$valid = array_filter(
			array_map(
				static fn( $id ) => filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ),
				$ids
			)
		);
		if ( count( $valid ) !== count( $ids ) ) {
			return new \WP_Error(
				'woocommerce_ability_extension_filter_failed',
				/* translators: %s: filter name, such as subscriptions.subscribable. */
				sprintf( __( 'The "%s" filter failed.', 'woocommerce' ), $filter ),
				array( 'status' => 500 )
			);
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Run the query. This is the execute callback of the ability.
	 *
	 * @internal
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_query( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$result = $this->query( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->prepare_output(
			array(
				$this->get_collection_key() => $result['items'],
				'total_pages'               => $result['total_pages'],
				'page'                      => $result['page'],
				'per_page'                  => $result['per_page'],
			),
			array( $this->get_collection_key() => $this->get_format_args( $input ) )
		);
	}

	/**
	 * A page of objects of the resource.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array(
			$this->get_collection_key() => array(
				'type'        => 'array',
				'description' => sprintf(
					/* translators: %s: Collection key, such as products or orders. */
					__( 'Returned %s for the current page.', 'woocommerce' ),
					$this->get_collection_key()
				),
				'items'       => array( '$ref' => $this->get_resource() ),
			),
			'total_pages'               => array(
				'type'        => 'integer',
				'description' => __( 'Total number of result pages available for the current query.', 'woocommerce' ),
			),
			'page'                      => array(
				'type'        => 'integer',
				'description' => __( 'Current result page.', 'woocommerce' ),
			),
			'per_page'                  => array(
				'type'        => 'integer',
				'description' => __( 'Maximum number of items requested per page.', 'woocommerce' ),
			),
		);
	}

	/**
	 * Add the `filters.extensions` property to the input schema when the feature is on and the
	 * resource has filters. It does not allow other filters, because a filter that the query
	 * ignores returns wrong results.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 * @return array<string, mixed>
	 */
	private function add_query_filters_schema( array $schema ): array {
		$filters = AbilityResources::get_query_filters( $this->get_resource() );
		if ( empty( $filters ) || ! AbilityResources::is_enabled() ) {
			return $schema;
		}

		$extensions = array(
			'type'                 => 'object',
			'description'          => __( 'Filters that extensions add, keyed by extension namespace, then filter.', 'woocommerce' ),
			'properties'           => array(),
			'additionalProperties' => false,
		);
		foreach ( $filters as $namespace => $namespace_filters ) {
			$extensions['properties'][ $namespace ] = array(
				'type'                 => 'object',
				'properties'           => array_map( static fn( array $args ) => $args['schema'], $namespace_filters ),
				'additionalProperties' => false,
			);
		}
		$schema['properties']['filters'] = array(
			'type'                 => 'object',
			'properties'           => array( 'extensions' => $extensions ),
			'additionalProperties' => false,
		);
		return $schema;
	}

	/**
	 * Use the query as the execute callback, and add the query filters of the resource to the input schema.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		$args['execute_callback'] = array( $this, 'execute_query' );
		if ( is_array( $args['input_schema'] ?? null ) ) {
			$args['input_schema'] = $this->add_query_filters_schema( $args['input_schema'] );
		}
		return parent::prepare_properties( $args );
	}
}
