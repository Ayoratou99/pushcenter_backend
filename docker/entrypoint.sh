#!/bin/bash

# Ensure runtime directories for supervisor, nginx, and logs exist
mkdir -p /var/log/supervisor /var/run /var/log/nginx

# APP_KEY must be provided by the environment and must be STABLE.
#
# Generating one here would give each container (app, horizon, cron) a different
# key, lost on every recreate. APP_KEY encrypts users' two_factor_secret, so a
# key that moves makes every Google Authenticator enrolment undecryptable and
# invalidates every issued token. Fail loudly instead.
if [ -z "${APP_KEY:-}" ]; then
    cat >&2 <<'MSG'
ERROR: APP_KEY is not set.

Generate one once and put it in .env before starting the stack:

    docker compose run --rm --no-deps app php artisan key:generate --show

Copy the printed value into .env as APP_KEY=base64:..., then run
`docker compose up -d` again.
MSG
    exit 1
fi

# Render the nginx config with the console origin, so the API documentation can
# be embedded there. sed rather than envsubst: no extra package, and nginx's own
# $variables are left untouched.
FRONTEND_ORIGIN="$(printf '%s' "${FRONTEND_URL:-}" | sed 's:/*$::')"
sed "s|\${FRONTEND_ORIGIN}|${FRONTEND_ORIGIN}|g" \
    /etc/nginx/default.conf.template > /etc/nginx/http.d/default.conf
echo "Nginx configured (documentation frameable from: ${FRONTEND_ORIGIN:-same origin only})"

# Cache configuration, routes, and views in production
if [ "$APP_ENV" = "production" ]; then
    echo "Caching Laravel configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

# Generate the OpenAPI document. It is a build artefact (excluded from the
# image), so producing it here guarantees the documentation always describes the
# code actually deployed.
echo "Generating the API documentation..."
mkdir -p storage/api-docs
php artisan l5-swagger:generate || echo "API documentation could not be generated; /api/documentation will be empty."

# Run database migrations without stopping execution on minor errors
echo "Running database migrations..."
php artisan migrate --force --isolated || echo "Migrations completed with warnings or skipped."

# The commands above run as root, so hand storage and the caches back to the
# user php-fpm and the workers actually run as.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Execute main container command or default to supervisord
if [ $# -gt 0 ]; then
    exec "$@"
else
    exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
fi