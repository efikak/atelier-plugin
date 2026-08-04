<?php
/**
 * Plugin Name: Quiz Atelier
 * Plugin URI: https://effiek.gr
 * Description: Επαγγελματικό studio για quiz, polls και personality tests με Organizations, editorial workflow, analytics, templates και ασφαλή portable embeds.
  * Version: 1.0.2
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Author: Έφη Κακούνη
 * Author URI: https://effiek.gr
 * Text Domain: wp-quiz-studio
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WPQS_VERSION', '1.0.2');
define('WPQS_DB_VERSION', '1.0.0');
define('WPQS_FILE', __FILE__);
define('WPQS_DIR', plugin_dir_path(__FILE__));
define('WPQS_URL', plugin_dir_url(__FILE__));

$composerAutoloader = WPQS_DIR . 'vendor/autoload.php';
if (is_readable($composerAutoloader)) {
    require_once $composerAutoloader;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WPQuizStudio\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = WPQS_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(__FILE__, [WPQuizStudio\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [WPQuizStudio\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    (new WPQuizStudio\Plugin())->boot();
});
