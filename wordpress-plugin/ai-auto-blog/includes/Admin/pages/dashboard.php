<?php
if (!defined('ABSPATH')) exit;

// Count TOTAL posts generated across ALL campaigns (do this ONCE, not in loop)
$total_posts_generated = count(get_posts([
    'post_type' => 'post',
    'meta_key' => 'aab_campaign_id',
    'meta_compare' => 'EXISTS',
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids'
]));

// Get statistics
$total_campaigns = wp_count_posts('aab_campaign')->publish;
$enabled_campaigns = 0;
$running_campaigns = 0;
$completed_campaigns = 0;

$campaigns = get_posts([
    'post_type' => 'aab_campaign',
    'post_status' => 'publish',
    'numberposts' => -1
]);

foreach ($campaigns as $campaign) {
    $enabled = get_post_meta($campaign->ID, 'aab_enabled', true);
    
    // Count posts for THIS campaign
    $posts_generated = count(get_posts([
        'post_type' => 'post',
        'meta_key' => 'aab_campaign_id',
        'meta_value' => $campaign->ID,
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids'
    ]));
    
    $max_posts = get_post_meta($campaign->ID, 'max_posts', true) ?: 0;
    
    if ($enabled) {
        $enabled_campaigns++;
        
        // Running: enabled AND hasn't reached max yet (or no max set)
        if ($max_posts == 0 || $posts_generated < $max_posts) {
            $running_campaigns++;
        }
    }
    
    // Completed: has max_posts set AND has reached or exceeded it
    if ($max_posts > 0 && $posts_generated >= $max_posts) {
        $completed_campaigns++;
    }
}

// Get recent campaigns (last 5)
$recent_campaigns = get_posts([
    'post_type' => 'aab_campaign',
    'post_status' => 'publish',
    'numberposts' => 5,
    'orderby' => 'modified',
    'order' => 'DESC'
]);

// Get SEO statistics
$seo_stats = \AAB\Extensions\SEOStats::get_stats();

?>

<div class="wrap aab-dashboard-wrap">
    
    <!-- Header -->
    <div class="aab-dashboard-header">
        <h1 class="aab-dashboard-title">
            <span class="dashicons dashicons-megaphone"></span>
            AI Auto Blog Dashboard
        </h1>
        <p class="aab-dashboard-subtitle">Manage your AI-powered content campaigns</p>
    </div>

    <!-- Overview Cards -->
    <div class="aab-overview-section">
        <h2 class="aab-section-title">Campaign Overview</h2>
        
        <div class="aab-stats-grid">
            <div class="aab-stat-card aab-stat-campaigns">
                <div class="aab-stat-icon">
                    <span class="dashicons dashicons-admin-post"></span>
                </div>
                <div class="aab-stat-content">
                    <div class="aab-stat-value"><?php echo esc_html($total_campaigns); ?></div>
                    <div class="aab-stat-label">Total Campaigns</div>
                </div>
            </div>

            <div class="aab-stat-card aab-stat-enabled">
                <div class="aab-stat-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="aab-stat-content">
                    <div class="aab-stat-value"><?php echo esc_html($total_posts_generated); ?></div>
                    <div class="aab-stat-label">Total Posts</div>
                </div>
            </div>

            <div class="aab-stat-card aab-stat-running">
                <div class="aab-stat-icon">
                    <span class="dashicons dashicons-controls-play"></span>
                </div>
                <div class="aab-stat-content">
                    <div class="aab-stat-value"><?php echo esc_html($running_campaigns); ?></div>
                    <div class="aab-stat-label">Running</div>
                </div>
            </div>

            <div class="aab-stat-card aab-stat-completed">
                <div class="aab-stat-icon">
                    <span class="dashicons dashicons-saved"></span>
                </div>
                <div class="aab-stat-content">
                    <div class="aab-stat-value"><?php echo esc_html($completed_campaigns); ?></div>
                    <div class="aab-stat-label">Completed</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Posts Generated Section -->
    <!-- <div class="aab-posts-section">
        <h2 class="aab-section-title">Content Generation Stats</h2>
        
        <div class="aab-posts-grid">
            <div class="aab-posts-card">
                <div class="aab-posts-icon">
                    <span class="dashicons dashicons-edit"></span>
                </div>
                <div class="aab-posts-content">
                    <div class="aab-posts-value"><?php echo esc_html($total_posts_generated); ?></div>
                    <div class="aab-posts-label">Total Posts Generated</div>
                </div>
            </div>
        </div>
    </div> -->

    <div class="aab-dashboard-grid">
        <!-- Recent Campaigns -->
        <div class="aab-dashboard-card aab-recent-campaigns">
            <div class="aab-card-header">
                <h2>Recent Campaigns</h2>
                <a href="<?php echo admin_url('admin.php?page=aab-campaigns'); ?>" class="aab-see-all">See All</a>
            </div>
            
            <div class="aab-campaigns-list">
                <?php if (empty($recent_campaigns)): ?>
                    <div class="aab-empty-state">
                        <span class="dashicons dashicons-portfolio"></span>
                        <p>No campaigns yet</p>
                        <a href="<?php echo admin_url('admin.php?page=aab-new-campaign'); ?>" class="button button-primary">
                            Create Your First Campaign
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_campaigns as $campaign):
                        // Count posts for this campaign
                        $posts_gen = count(get_posts([
                            'post_type' => 'post',
                            'meta_key' => 'aab_campaign_id',
                            'meta_value' => $campaign->ID,
                            'post_status' => 'any',
                            'numberposts' => -1,
                            'fields' => 'ids'
                        ])); 
                        
                        $max_posts = get_post_meta($campaign->ID, 'max_posts', true) ?: 0;
                        $enabled = get_post_meta($campaign->ID, 'aab_enabled', true);
                        
                        // Determine status
                        $status_class = 'disabled';
                        $status_text = 'Disabled';
                        
                        if ($enabled) {
                            if ($max_posts > 0 && $posts_gen >= $max_posts) {
                                $status_class = 'completed';
                                $status_text = 'Completed';
                            } else {
                                $status_class = 'running';
                                $status_text = 'Running';
                            }
                        }
                    ?>
                        <div class="aab-campaign-item">
                            <div class="aab-campaign-icon">
                                <span class="dashicons dashicons-admin-post"></span>
                            </div>
                            <div class="aab-campaign-details">
                                <div class="aab-campaign-name"><?php echo esc_html($campaign->post_title); ?></div>
                                <div class="aab-campaign-meta">
                                    <span class="aab-campaign-progress"><?php echo esc_html($posts_gen); ?>/<?php echo esc_html($max_posts ?: '∞'); ?></span>
                                    <span class="aab-campaign-status aab-status-<?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="aab-campaign-actions">
                                <a href="<?php echo admin_url('admin.php?page=aab-new-campaign&edit=' . $campaign->ID); ?>" 
                                   class="button button-small">
                                    <span class="dashicons dashicons-edit"></span> Edit
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- SEO Overview (Dynamic) -->
        <div class="aab-dashboard-card aab-seo-overview">
            <div class="aab-card-header">
                <h2>SEO Overview</h2>
            </div>
            
            <?php if ($seo_stats['total_posts'] === 0): ?>
                <!-- No posts yet -->
                <div class="aab-seo-placeholder">
                    <span class="dashicons dashicons-chart-line"></span>
                    <p>No AI-generated posts yet. Create a campaign to get started!</p>
                </div>
            <?php else: ?>
                
                <?php
                // Get last 7 days of posts with SEO
                $last_7_days = [];
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $day_label = date('D', strtotime("-$i days")); // Mon, Tue, etc.
                    
                    // Get posts created on this day with SEO enabled
                    $day_posts = get_posts([
                        'post_type' => 'post',
                        'meta_key' => 'aab_campaign_id',
                        'meta_compare' => 'EXISTS',
                        'post_status' => 'publish',
                        'date_query' => [
                            [
                                'year' => date('Y', strtotime($date)),
                                'month' => date('m', strtotime($date)),
                                'day' => date('d', strtotime($date)),
                            ]
                        ],
                        'numberposts' => -1,
                        'fields' => 'ids'
                    ]);
                    
                    // Count how many have SEO
                    $posts_with_seo = 0;
                    foreach ($day_posts as $post_id) {
                        $campaign_id = get_post_meta($post_id, 'aab_campaign_id', true);
                        if ($campaign_id && get_post_meta($campaign_id, 'aab_seo_enabled', true)) {
                            $posts_with_seo++;
                        }
                    }
                    
                    $last_7_days[] = [
                        'date' => $date,
                        'day' => $day_label,
                        'total' => count($day_posts),
                        'with_seo' => $posts_with_seo
                    ];
                }
                
                // Find max value for scaling
                $max_posts = max(array_column($last_7_days, 'total'));
                if ($max_posts === 0) $max_posts = 1; // Prevent division by zero
                ?>
                
                <!-- 7-Day SEO Trend Graph -->
                <div class="aab-seo-graph-container">
                    <div class="aab-graph-header">
                        <h3>Posts Generated (Last 7 Days)</h3>
                        <div class="aab-graph-legend-inline">
                            <span class="aab-legend-item-inline">
                                <span class="aab-legend-bar" style="background: #667eea;"></span>
                                With SEO
                            </span>
                            <span class="aab-legend-item-inline">
                                <span class="aab-legend-bar" style="background: #e2e8f0;"></span>
                                Without SEO
                            </span>
                        </div>
                    </div>
                    
                    <div class="aab-bar-chart">
                        <?php foreach ($last_7_days as $day_data): ?>
                            <?php
                            $total_height = $max_posts > 0 ? ($day_data['total'] / $max_posts) * 100 : 0;
                            $seo_height = $day_data['total'] > 0 ? ($day_data['with_seo'] / $day_data['total']) * $total_height : 0;
                            $no_seo_height = $total_height - $seo_height;
                            ?>
                            <div class="aab-bar-column">
                                <div class="aab-bar-wrapper">
                                    <div class="aab-bar-stack" style="height: <?php echo max($total_height, 5); ?>%;">
                                        <?php if ($day_data['with_seo'] > 0): ?>
                                            <div class="aab-bar-segment aab-bar-seo" 
                                                 style="height: <?php echo ($day_data['with_seo'] / $day_data['total']) * 100; ?>%;"
                                                 title="<?php echo $day_data['with_seo']; ?> posts with SEO">
                                                <span class="aab-bar-value"><?php echo $day_data['with_seo']; ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($day_data['total'] - $day_data['with_seo'] > 0): ?>
                                            <div class="aab-bar-segment aab-bar-no-seo" 
                                                 style="height: <?php echo (($day_data['total'] - $day_data['with_seo']) / $day_data['total']) * 100; ?>%;"
                                                 title="<?php echo ($day_data['total'] - $day_data['with_seo']); ?> posts without SEO">
                                                <span class="aab-bar-value"><?php echo ($day_data['total'] - $day_data['with_seo']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="aab-bar-label">
                                    <span class="aab-day-label"><?php echo $day_data['day']; ?></span>
                                    <span class="aab-total-label"><?php echo $day_data['total']; ?> total</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="aab-graph-summary">
                        <?php
                        $total_week = array_sum(array_column($last_7_days, 'total'));
                        $total_seo_week = array_sum(array_column($last_7_days, 'with_seo'));
                        $seo_percentage_week = $total_week > 0 ? round(($total_seo_week / $total_week) * 100) : 0;
                        ?>
                        <div class="aab-summary-stat">
                            <span class="aab-summary-number"><?php echo $total_week; ?></span>
                            <span class="aab-summary-label">Total Posts</span>
                        </div>
                        <div class="aab-summary-stat">
                            <span class="aab-summary-number"><?php echo $total_seo_week; ?></span>
                            <span class="aab-summary-label">With SEO</span>
                        </div>
                        <div class="aab-summary-stat">
                            <span class="aab-summary-number"><?php echo $seo_percentage_week; ?>%</span>
                            <span class="aab-summary-label">SEO Coverage</span>
                        </div>
                    </div>
                </div>
                
                <!-- Dynamic SEO Stats -->
                <div class="aab-seo-stats-detailed">
                    
                    <!-- Posts with SEO Meta -->
                    <div class="aab-seo-stat-row">
                        <div class="aab-seo-stat-icon">
                            <span class="dashicons dashicons-analytics"></span>
                        </div>
                        <div class="aab-seo-stat-content">
                            <div class="aab-seo-stat-label">Posts with SEO</div>
                            <div class="aab-seo-stat-value-container">
                                <span class="aab-seo-stat-value">
                                    <?php echo esc_html($seo_stats['with_seo_meta']); ?> / <?php echo esc_html($seo_stats['total_posts']); ?>
                                </span>
                                <span class="aab-seo-stat-percentage <?php echo $seo_stats['with_seo_meta_percentage'] >= 75 ? 'good' : ($seo_stats['with_seo_meta_percentage'] >= 50 ? 'warning' : 'poor'); ?>">
                                    (<?php echo esc_html($seo_stats['with_seo_meta_percentage']); ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Complete SEO -->
                    <div class="aab-seo-stat-row">
                        <div class="aab-seo-stat-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="aab-seo-stat-content">
                            <div class="aab-seo-stat-label">Complete SEO (all fields)</div>
                            <div class="aab-seo-stat-value-container">
                                <span class="aab-seo-stat-value">
                                    <?php echo esc_html($seo_stats['complete_seo']); ?> / <?php echo esc_html($seo_stats['total_posts']); ?>
                                </span>
                                <span class="aab-seo-stat-percentage <?php echo $seo_stats['complete_seo_percentage'] >= 75 ? 'good' : ($seo_stats['complete_seo_percentage'] >= 50 ? 'warning' : 'poor'); ?>">
                                    (<?php echo esc_html($seo_stats['complete_seo_percentage']); ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Schema Markup -->
                    <div class="aab-seo-stat-row">
                        <div class="aab-seo-stat-icon">
                            <span class="dashicons dashicons-editor-code"></span>
                        </div>
                        <div class="aab-seo-stat-content">
                            <div class="aab-seo-stat-label">Schema Markup</div>
                            <div class="aab-seo-stat-value-container">
                                <span class="aab-seo-stat-value">
                                    <?php echo esc_html($seo_stats['with_schema']); ?> / <?php echo esc_html($seo_stats['total_posts']); ?>
                                </span>
                                <span class="aab-seo-stat-percentage <?php echo $seo_stats['with_schema_percentage'] >= 75 ? 'good' : ($seo_stats['with_schema_percentage'] >= 50 ? 'warning' : 'poor'); ?>">
                                    (<?php echo esc_html($seo_stats['with_schema_percentage']); ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Social Tags -->
                    <div class="aab-seo-stat-row">
                        <div class="aab-seo-stat-icon">
                            <span class="dashicons dashicons-share"></span>
                        </div>
                        <div class="aab-seo-stat-content">
                            <div class="aab-seo-stat-label">Social Tags</div>
                            <div class="aab-seo-stat-value-container">
                                <span class="aab-seo-stat-value">
                                    <?php echo esc_html($seo_stats['with_social_tags']); ?> / <?php echo esc_html($seo_stats['total_posts']); ?>
                                </span>
                                <span class="aab-seo-stat-percentage <?php echo $seo_stats['with_social_tags_percentage'] >= 75 ? 'good' : ($seo_stats['with_social_tags_percentage'] >= 50 ? 'warning' : 'poor'); ?>">
                                    (<?php echo esc_html($seo_stats['with_social_tags_percentage']); ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Internal Links -->
                    <div class="aab-seo-stat-row">
                        <div class="aab-seo-stat-icon">
                            <span class="dashicons dashicons-admin-links"></span>
                        </div>
                        <div class="aab-seo-stat-content">
                            <div class="aab-seo-stat-label">Internal Links</div>
                            <div class="aab-seo-stat-value-container">
                                <span class="aab-seo-stat-value">
                                    <?php echo esc_html($seo_stats['with_internal_links']); ?> / <?php echo esc_html($seo_stats['total_posts']); ?>
                                </span>
                                <span class="aab-seo-stat-percentage <?php echo $seo_stats['with_internal_links_percentage'] >= 50 ? 'good' : ($seo_stats['with_internal_links_percentage'] >= 25 ? 'warning' : 'poor'); ?>">
                                    (<?php echo esc_html($seo_stats['with_internal_links_percentage']); ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SEO-Enabled Campaigns -->
                    <div class="aab-seo-stat-row">
                        <div class="aab-seo-stat-icon">
                            <span class="dashicons dashicons-admin-settings"></span>
                        </div>
                        <div class="aab-seo-stat-content">
                            <div class="aab-seo-stat-label">SEO-Enabled Campaigns</div>
                            <div class="aab-seo-stat-value-container">
                                <span class="aab-seo-stat-value">
                                    <?php echo esc_html($seo_stats['seo_enabled_campaigns']); ?> / <?php echo esc_html($seo_stats['total_campaigns']); ?> campaigns
                                </span>
                            </div>
                        </div>
                    </div>
                    
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="aab-quick-actions-section">
        <h2 class="aab-section-title">Quick Actions</h2>
        
        <div class="aab-quick-actions-grid">
            <a href="<?php echo admin_url('admin.php?page=aab-new-campaign'); ?>" class="aab-quick-action-card">
                <div class="aab-qa-icon">
                    <span class="dashicons dashicons-plus-alt"></span>
                </div>
                <div class="aab-qa-content">
                    <h3>New Campaign</h3>
                    <p>Create a new content generation campaign</p>
                </div>
            </a>

            <a href="<?php echo admin_url('admin.php?page=aab-campaigns'); ?>" class="aab-quick-action-card">
                <div class="aab-qa-icon">
                    <span class="dashicons dashicons-list-view"></span>
                </div>
                <div class="aab-qa-content">
                    <h3>Manage Campaigns</h3>
                    <p>View and edit all your campaigns</p>
                </div>
            </a>

            <a href="<?php echo admin_url('admin.php?page=aab-settings'); ?>" class="aab-quick-action-card">
                <div class="aab-qa-icon">
                    <span class="dashicons dashicons-admin-settings"></span>
                </div>
                <div class="aab-qa-content">
                    <h3>Settings</h3>
                    <p>Configure your AI and plugin settings</p>
                </div>
            </a>

            <a href="<?php echo admin_url('admin.php?page=aab-import-export'); ?>" class="aab-quick-action-card">
                <div class="aab-qa-icon">
                    <span class="dashicons dashicons-database-import"></span>
                </div>
                <div class="aab-qa-content">
                    <h3>Import/Export</h3>
                    <p>Backup or restore your campaigns</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Help Section -->
    <div class="aab-help-section">
        <div class="aab-help-card">
            <h3><span class="dashicons dashicons-book"></span> Documentation</h3>
            <p>Learn how to get the most out of AI Auto Blog</p>
            <a href="#" class="aab-fancy-btn aab-btn-docs" target="_blank">
                <span class="dashicons dashicons-book-alt"></span>
                View Docs
            </a>

        </div>

        <div class="aab-help-card">
            <h3><span class="dashicons dashicons-sos"></span> Help Center</h3>
            <p>Need assistance? Check out our support resources</p>
            <a href="#" class="aab-fancy-btn aab-btn-help" target="_blank">
                <span class="dashicons dashicons-sos"></span>
                Get Help
            </a>
        </div>
    </div>

</div>