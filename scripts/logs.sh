#!/bin/bash

echo "📋 WordPress Logs Management"
echo "=========================="

# Create logs directory structure
setup_logs() {
    echo "🔧 Setting up logs directory..."
    mkdir -p logs/wordpress
    chmod 755 logs
    chmod 755 logs/wordpress
    echo "✅ Logs directory created"
    
    # Create initial log files with correct permissions
    touch logs/wordpress/debug.log
    touch logs/wordpress/php_errors.log
    chmod 666 logs/wordpress/debug.log
    chmod 666 logs/wordpress/php_errors.log
    echo "✅ Log files initialized"
}

# View logs
view_logs() {
    local LOG_TYPE=${1:-all}
    
    case $LOG_TYPE in
        "debug")
            echo "📋 WordPress Debug Log:"
            echo "======================"
            if [ -f "logs/wordpress/debug.log" ]; then
                tail -f logs/wordpress/debug.log
            else
                echo "❌ Debug log not found. Run 'setup' first."
            fi
            ;;
        "php")
            echo "📋 PHP Error Log:"
            echo "================"
            if [ -f "logs/wordpress/php_errors.log" ]; then
                tail -f logs/wordpress/php_errors.log
            else
                echo "❌ PHP error log not found. Run 'setup' first."
            fi
            ;;
        "wms")
            echo "📋 WMS Integration Logs:"
            echo "======================="
            # WMS logs are typically in uploads/wc-logs/
            if [ -d "uploads/wc-logs" ]; then
                # Find latest WMS log file
                LATEST_WMS_LOG=$(ls -t uploads/wc-logs/wc-wms-integration-*.log 2>/dev/null | head -1)
                if [ ! -z "$LATEST_WMS_LOG" ]; then
                    tail -f "$LATEST_WMS_LOG"
                else
                    echo "❌ No WMS logs found yet."
                fi
            else
                echo "❌ WooCommerce logs directory not found."
            fi
            ;;
        "all")
            echo "📋 All WordPress Logs (Press Ctrl+C to exit):"
            echo "==========================================="
            # Use multitail if available, otherwise show logs sequentially
            if command -v multitail &> /dev/null; then
                multitail logs/wordpress/debug.log logs/wordpress/php_errors.log
            else
                echo "💡 Tip: Install 'multitail' for better multi-log viewing"
                echo ""
                echo "Showing debug.log (Press Ctrl+C to switch to PHP errors)..."
                tail -f logs/wordpress/debug.log || true
                echo ""
                echo "Showing php_errors.log..."
                tail -f logs/wordpress/php_errors.log
            fi
            ;;
        *)
            echo "❌ Unknown log type: $LOG_TYPE"
            echo "Available types: debug, php, wms, all"
            ;;
    esac
}

# Clear logs
clear_logs() {
    echo "🧹 Clearing log files..."
    > logs/wordpress/debug.log 2>/dev/null || true
    > logs/wordpress/php_errors.log 2>/dev/null || true
    echo "✅ Log files cleared"
}

# Get log file sizes
log_sizes() {
    echo "📊 Log File Sizes:"
    echo "=================="
    if [ -d "logs/wordpress" ]; then
        du -h logs/wordpress/* 2>/dev/null || echo "No log files found"
    fi
    if [ -d "uploads/wc-logs" ]; then
        echo ""
        echo "WooCommerce Logs:"
        du -h uploads/wc-logs/*.log 2>/dev/null | tail -5 || echo "No WooCommerce logs found"
    fi
}

# Search logs
search_logs() {
    local SEARCH_TERM="$1"
    if [ -z "$SEARCH_TERM" ]; then
        echo "❌ Please provide a search term"
        return 1
    fi
    
    echo "🔍 Searching for '$SEARCH_TERM' in logs..."
    echo "========================================"
    
    if [ -f "logs/wordpress/debug.log" ]; then
        echo "In debug.log:"
        grep -n "$SEARCH_TERM" logs/wordpress/debug.log | tail -20 || echo "No matches found"
    fi
    
    if [ -f "logs/wordpress/php_errors.log" ]; then
        echo ""
        echo "In php_errors.log:"
        grep -n "$SEARCH_TERM" logs/wordpress/php_errors.log | tail -20 || echo "No matches found"
    fi
}

# Main command handler
case "$1" in
    "setup")
        setup_logs
        ;;
    "view")
        view_logs "$2"
        ;;
    "clear")
        clear_logs
        ;;
    "sizes")
        log_sizes
        ;;
    "search")
        search_logs "$2"
        ;;
    "tail")
        # Quick access to latest entries
        echo "📋 Latest Log Entries:"
        echo "===================="
        echo "Debug Log (last 10 lines):"
        tail -10 logs/wordpress/debug.log 2>/dev/null || echo "No debug log"
        echo ""
        echo "PHP Errors (last 10 lines):"
        tail -10 logs/wordpress/php_errors.log 2>/dev/null || echo "No PHP errors"
        ;;
    *)
        echo "Usage: $0 [command] [options]"
        echo ""
        echo "Commands:"
        echo "  setup     - Create logs directory structure"
        echo "  view      - View logs in real-time"
        echo "    view debug  - View WordPress debug log"
        echo "    view php    - View PHP error log"
        echo "    view wms    - View WMS integration logs"
        echo "    view all    - View all logs (default)"
        echo "  clear     - Clear all log files"
        echo "  sizes     - Show log file sizes"
        echo "  search    - Search logs for a term"
        echo "  tail      - Show last 10 lines of each log"
        echo ""
        echo "Examples:"
        echo "  $0 setup           # Set up logging"
        echo "  $0 view           # View all logs"
        echo "  $0 view debug     # View only debug log"
        echo "  $0 search error   # Search for 'error' in logs"
        echo "  $0 tail           # Quick view of recent entries"
        ;;
esac
