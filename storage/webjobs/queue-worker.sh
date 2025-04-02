#!/bin/bash
while true
do
  php /home/site/wwwroot/artisan queue:listen --tries=6 --timeout=480
  sleep 5  # Prevents high CPU usage
done
