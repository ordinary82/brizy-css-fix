<?php
/**
 * Plugin Name: Brizy CSS Fix
 * Plugin URI: https://github.com/ordinary82/brizy-css-fix
 * Description: Fixes broken layouts after Brizy 2.8.8+ updates by restoring missing CSS files and providing per-page compiled data clearing.
 * Version: 1.3.3
 * Author: dustin.com.au
 * Author URI: https://dustin.com.au
 * GitHub Plugin URI: ordinary82/brizy-css-fix
 * Primary Branch: main
 * Release Asset: true
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class Brizy_CSS_Fix {

    const VERSION = '1.3.3';

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
        add_filter('default_hidden_meta_boxes', [__CLASS__, 'hide_meta_box_by_default']);
    }

    /**
     * Check if Brizy is installed (active or inactive).
     */
    private static function brizy_installed() {
        return file_exists(WP_PLUGIN_DIR . '/brizy/brizy.php');
    }

    /**
     * On activation: copy CSS files and store current Brizy core + pro versions.
     */
    public static function on_activate() {
        if (!self::brizy_installed()) return;
        self::copy_css_files();
        self::store_brizy_versions();
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
        delete_option('brizy_css_fix_pro_version');
        delete_option('brizy_css_fix_plugin_version');
    }

    /**
     * On admin_init: re-copy CSS if Brizy core OR Pro was updated since last copy.
     */
    public static function maybe_copy_css() {
        if (!defined('BRIZY_VERSION') && !defined('BRIZY_PRO_VERSION')) return;

        $brizy     = defined('BRIZY_VERSION')     ? BRIZY_VERSION     : '';
        $brizy_pro = defined('BRIZY_PRO_VERSION') ? BRIZY_PRO_VERSION : '';

        $stored_brizy     = get_option('brizy_css_fix_version', '');
        $stored_brizy_pro = get_option('brizy_css_fix_pro_version', '');
        $stored_plugin    = get_option('brizy_css_fix_plugin_version', '');

        if ($stored_brizy     !== $brizy
         || $stored_brizy_pro !== $brizy_pro
         || $stored_plugin    !== self::VERSION) {
            self::copy_css_files();
            update_option('brizy_css_fix_version',        $brizy);
            update_option('brizy_css_fix_pro_version',    $brizy_pro);
            update_option('brizy_css_fix_plugin_version', self::VERSION);
            delete_transient('brizy_css_fix_stale_count');
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
     * Store current Brizy core + pro versions so we know when either updates.
     */
    private static function store_brizy_versions() {
        update_option('brizy_css_fix_version',     defined('BRIZY_VERSION')     ? BRIZY_VERSION     : '');
        update_option('brizy_css_fix_pro_version', defined('BRIZY_PRO_VERSION') ? BRIZY_PRO_VERSION : '');
    }

    /**
     * Add meta box to Brizy post edit screens.
     */
    public static function add_meta_box() {
        if (!current_user_can('manage_options')) return;

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
            $is_stale = self::compiled_data_is_stale($compiled);

            if ($is_stale) {
                echo '<span style="color:#dba617;">&#9888;</span> Compiled by older Brizy version — using workaround CSS. Resave in Brizy editor for a permanent fix.';
            } else {
                echo '<span style="color:#00a32a;">&#10003;</span> Compiled data references current CSS files';
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
        if (!current_user_can('manage_options')) return;

        $post_id = absint($_GET['brizy_css_fix_clear']);
        if (!wp_verify_nonce($_GET['_bcf_nonce'], 'brizy_css_fix_clear_' . $post_id)) return;

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
     * Show dashboard warning if any pages were compiled by an older Brizy version.
     */
    public static function stale_css_warning() {
        if (!current_user_can('manage_options')) return;

        $transient = get_transient('brizy_css_fix_stale_count');
        if ($transient === false) {
            global $wpdb;
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                'brizy-compiled-sections'
            ));
            $count = 0;
            foreach ($rows as $val) {
                if (self::compiled_data_is_stale($val)) $count++;
            }
            set_transient('brizy_css_fix_stale_count', $count, HOUR_IN_SECONDS);
        } else {
            $count = (int) $transient;
        }

        if ($count === 0) return;

        $s = $count === 1 ? '' : 's';
        echo '<div class="notice notice-warning">';
        echo '<p><strong>Brizy CSS Fix:</strong> ' . $count . ' page' . $s . ' compiled by an older Brizy version. ';
        echo 'These are rendering via the workaround CSS files. Resave each in the Brizy editor for a permanent fix.</p>';
        echo '</div>';
    }

    /**
     * Hide the meta box by default — admins can re-show it via Screen Options.
     */
    public static function hide_meta_box_by_default($hidden) {
        $hidden[] = 'brizy-css-fix';
        return $hidden;
    }

    /**
     * Brizy 2.8.x+ embeds CSS file paths in compiled-sections meta (base64 JSON).
     * Older versions did not, so absence of any .css path = compiled by older Brizy
     * and likely depending on the workaround CSS files.
     */
    private static function compiled_data_is_stale($compiled) {
        if (!is_string($compiled) || $compiled === '') return false;
        $decoded = base64_decode($compiled, true);
        if (!is_string($decoded) || $decoded === '') return false;
        return strpos($decoded, '.css') === false;
    }
}

Brizy_CSS_Fix::init();
