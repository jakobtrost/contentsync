/**
 * Compile JSX source folders to build folders.
 * Reads folder list from build.config.json; runs Babel for each (src → build).
 * Usage: node scripts/compile-jsx.js [--watch]
 */

const path = require( 'path' );
const fs = require( 'fs' );
const { spawn } = require( 'child_process' );

const pluginRoot = path.resolve( __dirname, '..' );
const configPath = path.join( pluginRoot, 'build.config.json' );
const watch = process.argv.includes( '--watch' );

let config;
try {
	config = JSON.parse( fs.readFileSync( configPath, 'utf8' ) );
} catch ( err ) {
	console.error( 'Could not read build.config.json:', err.message );
	process.exit( 1 );
}

const folders = config.folders;
if ( !Array.isArray( folders ) || folders.length === 0 ) {
	console.log( 'No folders in build.config.json "folders" array.' );
	process.exit( 0 );
}

const srcDir = ( rel ) => path.join( pluginRoot, rel, 'src' );
const buildDir = ( rel ) => path.join( pluginRoot, rel, 'build' );

function runBabel( relPath, isWatch ) {
	const src = srcDir( relPath );
	const out = buildDir( relPath );

	if ( !fs.existsSync( src ) ) {
		console.warn( `Skipping ${ relPath }: src directory does not exist.` );

		return Promise.resolve();
	}

	return new Promise( ( resolve, reject ) => {
		// Use shell command string so paths with spaces are handled.
		const quote = ( a ) => ( String( a ).indexOf( ' ' ) >= 0 ? '"' + a + '"' : a );
		const parts = [ 'npx', 'babel', quote( src ), '--out-dir', quote( out ), '--extensions', '.jsx', '--copy-files' ];
		if ( isWatch ) parts.push( '--watch' );
		const cmd = parts.join( ' ' );
		const child = spawn( cmd, [], {
			cwd: pluginRoot,
			stdio: 'inherit',
			shell: true
		} );

		child.on( 'error', reject );
		child.on( 'close', ( code ) => {
			if ( code !== 0 && !isWatch ) {
				reject( new Error( `Babel exited with code ${ code }` ) );
			} else {
				resolve();
			}
		} );
	} );
}

async function main() {
	if ( watch ) {
		// In watch mode, run all folders in parallel (each runs babel --watch).
		console.log( 'Watch mode: compiling', folders.length, 'folder(s).' );
		const promises = folders.map( ( rel ) => runBabel( rel, true ) );
		await Promise.all( promises );
		// Never resolves when watching (each process keeps running).
	} else {
		for ( const rel of folders ) {
			console.log( 'Compiling', rel, '...' );
			await runBabel( rel, false );
		}

		console.log( 'Done.' );
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
