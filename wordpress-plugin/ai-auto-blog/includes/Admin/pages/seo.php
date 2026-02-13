<?php
if (!defined('ABSPATH')) exit;

// Get SEO statistics
$seo_stats = \AAB\Extensions\SEOStats::get_stats();

// Get all AI Auto Blog posts for the table
$all_posts = get_posts([
    'post_type' => 'post',
    'meta_key' => 'aab_campaign_id',
    'meta_compare' => 'EXISTS',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'date',
    'order' => 'DESC'
]);

?>

<div class="wrap aab-seo-wrap">
    
    <div class="aab-dashboard-header">
        <h1 class="aab-dashboard-title">
            <span class="dashicons dashicons-chart-line"></span>
            SEO Management
        </h1>
        <p class="aab-dashboard-subtitle">Optimize your AI-generated content for search engines</p>
    </div>

    <!-- SEO Overview - Dynamic -->
    <div class="aab-seo-preview-section">
        <h2 class="aab-section-title">SEO Overview</h2>
        
        <?php if ($seo_stats['total_posts'] === 0): ?>
            <!-- Empty State -->
            <div class="notice notice-info">
                <p><strong>No AI-generated posts yet.</strong> Create a campaign to start tracking SEO metrics!</p>
            </div>
        <?php else: ?>
            <div class="aab-seo-stats-grid">
                
                <!-- Posts with SEO Meta -->
                <div class="aab-seo-preview-card">
                    <div class="aab-seo-preview-icon">
                        <span class="dashicons dashicons-analytics"></span>
                    </div>
                    <div class="aab-seo-preview-header">
                        <h3>Posts with SEO</h3>
                        <div class="aab-seo-preview-value">
                            <?php echo esc_html($seo_stats['with_seo_meta']); ?>/<?php echo esc_html($seo_stats['total_posts']); ?>
                        </div>
                    </div>
                    <div class="aab-progress-bar">
                        <div class="aab-progress-fill <?php echo $seo_stats['with_seo_meta_percentage'] >= 75 ? 'good' : ($seo_stats['with_seo_meta_percentage'] >= 50 ? 'warning' : 'poor'); ?>" 
                             style="width: <?php echo esc_attr($seo_stats['with_seo_meta_percentage']); ?>%">
                        </div>
                    </div>
                    <div class="aab-progress-label"><?php echo esc_html($seo_stats['with_seo_meta_percentage']); ?>%</div>
                </div>

                <!-- Complete SEO -->
                <div class="aab-seo-preview-card">
                    <div class="aab-seo-preview-icon">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="aab-seo-preview-header">
                        <h3>Complete SEO</h3>
                        <div class="aab-seo-preview-value">
                            <?php echo esc_html($seo_stats['complete_seo']); ?>/<?php echo esc_html($seo_stats['total_posts']); ?>
                        </div>
                    </div>
                    <div class="aab-progress-bar">
                        <div class="aab-progress-fill <?php echo $seo_stats['complete_seo_percentage'] >= 75 ? 'good' : ($seo_stats['complete_seo_percentage'] >= 50 ? 'warning' : 'poor'); ?>" 
                             style="width: <?php echo esc_attr($seo_stats['complete_seo_percentage']); ?>%">
                        </div>
                    </div>
                    <div class="aab-progress-label"><?php echo esc_html($seo_stats['complete_seo_percentage']); ?>%</div>
                </div>

                <!-- Schema Markup -->
                <div class="aab-seo-preview-card">
                    <div class="aab-seo-preview-icon">
                        <span class="dashicons dashicons-editor-code"></span>
                    </div>
                    <div class="aab-seo-preview-header">
                        <h3>Schema Markup</h3>
                        <div class="aab-seo-preview-value">
                            <?php echo esc_html($seo_stats['with_schema']); ?>/<?php echo esc_html($seo_stats['total_posts']); ?>
                        </div>
                    </div>
                    <div class="aab-progress-bar">
                        <div class="aab-progress-fill <?php echo $seo_stats['with_schema_percentage'] >= 75 ? 'good' : ($seo_stats['with_schema_percentage'] >= 50 ? 'warning' : 'poor'); ?>" 
                             style="width: <?php echo esc_attr($seo_stats['with_schema_percentage']); ?>%">
                        </div>
                    </div>
                    <div class="aab-progress-label"><?php echo esc_html($seo_stats['with_schema_percentage']); ?>%</div>
                </div>

                <!-- Social Tags -->
                <div class="aab-seo-preview-card">
                    <div class="aab-seo-preview-icon">
                        <span class="dashicons dashicons-share"></span>
                    </div>
                    <div class="aab-seo-preview-header">
                        <h3>Social Tags</h3>
                        <div class="aab-seo-preview-value">
                            <?php echo esc_html($seo_stats['with_social_tags']); ?>/<?php echo esc_html($seo_stats['total_posts']); ?>
                        </div>
                    </div>
                    <div class="aab-progress-bar">
                        <div class="aab-progress-fill <?php echo $seo_stats['with_social_tags_percentage'] >= 75 ? 'good' : ($seo_stats['with_social_tags_percentage'] >= 50 ? 'warning' : 'poor'); ?>" 
                             style="width: <?php echo esc_attr($seo_stats['with_social_tags_percentage']); ?>%">
                        </div>
                    </div>
                    <div class="aab-progress-label"><?php echo esc_html($seo_stats['with_social_tags_percentage']); ?>%</div>
                </div>

                <!-- Internal Links -->
                <div class="aab-seo-preview-card">
                    <div class="aab-seo-preview-icon">
                        <span class="dashicons dashicons-admin-links"></span>
                    </div>
                    <div class="aab-seo-preview-header">
                        <h3>Internal Links</h3>
                        <div class="aab-seo-preview-value">
                            <?php echo esc_html($seo_stats['with_internal_links']); ?>/<?php echo esc_html($seo_stats['total_posts']); ?>
                        </div>
                    </div>
                    <div class="aab-progress-bar">
                        <div class="aab-progress-fill <?php echo $seo_stats['with_internal_links_percentage'] >= 50 ? 'good' : ($seo_stats['with_internal_links_percentage'] >= 25 ? 'warning' : 'poor'); ?>" 
                             style="width: <?php echo esc_attr($seo_stats['with_internal_links_percentage']); ?>%">
                        </div>
                    </div>
                    <div class="aab-progress-label"><?php echo esc_html($seo_stats['with_internal_links_percentage']); ?>%</div>
                </div>

                <!-- SEO-Enabled Campaigns -->
                <div class="aab-seo-preview-card">
                    <div class="aab-seo-preview-icon">
                        <span class="dashicons dashicons-admin-settings"></span>
                    </div>
                    <div class="aab-seo-preview-header">
                        <h3>SEO Campaigns</h3>
                        <div class="aab-seo-preview-value">
                            <?php echo esc_html($seo_stats['seo_enabled_campaigns']); ?>/<?php echo esc_html($seo_stats['total_campaigns']); ?>
                        </div>
                    </div>
                    <div class="aab-progress-bar">
                        <?php 
                        $campaign_percentage = $seo_stats['total_campaigns'] > 0 
                            ? round(($seo_stats['seo_enabled_campaigns'] / $seo_stats['total_campaigns']) * 100) 
                            : 0;
                        ?>
                        <div class="aab-progress-fill <?php echo $campaign_percentage >= 75 ? 'good' : ($campaign_percentage >= 50 ? 'warning' : 'poor'); ?>" 
                             style="width: <?php echo esc_attr($campaign_percentage); ?>%">
                        </div>
                    </div>
                    <div class="aab-progress-label"><?php echo esc_html($campaign_percentage); ?>%</div>
                </div>

            </div>
        <?php endif; ?>
    </div>

    <!-- SEO Content Table -->
    <div class="aab-seo-table-section">
        <h2 class="aab-section-title">SEO Content Table</h2>
        
        <div class="aab-table-wrapper">
            <table class="wp-list-table widefat fixed striped aab-seo-table">
                <thead>
                    <tr>
                        <th class="column-title">Post Title</th>
                        <th class="column-campaign">Campaign</th>
                        <th class="column-meta-title">SEO Title</th>
                        <th class="column-meta-desc">Meta Description</th>
                        <th class="column-status">Status</th>
                        <th class="column-date">Date</th>
                        <th class="column-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_posts)): ?>
                        <tr>
                            <td colspan="7" class="aab-empty-table">
                                <div class="aab-empty-state">
                                    <span class="dashicons dashicons-admin-post"></span>
                                    <p>No AI-generated posts yet</p>
                                    <p class="description">Posts will appear here once your campaigns start generating content</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($all_posts as $post): 
                            // Get SEO data
                            $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
                            $campaign = $campaign_id ? get_post($campaign_id) : null;
                            
                            // Check for meta title (Yoast, Rank Math, AIOSEO)
                            $meta_title = get_post_meta($post->ID, '_yoast_wpseo_title', true) 
                                       ?: get_post_meta($post->ID, 'rank_math_title', true)
                                       ?: get_post_meta($post->ID, '_aioseo_title', true);
                            
                            // Check for meta description
                            $meta_desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true)
                                      ?: get_post_meta($post->ID, 'rank_math_description', true)
                                      ?: get_post_meta($post->ID, '_aioseo_description', true);
                            
                            // Determine SEO status
                            $seo_enabled = $campaign_id ? get_post_meta($campaign_id, 'aab_seo_enabled', true) : false;
                            
                            $has_title = !empty($meta_title);
                            $has_desc = !empty($meta_desc);
                            $has_schema = $seo_enabled;
                            
                            // Calculate status
                            if ($has_title && $has_desc && $has_schema) {
                                $status_class = 'complete';
                                $status_text = 'Complete';
                                $status_icon = 'yes-alt';
                            } elseif ($has_title || $has_desc) {
                                $status_class = 'partial';
                                $status_text = 'Partial';
                                $status_icon = 'warning';
                            } else {
                                $status_class = 'missing';
                                $status_text = 'Missing';
                                $status_icon = 'dismiss';
                            }
                        ?>
                            <tr>
                                <td class="column-title">
                                    <strong>
                                        <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank">
                                            <?php echo esc_html($post->post_title); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td class="column-campaign">
                                    <?php if ($campaign): ?>
                                        <a href="<?php echo admin_url('admin.php?page=aab-new-campaign&edit=' . $campaign_id); ?>">
                                            <?php echo esc_html($campaign->post_title); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="description">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-meta-title">
                                    <?php if ($has_title): ?>
                                        <span class="aab-has-meta" title="<?php echo esc_attr($meta_title); ?>">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php echo esc_html(mb_substr($meta_title, 0, 30)) . (mb_strlen($meta_title) > 30 ? '...' : ''); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="aab-missing-meta">
                                            <span class="dashicons dashicons-dismiss"></span>
                                            Not set
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-meta-desc">
                                    <?php if ($has_desc): ?>
                                        <span class="aab-has-meta" title="<?php echo esc_attr($meta_desc); ?>">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php echo esc_html(mb_substr($meta_desc, 0, 35)) . (mb_strlen($meta_desc) > 35 ? '...' : ''); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="aab-missing-meta">
                                            <span class="dashicons dashicons-dismiss"></span>
                                            Not set
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-status">
                                    <span class="aab-seo-status aab-status-<?php echo esc_attr($status_class); ?>">
                                        <span class="dashicons dashicons-<?php echo esc_attr($status_icon); ?>"></span>
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </td>
                                <td class="column-date">
                                    <?php echo esc_html(get_the_date('M j, Y', $post->ID)); ?>
                                </td>
                                <td class="column-actions">
                                    <a href="<?php echo get_permalink($post->ID); ?>" class="button button-small aab-view-btn" target="_blank">
                                        <span class="dashicons dashicons-visibility"></span> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SEO Tips Section -->
    <div class="aab-seo-tips-section">
        <h2 class="aab-section-title">SEO Optimization Tips</h2>
        
        <div class="aab-tips-grid">
            <div class="aab-tip-card">
                <span class="dashicons dashicons-lightbulb"></span>
                <h3>Enable SEO for Campaigns</h3>
                <p>Turn on SEO features in your campaign settings to automatically add meta titles, descriptions, and schema markup to all generated posts.</p>
            </div>

            <div class="aab-tip-card">
                <span class="dashicons dashicons-lightbulb"></span>
                <h3>Use Focus Keywords</h3>
                <p>Set a focus keyword for each campaign to ensure AI-generated content is optimized for specific search terms.</p>
            </div>

            <div class="aab-tip-card">
                <span class="dashicons dashicons-lightbulb"></span>
                <h3>Enable Internal Linking</h3>
                <p>Turn on internal links to automatically connect related posts and improve your site's SEO structure and user navigation.</p>
            </div>

            <div class="aab-tip-card">
                <span class="dashicons dashicons-lightbulb"></span>
                <h3>Optimize Meta Descriptions</h3>
                <p>Ensure all posts have compelling meta descriptions under 160 characters to improve click-through rates from search results.</p>
            </div>
        </div>
    </div>

</div>