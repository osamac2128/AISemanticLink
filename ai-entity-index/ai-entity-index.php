<?php
/**
 * Plugin Name: AI Entity Index
 * Plugin URI: https://vibeai.dev/ai-entity-index
 * Description: Semantic Truth Layer for WordPress - Extract, normalize, and link named entities with Schema.org JSON-LD output
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Author: Vibe Architect
 * Author URI: https://vibeai.dev
 * License: Proprietary
 * License URI: LICENSE
 * Text Domain: ai-entity-index
 * Domain Path: /languages
 *
 * @package Vibe\AIIndex
 * @copyright 2026 Vibe Architect. All Rights Reserved.
 *
 * PROPRIETARY SOFTWARE - ALL RIGHTS RESERVED
 *
 * This software is the confidential and proprietary property of Vibe Architect.
 * Unauthorized copying, modification, distribution, or use of this software,
 * via any medium, is strictly prohibited without the express written consent
 * of Vibe Architect.
 *
 * See LICENSE file for full license terms.
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
