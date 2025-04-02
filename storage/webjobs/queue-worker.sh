#!/bin/bash
while true
do
  php /home/site/wwwroot/artisan queue:work --tries=6 --timeout=480
  sleep 5  # Prevents high CPU usage
done
