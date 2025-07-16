#!/bin/bash
# Debug ngrok API response

echo "🔍 Debugging ngrok API"
echo "====================="

echo "1. Testing ngrok API accessibility..."
if curl -s http://localhost:4040/api/tunnels >/dev/null; then
    echo "✅ ngrok API is accessible"
else
    echo "❌ ngrok API not accessible"
    exit 1
fi

echo ""
echo "2. Raw API response:"
echo "-------------------"
curl -s http://localhost:4040/api/tunnels | jq . 2>/dev/null || curl -s http://localhost:4040/api/tunnels

echo ""
echo "3. Extracting URLs with different methods:"
echo "------------------------------------------"

# Method 1: grep for https
echo "Method 1 (grep https):"
URL1=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | grep -o '"public_url":"https://[^"]*' | head -1 | cut -d'"' -f4)
echo "Result: '$URL1'"

# Method 2: grep for any URL
echo ""
echo "Method 2 (grep any):"
URL2=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | grep -o '"public_url":"[^"]*' | head -1 | cut -d'"' -f4)
echo "Result: '$URL2'"

# Method 3: jq if available
echo ""
echo "Method 3 (jq if available):"
if command -v jq >/dev/null 2>&1; then
    URL3=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | jq -r '.tunnels[0].public_url' 2>/dev/null)
    echo "Result: '$URL3'"
else
    echo "jq not available"
fi

echo ""
echo "4. Container status:"
echo "-------------------"
docker-compose --profile webhook-testing ps ngrok

echo ""
echo "5. Recent ngrok logs:"
echo "--------------------"
docker-compose logs ngrok --tail=5
