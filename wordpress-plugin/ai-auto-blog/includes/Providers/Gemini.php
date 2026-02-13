<?php
namespace AAB\Providers;

if (!defined('ABSPATH')) exit;

class Gemini {

    public static function generate($prompt, $model, $token_limit) {

        $api_key = get_option('aab_gemini_key', '');

        if (empty($api_key)) {
            error_log('AAB: Gemini key missing');
            return false;
        }

        $model = $model ?: 'gemini-1.5-pro';

        $endpoint = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$api_key}";

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? false;
    }
}
