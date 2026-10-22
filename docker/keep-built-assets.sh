#!/bin/sh
# Runs when the web container starts (nginx runs /docker-entrypoint.d/*.sh).
#
# Every build renames its JS/CSS chunks, and this image only carries the
# newest set. A browser tab opened before a deploy still asks for the old
# names, and during a rolling deploy the new app can briefly be answered by
# the old web container. Either way a missing chunk is a blank page.
#
# So each web container adds its assets to a shared history volume, and nginx
# falls back to it (see docker/nginx/default.conf). cp -n keeps the first-seen
# modification time, which is what the 14-day prune goes by.
set -e

HISTORY=/var/www/html/public/build-history/assets

if [ -d /var/www/html/public/build-history ]; then
    mkdir -p "$HISTORY"
    cp -n /var/www/html/public/build/assets/* "$HISTORY/" 2>/dev/null || true
    find "$HISTORY" -type f -mtime +14 -delete
fi
