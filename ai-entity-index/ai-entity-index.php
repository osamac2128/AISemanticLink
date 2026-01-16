<?php
/**
 * Plugin Name: AI Entity Index
 * Description: Semantic Truth Layer for WordPress - Extract, normalize, and link named entities with Schema.org JSON-LD output
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Vibe Architect
 */

declare(strict_types=1);

namespace Vibe\AIIndex;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('VIBE_AI_VERSION', '1.0.0');
define('VIBE_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIBE_AI_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
require_once VIBE_AI_PLUGIN_DIR . 'vendor/autoload.php';

// Initialize plugin
add_action('plugins_loaded', function() {
    // Load dependencies
    require_once VIBE_AI_PLUGIN_DIR . 'includes/Config.php';
    require_once VIBE_AI_PLUGIN_DIR . 'includes/Activator.php';
    require_once VIBE_AI_PLUGIN_DIR . 'includes/Plugin.php';

    // Initialize main plugin class
    $plugin = new Plugin();
    $plugin->run();
});

// Activation/Deactivation hooks
register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Activator::class, 'deactivate']);
