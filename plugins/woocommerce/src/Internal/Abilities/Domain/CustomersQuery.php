<?php
/**
 * Customers query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\QueryAbility;
use Automattic\WooCommerce\Internal\Abilities\PersonalData;
use Automattic\WooCommerce\Internal\Abilities\Resources\CustomerResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce customers query ability. With customer data
 * withheld it still answers who buys and how much, by ID, without
 * identifying anyone.
 */
class CustomersQuery extends QueryAbility {

	public const ABILITY_NAME = 'woocommerce/customers-query';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query customers', 'woocommerce' ),
			'description'         => __( 'Find registered customers by ID or filters, with their order count and total spent.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => self::build_input_schema(),
			'permission_callback' => array( __CLASS__, 'can_query_customers' ),
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
	 * Check customer read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_customers(): bool {
		return wc_rest_check_user_permissions( 'read' );
	}

	/**
	 * Resource the ability returns.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return CustomerResource::NAME;
	}

	/**
	 * Key of the list in the output.
	 *
	 * @return string
	 */
	protected function get_collection_key(): string {
		return 'customers';
	}

	/**
	 * Find the customers for the filters.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	protected function query( array $input ) {
		foreach ( array( 'search', 'email' ) as $lookup ) {
			if ( ! empty( $input[ $lookup ] ) && ! PersonalData::is_shared( CustomerResource::NAME ) ) {
				return self::lookup_refused( $lookup );
			}
		}

		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 10 );
		$role     = $input['role'] ?? 'customer';
		$args     = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => array(
				'id'              => 'ID',
				'registered_date' => 'registered',
				'name'            => 'display_name',
			)[ $input['orderby'] ?? 'registered_date' ],
			'order'   => strtoupper( $input['order'] ?? 'desc' ),
		);
		if ( 'all' !== $role ) {
			$args['role'] = $role;
		}
		if ( ! empty( $input['include'] ) ) {
			$args['include'] = array_map( 'absint', $input['include'] );
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . sanitize_text_field( (string) $input['search'] ) . '*';
		}
		if ( ! empty( $input['email'] ) ) {
			$args['search']         = sanitize_email( (string) $input['email'] );
			$args['search_columns'] = array( 'user_email' );
		}

		$extension_args = $this->get_extension_query_args( $input );
		if ( is_wp_error( $extension_args ) ) {
			return $extension_args;
		}
		$args = self::add_extension_args( $args, $extension_args );

		$query     = new \WP_User_Query( $args );
		$customers = array();
		foreach ( $query->get_results() as $user ) {
			try {
				$customers[] = new \WC_Customer( $user->ID );
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return array(
			'items'       => $customers,
			'total_pages' => (int) ceil( $query->get_total() / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Add the extension filter clauses to the user query. The filters return post query keys, which WP_User_Query names `include` and `exclude`.
	 *
	 * @param array $args           WP_User_Query arguments.
	 * @param array $extension_args Arguments from get_extension_query_args().
	 * @return array
	 */
	private static function add_extension_args( array $args, array $extension_args ): array {
		if ( isset( $extension_args['post__in'] ) ) {
			$include         = $extension_args['post__in'];
			$args['include'] = isset( $args['include'] ) ? array_values( array_intersect( $args['include'], $include ) ) : $include;
			$args['include'] = empty( $args['include'] ) ? array( 0 ) : $args['include'];
		}
		if ( isset( $extension_args['post__not_in'] ) ) {
			$args['exclude'] = $extension_args['post__not_in'];
		}
		if ( isset( $extension_args['meta_query'] ) ) {
			$args['meta_query'] = $extension_args['meta_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	/**
	 * The error for a lookup by personal data while it is withheld, so the answer cannot confirm that a person is a customer.
	 *
	 * @param string $param Input property.
	 * @return \WP_Error
	 */
	private static function lookup_refused( string $param ): \WP_Error {
		return new \WP_Error(
			'woocommerce_ability_customer_data_withheld',
			/* translators: %s: Input property name. */
			sprintf( __( 'The "%s" filter is not available because this store does not share personal data with abilities.', 'woocommerce' ), $param ),
			array(
				'status' => 403,
				'param'  => $param,
			)
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
				'include'  => array(
					'type'        => 'array',
					'description' => __( 'Return only these customer IDs.', 'woocommerce' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Match part of a name, username or email. Refused when the store does not share customer data.', 'woocommerce' ),
				),
				'email'    => array(
					'type'        => 'string',
					'description' => __( 'Exact email. Refused when the store does not share customer data.', 'woocommerce' ),
					'format'      => 'email',
				),
				'role'     => array(
					'type'    => 'string',
					'enum'    => array_merge( array( 'all' ), array_keys( wp_roles()->get_names() ) ),
					'default' => 'customer',
				),
				'orderby'  => array(
					'type'    => 'string',
					'enum'    => array( 'registered_date', 'id', 'name' ),
					'default' => 'registered_date',
				),
				'order'    => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'desc',
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
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
