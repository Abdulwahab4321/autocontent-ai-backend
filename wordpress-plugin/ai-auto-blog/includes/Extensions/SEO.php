<?php
namespace AAB\Extensions;

if (!defined('ABSPATH')) exit;

/**
 * SEO Integration for AutoContent AI (WITH DEBUGGING)
 * 
 * This class handles all SEO-related features:
 * - Meta titles & descriptions
 * - Schema markup
 * - Internal linking
 * - Image SEO
 * - Social media tags
 */
class SEO {
    
    public static function init() {
        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB SEO: Module initializing...');
        
        // Hook into post creation to add SEO meta
        add_action('wp_insert_post', [self::class, 'add_seo_meta'], 20, 2);
        error_log('AAB SEO: Hooked into wp_insert_post');
        
        // Disable SEO plugin meta output to prevent duplicates
        add_action('plugins_loaded', [self::class, 'disable_plugin_meta_output'], 999);
        error_log('AAB SEO: Will disable plugin meta output');
        
        // Add custom meta title and description tags (priority 1 = very early)
        add_action('wp_head', [self::class, 'add_meta_tags'], 1);
        error_log('AAB SEO: Hooked into wp_head for meta tags');
        
        // Add schema markup to posts
        add_action('wp_head', [self::class, 'add_schema_markup'], 5);
        error_log('AAB SEO: Hooked into wp_head for schema');
        
        // Add Open Graph and Twitter Card tags
        add_action('wp_head', [self::class, 'add_social_meta_tags'], 5);
        error_log('AAB SEO: Hooked into wp_head for social tags');
        
        // Modify post content to add internal links
        add_filter('the_content', [self::class, 'add_internal_links'], 10);
        error_log('AAB SEO: Hooked into the_content for internal links');
        
        // Optimize images (alt text, title)
        add_filter('wp_get_attachment_image_attributes', [self::class, 'optimize_image_attributes'], 10, 3);
        error_log('AAB SEO: Hooked into image optimization');
        
        error_log('AAB SEO: Module initialization complete!');
        error_log('═══════════════════════════════════════════════════════');
    }
    
    /**
     * Disable SEO plugin meta output to prevent duplicates
     * Only for AutoContent AI posts
     */
    public static function disable_plugin_meta_output() {
        // Only disable on single posts
        if (!is_single()) {
            return;
        }
        
        global $post;
        if (!$post) {
            return;
        }
        
        // Check if this is an AI-generated post
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        if (!$campaign_id) {
            return; // Not our post, let SEO plugins handle it
        }
        
        // Check if SEO is enabled for this campaign
        $seo_enabled = get_post_meta($campaign_id, 'aab_seo_enabled', true);
        if (!$seo_enabled) {
            return; // SEO not enabled, let plugins handle it
        }
        
        // Disable Yoast SEO meta output
        add_filter('wpseo_frontend_presenters', '__return_empty_array', 999);
        add_filter('wpseo_json_ld_output', '__return_false', 999);
        
        // Disable Rank Math meta output
        add_filter('rank_math/frontend/remove_head_tags', '__return_true', 999);
        add_filter('rank_math/json_ld', '__return_false', 999);
        
        // Disable All in One SEO meta output
        add_filter('aioseo_description', '__return_false', 999);
        add_filter('aioseo_title', '__return_false', 999);
        
        error_log('AAB SEO: Disabled plugin meta output for AutoContent AI post ' . $post->ID);
    }
    
    /**
     * Add custom meta title and description tags to <head>
     */
    public static function add_meta_tags() {
        if (!is_single()) {
            return;
        }
        
        global $post;
        
        // Check if this is an AI-generated post
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        if (!$campaign_id) {
            return;
        }
        
        // Check if SEO is enabled
        $seo_enabled = get_post_meta($campaign_id, 'aab_seo_enabled', true);
        if (!$seo_enabled) {
            return;
        }
        
        // Get custom meta title (with fallback to post title)
        $meta_title = get_post_meta($post->ID, '_yoast_wpseo_title', true)
                   ?: get_post_meta($post->ID, 'rank_math_title', true)
                   ?: get_post_meta($post->ID, '_aioseo_title', true)
                   ?: get_the_title($post) . ' - ' . get_bloginfo('name'); // FALLBACK
        
        // Get custom meta description (with fallback to excerpt)
        $meta_description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true)
                         ?: get_post_meta($post->ID, 'rank_math_description', true)
                         ?: get_post_meta($post->ID, '_aioseo_description', true)
                         ?: wp_trim_words(get_the_excerpt($post) ?: strip_tags($post->post_content), 30); // FALLBACK
        
        // Get focus keyword
        $focus_keyword = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true)
                      ?: get_post_meta($post->ID, 'rank_math_focus_keyword', true);
        
        echo "\n" . '<!-- AutoContent AI - Custom SEO Meta Tags -->' . "\n";
        
        // Always output meta title (now has fallback)
        echo '<meta name="title" content="' . esc_attr($meta_title) . '">' . "\n";
        
        // Always output meta description (now has fallback)
        echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
        
        // Output keywords if available
        if ($focus_keyword) {
            echo '<meta name="keywords" content="' . esc_attr($focus_keyword) . '">' . "\n";
        }
        
        // Add robots meta
        echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">' . "\n";
        
        error_log('AAB SEO: ✅ Custom meta tags added for post ' . $post->ID);
        error_log('AAB SEO: Meta Title = ' . $meta_title);
        error_log('AAB SEO: Meta Description = ' . substr($meta_description, 0, 100) . '...');
    }
    
    /**
     * Add SEO meta (title, description) when post is created
     */
    public static function add_seo_meta($post_id, $post) {
        error_log('─────────────────────────────────────────');
        error_log('AAB SEO: add_seo_meta() called for post ID: ' . $post_id);
        error_log('AAB SEO: Post type: ' . $post->post_type);
        error_log('AAB SEO: Post status: ' . $post->post_status);
        error_log('AAB SEO: Post title: ' . $post->post_title);
        
        // Skip auto-drafts, revisions, autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            error_log('AAB SEO: Skipping - revision or autosave');
            return;
        }
        
        // Only process posts (not pages or other post types)
        if ($post->post_type !== 'post') {
            error_log('AAB SEO: Skipping - not a post (type: ' . $post->post_type . ')');
            return;
        }
        
        // Only process posts generated by our campaigns
        $campaign_id = get_post_meta($post_id, 'aab_campaign_id', true);
        error_log('AAB SEO: Campaign ID from post meta: ' . ($campaign_id ? $campaign_id : 'NOT FOUND'));
        
        if (!$campaign_id) {
            error_log('AAB SEO: ❌ No campaign ID found - this post was not created by AutoContent AI');
            error_log('AAB SEO: Checking all meta keys for this post:');
            $all_meta = get_post_meta($post_id);
            foreach ($all_meta as $key => $value) {
                error_log('AAB SEO:    - ' . $key . ' = ' . print_r($value, true));
            }
            return;
        }
        
        error_log('AAB SEO: ✅ Campaign ID found: ' . $campaign_id);
        
        // Check if campaign has SEO enabled
        $seo_enabled = get_post_meta($campaign_id, 'aab_seo_enabled', true);
        error_log('AAB SEO: Checking if campaign ' . $campaign_id . ' has SEO enabled...');
        error_log('AAB SEO: aab_seo_enabled value: ' . ($seo_enabled ? 'TRUE (1)' : 'FALSE (0 or empty)'));
        
        if (!$seo_enabled) {
            error_log('AAB SEO: ❌ SEO is DISABLED for this campaign');
            error_log('AAB SEO: Campaign meta values:');
            $campaign_meta = get_post_meta($campaign_id);
            foreach ($campaign_meta as $key => $value) {
                if (strpos($key, 'seo') !== false) {
                    error_log('AAB SEO:    - ' . $key . ' = ' . print_r($value, true));
                }
            }
            return;
        }
        
        error_log('AAB SEO: ✅ SEO is ENABLED for this campaign');
        
        // Get templates
        $title_template = get_post_meta($campaign_id, 'aab_seo_title_template', true);
        $desc_template = get_post_meta($campaign_id, 'aab_seo_description_template', true);
        error_log('AAB SEO: Title template: ' . ($title_template ? $title_template : '[EMPTY]'));
        error_log('AAB SEO: Description template: ' . ($desc_template ? $desc_template : '[EMPTY]'));
        
        // Generate meta title
        error_log('AAB SEO: Generating meta title...');
        $meta_title = self::generate_meta_title($post);
        error_log('AAB SEO: Generated title: ' . $meta_title);
        
        // Save to SEO plugins
        error_log('AAB SEO: Saving meta title to SEO plugins...');
        update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
        error_log('AAB SEO:    ✅ Saved to Yoast (_yoast_wpseo_title)');
        
        update_post_meta($post_id, 'rank_math_title', $meta_title);
        error_log('AAB SEO:    ✅ Saved to Rank Math (rank_math_title)');
        
        update_post_meta($post_id, '_aioseo_title', $meta_title);
        error_log('AAB SEO:    ✅ Saved to All in One SEO (_aioseo_title)');
        
        // Generate meta description
        error_log('AAB SEO: Generating meta description...');
        $meta_description = self::generate_meta_description($post);
        error_log('AAB SEO: Generated description: ' . substr($meta_description, 0, 100) . '...');
        
        // Save descriptions
        error_log('AAB SEO: Saving meta description to SEO plugins...');
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
        error_log('AAB SEO:    ✅ Saved to Yoast (_yoast_wpseo_metadesc)');
        
        update_post_meta($post_id, 'rank_math_description', $meta_description);
        error_log('AAB SEO:    ✅ Saved to Rank Math (rank_math_description)');
        
        update_post_meta($post_id, '_aioseo_description', $meta_description);
        error_log('AAB SEO:    ✅ Saved to All in One SEO (_aioseo_description)');
        
        // Set focus keyword - Use campaign keyword OR custom SEO keyword
        $keyword = get_post_meta($post_id, 'aab_keyword', true); // From campaign
        if (!$keyword) {
            $keyword = get_post_meta($campaign_id, 'aab_seo_focus_keyword', true); // Custom SEO keyword
        }
        
        if ($keyword) {
            error_log('AAB SEO: Focus keyword: ' . $keyword);
            
            // Save to Yoast
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
            
            // Save to Rank Math (important!)
            update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
            
            // Save to AIOSEO
            update_post_meta($post_id, '_aioseo_keyphrases', json_encode([
                'focus' => [
                    'keyphrase' => $keyword,
                    'score' => 0
                ]
            ]));
            
            error_log('AAB SEO:    ✅ Saved focus keyword to all plugins');
            
            // Run content optimizer to fix all Rank Math issues
            if (class_exists('\AAB\Extensions\SEOContentOptimizer')) {
                error_log('AAB SEO: Running advanced content optimizer...');
                \AAB\Extensions\SEOContentOptimizer::optimize_post_content($post_id, $post);
            }
        } else {
            error_log('AAB SEO: ⚠️ No keyword found for SEO optimization');
        }
        
        error_log('AAB SEO: ✅✅✅ SEO meta successfully saved for post ' . $post_id . ' ✅✅✅');
        error_log('─────────────────────────────────────────');
    }
    
    /**
     * Generate optimized meta title
     * Template: {Post Title} | {Site Name}
     */
    private static function generate_meta_title($post) {
        error_log('AAB SEO: generate_meta_title() called');
        
        $title = $post->post_title;
        $site_name = get_bloginfo('name');
        error_log('AAB SEO: Original title: ' . $title);
        error_log('AAB SEO: Site name: ' . $site_name);
        
        // Campaign-specific template
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        $title_template = get_post_meta($campaign_id, 'aab_seo_title_template', true);
        error_log('AAB SEO: Using template: ' . ($title_template ? $title_template : '[DEFAULT]'));
        
        if ($title_template) {
            // Get keyword
            $keyword = get_post_meta($post->ID, 'aab_keyword', true);
            error_log('AAB SEO: Post keyword: ' . ($keyword ? $keyword : '[NONE]'));
            
            // Replace variables
            $meta_title = str_replace(
                ['{title}', '{site_name}', '{keyword}'],
                [$title, $site_name, $keyword],
                $title_template
            );
            error_log('AAB SEO: After variable replacement: ' . $meta_title);
        } else {
            // Default: Just the title (let SEO plugins add site name)
            $meta_title = $title;
            error_log('AAB SEO: Using default (post title only)');
        }
        
        // No truncation - let Google handle optimal display length
        // Google typically shows 50-60 characters, but can show up to 70+
        // Better to provide full title and let Google decide
        
        error_log('AAB SEO: Final meta title: ' . $meta_title . ' (length: ' . strlen($meta_title) . ' chars)');
        return $meta_title;
    }
    
    /**
     * Generate meta description from post content
     * Max length: 160 characters
     */
    private static function generate_meta_description($post) {
        error_log('AAB SEO: generate_meta_description() called');
        
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        $desc_template = get_post_meta($campaign_id, 'aab_seo_description_template', true);
        error_log('AAB SEO: Description template: ' . ($desc_template ? $desc_template : '[EMPTY]'));
        
        if ($desc_template) {
            // Use custom template
            $keyword = get_post_meta($post->ID, 'aab_keyword', true);
            $excerpt = wp_trim_words(strip_tags($post->post_content), 20);
            
            error_log('AAB SEO: Template variables:');
            error_log('AAB SEO:    {title} = ' . $post->post_title);
            error_log('AAB SEO:    {keyword} = ' . ($keyword ? $keyword : '[NONE]'));
            error_log('AAB SEO:    {excerpt} = ' . substr($excerpt, 0, 50) . '...');
            
            $description = str_replace(
                ['{title}', '{keyword}', '{excerpt}'],
                [
                    $post->post_title,
                    $keyword,
                    $excerpt
                ],
                $desc_template
            );
            error_log('AAB SEO: After variable replacement: ' . substr($description, 0, 100) . '...');
        } else {
            // Extract first paragraph or use excerpt
            $content = strip_tags($post->post_content);
            $description = wp_trim_words($content, 25, '...');
            error_log('AAB SEO: Using auto-generated excerpt (no template)');
        }
        
        // Truncate to 160 characters
        if (strlen($description) > 160) {
            $original = $description;
            $description = substr($description, 0, 157) . '...';
            error_log('AAB SEO: Truncated from ' . strlen($original) . ' to 160 chars');
        }
        
        error_log('AAB SEO: Final description: ' . substr($description, 0, 100) . '...');
        return $description;
    }
    
    /**
     * Add Schema.org Article markup
     */
    public static function add_schema_markup() {
        error_log('AAB SEO: add_schema_markup() called');
        
        if (!is_single()) {
            error_log('AAB SEO: Not a single post, skipping schema');
            return;
        }
        
        global $post;
        error_log('AAB SEO: Adding schema for post ID: ' . $post->ID);
        
        // Check if this is an AI-generated post
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        if (!$campaign_id) {
            error_log('AAB SEO: No campaign ID, skipping schema');
            return;
        }
        
        error_log('AAB SEO: Campaign ID: ' . $campaign_id);
        
        // Check if schema is enabled
        $schema_enabled = get_post_meta($campaign_id, 'aab_seo_schema_enabled', true);
        error_log('AAB SEO: Schema enabled: ' . ($schema_enabled ? 'YES' : 'NO'));
        
        // If not explicitly set, enable by default if SEO is enabled
        if (!$schema_enabled) {
            $seo_enabled = get_post_meta($campaign_id, 'aab_seo_enabled', true);
            if ($seo_enabled) {
                error_log('AAB SEO: Schema not explicitly enabled, but SEO is enabled - showing schema anyway');
                $schema_enabled = true;
            }
        }
        
        if (!$schema_enabled) {
            error_log('AAB SEO: Schema disabled, skipping');
            return;
        }
        
        $author = get_the_author_meta('display_name', $post->post_author);
        $published = get_the_date('c', $post);
        $modified = get_the_modified_date('c', $post);
        $image = get_the_post_thumbnail_url($post, 'full');
        
        error_log('AAB SEO: Building schema markup...');
        error_log('AAB SEO:    Author: ' . $author);
        error_log('AAB SEO:    Published: ' . $published);
        error_log('AAB SEO:    Image: ' . ($image ? 'YES' : 'NO'));
        
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => get_the_title($post),
            'description' => get_the_excerpt($post),
            'image' => $image ?: get_site_icon_url(),
            'datePublished' => $published,
            'dateModified' => $modified,
            'author' => [
                '@type' => 'Person',
                'name' => $author,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => get_site_icon_url(),
                ],
            ],
        ];
        
        error_log('AAB SEO: ✅ Outputting Schema markup to page <head>');
        echo "\n" . '<!-- AutoContent AI - Schema.org Markup -->' . "\n";
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";
        error_log('AAB SEO: ✅ Schema markup added successfully');
    }
    
    /**
     * Add internal links to related posts
     */
    public static function add_internal_links($content) {
        if (!is_single()) {
            return $content;
        }
        
        global $post;
        
        error_log('AAB SEO: add_internal_links() called for post ' . $post->ID);
        
        // Check if this is an AI-generated post
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        if (!$campaign_id) {
            error_log('AAB SEO: No campaign ID, skipping internal links');
            return $content;
        }
        
        // Check if internal linking is enabled
        $internal_links_enabled = get_post_meta($campaign_id, 'aab_seo_internal_links', true);
        error_log('AAB SEO: Internal links enabled: ' . ($internal_links_enabled ? 'YES' : 'NO'));
        
        if (!$internal_links_enabled) {
            return $content;
        }
        
        // Get number of links to add
        $num_links = get_post_meta($campaign_id, 'aab_seo_internal_links_count', true) ?: 3;
        error_log('AAB SEO: Adding ' . $num_links . ' internal links');
        
        // Get related posts
        $related = self::get_related_posts($post->ID, $num_links);
        
        if (empty($related)) {
            error_log('AAB SEO: No related posts found');
            return $content;
        }
        
        error_log('AAB SEO: Found ' . count($related) . ' related posts');
        
        // Split content into paragraphs
        $paragraphs = preg_split('/(<p[^>]*>.*?<\/p>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $link_index = 0;
        $modified_content = '';
        
        foreach ($paragraphs as $i => $paragraph) {
            $modified_content .= $paragraph;
            
            // Add link after every 3rd paragraph
            if ($i > 0 && $i % 6 == 0 && $link_index < count($related)) {
                $related_post = $related[$link_index];
                $link_html = '<p class="aab-related-link"><strong>Related:</strong> <a href="' . get_permalink($related_post->ID) . '">' . esc_html($related_post->post_title) . '</a></p>';
                $modified_content .= $link_html;
                $link_index++;
                error_log('AAB SEO:    Added link to: ' . $related_post->post_title);
            }
        }
        
        error_log('AAB SEO: ✅ Internal links added');
        return $modified_content;
    }
    
    /**
     * Get related posts based on categories/tags
     */
    private static function get_related_posts($post_id, $limit = 3) {
        $categories = wp_get_post_categories($post_id);
        $tags = wp_get_post_tags($post_id, ['fields' => 'ids']);
        
        $args = [
            'post__not_in' => [$post_id],
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'orderby' => 'rand',
        ];
        
        // Prefer same category
        if (!empty($categories)) {
            $args['category__in'] = $categories;
        } elseif (!empty($tags)) {
            $args['tag__in'] = $tags;
        }
        
        return get_posts($args);
    }
    
    /**
     * Add Open Graph and Twitter Card meta tags
     */
    public static function add_social_meta_tags() {
        if (!is_single()) {
            return;
        }
        
        global $post;
        
        error_log('AAB SEO: add_social_meta_tags() called for post ' . $post->ID);
        
        // Check if this is an AI-generated post
        $campaign_id = get_post_meta($post->ID, 'aab_campaign_id', true);
        if (!$campaign_id) {
            error_log('AAB SEO: No campaign ID, skipping social tags');
            return;
        }
        
        // Check if social meta is enabled
        $social_enabled = get_post_meta($campaign_id, 'aab_seo_social_meta', true);
        error_log('AAB SEO: Social meta enabled: ' . ($social_enabled ? 'YES' : 'NO'));
        
        // If not explicitly set, enable by default if SEO is enabled
        if (!$social_enabled) {
            $seo_enabled = get_post_meta($campaign_id, 'aab_seo_enabled', true);
            if ($seo_enabled) {
                error_log('AAB SEO: Social meta not explicitly enabled, but SEO is enabled - showing tags anyway');
                $social_enabled = true;
            }
        }
        
        if (!$social_enabled) {
            return;
        }
        
        // Get CUSTOM SEO meta title (not default post title)
        $meta_title = get_post_meta($post->ID, '_yoast_wpseo_title', true)
                   ?: get_post_meta($post->ID, 'rank_math_title', true)
                   ?: get_post_meta($post->ID, '_aioseo_title', true)
                   ?: get_the_title($post); // fallback to default
        
        // Get CUSTOM SEO meta description (not default excerpt)
        $meta_description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true)
                         ?: get_post_meta($post->ID, 'rank_math_description', true)
                         ?: get_post_meta($post->ID, '_aioseo_description', true)
                         ?: get_the_excerpt($post)
                         ?: wp_trim_words(strip_tags($post->post_content), 25); // fallback
        
        $image = get_the_post_thumbnail_url($post, 'full');
        $url = get_permalink($post);
        
        error_log('AAB SEO: Using meta_title: ' . $meta_title);
        error_log('AAB SEO: Using meta_description: ' . substr($meta_description, 0, 100) . '...');
        error_log('AAB SEO: ✅ Outputting social meta tags');
        
        echo "\n" . '<!-- AutoContent AI - Social Media Tags -->' . "\n";
        
        // Open Graph (Facebook, LinkedIn, etc.)
        echo '<meta property="og:title" content="' . esc_attr($meta_title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($meta_description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
        
        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($meta_title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '">' . "\n";
        if ($image) {
            echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
        }
        
        error_log('AAB SEO: ✅ Social meta tags added successfully');
    }
    
    /**
     * Optimize image attributes (alt, title)
     */
    public static function optimize_image_attributes($attr, $attachment, $size) {
        // If alt text is empty, use post title or filename
        if (empty($attr['alt'])) {
            $post_id = get_post()->ID ?? 0;
            
            // Check if this image is in an AI-generated post
            if ($post_id && get_post_meta($post_id, 'aab_campaign_id', true)) {
                // Use post title as alt text
                $attr['alt'] = get_the_title($post_id);
            } else {
                // Use filename without extension
                $filename = basename(get_attached_file($attachment->ID));
                $attr['alt'] = preg_replace('/\.[^.]+$/', '', $filename);
                $attr['alt'] = str_replace(['-', '_'], ' ', $attr['alt']);
                $attr['alt'] = ucwords($attr['alt']);
            }
        }
        
        // Add title attribute if missing
        if (empty($attr['title'])) {
            $attr['title'] = $attr['alt'];
        }
        
        return $attr;
    }
}