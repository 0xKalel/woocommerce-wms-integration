#!/bin/bash

# WP-Cron runner - executed by system cron
# This script runs every minute to process WordPress scheduled tasks

# Log file for cron execution
LOG_FILE="/var/log/wp-cron.log"

# Function to log messages
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

# Start execution
log_message "Starting WP-Cron execution"

# Run WP-Cron (allow root for Docker environment)
/usr/local/bin/wp cron event run --due-now --path=/var/www/html --allow-root >> "$LOG_FILE" 2>&1

# Check exit status
if [ $? -eq 0 ]; then
    log_message "WP-Cron executed successfully"
else
    log_message "WP-Cron execution failed"
fi

# Keep log file size under control (max 10MB)
if [ -f "$LOG_FILE" ] && [ $(stat -c%s "$LOG_FILE") -gt 10485760 ]; then
    tail -n 1000 "$LOG_FILE" > "$LOG_FILE.tmp"
    mv "$LOG_FILE.tmp" "$LOG_FILE"
    log_message "Log file truncated"
fi
