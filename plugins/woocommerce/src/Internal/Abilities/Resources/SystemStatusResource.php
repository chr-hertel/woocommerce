<?php
/**
 * System status resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * The `system_status` resource: an allowlisted part of the system status report.
 * Server paths, the database prefix, table names, URLs and remote call results
 * have no field. There is one report per store, so its subject is the status
 * controller that the fields read from.
 *
 * @since 11.4.0
 */
class SystemStatusResource {

	public const NAME = 'system_status';

	/**
	 * Register the resource and its fields.
	 *
	 * @since 11.4.0
	 */
	public function register(): void {
		AbilityResources::register( self::NAME );
		foreach ( self::get_fields() as $field => $args ) {
			$args['resource'] = self::NAME;
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Fields of the report, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_fields(): array {
		$string = array( 'type' => 'string' );

		return array(
			'woocommerce_version'    => array(
				'schema'       => $string,
				'get_callback' => static fn() => (string) WC()->version,
			),
			'woocommerce_db_version' => array(
				'schema'       => $string,
				'get_callback' => static fn() => (string) get_option( 'woocommerce_db_version', '' ),
			),
			'order_storage'          => array(
				'schema'       => array(
					'type' => 'string',
					'enum' => array( 'hpos', 'posts' ),
				),
				'get_callback' => static fn() => OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts',
			),
			'active_plugins'         => array(
				'schema'       => array(
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
				'get_callback' => array( __CLASS__, 'get_active_plugins' ),
			),
			'enabled_features'       => array(
				'schema'       => array(
					'type'  => 'array',
					'items' => $string,
				),
				'get_callback' => array( __CLASS__, 'get_enabled_features' ),
			),
			'theme'                  => array(
				'schema'       => array(
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
				'get_callback' => array( __CLASS__, 'get_theme' ),
			),
			'post_type_counts'       => array(
				'schema'       => array(
					'type'                 => 'object',
					'description'          => __( 'Number of posts of each post type, in every status.', 'woocommerce' ),
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'get_callback' => array( __CLASS__, 'get_post_type_counts' ),
			),
		);
	}

	/**
	 * Active plugins with their name, version and author, without URLs.
	 *
	 * @internal
	 *
	 * @param \WC_REST_System_Status_Controller $controller Status controller.
	 * @return array<int, array<string, string>>
	 */
	public static function get_active_plugins( \WC_REST_System_Status_Controller $controller ): array {
		return array_map(
			static function ( array $plugin ): array {
				return array(
					'plugin'  => (string) ( $plugin['plugin'] ?? '' ),
					'name'    => (string) ( $plugin['name'] ?? '' ),
					'version' => (string) ( $plugin['version'] ?? '' ),
					'author'  => (string) ( $plugin['author_name'] ?? '' ),
				);
			},
			array_values( array_filter( $controller->get_active_plugins(), 'is_array' ) )
		);
	}

	/**
	 * IDs of the enabled WooCommerce features that are not deprecated, sorted.
	 *
	 * @internal
	 *
	 * @return string[]
	 */
	public static function get_enabled_features(): array {
		$features = array();
		foreach ( FeaturesUtil::get_features( true, true ) as $id => $feature ) {
			if ( ! empty( $feature['is_enabled'] ) && empty( $feature['deprecated_since'] ) ) {
				$features[] = (string) $id;
			}
		}
		sort( $features );
		return $features;
	}

	/**
	 * The active theme, read locally: the status controller would look up the latest version on WordPress.org.
	 *
	 * @internal
	 *
	 * @return array<string, mixed>
	 */
	public static function get_theme(): array {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return array(
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'is_block_theme' => $theme->is_block_theme(),
			'parent_name'    => $parent ? (string) $parent->get( 'Name' ) : null,
			'parent_version' => $parent ? (string) $parent->get( 'Version' ) : null,
		);
	}

	/**
	 * Post counts keyed by post type, as an object so an empty list stays a JSON object.
	 *
	 * @internal
	 *
	 * @param \WC_REST_System_Status_Controller $controller Status controller.
	 * @return object
	 */
	public static function get_post_type_counts( \WC_REST_System_Status_Controller $controller ): object {
		$counts = array();
		foreach ( $controller->get_post_type_counts() as $row ) {
			$counts[ (string) $row->type ] = (int) $row->count;
		}
		return (object) $counts;
	}
}
