<?php
/**
 * System status tools query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ResourceAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\StatusToolResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists the WooCommerce status tools. It never
 * runs a tool; several of them delete data.
 */
class SystemStatusToolsQuery extends ResourceAbility {

	public const ABILITY_NAME = 'woocommerce/system-status-tools-query';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Query status tools', 'woocommerce' ),
			'description'         => __( 'List the maintenance tools under WooCommerce > Status > Tools and what each does. Listing never runs a tool.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'permission_callback' => array( __CLASS__, 'can_read_tools' ),
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
		return StatusToolResource::NAME;
	}

	/**
	 * List the status tools. This is the execute callback of the ability.
	 *
	 * @internal
	 *
	 * @return array
	 */
	public function query_tools(): array {
		$tools = array();
		foreach ( ( new \WC_REST_System_Status_Tools_Controller() )->get_tools() as $id => $tool ) {
			if ( is_array( $tool ) ) {
				$tools[] = array_merge( $tool, array( 'id' => (string) $id ) );
			}
		}

		return $this->prepare_output( array( 'tools' => $tools ) );
	}

	/**
	 * Check status tools read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_read_tools(): bool {
		return wc_rest_check_manager_permissions( 'system_status', 'read' );
	}

	/**
	 * Use the tool list as the execute callback.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		$args['execute_callback'] = array( $this, 'query_tools' );
		return parent::prepare_properties( $args );
	}

	/**
	 * The tools as the resource, in the order of the Tools screen.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array(
			'tools' => array(
				'type'  => 'array',
				'items' => array( '$ref' => StatusToolResource::NAME ),
			),
		);
	}
}
