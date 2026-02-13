<?php
if (!defined('ABSPATH')) exit;

// Handle import
if (isset($_POST['aab_import_submit']) && check_admin_referer('aab_import_campaigns')) {
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($file_content, true);
        
        if ($import_data && is_array($import_data)) {
            $imported = 0;
            
            // Handle both single campaign and bulk export formats
            $campaigns_to_import = isset($import_data['title']) ? [$import_data] : $import_data;
            
            foreach ($campaigns_to_import as $campaign_data) {
                if (isset($campaign_data['title'], $campaign_data['meta'])) {
                    $new_id = wp_insert_post([
                        'post_title' => $campaign_data['title'] . ' (Imported)',
                        'post_type' => 'aab_campaign',
                        'post_status' => 'publish',
                    ]);
                    
                    if ($new_id && !is_wp_error($new_id)) {
                        foreach ($campaign_data['meta'] as $key => $value) {
                            update_post_meta($new_id, $key, $value);
                        }
                        update_post_meta($new_id, 'aab_enabled', 0); // Disable imported campaigns by default
                        $imported++;
                    }
                }
            }
            
            echo '<div class="notice notice-success"><p>' . sprintf(__('Successfully imported %d campaign(s)!', 'ai-auto-blog'), $imported) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('Invalid import file format.', 'ai-auto-blog') . '</p></div>';
        }
    }
}

// Get all campaigns for export
$all_campaigns = get_posts([
    'post_type' => 'aab_campaign',
    'post_status' => 'publish',
    'numberposts' => -1
]);
?>

<div class="wrap aab-import-export-wrap">
    
    <div class="aab-dashboard-header">
        <h1 class="aab-dashboard-title">
            <span class="dashicons dashicons-database-import"></span>
            Import & Export
        </h1>
        <p class="aab-dashboard-subtitle">Backup and restore your campaigns</p>
    </div>

    <div class="aab-import-export-grid">
        
        <!-- Export Section -->
        <div class="aab-dashboard-card">
            <div class="aab-card-header">
                <h2><span class="dashicons dashicons-database-export"></span> Export Campaigns</h2>
            </div>
            
            <div class="aab-card-content">
                <p class="description">Download your campaigns as a JSON file for backup or migration purposes.</p>
                
                <?php if (empty($all_campaigns)): ?>
                    <div class="aab-empty-state">
                        <span class="dashicons dashicons-portfolio"></span>
                        <p>No campaigns to export</p>
                        <p class="description">Create a campaign first to use the export feature</p>
                    </div>
                <?php else: ?>
                    <div class="aab-export-options">
                        <h3>Export All Campaigns</h3>
                        <p>Export all <?php echo count($all_campaigns); ?> campaign(s) at once.</p>
                        <form method="post" action="<?php echo admin_url('admin.php?page=aab-campaigns'); ?>">
                            <?php wp_nonce_field('aab_bulk_actions'); ?>
                            <input type="hidden" name="bulk_action" value="export">
                            <?php foreach ($all_campaigns as $campaign): ?>
                                <input type="hidden" name="campaign_ids[]" value="<?php echo $campaign->ID; ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-download"></span> Export All Campaigns
                            </button>
                        </form>
                    </div>

                    <hr>

                    <div class="aab-export-individual">
                        <h3>Export Individual Campaigns</h3>
                        <div class="aab-campaigns-export-list">
                            <?php foreach ($all_campaigns as $campaign): 
                                $posts_gen = get_post_meta($campaign->ID, 'aab_posts_generated', true) ?: 0;
                                $max_posts = get_post_meta($campaign->ID, 'max_posts', true) ?: 0;
                            ?>
                                <div class="aab-export-campaign-item">
                                    <div class="aab-export-campaign-info">
                                        <strong><?php echo esc_html($campaign->post_title); ?></strong>
                                        <span class="aab-campaign-meta"><?php echo $posts_gen; ?>/<?php echo $max_posts ?: '∞'; ?> posts</span>
                                    </div>
                                    <a href="<?php echo wp_nonce_url(
                                        admin_url('admin.php?page=aab-campaigns&action=export&id=' . $campaign->ID),
                                        'aab_export_campaign_' . $campaign->ID
                                    ); ?>" class="button button-small">
                                        <span class="dashicons dashicons-download"></span> Export
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Import Section -->
        <div class="aab-dashboard-card">
            <div class="aab-card-header">
                <h2><span class="dashicons dashicons-database-import"></span> Import Campaigns</h2>
            </div>
            
            <div class="aab-card-content">
                <p class="description">Upload a previously exported campaign file to restore or migrate campaigns.</p>
                
                <div class="aab-import-form">
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('aab_import_campaigns'); ?>
                        
                        <div class="aab-form-group">
                            <label for="import_file">
                                <strong>Select JSON file to import</strong>
                            </label>
                            <input type="file" 
                                   name="import_file" 
                                   id="import_file" 
                                   accept=".json,application/json" 
                                   required
                                   class="aab-file-input">
                            <p class="description">Accepted format: .json (campaign export file)</p>
                        </div>

                        <div class="aab-import-notice">
                            <span class="dashicons dashicons-info"></span>
                            <div>
                                <strong>Import Notes:</strong>
                                <ul>
                                    <li>Imported campaigns will be disabled by default</li>
                                    <li>Campaign names will have "(Imported)" suffix</li>
                                    <li>All campaign settings and keywords will be preserved</li>
                                    <li>You can import single or bulk export files</li>
                                </ul>
                            </div>
                        </div>

                        <button type="submit" name="aab_import_submit" class="button button-primary">
                            <span class="dashicons dashicons-upload"></span> Import Campaigns
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- Tips Section -->
    <div class="aab-tips-section">
        <h2 class="aab-section-title">Import/Export Tips</h2>
        
        <div class="aab-tips-grid">
            <div class="aab-tip-card">
                <span class="dashicons dashicons-backup"></span>
                <h3>Regular Backups</h3>
                <p>Export your campaigns regularly to prevent data loss and maintain backups of your configurations.</p>
            </div>

            <div class="aab-tip-card">
                <span class="dashicons dashicons-migrate"></span>
                <h3>Site Migration</h3>
                <p>Use export/import to easily move your campaigns between different WordPress installations.</p>
            </div>

            <div class="aab-tip-card">
                <span class="dashicons dashicons-admin-generic"></span>
                <h3>Campaign Templates</h3>
                <p>Create template campaigns, export them, and import to quickly set up new similar campaigns.</p>
            </div>

            <div class="aab-tip-card">
                <span class="dashicons dashicons-share"></span>
                <h3>Share Configurations</h3>
                <p>Share your campaign configurations with team members or across multiple sites.</p>
            </div>
        </div>
    </div>

</div>
<style>

/* ================================
   AAB Fancy Buttons
================================ */

/* Primary Buttons (Export All + Import) */
.aab-import-export-wrap .button-primary {
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
    border: none;
    padding: 12px 26px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    color: #fff;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.aab-import-export-wrap .button-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.45);
    background: linear-gradient(135deg, #1d4ed8 0%, #4338ca 100%);
}

/* Small Export Button */
.aab-import-export-wrap .button-small {
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border: 1px solid #d1d5db;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 6px;
    color: #1f2937;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.aab-import-export-wrap .button-small:hover {
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
    color: #fff;
    border-color: transparent;
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(37, 99, 235, 0.35);
}

/* Upload Button */
.aab-import-export-wrap button[name="aab_import_submit"] {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    padding: 12px 26px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    color: #fff;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.aab-import-export-wrap button[name="aab_import_submit"]:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.45);
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
}

/* Dashicons inside buttons */
.aab-import-export-wrap .button .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    line-height: 1;
}

/* Smooth press effect */
.aab-import-export-wrap .button:active {
    transform: translateY(1px);
    box-shadow: none;
}

/* Spacing Improvements */
.aab-export-options button,
.aab-import-form button {
    margin-top: 12px;
}

/* Campaign export list item hover */
.aab-export-campaign-item {
    padding: 14px 16px;
    border-radius: 10px;
    transition: background 0.2s ease, transform 0.2s ease;
}

.aab-export-campaign-item:hover {
    background: #f8fafc;
    transform: translateX(4px);
}

</style>
