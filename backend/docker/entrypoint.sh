#!/bin/sh
set -eu

# Laravel needs writable dirs for sessions, cache, views and compiled config.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -n -c /etc/supervisor/conf.d/supervisord.conf