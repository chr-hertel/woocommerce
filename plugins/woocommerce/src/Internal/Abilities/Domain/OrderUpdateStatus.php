<?php
/**
 * Order update status ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ActionableAbility;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\DomainAbilityHelpers;
use Automattic\WooCommerce\Internal\Abilities\Domain\Traits\OrderAbilityTrait;
use Automattic\WooCommerce\Internal\Abilities\Resources\OrderResource;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce order update status ability.
 */
class OrderUpdateStatus extends ActionableAbility {

	use DomainAbilityHelpers;
	use OrderAbilityTrait;

	public const ABILITY_NAME = 'woocommerce/order-update-status';

	/**
	 * Loaded order, as status and the timestamps of the paid and completed dates, for save() to restore.
	 *
	 * @var array{0: string, 1: ?int, 2: ?int}
	 */
	private array $loaded = array( '', null, null );

	/**
	 * Status and note that change() accepted, for save().
	 *
	 * @var array{0: string, 1: string}
	 */
	private array $pending = array( '', '' );

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 10.9.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Update order status', 'woocommerce' ),
			'description'         => __(
				'Update an order status.',
				'woocommerce'
			),
			'category'            => 'woocommerce',
			'input_schema'        => self::build_input_schema(),
			'permission_callback' => array( __CLASS__, 'can_edit_order' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		);
	}

	/**
	 * Resource the ability changes.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return OrderResource::NAME;
	}

	/**
	 * Load the order.
	 *
	 * @param array $input Ability input.
	 * @return \WC_Data|\WP_Error
	 */
	public function load( array $input ) {
		return $this->load_resource( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Set the new status so validators see it, without the transition hooks that save() runs.
	 *
	 * @param \WC_Order $subject Order to change.
	 * @param array     $input   Ability input.
	 * @return null|\WP_Error
	 */
	public function change( $subject, array $input ) {
		if ( empty( $input['status'] ) ) {
			return new \WP_Error(
				'woocommerce_order_status_required',
				__( 'Order status is required.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$status = OrderUtil::remove_status_prefix( sanitize_key( $input['status'] ) );

		if ( ! in_array( $status, self::get_query_order_status_slugs(), true ) ) {
			return new \WP_Error(
				'woocommerce_order_status_invalid',
				__( 'Order status is invalid.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( $status === $subject->get_status() ) {
			return new \WP_Error(
				'woocommerce_order_status_unchanged',
				__(
					'Order already has this status. Use the woocommerce/order-add-note ability to add a note without changing status.',
					'woocommerce'
				),
				array( 'status' => 400 )
			);
		}

		if ( ! $subject->is_valid_status( $status ) ) {
			return new \WP_Error(
				'woocommerce_order_status_invalid',
				/* translators: %s: order status slug. */
				sprintf( __( 'This order cannot have the "%s" status.', 'woocommerce' ), $status ),
				array( 'status' => 400 )
			);
		}

		$paid          = $subject->get_date_paid( 'edit' );
		$completed     = $subject->get_date_completed( 'edit' );
		$this->loaded  = array( $subject->get_status(), $paid ? $paid->getTimestamp() : null, $completed ? $completed->getTimestamp() : null );
		$this->pending = array( $status, isset( $input['note'] ) ? wp_kses_post( $input['note'] ) : '' );
		$subject->set_status( $status );
		return null;
	}

	/**
	 * Save through WC_Order::update_status(), which order classes can override.
	 *
	 * @param \WC_Order $subject Changed order.
	 * @return null|\WP_Error
	 */
	public function save( $subject ) {
		// Back to the loaded order, so update_status() makes the manual change from it, as without validators.
		$subject->set_status( $this->loaded[0] );
		$subject->set_date_paid( $this->loaded[1] );
		$subject->set_date_completed( $this->loaded[2] );

		try {
			$updated = $subject->update_status( $this->pending[0], $this->pending[1], true );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'woocommerce_order_status_update_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( ! $updated ) {
			return new \WP_Error( 'woocommerce_order_status_update_failed', __( 'Failed to update order status.', 'woocommerce' ), array( 'status' => 500 ) );
		}
		return null;
	}

	/**
	 * The updated order, without line items.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array(
			'order' => array(
				'$ref'    => OrderResource::NAME,
				'exclude' => array( 'line_items' ),
			),
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
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Order status slug without the wc- prefix. Custom order types may add their own statuses.', 'woocommerce' ),
					'enum'        => self::get_query_order_status_slugs(),
				),
				'note'   => array(
					'type'        => 'string',
					'description' => __( 'Optional status change note. Safe HTML is allowed. Use the woocommerce/order-add-note ability for notes without a status change.', 'woocommerce' ),
				),
			),
			'required'             => array( 'id', 'status' ),
			'additionalProperties' => false,
		);
	}
}
