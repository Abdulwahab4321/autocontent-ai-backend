<?php
if (!defined('ABSPATH')) exit;

$campaigns = get_posts([
    'post_type'   => 'aab_campaign',
    'numberposts' => -1,
]);

?>
<div class="wrap">
    <h1>All Campaigns</h1>

    <table class="widefat striped">
        <thead>
        <tr>
            <th>Name</th>
            <th>Status</th>
            <th>Max Posts</th>
            <th>Last Run</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($campaigns)): ?>
            <tr><td colspan="5">No campaigns found.</td></tr>
        <?php endif; ?>

        <?php foreach ($campaigns as $campaign):
            $id = $campaign->ID;
            $enabled = get_post_meta($id, 'aab_enabled', true) ? true : false;
            $paused = get_post_meta($id, 'aab_pause_autorun', true) ? true : false;

            // Safely check scheduled state. If CampaignScheduler or method doesn't exist, avoid fatal error.
            $scheduled = false;
            if (class_exists('\\AAB\\Core\\CampaignScheduler') && method_exists('\\AAB\\Core\\CampaignScheduler', 'is_scheduled')) {
                try {
                    $scheduled = \AAB\Core\CampaignScheduler::is_scheduled($id) ? true : false;
                } catch (\Throwable $e) {
                    // swallow — treat as not scheduled
                    $scheduled = false;
                }
            }

            $last_run = intval(get_post_meta($id, 'aab_last_run', true) ?: 0);
            $max_posts = intval(get_post_meta($id, 'max_posts', true) ?: 0);

            // New: completion detection
            $posts_run = intval(get_post_meta($id, 'aab_posts_run', true) ?: 0);
            $keywords = (array) get_post_meta($id, 'aab_keywords', true);
            // normalize and count non-empty keywords
            $keyword_count = 0;
            if (!empty($keywords) && is_array($keywords)) {
                foreach ($keywords as $k) {
                    if (is_string($k) && trim($k) !== '') $keyword_count++;
                }
            }
            // meta keys used elsewhere in plugin (existing code referenced these flags)
            $one_post_per_keyword = get_post_meta($id, 'one_post_per_keyword', true) ? true : false;

            // A campaign is considered "Completed" when:
            // - max_posts is set and posts_run >= max_posts
            // OR
            // - one_post_per_keyword is enabled and we've generated at least one post per keyword (posts_run >= keyword_count)
            $is_completed = false;
            if ($max_posts > 0 && $posts_run >= $max_posts) {
                $is_completed = true;
            } elseif ($one_post_per_keyword && $keyword_count > 0 && $posts_run >= $keyword_count) {
                $is_completed = true;
            }

            // Determine status label (Completed takes precedence)
            if ($is_completed) {
                $status = '<span style="color:#0055AA;font-weight:600;">Completed</span>';
            } elseif (!$enabled) {
                $status = '<span style="color:#a00;font-weight:600;">Disabled</span>';
            } elseif ($paused) {
                $status = '<span style="color:#cc7a00;font-weight:600;">Paused</span>';
            } elseif ($scheduled) {
                $status = '<span style="color:#0a0;font-weight:600;">Running</span>';
            } else {
                $status = '<span style="color:#666;font-weight:600;">Not scheduled</span>';
            }

            $toggle_nonce = wp_create_nonce('aab_toggle_enable_' . $id);
            $toggle_url = admin_url('admin.php?page=aab-campaigns&action=toggle_enable&id=' . $id . '&_wpnonce=' . $toggle_nonce);

            $del_url = wp_nonce_url(admin_url('admin.php?page=aab-campaigns&action=delete&id=' . $id), 'aab_delete_campaign_' . $id);
            $run_nonce = wp_create_nonce('aab_run_now_' . $id);
            $run_url = admin_url('admin.php?page=aab-campaigns&action=run_now&id=' . $id . '&_wpnonce=' . $run_nonce);

            // Prepare UI affordances:
            // - Run Now disabled if campaign is disabled OR completed.
            //   (Note: paused campaigns should still allow "Run Now" per user request.)
            $run_disabled = (!$enabled || $is_completed);

            // - Edit should be disabled while enabled, but if campaign is Completed it must remain editable.
            //   (So completed campaigns are editable regardless of enabled flag.)
            $edit_disabled = ($enabled && !$is_completed);

            // For toggle enable/disable: if campaign is Completed, do NOT allow enabling/disabling — show Completed label instead.
        ?>
            <tr>
                <td><?php echo esc_html($campaign->post_title); ?></td>
                <td><?php echo $status; ?></td>
                <td><?php echo esc_html($max_posts); ?></td>
                <td><?php echo $last_run ? esc_html(date('Y-m-d H:i:s', $last_run)) : 'Never'; ?></td>
                <td>
                    <?php if ($run_disabled): ?>
                        <button class="button button-small" disabled title="<?php echo $is_completed ? 'Campaign completed — cannot run again.' : 'Campaign is disabled. Enable it to run.'; ?>">Run Now</button>
                    <?php else: ?>
                        <a class="button button-small" href="<?php echo esc_url($run_url); ?>">Run Now</a>
                    <?php endif; ?>

                    &nbsp;

                    <?php if ($edit_disabled): ?>
                        <span class="button button-small disabled" style="opacity:0.6;cursor:not-allowed;" title="Disable campaign to edit.">Edit</span>
                    <?php else: ?>
                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=aab-new-campaign&edit=' . $id)); ?>">Edit</a>
                    <?php endif; ?>

                    &nbsp;|&nbsp;

                    <?php if ($is_completed): ?>
                        <span style="font-weight:600;color:#666;" title="Campaign completed — cannot be enabled or run.">Completed</span>
                    <?php else: ?>
                        <a href="<?php echo esc_url($toggle_url); ?>"><?php echo $enabled ? 'Disable' : 'Enable'; ?></a>
                    <?php endif; ?>

                    &nbsp;|&nbsp;

                    <a href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Delete this campaign?');">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
