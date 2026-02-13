<?php

namespace AAB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CampaignScheduler
 *
 * Single-event scheduler per campaign that re-schedules itself after each run.
 * Provides a secure external trigger URL so server cron can invoke campaign runs
 * even when the site receives no visitors.
 *
 * - schedule_or_unschedule($campaign_id) is called by CampaignSaver after save.
 * - aab_campaign_run hook executes handle_scheduled_run which calls CampaignRunner::run()
 *   and then re-schedules the next run if appropriate.
 * - External trigger available via ?aab_external_run=1&campaign={id}&key={secret}
 */
class CampaignScheduler
{
    const EXTERNAL_OPTION = 'aab_external_key';
    const EXTERNAL_QUERY_VAR = 'aab_external_run';
    const EXTERNAL_KEY_PARAM = 'key';
    const EXTERNAL_CAMPAIGN_PARAM = 'campaign';

    public static function init()
    {
        // hook fired when scheduled: aab_campaign_run with single arg (campaign_id)
        add_action('aab_campaign_run', [self::class, 'handle_scheduled_run'], 10, 1);

        // handle external trigger on init (lightweight check)
        add_action('init', [self::class, 'maybe_handle_external_trigger'], 0);
    }

    /**
     * Create or remove a single scheduled event for a campaign.
     * Called after campaign is saved (CampaignSaver).
     *
     * - Clears existing scheduled events for the campaign,
     * - If campaign is enabled and not paused and interval > 0, schedules the next run.
     */
    public static function schedule_or_unschedule($campaign_id)
    {
        $campaign_id = intval($campaign_id);
        if (!$campaign_id) return false;

        // Clear any existing scheduled events for this campaign
        wp_clear_scheduled_hook('aab_campaign_run', [$campaign_id]);

        // load campaign flags
        $enabled = get_post_meta($campaign_id, 'aab_enabled', true) ? true : false;
        $paused = get_post_meta($campaign_id, 'aab_pause_autorun', true) ? true : false;
        $interval = intval(get_post_meta($campaign_id, 'aab_run_interval', true) ?: 0);
        $unit = get_post_meta($campaign_id, 'aab_run_unit', true) ?: 'minutes';
        $custom_time = get_post_meta($campaign_id, 'aab_custom_post_time', true) ? true : false;
        $custom_time_value = get_post_meta($campaign_id, 'aab_custom_post_time_value', true) ?: '';

        if (!$enabled || $paused || $interval <= 0) {
            // nothing to schedule
            return true;
        }

        // compute seconds interval for convenience
        $interval_seconds = self::interval_seconds($interval, $unit);
        if ($interval_seconds <= 0) return false;

        // compute next run timestamp in WP time (current_time('timestamp') gives WP-local timestamp)
        $now = (int) current_time('timestamp');

        if ($custom_time && preg_match('/^(\d{1,2}):(\d{2})$/', $custom_time_value, $m)) {
            $hour = intval($m[1]);
            $min = intval($m[2]);
            // next time today at HH:MM (WP local time)
            $today_date = date('Y-m-d', $now);
            $candidate = strtotime("{$today_date} {$hour}:{$min}:00");
            // convert candidate (server time) -> WP timestamp: candidate already in server time; but WP uses same epoch,
            // so comparing / using current_time('timestamp') is consistent enough for scheduling single events.
            if ($candidate <= $now) {
                // schedule for next day
                $candidate += 86400;
            }
            $next = $candidate;
        } else {
            // no custom time: schedule at now + interval
            $next = $now + $interval_seconds;
        }

        // Avoid double-scheduling if one is already present (we cleared earlier but check anyway)
        if (!wp_next_scheduled('aab_campaign_run', [$campaign_id])) {
            wp_schedule_single_event($next, 'aab_campaign_run', [$campaign_id]);
        }

        // store the next runtime for debugging/inspection
        update_post_meta($campaign_id, 'aab_next_run', $next);

        return true;
    }

    /**
     * Handler invoked by WP-Cron when a scheduled run fires.
     * Calls CampaignRunner::run($campaign_id) and re-schedules the next run if still enabled.
     *
     * Note: this function must be robust — it will attempt to re-schedule after run.
     */
    public static function handle_scheduled_run($campaign_id)
    {
        $campaign_id = intval($campaign_id);
        if (!$campaign_id) return;

        // double-check campaign still exists and is enabled
        $enabled = get_post_meta($campaign_id, 'aab_enabled', true) ? true : false;
        $paused = get_post_meta($campaign_id, 'aab_pause_autorun', true) ? true : false;
        if (!$enabled || $paused) {
            // ensure any scheduled hooks are cleared
            wp_clear_scheduled_hook('aab_campaign_run', [$campaign_id]);
            delete_post_meta($campaign_id, 'aab_next_run');
            return;
        }

        try {
            if (class_exists('\\AAB\\Core\\CampaignRunner')) {
                // run the campaign
                CampaignRunner::run($campaign_id);
            }
        } catch (\Throwable $e) {
            // error_log('AAB CampaignScheduler: handle_scheduled_run exception for campaign ' . $campaign_id . ' - ' . $e->getMessage());
        }

        // Now schedule the next occurrence using same logic saver uses
        // Use campaign interval & unit
        $interval = intval(get_post_meta($campaign_id, 'aab_run_interval', true) ?: 0);
        $unit = get_post_meta($campaign_id, 'aab_run_unit', true) ?: 'minutes';
        $custom_time = get_post_meta($campaign_id, 'aab_custom_post_time', true) ? true : false;
        $custom_time_value = get_post_meta($campaign_id, 'aab_custom_post_time_value', true) ?: '';

        if ($interval <= 0) {
            delete_post_meta($campaign_id, 'aab_next_run');
            return;
        }

        $now = (int) current_time('timestamp');

        // Default: schedule next as now + interval_seconds
        $interval_seconds = self::interval_seconds($interval, $unit);
        if ($interval_seconds <= 0) {
            delete_post_meta($campaign_id, 'aab_next_run');
            return;
        }

        if ($custom_time && preg_match('/^(\d{1,2}):(\d{2})$/', $custom_time_value, $m)) {
            // If custom_time is true, we schedule the next run on the next day at the chosen HH:MM.
            // That produces a daily cadence (the interval param remains used for non-daily cadences).
            $hour = intval($m[1]);
            $min = intval($m[2]);
            $today_date = date('Y-m-d', $now);
            $candidate = strtotime("{$today_date} {$hour}:{$min}:00");
            if ($candidate <= $now) {
                $candidate += 86400;
            }
            $next = $candidate;
        } else {
            $next = $now + $interval_seconds;
        }

        // Clear existing then schedule single event
        wp_clear_scheduled_hook('aab_campaign_run', [$campaign_id]);
        if (!wp_next_scheduled('aab_campaign_run', [$campaign_id])) {
            wp_schedule_single_event($next, 'aab_campaign_run', [$campaign_id]);
        }
        update_post_meta($campaign_id, 'aab_next_run', $next);
    }

    /**
     * Compute seconds for interval and unit.
     */
    private static function interval_seconds($interval, $unit)
    {
        $interval = max(0, intval($interval));
        if ($interval <= 0) return 0;
        switch ($unit) {
            case 'minutes':
                return $interval * 60;
            case 'hours':
                return $interval * 3600;
            case 'days':
                return $interval * 86400;
            default:
                return $interval * 60;
        }
    }

    /**
     * Return a secure external trigger URL for a campaign.
     * The URL is: site_url/?aab_external_run=1&campaign={id}&key={secret}
     * The secret is stored in option 'aab_external_key' and generated if absent.
     */
    public static function get_external_trigger_url($campaign_id)
    {
        $campaign_id = intval($campaign_id);
        if (!$campaign_id) return '';

        $key = get_option(self::EXTERNAL_OPTION, '');
        if (empty($key)) {
            $key = wp_generate_password(28, false);
            update_option(self::EXTERNAL_OPTION, $key, false);
        }

        $url = home_url(add_query_arg([
            self::EXTERNAL_QUERY_VAR => 1,
            self::EXTERNAL_CAMPAIGN_PARAM => $campaign_id,
            self::EXTERNAL_KEY_PARAM => $key,
        ], '/'));

        return $url;
    }

    /**
     * Lightweight handler that checks for external trigger query var on init.
     * This is intentionally simple: if ?aab_external_run=1&campaign={id}&key={secret}
     * is called, the campaign will be run immediately (no auth required).
     *
     * Use the get_external_trigger_url() URL in a server cron (curl/wget/php).
     */
    public static function maybe_handle_external_trigger()
    {
        // Only react when external query var exists
        if (empty($_GET[self::EXTERNAL_QUERY_VAR])) return;

        // basic sanitization
        $campaign_id = isset($_REQUEST[self::EXTERNAL_CAMPAIGN_PARAM]) ? intval($_REQUEST[self::EXTERNAL_CAMPAIGN_PARAM]) : 0;
        $key = isset($_REQUEST[self::EXTERNAL_KEY_PARAM]) ? sanitize_text_field(wp_unslash($_REQUEST[self::EXTERNAL_KEY_PARAM])) : '';

        if (!$campaign_id || empty($key)) {
            status_header(400);
            echo 'Invalid trigger';
            exit;
        }

        $stored_key = get_option(self::EXTERNAL_OPTION, '');
        if (empty($stored_key) || !hash_equals($stored_key, $key)) {
            status_header(403);
            echo 'Forbidden';
            exit;
        }

        // It's valid — run the campaign synchronously
        if (class_exists('\\AAB\\Core\\CampaignRunner')) {
            // We run it but keep output minimal for cron callers
            try {
                CampaignRunner::run($campaign_id);
                echo 'OK';
            } catch (\Throwable $e) {
                // error_log('AAB CampaignScheduler: external trigger run failed for campaign ' . $campaign_id . ' - ' . $e->getMessage());
                status_header(500);
                echo 'Error';
            }
        } else {
            status_header(500);
            echo 'Runner missing';
        }
        // exit after handling
        exit;
    }

    public static function is_scheduled($campaign_id)
    {
        $campaign_id = intval($campaign_id);
        if (!$campaign_id) return false;

        $next = wp_next_scheduled('aab_campaign_run', [$campaign_id]);
        return $next ? true : false;
    }
}

\AAB\Core\CampaignScheduler::init();
