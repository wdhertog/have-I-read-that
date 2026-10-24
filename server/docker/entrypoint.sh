#!/bin/sh
set -e
# Wait for the database, then bring the schema up to date.
for i in $(seq 1 30); do
    php bin/migrate.php && break
    echo "Database not ready yet, retrying ($i/30)..."
    sleep 2
done
exec docker-php-entrypoint "$@"
