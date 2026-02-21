<?php
namespace AAB\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {

    // ═══════════════════════════════════════════════════════
    // LICENSE API CONFIGURATION
    // ═══════════════════════════════════════════════════════
    const LICENSE_API_BASE = 'https://backend.autocontentai.co/api';

    public static function init() {
        error_log('AAB License: Settings::init() called');
        
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_post_aab_clear_single_key', [self::class, 'handle_clear_single_key']);
        
        // License activation/deactivation handlers
        add_action('admin_post_aab_activate_license', [self::class, 'handle_activate_license']);
        add_action('admin_post_aab_deactivate_license', [self::class, 'handle_deactivate_license']);

        // ═══════════════════════════════════════════════════════
        // MANUAL TEST TRIGGER (for debugging)
        // ═══════════════════════════════════════════════════════
        add_action('admin_post_aab_test_license_check', [self::class, 'test_license_check']);

        // ═══════════════════════════════════════════════════════
        // Register custom cron interval FIRST (must be early)
        // ═══════════════════════════════════════════════════════
        add_filter('cron_schedules', [self::class, 'add_cron_interval']);

        // ═══════════════════════════════════════════════════════
        // AUTOMATIC LICENSE VERIFICATION (Every 5 minutes)
        // ═══════════════════════════════════════════════════════
        add_action('aab_verify_license_cron', [self::class, 'verify_license_background']);
        
        // Schedule the cron job on init
        add_action('init', [self::class, 'setup_cron_job']);
    }

    /**
     * ═══════════════════════════════════════════════════════
     * SETUP CRON JOB (Runs on every page load to ensure scheduled)
     * ═══════════════════════════════════════════════════════
     */
    public static function setup_cron_job() {
        error_log('AAB License: setup_cron_job() called');
        
        $next_run = wp_next_scheduled('aab_verify_license_cron');
        
        if (!$next_run) {
            error_log('AAB License: No cron scheduled - scheduling now');
            $scheduled = wp_schedule_event(time(), 'aab_every_5_minutes', 'aab_verify_license_cron');
            
            if ($scheduled === false) {
                error_log('AAB License: ❌ FAILED to schedule cron job!');
            } else {
                error_log('AAB License: ✅ Cron job scheduled successfully');
                $next_run = wp_next_scheduled('aab_verify_license_cron');
                error_log('AAB License: Next run: ' . date('Y-m-d H:i:s', $next_run));
            }
        } else {
            error_log('AAB License: Cron already scheduled - next run: ' . date('Y-m-d H:i:s', $next_run));
        }
        
        // Log all scheduled cron jobs for debugging
        $crons = _get_cron_array();
        $aab_crons = [];
        foreach ($crons as $timestamp => $cron) {
            if (isset($cron['aab_verify_license_cron'])) {
                $aab_crons[] = date('Y-m-d H:i:s', $timestamp);
            }
        }
        if (!empty($aab_crons)) {
            error_log('AAB License: All AAB cron schedules: ' . implode(', ', $aab_crons));
        }
    }

    /**
     * ═══════════════════════════════════════════════════════
     * ADD CUSTOM 5-MINUTE CRON INTERVAL
     * ═══════════════════════════════════════════════════════
     */
    public static function add_cron_interval($schedules) {
        error_log('AAB License: add_cron_interval() called');
        error_log('AAB License: Existing schedules: ' . implode(', ', array_keys($schedules)));
        
        $schedules['aab_every_5_minutes'] = [
            'interval' => 300, // 5 minutes in seconds
            'display'  => __('Every 5 Minutes (AAB License Check)')
        ];
        
        error_log('AAB License: ✅ Added aab_every_5_minutes schedule (300 seconds)');
        
        return $schedules;
    }

    /**
     * ═══════════════════════════════════════════════════════
     * MANUAL TEST TRIGGER (Access via: /wp-admin/admin-post.php?action=aab_test_license_check)
     * ═══════════════════════════════════════════════════════
     */
    public static function test_license_check() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB License: MANUAL TEST TRIGGERED');
        error_log('═══════════════════════════════════════════════════════');

        self::verify_license_background();

        wp_redirect(admin_url('admin.php?page=aab-settings&test_run=1'));
        exit;
    }

    /**
     * ═══════════════════════════════════════════════════════
     * BACKGROUND LICENSE VERIFICATION (Runs every 5 minutes)
     * ═══════════════════════════════════════════════════════
     */
    public static function verify_license_background() {
        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB License: verify_license_background() STARTED');
        error_log('AAB License: Timestamp: ' . date('Y-m-d H:i:s'));
        error_log('═══════════════════════════════════════════════════════');
        
        // Check if license is currently activated
        $is_activated = get_option('aab_license_activated', '0');
        error_log('AAB License: aab_license_activated option value: "' . $is_activated . '"');
        
        if ($is_activated !== '1') {
            error_log('AAB License: No active license (value is not "1") - skipping verification');
            error_log('═══════════════════════════════════════════════════════');
            return;
        }

        error_log('AAB License: ✅ Active license detected - proceeding with verification');

        $license_key = get_option('aab_license_key', '');
        error_log('AAB License: License key exists: ' . (!empty($license_key) ? 'YES' : 'NO'));
        
        if (empty($license_key)) {
            error_log('AAB License: ❌ No license key found - skipping verification');
            error_log('═══════════════════════════════════════════════════════');
            return;
        }

        $site_domain = self::get_site_domain();
        error_log('AAB License: Site domain: ' . $site_domain);
        error_log('AAB License: Key preview: ' . substr($license_key, 0, 10) . '...' . substr($license_key, -4));

        // Call the license check API
        $api_url = self::LICENSE_API_BASE . '/licenses/check';
        
        $request_body = [
            'key'    => $license_key,
            'domain' => $site_domain
        ];

        error_log('AAB License: Calling API: ' . $api_url);
        error_log('AAB License: Request payload: ' . json_encode($request_body));

        $start_time = microtime(true);

        $response = wp_remote_post($api_url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'body' => json_encode($request_body)
        ]);

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);
        error_log('AAB License: API call took ' . $duration . ' seconds');

        // Handle connection errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('AAB License: ❌ WP_Error occurred');
            error_log('AAB License: Error code: ' . $error_code);
            error_log('AAB License: Error message: ' . $error_message);
            error_log('AAB License: Keeping license active (connection failed)');
            error_log('═══════════════════════════════════════════════════════');
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('AAB License: HTTP status code: ' . $status_code);
        error_log('AAB License: Response body length: ' . strlen($body) . ' bytes');
        error_log('AAB License: Response body: ' . $body);

        // Parse response
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('AAB License: ❌ JSON decode failed: ' . json_last_error_msg());
            error_log('AAB License: Keeping license active (invalid JSON)');
            error_log('═══════════════════════════════════════════════════════');
            return;
        }

        error_log('AAB License: JSON decoded successfully');
        error_log('AAB License: Response data keys: ' . implode(', ', array_keys($data)));

        // ✅ Check if license is valid
        $valid_field = isset($data['valid']) ? $data['valid'] : null;
        error_log('AAB License: "valid" field value: ' . var_export($valid_field, true));
        error_log('AAB License: "valid" field type: ' . gettype($valid_field));

        if ($status_code === 200 && !empty($data['valid']) && $data['valid'] === true) {
            error_log('AAB License: ✅ License is VALID - keeping active');
            error_log('═══════════════════════════════════════════════════════');
            return;
        }

        // ❌ License is INVALID - Deactivate automatically
        error_log('AAB License: ❌ License is INVALID - AUTO-DEACTIVATING');
        error_log('AAB License: Status code: ' . $status_code);
        error_log('AAB License: Valid field: ' . var_export($valid_field, true));
        
        // Get reason if provided
        $reason = 'License verification failed';
        if (!empty($data['message'])) {
            $reason = $data['message'];
            error_log('AAB License: Reason from message field: ' . $reason);
        } elseif (!empty($data['error'])) {
            $reason = $data['error'];
            error_log('AAB License: Reason from error field: ' . $reason);
        } elseif (isset($data['valid']) && $data['valid'] === false) {
            $reason = 'License no longer valid';
            error_log('AAB License: Reason: valid=false');
        }

        error_log('AAB License: Final deactivation reason: ' . $reason);

        // Delete all license data
        error_log('AAB License: Deleting license options...');
        delete_option('aab_license_key');
        delete_option('aab_license_activated');
        delete_option('aab_license_domain');
        delete_option('aab_license_activated_at');
        delete_option('aab_license_purchased');
        delete_option('aab_license_email');
        delete_option('aab_license_type');
        error_log('AAB License: ✅ License options deleted');

        // Store deactivation reason for admin notice
        update_option('aab_license_auto_deactivated', current_time('mysql'));
        update_option('aab_license_deactivation_reason', $reason);
        error_log('AAB License: ✅ Deactivation timestamp and reason stored');

        error_log('AAB License: ✅ License auto-deactivated successfully');
        error_log('═══════════════════════════════════════════════════════');
    }

    public static function handle_activate_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aab_activate_license')) {
            wp_die('Invalid nonce');
        }

        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

        if (empty($license_key)) {
            wp_redirect(admin_url('admin.php?page=aab-settings&license_error=empty'));
            exit;
        }

        $site_domain = self::get_site_domain();
        
        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB License: Starting activation...');
        error_log('AAB License: Key: ' . substr($license_key, 0, 10) . '...');
        error_log('AAB License: Domain: ' . $site_domain);
        
        $api_response = self::verify_license_with_api($license_key, $site_domain);

        if ($api_response['success']) {
            error_log('AAB License: ✅ Activation SUCCESS - Saving data');
            
            update_option('aab_license_key', $license_key);
            update_option('aab_license_activated', '1');
            update_option('aab_license_domain', $site_domain);
            update_option('aab_license_activated_at', current_time('mysql'));
            
            // Clear any auto-deactivation notices
            delete_option('aab_license_auto_deactivated');
            delete_option('aab_license_deactivation_reason');
            
            if (!empty($api_response['data']['purchase_date'])) {
                update_option('aab_license_purchased', $api_response['data']['purchase_date']);
            }
            if (!empty($api_response['data']['customer_email'])) {
                update_option('aab_license_email', $api_response['data']['customer_email']);
            }
            if (!empty($api_response['data']['license_type'])) {
                update_option('aab_license_type', $api_response['data']['license_type']);
            }

            wp_redirect(admin_url('admin.php?page=aab-settings&license_activated=1'));
            exit;
        } else {
            error_log('AAB License: ❌ Activation FAILED: ' . $api_response['message']);
            
            $error_message = !empty($api_response['message']) ? $api_response['message'] : 'License verification failed';
            wp_redirect(admin_url('admin.php?page=aab-settings&license_error=custom&error_msg=' . urlencode($error_message)));
            exit;
        }
    }

    public static function handle_deactivate_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aab_deactivate_license')) {
            wp_die('Invalid nonce');
        }

        $license_key = get_option('aab_license_key', '');
        $site_domain = self::get_site_domain();

        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB License: Starting manual deactivation...');
        
        if (!empty($license_key)) {
            self::deactivate_license_with_api($license_key, $site_domain);
        }

        // Delete local license data
        delete_option('aab_license_key');
        delete_option('aab_license_activated');
        delete_option('aab_license_domain');
        delete_option('aab_license_activated_at');
        delete_option('aab_license_purchased');
        delete_option('aab_license_email');
        delete_option('aab_license_type');
        delete_option('aab_license_auto_deactivated');
        delete_option('aab_license_deactivation_reason');

        error_log('AAB License: ✅ Manual deactivation complete');
        error_log('═══════════════════════════════════════════════════════');

        wp_redirect(admin_url('admin.php?page=aab-settings&license_deactivated=1'));
        exit;
    }

    /**
     * ═══════════════════════════════════════════════════════
     * VERIFY LICENSE WITH API (Used during activation)
     * ═══════════════════════════════════════════════════════
     */
    private static function verify_license_with_api($license_key, $domain) {
        $api_url = self::LICENSE_API_BASE . '/licenses/verify';

        $request_body = [
            'key' => $license_key,
            'domain' => $domain
        ];

        error_log('AAB License API: Calling: ' . $api_url);
        error_log('AAB License API: Request: ' . json_encode($request_body));

        $response = wp_remote_post($api_url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => json_encode($request_body)
        ]);

        if (is_wp_error($response)) {
            error_log('AAB License API: ❌ Connection error: ' . $response->get_error_message());
            return [
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message()
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('AAB License API: Response code: ' . $status_code);
        error_log('AAB License API: Response body: ' . $body);

        if ($status_code === 200) {
            error_log('AAB License API: ✅ Status 200 - License VALID');
            $data = json_decode($body, true);
            return [
                'success' => true,
                'data' => $data ?: []
            ];
        }

        error_log('AAB License API: ❌ Status ' . $status_code . ' - License INVALID');
        
        $data = json_decode($body, true);
        $error_message = 'License verification failed';
        
        if (!empty($data['message'])) {
            $error_message = $data['message'];
        } elseif (!empty($data['error'])) {
            $error_message = $data['error'];
        }

        return [
            'success' => false,
            'message' => $error_message
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════
     * DEACTIVATE LICENSE WITH API
     * ═══════════════════════════════════════════════════════
     */
    private static function deactivate_license_with_api($license_key, $domain) {
        $api_url = self::LICENSE_API_BASE . '/licenses/deactivate';

        $request_body = [
            'key' => $license_key,
            'domain' => $domain
        ];

        error_log('AAB License API: Deactivating...');
        error_log('AAB License API: URL: ' . $api_url);

        $response = wp_remote_post($api_url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => json_encode($request_body)
        ]);

        if (is_wp_error($response)) {
            error_log('AAB License API: Deactivation failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        error_log('AAB License API: Deactivation response: ' . $status_code);

        return $status_code === 200;
    }

    /**
     * ═══════════════════════════════════════════════════════
     * GET SITE DOMAIN
     * ═══════════════════════════════════════════════════════
     */
    private static function get_site_domain() {
        $site_url = get_site_url();
        $parsed = parse_url($site_url);
        $domain = isset($parsed['host']) ? $parsed['host'] : '';
        $domain = preg_replace('/^www\./', '', $domain);
        error_log('AAB License: Detected domain: ' . $domain . ' (from: ' . $site_url . ')');
        return $domain;
    }

    // ═══════════════════════════════════════════════════════
    // SETTINGS REGISTRATION (unchanged)
    // ═══════════════════════════════════════════════════════

    public static function register() {
        register_setting('aab_settings', 'aab_ai_provider', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'openai',
        ]);

       register_setting('aab_settings', 'aab_openai_key', [
            'sanitize_callback' => [self::class, 'preserve_openai_key'],
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_claude_key', [
            'sanitize_callback' => [self::class, 'preserve_claude_key'],
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_gemini_key', [
            'sanitize_callback' => [self::class, 'preserve_gemini_key'],
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_openai_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4o',
        ]);

        register_setting('aab_settings', 'aab_claude_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'claude-sonnet-4-20250514',
        ]);

        register_setting('aab_settings', 'aab_gemini_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gemini-2.5-flash',
        ]);

        register_setting('aab_settings', 'aab_openai_custom_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_claude_custom_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_gemini_custom_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4o',
        ]);

        add_settings_section(
            'aab_main',
            '',
            '__return_false',
            'aab-settings'
        );

        add_settings_field(
            'aab_provider',
            'AI Provider',
            [self::class, 'provider_field'],
            'aab-settings',
            'aab_main'
        );

        add_settings_field(
            'aab_api_keys_and_model',
            'Configuration',
            [self::class, 'api_keys_and_model_field'],
            'aab-settings',
            'aab_main'
        );
    }

    public static function provider_field() {
        $current = get_option('aab_ai_provider', 'openai');
        ?>
        <select name="aab_ai_provider" id="aab_ai_provider" class="aab-modern-select" onchange="aabToggleProviderFields()">
            <option value="openai" <?php selected($current, 'openai'); ?>>OpenAI</option>
            <option value="claude" <?php selected($current, 'claude'); ?>>Claude (Anthropic)</option>
            <option value="gemini" <?php selected($current, 'gemini'); ?>>Gemini (Google)</option>
        </select>
        <p class="description">Select your preferred AI provider</p>
        <?php
    }

    public static function preserve_openai_key($value) {
        $value = trim($value ?? '');
        return $value === '' 
            ? get_option('aab_openai_key', '') 
            : sanitize_text_field($value);
    }

    public static function preserve_claude_key($value) {
        $value = trim($value ?? '');
        return $value === '' 
            ? get_option('aab_claude_key', '') 
            : sanitize_text_field($value);
    }

    public static function preserve_gemini_key($value) {
        $value = trim($value ?? '');
        return $value === '' 
            ? get_option('aab_gemini_key', '') 
            : sanitize_text_field($value);
    }

    public static function api_keys_and_model_field() {
        $openai_key = get_option('aab_openai_key', '');
        $claude_key = get_option('aab_claude_key', '');
        $gemini_key = get_option('aab_gemini_key', '');
        
        $openai_model = get_option('aab_openai_model', 'gpt-4o');
        $claude_model = get_option('aab_claude_model', 'claude-sonnet-4-20250514');
        $gemini_model = get_option('aab_gemini_model', 'gemini-2.5-flash');

        $openai_custom = get_option('aab_openai_custom_model', '');
        $claude_custom = get_option('aab_claude_custom_model', '');
        $gemini_custom = get_option('aab_gemini_custom_model', '');

       $openai_models = [
            'gpt-5.2' => 'GPT-5.2',
            'gpt-5.2-pro' => 'GPT-5.2 Pro',
            'gpt-5.2-chat-latest' => 'GPT-5.2 Chat Latest',
            'gpt-5.2-codex' => 'GPT-5.2 Codex',
            'gpt-5.1' => 'GPT-5.1',
            'gpt-5.1-chat-latest' => 'GPT-5.1 Chat Latest',
            'gpt-5.1-pro' => 'GPT-5.1 Pro',
            'gpt-5' => 'GPT-5 (Legacy)',
            'gpt-4.1' => 'GPT-4.1',
            'gpt-4.1-mini' => 'GPT-4.1 Mini',
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o Mini',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        ];

        $claude_models = [
            'claude-opus-4-20250514' => 'Claude Opus 4 (May 2025)',
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4 (May 2025)',
            'claude-opus-4-5-20251101' => 'Claude Opus 4.5 (Nov 2025)',
            'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 (Sep 2025)',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (Oct 2025)',
            'claude-3-7-sonnet-20250219' => 'Claude 3.7 Sonnet (Feb 2025)',
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Oct 2024)',
            'claude-3-5-sonnet-20240620' => 'Claude 3.5 Sonnet (Jun 2024)',
            'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (Oct 2024)',
            'claude-3-opus-20240229' => 'Claude 3 Opus (Feb 2024)',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet (Feb 2024)',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku (Mar 2024)',
            'claude-2.1' => 'Claude 2.1 (Legacy)',
            'claude-2.0' => 'Claude 2.0 (Legacy)',
            'claude-instant-1.2' => 'Claude Instant 1.2 (Legacy)',
        ];

        $gemini_models = [
            'gemini-2.5-flash' => 'Gemini 2.5 Flash',
            'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash (Experimental)',
            'gemini-2.0-flash-thinking-exp-01-21' => 'Gemini 2.0 Flash Thinking (Jan 21)',
            'gemini-2.0-flash-thinking-exp' => 'Gemini 2.0 Flash Thinking (Exp)',
            'gemini-exp-1206' => 'Gemini Experimental 1206',
            'gemini-exp-1121' => 'Gemini Experimental 1121',
            'gemini-exp-1114' => 'Gemini Experimental 1114',
            'learnlm-1.5-pro-experimental' => 'LearnLM 1.5 Pro (Experimental)',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro (Latest)',
            'gemini-1.5-pro-latest' => 'Gemini 1.5 Pro Latest',
            'gemini-1.5-pro-002' => 'Gemini 1.5 Pro 002',
            'gemini-1.5-pro-001' => 'Gemini 1.5 Pro 001',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash (Latest)',
            'gemini-1.5-flash-latest' => 'Gemini 1.5 Flash Latest',
            'gemini-1.5-flash-002' => 'Gemini 1.5 Flash 002',
            'gemini-1.5-flash-001' => 'Gemini 1.5 Flash 001',
            'gemini-1.5-flash-8b' => 'Gemini 1.5 Flash 8B',
            'gemini-1.5-flash-8b-latest' => 'Gemini 1.5 Flash 8B Latest',
            'gemini-1.5-flash-8b-001' => 'Gemini 1.5 Flash 8B 001',
            'gemini-1.0-pro' => 'Gemini 1.0 Pro (Legacy)',
            'gemini-1.0-pro-latest' => 'Gemini 1.0 Pro Latest (Legacy)',
            'gemini-1.0-pro-001' => 'Gemini 1.0 Pro 001 (Legacy)',
        ];
        ?>
        
        <div class="aab-provider-configs">
            
            <div class="aab-provider-config" data-provider="openai" style="display:none;">
                <div class="aab-config-section">
                    <label class="aab-config-label">OpenAI API Key</label>
                    <input type="password" 
                           name="aab_openai_key"
                           placeholder="Enter OpenAI API Key (sk-...)"
                           class="aab-modern-input">
                    <?php if (!empty($openai_key)): ?>
                        <p class="aab-key-info">
                            <span class="dashicons dashicons-saved" style="color: #10b981;"></span>
                            Saved key: <strong>••••••••<?php echo esc_html(substr($openai_key, -4)); ?></strong>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aab_clear_single_key&provider=openai'), 'aab_clear_openai')); ?>" 
                               class="aab-remove-key-link"
                               onclick="return confirm('Remove saved OpenAI API key?');">
                                Remove
                            </a>
                        </p>
                    <?php else: ?>
                        <p class="aab-key-info aab-no-key"><span class="dashicons dashicons-warning" style="color: #f59e0b;"></span> No key saved yet.</p>
                    <?php endif; ?>
                </div>

                <div class="aab-config-section">
                    <label class="aab-config-label">
                        OpenAI Model
                        <span class="aab-model-badge">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php echo count($openai_models); ?> models available
                        </span>
                    </label>
                    <select name="aab_openai_model" id="aab-openai-model-select" class="aab-modern-select">
                        <?php foreach ($openai_models as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($openai_model, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom" <?php selected($openai_model, 'custom'); ?>>🔧 Custom Model</option>
                    </select>
                    <p class="description">Select OpenAI model or use custom</p>
                    
                    <div id="aab-openai-custom-wrapper" style="<?php echo ($openai_model === 'custom' || !empty($openai_custom)) ? '' : 'display:none;'; ?> margin-top: 15px;">
                        <label class="aab-config-label">
                            <span class="dashicons dashicons-admin-tools" style="color: #8b5cf6;"></span>
                            Custom Model Name
                        </label>
                        <input type="text" 
                               name="aab_openai_custom_model"
                               id="aab-openai-custom-input"
                               value="<?php echo esc_attr($openai_custom); ?>"
                               placeholder="e.g., gpt-4-custom or gpt-5"
                               class="aab-modern-input aab-custom-model-input">
                        <p class="description">Enter exact model identifier from OpenAI</p>
                    </div>
                </div>
            </div>

            <div class="aab-provider-config" data-provider="claude" style="display:none;">
                <div class="aab-config-section">
                    <label class="aab-config-label">Claude API Key</label>
                    <input type="password" 
                           name="aab_claude_key"
                           placeholder="Enter Claude API Key (sk-ant-...)"
                           class="aab-modern-input">
                    <?php if (!empty($claude_key)): ?>
                        <p class="aab-key-info">
                            <span class="dashicons dashicons-saved" style="color: #10b981;"></span>
                            Saved key: <strong>••••••••<?php echo esc_html(substr($claude_key, -4)); ?></strong>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aab_clear_single_key&provider=claude'), 'aab_clear_claude')); ?>" 
                               class="aab-remove-key-link"
                               onclick="return confirm('Remove saved Claude API key?');">
                                Remove
                            </a>
                        </p>
                    <?php else: ?>
                        <p class="aab-key-info aab-no-key"><span class="dashicons dashicons-warning" style="color: #f59e0b;"></span> No key saved yet.</p>
                    <?php endif; ?>
                </div>

                <div class="aab-config-section">
                    <label class="aab-config-label">
                        Claude Model
                        <span class="aab-model-badge">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php echo count($claude_models); ?> models available
                        </span>
                    </label>
                    <select name="aab_claude_model" id="aab-claude-model-select" class="aab-modern-select">
                        <?php foreach ($claude_models as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($claude_model, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom" <?php selected($claude_model, 'custom'); ?>>🔧 Custom Model</option>
                    </select>
                    <p class="description">Select Claude model or use custom</p>
                    
                    <div id="aab-claude-custom-wrapper" style="<?php echo ($claude_model === 'custom' || !empty($claude_custom)) ? '' : 'display:none;'; ?> margin-top: 15px;">
                        <label class="aab-config-label">
                            <span class="dashicons dashicons-admin-tools" style="color: #8b5cf6;"></span>
                            Custom Model Name
                        </label>
                        <input type="text" 
                               name="aab_claude_custom_model"
                               id="aab-claude-custom-input"
                               value="<?php echo esc_attr($claude_custom); ?>"
                               placeholder="e.g., claude-4-opus-custom"
                               class="aab-modern-input aab-custom-model-input">
                        <p class="description">Enter exact model identifier from Anthropic</p>
                    </div>
                </div>
            </div>

            <div class="aab-provider-config" data-provider="gemini" style="display:none;">
                <div class="aab-config-section">
                    <label class="aab-config-label">Gemini API Key</label>
                    <input type="password" 
                           name="aab_gemini_key"
                           placeholder="Enter Gemini API Key (AIza...)"
                           class="aab-modern-input">
                    <?php if (!empty($gemini_key)): ?>
                        <p class="aab-key-info">
                            <span class="dashicons dashicons-saved" style="color: #10b981;"></span>
                            Saved key: <strong>••••••••<?php echo esc_html(substr($gemini_key, -4)); ?></strong>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aab_clear_single_key&provider=gemini'), 'aab_clear_gemini')); ?>" 
                               class="aab-remove-key-link"
                               onclick="return confirm('Remove saved Gemini API key?');">
                                Remove
                            </a>
                        </p>
                    <?php else: ?>
                        <p class="aab-key-info aab-no-key"><span class="dashicons dashicons-warning" style="color: #f59e0b;"></span> No key saved yet.</p>
                    <?php endif; ?>
                </div>

                <div class="aab-config-section">
                    <label class="aab-config-label">
                        Gemini Model
                        <span class="aab-model-badge">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php echo count($gemini_models); ?> models available
                        </span>
                    </label>
                    <select name="aab_gemini_model" id="aab-gemini-model-select" class="aab-modern-select">
                        <?php foreach ($gemini_models as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($gemini_model, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom" <?php selected($gemini_model, 'custom'); ?>>🔧 Custom Model</option>
                    </select>
                    <p class="description">Select Gemini model or use custom</p>
                    
                    <div id="aab-gemini-custom-wrapper" style="<?php echo ($gemini_model === 'custom' || !empty($gemini_custom)) ? '' : 'display:none;'; ?> margin-top: 15px;">
                        <label class="aab-config-label">
                            <span class="dashicons dashicons-admin-tools" style="color: #8b5cf6;"></span>
                            Custom Model Name
                        </label>
                        <input type="text" 
                               name="aab_gemini_custom_model"
                               id="aab-gemini-custom-input"
                               value="<?php echo esc_attr($gemini_custom); ?>"
                               placeholder="e.g., gemini-3.0-ultra or gemini-custom"
                               class="aab-modern-input aab-custom-model-input">
                        <p class="description">Enter exact model identifier from Google AI</p>
                    </div>
                </div>
            </div>

        </div>

        <script>
        function aabToggleProviderFields() {
            const provider = document.getElementById('aab_ai_provider').value;
            console.log('AAB Settings: Switching to provider:', provider);
            
            document.querySelectorAll('.aab-provider-config').forEach(config => {
                const configProvider = config.getAttribute('data-provider');
                if (configProvider === provider) {
                    config.style.display = 'block';
                    console.log('AAB Settings: Showing config for', configProvider);
                } else {
                    config.style.display = 'none';
                }
            });
        }

        function setupCustomModelToggles() {
            const openaiSelect = document.getElementById('aab-openai-model-select');
            const openaiCustom = document.getElementById('aab-openai-custom-wrapper');
            if (openaiSelect && openaiCustom) {
                openaiSelect.addEventListener('change', function() {
                    openaiCustom.style.display = this.value === 'custom' ? 'block' : 'none';
                });
            }

            const claudeSelect = document.getElementById('aab-claude-model-select');
            const claudeCustom = document.getElementById('aab-claude-custom-wrapper');
            if (claudeSelect && claudeCustom) {
                claudeSelect.addEventListener('change', function() {
                    claudeCustom.style.display = this.value === 'custom' ? 'block' : 'none';
                });
            }

            const geminiSelect = document.getElementById('aab-gemini-model-select');
            const geminiCustom = document.getElementById('aab-gemini-custom-wrapper');
            if (geminiSelect && geminiCustom) {
                geminiSelect.addEventListener('change', function() {
                    geminiCustom.style.display = this.value === 'custom' ? 'block' : 'none';
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            console.log('AAB Settings: Page loaded, initializing provider fields');
            aabToggleProviderFields();
            setupCustomModelToggles();
        });
        </script>

        <style>
        .aab-provider-configs {
            margin-top: 20px;
        }
        
        .aab-provider-config {
            padding: 20px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .aab-config-section {
            margin-bottom: 24px;
        }
        
        .aab-config-section:last-child {
            margin-bottom: 0;
        }
        
        .aab-config-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .aab-model-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #eff6ff;
            color: #1e40af;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: auto;
        }
        
        .aab-model-badge .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
        }
        
        .aab-custom-model-input {
            border: 2px dashed #a78bfa !important;
            background: #faf5ff !important;
        }
        
        .aab-custom-model-input:focus {
            border-color: #8b5cf6 !important;
            background: #ffffff !important;
        }
        
        .aab-key-info {
            margin-top: 10px;
            color: #6b7280;
            font-size: 0.94rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .aab-key-info .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        
        .aab-no-key {
            color: #d97706;
        }
        
        .aab-remove-key-link {
            color: #dc3545;
            text-decoration: none;
            margin-left: 10px;
            font-weight: 500;
        }
        
        .aab-remove-key-link:hover {
            color: #b91c1c;
            text-decoration: underline;
        }
        </style>
        <?php
    }

    public static function handle_clear_single_key() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $provider = isset($_GET['provider']) ? sanitize_text_field($_GET['provider']) : '';

        if (empty($provider) || empty($_GET['_wpnonce'])) {
            wp_die('Invalid request');
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'aab_clear_' . $provider)) {
            wp_die('Invalid nonce');
        }

        $cleared = '';

        if ($provider === 'openai') {
            delete_option('aab_openai_key');
            delete_option('aab_api_key');
            $cleared = 'OpenAI';
        } elseif ($provider === 'claude') {
            delete_option('aab_claude_key');
            $cleared = 'Claude';
        } elseif ($provider === 'gemini') {
            delete_option('aab_gemini_key');
            $cleared = 'Gemini';
        }

        $redirect = admin_url('admin.php?page=aab-settings');
        if (!empty($cleared)) {
            $redirect = add_query_arg('aab_cleared', $cleared, $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $license_key = get_option('aab_license_key', '');
        $is_activated = get_option('aab_license_activated', '0') === '1';
        $license_purchased = get_option('aab_license_purchased', '');
        $license_activated_at = get_option('aab_license_activated_at', '');

        // Check if license was auto-deactivated
        $auto_deactivated = get_option('aab_license_auto_deactivated', '');
        $deactivation_reason = get_option('aab_license_deactivation_reason', '');

        if ($is_activated && !empty($license_activated_at)) {
            $license_purchased = date('F jS, Y', strtotime($license_activated_at));
        }

        $current_provider = get_option('aab_ai_provider', 'openai');
        
        // Get next cron run time
        $next_cron = wp_next_scheduled('aab_verify_license_cron');
        $next_cron_display = $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Not scheduled';
        ?>

        <div class="wrap aab-settings-wrap">

            <?php
            // TEST RUN NOTICE
            if (!empty($_GET['test_run'])): ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong>✅ Manual license check triggered!</strong> Check your error logs for detailed results.</p>
                </div>
            <?php endif; ?>

            <?php
            // AUTO-DEACTIVATION NOTICE
            if (!empty($auto_deactivated) && !empty($deactivation_reason)): ?>
                <div class="notice notice-error is-dismissible" style="border-left: 4px solid #dc3545;">
                    <p style="font-size: 1.1rem;">
                        <strong>⚠️ License Auto-Deactivated</strong><br>
                        Your license was automatically deactivated on <?php echo esc_html(date('F jS, Y \a\t g:i A', strtotime($auto_deactivated))); ?>.
                    </p>
                    <p><strong>Reason:</strong> <?php echo esc_html($deactivation_reason); ?></p>
                    <p>Please re-activate your license below or contact support if you believe this was an error.</p>
                </div>
                <?php
                delete_option('aab_license_auto_deactivated');
                delete_option('aab_license_deactivation_reason');
            endif;
            ?>
            
            <?php
            if (!empty($_GET['license_required'])): ?>
                <div class="aab-license-required-notice" style="position: relative; z-index: 9999;">
                    <div style="background: #fff; border: 4px solid #dc3545; padding: 25px 30px; margin: 20px 0 30px 0; border-radius: 8px; box-shadow: 0 4px 12px rgba(220, 53, 69, 0.2);">
                        <h2 style="margin: 0 0 15px 0; color: #dc3545; font-size: 1.8rem; display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 2rem;">🔒</span>
                            License Activation Required
                        </h2>
                        <p style="font-size: 1.1rem; margin: 0 0 10px 0; color: #333; line-height: 1.6;">
                            <strong>You need to activate your license to access AutoContent AI features.</strong>
                        </p>
                        <p style="margin: 0; color: #666; font-size: 1rem; line-height: 1.5;">
                            Please enter your license key in the form below to unlock all features and start creating content.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['aab_cleared'])): 
                $val = sanitize_text_field($_GET['aab_cleared']);
                $which = esc_html($val);
            ?>
                <div class="notice notice-success is-dismissible"><p>Removed saved API key: <?php echo $which; ?></p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['license_activated'])): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Your license has been activated.</p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['license_deactivated'])): ?>
                <div class="notice notice-info is-dismissible"><p>Your license has been deactivated.</p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['license_error'])): 
                $error_type = sanitize_text_field($_GET['license_error']);
                if ($error_type === 'empty'): ?>
                    <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a valid license key.</p></div>
                <?php elseif ($error_type === 'custom' && !empty($_GET['error_msg'])): 
                    $error_msg = sanitize_text_field($_GET['error_msg']); ?>
                    <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> <?php echo esc_html($error_msg); ?></p></div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible"><p><strong>Error:</strong> An error occurred. Please try again.</p></div>
                <?php endif; ?>
            <?php endif; ?>

            <h1>AutoContent AI — Settings</h1>

            <div class="aab-settings-grid-single">

                <div class="aab-settings-card">
                    <div class="aab-card-header">
                        <h2>AI Provider Configuration</h2>
                        <p class="aab-card-subtitle">Configure your AI provider, API keys, and models</p>
                    </div>

                    <form method="post" action="options.php" class="aab-settings-form">
                        <?php
                        settings_fields('aab_settings');
                        do_settings_sections('aab-settings');
                        ?>

                        <div class="aab-submit-wrapper">
                            <button type="submit" name="submit" id="submit" class="aab-save-btn">
                                <span class="dashicons dashicons-saved"></span>
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>

            </div>

            <div class="aab-license-section">
                <div class="aab-settings-card aab-license-card">
                    <div class="aab-card-header">
                        <h2>
                            <span class="dashicons dashicons-admin-network"></span>
                            AutoContent AI License
                        </h2>
                        <!-- <p class="aab-card-subtitle">
                            Auto-verification every 5 minutes • Next check: <?php echo esc_html($next_cron_display); ?>
                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=aab_test_license_check')); ?>" style="margin-left: 10px; color: #2563eb;">Test Now</a>
                        </p> -->
                    </div>

                    <?php if ($is_activated): ?>
                        <div class="aab-license-content">
                            <div class="aab-license-status-badge aab-license-active">
                                <span class="dashicons dashicons-yes-alt"></span>
                                License Activated
                            </div>

                            <div class="aab-license-key-display">
                                <?php 
                                $masked_key = str_repeat('•', max(0, strlen($license_key) - 4)) . substr($license_key, -4);
                                echo esc_html($masked_key); 
                                ?>
                            </div>

                            <div class="aab-license-details">
                                <div class="aab-license-info-grid">
                                    <div class="aab-license-info-item">
                                        <span class="aab-license-icon">
                                            <span class="dashicons dashicons-calendar-alt"></span>
                                        </span>
                                        <div>
                                            <div class="aab-license-info-label">Activated On</div>
                                            <div class="aab-license-info-value"><?php echo esc_html($license_purchased); ?></div>
                                        </div>
                                    </div>
                                    <div class="aab-license-info-item">
                                        <span class="aab-license-icon">
                                            <span class="dashicons dashicons-shield-alt"></span>
                                        </span>
                                        <div>
                                            <div class="aab-license-info-label">Status</div>
                                            <div class="aab-license-info-value aab-status-active">Active</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aab-license-deactivate-form">
                                <?php wp_nonce_field('aab_deactivate_license'); ?>
                                <input type="hidden" name="action" value="aab_deactivate_license">
                                <button type="submit" class="aab-deactivate-btn" onclick="return confirm('Are you sure you want to deactivate your license?');">
                                    <span class="dashicons dashicons-dismiss"></span>
                                    Deactivate License
                                </button>
                            </form>
                        </div>

                    <?php else: ?>
                        <div class="aab-license-content">
                            <div class="aab-license-status-badge aab-license-inactive">
                                <span class="dashicons dashicons-warning"></span>
                                License Not Activated
                            </div>

                            <div class="aab-license-not-activated-msg">
                                Please activate your license to unlock all features of AutoContent AI.
                            </div>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aab-license-activate-form" id="aab-license-form">
                                <?php wp_nonce_field('aab_activate_license'); ?>
                                <input type="hidden" name="action" value="aab_activate_license">

                                <div class="aab-license-input-group">
                                    <input type="text" 
                                           name="license_key" 
                                           id="aab-license-key" 
                                           class="aab-license-input" 
                                           placeholder="Enter your license key (e.g., XXXX-XXXX-XXXX-XXXX)"
                                           required>
                                    <button type="submit" class="aab-activate-btn" id="aab-activate-btn">
                                        <span class="dashicons dashicons-unlock"></span>
                                        <span class="btn-text">Activate License</span>
                                        <span class="btn-loading" style="display:none;">
                                            <span class="dashicons dashicons-update spin"></span>
                                            Verifying...
                                        </span>
                                    </button>
                                </div>
                                
                                <div id="aab-license-hint" class="aab-license-hint" style="display:none;">
                                    <span class="dashicons dashicons-info"></span>
                                    Verifying license with server...
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <style>
        .aab-settings-wrap {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 24px;
        }

        .aab-settings-wrap h1 {
            font-size: 2.4rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 40px;
        }

        .aab-settings-grid-single {
            margin-bottom: 40px;
        }

        .aab-settings-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .aab-card-header {
            padding: 24px 32px;
            background: linear-gradient(to bottom, #f9fafb, #f1f5f9);
            border-bottom: 1px solid #e5e7eb;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .aab-card-header h2 {
            margin: 0 0 8px 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.3;
            word-wrap: break-word;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .aab-card-header h2 .dashicons {
            color: #2563eb;
        }

        .aab-card-subtitle {
            margin: 0;
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .aab-settings-form {
            padding: 32px;
        }

        .aab-modern-select,
        .aab-modern-input {
            width: 100%;
            max-width: 540px;
            padding: 13px 18px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            transition: all 0.2s ease;
        }

        .aab-modern-select:disabled {
            background: #f3f4f6;
            color: #6b7280;
            cursor: not-allowed;
        }

        .aab-modern-select:focus,
        .aab-modern-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59,130,246,0.15);
            outline: none;
        }

        .aab-key-field {
            margin-bottom: 28px;
        }

        .aab-submit-wrapper {
            margin-top: 32px;
            text-align: right;
        }

        .aab-save-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 32px;
            font-size: 1.05rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(37,99,235,0.25);
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .aab-save-btn:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }

        .aab-save-btn .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }

        .aab-license-section {
            margin-top: 40px;
        }

        .aab-license-card {
            max-width: 100%;
        }

        .aab-license-content {
            padding: 40px;
        }

        .aab-license-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 24px;
        }

        .aab-license-active {
            background: #d1fae5;
            color: #065f46;
        }

        .aab-license-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .aab-license-status-badge .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
        }

        .aab-license-key-display {
            background: #f8f9fa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px 24px;
            font-size: 1.1rem;
            font-family: monospace;
            letter-spacing: 2px;
            color: #1f2937;
            margin-bottom: 24px;
        }

        .aab-license-not-activated-msg {
            color: #374151;
            font-size: 1rem;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .aab-license-details {
            border-top: 1px solid #e5e7eb;
            padding-top: 24px;
            margin-bottom: 24px;
        }

        .aab-license-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .aab-license-info-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .aab-license-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            background: #eff6ff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .aab-license-icon .dashicons {
            color: #2563eb;
            font-size: 20px;
            width: 20px;
            height: 20px;
        }

        .aab-license-info-label {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .aab-license-info-value {
            font-size: 1rem;
            color: #1f2937;
            font-weight: 600;
        }

        .aab-status-active {
            color: #059669;
        }

        .aab-license-deactivate-form {
            margin-top: 24px;
        }

        .aab-deactivate-btn {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 32px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .aab-deactivate-btn:hover {
            background: #4b5563;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(107,114,128,0.3);
        }

        .aab-deactivate-btn .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }

        .aab-license-activate-form {
            max-width: 700px;
        }

        .aab-license-input-group {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .aab-license-input {
            flex: 1;
            padding: 14px 20px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
        }

        .aab-license-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
            outline: none;
        }

        .aab-activate-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 14px 32px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .aab-activate-btn:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }

        .aab-activate-btn .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }

        .spin {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .aab-license-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            color: #1e40af;
            font-size: 0.9rem;
            margin-top: 12px;
        }
        
        .aab-license-hint .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        
        .aab-activate-btn .btn-text,
        .aab-activate-btn .btn-loading {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .aab-card-header {
                padding: 20px 24px;
                min-height: auto;
            }
            
            .aab-card-header h2 {
                font-size: 1.3rem;
            }
            
            .aab-card-subtitle {
                font-size: 0.9rem;
            }
            
            .aab-submit-wrapper {
                text-align: center;
            }
            
            .aab-license-input-group {
                flex-direction: column;
            }
            
            .aab-license-content {
                padding: 24px;
            }

            .aab-license-info-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>

        <script>
        (function () {
            const form = document.getElementById('aab-license-form');
            const btn = document.getElementById('aab-activate-btn');
            const hint = document.getElementById('aab-license-hint');
            
            if (form && btn) {
                form.addEventListener('submit', function() {
                    const btnText = btn.querySelector('.btn-text');
                    const btnLoading = btn.querySelector('.btn-loading');
                    
                    if (btnText && btnLoading && hint) {
                        btnText.style.display = 'none';
                        btnLoading.style.display = 'flex';
                        hint.style.display = 'flex';
                        btn.disabled = true;
                    }
                });
            }
            
            setTimeout(function() {
                const notices = document.querySelectorAll('.notice.is-dismissible');
                notices.forEach(function(notice) {
                    notice.style.opacity = '0';
                    notice.style.transition = 'opacity 0.3s';
                    setTimeout(function() {
                        notice.remove();
                    }, 300);
                });
            }, 5000);
        })();
        </script>
        <?php
    }
}