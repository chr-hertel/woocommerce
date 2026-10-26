<?php
/**
 * ResourceAbilityTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Abilities;

use Automattic\WooCommerce\Abilities\AbilityResources;
use Automattic\WooCommerce\Abilities\ResourceAbility;
use Automattic\WooCommerce\Tests\Internal\Abilities\Domain\DomainAbilityTestCase;

/**
 * Outputs that hold several resources, side by side and nested.
 */
class ResourceAbilityTest extends DomainAbilityTestCase {

	/**
	 * Set up a `test_order` resource with a nullable `customer` and a `test_refund` resource.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( 'test_order', 'test_customer', 'test_refund' ) as $resource_name ) {
			AbilityResources::register( $resource_name );
			AbilityResources::add_field(
				array(
					'resource'     => $resource_name,
					'field'        => 'id',
					'schema'       => array( 'type' => 'integer' ),
					'get_callback' => static fn( array $subject ) => $subject['id'],
				)
			);
		}
		AbilityResources::add_field(
			array(
				'resource'      => 'test_customer',
				'field'         => 'email',
				'schema'        => array( 'type' => array( 'string', 'null' ) ),
				'get_callback'  => static fn( array $customer ) => $customer['email'],
				'personal_data' => true,
			)
		);
		AbilityResources::add_field(
			array(
				'resource'     => 'test_order',
				'field'        => 'customer',
				'schema'       => array(
					'$ref' => 'test_customer',
					'type' => array( 'object', 'null' ),
				),
				'get_callback' => static fn( array $order ) => $order['customer'],
			)
		);
	}

	/**
	 * @testdox Should build a compound output and its schema from one output shape, with plain properties passed through.
	 */
	public function test_builds_a_compound_output_from_its_shape(): void {
		$sut = $this->create_order_ability(
			array(
				'refund' => array( '$ref' => 'test_refund' ),
				'order'  => array(
					'$ref'    => 'test_order',
					'exclude' => array( 'customer' ),
				),
				'notes'  => array( 'type' => 'integer' ),
			)
		);

		$output = $sut->output(
			array(
				'refund' => array( 'id' => 2 ),
				'order'  => array(
					'id'       => 1,
					'customer' => null,
				),
				'notes'  => 3,
			)
		);
		$schema = $sut->get_output_schema();

		$this->assertSame( array( 'id' => 2 ), $output['refund'] );
		$this->assertSame( array( 'id' => 1 ), $output['order'] );
		$this->assertSame( 3, $output['notes'] );
		$this->assertArrayNotHasKey( 'customer', $schema['properties']['order']['properties'] );
		$this->assertTrue( rest_validate_value_from_schema( $output, $schema ), 'The compound output should match its schema.' );
	}

	/**
	 * @testdox Should leave more out of a resource for one output when prepare_output() narrows it.
	 */
	public function test_narrows_a_resource_for_one_output(): void {
		$sut = $this->create_order_ability();

		$output = $sut->output(
			array(
				'order' => array(
					'id'       => 1,
					'customer' => null,
				),
			),
			array( 'order' => array( 'exclude' => array( 'customer' ) ) )
		);

		$this->assertSame( array( 'order' => array( 'id' => 1 ) ), $output );
		$this->assertTrue( rest_validate_value_from_schema( $output, $sut->get_output_schema() ), 'The narrowed output should match the full schema.' );
	}

	/**
	 * @testdox Should allow null for a nested resource whose field schema allows it, and leave its personal data out.
	 */
	public function test_nested_resource_can_be_null(): void {
		$this->withhold_personal_data();
		$sut    = $this->create_order_ability();
		$schema = $sut->get_output_schema();

		$guest    = $sut->output(
			array(
				'order' => array(
					'id'       => 1,
					'customer' => null,
				),
			)
		);
		$customer = $sut->output(
			array(
				'order' => array(
					'id'       => 2,
					'customer' => array(
						'id'    => 3,
						'email' => 'jane@example.com',
					),
				),
			)
		);

		$customer_schema = $schema['properties']['order']['properties']['customer'];
		$this->assertSame( array( 'object', 'null' ), $customer_schema['type'] );
		$this->assertArrayNotHasKey( 'email', $customer_schema['properties'], 'The nested schema should leave out its own personal data.' );
		$this->assertNull( $guest['order']['customer'] );
		$this->assertTrue( rest_validate_value_from_schema( $guest, $schema ), 'A guest order should match the schema.' );
		$this->assertSame( 3, $customer['order']['customer']['id'] );
		$this->assertArrayNotHasKey( 'email', $customer['order']['customer'], 'The nested customer should leave out its own personal data.' );
		$this->assertTrue( rest_validate_value_from_schema( $customer, $schema ), 'An order with a customer should match the schema.' );
	}

	/**
	 * @testdox Should nest only the listed fields of a resource, without its extension fields unless listed.
	 */
	public function test_nested_resource_with_a_field_list(): void {
		update_option( 'woocommerce_feature_' . AbilityResources::FEATURE_ID . '_enabled', 'yes' );
		AbilityResources::register_field(
			array(
				'resource'     => 'test_customer',
				'namespace'    => 'test-ext',
				'field'        => 'tier',
				'schema'       => array( 'type' => 'string' ),
				'get_callback' => static fn() => 'gold',
			)
		);
		AbilityResources::add_field(
			array(
				'resource'     => 'test_order',
				'field'        => 'customer_ref',
				'schema'       => array(
					'$ref'   => 'test_customer',
					'fields' => array( 'id' ),
				),
				'get_callback' => static fn( array $order ) => $order['customer'],
			)
		);
		$sut = $this->create_order_ability();

		$schema = $sut->get_output_schema();
		$output = $sut->output(
			array(
				'order' => array(
					'id'       => 1,
					'customer' => array(
						'id'    => 3,
						'email' => 'jane@example.com',
					),
				),
			)
		);

		$order_schema = $schema['properties']['order'];
		$this->assertSame( array( 'id' ), array_keys( $order_schema['properties']['customer_ref']['properties'] ) );
		$this->assertArrayNotHasKey( 'fields', $order_schema['properties']['customer_ref'] );
		$this->assertSame( array( 'id' => 3 ), $output['order']['customer_ref'] );
		$this->assertSame( 'gold', $output['order']['customer']['extensions']['test-ext']['tier'], 'A nested resource without a field list should keep its extension fields.' );
		$this->assertTrue( rest_validate_value_from_schema( $output, $schema ), 'The output should match its schema.' );
	}

	/**
	 * An ability bound to `test_order` with an output shape, and prepare_output() exposed.
	 *
	 * @param array|null $shape Output shape, or null for the order under `order`.
	 * @return ResourceAbility
	 */
	private function create_order_ability( ?array $shape = null ): ResourceAbility {
		return new class( $shape ?? array( 'order' => array( '$ref' => 'test_order' ) ) ) extends ResourceAbility {
			/**
			 * Output shape.
			 *
			 * @var array
			 */
			private array $shape;

			/**
			 * Register the ability without a registry.
			 *
			 * @param array $shape Output shape.
			 */
			public function __construct( array $shape ) {
				$this->shape = $shape;
				parent::__construct(
					'test/order',
					array(
						'label'               => 'Order',
						'description'         => 'Returns an order.',
						'category'            => 'woocommerce',
						'execute_callback'    => '__return_null',
						'permission_callback' => '__return_true',
					)
				);
			}

			/**
			 * Resource.
			 *
			 * @return string
			 */
			public function get_resource(): string {
				return 'test_order';
			}

			/**
			 * Output shape.
			 *
			 * @return array
			 */
			protected function get_output_shape(): array {
				return $this->shape;
			}

			/**
			 * Expose prepare_output().
			 *
			 * @param array $values Values keyed by property.
			 * @param array $narrow Field lists keyed by property.
			 * @return array
			 */
			public function output( array $values, array $narrow = array() ): array {
				return $this->prepare_output( $values, $narrow );
			}
		};
	}
}
