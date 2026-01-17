<?php
/**
 * Minimal autoloader for AI Entity Index plugin
 * Generated without Composer - provides PSR-4 autoloading for plugin classes
 */

spl_autoload_register(function ($class) {
    // Plugin namespace prefix
    $prefix = 'Vibe\\AIIndex\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/../includes/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators and append .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Load Action Scheduler if available
$action_scheduler_path = __DIR__ . '/woocommerce/action-scheduler/action-scheduler.php';
if (file_exists($action_scheduler_path)) {
    require_once $action_scheduler_path;
}
