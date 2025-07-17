#!/bin/bash
# Quick ngrok URL getter - simple version

echo "🌐 Quick ngrok URL check"
echo "========================"

# Method 1: Direct API call
echo "Checking ngrok API..."
if curl -s http://localhost:4040/api/tunnels >/dev/null; then
    echo "✅ ngrok API accessible"
    
    # Simple extraction
    RAW_RESPONSE=$(curl -s http://localhost:4040/api/tunnels)
    echo ""
    echo "Raw response:"
    echo "$RAW_RESPONSE"
    
    echo ""
    echo "Extracted URLs:"
    echo "$RAW_RESPONSE" | grep -o '"public_url":"[^"]*' | cut -d'"' -f4
    
    echo ""
    echo "HTTPS URLs only:"
    echo "$RAW_RESPONSE" | grep -o '"public_url":"https://[^"]*' | cut -d'"' -f4
    
else
    echo "❌ ngrok API not accessible"
    echo "Is ngrok running? Check: docker-compose --profile webhook-testing ps"
fi

echo ""
echo "🔧 Manual check - visit http://localhost:4040 in browser"
echo "Copy the HTTPS URL from the dashboard and use it manually if needed"
