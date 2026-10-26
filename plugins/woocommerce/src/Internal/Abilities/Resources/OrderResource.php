<?php
/**
 * Order resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Abilities\AbilityResources;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * The `order` and `order_item` resources: the fields that abilities return for
 * them, and how an order is loaded and checked. Every ability that returns an
 * order reads its fields from here.
 *
 * @extends DataResource<\WC_Order>
 *
 * @since 11.4.0
 */
class OrderResource extends DataResource {

	public const NAME = 'order';

	public const ITEM = 'order_item';

	/**
	 * Resource name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Class of an order.
	 *
	 * @return class-string<\WC_Order>
	 */
	public function get_data_class(): string {
		return \WC_Order::class;
	}

	/**
	 * Register the order and its line items, which the `line_items` field refers to.
	 */
	public function register(): void {
		parent::register();
		AbilityResources::register( self::ITEM );
		foreach ( $this->get_item_fields() as $field => $args ) {
			$args['resource'] = self::ITEM;
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Check a permission against the actual type of an order. An order whose
	 * type the abilities do not support fails. An ID with no order is checked
	 * as `shop_order`.
	 *
	 * @param int    $id      Order ID.
	 * @param string $context Permission context, such as `read` or `edit`.
	 * @return bool
	 */
	public function can( int $id, string $context ): bool {
		$type = OrderUtil::get_order_type( $id );
		$type = $type ? $type : 'shop_order';

		return in_array( $type, self::get_type_slugs(), true )
			&& wc_rest_check_post_permissions( $type, $context, $id );
	}

	/**
	 * Look up an order.
	 *
	 * @param int $id Order ID.
	 * @return \WC_Data|null|false
	 */
	protected function find( int $id ) {
		return wc_get_order( $id );
	}

	/**
	 * Messages for a missing order ID and an order that is not found.
	 *
	 * @return array{id_required: string, not_found: string}
	 */
	protected function get_messages(): array {
		return array(
			'id_required' => __( 'Order ID is required.', 'woocommerce' ),
			'not_found'   => __( 'Order not found.', 'woocommerce' ),
		);
	}

	/**
	 * Order types the abilities support: every registered order type except
	 * refunds and the HPOS placeholder.
	 *
	 * @since 11.4.0
	 *
	 * @return array<int, string>
	 */
	public static function get_type_slugs(): array {
		return array_values(
			array_diff(
				wc_get_order_types(),
				array( 'shop_order_refund', DataSynchronizer::PLACEHOLDER_ORDER_POST_TYPE )
			)
		);
	}

	/**
	 * Status slugs (without the `wc-` prefix) that are registered with the
	 * `wc-` prefix, including the statuses of other order types.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args Arguments for get_post_stati().
	 * @return array<int, string>
	 */
	public static function get_registered_status_slugs( array $args = array() ): array {
		$statuses = array_filter(
			get_post_stati( $args ),
			static function ( $status ): bool {
				return 0 === strpos( $status, 'wc-' );
			}
		);

		return array_map( array( OrderUtil::class, 'remove_status_prefix' ), array_values( $statuses ) );
	}

	/**
	 * Every status slug (without the `wc-` prefix) an order in the output can have.
	 *
	 * @since 11.4.0
	 *
	 * @return array<int, string>
	 */
	public static function get_output_status_slugs(): array {
		return array_values(
			array_unique(
				array_merge(
					array_map(
						array( OrderUtil::class, 'remove_status_prefix' ),
						array_merge( OrderStatus::get_all(), array_keys( wc_get_order_statuses() ) )
					),
					self::get_registered_status_slugs()
				)
			)
		);
	}

	/**
	 * Fields of an order, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_fields(): array {
		return array_merge(
			array(
				'id'     => $this->prop( 'id', array( 'type' => 'integer' ) ),
				'status' => $this->prop(
					'status',
					array(
						'type' => 'string',
						'enum' => self::get_output_status_slugs(),
					)
				),
			),
			$this->currency( static fn( \WC_Order $order ) => $order->get_currency() ),
			array(
				'total'                => $this->prop( 'total', array( 'type' => 'string' ) ),
				'customer_id'          => $this->prop( 'customer_id', array( 'type' => 'integer' ) ),
				'billing_email'        => $this->prop(
					'billing_email',
					array(
						'type'   => array( 'string', 'null' ),
						'format' => 'email',
					),
					array(
						'get'           => static fn( $email ) => '' === $email ? null : $email,
						'personal_data' => true,
					)
				),
				'payment_method'       => $this->prop( 'payment_method', array( 'type' => 'string' ) ),
				'payment_method_title' => $this->prop( 'payment_method_title', array( 'type' => 'string' ) ),
			),
			$this->dates( 'created', 'modified' ),
			array(
				'line_items' => array(
					'schema'       => array(
						'type'        => 'array',
						'description' => __( 'Order line items. Only present when include_line_items is true.', 'woocommerce' ),
						'items'       => array( '$ref' => self::ITEM ),
					),
					'get_callback' => static fn( \WC_Order $order ) => array_values(
						array_filter(
							$order->get_items(),
							static fn( $item ) => $item instanceof \WC_Order_Item_Product
						)
					),
				),
			)
		);
	}

	/**
	 * Fields of an order line item, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_item_fields(): array {
		$types = array(
			'id'           => 'integer',
			'name'         => 'string',
			'product_id'   => 'integer',
			'variation_id' => 'integer',
			'quantity'     => 'integer',
			'subtotal'     => 'string',
			'total'        => 'string',
		);

		$fields = array();
		foreach ( $types as $prop => $type ) {
			$fields[ $prop ] = $this->prop( $prop, array( 'type' => $type ) );
		}
		return $fields;
	}
}
