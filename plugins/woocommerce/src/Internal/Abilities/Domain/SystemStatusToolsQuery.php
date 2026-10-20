<?php
/**
 * System status tools query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that lists the WooCommerce status tools. It never
 * runs a tool; several of them delete data.
 */
class SystemStatusToolsQuery extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/system-status-tools-query';
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
			'label'               => __( 'Query status tools', 'woocommerce' ),
			'description'         => __( 'List the maintenance tools under WooCommerce > Status > Tools and what each does. Listing never runs a tool.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'tools' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'          => array( 'type' => 'string' ),
								'name'        => array( 'type' => 'string' ),
								'description' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( __CLASS__, 'execute' ),
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
	 * List the status tools.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function execute(): array {
		$tools = array();
		foreach ( ( new \WC_REST_System_Status_Tools_Controller() )->get_tools() as $id => $tool ) {
			$tools[] = array(
				'id'          => (string) $id,
				'name'        => wp_strip_all_tags( (string) ( $tool['name'] ?? '' ) ),
				'description' => wp_strip_all_tags( (string) ( $tool['desc'] ?? '' ) ),
			);
		}

		return array( 'tools' => $tools );
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
}
