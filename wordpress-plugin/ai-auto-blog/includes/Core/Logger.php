<?php
namespace AAB\Core;

if (!defined('ABSPATH')) exit;

class Logger {
    
    /**
     * Log a campaign activity
     * 
     * @param int $campaign_id Campaign ID
     * @param string $status Status: 'SUCCESS', 'ERROR', 'WARNING'
     * @param string $post_title Generated post title
     * @param string $post_url URL to the generated post
     * @param array $details Additional details (optional)
     */
    public static function log($campaign_id, $status, $post_title, $post_url = '', $details = []) {
        $campaign = get_post($campaign_id);
        
        if (!$campaign) {
            return;
        }
        
        // Get existing logs
        $logs = get_option('aab_campaign_logs', []);
        
        // Create log entry
        $log_entry = [
            'id' => uniqid(),
            'campaign_id' => $campaign_id,
            'campaign_name' => $campaign->post_title,
            'status' => strtoupper($status),
            'post_title' => $post_title,
            'post_url' => $post_url,
            'total_logs' => count($logs) + 1,
            'date' => current_time('Y-m-d H:i:s'),
            'timestamp' => time(),
            'details' => $details
        ];
        
        // Add to beginning of array (newest first)
        array_unshift($logs, $log_entry);
        
        // Keep only last 500 logs to prevent database bloat
        if (count($logs) > 500) {
            $logs = array_slice($logs, 0, 500);
        }
        
        // Update option
        update_option('aab_campaign_logs', $logs);
        
        // Also update last cron run time
        update_option('aab_last_cron_run', current_time('Y-m-d H:i:s'));
    }
    
    /**
     * Get all logs
     * 
     * @param int $limit Number of logs to return (0 = all)
     * @return array
     */
    public static function get_logs($limit = 0) {
        $logs = get_option('aab_campaign_logs', []);
        
        if ($limit > 0 && count($logs) > $limit) {
            return array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * Get logs for specific campaign
     * 
     * @param int $campaign_id
     * @return array
     */
    public static function get_campaign_logs($campaign_id) {
        $logs = self::get_logs();
        
        return array_filter($logs, function($log) use ($campaign_id) {
            return isset($log['campaign_id']) && $log['campaign_id'] == $campaign_id;
        });
    }
    
    /**
     * Clear all logs
     */
    public static function clear_logs() {
        delete_option('aab_campaign_logs');
        delete_option('aab_last_cron_run');
    }
    
    /**
     * Get last cron run time
     * 
     * @return string
     */
    public static function get_last_cron_time() {
        return get_option('aab_last_cron_run', 'Never');
    }
    
    /**
     * Get time difference in human readable format
     * 
     * @param string $datetime
     * @return string
     */
    public static function time_ago($datetime) {
        if ($datetime === 'Never') {
            return 'Never';
        }
        
        $timestamp = strtotime($datetime);
        $current_time = current_time('timestamp');
        $diff = $current_time - $timestamp;
        
        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } else {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        }
    }
}
