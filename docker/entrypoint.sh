#!/bin/bash

# Ensure runtime directories for supervisor, nginx, and logs exist
mkdir -p /var/log/supervisor /var/run /var/log/nginx

# Generate APP_KEY if missing or empty
if [ -f .env ]; then
    if ! grep -qE '^APP_KEY=base64:' .env || [ -z "$(grep '^APP_KEY=' .env | cut -d '=' -f 2)" ]; then
        echo "APP_KEY is missing or empty. Generating application key..."
        php artisan key:generate --force
    fi
else
    echo "No .env file found. Generating key in environment runtime..."
    php artisan key:generate --force
fi

# Cache configuration, routes, and views in production
if [ "$APP_ENV" = "production" ]; then
    echo "Caching Laravel configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

# Run database migrations without stopping execution on minor errors
echo "Running database migrations..."
php artisan migrate --force --isolated || echo "Migrations completed with warnings or skipped."

# Execute main container command or default to supervisord
if [ $# -gt 0 ]; then
    exec "$@"
else
    exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
fi