<?php
/**
 * Plugin Name: AutoContentAI
 * Author: Hatim
 * Description: Generates blog's and posts using Chat-gpt and Publish them.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

define('AAB_PATH', plugin_dir_path(__FILE__));
define('AAB_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function() {
    \AAB\Core\License::create_table();
});

require_once AAB_PATH . 'includes/bootstrap.php';

/**
 * ═══════════════════════════════════════════════════════
 * 🔒 GLOBAL LICENSE CHECK - BLOCKS ALL PAGES
 * ═══════════════════════════════════════════════════════
 * 
 * Blocks ALL plugin pages except Settings until license activated
 */
add_action('admin_init', function () {
    
    // Only check our plugin pages (pages starting with 'aab-')
    if (!isset($_GET['page']) || strpos($_GET['page'], 'aab-') !== 0) {
        return; // Not our page, skip check
    }

    // ✅ ALWAYS allow Settings page (needed for license activation)
    if ($_GET['page'] === 'aab-settings') {
        return; // Allow Settings page
    }

    // Check if license is activated
    if (!\AAB\Core\License::is_license_active()) {
        // ❌ License NOT activated - BLOCK ALL PAGES
        error_log('AAB License: ❌ BLOCKING page: ' . $_GET['page'] . ' (License not activated)');
        
        wp_safe_redirect(
            admin_url('admin.php?page=aab-settings&license_required=1')
        );
        exit;
    }

    // ✅ License IS activated - allow access
    error_log('AAB License: ✅ ALLOWING page: ' . $_GET['page'] . ' (License active)');
    
}, 1); // Priority 1 = runs early, before other admin_init hooks