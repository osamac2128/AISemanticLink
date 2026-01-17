<?php
/**
 * Plugin Name: AI Entity Index
 * Plugin URI: https://vibeai.dev/ai-entity-index
 * Description: Semantic Truth Layer for WordPress - Extract, normalize, and link named entities with Schema.org JSON-LD output
 * Version: 1.0.5
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Author: Vibe Architect
 * Author URI: https://vibeai.dev
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-entity-index
 * Domain Path: /languages
 *
 * @package Vibe\AIIndex
 * @copyright 2026 Vibe Architect. All Rights Reserved.
 *
 * See LICENSE file for full license terms.
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('VIBE_AI_VERSION', '1.0.5');
define('VIBE_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIBE_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VIBE_AI_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check PHP version requirement
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('AI Entity Index requires PHP 8.1 or higher. Please upgrade your PHP version.', 'ai-entity-index');
        echo '</p></div>';
    });
    return;
}

/**
 * Check if vendor autoload exists
 */
$autoload_file = VIBE_AI_PLUGIN_DIR . 'vendor/autoload.php';
if (!file_exists($autoload_file)) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo '<strong>AI Entity Index:</strong> ';
        echo esc_html__('Dependencies not installed. The "vendor" directory is missing. If you installed this from source, please run "composer install".', 'ai-entity-index');
        echo '</p></div>';
    });
    // Stop execution to prevent fatal errors
    return;
}

// Load Composer autoloader
require_once $autoload_file;

/**
 * Initialize Action Scheduler
 * Action Scheduler is bundled via Composer
 */
add_action('plugins_loaded', function () {
    // Action Scheduler is loaded via Composer autoload
    // Check if it's available
    if (!class_exists('ActionScheduler')) {
        // Try loading from vendor path
        $as_path = VIBE_AI_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
        if (file_exists($as_path)) {
            require_once $as_path;
        }
    }
}, 1);

/**
 * Initialize the plugin
 */
add_action('plugins_loaded', function () {
    // Ensure our namespace is available via autoload
    if (!class_exists('Vibe\\AIIndex\\Plugin')) {
        return;
    }

    // Initialize main plugin class
    $plugin = new Vibe\AIIndex\Plugin();
    $plugin->run();
}, 10);

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function () {
    // Check dependencies
    if (!file_exists(VIBE_AI_PLUGIN_DIR . 'vendor/autoload.php')) {
        wp_die(
            esc_html__('AI Entity Index requires dependencies to be installed. Please run "composer install" first.', 'ai-entity-index'),
            esc_html__('Plugin Activation Error', 'ai-entity-index'),
            ['back_link' => true]
        );
    }

    require_once VIBE_AI_PLUGIN_DIR . 'vendor/autoload.php';

    if (class_exists('Vibe\\AIIndex\\Activator')) {
        Vibe\AIIndex\Activator::activate();
    }
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function () {
    if (class_exists('Vibe\\AIIndex\\Activator')) {
        Vibe\AIIndex\Activator::deactivate();
    }
});

/**
 * Add settings link to plugins page
 */
add_filter('plugin_action_links_' . VIBE_AI_PLUGIN_BASENAME, function ($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=ai-entity-index') . '">' .
        esc_html__('Settings', 'ai-entity-index') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
