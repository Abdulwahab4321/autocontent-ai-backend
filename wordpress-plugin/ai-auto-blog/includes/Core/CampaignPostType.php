<?php
namespace AAB\Core;

class CampaignPostType {

    public static function register() {
        register_post_type('aab_campaign', [
            'labels' => [
                'name'          => 'Campaigns',
                'singular_name' => 'Campaign',
            ],
            'public'       => false,
            'show_ui'      => false, // IMPORTANT: we build our own UI
            'supports'     => ['title'],
            'capability_type' => 'post',
        ]);
    }
}
