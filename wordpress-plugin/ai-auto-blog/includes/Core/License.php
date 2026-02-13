<?php
namespace AAB\Core;

if (!defined('ABSPATH')) {
    exit;
}

class License {
    
    private static $table_name = 'aab_licenses';

    /**
     * Initialize License System
     */
    public static function init() {
        add_action('admin_post_aab_activate_license', [self::class, 'handle_activate_license']);
        add_action('admin_post_aab_deactivate_license', [self::class, 'handle_deactivate_license']);
        
        // Create table on plugin activation
        // register_activation_hook(AAB_FILE, [self::class, 'create_table']);
    }

    /**
     * Create License Table
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key varchar(20) NOT NULL,
            purchase_date datetime DEFAULT CURRENT_TIMESTAMP,
            is_activated tinyint(1) DEFAULT 0,
            activated_domain varchar(255) DEFAULT NULL,
            activated_date datetime DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Generate Random 16-Character License Key
     * Format: XXXX-XXXX-XXXX-XXXX
     */
    public static function generate_license_key() {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $segments = [];
        
        for ($i = 0; $i < 4; $i++) {
            $segment = '';
            for ($j = 0; $j < 4; $j++) {
                $segment .= $characters[random_int(0, strlen($characters) - 1)];
            }
            $segments[] = $segment;
        }
        
        return implode('-', $segments);
    }

    /**
     * Insert New License into Database
     * (For admin use - generating new licenses)
     */
    public static function create_license($purchase_date = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        $license_key = self::generate_license_key();
        $purchase_date = $purchase_date ?? current_time('mysql');
        
        $inserted = $wpdb->insert(
            $table,
            [
                'license_key' => $license_key,
                'purchase_date' => $purchase_date,
                'is_activated' => 0,
            ],
            ['%s', '%s', '%d']
        );
        
        if ($inserted) {
            return $license_key;
        }
        
        return false;
    }

    /**
     * Verify if License Key Exists in Database
     */
    public static function verify_license($license_key) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        $license = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE license_key = %s", $license_key)
        );
        
        return $license ? $license : false;
    }

    /**
     * Activate License on Current Domain
     */
    public static function activate_license($license_key) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        $current_domain = self::get_current_domain();
        
        // Check if license exists
        $license = self::verify_license($license_key);
        
        if (!$license) {
            return [
                'success' => false,
                'message' => 'Invalid license key. Please check and try again.'
            ];
        }
        
        // Check if already activated on another domain
        if ($license->is_activated && $license->activated_domain !== $current_domain) {
            return [
                'success' => false,
                'message' => 'This license is already activated on another domain: ' . esc_html($license->activated_domain)
            ];
        }
        
        // Check if already activated on this domain
        if ($license->is_activated && $license->activated_domain === $current_domain) {
            return [
                'success' => true,
                'message' => 'License is already activated on this domain.',
                'already_active' => true
            ];
        }
        
        // Activate the license
        $updated = $wpdb->update(
            $table,
            [
                'is_activated' => 1,
                'activated_domain' => $current_domain,
                'activated_date' => current_time('mysql')
            ],
            ['license_key' => $license_key],
            ['%d', '%s', '%s'],
            ['%s']
        );
        
        if ($updated !== false) {
            // Save to WordPress options for quick access
            update_option('aab_license_key', $license_key);
            update_option('aab_license_status', 'active');
            update_option('aab_license_purchased', date('F jS, Y', strtotime($license->purchase_date)));
            update_option('aab_license_expiry', 'Never');
            update_option('aab_license_domain', $current_domain);
            
            return [
                'success' => true,
                'message' => 'License activated successfully!'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to activate license. Please try again.'
        ];
    }

    /**
     * Deactivate License
     */
    public static function deactivate_license($license_key) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        $current_domain = self::get_current_domain();
        
        // Verify license exists
        $license = self::verify_license($license_key);
        
        if (!$license) {
            return [
                'success' => false,
                'message' => 'License not found.'
            ];
        }
        
        // Check if activated on this domain
        if ($license->activated_domain !== $current_domain) {
            return [
                'success' => false,
                'message' => 'This license is not activated on this domain.'
            ];
        }
        
        // Deactivate
        $updated = $wpdb->update(
            $table,
            [
                'is_activated' => 0,
                'activated_domain' => null,
                'activated_date' => null
            ],
            ['license_key' => $license_key],
            ['%d', '%s', '%s'],
            ['%s']
        );
        
        if ($updated !== false) {
            // Remove from WordPress options
            delete_option('aab_license_key');
            delete_option('aab_license_status');
            delete_option('aab_license_purchased');
            delete_option('aab_license_expiry');
            delete_option('aab_license_domain');
            
            return [
                'success' => true,
                'message' => 'License deactivated successfully.'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to deactivate license.'
        ];
    }

    /**
     * Get License Information
     */
    public static function get_license_info($license_key) {
        $license = self::verify_license($license_key);
        
        if (!$license) {
            return false;
        }
        
        return [
            'license_key' => $license->license_key,
            'purchase_date' => $license->purchase_date,
            'is_activated' => (bool) $license->is_activated,
            'activated_domain' => $license->activated_domain,
            'activated_date' => $license->activated_date,
        ];
    }

    /**
     * Check if License is Activated on Current Domain
     */
    public static function is_license_active() {
        $saved_key = get_option('aab_license_key', '');
        
        if (empty($saved_key)) {
            return false;
        }
        
        $license = self::verify_license($saved_key);
        
        if (!$license) {
            return false;
        }
        
        $current_domain = self::get_current_domain();
        
        return ($license->is_activated && $license->activated_domain === $current_domain);
    }

    /**
     * Get Current Domain
     */
    private static function get_current_domain() {
        return parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Handle License Activation (POST Handler)
     */
    public static function handle_activate_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aab_activate_license')) {
            wp_die('Invalid nonce');
        }

        $license_key = sanitize_text_field($_POST['license_key'] ?? '');

        if (empty($license_key)) {
            wp_safe_redirect(admin_url('admin.php?page=aab-settings&license_error=empty'));
            exit;
        }

        // Activate the license
        $result = self::activate_license($license_key);

        if ($result['success']) {
            wp_safe_redirect(admin_url('admin.php?page=aab-settings&license_activated=1'));
        } else {
            $error_message = urlencode($result['message']);
            wp_safe_redirect(admin_url('admin.php?page=aab-settings&license_error=custom&error_msg=' . $error_message));
        }
        exit;
    }

    /**
     * Handle License Deactivation (POST Handler)
     */
    public static function handle_deactivate_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aab_deactivate_license')) {
            wp_die('Invalid nonce');
        }

        $license_key = get_option('aab_license_key', '');

        if (empty($license_key)) {
            wp_safe_redirect(admin_url('admin.php?page=aab-settings&license_error=not_found'));
            exit;
        }

        // Deactivate the license
        $result = self::deactivate_license($license_key);

        if ($result['success']) {
            wp_safe_redirect(admin_url('admin.php?page=aab-settings&license_deactivated=1'));
        } else {
            $error_message = urlencode($result['message']);
            wp_safe_redirect(admin_url('admin.php?page=aab-settings&license_error=custom&error_msg=' . $error_message));
        }
        exit;
    }
}
