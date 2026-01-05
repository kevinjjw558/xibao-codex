<?php
/**
 * Plugin Name: XIBAO AIGC
 * Description: Leonardo-like UI skeleton for XIBAO AIGC.
 * Version: 0.1.0
 * Author: XIBAO-AIGC
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/models-store.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-models.php';

final class Xibao_AIGC {
    const VERSION = '0.1.0';
    const SHORTCODE = 'xibao_aigc';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_assets']);
        Xibao_AIGC_Admin_Models::init();
    }

    public static function render_shortcode($atts = []) {
        $models = self::get_models();
        $template = plugin_dir_path(__FILE__) . 'templates/generator.php';
        ob_start();
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="xibao-aigc">XIBAO AIGC UI loading...</div>';
        }
        return ob_get_clean();
    }

    public static function maybe_enqueue_assets() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post || !has_shortcode($post->post_content, self::SHORTCODE)) {
            return;
        }

        $base_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('xibao-aigc-app', $base_url . 'assets/css/app.css', [], self::VERSION);
        wp_enqueue_script('xibao-aigc-app', $base_url . 'assets/js/app.js', [], self::VERSION, true);
    }

    private static function get_models() {
        return Xibao_AIGC_Models_Store::get_enabled_models();
    }
}

Xibao_AIGC::init();
