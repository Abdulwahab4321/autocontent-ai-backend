<?php
namespace AAB\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * License Generator Page
 * Use this to generate new license keys for customers
 */
class LicenseGenerator {

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu'], 100);
        add_action('admin_post_aab_generate_license', [self::class, 'handle_generate_license']);
        add_action('admin_post_aab_delete_license', [self::class, 'handle_delete_license']);
    }

    public static function add_menu() {
        add_submenu_page(
            'aab-dashboard',
            'License Generator',
            'License Generator',
            'manage_options',
            'aab-license-generator',
            [self::class, 'page']
        );
    }

    public static function handle_generate_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aab_generate_license')) {
            wp_die('Invalid nonce');
        }

        $count = intval($_POST['license_count'] ?? 1);
        $count = min(50, max(1, $count)); // Max 50 at once

        $generated = [];
        for ($i = 0; $i < $count; $i++) {
            $key = \AAB\Core\License::create_license();
            if ($key) {
                $generated[] = $key;
            }
        }

        $redirect = admin_url('admin.php?page=aab-license-generator');
        if (!empty($generated)) {
            $redirect = add_query_arg('generated', count($generated), $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_delete_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aab_delete_license')) {
            wp_die('Invalid nonce');
        }

        $license_id = intval($_POST['license_id'] ?? 0);

        global $wpdb;
        $table = $wpdb->prefix . 'aab_licenses';

        $deleted = $wpdb->delete($table, ['id' => $license_id], ['%d']);

        $redirect = admin_url('admin.php?page=aab-license-generator');
        if ($deleted) {
            $redirect = add_query_arg('deleted', '1', $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Get all licenses from database
        global $wpdb;
        $table = $wpdb->prefix . 'aab_licenses';
        $licenses = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

        // Show notices
        if (!empty($_GET['generated'])) {
            $count = intval($_GET['generated']);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Generated ' . $count . ' new license key(s).</p></div>';
        }

        if (!empty($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>License deleted successfully.</p></div>';
        }

        ?>
        <div class="wrap aab-license-gen-wrap">
            <h1>License Generator</h1>
            <p class="subtitle">Generate and manage license keys for WPAuto Pro</p>

            <!-- Generate New Licenses -->
            <div class="aab-gen-card">
                <h2>Generate New Licenses</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aab-gen-form">
                    <?php wp_nonce_field('aab_generate_license'); ?>
                    <input type="hidden" name="action" value="aab_generate_license">

                    <div class="aab-gen-input-group">
                        <label for="license_count">Number of licenses to generate:</label>
                        <input type="number" name="license_count" id="license_count" value="1" min="1" max="50" required>
                        <button type="submit" class="button button-primary">Generate License(s)</button>
                    </div>
                </form>
            </div>

            <!-- All Licenses Table -->
            <div class="aab-licenses-table-card">
                <h2>All Licenses (<?php echo count($licenses); ?>)</h2>

                <?php if (empty($licenses)): ?>
                    <p style="padding: 20px; text-align: center; color: #666;">No licenses generated yet. Generate your first license above.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>License Key</th>
                                <th>Purchase Date</th>
                                <th>Status</th>
                                <th>Activated Domain</th>
                                <th>Activated Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($licenses as $license): ?>
                                <tr>
                                    <td><?php echo esc_html($license->id); ?></td>
                                    <td>
                                        <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-size: 13px;">
                                            <?php echo esc_html($license->license_key); ?>
                                        </code>
                                    </td>
                                    <td><?php echo esc_html(date('M j, Y', strtotime($license->purchase_date))); ?></td>
                                    <td>
                                        <?php if ($license->is_activated): ?>
                                            <span class="aab-status-badge aab-status-active">Active</span>
                                        <?php else: ?>
                                            <span class="aab-status-badge aab-status-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($license->activated_domain ?: '—'); ?></td>
                                    <td><?php echo $license->activated_date ? esc_html(date('M j, Y', strtotime($license->activated_date))) : '—'; ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                            <?php wp_nonce_field('aab_delete_license'); ?>
                                            <input type="hidden" name="action" value="aab_delete_license">
                                            <input type="hidden" name="license_id" value="<?php echo esc_attr($license->id); ?>">
                                            <button type="submit" class="button button-small" onclick="return confirm('Delete this license? This cannot be undone.');" 
                                                    <?php echo $license->is_activated ? 'disabled title="Cannot delete activated license"' : ''; ?>>
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .aab-license-gen-wrap {
            max-width: 1200px;
            margin: 20px auto;
        }

        .aab-license-gen-wrap .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .aab-gen-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .aab-gen-card h2 {
            margin-top: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }

        .aab-gen-input-group {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .aab-gen-input-group label {
            font-weight: 500;
            color: #374151;
        }

        .aab-gen-input-group input[type="number"] {
            width: 100px;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }

        .aab-licenses-table-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .aab-licenses-table-card h2 {
            margin-top: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
        }

        .aab-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .aab-status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .aab-status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        .wp-list-table code {
            font-family: 'Courier New', monospace;
        }
        </style>
        <?php
    }
}
