<?php
/**
 * Customer resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * The `customer` and `address` resources: the fields that abilities return for
 * a registered customer and for a billing or shipping address, and how a
 * customer is loaded and checked.
 *
 * @extends DataResource<\WC_Customer>
 *
 * @since 11.4.0
 */
class CustomerResource extends DataResource {

	public const NAME = 'customer';

	public const ADDRESS = 'address';

	/**
	 * Address parts that do not identify a person, so location questions still work while personal data is withheld.
	 */
	private const LOCATION_KEYS = array( 'state', 'country' );

	/**
	 * Resource name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Class of a customer.
	 *
	 * @return class-string<\WC_Customer>
	 */
	public function get_data_class(): string {
		return \WC_Customer::class;
	}

	/**
	 * Register the customer and the address, which the `billing` and `shipping` fields refer to.
	 */
	public function register(): void {
		parent::register();
		AbilityResources::register( self::ADDRESS );
		foreach ( $this->get_address_fields() as $field => $args ) {
			$args['resource'] = self::ADDRESS;
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Customers are users, so the user capabilities decide.
	 *
	 * @param int    $id      Customer ID.
	 * @param string $context Permission context, such as `read` or `edit`.
	 * @return bool
	 */
	public function can( int $id, string $context ): bool {
		return wc_rest_check_user_permissions( $context, $id );
	}

	/**
	 * Look up a customer.
	 *
	 * @param int $id Customer ID.
	 * @return \WC_Data|null
	 */
	protected function find( int $id ) {
		try {
			$customer = new \WC_Customer( $id );
		} catch ( \Exception $e ) {
			return null;
		}
		return $customer->get_id() ? $customer : null;
	}

	/**
	 * Messages for a missing customer ID and a customer that is not found.
	 *
	 * @return array{id_required: string, not_found: string}
	 */
	protected function get_messages(): array {
		return array(
			'id_required' => __( 'Customer ID is required.', 'woocommerce' ),
			'not_found'   => __( 'Customer not found.', 'woocommerce' ),
		);
	}

	/**
	 * Fields of a customer, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_fields(): array {
		$personal = array( 'personal_data' => true );
		$date     = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);

		return array(
			'id'                 => $this->prop( 'id', array( 'type' => 'integer' ) ),
			'email'              => $this->prop( 'email', array( 'type' => 'string' ), $personal ),
			'first_name'         => $this->prop( 'first_name', array( 'type' => 'string' ), $personal ),
			'last_name'          => $this->prop( 'last_name', array( 'type' => 'string' ), $personal ),
			'username'           => $this->prop( 'username', array( 'type' => 'string' ), $personal ),
			'role'               => $this->prop( 'role', array( 'type' => 'string' ) ),
			'is_paying_customer' => $this->prop( 'is_paying_customer', array( 'type' => 'boolean' ), array( 'get' => 'boolval' ) ),
			'order_count'        => $this->prop( 'order_count', array( 'type' => 'integer' ) ),
			'total_spent'        => $this->prop(
				'total_spent',
				array(
					'type'        => 'string',
					'description' => __( 'Decimal amount in the store currency, from paid orders.', 'woocommerce' ),
				),
				array( 'get' => static fn( $total ) => wc_format_decimal( $total, wc_get_price_decimals() ) )
			),
			'last_order_id'      => array(
				'schema'       => array( 'type' => array( 'integer', 'null' ) ),
				'get_callback' => static function ( \WC_Customer $customer ) {
					$last_order = $customer->get_last_order();
					return $last_order ? $last_order->get_id() : null;
				},
			),
			'billing'            => array(
				'schema'       => array( '$ref' => self::ADDRESS ),
				'get_callback' => static fn( \WC_Customer $customer ) => $customer->get_billing( 'edit' ),
			),
			'shipping'           => array(
				'schema'       => array(
					'$ref'    => self::ADDRESS,
					'exclude' => array( 'email' ),
				),
				'get_callback' => static fn( \WC_Customer $customer ) => $customer->get_shipping( 'edit' ),
			),
			'date_created'       => array(
				'schema'       => $date,
				'get_callback' => static fn( \WC_Customer $customer ) => $customer->get_date_created() ? $customer->get_date_created()->format( 'c' ) : null,
			),
			'date_modified'      => array(
				'schema'       => $date,
				'get_callback' => static fn( \WC_Customer $customer ) => $customer->get_date_modified() ? $customer->get_date_modified()->format( 'c' ) : null,
			),
		);
	}

	/**
	 * Fields of a billing or shipping address, read from the array that WC_Customer::get_billing() returns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_address_fields(): array {
		$keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone' );

		$fields = array();
		foreach ( $keys as $key ) {
			$fields[ $key ] = array(
				'schema'        => array( 'type' => 'string' ),
				'get_callback'  => static fn( array $address ) => (string) ( $address[ $key ] ?? '' ),
				'personal_data' => ! in_array( $key, self::LOCATION_KEYS, true ),
			);
		}
		return $fields;
	}
}
