<?php
/**
 * System status ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ResourceAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\SystemStatusResource;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that returns an allowlisted part of the system status
 * report. WordPress, PHP and database server versions come from
 * core/get-environment-info.
 */
class SystemStatusGet extends ResourceAbility {

	public const ABILITY_NAME = 'woocommerce/system-status-get';

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Get system status', 'woocommerce' ),
			'description'         => __( 'WooCommerce and database versions, order storage, active plugins, enabled WooCommerce features, the theme and content counts.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'permission_callback' => array( __CLASS__, 'can_read_system_status' ),
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
		return SystemStatusResource::NAME;
	}

	/**
	 * Read the system status. This is the execute callback of the ability.
	 *
	 * @internal
	 *
	 * @return array
	 */
	public function get_status(): array {
		return $this->prepare_output( array( SystemStatusResource::NAME => new \WC_REST_System_Status_Controller() ) );
	}

	/**
	 * Check system status read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_read_system_status(): bool {
		return wc_rest_check_manager_permissions( 'system_status', 'read' );
	}

	/**
	 * Use the status read as the execute callback.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		$args['execute_callback'] = array( $this, 'get_status' );
		return parent::prepare_properties( $args );
	}
}
