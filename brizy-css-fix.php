<?php
/**
 * Plugin Name: Brizy CSS Fix
 * Plugin URI: https://github.com/ordinary82/brizy-css-fix
 * Description: Fixes broken layouts after Brizy 2.8.8+ updates by restoring missing CSS files and providing per-page compiled data clearing.
 * Version: 1.2.3
 * Author: dustin.com.au
 * Author URI: https://dustin.com.au
 * GitHub Plugin URI: ordinary82/brizy-css-fix
 * Primary Branch: main
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class Brizy_CSS_Fix {

    const VERSION = '1.2.3';

    private static $css_maps = [
        [
            'src'  => 'brizy/public/editor-build/prod/editor/css/main.base.min.css',
            'dest' => 'brizy/public/editor-build/prod/editor/css/preview.min.css',
        ],
        [
            'src'  => 'brizy-pro/public/editor-build/prod/css/main.base.pro.min.css',
            'dest' => 'brizy-pro/public/editor-build/prod/css/preview.pro.min.css',
        ],
    ];

    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);

        if (!self::brizy_installed()) return;

        add_action('admin_init', [__CLASS__, 'maybe_copy_css']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('admin_notices', [__CLASS__, 'handle_clear_action']);
        add_action('admin_notices', [__CLASS__, 'stale_css_warning']);
    }

    /**
     * Check if Brizy is installed (active or inactive).
     */
    private static function brizy_installed() {
        return file_exists(WP_PLUGIN_DIR . '/brizy/brizy.php');
    }

    /**
     * On activation: copy CSS files and store the current Brizy version.
     */
    public static function on_activate() {
        if (!self::brizy_installed()) return;
        self::copy_css_files();
        self::store_brizy_version();
    }

    /**
     * On deactivation: remove the copied CSS files and clean up options.
     */
    public static function on_deactivate() {
        $plugin_dir = WP_PLUGIN_DIR . '/';
        foreach (self::$css_maps as $map) {
            $dest = $plugin_dir . $map['dest'];
            if (file_exists($dest)) {
                @unlink($dest);
            }
        }
        delete_option('brizy_css_fix_version');
        delete_option('brizy_css_fix_plugin_version');
    }

    /**
     * On admin_init: re-copy CSS if Brizy was updated since last copy.
     */
    public static function maybe_copy_css() {
        if (!defined('BRIZY_VERSION')) return;

        $stored_brizy  = get_option('brizy_css_fix_version', '');
        $stored_plugin = get_option('brizy_css_fix_plugin_version', '');

        if ($stored_brizy !== BRIZY_VERSION || $stored_plugin !== self::VERSION) {
            self::copy_css_files();
            self::store_brizy_version();
            update_option('brizy_css_fix_plugin_version', self::VERSION);
        }
    }

    /**
     * Copy main.base.min.css → preview.min.css (and pro equivalent).
     */
    private static function copy_css_files() {
        $plugin_dir = WP_PLUGIN_DIR . '/';
        foreach (self::$css_maps as $map) {
            $src  = $plugin_dir . $map['src'];
            $dest = $plugin_dir . $map['dest'];
            if (file_exists($src)) {
                @copy($src, $dest);
            }
        }
    }

    /**
     * Store the current Brizy version so we know when it updates.
     */
    private static function store_brizy_version() {
        $version = defined('BRIZY_VERSION') ? BRIZY_VERSION : '';
        update_option('brizy_css_fix_version', $version);
    }

    /**
     * Add meta box to Brizy post edit screens.
     */
    public static function add_meta_box() {
        $screen = get_current_screen();
        if (!$screen) return;

        global $post;
        if (!$post || !get_post_meta($post->ID, 'brizy_post_uid', true)) return;

        add_meta_box(
            'brizy-css-fix',
            'Brizy Compiled Data',
            [__CLASS__, 'render_meta_box'],
            $screen->id,
            'side',
            'high'
        );
    }

    /**
     * Render the meta box with clear button and status.
     */
    public static function render_meta_box($post) {
        $has_compiled = metadata_exists('post', $post->ID, 'brizy-compiled-sections');
        $nonce = wp_create_nonce('brizy_css_fix_clear_' . $post->ID);
        $clear_url = add_query_arg([
            'brizy_css_fix_clear' => $post->ID,
            '_bcf_nonce' => $nonce,
        ]);

        echo '<p style="margin-bottom:8px;">';
        if ($has_compiled) {
            $compiled = get_post_meta($post->ID, 'brizy-compiled-sections', true);
            $is_stale = is_string($compiled) && (strpos($compiled, 'preview.min.css') !== false || strpos($compiled, 'preview.pro.min.css') !== false);

            if ($is_stale) {
                echo '<span style="color:#dba617;">&#9888;</span> Compiled data has stale CSS references — working via workaround, resave in Brizy editor for permanent fix';
            } else {
                echo '<span style="color:#00a32a;">&#10003;</span> Compiled data is up to date';
            }
        } else {
            echo '<span style="color:#d63638;">&#10007;</span> No compiled data — page will be blank until resaved in Brizy editor';
        }
        echo '</p>';

        if ($has_compiled) {
            echo '<a href="' . esc_url($clear_url) . '" class="button" onclick="return confirm(\'Clear compiled data for this page? It will appear blank until resaved in the Brizy editor.\');">Clear Compiled Data</a>';
            echo '<p class="description" style="margin-top:6px;">Clears stale compiled data so the page recompiles on next editor save.</p>';
        }
    }

    /**
     * Handle the clear action from the meta box button.
     */
    public static function handle_clear_action() {
        if (!isset($_GET['brizy_css_fix_clear']) || !isset($_GET['_bcf_nonce'])) return;

        $post_id = absint($_GET['brizy_css_fix_clear']);
        if (!wp_verify_nonce($_GET['_bcf_nonce'], 'brizy_css_fix_clear_' . $post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $deleted = delete_post_meta($post_id, 'brizy-compiled-sections');
        delete_transient('brizy_css_fix_stale_count');
        $title = get_the_title($post_id);

        if ($deleted) {
            echo '<div class="notice notice-success is-dismissible"><p>Cleared compiled data for <strong>' . esc_html($title) . '</strong>. Open in Brizy editor and save to recompile.</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>No compiled data found for <strong>' . esc_html($title) . '</strong>.</p></div>';
        }
    }
    /**
     * Show dashboard warning if any pages have stale CSS references.
     */
    public static function stale_css_warning() {
        if (!current_user_can('manage_options')) return;

        $transient = get_transient('brizy_css_fix_stale_count');
        if ($transient === false) {
            global $wpdb;
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND (meta_value LIKE %s OR meta_value LIKE %s)",
                'brizy-compiled-sections',
                '%preview.min.css%',
                '%preview.pro.min.css%'
            ));
            set_transient('brizy_css_fix_stale_count', $count, HOUR_IN_SECONDS);
        } else {
            $count = (int) $transient;
        }

        if ($count === 0) return;

        $s = $count === 1 ? '' : 's';
        echo '<div class="notice notice-warning">';
        echo '<p><strong>Brizy CSS Fix:</strong> ' . $count . ' page' . $s . ' still referencing outdated CSS files. ';
        echo 'These pages are working thanks to the workaround CSS files, but should be resaved in the Brizy editor for a permanent fix.</p>';
        echo '</div>';
    }
}

Brizy_CSS_Fix::init();
