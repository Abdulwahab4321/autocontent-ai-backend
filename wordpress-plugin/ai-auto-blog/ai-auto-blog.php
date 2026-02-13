<?php
/**
 * Plugin Name: Ai Auto Blog
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


add_action('admin_init', function () {

    // Only guard the New/Edit Campaign page
    if (
        isset($_GET['page']) &&
        $_GET['page'] === 'aab-new-campaign' &&
        ! \AAB\Core\License::is_license_active()
    ) {
        wp_safe_redirect(
            admin_url('admin.php?page=aab-settings&license_error=not_activated')
        );
        exit;
    }

});
