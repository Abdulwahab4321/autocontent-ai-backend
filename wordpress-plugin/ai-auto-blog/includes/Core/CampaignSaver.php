<?php

namespace AAB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CampaignSaver
 * Handles create/update/delete/toggle/run_now for campaigns.
 *
 * Extended to save image-related campaign meta and to redirect back to
 * the editor when a campaign was edited via the campaign-new page.
 */
class CampaignSaver
{

    public static function init()
    {
        add_action('admin_init', [self::class, 'save']);
        add_action('admin_init', [self::class, 'handle_delete']);
        add_action('admin_init', [self::class, 'handle_toggle_enable']);
        add_action('admin_init', [self::class, 'handle_run_now']);
    }

    /**
     * Handle create / update of campaigns (form submit).
     */
    public static function save()
    {

        // Only proceed when the campaign form is submitted
        if (empty($_POST['campaign_name'])) {
            return;
        }

        // Capability & nonce checks
        if (!current_user_can('manage_options')) {
            return;
        }
        if (empty($_POST['_wpnonce'])) {
            return;
        }
        if (!check_admin_referer('aab_save_campaign')) {
            return;
        }

        // Prepare post args
        $campaign_id = isset($_POST['campaign_id']) && intval($_POST['campaign_id']) ? intval($_POST['campaign_id']) : 0;

        $post_args = [
            'post_type'   => 'aab_campaign',
            'post_title'  => sanitize_text_field(wp_unslash($_POST['campaign_name'])),
            'post_status' => 'publish',
        ];

        if ($campaign_id) {
            $post_args['ID'] = $campaign_id;
            $res = wp_update_post($post_args, true);
            if (is_wp_error($res)) return;
            $campaign_id = intval($res);
        } else {
            $res = wp_insert_post($post_args, true);
            if (is_wp_error($res)) return;
            $campaign_id = intval($res);
        }

        if (!$campaign_id) return;

        // Keywords (pipe-separated hidden input) - keep duplicates and order
        $keywords = [];
        if (!empty($_POST['aab_keywords'])) {
            $raw = explode('|', wp_unslash($_POST['aab_keywords']));
            $keywords = array_map('sanitize_text_field', $raw);
        }
        update_post_meta($campaign_id, 'aab_keywords', $keywords);

        // Numeric and basic fields
        update_post_meta($campaign_id, 'min_words', isset($_POST['min_words']) ? intval($_POST['min_words']) : 0);
        update_post_meta($campaign_id, 'max_words', isset($_POST['max_words']) ? intval($_POST['max_words']) : 0);
        update_post_meta($campaign_id, 'max_posts', isset($_POST['max_posts']) ? intval($_POST['max_posts']) : 0);

        // Basic options
        update_post_meta($campaign_id, 'rotate_keywords', isset($_POST['rotate_keywords']) ? 1 : 0);
        update_post_meta($campaign_id, 'keyword_as_title', isset($_POST['keyword_as_title']) ? 1 : 0);
        update_post_meta($campaign_id, 'one_post_per_keyword', isset($_POST['one_post_per_keyword']) ? 1 : 0);
        update_post_meta($campaign_id, 'use_custom_prompt', isset($_POST['use_custom_prompt']) ? 1 : 0);

        // Custom prompts
        update_post_meta($campaign_id, 'custom_title_prompt', isset($_POST['custom_title_prompt']) ? sanitize_text_field(wp_unslash($_POST['custom_title_prompt'])) : '');
        update_post_meta($campaign_id, 'custom_content_prompt', isset($_POST['custom_content_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['custom_content_prompt'])) : '');

        // --- Scheduling fields ---
        update_post_meta($campaign_id, 'aab_run_interval', isset($_POST['aab_run_interval']) ? intval($_POST['aab_run_interval']) : 0);
        update_post_meta($campaign_id, 'aab_run_unit', isset($_POST['aab_run_unit']) ? sanitize_text_field(wp_unslash($_POST['aab_run_unit'])) : 'minutes');

        update_post_meta($campaign_id, 'aab_pause_autorun', isset($_POST['aab_pause_autorun']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_custom_post_time', isset($_POST['aab_custom_post_time']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_custom_post_time_value', isset($_POST['aab_custom_post_time_value']) ? sanitize_text_field(wp_unslash($_POST['aab_custom_post_time_value'])) : '');
        update_post_meta($campaign_id, 'aab_show_external_cron', isset($_POST['aab_show_external_cron']) ? 1 : 0);

        // -------------------------
        // Content Settings Fields ---
        // (these were already present; keep them)
        update_post_meta($campaign_id, 'aab_alt_from_title_all', isset($_POST['aab_alt_from_title_all']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_alt_from_title_empty', isset($_POST['aab_alt_from_title_empty']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_remove_links', isset($_POST['aab_remove_links']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_links_new_tab', isset($_POST['aab_links_new_tab']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_links_nofollow', isset($_POST['aab_links_nofollow']) ? 1 : 0);

        // -------------------------
        // NEW: Post Settings (saved so CampaignRunner can use them)
        // - post type (post/page)
        // - post status (pending, draft, publish)
        // - post author (user ID)
        // - set category flag and categories list
        // -------------------------
        // Post type and status (sanitized)
        $post_type = isset($_POST['aab_post_type']) ? sanitize_text_field(wp_unslash($_POST['aab_post_type'])) : 'post';
        if ($post_type !== 'post' && $post_type !== 'page') $post_type = 'post';
        update_post_meta($campaign_id, 'aab_post_type', $post_type);

        $post_status = isset($_POST['aab_post_status']) ? sanitize_text_field(wp_unslash($_POST['aab_post_status'])) : 'draft';
        $allowed_status = ['pending','draft','publish'];
        if (!in_array($post_status, $allowed_status, true)) $post_status = 'draft';
        update_post_meta($campaign_id, 'aab_post_status', $post_status);

        // Author: store user ID if valid user, otherwise 0
        $author_id = isset($_POST['aab_post_author']) ? intval($_POST['aab_post_author']) : 0;
        if ($author_id && get_userdata($author_id)) {
            update_post_meta($campaign_id, 'aab_post_author', $author_id);
        } else {
            update_post_meta($campaign_id, 'aab_post_author', 0);
        }

        // Category handling: set flag + categories textarea parsing
        update_post_meta($campaign_id, 'aab_set_category', isset($_POST['aab_set_category']) ? 1 : 0);

        // Normalize categories input: accept IDs, names, slugs separated by newline/comma/pipe
        $raw_cats = isset($_POST['aab_categories']) ? wp_unslash($_POST['aab_categories']) : '';
        $cat_ids = [];

        if (is_string($raw_cats) && trim($raw_cats) !== '') {
            // Split on newlines, commas, pipes
            $parts = preg_split('/[\r\n,|]+/', $raw_cats);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;

                // numeric -> assume term ID
                if (ctype_digit($p)) {
                    $tid = intval($p);
                    if (term_exists($tid, 'category')) {
                        $cat_ids[] = $tid;
                        continue;
                    }
                }

                // try by name
                $term = get_term_by('name', $p, 'category');
                if ($term && !is_wp_error($term)) {
                    $cat_ids[] = intval($term->term_id);
                    continue;
                }

                // try by slug (sanitized)
                $slug = sanitize_title($p);
                $term2 = get_term_by('slug', $slug, 'category');
                if ($term2 && !is_wp_error($term2)) {
                    $cat_ids[] = intval($term2->term_id);
                    continue;
                }

                // fallback: if it's not found, skip (we don't create categories automatically)
            }
        }

        // Remove duplicates and ensure ints
        $cat_ids = array_values(array_filter(array_map('intval', array_unique($cat_ids))));
        update_post_meta($campaign_id, 'aab_categories', $cat_ids);

        // -------------------------
        // NEW: AI Settings (campaign-level override)
        // -------------------------
        update_post_meta($campaign_id, 'aab_ai_custom_params', isset($_POST['aab_ai_custom_params']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_ai_max_tokens', isset($_POST['aab_ai_max_tokens']) ? intval($_POST['aab_ai_max_tokens']) : 0);
        update_post_meta($campaign_id, 'aab_ai_temperature', isset($_POST['aab_ai_temperature']) ? floatval($_POST['aab_ai_temperature']) : 0.0);

        // Enabled toggle
        update_post_meta($campaign_id, 'aab_enabled', isset($_POST['aab_enabled']) ? 1 : 0);

        // Ensure last run meta exists
        if (get_post_meta($campaign_id, 'aab_last_run', true) === '') {
            update_post_meta($campaign_id, 'aab_last_run', 0);
        }

        // -------------------------
        // SEO Settings (NEW)
        // -------------------------
        update_post_meta($campaign_id, 'aab_seo_enabled', isset($_POST['aab_seo_enabled']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_seo_title_template', isset($_POST['aab_seo_title_template']) ? sanitize_text_field(wp_unslash($_POST['aab_seo_title_template'])) : '');
        update_post_meta($campaign_id, 'aab_seo_description_template', isset($_POST['aab_seo_description_template']) ? sanitize_textarea_field(wp_unslash($_POST['aab_seo_description_template'])) : '');
        update_post_meta($campaign_id, 'aab_seo_focus_keyword', isset($_POST['aab_seo_focus_keyword']) ? sanitize_text_field(wp_unslash($_POST['aab_seo_focus_keyword'])) : '');
        update_post_meta($campaign_id, 'aab_seo_schema_enabled', isset($_POST['aab_seo_schema_enabled']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_seo_internal_links', isset($_POST['aab_seo_internal_links']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_seo_internal_links_count', isset($_POST['aab_seo_internal_links_count']) ? intval($_POST['aab_seo_internal_links_count']) : 3);
        update_post_meta($campaign_id, 'aab_seo_social_meta', isset($_POST['aab_seo_social_meta']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_seo_optimize_images', isset($_POST['aab_seo_optimize_images']) ? 1 : 0);

        // -------------------------
        // Save Image Integration meta (featured / content / optimization / youtube)
        // -------------------------
        // Featured image settings
        update_post_meta($campaign_id, 'aab_feat_generate', isset($_POST['aab_feat_generate']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_feat_image_method', isset($_POST['aab_feat_image_method']) ? sanitize_text_field(wp_unslash($_POST['aab_feat_image_method'])) : 'dalle');
        update_post_meta($campaign_id, 'aab_feat_image_size', isset($_POST['aab_feat_image_size']) ? sanitize_text_field(wp_unslash($_POST['aab_feat_image_size'])) : '1024x1024');
        update_post_meta($campaign_id, 'aab_feat_set_first_as_featured', isset($_POST['aab_feat_set_first_as_featured']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_feat_get_original_from_data', isset($_POST['aab_feat_get_original_from_data']) ? 1 : 0);

        // Content image settings
        update_post_meta($campaign_id, 'aab_content_image_method', isset($_POST['aab_content_image_method']) ? sanitize_text_field(wp_unslash($_POST['aab_content_image_method'])) : 'dalle');
        update_post_meta($campaign_id, 'aab_content_image_size', isset($_POST['aab_content_image_size']) ? sanitize_text_field(wp_unslash($_POST['aab_content_image_size'])) : '1024x1024');
        update_post_meta($campaign_id, 'aab_get_image_by_title', isset($_POST['aab_get_image_by_title']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_get_image_by_prompt', isset($_POST['aab_get_image_by_prompt']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_content_custom_prompt', isset($_POST['aab_content_custom_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['aab_content_custom_prompt'])) : '');
        update_post_meta($campaign_id, 'aab_set_keyword_as_alt', isset($_POST['aab_set_keyword_as_alt']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_search_by_parent_keyword', isset($_POST['aab_search_by_parent_keyword']) ? 1 : 0);

        $num_images = isset($_POST['aab_content_num_images']) ? intval($_POST['aab_content_num_images']) : 1;
        $num_images = min(3, max(1, $num_images));
        update_post_meta($campaign_id, 'aab_content_num_images', $num_images);

        update_post_meta($campaign_id, 'aab_content_dist', isset($_POST['aab_content_dist']) ? sanitize_text_field(wp_unslash($_POST['aab_content_dist'])) : 'fixed');
        update_post_meta($campaign_id, 'aab_content_pos_1', isset($_POST['aab_content_pos_1']) ? sanitize_text_field(wp_unslash($_POST['aab_content_pos_1'])) : 'top');
        update_post_meta($campaign_id, 'aab_content_pos_2', isset($_POST['aab_content_pos_2']) ? sanitize_text_field(wp_unslash($_POST['aab_content_pos_2'])) : 'middle');
        update_post_meta($campaign_id, 'aab_content_pos_3', isset($_POST['aab_content_pos_3']) ? sanitize_text_field(wp_unslash($_POST['aab_content_pos_3'])) : 'bottom');
        update_post_meta($campaign_id, 'aab_content_wp_image_size', isset($_POST['aab_content_wp_image_size']) ? sanitize_text_field(wp_unslash($_POST['aab_content_wp_image_size'])) : 'full');

        // Compression & YouTube toggles
        update_post_meta($campaign_id, 'aab_image_quality_processing', isset($_POST['aab_image_quality_processing']) ? 1 : 0);
        update_post_meta($campaign_id, 'aab_enable_youtube', isset($_POST['aab_enable_youtube']) ? 1 : 0);

        // -------------------------
        // END image meta save
        // -------------------------

        // Schedule / unschedule based on new settings
        if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
            CampaignScheduler::schedule_or_unschedule($campaign_id);
        }

        // If the form came from the campaign editor page, redirect back to edit so user sees saved state.
        if (isset($_POST['aab_from_page']) && sanitize_text_field(wp_unslash($_POST['aab_from_page'])) === 'campaign_new') {
            wp_redirect(admin_url('admin.php?page=aab-new-campaign&edit=' . $campaign_id));
            exit;
        }

        // Otherwise redirect back to campaigns list
        wp_redirect(admin_url('admin.php?page=aab-campaigns'));
        exit;
    }

    /**
     * Handle deletion via admin link.
     * URL: admin.php?page=aab-campaigns&action=delete&id={ID}&_wpnonce={nonce}
     */
    public static function handle_delete()
    {
        if (empty($_GET['action']) || $_GET['action'] !== 'delete') return;
        if (empty($_GET['id'])) return;
        if (!current_user_can('manage_options')) return;

        $campaign_id = intval($_GET['id']);
        if (!$campaign_id) return;

        if (empty($_REQUEST['_wpnonce'])) return;
        if (!check_admin_referer('aab_delete_campaign_' . $campaign_id)) return;

        // Clear scheduled events and delete
        if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
            wp_clear_scheduled_hook('aab_campaign_run', [$campaign_id]);
        }

        wp_delete_post($campaign_id, true);

        wp_redirect(admin_url('admin.php?page=aab-campaigns'));
        exit;
    }

    /**
     * Toggle enable/disable campaign
     * URL: admin.php?page=aab-campaigns&action=toggle_enable&id={ID}&_wpnonce={nonce}
     */
    public static function handle_toggle_enable()
    {
        if (empty($_GET['action']) || $_GET['action'] !== 'toggle_enable') return;
        if (empty($_GET['id'])) return;
        if (!current_user_can('manage_options')) return;

        $campaign_id = intval($_GET['id']);
        if (!$campaign_id) return;

        if (empty($_REQUEST['_wpnonce'])) return;
        if (!check_admin_referer('aab_toggle_enable_' . $campaign_id)) return;

        $enabled = get_post_meta($campaign_id, 'aab_enabled', true) ? 1 : 0;
        $new = $enabled ? 0 : 1;
        update_post_meta($campaign_id, 'aab_enabled', $new);

        if ($new && class_exists('\\AAB\\Core\\CampaignScheduler')) {
            CampaignScheduler::schedule_or_unschedule($campaign_id);
        } else {
            wp_clear_scheduled_hook('aab_campaign_run', [$campaign_id]);
        }

        wp_redirect(admin_url('admin.php?page=aab-campaigns'));
        exit;
    }

    /**
     * Handle manual "Run Now" action from All Campaigns list.
     * URL: admin.php?page=aab-campaigns&action=run_now&id={ID}&_wpnonce={nonce}
     */
    public static function handle_run_now()
    {
        if (empty($_GET['action']) || $_GET['action'] !== 'run_now') return;
        if (empty($_GET['id'])) return;
        if (!current_user_can('manage_options')) return;

        $campaign_id = intval($_GET['id']);
        if (!$campaign_id) return;

        if (empty($_REQUEST['_wpnonce'])) return;
        if (!check_admin_referer('aab_run_now_' . $campaign_id)) return;

        // Run immediately via CampaignRunner
        if (class_exists('\\AAB\\Core\\CampaignRunner')) {
            CampaignRunner::run($campaign_id);
        }

        wp_redirect(admin_url('admin.php?page=aab-campaigns'));
        exit;
    }
}