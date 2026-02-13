<?php
if (!defined('ABSPATH')) exit;

// Core requirements...
require_once AAB_PATH . 'includes/Core/CampaignPostType.php';
require_once AAB_PATH . 'includes/Core/CampaignSaver.php';
require_once AAB_PATH . 'includes/Core/CampaignScheduler.php';
require_once AAB_PATH . 'includes/Core/CampaignRunner.php';
require_once AAB_PATH . 'includes/Core/License.php';
require_once AAB_PATH . 'includes/Core/Logger.php';

// Provider requirements...
require_once AAB_PATH . 'includes/Providers/OpenAI.php';
require_once AAB_PATH . 'includes/Providers/Claude.php';
require_once AAB_PATH . 'includes/Providers/Gemini.php';

// Admin requirements - Menu must be loaded before Settings
require_once AAB_PATH . 'includes/Admin/Menu.php';
require_once AAB_PATH . 'includes/Admin/Settings.php';
require_once AAB_PATH . 'includes/Admin/ajax-kw-suggest.php';
// require_once AAB_PATH . 'includes/Admin/LicenseGenerator.php';


// Load extensions (image integration, etc.)
require_once AAB_PATH . 'includes/Extensions/ImageIntegration.php';
require_once AAB_PATH . 'includes/Extensions/CampaignResume.php';
require_once AAB_PATH . 'includes/Extensions/SEO.php'; // ← SEO MODULE ADDED
require_once AAB_PATH . 'includes/Extensions/SEOStats.php'; // ← SEO STATS MODULE


// Register CPT on init
add_action('init', function () {
    \AAB\Core\CampaignPostType::register();
});

// Initialize after plugins_loaded in correct order
add_action('plugins_loaded', function () {
    \AAB\Admin\Menu::init();
    \AAB\Admin\Settings::init();
    \AAB\Core\CampaignSaver::init();
    \AAB\Core\CampaignScheduler::init();
    \AAB\Extensions\CampaignResume::init();
    \AAB\Core\License::init(); 
    // \AAB\Core\Logger::init(); // ← LOGGER ENABLED (was commented)
    \AAB\Extensions\SEO::init(); // ← SEO INITIALIZED
    // \AAB\Admin\LicenseGenerator::init();
});