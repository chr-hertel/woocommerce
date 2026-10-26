<?php
/**
 * Ability resources class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Abilities;

use Automattic\WooCommerce\Internal\Abilities\PersonalData;
use Automattic\WooCommerce\Internal\Abilities\Resources\DataResource;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of the objects that abilities return, such as `order`, and of the
 * fields on them. The output and the output schema of every resource ability
 * are built from it, and it decides which fields are withheld as personal data.
 *
 * Extensions register a resource inside the `woocommerce_extensible_abilities_resources_init`
 * action, and fields, query filters and validators inside the `woocommerce_extensible_abilities_init`
 * action, which fires after. An extension field is returned under `extensions.<namespace>.<field>`,
 * so only Core puts fields at the root of a resource. Both actions fire only while the
 * experimental `extensible_abilities` feature is on. Core's resources and the personal data
 * rule do not depend on it.
 *
 * The extension API is experimental while the `extensible_abilities` feature exists.
 *
 * @since 11.4.0
 */
class AbilityResources {

	public const FEATURE_ID = 'extensible_abilities';

	/**
	 * Registered resources, keyed by resource name.
	 *
	 * @var array<string, bool>
	 */
	private static array $resources = array();

	/**
	 * Resources whose objects are WC_Data objects, keyed by resource name.
	 *
	 * @var array<string, DataResource<\WC_Data>>
	 */
	private static array $data_resources = array();

	/**
	 * Core fields at the root of a resource, keyed by resource, then field.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $fields = array();

	/**
	 * Fields that extensions return under `extensions.<namespace>`, keyed by resource, namespace, then field.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	private static array $extension_fields = array();

	/**
	 * Query filters keyed by resource, then namespace, then filter name.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	private static array $filters = array();

	/**
	 * Validators keyed by resource.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $validators = array();

	/**
	 * Extension fields already reported for a value that does not match the schema, keyed by resource, namespace and field.
	 *
	 * @var array<string, bool>
	 */
	private static array $reported = array();

	/**
	 * Whether the store enabled the experimental feature.
	 *
	 * @since 11.4.0
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return FeaturesUtil::feature_is_enabled( self::FEATURE_ID );
	}

	/**
	 * Register a resource that an extension's abilities return, so extensions can register fields on it.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_resources_init` action. A call after that action is refused.
	 * Core's `product`, `order` and `order_item` are registered before it. Registering the same resource again does nothing.
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Slug of the resource, such as `ticket`.
	 */
	public static function register_resource( string $resource_name ): void {
		if ( did_action( 'woocommerce_extensible_abilities_resources_init' ) && ! doing_action( 'woocommerce_extensible_abilities_resources_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a resource inside the woocommerce_extensible_abilities_resources_init action, before the fields are registered.', '11.4.0' );
			return;
		}
		if ( ! self::is_slug( $resource_name ) ) {
			wc_doing_it_wrong( __METHOD__, 'The resource name must be a slug of lowercase letters, numbers, dashes and underscores, not only numbers.', '11.4.0' );
			return;
		}

		self::register( $resource_name );
	}

	/**
	 * Register a field that the abilities of a resource return under `extensions.<namespace>.<field>`.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. A call after that action is refused.
	 * The resource must be registered with `register_resource()` in the earlier `woocommerce_extensible_abilities_resources_init` action.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Field arguments.
	 *
	 *     @type string   $resource        Resource that an ability returns, for example `product`, `order` or `order_item`.
	 *     @type string   $namespace       Slug of the extension that owns the field, such as `subscriptions`.
	 *     @type string   $field           Field name inside the namespace, such as `trial_length`.
	 *     @type array    $schema          JSON schema of the value. It needs a `type`. Its `title` is the label clients show.
	 *                                     It cannot set `required`, because a value that fails its schema is
	 *                                     left out. For a field that is always present, allow null and return null.
	 *     @type callable $get_callback    Receives the object and returns the value. A value that the schema
	 *                                     does not allow is left out, so return null when the object has no
	 *                                     value. It reads the object it is given, not the database,
	 *                                     because an ability can format an object before it is saved.
	 *     @type callable $update_callback Optional. Receives the object and the value, and changes the object
	 *                                     in memory. It returns a WP_Error to reject the value, and then
	 *                                     nothing is saved. A field without it cannot be written. It only sets
	 *                                     values in memory, receives the value cast to the field schema, and
	 *                                     deletes the value of the field for null.
	 *     @type bool     $personal_data   Optional. Whether the value is personal data, such as a phone number.
	 *                                     The value is left out when the store does not share personal data
	 *                                     with abilities. Default false.
	 * }
	 */
	public static function register_field( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a field inside the woocommerce_extensible_abilities_init action, before the abilities build their schemas.', '11.4.0' );
			return;
		}
		if ( ! self::has_slugs( __METHOD__, $args, array( 'resource', 'namespace', 'field' ) ) ) {
			return;
		}
		if ( ! self::is_registered( $args['resource'] ) && ! did_action( 'woocommerce_extensible_abilities_resources_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a field inside the woocommerce_extensible_abilities_init action. The resources are not registered yet.', '11.4.0' );
			return;
		}
		if ( ! self::is_registered( $args['resource'] ) ) {
			self::report_unregistered( __METHOD__, $args['resource'] );
			return;
		}
		if ( ! is_array( $args['schema'] ?? null ) || ! isset( $args['schema']['type'] ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "schema" argument must be an array with a "type".', '11.4.0' );
			return;
		}
		if ( true === ( $args['schema']['required'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "schema" argument cannot set "required". A value that fails its schema is left out, so allow null for a field that is always present.', '11.4.0' );
			return;
		}
		if ( ! is_callable( $args['get_callback'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "get_callback" argument must be callable.', '11.4.0' );
			return;
		}
		if ( isset( $args['update_callback'] ) && ! is_callable( $args['update_callback'] ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "update_callback" argument must be callable.', '11.4.0' );
			return;
		}
		if ( ! self::has_valid_personal_data_flag( __METHOD__, $args ) ) {
			return;
		}
		if ( isset( self::$extension_fields[ $args['resource'] ][ $args['namespace'] ][ $args['field'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf( 'The field "%1$s.%2$s" of "%3$s" is already registered.', $args['namespace'], $args['field'], $args['resource'] ),
				'11.4.0'
			);
			return;
		}

		self::$extension_fields[ $args['resource'] ][ $args['namespace'] ][ $args['field'] ] = $args;
	}

	/**
	 * Register a filter that the query ability of a resource accepts under `filters.extensions.<namespace>.<filter>`.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. A call after that action is refused.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Filter arguments.
	 *
	 *     @type string   $resource  Resource whose QueryAbility applies the filter, such as `product` or `order`.
	 *     @type string   $namespace Slug of the extension that owns the filter, such as `subscriptions`.
	 *     @type string   $filter    Filter name inside the namespace, such as `subscribable`.
	 *     @type array    $schema    JSON schema of the value. Its `description` tells agents how to use the filter.
	 *     @type callable $callback  Receives the value, sanitized with the schema, and returns query arguments:
	 *                               a `meta_query`, for products also a `tax_query`, `include_ids` or
	 *                               `exclude_ids`. Core adds each one to the query with AND. Return a
	 *                               WP_Error to refuse the value. `include_ids` and `exclude_ids` are lists
	 *                               of object IDs, for data that is not meta: Core keeps the IDs that every
	 *                               `include_ids` filter returns, then drops the IDs that any `exclude_ids`
	 *                               filter returns. An empty `include_ids` matches nothing.
	 * }
	 */
	public static function register_query_filter( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a filter inside the woocommerce_extensible_abilities_init action, before the abilities build their schemas.', '11.4.0' );
			return;
		}
		if ( ! self::has_slugs( __METHOD__, $args, array( 'resource', 'namespace', 'filter' ) ) ) {
			return;
		}
		if ( ! self::is_registered( $args['resource'] ) ) {
			self::report_unregistered( __METHOD__, $args['resource'] );
			return;
		}
		if ( ! is_array( $args['schema'] ?? null ) || ! isset( $args['schema']['type'] ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "schema" argument must be an array with a "type".', '11.4.0' );
			return;
		}
		if ( ! is_callable( $args['callback'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "callback" argument must be callable.', '11.4.0' );
			return;
		}
		if ( isset( self::$filters[ $args['resource'] ][ $args['namespace'] ][ $args['filter'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf( 'The filter "%1$s.%2$s" of "%3$s" is already registered.', $args['namespace'], $args['filter'], $args['resource'] ),
				'11.4.0'
			);
			return;
		}

		self::$filters[ $args['resource'] ][ $args['namespace'] ][ $args['filter'] ] = $args;
	}

	/**
	 * Register a validator that can refuse a change to an object of a resource.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. Validators
	 * run only when the `extensible_abilities` feature is on.
	 *
	 * Use a field `update_callback` to refuse a bad value of one field. Use a
	 * validator to refuse a change as a whole: it runs on every change that an
	 * ActionableAbility of the resource makes, also when the input sends no
	 * extension fields.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Validator arguments.
	 *
	 *     @type string   $resource  Resource that an ActionableAbility changes, such as `product`.
	 *     @type string   $namespace Slug of the extension that owns the validator, such as `subscriptions`.
	 *     @type callable $callback  Receives the changed object and the ability, after Core's changes and the
	 *                               field callbacks, before the one save. It returns a WP_Error to refuse the
	 *                               change, and then nothing is saved. It only reads the object.
	 * }
	 */
	public static function register_validator( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a validator inside the woocommerce_extensible_abilities_init action.', '11.4.0' );
			return;
		}
		foreach ( array( 'resource', 'namespace' ) as $key ) {
			if ( ! is_string( $args[ $key ] ?? null ) || '' === $args[ $key ] || sanitize_key( $args[ $key ] ) !== $args[ $key ] ) {
				wc_doing_it_wrong(
					__METHOD__,
					sprintf( 'The "%s" argument must be a slug of lowercase letters, numbers, dashes and underscores.', $key ),
					'11.4.0'
				);
				return;
			}
		}
		if ( ! is_callable( $args['callback'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "callback" argument must be callable.', '11.4.0' );
			return;
		}
		if ( ! self::is_registered( $args['resource'] ) ) {
			self::report_unregistered( __METHOD__, $args['resource'] );
			return;
		}

		self::$validators[ $args['resource'] ][] = $args;
	}

	/**
	 * Whether a resource is registered.
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Resource.
	 * @return bool
	 */
	public static function is_registered( string $resource_name ): bool {
		return isset( self::$resources[ $resource_name ] );
	}

	/**
	 * JSON schema of a resource, built from its fields, with the fields that extensions add under `extensions`.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Schema arguments.
	 *
	 *     @type string   $resource Resource.
	 *     @type string[] $fields   Optional. Only these fields. Extension fields come along only when `extensions` is listed.
	 *     @type string[] $exclude  Optional. Leave these fields out, or `extensions` for the extension fields.
	 * }
	 * @return array<string, mixed>
	 */
	public static function output_schema( array $args ): array {
		return self::build_schema( $args, array() );
	}

	/**
	 * Replace each `$ref` to a resource in a schema, at the top, under `items` or under `properties`, with the schema
	 * of that resource. A `fields` or `exclude` list next to a `$ref` nests only a part of the resource.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $schema JSON schema with `$ref`s, such as the output shape of an ability.
	 * @return array<string, mixed>
	 */
	public static function resolve_schema( array $schema ): array {
		return self::resolve_ref( $schema, array() );
	}

	/**
	 * Output of a value for a schema from resolve_schema(): each object at a `$ref` is formatted as that resource,
	 * and everything else is returned as it is.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $schema JSON schema with `$ref`s.
	 * @param mixed                $value  Value, such as `array( 'order' => $order, 'total' => 3 )` for an output shape.
	 * @return mixed
	 */
	public static function resolve_output( array $schema, $value ) {
		if ( isset( $schema['$ref'] ) ) {
			return null === $value ? null : self::output_data( array_merge( self::get_ref_args( $schema ), array( 'subject' => $value ) ) );
		}
		if ( ! is_array( $value ) || ! self::has_ref( $schema ) ) {
			return $value;
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			return array_map( static fn( $item ) => self::resolve_output( $schema['items'], $item ), array_values( $value ) );
		}
		foreach ( $value as $key => $property ) {
			if ( isset( $schema['properties'][ $key ] ) && is_array( $schema['properties'][ $key ] ) ) {
				$value[ $key ] = self::resolve_output( $schema['properties'][ $key ], $property );
			}
		}
		return $value;
	}

	/**
	 * Output of an object as the resource, read from its fields.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Output arguments.
	 *
	 *     @type string   $resource Resource.
	 *     @type mixed    $subject  Object to read, such as a WC_Order.
	 *     @type string[] $fields   Optional. Only these fields. Extension fields come along only when `extensions` is listed.
	 *     @type string[] $exclude  Optional. Leave these fields out, or `extensions` for the extension fields.
	 * }
	 * @return array<string, mixed>
	 */
	public static function output_data( array $args ): array {
		$resource_name = (string) $args['resource'];
		$output        = array();
		foreach ( self::get_output_fields( $args ) as $field => $field_args ) {
			$output[ $field ] = self::resolve_output( $field_args['schema'], call_user_func( $field_args['get_callback'], $args['subject'] ) );
		}

		if ( ! empty( self::$extension_fields[ $resource_name ] ) && self::is_enabled() && self::includes_extensions( $args ) ) {
			$extensions = self::get_extension_values( $resource_name, $args['subject'] );
			if ( ! empty( $extensions ) ) {
				$output['extensions'] = $extensions;
			}
		}
		return $output;
	}

	/**
	 * Register a resource. Registering it again does nothing.
	 *
	 * @internal Core registers its resources through it. Extensions call register_resource().
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Slug of the resource, such as `order`.
	 */
	public static function register( string $resource_name ): void {
		self::$resources[ $resource_name ] = true;
	}

	/**
	 * Register a resource whose objects are WC_Data objects, so abilities can load and check them through it.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param DataResource $data_resource Resource.
	 * @phpstan-param DataResource<\WC_Data> $data_resource
	 */
	public static function add( DataResource $data_resource ): void {
		self::register( $data_resource->get_name() );
		self::$data_resources[ $data_resource->get_name() ] = $data_resource;
	}

	/**
	 * A resource that add() registered.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Resource.
	 * @return DataResource<\WC_Data>|null
	 */
	public static function get( string $resource_name ): ?DataResource {
		return self::$data_resources[ $resource_name ] ?? null;
	}

	/**
	 * Register a Core field of a resource.
	 *
	 * @internal Extensions call register_field(), which keeps their fields under `extensions`.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Field arguments.
	 *
	 *     @type string   $resource      Resource.
	 *     @type string   $field         Key of the field in the formatted object.
	 *     @type array    $schema        Optional. JSON schema of the value. A `$ref` to a resource, at the top, under `items` or under `properties`, nests that resource,
	 *                                   with `fields` or `exclude` next to it to nest only a part. Other keys win over the nested schema,
	 *                                   so `'type' => array( 'object', 'null' )` allows null.
	 *     @type callable $get_callback  Optional. Reads the value from the object. Root fields with a schema and a get_callback are part of the output.
	 *     @type bool     $personal_data Optional. Whether the value is personal data, left out of the schema and the output while it is withheld.
	 * }
	 * @return bool Whether the field was registered.
	 */
	public static function add_field( array $args ): bool {
		$resource_name = (string) ( $args['resource'] ?? '' );
		$field         = (string) ( $args['field'] ?? '' );
		if ( ! self::is_registered( $resource_name ) ) {
			self::report_unregistered( __METHOD__, $resource_name );
			return false;
		}
		if ( ! self::has_valid_personal_data_flag( __METHOD__, $args ) ) {
			return false;
		}
		if ( isset( $args['get_callback'] ) && ! is_callable( $args['get_callback'] ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "get_callback" argument must be callable.', '11.4.0' );
			return false;
		}

		self::$fields[ $resource_name ][ $field ] = array_diff_key( $args, array_flip( array( 'resource', 'field' ) ) );
		return true;
	}

	/**
	 * Extension fields of a resource, keyed by namespace, then field.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Resource.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function get_extension_fields( string $resource_name ): array {
		return self::$extension_fields[ $resource_name ] ?? array();
	}

	/**
	 * Query filters of a resource, keyed by namespace, then filter.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Resource.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function get_query_filters( string $resource_name ): array {
		return self::$filters[ $resource_name ] ?? array();
	}

	/**
	 * Validators of a resource.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_validators( string $resource_name ): array {
		return self::$validators[ $resource_name ] ?? array();
	}

	/**
	 * Whether a schema holds a `$ref` to a resource anywhere, so a value of it needs formatting.
	 *
	 * @param array<string, mixed> $schema JSON schema.
	 * @return bool
	 */
	private static function has_ref( array $schema ): bool {
		if ( isset( $schema['$ref'] ) ) {
			return true;
		}
		$children = array_merge(
			isset( $schema['items'] ) && is_array( $schema['items'] ) ? array( $schema['items'] ) : array(),
			is_array( $schema['properties'] ?? null ) ? array_filter( $schema['properties'], 'is_array' ) : array()
		);
		foreach ( $children as $child ) {
			if ( self::has_ref( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Arguments for the resource that a `$ref` field schema nests.
	 *
	 * @param array<string, mixed> $schema Field schema with the `$ref`, and optional `fields` or `exclude`.
	 * @return array<string, mixed>
	 */
	private static function get_ref_args( array $schema ): array {
		return array_merge(
			array_intersect_key( $schema, array_flip( array( 'fields', 'exclude' ) ) ),
			array( 'resource' => (string) $schema['$ref'] )
		);
	}

	/**
	 * Whether the `fields` and `exclude` arguments keep the extension fields.
	 *
	 * @param array<string, mixed> $args Arguments of output_schema() or output_data().
	 * @return bool
	 */
	private static function includes_extensions( array $args ): bool {
		if ( isset( $args['fields'] ) && ! in_array( 'extensions', (array) $args['fields'], true ) ) {
			return false;
		}
		return ! in_array( 'extensions', (array) ( $args['exclude'] ?? array() ), true );
	}

	/**
	 * Build the schema of a resource, and of the resources it refers to.
	 *
	 * @param array    $args      Arguments of output_schema().
	 * @param string[] $resolving Resources whose schema is being built, so a reference back to one of them stops instead of recursing forever.
	 * @return array<string, mixed>
	 */
	private static function build_schema( array $args, array $resolving ): array {
		$resource_name = (string) $args['resource'];
		$resolving[]   = $resource_name;
		$schema        = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
		foreach ( self::get_output_fields( $args ) as $field => $field_args ) {
			$schema['properties'][ $field ] = self::resolve_ref( $field_args['schema'], $resolving );
		}

		$extension_fields = self::is_enabled() && self::includes_extensions( $args ) ? self::get_output_extension_fields( $resource_name ) : array();
		if ( empty( $extension_fields ) ) {
			return $schema;
		}
		$extensions = array(
			'type'                 => 'object',
			'description'          => __( 'Values that extensions add, keyed by extension namespace, then field.', 'woocommerce' ),
			'additionalProperties' => false,
		);
		foreach ( $extension_fields as $namespace => $fields ) {
			$extensions['properties'][ $namespace ] = array(
				'type'                 => 'object',
				'properties'           => array_map( static fn( array $field_args ) => $field_args['schema'], $fields ),
				'additionalProperties' => false,
			);
		}
		$schema['properties']['extensions'] = $extensions;
		return $schema;
	}

	/**
	 * Replace a `$ref` to a resource, at the top of a schema, under `items` or under `properties`, with the schema of that resource.
	 *
	 * @param array<string, mixed> $schema    Field schema.
	 * @param string[]             $resolving Resources whose schema is being built.
	 * @return array<string, mixed>
	 */
	private static function resolve_ref( array $schema, array $resolving ): array {
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::resolve_ref( $schema['items'], $resolving );
		}
		if ( is_array( $schema['properties'] ?? null ) ) {
			foreach ( $schema['properties'] as $key => $property ) {
				if ( is_array( $property ) ) {
					$schema['properties'][ $key ] = self::resolve_ref( $property, $resolving );
				}
			}
		}
		if ( ! isset( $schema['$ref'] ) ) {
			return $schema;
		}

		$ref_args = self::get_ref_args( $schema );
		$schema   = array_diff_key( $schema, array_flip( array( '$ref', 'fields', 'exclude' ) ) );
		if ( in_array( $ref_args['resource'], $resolving, true ) || ! self::is_registered( $ref_args['resource'] ) ) {
			return array_merge( array( 'type' => 'object' ), $schema );
		}
		return array_merge( self::build_schema( $ref_args, $resolving ), $schema );
	}

	/**
	 * Root fields of a resource that are part of the output, after `fields` and `exclude`, without personal data while it is withheld.
	 *
	 * @param array $args Arguments of output_schema() or output_data().
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_output_fields( array $args ): array {
		$fields = self::without_withheld_fields(
			array_filter(
				self::$fields[ (string) $args['resource'] ] ?? array(),
				static fn( array $field_args ) => isset( $field_args['schema'], $field_args['get_callback'] )
			),
			(string) $args['resource']
		);
		if ( isset( $args['fields'] ) ) {
			$fields = array_intersect_key( $fields, array_flip( (array) $args['fields'] ) );
		}
		return array_diff_key( $fields, array_flip( (array) ( $args['exclude'] ?? array() ) ) );
	}

	/**
	 * Extension fields of a resource that are part of the output, without personal data while it is withheld.
	 *
	 * @param string $resource_name Resource.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function get_output_extension_fields( string $resource_name ): array {
		$extension_fields = array_map(
			static fn( array $fields ) => self::without_withheld_fields( $fields, $resource_name ),
			self::$extension_fields[ $resource_name ] ?? array()
		);
		return array_filter( $extension_fields );
	}

	/**
	 * Leave out the fields that hold personal data while the store does not share it, so neither the schema nor the
	 * output tells that they exist.
	 *
	 * @param array<string, array<string, mixed>> $fields        Field arguments keyed by field.
	 * @param string                              $resource_name Resource.
	 * @return array<string, array<string, mixed>>
	 */
	private static function without_withheld_fields( array $fields, string $resource_name ): array {
		$personal = array_filter( $fields, static fn( array $field_args ) => ! empty( $field_args['personal_data'] ) );
		if ( empty( $personal ) || PersonalData::is_shared( $resource_name ) ) {
			return $fields;
		}
		return array_diff_key( $fields, $personal );
	}

	/**
	 * Values of the extension fields of an object, keyed by namespace, then field. A value is cast to
	 * its schema type, and a field that throws or returns a value its schema does not allow is left out.
	 *
	 * @param string $resource_name Resource.
	 * @param mixed  $subject       Object to read.
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_extension_values( string $resource_name, $subject ): array {
		$extensions = array();
		foreach ( self::get_output_extension_fields( $resource_name ) as $namespace => $fields ) {
			foreach ( $fields as $field => $args ) {
				try {
					$value = call_user_func( $args['get_callback'], $subject );
				} catch ( \Throwable $e ) {
					wc_get_logger()->error(
						sprintf( 'Ability field "%s.%s" of "%s" failed: %s', $namespace, $field, $resource_name, $e->getMessage() ),
						array( 'source' => 'extensible-abilities' )
					);
					continue;
				}
				if ( ! self::matches_schema( $args, $value ) ) {
					continue;
				}
				$sanitized = rest_sanitize_value_from_schema( $value, $args['schema'], $field );
				if ( ! is_wp_error( $sanitized ) ) {
					$extensions[ $namespace ][ $field ] = $sanitized;
				}
			}
		}
		return $extensions;
	}

	/**
	 * Whether a value matches its field schema. A value other than null that
	 * does not match is logged and reported one time for each field.
	 *
	 * @param array<string, mixed> $args  Field arguments.
	 * @param mixed                $value Value that the get_callback returned.
	 * @return bool
	 */
	private static function matches_schema( array $args, $value ): bool {
		$valid = rest_validate_value_from_schema( $value, $args['schema'], $args['field'] );
		if ( true === $valid ) {
			return true;
		}

		$key = $args['resource'] . '.' . $args['namespace'] . '.' . $args['field'];
		if ( null === $value || isset( self::$reported[ $key ] ) ) {
			return false;
		}

		self::$reported[ $key ] = true;
		$message                = sprintf(
			'Ability field "%1$s.%2$s" of "%3$s" was left out because its value does not match its schema: %4$s',
			$args['namespace'],
			$args['field'],
			$args['resource'],
			$valid->get_error_message()
		);
		wc_get_logger()->error( $message, array( 'source' => 'extensible-abilities' ) );
		wc_doing_it_wrong( __CLASS__ . '::register_field', $message, '11.4.0' );
		return false;
	}

	/**
	 * Whether a value is a slug of lowercase letters, numbers, dashes and underscores, not only numbers.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_slug( $value ): bool {
		return is_string( $value ) && '' !== $value && sanitize_key( $value ) === $value && ! ctype_digit( $value );
	}

	/**
	 * Whether the given arguments are slugs, reporting the first one that is not.
	 *
	 * @param string               $method Method that was called.
	 * @param array<string, mixed> $args   Arguments.
	 * @param string[]             $keys   Keys that must hold slugs.
	 * @return bool
	 */
	private static function has_slugs( string $method, array $args, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! self::is_slug( $args[ $key ] ?? null ) ) {
				wc_doing_it_wrong(
					$method,
					sprintf( 'The "%s" argument must be a slug of lowercase letters, numbers, dashes and underscores, not only numbers.', $key ),
					'11.4.0'
				);
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the `personal_data` argument is missing or a boolean. Anything else is refused, so a
	 * field never falls back to being shared.
	 *
	 * @param string               $method Method that was called.
	 * @param array<string, mixed> $args   Field arguments.
	 * @return bool
	 */
	private static function has_valid_personal_data_flag( string $method, array $args ): bool {
		if ( ! isset( $args['personal_data'] ) || is_bool( $args['personal_data'] ) ) {
			return true;
		}

		wc_doing_it_wrong( $method, 'The "personal_data" argument must be a boolean.', '11.4.0' );
		return false;
	}

	/**
	 * Report a call that names a resource that is not registered.
	 *
	 * @param string $method        Method that was called.
	 * @param string $resource_name Resource.
	 */
	private static function report_unregistered( string $method, string $resource_name ): void {
		wc_doing_it_wrong(
			$method,
			sprintf( 'The "%s" resource is not registered. Call AbilityResources::register_resource() first.', $resource_name ),
			'11.4.0'
		);
	}
}
