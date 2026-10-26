<?php
/**
 * Setting resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * The `setting` resource. Its subject is a setting array of the REST settings
 * controller, with its value. It does not leave out secrets: an ability that
 * returns settings drops the secret ones before formatting them.
 *
 * @since 11.4.0
 */
class SettingResource {

	public const NAME = 'setting';

	/**
	 * Choices beyond this count, such as countries, are left out: they are long, and the value is the code.
	 */
	private const MAX_OPTIONS = 50;

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
	 * Fields of a setting, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_fields(): array {
		$string = array( 'type' => 'string' );
		$text   = static fn( string $key ) => static fn( array $setting ): string => wp_strip_all_tags( (string) ( $setting[ $key ] ?? '' ) );

		return array(
			'id'          => array(
				'schema'       => $string,
				'get_callback' => static fn( array $setting ): string => (string) ( $setting['id'] ?? '' ),
			),
			'label'       => array(
				'schema'       => $string,
				'get_callback' => $text( 'label' ),
			),
			'description' => array(
				'schema'       => $string,
				'get_callback' => $text( 'description' ),
			),
			'type'        => array(
				'schema'       => $string,
				'get_callback' => static fn( array $setting ): string => (string) ( $setting['type'] ?? '' ),
			),
			'value'       => array(
				'schema'       => array( 'type' => array( 'string', 'number', 'boolean', 'array', 'object', 'null' ) ),
				'get_callback' => static fn( array $setting ) => $setting['value'] ?? null,
			),
			'options'     => array(
				'schema'       => array(
					'type'                 => array( 'object', 'null' ),
					'description'          => __( 'Choices of a select setting, keyed by value.', 'woocommerce' ),
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'get_callback' => array( __CLASS__, 'get_options' ),
			),
		);
	}

	/**
	 * Choices of a select setting, or null for other settings and long lists.
	 *
	 * @param array $setting Setting.
	 * @return array|null
	 */
	public static function get_options( array $setting ): ?array {
		$options = $setting['options'] ?? null;
		if ( ! is_array( $options ) || array() === $options || count( $options ) > self::MAX_OPTIONS ) {
			return null;
		}

		return array_map(
			static function ( $label ): string {
				return wp_strip_all_tags( is_scalar( $label ) ? (string) $label : '' );
			},
			$options
		);
	}
}
