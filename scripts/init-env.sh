#!/bin/bash

# Environment Setup Script
# Configures Docker user IDs to prevent permission issues

echo "🔧 Setting up development environment for user: $(whoami)"

# Get current user and group IDs
USER_ID=$(id -u)
GROUP_ID=$(id -g)

echo "👤 User ID: $USER_ID"
echo "👥 Group ID: $GROUP_ID"

# Create/update .env file with current user IDs
cat > .env << EOF
# Docker User Configuration - Auto-generated
# This ensures containers run with your user ID to prevent permission issues

# Your user and group IDs (auto-detected)
DOCKER_USER_ID=$USER_ID
DOCKER_GROUP_ID=$GROUP_ID

# ngrok token (optional - for webhook testing)
# NGROK_AUTHTOKEN=your_token_here
EOF

echo "✅ Created .env file with your user IDs"

# Create necessary directories with correct permissions
echo "📁 Creating directories..."
mkdir -p wms uploads

# Set proper ownership and permissions
echo "🔧 Setting file permissions..."
sudo chown -R $USER_ID:$GROUP_ID wms/ uploads/ 2>/dev/null || chown -R $USER_ID:$GROUP_ID wms/ uploads/ 2>/dev/null || true
chmod -R 755 wms/ uploads/ 2>/dev/null || true

echo "✅ Environment setup complete!"
echo ""
echo "📋 Configuration:"
echo "   • User ID: $USER_ID"
echo "   • Group ID: $GROUP_ID" 
echo "   • Docker containers will run with your user ID"
echo "   • Files will be editable by your user"
echo ""
echo "🚀 Next steps:"
echo "   1. Run: ./setup.sh"
echo "   2. Or run: docker-compose up -d"
