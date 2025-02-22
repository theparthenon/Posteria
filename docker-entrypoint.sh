#!/bin/bash
set -e

# Make sure the directories exist and have correct permissions
for dir in movies tv-shows tv-seasons collections; do
    directory="/var/www/html/posters/$dir"
    mkdir -p "$directory"
    chown -R www-data:www-data "$directory"
    chmod -R 775 "$directory"
done

# Start Apache in foreground
apache2-foreground
