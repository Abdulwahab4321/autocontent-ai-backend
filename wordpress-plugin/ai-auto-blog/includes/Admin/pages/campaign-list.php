<?php
if (!defined('ABSPATH')) exit;

// // ================================================
// // LICENSE CHECK — redirect to settings if not activated
// // ================================================
// if ( ! \AAB\Core\License::is_license_active() ) {
//     wp_redirect( admin_url( 'admin.php?page=aab-settings&license_error=not_activated' ) );
//     exit;
// }
// All actions (export, duplicate, toggle, bulk, etc.) are handled in
// Menu::handle_campaign_actions() via the admin_init hook — before any output.
// --- API key presence check ---
$provider = get_option('aab_ai_provider', 'openai');
$api_key_option = ($provider === 'openrouter') ? 'aab_openrouter_key' : 'aab_api_key';
$api_key = trim((string) get_option($api_key_option, ''));

$api_status = 'ok';
$api_message = '';

if ($api_key === '') {
    $api_status = 'missing';
    $api_message = 'No API key configured for the AI provider. Add it on the Settings page.';
}

// Get all campaigns - FIXED: Explicitly exclude trashed posts
$all_campaigns = get_posts([
    'post_type'   => 'aab_campaign',
    'post_status' => 'publish', // Only get published campaigns, exclude trash
    'numberposts' => -1,
    'orderby'     => 'title',
    'order'       => 'ASC',
]);

// Pagination settings
$per_page = 20;
$total_campaigns = count($all_campaigns);
$total_pages = max(1, ceil($total_campaigns / $per_page));
$current_page = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
$offset = ($current_page - 1) * $per_page;

// Slice campaigns for current page
$campaigns = array_slice($all_campaigns, $offset, $per_page);
?>

<div class="wrap aab-campaigns-page">

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $msg = sanitize_text_field($_GET['msg']);
                $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
                
                switch ($msg) {
                    case 'toggled': echo 'Campaign status updated.'; break;
                    case 'run_success': echo 'Campaign executed successfully!'; break;
                    case 'run_error': echo 'Error running campaign.'; break;
                    case 'trashed': echo 'Campaign moved to trash.'; break;
                    case 'duplicated': echo 'Campaign duplicated successfully!'; break;
                    case 'bulk_deleted': echo $count . ' campaign(s) moved to trash.'; break;
                    case 'bulk_enabled': echo $count . ' campaign(s) enabled.'; break;
                    case 'bulk_disabled': echo $count . ' campaign(s) disabled.'; break;
                }
                ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Top Controls Bar -->
    <div class="aab-top-controls">
        <div class="aab-type-filter">
            <select id="aab-campaign-type">
                <option value="all">All Campaigns</option>
                <option value="gpt" selected>GPT Campaigns</option>
                <option value="enabled">Enabled Only</option>
                <option value="disabled">Disabled Only</option>
                <option value="running">Running Only</option>
            </select>
        </div>

        <a href="<?php echo esc_url(admin_url('admin.php?page=aab-new-campaign')); ?>" class="aab-btn aab-btn-new">
            <span class="dashicons dashicons-plus-alt2"></span> New Campaign
        </a>

        <div class="aab-search-wrapper">
            <input type="text" id="aab-campaign-search" placeholder="Search campaigns..." class="aab-search-input">
            <button type="button" class="aab-search-btn">
                <span class="dashicons dashicons-search"></span>
            </button>
        </div>

        <form method="post" id="aab-bulk-form" style="display: flex; gap: 12px; align-items: center;">
            <?php wp_nonce_field('aab_bulk_actions'); ?>
            <div class="aab-bulk-actions">
                <select id="aab-bulk-action" name="bulk_action">
                    <option value="">Bulk Actions</option>
                    <option value="delete">Move to Trash</option>
                    <option value="enable">Enable</option>
                    <option value="disable">Disable</option>
                    <option value="export">Export Selected</option>
                </select>
                <button type="button" class="aab-btn aab-btn-apply" id="aab-apply-bulk">Apply</button>
            </div>
        </form>

        <button type="button" class="aab-btn aab-btn-export" id="aab-export-all" title="Export All Campaigns">
            <span class="dashicons dashicons-download"></span> Export All
        </button>

        <button type="button" class="aab-btn aab-btn-trash" id="aab-view-trash" title="View Trash">
            <span class="dashicons dashicons-trash"></span> Trash
        </button>
    </div>

    <!-- Table -->
    <table class="aab-campaigns-table">
        <thead>
            <tr>
                <th class="aab-col-check"><input type="checkbox" id="aab-select-all"></th>
                <th class="aab-col-title">Campaign Title</th>
                <th class="aab-col-type">Type</th>
                <th class="aab-col-toggle">Enable/Disable</th>
                <th class="aab-col-status">Status</th>
                <th class="aab-col-max">Progress</th>
                <th class="aab-col-last">Last Run</th>
                <th class="aab-col-actions">Actions</th>
            </tr>
        </thead>
        <tbody id="aab-campaign-tbody">
            <?php if (empty($campaigns)): ?>
                <tr>
                    <td colspan="8" class="aab-no-campaigns">
                        No campaigns found. <a href="<?php echo esc_url(admin_url('admin.php?page=aab-new-campaign')); ?>">Create your first campaign</a>.
                    </td>
                </tr>
            <?php else: ?>
                <?php
                $serial = $offset + 1;
                foreach ($campaigns as $campaign):
                    $id = $campaign->ID;
                    $title_lower = strtolower($campaign->post_title);

                    $enabled     = (bool) get_post_meta($id, 'aab_enabled', true);
                    $paused      = (bool) get_post_meta($id, 'aab_pause_autorun', true);
                    $last_run    = (int)  get_post_meta($id, 'aab_last_run', true);
                    $max_posts   = (int)  get_post_meta($id, 'max_posts', true);
                    $posts_run   = (int)  get_post_meta($id, 'aab_posts_run', true);

                    $is_completed = ($max_posts > 0 && $posts_run >= $max_posts);

                    $status_class = $is_completed ? 'completed' : 
                                    (!$enabled ? 'disabled' : 
                                    ($paused ? 'paused' : 'running'));

                    $status_text = $is_completed ? 'Completed' :
                                   (!$enabled ? 'Disabled' :
                                   ($paused ? 'Paused' : 'Running'));

                    $toggle_url = admin_url('admin.php?page=aab-campaigns&action=toggle_enable&id=' . $id . '&_wpnonce=' . wp_create_nonce('aab_toggle_enable_' . $id));
                    $run_url    = admin_url('admin.php?page=aab-campaigns&action=run_now&id=' . $id . '&_wpnonce=' . wp_create_nonce('aab_run_now_' . $id));
                    $edit_url   = admin_url('admin.php?page=aab-new-campaign&edit=' . $id);
                    $del_url    = wp_nonce_url(admin_url('admin.php?page=aab-campaigns&action=delete&id=' . $id), 'aab_delete_campaign_' . $id);
                    $dup_url    = admin_url('admin.php?page=aab-campaigns&action=duplicate&id=' . $id . '&_wpnonce=' . wp_create_nonce('aab_duplicate_campaign_' . $id));
                    $exp_url    = admin_url('admin.php?page=aab-campaigns&action=export&id=' . $id . '&_wpnonce=' . wp_create_nonce('aab_export_campaign_' . $id));

                    $run_disabled  = (!$enabled || $is_completed);
                ?>

                <tr class="aab-campaign-row" 
                    data-title="<?php echo esc_attr($title_lower); ?>"
                    data-status="<?php echo esc_attr($status_class); ?>"
                    data-enabled="<?php echo $enabled ? '1' : '0'; ?>">
                    <td class="aab-col-check"><input type="checkbox" class="aab-row-checkbox" value="<?php echo $id; ?>"></td>
                    <td class="aab-col-title">
                        <strong><?php echo esc_html($campaign->post_title); ?></strong>
                        <div class="aab-row-actions">
                            <a href="<?php echo esc_url($edit_url); ?>">Edit</a> |
                            <a href="<?php echo esc_url($exp_url); ?>">Export</a> |
                            <a href="<?php echo esc_url($dup_url); ?>" onclick="return confirm('Duplicate this campaign?');">Duplicate</a> |
                            <a href="<?php echo esc_url($del_url); ?>" class="aab-trash-link" onclick="return confirm('Move to trash?');">Trash</a>
                        </div>
                    </td>
                    <td class="aab-col-type">
                        <span class="aab-type-badge">GPT</span>
                    </td>
                    <td class="aab-col-toggle">
                        <a href="<?php echo esc_url($toggle_url); ?>" class="aab-toggle-wrapper" title="Click to <?php echo $enabled ? 'disable' : 'enable'; ?>">
                            <span class="aab-toggle <?php echo $enabled ? 'active' : ''; ?>"></span>
                        </a>
                    </td>
                    <td class="aab-col-status">
                        <span class="aab-status-badge aab-status-<?php echo $status_class; ?>"><?php echo esc_html($status_text); ?></span>
                    </td>
                    <td class="aab-col-max">
                        <?php echo $posts_run; ?>/<?php echo $max_posts ?: '∞'; ?>
                    </td>
                    <td class="aab-col-last">
                        <?php echo $last_run ? esc_html(date('Y-m-d H:i', $last_run)) : '-'; ?>
                    </td>
                    <td class="aab-col-actions">
                        <?php if (!$run_disabled): ?>
                            <a href="<?php echo esc_url($run_url); ?>" class="aab-btn-run" title="Run Now" onclick="return confirm('Run this campaign now?');">
                                <span class="dashicons dashicons-controls-play"></span>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="aab-pagination">
            <?php if ($current_page > 1): ?>
                <a href="?page=aab-campaigns&paged=<?php echo $current_page - 1; ?>" class="aab-page-link">« Previous</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=aab-campaigns&paged=<?php echo $i; ?>" class="aab-page-link <?php echo $i === $current_page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($current_page < $total_pages): ?>
                <a href="?page=aab-campaigns&paged=<?php echo $current_page + 1; ?>" class="aab-page-link">Next »</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<style>
/* ================================================
   Modern Campaigns List Design
   ================================================ */

:root {
    --primary: #0b12da;
    --primary-dark: #475569;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-600: #4b5563;
    --radius: 6px;
}

.aab-campaigns-page {
    max-width: 1400px;
    margin: 20px auto;
    padding: 0 20px;
}

/* Top Controls */
.aab-top-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    background: white;
    padding: 12px 16px;
    border-radius: var(--radius);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.aab-type-filter select {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: var(--radius);
    background: white;
    font-size: 14px;
    min-width: 160px;
    cursor: pointer;
}

.aab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.aab-btn-new {
    background: var(--primary);
    color: white;
}

.aab-btn-new:hover {
    background: var(--primary-dark);
    color: white;
}

.aab-search-wrapper {
    flex: 1;
    min-width: 220px;
    display: flex;
    border: 1px solid #d1d5db;
    border-radius: var(--radius);
    overflow: hidden;
}

.aab-search-input {
    flex: 1;
    padding: 8px 12px;
    border: none;
    font-size: 14px;
    outline: none;
}

.aab-search-btn {
    background: var(--primary);
    color: white;
    border: none;
    padding: 0 14px;
    cursor: pointer;
}

.aab-search-btn:hover {
    background: var(--primary-dark);
}

.aab-bulk-actions {
    display: flex;
    gap: 8px;
}

.aab-bulk-actions select,
.aab-btn-apply {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: var(--radius);
    background: white;
    font-size: 14px;
}

.aab-btn-apply:hover {
    background: #f9fafb;
}

.aab-btn-export,
.aab-btn-trash {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    background: white;
    border-radius: var(--radius);
    font-size: 14px;
}

.aab-btn-export:hover {
    background: #eff6ff;
    border-color: var(--primary);
    color: var(--primary);
}

.aab-btn-trash {
    color: var(--danger);
    border-color: #fecaca;
}

.aab-btn-trash:hover {
    background: #fee2e2;
}

/* Table */
.aab-campaigns-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 8px;
    background: transparent;
}

.aab-campaigns-table thead th {
    background: white;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.aab-campaigns-table tbody tr {
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border-radius: var(--radius);
    transition: all 0.2s;
}

.aab-campaigns-table tbody tr:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.aab-campaigns-table td {
    padding: 16px;
    vertical-align: middle;
}

.aab-col-check { width: 40px; text-align: center; }
.aab-col-title { font-weight: 500; }
.aab-col-type { width: 100px; }
.aab-col-toggle { width: 100px; }
.aab-col-status { width: 120px; }
.aab-col-max { width: 100px; text-align: center; }
.aab-col-last { width: 140px; color: #6b7280; font-size: 13px; }
.aab-col-actions { width: 60px; text-align: center; }

/* Toggle Switch */
.aab-toggle-wrapper {
    display: inline-block;
    position: relative;
    width: 50px;
    height: 26px;
}

.aab-toggle {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: #cbd5e1;
    border-radius: 26px;
    transition: .3s;
}

.aab-toggle::before {
    content: "";
    position: absolute;
    width: 22px;
    height: 22px;
    left: 2px;
    top: 2px;
    background: white;
    border-radius: 50%;
    transition: .3s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.aab-toggle.active {
    background: var(--success);
}

.aab-toggle.active::before {
    transform: translateX(24px);
}

/* Status Badges */
.aab-status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.aab-status-running {
    background: #d1fae5;
    color: #065f46;
}

.aab-status-paused {
    background: #fef3c7;
    color: #92400e;
}

.aab-status-disabled {
    background: #f3f4f6;
    color: #6b7280;
}

.aab-status-completed {
    background: #dbeafe;
    color: #1e40af;
}

/* Type Badge */
.aab-type-badge {
    background: #eff6ff;
    color: #1d4ed8;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

/* Row Actions */
.aab-row-actions {
    margin-top: 6px;
    font-size: 13px;
}

.aab-row-actions a {
    color: #3b82f6;
    margin-right: 8px;
    text-decoration: none;
}

.aab-row-actions a:hover {
    text-decoration: underline;
}

.aab-trash-link {
    color: #ef4444 !important;
}

/* Run Button */
.aab-btn-run {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: #10b981;
    color: white;
    border-radius: 50%;
    text-decoration: none;
    transition: all 0.2s;
}

.aab-btn-run:hover {
    background: #059669;
    transform: scale(1.1);
}

.aab-btn-run .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}
/* Running state */
.aab-btn-run.is-running {
    background: #9ca3af; /* gray */
    pointer-events: none;
    cursor: not-allowed;
}

/* Hide play icon when running */
.aab-btn-run.is-running .dashicons {
    display: none;
}

/* Spinner */
.aab-btn-run.is-running::after {
    content: "";
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-top-color: white;
    border-radius: 50%;
    animation: aab-spin 0.8s linear infinite;
}

/* Spin animation */
@keyframes aab-spin {
    to {
        transform: rotate(360deg);
    }
}

/* No Campaigns */
.aab-no-campaigns {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
    font-size: 16px;
}

.aab-no-campaigns a {
    color: var(--primary);
    text-decoration: none;
}

.aab-no-campaigns a:hover {
    text-decoration: underline;
}

/* Pagination */
.aab-pagination {
    margin-top: 24px;
    text-align: center;
}

.aab-page-link {
    padding: 8px 14px;
    margin: 0 4px;
    border: 1px solid #d1d5db;
    border-radius: var(--radius);
    text-decoration: none;
    color: #374151;
    transition: all 0.2s;
}

.aab-page-link:hover {
    background: #f9fafb;
}

.aab-page-link.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Success Notice */
.notice {
    margin: 20px 0;
    padding: 12px;
    border-left: 4px solid #10b981;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

/* Responsive */
@media (max-width: 960px) {
    .aab-top-controls { 
        flex-direction: column; 
        align-items: stretch; 
    }
    .aab-search-wrapper { 
        width: 100%; 
    }
    .aab-bulk-actions {
        width: 100%;
    }
    .aab-bulk-actions select {
        flex: 1;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ================================================
    // SELECT ALL CHECKBOX
    // ================================================
    const selectAll = document.getElementById('aab-select-all');
    const rowCheckboxes = document.querySelectorAll('.aab-row-checkbox');

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => {
                cb.checked = this.checked;
            });
        });
    }

    // Update select-all if individual checkboxes change
    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const allChecked = Array.from(rowCheckboxes).every(checkbox => checkbox.checked);
            const anyChecked = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
            if (selectAll) {
                selectAll.checked = allChecked;
                selectAll.indeterminate = anyChecked && !allChecked;
            }
        });
    });

    // ================================================
    // SEARCH FUNCTIONALITY
    // ================================================
    const searchInput = document.getElementById('aab-campaign-search');
    const rows = document.querySelectorAll('.aab-campaign-row');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            rows.forEach(row => {
                const title = row.dataset.title || '';
                row.style.display = title.includes(query) ? '' : 'none';
            });
        });
    }

    // ================================================
    // TYPE FILTER
    // ================================================
    const typeFilter = document.getElementById('aab-campaign-type');
    
    if (typeFilter) {
        typeFilter.addEventListener('change', function() {
            const filter = this.value;
            
            rows.forEach(row => {
                const status = row.dataset.status || '';
                const enabled = row.dataset.enabled || '';
                
                let show = false;
                
                switch(filter) {
                    case 'all':
                        show = true;
                        break;
                    case 'gpt':
                        show = true; // All are GPT for now
                        break;
                    case 'enabled':
                        show = (enabled === '1');
                        break;
                    case 'disabled':
                        show = (enabled === '0');
                        break;
                    case 'running':
                        show = (status === 'running');
                        break;
                }
                
                row.style.display = show ? '' : 'none';
            });
        });
    }

    // ================================================
    // BULK ACTIONS
    // ================================================
    const bulkApplyBtn = document.getElementById('aab-apply-bulk');
    const bulkActionSelect = document.getElementById('aab-bulk-action');
    const bulkForm = document.getElementById('aab-bulk-form');

    if (bulkApplyBtn && bulkForm) {
        bulkApplyBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const action = bulkActionSelect.value;
            if (!action) {
                alert('Please select an action');
                return;
            }

            const checked = Array.from(rowCheckboxes).filter(cb => cb.checked);
            if (checked.length === 0) {
                alert('Please select at least one campaign');
                return;
            }

            // Confirm action
            let confirmMsg = '';
            switch(action) {
                case 'delete':
                    confirmMsg = 'Move ' + checked.length + ' campaign(s) to trash?';
                    break;
                case 'enable':
                    confirmMsg = 'Enable ' + checked.length + ' campaign(s)?';
                    break;
                case 'disable':
                    confirmMsg = 'Disable ' + checked.length + ' campaign(s)?';
                    break;
                case 'export':
                    confirmMsg = 'Export ' + checked.length + ' campaign(s)?';
                    break;
            }

            if (!confirm(confirmMsg)) {
                return;
            }

            // Add hidden inputs for selected IDs
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'campaign_ids[]';
                input.value = cb.value;
                bulkForm.appendChild(input);
            });

            // Submit form
            bulkForm.submit();
        });
    }

    // ================================================
    // EXPORT ALL BUTTON
    // ================================================
    const exportAllBtn = document.getElementById('aab-export-all');
    
    if (exportAllBtn) {
        exportAllBtn.addEventListener('click', function() {
            if (!confirm('Export all campaigns?')) {
                return;
            }

            // Select all checkboxes
            rowCheckboxes.forEach(cb => cb.checked = true);
            
            // Set bulk action to export
            bulkActionSelect.value = 'export';
            
            // Trigger bulk apply
            bulkApplyBtn.click();
        });
    }

    // ================================================
    // VIEW TRASH BUTTON
    // ================================================
    // const viewTrashBtn = document.getElementById('aab-view-trash');
    
    // if (viewTrashBtn) {
    //     viewTrashBtn.addEventListener('click', function() {
    //         window.location.href = '<?php echo admin_url("edit.php?post_status=trash&post_type=aab_campaign"); ?>';
    //     });
    // }
    const viewTrashBtn = document.getElementById('aab-view-trash');

    if (viewTrashBtn && bulkApplyBtn && bulkActionSelect) {
        viewTrashBtn.addEventListener('click', function (e) {
            e.preventDefault();

            // Select ALL campaign checkboxes
            rowCheckboxes.forEach(cb => {
                cb.checked = true;
            });

            // If there are no campaigns
            if (rowCheckboxes.length === 0) {
                alert('No campaigns found');
                return;
            }

            // Set bulk action to delete (move to trash)
            bulkActionSelect.value = 'delete';

            // Trigger existing bulk logic
            bulkApplyBtn.click();
        });
    }
    // ================================================
    // ACTION RUN BUTTON LOADING STATE
    // ================================================
    document.addEventListener('click', function (e) {
        const runBtn = e.target.closest('.aab-btn-run');
        if (!runBtn) return;

        // Confirmation already handled inline
        runBtn.classList.add('is-running');
        runBtn.setAttribute('title', 'Running…');
    });

    // ================================================
    // AUTO-DISMISS NOTICES
    // ================================================
    setTimeout(function() {
        const notices = document.querySelectorAll('.notice.is-dismissible');
        notices.forEach(notice => {
            notice.style.opacity = '0';
            setTimeout(() => notice.remove(), 300);
        });
    }, 5000);
});
</script>