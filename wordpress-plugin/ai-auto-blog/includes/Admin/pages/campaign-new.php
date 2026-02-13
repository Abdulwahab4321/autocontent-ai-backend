<?php
if (!defined('ABSPATH')) exit;
// ================================================
// LICENSE CHECK — redirect to settings if not activated
// ================================================
if ( ! \AAB\Core\License::is_license_active() ) {
    wp_redirect( admin_url( 'admin.php?page=aab-settings&license_error=not_activated' ) );
    exit;
}

$editing = isset($_GET['edit']);
$campaign_id = $editing ? intval($_GET['edit']) : 0;

$campaign_title = '';
$keywords = [];
$min_words = 500;      // new default
$max_words = 1000;
$max_posts = 100;
$rotate_keywords = false;
$keyword_as_title = false;
$one_post_per_keyword = false;
$use_custom_prompt = false;
$custom_title_prompt = '';
$custom_content_prompt = '';

$run_interval = 60;
$run_unit = 'minutes';
$pause_autorun = false;
$custom_post_time = false;
$custom_post_time_value = '';
$show_external_cron = false;
$enabled = true;
$last_run = 0;

// Post settings defaults
$aab_post_type = 'post';
$aab_post_status = 'draft';
$aab_post_author = 0;
$aab_set_category = 0;
$aab_categories = []; // will be array of IDs or names in saver
$aab_categories_for_display = []; // for textarea display

// Content settings defaults
$alt_from_title_all = 0;
$alt_from_title_empty = 0;
$remove_links = 0;
$links_new_tab = 0;
$links_nofollow = 0;

/**
 * SAVE HANDLER
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_wpnonce']) && check_admin_referer('aab_save_campaign')) {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $posted_id = intval($_POST['campaign_id'] ?? 0);
    $title = sanitize_text_field($_POST['campaign_name'] ?? '');

    // Fallback if title somehow empty on save
    if (empty($title)) {
        $title = 'campaign-' . mt_rand(1000, 9999);
    }

    $post_args = [
        'post_title' => $title,
        'post_type'  => 'aab_campaign',
    ];

    if ($posted_id > 0) {
        $post_args['ID'] = $posted_id;
        wp_update_post($post_args);
        $saved_id = $posted_id;
    } else {
        $post_args['post_status'] = 'publish';
        $saved_id = wp_insert_post($post_args);
    }

    if (!$saved_id || is_wp_error($saved_id)) {
        wp_die('Failed to save campaign');
    }

    // Keywords
    $raw_keywords = sanitize_text_field($_POST['aab_keywords'] ?? '');
    $keywords_arr = array_values(array_filter(array_map('trim', explode('|', $raw_keywords))));
    update_post_meta($saved_id, 'aab_keywords', $keywords_arr);

    // Min / Max words
    update_post_meta($saved_id, 'min_words', max(0, intval($_POST['min_words'] ?? 0)));
    update_post_meta($saved_id, 'max_words', max(0, intval($_POST['max_words'] ?? 0)));

    // Max posts
    update_post_meta($saved_id, 'max_posts', max(0, intval($_POST['max_posts'] ?? 0)));

    // Basic options
    update_post_meta($saved_id, 'rotate_keywords', isset($_POST['rotate_keywords']) ? 1 : 0);
    update_post_meta($saved_id, 'keyword_as_title', isset($_POST['keyword_as_title']) ? 1 : 0);
    update_post_meta($saved_id, 'one_post_per_keyword', isset($_POST['one_post_per_keyword']) ? 1 : 0);
    update_post_meta($saved_id, 'use_custom_prompt', isset($_POST['use_custom_prompt']) ? 1 : 0);

    // Custom prompts
    update_post_meta($saved_id, 'custom_title_prompt', sanitize_text_field($_POST['custom_title_prompt'] ?? ''));
    update_post_meta($saved_id, 'custom_content_prompt', sanitize_textarea_field($_POST['custom_content_prompt'] ?? ''));

    // Scheduling
    update_post_meta($saved_id, 'aab_run_interval', max(0, intval($_POST['aab_run_interval'] ?? 0)));
    update_post_meta($saved_id, 'aab_run_unit', sanitize_text_field($_POST['aab_run_unit'] ?? 'minutes'));
    update_post_meta($saved_id, 'aab_pause_autorun', isset($_POST['aab_pause_autorun']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_custom_post_time', isset($_POST['aab_custom_post_time']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_custom_post_time_value', sanitize_text_field($_POST['aab_custom_post_time_value'] ?? ''));
    update_post_meta($saved_id, 'aab_show_external_cron', isset($_POST['aab_show_external_cron']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_enabled', isset($_POST['aab_enabled']) ? 1 : 0);

    // Post settings
    $post_type_post = sanitize_text_field($_POST['aab_post_type'] ?? 'post');
    if (!in_array($post_type_post, ['post', 'page'], true)) $post_type_post = 'post';
    update_post_meta($saved_id, 'aab_post_type', $post_type_post);

    $post_status_post = sanitize_text_field($_POST['aab_post_status'] ?? 'draft');
    $allowed_status = ['pending', 'draft', 'publish'];
    if (!in_array($post_status_post, $allowed_status, true)) $post_status_post = 'draft';
    update_post_meta($saved_id, 'aab_post_status', $post_status_post);

    $author_id_post = intval($_POST['aab_post_author'] ?? 0);
    if ($author_id_post && get_userdata($author_id_post)) {
        update_post_meta($saved_id, 'aab_post_author', $author_id_post);
    } else {
        update_post_meta($saved_id, 'aab_post_author', 0);
    }

    update_post_meta($saved_id, 'aab_set_category', isset($_POST['aab_set_category']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_categories', isset($_POST['aab_categories']) ? wp_unslash($_POST['aab_categories']) : '');

    // AI Settings
    update_post_meta($saved_id, 'aab_ai_custom_params', isset($_POST['aab_ai_custom_params']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_ai_max_tokens', isset($_POST['aab_ai_max_tokens']) ? intval($_POST['aab_ai_max_tokens']) : 0);
    update_post_meta($saved_id, 'aab_ai_temperature', isset($_POST['aab_ai_temperature']) ? floatval($_POST['aab_ai_temperature']) : 0.0);

    // Image Settings
    update_post_meta($saved_id, 'aab_feat_generate', isset($_POST['aab_feat_generate']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_feat_image_method', sanitize_text_field($_POST['aab_feat_image_method'] ?? 'dalle'));
    update_post_meta($saved_id, 'aab_feat_image_size', sanitize_text_field($_POST['aab_feat_image_size'] ?? '1024x1024'));
    update_post_meta($saved_id, 'aab_feat_set_first_as_featured', isset($_POST['aab_feat_set_first_as_featured']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_feat_get_original_from_data', isset($_POST['aab_feat_get_original_from_data']) ? 1 : 0);

    update_post_meta($saved_id, 'aab_content_image_method', sanitize_text_field($_POST['aab_content_image_method'] ?? 'dalle'));
    update_post_meta($saved_id, 'aab_content_image_size', sanitize_text_field($_POST['aab_content_image_size'] ?? '1024x1024'));
    update_post_meta($saved_id, 'aab_get_image_by_title', isset($_POST['aab_get_image_by_title']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_get_image_by_prompt', isset($_POST['aab_get_image_by_prompt']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_content_custom_prompt', sanitize_textarea_field($_POST['aab_content_custom_prompt'] ?? ''));
    update_post_meta($saved_id, 'aab_set_keyword_as_alt', isset($_POST['aab_set_keyword_as_alt']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_search_by_parent_keyword', isset($_POST['aab_search_by_parent_keyword']) ? 1 : 0);

    $num_images = isset($_POST['aab_content_num_images']) ? intval($_POST['aab_content_num_images']) : 1;
    $num_images = min(3, max(1, $num_images));
    update_post_meta($saved_id, 'aab_content_num_images', $num_images);

    update_post_meta($saved_id, 'aab_content_dist', sanitize_text_field($_POST['aab_content_dist'] ?? 'fixed'));
    update_post_meta($saved_id, 'aab_content_pos_1', sanitize_text_field($_POST['aab_content_pos_1'] ?? 'top'));
    update_post_meta($saved_id, 'aab_content_pos_2', sanitize_text_field($_POST['aab_content_pos_2'] ?? 'middle'));
    update_post_meta($saved_id, 'aab_content_pos_3', sanitize_text_field($_POST['aab_content_pos_3'] ?? 'bottom'));
    update_post_meta($saved_id, 'aab_content_wp_image_size', sanitize_text_field($_POST['aab_content_wp_image_size'] ?? 'full'));

    update_post_meta($saved_id, 'aab_image_quality_processing', isset($_POST['aab_image_quality_processing']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_enable_youtube', isset($_POST['aab_enable_youtube']) ? 1 : 0);

    // Content Settings
    update_post_meta($saved_id, 'aab_alt_from_title_all', isset($_POST['aab_alt_from_title_all']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_alt_from_title_empty', isset($_POST['aab_alt_from_title_empty']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_remove_links', isset($_POST['aab_remove_links']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_links_new_tab', isset($_POST['aab_links_new_tab']) ? 1 : 0);
    update_post_meta($saved_id, 'aab_links_nofollow', isset($_POST['aab_links_nofollow']) ? 1 : 0);

    // Redirect to edit mode after save
    $redirect = admin_url('admin.php?page=aab-new-campaign' . ($saved_id ? '&edit=' . $saved_id : ''));
    wp_redirect($redirect);
    exit;
}

// ── Load existing campaign data when editing ──
if ($editing) {
    $post = get_post($campaign_id);
    if ($post && $post->post_type === 'aab_campaign') {
        $campaign_title = $post->post_title;
        $keywords = (array) get_post_meta($campaign_id, 'aab_keywords', true);
        $min_words = (int) get_post_meta($campaign_id, 'min_words', true) ?: $min_words;
        $max_words = (int) get_post_meta($campaign_id, 'max_words', true) ?: $max_words;
        $max_posts = (int) get_post_meta($campaign_id, 'max_posts', true) ?: $max_posts;
        $rotate_keywords = (bool) get_post_meta($campaign_id, 'rotate_keywords', true);
        $keyword_as_title = (bool) get_post_meta($campaign_id, 'keyword_as_title', true);
        $one_post_per_keyword = (bool) get_post_meta($campaign_id, 'one_post_per_keyword', true);
        $use_custom_prompt = (bool) get_post_meta($campaign_id, 'use_custom_prompt', true);
        $custom_title_prompt = (string) get_post_meta($campaign_id, 'custom_title_prompt', true);
        $custom_content_prompt = (string) get_post_meta($campaign_id, 'custom_content_prompt', true);

        $run_interval = (int) get_post_meta($campaign_id, 'aab_run_interval', true) ?: $run_interval;
        $run_unit = get_post_meta($campaign_id, 'aab_run_unit', true) ?: $run_unit;
        $pause_autorun = (bool) get_post_meta($campaign_id, 'aab_pause_autorun', true);
        $custom_post_time = (bool) get_post_meta($campaign_id, 'aab_custom_post_time', true);
        $custom_post_time_value = get_post_meta($campaign_id, 'aab_custom_post_time_value', true) ?: '';
        $show_external_cron = (bool) get_post_meta($campaign_id, 'aab_show_external_cron', true);
        $enabled = get_post_meta($campaign_id, 'aab_enabled', true) === '' ? true : (bool) get_post_meta($campaign_id, 'aab_enabled', true);
        $last_run = intval(get_post_meta($campaign_id, 'aab_last_run', true) ?: 0);

        // Image settings
        $feat_generate = (int) get_post_meta($campaign_id, 'aab_feat_generate', true);
        $feat_image_method = get_post_meta($campaign_id, 'aab_feat_image_method', true) ?: 'dalle';
        $feat_image_size = get_post_meta($campaign_id, 'aab_feat_image_size', true) ?: '1024x1024';
        $feat_set_first = (int) get_post_meta($campaign_id, 'aab_feat_set_first_as_featured', true);
        $feat_get_original = (int) get_post_meta($campaign_id, 'aab_feat_get_original_from_data', true);

        $content_image_method = get_post_meta($campaign_id, 'aab_content_image_method', true) ?: 'dalle';
        $content_image_size = get_post_meta($campaign_id, 'aab_content_image_size', true) ?: '1024x1024';
        $get_by_title = (int) get_post_meta($campaign_id, 'aab_get_image_by_title', true);
        $get_by_prompt = (int) get_post_meta($campaign_id, 'aab_get_image_by_prompt', true);
        $content_custom_prompt = get_post_meta($campaign_id, 'aab_content_custom_prompt', true) ?: '';
        $set_keyword_alt = (int) get_post_meta($campaign_id, 'aab_set_keyword_as_alt', true);
        $search_by_parent_keyword = (int) get_post_meta($campaign_id, 'aab_search_by_parent_keyword', true);
        $content_num_images = (int) (get_post_meta($campaign_id, 'aab_content_num_images', true) ?: 1);
        $content_dist = get_post_meta($campaign_id, 'aab_content_dist', true) ?: 'fixed';
        $content_pos_1 = get_post_meta($campaign_id, 'aab_content_pos_1', true) ?: 'top';
        $content_pos_2 = get_post_meta($campaign_id, 'aab_content_pos_2', true) ?: 'middle';
        $content_pos_3 = get_post_meta($campaign_id, 'aab_content_pos_3', true) ?: 'bottom';
        $content_wp_image_size = get_post_meta($campaign_id, 'aab_content_wp_image_size', true) ?: 'full';

        $image_quality_processing = (int) get_post_meta($campaign_id, 'aab_image_quality_processing', true);
        $enable_youtube = (int) get_post_meta($campaign_id, 'aab_enable_youtube', true);

        // Post settings
        $aab_post_type = get_post_meta($campaign_id, 'aab_post_type', true) ?: $aab_post_type;
        $aab_post_status = get_post_meta($campaign_id, 'aab_post_status', true) ?: $aab_post_status;
        $aab_post_author = intval(get_post_meta($campaign_id, 'aab_post_author', true) ?: 0);
        $aab_set_category = get_post_meta($campaign_id, 'aab_set_category', true) ? 1 : 0;

        $raw_aab_cats = get_post_meta($campaign_id, 'aab_categories', true);
        $aab_categories = is_array($raw_aab_cats) ? $raw_aab_cats : [];
        if (is_string($raw_aab_cats) && trim($raw_aab_cats) !== '') {
            $parts = preg_split('/[\r\n,|]+/', $raw_aab_cats);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p) $aab_categories[] = $p;
            }
        }

        $aab_categories_for_display = [];
        foreach ($aab_categories as $entry) {
            if (is_numeric($entry)) {
                $term = get_term((int)$entry, 'category');
                if ($term && !is_wp_error($term)) {
                    $aab_categories_for_display[] = $term->name;
                    continue;
                }
            }
            $aab_categories_for_display[] = (string)$entry;
        }

        // Content settings
        $alt_from_title_all = (int) get_post_meta($campaign_id, 'aab_alt_from_title_all', true) ?: 0;
        $alt_from_title_empty = (int) get_post_meta($campaign_id, 'aab_alt_from_title_empty', true) ?: 0;
        $remove_links = (int) get_post_meta($campaign_id, 'aab_remove_links', true) ?: 0;
        $links_new_tab = (int) get_post_meta($campaign_id, 'aab_links_new_tab', true) ?: 0;
        $links_nofollow = (int) get_post_meta($campaign_id, 'aab_links_nofollow', true) ?: 0;
    }
} else {
    // ── Automatically suggest campaign name for NEW campaigns ──
    $campaign_title = 'campaign-' . mt_rand(1000, 9999);
}
?>

<!-- Load Custom CSS -->
 
<link rel="stylesheet" href="<?php echo esc_url(AAB_URL . 'assets/admin/css/aab-kw-suggest.css'); ?>">

<div class="aab-campaign-wrapper">
    <!-- Header -->
     <!-- ===============================
             STICKY TOP ACTION BAR
        ================================ -->
        <div class="aab-sticky-actions" id="aabStickyActions">
            <div class="aab-sticky-inner">
                <div class="aab-sticky-left">
                    <?php echo $editing ? 'Edit Campaign' : 'New Campaign'; ?>
                </div>

                <div class="aab-sticky-right">
                    <button type="button"
                            id="aabStickySubmit"
                            class="aab-btn aab-btn-primary">
                        <?php echo $editing ? 'Update Campaign' : 'Create Campaign'; ?>
                    </button>
                </div>
            </div>
        </div>

    <form method="post" id="aab-campaign-form">
        <?php wp_nonce_field('aab_save_campaign'); ?>
        <input type="hidden" name="campaign_id" value="<?php echo esc_attr($campaign_id); ?>">

        <!-- Basic Information Section -->
        <div class="aab-section">
            <h2 class="aab-section-title">Basic Information</h2>
            <div class="aab-section-content">
                <div class="aab-form-row">
                    <label for="campaign_name">Campaign Name *</label>
                    <input type="text" id="campaign_name" name="campaign_name"
                           value="<?php echo esc_attr($campaign_title); ?>" required>
                    <small>Give your campaign a descriptive name</small>
                </div>
            </div>
        </div>

        <!-- Keywords Section -->
        <div class="aab-section">
            <h2 class="aab-section-title">Article Keywords</h2>
            <div class="aab-section-content">
                <div class="aab-form-row">
                    <label for="aab-single-keyword">Add Single Keyword</label>
                    <div class="aab-form-inline">
                        <div class="aab-form-group" style="flex: 3;">
                            <input type="text" id="aab-single-keyword" placeholder="Enter keyword">
                        </div>
                        <div class="aab-form-group" style="flex: 1;">
                            <button type="button" class="aab-btn aab-btn-primary" id="aab-add-keyword">Add Keyword</button>
                        </div>
                    </div>
                </div>

                <div class="aab-form-row">
                    <label for="aab-bulk-keywords">Bulk Add Keywords</label>
                    <textarea id="aab-bulk-keywords" rows="4" placeholder="Enter multiple keywords (one per line)"></textarea>
                    <button type="button" class="aab-btn aab-btn-secondary aab-mt-10" id="aab-add-bulk">Add Bulk Keywords</button>
                </div>

                <div class="aab-form-row">
                    <label>All Keywords</label>
                    <div class="aab-keywords-container" id="aab-keyword-box">
                        <!-- Keywords will be rendered here as tags -->
                    </div>
                    <input type="hidden" name="aab_keywords" id="aab-keywords-input" value="">
                </div>
            </div>
        </div>

        <!-- Post Settings Section -->
        <div class="aab-section">
            <h2 class="aab-section-title">Post Configuration</h2>
            <div class="aab-section-content">
                <div class="aab-grid aab-grid-2">
                    <div class="aab-form-row">
                        <label for="max_words">Max Words Per Post</label>
                        <input type="number" id="max_words" name="max_words" value="<?php echo esc_attr($max_words); ?>" min="0" step="1">
                        <small>Maximum words per generated post. 0 = unlimited</small>
                    </div>

                    <div class="aab-form-row">
                        <label for="max_posts">Max Posts</label>
                        <input type="number" id="max_posts" name="max_posts" value="<?php echo esc_attr($max_posts); ?>" min="0">
                        <small>Maximum number of posts to generate</small>
                    </div>
                </div>

                <div class="aab-form-row">
                    <label>Options</label>
                    <div class="aab-checkbox-group">
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="rotate_keywords" name="rotate_keywords" <?php checked($rotate_keywords); ?>>
                            <span class="aab-toggle"></span>
                            <label for="rotate_keywords">Rotate Keywords</label>
                        </div>
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="keyword_as_title" name="keyword_as_title" <?php checked($keyword_as_title); ?>>
                            <span class="aab-toggle"></span>
                            <label for="keyword_as_title">Use Keyword as Title</label>
                        </div>
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="one_post_per_keyword" name="one_post_per_keyword" <?php checked($one_post_per_keyword); ?>>
                            <span class="aab-toggle"></span>
                            <label for="one_post_per_keyword">One Post Per Keyword</label>
                        </div>
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="aab-custom-prompt-toggle" name="use_custom_prompt" <?php checked($use_custom_prompt); ?>>
                            <span class="aab-toggle"></span>
                            <label for="aab-custom-prompt-toggle">Use Custom Prompt</label>
                        </div>
                    </div>
                </div>

                <div id="aab-custom-prompts" style="<?php echo $use_custom_prompt ? '' : 'display:none;'; ?>">
                    <div class="aab-form-row">
                        <label for="custom_title_prompt">Custom Title Prompt</label>
                        <input type="text" id="custom_title_prompt" name="custom_title_prompt"
                               value="<?php echo esc_attr($custom_title_prompt); ?>"
                               placeholder="Custom Title Prompt">
                    </div>

                    <div class="aab-form-row">
                        <label for="custom_content_prompt">Custom Content Prompt</label>
                        <textarea id="custom_content_prompt" name="custom_content_prompt" rows="4"
                                  placeholder="Custom Content Prompt"><?php echo esc_textarea($custom_content_prompt); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scheduling Section -->
        <div class="aab-section">
            <h2 class="aab-section-title">Scheduling & Automation</h2>
            <div class="aab-section-content">
                <div class="aab-form-row">
                    <label>Auto Run Frequency</label>
                    <small class="aab-text-muted aab-mb-10" style="display: block;">Set the interval between new posts (Not recommended below 30 minutes)</small>
                    <div class="aab-form-inline">
                        <div class="aab-form-group">
                            <input type="number" name="aab_run_interval" id="aab_run_interval" value="<?php echo esc_attr($run_interval); ?>">
                        </div>
                        <div class="aab-form-group">
                            <select name="aab_run_unit" id="aab_run_unit">
                                <option value="minutes" <?php selected($run_unit, 'minutes'); ?>>Minutes</option>
                                <option value="hours" <?php selected($run_unit, 'hours'); ?>>Hours</option>
                                <option value="days" <?php selected($run_unit, 'days'); ?>>Days</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="aab-form-row">
                    <label>Scheduling Options</label>
                    <div class="aab-checkbox-group">
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="aab_pause_autorun" name="aab_pause_autorun" <?php checked($pause_autorun); ?>>
                            <span class="aab-toggle"></span>
                            <label for="aab_pause_autorun">Pause AutoRun for this Campaign</label>
                        </div>
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="aab_custom_post_time" name="aab_custom_post_time" <?php checked($custom_post_time); ?>>
                            <span class="aab-toggle"></span>
                            <label for="aab_custom_post_time">Custom Post Time</label>
                        </div>
                        <div class="aab-checkbox-wrapper">
                            <input type="checkbox" id="aab_show_external_cron" name="aab_show_external_cron" <?php checked($show_external_cron); ?>>
                            <span class="aab-toggle"></span>
                            <label for="aab_show_external_cron">Show External CRON for this campaign</label>
                        </div>
                    </div>
                </div>

                <div id="aab_custom_time_row" class="aab-form-row" style="<?php echo $custom_post_time ? '' : 'display:none;'; ?>">
                    <label for="aab_custom_post_time_value">Custom Post Time (24-hour format)</label>
                    <input type="time" id="aab_custom_post_time_value" name="aab_custom_post_time_value" value="<?php echo esc_attr($custom_post_time_value); ?>">
                </div>

                <?php if ($editing && $show_external_cron): ?>
                    <div class="aab-info-box info">
                        <strong>External CRON URL:</strong><br>
                        <code style="display:block;padding:8px;margin-top:8px;background:#fff;border-radius:4px;word-break:break-all;"><?php echo esc_html(\AAB\Core\CampaignScheduler::get_external_trigger_url($campaign_id)); ?></code>
                        <small>Use this URL in your external CRON service</small>
                    </div>
                <?php endif; ?>

                <?php if ($editing): ?>
                    <div class="aab-form-row">
                        <label>Last Run</label>
                        <div style="padding: 10px; background: #f8fafc; border-radius: 6px; font-weight: 500;">
                            <?php if ($last_run): ?>
                                <?php echo esc_html(date('Y-m-d H:i:s', $last_run)); ?>
                            <?php else: ?>
                                Never
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Post Type & Publishing Settings -->
        <div class="aab-section">
            <h2 class="aab-section-title">Post Type & Publishing</h2>
            <div class="aab-section-content">
                <div class="aab-grid aab-grid-3">
                    <div class="aab-form-row">
                        <label for="aab_post_type">Post Type</label>
                        <select id="aab_post_type" name="aab_post_type">
                            <option value="post" <?php selected($aab_post_type, 'post'); ?>>Post</option>
                            <option value="page" <?php selected($aab_post_type, 'page'); ?>>Page</option>
                        </select>
                    </div>

                    <div class="aab-form-row">
                        <label for="aab_post_status">Post Status</label>
                        <select id="aab_post_status" name="aab_post_status">
                            <option value="pending" <?php selected($aab_post_status, 'pending'); ?>>Pending</option>
                            <option value="draft" <?php selected($aab_post_status, 'draft'); ?>>Draft</option>
                            <option value="publish" <?php selected($aab_post_status, 'publish'); ?>>Publish</option>
                        </select>
                    </div>

                    <div class="aab-form-row">
                        <label for="aab_post_author">Author</label>
                        <select id="aab_post_author" name="aab_post_author">
                            <option value="0"><?php echo esc_html('Current User / Default'); ?></option>
                            <?php
                            $users = get_users(['who' => 'authors', 'orderby' => 'display_name']);
                            foreach ($users as $u) {
                                printf('<option value="%d" %s>%s</option>', esc_attr($u->ID), selected($aab_post_author, $u->ID, false), esc_html($u->display_name));
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="aab-form-row">
                    <div class="aab-checkbox-wrapper">
                        <input type="checkbox" id="aab_set_category" name="aab_set_category" <?php echo $aab_set_category ? 'checked' : ''; ?>>
                        <span class="aab-toggle"></span>
                        <label for="aab_set_category">Set Category</label>
                    </div>
                </div>

                <div class="aab-form-row">
                    <label for="aab-categories-textarea">Selected Categories</label>
                    <textarea id="aab-categories-textarea" name="aab_categories" rows="3" 
                              placeholder="Click to add categories (one per line)" 
                              <?php echo $aab_set_category ? '' : 'disabled="disabled"'; ?>><?php echo esc_textarea(implode("\n", $aab_categories_for_display ?? [])); ?></textarea>
                    <small>Click into the box to see all categories. You can paste IDs, slugs, or names (one per line)</small>
                    <div id="aab-categories-picker"></div>
                </div>
            </div>
        </div>

        <!-- Content Settings -->
        <div class="aab-section">
            <h2 class="aab-section-title">Content Settings</h2>
            <div class="aab-section-content">
                <div class="aab-checkbox-group">
                    <div class="aab-checkbox-wrapper">
                        <input type="checkbox" id="aab_alt_from_title_all" name="aab_alt_from_title_all" <?php checked($alt_from_title_all); ?>>
                        <span class="aab-toggle"></span>
                        <label for="aab_alt_from_title_all">Set Post Title as Alt Text for all Content Images</label>
                    </div>
                    <div class="aab-checkbox-wrapper">
                        <input type="checkbox" id="aab_alt_from_title_empty" name="aab_alt_from_title_empty" <?php checked($alt_from_title_empty); ?>>
                        <span class="aab-toggle"></span>
                        <label for="aab_alt_from_title_empty">Set Post Title as Alt Text for Empty Image Alt only</label>
                    </div>
                    <div class="aab-checkbox-wrapper">
                        <input type="checkbox" id="aab_remove_links" name="aab_remove_links" <?php checked($remove_links); ?>>
                        <span class="aab-toggle"></span>
                        <label for="aab_remove_links">Auto-remove all Hyperlinks (remove & keep anchor text)</label>
                    </div>
                    <div class="aab-checkbox-wrapper">
                        <input type="checkbox" id="aab_links_new_tab" name="aab_links_new_tab" <?php checked($links_new_tab); ?>>
                        <span class="aab-toggle"></span>
                        <label for="aab_links_new_tab">Open Source Links in New Tab (add target="_blank")</label>
                    </div>
                    <div class="aab-checkbox-wrapper">
                        <input type="checkbox" id="aab_links_nofollow" name="aab_links_nofollow" <?php checked($links_nofollow); ?>>
                        <span class="aab-toggle"></span>
                        <label for="aab_links_nofollow">Add Nofollow to Links (append rel="nofollow")</label>
                    </div>
                </div>
                <small class="aab-text-muted" style="display: block; margin-top: 12px;">These content-level options will be applied after post generation when inserting/updating content.</small>
            </div>
        </div>

        <!-- AI Settings -->
        <div class="aab-section">
            <h2 class="aab-section-title">AI Settings</h2>
            <div class="aab-section-content">
                <div class="aab-checkbox-wrapper">
                    <input type="checkbox" id="aab_ai_custom_params" name="aab_ai_custom_params" <?php checked(get_post_meta($campaign_id, 'aab_ai_custom_params', true) ?: 0); ?>>
                    <span class="aab-toggle"></span>
                    <label for="aab_ai_custom_params">Enable AI Custom Parameters</label>
                </div>

                <div id="aab_ai_custom_row" style="<?php echo get_post_meta($campaign_id, 'aab_ai_custom_params', true) ? '' : 'display:none;'; ?>">
                    <div class="aab-grid aab-grid-2 aab-mt-20">
                        <div class="aab-form-row">
                            <label for="aab_ai_max_tokens">Max Token Length</label>
                            <input type="number" id="aab_ai_max_tokens" name="aab_ai_max_tokens" value="<?php echo esc_attr(get_post_meta($campaign_id, 'aab_ai_max_tokens', true) ?: ''); ?>">
                        </div>

                        <div class="aab-form-row">
                            <label for="aab_ai_temperature">Temperature (0.0 - 1.0)</label>
                            <input type="number" id="aab_ai_temperature" step="0.01" min="0" max="1" name="aab_ai_temperature" value="<?php echo esc_attr(get_post_meta($campaign_id, 'aab_ai_temperature', true) ?: ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image Settings Toggle Button -->
        <!-- <div style="text-align: center; margin: 30px 0;">
            <button type="button" id="aab-toggle-image-settings" class="aab-btn aab-btn-outline aab-btn-large">
                🖼️ Show Image Settings
            </button>
        </div> -->

        <!-- Image Settings Container (Hidden by default) -->
        <div id="aab-image-settings-container" style="display: none;">
            <?php
            // Include the image integration settings from extensions
            $image_integration_file = AAB_PATH . 'extensions/imageIntegration.php';
            if (file_exists($image_integration_file)) {
                include $image_integration_file;
            }
            ?>
        </div>

        <?php
        // ============================================
        // SEO SETTINGS SECTION
        // ============================================
        
        // Get campaign ID for SEO settings
        $seo_campaign_id = $campaign_id;
        
        // Load SEO settings if editing existing campaign
        $seo_enabled = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_enabled', true) : false;
        $seo_title_template = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_title_template', true) : '{title}';
        $seo_description_template = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_description_template', true) : '';
        $seo_focus_keyword = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_focus_keyword', true) : '';
        $seo_schema_enabled = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_schema_enabled', true) : true;
        $seo_internal_links = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_internal_links', true) : false;
        $seo_internal_links_count = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_internal_links_count', true) : 3;
        $seo_social_meta = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_social_meta', true) : true;
        $seo_optimize_images = $seo_campaign_id ? get_post_meta($seo_campaign_id, 'aab_seo_optimize_images', true) : true;
        ?>

        <!-- SEO Settings Section -->
        <div class="aab-seo-settings-section" style="margin-top: 40px;">
            
            <!-- Section Header -->
            <div class="aab-seo-header">
                <div class="aab-seo-header-icon">
                    <span class="dashicons dashicons-chart-line"></span>
                </div>
                <div class="aab-seo-header-content">
                    <h2>SEO Optimization</h2>
                    <p>Automatically optimize posts for search engines and social media</p>
                </div>
            </div>

            <!-- Enable/Disable Toggle -->
            <div class="aab-seo-toggle-row">
                <label class="aab-seo-main-toggle">
                    <input type="checkbox" 
                           name="aab_seo_enabled" 
                           value="1" 
                           <?php checked($seo_enabled, true); ?>
                           id="aab-seo-main-toggle"
                           class="aab-seo-checkbox-hidden">
                    <span class="aab-seo-toggle-track">
                        <span class="aab-seo-toggle-thumb"></span>
                    </span>
                    <span class="aab-seo-toggle-label">Enable SEO Optimization</span>
                </label>
                <p class="aab-seo-description">
                    Works with Yoast SEO, Rank Math, and All in One SEO plugins
                </p>
            </div>

            <!-- SEO Options Panel -->
            <div id="aab-seo-options-wrapper" style="<?php echo $seo_enabled ? '' : 'display:none;'; ?>">
                
                <!-- Meta Tags Section -->
                <div class="aab-seo-subsection">
                    <div class="aab-seo-subsection-header">
                        <span class="dashicons dashicons-edit"></span>
                        <h3>Meta Tags</h3>
                    </div>
                    
                    <div class="aab-seo-field">
                        <label for="aab-seo-title-template" class="aab-seo-label">
                            Meta Title Template
                            <span class="aab-seo-tooltip" title="Use variables: {title}, {keyword}, {site_name}">
                                <span class="dashicons dashicons-info"></span>
                            </span>
                        </label>
                        <input type="text" 
                               name="aab_seo_title_template" 
                               id="aab-seo-title-template" 
                               value="<?php echo esc_attr($seo_title_template); ?>"
                               placeholder="{title} | {site_name}"
                               class="aab-seo-text-input aab-seo-full-width">
                        <div class="aab-seo-field-footer">
                            <span class="aab-seo-hint">
                                Variables: <code>{title}</code> <code>{keyword}</code> <code>{site_name}</code>
                            </span>
                            <span class="aab-seo-char-count" id="seo-title-counter"></span>
                        </div>
                    </div>

                    <div class="aab-seo-field">
                        <label for="aab-seo-description-template" class="aab-seo-label">
                            Meta Description Template
                            <span class="aab-seo-tooltip" title="Use variables: {title}, {keyword}, {excerpt}">
                                <span class="dashicons dashicons-info"></span>
                            </span>
                        </label>
                        <textarea name="aab_seo_description_template" 
                                  id="aab-seo-description-template" 
                                  rows="3" 
                                  placeholder="Learn about {keyword}. {excerpt}"
                                  class="aab-seo-textarea aab-seo-full-width"><?php echo esc_textarea($seo_description_template); ?></textarea>
                        <div class="aab-seo-field-footer">
                            <span class="aab-seo-hint">
                                Variables: <code>{title}</code> <code>{keyword}</code> <code>{excerpt}</code>
                            </span>
                            <span class="aab-seo-char-count" id="seo-desc-counter"></span>
                        </div>
                    </div>

                    <div class="aab-seo-field">
                        <label for="aab-seo-focus-keyword" class="aab-seo-label">
                            Focus Keyword (Optional)
                        </label>
                        <input type="text" 
                               name="aab_seo_focus_keyword" 
                               id="aab-seo-focus-keyword" 
                               value="<?php echo esc_attr($seo_focus_keyword); ?>"
                               placeholder="Leave empty to use campaign keyword"
                               class="aab-seo-text-input aab-seo-full-width">
                        <p class="aab-seo-description">
                            Override the campaign keyword for SEO plugins
                        </p>
                    </div>
                </div>

                <!-- Schema Markup Section -->
                <div class="aab-seo-subsection">
                    <div class="aab-seo-subsection-header">
                        <span class="dashicons dashicons-networking"></span>
                        <h3>Structured Data</h3>
                    </div>
                    
                    <div class="aab-seo-checkbox-field">
                        <label class="aab-seo-checkbox-label">
                            <input type="checkbox" 
                                   name="aab_seo_schema_enabled" 
                                   value="1" 
                                   <?php checked($seo_schema_enabled, true); ?>
                                   class="aab-seo-checkbox">
                            <span class="aab-seo-checkbox-text">
                                <strong>Enable Schema.org Article Markup</strong>
                                <span class="aab-seo-checkbox-desc">Add JSON-LD structured data for better Google understanding</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Internal Linking Section -->
                <div class="aab-seo-subsection">
                    <div class="aab-seo-subsection-header">
                        <span class="dashicons dashicons-admin-links"></span>
                        <h3>Internal Linking</h3>
                    </div>
                    
                    <div class="aab-seo-checkbox-field">
                        <label class="aab-seo-checkbox-label">
                            <input type="checkbox" 
                                   name="aab_seo_internal_links" 
                                   value="1" 
                                   <?php checked($seo_internal_links, true); ?>
                                   id="aab-seo-internal-links-toggle"
                                   class="aab-seo-checkbox">
                            <span class="aab-seo-checkbox-text">
                                <strong>Add Internal Links to Related Posts</strong>
                                <span class="aab-seo-checkbox-desc">Automatically link to related content based on categories and tags</span>
                            </span>
                        </label>
                    </div>

                    <div id="aab-seo-internal-links-config" style="<?php echo $seo_internal_links ? '' : 'display:none;'; ?>; margin-left: 30px; margin-top: 15px;">
                        <div class="aab-seo-field">
                            <label for="aab-seo-internal-links-count" class="aab-seo-label">
                                Number of Internal Links
                            </label>
                            <input type="number" 
                                   name="aab_seo_internal_links_count" 
                                   id="aab-seo-internal-links-count" 
                                   value="<?php echo esc_attr($seo_internal_links_count); ?>"
                                   min="1"
                                   max="10"
                                   class="aab-seo-number-input">
                            <p class="aab-seo-description">
                                Recommended: 2-4 links per post
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Social Media Section -->
                <div class="aab-seo-subsection">
                    <div class="aab-seo-subsection-header">
                        <span class="dashicons dashicons-share"></span>
                        <h3>Social Media & Images</h3>
                    </div>
                    
                    <div class="aab-seo-checkbox-field">
                        <label class="aab-seo-checkbox-label">
                            <input type="checkbox" 
                                   name="aab_seo_social_meta" 
                                   value="1" 
                                   <?php checked($seo_social_meta, true); ?>
                                   class="aab-seo-checkbox">
                            <span class="aab-seo-checkbox-text">
                                <strong>Add Social Media Meta Tags</strong>
                                <span class="aab-seo-checkbox-desc">Open Graph (Facebook/LinkedIn) and Twitter Card tags</span>
                            </span>
                        </label>
                    </div>

                    <div class="aab-seo-checkbox-field">
                        <label class="aab-seo-checkbox-label">
                            <input type="checkbox" 
                                   name="aab_seo_optimize_images" 
                                   value="1" 
                                   <?php checked($seo_optimize_images, true); ?>
                                   class="aab-seo-checkbox">
                            <span class="aab-seo-checkbox-text">
                                <strong>Optimize Image SEO</strong>
                                <span class="aab-seo-checkbox-desc">Automatically add alt text and title attributes</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="aab-seo-info-box">
                    <span class="dashicons dashicons-info-outline"></span>
                    <div class="aab-seo-info-content">
                        <strong>Compatibility Note:</strong>
                        <p>Works seamlessly with Yoast SEO, Rank Math, and All in One SEO. If no SEO plugin is installed, tags will be added directly to page headers.</p>
                    </div>
                </div>

            </div>

        </div>
        <!-- End SEO Settings Section -->

        <style>
        /* SEO Settings Styles */
        .aab-seo-settings-section {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .aab-seo-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .aab-seo-header-icon {
            flex-shrink: 0;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .aab-seo-header-icon .dashicons {
            font-size: 28px;
            width: 28px;
            height: 28px;
            color: white;
        }

        .aab-seo-header-content h2 {
            margin: 0 0 5px 0;
            font-size: 22px;
            font-weight: 600;
            color: #1e293b;
        }

        .aab-seo-header-content p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .aab-seo-toggle-row {
            margin-bottom: 25px;
        }

        .aab-seo-main-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
        }

        .aab-seo-checkbox-hidden {
            position: absolute !important;
            opacity: 0 !important;
            width: 0 !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            pointer-events: none !important;
            visibility: hidden !important;
        }

        .aab-seo-toggle-track {
            position: relative;
            width: 56px;
            height: 30px;
            background: #cbd5e1;
            border-radius: 30px;
            transition: background 0.3s;
            flex-shrink: 0;
        }

        .aab-seo-toggle-thumb {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 24px;
            height: 24px;
            background: white;
            border-radius: 50%;
            transition: transform 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .aab-seo-checkbox-hidden:checked + .aab-seo-toggle-track {
            background: #2563eb;
        }

        .aab-seo-checkbox-hidden:checked + .aab-seo-toggle-track .aab-seo-toggle-thumb {
            transform: translateX(26px);
        }

        .aab-seo-toggle-label {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }

        .aab-seo-description {
            margin: 8px 0 0 0;
            color: #64748b;
            font-size: 13px;
            font-style: italic;
        }

        .aab-seo-subsection {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        .aab-seo-subsection-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .aab-seo-subsection-header .dashicons {
            color: #2563eb;
            font-size: 22px;
            width: 22px;
            height: 22px;
        }

        .aab-seo-subsection-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #334155;
        }

        .aab-seo-field {
            margin-bottom: 20px;
        }

        .aab-seo-label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1e293b;
            font-size: 14px;
        }

        .aab-seo-tooltip {
            cursor: help;
            color: #64748b;
        }

        .aab-seo-tooltip .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }

        .aab-seo-text-input,
        .aab-seo-textarea,
        .aab-seo-number-input {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .aab-seo-full-width {
            width: 100%;
            max-width: 700px;
        }

        .aab-seo-text-input:focus,
        .aab-seo-textarea:focus,
        .aab-seo-number-input:focus {
            border-color: #2563eb;
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .aab-seo-number-input {
            width: 100px;
        }

        .aab-seo-textarea {
            resize: vertical;
        }

        .aab-seo-field-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .aab-seo-hint {
            color: #64748b;
            font-size: 12px;
        }

        .aab-seo-hint code {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            color: #dc2626;
            font-family: 'Courier New', monospace;
        }

        .aab-seo-char-count {
            font-size: 12px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
        }

        .aab-seo-char-count.good {
            background: #d1fae5;
            color: #065f46;
        }

        .aab-seo-char-count.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .aab-seo-char-count.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .aab-seo-checkbox-field {
            margin-bottom: 15px;
        }

        .aab-seo-checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
        }

        .aab-seo-checkbox {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .aab-seo-checkbox-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .aab-seo-checkbox-text strong {
            color: #1e293b;
            font-size: 14px;
        }

        .aab-seo-checkbox-desc {
            color: #64748b;
            font-size: 13px;
            font-weight: normal;
        }

        .aab-seo-info-box {
            display: flex;
            gap: 12px;
            padding: 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            margin-top: 20px;
        }

        .aab-seo-info-box .dashicons {
            color: #2563eb;
            font-size: 22px;
            width: 22px;
            height: 22px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .aab-seo-info-content strong {
            color: #1e40af;
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .aab-seo-info-content p {
            margin: 0;
            color: #1e40af;
            font-size: 13px;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            
            // Toggle SEO options panel
            $('#aab-seo-main-toggle').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#aab-seo-options-wrapper').slideDown(300);
                } else {
                    $('#aab-seo-options-wrapper').slideUp(300);
                }
            });
            
            // Toggle internal links configuration
            $('#aab-seo-internal-links-toggle').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#aab-seo-internal-links-config').slideDown(300);
                } else {
                    $('#aab-seo-internal-links-config').slideUp(300);
                }
            });
            
            // Character counter for title
            function updateTitleCounter() {
                var value = $('#aab-seo-title-template').val();
                var length = value.length;
                var $counter = $('#seo-title-counter');
                
                $counter.text(length + ' / 60');
                $counter.removeClass('good warning error');
                
                if (length === 0) {
                    $counter.text('');
                } else if (length > 60) {
                    $counter.addClass('error');
                } else if (length > 50) {
                    $counter.addClass('warning');
                } else {
                    $counter.addClass('good');
                }
            }
            
            $('#aab-seo-title-template').on('input', updateTitleCounter);
            updateTitleCounter();
            
            // Character counter for description
            function updateDescriptionCounter() {
                var value = $('#aab-seo-description-template').val();
                var length = value.length;
                var $counter = $('#seo-desc-counter');
                
                $counter.text(length + ' / 160');
                $counter.removeClass('good warning error');
                
                if (length === 0) {
                    $counter.text('');
                } else if (length > 160) {
                    $counter.addClass('error');
                } else if (length > 140) {
                    $counter.addClass('warning');
                } else {
                    $counter.addClass('good');
                }
            }
            
            $('#aab-seo-description-template').on('input', updateDescriptionCounter);
            updateDescriptionCounter();
            
        });
        </script>

    </form>
</div>

<!-- Keyword Suggest Configuration -->
<script>
    window.AAB_KW_SUGGEST = {
        ajax_url: "<?php echo esc_js(admin_url('admin-ajax.php')); ?>",
        nonce: "<?php echo esc_js(wp_create_nonce('aab_kw_suggest')); ?>"
    };
</script>

<!-- Load Keyword Suggest JS -->
<script src="<?php echo esc_url(AAB_URL . 'assets/admin/js/aab-kw-suggest.js'); ?>" defer></script>

<!-- Main JavaScript -->
<script>
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ===================================
        // STICKY SUBMIT BUTTON FIX
        // ===================================
        const stickySubmit = document.getElementById('aabStickySubmit');
        const form = document.getElementById('aab-campaign-form') || document.querySelector('form[method="post"]');

        if (stickySubmit && form) {
            stickySubmit.addEventListener('click', function(e) {
                e.preventDefault();
                form.submit();
            });
        }

        // Shadow on scroll
        const stickyBar = document.getElementById('aabStickyActions');

        if (stickyBar) {
            window.addEventListener('scroll', function() {
                if (window.scrollY > 10) {
                    stickyBar.classList.add('is-stuck');
                } else {
                    stickyBar.classList.remove('is-stuck');
                }
            });
        }

        // ===================================
        // IMAGE SETTINGS TOGGLE
        // ===================================
        const toggleBtn = document.getElementById('aab-toggle-image-settings');
        const imageContainer = document.getElementById('aab-image-settings-container');

        if (toggleBtn && imageContainer) {
            toggleBtn.addEventListener('click', function() {
                if (imageContainer.style.display === 'none') {
                    imageContainer.style.display = 'block';
                    imageContainer.style.animation = 'fadeIn 0.3s ease';
                    toggleBtn.textContent = '🖼️ Hide Image Settings';
                    toggleBtn.classList.remove('aab-btn-outline');
                    toggleBtn.classList.add('aab-btn-secondary');
                } else {
                    imageContainer.style.display = 'none';
                    toggleBtn.textContent = '🖼️ Show Image Settings';
                    toggleBtn.classList.remove('aab-btn-secondary');
                    toggleBtn.classList.add('aab-btn-outline');
                }
            });
        }

        // ===================================
        // KEYWORD MANAGEMENT
        // ===================================
        const keywordBox = document.getElementById('aab-keyword-box');
        const hiddenInput = document.getElementById('aab-keywords-input');
        const singleInput = document.getElementById('aab-single-keyword');
        const bulkInput = document.getElementById('aab-bulk-keywords');
        const addKeywordBtn = document.getElementById('aab-add-keyword');
        const addBulkBtn = document.getElementById('aab-add-bulk');

        let keywords = <?php echo json_encode(array_values($keywords)); ?>;

        function renderKeywords() {
            if (!keywordBox || !hiddenInput) return;
            
            keywordBox.innerHTML = '';
            
            if (keywords.length === 0) {
                keywordBox.innerHTML = '<div style="color: #94a3b8; padding: 20px; text-align: center;">No keywords added yet. Add keywords above to get started.</div>';
                hiddenInput.value = '';
                return;
            }
            
            keywords.forEach(function(kw, index) {
                const tag = document.createElement('span');
                tag.className = 'aab-keyword-tag';
                tag.innerHTML = kw + ' <span class="remove" data-index="' + index + '">×</span>';
                
                const removeBtn = tag.querySelector('.remove');
                if (removeBtn) {
                    removeBtn.onclick = function() {
                        keywords.splice(index, 1);
                        renderKeywords();
                    };
                }
                
                keywordBox.appendChild(tag);
            });
            
            hiddenInput.value = keywords.join('|');
        }

        function addKeyword(kw) {
            kw = kw.trim();
            if (!kw) return;
            keywords.push(kw);
            renderKeywords();
        }

        if (addKeywordBtn && singleInput) {
            addKeywordBtn.addEventListener('click', function() {
                addKeyword(singleInput.value);
                singleInput.value = '';
            });

            singleInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addKeyword(singleInput.value);
                    singleInput.value = '';
                }
            });
        }

        if (addBulkBtn && bulkInput) {
            addBulkBtn.addEventListener('click', function() {
                const lines = bulkInput.value.split('\n');
                lines.forEach(function(line) {
                    const trimmed = line.trim();
                    if (trimmed) {
                        addKeyword(trimmed);
                    }
                });
                bulkInput.value = '';
            });
        }

        // Initial render
        renderKeywords();

        // ===================================
        // CUSTOM PROMPT TOGGLE
        // ===================================
        const customPromptToggle = document.getElementById('aab-custom-prompt-toggle');
        const customPromptsDiv = document.getElementById('aab-custom-prompts');

        if (customPromptToggle && customPromptsDiv) {
            customPromptToggle.addEventListener('change', function() {
                customPromptsDiv.style.display = this.checked ? 'block' : 'none';
            });
        }

        // ===================================
        // SCHEDULING UI TOGGLES
        // ===================================
        const customPostTimeCheckbox = document.getElementById('aab_custom_post_time');
        const customTimeRow = document.getElementById('aab_custom_time_row');

        if (customPostTimeCheckbox && customTimeRow) {
            customPostTimeCheckbox.addEventListener('change', function() {
                customTimeRow.style.display = this.checked ? 'block' : 'none';
            });
        }

        // ===================================
        // AI CUSTOM PARAMS TOGGLE
        // ===================================
        const aiCustomParamsCheckbox = document.getElementById('aab_ai_custom_params');
        const aiCustomRow = document.getElementById('aab_ai_custom_row');

        if (aiCustomParamsCheckbox && aiCustomRow) {
            aiCustomParamsCheckbox.addEventListener('change', function() {
                aiCustomRow.style.display = this.checked ? 'block' : 'none';
            });
        }

        // ===================================
        // TOGGLE SWITCH CLICK HANDLERS
        // ===================================
        const checkboxWrappers = document.querySelectorAll('.aab-checkbox-wrapper');
        
        checkboxWrappers.forEach(function(wrapper) {
            const checkbox = wrapper.querySelector('input[type="checkbox"]');
            const toggle = wrapper.querySelector('.aab-toggle');
            const label = wrapper.querySelector('label');
            
            if (checkbox && toggle) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change'));
                });
                
                if (label) {
                    label.addEventListener('click', function(e) {
                        e.preventDefault();
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change'));
                    });
                }
            }
        });

        // ===================================
        // DALL·E SIZE ENFORCEMENT
        // ===================================
        function enforceSupportedDalleSizes() {
            const supported = ['256x256', '512x512', '1024x1024'];

            function replaceSelect(id) {
                const sel = document.getElementById(id);
                if (!sel) return;
                
                const current = sel.value;
                sel.innerHTML = '';
                
                supported.forEach(function(size) {
                    const opt = document.createElement('option');
                    opt.value = size;
                    opt.text = size;
                    sel.appendChild(opt);
                });
                
                if (supported.indexOf(current) !== -1) {
                    sel.value = current;
                } else {
                    sel.value = '1024x1024';
                }
            }

            replaceSelect('aab_feat_image_size');
            replaceSelect('aab_content_image_size');

            // Retry mechanism for dynamically loaded elements
            let attempts = 0;
            const maxAttempts = 10;
            const retryInterval = setInterval(function() {
                attempts++;
                replaceSelect('aab_feat_image_size');
                replaceSelect('aab_content_image_size');
                
                const featSelect = document.getElementById('aab_feat_image_size');
                const contentSelect = document.getElementById('aab_content_image_size');
                
                if (featSelect && contentSelect) {
                    clearInterval(retryInterval);
                }
                
                if (attempts >= maxAttempts) {
                    clearInterval(retryInterval);
                }
            }, 300);
        }

        // Execute DALL·E size enforcement
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(enforceSupportedDalleSizes, 50);
        } else {
            window.addEventListener('load', function() {
                setTimeout(enforceSupportedDalleSizes, 50);
            });
        }

        // ===================================
        // CATEGORY PICKER LOGIC
        // ===================================
        (function() {
            const textarea = document.getElementById('aab-categories-textarea');
            const picker = document.getElementById('aab-categories-picker');
            const setCatCheckbox = document.getElementById('aab_set_category');

            if (!textarea || !picker || !setCatCheckbox) return;

            const categories = <?php
                $all_terms = get_terms([
                    'taxonomy' => 'category',
                    'hide_empty' => false,
                ]);
                $list = [];
                if (!is_wp_error($all_terms) && is_array($all_terms)) {
                    foreach ($all_terms as $t) {
                        $list[] = [
                            'id' => intval($t->term_id), 
                            'name' => (string)$t->name, 
                            'slug' => (string)$t->slug
                        ];
                    }
                }
                echo json_encode($list);
            ?>;

            function showPicker() {
                if (!setCatCheckbox.checked) return;
                
                const rect = textarea.getBoundingClientRect();
                picker.style.left = (rect.left + window.pageXOffset) + 'px';
                picker.style.top = (rect.bottom + window.pageYOffset) + 'px';
                picker.style.width = Math.max(300, rect.width) + 'px';
                picker.style.display = 'block';
            }

            function hidePicker() {
                picker.style.display = 'none';
            }

            function getCurrentItems() {
                return textarea.value
                    .split(/[\r\n,|]+/)
                    .map(function(s) { return s.trim(); })
                    .filter(function(s) { return s.length > 0; });
            }

            function addCategory(name) {
                if (!setCatCheckbox.checked) return;
                
                const items = getCurrentItems();
                if (items.indexOf(name) === -1) {
                    items.push(name);
                    textarea.value = items.join("\n");
                }
            }

            function renderList(filter) {
                filter = filter || '';
                picker.innerHTML = '';
                
                const frag = document.createDocumentFragment();
                const q = filter.trim().toLowerCase();
                
                categories.forEach(function(cat) {
                    const nameMatch = cat.name.toLowerCase().indexOf(q) !== -1;
                    const slugMatch = cat.slug.indexOf(q) !== -1;
                    const idMatch = String(cat.id).indexOf(q) !== -1;
                    
                    if (q && !nameMatch && !slugMatch && !idMatch) return;
                    
                    const el = document.createElement('div');
                    el.textContent = cat.name + ' (id:' + cat.id + ')';
                    el.dataset.catName = cat.name;
                    el.dataset.catId = cat.id;
                    el.onclick = function() {
                        addCategory(this.dataset.catName);
                    };
                    frag.appendChild(el);
                });
                
                if (!frag.childNodes.length) {
                    const none = document.createElement('div');
                    none.style.cssText = 'padding:8px;color:#666';
                    none.textContent = 'No categories found';
                    picker.appendChild(none);
                } else {
                    picker.appendChild(frag);
                }
            }

            // Event listeners for category picker
            textarea.addEventListener('focus', function() {
                if (!setCatCheckbox.checked) return;
                renderList('');
                showPicker();
            });
            
            textarea.addEventListener('click', function() {
                if (!setCatCheckbox.checked) return;
                renderList('');
                showPicker();
            });

            textarea.addEventListener('input', function() {
                if (!setCatCheckbox.checked) return;
                
                const lines = getCurrentItems();
                const last = lines.length ? lines[lines.length - 1] : '';
                renderList(last);
            });

            document.addEventListener('click', function(e) {
                if (!picker.contains(e.target) && e.target !== textarea) {
                    hidePicker();
                }
            });

            setCatCheckbox.addEventListener('change', function() {
                textarea.disabled = !this.checked;
                if (!this.checked) {
                    hidePicker();
                } else {
                    renderList('');
                }
            });

            // Initial state
            textarea.disabled = !setCatCheckbox.checked;
            renderList('');
        })();

    }); // End DOMContentLoaded

})(); // End IIFE
</script>