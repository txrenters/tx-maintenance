#!/bin/bash
while true
do
  php /home/site/wwwroot/artisan schedule:run --no-interaction
  sleep 60  # Runs every minute
done
