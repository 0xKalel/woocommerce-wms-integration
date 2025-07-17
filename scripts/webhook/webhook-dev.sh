#!/bin/bash
# Webhook Development Helper Script
# Manages ngrok tunnels and webhook configuration for WMS integration

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LOGS_DIR="$PROJECT_ROOT/logs/webhook"
CONFIG_DIR="$PROJECT_ROOT/config"

# Ensure directories exist
mkdir -p "$LOGS_DIR" "$CONFIG_DIR"

# Load environment variables
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(cat "$PROJECT_ROOT/.env" | grep -v '^#' | grep -v '^$' | xargs)
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

log() {
    echo -e "${CYAN}[$(date '+%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}" >&2
}

success() {
    echo -e "${GREEN}[SUCCESS] $1${NC}"
}

warning() {
    echo -e "${YELLOW}[WARNING] $1${NC}"
}

# Function to check if ngrok authtoken is configured
check_ngrok_auth() {
    if [ -z "$NGROK_AUTHTOKEN" ]; then
        error "NGROK_AUTHTOKEN not set in .env file"
        echo ""
        echo "To get webhook testing working:"
        echo "1. Sign up at https://ngrok.com/"
        echo "2. Get your authtoken from https://dashboard.ngrok.com/get-started/your-authtoken"
        echo "3. Add it to your .env file: NGROK_AUTHTOKEN=your_token_here"
        echo ""
        return 1
    fi
    return 0
}

# Function to get ngrok public URL
get_ngrok_url() {
    local max_attempts=30
    local attempt=1
    
    while [ $attempt -le $max_attempts ]; do
        # Check if ngrok API is accessible
        if curl -s http://localhost:4040/api/tunnels > /dev/null 2>&1; then
            # Try multiple methods to get the URL
            local url=""
            
            # Method 1: Look for https tunnel
            url=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | grep -o '"public_url":"https://[^"]*' | head -1 | cut -d'"' -f4)
            
            # Method 2: If no https, try any tunnel
            if [ -z "$url" ] || [ "$url" = "null" ]; then
                url=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | grep -o '"public_url":"[^"]*' | head -1 | cut -d'"' -f4)
            fi
            
            # Method 3: Use jq if available
            if [ -z "$url" ] || [ "$url" = "null" ]; then
                if command -v jq >/dev/null 2>&1; then
                    url=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | jq -r '.tunnels[0].public_url' 2>/dev/null)
                fi
            fi
            
            # Check if we got a valid URL
            if [ -n "$url" ] && [ "$url" != "null" ] && echo "$url" | grep -q "ngrok"; then
                echo "$url"
                return 0
            fi
        fi
        
        log "Waiting for ngrok tunnel... (attempt $attempt/$max_attempts)"
        sleep 2
        attempt=$((attempt + 1))
    done
    
    error "Failed to get ngrok URL after $max_attempts attempts"
    return 1
}

# Function to update WordPress with ngrok URL
update_wordpress_webhook_url() {
    local ngrok_url="$1"
    log "Updating WordPress with ngrok URL: $ngrok_url"
    
    # Update WordPress site URL for webhook endpoints
    docker-compose run --rm wpcli wp option update home "$ngrok_url" || true
    docker-compose run --rm wpcli wp option update siteurl "$ngrok_url" || true
    
    # Update WMS integration webhook URL
    local webhook_endpoint="$ngrok_url/wp-json/wc-wms/v1/webhook"
    docker-compose run --rm wpcli wp option update wc_wms_integration_webhook_url "$webhook_endpoint" || true
    
    success "WordPress updated with webhook URL: $webhook_endpoint"
}

# Function to start webhook testing environment
start_webhook_testing() {
    log "Starting webhook testing environment..."
    
    # Check if ngrok auth is configured
    if ! check_ngrok_auth; then
        return 1
    fi
    
    # Start the webhook testing profile
    log "Starting containers with webhook testing profile..."
    cd "$PROJECT_ROOT"
    docker-compose --profile webhook-testing up -d
    
    # Wait for ngrok to be ready
    log "Waiting for ngrok to initialize..."
    sleep 5
    
    # Get ngrok URL
    local ngrok_url
    if ngrok_url=$(get_ngrok_url); then
        success "ngrok tunnel established: $ngrok_url"
        
        # Update .env file with ngrok URL
        if grep -q "NGROK_URL=" "$PROJECT_ROOT/.env"; then
            sed -i "s|NGROK_URL=.*|NGROK_URL=$ngrok_url|" "$PROJECT_ROOT/.env"
        else
            echo "NGROK_URL=$ngrok_url" >> "$PROJECT_ROOT/.env"
        fi
        
        # Update WordPress configuration
        update_wordpress_webhook_url "$ngrok_url"
        
        # Log webhook endpoints
        echo ""
        success "🌐 Webhook Testing Environment Ready!"
        echo ""
        echo "🔗 Public URLs:"
        echo "   WordPress:        $ngrok_url"
        echo "   WordPress Admin:  $ngrok_url/wp-admin"
        echo "   WMS Integration:  $ngrok_url/wp-admin/admin.php?page=wc-wms-integration"
        echo ""
        echo "📡 Webhook Endpoints:"
        echo "   Main Webhook:     $ngrok_url/wp-json/wc-wms/v1/webhook"
        echo "   Test Endpoint:    $ngrok_url/wp-json/wc-wms/v1/webhook/test"
        echo ""
        echo "🔧 Development Tools:"
        echo "   ngrok Dashboard:  http://localhost:4040"
        echo "   Local WordPress:  http://localhost:8000"
        echo "   phpMyAdmin:       http://localhost:8080 (with --profile dev-tools)"
        echo ""
        echo "💡 Use these URLs to configure webhooks in the WMS portal"
        
        # Save webhook info to file
        cat > "$LOGS_DIR/webhook-urls.txt" << EOF
Webhook Testing Environment
Generated: $(date)

Public URLs:
- WordPress: $ngrok_url
- WordPress Admin: $ngrok_url/wp-admin
- WMS Integration: $ngrok_url/wp-admin/admin.php?page=wc-wms-integration

Webhook Endpoints:
- Main Webhook: $ngrok_url/wp-json/wc-wms/v1/webhook
- Test Endpoint: $ngrok_url/wp-json/wc-wms/v1/webhook/test

Development Tools:
- ngrok Dashboard: http://localhost:4040
- Local WordPress: http://localhost:8000
- phpMyAdmin: http://localhost:8080 (with --profile dev-tools)
EOF
        
        success "Webhook URLs saved to $LOGS_DIR/webhook-urls.txt"
        
    else
        error "Failed to establish ngrok tunnel"
        return 1
    fi
}

# Function to stop webhook testing (FIXED)
stop_webhook_testing() {
    log "Stopping webhook testing environment..."
    cd "$PROJECT_ROOT"
    
    # Reset WordPress URLs to local ONLY if they were changed
    log "Resetting WordPress URLs to local..."
    docker-compose run --rm wpcli wp option update home "http://localhost:8000" || true
    docker-compose run --rm wpcli wp option update siteurl "http://localhost:8000" || true
    
    # Stop ONLY webhook testing containers (keeps WordPress/DB running)
    log "Stopping webhook testing containers..."
    docker-compose stop ngrok ngrok-monitor 2>/dev/null || true
    docker-compose rm -f ngrok ngrok-monitor 2>/dev/null || true
    
    # Remove ngrok URL from .env
    if [ -f "$PROJECT_ROOT/.env" ]; then
        sed -i '/^NGROK_URL=/d' "$PROJECT_ROOT/.env"
    fi
    
    success "Webhook testing stopped"
    success "WordPress URLs reset to http://localhost:8000" 
    success "Main WordPress containers still running"
}

# Function to get webhook status
webhook_status() {
    log "Checking webhook testing status..."
    
    cd "$PROJECT_ROOT"
    
    # Check if ngrok container is running
    if docker-compose --profile webhook-testing ps ngrok | grep -q "Up"; then
        success "ngrok container is running"
        
        # Try to get current URL
        if ngrok_url=$(get_ngrok_url 2>/dev/null); then
            success "ngrok tunnel active: $ngrok_url"
            echo ""
            echo "📡 Current Webhook Endpoints:"
            echo "   Main Webhook:     $ngrok_url/wp-json/wc-wms/v1/webhook"
            echo "   Test Endpoint:    $ngrok_url/wp-json/wc-wms/v1/webhook/test"
            echo "   ngrok Dashboard:  http://localhost:4040"
        else
            warning "ngrok container running but tunnel not ready"
        fi
    else
        warning "ngrok container not running"
        echo "Run: make webhook-start or ./scripts/webhook/webhook-dev.sh start"
    fi
}

# Function to test webhook endpoints
test_webhooks() {
    log "Testing webhook endpoints..."
    
    # Get current ngrok URL
    local ngrok_url
    if ngrok_url=$(get_ngrok_url 2>/dev/null); then
        log "Testing with ngrok URL: $ngrok_url"
        
        # Test the test endpoint
        log "Testing webhook test endpoint..."
        local test_response=$(curl -s "$ngrok_url/wp-json/wc-wms/v1/webhook/test" || echo "FAILED")
        
        if echo "$test_response" | grep -q "success"; then
            success "Webhook test endpoint working"
        else
            error "Webhook test endpoint failed"
            echo "Response: $test_response"
        fi
        
        # Test webhook with sample data
        log "Testing main webhook endpoint with sample data..."
        local webhook_response=$(curl -s -X POST \
            -H "Content-Type: application/json" \
            -H "X-Webhook-Id: test-$(date +%s)" \
            -H "X-Webhook-Topic: test" \
            -d '{"group":"test","action":"ping","body":{"message":"test"}}' \
            "$ngrok_url/wp-json/wc-wms/v1/webhook" || echo "FAILED")
        
        if echo "$webhook_response" | grep -q "success"; then
            success "Main webhook endpoint working"
        else
            warning "Main webhook endpoint may have issues (expected for unsigned requests)"
            echo "Response: $webhook_response"
        fi
        
    else
        warning "No active ngrok tunnel found"
        echo "Start webhook testing first: make webhook-start"
    fi
}

# Function to show webhook logs
show_logs() {
    log "Showing webhook-related logs..."
    
    cd "$PROJECT_ROOT"
    
    echo ""
    echo "=== ngrok Container Logs ==="
    docker-compose logs --tail=20 ngrok 2>/dev/null || echo "ngrok container not running"
    
    echo ""
    echo "=== WordPress Debug Logs ==="
    docker-compose run --rm wpcli tail -n 20 wp-content/debug.log 2>/dev/null || echo "No debug log found"
    
    echo ""
    echo "=== Webhook Log Files ==="
    if [ -d "$LOGS_DIR" ]; then
        ls -la "$LOGS_DIR/"
        echo ""
        if [ -f "$LOGS_DIR/webhook-urls.txt" ]; then
            echo "=== Current Webhook URLs ==="
            cat "$LOGS_DIR/webhook-urls.txt"
        fi
    else
        echo "No webhook logs directory found"
    fi
}

# Function to register webhooks with WMS
register_webhooks() {
    log "Registering webhooks with WMS..."
    
    # Get current ngrok URL
    local ngrok_url
    if ngrok_url=$(get_ngrok_url 2>/dev/null); then
        log "Using webhook URL: $ngrok_url/wp-json/wc-wms/v1/webhook"
        
        # Use WP-CLI to trigger webhook registration
        cd "$PROJECT_ROOT"
        docker-compose run --rm wpcli wp eval '
            $webhook_manager = new WC_WMS_Webhook_Manager();
            $result = $webhook_manager->register_all_webhooks();
            if (is_wp_error($result)) {
                echo "Error: " . $result->get_error_message() . "\n";
            } else {
                echo "Success: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
            }
        ' 2>/dev/null || error "Failed to register webhooks - ensure WMS plugin is active"
        
    else
        error "No active ngrok tunnel found. Start webhook testing first."
        return 1
    fi
}

# Main script logic
case "${1:-help}" in
    "start")
        start_webhook_testing
        ;;
    "stop")
        stop_webhook_testing
        ;;
    "status")
        webhook_status
        ;;
    "test")
        test_webhooks
        ;;
    "logs")
        show_logs
        ;;
    "register")
        register_webhooks
        ;;
    "url")
        if ngrok_url=$(get_ngrok_url 2>/dev/null); then
            echo "$ngrok_url"
        else
            error "No active ngrok tunnel"
            exit 1
        fi
        ;;
    "help"|*)
        echo "🌐 Webhook Development Helper"
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  start     - Start ngrok webhook testing environment"
        echo "  stop      - Stop webhook testing and reset to local URLs"
        echo "  status    - Check webhook testing status"
        echo "  test      - Test webhook endpoints"
        echo "  logs      - Show webhook-related logs"
        echo "  register  - Register webhooks with WMS using current ngrok URL"
        echo "  url       - Get current ngrok public URL"
        echo "  help      - Show this help"
        echo ""
        echo "Examples:"
        echo "  $0 start          # Start webhook testing"
        echo "  $0 status         # Check if running"
        echo "  $0 test           # Test webhook endpoints"
        echo "  $0 register       # Register with WMS"
        echo "  $0 stop           # Stop testing"
        echo ""
        echo "Prerequisites:"
        echo "  1. Sign up at https://ngrok.com/"
        echo "  2. Add NGROK_AUTHTOKEN to your .env file"
        echo "  3. Run 'make setup' to ensure WordPress is ready"
        ;;
esac
