// Bullseye's LTS ended 2026-08-31 and @wordpress/env has no bullseye apt
// redirect yet (only stretch/buster). Delete this once it ships one.
const fs = require( 'node:fs' );

const FILE = 'node_modules/@wordpress/env/lib/runtime/docker/docker-config.js';
const ANCHOR = '# buster (';
const PATCH = `# bullseye (LTS ended 2026-08-31)
RUN echo 'deb http://snapshot.debian.org/archive/debian/20221114T000000Z bullseye main' > /etc/apt/sources.list
RUN echo 'deb http://snapshot.debian.org/archive/debian-security/20221114T000000Z bullseye-security main' >> /etc/apt/sources.list
RUN echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until

${ ANCHOR }`;

if ( ! fs.existsSync( FILE ) ) {
	process.exit( 0 );
}

const contents = fs.readFileSync( FILE, 'utf8' );

if ( contents.includes( 'bullseye' ) ) {
	process.exit( 0 );
}

fs.writeFileSync( FILE, contents.replace( ANCHOR, PATCH ) );
