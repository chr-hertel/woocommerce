import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const PLUGIN_DIR = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);

const INSPECTOR_BIN =
	process.env.MCP_INSPECTOR_BIN ??
	path.join( PLUGIN_DIR, 'node_modules/.bin/mcp-inspector' );

// The wp-env environment is found from the working directory, so this can point at a checkout whose env is already running.
const WP_ENV_CWD = process.env.WP_ENV_CWD ?? PLUGIN_DIR;

const WP_ENV = [
	'exec',
	'wp-env',
	...( process.env.WP_ENV_CONFIG
		? [ '--config', process.env.WP_ENV_CONFIG ]
		: [] ),
];

const TIMEOUT_MS = 120_000;

function exec( command, args, options = {} ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn( command, args, { timeout: TIMEOUT_MS, ...options } );
		let stdout = '';
		let stderr = '';
		child.stdout.on( 'data', ( chunk ) => ( stdout += chunk ) );
		child.stderr.on( 'data', ( chunk ) => ( stderr += chunk ) );
		child.on( 'error', reject );
		child.on( 'close', ( code ) =>
			0 === code
				? resolve( stdout.trim() )
				: reject( new Error( `${ command } ${ args.join( ' ' ) } exited with ${ code }.\n${ stderr }${ stdout }` ) )
		);
	} );
}

let cliContainer;

/**
 * The `docker compose exec` that `wp-env run cli` performs. Calling it directly keeps concurrent calls away from
 * wp-env's state file, which `wp-env run` rewrites on every call and can leave without the environment's runtime.
 */
function cliCommand() {
	cliContainer ??= exec( 'pnpm', [ ...WP_ENV, 'status', '--json' ], { cwd: WP_ENV_CWD } ).then( ( output ) => {
		const { status, installPath } = JSON.parse( output );
		if ( 'running' !== status ) {
			throw new Error( `The wp-env environment is ${ status }; start it first.` );
		}
		// wp-env falls back to 1000 where the host has no uid, as on Windows.
		const user = `${ process.getuid?.() ?? 1000 }:${ process.getgid?.() ?? 1000 }`;
		return [ 'docker', 'compose', '-f', path.join( installPath, 'docker-compose.yml' ), 'exec', '-T', '-w', '/var/www/html', '--user', user, 'cli' ];
	} );
	return cliContainer;
}

export async function wpCli( ...args ) {
	const [ command, ...prefix ] = await cliCommand();
	return exec( command, [ ...prefix, 'wp', ...args ] );
}

function toolArg( key, value ) {
	const encoded =
		typeof value === 'string' ? value : JSON.stringify( value );
	return [ '--tool-arg', `${ key }=${ encoded }` ];
}

/**
 * Runs one MCP request through the Inspector CLI against `wp mcp-adapter serve`, acting as `user`.
 *
 * Resolves with the parsed response even when the tool reports an error, which the CLI signals with a non-zero exit code.
 */
export async function inspect( { user, method, toolName, toolArgs = {} } ) {
	if ( ! existsSync( INSPECTOR_BIN ) ) {
		throw new Error(
			`MCP Inspector is not installed at ${ INSPECTOR_BIN }. Install @modelcontextprotocol/inspector@2.11.0 and point MCP_INSPECTOR_BIN at its mcp-inspector binary.`
		);
	}

	const args = [ '--cli', '--transport', 'stdio', '--method', method ];

	if ( toolName ) {
		args.push( '--tool-name', toolName );
		for ( const [ key, value ] of Object.entries( toolArgs ) ) {
			args.push( ...toolArg( key, value ) );
		}
	}

	// Without `--`, the Inspector takes the adapter's `--server=` as its own flag.
	args.push(
		'--',
		...( await cliCommand() ),
		'wp',
		'mcp-adapter',
		'serve',
		'--server=mcp-adapter-default-server',
		`--user=${ user }`
	);

	return run( args );
}

function run( args ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn( INSPECTOR_BIN, args, { timeout: TIMEOUT_MS } );
		let stdout = '';
		let stderr = '';
		child.stdout.on( 'data', ( chunk ) => ( stdout += chunk ) );
		child.stderr.on( 'data', ( chunk ) => ( stderr += chunk ) );
		child.on( 'error', reject );
		child.on( 'close', ( code ) => {
			let response;
			try {
				response = JSON.parse( stdout );
			} catch {}

			if ( response && ! response.error ) {
				resolve( response );
				return;
			}

			reject(
				new Error(
					`Inspector exited with ${ code } and no MCP response.\n${ stderr }${ stdout }`
				)
			);
		} );
	} );
}

export function callTool( user, toolName, toolArgs ) {
	return inspect( { user, method: 'tools/call', toolName, toolArgs } );
}

/**
 * The meta tools return their payload as JSON text; structured content is only set on success.
 */
export function payloadOf( response ) {
	if ( response.structuredContent ) {
		return response.structuredContent;
	}
	const text = response.content?.find( ( item ) => item.type === 'text' )
		?.text;
	try {
		return JSON.parse( text );
	} catch {
		return text;
	}
}

export async function mapWithLimit( items, limit, fn ) {
	const results = new Array( items.length );
	let next = 0;
	const worker = async () => {
		while ( next < items.length ) {
			const index = next++;
			results[ index ] = await fn( items[ index ] );
		}
	};
	await Promise.all(
		Array.from( { length: Math.min( limit, items.length ) }, worker )
	);
	return results;
}
