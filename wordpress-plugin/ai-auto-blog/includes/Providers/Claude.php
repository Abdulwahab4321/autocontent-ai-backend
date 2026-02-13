<?php
namespace AAB\Providers;

if (!defined('ABSPATH')) exit;

class Claude {

    public static function generate($prompt, $model, $token_limit) {

        $api_key = get_option('aab_claude_key', '');

        if (empty($api_key)) {
            error_log('AAB: Claude key missing');
            return false;
        }

        $endpoint = 'https://api.anthropic.com/v1/messages';

        $body = [
            'model' => $model ?: 'claude-3-sonnet-20240229',
            'max_tokens' => $token_limit ?: 4000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data['content'][0]['text'] ?? false;
    }
}
