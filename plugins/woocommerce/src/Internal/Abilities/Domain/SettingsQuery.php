<?php
/**
 * Settings query ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\ResourceAbility;
use Automattic\WooCommerce\Internal\Abilities\Resources\SettingResource;
use Automattic\WooCommerce\Internal\Abilities\SecretFields;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that reads WooCommerce settings groups, including
 * the tabs and emails that extensions register, without their secrets.
 */
class SettingsQuery extends ResourceAbility {

	public const ABILITY_NAME = 'woocommerce/settings-query';

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
	 * Resource the ability returns.
	 *
	 * @return string
	 */
	public function get_resource(): string {
		return SettingResource::NAME;
	}

	/**
	 * List the settings groups, or the settings of one group. This is the execute callback of the ability.
	 *
	 * @internal
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function query_settings( $input = null ) {
		WC()->register_wp_admin_settings();

		if ( empty( $input['group'] ) ) {
			return $this->prepare_output( array( 'groups' => self::get_groups() ) );
		}

		$settings = ( new \WC_REST_Setting_Options_Controller() )->get_group_settings( (string) $input['group'] );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$ids = isset( $input['ids'] ) ? array_map( 'strval', (array) $input['ids'] ) : null;

		return $this->prepare_output(
			array(
				'settings' => array_filter(
					$settings,
					static function ( array $setting ) use ( $ids ): bool {
						$id = (string) ( $setting['id'] ?? '' );
						return ! self::is_hidden( $setting ) && ( null === $ids || in_array( $id, $ids, true ) );
					}
				),
			)
		);
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
	 * Use the settings query as the execute callback.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		$args['execute_callback'] = array( $this, 'query_settings' );
		return parent::prepare_properties( $args );
	}

	/**
	 * The groups as plain objects, the settings as the resource.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		$string = array( 'type' => 'string' );

		return array(
			'groups'   => array(
				'type'        => 'array',
				'description' => __( 'Settings groups, when no group was given.', 'woocommerce' ),
				'items'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => $string,
						'label'     => $string,
						'parent_id' => $string,
					),
					'additionalProperties' => false,
				),
			),
			'settings' => array(
				'type'        => 'array',
				'description' => __( 'Settings of the group, when a group was given.', 'woocommerce' ),
				'items'       => array( '$ref' => SettingResource::NAME ),
			),
		);
	}

	/**
	 * Whether a setting is a section marker or holds a credential.
	 *
	 * @param array $setting Setting.
	 * @return bool
	 */
	private static function is_hidden( array $setting ): bool {
		$type = (string) ( $setting['type'] ?? '' );
		// Email settings store their options in one array, so the key is the option and the setting inside it.
		$option_key = implode( '_', array_map( 'strval', (array) ( $setting['option_key'] ?? '' ) ) );

		return in_array( $type, array( 'title', 'sectionend' ), true )
			|| SecretFields::is_secret( (string) ( $setting['id'] ?? '' ), $type )
			|| SecretFields::is_secret( $option_key );
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
}
