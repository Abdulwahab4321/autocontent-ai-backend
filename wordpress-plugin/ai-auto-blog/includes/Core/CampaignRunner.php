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

        // ============================================================
        // LOGGING ADDITION: Check campaign status - don't run trashed/deleted campaigns
        // ============================================================
        $campaign = get_post($campaign_id);
        if (!$campaign || $campaign->post_status !== 'publish') {
            error_log('AAB DEBUG: Campaign ' . $campaign_id . ' is not published (status: ' . ($campaign ? $campaign->post_status : 'null') . ') - aborting run');
            return;
        }

        $enabled = get_post_meta($campaign_id, 'aab_enabled', true) ? true : false;
        $paused  = get_post_meta($campaign_id, 'aab_pause_autorun', true) ? true : false;

        // Detect whether this invocation is an explicit "run now" / manual invocation.
        // We check several possible signals (AJAX param, generic run_now param, CLI, admin context with run flag).
        $user_requested_force = false;
        try {
            if (php_sapi_name() === 'cli') {
                $user_requested_force = true;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        if (!$user_requested_force) {
            if (defined('DOING_AJAX') && DOING_AJAX && !empty($_REQUEST['aab_run_now'])) $user_requested_force = true;
            if (!$user_requested_force && !empty($_REQUEST['aab_run_now'])) $user_requested_force = true;
            if (!$user_requested_force && !empty($_REQUEST['run_now'])) $user_requested_force = true;
            if (!$user_requested_force && is_admin() && current_user_can('manage_options') && (!empty($_REQUEST['aab_run_now']) || !empty($_REQUEST['run_now']))) $user_requested_force = true;
        }
        error_log('AAB TRACE: run() force-run-detected=' . intval($user_requested_force) . ' for campaign ' . $campaign_id);

        // Respect enabled flag unless this was a manual "run now" request.
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

        // === NEW: Load runtime flags relevant for keyword logic and tracing ===
        $keyword_as_title = get_post_meta($campaign_id, 'keyword_as_title', true) ? true : false;
        $one_post_per_keyword = get_post_meta($campaign_id, 'one_post_per_keyword', true) ? true : false;
        $rotate_keywords = get_post_meta($campaign_id, 'rotate_keywords', true) ? true : false;
        $remove_links_flag = get_post_meta($campaign_id, 'aab_remove_links', true) ? true : false;
        $links_new_tab_flag = get_post_meta($campaign_id, 'aab_links_new_tab', true) ? true : false;
        $links_nofollow_flag = get_post_meta($campaign_id, 'aab_links_nofollow', true) ? true : false;
        $alt_from_title_all = get_post_meta($campaign_id, 'aab_alt_from_title_all', true) ? true : false;
        $alt_from_title_empty = get_post_meta($campaign_id, 'aab_alt_from_title_empty', true) ? true : false;

        error_log('AAB TRACE: Campaign flags for ' . $campaign_id . ' => keyword_as_title=' . intval($keyword_as_title) . ', one_post_per_keyword=' . intval($one_post_per_keyword) . ', rotate_keywords=' . intval($rotate_keywords) . ', remove_links=' . intval($remove_links_flag) . ', links_new_tab=' . intval($links_new_tab_flag) . ', links_nofollow=' . intval($links_nofollow_flag) . ', alt_from_title_all=' . intval($alt_from_title_all) . ', alt_from_title_empty=' . intval($alt_from_title_empty));

        // Normalized keyword list (trimmed)
        $keywords_clean = array_values(array_filter(array_map(function($k){ return is_string($k) ? trim($k) : ''; }, $keywords)));

        // Track used keywords (normalized lowercase) for one-post-per-keyword enforcement
        $used_raw = get_post_meta($campaign_id, 'aab_keywords_done', true);
        $used_raw = is_array($used_raw) ? $used_raw : (strlen((string)$used_raw) ? (array)$used_raw : []);
        $used_norm = array_map('mb_strtolower', array_map('trim', $used_raw));

        error_log('AAB TRACE: campaign ' . $campaign_id . ' has ' . count($keywords_clean) . ' keywords; posts_run=' . $posts_run . '; used_keywords_count=' . count($used_norm) . ( $max_posts ? '; max_posts=' . $max_posts : '' ) );

        // Determine keyword
        // Keep original selection as fallback (do not delete existing logic) then override below.
        $keyword = $keywords[$posts_run % count($keywords)];

        // New improved selection logic:
        $selected_keyword = null;

        // If one_post_per_keyword is active, compute remaining keywords
        if ($one_post_per_keyword) {
            $remaining = [];
            foreach ($keywords_clean as $k) {
                if (!in_array(mb_strtolower($k), $used_norm, true) && $k !== '') {
                    $remaining[] = $k;
                }
            }

            if (empty($remaining)) {
                // All keywords used -> mark campaign completed and set completed meta (do not rely solely on aab_enabled for UI)
                error_log('AAB INFO: All keywords used for campaign ' . $campaign_id . '. Marking campaign completed.');
                update_post_meta($campaign_id, 'aab_status', 'completed');
                update_post_meta($campaign_id, 'aab_completed', 1);
                update_post_meta($campaign_id, 'aab_disabled_reason', 'completed');
                // Also disable autorun to stop further scheduled runs, but keep status meta so UI can detect completed.
                update_post_meta($campaign_id, 'aab_enabled', 0);
                if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                    CampaignScheduler::schedule_or_unschedule($campaign_id);
                }
                return;
            }

            // If rotate_keywords + we have stored order, prefer using that order to pick next unused keyword deterministically per the order
            if ($rotate_keywords) {
                $order = get_post_meta($campaign_id, 'aab_keyword_order', true);
                $order = is_array($order) ? $order : [];
                // Normalize order coherence: if missing or different size, generate new randomized order
                if (count($order) !== count($keywords_clean)) {
                    $order = $keywords_clean;
                    shuffle($order);
                    update_post_meta($campaign_id, 'aab_keyword_order', $order);
                    error_log('AAB TRACE: generated random keyword order for campaign ' . $campaign_id . ' => ' . json_encode($order));
                }
                // find first in order not used
                foreach ($order as $k) {
                    if (!in_array(mb_strtolower(trim($k)), $used_norm, true)) {
                        $selected_keyword = $k;
                        break;
                    }
                }
                if ($selected_keyword === null) {
                    // fallback: use first remaining
                    $selected_keyword = $remaining[0];
                }
            } else {
                // no rotation requested, just pick first remaining
                $selected_keyword = $remaining[0];
            }
        } else {
            // not one_post_per_keyword
            if ($rotate_keywords) {
                // Use stored randomized order to avoid sequential 1=>2=>3 behaviour
                $order = get_post_meta($campaign_id, 'aab_keyword_order', true);
                $order = is_array($order) ? $order : [];
                if (count($order) !== count($keywords_clean)) {
                    $order = $keywords_clean;
                    shuffle($order);
                    update_post_meta($campaign_id, 'aab_keyword_order', $order);
                    error_log('AAB TRACE: generated random keyword order for campaign ' . $campaign_id . ' => ' . json_encode($order));
                }
                // pick index by posts_run to have a pseudo-random sequence (permutation cycling)
                $idx = $posts_run % count($order);
                $selected_keyword = $order[$idx];
            } else {
                // preserve original behavior (sequential rotation/cycling)
                $selected_keyword = $keywords[$posts_run % count($keywords)];
            }
        }

        if ($selected_keyword === null || trim($selected_keyword) === '') {
            // fallback to original naive selection if something went wrong
            $selected_keyword = $keyword;
        }

        // finalize keyword
        $keyword = $selected_keyword;
        error_log('AAB TRACE: selected keyword index=' . $posts_run . ' keyword="' . $keyword . '" (rotate_keywords flag=' . intval($rotate_keywords) . ', one_post_per_keyword=' . intval($one_post_per_keyword) . ')');

        // ============================================================
        // WRAP THE MAIN GENERATION LOGIC IN TRY-CATCH FOR ERROR LOGGING
        // ============================================================
        try {
            // Build prompt pieces
            $title_prompt   = get_post_meta($campaign_id, 'custom_title_prompt', true) ?: '';
            $content_prompt = get_post_meta($campaign_id, 'custom_content_prompt', true) ?: '';

            $min_words = intval(get_post_meta($campaign_id, 'min_words', true) ?: 0);
            $max_words = intval(get_post_meta($campaign_id, 'max_words', true) ?: 0);

            // Compose length instructions
            $length_instr = '';
            if ($min_words > 0 && $max_words > 0) {
                $length_instr = "Target length: produce between {$min_words} and {$max_words} words of article content (words only, not counting HTML tags). Aim for a natural article length within that range.";
            } elseif ($min_words > 0) {
                $length_instr = "Target length: produce at least {$min_words} words of article content (words only, not counting HTML tags).";
            } elseif ($max_words > 0) {
                $length_instr = "Target length: produce no more than {$max_words} words of article content (words only, not counting HTML tags).";
            } else {
                $length_instr = "Target length: produce a typical long-form article (roughly 800-1500 words).";
            }

            // Word->token heuristic: tokens ~ words * 1.5 (conservative)
            $desired_words = $max_words > 0 ? $max_words : max($min_words, 1200);
            $token_limit = self::words_to_token_limit($desired_words);

            // Apply campaign AI overrides if set
            $ai_custom = get_post_meta($campaign_id, 'aab_ai_custom_params', true) ? true : false;
            $ai_max_tokens = intval(get_post_meta($campaign_id, 'aab_ai_max_tokens', true) ?: 0);
            $ai_temperature = floatval(get_post_meta($campaign_id, 'aab_ai_temperature', true) ?: 0.0);

            if ($ai_custom && $ai_max_tokens > 0) {
                // If user set a direct token limit, respect it (cap to safe upper bound)
                $token_limit = intval(min($ai_max_tokens, 10000));
                error_log('AAB DEBUG: CampaignRunner::run() - overriding token_limit with campaign ai_max_tokens=' . $token_limit);
            }

            $extra_options = [];
            if ($ai_custom && $ai_temperature >= 0.0) {
                $extra_options['temperature'] = $ai_temperature;
                error_log('AAB DEBUG: CampaignRunner::run() - using custom temperature ' . $ai_temperature);
            }

            // Build combined prompt with explicit length guidance
            $prompt = "You are a professional SEO content writer. Produce a single blog article in valid HTML.
Do NOT include any extra commentary outside the HTML.
Structure requirements:
- Top-level title must be inside <h1> ... </h1>.
- Include an introductory <p>.
- Use at least two <h2> sections and optionally <h3> subsections.
- Use lists <ul><li> for key points where relevant.
- Keep language natural and human, avoid 'As an AI' lines.
- Use the KEYWORD: {$keyword} within title and naturally in content.
{$length_instr}
If you reach a token limit and must stop early, end at a sentence boundary and append the single token: CONTINUE
(so the system can request continuation). Do NOT output any <html>, <head>, <body>, <!DOCTYPE> or <title> tags.
If custom prompts exist, apply them precisely:
Title instructions: {$title_prompt}
Content instructions: {$content_prompt}
Return ONLY the article HTML (blocks: <h1>, <h2>, <h3>, <p>, <ul>, <ol>, <li>, <article>, <section>).";

            // First API call: call_provider now returns ['raw'=>..., 'content'=>...]
            $prov = self::call_provider($prompt, $token_limit, $extra_options);
            if (!$prov || empty($prov['raw'])) {
                error_log('AAB ERROR: call_provider returned empty for campaign ' . $campaign_id);
                
                // ============================================================
                // LOGGING: Log API failure
                // ============================================================
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log(
                        $campaign_id,
                        'ERROR',
                        'API call failed - empty response',
                        '',
                        ['error' => 'call_provider returned empty']
                    );
                }
                
                return;
            }

            $raw_body = (string)$prov['raw'];
            error_log('AAB DEBUG: Raw provider length: ' . strlen($raw_body));

            // Use the extracted content if call_provider provided it; otherwise normalize raw body
            $content_html = (isset($prov['content']) && trim((string)$prov['content']) !== '') ? (string)$prov['content'] : self::normalize_raw_html($raw_body);

            // === NEW: If provider status is "incomplete" try status-driven continuations ===
            $status = self::response_status_from_raw($raw_body);
            $status_attempts = 0;
            $max_status_attempts = 6; // slightly more attempts to support long articles
            $did_force_status_cont = false;

            if ($status === 'incomplete') {
                error_log("AAB DEBUG: initial provider status=INCOMPLETE for campaign {$campaign_id} - attempting status-driven continuations.");
                $did_force_status_cont = true;

                // Continuation loop: try to continue until provider reports completed or we exhaust attempts
                while ($status === 'incomplete' && $status_attempts < $max_status_attempts) {
                    $status_attempts++;

                    // compute current word count and remaining target
                    $plain_current = self::plain_text($content_html);
                    $current_words = self::word_count_from_text($plain_current);
                    // desired target: prefer max_words if set, otherwise prefer the 5000 support ceiling
                    $target_words = $max_words > 0 ? $max_words : 5000;
                    $remaining_words = max(150, $target_words - $current_words);

                    // Pick a continuation token budget (be generous)
                    $cont_token_limit = self::words_to_token_limit( max(200, intval(ceil($remaining_words * 1.4))) );
                    if ($ai_custom && $ai_max_tokens > 0) {
                        $cont_token_limit = min($cont_token_limit, $ai_max_tokens);
                    } elseif ($cont_token_limit > 9000) {
                        $cont_token_limit = 9000;
                    }

                    // Provide context snippet to the continuation so model continues the same piece.
                    // Prefer the last headings + paragraphs (truncate to avoid huge payload)
                    $context_for_prompt = strip_tags($content_html);
                    if (strlen($context_for_prompt) > 2500) {
                        // try to keep last 2500 chars
                        $context_for_prompt = substr($context_for_prompt, -2500);
                    }
                    if (trim($context_for_prompt) === '') {
                        $context_for_prompt = "Title/keyword: {$keyword}. The article so far is missing (no visible HTML fragment). Continue the article for the topic '{$keyword}'.";
                    }

                    // Status-driven continuation prompt — keyword adaptive and strict
                    $continue_prompt = <<<EOT
The previous response was truncated by token limits and returned status=INCOMPLETE.

STRICT RULES (MANDATORY):
- Do NOT introduce new topics.
- Continue ONLY the topic: {$keyword}.
- If unsure what to write next, expand the last relevant section or add a directly-related troubleshooting/technical section.
- Do NOT add unrelated content, promotional content, or sections about unrelated domains.

Previous content (truncated, plaintext):
{$context_for_prompt}

Goal: Add roughly {$remaining_words} more words (give or take) toward the article target (do not exceed the target).
Return ONLY valid HTML fragments (<p>, <h2>, <h3>, <ul>, <li>, <section>) and end at a sentence boundary. If you must stop because of token limits, append the single token: CONTINUE
EOT;

                    error_log("AAB DEBUG: status-driven continuation attempt {$status_attempts} with token_limit={$cont_token_limit} (need {$remaining_words} words, current {$current_words}) for campaign {$campaign_id}.");

                    $contProv = self::call_provider($continue_prompt, $cont_token_limit, $extra_options);

                    if (!$contProv || empty($contProv['raw'])) {
                        error_log("AAB DEBUG: status continuation returned empty on attempt {$status_attempts} for campaign {$campaign_id}.");
                        sleep(1);
                        $status = self::response_status_from_raw($contProv['raw'] ?? '');
                        continue;
                    }

                    $cont_raw = (string)$contProv['raw'];
                    $cont_html = (isset($contProv['content']) && trim((string)$contProv['content']) !== '') ? (string)$contProv['content'] : self::normalize_raw_html($cont_raw);

                    // fallback extraction for continuation if needed
                    if (trim($cont_html) === '') {
                        if (self::looks_like_json($cont_raw)) {
                            $dec = json_decode($cont_raw, true);
                            if (is_array($dec)) {
                                $foundc = self::recursive_find_content($dec);
                                if ($foundc !== false) {
                                    $cont_html = $foundc;
                                    error_log('AAB DEBUG: continuation recursive_find_content found content on attempt ' . $status_attempts);
                                }
                            }
                        }
                        if (trim($cont_html) === '') {
                            $rawc = trim((string)$cont_raw);
                            if ($rawc !== '' && !self::looks_like_internal_id($rawc)) {
                                if (strip_tags($rawc) !== $rawc) {
                                    $cont_html = $rawc;
                                } else {
                                    $cont_html = '<p>' . esc_html(substr($rawc, 0, 2000)) . '</p>';
                                }
                                error_log('AAB DEBUG: continuation fallback used raw text on attempt ' . $status_attempts);
                            }
                        }
                    }

                    // Ensure not internal-id-only
                    if (preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($cont_html)))) {
                        error_log('AAB DEBUG: continuation returned only internal id on status attempt ' . $status_attempts);
                        $status = self::response_status_from_raw($cont_raw);
                        continue;
                    }

                    // Append the fragment to the main content
                    $content_html = self::append_html_fragment($content_html, $cont_html);

                    // Update status from the continuation response
                    $status = self::response_status_from_raw($cont_raw);

                    error_log("AAB DEBUG: after status-driven cont attempt {$status_attempts}, provider status is '" . ($status ?: 'null') . "' for campaign {$campaign_id}.");

                    // If provider returned completed (or no longer 'incomplete'), break out
                    if ($status !== 'incomplete') break;
                } // end status continuation loop
            }

            // If after status attempts still incomplete -> CREATE DRAFT and do NOT publish
            if ($did_force_status_cont && $status === 'incomplete') {
                error_log("AAB ERROR: Provider still INCOMPLETE after {$status_attempts} attempts for campaign {$campaign_id}. Creating draft (incomplete) instead of publishing.");

                // sanitize and fallback as before, but do not increment posts_run
                $safe_content = wp_kses_post(self::strip_document_tags($content_html));
                $safe_content = preg_replace('/&lt;\/?(title|html|head|body)&gt;/i', '', $safe_content);

                if (trim(strip_tags($safe_content)) === '') {
                    error_log('AAB DEBUG: wp_kses_post stripped content to empty when preparing incomplete draft for campaign ' . $campaign_id . ' — trying fallback.');
                    $plain_fallback = self::plain_text($content_html);
                    if (trim($plain_fallback) === '') {
                        $plain_fallback = trim((string)$raw_body);
                    }
                    if ($plain_fallback !== '' && !self::looks_like_internal_id($plain_fallback)) {
                        $safe_content = '<p>' . esc_html($plain_fallback) . '</p>';
                        error_log('AAB DEBUG: final fallback created minimal HTML for incomplete draft for campaign ' . $campaign_id . '.');
                    } else {
                        error_log('AAB ERROR: Unable to create any content for incomplete draft for campaign ' . $campaign_id);
                        
                        // ============================================================
                        // LOGGING: Log incomplete draft failure
                        // ============================================================
                        if (class_exists('\\AAB\\Core\\Logger')) {
                            \AAB\Core\Logger::log(
                                $campaign_id,
                                'ERROR',
                                'Incomplete response - unable to generate content',
                                '',
                                ['attempts' => $status_attempts, 'reason' => 'empty_content_after_sanitization']
                            );
                        }
                        
                        return;
                    }
                }

                // Extract title if present, otherwise keyword
                $title = $keyword;
                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $safe_content, $m)) {
                    $title = wp_strip_all_tags($m[1]);
                    $safe_content = preg_replace('/<h1[^>]*>.*?<\/h1>\s*/is', '', $safe_content, 1);
                } else {
                    if (get_post_meta($campaign_id, 'keyword_as_title', true)) {
                        $title = $keyword;
                    }
                }

                // Create the draft post (incomplete flag/meta added)
                $post_arr = [
                    'post_title'   => $title,
                    'post_content' => $safe_content,
                    'post_status'  => 'draft', // keep it draft for user review
                    'post_author'  => get_current_user_id() ?: 1,
                    'post_type'    => get_post_meta($campaign_id, 'aab_post_type', true) ?: 'post',
                    'meta_input'   => [
                        'aab_generated_campaign' => $campaign_id,
                        'aab_campaign_id' => $campaign_id,
                        'aab_incomplete' => 1,
                        'aab_incomplete_attempts' => $status_attempts,
                        'aab_incomplete_reason' => 'provider_incomplete',
                    ],
                ];

                $post_id = wp_insert_post($post_arr);
                if (is_wp_error($post_id) || !$post_id) {
                    error_log('AAB ERROR: wp_insert_post failed for incomplete-draft (campaign ' . $campaign_id . ') error:' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'));
                    
                    // ============================================================
                    // LOGGING: Log draft creation failure
                    // ============================================================
                    if (class_exists('\\AAB\\Core\\Logger')) {
                        \AAB\Core\Logger::log(
                            $campaign_id,
                            'ERROR',
                            'Failed to create draft post',
                            '',
                            ['error' => is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown']
                        );
                    }
                    
                    return;
                }

                // Do not increment aab_posts_run so campaign can try again in next cycle.
                update_post_meta($campaign_id, 'aab_last_run', time());
                if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                    CampaignScheduler::schedule_or_unschedule($campaign_id);
                }

                error_log('AAB INFO: Created draft post ' . $post_id . ' for incomplete response (campaign ' . $campaign_id . ').');
                
                // ============================================================
                // LOGGING: Log incomplete draft creation (warning, not error)
                // ============================================================
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log(
                        $campaign_id,
                        'WARNING',
                        'Created draft (incomplete): ' . $title,
                        get_permalink($post_id),
                        ['post_id' => $post_id, 'attempts' => $status_attempts, 'status' => 'draft']
                    );
                }
                
                return;
            }

            // === Continue with existing min_words continuation logic if still under min_words ===

            // Avoid internal-id-only returns
            if (preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($content_html)))) {
                error_log('AAB ERROR: Provider returned only an internal id, not content.');
                
                // ============================================================
                // LOGGING: Log internal ID error
                // ============================================================
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log(
                        $campaign_id,
                        'ERROR',
                        'Provider returned only internal ID',
                        '',
                        ['content' => substr(strip_tags($content_html), 0, 200)]
                    );
                }
                
                return;
            }

            // If content indicates it ended with CONTINUE token, we may want to append continuation immediately.
            // Also, if min_words is set and content too short, perform continuation attempts up to N times.
            $plain = self::plain_text($content_html);
            $count = self::word_count_from_text($plain);

            $attempt = 0;
            $max_attempts_for_min = 5; // increased for robustness with long articles
            while ($attempt < $max_attempts_for_min && $min_words > 0 && $count < $min_words) {
                $remaining = max(50, $min_words - $count); // request reasonable chunk
                error_log("AAB DEBUG: content under min_words ({$count}/{$min_words}). continuation attempt #" . ($attempt + 1) . " need {$remaining} words.");

                // Keyword-adaptive continuation prompt for min_words
                $continue_prompt = <<<EOT
You are continuing an existing HTML article already in progress.

STRICT RULES (MANDATORY):
- Do NOT introduce new topics.
- Continue ONLY the topic: {$keyword}.
- If unsure what to write next, expand the LAST relevant section or add technical/troubleshooting details that directly relate to '{$keyword}'.
- Do NOT write about unrelated subjects (health, business, general tech not related to the keyword).

Add at least {$remaining} more words.
Return ONLY valid HTML fragments (<p>, <h2>, <h3>, <ul>, <li>, <section>, <ol>).
If you must stop due to token limits, end at a sentence boundary and append 'CONTINUE'.
EOT;

                // Choose token limit for this continuation based on remaining words (be generous)
                $cont_token_limit = self::words_to_token_limit(intval(ceil($remaining * 1.6)));
                // If campaign specified ai_max_tokens and it's smaller than cont_token_limit, respect it
                if ($ai_custom && $ai_max_tokens > 0) {
                    $cont_token_limit = min($cont_token_limit, $ai_max_tokens);
                }

                $contProv = self::call_provider($continue_prompt, $cont_token_limit, $extra_options);

                if (!$contProv || empty($contProv['raw'])) {
                    error_log('AAB DEBUG: continuation returned empty on attempt ' . ($attempt + 1));
                    $attempt++;
                    continue;
                }

                $cont_html = (isset($contProv['content']) && trim((string)$contProv['content']) !== '') ? (string)$contProv['content'] : self::normalize_raw_html((string)$contProv['raw']);

                // fallback for continuation as well
                if (trim($cont_html) === '') {
                    if (self::looks_like_json((string)$contProv['raw'])) {
                        $dec = json_decode((string)$contProv['raw'], true);
                        if (is_array($dec)) {
                            $foundc = self::recursive_find_content($dec);
                            if ($foundc !== false) {
                                $cont_html = $foundc;
                                error_log('AAB DEBUG: continuation recursive_find_content found content on attempt ' . ($attempt + 1));
                            }
                        }
                    }
                    if (trim($cont_html) === '') {
                        $rawc = trim((string)$contProv['raw']);
                        if ($rawc !== '' && !self::looks_like_internal_id($rawc)) {
                            if (strip_tags($rawc) !== $rawc) {
                                $cont_html = $rawc;
                            } else {
                                $cont_html = '<p>' . esc_html(substr($rawc, 0, 2000)) . '</p>';
                            }
                            error_log('AAB DEBUG: continuation fallback used raw text on attempt ' . ($attempt + 1));
                        }
                    }
                }

                // Prevent appending internal ids
                if (preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($cont_html)))) {
                    error_log('AAB DEBUG: continuation returned only internal id on attempt ' . ($attempt + 1));
                    $attempt++;
                    continue;
                }

                $content_html = self::append_html_fragment($content_html, $cont_html);
                $plain = self::plain_text($content_html);
                $count = self::word_count_from_text($plain);
                $attempt++;
            }

            // If content contains trailing "CONTINUE" token (the marker we asked for), attempt one more continuation
            if (preg_match('/\bCONTINUE\b\s*$/i', strip_tags($content_html))) {
                error_log('AAB DEBUG: Content ended with CONTINUE token — requesting continuation once.');
                $cont_prompt = "Continue the HTML article where it left off. Return valid HTML fragments only.";
                $contProv = self::call_provider($cont_prompt, self::words_to_token_limit(1000), $extra_options);
                if ($contProv && !empty($contProv['raw'])) {
                    $cont_html = (isset($contProv['content']) && trim((string)$contProv['content']) !== '') ? (string)$contProv['content'] : self::normalize_raw_html((string)$contProv['raw']);
                    if (!preg_match('/^(chatcmpl-|resp_|rs_|msg_)[A-Za-z0-9\-_]+$/i', trim(strip_tags($cont_html)))) {
                        $content_html = self::append_html_fragment(preg_replace('/\bCONTINUE\b\s*$/i','', $content_html), $cont_html );
                    }
                }
            }

            // Enforce max_words by truncating HTML blocks
            if ($max_words > 0) {
                $content_html = self::truncate_html_by_words($content_html, $max_words);
            } else {
                // If no campaign max_words, still enforce a safety ceiling of 5000 words for final output
                $plain_final_check = self::plain_text($content_html);
                if (self::word_count_from_text($plain_final_check) > 5000) {
                    $content_html = self::truncate_html_by_words($content_html, 5000);
                    error_log('AAB DEBUG: truncated content to safety ceiling of 5000 words for campaign ' . $campaign_id);
                }
            }

            // Remove any accidental doc-level tags
            $content_html = self::strip_document_tags($content_html);

            // Final sanitize
            $safe_content = wp_kses_post($content_html);
            $safe_content = preg_replace('/&lt;\/?(title|html|head|body)&gt;/i', '', $safe_content);

            // === NEW fallback if wp_kses_post stripped everything ===
            if (trim(strip_tags($safe_content)) === '') {
                error_log('AAB DEBUG: wp_kses_post stripped content to empty for campaign ' . $campaign_id . ' — attempting final fallback using plain text.');
                // try to salvage plain text from content_html or original raw
                $plain_fallback = self::plain_text($content_html);
                if (trim($plain_fallback) === '') {
                    $plain_fallback = trim((string)$raw_body);
                }
                if ($plain_fallback !== '' && !self::looks_like_internal_id($plain_fallback)) {
                    // create a minimal safe HTML
                    $safe_content = '<p>' . esc_html($plain_fallback) . '</p>';
                    error_log('AAB DEBUG: final fallback created minimal HTML for campaign ' . $campaign_id . '.');
                } else {
                    error_log('AAB ERROR: Final content empty after sanitization for campaign ' . $campaign_id);
                    
                    // ============================================================
                    // LOGGING: Log empty content error
                    // ============================================================
                    if (class_exists('\\AAB\\Core\\Logger')) {
                        \AAB\Core\Logger::log(
                            $campaign_id,
                            'ERROR',
                            'Content empty after sanitization',
                            '',
                            ['keyword' => $keyword]
                        );
                    }
                    
                    return;
                }
            }

            if (trim(strip_tags($safe_content)) === '') {
                error_log('AAB ERROR: Final content empty after sanitization for campaign ' . $campaign_id);
                
                // ============================================================
                // LOGGING: Log empty content error
                // ============================================================
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log(
                        $campaign_id,
                        'ERROR',
                        'Final content empty',
                        '',
                        ['keyword' => $keyword]
                    );
                }
                
                return;
            }

            // Extract title
            $title = $keyword;
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $safe_content, $m)) {
                $title = wp_strip_all_tags($m[1]);
            } else {
                if (get_post_meta($campaign_id, 'keyword_as_title', true)) {
                    $title = $keyword;
                }
            }

            // --- remove first top-level H1 from content to avoid duplicate title display ---
            // This strips only the first <h1>...</h1> occurrence (keeps other headings intact)
            $safe_content = preg_replace('/<h1[^>]*>.*?<\/h1>\s*/is', '', $safe_content, 1);

            // Insert post respecting campaign-level post settings if available
            $post_status = get_post_meta($campaign_id, 'aab_post_status', true) ?: 'draft';
            $post_type = get_post_meta($campaign_id, 'aab_post_type', true) ?: 'post';
            $campaign_author = intval(get_post_meta($campaign_id, 'aab_post_author', true) ?: 0);
            $author_to_use = $campaign_author && get_userdata($campaign_author) ? $campaign_author : (get_current_user_id() ?: 1);

            $post_arr = [
                'post_title'   => $title,
                'post_content' => $safe_content,
                'post_status'  => $post_status,
                'post_author'  => $author_to_use,
                'post_type'    => $post_type,
                'meta_input'   => [
                    'aab_generated_campaign' => $campaign_id,
                    'aab_campaign_id' => $campaign_id,
                ],
            ];

            $post_id = wp_insert_post($post_arr);

            if (is_wp_error($post_id) || !$post_id) {
                error_log('AAB ERROR: wp_insert_post failed for campaign ' . $campaign_id . ' error:' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'));
                
                // ============================================================
                // LOGGING: Log post creation failure
                // ============================================================
                if (class_exists('\\AAB\\Core\\Logger')) {
                    \AAB\Core\Logger::log(
                        $campaign_id,
                        'ERROR',
                        'Failed to create post: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'),
                        '',
                        ['keyword' => $keyword, 'title' => $title]
                    );
                }
                
                return;
            }

            // If campaign asked to set categories and post_type is 'post', apply categories
            $set_cat = get_post_meta($campaign_id, 'aab_set_category', true) ? true : false;
            if ($set_cat && $post_type === 'post') {
                $cat_ids = (array) get_post_meta($campaign_id, 'aab_categories', true);
                $cat_ids_clean = array_values(array_filter(array_map('intval', $cat_ids)));
                if (!empty($cat_ids_clean)) {
                    wp_set_post_categories($post_id, $cat_ids_clean, false);
                    error_log('AAB DEBUG: CampaignRunner::run() - set categories for post ' . $post_id . ' => ' . implode(',', $cat_ids_clean));
                }
            }

            // Log that we included campaign meta in the insert (it will be available during save_post)
            error_log('AAB DEBUG: CampaignRunner::run() - inserted post ' . $post_id . ' with meta_input for campaign ' . $campaign_id);

            // ============================================================
            // LOGGING: Log successful post creation
            // ============================================================
            if (class_exists('\\AAB\\Core\\Logger')) {
                \AAB\Core\Logger::log(
                    $campaign_id,
                    'SUCCESS',
                    $title,
                    get_permalink($post_id),
                    [
                        'post_id' => $post_id,
                        'keyword' => $keyword,
                        'word_count' => self::word_count_from_text(self::plain_text($safe_content)),
                        'status' => $post_status
                    ]
                );
            }

            // === NEW: If one_post_per_keyword is enabled, record this keyword as used ===
            if ($one_post_per_keyword) {
                $kw_norm = mb_strtolower(trim($keyword));
                if ($kw_norm !== '' && !in_array($kw_norm, $used_norm, true)) {
                    $used_norm[] = $kw_norm;
                    // store original cased variants for readability
                    $used_store = get_post_meta($campaign_id, 'aab_keywords_done', true);
                    $used_store = is_array($used_store) ? $used_store : (strlen((string)$used_store) ? (array)$used_store : []);
                    $used_store[] = $keyword;
                    update_post_meta($campaign_id, 'aab_keywords_done', array_values(array_unique($used_store)));
                    error_log('AAB TRACE: recorded keyword as used for campaign ' . $campaign_id . ' => ' . $keyword . ' (used_count=' . count($used_norm) . ')');
                    // If used covers all keywords, mark as completed and disable autorun (but set completed meta for UI)
                    if (count($used_norm) >= count($keywords_clean)) {
                        error_log('AAB INFO: All keywords exhausted for campaign ' . $campaign_id . '. Marking campaign completed.');
                        update_post_meta($campaign_id, 'aab_status', 'completed');
                        update_post_meta($campaign_id, 'aab_completed', 1);
                        update_post_meta($campaign_id, 'aab_disabled_reason', 'completed');
                        update_post_meta($campaign_id, 'aab_enabled', 0);
                        if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                            CampaignScheduler::schedule_or_unschedule($campaign_id);
                        }
                    }
                } else {
                    error_log('AAB TRACE: keyword already recorded as used (or empty) for campaign ' . $campaign_id . ' => ' . $keyword);
                }
            }

            update_post_meta($campaign_id, 'aab_posts_run', $posts_run + 1);
            update_post_meta($campaign_id, 'aab_last_run', time());

            if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                CampaignScheduler::schedule_or_unschedule($campaign_id);
            }

            error_log('AAB INFO: CampaignRunner::run() completed successfully for ID ' . $campaign_id . ' (post ' . $post_id . ')');

        } catch (\Exception $e) {
            // ============================================================
            // LOGGING: Log exceptions
            // ============================================================
            error_log('AAB ERROR: Exception in CampaignRunner::run() for campaign ' . $campaign_id . ' - ' . $e->getMessage());
            
            if (class_exists('\\AAB\\Core\\Logger')) {
                \AAB\Core\Logger::log(
                    $campaign_id,
                    'ERROR',
                    'Exception: ' . $e->getMessage(),
                    '',
                    [
                        'error_message' => $e->getMessage(),
                        'error_line' => $e->getLine(),
                        'error_file' => basename($e->getFile()),
                        'keyword' => isset($keyword) ? $keyword : 'unknown'
                    ]
                );
            }
            
            return;
        }
    }

    /**
     * Primary provider call with optional token limit and extra options (temperature).
     * Returns array ['raw' => <http body string>, 'content' => <extracted candidate string>] or false on error.
     *
     * @param string $prompt
     * @param int|null $token_limit
     * @param array $extra Options like ['temperature' => 0.7]
     * @return array|false
     */
    private static function call_provider($prompt, $token_limit = null, $extra = [])
    {
        // ═══════════════════════════════════════════════════════════
        // STEP 1: Get Provider and Model
        // ═══════════════════════════════════════════════════════════
        $provider = get_option('aab_ai_provider', 'openai');
        error_log('═══════════════════════════════════════════════════════');
        error_log('AAB DEBUG: Starting API call');
        error_log('AAB DEBUG: Provider selected: ' . $provider);

        // Get model based on provider
        if ($provider === 'openai') {
            $model = get_option('aab_openai_model', 'gpt-4o');
        }
        elseif ($provider === 'claude') {
            $model = get_option('aab_claude_model', 'claude-sonnet-4-5-20250514');
        }
        elseif ($provider === 'gemini') {
            $model = get_option('aab_gemini_model', 'gemini-2.0-flash-exp');
        }
        else {
            error_log('AAB ERROR: Unknown provider: ' . $provider);
            return false;
        }

        error_log('AAB DEBUG: Model: ' . $model);

        $token_limit = $token_limit ?: 4500;
        error_log('AAB DEBUG: Token limit: ' . $token_limit);

        // ═══════════════════════════════════════════════════════════
        // STEP 2: Get API Key (Dynamic based on provider)
        // ═══════════════════════════════════════════════════════════
        $api_key = '';

        if ($provider === 'openai') {
            // Try new key first, fallback to old key for backward compatibility
            $api_key = get_option('aab_openai_key', '');
            if (empty($api_key)) {
                $api_key = get_option('aab_api_key', '');
                if (!empty($api_key)) {
                    error_log('AAB DEBUG: Using legacy aab_api_key for OpenAI');
                }
            }
        }
        elseif ($provider === 'claude') {
            $api_key = get_option('aab_claude_key', '');
        }
        elseif ($provider === 'gemini') {
            $api_key = get_option('aab_gemini_key', '');
        }
        elseif ($provider === 'openrouter') {
            $api_key = get_option('aab_openrouter_key', '');
        }

        $key_length = strlen($api_key);
        if (empty($api_key)) {
            error_log('AAB ERROR: API key missing for provider: ' . $provider);
            error_log('AAB ERROR: Please set the API key in Settings');
            return false;
        }

        error_log('AAB DEBUG: API key found - length: ' . $key_length . ' chars');
        error_log('AAB DEBUG: API key preview: ' . substr($api_key, 0, 8) . '...' . substr($api_key, -4));

        // ═══════════════════════════════════════════════════════════
        // STEP 3: Prepare Request Headers and Body
        // ═══════════════════════════════════════════════════════════
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $body = [];
        $endpoint = '';

        error_log('AAB DEBUG: Preparing request for provider: ' . $provider);

        /*
        ─────────────────────────────────────────
        OPENAI
        ─────────────────────────────────────────
        */
        if ($provider === 'openai') {

            $is_gpt5 = stripos($model, 'gpt-5') !== false;
            error_log('AAB DEBUG: OpenAI - Is GPT-5: ' . ($is_gpt5 ? 'YES' : 'NO'));

            if ($is_gpt5) {
                $endpoint = 'https://api.openai.com/v1/responses';

                $body = [
                    'model' => $model,
                    'input' => $prompt,
                    'max_output_tokens' => $token_limit,
                ];

                error_log('AAB DEBUG: OpenAI GPT-5 - Using Responses API');
            } else {
                $endpoint = 'https://api.openai.com/v1/chat/completions';

                $body = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $token_limit,
                ];

                error_log('AAB DEBUG: OpenAI - Using Chat Completions API');
            }

            if (isset($extra['temperature'])) {
                $body['temperature'] = floatval($extra['temperature']);
                error_log('AAB DEBUG: OpenAI - Temperature: ' . $body['temperature']);
            }

            $headers['Authorization'] = 'Bearer ' . $api_key;
            error_log('AAB DEBUG: OpenAI - Authorization header set');
        }

        /*
        ─────────────────────────────────────────
        CLAUDE (Anthropic)
        ─────────────────────────────────────────
        */
        elseif ($provider === 'claude') {

            $endpoint = 'https://api.anthropic.com/v1/messages';

            $body = [
                'model' => $model,
                'max_tokens' => $token_limit,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ];

            if (isset($extra['temperature'])) {
                $body['temperature'] = floatval($extra['temperature']);
                error_log('AAB DEBUG: Claude - Temperature: ' . $body['temperature']);
            }

            $headers['x-api-key'] = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
            
            error_log('AAB DEBUG: Claude - Headers set (x-api-key, anthropic-version)');
            error_log('AAB DEBUG: Claude - Request prepared with max_tokens: ' . $token_limit);
        }

        /*
        ─────────────────────────────────────────
        GEMINI (Google)
        ─────────────────────────────────────────
        */
        elseif ($provider === 'gemini') {

            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

            $body = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ];

            // Gemini generation config
            $generation_config = [];
            
            if (isset($extra['temperature'])) {
                $generation_config['temperature'] = floatval($extra['temperature']);
                error_log('AAB DEBUG: Gemini - Temperature: ' . $generation_config['temperature']);
            }
            
            // Add maxOutputTokens for Gemini
            $generation_config['maxOutputTokens'] = $token_limit;
            
            if (!empty($generation_config)) {
                $body['generationConfig'] = $generation_config;
            }

            error_log('AAB DEBUG: Gemini - Endpoint prepared');
            error_log('AAB DEBUG: Gemini - Max output tokens: ' . $token_limit);
        }

        /*
        ─────────────────────────────────────────
        OPENROUTER
        ─────────────────────────────────────────
        */
        elseif ($provider === 'openrouter') {

            $endpoint = 'https://openrouter.ai/api/v1/chat/completions';

            if (strpos($model, 'openai/') !== 0) {
                $model = 'openai/' . $model;
                error_log('AAB DEBUG: OpenRouter - Prefixed model with openai/: ' . $model);
            }

            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $token_limit,
            ];

            if (isset($extra['temperature'])) {
                $body['temperature'] = floatval($extra['temperature']);
                error_log('AAB DEBUG: OpenRouter - Temperature: ' . $body['temperature']);
            }

            $headers['Authorization'] = 'Bearer ' . $api_key;
            $headers['Referer'] = home_url();
            $headers['X-Title'] = 'AI Auto Blog';
            
            error_log('AAB DEBUG: OpenRouter - Headers set');
        }

        else {
            error_log('AAB ERROR: Unknown provider: ' . $provider);
            return false;
        }

        // ═══════════════════════════════════════════════════════════
        // STEP 4: Send Request
        // ═══════════════════════════════════════════════════════════
        error_log('AAB DEBUG: Endpoint: ' . substr($endpoint, 0, 100));
        error_log('AAB DEBUG: Request body keys: ' . implode(', ', array_keys($body)));
        error_log('AAB DEBUG: Prompt length: ' . strlen($prompt) . ' chars');
        error_log('AAB DEBUG: Sending HTTP POST request...');

        $start_time = microtime(true);

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ]);

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        error_log('AAB DEBUG: Request completed in ' . $duration . ' seconds');

        // ═══════════════════════════════════════════════════════════
        // STEP 5: Handle Response
        // ═══════════════════════════════════════════════════════════
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            error_log('AAB ERROR: WP HTTP Error');
            error_log('AAB ERROR: Error code: ' . $error_code);
            error_log('AAB ERROR: Error message: ' . $error_message);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        error_log('AAB DEBUG: HTTP status code: ' . $code);
        error_log('AAB DEBUG: Response body length: ' . strlen($raw) . ' bytes');
        error_log('AAB DEBUG: Response preview (first 300 chars): ' . substr($raw, 0, 300));

        if ($code < 200 || $code >= 300) {
            error_log('AAB ERROR: Non-2xx HTTP response code: ' . $code);
            error_log('AAB ERROR: Response body (first 1000 chars): ' . substr($raw, 0, 1000));
            
            // Try to parse error message from response
            $error_data = json_decode($raw, true);
            if ($error_data && isset($error_data['error'])) {
                if (is_string($error_data['error'])) {
                    error_log('AAB ERROR: API error message: ' . $error_data['error']);
                } elseif (isset($error_data['error']['message'])) {
                    error_log('AAB ERROR: API error message: ' . $error_data['error']['message']);
                }
            }
            
            return false;
        }

        // ═══════════════════════════════════════════════════════════
        // STEP 6: Parse JSON Response
        // ═══════════════════════════════════════════════════════════
        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('AAB ERROR: JSON decode failed');
            error_log('AAB ERROR: JSON error: ' . json_last_error_msg());
            error_log('AAB ERROR: Raw response (first 500 chars): ' . substr($raw, 0, 500));
            return false;
        }

        error_log('AAB DEBUG: JSON parsed successfully');
        error_log('AAB DEBUG: Response structure keys: ' . implode(', ', array_keys($data)));

        // ═══════════════════════════════════════════════════════════
        // STEP 7: Extract Content (Provider-Specific)
        // ═══════════════════════════════════════════════════════════
        error_log('AAB DEBUG: Extracting content for provider: ' . $provider);

        // OPENAI & OPENROUTER
        if ($provider === 'openai' || $provider === 'openrouter') {
            error_log('AAB DEBUG: OpenAI/OpenRouter - Checking choices array...');
            
            // Chat completions response
            if (!empty($data['choices'][0]['message']['content'])) {
                $content = $data['choices'][0]['message']['content'];
                error_log('AAB SUCCESS: Found content in choices[0].message.content');
                error_log('AAB DEBUG: Content length: ' . strlen($content) . ' chars');
                error_log('AAB DEBUG: Content preview (first 200 chars): ' . substr($content, 0, 200));
                return ['raw' => $raw, 'content' => $content];
            }
            
            // Responses API (GPT-5)
            if (!empty($data['output_text'])) {
                $content = $data['output_text'];
                error_log('AAB SUCCESS: Found content in output_text');
                error_log('AAB DEBUG: Content length: ' . strlen($content) . ' chars');
                return ['raw' => $raw, 'content' => $content];
            }
            
            // Check output array for GPT-5
            if (!empty($data['output']) && is_array($data['output'])) {
                error_log('AAB DEBUG: OpenAI - Checking output array...');
                foreach ($data['output'] as $idx => $outItem) {
                    error_log('AAB DEBUG: OpenAI - output[' . $idx . '] keys: ' . implode(', ', array_keys($outItem)));
                    
                    if (!empty($outItem['content']) && is_array($outItem['content'])) {
                        foreach ($outItem['content'] as $block) {
                            if (!empty($block['text']) && is_string($block['text'])) {
                                error_log('AAB SUCCESS: Found content in output[].content[].text');
                                return ['raw' => $raw, 'content' => $block['text']];
                            }
                        }
                    }
                    
                    if (!empty($outItem['output_text'])) {
                        error_log('AAB SUCCESS: Found content in output[].output_text');
                        return ['raw' => $raw, 'content' => $outItem['output_text']];
                    }
                }
            }
            
            error_log('AAB ERROR: No content found in OpenAI response structure');
            error_log('AAB ERROR: Available keys: ' . implode(', ', array_keys($data)));
        }

        // CLAUDE
        if ($provider === 'claude') {
            error_log('AAB DEBUG: Claude - Checking content array...');
            
            if (!empty($data['content']) && is_array($data['content'])) {
                error_log('AAB DEBUG: Claude - content array has ' . count($data['content']) . ' items');
                
                if (!empty($data['content'][0]['text'])) {
                    $content = $data['content'][0]['text'];
                    error_log('AAB SUCCESS: Found Claude content in content[0].text');
                    error_log('AAB DEBUG: Content length: ' . strlen($content) . ' chars');
                    error_log('AAB DEBUG: Content preview (first 200 chars): ' . substr($content, 0, 200));
                    return ['raw' => $raw, 'content' => $content];
                }
                
                error_log('AAB DEBUG: Claude - content[0] keys: ' . implode(', ', array_keys($data['content'][0])));
            }
            
            error_log('AAB ERROR: No content found in Claude response structure');
            error_log('AAB ERROR: Available top-level keys: ' . implode(', ', array_keys($data)));
        }

        // GEMINI
        if ($provider === 'gemini') {
            error_log('AAB DEBUG: Gemini - Checking candidates array...');
            
            if (!empty($data['candidates']) && is_array($data['candidates'])) {
                error_log('AAB DEBUG: Gemini - candidates array has ' . count($data['candidates']) . ' items');
                
                if (!empty($data['candidates'][0])) {
                    $candidate = $data['candidates'][0];
                    error_log('AAB DEBUG: Gemini - candidates[0] keys: ' . implode(', ', array_keys($candidate)));
                    
                    if (!empty($candidate['content']['parts'][0]['text'])) {
                        $content = $candidate['content']['parts'][0]['text'];
                        error_log('AAB SUCCESS: Found Gemini content in candidates[0].content.parts[0].text');
                        error_log('AAB DEBUG: Content length: ' . strlen($content) . ' chars');
                        error_log('AAB DEBUG: Content preview (first 200 chars): ' . substr($content, 0, 200));
                        return ['raw' => $raw, 'content' => $content];
                    }
                }
            }
            
            error_log('AAB ERROR: No content found in Gemini response structure');
            error_log('AAB ERROR: Available top-level keys: ' . implode(', ', array_keys($data)));
        }

        // ═══════════════════════════════════════════════════════════
        // STEP 8: Fallback - No Content Found
        // ═══════════════════════════════════════════════════════════
        error_log('AAB ERROR: No usable content found for provider: ' . $provider);
        error_log('AAB ERROR: Full response structure (first 2000 chars): ' . substr($raw, 0, 2000));
        error_log('═══════════════════════════════════════════════════════');
        
        return false;
    }

    /**
     * Convert desired words into a conservative token limit (capped).
     */
    private static function words_to_token_limit($words)
    {
        $words = max(50, intval($words));
        // Heuristic: tokens ≈ words * 1.5 (conservative)
        $tokens = intval(ceil($words * 1.5));
        // Cap sensible limits to avoid massive requests
        // Increased cap to support longer articles up to ~5000 words.
        $cap = 10000;
        if ($tokens > $cap) $tokens = $cap;
        return $tokens;
    }

    // --------------------------
    // Utility methods (preserved/unchanged, with minor improvements)
    // --------------------------

    private static function normalize_raw_html($raw)
    {
        $content = trim($raw);

        if (self::looks_like_json($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $found = self::recursive_find_content($decoded);
                if ($found !== false) $content = $found;
                else $content = '';
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
        $html = preg_replace(
            '/<(\/?)(html|head|meta|title|link|script|style|!DOCTYPE)[^>]*>/i',
            '',
            $html
        );
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

        $result = '';
        $words_acc = 0;

        if (!empty($matches[0])) {
            foreach ($matches[0] as $m) {
                $block_html = $m[0];
                $block_text = self::plain_text($block_html);
                $block_words = self::word_count_from_text($block_text);

                if ($words_acc + $block_words <= $max_words) {
                    $result .= $block_html;
                    $words_acc += $block_words;
                    if ($words_acc >= $max_words) break;
                } else {
                    $remaining = $max_words - $words_acc;
                    if ($remaining <= 0) break;

                    if (preg_match('/^<\s*(ul|ol)/i', $block_html)) {
                        preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $block_html, $li_matches);
                        $li_html = [];
                        $li_words_acc = 0;
                        if (!empty($li_matches[0])) {
                            foreach ($li_matches[0] as $li) {
                                $li_text = wp_strip_all_tags($li);
                                $li_words = self::word_count_from_text($li_text);
                                if ($li_words_acc + $li_words <= $remaining) {
                                    $li_html[] = $li;
                                    $li_words_acc += $li_words;
                                } else {
                                    $trimmed = self::trim_text_to_words($li_text, $remaining - $li_words_acc);
                                    if ($trimmed !== '') {
                                        $li_html[] = '<li>' . esc_html($trimmed) . '</li>';
                                        $li_words_acc = $remaining;
                                    }
                                    break;
                                }
                            }
                        }
                        if (!empty($li_html)) {
                            $list_tag = (strpos($block_html, '<ol') !== false) ? 'ol' : 'ul';
                            $result .= '<' . $list_tag . '>' . implode('', $li_html) . '</' . $list_tag . '>';
                            $words_acc += $li_words_acc;
                        }
                    } else {
                        if (preg_match('/^<\s*(h[1-6]|p|div|section|article)/i', $block_html, $tagm)) {
                            $tag = strtolower($tagm[1]);
                            $text = wp_strip_all_tags($block_html);
                            $trimmed = self::trim_text_to_words($text, $remaining);
                            if ($trimmed !== '') {
                                $result .= '<' . $tag . '>' . esc_html($trimmed) . '</' . $tag . '>';
                                $words_acc += self::word_count_from_text($trimmed);
                            }
                        } else {
                            $trimmed = self::trim_text_to_words($block_text, $remaining);
                            if ($trimmed !== '') {
                                $result .= '<p>' . esc_html($trimmed) . '</p>';
                                $words_acc += self::word_count_from_text($trimmed);
                            }
                        }
                    }

                    break;
                }
            }
        } else {
            $trimmed = self::trim_text_to_words($plain, $max_words);
            return '<p>' . esc_html($trimmed) . '</p>';
        }

        if (trim($result) === '') {
            $trimmed = self::trim_text_to_words($plain, $max_words);
            return '<p>' . esc_html($trimmed) . '</p>';
        }

        return $result;
    }

    private static function trim_text_to_words($text, $max_words)
    {
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= $max_words) {
            return implode(' ', $words);
        }
        $slice = array_slice($words, 0, $max_words);
        $candidate = implode(' ', $slice);

        $last_pos = max(strrpos($candidate, '.'), strrpos($candidate, '?'), strrpos($candidate, '!'));
        if ($last_pos !== false && $last_pos > 10) {
            return rtrim(substr($candidate, 0, $last_pos + 1));
        }

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
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return count($words);
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

    /**
     * Attempt to read response.status from provider raw string (if JSON).
     * Returns status string (e.g. 'incomplete' or 'completed') or null if not present.
     */
    private static function response_status_from_raw($raw)
    {
        if (!is_string($raw) || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;

        // Responses API top-level status
        if (!empty($decoded['status']) && is_string($decoded['status'])) {
            return $decoded['status'];
        }

        // Some providers might nest status inside objects in output
        if (!empty($decoded['output']) && is_array($decoded['output'])) {
            foreach ($decoded['output'] as $out) {
                if (!empty($out['status']) && is_string($out['status'])) {
                    return $out['status'];
                }
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
            if (preg_match('/<\\/?(h1|h2|h3|p|ul|li|article|section|div|strong|em)/i', $item)) {
                return $item;
            }
        }

        usort($candidates, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

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
            $arr = (array)$var;
            foreach ($arr as $v) self::recursive_collect_strings($v, $out);
            return;
        }
    }
}