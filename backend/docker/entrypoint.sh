#!/bin/sh
set -e

php artisan migrate --force

# Cache config so the running app no longer reads a .env file at request
# time (there is none in the image - see .dockerignore), and link the public
# storage dir so uploaded avatars resolve. Routes are intentionally NOT
# cached: web.php / api.php still use closure routes, which route:cache
# cannot serialize.
php artisan storage:link || true
php artisan config:cache

# Render (and most PaaS free tiers) assign the public port at runtime via
# $PORT - nginx.conf.template can't read env vars directly, so the actual
# listen directive is substituted in here, once, before nginx ever starts.
# This is a full nginx.conf (events{}/http{} included), so it replaces the
# image's default entirely rather than dropping into conf.d/.
PORT="${PORT:-10000}"
sed "s/__PORT__/${PORT}/" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

exec supervisord -c /etc/supervisord.conf
