#!/bin/bash

# WP-Cron management script
# This script handles WordPress cron execution and monitoring

set -e

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to run WP-Cron
run_cron() {
    echo -e "${YELLOW}Running WordPress cron...${NC}"
    
    # Check if WordPress container is running
    if ! docker-compose ps wordpress | grep -q "Up"; then
        echo -e "${RED}Error: WordPress container is not running${NC}"
        exit 1
    fi
    
    # Run WP-Cron via WP-CLI with error handling
    set +e  # Temporarily disable strict error handling
    docker-compose run --rm wpcli wp cron event run --due-now
    CRON_EXIT_CODE=$?
    set -e  # Re-enable strict error handling
    
    # Check if cron executed successfully (ignore segfault during cleanup)
    if [ $CRON_EXIT_CODE -eq 0 ] || [ $CRON_EXIT_CODE -eq 139 ]; then
        echo -e "${GREEN}✅ WP-Cron executed successfully${NC}"
        exit 0
    else
        echo -e "${RED}❌ WP-Cron execution failed with exit code: $CRON_EXIT_CODE${NC}"
        exit $CRON_EXIT_CODE
    fi
}

# Function to list scheduled cron events
list_events() {
    echo -e "${YELLOW}Scheduled cron events:${NC}"
    docker-compose run --rm wpcli wp cron event list
}

# Function to test cron
test_cron() {
    echo -e "${YELLOW}Testing WP-Cron...${NC}"
    docker-compose run --rm wpcli wp cron test
}

# Function to get cron status
status() {
    echo -e "${YELLOW}WP-Cron Status:${NC}"
    
    # Check if DISABLE_WP_CRON is set
    CRON_STATUS=$(docker-compose run --rm wpcli wp config get DISABLE_WP_CRON 2>/dev/null || echo "false")
    
    if [ "$CRON_STATUS" = "true" ]; then
        echo -e "${GREEN}✅ WP-Cron is properly disabled (using external cron)${NC}"
    else
        echo -e "${YELLOW}⚠️  WP-Cron is enabled (using built-in cron on page loads)${NC}"
    fi
    
    # Check if cron service is running
    if docker-compose ps cron 2>/dev/null | grep -q "Up"; then
        echo -e "${GREEN}✅ Cron service is running${NC}"
    else
        echo -e "${RED}❌ Cron service is not running${NC}"
    fi
    
    echo ""
    echo -e "${YELLOW}Next scheduled events:${NC}"
    docker-compose run --rm wpcli wp cron event list --fields=hook,next_run_relative --format=table | head -10
}

# Main script logic
case "${1}" in
    run)
        run_cron
        ;;
    list)
        list_events
        ;;
    test)
        test_cron
        ;;
    status)
        status
        ;;
    *)
        echo "Usage: $0 {run|list|test|status}"
        echo ""
        echo "Commands:"
        echo "  run    - Execute all due cron events"
        echo "  list   - List all scheduled cron events"
        echo "  test   - Test if WP-Cron is working"
        echo "  status - Show WP-Cron configuration status"
        exit 1
        ;;
esac
