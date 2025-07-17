<?php
/**
 * Admin page entry point
 * This file has been refactored for better maintainability
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the main admin page template
require_once plugin_dir_path(__FILE__) . 'admin-page-template.php';
