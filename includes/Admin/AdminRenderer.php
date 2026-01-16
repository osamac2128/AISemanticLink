<?php

declare(strict_types=1);

namespace Vibe\AIIndex\Admin;

use Vibe\AIIndex\Config;

/**
 * Handle Admin UI rendering and assets.
 */
class AdminRenderer
{
    /** @var string Plugin text domain */
    private string $textDomain = 'ai-entity-index';

    /** @var string Plugin version */
    private string $version;

    public function __construct(string $version)
    {
        $this->version = $version;
    }

    /**
     * Register admin hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'displayNotices']);
        add_action('wp_ajax_vibe_ai_dismiss_notice', [$this, 'dismissNotice']);
    }

    /**
     * Register the admin menu page.
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('AI Entity Index', $this->textDomain),
            __('AI Entity Index', $this->textDomain),
            Config::REQUIRED_CAPABILITY,
            'vibe-ai-index',
            [$this, 'renderPage'],
            'dashicons-networking',
            30
        );

        // Submenus for deep liking support in UI
        $submenus = [
            'Dashboard' => 'vibe-ai-index',
            'Entities' => 'vibe-ai-index#/entities',
            'Settings' => 'vibe-ai-index#/settings',
            'Logs' => 'vibe-ai-index#/logs',
        ];

        foreach ($submenus as $label => $slug) {
            add_submenu_page(
                'vibe-ai-index',
                __($label, $this->textDomain),
                __($label, $this->textDomain),
                Config::REQUIRED_CAPABILITY,
                $slug,
                [$this, 'renderPage']
            );
        }
    }

    /**
     * Render the admin page (React SPA container).
     */
    public function renderPage(): void
    {
        if (!current_user_can(Config::REQUIRED_CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.', $this->textDomain));
        }

        echo '<div id="vibe-ai-admin" class="wrap"></div>';
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'vibe-ai-index') === false) {
            return;
        }

        $asset_file = VIBE_AI_PLUGIN_DIR . 'build/index.asset.php';
        $asset = file_exists($asset_file)
            ? require $asset_file
            : ['dependencies' => [], 'version' => $this->version];

        wp_enqueue_script(
            'vibe-ai-admin',
            VIBE_AI_PLUGIN_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'vibe-ai-admin',
            VIBE_AI_PLUGIN_URL . 'build/index.css',
            [],
            $asset['version']
        );

        wp_localize_script('vibe-ai-admin', 'vibeAiData', [
            'apiUrl' => rest_url(Config::REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => $this->version,
            'pollingInterval' => Config::POLLING_INTERVAL_MS,
            'adminUrl' => admin_url('admin.php?page=vibe-ai-index'),
            'pluginUrl' => VIBE_AI_PLUGIN_URL,
        ]);
    }

    /**
     * Display admin notices.
     */
    public function displayNotices(): void
    {
        if (!defined('VIBE_AI_OPENROUTER_KEY') || empty(VIBE_AI_OPENROUTER_KEY)) {
            $dismissed = get_option('vibe_ai_api_key_notice_dismissed', false);

            if (!$dismissed) {
                echo '<div class="notice notice-warning is-dismissible" data-notice="vibe_ai_api_key">';
                echo '<p><strong>' . esc_html__('AI Entity Index:', $this->textDomain) . '</strong> ';
                echo esc_html__('Please configure your OpenRouter API key in wp-config.php to enable entity extraction.', $this->textDomain);
                echo '</p></div>';
            }
        }
    }

    /**
     * AJAX handler for dismissing notices.
     */
    public function dismissNotice(): void
    {
        check_ajax_referer('vibe_ai_nonce', 'nonce');

        if (!current_user_can(Config::REQUIRED_CAPABILITY)) {
            wp_send_json_error('Unauthorized');
        }

        $notice = sanitize_text_field($_POST['notice'] ?? '');

        if ($notice === 'vibe_ai_api_key') {
            update_option('vibe_ai_api_key_notice_dismissed', true);
        }

        wp_send_json_success();
    }
}
