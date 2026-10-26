<?php
/**
 * AbilitiesContractTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities;

use Automattic\WooCommerce\Tests\Internal\Abilities\Domain\DomainAbilityTestCase;

/**
 * Rules every `woocommerce/` ability follows, checked without a model. A new
 * ability is covered as soon as it registers.
 */
class AbilitiesContractTest extends DomainAbilityTestCase {

	/**
	 * Estimated tokens an ability may cost in a tool list: name, description and both schemas.
	 */
	private const TOKEN_BUDGET = 1500;

	/**
	 * Abilities over the budget, with the budget they must stay within. Shrink, never grow.
	 */
	private const TOKEN_BUDGET_EXCEPTIONS = array(
		'woocommerce/product-create' => 3100,
		'woocommerce/product-update' => 3200,
	);

	/**
	 * Inputs for read abilities that cannot run without one, keyed by ability name. Values are filled per test.
	 */
	private const REQUIRED_INPUTS = array( 'woocommerce/order-notes-query' => 'order_id' );

	/**
	 * Read abilities that do not withhold personal data yet. Order notes are free text that can hold it.
	 */
	private const NOT_WITHHOLDING = array( 'woocommerce/order-notes-query' );

	private const CANARY_EMAIL  = 'zebulon.canary@example.com';
	private const CANARY_NAME   = 'Zebulon';
	private const CANARY_SECRET = 'sk_live_canary_9f3';

	/**
	 * @testdox Should not restate enum values in a property description.
	 */
	public function test_no_description_restates_its_enum_values(): void {
		$offenders = array();
		foreach ( $this->get_core_abilities() as $name => $ability ) {
			foreach ( $this->walk_properties( $ability->get_input_schema() ) as $path => $property ) {
				$enum = array_values( array_filter( (array) ( $property['enum'] ?? array() ), 'is_string' ) );
				if ( count( $enum ) < 2 || empty( $property['description'] ) ) {
					continue;
				}
				$pattern = '/' . implode( '["\']?(?:,|,? or|\/)\s+["\']?', array_map( 'preg_quote', $enum ) ) . '/i';
				if ( preg_match( $pattern, $property['description'] ) ) {
					$offenders[] = "{$name} {$path}";
				}
			}
		}

		$this->assertSame( array(), $offenders, 'The schema already lists the values; prose that repeats them costs tokens.' );
	}

	/**
	 * @testdox Should not name the default in a property description.
	 */
	public function test_no_description_names_its_default(): void {
		$offenders = array();
		foreach ( $this->get_core_abilities() as $name => $ability ) {
			foreach ( $this->walk_properties( $ability->get_input_schema() ) as $path => $property ) {
				if ( isset( $property['default'] ) && preg_match( '/\bdefaults?( to|:)\s/i', (string) ( $property['description'] ?? '' ) ) ) {
					$offenders[] = "{$name} {$path}";
				}
			}
		}

		$this->assertSame( array(), $offenders, 'The schema default already says it.' );
	}

	/**
	 * @testdox Should run every read ability without required input with no input at all.
	 */
	public function test_read_abilities_accept_no_input(): void {
		$offenders = array();
		foreach ( $this->get_read_abilities() as $name => $ability ) {
			if ( ! empty( $ability->get_input_schema()['required'] ) ) {
				continue;
			}
			if ( is_wp_error( $ability->validate_input( $ability->normalize_input( null ) ) ) ) {
				$offenders[] = $name;
			}
		}

		$this->assertSame( array(), $offenders, 'Give the input schema a default of an empty array.' );
	}

	/**
	 * @testdox Should refuse every ability to a subscriber.
	 */
	public function test_every_ability_checks_permissions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$offenders = array();
		foreach ( $this->get_core_abilities() as $name => $ability ) {
			if ( true === $ability->check_permissions( $ability->normalize_input( null ) ) ) {
				$offenders[] = $name;
			}
		}

		$this->assertSame( array(), $offenders );
	}

	/**
	 * @testdox Should keep each ability within its token budget.
	 */
	public function test_schemas_stay_within_token_budget(): void {
		$offenders = array();
		foreach ( $this->get_core_abilities() as $name => $ability ) {
			// Compact JSON runs 3.6 to 4.5 bytes per o200k_base token for these schemas; the low end keeps the estimate from undercounting.
			$tokens = (int) ceil(
				strlen(
					(string) wp_json_encode(
						array( $name, $ability->get_description(), $ability->get_input_schema(), $ability->get_output_schema() )
					)
				) / 3.6
			);
			$budget = self::TOKEN_BUDGET_EXCEPTIONS[ $name ] ?? self::TOKEN_BUDGET;
			if ( $tokens > $budget ) {
				$offenders[] = "{$name}: {$tokens} > {$budget}";
			}
		}

		$this->assertSame( array(), $offenders );
	}

	/**
	 * @testdox Should return no customer data from any read ability while it is withheld, and no secret ever.
	 */
	public function test_no_customer_data_or_secret_reaches_output(): void {
		$inputs = array( 'order_id' => $this->seed_canaries() );
		$this->withhold_personal_data();

		$leaks = array();
		foreach ( $this->get_read_abilities() as $name => $ability ) {
			if ( in_array( $name, self::NOT_WITHHOLDING, true ) ) {
				continue;
			}
			$input = array();
			if ( isset( self::REQUIRED_INPUTS[ $name ] ) ) {
				$input = array( 'id' => $inputs[ self::REQUIRED_INPUTS[ $name ] ] );
			} elseif ( ! empty( $ability->get_input_schema()['required'] ) ) {
				$leaks[] = "{$name}: add it to REQUIRED_INPUTS";
				continue;
			}
			$runs = array( $input );
			if ( 'woocommerce/settings-query' === $name ) {
				$runs[] = array( 'group' => 'canary' );
			}
			foreach ( $runs as $run ) {
				$result = $ability->execute( $run );
				if ( is_wp_error( $result ) ) {
					$leaks[] = "{$name} failed: " . $result->get_error_code();
					continue;
				}
				$json = strtolower( (string) wp_json_encode( $result ) );
				foreach ( array( self::CANARY_EMAIL, self::CANARY_NAME, self::CANARY_SECRET ) as $canary ) {
					if ( false !== strpos( $json, strtolower( $canary ) ) ) {
						$leaks[] = "{$name} returned {$canary}";
					}
				}
			}
		}

		$this->assertSame( array(), $leaks );
	}

	/**
	 * Put the canaries where customer data and secrets live: an order, its note, a customer, a review, a coupon, a gateway and a settings group.
	 *
	 * @return int Order ID.
	 */
	private function seed_canaries(): int {
		$customer = new \WC_Customer();
		$customer->set_username( 'canary' );
		$customer->set_email( self::CANARY_EMAIL );
		$customer->set_password( 'x' );
		$customer->set_first_name( self::CANARY_NAME );
		$customer->set_billing_first_name( self::CANARY_NAME );
		$customer->save();

		$order = \WC_Helper_Order::create_order( $customer->get_id() );
		$order->set_billing_email( self::CANARY_EMAIL );
		$order->set_billing_first_name( self::CANARY_NAME );
		$order->save();
		$order->add_order_note( 'Call ' . self::CANARY_NAME . ' back.' );

		$product = \WC_Helper_Product::create_simple_product();
		wp_insert_comment(
			array(
				'comment_post_ID'      => $product->get_id(),
				'comment_author'       => self::CANARY_NAME,
				'comment_author_email' => self::CANARY_EMAIL,
				'comment_content'      => 'Great.',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);

		$coupon = new \WC_Coupon();
		$coupon->set_code( 'CANARY' );
		$coupon->set_email_restrictions( array( self::CANARY_EMAIL ) );
		$coupon->save();

		update_option(
			'woocommerce_bacs_settings',
			array(
				'enabled'      => 'yes',
				'api_password' => self::CANARY_SECRET,
			)
		);
		WC()->payment_gateways()->init();

		update_option( 'canary_api_key', self::CANARY_SECRET );
		add_filter(
			'woocommerce_settings_groups',
			static function ( array $groups ): array {
				$groups[] = array(
					'id'    => 'canary',
					'label' => 'Canary',
				);
				return $groups;
			}
		);
		add_filter(
			'woocommerce_settings-canary',
			static function (): array {
				return array(
					array(
						'id'         => 'canary_api_key',
						'option_key' => 'canary_api_key',
						'label'      => 'Key',
						'type'       => 'text',
					),
				);
			}
		);

		return $order->get_id();
	}

	/**
	 * Abilities in WooCommerce's reserved namespace.
	 *
	 * @return array<string, \WP_Ability>
	 */
	private function get_core_abilities(): array {
		$abilities = array_filter(
			wp_get_abilities(),
			static function ( \WP_Ability $ability ): bool {
				return 0 === strpos( $ability->get_name(), 'woocommerce/' );
			}
		);
		$this->assertNotEmpty( $abilities );

		return $abilities;
	}

	/**
	 * Abilities annotated as read-only.
	 *
	 * @return array<string, \WP_Ability>
	 */
	private function get_read_abilities(): array {
		return array_filter(
			$this->get_core_abilities(),
			static function ( \WP_Ability $ability ): bool {
				return true === ( $ability->get_meta()['annotations']['readonly'] ?? null );
			}
		);
	}

	/**
	 * Every property schema in a schema, keyed by its path.
	 *
	 * @param array  $schema Schema.
	 * @param string $path   Path of the schema.
	 * @return array<string, array>
	 */
	private function walk_properties( array $schema, string $path = '' ): array {
		$found = array();
		foreach ( (array) ( $schema['properties'] ?? array() ) as $key => $property ) {
			if ( is_array( $property ) ) {
				$found[ "{$path}.{$key}" ] = $property;
				$found                    += $this->walk_properties( $property, "{$path}.{$key}" );
			}
		}
		if ( is_array( $schema['items'] ?? null ) ) {
			$found += $this->walk_properties( $schema['items'], "{$path}[]" );
		}
		foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $keyword ) {
			foreach ( (array) ( $schema[ $keyword ] ?? array() ) as $index => $branch ) {
				if ( is_array( $branch ) ) {
					$found += $this->walk_properties( $branch, "{$path}<{$keyword}{$index}>" );
				}
			}
		}

		return $found;
	}
}
