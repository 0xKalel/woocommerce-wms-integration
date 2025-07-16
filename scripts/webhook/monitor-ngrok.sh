#!/bin/sh
# ngrok URL monitoring script
# Continuously monitors ngrok tunnel and logs URL changes

NGROK_API_URL="${NGROK_API_URL:-http://ngrok:4040/api}"
WEBHOOK_LOG_DIR="${WEBHOOK_LOG_DIR:-/logs}"
LOG_FILE="$WEBHOOK_LOG_DIR/ngrok-monitor.log"

# Ensure log directory exists
mkdir -p "$WEBHOOK_LOG_DIR"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a "$LOG_FILE"
}

get_ngrok_url() {
    curl -s "$NGROK_API_URL/tunnels" 2>/dev/null | \
    grep -o '"public_url":"https://[^"]*' | \
    grep -o 'https://[^"]*' | \
    head -1
}

log "Starting ngrok URL monitor..."
log "API URL: $NGROK_API_URL"

LAST_URL=""
RETRY_COUNT=0
MAX_RETRIES=30

while true; do
    CURRENT_URL=$(get_ngrok_url)
    
    if [ -n "$CURRENT_URL" ] && [ "$CURRENT_URL" != "null" ]; then
        if [ "$CURRENT_URL" != "$LAST_URL" ]; then
            log "ngrok URL changed: $CURRENT_URL"
            echo "$CURRENT_URL" > "$WEBHOOK_LOG_DIR/current-url.txt"
            echo "$(date '+%Y-%m-%d %H:%M:%S')" > "$WEBHOOK_LOG_DIR/last-update.txt"
            LAST_URL="$CURRENT_URL"
            RETRY_COUNT=0
        fi
    else
        RETRY_COUNT=$((RETRY_COUNT + 1))
        if [ $RETRY_COUNT -le $MAX_RETRIES ]; then
            log "Waiting for ngrok tunnel... (attempt $RETRY_COUNT/$MAX_RETRIES)"
        else
            log "ngrok tunnel not available after $MAX_RETRIES attempts"
        fi
    fi
    
    sleep 5
done
