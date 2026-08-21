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
const FIXTURE_DIR = `wp-content/plugins/${ SLUG }/tests/e2e/fixtures`;

// kitchen-sink builds the components page; shop builds the product, the group
// and the order the round 7 specs walk.
const FIXTURES = [ 'kitchen-sink.php', 'shop.php' ];

/**
 * Runs one fixture and returns everything it printed.
 *
 * `wp eval-file` wraps the file, which breaks `declare( strict_types )`, so the
 * fixture is included instead. spawnSync rather than execFileSync so stderr is
 * kept: wp can report a failed include there and still exit 0, which left this
 * throwing an error with nothing in it.
 *
 * @param {string} file The fixture's filename.
 * @return {string} Its combined output.
 */
function build( file ) {
	const target = `${ FIXTURE_DIR }/${ file }`;

	const result = spawnSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', 'eval', `include "${ target }";` ],
		{ encoding: 'utf8' }
	);

	const output = `${ result.stdout || '' }${ result.stderr || '' }`;

	if ( ! output.includes( 'Fixture ready' ) ) {
		throw new Error(
			`Fixture did not build (${ target }, exit ${ result.status }):\n${ output }`
		);
	}

	return output;
}

module.exports = async () => {
	if (
		process.env.WP_BASE_URL &&
		! process.env.WP_BASE_URL.includes( 'localhost' )
	) {
		// eslint-disable-next-line no-console
		console.log(
			'Skipping fixture setup: WP_BASE_URL is not a local wp-env site.'
		);
		return;
	}

	for ( const file of FIXTURES ) {
		const output = build( file );

		// A product's URL carries a uuid minted when the fixture ran, so the
		// specs are handed it rather than hardcoding one that changes.
		for ( const line of output.split( '\n' ) ) {
			const found = line.match( /^(GATEDMEDIA_[A-Z_]+)=(.+)$/ );

			if ( found ) {
				process.env[ found[ 1 ] ] = found[ 2 ].trim();
			}

			if ( line.includes( 'Fixture ready' ) ) {
				// eslint-disable-next-line no-console
				console.log( `${ file }: ${ line.trim() }` );
			}
		}
	}
};
