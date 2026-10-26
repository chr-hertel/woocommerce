<?php
/**
 * Resource ability class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * An ability bound to one resource, such as `order`. Its output schema and
 * its output are built from the fields registered on that resource, so Core
 * fields, extension fields and the personal data rules apply without code in
 * the ability. Register it with a subclass as the `ability_class`, and leave out
 * `output_schema` to use the one built from its output shape.
 *
 * The output shape lists the properties of the output with a `$ref` where a
 * resource goes, the same way a resource field nests another resource, so one
 * declaration gives both the schema and the output.
 *
 * The API is experimental while the `extensible_abilities` feature exists.
 *
 * @since 11.4.0
 */
abstract class ResourceAbility extends \WP_Ability {

	/**
	 * Resource the ability returns, for example `product` or `order`.
	 *
	 * @since 11.4.0
	 *
	 * @return string
	 */
	abstract public function get_resource(): string;

	/**
	 * Properties of the output, as JSON schemas where a `$ref` with optional `fields` or `exclude`
	 * stands for a resource. The default is the resource under its own name, such as `{ "order": { ... } }`.
	 *
	 * @since 11.4.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_output_shape(): array {
		return array( $this->get_resource() => array( '$ref' => $this->get_resource() ) );
	}

	/**
	 * The output for values keyed like the output shape, with each object at a `$ref` formatted as its resource.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $values Values keyed by property, such as `array( 'order' => $order )`.
	 * @param array<string, array> $narrow Optional `fields` or `exclude` lists keyed by property, that leave more out
	 *                                     of a resource for this output only, such as line items that the input did not ask for.
	 * @return array<string, mixed>
	 */
	protected function prepare_output( array $values, array $narrow = array() ): array {
		$shape = $this->get_output_shape();
		foreach ( $narrow as $property => $args ) {
			if ( isset( $shape[ $property ]['items']['$ref'] ) ) {
				$shape[ $property ]['items'] = array_merge( $shape[ $property ]['items'], $args );
			} elseif ( isset( $shape[ $property ]['$ref'] ) ) {
				$shape[ $property ] = array_merge( $shape[ $property ], $args );
			}
		}
		return AbilityResources::resolve_output( self::shape_schema( $shape ), $values );
	}

	/**
	 * Load an object of the resource by ID, with the errors of the resource for a missing ID or object.
	 *
	 * @since 11.4.0
	 *
	 * @param int $id Object ID.
	 * @return \WC_Data|\WP_Error
	 */
	protected function load_resource( int $id ) {
		$resource = AbilityResources::get( $this->get_resource() );
		if ( null === $resource ) {
			return new \WP_Error(
				'woocommerce_ability_resource_not_loadable',
				__( 'This resource cannot load objects by ID.', 'woocommerce' ),
				array( 'status' => 500 )
			);
		}
		return $resource->load( $id );
	}

	/**
	 * Add the output schema built from the output shape when the registration has none.
	 *
	 * @param array<string, mixed> $args Ability properties.
	 * @return array<string, mixed>
	 */
	protected function prepare_properties( array $args ): array {
		if ( ! isset( $args['output_schema'] ) ) {
			$args['output_schema'] = AbilityResources::resolve_schema( self::shape_schema( $this->get_output_shape() ) );
		}
		return parent::prepare_properties( $args );
	}

	/**
	 * The object schema of an output shape.
	 *
	 * @param array<string, array<string, mixed>> $shape Output shape.
	 * @return array<string, mixed>
	 */
	private static function shape_schema( array $shape ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $shape,
			'additionalProperties' => false,
		);
	}
}
