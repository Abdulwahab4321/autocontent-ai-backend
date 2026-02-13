<?php
namespace AAB\Providers;

if (!defined('ABSPATH')) exit;

class OpenAI {

    public static function generate($prompt, $model, $token_limit) {

        $api_key = get_option('aab_openai_key', '');

        if (empty($api_key)) {
            error_log('AAB: OpenAI key missing');
            return false;
        }

        $endpoint = 'https://api.openai.com/v1/chat/completions';

        $body = [
            'model' => $model ?: 'gpt-4o',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $token_limit ?: 4000,
            'temperature' => 0.7,
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data['choices'][0]['message']['content'] ?? false;
    }
}
