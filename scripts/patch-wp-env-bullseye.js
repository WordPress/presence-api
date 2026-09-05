// Bullseye's LTS ended 2026-08-31 and @wordpress/env has no bullseye apt
// redirect yet (only stretch/buster). Delete this once it ships one.
const fs = require( 'node:fs' );

const FILE = 'node_modules/@wordpress/env/lib/runtime/docker/docker-config.js';
const ANCHOR = '# buster (';
const PATCH = `# bullseye (LTS ended 2026-08-31)
RUN sed -i 's|deb.debian.org/debian bullseye|archive.debian.org/debian bullseye|g' /etc/apt/sources.list
RUN sed -i 's|security.debian.org/debian-security bullseye-security|archive.debian.org/debian-security bullseye-security|g' /etc/apt/sources.list
RUN sed -i '/bullseye-updates/d' /etc/apt/sources.list

${ ANCHOR }`;

if ( ! fs.existsSync( FILE ) ) {
	process.exit( 0 );
}

const contents = fs.readFileSync( FILE, 'utf8' );

if ( contents.includes( 'bullseye' ) ) {
	process.exit( 0 );
}

fs.writeFileSync( FILE, contents.replace( ANCHOR, PATCH ) );
