#!/bin/bash

echo "🔍 Checking system dependencies..."

MISSING_DEPS=0

# Check Docker
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed"
    MISSING_DEPS=1
else
    echo "✅ Docker found: $(docker --version)"
fi

# Check Docker Compose
if ! command -v docker-compose &> /dev/null; then
    if ! docker compose version &> /dev/null; then
        echo "❌ Docker Compose is not installed"
        MISSING_DEPS=1
    else
        echo "✅ Docker Compose found (plugin): $(docker compose version)"
    fi
else
    echo "✅ Docker Compose found: $(docker-compose --version)"
fi

# Check if Docker daemon is running
if ! docker info &> /dev/null; then
    echo "❌ Docker daemon is not running"
    MISSING_DEPS=1
else
    echo "✅ Docker daemon is running"
fi

# Check WSL if on Windows
if grep -qi microsoft /proc/version 2>/dev/null; then
    echo "✅ Running in WSL environment"
    
    # Check if we're in WSL2
    if [ -z "$WSL_DISTRO_NAME" ]; then
        echo "⚠️  WSL version could not be determined"
    else
        echo "✅ WSL Distribution: $WSL_DISTRO_NAME"
    fi
fi

# Check required ports
for PORT in 8000 8080; do
    if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
        echo "⚠️  Port $PORT is already in use"
    else
        echo "✅ Port $PORT is available"
    fi
done

if [ $MISSING_DEPS -eq 1 ]; then
    echo ""
    echo "❌ Missing dependencies detected. Please install required software."
    exit 1
else
    echo ""
    echo "✅ All dependencies satisfied!"
fi