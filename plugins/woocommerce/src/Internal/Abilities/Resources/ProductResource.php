<?php
/**
 * Product resource class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Resources;

use Automattic\WooCommerce\Enums\ProductStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The `product` resource: the fields that abilities return for a product, and
 * how a product is loaded and checked.
 *
 * @extends DataResource<\WC_Product>
 *
 * @since 11.4.0
 */
class ProductResource extends DataResource {

	public const NAME = 'product';

	/**
	 * Resource name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Class of a product.
	 *
	 * @return class-string<\WC_Product>
	 */
	public function get_data_class(): string {
		return \WC_Product::class;
	}

	/**
	 * Look up a product.
	 *
	 * @param int $id Product ID.
	 * @return \WC_Data|null|false
	 */
	protected function find( int $id ) {
		return wc_get_product( $id );
	}

	/**
	 * Messages for a missing product ID and a product that is not found.
	 *
	 * @return array{id_required: string, not_found: string}
	 */
	protected function get_messages(): array {
		return array(
			'id_required' => __( 'Product ID is required.', 'woocommerce' ),
			'not_found'   => __( 'Product not found.', 'woocommerce' ),
		);
	}

	/**
	 * Post status slugs that a product can be set to.
	 *
	 * @since 11.4.0
	 *
	 * @return array<int, string>
	 */
	public static function get_mutation_status_slugs(): array {
		return array_merge(
			array_keys( get_post_statuses() ),
			array( ProductStatus::FUTURE )
		);
	}

	/**
	 * Every post status slug a product in the output can have.
	 *
	 * @since 11.4.0
	 *
	 * @return array<int, string>
	 */
	public static function get_output_status_slugs(): array {
		return array_values(
			array_unique(
				array_merge(
					self::get_mutation_status_slugs(),
					array( ProductStatus::AUTO_DRAFT, ProductStatus::TRASH )
				)
			)
		);
	}

	/**
	 * Schema type of a stock quantity, which is fractional when the stock amount filter allows it.
	 *
	 * @since 11.4.0
	 *
	 * @return string
	 */
	public static function get_stock_quantity_schema_type(): string {
		return wc_is_stock_amount_integer() ? 'integer' : 'number';
	}

	/**
	 * Fields of a product, in output order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_fields(): array {
		$price_schema = array(
			'type'        => 'string',
			'description' => __( 'Decimal price as a string, without a currency symbol.', 'woocommerce' ),
		);
		$string       = array( 'type' => 'string' );
		$boolean      = array( 'type' => 'boolean' );

		return array_merge(
			array(
				'id'        => $this->prop( 'id', array( 'type' => 'integer' ) ),
				'name'      => $this->prop( 'name', $string ),
				'slug'      => $this->prop( 'slug', $string ),
				'permalink' => $this->prop(
					'permalink',
					array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Product permalink, or null when no public permalink is available.', 'woocommerce' ),
						'format'      => 'uri',
					),
					array( 'get' => static fn( $permalink ) => false === $permalink ? null : $permalink )
				),
				'type'      => $this->prop(
					'type',
					array(
						'type'        => 'string',
						'description' => __( 'Internal product type slug, such as simple, external, grouped, or variable.', 'woocommerce' ),
						'enum'        => array_keys( wc_get_product_types() ),
					)
				),
				'status'    => $this->prop(
					'status',
					array(
						'type' => 'string',
						'enum' => self::get_output_status_slugs(),
					)
				),
				'sku'       => $this->prop( 'sku', $string ),
			),
			$this->currency( static fn() => get_woocommerce_currency() ),
			array(
				'price'            => $this->prop( 'price', $price_schema ),
				'regular_price'    => $this->prop( 'regular_price', $price_schema ),
				'sale_price'       => $this->prop( 'sale_price', $price_schema ),
				'stock_status'     => $this->prop(
					'stock_status',
					array(
						'type' => 'string',
						'enum' => array_keys( wc_get_product_stock_status_options() ),
					)
				),
				'stock_quantity'   => $this->prop(
					'stock_quantity',
					array(
						'type'        => array( self::get_stock_quantity_schema_type(), 'null' ),
						'description' => __( 'Current stock quantity, or null when no stock quantity is set.', 'woocommerce' ),
					),
					array( 'get' => static fn( $quantity ) => null === $quantity ? null : wc_stock_amount( $quantity ) )
				),
				'manage_stock'     => $this->prop( 'manage_stock', $boolean, array( 'get' => 'boolval' ) ),
				'virtual'          => $this->prop( 'virtual', $boolean, array( 'get' => 'boolval' ) ),
				'downloadable'     => $this->prop( 'downloadable', $boolean, array( 'get' => 'boolval' ) ),
				'external_url'     => $this->prop(
					'product_url',
					array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'External product URL for external products.', 'woocommerce' ),
						'format'      => 'uri',
					),
					array( 'applies_to' => \WC_Product_External::class )
				),
				'button_text'      => $this->prop(
					'button_text',
					array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Button text for external products.', 'woocommerce' ),
					),
					array( 'applies_to' => \WC_Product_External::class )
				),
				'grouped_products' => array(
					'schema'       => array(
						'type'        => 'array',
						'description' => __( 'Product IDs included as children of a grouped product.', 'woocommerce' ),
						'items'       => array( 'type' => 'integer' ),
					),
					'get_callback' => static fn( \WC_Product $product ) => $product instanceof \WC_Product_Grouped ? array_map( 'absint', $product->get_children( 'edit' ) ) : array(),
				),
			),
			$this->dates( 'created', 'modified' )
		);
	}
}
