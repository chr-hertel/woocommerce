import { FIXTURE_TAGS } from './fixtures.mjs';

const TERMS = 'woocommerce/product-terms-query';
const ORDERS = 'woocommerce/orders-query';

/**
 * Calls an MCP client makes, with what it gets back. `expect` holds exactly one of:
 * `termSlugs` (the exact result), `includesTermSlugs`, `orderStatuses` (the exact set returned), `refused` (a pattern for the error).
 */
export const CASES = [
	{
		ability: TERMS,
		title: 'filters by a list of slugs',
		parameters: { taxonomy: 'product_tag', slug: [ 'mcp-alpha', 'mcp-gamma' ] },
		expect: { termSlugs: [ 'mcp-alpha', 'mcp-gamma' ] },
	},
	{
		ability: TERMS,
		title: 'filters by one slug sent as a string',
		parameters: { taxonomy: 'product_tag', slug: 'mcp-beta' },
		expect: { termSlugs: [ 'mcp-beta' ] },
	},
	{
		ability: TERMS,
		title: 'applies no slug filter for an empty list',
		parameters: { taxonomy: 'product_tag', slug: [], per_page: 100 },
		expect: { includesTermSlugs: FIXTURE_TAGS },
	},
	{
		ability: ORDERS,
		title: 'filters by a list of statuses',
		parameters: { status: [ 'processing', 'completed' ] },
		expect: { orderStatuses: [ 'completed', 'processing' ] },
	},
	{
		ability: ORDERS,
		title: 'filters by one status sent as a string',
		parameters: { status: 'on-hold' },
		expect: { orderStatuses: [ 'on-hold' ] },
	},
	{
		ability: ORDERS,
		title: 'refuses an unknown status',
		parameters: { status: [ 'not-a-status' ] },
		expect: { refused: /status/ },
	},
];
