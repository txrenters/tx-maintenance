#!/bin/sh
# Health check for the PHP-FPM container.
#
# Requests Laravel's health route (/up in this app) through FastCGI rather
# than pinging FPM's own /ping. /ping only proves a worker can answer; routing
# the probe through the framework proves a request can actually be served, so
# bin/deploy.sh never retires the old container in favour of a broken one.
set -e

# A wedged or absent FPM fails the substitution outright, which `set -e` turns
# into a failing check.
response=$(
    SCRIPT_NAME=/index.php \
    SCRIPT_FILENAME=/var/www/html/public/index.php \
    REQUEST_URI=/up \
    DOCUMENT_URI=/up \
    QUERY_STRING= \
    REQUEST_METHOD=GET \
    SERVER_PROTOCOL=HTTP/1.1 \
    cgi-fcgi -bind -connect 127.0.0.1:9000
)

# PHP emits no Status header on a 200, and `Status: 500 ...` when the health
# route fails.
if echo "$response" | grep -qiE '^Status:[[:space:]]*[45]'; then
    exit 1
fi

exit 0
