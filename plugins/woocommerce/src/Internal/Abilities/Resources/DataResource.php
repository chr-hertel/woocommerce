<?php
/**
 * Data resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Abilities\AbilityResources;

defined( 'ABSPATH' ) || exit;

/**
 * A resource whose objects are WC_Data objects, such as `order` or `product`.
 * Most fields read a prop through its `get_<prop>()` getter in the default view
 * context, so the getter filters apply, and the factories below build those
 * field records.
 *
 * @template TData of \WC_Data
 *
 * @since 11.4.0
 */
abstract class DataResource {

	/**
	 * Resource name, such as `order`. It also names the error codes, such as `woocommerce_order_not_found`.
	 *
	 * @since 11.4.0
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	/**
	 * Class that every object of the resource is an instance of, such as WC_Order.
	 *
	 * @since 11.4.0
	 *
	 * @return class-string<TData>
	 */
	abstract public function get_data_class(): string;

	/**
	 * Field arguments keyed by field, in output order.
	 *
	 * @since 11.4.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function get_fields(): array;

	/**
	 * Look up an object by ID, with no permission check.
	 *
	 * @since 11.4.0
	 *
	 * @param int $id Object ID.
	 * @return TData|\WC_Data|null|false
	 */
	abstract protected function find( int $id );

	/**
	 * Translated messages for an ID that is missing and for an object that is not found.
	 *
	 * @since 11.4.0
	 *
	 * @return array{id_required: string, not_found: string}
	 */
	abstract protected function get_messages(): array;

	/**
	 * Register the resource and its fields.
	 *
	 * @since 11.4.0
	 */
	public function register(): void {
		AbilityResources::add( $this );
		foreach ( $this->get_fields() as $field => $args ) {
			$args['resource'] = $this->get_name();
			$args['field']    = $field;
			AbilityResources::add_field( $args );
		}
	}

	/**
	 * Load an object of the resource.
	 *
	 * @since 11.4.0
	 *
	 * @param int $id Object ID.
	 * @return TData|\WP_Error
	 */
	public function load( int $id ) {
		$messages = $this->get_messages();
		if ( $id < 1 ) {
			return new \WP_Error( 'woocommerce_' . $this->get_name() . '_id_required', $messages['id_required'], array( 'status' => 400 ) );
		}

		$data_class = $this->get_data_class();
		$subject    = $this->find( $id );
		if ( ! $subject instanceof $data_class ) {
			return new \WP_Error( 'woocommerce_' . $this->get_name() . '_not_found', $messages['not_found'], array( 'status' => 404 ) );
		}
		return $subject;
	}

	/**
	 * Whether the current user has a permission on an object, checked against its post type.
	 *
	 * @since 11.4.0
	 *
	 * @param int    $id      Object ID.
	 * @param string $context Permission context, such as `read` or `edit`.
	 * @return bool
	 */
	public function can( int $id, string $context ): bool {
		return wc_rest_check_post_permissions( $this->get_name(), $context, $id );
	}

	/**
	 * A field that reads a prop through its getter.
	 *
	 * @param string               $prop   Prop name, such as `total` for get_total().
	 * @param array<string, mixed> $schema JSON schema of the value.
	 * @param array<string, mixed> $args {
	 *     Optional field arguments, kept on the field except for these.
	 *
	 *     @type callable $get        Changes the value the getter returns, such as false to null.
	 *     @type string   $applies_to Class the object must be an instance of. Other objects return null.
	 * }
	 * @return array<string, mixed>
	 */
	protected function prop( string $prop, array $schema, array $args = array() ): array {
		$getter     = 'get_' . $prop;
		$transform  = $args['get'] ?? null;
		$applies_to = $args['applies_to'] ?? null;
		unset( $args['get'], $args['applies_to'] );

		return array_merge(
			$args,
			array(
				'schema'       => $schema,
				'get_callback' => static function ( \WC_Data $subject ) use ( $getter, $transform, $applies_to ) {
					if ( null !== $applies_to && ! $subject instanceof $applies_to ) {
						return null;
					}
					$value = $subject->$getter();
					return null === $transform ? $value : $transform( $value );
				},
			)
		);
	}

	/**
	 * A local and a GMT field for each date prop, such as `date_created` and `date_created_gmt`.
	 *
	 * @param string ...$names Date names, such as `created` for get_date_created().
	 * @return array<string, array<string, mixed>>
	 */
	protected function dates( string ...$names ): array {
		$schema = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
		$fields = array();
		foreach ( $names as $name ) {
			$getter                             = 'get_date_' . $name;
			$fields[ 'date_' . $name ]          = array(
				'schema'       => $schema,
				'get_callback' => static fn( \WC_Data $subject ) => wc_rest_prepare_date_response( $subject->$getter(), false ),
			);
			$fields[ 'date_' . $name . '_gmt' ] = array(
				'schema'       => $schema,
				'get_callback' => static fn( \WC_Data $subject ) => wc_rest_prepare_date_response( $subject->$getter() ),
			);
		}
		return $fields;
	}

	/**
	 * The `currency` code and its `currency_symbol`.
	 *
	 * @param callable $get_code Returns the currency code for an object.
	 * @return array<string, array<string, mixed>>
	 */
	protected function currency( callable $get_code ): array {
		return array(
			'currency'        => array(
				'schema'       => array(
					'type' => 'string',
					'enum' => array_keys( get_woocommerce_currencies() ),
				),
				'get_callback' => $get_code,
			),
			'currency_symbol' => array(
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn( \WC_Data $subject ) => html_entity_decode(
					get_woocommerce_currency_symbol( $get_code( $subject ) ),
					ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401
				),
			),
		);
	}
}
