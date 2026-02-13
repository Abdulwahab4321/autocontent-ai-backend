<?php

namespace AAB\Extensions;

if (!defined('ABSPATH')) exit;

/**
 * ImageIntegration
 *
 * Same behavior as before but with extensive debug logging at all decision points so we can
 * trace why images are or aren't generated/attached.
 *
 * NOTE: generate_image_via_openai() updated to accept $model and remove the unsupported 'response_format' parameter
 * (some OpenAI image endpoints reject that parameter). The code still handles both base64 and URL
 * responses from the provider.
 */
class ImageIntegration
{

    public static function init()
    {
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);
        add_action('admin_footer', [self::class, 'inject_ui_html']);
        add_action('save_post_aab_campaign', [self::class, 'save_campaign_image_meta'], 20, 3);
        add_action('save_post', [self::class, 'handle_post_after_insert'], 20, 2);
    }

    public static function admin_assets($hook)
    {
        $screen = get_current_screen();
        if (!$screen) {
            // error_log('AAB ImageIntegration: admin_assets() - no screen, returning');
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== 'aab-new-campaign') {
            // error_log('AAB ImageIntegration: admin_assets() - not on aab-new-campaign page, returning');
            return;
        }

        wp_enqueue_style('aab-image-integration', AAB_URL . 'includes/Extensions/css/image-integration.css', [], '0.3.0');
        wp_enqueue_script('aab-image-integration', AAB_URL . 'includes/Extensions/js/image-integration.js', ['jquery'], '0.3.0', true);

        $campaign_id = intval($_GET['edit'] ?? 0);
        $data = [
            'campaign_id' => $campaign_id,
            'feat_generate' => intval(get_post_meta($campaign_id, 'aab_feat_generate', true) ?: 0),
            'feat_method' => get_post_meta($campaign_id, 'aab_feat_image_method', true) ?: 'dalle',
            'feat_model' => get_post_meta($campaign_id, 'aab_feat_image_model', true) ?: 'dall-e-3',
            'feat_set_first' => intval(get_post_meta($campaign_id, 'aab_feat_set_first_as_featured', true) ?: 0),
            'feat_get_original' => intval(get_post_meta($campaign_id, 'aab_feat_get_original_from_data', true) ?: 0),
            'feat_size' => get_post_meta($campaign_id, 'aab_feat_image_size', true) ?: '1024x1024',

            // NEW featured prompt flags
            'feat_get_by_prompt' => intval(get_post_meta($campaign_id, 'aab_feat_get_image_by_prompt', true) ?: 0),
            'feat_custom_prompt' => get_post_meta($campaign_id, 'aab_feat_custom_prompt', true) ?: '',

            'content_method' => get_post_meta($campaign_id, 'aab_content_image_method', true) ?: 'dalle',
            'content_model' => get_post_meta($campaign_id, 'aab_content_image_model', true) ?: 'dall-e-3',
            'content_size' => get_post_meta($campaign_id, 'aab_content_image_size', true) ?: '1024x1024',
            'content_num' => intval(get_post_meta($campaign_id, 'aab_content_num_images', true) ?: 1),
            'content_dist' => get_post_meta($campaign_id, 'aab_content_dist', true) ?: 'fixed',
            'content_pos_1' => get_post_meta($campaign_id, 'aab_content_pos_1', true) ?: 'top',
            'content_pos_2' => get_post_meta($campaign_id, 'aab_content_pos_2', true) ?: 'middle',
            'content_pos_3' => get_post_meta($campaign_id, 'aab_content_pos_3', true) ?: 'bottom',
            'content_size_choice' => get_post_meta($campaign_id, 'aab_content_wp_image_size', true) ?: 'full',
            'get_by_title' => intval(get_post_meta($campaign_id, 'aab_get_image_by_title', true) ?: 0),
            'get_by_prompt' => intval(get_post_meta($campaign_id, 'aab_get_image_by_prompt', true) ?: 0),
            'custom_prompt' => get_post_meta($campaign_id, 'aab_content_custom_prompt', true) ?: '',
            'set_alt_keyword' => intval(get_post_meta($campaign_id, 'aab_set_keyword_as_alt', true) ?: 0),
            'search_by_parent_keyword' => intval(get_post_meta($campaign_id, 'aab_search_by_parent_keyword', true) ?: 0),
            'quality_proc' => intval(get_post_meta($campaign_id, 'aab_image_quality_processing', true) ?: 0),
            'enable_youtube' => intval(get_post_meta($campaign_id, 'aab_enable_youtube', true) ?: 0),
            // Content settings (existing/new)
            'alt_from_title_all' => intval(get_post_meta($campaign_id, 'aab_alt_from_title_all', true) ?: 0),
            'alt_from_title_empty' => intval(get_post_meta($campaign_id, 'aab_alt_from_title_empty', true) ?: 0),
            'remove_links' => intval(get_post_meta($campaign_id, 'aab_remove_links', true) ?: 0),
            'links_new_tab' => intval(get_post_meta($campaign_id, 'aab_links_new_tab', true) ?: 0),
            'links_nofollow' => intval(get_post_meta($campaign_id, 'aab_links_nofollow', true) ?: 0),

            'wp_image_sizes' => array_values(self::get_wp_image_sizes()),
        ];

        wp_localize_script('aab-image-integration', 'AABImageOpts', $data);
        // error_log('AAB ImageIntegration: admin_assets() - localized script for campaign ' . $campaign_id);
    }

    public static function inject_ui_html()
    {
        if (empty($_GET['page']) || $_GET['page'] !== 'aab-new-campaign') {
            return;
        }
        $campaign_id = intval($_GET['edit'] ?? 0);

        $feat_generate = intval(get_post_meta($campaign_id, 'aab_feat_generate', true) ?: 0);
        $feat_method = get_post_meta($campaign_id, 'aab_feat_image_method', true) ?: 'dalle';
        $feat_model = get_post_meta($campaign_id, 'aab_feat_image_model', true) ?: 'dall-e-3';
        $feat_size = get_post_meta($campaign_id, 'aab_feat_image_size', true) ?: '1024x1024';
        $feat_set_first = intval(get_post_meta($campaign_id, 'aab_feat_set_first_as_featured', true) ?: 0);
        $feat_get_original = intval(get_post_meta($campaign_id, 'aab_feat_get_original_from_data', true) ?: 0);

        // NEW featured prompt values
        $feat_get_by_prompt = intval(get_post_meta($campaign_id, 'aab_feat_get_image_by_prompt', true) ?: 0);
        $feat_custom_prompt = esc_textarea(get_post_meta($campaign_id, 'aab_feat_custom_prompt', true) ?: '');

        $content_method = get_post_meta($campaign_id, 'aab_content_image_method', true) ?: 'dalle';
        $content_model = get_post_meta($campaign_id, 'aab_content_image_model', true) ?: 'dall-e-3';
        $content_size = get_post_meta($campaign_id, 'aab_content_image_size', true) ?: '1024x1024';
        $get_by_title = intval(get_post_meta($campaign_id, 'aab_get_image_by_title', true) ?: 0);
        $get_by_prompt = intval(get_post_meta($campaign_id, 'aab_get_image_by_prompt', true) ?: 0);
        $content_custom_prompt = esc_textarea(get_post_meta($campaign_id, 'aab_content_custom_prompt', true) ?: '');
        $set_keyword_alt = intval(get_post_meta($campaign_id, 'aab_set_keyword_as_alt', true) ?: 0);
        $search_by_parent_keyword = intval(get_post_meta($campaign_id, 'aab_search_by_parent_keyword', true) ?: 0);
        $content_num = intval(get_post_meta($campaign_id, 'aab_content_num_images', true) ?: 1);
        $content_dist = get_post_meta($campaign_id, 'aab_content_dist', true) ?: 'fixed';
        $content_pos_1 = get_post_meta($campaign_id, 'aab_content_pos_1', true) ?: 'top';
        $content_pos_2 = get_post_meta($campaign_id, 'aab_content_pos_2', true) ?: 'middle';
        $content_pos_3 = get_post_meta($campaign_id, 'aab_content_pos_3', true) ?: 'bottom';
        $content_wp_image_size = get_post_meta($campaign_id, 'aab_content_wp_image_size', true) ?: 'full';
        $quality_proc = intval(get_post_meta($campaign_id, 'aab_image_quality_processing', true) ?: 0);
        $enable_youtube = intval(get_post_meta($campaign_id, 'aab_enable_youtube', true) ?: 0);

        // Content settings (for UI rendering if needed)
        $alt_from_title_all = intval(get_post_meta($campaign_id, 'aab_alt_from_title_all', true) ?: 0);
        $alt_from_title_empty = intval(get_post_meta($campaign_id, 'aab_alt_from_title_empty', true) ?: 0);
        $remove_links = intval(get_post_meta($campaign_id, 'aab_remove_links', true) ?: 0);
        $links_new_tab = intval(get_post_meta($campaign_id, 'aab_links_new_tab', true) ?: 0);
        $links_nofollow = intval(get_post_meta($campaign_id, 'aab_links_nofollow', true) ?: 0);

        $wp_sizes = self::get_wp_image_sizes();
?>
<style>
/* ────────────────────────────────────────────────
   Modern & Clean Image Settings - Matching Reference Style
   ──────────────────────────────────────────────── */

    .aab-image-settings-container {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        overflow: hidden;
        margin: 20px 0;
    }

    .aab-image-settings-header {
        background: #f8fafc;
        padding: 18px 24px;
        border-bottom: 1px solid #e2e8f0;
    }

    .aab-image-settings-header h2 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 600;
        color: #1e293b;
    }

    /* Sub-sections (Featured & Content) */
    .aab-image-subsection {
        padding: 24px;
        border-bottom: 1px solid #f1f5f9;
    }

    .aab-image-subsection:last-child {
        border-bottom: none;
    }

    .aab-image-subsection h3 {
        margin: 0 0 16px;
        font-size: 1.15rem;
        font-weight: 600;
        color: #334155;
    }

    /* Form rows */
    .aab-form-row {
        margin-bottom: 20px;
    }

    .aab-form-row label {
        display: block;
        font-weight: 500;
        margin-bottom: 8px;
        color: #334155;
        font-size: 0.95rem;
    }

    .aab-form-row select,
    .aab-form-row input[type="text"],
    .aab-form-row textarea {
        width: 100%;
        max-width: 420px;
        padding: 10px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.95rem;
        background: #ffffff;
        transition: all 0.2s ease;
    }

    .aab-form-row select:focus,
    .aab-form-row input[type="text"]:focus,
    .aab-form-row textarea:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        outline: none;
    }

    .aab-form-row textarea {
        resize: vertical;
        min-height: 80px;
    }

    /* Modern Toggle Switch */
    .aab-toggle-container {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 12px 0;
    }

    .aab-toggle-label {
        position: relative;
        display: inline-block;
        width: 48px;
        height: 26px;
        cursor: pointer;
    }

    .aab-toggle-input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .aab-toggle-slider {
        position: absolute;
        inset: 0;
        background-color: #cbd5e1;
        border-radius: 26px;
        transition: .3s;
    }

    .aab-toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        border-radius: 50%;
        transition: .3s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    .aab-toggle-input:checked + .aab-toggle-slider {
        background-color: #3b82f6;
    }

    .aab-toggle-input:checked + .aab-toggle-slider:before {
        transform: translateX(22px);
    }

    /* Checkbox group (multiple inline) */
    .aab-checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin: 12px 0;
    }

    .aab-checkbox-group label {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 400;
        color: #374151;
    }

    /* Notes / muted text */
    .muted {
        color: #6b7280;
        font-size: 0.9rem;
        margin-top: 6px;
        display: block;
    }

    .muted code {
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 4px;
    }

    /* Hide featured settings when toggle is off */
    .aab-featured-settings {
        transition: opacity 0.3s ease, max-height 0.3s ease;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .aab-image-subsection {
            padding: 18px;
        }
        .aab-form-row select,
        .aab-form-row input[type="text"],
        .aab-form-row textarea {
            max-width: 100%;
        }
    }
</style>

<template id="aab-image-settings-template">
    <div class="aab-image-settings-container">

        <div class="aab-image-settings-header">
            <h2>Image Settings</h2>
        </div>

        <!-- Featured Image Settings -->
        <div class="aab-image-subsection">
            <h3>Featured Image</h3>

            <div class="aab-form-row">
                <div class="aab-toggle-container">
                    <label class="aab-toggle-label">
                        <input type="checkbox" class="aab-toggle-input" name="aab_feat_generate" id="aab_feat_generate" <?php checked($feat_generate); ?>>
                        <span class="aab-toggle-slider"></span>
                    </label>
                    <span>Generate Featured Image</span>
                </div>
            </div>

            <!-- Wrap all featured settings in a container -->
            <div class="aab-featured-settings" id="aab_featured_settings" style="display: <?php echo $feat_generate ? 'block' : 'none'; ?>;">
                <div class="aab-form-row">
                    <label for="aab_feat_image_method">Image Extraction Method</label>
                    <select name="aab_feat_image_method" id="aab_feat_image_method">
                        <option value="dalle" <?php selected($feat_method ?? 'dalle', 'dalle'); ?>>Dall-e (default)</option>
                    </select>
                </div>

                <div class="aab-form-row">
                    <label for="aab_feat_image_model">Select Image Model</label>
                    <select name="aab_feat_image_model" id="aab_feat_image_model">
                        <option value="dall-e-3" <?php selected($feat_model ?? 'dall-e-3', 'dall-e-3'); ?>>DALL·E 3</option>
                        <option value="dall-e-2" <?php selected($feat_model, 'dall-e-2'); ?>>DALL·E 2</option>
                        <option value="gpt-image-1" <?php selected($feat_model, 'gpt-image-1'); ?>>gpt-image-1</option>
                        <option value="gpt-image-1.5" <?php selected($feat_model, 'gpt-image-1.5'); ?>>gpt-image-1.5</option>
                        <option value="gpt-image-1-mini" <?php selected($feat_model, 'gpt-image-1-mini'); ?>>gpt-image-1-mini</option>
                    </select>
                    <span class="muted" id="aab_feat_model_note" style="display:<?php echo (stripos($feat_model ?? '', 'gpt-image') === 0) ? 'block' : 'none'; ?>">
                        Your organization must be verified to use <code><?php echo esc_html($feat_model ?? ''); ?></code>.<br>
                        <a href="https://platform.openai.com/settings/organization/general" target="_blank">Verify here →</a> (may take up to 15 min)
                    </span>
                </div>

                <div class="aab-form-row">
                    <label for="aab_feat_image_size">Select Image Size</label>
                    <select name="aab_feat_image_size" id="aab_feat_image_size">
                        <option value="1024x1024" <?php selected($feat_size ?? '1024x1024', '1024x1024'); ?>>1024×1024</option>
                        <option value="512x512" <?php selected($feat_size, '512x512'); ?>>512×512</option>
                        <option value="256x256" <?php selected($feat_size, '256x256'); ?>>256×256</option>
                    </select>
                </div>

                <div class="aab-form-row">
                    <div class="aab-toggle-container">
                        <label class="aab-toggle-label">
                            <input type="checkbox" class="aab-toggle-input" name="aab_feat_get_image_by_prompt" id="aab_feat_get_image_by_prompt" <?php checked($feat_get_by_prompt); ?>>
                            <span class="aab-toggle-slider"></span>
                        </label>
                        <span>Get Featured Image By Prompt</span>
                    </div>
                </div>

                <div class="aab-form-row" id="aab_feat_custom_prompt_row" style="<?php echo $feat_get_by_prompt ? '' : 'display:none;'; ?>">
                    <label for="aab_feat_custom_prompt">Featured Custom Prompt</label>
                    <textarea name="aab_feat_custom_prompt" id="aab_feat_custom_prompt" rows="3"><?php echo esc_textarea($feat_custom_prompt ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Content Image Settings -->
        <div class="aab-image-subsection">
            <h3>Content Images</h3>

            <div class="aab-form-row">
                <label for="aab_content_image_method">Image Extraction Method</label>
                <select name="aab_content_image_method" id="aab_content_image_method">
                    <option value="dalle" <?php selected($content_method ?? 'dalle', 'dalle'); ?>>Dall-e (default)</option>
                </select>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_image_model">Select Image Model</label>
                <select name="aab_content_image_model" id="aab_content_image_model">
                    <option value="dall-e-3" <?php selected($content_model ?? 'dall-e-3', 'dall-e-3'); ?>>DALL·E 3</option>
                    <option value="dall-e-2" <?php selected($content_model, 'dall-e-2'); ?>>DALL·E 2</option>
                    <option value="gpt-image-1" <?php selected($content_model, 'gpt-image-1'); ?>>gpt-image-1</option>
                    <option value="gpt-image-1.5" <?php selected($content_model, 'gpt-image-1.5'); ?>>gpt-image-1.5</option>
                    <option value="gpt-image-1-mini" <?php selected($content_model, 'gpt-image-1-mini'); ?>>gpt-image-1-mini</option>
                </select>
                <span class="muted" id="aab_content_model_note" style="display:<?php echo (stripos($content_model ?? '', 'gpt-image') === 0) ? 'block' : 'none'; ?>">
                    Your organization must be verified to use <code><?php echo esc_html($content_model ?? ''); ?></code>.<br>
                    <a href="https://platform.openai.com/settings/organization/general" target="_blank">Verify here →</a>
                </span>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_image_size">Select Image Size</label>
                <select name="aab_content_image_size" id="aab_content_image_size">
                    <option value="1024x1024" <?php selected($content_size ?? '1024x1024', '1024x1024'); ?>>1024×1024</option>
                    <option value="512x512" <?php selected($content_size, '512x512'); ?>>512×512</option>
                    <option value="256x256" <?php selected($content_size, '256x256'); ?>>256×256</option>
                </select>
            </div>

            <div class="aab-checkbox-group">
                <label>
                    <input type="checkbox" name="aab_get_image_by_title" id="aab_get_image_by_title" <?php checked($get_by_title); ?>>
                    Get Image By Title
                </label>
                <label>
                    <input type="checkbox" name="aab_get_image_by_prompt" id="aab_get_image_by_prompt" <?php checked($get_by_prompt); ?>>
                    Get Image By Prompt
                </label>
            </div>

            <div class="aab-form-row" id="aab_custom_prompt_row" style="<?php echo $get_by_prompt ? '' : 'display:none;'; ?>">
                <label for="aab_content_custom_prompt">Custom Prompt</label>
                <textarea name="aab_content_custom_prompt" id="aab_content_custom_prompt" rows="3"><?php echo esc_textarea($content_custom_prompt ?? ''); ?></textarea>
            </div>

            <div class="aab-checkbox-group">
                <label>
                    <input type="checkbox" name="aab_set_keyword_as_alt" id="aab_set_keyword_as_alt" <?php checked($set_keyword_alt); ?>>
                    Set Image Keyword as Alt Text
                </label>
                <label>
                    <input type="checkbox" name="aab_search_by_parent_keyword" id="aab_search_by_parent_keyword" <?php checked($search_by_parent_keyword); ?>>
                    Search Images by Parent Keyword
                </label>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_num_images">Number of Images to Import</label>
                <select name="aab_content_num_images" id="aab_content_num_images">
                    <option value="1" <?php selected($content_num ?? 1, 1); ?>>1</option>
                    <option value="2" <?php selected($content_num, 2); ?>>2</option>
                    <option value="3" <?php selected($content_num, 3); ?>>3</option>
                </select>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_dist">Image Distribution Method</label>
                <select name="aab_content_dist" id="aab_content_dist">
                    <option value="fixed" <?php selected($content_dist ?? 'fixed', 'fixed'); ?>>Fixed</option>
                    <option value="dynamic" <?php selected($content_dist, 'dynamic'); ?>>Dynamic</option>
                </select>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_pos_1">Position for Image #1</label>
                <select name="aab_content_pos_1" id="aab_content_pos_1">
                    <option value="top" <?php selected($content_pos_1 ?? 'top', 'top'); ?>>Top of Content</option>
                    <option value="middle" <?php selected($content_pos_1, 'middle'); ?>>Middle of Content</option>
                    <option value="bottom" <?php selected($content_pos_1, 'bottom'); ?>>Bottom of Content</option>
                </select>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_pos_2">Position for Image #2</label>
                <select name="aab_content_pos_2" id="aab_content_pos_2">
                    <option value="top" <?php selected($content_pos_2 ?? 'middle', 'top'); ?>>Top of Content</option>
                    <option value="middle" <?php selected($content_pos_2, 'middle'); ?>>Middle of Content</option>
                    <option value="bottom" <?php selected($content_pos_2, 'bottom'); ?>>Bottom of Content</option>
                </select>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_pos_3">Position for Image #3</label>
                <select name="aab_content_pos_3" id="aab_content_pos_3">
                    <option value="top" <?php selected($content_pos_3 ?? 'bottom', 'top'); ?>>Top of Content</option>
                    <option value="middle" <?php selected($content_pos_3, 'middle'); ?>>Middle of Content</option>
                    <option value="bottom" <?php selected($content_pos_3, 'bottom'); ?>>Bottom of Content</option>
                </select>
            </div>

            <div class="aab-form-row">
                <label for="aab_content_wp_image_size">Content Image Size (WP)</label>
                <select name="aab_content_wp_image_size" id="aab_content_wp_image_size">
                    <?php foreach ($wp_sizes as $size): ?>
                        <option value="<?php echo esc_attr($size); ?>" <?php selected($content_wp_image_size ?? 'full', $size); ?>>
                            <?php echo esc_html($size); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

    </div>
</template>

        <script>
            // Toggle featured image settings visibility
                document.addEventListener('DOMContentLoaded', function() {
                    const featToggle = document.getElementById('aab_feat_generate');
                    const featSettings = document.getElementById('aab_featured_settings');
                    
                    if (featToggle && featSettings) {
                        featToggle.addEventListener('change', function() {
                            featSettings.style.display = this.checked ? 'block' : 'none';
                        });
                    }
                    
                    // Handle custom prompt visibility for featured image
                    const featPromptToggle = document.getElementById('aab_feat_get_image_by_prompt');
                    const featPromptRow = document.getElementById('aab_feat_custom_prompt_row');
                    
                    if (featPromptToggle && featPromptRow) {
                        featPromptToggle.addEventListener('change', function() {
                            featPromptRow.style.display = this.checked ? 'block' : 'none';
                        });
                    }
                    
                    // Handle custom prompt visibility for content images
                    const contentPromptToggle = document.getElementById('aab_get_image_by_prompt');
                    const contentPromptRow = document.getElementById('aab_custom_prompt_row');
                    
                    if (contentPromptToggle && contentPromptRow) {
                        contentPromptToggle.addEventListener('change', function() {
                            contentPromptRow.style.display = this.checked ? 'block' : 'none';
                        });
                    }
                });            
            
            // Show/hide custom prompt rows when checkboxes are toggled
            document.addEventListener('DOMContentLoaded', function() {
                const featPromptCheck = document.getElementById('aab_feat_get_image_by_prompt');
                const contentPromptCheck = document.getElementById('aab_get_image_by_prompt');

                if (featPromptCheck) {
                    featPromptCheck.addEventListener('change', function() {
                        document.getElementById('aab_feat_custom_prompt_row').style.display = this.checked ? 'block' : 'none';
                    });
                }

                if (contentPromptCheck) {
                    contentPromptCheck.addEventListener('change', function() {
                        document.getElementById('aab_custom_prompt_row').style.display = this.checked ? 'block' : 'none';
                    });
                }

                // Optional: model note visibility (if model changes)
                ['aab_feat_image_model', 'aab_content_image_model'].forEach(id => {
                    const select = document.getElementById(id);
                    if (select) {
                        select.addEventListener('change', function() {
                            const note = document.getElementById(id.replace('model', 'model_note'));
                            if (note) {
                                note.style.display = this.value.includes('gpt-image') ? 'block' : 'none';
                            }
                        });
                    }
                });
            });
            (function() {
                const tpl = document.getElementById('aab-image-settings-template');
                const form = document.querySelector('form');
                if (!tpl || !form) return;

                const submit = form.querySelector('#submit, input[type=submit], .submit');
                const clone = tpl.content.cloneNode(true);
                if (submit) {
                    form.insertBefore(clone, submit);
                } else {
                    form.appendChild(clone);
                }

                // small client-side UX behaviours only (server already pre-filled)
                const promptCheckbox = document.getElementById('aab_get_image_by_prompt');
                const promptRow = document.getElementById('aab_custom_prompt_row');

                function togglePromptRow() {
                    if (!promptRow) return;
                    promptRow.style.display = promptCheckbox && promptCheckbox.checked ? '' : 'none';
                }
                if (promptCheckbox) {
                    promptCheckbox.addEventListener('change', togglePromptRow);
                    togglePromptRow();
                }

                // NEW: Featured prompt toggle
                const featPromptCheckbox = document.getElementById('aab_feat_get_image_by_prompt');
                const featPromptRow = document.getElementById('aab_feat_custom_prompt_row');

                function toggleFeatPromptRow() {
                    if (!featPromptRow) return;
                    featPromptRow.style.display = featPromptCheckbox && featPromptCheckbox.checked ? '' : 'none';
                }
                if (featPromptCheckbox) {
                    featPromptCheckbox.addEventListener('change', toggleFeatPromptRow);
                    toggleFeatPromptRow();
                }

                // Ensure number-of-images stays within 1..3
                const numSel = document.getElementById('aab_content_num_images');
                if (numSel) {
                    numSel.addEventListener('change', function() {
                        let v = parseInt(this.value) || 1;
                        if (v < 1) v = 1;
                        if (v > 3) v = 3;
                        this.value = v;
                    });
                }

                const featGenerate = document.getElementById('aab_feat_generate');

                function toggleFeatFields() {
                    const featSize = document.getElementById('aab_feat_image_size');
                    const featMethod = document.getElementById('aab_feat_image_method');
                    if (!featSize || !featMethod) return;
                    if (featGenerate && !featGenerate.checked) {
                        featSize.disabled = true;
                        featMethod.disabled = true;
                    } else {
                        featSize.disabled = false;
                        featMethod.disabled = false;
                    }
                }
                if (featGenerate) {
                    featGenerate.addEventListener('change', toggleFeatFields);
                    toggleFeatFields();
                }

                // Model note toggles for verification info
                const featModelSel = document.getElementById('aab_feat_image_model');
                const featModelNote = document.getElementById('aab_feat_model_note');
                if (featModelSel && featModelNote) {
                    featModelSel.addEventListener('change', function() {
                        const v = this.value || '';
                        if (v.indexOf('gpt-image') === 0) {
                            featModelNote.style.display = '';
                            featModelNote.querySelector('code').textContent = v;
                        } else {
                            featModelNote.style.display = 'none';
                        }
                    });
                }
                const contentModelSel = document.getElementById('aab_content_image_model');
                const contentModelNote = document.getElementById('aab_content_model_note');
                if (contentModelSel && contentModelNote) {
                    contentModelSel.addEventListener('change', function() {
                        const v = this.value || '';
                        if (v.indexOf('gpt-image') === 0) {
                            contentModelNote.style.display = '';
                            contentModelNote.querySelector('code').textContent = v;
                        } else {
                            contentModelNote.style.display = 'none';
                        }
                    });
                }
            })();
        </script>
<?php
    }

    public static function save_campaign_image_meta($post_id, $post, $update)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            // error_log('AAB ImageIntegration: save_campaign_image_meta() - DOING_AUTOSAVE, returning for post ' . $post_id);
            return;
        }
        if (wp_is_post_revision($post_id)) {
            // error_log('AAB ImageIntegration: save_campaign_image_meta() - revision, returning for post ' . $post_id);
            return;
        }
        if (!$post || $post->post_type !== 'aab_campaign') {
            // error_log('AAB ImageIntegration: save_campaign_image_meta() - not aab_campaign, returning for post ' . $post_id);
            return;
        }
        if (!current_user_can('manage_options', $post_id)) {
            // error_log('AAB ImageIntegration: save_campaign_image_meta() - current_user_can check failed for post ' . $post_id);
            return;
        }
        if (empty($_POST)) {
            // error_log('AAB ImageIntegration: save_campaign_image_meta() - empty POST, returning for post ' . $post_id);
            return;
        }

        $fields = [
            'aab_feat_generate' => isset($_POST['aab_feat_generate']) ? 1 : 0,
            'aab_feat_image_method' => sanitize_text_field($_POST['aab_feat_image_method'] ?? 'dalle'),
            'aab_feat_image_model' => sanitize_text_field($_POST['aab_feat_image_model'] ?? 'dall-e-3'),
            'aab_feat_image_size' => sanitize_text_field($_POST['aab_feat_image_size'] ?? '1024x1024'),
            'aab_feat_set_first_as_featured' => isset($_POST['aab_feat_set_first_as_featured']) ? 1 : 0,
            'aab_feat_get_original_from_data' => isset($_POST['aab_feat_get_original_from_data']) ? 1 : 0,

            // NEW featured prompt storage
            'aab_feat_get_image_by_prompt' => isset($_POST['aab_feat_get_image_by_prompt']) ? 1 : 0,
            'aab_feat_custom_prompt' => sanitize_textarea_field($_POST['aab_feat_custom_prompt'] ?? ''),

            'aab_content_image_method' => sanitize_text_field($_POST['aab_content_image_method'] ?? 'dalle'),
            'aab_content_image_model' => sanitize_text_field($_POST['aab_content_image_model'] ?? 'dall-e-3'),
            'aab_content_image_size' => sanitize_text_field($_POST['aab_content_image_size'] ?? '1024x1024'),
            'aab_get_image_by_title' => isset($_POST['aab_get_image_by_title']) ? 1 : 0,
            'aab_get_image_by_prompt' => isset($_POST['aab_get_image_by_prompt']) ? 1 : 0,
            'aab_content_custom_prompt' => sanitize_textarea_field($_POST['aab_content_custom_prompt'] ?? ''),
            'aab_set_keyword_as_alt' => isset($_POST['aab_set_keyword_as_alt']) ? 1 : 0,
            'aab_search_by_parent_keyword' => isset($_POST['aab_search_by_parent_keyword']) ? 1 : 0,
            'aab_content_num_images' => min(3, max(1, intval($_POST['aab_content_num_images'] ?? 1))),
            'aab_content_dist' => sanitize_text_field($_POST['aab_content_dist'] ?? 'fixed'),
            'aab_content_pos_1' => sanitize_text_field($_POST['aab_content_pos_1'] ?? 'top'),
            'aab_content_pos_2' => sanitize_text_field($_POST['aab_content_pos_2'] ?? 'middle'),
            'aab_content_pos_3' => sanitize_text_field($_POST['aab_content_pos_3'] ?? 'bottom'),
            'aab_content_wp_image_size' => sanitize_text_field($_POST['aab_content_wp_image_size'] ?? 'full'),

            'aab_image_quality_processing' => isset($_POST['aab_image_quality_processing']) ? 1 : 0,
            'aab_enable_youtube' => isset($_POST['aab_enable_youtube']) ? 1 : 0,

            // NEW content settings
            'aab_alt_from_title_all' => isset($_POST['aab_alt_from_title_all']) ? 1 : 0,
            'aab_alt_from_title_empty' => isset($_POST['aab_alt_from_title_empty']) ? 1 : 0,
            'aab_remove_links' => isset($_POST['aab_remove_links']) ? 1 : 0,
            'aab_links_new_tab' => isset($_POST['aab_links_new_tab']) ? 1 : 0,
            'aab_links_nofollow' => isset($_POST['aab_links_nofollow']) ? 1 : 0,
        ];

        foreach ($fields as $k => $v) {
            update_post_meta($post_id, $k, $v);
            // error_log('AAB ImageIntegration: save_campaign_image_meta() - saved meta ' . $k . ' => ' . (is_scalar($v) ? $v : json_encode($v)));
        }

        // error_log('AAB ImageIntegration: save_campaign_image_meta() - completed for campaign ' . $post_id);
    }

    public static function handle_post_after_insert($post_id, $post)
    {
        // error_log('AAB ImageIntegration: handle_post_after_insert() start for post ' . $post_id);

        if (wp_is_post_revision($post_id)) {
            // error_log('AAB ImageIntegration: handle_post_after_insert() - is revision, returning for post ' . $post_id);
            return;
        }
        if ($post->post_type !== 'post') {
            // error_log('AAB ImageIntegration: handle_post_after_insert() - post_type not "post" (' . $post->post_type . '), returning for post ' . $post_id);
            return;
        }
        if ($post->post_status === 'auto-draft') {
            // error_log('AAB ImageIntegration: handle_post_after_insert() - post is auto-draft, returning for post ' . $post_id);
            return;
        }

        // If content already has images, do nothing.
        if (preg_match('/<img\s+[^>]*src=([\'"])(.*?)\1/i', $post->post_content)) {
            // error_log('AAB ImageIntegration: handle_post_after_insert() - content already has <img>, skipping post ' . $post_id);
            return;
        }

        // First try to find a campaign id stored as post meta (some runners save it).
        $campaign_id = 0;
        $possible_meta_keys = [
            'aab_campaign_id',
            'aab_campaign',
            '_aab_campaign',
            'aab_source_campaign',
            'aab_generated_campaign',
            'aab_campaign_ref',
        ];
        foreach ($possible_meta_keys as $meta_key) {
            $val = get_post_meta($post_id, $meta_key, true);
            if (!empty($val) && intval($val) > 0) {
                $campaign_id = intval($val);
                // error_log('AAB ImageIntegration: handle_post_after_insert() - detected campaign meta "' . $meta_key . '" = ' . $campaign_id . ' for post ' . $post_id);
                break;
            } else {
                // error_log('AAB ImageIntegration: handle_post_after_insert() - meta "' . $meta_key . '" not present or empty for post ' . $post_id);
            }
        }

        // If no campaign meta found, require the h1 sanity check and fallback to keyword detection.
        if (!$campaign_id) {
            // Small sanity: only process posts that look like AI-generated (have h1)
            if (!preg_match('/<h1\b[^>]*>.*?<\/h1>/is', $post->post_content)) {
                // error_log('AAB ImageIntegration: handle_post_after_insert() - skipping post ' . $post_id . ' — no <h1> and no campaign meta.');
                return;
            } else {
                // error_log('AAB ImageIntegration: handle_post_after_insert() - <h1> present, will attempt find_campaign_for_post for post ' . $post_id);
            }

            $campaign_id = self::find_campaign_for_post($post->post_title . ' ' . $post->post_content);
            if ($campaign_id) {
                // error_log('AAB ImageIntegration: handle_post_after_insert() - find_campaign_for_post found campaign ' . $campaign_id . ' for post ' . $post_id);
            } else {
                // error_log('AAB ImageIntegration: handle_post_after_insert() - find_campaign_for_post found no campaign for post ' . $post_id);
            }
        }

        if (!$campaign_id) {
            // error_log('AAB ImageIntegration: handle_post_after_insert() - no campaign detected for post ' . $post_id . ' (title: ' . substr($post->post_title, 0, 120) . ')');
            return;
        }

        try {
            self::process_images_for_post($post_id, $post, $campaign_id);
        } catch (\Throwable $e) {
            // error_log('AAB ImageIntegration error: ' . $e->getMessage());
        }
    }

    private static function find_campaign_for_post($text)
    {
        // error_log('AAB ImageIntegration: find_campaign_for_post() start');
        $text_l = mb_strtolower(strip_tags($text));

        $campaigns = get_posts([
            'post_type' => 'aab_campaign',
            'numberposts' => -1,
            'post_status' => 'publish',
        ]);

        // error_log('AAB ImageIntegration: find_campaign_for_post() - loaded ' . count($campaigns) . ' campaigns');

        foreach ($campaigns as $c) {
            $kw = (array) get_post_meta($c->ID, 'aab_keywords', true);
            foreach ($kw as $k) {
                $k_clean = mb_strtolower(trim($k));
                if ($k_clean === '') {
                    // error_log('AAB ImageIntegration: find_campaign_for_post() - skipping empty keyword for campaign ' . $c->ID);
                    continue;
                }

                if (strpos($k_clean, ' ') !== false) {
                    if (strpos($text_l, $k_clean) !== false) {
                        // error_log('AAB ImageIntegration: find_campaign_for_post() - matched multi-word "' . $k_clean . '" for campaign ' . $c->ID);
                        return $c->ID;
                    }
                } else {
                    if (preg_match('/\b' . preg_quote($k_clean, '/') . '\b/u', $text_l)) {
                        // error_log('AAB ImageIntegration: find_campaign_for_post() - matched single-word "' . $k_clean . '" for campaign ' . $c->ID);
                        return $c->ID;
                    }
                }
            }
        }

        // error_log('AAB ImageIntegration: find_campaign_for_post() - no campaign matched');
        return 0;
    }

    private static function process_images_for_post($post_id, $post, $campaign_id)
    {
        // error_log('AAB ImageIntegration: process_images_for_post() start for post ' . $post_id . ' campaign ' . $campaign_id);

        $feat_generate = get_post_meta($campaign_id, 'aab_feat_generate', true) ? 1 : 0;
        $feat_method = get_post_meta($campaign_id, 'aab_feat_image_method', true) ?: 'dalle';
        $feat_model = get_post_meta($campaign_id, 'aab_feat_image_model', true) ?: 'dall-e-3';
        $feat_size_requested = get_post_meta($campaign_id, 'aab_feat_image_size', true) ?: '1024x1024';
        $set_first = get_post_meta($campaign_id, 'aab_feat_set_first_as_featured', true) ? 1 : 0;
        $get_original = get_post_meta($campaign_id, 'aab_feat_get_original_from_data', true) ? 1 : 0;

        // NEW featured prompt flags
        $feat_by_prompt = get_post_meta($campaign_id, 'aab_feat_get_image_by_prompt', true) ? 1 : 0;
        $feat_custom_prompt = get_post_meta($campaign_id, 'aab_feat_custom_prompt', true) ?: '';

        $content_method = get_post_meta($campaign_id, 'aab_content_image_method', true) ?: 'dalle';
        $content_model = get_post_meta($campaign_id, 'aab_content_image_model', true) ?: 'dall-e-3';
        $content_size_requested = get_post_meta($campaign_id, 'aab_content_image_size', true) ?: '1024x1024';
        $by_title = get_post_meta($campaign_id, 'aab_get_image_by_title', true) ? 1 : 0;
        $by_prompt = get_post_meta($campaign_id, 'aab_get_image_by_prompt', true) ? 1 : 0;
        $custom_prompt = get_post_meta($campaign_id, 'aab_content_custom_prompt', true) ?: '';
        $set_alt_keyword = get_post_meta($campaign_id, 'aab_set_keyword_as_alt', true) ? 1 : 0;
        $search_parent = get_post_meta($campaign_id, 'aab_search_by_parent_keyword', true) ? 1 : 0;
        $num = min(3, max(1, intval(get_post_meta($campaign_id, 'aab_content_num_images', true) ?: 1)));
        $dist = get_post_meta($campaign_id, 'aab_content_dist', true) ?: 'fixed';
        $pos = [
            get_post_meta($campaign_id, 'aab_content_pos_1', true) ?: 'top',
            get_post_meta($campaign_id, 'aab_content_pos_2', true) ?: 'middle',
            get_post_meta($campaign_id, 'aab_content_pos_3', true) ?: 'bottom',
        ];
        $wp_size = get_post_meta($campaign_id, 'aab_content_wp_image_size', true) ?: 'full';
        $quality_proc = get_post_meta($campaign_id, 'aab_image_quality_processing', true) ? 1 : 0;

        // error_log('AAB ImageIntegration: process_images_for_post() - settings: feat_generate=' . $feat_generate . ' feat_method=' . $feat_method . ' feat_model=' . $feat_model . ' feat_size=' . $feat_size_requested . ' content_method=' . $content_method . ' content_model=' . $content_model . ' content_size=' . $content_size_requested . ' num=' . $num . ' dist=' . $dist);

        // Attempt to use original image in data attributes for featured (highest priority if requested)
        if ($get_original) {
            // error_log('AAB ImageIntegration: process_images_for_post() - attempting get_original for post ' . $post_id);
            $first_url = self::extract_first_data_image_url($post->post_content);
            if ($first_url) {
                // error_log('AAB ImageIntegration: process_images_for_post() - found original image URL ' . $first_url);
                $attach_id = self::download_and_attach_image($first_url, $post_id, $quality_proc);
                if ($attach_id) {
                    // error_log('AAB ImageIntegration: process_images_for_post() - downloaded and attached original image as ' . $attach_id);
                    // SET featured if requested OR if there's currently no featured image
                    if ($set_first || !has_post_thumbnail($post_id)) {
                        set_post_thumbnail($post_id, $attach_id);
                        // error_log('AAB ImageIntegration: process_images_for_post() - set original as featured for post ' . $post_id . ' attachment ' . $attach_id);
                    }
                } else {
                    // error_log('AAB ImageIntegration: process_images_for_post() - failed to download/attach original image for post ' . $post_id);
                }
            } else {
                // error_log('AAB ImageIntegration: process_images_for_post() - no data-src/data-original found in content for post ' . $post_id);
            }
        }

        // FEATURED generation
        if ($feat_generate && $feat_method === 'dalle' && (!has_post_thumbnail($post_id) || !$get_original)) {
            // error_log('AAB ImageIntegration: process_images_for_post() - will attempt featured generation for post ' . $post_id);

            // Determine prompt for featured image with new "get by prompt" support
            if ($feat_by_prompt && trim($feat_custom_prompt) !== '') {
                $prompt = trim((string)$feat_custom_prompt);
                $used_custom_for_featured = true;
                // error_log('AAB ImageIntegration: process_images_for_post() - using featured custom prompt (truncated): ' . substr($prompt, 0, 200));
            } elseif ($feat_by_prompt && trim($feat_custom_prompt) === '') {
                // user checked "get by prompt" but left it blank -> fallback to build prompt
                $prompt = self::build_image_prompt_for_post($post, $campaign_id, 'featured', false, true, '', $search_parent);
                $used_custom_for_featured = false;
                // error_log('AAB ImageIntegration: process_images_for_post() - featured prompt checkbox checked but custom prompt empty, fallback prompt (truncated): ' . substr($prompt, 0, 200));
            } else {
                // original behavior: use title as base for featured
                $prompt = self::build_image_prompt_for_post($post, $campaign_id, 'featured', true, false, '', $search_parent);
                $used_custom_for_featured = false;
                // error_log('AAB ImageIntegration: process_images_for_post() - featured prompt from title (truncated): ' . substr($prompt, 0, 200));
            }

            // If using DALL·E 2/3 and no explicit custom prompt from user, append "no text" instruction
            if (in_array($feat_model, ['dall-e-2', 'dall-e-3'], true) && empty(trim($feat_custom_prompt))) {
                $prompt .= ' — Do NOT include any readable text, lettering, captions, or logos in the image. Pure visuals only.';
                // error_log('AAB ImageIntegration: process_images_for_post() - appended no-text instruction for featured (model=' . $feat_model . ')');
            }

            $used_size = self::map_supported_size($feat_size_requested);
            $b64 = self::generate_image_via_openai($prompt, $used_size, $feat_model);
            if ($b64) {
                // error_log('AAB ImageIntegration: process_images_for_post() - featured generation returned base64 (length ' . strlen($b64) . ') for post ' . $post_id);
                $attach_id = self::save_base64_image_to_media($b64, $post_id, $quality_proc);
                if ($attach_id) {
                    // error_log('AAB ImageIntegration: process_images_for_post() - saved featured attachment ' . $attach_id);
                    if ($used_size !== $feat_size_requested) {
                        $resized = self::maybe_resize_attachment_to($attach_id, $feat_size_requested);
                        if (!$resized) {
                            // error_log('AAB ImageIntegration: failed to resize featured attachment ' . $attach_id . ' to requested ' . $feat_size_requested);
                        } else {
                            // error_log('AAB ImageIntegration: resized featured attachment ' . $attach_id . ' to ' . $feat_size_requested);
                        }
                    }
                    // SET featured if requested OR if there's currently no featured image
                    if ($set_first || !has_post_thumbnail($post_id)) {
                        set_post_thumbnail($post_id, $attach_id);
                        // error_log('AAB ImageIntegration: set featured image to attachment ' . $attach_id . ' for post ' . $post_id);
                    }
                } else {
                    // error_log('AAB ImageIntegration: process_images_for_post() - failed to save featured attachment for post ' . $post_id);
                }
            } else {
                // error_log('AAB ImageIntegration: process_images_for_post() - featured image generation failed for post ' . $post_id);
            }
        } else {
            // error_log('AAB ImageIntegration: process_images_for_post() - skipping featured generation (conditions not met) for post ' . $post_id);
        }

        // CONTENT images generation/import
        $inserted_urls = [];
        $keywords = (array) get_post_meta($campaign_id, 'aab_keywords', true);
        $primary_keyword = $keywords[0] ?? '';

        for ($i = 0; $i < $num; $i++) {
            $index = $i + 1;
            // error_log('AAB ImageIntegration: process_images_for_post() - preparing content image #' . $index . ' for post ' . $post_id);
            if ($by_prompt && trim($custom_prompt) !== '') {
                $image_prompt = trim((string)$custom_prompt);
                $used_custom_for_content = true;
                // error_log('AAB ImageIntegration: using custom prompt for image #' . $index . ' -> ' . substr($image_prompt, 0, 200));
            } elseif ($by_prompt && trim($custom_prompt) === '') {
                $image_prompt = self::build_image_prompt_for_post($post, $campaign_id, 'content_' . $index, false, false, '', $search_parent);
                $used_custom_for_content = false;
                // error_log('AAB ImageIntegration: by_prompt selected but custom empty, fallback prompt for image #' . $index . ' -> ' . substr($image_prompt, 0, 200));
            } elseif ($by_title) {
                $image_prompt = self::build_image_prompt_for_post($post, $campaign_id, 'content_' . $index, true, false, '', $search_parent);
                $used_custom_for_content = false;
                // error_log('AAB ImageIntegration: by_title prompt for image #' . $index . ' -> ' . substr($image_prompt, 0, 200));
            } else {
                $image_prompt = self::build_image_prompt_for_post($post, $campaign_id, 'content_' . $index, false, false, '', $search_parent);
                $used_custom_for_content = false;
                // error_log('AAB ImageIntegration: default prompt for image #' . $index . ' -> ' . substr($image_prompt, 0, 200));
            }

            if (empty($image_prompt)) {
                // error_log('AAB ImageIntegration: empty image prompt for post ' . $post_id . ' image#' . $index . ' (campaign ' . $campaign_id . ') - skipping');
                continue;
            }

            if ($content_method === 'dalle') {
                $used_size = self::map_supported_size($content_size_requested);
                // error_log('AAB ImageIntegration: calling OpenAI for content image #' . $index . ' size ' . $used_size . ' model ' . $content_model);

                // If using DALL·E 2/3 and no explicit custom prompt, append "no text" instruction
                if (in_array($content_model, ['dall-e-2', 'dall-e-3'], true) && empty(trim($custom_prompt))) {
                    $image_prompt .= ' — Do NOT include any readable text, lettering, captions, or logos in the image. Pure visuals only.';
                    // error_log('AAB ImageIntegration: process_images_for_post() - appended no-text instruction for content image #' . $index . ' (model=' . $content_model . ')');
                }

                $b64 = self::generate_image_via_openai($image_prompt, $used_size, $content_model);
                if (!$b64) {
                    // error_log('AAB ImageIntegration: content image generation failed for post ' . $post_id . ' image#' . $index);
                    continue;
                }
                // error_log('AAB ImageIntegration: content image generation succeeded (image#' . $index . ') - saving attachment');
                $attach_id = self::save_base64_image_to_media($b64, $post_id, $quality_proc);
                if (!$attach_id) {
                    // error_log('AAB ImageIntegration: failed to save attachment for post ' . $post_id . ' image#' . $index);
                    continue;
                }
                // error_log('AAB ImageIntegration: saved content attachment ' . $attach_id . ' for post ' . $post_id . ' image#' . $index);

                if ($used_size !== $content_size_requested) {
                    $resized = self::maybe_resize_attachment_to($attach_id, $content_size_requested);
                    if (!$resized) {
                        // error_log('AAB ImageIntegration: failed to resize content attachment ' . $attach_id . ' to requested ' . $content_size_requested);
                    } else {
                        // error_log('AAB ImageIntegration: resized content attachment ' . $attach_id . ' to ' . $content_size_requested);
                    }
                }

                $url = wp_get_attachment_url($attach_id);
                if ($url) {
                    if ($set_alt_keyword && $primary_keyword) {
                        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($primary_keyword));
                        // error_log('AAB ImageIntegration: set alt for attachment ' . $attach_id . ' => ' . $primary_keyword);
                    }
                    $pos_for = $pos[$i] ?? $pos[count($pos) - 1];
                    $inserted_urls[] = ['url' => $url, 'pos' => $pos_for];
                    // error_log('AAB ImageIntegration: will insert image URL ' . $url . ' at pos ' . $pos_for . ' (image#' . $index . ')');
                } else {
                    // error_log('AAB ImageIntegration: wp_get_attachment_url returned empty for attach ' . $attach_id);
                }
            } else {
                // error_log('AAB ImageIntegration: content_method ' . $content_method . ' not supported for generation, skipping image#' . $index);
            }
        }

        if (empty($inserted_urls)) {
            // error_log('AAB ImageIntegration: no images generated/collected for post ' . $post_id . ' campaign ' . $campaign_id);
            return;
        }

        $new_content = self::insert_images_into_content($post->post_content, $inserted_urls, $dist);
        // error_log('AAB ImageIntegration: inserting ' . count($inserted_urls) . ' images into post ' . $post_id . ' (distribution: ' . $dist . ')');

        // --- APPLY CONTENT LINK SETTINGS (existing) ---
        $new_content = self::apply_content_link_settings($new_content, $campaign_id);

        // prevent recursion
        remove_action('save_post', [self::class, 'handle_post_after_insert'], 20);
        $res = wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content,
        ]);
        add_action('save_post', [self::class, 'handle_post_after_insert'], 20, 2);

        if (is_wp_error($res)) {
            // error_log('AAB ImageIntegration: wp_update_post returned error when inserting images for post ' . $post_id . ' - ' . $res->get_error_message());
        } else {
            // error_log('AAB ImageIntegration: successfully updated post ' . $post_id . ' with images');

            // Apply alt rules AFTER post has been updated and attachments exist (new)
            try {
                self::apply_campaign_alt_rules($post_id, $campaign_id);
            } catch (\Throwable $e) {
            }
        }
    }

    private static function map_supported_size($size)
    {
        $allowed = ['256x256', '512x512', '1024x1024'];
        $size = trim((string)$size);
        if (in_array($size, $allowed, true)) return $size;
        return '1024x1024';
    }

    /**
     * Generate image via OpenAI (or provider). Accepts explicit $model (campaign-level).
     *
     * @param string $prompt
     * @param string $size
     * @param string $model
     * @return false|string base64
     */
    private static function generate_image_via_openai($prompt, $size = '1024x1024', $model = 'dall-e-3')
    {
        // error_log('AAB ImageIntegration: generate_image_via_openai() start (size=' . $size . ', model=' . $model . ')');
        $api_key = get_option('aab_openai_key', '');
        if (empty($api_key)) {
            error_log('AAB ImageIntegration: OpenAI key missing.');
            return false;
        }

        $used_size = self::map_supported_size($size);
        $endpoint = 'https://api.openai.com/v1/images/generations';
        
        // Build body using provided model (campaign-level choice)
        $body = [
            'prompt' => $prompt,
            'n' => 1,
            'size' => $used_size,
            'model' => $model,
        ];

        $attempts = 0;
        $max_attempts = 3;
        while ($attempts < $max_attempts) {
            $attempts++;
            error_log('AAB ImageIntegration: generate_image_via_openai() - attempt ' . $attempts . ' model=' . $model);
            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode($body),
                'timeout' => 90,
            ]);

            if (is_wp_error($response)) {
                error_log('AAB ImageIntegration: OpenAI HTTP error (attempt ' . $attempts . '): ' . $response->get_error_message());
                if ($attempts < $max_attempts) sleep(1 << ($attempts - 1));
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            error_log('AAB ImageIntegration: OpenAI response code ' . $code . ' (attempt ' . $attempts . ')');

            if ($code < 200 || $code >= 300) {
                $snippet = substr($raw, 0, 2000);
                error_log('AAB ImageIntegration: OpenAI non-2xx (attempt ' . $attempts . '): ' . $code . ' body: ' . $snippet);
                $parsed = json_decode($raw, true);
                if (is_array($parsed) && !empty($parsed['error']['message'])) {
                    error_log('AAB ImageIntegration: OpenAI error message: ' . $parsed['error']['message']);
                }
                if ($attempts < $max_attempts) sleep(1 << ($attempts - 1));
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                error_log('AAB ImageIntegration: OpenAI returned malformed JSON. Raw len=' . strlen($raw));
                if ($attempts < $max_attempts) sleep(1 << ($attempts - 1));
                continue;
            }

            if (!empty($data['error'])) {
                $errMsg = is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : (string)$data['error'];
                error_log('AAB ImageIntegration: OpenAI returned error: ' . $errMsg);
                return false;
            }

            // Try common payload shapes:
            // - data[0].b64_json
            // - data[0].url
            if (!empty($data['data'][0]['b64_json'])) {
                error_log('AAB ImageIntegration: OpenAI returned b64_json (attempt ' . $attempts . ')');
                return $data['data'][0]['b64_json'];
            }

            if (!empty($data['data'][0]['url'])) {
                $img_url = $data['data'][0]['url'];
                error_log('AAB ImageIntegration: OpenAI returned url (attempt ' . $attempts . '): ' . $img_url);
                $resp = wp_remote_get($img_url, ['timeout' => 60]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) >= 200 && wp_remote_retrieve_response_code($resp) < 300) {
                    error_log('AAB ImageIntegration: fetched fallback image url successfully');
                    return base64_encode(wp_remote_retrieve_body($resp));
                } else {
                    error_log('AAB ImageIntegration: could not fetch image url fallback: ' . (is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp)));
                }
            }

            error_log('AAB ImageIntegration: OpenAI returned no image data (attempt ' . $attempts . '). Raw len=' . strlen($raw));
            if ($attempts < $max_attempts) sleep(1 << ($attempts - 1));
        }

        error_log('AAB ImageIntegration: generate_image_via_openai() exhausted attempts and failed (model=' . $model . ')');
        return false;
    }

    private static function extract_first_data_image_url($html)
    {
        if (preg_match('/<img\b[^>]*(?:data-src|data-original|data-lazy|data-srcset)\s*=\s*([\'"])(.*?)\1[^>]*>/i', $html, $m)) {
            // error_log('AAB ImageIntegration: extract_first_data_image_url() found data attr URL');
            return esc_url_raw($m[2]);
        }
        if (preg_match('/<img\b[^>]*src\s*=\s*([\'"])(.*?)\1[^>]*>/i', $html, $m2)) {
            // error_log('AAB ImageIntegration: extract_first_data_image_url() found src URL');
            return esc_url_raw($m2[2]);
        }
        // error_log('AAB ImageIntegration: extract_first_data_image_url() found no image URLs');
        return false;
    }

    private static function download_and_attach_image($url, $post_id, $quality_proc = 0)
    {
        // error_log('AAB ImageIntegration: download_and_attach_image() start for URL ' . $url . ' post ' . $post_id);
        if (empty($url)) {
            // error_log('AAB ImageIntegration: download_and_attach_image() - empty URL');
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            // error_log('AAB ImageIntegration: download_and_attach_image() - remote get error: ' . $response->get_error_message());
            return 0;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            // error_log('AAB ImageIntegration: download_and_attach_image() - HTTP ' . $code . ' when fetching ' . $url);
            return 0;
        }
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            // error_log('AAB ImageIntegration: download_and_attach_image() - empty body for ' . $url);
            return 0;
        }

        $filename = wp_basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg');
        $upload = wp_upload_bits($filename, null, $body);
        if ($upload['error']) {
            // error_log('AAB ImageIntegration: upload_bits error: ' . $upload['error']);
            return 0;
        }
        $file = $upload['file'];
        $upload_url = $upload['url'] ?? '';

        if ($quality_proc) {
            $opt = self::maybe_optimize_image($file);
            // error_log('AAB ImageIntegration: download_and_attach_image() - maybe_optimize_image returned ' . ($opt ? 'true' : 'false'));
        }

        $wp_filetype = wp_check_filetype($file);
        $attachment = [
            'guid' => $upload_url,
            'post_mime_type' => $wp_filetype['type'] ?? 'image/jpeg',
            'post_title' => sanitize_file_name(pathinfo($file, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        if (is_wp_error($attach_id) || !$attach_id) {
            // error_log('AAB ImageIntegration: wp_insert_attachment failed: ' . (is_wp_error($attach_id) ? $attach_id->get_error_message() : 'unknown'));
            return 0;
        }

        // Ensure _wp_attached_file is set (uploads-relative). WP admin relies on this.
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);
        $relative = ltrim(str_replace($basedir, '', wp_normalize_path($file)), '/\\');
        update_post_meta($attach_id, '_wp_attached_file', $relative);
        // error_log('AAB ImageIntegration: download_and_attach_image() - set _wp_attached_file=' . $relative . ' for attach ' . $attach_id);

        // Generate metadata and provide a fallback if generation failed.
        $meta = wp_generate_attachment_metadata($attach_id, $file);
        if (!is_array($meta) || empty($meta['file'])) {
            // fallback: try to build minimal metadata using getimagesize
            $img = @getimagesize($file);
            $w = $img[0] ?? 0;
            $h = $img[1] ?? 0;
            $meta = [
                'width' => $w,
                'height' => $h,
                'file' => $relative,
                'sizes' => [],
                'image_meta' => [],
            ];
            // error_log('AAB ImageIntegration: download_and_attach_image() - fallback metadata used for attach ' . $attach_id . ' (w=' . $w . ',h=' . $h . ')');
        }

        wp_update_attachment_metadata($attach_id, $meta);
        // error_log('AAB ImageIntegration: download_and_attach_image() - inserted attachment ' . $attach_id . ' with metadata');

        // Ensure guid is set on the post record as well (some installs expect GUID present).
        wp_update_post(['ID' => $attach_id, 'guid' => $upload_url]);

        return $attach_id;
    }

    private static function save_base64_image_to_media($b64, $post_id, $quality_proc = 0)
    {
        // error_log('AAB ImageIntegration: save_base64_image_to_media() start for post ' . $post_id);
        if (empty($b64)) {
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - empty b64');
            return 0;
        }
        $decoded = base64_decode($b64);
        if ($decoded === false) {
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - base64 decode failed');
            return 0;
        }

        $ext = 'png';
        if (substr($decoded, 0, 3) === "\xFF\xD8\xFF") $ext = 'jpg';
        if (substr($decoded, 0, 4) === "RIFF" && substr($decoded, 8, 4) === "WEBP") $ext = 'webp';

        $filename = 'aab-img-' . time() . '-' . wp_rand(1000, 9999) . '.' . $ext;

        // ensure upload includes are available
        if (!function_exists('wp_insert_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - included WP admin files for attachment operations');
        }

        $upload = wp_upload_bits($filename, null, $decoded);
        if ($upload['error']) {
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - upload_bits error: ' . $upload['error']);
            return 0;
        }
        $file = $upload['file'];
        $upload_url = $upload['url'] ?? '';

        if ($quality_proc) {
            $opt = self::maybe_optimize_image($file);
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - maybe_optimize_image returned ' . ($opt ? 'true' : 'false'));
        }

        $wp_filetype = wp_check_filetype($file);
        $attachment = [
            'guid' => $upload_url,
            'post_mime_type' => $wp_filetype['type'] ?? 'image/jpeg',
            'post_title' => sanitize_file_name(pathinfo($file, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        if (is_wp_error($attach_id) || !$attach_id) {
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - wp_insert_attachment failed: ' . (is_wp_error($attach_id) ? $attach_id->get_error_message() : 'unknown'));
            return 0;
        }

        // Set uploads-relative path manually to _wp_attached_file (WP admin expects this meta)
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);
        $relative = ltrim(str_replace($basedir, '', wp_normalize_path($file)), '/\\');
        update_post_meta($attach_id, '_wp_attached_file', $relative);
        // error_log('AAB ImageIntegration: save_base64_image_to_media() - set _wp_attached_file=' . $relative . ' for attach ' . $attach_id);

        // Generate attachment metadata and update — this is required by WP admin to show image details.
        $meta = wp_generate_attachment_metadata($attach_id, $file);
        if (!is_array($meta) || empty($meta['file'])) {
            // fallback: build minimal metadata via getimagesize
            $img = @getimagesize($file);
            $w = $img[0] ?? 0;
            $h = $img[1] ?? 0;
            $meta = [
                'width' => $w,
                'height' => $h,
                'file' => $relative,
                'sizes' => [],
                'image_meta' => [],
            ];
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - fallback metadata used for attach ' . $attach_id . ' (w=' . $w . ',h=' . $h . ')');
        } else {
            // error_log('AAB ImageIntegration: save_base64_image_to_media() - wp_generate_attachment_metadata returned normal metadata for attach ' . $attach_id);
        }

        wp_update_attachment_metadata($attach_id, $meta);
        // error_log('AAB ImageIntegration: save_base64_image_to_media() - updated attachment metadata for ' . $attach_id);

        // Ensure guid is present on the post record too
        wp_update_post(['ID' => $attach_id, 'guid' => $upload_url]);

        // error_log('AAB ImageIntegration: save_base64_image_to_media() - created attachment ' . $attach_id . ' (file=' . $file . ')');
        return $attach_id;
    }

    private static function maybe_optimize_image($file)
    {
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor) || !$editor) {
            // error_log('AAB ImageIntegration: maybe_optimize_image() - no image editor available for ' . $file);
            return false;
        }
        if (method_exists($editor, 'set_quality')) $editor->set_quality(82);
        $saved = $editor->save($file);
        if (is_wp_error($saved)) {
            // error_log('AAB ImageIntegration: maybe_optimize_image() - editor save error: ' . $saved->get_error_message());
            return false;
        }
        // error_log('AAB ImageIntegration: maybe_optimize_image() - saved optimized image ' . $file);
        return true;
    }

    private static function maybe_resize_attachment_to($attach_id, $target_size)
    {
        if (!$attach_id || empty($target_size)) {
            // error_log('AAB ImageIntegration: maybe_resize_attachment_to() - bad params attach=' . $attach_id . ' target=' . $target_size);
            return false;
        }
        $file = get_attached_file($attach_id);
        if (!$file || !file_exists($file)) {
            // error_log('AAB ImageIntegration: maybe_resize_attachment_to() - file not found for attach ' . $attach_id);
            return false;
        }

        $parts = explode('x', $target_size);
        if (count($parts) !== 2) {
            // error_log('AAB ImageIntegration: maybe_resize_attachment_to() - invalid target_size ' . $target_size);
            return false;
        }
        $w = intval($parts[0]);
        $h = intval($parts[1]);
        if ($w <= 0 || $h <= 0) {
            // error_log('AAB ImageIntegration: maybe_resize_attachment_to() - invalid dimensions for ' . $target_size);
            return false;
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor) || !$editor) {
            // error_log('AAB ImageIntegration: maybe_resize_attachment_to() - unable to get WP image editor for file ' . $file);
            return false;
        }

        $res = $editor->resize($w, $h, true);
        if (is_wp_error($res)) {
            // error_log('AAB ImageIntegration: WP editor resize error: ' . $res->get_error_message());
            return false;
        }

        $saved = $editor->save($file);
        if (is_wp_error($saved)) {
            // error_log('AAB ImageIntegration: WP editor save error: ' . $saved->get_error_message());
            return false;
        }

        $meta = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $meta);

        // error_log('AAB ImageIntegration: maybe_resize_attachment_to() - resized and updated metadata for attach ' . $attach_id);
        return true;
    }

    private static function build_image_prompt_for_post($post, $campaign_id, $type = 'featured', $by_title = false, $by_prompt = false, $custom_prompt = '', $include_keyword = false)
    {
        $keywords = (array) get_post_meta($campaign_id, 'aab_keywords', true);
        $keyword = $keywords[0] ?? '';
        $title = wp_strip_all_tags($post->post_title ?: '');
        if ($by_prompt && !empty($custom_prompt)) {
            $base = $custom_prompt;
        } elseif ($by_title && $title) {
            $base = $title;
        } else {
            $base = trim($title ?: $keyword ?: 'A high quality photograph for blog article');
        }
        $suffix = (strtolower($type) === 'featured')
            ? ' — high quality, professional, editorial-style, clear subject, flattering composition, high resolution, Should be no use of any Text in the image just the illustration'
            : ' — inline content image, informative illustration, medium detail, Should be no use of any Text in the image just the illustration';
        if ($include_keyword && $keyword) $base .= ' — keyword: ' . $keyword;
        return $base . $suffix;
    }

    private static function apply_campaign_alt_rules($post_id, $campaign_id)
    {
        $set_all   = get_post_meta($campaign_id, 'aab_alt_from_title_all', true);
        $set_empty = get_post_meta($campaign_id, 'aab_alt_from_title_empty', true);

        if (!$set_all && !$set_empty) {
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - no alt rules enabled for campaign ' . $campaign_id);
            return;
        }

        $title = get_the_title($post_id);
        if (!$title) {
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - post has no title for post ' . $post_id);
            return;
        }

        $alt_text = wp_strip_all_tags($title);

        // Gather images from post content
        $content = get_post_field('post_content', $post_id);
        $found_urls = [];

        if (!empty($content) && preg_match_all('/<img\b[^>]*\bsrc=([\'"])(.*?)\1[^>]*>/i', $content, $m)) {
            foreach ($m[2] as $u) {
                $u = esc_url_raw($u);
                if ($u) $found_urls[$u] = true;
            }
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - found ' . count($found_urls) . ' <img> URLs in post ' . $post_id);
        } else {
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - found 0 <img> in post ' . $post_id);
        }

        // Add attached media map (URL => attach_id) for fast lookups
        $attached = get_attached_media('image', $post_id);
        $attached_map = [];
        foreach ($attached as $att) {
            $aurl = wp_get_attachment_url($att->ID);
            if ($aurl) {
                $attached_map[$aurl] = $att->ID;
                // ensure attached urls are also considered if not present as <img>
                if (!isset($found_urls[$aurl])) $found_urls[$aurl] = true;
            }
        }
        // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - attached media count for post ' . $post_id . ' => ' . count($attached_map));

        if (empty($found_urls) && empty($attached_map)) {
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - no images to process for post ' . $post_id);
            return;
        }

        // Helper to resolve attachment id from URL using multiple strategies
        $resolve_attachment = function ($img_url) use ($attached_map) {
            // exact attached map
            if (!empty($attached_map[$img_url])) {
                return intval($attached_map[$img_url]);
            }
            // try WP helper
            $aid = attachment_url_to_postid($img_url);
            if ($aid) return intval($aid);

            // basename fallback
            $target_basename = wp_basename(parse_url($img_url, PHP_URL_PATH) ?: $img_url);
            if ($target_basename && !empty($attached_map)) {
                foreach ($attached_map as $aurl => $aid2) {
                    $a_basename = wp_basename(parse_url($aurl, PHP_URL_PATH) ?: $aurl);
                    if ($a_basename && strcasecmp($a_basename, $target_basename) === 0) {
                        return intval($aid2);
                    }
                }
            }
            return 0;
        };

        // Update attachment meta where appropriate (so attachment alt is set)
        foreach (array_keys($found_urls) as $img_url) {
            $att_id = $resolve_attachment($img_url);
            if (!$att_id) {
                // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - could not resolve attachment for URL: ' . $img_url . ' (post ' . $post_id . ')');
                continue;
            }

            $current_alt = get_post_meta($att_id, '_wp_attachment_image_alt', true);
            if ($set_all || ($set_empty && trim($current_alt) === '')) {
                update_post_meta($att_id, '_wp_attachment_image_alt', $alt_text);
                // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - set alt for attach ' . $att_id . ' => "' . $alt_text . '" (post ' . $post_id . ')');
            } else {
                // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - skipped attach ' . $att_id . ' (has alt already)');
            }
        }

        // Now ensure the actual <img> tags in post content have alt attributes.
        // We'll only alter tags that either have no alt or have an empty alt, and where rules require it.
        $updated = false;
        $new_content = preg_replace_callback('/<img\b([^>]*)>/i', function ($m) use ($alt_text, $set_all, $set_empty, $resolve_attachment, &$updated) {
            $orig_tag = $m[0];
            $attrs = $m[1];

            // find src
            if (!preg_match('/\bsrc=([\'"])(.*?)\1/i', $attrs, $s)) {
                // cannot find src -> leave as is
                return $orig_tag;
            }
            $src = esc_url_raw($s[2]);

            // find alt
            $has_alt = preg_match('/\balt\s*=\s*([\'"])(.*?)\1/i', $attrs, $a);
            $alt_val = $has_alt ? $a[2] : null;

            // If alt exists and is non-empty and set_all isn't forcing override, skip unless set_all true.
            if ($has_alt && trim($alt_val) !== '' && !$set_all) {
                return $orig_tag;
            }

            // If alt exists and empty, and rule is set_empty OR set_all -> we will set
            if ($has_alt && trim($alt_val) === '' && !($set_empty || $set_all)) {
                return $orig_tag;
            }

            // If no alt attribute, and neither set_all nor set_empty requested -> skip
            if (!$has_alt && !$set_all) {
                return $orig_tag;
            }

            // resolve attachment id (not strictly required to set alt in HTML, but keep logic consistent)
            $att_id = $resolve_attachment($src);
            // Construct new alt attribute value (escape)
            $safe_alt = esc_attr($alt_text);

            if ($has_alt) {
                // replace existing alt value (even if empty)
                $new_attrs = preg_replace('/\balt\s*=\s*([\'"])(.*?)\1/i', ' alt="' . $safe_alt . '"', $attrs, 1);
            } else {
                // insert alt before the final closing (preserve trailing slash if present)
                // add a space if needed
                $new_attrs = rtrim($attrs) . ' alt="' . $safe_alt . '"';
            }

            $updated = true;
            $new_tag = '<img' . $new_attrs . '>';
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - updated <img> tag src=' . $src . ' set alt="' . $safe_alt . '"');
            return $new_tag;
        }, $content);

        if ($updated && $new_content !== $content) {
            // prevent recursion on save_post handlers that might trigger; mirror pattern used in other places
            remove_action('save_post', [self::class, 'handle_post_after_insert'], 20);
            $res = wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content,
            ]);
            add_action('save_post', [self::class, 'handle_post_after_insert'], 20, 2);

            if (is_wp_error($res)) {
                // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - wp_update_post returned error when writing alt attributes for post ' . $post_id . ' - ' . $res->get_error_message());
            } else {
                // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - updated post content with alt attributes for post ' . $post_id);
            }
        } else {
            // error_log('AAB ImageIntegration: apply_campaign_alt_rules() - no content change required for post ' . $post_id);
        }
    }



    /**
     * Apply content-level link settings for a campaign:
     * - remove links (keep inner text)
     * - open links in new tab (add target="_blank" if missing)
     * - add rel="nofollow" (append if missing)
     */
    private static function apply_content_link_settings($content, $campaign_id)
    {
        $remove_links = get_post_meta($campaign_id, 'aab_remove_links', true) ? true : false;
        $links_new_tab = get_post_meta($campaign_id, 'aab_links_new_tab', true) ? true : false;
        $links_nofollow = get_post_meta($campaign_id, 'aab_links_nofollow', true) ? true : false;

        if ($remove_links) {
            // Replace anchor with its inner HTML/text
            $content = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $content);
            return $content;
        }

        if (!$links_new_tab && !$links_nofollow) {
            return $content;
        }

        // Modify <a ...> tags as needed
        $content = preg_replace_callback('/<a\b[^>]*>/i', function ($m) use ($links_new_tab, $links_nofollow) {
            $tag = $m[0];

            // Add target="_blank" if requested and missing
            if ($links_new_tab && stripos($tag, 'target=') === false) {
                $tag = preg_replace('/\s*>$/', ' target="_blank">', $tag);
            }

            // Handle rel attribute
            if ($links_nofollow) {
                if (stripos($tag, 'rel=') === false) {
                    // add rel before closing bracket
                    $tag = preg_replace('/\s*>$/', ' rel="nofollow">', $tag);
                } else {
                    // append nofollow to existing rel value if not present
                    $tag = preg_replace_callback('/rel\s*=\s*([\'"])(.*?)\1/i', function ($rm) {
                        $quote = $rm[1];
                        $vals = trim($rm[2]);
                        $parts = preg_split('/\s+/', $vals, -1, PREG_SPLIT_NO_EMPTY);
                        if (!in_array('nofollow', $parts)) {
                            $parts[] = 'nofollow';
                        }
                        $new = 'rel=' . $quote . implode(' ', $parts) . $quote;
                        return $new;
                    }, $tag);
                }
            }

            return $tag;
        }, $content);

        return $content;
    }

    /**
     * Insert images into content.
     *
     * Improved middle-image placement:
     * - Uses DOMDocument when possible to insert after a "true middle" paragraph based on cumulative word counts.
     * - Falls back to paragraph-splitting + cumulative-word heuristic.
     * - Keeps top/middle/bottom behavior and dynamic distribution unchanged otherwise.
     */
    private static function insert_images_into_content($content, $inserted_urls, $dist = 'fixed')
    {
        // error_log('AAB ImageIntegration: insert_images_into_content() start - ' . count($inserted_urls) . ' images, dist=' . $dist);

        $imgs_html = [];
        foreach ($inserted_urls as $it) {
            $imgs_html[] = '<figure><img src="' . esc_url($it['url']) . '" alt=""></figure>';
        }

        // Dynamic mode (existing behavior) — leave unchanged
        if ($dist === 'dynamic') {
            $doc = $content;
            $parts = preg_split('/(\<\/p\>)/i', $doc, -1, PREG_SPLIT_DELIM_CAPTURE);
            $paras = [];
            for ($i = 0; $i < count($parts); $i += 2) {
                $p = ($parts[$i] ?? '') . ($parts[$i + 1] ?? '');
                $p = trim($p);
                if ($p === '') continue;
                $paras[] = $p;
            }
            if (count($paras) > 0) {
                $n = count($inserted_urls);
                $pcount = count($paras);
                $positions = [];
                for ($i = 0; $i < $n; $i++) {
                    $positions[] = intval(($i + 1) * $pcount / ($n + 1));
                }
                foreach ($positions as $idx => $pos) {
                    $insert_after = max(0, min($pcount - 1, $pos - 1));
                    $paras[$insert_after] .= "\n\n" . $imgs_html[$idx];
                }
                // error_log('AAB ImageIntegration: insert_images_into_content() - dynamic insertion complete');
                return implode("\n\n", $paras);
            }
        }

        // Fixed mode: collect top / middle / bottom strings as before
        $top_html = '';
        $middle_html = '';
        $bottom_html = '';
        foreach ($inserted_urls as $idx => $it) {
            $h = '<figure><img src="' . esc_url($it['url']) . '" alt=""></figure>';
            switch ($it['pos']) {
                case 'top':
                    $top_html .= $h;
                    break;
                case 'middle':
                    $middle_html .= $h;
                    break;
                default:
                    $bottom_html .= $h;
                    break;
            }
        }

        // If content contains <p> tags, try DOM-based insertion for robust "true middle"
        if (stripos($content, '<p') !== false && stripos($content, '</p>') !== false) {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            // wrap in utf-8 friendly pseudo-document
            $html = '<?xml encoding="utf-8" ?>' . "\n" . '<!doctype html><html><body>' . $content . '</body></html>';
            $loaded = @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            if ($loaded) {
                $body = $dom->getElementsByTagName('body')->item(0);
                if ($body) {
                    $ps = $body->getElementsByTagName('p');
                    $pcount = $ps->length;
                    if ($pcount > 0 && $middle_html) {
                        // compute cumulative words per paragraph
                        $total_words = 0;
                        $word_counts = [];
                        for ($i = 0; $i < $pcount; $i++) {
                            $pnode = $ps->item($i);
                            $text = $pnode->textContent ?? '';
                            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $text = preg_replace('/\s+/u', ' ', trim($text));
                            $wc = ($text === '') ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
                            $word_counts[] = $wc;
                            $total_words += $wc;
                        }
                        // target cumulative words = 50% of total, but bias slightly toward later paragraphs to avoid first-paragraph insertion
                        $target = ($total_words > 0) ? intval(ceil($total_words / 2)) : intval(ceil($pcount / 2));
                        $cumulative = 0;
                        $middle_index = null;
                        for ($i = 0; $i < $pcount; $i++) {
                            $cumulative += $word_counts[$i];
                            if ($cumulative >= $target) {
                                $middle_index = $i;
                                break;
                            }
                        }
                        // safety fallbacks and tuning to avoid too-early placement:
                        if ($middle_index === null) {
                            $middle_index = intval(floor($pcount / 2));
                        }
                        // ensure not first paragraph unless content is very short
                        if ($middle_index < 1 && $pcount > 2) $middle_index = 1;
                        // if content is long (many words), prefer to move middle_index further down slightly
                        if ($total_words > 800 && $pcount > 4) {
                            $middle_index = min($pcount - 2, max($middle_index, 2));
                        }

                        // build fragment properly by parsing HTML and importing nodes (fixes appendXML issue)
                        $fragment = $dom->createDocumentFragment();
                        $tmp = new \DOMDocument();
                        libxml_use_internal_errors(true);
                        // wrap middle_html in a temporary container to extract child nodes safely
                        $tmp_html = '<?xml encoding="utf-8" ?><!doctype html><html><body><div id="aab_tmp_wrap">' . $middle_html . '</div></body></html>';
                        $tmp_loaded = @$tmp->loadHTML($tmp_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        libxml_clear_errors();
                        if ($tmp_loaded) {
                            $div = $tmp->getElementById('aab_tmp_wrap');
                            if (!$div) {
                                // fallback: try to get the first div
                                $divs = $tmp->getElementsByTagName('div');
                                $div = $divs->item(0);
                            }
                            if ($div) {
                                // import each child of div into main DOM and append to fragment
                                foreach (iterator_to_array($div->childNodes) as $child) {
                                    $imported = $dom->importNode($child, true);
                                    $fragment->appendChild($imported);
                                }
                            }
                        }

                        // insert fragment after the target paragraph node
                        $targetNode = $ps->item($middle_index);
                        if ($targetNode) {
                            if ($fragment->childNodes->length > 0) {
                                if ($targetNode->nextSibling) {
                                    $targetNode->parentNode->insertBefore($fragment, $targetNode->nextSibling);
                                } else {
                                    $targetNode->parentNode->appendChild($fragment);
                                }
                            } else {
                                // fragment empty (shouldn't happen now) - fallback to text append on target
                                $textNode = $dom->createDocumentFragment();
                                $textNode->appendXML($middle_html);
                                if ($targetNode->nextSibling) {
                                    $targetNode->parentNode->insertBefore($textNode, $targetNode->nextSibling);
                                } else {
                                    $targetNode->parentNode->appendChild($textNode);
                                }
                            }

                            // prepend top_html at beginning of body if present (use safe import)
                            if ($top_html) {
                                $topFrag = $dom->createDocumentFragment();
                                $tmp2 = new \DOMDocument();
                                libxml_use_internal_errors(true);
                                $tmp2_html = '<?xml encoding="utf-8" ?><!doctype html><html><body><div id="aab_tmp_top">' . $top_html . '</div></body></html>';
                                $tmp2_loaded = @$tmp2->loadHTML($tmp2_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                libxml_clear_errors();
                                if ($tmp2_loaded) {
                                    $div2 = $tmp2->getElementById('aab_tmp_top');
                                    if (!$div2) {
                                        $divs2 = $tmp2->getElementsByTagName('div');
                                        $div2 = $divs2->item(0);
                                    }
                                    if ($div2) {
                                        foreach (iterator_to_array($div2->childNodes) as $child) {
                                            $imported2 = $dom->importNode($child, true);
                                            $topFrag->appendChild($imported2);
                                        }
                                    }
                                }
                                if ($body->firstChild && $topFrag->childNodes->length > 0) {
                                    $body->insertBefore($topFrag, $body->firstChild);
                                } elseif ($topFrag->childNodes->length > 0) {
                                    $body->appendChild($topFrag);
                                }
                            }

                            // append bottom_html at end of body if present
                            if ($bottom_html) {
                                $bottomFrag = $dom->createDocumentFragment();
                                $tmp3 = new \DOMDocument();
                                libxml_use_internal_errors(true);
                                $tmp3_html = '<?xml encoding="utf-8" ?><!doctype html><html><body><div id="aab_tmp_bottom">' . $bottom_html . '</div></body></html>';
                                $tmp3_loaded = @$tmp3->loadHTML($tmp3_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                libxml_clear_errors();
                                if ($tmp3_loaded) {
                                    $div3 = $tmp3->getElementById('aab_tmp_bottom');
                                    if (!$div3) {
                                        $divs3 = $tmp3->getElementsByTagName('div');
                                        $div3 = $divs3->item(0);
                                    }
                                    if ($div3) {
                                        foreach (iterator_to_array($div3->childNodes) as $child) {
                                            $imported3 = $dom->importNode($child, true);
                                            $bottomFrag->appendChild($imported3);
                                        }
                                    }
                                }
                                if ($bottomFrag->childNodes->length > 0) {
                                    $body->appendChild($bottomFrag);
                                }
                            }

                            // extract innerHTML of body
                            $out = '';
                            foreach ($body->childNodes as $child) {
                                $out .= $dom->saveHTML($child);
                            }
                            $out = trim($out);
                            libxml_clear_errors();
                            // error_log('AAB ImageIntegration: insert_images_into_content() - DOM insertion complete (middle after paragraph ' . $middle_index . ', total paras=' . $pcount . ', total_words=' . $total_words . ')');
                            return $out;
                        }
                        // else fall back to paragraph-based below
                    }
                }
            }
            libxml_clear_errors();
            // if DOM failed or didn't insert, we'll fall back to paragraph splitting method below
            // error_log('AAB ImageIntegration: insert_images_into_content() - DOM insertion not used or failed, falling back to paragraph-split method');
        }

        // Fallback: paragraph-splitting method (robust)
        $doc = $content;
        $parts = preg_split('/(\<\/p\>)/i', $doc, -1, PREG_SPLIT_DELIM_CAPTURE);
        $paras = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $p = ($parts[$i] ?? '') . ($parts[$i + 1] ?? '');
            $p = trim($p);
            if ($p === '') continue;
            $paras[] = $p;
        }

        if (!empty($paras)) {
            // compute word counts and choose middle paragraph by cumulative words
            $word_counts = [];
            $total_words = 0;
            foreach ($paras as $p) {
                $plain = wp_strip_all_tags($p);
                $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $plain = preg_replace('/\s+/u', ' ', trim($plain));
                $words = ($plain === '') ? 0 : count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY));
                $word_counts[] = $words;
                $total_words += $words;
            }
            $target = ($total_words > 0) ? intval(ceil($total_words / 2)) : intval(ceil(count($paras) / 2));
            $cumulative = 0;
            $middle_index = null;
            for ($i = 0; $i < count($paras); $i++) {
                $cumulative += $word_counts[$i];
                if ($cumulative >= $target) {
                    $middle_index = $i;
                    break;
                }
            }
            if ($middle_index === null) $middle_index = intval(floor(count($paras) / 2));
            if ($middle_index < 1 && count($paras) > 2) $middle_index = 1;
            if ($total_words > 800 && count($paras) > 4) {
                $middle_index = min(count($paras) - 2, max($middle_index, 2));
            }

            if ($middle_html) {
                $paras[$middle_index] .= "\n\n" . $middle_html;
                // error_log('AAB ImageIntegration: insert_images_into_content() - inserted middle images after paragraph index ' . $middle_index . ' (fallback path, paras=' . count($paras) . ', total_words=' . $total_words . ')');
            }

            $result = $top_html . "\n\n" . implode("\n\n", $paras);
            if ($bottom_html) {
                $result .= "\n\n" . $bottom_html;
            }
            $result = trim($result);
            // error_log('AAB ImageIntegration: insert_images_into_content() - fixed insertion complete (fallback)');
            return $result;
        }

        // Last resort: no paragraph structure found — use earlier behavior but try to place middle more intelligently
        $result = $top_html . "\n\n" . $content;
        if ($middle_html) {
            // try sentence-split fallback
            $plain_all = wp_strip_all_tags($content);
            $plain_all = html_entity_decode($plain_all, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain_all = preg_replace('/\s+/u', ' ', trim($plain_all));
            $all_words = ($plain_all === '') ? 0 : count(preg_split('/\s+/u', $plain_all, -1, PREG_SPLIT_NO_EMPTY));
            if ($all_words > 40) {
                $sentences = preg_split('/([\.!?])\s+/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
                if ($sentences && count($sentences) > 6) {
                    $reconstructed = '';
                    $sentence_elements = [];
                    for ($i = 0; $i < count($sentences); $i += 2) {
                        $s = $sentences[$i] . ($sentences[$i + 1] ?? '');
                        $s = trim($s);
                        if ($s !== '') $sentence_elements[] = $s;
                    }
                    $mid = intval(floor(count($sentence_elements) / 2));
                    if ($mid < 1) $mid = 1;
                    for ($i = 0; $i < count($sentence_elements); $i++) {
                        $reconstructed .= $sentence_elements[$i] . ' ';
                        if ($i === $mid) {
                            $reconstructed .= "\n\n" . $middle_html . "\n\n";
                        }
                    }
                    $result = $top_html . "\n\n" . trim($reconstructed);
                    if ($bottom_html) $result .= "\n\n" . $bottom_html;
                    // error_log('AAB ImageIntegration: insert_images_into_content() - sentence-split fallback used');
                    return $result;
                }
            }
            // final fallback
            if (preg_match('/<\/\w+>/i', $result)) {
                $result = preg_replace('/(<\/\w+>)/i', '$1' . $middle_html, $result, 1);
            } else {
                $result .= "\n\n" . $middle_html;
            }
        }
        if ($bottom_html) $result .= "\n\n" . $bottom_html;

        // error_log('AAB ImageIntegration: insert_images_into_content() - fixed insertion complete (last resort)');
        return $result;
    }

    private static function get_wp_image_sizes()
    {
        $sizes = get_intermediate_image_sizes();
        $sizes[] = 'full';
        return array_unique($sizes);
    }
}

\AAB\Extensions\ImageIntegration::init();
