#!/usr/bin/env bash
#
# Starts the multisite environment the Network Admin e2e specs run against and
# seeds the fixture network they assert on.
#
# This is a second, independent wp-env instance rather than a multisite flag on
# the shared .wp-env.json. wp-env regenerates wp-tests-config.php from the tests
# environment's wp-config.php on every start, and the WordPress test bootstrap
# runs the entire suite as multisite whenever that file defines MULTISITE. A
# flag on the shared instance would therefore turn the single-site PHPUnit run
# in CI into a second multisite run, silently, and the two-mode coverage
# strategy in .github/workflows/phpunit.yml would stop meaning anything.
#
# Own ports, own database, own containers: the single-site specs on :8888 and
# `npm test` are untouched.
#
# Called from tests/e2e/playwright.config.js as the multisite project's
# webServer, and from .github/workflows/e2e.yml. Also runnable on its own:
#
#   npm run env:start:multisite

set -euo pipefail

# wp-env resolves a config's relative plugin path against the working
# directory rather than against .wp-env.json, so every command below runs from
# the directory holding that config.
cd "$(dirname "$0")/../tests/e2e/multisite"

SITE_SLUG='team'

wp() {
	npx wp-env run cli wp "$@"
}

npx wp-env start

# Network activation is what provisions a presence table for each site and the
# network summary table; wp-env's own install only activates on the main site.
wp plugin activate presence-api --network

if ! wp site list --field=url | grep -q "/${SITE_SLUG}/"; then
	wp site create --slug="${SITE_SLUG}" --title='Team' --email='wordpress@example.com'
fi

# Users are seeded once and reused rather than created per test: the REST users
# endpoint refuses deletion on multisite, so the teardown the single-site specs
# rely on has no equivalent here. The logins carry no underscores because
# multisite rejects anything but lowercase letters and numbers.
seed_user() {
	local login="$1" email="$2" display="$3"

	if wp user get "${login}" --field=ID >/dev/null 2>&1; then
		return
	fi

	wp user create "${login}" "${email}" \
		--user_pass='password' \
		--display_name="${display}" \
		--role='editor'
}

seed_user 'presencenetusera' 'presencenetusera@example.com' 'Network UserA'
seed_user 'presencenetuserb' 'presencenetuserb@example.com' 'Network UserB'

# add_user_to_blog() rather than `wp user set-role --url=`: the role has to be
# granted on a site the user is not a member of yet, which is the one thing
# set-role does not do.
wp eval "
\$site = get_id_from_blogname( '${SITE_SLUG}' );
add_user_to_blog( \$site, get_user_by( 'login', 'presencenetusera' )->ID, 'author' );
add_user_to_blog( \$site, get_user_by( 'login', 'presencenetuserb' )->ID, 'author' );
"
