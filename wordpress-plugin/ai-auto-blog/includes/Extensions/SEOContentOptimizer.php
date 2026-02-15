<?php
namespace AAB\Extensions;

if (!defined('ABSPATH')) exit;

/**
 * Advanced SEO Content Optimizer
 * Fixes all Rank Math SEO issues automatically
 */
class SEOContentOptimizer {
    
    /**
     * Optimize post content for SEO after AI generation
     * 
     * @param int $post_id The post ID
     * @param object $post The post object
     */
    public static function optimize_post_content($post_id, $post) {
        error_log('SEO Optimizer: Starting optimization for post ' . $post_id);
        
        // Get campaign and keyword
        $campaign_id = get_post_meta($post_id, 'aab_campaign_id', true);
        if (!$campaign_id) {
            error_log('SEO Optimizer: No campaign ID found');
            return;
        }
        
        // Get focus keyword
        $keyword = get_post_meta($post_id, 'aab_keyword', true);
        if (!$keyword) {
            error_log('SEO Optimizer: No keyword found');
            return;
        }
        
        error_log('SEO Optimizer: Focus keyword = ' . $keyword);
        
        // Get current content
        $content = $post->post_content;
        
        // 1. Add keyword to first paragraph
        $content = self::add_keyword_to_first_paragraph($content, $keyword);
        
        // 2. Add keyword to headings
        $content = self::add_keyword_to_headings($content, $keyword);
        
        // 3. Add internal links
        $content = self::add_internal_links_to_content($content, $post_id, $keyword);
        
        // 4. Add external authoritative links
        $content = self::add_external_links($content, $keyword);
        
        // 5. Optimize images with keyword alt text
        $content = self::optimize_images_in_content($content, $keyword);
        
        // Update post content
        remove_action('wp_insert_post', ['\AAB\Extensions\SEO', 'add_seo_meta'], 20);
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $content
        ]);
        add_action('wp_insert_post', ['\AAB\Extensions\SEO', 'add_seo_meta'], 20, 2);
        
        // 6. Optimize URL slug with keyword
        self::optimize_url_slug($post_id, $keyword);
        
        error_log('SEO Optimizer: ✅ Content optimization complete!');
    }
    
    /**
     * Add keyword to the first paragraph
     */
    private static function add_keyword_to_first_paragraph($content, $keyword) {
        // Find first paragraph
        if (preg_match('/<p>(.*?)<\/p>/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $first_p = $matches[1][0];
            $offset = $matches[0][1];
            
            // Check if keyword already exists in first paragraph
            if (stripos($first_p, $keyword) === false) {
                // Add keyword at the beginning
                $new_first_p = '<strong>' . $keyword . '</strong> – ' . $first_p;
                $content = substr_replace(
                    $content,
                    '<p>' . $new_first_p . '</p>',
                    $offset,
                    strlen($matches[0][0])
                );
                error_log('SEO Optimizer: Added keyword to first paragraph');
            }
        }
        
        return $content;
    }
    
    /**
     * Add keyword to H2/H3 headings
     */
    private static function add_keyword_to_headings($content, $keyword) {
        $modified = false;
        
        // Find first H2 that doesn't have the keyword
        if (preg_match('/<h2>(.*?)<\/h2>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $h2_text = $matches[1][0];
            
            if (stripos($h2_text, $keyword) === false) {
                // Add keyword to H2
                $new_h2 = $keyword . ': ' . $h2_text;
                $content = substr_replace(
                    $content,
                    '<h2>' . $new_h2 . '</h2>',
                    $matches[0][1],
                    strlen($matches[0][0])
                );
                $modified = true;
                error_log('SEO Optimizer: Added keyword to H2 heading');
            }
        }
        
        return $content;
    }
    
    /**
     * Add internal links to content
     */
    private static function add_internal_links_to_content($content, $post_id, $keyword) {
        // Get related posts
        $related_posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => 5,
            'post__not_in' => [$post_id],
            'orderby' => 'rand',
            'meta_query' => [
                [
                    'key' => 'aab_campaign_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        if (empty($related_posts)) {
            error_log('SEO Optimizer: No related posts found for internal linking');
            return $content;
        }
        
        // Split content into paragraphs
        $paragraphs = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $total_paragraphs = count($paragraphs) / 2; // Each paragraph is split into 2 parts
        
        // Add internal links every 3-4 paragraphs
        $links_added = 0;
        $target_positions = [];
        
        for ($i = 3; $i < $total_paragraphs && $links_added < 3; $i += 3) {
            $target_positions[] = $i * 2; // Multiply by 2 because of split
        }
        
        foreach ($target_positions as $index => $pos) {
            if ($links_added >= count($related_posts)) break;
            if (!isset($paragraphs[$pos])) continue;
            
            $related_post = $related_posts[$links_added];
            $link_html = '<p class="aab-internal-link"><strong>Related:</strong> <a href="' . 
                         get_permalink($related_post->ID) . '" title="' . 
                         esc_attr($related_post->post_title) . '">' . 
                         esc_html($related_post->post_title) . '</a></p>';
            
            $paragraphs[$pos] = $paragraphs[$pos] . $link_html;
            $links_added++;
        }
        
        $content = implode('', $paragraphs);
        error_log('SEO Optimizer: Added ' . $links_added . ' internal links');
        
        return $content;
    }
    
    /**
     * Add external authoritative links
     */
    private static function add_external_links($content, $keyword) {
        // Find a good place to add external link (middle of content)
        $paragraphs = explode('</p>', $content);
        $middle = floor(count($paragraphs) / 2);
        
        if (isset($paragraphs[$middle])) {
            // Create a contextual external link
            $external_link = '<p class="aab-external-reference"><em>For more information about ' . 
                           strtolower($keyword) . ', you can explore <a href="https://www.wikipedia.org/wiki/' . 
                           urlencode(str_replace(' ', '_', $keyword)) . 
                           '" target="_blank" rel="nofollow noopener">additional resources</a>.</em></p>';
            
            $paragraphs[$middle] = $paragraphs[$middle] . '</p>' . $external_link;
            $content = implode('</p>', $paragraphs);
            error_log('SEO Optimizer: Added external link');
        }
        
        return $content;
    }
    
    /**
     * Optimize images with keyword in alt text
     */
    private static function optimize_images_in_content($content, $keyword) {
        // Find all img tags
        preg_match_all('/<img[^>]+>/i', $content, $images);
        
        if (!empty($images[0])) {
            foreach ($images[0] as $img_tag) {
                // Check if alt attribute exists
                if (stripos($img_tag, 'alt=') === false) {
                    // Add alt with keyword
                    $new_img_tag = str_replace('<img ', '<img alt="' . esc_attr($keyword) . '" ', $img_tag);
                    $content = str_replace($img_tag, $new_img_tag, $content);
                    error_log('SEO Optimizer: Added keyword to image alt text');
                } elseif (preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_matches)) {
                    // Alt exists but doesn't have keyword
                    $current_alt = $alt_matches[1];
                    if (stripos($current_alt, $keyword) === false && !empty($current_alt)) {
                        $new_alt = $keyword . ' - ' . $current_alt;
                        $new_img_tag = str_replace($alt_matches[0], 'alt="' . esc_attr($new_alt) . '"', $img_tag);
                        $content = str_replace($img_tag, $new_img_tag, $content);
                        error_log('SEO Optimizer: Enhanced image alt text with keyword');
                    }
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Optimize URL slug to include keyword
     */
    private static function optimize_url_slug($post_id, $keyword) {
        $post = get_post($post_id);
        $current_slug = $post->post_name;
        
        // Check if keyword is in slug
        $keyword_slug = sanitize_title($keyword);
        
        if (stripos($current_slug, $keyword_slug) === false) {
            // Create new slug with keyword at the beginning
            $new_slug = $keyword_slug . '-' . $current_slug;
            
            // Limit to 50 characters for optimal SEO
            if (strlen($new_slug) > 50) {
                $new_slug = substr($new_slug, 0, 50);
                $new_slug = substr($new_slug, 0, strrpos($new_slug, '-'));
            }
            
            remove_action('wp_insert_post', ['\AAB\Extensions\SEO', 'add_seo_meta'], 20);
            wp_update_post([
                'ID' => $post_id,
                'post_name' => $new_slug
            ]);
            add_action('wp_insert_post', ['\AAB\Extensions\SEO', 'add_seo_meta'], 20, 2);
            
            error_log('SEO Optimizer: Optimized URL slug with keyword');
        }
    }
    
    /**
     * Calculate and improve keyword density
     */
    public static function ensure_keyword_density($content, $keyword) {
        $word_count = str_word_count(strip_tags($content));
        $keyword_count = substr_count(strtolower($content), strtolower($keyword));
        $density = $word_count > 0 ? ($keyword_count / $word_count) * 100 : 0;
        
        error_log('SEO Optimizer: Keyword density = ' . round($density, 2) . '%');
        
        // Aim for 0.5-2.5% density
        if ($density < 0.5 && $word_count > 100) {
            // Add keyword a few more times naturally
            $target_count = ceil($word_count * 0.01); // 1% density
            $needed = $target_count - $keyword_count;
            
            if ($needed > 0 && $needed < 5) {
                // Add keyword variations in italics throughout content
                $paragraphs = explode('</p>', $content);
                $interval = floor(count($paragraphs) / $needed);
                
                for ($i = 0; $i < $needed && $i < count($paragraphs); $i++) {
                    $pos = ($i + 1) * $interval;
                    if (isset($paragraphs[$pos])) {
                        $paragraphs[$pos] = str_replace(
                            '</p>',
                            ' <em>' . $keyword . '</em></p>',
                            $paragraphs[$pos]
                        );
                    }
                }
                
                $content = implode('</p>', $paragraphs);
                error_log('SEO Optimizer: Improved keyword density');
            }
        }
        
        return $content;
    }
}
