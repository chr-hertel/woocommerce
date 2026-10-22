<?php
/**
 * Customer data class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether Core's abilities return customer data, such as emails and the
 * extension fields flagged as personal data. ExtensibleAbilities applies it to
 * every formatted object, so the abilities themselves do not check it.
 */
class CustomerData {

	/**
	 * Filter that switches customer data off for AI agents and other ability clients.
	 */
	public const FILTER = 'woocommerce_abilities_share_customer_data';

	/**
	 * Core properties that hold customer data, keyed by resource.
	 */
	private const PROPERTIES = array(
		'order' => array( 'billing_email' ),
	);

	/**
	 * Whether the abilities return the customer data of a resource. Any filter
	 * value other than true withholds it, so a broken callback fails closed.
	 *
	 * @param string $resource_name Resource being formatted, such as `order`.
	 * @return bool
	 */
	public static function is_shared( string $resource_name ): bool {
		/**
		 * Filters whether WooCommerce abilities return customer data.
		 *
		 * Return false to leave out customer details, such as billing emails, and
		 * the extension fields registered with `personal_data`.
		 *
		 * @since 11.4.0
		 *
		 * @param bool   $shared        Whether customer data is returned. Default true.
		 * @param string $resource_name Resource being formatted, such as `order`.
		 */
		return true === apply_filters( self::FILTER, true, $resource_name );
	}

	/**
	 * Null the customer data properties of a formatted object while customer data is withheld.
	 *
	 * @param array<string, mixed> $output        Formatted object.
	 * @param string               $resource_name Resource.
	 * @return array<string, mixed>
	 */
	public static function withhold_properties( array $output, string $resource_name ): array {
		if ( empty( self::PROPERTIES[ $resource_name ] ) || self::is_shared( $resource_name ) ) {
			return $output;
		}

		foreach ( self::PROPERTIES[ $resource_name ] as $property ) {
			if ( array_key_exists( $property, $output ) ) {
				$output[ $property ] = null;
			}
		}
		return $output;
	}
}
