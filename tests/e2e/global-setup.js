/**
 * Builds the fixtures the e2e suite needs, before it runs.
 *
 * The component specs need a page holding every §6 component on ordinary page
 * furniture. That page used to exist only because it had been created by hand
 * on one machine, which meant the specs passed there and would have failed
 * everywhere else — a test that only passes where it was written is worse than
 * no test, because it reads as coverage.
 *
 * Skipped when WP_BASE_URL points somewhere that is not wp-env: there is no
 * safe way to create posts on a site we do not own.
 */

const { execFileSync } = require( 'node:child_process' );

const FIXTURE = 'wp-content/plugins/gated-media-access/tests/e2e/fixtures/kitchen-sink.php';

module.exports = async () => {
	if ( process.env.WP_BASE_URL && ! process.env.WP_BASE_URL.includes( 'localhost' ) ) {
		// eslint-disable-next-line no-console
		console.log( 'Skipping fixture setup: WP_BASE_URL is not a local wp-env site.' );
		return;
	}

	// `wp eval-file` wraps the file, which breaks `declare( strict_types )`,
	// so the fixture is included instead.
	const output = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', 'eval', `include "${ FIXTURE }";` ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);

	if ( ! output.includes( 'Fixture ready' ) ) {
		throw new Error( `Fixture did not build:\n${ output }` );
	}

	// eslint-disable-next-line no-console
	console.log( output.split( '\n' ).find( ( line ) => line.includes( 'Fixture ready' ) ) );
};
