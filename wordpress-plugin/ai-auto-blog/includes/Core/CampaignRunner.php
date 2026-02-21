<?php

namespace AAB\Core;
use AAB\Providers\OpenAI;
use AAB\Providers\Claude;
use AAB\Providers\Gemini;

if (!defined('ABSPATH')) exit;

class CampaignRunner
{
    public static function run($campaign_id)
    {
        error_log('AAB DEBUG: CampaignRunner::run() fired for ID ' . $campaign_id);
        $campaign_id = intval($campaign_id);
        if (!$campaign_id) return;

        $campaign = get_post($campaign_id);
        if (!$campaign || $campaign->post_status !== 'publish') {
            error_log('AAB DEBUG: Campaign ' . $campaign_id . ' is not published (status: ' . ($campaign ? $campaign->post_status : 'null') . ') - aborting run');
            return;
        }

        $enabled = get_post_meta($campaign_id, 'aab_enabled', true) ? true : false;
        $paused  = get_post_meta($campaign_id, 'aab_pause_autorun', true) ? true : false;

        $user_requested_force = false;
        try {
            if (php_sapi_name() === 'cli') {
                $user_requested_force = true;
            }
        } catch (\Throwable $e) {}
        if (!$user_requested_force) {
            if (defined('DOING_AJAX') && DOING_AJAX && !empty($_REQUEST['aab_run_now'])) $user_requested_force = true;
            if (!$user_requested_force && !empty($_REQUEST['aab_run_now'])) $user_requested_force = true;
            if (!$user_requested_force && !empty($_REQUEST['run_now'])) $user_requested_force = true;
            if (!$user_requested_force && is_admin() && current_user_can('manage_options') && (!empty($_REQUEST['aab_run_now']) || !empty($_REQUEST['run_now']))) $user_requested_force = true;
        }
        error_log('AAB TRACE: run() force-run-detected=' . intval($user_requested_force) . ' for campaign ' . $campaign_id);

        if (!$enabled || ($paused && !$user_requested_force)) {
            error_log('AAB DEBUG: CampaignRunner::run() - enabled=' . intval($enabled) . ' paused=' . intval($paused) . ' user_force=' . intval($user_requested_force) . ' => aborting run for ' . $campaign_id);
            return;
        }

        $keywords = (array) get_post_meta($campaign_id, 'aab_keywords', true);
        if (empty($keywords)) return;

        $max_posts = intval(get_post_meta($campaign_id, 'max_posts', true) ?: 0);
        $posts_run = intval(get_post_meta($campaign_id, 'aab_posts_run', true) ?: 0);

        if ($max_posts && $posts_run >= $max_posts) {
            return;
        }

        $keyword_as_title       = get_post_meta($campaign_id, 'keyword_as_title', true) ? true : false;
        $one_post_per_keyword   = get_post_meta($campaign_id, 'one_post_per_keyword', true) ? true : false;
        $rotate_keywords        = get_post_meta($campaign_id, 'rotate_keywords', true) ? true : false;
        $remove_links_flag      = get_post_meta($campaign_id, 'aab_remove_links', true) ? true : false;
        $links_new_tab_flag     = get_post_meta($campaign_id, 'aab_links_new_tab', true) ? true : false;
        $links_nofollow_flag    = get_post_meta($campaign_id, 'aab_links_nofollow', true) ? true : false;
        $alt_from_title_all     = get_post_meta($campaign_id, 'aab_alt_from_title_all', true) ? true : false;
        $alt_from_title_empty   = get_post_meta($campaign_id, 'aab_alt_from_title_empty', true) ? true : false;

        // ═══════════════════════════════════════════════════════
        // SEO SETTINGS — Read from Campaign
        // ═══════════════════════════════════════════════════════
        $seo_enabled              = get_post_meta($campaign_id, 'aab_seo_enabled', true) ? true : false;
        $seo_focus_keyword        = get_post_meta($campaign_id, 'aab_seo_focus_keyword', true) ?: '';
        $seo_title_template       = get_post_meta($campaign_id, 'aab_seo_title_template', true) ?: '{title} | {site_name}';
        $seo_description_template = get_post_meta($campaign_id, 'aab_seo_description_template', true) ?: '';
        $seo_schema_enabled       = get_post_meta($campaign_id, 'aab_seo_schema_enabled', true) ? true : false;
        $seo_internal_links       = get_post_meta($campaign_id, 'aab_seo_internal_links', true) ? true : false;
        $seo_internal_links_count = intval(get_post_meta($campaign_id, 'aab_seo_internal_links_count', true) ?: 3);
        $seo_social_meta          = get_post_meta($campaign_id, 'aab_seo_social_meta', true) ? true : false;
        $seo_optimize_images      = get_post_meta($campaign_id, 'aab_seo_optimize_images', true) ? true : false;

        error_log('AAB SEO: enabled=' . intval($seo_enabled) . ' focus_keyword="' . $seo_focus_keyword . '"');

        $keywords_clean = array_values(array_filter(array_map(function($k){ return is_string($k) ? trim($k) : ''; }, $keywords)));

        $used_raw  = get_post_meta($campaign_id, 'aab_keywords_done', true);
        $used_raw  = is_array($used_raw) ? $used_raw : (strlen((string)$used_raw) ? (array)$used_raw : []);
        $used_norm = array_map('mb_strtolower', array_map('trim', $used_raw));

        $keyword = $keywords[$posts_run % count($keywords)];
        $selected_keyword = null;

        if ($one_post_per_keyword) {
            $remaining = [];
            foreach ($keywords_clean as $k) {
                if (!in_array(mb_strtolower($k), $used_norm, true) && $k !== '') {
                    $remaining[] = $k;
                }
            }

            if (empty($remaining)) {
                update_post_meta($campaign_id, 'aab_status', 'completed');
                update_post_meta($campaign_id, 'aab_completed', 1);
                update_post_meta($campaign_id, 'aab_disabled_reason', 'completed');
                update_post_meta($campaign_id, 'aab_enabled', 0);
                if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                    CampaignScheduler::schedule_or_unschedule($campaign_id);
                }
                return;
            }

            if ($rotate_keywords) {
                $order = get_post_meta($campaign_id, 'aab_keyword_order', true);
                $order = is_array($order) ? $order : [];
                if (count($order) !== count($keywords_clean)) {
                    $order = $keywords_clean;
                    shuffle($order);
                    update_post_meta($campaign_id, 'aab_keyword_order', $order);
                }
                foreach ($order as $k) {
                    if (!in_array(mb_strtolower(trim($k)), $used_norm, true)) {
                        $selected_keyword = $k;
                        break;
                    }
                }
                if ($selected_keyword === null) {
                    $selected_keyword = $remaining[0];
                }
            } else {
                $selected_keyword = $remaining[0];
            }
        } else {
            if ($rotate_keywords) {
                $order = get_post_meta($campaign_id, 'aab_keyword_order', true);
                $order = is_array($order) ? $order : [];
                if (count($order) !== count($keywords_clean)) {
                    $order = $keywords_clean;
                    shuffle($order);
                    update_post_meta($campaign_id, 'aab_keyword_order', $order);
                }
                $idx = $posts_run % count($order);
                $selected_keyword = $order[$idx];
            } else {
                $selected_keyword = $keywords[$posts_run % count($keywords)];
            }
        }

        if ($selected_keyword === null || trim($selected_keyword) === '') {
            $selected_keyword = $keyword;
        }

        $keyword = $selected_keyword;

        // Final focus keyword for SEO: use campaign override if set, else use campaign keyword
        $focus_keyword = !empty($seo_focus_keyword) ? $seo_focus_keyword : $keyword;

        error_log('AAB SEO: Focus keyword = "' . $focus_keyword . '"');

        try {
            $title_prompt   = get_post_meta($campaign_id, 'custom_title_prompt', true) ?: '';
            $content_prompt = get_post_meta($campaign_id, 'custom_content_prompt', true) ?: '';

            $min_words = intval(get_post_meta($campaign_id, 'min_words', true) ?: 0);
            $max_words = intval(get_post_meta($campaign_id, 'max_words', true) ?: 0);

            $length_instr = '';
            if ($min_words > 0 && $max_words > 0) {
                $length_instr = "Target length: produce between {$min_words} and {$max_words} words.";
            } elseif ($min_words > 0) {
                $length_instr = "Target length: produce at least {$min_words} words.";
            } elseif ($max_words > 0) {
                $length_instr = "Target length: produce no more than {$max_words} words.";
            } else {
                $length_instr = "Target length: produce a typical long-form article (roughly 1200-1800 words).";
            }

            $desired_words = $max_words > 0 ? $max_words : max($min_words, 1200);
            $token_limit = self::words_to_token_limit($desired_words);

            $ai_custom     = get_post_meta($campaign_id, 'aab_ai_custom_params', true) ? true : false;
            $ai_max_tokens = intval(get_post_meta($campaign_id, 'aab_ai_max_tokens', true) ?: 0);
            $ai_temperature = floatval(get_post_meta($campaign_id, 'aab_ai_temperature', true) ?: 0.0);

            if ($ai_custom && $ai_max_tokens > 0) {
                $token_limit = intval(min($ai_max_tokens, 10000));
            }

            $extra_options = [];
            if ($ai_custom && $ai_temperature >= 0.0) {
                $extra_options['temperature'] = $ai_temperature;
            }

            // ═══════════════════════════════════════════════════════
            // BUILD PROMPT — SEO-OPTIMIZED vs BASIC
            // ═══════════════════════════════════════════════════════
            if ($seo_enabled) {

                // Calculate approximate keyword density target
                $target_word_count = $max_words > 0 ? $max_words : 1500;
                $keyword_uses = max(3, intval(round($target_word_count * 0.015))); // ~1.5% density

                // ══════════════════════════════════════════════════════════
                // RANKMATH FIX #1: Force AI to add 2-3 OUTBOUND LINKS
                // ══════════════════════════════════════════════════════════
                $prompt = "You are a professional SEO content writer. Produce a SINGLE blog article in valid HTML that is optimized to score 90+ on Yoast SEO or Rank Math.

═══════════════════════════════════════════════════════
FOCUS KEYWORD: \"{$focus_keyword}\"
═══════════════════════════════════════════════════════

🎯 MANDATORY SEO REQUIREMENTS (MUST ALL BE FOLLOWED):

1. H1 TITLE: Must contain \"{$focus_keyword}\" EXACTLY as written.
   Example: <h1>Complete Guide to {$focus_keyword}</h1>

2. FIRST PARAGRAPH: The VERY FIRST <p> tag MUST contain \"{$focus_keyword}\" within the first 100 words.
   Example: <p>Understanding {$focus_keyword} is essential for...</p>

3. SUBHEADINGS: Use \"{$focus_keyword}\" in at least 2 of your <h2> tags.
   Example: <h2>Why {$focus_keyword} Matters</h2>
            <h2>How to Use {$focus_keyword} Effectively</h2>

4. KEYWORD DENSITY: Use \"{$focus_keyword}\" naturally {$keyword_uses} times throughout the article.
   - Spread usage across introduction, body sections, and conclusion
   - Do NOT stuff — keep it natural and readable

5. IMAGES: Do NOT write any <img> tags at all. Images are inserted automatically
   by the system after the article is saved. Do not reference or placeholder images.

6. ⚠️ OUTBOUND LINKS (MANDATORY - RankMath Requirement):
   Add 2-3 external links to authoritative sources within the content:
   
   Examples:
   - <a href=\"https://en.wikipedia.org/wiki/[Topic]\" target=\"_blank\" rel=\"noopener\">Learn more about {$focus_keyword}</a>
   - Link to .gov, .edu, or industry authority sites related to {$focus_keyword}
   
   IMPORTANT:
   - Use REAL working URLs (Wikipedia, government sites, educational sites)
   - Add target=\"_blank\" and rel=\"noopener\" to all external links
   - Spread links naturally throughout the content (not all at the end)
   - Make anchor text relevant and natural

7. CONTENT STRUCTURE (for readability + SEO):
   - <h1> → Main title with keyword
   - <h2> → 3-5 major sections (at least 2 must contain keyword)
   - <h3> → Subsections where relevant
   - <p> → Minimum 2-3 paragraphs per section
   - <ul><li> or <ol><li> → Use lists for key points/steps
   - Last section: Add a <h2>Conclusion</h2> that mentions \"{$focus_keyword}\"

8. CONTENT DEPTH: Write detailed, expert-level content (not generic/thin).
   - Include specific facts, examples, tips, or steps
   - Avoid vague statements — be specific and actionable

9. INTERNAL LINK PLACEHOLDER: Add {$seo_internal_links_count} placeholder links like:
   <a href=\"#related-article\">{$focus_keyword} related topic</a>
   (These will be replaced with real links automatically)

{$length_instr}

CUSTOM INSTRUCTIONS:
Title: {$title_prompt}
Content: {$content_prompt}

STRICT RULES:
- Do NOT include <html>, <head>, <body>, <!DOCTYPE>, <title> tags
- Do NOT include any commentary outside the HTML
- Do NOT write any <img> tags — images are handled by the system
- MUST include 2-3 external links to authority sites (Wikipedia, .gov, .edu)
- Start directly with <h1>
- End with a </p> or </ul> closing tag
- If you must stop early, end at a sentence boundary and append: CONTINUE

Return ONLY the article HTML.";

            } else {
                // Standard prompt (unchanged from original)
                $prompt = "You are a professional SEO content writer. Produce a single blog article in valid HTML.
Do NOT include any extra commentary outside the HTML.
Structure requirements:
- Top-level title must be inside <h1> ... </h1>.
- Include an introductory <p>.
- Use at least two <h2> sections and optionally <h3> subsections.
- Use lists <ul><li> for key points where relevant.
- Keep language natural and human, avoid 'As an AI' lines.
- Use the KEYWORD: {$keyword} within title and naturally in content.
- Do NOT write any <img> tags — images are inserted automatically by the system.
{$length_instr}
If you reach a token limit and must stop early, end at a sentence boundary and append the single token: CONTINUE
Do NOT output any <html>, <head>, <body>, <!DOCTYPE> or <title> tags.
If custom prompts exist, apply them precisely:
Title instructions: {$title_prompt}
Content instructions: {$content_prompt}
Return ONLY the article HTML (blocks: <h1>, <h2>, <h3>, <p>, <ul>, <ol>, <li>, <article>, <section>).";
            }

            $prov = self::call_provider($prompt, $token_limit, $extra_options);
            if (!$prov || empty($prov['raw'])) {
                error_log('AAB ERROR: call_provider returned empty for campaign ' . $campaign_id);
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log($campaign_id, 'ERROR', 'API call failed - empty response', '', ['error' => 'call_provider returned empty']);
                }
                return;
            }

            $raw_body     = (string)$prov['raw'];
            $content_html = (isset($prov['content']) && trim((string)$prov['content']) !== '') ? (string)$prov['content'] : self::normalize_raw_html($raw_body);

            $status = self::response_status_from_raw($raw_body);
            $status_attempts = 0;
            $max_status_attempts = 6;
            $did_force_status_cont = false;

            if ($status === 'incomplete') {
                $did_force_status_cont = true;

                while ($status === 'incomplete' && $status_attempts < $max_status_attempts) {
                    $status_attempts++;
                    $plain_current = self::plain_text($content_html);
                    $current_words = self::word_count_from_text($plain_current);
                    $target_words  = $max_words > 0 ? $max_words : 5000;
                    $remaining_words = max(150, $target_words - $current_words);

                    $cont_token_limit = self::words_to_token_limit(max(200, intval(ceil($remaining_words * 1.4))));
                    if ($ai_custom && $ai_max_tokens > 0) {
                        $cont_token_limit = min($cont_token_limit, $ai_max_tokens);
                    } elseif ($cont_token_limit > 9000) {
                        $cont_token_limit = 9000;
                    }

                    $context_for_prompt = strip_tags($content_html);
                    if (strlen($context_for_prompt) > 2500) {
                        $context_for_prompt = substr($context_for_prompt, -2500);
                    }
                    if (trim($context_for_prompt) === '') {
                        $context_for_prompt = "Title/keyword: {$keyword}. Continue the article for the topic '{$keyword}'.";
                    }

                    $continue_prompt = <<<EOT
Continue the article about: {$focus_keyword}
Previous content (truncated): {$context_for_prompt}
Add roughly {$remaining_words} more words.
IMPORTANT: Use "{$focus_keyword}" naturally in any new sections/paragraphs.
Do NOT write any <img> tags — images are handled by the system.
Return ONLY valid HTML fragments (<p>, <h2>, <h3>, <ul>, <li>). End at sentence boundary. If stopping early append: CONTINUE
EOT;

                    $contProv = self::call_provider($continue_prompt, $cont_token_limit, $extra_options);

                    if (!$contProv || empty($contProv['raw'])) {
                        sleep(1);
                        $status = self::response_status_from_raw($contProv['raw'] ?? '');
                        continue;
                    }

                    $cont_raw  = (string)$contProv['raw'];
                    $cont_html = (isset($contProv['content']) && trim((string)$contProv['content']) !== '') ? (string)$contProv['content'] : self::normalize_raw_html($cont_raw);

                    if (trim($cont_html) === '') {
                        if (self::looks_like_json($cont_raw)) {
                            $dec = json_decode($cont_raw, true);
                            if (is_array($dec)) {
                                $foundc = self::recursive_find_content($dec);
                                if ($foundc !== false) $cont_html = $foundc;
                            }
                        }
                        if (trim($cont_html) === '') {
                            $rawc = trim((string)$cont_raw);
                            if ($rawc !== '' && !self::looks_like_internal_id($rawc)) {
                                $cont_html = strip_tags($rawc) !== $rawc ? $rawc : '<p>' . esc_html(substr($rawc, 0, 2000)) . '</p>';
                            }
                        }
                    }

                    if (preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($cont_html)))) {
                        $status = self::response_status_from_raw($cont_raw);
                        continue;
                    }

                    $content_html = self::append_html_fragment($content_html, $cont_html);
                    $status = self::response_status_from_raw($cont_raw);
                    if ($status !== 'incomplete') break;
                }
            }

            if ($did_force_status_cont && $status === 'incomplete') {
                $safe_content = wp_kses_post(self::strip_document_tags($content_html));
                $safe_content = preg_replace('/&lt;\/?(title|html|head|body)&gt;/i', '', $safe_content);
                $safe_content = self::strip_placeholder_images($safe_content);

                if (trim(strip_tags($safe_content)) === '') {
                    $plain_fallback = self::plain_text($content_html);
                    if (trim($plain_fallback) === '') $plain_fallback = trim((string)$raw_body);
                    if ($plain_fallback !== '' && !self::looks_like_internal_id($plain_fallback)) {
                        $safe_content = '<p>' . esc_html($plain_fallback) . '</p>';
                    } else {
                        if (class_exists('\\AAB\\Core\\Logger')) {
                            \AAB\Core\Logger::log($campaign_id, 'ERROR', 'Incomplete response - unable to generate content', '', ['attempts' => $status_attempts]);
                        }
                        return;
                    }
                }

                $title = $keyword;
                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $safe_content, $m)) {
                    $title = wp_strip_all_tags($m[1]);
                    $safe_content = preg_replace('/<h1[^>]*>.*?<\/h1>\s*/is', '', $safe_content, 1);
                }

                $post_arr = [
                    'post_title'   => $title,
                    'post_content' => $safe_content,
                    'post_status'  => 'draft',
                    'post_author'  => get_current_user_id() ?: 1,
                    'post_type'    => get_post_meta($campaign_id, 'aab_post_type', true) ?: 'post',
                    'meta_input'   => [
                        'aab_generated_campaign' => $campaign_id,
                        'aab_campaign_id'        => $campaign_id,
                        'aab_incomplete'         => 1,
                    ],
                ];

                $post_id = wp_insert_post($post_arr);
                if (is_wp_error($post_id) || !$post_id) {
                    if (class_exists('\\AAB\\Core\\Logger')) {
                        \AAB\Core\Logger::log($campaign_id, 'ERROR', 'Failed to create draft post', '', ['error' => is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown']);
                    }
                    return;
                }

                update_post_meta($campaign_id, 'aab_last_run', time());
                if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                    CampaignScheduler::schedule_or_unschedule($campaign_id);
                }
                return;
            }

            if (preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($content_html)))) {
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log($campaign_id, 'ERROR', 'Provider returned only internal ID', '', ['content' => substr(strip_tags($content_html), 0, 200)]);
                }
                return;
            }

            $plain = self::plain_text($content_html);
            $count = self::word_count_from_text($plain);

            $attempt = 0;
            $max_attempts_for_min = 5;
            while ($attempt < $max_attempts_for_min && $min_words > 0 && $count < $min_words) {
                $remaining = max(50, $min_words - $count);

                $continue_prompt = <<<EOT
Continue an existing HTML article about: {$focus_keyword}
Add at least {$remaining} more words.
IMPORTANT: Mention "{$focus_keyword}" naturally in any new paragraphs.
Do NOT write any <img> tags — images are handled by the system.
Return ONLY valid HTML fragments (<p>, <h2>, <h3>, <ul>, <li>, <ol>).
If stopping due to token limits, end at sentence boundary and append 'CONTINUE'.
EOT;

                $cont_token_limit = self::words_to_token_limit(intval(ceil($remaining * 1.6)));
                if ($ai_custom && $ai_max_tokens > 0) {
                    $cont_token_limit = min($cont_token_limit, $ai_max_tokens);
                }

                $contProv = self::call_provider($continue_prompt, $cont_token_limit, $extra_options);

                if (!$contProv || empty($contProv['raw'])) {
                    $attempt++;
                    continue;
                }

                $cont_html = (isset($contProv['content']) && trim((string)$contProv['content']) !== '') ? (string)$contProv['content'] : self::normalize_raw_html((string)$contProv['raw']);

                if (trim($cont_html) === '') {
                    if (self::looks_like_json((string)$contProv['raw'])) {
                        $dec = json_decode((string)$contProv['raw'], true);
                        if (is_array($dec)) {
                            $foundc = self::recursive_find_content($dec);
                            if ($foundc !== false) $cont_html = $foundc;
                        }
                    }
                    if (trim($cont_html) === '') {
                        $rawc = trim((string)$contProv['raw']);
                        if ($rawc !== '' && !self::looks_like_internal_id($rawc)) {
                            $cont_html = strip_tags($rawc) !== $rawc ? $rawc : '<p>' . esc_html(substr($rawc, 0, 2000)) . '</p>';
                        }
                    }
                }

                if (preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($cont_html)))) {
                    $attempt++;
                    continue;
                }

                $content_html = self::append_html_fragment($content_html, $cont_html);
                $plain = self::plain_text($content_html);
                $count = self::word_count_from_text($plain);
                $attempt++;
            }

            if (preg_match('/\bCONTINUE\b\s*$/i', strip_tags($content_html))) {
                $cont_prompt = "Continue the HTML article where it left off. Do NOT write any <img> tags. Return valid HTML fragments only.";
                $contProv = self::call_provider($cont_prompt, self::words_to_token_limit(1000), $extra_options);
                if ($contProv && !empty($contProv['raw'])) {
                    $cont_html = (isset($contProv['content']) && trim((string)$contProv['content']) !== '') ? (string)$contProv['content'] : self::normalize_raw_html((string)$contProv['raw']);
                    if (!preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($cont_html)))) {
                        $content_html = self::append_html_fragment(preg_replace('/\bCONTINUE\b\s*$/i','', $content_html), $cont_html);
                    }
                }
            }

            if ($max_words > 0) {
                $content_html = self::truncate_html_by_words($content_html, $max_words);
            } else {
                $plain_final_check = self::plain_text($content_html);
                if (self::word_count_from_text($plain_final_check) > 5000) {
                    $content_html = self::truncate_html_by_words($content_html, 5000);
                }
            }

            $content_html = self::strip_document_tags($content_html);

            // ═══════════════════════════════════════════════════════
            // SEO: Auto-fix ALL image ALT tags with focus keyword
            // ═══════════════════════════════════════════════════════
            if ($seo_enabled && $seo_optimize_images && !empty($focus_keyword)) {
                $content_html = self::inject_seo_alt_tags($content_html, $focus_keyword);
                error_log('AAB SEO: Injected ALT tags with keyword: ' . $focus_keyword);
            }

            // ══════════════════════════════════════════════════════════
            // RANKMATH FIX #2: Ensure outbound links exist (fallback)
            // ══════════════════════════════════════════════════════════
            if ($seo_enabled && !empty($focus_keyword)) {
                $content_html = self::ensure_seo_outbound_links($content_html, $focus_keyword);
            }

            $safe_content = wp_kses_post($content_html);
            $safe_content = preg_replace('/&lt;\/?(title|html|head|body)&gt;/i', '', $safe_content);
            $safe_content = self::strip_placeholder_images($safe_content);

            if (trim(strip_tags($safe_content)) === '') {
                $plain_fallback = self::plain_text($content_html);
                if (trim($plain_fallback) === '') $plain_fallback = trim((string)$raw_body);
                if ($plain_fallback !== '' && !self::looks_like_internal_id($plain_fallback)) {
                    $safe_content = '<p>' . esc_html($plain_fallback) . '</p>';
                } else {
                    if (class_exists('\\AAB\\Core\\Logger')) {
                        \AAB\Core\Logger::log($campaign_id, 'ERROR', 'Content empty after sanitization', '', ['keyword' => $keyword]);
                    }
                    return;
                }
            }

            // Extract title
            $title = $keyword;
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $safe_content, $m)) {
                $title = wp_strip_all_tags($m[1]);
            } elseif (get_post_meta($campaign_id, 'keyword_as_title', true)) {
                $title = $keyword;
            }

            $safe_content = preg_replace('/<h1[^>]*>.*?<\/h1>\s*/is', '', $safe_content, 1);

            $post_status    = get_post_meta($campaign_id, 'aab_post_status', true) ?: 'draft';
            $post_type      = get_post_meta($campaign_id, 'aab_post_type', true) ?: 'post';
            $campaign_author = intval(get_post_meta($campaign_id, 'aab_post_author', true) ?: 0);
            $author_to_use  = $campaign_author && get_userdata($campaign_author) ? $campaign_author : (get_current_user_id() ?: 1);

            // ══════════════════════════════════════════════════════════
            // RANKMATH FIX #3: Generate short, keyword-based slug (max 60 chars)
            // ══════════════════════════════════════════════════════════
            $post_slug = '';
            if ($seo_enabled && !empty($focus_keyword)) {
                $post_slug = sanitize_title($focus_keyword);
                
                if (strlen($post_slug) > 60) {
                    $words = explode('-', $post_slug);
                    $short_slug = '';
                    foreach ($words as $word) {
                        if (strlen($short_slug . '-' . $word) <= 60) {
                            $short_slug .= ($short_slug ? '-' : '') . $word;
                        } else {
                            break;
                        }
                    }
                    $post_slug = $short_slug;
                }
                
                error_log('AAB SEO: Generated short slug (' . strlen($post_slug) . ' chars): ' . $post_slug);
            }

            $post_arr = [
                'post_title'   => $title,
                'post_content' => $safe_content,
                'post_status'  => $post_status,
                'post_author'  => $author_to_use,
                'post_type'    => $post_type,
                'meta_input'   => [
                    'aab_generated_campaign' => $campaign_id,
                    'aab_campaign_id'        => $campaign_id,
                ],
            ];

            // Add slug if SEO enabled
            if (!empty($post_slug)) {
                $post_arr['post_name'] = $post_slug;
            }

            $post_id = wp_insert_post($post_arr);

            if (is_wp_error($post_id) || !$post_id) {
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log($campaign_id, 'ERROR', 'Failed to create post: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'), '', ['keyword' => $keyword]);
                }
                return;
            }

            // Categories
            $set_cat = get_post_meta($campaign_id, 'aab_set_category', true) ? true : false;
            if ($set_cat && $post_type === 'post') {
                $cat_ids = (array) get_post_meta($campaign_id, 'aab_categories', true);
                $cat_ids_clean = array_values(array_filter(array_map('intval', $cat_ids)));
                if (!empty($cat_ids_clean)) {
                    wp_set_post_categories($post_id, $cat_ids_clean, false);
                }
            }

            // ═══════════════════════════════════════════════════════
            // SEO META — Save to Yoast / Rank Math / AIOSEO
            // ═══════════════════════════════════════════════════════
            if ($seo_enabled) {
                self::save_seo_meta($post_id, $title, $focus_keyword, $seo_title_template, $seo_description_template, $safe_content);
            }

            // ═══════════════════════════════════════════════════════
            // SEO: Internal Links
            // ═══════════════════════════════════════════════════════
            if ($seo_enabled && $seo_internal_links) {
                $updated_content = self::inject_internal_links($post_id, $safe_content, $focus_keyword, $seo_internal_links_count);
                if ($updated_content !== $safe_content) {
                    wp_update_post(['ID' => $post_id, 'post_content' => $updated_content]);
                    error_log('AAB SEO: Injected ' . $seo_internal_links_count . ' internal links');
                }
            }

            if (class_exists('\\AAB\\Core\\Logger')) {
                \AAB\Core\Logger::log(
                    $campaign_id, 'SUCCESS', $title, get_permalink($post_id),
                    [
                        'post_id'    => $post_id,
                        'keyword'    => $keyword,
                        'word_count' => self::word_count_from_text(self::plain_text($safe_content)),
                        'status'     => $post_status,
                        'seo'        => $seo_enabled ? 'enabled' : 'disabled'
                    ]
                );
            }

            if ($one_post_per_keyword) {
                $kw_norm = mb_strtolower(trim($keyword));
                if ($kw_norm !== '' && !in_array($kw_norm, $used_norm, true)) {
                    $used_norm[] = $kw_norm;
                    $used_store = get_post_meta($campaign_id, 'aab_keywords_done', true);
                    $used_store = is_array($used_store) ? $used_store : (strlen((string)$used_store) ? (array)$used_store : []);
                    $used_store[] = $keyword;
                    update_post_meta($campaign_id, 'aab_keywords_done', array_values(array_unique($used_store)));
                    if (count($used_norm) >= count($keywords_clean)) {
                        update_post_meta($campaign_id, 'aab_status', 'completed');
                        update_post_meta($campaign_id, 'aab_completed', 1);
                        update_post_meta($campaign_id, 'aab_disabled_reason', 'completed');
                        update_post_meta($campaign_id, 'aab_enabled', 0);
                        if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                            CampaignScheduler::schedule_or_unschedule($campaign_id);
                        }
                    }
                }
            }

            update_post_meta($campaign_id, 'aab_posts_run', $posts_run + 1);
            update_post_meta($campaign_id, 'aab_last_run', time());

            if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                CampaignScheduler::schedule_or_unschedule($campaign_id);
            }

            error_log('AAB INFO: CampaignRunner::run() completed successfully for ID ' . $campaign_id . ' (post ' . $post_id . ')');

        } catch (\Exception $e) {
            error_log('AAB ERROR: Exception in CampaignRunner::run() for campaign ' . $campaign_id . ' - ' . $e->getMessage());
            if (class_exists('\\AAB\\Core\\Logger')) {
                \AAB\Core\Logger::log($campaign_id, 'ERROR', 'Exception: ' . $e->getMessage(), '', ['error_message' => $e->getMessage(), 'error_line' => $e->getLine(), 'keyword' => isset($keyword) ? $keyword : 'unknown']);
            }
            return;
        }
    }

    // ═══════════════════════════════════════════════════════
    // Strip fake placeholder <img> tags before saving
    // ═══════════════════════════════════════════════════════
    private static function strip_placeholder_images($content)
    {
        if (empty($content)) return $content;

        return preg_replace_callback(
            '/<img([^>]*)>/i',
            function ($matches) {
                $attrs = $matches[1];
                if (preg_match('/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $src_match)) {
                    $src = trim($src_match[2]);
                    if (preg_match('/^https?:\/\/.+\..+/i', $src)) {
                        return $matches[0];
                    }
                }
                error_log('AAB IMG: Stripped placeholder <img> (fake/missing src)');
                return '';
            },
            $content
        );
    }

    // ══════════════════════════════════════════════════════════
    // RANKMATH FIX: Ensure outbound links exist (fallback injection)
    // ══════════════════════════════════════════════════════════
    private static function ensure_seo_outbound_links($content, $focus_keyword) {
        if (empty($focus_keyword) || empty($content)) return $content;

        // Check if outbound links already exist
        $has_outbound = preg_match('/<a[^>]+href=["\']https?:\/\//i', $content);
        
        if ($has_outbound) {
            error_log('AAB SEO: ✅ Outbound links already present');
            return $content;
        }

        error_log('AAB SEO: ⚠️ No outbound links found - injecting Wikipedia link');

        // Create Wikipedia link
        $wiki_keyword = str_replace(' ', '_', $focus_keyword);
        $wiki_url = 'https://en.wikipedia.org/wiki/' . urlencode($wiki_keyword);
        $wiki_link = '<p>For more information, you can refer to the <a href="' . esc_url($wiki_url) . '" target="_blank" rel="noopener">Wikipedia article about ' . esc_html($focus_keyword) . '</a>.</p>';

        // Try to insert before conclusion
        if (preg_match('/(<h2[^>]*>.*?conclusion.*?<\/h2>)/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[0][1];
            $content = substr_replace($content, $wiki_link . "\n", $pos, 0);
            error_log('AAB SEO: ✅ Injected Wikipedia outbound link before conclusion');
        } else {
            // Insert at the end if no conclusion found
            $content .= "\n" . $wiki_link;
            error_log('AAB SEO: ✅ Injected Wikipedia outbound link at end');
        }

        return $content;
    }

    // ═══════════════════════════════════════════════════════
    // SEO HELPER: Save meta to Yoast / Rank Math / AIOSEO
    // ═══════════════════════════════════════════════════════
    private static function save_seo_meta($post_id, $title, $focus_keyword, $title_template, $desc_template, $content) {

        $plain_content = wp_strip_all_tags($content);
        $excerpt = mb_substr($plain_content, 0, 160);
        if (mb_strlen($plain_content) > 160) {
            $last_space = mb_strrpos($excerpt, ' ');
            if ($last_space !== false) $excerpt = mb_substr($excerpt, 0, $last_space) . '...';
        }

        $site_name = get_bloginfo('name');

        $seo_title = str_replace(
            ['{title}', '{keyword}', '{site_name}'],
            [$title, $focus_keyword, $site_name],
            $title_template
        );

        $seo_description = str_replace(
            ['{title}', '{keyword}', '{excerpt}', '{site_name}'],
            [$title, $focus_keyword, $excerpt, $site_name],
            $desc_template
        );

        if (empty(trim($seo_description))) {
            $seo_description = "Learn everything about {$focus_keyword}. " . mb_substr($plain_content, 0, 120) . '...';
        }

        $seo_title       = mb_substr($seo_title, 0, 60);
        $seo_description = mb_substr($seo_description, 0, 160);

        error_log('AAB SEO: Saving meta - Title: ' . $seo_title);
        error_log('AAB SEO: Saving meta - Desc: ' . $seo_description);
        error_log('AAB SEO: Saving meta - Keyword: ' . $focus_keyword);

        // Yoast SEO
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') || function_exists('wpseo_init')) {
            update_post_meta($post_id, '_yoast_wpseo_title',       $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc',    $seo_description);
            update_post_meta($post_id, '_yoast_wpseo_focuskw',     $focus_keyword);
            update_post_meta($post_id, '_yoast_wpseo_opengraph-title',       $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $seo_description);
            update_post_meta($post_id, '_yoast_wpseo_twitter-title',         $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_twitter-description',   $seo_description);
            error_log('AAB SEO: ✅ Saved to Yoast SEO');
        }

        // Rank Math
        if (class_exists('RankMath') || class_exists('\\RankMath\\Post') || defined('RANK_MATH_VERSION')) {
            update_post_meta($post_id, 'rank_math_title',            $seo_title);
            update_post_meta($post_id, 'rank_math_description',      $seo_description);
            update_post_meta($post_id, 'rank_math_focus_keyword',    $focus_keyword);
            update_post_meta($post_id, 'rank_math_og_title',         $seo_title);
            update_post_meta($post_id, 'rank_math_og_description',   $seo_description);
            update_post_meta($post_id, 'rank_math_twitter_title',    $seo_title);
            update_post_meta($post_id, 'rank_math_twitter_description', $seo_description);
            error_log('AAB SEO: ✅ Saved to Rank Math');
        }

        // All in One SEO (AIOSEO)
        if (class_exists('AIOSEO\\Plugin\\AIOSEO') || defined('AIOSEO_VERSION')) {
            update_post_meta($post_id, '_aioseo_title',       $seo_title);
            update_post_meta($post_id, '_aioseo_description', $seo_description);
            update_post_meta($post_id, '_aioseo_keywords',    $focus_keyword);
            error_log('AAB SEO: ✅ Saved to AIOSEO');
        }

        // Fallback generic meta
        update_post_meta($post_id, 'aab_seo_title',       $seo_title);
        update_post_meta($post_id, 'aab_seo_description', $seo_description);
        update_post_meta($post_id, 'aab_seo_keyword',     $focus_keyword);

        error_log('AAB SEO: ✅ All meta saved for post ' . $post_id);
    }

    // ═══════════════════════════════════════════════════════
    // SEO HELPER: Inject keyword into ALL image ALT tags
    // ═══════════════════════════════════════════════════════
    private static function inject_seo_alt_tags($content, $focus_keyword) {
        if (empty($focus_keyword) || empty($content)) return $content;

        $keyword_safe = esc_attr($focus_keyword);

        $content = preg_replace_callback(
            '/<img([^>]+)>/i',
            function($matches) use ($keyword_safe) {
                $img_attrs = $matches[1];
                $img_attrs = preg_replace('/\s+alt="[^"]*"/i', '', $img_attrs);
                $img_attrs = preg_replace("/\s+alt='[^']*'/i", '', $img_attrs);
                $img_attrs = preg_replace('/\s+title="[^"]*"/i', '', $img_attrs);
                $img_attrs .= ' alt="' . $keyword_safe . '" title="' . $keyword_safe . '"';
                return '<img' . $img_attrs . '>';
            },
            $content
        );

        return $content;
    }

    // ═══════════════════════════════════════════════════════
    // SEO HELPER: Inject internal links into content
    // ═══════════════════════════════════════════════════════
    private static function inject_internal_links($post_id, $content, $focus_keyword, $count = 3) {
        $related_posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $count + 2,
            'post__not_in'   => [$post_id],
            's'              => $focus_keyword,
            'orderby'        => 'relevance',
        ]);

        if (empty($related_posts)) {
            $related_posts = get_posts([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $count,
                'post__not_in'   => [$post_id],
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
        }

        if (empty($related_posts)) return $content;

        $paragraphs = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $links_inserted   = 0;
        $total_paragraphs = count($paragraphs);

        $insert_positions = [];
        if ($count >= 1) $insert_positions[] = intval($total_paragraphs * 0.25);
        if ($count >= 2) $insert_positions[] = intval($total_paragraphs * 0.55);
        if ($count >= 3) $insert_positions[] = intval($total_paragraphs * 0.80);

        $rebuilt    = '';
        $post_index = 0;

        for ($i = 0; $i < count($paragraphs); $i++) {
            $rebuilt .= $paragraphs[$i];
            if (in_array($i, $insert_positions) && $post_index < count($related_posts) && $links_inserted < $count) {
                $related  = $related_posts[$post_index];
                $rebuilt .= '<p class="aab-internal-link">📖 Also read: <a href="' . get_permalink($related->ID) . '" title="' . esc_attr($related->post_title) . '">' . esc_html($related->post_title) . '</a></p>';
                $post_index++;
                $links_inserted++;
            }
        }

        return $rebuilt;
    }

    /**
     * Primary provider call - OpenAI, Claude, Gemini only
     */
    private static function call_provider($prompt, $token_limit = null, $extra = [])
    {
        $provider = get_option('aab_ai_provider', 'openai');
        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB DEBUG: Starting API call');
        error_log('AAB DEBUG: Provider selected: ' . $provider);

        if ($provider === 'openai')       $model = get_option('aab_openai_model', 'gpt-4o');
        elseif ($provider === 'claude')   $model = get_option('aab_claude_model', 'claude-sonnet-4-20250514');
        elseif ($provider === 'gemini')   $model = get_option('aab_gemini_model', 'gemini-2.0-flash-exp');
        else {
            error_log('AAB ERROR: Unknown provider: ' . $provider);
            return false;
        }

        error_log('AAB DEBUG: Model: ' . $model);

        $token_limit = $token_limit ?: 4500;

        $api_key = '';
        if ($provider === 'openai') {
            $api_key = get_option('aab_openai_key', '');
            if (empty($api_key)) $api_key = get_option('aab_api_key', '');
        } elseif ($provider === 'claude') {
            $api_key = get_option('aab_claude_key', '');
        } elseif ($provider === 'gemini') {
            $api_key = get_option('aab_gemini_key', '');
        }

        if (empty($api_key)) {
            error_log('AAB ERROR: API key missing for provider: ' . $provider);
            return false;
        }

        $headers  = ['Content-Type' => 'application/json'];
        $body     = [];
        $endpoint = '';

        if ($provider === 'openai') {
            $is_gpt5 = stripos($model, 'gpt-5') !== false;
            if ($is_gpt5) {
                $endpoint = 'https://api.openai.com/v1/responses';
                $body = ['model' => $model, 'input' => $prompt, 'max_output_tokens' => $token_limit];
            } else {
                $endpoint = 'https://api.openai.com/v1/chat/completions';
                $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => $token_limit];
            }
            if (isset($extra['temperature'])) $body['temperature'] = floatval($extra['temperature']);
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }
        elseif ($provider === 'claude') {
            $endpoint = 'https://api.anthropic.com/v1/messages';
            $body = ['model' => $model, 'max_tokens' => $token_limit, 'messages' => [['role' => 'user', 'content' => $prompt]]];
            if (isset($extra['temperature'])) $body['temperature'] = floatval($extra['temperature']);
            $headers['x-api-key'] = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
        }
        elseif ($provider === 'gemini') {
            $endpoint   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
            $body       = ['contents' => [['parts' => [['text' => $prompt]]]]];
            $gen_config = ['maxOutputTokens' => $token_limit];
            if (isset($extra['temperature'])) $gen_config['temperature'] = floatval($extra['temperature']);
            $body['generationConfig'] = $gen_config;
        }
        else {
            return false;
        }

        error_log('AAB DEBUG: Endpoint: ' . substr($endpoint, 0, 100));
        error_log('AAB DEBUG: Sending request...');

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            error_log('AAB ERROR: WP HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        error_log('AAB DEBUG: HTTP status code: ' . $code);

        if ($code < 200 || $code >= 300) {
            error_log('AAB ERROR: Non-2xx HTTP response code: ' . $code);
            return false;
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('AAB ERROR: JSON decode failed');
            return false;
        }

        if ($provider === 'openai') {
            if (!empty($data['choices'][0]['message']['content'])) return ['raw' => $raw, 'content' => $data['choices'][0]['message']['content']];
            if (!empty($data['output_text'])) return ['raw' => $raw, 'content' => $data['output_text']];
            if (!empty($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $outItem) {
                    if (!empty($outItem['content']) && is_array($outItem['content'])) {
                        foreach ($outItem['content'] as $block) {
                            if (!empty($block['text'])) return ['raw' => $raw, 'content' => $block['text']];
                        }
                    }
                    if (!empty($outItem['output_text'])) return ['raw' => $raw, 'content' => $outItem['output_text']];
                }
            }
        }
        if ($provider === 'claude') {
            if (!empty($data['content'][0]['text'])) return ['raw' => $raw, 'content' => $data['content'][0]['text']];
        }
        if ($provider === 'gemini') {
            if (!empty($data['candidates'][0]['content']['parts'][0]['text'])) return ['raw' => $raw, 'content' => $data['candidates'][0]['content']['parts'][0]['text']];
        }

        error_log('AAB ERROR: No content found in response');
        return false;
    }

    private static function words_to_token_limit($words)
    {
        $words  = max(50, intval($words));
        $tokens = intval(ceil($words * 1.5));
        $cap    = 10000;
        if ($tokens > $cap) $tokens = $cap;
        return $tokens;
    }

    private static function normalize_raw_html($raw)
    {
        $content = trim($raw);
        if (self::looks_like_json($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $found   = self::recursive_find_content($decoded);
                $content = ($found !== false) ? $found : '';
            }
        }
        if (stripos($content, '<html') !== false || stripos($content, '<!doctype') !== false) {
            if (preg_match('/<article[^>]*>(.*?)<\/article>/is', $content, $m)) {
                $content = $m[1];
            } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $m)) {
                $content = $m[1];
            } else {
                $content = preg_replace('/^.*<\/head>/is', '', $content);
            }
        }
        $content = self::strip_document_tags($content);
        return trim($content);
    }

    private static function strip_document_tags($html)
    {
        if (!is_string($html)) return $html;
        $html = preg_replace('/<(\/?)(html|head|meta|title|link|script|style|!DOCTYPE)[^>]*>/i', '', $html);
        return trim($html);
    }

    private static function append_html_fragment($orig, $frag)
    {
        $orig = trim($orig);
        $frag = trim($frag);
        if ($orig === '') return $frag;
        if ($frag === '') return $orig;
        $orig = preg_replace('/<\/article>\s*$/i', '', $orig);
        $frag = preg_replace('/^\s*<article[^>]*>/i', '', $frag);
        return $orig . "\n\n" . $frag;
    }

    private static function truncate_html_by_words($html, $max_words)
    {
        $html = trim($html);
        if ($html === '') return $html;
        $plain = self::plain_text($html);
        if (self::word_count_from_text($plain) <= $max_words) return $html;
        $pattern = '/<(article|section|h[1-6]|p|div|ul|ol)[^>]*>.*?<\/\\1>/is';
        preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
        $result    = '';
        $words_acc = 0;
        if (!empty($matches[0])) {
            foreach ($matches[0] as $m) {
                $block_html  = $m[0];
                $block_text  = self::plain_text($block_html);
                $block_words = self::word_count_from_text($block_text);
                if ($words_acc + $block_words <= $max_words) {
                    $result    .= $block_html;
                    $words_acc += $block_words;
                    if ($words_acc >= $max_words) break;
                } else {
                    $remaining = $max_words - $words_acc;
                    if ($remaining <= 0) break;
                    $trimmed = self::trim_text_to_words($block_text, $remaining);
                    if ($trimmed !== '') $result .= '<p>' . esc_html($trimmed) . '</p>';
                    break;
                }
            }
        } else {
            $trimmed = self::trim_text_to_words($plain, $max_words);
            return '<p>' . esc_html($trimmed) . '</p>';
        }
        return $result ?: '<p>' . esc_html(self::trim_text_to_words($plain, $max_words)) . '</p>';
    }

    private static function trim_text_to_words($text, $max_words)
    {
        $text      = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $words     = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= $max_words) return implode(' ', $words);
        $slice     = array_slice($words, 0, $max_words);
        $candidate = implode(' ', $slice);
        $last_pos  = max(strrpos($candidate, '.'), strrpos($candidate, '?'), strrpos($candidate, '!'));
        if ($last_pos !== false && $last_pos > 10) return rtrim(substr($candidate, 0, $last_pos + 1));
        return rtrim($candidate, ',;:- ');
    }

    private static function plain_text($html)
    {
        $plain = wp_strip_all_tags($html);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', trim($plain));
        return $plain;
    }

    private static function word_count_from_text($text)
    {
        if (trim($text) === '') return 0;
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function looks_like_internal_id($str)
    {
        if (!is_string($str)) return false;
        return (bool) preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($str)));
    }

    private static function looks_like_json($s)
    {
        if (!is_string($s)) return false;
        $s = trim($s);
        return (substr($s, 0, 1) === '{' || substr($s, 0, 1) === '[');
    }

    private static function response_status_from_raw($raw)
    {
        if (!is_string($raw) || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;
        if (!empty($decoded['status']) && is_string($decoded['status'])) return $decoded['status'];
        if (!empty($decoded['output']) && is_array($decoded['output'])) {
            foreach ($decoded['output'] as $out) {
                if (!empty($out['status']) && is_string($out['status'])) return $out['status'];
            }
        }
        return null;
    }

    private static function recursive_find_content($var)
    {
        $candidates = [];
        self::recursive_collect_strings($var, $candidates);
        if (empty($candidates)) return false;
        foreach ($candidates as $item) {
            if (preg_match('/<\\/?(h1|h2|h3|p|ul|li|article|section|div|strong|em)/i', $item)) return $item;
        }
        usort($candidates, function ($a, $b) { return strlen($b) - strlen($a); });
        if (strlen($candidates[0]) >= 30) return $candidates[0];
        return false;
    }

    private static function recursive_collect_strings($var, array &$out)
    {
        if (is_string($var)) {
            $s = trim($var);
            if ($s !== '') $out[] = $s;
            return;
        }
        if (is_array($var)) {
            foreach ($var as $v) self::recursive_collect_strings($v, $out);
            return;
        }
        if (is_object($var)) {
            foreach ((array)$var as $v) self::recursive_collect_strings($v, $out);
        }
    }
}