<?php
if (!defined('ABSPATH')) exit;

// Handle clear logs action
if (isset($_GET['action']) && $_GET['action'] === 'clear_logs' && 
    isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'aab_clear_logs')) {
    \AAB\Core\Logger::clear_logs();
    wp_redirect(admin_url('admin.php?page=aab-log&msg=logs_cleared'));
    exit;
}

// Get filter parameters
$filter_campaign = isset($_GET['filter_campaign']) ? intval($_GET['filter_campaign']) : 0;
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Get logs from database
$all_logs = \AAB\Core\Logger::get_logs();

// Apply filters
$logs = $all_logs;

// Filter by campaign
if ($filter_campaign > 0) {
    $logs = array_filter($logs, function($log) use ($filter_campaign) {
        return isset($log['campaign_id']) && $log['campaign_id'] == $filter_campaign;
    });
}

// Filter by status
if ($filter_status !== '') {
    $logs = array_filter($logs, function($log) use ($filter_status) {
        return isset($log['status']) && strtoupper($log['status']) === strtoupper($filter_status);
    });
}

// Search by post title
if ($search_query !== '') {
    $logs = array_filter($logs, function($log) use ($search_query) {
        $post_title = $log['post_title'] ?? '';
        return stripos($post_title, $search_query) !== false;
    });
}

// Re-index array after filtering
$logs = array_values($logs);

// Get server time and last cron time
$server_time = current_time('Y-m-d H:i:s');
$last_cron_time = \AAB\Core\Logger::get_last_cron_time();
$time_ago = \AAB\Core\Logger::time_ago($last_cron_time);

// Get all campaigns for filter dropdown
$campaigns = get_posts([
    'post_type' => 'aab_campaign',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
]);

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$total_logs = count($logs);
$total_pages = ceil($total_logs / $per_page);
$offset = ($current_page - 1) * $per_page;
$logs_page = array_slice($logs, $offset, $per_page);

?>

<div class="wrap aab-campaign-log-wrap">
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'logs_cleared'): ?>
        <div class="notice notice-success is-dismissible">
            <p>All logs have been cleared successfully.</p>
        </div>
    <?php endif; ?>
    
    <!-- Header Section -->
    <div class="aab-log-header">
        <h1 class="aab-log-title">Campaign Log</h1>
        <?php if (!empty($all_logs)): ?>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=aab-log&action=clear_logs'), 'aab_clear_logs'); ?>" 
               class="aab-clear-logs-btn"
               onclick="return confirm('Are you sure you want to clear all logs? This action cannot be undone.');">
                <span class="dashicons dashicons-trash"></span> Clear All Logs
            </a>
        <?php endif; ?>
    </div>

    <!-- Time Info Section -->
    <div class="aab-log-time-info">
        <div class="aab-time-item">
            <span class="dashicons dashicons-clock"></span>
            <span class="aab-time-label">Server Time:</span>
            <span class="aab-time-value"><?php echo $server_time; ?></span>
        </div>
        <div class="aab-time-item">
            <span class="dashicons dashicons-update"></span>
            <span class="aab-time-label">Last Cron Run:</span>
            <span class="aab-time-value">
                <?php 
                if ($last_cron_time === 'Never') {
                    echo 'Never';
                } else {
                    echo $last_cron_time . ' (' . $time_ago . ')';
                }
                ?>
            </span>
        </div>
        <div class="aab-time-item">
            <span class="dashicons dashicons-list-view"></span>
            <span class="aab-time-label">Total Logs:</span>
            <span class="aab-time-value"><?php echo count($all_logs); ?></span>
        </div>
    </div>

    <!-- Filters Section -->
    <?php if (!empty($all_logs)): ?>
        <div class="aab-log-filters">
            <form method="get" action="">
                <input type="hidden" name="page" value="aab-log">
                
                <div class="aab-filter-row">
                    <!-- Campaign Filter -->
                    <select name="filter_campaign" id="aab-filter-campaign">
                        <option value="0">All Campaigns</option>
                        <?php foreach ($campaigns as $campaign): ?>
                            <option value="<?php echo $campaign->ID; ?>" <?php selected($filter_campaign, $campaign->ID); ?>>
                                [#<?php echo $campaign->ID; ?>] <?php echo esc_html($campaign->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Status Filter -->
                    <select name="filter_status" id="aab-filter-status">
                        <option value="">All Statuses</option>
                        <option value="SUCCESS" <?php selected($filter_status, 'SUCCESS'); ?>>Success</option>
                        <option value="ERROR" <?php selected($filter_status, 'ERROR'); ?>>Error</option>
                        <option value="WARNING" <?php selected($filter_status, 'WARNING'); ?>>Warning</option>
                    </select>

                    <!-- Search Box -->
                    <input type="text" 
                           name="s" 
                           id="aab-search-logs" 
                           placeholder="Search post titles..." 
                           value="<?php echo esc_attr($search_query); ?>">

                    <!-- Filter Button -->
                    <button type="submit" class="aab-filter-btn">
                        <span class="dashicons dashicons-filter"></span> Filter
                    </button>

                    <!-- Reset Button -->
                    <?php if ($filter_campaign || $filter_status || $search_query): ?>
                        <a href="<?php echo admin_url('admin.php?page=aab-log'); ?>" class="aab-reset-btn">
                            <span class="dashicons dashicons-image-rotate"></span> Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Log Table or Empty State -->
    <?php if (empty($logs)): ?>
        <div class="aab-log-empty-state">
            <div class="aab-empty-icon">
                <span class="dashicons dashicons-list-view"></span>
            </div>
            <p class="aab-empty-text">
                <?php 
                if ($filter_campaign || $filter_status || $search_query) {
                    echo 'No logs found matching your filters';
                } else {
                    echo 'No campaign logs found';
                }
                ?>
            </p>
            <p class="aab-empty-description">
                <?php 
                if ($filter_campaign || $filter_status || $search_query) {
                    echo 'Try adjusting your filters or search query';
                } else {
                    echo 'Logs will appear here when campaigns start generating content';
                }
                ?>
            </p>
        </div>
    <?php else: ?>
        <!-- Log Table -->
        <div class="aab-log-table-container">
            <table class="aab-campaign-log-table">
                <thead>
                    <tr>
                        <th class="aab-col-number">Sr</th>
                        <th class="aab-col-source">Source</th>
                        <th class="aab-col-status">Status</th>
                        <th class="aab-col-summary">Post Title</th>
                        <th class="aab-col-date">Date</th>
                        <th class="aab-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $row_number = $offset + 1;
                    foreach ($logs_page as $index => $log): 
                        $log_id = $log['id'] ?? uniqid();
                        $display_number = $row_number++;
                        $campaign_id = $log['campaign_id'] ?? 0;
                        $campaign_name = $log['campaign_name'] ?? 'Unknown Campaign';
                        $status = $log['status'] ?? 'SUCCESS';
                        $post_title = $log['post_title'] ?? 'No title';
                        $post_url = $log['post_url'] ?? '#';
                        $date = $log['date'] ?? current_time('Y-m-d H:i:s');
                        $details = $log['details'] ?? [];
                        
                        // Determine status class
                        $status_class = 'success';
                        if ($status === 'ERROR') {
                            $status_class = 'error';
                        } elseif ($status === 'WARNING') {
                            $status_class = 'warning';
                        }
                        
                        // Prepare details for modal
                        $details_html = '';
                        if (!empty($details)) {
                            foreach ($details as $key => $value) {
                                $details_html .= '<div class="aab-detail-row">';
                                $details_html .= '<span class="aab-detail-label">' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</span> ';
                                $details_html .= '<span class="aab-detail-value">' . esc_html(is_array($value) ? json_encode($value) : $value) . '</span>';
                                $details_html .= '</div>';
                            }
                        }
                    ?>
                        <tr class="aab-log-row" data-log-id="<?php echo esc_attr($log_id); ?>">
                            <td class="aab-col-number"><?php echo $display_number; ?></td>
                            <td class="aab-col-source">
                                <div class="aab-source-text">
                                    [#<?php echo $campaign_id; ?>] <?php echo esc_html($campaign_name); ?>
                                </div>
                            </td>
                            <td class="aab-col-status">
                                <span class="aab-status-badge aab-status-<?php echo esc_attr(strtolower($status_class)); ?>">
                                    <?php echo esc_html($status); ?>
                                </span>
                            </td>
                            <td class="aab-col-summary">
                                <?php if ($post_url && $post_url !== '#' && $status === 'SUCCESS'): ?>
                                    <a href="<?php echo esc_url($post_url); ?>" class="aab-summary-link" target="_blank">
                                        <?php echo esc_html($post_title); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php else: ?>
                                    <?php echo esc_html($post_title); ?>
                                <?php endif; ?>
                            </td>
                            <td class="aab-col-date">
                                <div class="aab-date-display">
                                    <?php echo date('M j, Y', strtotime($date)); ?>
                                    <span class="aab-time-display"><?php echo date('g:i A', strtotime($date)); ?></span>
                                </div>
                            </td>
                            <td class="aab-col-actions">
                                <?php if (!empty($details)): ?>
                                    <button class="aab-view-details-btn" onclick="toggleDetails('<?php echo esc_js($log_id); ?>')">
                                        <span class="dashicons dashicons-visibility"></span> Details
                                    </button>
                                <?php else: ?>
                                    <span class="aab-no-details">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- Details Row (Hidden by default) -->
                        <?php if (!empty($details)): ?>
                            <tr class="aab-details-row" id="details-<?php echo esc_attr($log_id); ?>" style="display: none;">
                                <td colspan="6">
                                    <div class="aab-details-container">
                                        <div class="aab-details-header">
                                            <h4>Log Details</h4>
                                            <button class="aab-close-details" onclick="toggleDetails('<?php echo esc_js($log_id); ?>')">
                                                <span class="dashicons dashicons-no-alt"></span>
                                            </button>
                                        </div>
                                        <div class="aab-details-content">
                                            <div class="aab-details-grid">
                                                <div class="aab-detail-row">
                                                    <span class="aab-detail-label">Campaign:</span>
                                                    <span class="aab-detail-value">[#<?php echo $campaign_id; ?>] <?php echo esc_html($campaign_name); ?></span>
                                                </div>
                                                <div class="aab-detail-row">
                                                    <span class="aab-detail-label">Status:</span>
                                                    <span class="aab-detail-value"><?php echo esc_html($status); ?></span>
                                                </div>
                                                <div class="aab-detail-row">
                                                    <span class="aab-detail-label">Post:</span>
                                                    <span class="aab-detail-value"><?php echo esc_html($post_title); ?></span>
                                                </div>
                                                <div class="aab-detail-row">
                                                    <span class="aab-detail-label">Date:</span>
                                                    <span class="aab-detail-value"><?php echo $date; ?></span>
                                                </div>
                                                <?php if ($post_url && $post_url !== '#'): ?>
                                                    <div class="aab-detail-row">
                                                        <span class="aab-detail-label">URL:</span>
                                                        <span class="aab-detail-value">
                                                            <a href="<?php echo esc_url($post_url); ?>" target="_blank">
                                                                <?php echo esc_html($post_url); ?> 
                                                                <span class="dashicons dashicons-external"></span>
                                                            </a>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($details)): ?>
                                                    <div class="aab-detail-separator"></div>
                                                    <div class="aab-detail-section-title">Additional Information</div>
                                                    <?php echo $details_html; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="aab-pagination">
                <?php
                $pagination_args = [
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                    'prev_text' => '<span class="dashicons dashicons-arrow-left-alt2"></span> Previous',
                    'next_text' => 'Next <span class="dashicons dashicons-arrow-right-alt2"></span>',
                ];
                
                // Preserve filter parameters in pagination
                if ($filter_campaign) {
                    $pagination_args['add_args'] = ['filter_campaign' => $filter_campaign];
                }
                if ($filter_status) {
                    $pagination_args['add_args'] = array_merge(
                        $pagination_args['add_args'] ?? [],
                        ['filter_status' => $filter_status]
                    );
                }
                if ($search_query) {
                    $pagination_args['add_args'] = array_merge(
                        $pagination_args['add_args'] ?? [],
                        ['s' => $search_query]
                    );
                }
                
                echo paginate_links($pagination_args);
                ?>
            </div>
        <?php endif; ?>
        
        <!-- Showing X-Y of Z logs -->
        <div class="aab-log-count-info">
            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_logs); ?> of <?php echo $total_logs; ?> logs
            <?php if ($filter_campaign || $filter_status || $search_query): ?>
                (filtered from <?php echo count($all_logs); ?> total)
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
function toggleDetails(logId) {
    var detailsRow = document.getElementById('details-' + logId);
    if (detailsRow) {
        if (detailsRow.style.display === 'none') {
            detailsRow.style.display = 'table-row';
        } else {
            detailsRow.style.display = 'none';
        }
    }
}

// Auto-dismiss success notices
setTimeout(function() {
    var notices = document.querySelectorAll('.notice.is-dismissible');
    notices.forEach(function(notice) {
        notice.style.opacity = '0';
        notice.style.transition = 'opacity 0.3s';
        setTimeout(function() {
            notice.remove();
        }, 300);
    });
}, 5000);
</script>

<style>
/* Campaign Log Specific Styles */
.aab-campaign-log-wrap {
    max-width: 1400px;
    margin: 20px 20px 20px 0;
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

/* Header */
.aab-log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
}

.aab-log-title {
    font-size: 28px;
    font-weight: 600;
    color: #23282d;
    margin: 0;
}

.aab-clear-logs-btn {
    background: #dc3545;
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.aab-clear-logs-btn .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

.aab-clear-logs-btn:hover {
    background: #c82333;
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
}

/* Time Info */
.aab-log-time-info {
    display: flex;
    gap: 40px;
    margin-bottom: 25px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.aab-time-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.aab-time-item .dashicons {
    color: #2563eb;
    font-size: 20px;
    width: 20px;
    height: 20px;
}

.aab-time-label {
    color: #6c757d;
    font-weight: 500;
}

.aab-time-value {
    color: #23282d;
    font-weight: 600;
}

/* Filters Section */
.aab-log-filters {
    margin-bottom: 25px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.aab-filter-row {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.aab-filter-row select,
.aab-filter-row input[type="text"] {
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    transition: border-color 0.2s;
}

.aab-filter-row select {
    min-width: 200px;
}

.aab-filter-row input[type="text"] {
    flex: 1;
    min-width: 250px;
}

.aab-filter-row select:focus,
.aab-filter-row input[type="text"]:focus {
    border-color: #2563eb;
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.aab-filter-btn,
.aab-reset-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.aab-filter-btn {
    background: #2563eb;
    color: #fff;
}

.aab-filter-btn:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}

.aab-reset-btn {
    background: #6c757d;
    color: #fff;
}

.aab-reset-btn:hover {
    background: #5a6268;
    color: #fff;
    transform: translateY(-1px);
}

.aab-filter-btn .dashicons,
.aab-reset-btn .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

/* Table Container */
.aab-log-table-container {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 20px;
}

/* Table */
.aab-campaign-log-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
}

.aab-campaign-log-table thead {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff;
}

.aab-campaign-log-table thead th {
    padding: 16px 12px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.aab-campaign-log-table tbody tr.aab-log-row {
    border-bottom: 1px solid #e2e8f0;
    transition: background-color 0.2s;
}

.aab-campaign-log-table tbody tr.aab-log-row:hover {
    background: #f8fafc;
}

.aab-campaign-log-table tbody td {
    padding: 16px 12px;
    font-size: 14px;
    color: #23282d;
}

/* Column Widths – Improved Layout */
/* SR Column */
.aab-col-number {
    width: 90px;              /* fixed width */
    text-align: center;
    vertical-align: middle;
    padding-left: 0;
    padding-right: 0;
}

/* Header SR Alignment */
.aab-campaign-log-table thead th.aab-col-number {
    text-align: center;
    padding-left: 0;
    padding-right: 0;
}
.aab-campaign-log-table th,
.aab-campaign-log-table td {
    vertical-align: middle;
}

.aab-col-source {
    width: 240px;  /* reduced to give title space */
}

.aab-col-status {
    width: 140px;
    text-align: center;
}

.aab-col-summary {
    width: 35%;     /* allow title to expand */
    padding-right: 25px; /* breathing space */
}

.aab-col-date {
    width: 200px;
    padding-left: 35px;  /* moves date right */
}

.aab-col-actions {
    width: 150px;
    text-align: left;   /* move button left */
    padding-left: 15px;
}



/* Source Column */
.aab-source-text {
    color: #23282d;
    font-weight: 500;
    font-size: 13px;
}

/* Status Badge */
.aab-status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    
    margin-left: -30px;   /* 👈 moves badge slightly left */
}

.aab-status-success {
    background: #d1fae5;
    color: #047857;
}

.aab-status-error {
    background: #fee2e2;
    color: #dc2626;
}

.aab-status-warning {
    background: #fef3c7;
    color: #d97706;
}

/* Date Display */
.aab-date-display {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.aab-time-display {
    font-size: 12px;
    color: #6c757d;
}

/* Summary Link */
.aab-summary-link {
    color: #2563eb;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: color 0.2s;
}

.aab-summary-link:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.aab-summary-link .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* View Details Button */
.aab-view-details-btn {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.aab-view-details-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.aab-view-details-btn:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
}

.aab-no-details {
    color: #cbd5e0;
    font-size: 18px;
}

/* Details Row */
.aab-details-row {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.aab-details-row td {
    padding: 0 !important;
}

.aab-details-container {
    padding: 20px;
    border-left: 4px solid #2563eb;
}

.aab-details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}

.aab-details-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #23282d;
}

.aab-close-details {
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 4px;
    transition: color 0.2s;
}

.aab-close-details:hover {
    color: #dc2626;
}

.aab-close-details .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
}

.aab-details-grid {
    display: grid;
    gap: 12px;
}

.aab-detail-row {
    display: flex;
    gap: 8px;
    font-size: 14px;
    padding: 8px 12px;
    background: white;
    border-radius: 4px;
}

.aab-detail-label {
    font-weight: 600;
    color: #6c757d;
    min-width: 140px;
}

.aab-detail-value {
    color: #23282d;
    word-break: break-word;
}

.aab-detail-value a {
    color: #2563eb;
    text-decoration: none;
}

.aab-detail-value a:hover {
    text-decoration: underline;
}

.aab-detail-separator {
    height: 1px;
    background: #e2e8f0;
    margin: 16px 0;
}

.aab-detail-section-title {
    font-size: 14px;
    font-weight: 600;
    color: #23282d;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Empty State */
.aab-log-empty-state {
    text-align: center;
    padding: 80px 20px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-top: 20px;
}

.aab-empty-icon .dashicons {
    font-size: 80px;
    width: 80px;
    height: 80px;
    color: #cbd5e0;
    margin-bottom: 20px;
}

.aab-empty-text {
    font-size: 18px;
    font-weight: 600;
    color: #23282d;
    margin: 0 0 8px 0;
}

.aab-empty-description {
    font-size: 14px;
    color: #6c757d;
    margin: 0;
}

/* Log Count Info */
.aab-log-count-info {
    text-align: center;
    margin-top: 15px;
    color: #6c757d;
    font-size: 14px;
}

/* Pagination */
.aab-pagination {
    margin-top: 20px;
    text-align: center;
}

.aab-pagination .page-numbers {
    padding: 10px 16px;
    margin: 0 4px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    color: #23282d;
    text-decoration: none;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}

.aab-pagination .page-numbers .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.aab-pagination .page-numbers:hover {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb;
}

.aab-pagination .page-numbers.current {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb;
    font-weight: 600;
}

/* Responsive */
@media (max-width: 1200px) {
    .aab-log-table-container {
        overflow-x: scroll;
    }
    
    .aab-campaign-log-table {
        min-width: 1000px;
    }
    
    .aab-log-time-info {
        flex-direction: column;
        gap: 12px;
    }
    
    .aab-filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .aab-filter-row select,
    .aab-filter-row input[type="text"] {
        width: 100%;
        min-width: 100%;
    }
}
</style>