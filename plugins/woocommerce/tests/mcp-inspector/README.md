# MCP Inspector contract tests

These tests drive WooCommerce's abilities the way an MCP client sees them: [MCP Inspector](https://github.com/modelcontextprotocol/inspector) talks to `wp mcp-adapter serve` in the `wp-env` development environment, and the suite checks every exposed `woocommerce/` ability against shared rules:

- Its `mcp-adapter-get-ability-info` response matches a reviewed snapshot in `snapshots/ability-info/`.
- No schema description lists its own `enum` values or names its default in prose.
- Its schema stays within a token budget, estimated at four characters per token.
- A readonly ability without required input runs with `{}`.
- A customer is refused.
- No input field takes a type list or one value or a list of it, apart from the entries in `MIXED_TYPE_EXCEPTIONS`. The list only shrinks: once a field has one type, its entry has to go.

It also runs the calls in `cases.mjs` against fixtures the suite creates (product tags `mcp-alpha`, `mcp-beta`, `mcp-gamma`, one order per status in `fixtures.mjs`, and a `customer` user), and checks what an MCP client gets back. Add a case when a change alters what an ability accepts or returns.

## Setup

The suite has its own environment, with the MCP Adapter, on port 8098:

```bash
pnpm env:mcp-inspector:start
```

## Running

```bash
pnpm test:mcp-inspector:ci
```

This installs the Inspector outside the workspace and runs the suite against that environment, the way CI's "MCP Inspector contract tests" job does. `pnpm test:mcp-inspector` runs the suite alone, configured by:

- `MCP_INSPECTOR_BIN` sets the Inspector binary.
- `WP_ENV_CONFIG` sets the wp-env config file, such as `.wp-env.mcp-inspector.json`. Without it, the suite uses the development environment, which then needs the MCP Adapter.
- `WP_ENV_CWD` points at another checkout whose environment is already running.
- `MCP_INSPECTOR_CONCURRENCY` sets how many Inspector calls run at once. The default is 4.
- `UPDATE_SNAPSHOTS=1` rewrites the snapshots after an intended change.

A missing snapshot is written and fails the run, so review it before re-running.
