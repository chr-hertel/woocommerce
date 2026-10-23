<?php
/**
 * Store settings ability definition file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Abilities\Domain;

use Automattic\WooCommerce\Abilities\AbilityDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability that returns the store settings most questions
 * need, in one call. Site name, URL and WordPress version come from
 * core/get-site-info; plugins and features from the system status ability.
 */
class StoreSettingsGet extends AbstractDomainAbility implements AbilityDefinition {

	/**
	 * Onboarding profile keys left out because they identify the merchant.
	 */
	private const PERSONAL_PROFILE_KEYS = array( 'store_email', 'is_agree_marketing' );

	/**
	 * Get the ability name.
	 *
	 * @return string
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce/store-settings-get';
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
			'label'               => __( 'Get store settings', 'woocommerce' ),
			'description'         => __( 'Store address, selling and shipping locations, currency, units, time and date formats, tax and stock options, checkout toggles, WooCommerce version, store age and onboarding answers.', 'woocommerce' ),
			'category'            => 'woocommerce',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => self::get_output_schema(),
			'execute_callback'    => array( __CLASS__, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_read_store_settings' ),
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
	 * Read the store settings.
	 *
	 * @return array
	 *
	 * @since 11.4.0
	 */
	public static function execute(): array {
		$country   = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) );
		$installed = (int) get_option( 'woocommerce_admin_install_timestamp', 0 );
		$profile   = get_option( 'woocommerce_onboarding_profile', array() );
		$profile   = is_array( $profile ) ? array_diff_key( $profile, array_flip( self::PERSONAL_PROFILE_KEYS ) ) : array();

		return array(
			'address'             => array(
				'address_1'    => (string) get_option( 'woocommerce_store_address', '' ),
				'address_2'    => (string) get_option( 'woocommerce_store_address_2', '' ),
				'city'         => (string) get_option( 'woocommerce_store_city', '' ),
				'postcode'     => (string) get_option( 'woocommerce_store_postcode', '' ),
				'country_code' => $country[0],
				'state_code'   => $country[1] ?? '',
			),
			'selling_locations'   => self::get_locations( 'woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_all_except_countries' ),
			'shipping_locations'  => self::get_locations( 'woocommerce_ship_to_countries', 'woocommerce_specific_ship_to_countries', '' ),
			'currency'            => array(
				'code'               => get_woocommerce_currency(),
				'symbol'             => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5 ),
				'position'           => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals'           => wc_get_price_decimals(),
				'decimal_separator'  => wc_get_price_decimal_separator(),
				'thousand_separator' => wc_get_price_thousand_separator(),
			),
			'units'               => array(
				'weight'    => (string) get_option( 'woocommerce_weight_unit', 'kg' ),
				'dimension' => (string) get_option( 'woocommerce_dimension_unit', 'cm' ),
			),
			'time'                => array(
				'timezone'      => wp_timezone_string(),
				'gmt_offset'    => (float) get_option( 'gmt_offset', 0 ),
				'date_format'   => (string) get_option( 'date_format' ),
				'time_format'   => (string) get_option( 'time_format' ),
				'start_of_week' => (int) get_option( 'start_of_week', 1 ),
			),
			'tax'                 => array(
				'enabled'            => wc_tax_enabled(),
				'prices_include_tax' => wc_prices_include_tax(),
				'display_in_shop'    => (string) get_option( 'woocommerce_tax_display_shop', 'excl' ),
				'display_in_cart'    => (string) get_option( 'woocommerce_tax_display_cart', 'excl' ),
			),
			'stock'               => array(
				'manage_stock'        => 'yes' === get_option( 'woocommerce_manage_stock', 'yes' ),
				'low_stock_threshold' => (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ),
				'hide_out_of_stock'   => 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ),
			),
			'checkout'            => array(
				'coupons_enabled'        => wc_coupons_enabled(),
				'guest_checkout'         => 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
				'reviews_enabled'        => wc_reviews_enabled(),
				'review_ratings_enabled' => wc_review_ratings_enabled(),
			),
			'woocommerce_version' => WC()->version,
			'store_age_days'      => $installed > 0 ? (int) floor( ( time() - $installed ) / DAY_IN_SECONDS ) : null,
			'onboarding_profile'  => $profile,
		);
	}

	/**
	 * Check store settings read access.
	 *
	 * @return bool
	 *
	 * @since 11.4.0
	 */
	public static function can_read_store_settings(): bool {
		return wc_rest_check_manager_permissions( 'settings', 'read' );
	}

	/**
	 * Selling or shipping locations from their scope option and country lists.
	 *
	 * @param string $scope_option    Option holding the scope.
	 * @param string $included_option Option holding the included countries.
	 * @param string $excluded_option Option holding the excluded countries, or empty when the scope has none.
	 * @return array
	 */
	private static function get_locations( string $scope_option, string $included_option, string $excluded_option ): array {
		$scope = (string) get_option( $scope_option, '' );
		$list  = static function ( string $option ): array {
			$countries = get_option( $option, array() );
			return is_array( $countries ) ? array_values( $countries ) : array();
		};

		if ( 'specific' === $scope ) {
			return array(
				'scope'     => 'specific',
				'countries' => $list( $included_option ),
			);
		}
		if ( 'all_except' === $scope && '' !== $excluded_option ) {
			return array(
				'scope'     => 'all_except',
				'countries' => $list( $excluded_option ),
			);
		}
		if ( '' === $scope && '' === $excluded_option ) {
			return array( 'scope' => 'same_as_selling' );
		}

		return array( 'scope' => '' === $scope ? 'all' : $scope );
	}

	/**
	 * Get the ability output schema.
	 *
	 * @return array
	 */
	private static function get_output_schema(): array {
		$object    = static function ( array $properties, string $description = '' ): array {
			return array_filter(
				array(
					'type'                 => 'object',
					'description'          => $description,
					'properties'           => $properties,
					'additionalProperties' => false,
				)
			);
		};
		$string    = array( 'type' => 'string' );
		$boolean   = array( 'type' => 'boolean' );
		$locations = $object(
			array(
				'scope'     => $string,
				'countries' => array(
					'type'  => 'array',
					'items' => $string,
				),
			),
			__( 'countries lists the included countries for specific, or the excluded ones for all_except.', 'woocommerce' )
		);

		return $object(
			array(
				'address'             => $object(
					array(
						'address_1'    => $string,
						'address_2'    => $string,
						'city'         => $string,
						'postcode'     => $string,
						'country_code' => $string,
						'state_code'   => $string,
					)
				),
				'selling_locations'   => $locations,
				'shipping_locations'  => $locations,
				'currency'            => $object(
					array(
						'code'               => $string,
						'symbol'             => $string,
						'position'           => $string,
						'decimals'           => array( 'type' => 'integer' ),
						'decimal_separator'  => $string,
						'thousand_separator' => $string,
					)
				),
				'units'               => $object(
					array(
						'weight'    => $string,
						'dimension' => $string,
					)
				),
				'time'                => $object(
					array(
						'timezone'      => $string,
						'gmt_offset'    => array( 'type' => 'number' ),
						'date_format'   => $string,
						'time_format'   => $string,
						'start_of_week' => array(
							'type'        => 'integer',
							'description' => __( '0 is Sunday.', 'woocommerce' ),
						),
					)
				),
				'tax'                 => $object(
					array(
						'enabled'            => $boolean,
						'prices_include_tax' => $boolean,
						'display_in_shop'    => $string,
						'display_in_cart'    => $string,
					)
				),
				'stock'               => $object(
					array(
						'manage_stock'        => $boolean,
						'low_stock_threshold' => array( 'type' => 'integer' ),
						'hide_out_of_stock'   => $boolean,
					)
				),
				'checkout'            => $object(
					array(
						'coupons_enabled'        => $boolean,
						'guest_checkout'         => $boolean,
						'reviews_enabled'        => $boolean,
						'review_ratings_enabled' => $boolean,
					)
				),
				'woocommerce_version' => $string,
				'store_age_days'      => array( 'type' => array( 'integer', 'null' ) ),
				'onboarding_profile'  => array(
					'type'        => 'object',
					'description' => __( 'Answers from the store setup wizard, such as industry and product types.', 'woocommerce' ),
				),
			)
		);
	}
}
