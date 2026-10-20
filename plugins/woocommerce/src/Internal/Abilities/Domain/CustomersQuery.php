<?php
/**
 * Customers query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce\Abilities\ExtensibleAbilities;
use Automattic\WooCommerce\Internal\Abilities\PersonalData;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce customers query ability. With customer data
 * withheld it still answers who buys and how much, by ID, without
 * identifying anyone.
 */
class CustomersQuery extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Address keys that identify a person. Country and state stay, so location questions still work.
	 */
	private const PERSONAL_ADDRESS_KEYS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'email', 'phone' );

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/customers-query';
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
			'label'               => __( 'Query customers', 'woocommerce' ),
			'description'         => __( 'Find registered customers by ID or filters, with their order count and total spent.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => self::get_input_schema(),
			'output_schema'       => self::get_collection_output_schema( 'customers', self::get_customer_output_schema() ),
			'execute_callback'    => array( __CLASS__, 'execute' ),
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
	 * Query customers.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 *
	 * @since 11.4.0
	 */
	public static function execute( array $input ) {
		$shared = PersonalData::is_shared( 'customer' );
		foreach ( array( 'search', 'email' ) as $lookup ) {
			if ( ! empty( $input[ $lookup ] ) && ! $shared ) {
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

		$query     = new \WP_User_Query( $args );
		$customers = array();
		foreach ( $query->get_results() as $user ) {
			try {
				$customers[] = self::format_customer_for_response( new \WC_Customer( $user->ID ), $shared );
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return array(
			'customers'   => $customers,
			'total_pages' => (int) ceil( $query->get_total() / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
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
	 * Format a customer for ability output.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @param bool         $shared   Whether personal data may be returned.
	 * @return array
	 */
	private static function format_customer_for_response( \WC_Customer $customer, bool $shared ): array {
		$addresses = array(
			'billing'  => $customer->get_billing( 'edit' ),
			'shipping' => $customer->get_shipping( 'edit' ),
		);
		foreach ( $addresses as $type => $address ) {
			foreach ( self::PERSONAL_ADDRESS_KEYS as $key ) {
				if ( array_key_exists( $key, $address ) ) {
					$addresses[ $type ][ $key ] = $shared ? (string) $address[ $key ] : null;
				}
			}
		}

		$last_order = $customer->get_last_order();

		return ExtensibleAbilities::add_fields_to_object(
			array(
				'id'                 => $customer->get_id(),
				'email'              => $shared ? $customer->get_email() : null,
				'first_name'         => $shared ? $customer->get_first_name() : null,
				'last_name'          => $shared ? $customer->get_last_name() : null,
				'username'           => $shared ? $customer->get_username() : null,
				'role'               => $customer->get_role(),
				'is_paying_customer' => (bool) $customer->get_is_paying_customer(),
				'order_count'        => $customer->get_order_count(),
				'total_spent'        => wc_format_decimal( $customer->get_total_spent(), wc_get_price_decimals() ),
				'last_order_id'      => $last_order ? $last_order->get_id() : null,
				'billing'            => $addresses['billing'],
				'shipping'           => $addresses['shipping'],
				'date_created'       => $customer->get_date_created() ? $customer->get_date_created()->format( 'c' ) : null,
				'date_modified'      => $customer->get_date_modified() ? $customer->get_date_modified()->format( 'c' ) : null,
			),
			'customer',
			$customer
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

	/**
	 * Get the schema of a customer in a response.
	 *
	 * @return array
	 */
	private static function get_customer_output_schema(): array {
		$personal = array(
			'type'        => array( 'string', 'null' ),
			'description' => __( 'Null when the store does not share customer data.', 'woocommerce' ),
		);
		$address  = array(
			'type'                 => 'object',
			'description'          => __( 'Country and state are always returned; the other parts are null when the store does not share customer data.', 'woocommerce' ),
			'additionalProperties' => array( 'type' => array( 'string', 'null' ) ),
		);
		$date     = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);

		return ExtensibleAbilities::add_fields_schema(
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'                 => array( 'type' => 'integer' ),
					'email'              => $personal,
					'first_name'         => $personal,
					'last_name'          => $personal,
					'username'           => $personal,
					'role'               => array( 'type' => 'string' ),
					'is_paying_customer' => array( 'type' => 'boolean' ),
					'order_count'        => array( 'type' => 'integer' ),
					'total_spent'        => array(
						'type'        => 'string',
						'description' => __( 'Decimal amount in the store currency, from paid orders.', 'woocommerce' ),
					),
					'last_order_id'      => array( 'type' => array( 'integer', 'null' ) ),
					'billing'            => $address,
					'shipping'           => $address,
					'date_created'       => $date,
					'date_modified'      => $date,
				),
				'additionalProperties' => false,
			),
			'customer'
		);
	}
}
