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

const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );

// wp-env mounts the project as a plugin named after the directory it sits in,
// which is not always this plugin's slug — CI checks out into a throwaway
// directory named after the run, so it mounts as plugins/run-60. Hardcoding
// the slug fails there, and fails as a silent no-op rather than an error.
const SLUG = path.basename( process.cwd() );
const FIXTURE = `wp-content/plugins/${ SLUG }/tests/e2e/fixtures/kitchen-sink.php`;

module.exports = async () => {
	if ( process.env.WP_BASE_URL && ! process.env.WP_BASE_URL.includes( 'localhost' ) ) {
		// eslint-disable-next-line no-console
		console.log( 'Skipping fixture setup: WP_BASE_URL is not a local wp-env site.' );
		return;
	}

	// `wp eval-file` wraps the file, which breaks `declare( strict_types )`,
	// so the fixture is included instead. spawnSync rather than execFileSync so
	// stderr is kept: wp can report a failed include there and still exit 0,
	// which left this throwing an error with nothing in it.
	const result = spawnSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', 'eval', `include "${ FIXTURE }";` ],
		{ encoding: 'utf8' }
	);

	const output = `${ result.stdout || '' }${ result.stderr || '' }`;

	if ( ! output.includes( 'Fixture ready' ) ) {
		throw new Error(
			`Fixture did not build (${ FIXTURE }, exit ${ result.status }):\n${ output }`
		);
	}

	// eslint-disable-next-line no-console
	console.log( output.split( '\n' ).find( ( line ) => line.includes( 'Fixture ready' ) ) );
};
