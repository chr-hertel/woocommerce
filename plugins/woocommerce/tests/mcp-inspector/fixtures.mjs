import { wpCli } from './inspector.mjs';

export const FIXTURE_TAGS = [ 'mcp-alpha', 'mcp-beta', 'mcp-gamma' ];
export const FIXTURE_ORDER_STATUSES = [ 'processing', 'completed', 'on-hold' ];

const OPTION = 'woocommerce_mcp_inspector_fixture_orders';

// Idempotent, so a run against a long-lived dev environment reuses what an earlier run created.
const SEED = `
if ( ! get_user_by( 'login', 'customer' ) ) {
	wp_insert_user( array( 'user_login' => 'customer', 'user_email' => 'customer@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
}
foreach ( ${ JSON.stringify( FIXTURE_TAGS ) } as $slug ) {
	if ( ! term_exists( $slug, 'product_tag' ) ) {
		wp_insert_term( $slug, 'product_tag', array( 'slug' => $slug ) );
	}
}
$orders = (array) get_option( '${ OPTION }', array() );
foreach ( ${ JSON.stringify( FIXTURE_ORDER_STATUSES ) } as $status ) {
	if ( empty( $orders[ $status ] ) || ! wc_get_order( $orders[ $status ] ) ) {
		$order = wc_create_order();
		$order->set_status( $status );
		$orders[ $status ] = $order->save();
	}
}
update_option( '${ OPTION }', $orders, false );
`;

export function seedFixtures() {
	return wpCli( 'eval', SEED.replace( /\n\s*/g, ' ' ) );
}
