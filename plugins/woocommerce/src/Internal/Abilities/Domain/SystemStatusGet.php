<?php
/**
 * System status ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that returns an allowlisted part of the system status
 * report. Server paths, the database prefix, table names, URLs and remote
 * call results stay out; WordPress, PHP and database server versions come
 * from core/get-environment-info.
 */
class SystemStatusGet extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/system-status-get';
	}

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function get_registration_args(): array {
		$string = array( 'type' => 'string' );

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
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'woocommerce_version'    => $string,
					'woocommerce_db_version' => $string,
					'order_storage'          => array(
						'type' => 'string',
						'enum' => array( 'hpos', 'posts' ),
					),
					'active_plugins'         => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'plugin'  => $string,
								'name'    => $string,
								'version' => $string,
								'author'  => $string,
							),
							'additionalProperties' => false,
						),
					),
					'enabled_features'       => array(
						'type'  => 'array',
						'items' => $string,
					),
					'theme'                  => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'           => $string,
							'version'        => $string,
							'is_block_theme' => array( 'type' => 'boolean' ),
							'parent_name'    => array( 'type' => array( 'string', 'null' ) ),
							'parent_version' => array( 'type' => array( 'string', 'null' ) ),
						),
						'additionalProperties' => false,
					),
					'post_type_counts'       => array(
						'type'                 => 'object',
						'description'          => __( 'Number of posts of each post type, in every status.', 'woocommerce' ),
						'additionalProperties' => array( 'type' => 'integer' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( __CLASS__, 'execute' ),
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
	 * Read the system status.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function execute(): array {
		$controller = new \WC_REST_System_Status_Controller();
		$theme      = wp_get_theme();
		$parent     = $theme->parent();

		$features = array();
		foreach ( FeaturesUtil::get_features( true, true ) as $id => $feature ) {
			if ( ! empty( $feature['is_enabled'] ) && empty( $feature['deprecated_since'] ) ) {
				$features[] = (string) $id;
			}
		}
		sort( $features );

		$counts = array();
		foreach ( $controller->get_post_type_counts() as $row ) {
			$counts[ (string) $row->type ] = (int) $row->count;
		}

		return array(
			'woocommerce_version'    => WC()->version,
			'woocommerce_db_version' => (string) get_option( 'woocommerce_db_version', '' ),
			'order_storage'          => OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts',
			'active_plugins'         => array_map(
				static function ( array $plugin ): array {
					return array(
						'plugin'  => (string) ( $plugin['plugin'] ?? '' ),
						'name'    => (string) ( $plugin['name'] ?? '' ),
						'version' => (string) ( $plugin['version'] ?? '' ),
						'author'  => (string) ( $plugin['author_name'] ?? '' ),
					);
				},
				array_values( array_filter( $controller->get_active_plugins(), 'is_array' ) )
			),
			'enabled_features'       => $features,
			'theme'                  => array(
				'name'           => (string) $theme->get( 'Name' ),
				'version'        => (string) $theme->get( 'Version' ),
				'is_block_theme' => $theme->is_block_theme(),
				'parent_name'    => $parent ? (string) $parent->get( 'Name' ) : null,
				'parent_version' => $parent ? (string) $parent->get( 'Version' ) : null,
			),
			'post_type_counts'       => (object) $counts,
		);
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
}
