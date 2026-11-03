#!/usr/bin/env bash
set -euo pipefail

# Installed outside the workspace: pnpm's trust policy rejects the Inspector's transitive pino@9.14.0.
prefix="${RUNNER_TEMP:-/tmp}/mcp-inspector"
npm install --silent --no-save --prefix "$prefix" @modelcontextprotocol/inspector@2.11.0

MCP_INSPECTOR_BIN="$prefix/node_modules/.bin/mcp-inspector" \
WP_ENV_CONFIG=.wp-env.mcp-inspector.json \
	node --test 'tests/mcp-inspector/*.test.mjs'
