#!/bin/bash
# Quick webhook setup verification and instructions

echo "🌐 Webhook Testing Setup Verification"
echo "====================================="

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Check if .env file exists
if [ -f "$PROJECT_ROOT/.env" ]; then
    echo "✅ .env file exists"
    
    # Check if NGROK_AUTHTOKEN is set
    if grep -q "NGROK_AUTHTOKEN=" "$PROJECT_ROOT/.env" && grep -v "^#" "$PROJECT_ROOT/.env" | grep "NGROK_AUTHTOKEN=" | grep -v "NGROK_AUTHTOKEN=$" >/dev/null; then
        echo "✅ NGROK_AUTHTOKEN is configured"
        NGROK_TOKEN_SET=true
    else
        echo "❌ NGROK_AUTHTOKEN not configured"
        echo "   Add your ngrok authtoken to .env file"
        NGROK_TOKEN_SET=false
    fi
    
    # Check if WMS credentials are configured
    if grep -v "^#" "$PROJECT_ROOT/.env" | grep -q "WMS_USERNAME=" && grep -v "^#" "$PROJECT_ROOT/.env" | grep -q "WMS_PASSWORD="; then
        echo "✅ WMS credentials are configured"
        WMS_CONFIGURED=true
    else
        echo "⚠️  WMS credentials not configured (optional)"
        WMS_CONFIGURED=false
    fi
else
    echo "⚠️  .env file not found"
    echo "   Copy .env.example to .env and configure"
fi

# Check if required directories exist
echo ""
echo "📁 Directory Structure:"
[ -d "$PROJECT_ROOT/config" ] && echo "✅ config/" || echo "❌ config/ (missing)"
[ -d "$PROJECT_ROOT/scripts/webhook" ] && echo "✅ scripts/webhook/" || echo "❌ scripts/webhook/ (missing)"
[ -f "$PROJECT_ROOT/config/ngrok.yml" ] && echo "✅ config/ngrok.yml" || echo "❌ config/ngrok.yml (missing)"

# Check if webhook scripts exist and are executable
echo ""
echo "🔧 Webhook Scripts:"
if [ -f "$PROJECT_ROOT/scripts/webhook/webhook-dev.sh" ]; then
    echo "✅ webhook-dev.sh exists"
    if [ -x "$PROJECT_ROOT/scripts/webhook/webhook-dev.sh" ]; then
        echo "✅ webhook-dev.sh is executable"
    else
        echo "⚠️  webhook-dev.sh not executable (will be fixed automatically)"
    fi
else
    echo "❌ webhook-dev.sh missing"
fi

if [ -f "$PROJECT_ROOT/scripts/webhook/monitor-ngrok.sh" ]; then
    echo "✅ monitor-ngrok.sh exists"
else
    echo "❌ monitor-ngrok.sh missing"
fi

# Check if jq is available (needed for ngrok API parsing)
echo ""
echo "🛠️  Dependencies:"
if command -v jq >/dev/null 2>&1; then
    echo "✅ jq is available"
elif docker run --rm alpine:latest sh -c "apk add --no-cache jq >/dev/null 2>&1 && echo 'jq works in container'" 2>/dev/null | grep -q "jq works"; then
    echo "✅ jq available in containers"
else
    echo "⚠️  jq not found - install with: sudo apt install jq"
fi

# Check Docker Compose
if command -v docker-compose >/dev/null 2>&1; then
    echo "✅ docker-compose is available"
else
    echo "❌ docker-compose not found"
fi

echo ""
echo "🚀 Quick Start:"
if [ "$NGROK_TOKEN_SET" = "true" ]; then
    echo "✅ You're ready to start webhook testing!"
    echo "1. Run: make webhook-start"
    echo "2. Visit ngrok dashboard: http://localhost:4040"
    echo "3. Use the HTTPS URL for webhook configuration in WMS portal"
    
    if [ "$WMS_CONFIGURED" = "true" ]; then
        echo "4. Register webhooks: make webhook-register"
        echo "✅ WMS credentials detected - full integration ready!"
    else
        echo "4. Configure WMS credentials in WordPress admin if needed"
    fi
else
    echo "1. Get ngrok authtoken from: https://dashboard.ngrok.com/get-started/your-authtoken"
    echo "2. Add to .env file: NGROK_AUTHTOKEN=your_token_here"
    echo "3. Run: make webhook-start"
    echo "4. Visit ngrok dashboard: http://localhost:4040"
    echo "5. Use the HTTPS URL for webhook configuration in WMS portal"
fi
echo ""
echo "📚 Available Commands:"
echo "make webhook-start    - Start webhook testing environment"
echo "make webhook-status   - Check status"
echo "make webhook-test     - Test endpoints"
echo "make webhook-register - Register with WMS"
echo "make webhook-stop     - Stop testing"
