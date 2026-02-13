<?php
namespace AAB\Admin;

if (!defined('ABSPATH')) exit;

class Menu {

    // single source of truth for parent slug
    const PARENT_SLUG = 'aab-dashboard';

    public static function init() {
        add_action('admin_menu', [self::class, 'register']);
        // Handle all campaign actions BEFORE page rendering so headers can be sent
        add_action('admin_init', [self::class, 'handle_campaign_actions']);
        // Enqueue admin styles
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue admin assets (CSS and JS)
     */
    public static function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        $our_pages = [
            'toplevel_page_aab-dashboard',
            'ai-auto-blog_page_aab-campaigns',
            'ai-auto-blog_page_aab-new-campaign',
            'ai-auto-blog_page_aab-seo',
            'ai-auto-blog_page_aab-settings',
            'ai-auto-blog_page_aab-import-export',
            'ai-auto-blog_page_aab-log',
        ];

        if (in_array($hook, $our_pages)) {
            wp_enqueue_style(
                'aab-dashboard-styles',
                AAB_URL . 'assets/admin/css/aab-dashboard.css',
                [],
                '1.0.0'
            );
        }
    }

    /**
     * Handle all campaign list page actions.
     * Runs on admin_init — before any page output — so wp_redirect and file headers work.
     */
    public static function handle_campaign_actions() {

        // Only run on the campaigns page
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'aab-campaigns' ) {
            return;
        }
        
        // License check — block all actions if not activated
        if ( ! \AAB\Core\License::is_license_active() ) {
            wp_redirect( admin_url( 'admin.php?page=aab-settings&license_error=not_activated' ) );
            exit;
        }
        
        // ============================================================
        // GET-based single-campaign actions
        // ============================================================
        if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) ) {

            $action      = sanitize_text_field( $_GET['action'] );
            $campaign_id = intval( $_GET['id'] );

            // -- EXPORT (single) -------------------------------------
            if ( $action === 'export' ) {
                if ( wp_verify_nonce( $_GET['_wpnonce'], 'aab_export_campaign_' . $campaign_id ) ) {
                    $campaign = get_post( $campaign_id );
                    if ( $campaign ) {
                        $meta = get_post_meta( $campaign_id );
                        $export_data = [
                            'title' => $campaign->post_title,
                            'meta'  => array_map( function( $v ) {
                                return maybe_unserialize( $v[0] );
                            }, $meta ),
                        ];
                        header( 'Content-Type: application/json; charset=utf-8' );
                        header( 'Content-Disposition: attachment; filename="campaign-' . $campaign_id . '-' . date( 'Y-m-d' ) . '.json"' );
                        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
                        header( 'Pragma: no-cache' );
                        header( 'Expires: 0' );
                        echo json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
                        exit;
                    }
                }
            }

            // -- TOGGLE ENABLE / DISABLE -----------------------------
            if ( $action === 'toggle_enable' ) {
                if ( wp_verify_nonce( $_GET['_wpnonce'], 'aab_toggle_enable_' . $campaign_id ) ) {
                    $current = (bool) get_post_meta( $campaign_id, 'aab_enabled', true );
                    update_post_meta( $campaign_id, 'aab_enabled', ! $current );
                    wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=toggled' ) );
                    exit;
                }
            }

            // -- RUN NOW ---------------------------------------------
            if ( $action === 'run_now' ) {
                if ( wp_verify_nonce( $_GET['_wpnonce'], 'aab_run_now_' . $campaign_id ) ) {
                    if ( class_exists( '\\AAB\\Core\\CampaignRunner' ) ) {
                        try {
                            \AAB\Core\CampaignRunner::run( $campaign_id );
                            wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=run_success' ) );
                        } catch ( \Exception $e ) {
                            wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=run_error' ) );
                        }
                        exit;
                    }
                }
            }

            // -- DELETE / TRASH --------------------------------------
            // FIXED: Now PERMANENTLY deletes from database instead of just moving to trash
            if ( $action === 'delete' ) {
                if ( wp_verify_nonce( $_GET['_wpnonce'], 'aab_delete_campaign_' . $campaign_id ) ) {
                    // Verify the post exists
                    $post = get_post( $campaign_id );
                    if ( $post ) {
                        // PERMANENTLY delete from database (not just trash)
                        // This removes the post from wp_posts table completely
                        $deleted = wp_delete_post( $campaign_id, true ); // true = force delete, bypass trash
                        
                        if ( $deleted ) {
                            wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=deleted' ) );
                            exit;
                        }
                    }
                    // If doesn't exist or delete failed, just redirect
                    wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=deleted' ) );
                    exit;
                }
            }

            // -- DUPLICATE -------------------------------------------
            if ( $action === 'duplicate' ) {
                if ( wp_verify_nonce( $_GET['_wpnonce'], 'aab_duplicate_campaign_' . $campaign_id ) ) {
                    $original = get_post( $campaign_id );
                    if ( $original ) {
                        $new_id = wp_insert_post( [
                            'post_title'  => $original->post_title . ' (Copy)',
                            'post_type'   => 'aab_campaign',
                            'post_status' => 'publish',
                        ] );

                        if ( $new_id && ! is_wp_error( $new_id ) ) {
                            $meta_keys = [
                                'aab_keywords', 'min_words', 'max_words', 'max_posts',
                                'rotate_keywords', 'keyword_as_title', 'one_post_per_keyword',
                                'use_custom_prompt', 'custom_title_prompt', 'custom_content_prompt',
                                'aab_run_interval', 'aab_run_unit', 'aab_post_type', 'aab_post_status',
                                'aab_post_author', 'aab_set_category', 'aab_categories',
                                'aab_ai_custom_params', 'aab_ai_max_tokens', 'aab_ai_temperature',
                                // Image settings
                                'aab_feat_generate', 'aab_feat_image_method', 'aab_feat_image_model',
                                'aab_feat_image_size', 'aab_feat_get_image_by_prompt', 'aab_feat_custom_prompt',
                                'aab_content_image_method', 'aab_content_image_model', 'aab_content_image_size',
                                'aab_get_image_by_title', 'aab_get_image_by_prompt', 'aab_content_custom_prompt',
                                'aab_set_keyword_as_alt', 'aab_search_by_parent_keyword',
                                'aab_content_num_images', 'aab_content_dist',
                                'aab_content_pos_1', 'aab_content_pos_2', 'aab_content_pos_3',
                                'aab_content_wp_image_size',
                            ];

                            foreach ( $meta_keys as $key ) {
                                $value = get_post_meta( $campaign_id, $key, true );
                                if ( $value !== '' ) {
                                    update_post_meta( $new_id, $key, $value );
                                }
                            }

                            update_post_meta( $new_id, 'aab_enabled', 0 );
                        }

                        wp_redirect( admin_url( 'admin.php?page=aab-new-campaign&edit=' . $new_id . '&msg=duplicated' ) );
                        exit;
                    }
                }
            }
        }

        // ============================================================
        // POST-based bulk actions — verify nonce ONCE here
        // ============================================================
        if ( isset( $_POST['bulk_action'], $_POST['campaign_ids'], $_POST['_wpnonce'] ) ) {

            // Single nonce verification — do NOT call check_admin_referer again below
            if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'aab_bulk_actions' ) ) {
                return;
            }

            $bulk_action = sanitize_text_field( $_POST['bulk_action'] );
            $ids         = array_map( 'intval', (array) $_POST['campaign_ids'] );

            // -- BULK EXPORT -----------------------------------------
            if ( $bulk_action === 'export' ) {
                $export_data = [];
                foreach ( $ids as $id ) {
                    $campaign = get_post( $id );
                    if ( $campaign ) {
                        $meta          = get_post_meta( $id );
                        $export_data[] = [
                            'title' => $campaign->post_title,
                            'meta'  => array_map( function( $v ) {
                                return maybe_unserialize( $v[0] );
                            }, $meta ),
                        ];
                    }
                }
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="campaigns-bulk-' . date( 'Y-m-d' ) . '.json"' );
                header( 'Cache-Control: no-cache, no-store, must-revalidate' );
                header( 'Pragma: no-cache' );
                header( 'Expires: 0' );
                echo json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
                exit;
            }

            // -- BULK DELETE -----------------------------------------
            // FIXED: Now PERMANENTLY deletes from database instead of just moving to trash
            if ( $bulk_action === 'delete' ) {
                $deleted_count = 0;
                foreach ( $ids as $id ) {
                    // Verify the post exists
                    $post = get_post( $id );
                    if ( $post ) {
                        // PERMANENTLY delete from database (not just trash)
                        // This removes the post from wp_posts table completely
                        $deleted = wp_delete_post( $id, true ); // true = force delete, bypass trash
                        
                        if ( $deleted ) {
                            $deleted_count++;
                        }
                    }
                }
                wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=bulk_deleted&count=' . $deleted_count ) );
                exit;
            }

            // -- BULK ENABLE -----------------------------------------
            if ( $bulk_action === 'enable' ) {
                foreach ( $ids as $id ) {
                    update_post_meta( $id, 'aab_enabled', 1 );
                }
                wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=bulk_enabled&count=' . count( $ids ) ) );
                exit;
            }

            // -- BULK DISABLE ----------------------------------------
            if ( $bulk_action === 'disable' ) {
                foreach ( $ids as $id ) {
                    update_post_meta( $id, 'aab_enabled', 0 );
                }
                wp_redirect( admin_url( 'admin.php?page=aab-campaigns&msg=bulk_disabled&count=' . count( $ids ) ) );
                exit;
            }
        }
    }

    public static function register() {

        // Top-level menu - Dashboard
        add_menu_page(
            'AI Auto Blog',
            'AI Auto Blog',
            'manage_options',
            self::PARENT_SLUG,
            [self::class, 'dashboard_page'],
            'dashicons-megaphone',
            26
        );

        // Dashboard submenu (renamed first item)
        add_submenu_page(
            self::PARENT_SLUG,
            'Dashboard',
            'Dashboard',
            'manage_options',
            self::PARENT_SLUG,
            [self::class, 'dashboard_page']
        );

        // All Campaigns
        add_submenu_page(
            self::PARENT_SLUG,
            'All Campaigns',
            'All Campaigns',
            'manage_options',
            'aab-campaigns',
            [self::class, 'campaigns_page']
        );

        // New Campaign
        add_submenu_page(
            self::PARENT_SLUG,
            'New Campaign',
            'New Campaign',
            'manage_options',
            'aab-new-campaign',
            [self::class, 'new_campaign_page']
        );

        // SEO (placeholder)
        add_submenu_page(
            self::PARENT_SLUG,
            'SEO',
            'SEO',
            'manage_options',
            'aab-seo',
            [self::class, 'seo_page']
        );

        // Settings
        add_submenu_page(
            self::PARENT_SLUG,
            'AI Auto Blog Settings',
            'Settings',
            'manage_options',
            'aab-settings',
            ['AAB\\Admin\\Settings', 'page']
        );

        // Import & Export
        add_submenu_page(
            self::PARENT_SLUG,
            'Import & Export',
            'Import & Export',
            'manage_options',
            'aab-import-export',
            [self::class, 'import_export_page']
        );

        // Log
        add_submenu_page(
            self::PARENT_SLUG,
            'Log',
            'Log',
            'manage_options',
            'aab-log',
            [self::class, 'log_page']
        );
    }

    public static function dashboard_page() {
        require AAB_PATH . 'includes/Admin/pages/dashboard.php';
    }

    public static function campaigns_page() {
        require AAB_PATH . 'includes/Admin/pages/campaign-list.php';
    }

    public static function new_campaign_page() {
        require AAB_PATH . 'includes/Admin/pages/campaign-new.php';
    }

    public static function seo_page() {
        require AAB_PATH . 'includes/Admin/pages/seo.php';
    }

    public static function import_export_page() {
        require AAB_PATH . 'includes/Admin/pages/import-export.php';
    }

    public static function log_page() {
        require AAB_PATH . 'includes/Admin/pages/log.php';
    }
}