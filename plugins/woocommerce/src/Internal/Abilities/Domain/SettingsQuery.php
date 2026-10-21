<?php
/**
 * Settings query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;
use Automattic\WooCommerce\Internal\Abilities\SecretFields;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that reads WooCommerce settings groups, including
 * the tabs and emails that extensions register, without their secrets.
 */
class SettingsQuery extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/settings-query';
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
			'label'               => __( 'Query settings', 'woocommerce' ),
			'description'         => __( 'Without a group, list the settings groups. With a group, return its settings and their values. Credentials are never returned. For the common store settings, prefer woocommerce/store-settings-get.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => 'string',
						'description' => __( 'Settings group ID from the group list, such as general or products.', 'woocommerce' ),
						'pattern'     => '^[A-Za-z0-9_-]+$',
					),
					'ids'   => array(
						'type'        => 'array',
						'description' => __( 'Return only these settings of the group.', 'woocommerce' ),
						'items'       => array( 'type' => 'string' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => self::get_output_schema(),
			'execute_callback'    => array( __CLASS__, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_query_settings' ),
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
	 * List the settings groups, or the settings of one group.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 *
	 * @since 11.4.0
	 */
	public static function execute( array $input ) {
		WC()->register_wp_admin_settings();

		if ( empty( $input['group'] ) ) {
			return array( 'groups' => self::get_groups() );
		}

		$settings = ( new \WC_REST_Setting_Options_Controller() )->get_group_settings( (string) $input['group'] );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$ids    = isset( $input['ids'] ) ? array_map( 'strval', (array) $input['ids'] ) : null;
		$output = array();
		foreach ( $settings as $setting ) {
			$id   = (string) ( $setting['id'] ?? '' );
			$type = (string) ( $setting['type'] ?? '' );
			if ( in_array( $type, array( 'title', 'sectionend' ), true ) || SecretFields::is_secret( $id, $type ) || SecretFields::is_secret( (string) ( $setting['option_key'] ?? '' ) ) ) {
				continue;
			}
			if ( null !== $ids && ! in_array( $id, $ids, true ) ) {
				continue;
			}
			$output[] = array_filter(
				array(
					'id'          => $id,
					'label'       => wp_strip_all_tags( (string) ( $setting['label'] ?? '' ) ),
					'description' => wp_strip_all_tags( (string) ( $setting['description'] ?? '' ) ),
					'type'        => $type,
					'value'       => $setting['value'] ?? null,
					'options'     => self::get_options( $setting ),
				),
				static function ( $value ): bool {
					return null !== $value && '' !== $value;
				}
			);
		}

		return array( 'settings' => $output );
	}

	/**
	 * Check settings read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_query_settings(): bool {
		return wc_rest_check_manager_permissions( 'settings', 'read' );
	}

	/**
	 * The settings groups, with their sub groups.
	 *
	 * @return array
	 */
	private static function get_groups(): array {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core's REST settings controller fires the same filter undocumented.
		$groups = apply_filters( 'woocommerce_settings_groups', array() );
		$output = array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			if ( empty( $group['id'] ) || empty( $group['label'] ) ) {
				continue;
			}
			$output[] = array(
				'id'        => (string) $group['id'],
				'label'     => wp_strip_all_tags( (string) $group['label'] ),
				'parent_id' => (string) ( $group['parent_id'] ?? '' ),
			);
		}

		return $output;
	}

	/**
	 * Choices of a select setting, or null for other settings. Country lists stay out: they are long, and the value is the code.
	 *
	 * @param array $setting Setting.
	 * @return array|null
	 */
	private static function get_options( array $setting ): ?array {
		$options = $setting['options'] ?? null;
		if ( ! is_array( $options ) || count( $options ) > 50 ) {
			return null;
		}

		return array_map(
			static function ( $label ): string {
				return wp_strip_all_tags( is_scalar( $label ) ? (string) $label : '' );
			},
			$options
		);
	}

	/**
	 * Get the ability output schema.
	 *
	 * @return array
	 */
	private static function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'groups'   => array(
					'type'        => 'array',
					'description' => __( 'Settings groups, when no group was given.', 'woocommerce' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'        => array( 'type' => 'string' ),
							'label'     => array( 'type' => 'string' ),
							'parent_id' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'settings' => array(
					'type'        => 'array',
					'description' => __( 'Settings of the group, when a group was given.', 'woocommerce' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'          => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'type'        => array( 'type' => 'string' ),
							'value'       => array( 'type' => array( 'string', 'number', 'boolean', 'array', 'object', 'null' ) ),
							'options'     => array(
								'type'                 => 'object',
								'description'          => __( 'Choices of a select setting, keyed by value.', 'woocommerce' ),
								'additionalProperties' => array( 'type' => 'string' ),
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}
}
