<?php
namespace AAB\Extensions;

if (!defined('ABSPATH')) exit;

class CampaignResume
{
    public static function init()
    {
        // run after most save handlers (priority 25) so saved meta is available
        add_action('save_post_aab_campaign', [self::class, 'maybe_resume_campaign'], 25, 3);
    }

    /**
     * If campaign was marked completed but now has new keywords (or posts_run < max_posts),
     * clear completed markers and re-schedule the campaign.
     */
    public static function maybe_resume_campaign($post_id, $post, $update)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post || $post->post_type !== 'aab_campaign') return;

        try {
            $completed = get_post_meta($post_id, 'aab_completed', true) ? true : false;
            if (!$completed) return; // nothing to do

            $keywords = (array) get_post_meta($post_id, 'aab_keywords', true);
            $keywords_clean = array_values(array_filter(array_map(function($k){ return is_string($k) ? trim($k) : ''; }, $keywords)));

            $keywords_done = get_post_meta($post_id, 'aab_keywords_done', true);
            $keywords_done = is_array($keywords_done) ? array_values(array_filter(array_map(function($k){ return is_string($k) ? trim($k) : ''; }, $keywords_done))) : [];

            $posts_run = intval(get_post_meta($post_id, 'aab_posts_run', true) ?: 0);
            $max_posts = intval(get_post_meta($post_id, 'max_posts', true) ?: 0);

            $total_keywords = count($keywords_clean);
            $done_count = count($keywords_done);

            // Decide if campaign should resume:
            // - new keywords were added (total_keywords > done_count)
            // - OR there is a max_posts cap and posts_run < max_posts
            $should_resume = false;
            if ($total_keywords > $done_count) $should_resume = true;
            if ($max_posts > 0 && $posts_run < $max_posts) $should_resume = true;

            if ($should_resume) {
                // Remove completed flag so UI/scheduler treat it as active again
                delete_post_meta($post_id, 'aab_completed');

                // Remove keywords_done so scheduler/runner will re-evaluate keywords (safer)
                delete_post_meta($post_id, 'aab_keywords_done');

                // Keep posts_run as-is (we don't reset progress unless user wants to).
                // Update last run time only if necessary (leave unchanged to avoid side effects).

                // error_log('AAB TRACE: CampaignResume - resuming campaign ' . $post_id . ' (cleared aab_completed and aab_keywords_done).');

                // Re-schedule using existing scheduler logic (it will respect enabled/pause/interval)
                if (class_exists('\\AAB\\Core\\CampaignScheduler')) {
                    try {
                        \AAB\Core\CampaignScheduler::schedule_or_unschedule($post_id);
                        // error_log('AAB TRACE: CampaignResume - called CampaignScheduler::schedule_or_unschedule for ' . $post_id);
                    } catch (\Throwable $e) {
                        // error_log('AAB ERROR: CampaignResume scheduler call failed for ' . $post_id . ' - ' . $e->getMessage());
                    }
                }
            } else {
                // nothing to resume
                // error_log('AAB TRACE: CampaignResume - no resume needed for ' . $post_id . ' (keywords=' . $total_keywords . ' done=' . $done_count . ' posts_run=' . $posts_run . ' max=' . $max_posts . ')');
            }
        } catch (\Throwable $e) {
            // error_log('AAB ERROR: CampaignResume exception for ' . $post_id . ' - ' . $e->getMessage());
        }
    }
}
