<?php
namespace AAB\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {

    public static function init() {
        // register settings fields and sections only
        add_action('admin_init', [self::class, 'register']);
    }

    public static function register() {
        register_setting('aab_settings', 'aab_ai_provider', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'openai',
        ]);

        // Preserve existing key when the input is left empty on save
        register_setting('aab_settings', 'aab_api_key', [
            'sanitize_callback' => [self::class, 'sanitize_api_key_preserve'],
            'default' => '',
        ]);

        register_setting('aab_settings', 'aab_model', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-5-mini',
        ]);

        // Preserve OpenRouter key the same way
        register_setting('aab_settings', 'aab_openrouter_key', [
            'sanitize_callback' => [self::class, 'sanitize_openrouter_key_preserve'],
            'default' => '',
        ]);

        add_settings_section(
            'aab_main',
            '',
            '__return_false',
            'aab-settings'
        );

        add_settings_field(
            'aab_provider',
            'AI Provider',
            [self::class, 'provider_field'],
            'aab-settings',
            'aab_main'
        );

        add_settings_field(
            'aab_openai_key',
            'OpenAI API Key',
            [self::class, 'openai_key_field'],
            'aab-settings',
            'aab_main'
        );

        add_settings_field(
            'aab_model',
            'GPT Model',
            [self::class, 'model_field'],
            'aab-settings',
            'aab_main'
        );

        add_settings_field(
            'aab_openrouter_key',
            'OpenRouter API Key',
            [self::class, 'openrouter_key_field'],
            'aab-settings',
            'aab_main'
        );
    }

    /**
     * Backwards-compatible generic sanitizer (kept for other uses).
     */
    public static function sanitize_key($val) {
        return trim(sanitize_text_field($val ?? ''));
    }

    /**
     * Preserve existing OpenAI API key when the submitted value is empty.
     * If a new non-empty key is provided, it will be sanitized and saved.
     */
    public static function sanitize_api_key_preserve($new_value) {
        // normalize and sanitize incoming value
        $val = trim(sanitize_text_field(wp_unslash($new_value ?? '')));

        // if submitted empty, keep previously saved key
        if ($val === '') {
            return get_option('aab_api_key', '');
        }

        return $val;
    }

    /**
     * Preserve existing OpenRouter API key when the submitted value is empty.
     */
    public static function sanitize_openrouter_key_preserve($new_value) {
        $val = trim(sanitize_text_field(wp_unslash($new_value ?? '')));

        if ($val === '') {
            return get_option('aab_openrouter_key', '');
        }

        return $val;
    }

    public static function provider_field() {
        $current = get_option('aab_ai_provider', 'openai');
        ?>
        <select name="aab_ai_provider" id="aab-ai-provider">
            <option value="openai" <?php selected($current, 'openai'); ?>>OpenAI</option>
            <option value="openrouter" <?php selected($current, 'openrouter'); ?>>OpenRouter</option>
        </select>
        <?php
    }

    public static function openai_key_field() {
        self::password_field('aab_api_key', 'OpenAI');
    }

    public static function openrouter_key_field() {
        self::password_field('aab_openrouter_key', 'OpenRouter');
    }

    private static function password_field($option, $label) {
        $saved = get_option($option, '');
        $has = !empty($saved);
        $masked = $has ? '••••••••' . substr($saved, -4) : '';
        ?>
        <input type="password" name="<?php echo esc_attr($option); ?>"
               placeholder="Enter <?php echo esc_attr($label); ?> API Key"
               style="width:420px;">

        <?php if ($has): ?>
            <p class="description">Saved key: <strong><?php echo esc_html($masked); ?></strong></p>
        <?php else: ?>
            <p class="description">No key saved.</p>
        <?php endif;
    }

    public static function model_field() {
        $models = [
            'gpt-4' => 'gpt-4',
            'gpt-4o-mini' => 'gpt-4o-mini',
            'gpt-4o' => 'gpt-4o',
            'gpt-4.1-2025-04-14' => 'gpt-4.1-2025-04-14',
        ];
        

        // align default with register_setting default above
        $current = get_option('aab_model', 'gpt-4.1-2025-04-14');

        echo '<select name="aab_model">';
        foreach ($models as $key => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($key),
                selected($current, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        ?>
        <div class="wrap">
            <h1>AI Auto Blog — Settings</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('aab_settings');
                do_settings_sections('aab-settings');
                
                submit_button();
                ?>
            </form>
            
        </div>

        <script>
        (function () {
            const provider = document.getElementById('aab-ai-provider');
            const rows = document.querySelectorAll('tr');

            function toggleFields() {
                rows.forEach(row => {
                    if (row.innerText.includes('OpenAI API Key') || row.innerText.includes('GPT Model')) {
                        row.style.display = provider.value === 'openai' ? '' : 'none';
                    }
                    if (row.innerText.includes('OpenRouter API Key')) {
                        row.style.display = provider.value === 'openrouter' ? '' : 'none';
                    }
                });
            }

            provider && provider.addEventListener('change', toggleFields);
            toggleFields();
        })();
        </script>
        <?php
    }
}
