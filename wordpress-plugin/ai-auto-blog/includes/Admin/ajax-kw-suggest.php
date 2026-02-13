<?php
// includes/Admin/ajax-kw-suggest.php
if (!defined('ABSPATH')) exit;

function aab_ajax_kw_suggest_handler() {
    // nonce check
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aab_kw_suggest')) {
        wp_send_json_error(['msg' => 'nonce'], 403);
    }

    $q = isset($_POST['q']) ? trim(sanitize_text_field(wp_unslash($_POST['q']))) : '';
    if ($q === '') {
        wp_send_json_success(['data' => [$q, []]]);
    }

    // cache key per query
    $trans_key = 'aab_kw_suggest_' . md5($q);
    $cached = get_transient($trans_key);
    if (is_array($cached)) {
        wp_send_json_success(['data' => [$q, $cached]]);
    }

    $suggestions = [];

    // Try Google first (may be blocked). Use 'client=firefox' which often returns JSON array.
    $google_url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . rawurlencode($q);
    $args_common = [
        'timeout' => 10,
        'headers' => [
            // make the request look like a browser to avoid being trivially blocked
            'User-Agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Referer' => home_url('/'),
        ],
    ];

    $resp = wp_remote_get($google_url, $args_common);

    if (!is_wp_error($resp)) {
        $code = intval(wp_remote_retrieve_response_code($resp));
        $body = wp_remote_retrieve_body($resp);

        // If we got an OK and JSON-like payload, attempt decode
        if ($code >= 200 && $code < 300) {
            $data = json_decode($body, true);
            if (is_array($data) && isset($data[1]) && is_array($data[1])) {
                $suggestions = array_values(array_filter(array_map('strval', $data[1])));
            } else {
                // maybe the body is JSONP / weird — try to extract JSON with regex
                if (preg_match('/(\[.*\])/s', $body, $m)) {
                    $try = json_decode($m[1], true);
                    if (is_array($try) && isset($try[1]) && is_array($try[1])) {
                        $suggestions = array_values(array_filter(array_map('strval', $try[1])));
                    }
                }
            }
        } else {
            // log for debugging
            error_log('AAB autosuggest: Google responded HTTP ' . $code . ' for query "' . $q . '"');
        }

        // If Google returned a blocking HTML page (common), body will start with <html> etc.
        if (empty($suggestions) && $code >= 400) {
            error_log('AAB autosuggest: Google blocked request for query "' . $q . '" HTTP ' . $code . ' snippet: ' . substr($body, 0, 200));
        }
    } else {
        error_log('AAB autosuggest: wp_remote_get Google error: ' . $resp->get_error_message());
    }

    // If Google failed or returned nothing, fallback to DuckDuckGo AC API
    if (empty($suggestions)) {
        $dd_url = 'https://ac.duckduckgo.com/ac/?q=' . rawurlencode($q) . '&type=list';
        $resp2 = wp_remote_get($dd_url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Referer' => home_url('/'),
            ],
        ]);

        if (!is_wp_error($resp2)) {
            $code2 = intval(wp_remote_retrieve_response_code($resp2));
            $body2 = wp_remote_retrieve_body($resp2);
            if ($code2 >= 200 && $code2 < 300) {
                $data2 = json_decode($body2, true);
                if (is_array($data2)) {
                    // DuckDuckGo returns array of objects like [{ "phrase":"..."}]
                    foreach ($data2 as $item) {
                        if (is_array($item) && isset($item['phrase'])) {
                            $suggestions[] = (string) $item['phrase'];
                        }
                    }
                    // make unique and limit
                    $suggestions = array_values(array_unique($suggestions));
                }
            } else {
                error_log('AAB autosuggest: DuckDuckGo responded HTTP ' . $code2 . ' for query "' . $q . '" snippet:' . substr($body2, 0, 200));
            }
        } else {
            error_log('AAB autosuggest: wp_remote_get DuckDuckGo error: ' . $resp2->get_error_message());
        }
    }

    // Final safety: ensure array of strings
    $suggestions = array_values(array_filter(array_map(function($s){ return (string)$s; }, $suggestions)));

    // Cache for a short period
    if (!empty($suggestions)) {
        set_transient($trans_key, $suggestions, HOUR_IN_SECONDS);
    } else {
        // cache empty result for brief time to avoid repeated failing remote calls
        set_transient($trans_key, [], MINUTE_IN_SECONDS * 5);
    }

    // Return Google-like shape to keep client parsing simple
    wp_send_json_success(['data' => [$q, $suggestions]]);
}
add_action('wp_ajax_aab_kw_suggest', 'aab_ajax_kw_suggest_handler');
add_action('wp_ajax_nopriv_aab_kw_suggest', 'aab_ajax_kw_suggest_handler'); // optional
