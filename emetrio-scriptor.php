<?php
/**
 * Plugin Name: Emetrio Scriptor
 * Description: Publish blog posts via a secure REST API or generate them automatically with your connected AI providers, with scheduling, brand voice settings, and request logs.
 * Version: 1.0.0
 * Author: Emetrio
 * Author URI: https://www.emetrio.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: emetrio-scriptor
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Fixed schedule timezone — independent of WP core's Settings → General timezone.
// Change this one line if your real timezone ever changes.
if ( ! defined( 'EMETRIO_SCRIPTOR_SCHEDULE_TIMEZONE' ) ) {
    define( 'EMETRIO_SCRIPTOR_SCHEDULE_TIMEZONE', 'Asia/Kolkata' );
}

/* -------------------------
   Init and CPT Registration
   ------------------------- */
add_action( 'init', 'emetrio_scriptor_register_log_cpt' );
function emetrio_scriptor_register_log_cpt() {
    register_post_type( 'emetrio_scriptor_log', array(
        'labels' => array(
            'name'          => __( 'Emetrio Scriptor Logs', 'emetrio-scriptor' ),
            'singular_name' => __( 'Emetrio Scriptor Log', 'emetrio-scriptor' ),
        ),
        'public'             => false,
        'show_ui'            => false, // Shown in custom admin dashboard logs tab
        'publicly_queryable' => false,
        'show_in_menu'       => false,
        'query_var'          => false,
        'rewrite'            => false,
        'capability_type'    => 'post',
        'supports'           => array( 'title', 'editor' ),
    ) );
}

/* -------------------------
   Logging helper
   ------------------------- */
function emetrio_scriptor_add_log( $status, $message, $payload = array(), $response = array() ) {
    $tz    = emetrio_scriptor_get_schedule_timezone();
    $title = sprintf( 'API Request - %s', ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' ) );
    $log_id = wp_insert_post( array(
        'post_title'   => $title,
        'post_content' => sanitize_textarea_field( $message ),
        'post_status'  => 'private',
        'post_type'    => 'emetrio_scriptor_log',
        'post_author'  => emetrio_scriptor_get_default_author_id(),
    ) );

    if ( ! is_wp_error( $log_id ) && $log_id > 0 ) {
        update_post_meta( $log_id, '_emetrio_scriptor_log_status', sanitize_text_field( $status ) );
        update_post_meta( $log_id, '_emetrio_scriptor_log_ip', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ) );
        update_post_meta( $log_id, '_emetrio_scriptor_log_payload', wp_json_encode( $payload ) );
        update_post_meta( $log_id, '_emetrio_scriptor_log_response', wp_json_encode( $response ) );
    }
}

add_action( 'wp_ajax_emetrio_scriptor_load_more_logs', 'emetrio_scriptor_ajax_load_more_logs' );
function emetrio_scriptor_ajax_load_more_logs() {
    check_ajax_referer( 'emetrio_scriptor_logs_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Insufficient permissions.', 'emetrio-scriptor' ), 403 );
    }

    $per_page = 15;
    $paged    = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
    $offset   = isset( $_POST['row_count'] ) ? max( 0, absint( $_POST['row_count'] ) ) : ( ( $paged - 1 ) * $per_page );

    $logs_query = new WP_Query( array(
        'post_type'      => 'emetrio_scriptor_log',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'post_status'    => array( 'private', 'publish' ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    ob_start();
    $row_number = $offset;
    if ( $logs_query->have_posts() ) {
        while ( $logs_query->have_posts() ) {
            $logs_query->the_post();
            $row_number++;
            emetrio_scriptor_render_log_row( get_the_ID(), $row_number );
        }
        wp_reset_postdata();
    }
    $html = ob_get_clean();

    wp_send_json_success( array(
        'html'      => $html,
        'has_more'  => $paged < (int) $logs_query->max_num_pages,
        'next_page' => $paged + 1,
        'row_count' => $row_number,
    ) );
}

add_action( 'admin_post_emetrio_scriptor_clear_logs', 'emetrio_scriptor_handle_clear_logs' );
function emetrio_scriptor_handle_clear_logs() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'emetrio-scriptor' ) );
    }
    check_admin_referer( 'emetrio_scriptor_clear_logs' );

    $log_ids = get_posts( array(
        'post_type'      => 'emetrio_scriptor_log',
        'post_status'    => 'private',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    foreach ( $log_ids as $log_id ) {
        wp_delete_post( $log_id, true );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=emetrio-scriptor&tab=logs&emetrio_scriptor_cleared=1' ) );
    exit;
}

function emetrio_scriptor_get_provider_label( $provider_id ) {
    if ( empty( $provider_id ) || 'default' === $provider_id ) {
        return '';
    }
    if ( ! function_exists( 'WordPress\AI\get_ai_connectors' ) ) {
        return '';
    }
    $connectors = WordPress\AI\get_ai_connectors( false );
    if ( empty( $connectors[ $provider_id ] ) || ! is_array( $connectors[ $provider_id ] ) ) {
        return '';
    }
    $cdata = $connectors[ $provider_id ];
    return ! empty( $cdata['name'] ) ? sanitize_text_field( $cdata['name'] ) : ucfirst( $provider_id );
}

function emetrio_scriptor_get_schedule_timezone() {
    try {
        return new DateTimeZone( EMETRIO_SCRIPTOR_SCHEDULE_TIMEZONE );
    } catch ( Exception $e ) {
        return new DateTimeZone( 'UTC' );
    }
}

function emetrio_scriptor_touch_cron_last_action( DateTime $now ) {
    update_option( 'emetrio_scriptor_cron_last_action_time', $now->format( 'Y-m-d H:i:s' ) );
}

/* -------------------------
   REST API Routing
   ------------------------- */
add_action( 'rest_api_init', 'emetrio_scriptor_register_routes' );
function emetrio_scriptor_register_routes() {
    register_rest_route(
        'blog-api/v1',
        '/publish',
        array(
            'methods'             => 'POST',
            'callback'            => 'emetrio_scriptor_handle_publish',
            'permission_callback' => 'emetrio_scriptor_permission_callback',
            'args'                => array(
                'title'              => array( 'required' => true,  'type' => 'string' ),
                'content'            => array( 'required' => true,  'type' => 'string' ),
                'category'           => array( 'required' => false, 'type' => 'string' ),
                'categories'         => array( 'required' => false, 'type' => 'array' ),
                'excerpt'            => array( 'required' => false, 'type' => 'string' ),
                'featured_image_url' => array( 'required' => false, 'type' => 'string' ),
                'custom_css'         => array( 'required' => false, 'type' => 'string' ),
                'tags'               => array( 'required' => false, 'type' => 'array' ),
                'date'               => array( 'required' => false, 'type' => 'string' ),
            ),
        )
    );
}

/* -------------------------
   Permission (uses WP core auth)
   - Recommend: create a dedicated user with 'Author' role and an Application Password.
   ------------------------- */
function emetrio_scriptor_permission_callback( $request ) {
    $required_cap = get_option( 'emetrio_scriptor_required_capability', 'publish_posts' );
    if ( ! current_user_can( $required_cap ) ) {
        return new WP_Error( 'rest_forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
    }
    return true;
}

/* -------------------------
   Simple transient-based rate limiter
   - uses REST_API_RATE_LIMIT (defined in wp-config.php) or defaults to 10
   - 60s sliding window (customize with REST_API_RATE_WINDOW)
   ------------------------- */
function emetrio_scriptor_rate_limit_check( $identifier ) {
    $limit  = get_option( 'emetrio_scriptor_rate_limit', 10 );
    $window = get_option( 'emetrio_scriptor_rate_window', 60 );

    $key  = 'emetrio_scriptor_rl_' . md5( $identifier );
    $data = get_transient( $key );
    $now  = time();

    if ( empty( $data ) || ! is_array( $data ) ) {
        $data = array( 'count' => 1, 'start' => $now );
        set_transient( $key, $data, $window );
        return true;
    }

    if ( ( $now - $data['start'] ) >= $window ) {
        $data = array( 'count' => 1, 'start' => $now );
        set_transient( $key, $data, $window );
        return true;
    }

    if ( $data['count'] >= $limit ) {
        return false;
    }

    $data['count']++;
    $expires = $window - ( $now - $data['start'] );
    set_transient( $key, $data, max( 1, $expires ) );
    return true;
}

/* -------------------------
   Sanitizers & Logging Helpers
   ------------------------- */
function emetrio_scriptor_sanitize_style_attr( $style ) {
    $style = trim( wp_strip_all_tags( $style ) );
    // remove weird control chars
    $style = preg_replace( '/[^\x20-\x7E]/', '', $style );
    $low   = strtolower( $style );

    // disallow CSS expressions and javascript: tokens
    if ( strpos( $low, 'expression(' ) !== false ) {
        return '';
    }
    $style = preg_replace( '#javascript\s*:#i', '', $style );

    // remove url(...) that contains javascript:
    $style = preg_replace_callback(
        '#url\(([^)]*)\)#i',
        function ( $m ) {
            $u = trim( $m[1], " \t\n\r\0\x0B'\"" );
            if ( stripos( $u, 'javascript:' ) !== false ) {
                return 'url()';
            }
            return 'url(' . $u . ')';
        },
        $style
    );

    return $style;
}

// Safe content preview logger (limits output size and collapses whitespace)
function emetrio_scriptor_log_content_preview( $label, $content ) {
    if ( ! is_string( $content ) ) {
        return;
    }
    $len = strlen( $content );
    $preview = preg_replace( '/\s+/', ' ', substr( $content, 0, 1000 ) );
}

function emetrio_scriptor_sanitize_content_with_style( $html ) {
    emetrio_scriptor_log_content_preview( 'Sanitize: original content', $html );
    $allowed = wp_kses_allowed_html( 'post' );

    // allow style attribute on all allowed tags
    foreach ( $allowed as $tag => $attrs ) {
        $allowed[ $tag ]['style'] = true;
    }
    $clean = wp_kses( $html, $allowed );

    // sanitize inline style attributes using a regex callback (simple & practical)
    $pattern = '/\sstyle\s*=\s*(["\'])(.*?)\\1/s';
    $clean   = preg_replace_callback(
        $pattern,
        function ( $m ) {
            $quote = $m[1];
            $style = $m[2];
            $safe  = emetrio_scriptor_sanitize_style_attr( $style );
            return ' style=' . $quote . $safe . $quote;
        },
        $clean
    );

    emetrio_scriptor_log_content_preview( 'Sanitize: cleaned content', $clean );
    return $clean;
}

function emetrio_scriptor_resolve_category_ids( $cats ) {
    if ( empty( $cats ) ) {
        return array();
    }

    if ( ! is_array( $cats ) ) {
        if ( is_string( $cats ) ) {
            $cats = ( false !== strpos( $cats, ',' ) ) ? array_map( 'trim', explode( ',', $cats ) ) : array( $cats );
        } else {
            return array();
        }
    }

    $ids = array();
    foreach ( $cats as $c ) {
        if ( is_numeric( $c ) ) {
            $term = get_term( intval( $c ), 'category' );
            if ( $term && ! is_wp_error( $term ) ) {
                $ids[] = intval( $c );
                continue;
            }
        }

        $c_s  = sanitize_text_field( $c );
        $term = get_term_by( 'slug', sanitize_title( $c_s ), 'category' );
        if ( ! $term ) {
            $term = get_term_by( 'name', $c_s, 'category' );
        }

        if ( $term ) {
            $ids[] = intval( $term->term_id );
            continue;
        }

        $new = wp_insert_term( $c_s, 'category' );
        if ( ! is_wp_error( $new ) && isset( $new['term_id'] ) ) {
            $ids[] = intval( $new['term_id'] );
        }
    }

    return array_values( array_unique( array_filter( $ids ) ) );
}

function emetrio_scriptor_resize_featured_image( $attachment_id, $max_w = 1200, $max_h = 629 ) {
    if ( get_post_meta( $attachment_id, '_emetrio_scriptor_resized', true ) ) {
        return; // already processed
    }

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        return;
    }

    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) {
        return;
    }

    $editor->resize( $max_w, $max_h, false ); // false = proportional fit, no crop, no distortion
    $saved = $editor->save( $file );
    if ( is_wp_error( $saved ) ) {
        return;
    }

    wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
    update_post_meta( $attachment_id, '_emetrio_scriptor_resized', 1 );
}

/* -------------------------
   Featured image sideload (uses core helpers)
   ------------------------- */
function emetrio_scriptor_attach_featured_image( $post_id, $image_url ) {
    if ( empty( $image_url ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Already in the Media Library (e.g. picked via the Upload button) — reuse it, no re-download.
    $existing_id = attachment_url_to_postid( $image_url );
    if ( $existing_id ) {
        emetrio_scriptor_resize_featured_image( $existing_id );
        set_post_thumbnail( $post_id, $existing_id );
        return;
    }

    // Primary attempt: use media_sideload_image (returns attachment ID when asked)
    $maybe_id = media_sideload_image( $image_url, $post_id, '', 'id' );
    if ( ! is_wp_error( $maybe_id ) && $maybe_id ) {
        emetrio_scriptor_resize_featured_image( (int) $maybe_id );
        set_post_thumbnail( $post_id, (int) $maybe_id );
        return;
    }

    // Fallback: download file and sideload (handle URLs without extensions)
    $tmp = download_url( $image_url );
    if ( is_wp_error( $tmp ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }
        return;
    }

    $filename = basename( wp_parse_url( $image_url, PHP_URL_PATH ) );
    if ( ! $filename || strpos( $filename, '.' ) === false ) {
        $info = @getimagesize( $tmp );
        if ( $info && ! empty( $info[2] ) ) {
            $ext = image_type_to_extension( $info[2], false );
            $filename = 'image-' . time() . ( $ext ? '.' . $ext : '.jpg' );
        } else {
            $filename = 'image-' . time() . '.jpg';
        }
    }

    $file = array(
        'name'     => $filename,
        'tmp_name' => $tmp,
    );

    $id = media_handle_sideload( $file, $post_id );
    if ( is_wp_error( $id ) ) {
        wp_delete_file( $tmp );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // 
        }
        return;
    }

    emetrio_scriptor_resize_featured_image( $id );
    set_post_thumbnail( $post_id, $id );
}

/* -------------------------
   REST Core Handle Publish
   ------------------------- */
function emetrio_scriptor_handle_publish( $request, $scheduled = false ) {
    // Get json params from request body (REST request object supports get_json_params)
    $params = is_array( $request ) ? $request : $request->get_json_params();
    if ( empty( $params ) && ! is_array( $request ) ) {
        // Fallback for direct mock calls in testing
        $params = $request->get_params();
    }

    $identifier = get_current_user_id() ? 'user_' . get_current_user_id() : 'ip_' . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
    if ( ! emetrio_scriptor_rate_limit_check( $identifier ) ) {
        $error_response = array( 'status' => 'error', 'message' => 'Rate limit exceeded' );
        if ( ! $scheduled ) {
            emetrio_scriptor_add_log( 'error', 'Rate limit exceeded for client.', $params, $error_response );
        }
        return new WP_Error( 'rate_limited', 'Rate limit exceeded', array( 'status' => 429 ) );
    }

    $title    = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
    $content  = isset( $params['content'] ) ? $params['content'] : '';
    $category = isset( $params['category'] ) ? $params['category'] : '';
    $excerpt  = isset( $params['excerpt'] ) ? sanitize_text_field( $params['excerpt'] ) : '';
    $feat     = isset( $params['featured_image_url'] ) ? esc_url_raw( $params['featured_image_url'] ) : '';
    $css      = isset( $params['custom_css'] ) ? $params['custom_css'] : ( isset( $params['custom_styles'] ) ? $params['custom_styles'] : '' );
    
    // Default post status from database option
    $default_status = get_option( 'emetrio_scriptor_default_status', 'draft' );
    // 'publish' param is honored only for the admin Quick Tester (direct array call, not scheduled).
    // REST API requests and scheduled generation always use the Default Post Status setting.
    $is_quick_tester = is_array( $request ) && ! $scheduled;
    $publish  = ( $is_quick_tester && isset( $params['publish'] ) ) ? ( filter_var( $params['publish'], FILTER_VALIDATE_BOOLEAN ) ? 'publish' : 'draft' ) : $default_status;

    if ( empty( $title ) || empty( $content ) ) {
        $error_response = array( 'status' => 'error', 'message' => 'Missing title or content' );
        if ( ! $scheduled ) {
            emetrio_scriptor_add_log( 'error', 'Missing required fields: title or content.', $params, $error_response );
        }
        return new WP_Error( 'missing_data', 'Missing required fields', array( 'status' => 400 ) );
    }

    $content = emetrio_scriptor_sanitize_content_with_style( $content );

    $post_data = array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_excerpt' => $excerpt,
        'post_status'  => $publish,
        'post_author'  => emetrio_scriptor_get_default_author_id(),
    );

    if ( ! empty( $params['date'] ) ) {
        $post_data['post_date'] = sanitize_text_field( $params['date'] );
    }

    // duplicate title check
    $title_trim = trim( $title );

    $existing_posts = get_posts( array(
        'post_type'              => 'post',
        'title'                  => $title_trim,
        'post_status'            => 'any',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );
    $existing_id = ! empty( $existing_posts ) ? $existing_posts[0] : null;

    $duplicate_behavior = get_option( 'emetrio_scriptor_duplicate_behavior', 'block' );

    if ( $existing_id && 'block' === $duplicate_behavior ) {
        $existing = get_post( $existing_id );
        $data = array(
            'status'               => 'error',
            'message'              => 'Post with the same title already exists.',
            'existing_post_id'     => (int) $existing->ID,
            'existing_post_status' => $existing->post_status,
            'existing_post_url'    => ( 'publish' === $existing->post_status ) ? get_permalink( $existing->ID ) : null,
            'edit_url'             => get_edit_post_link( $existing->ID ),
        );
        if ( ! $scheduled ) {
            emetrio_scriptor_add_log( 'error', 'Duplicate post title blocked.', $params, $data );
        }
        $res = rest_ensure_response( $data );
        $res->set_status( 409 );
        return $res;
    }

    $post_id = wp_insert_post( $post_data, true );
    if ( is_wp_error( $post_id ) ) {
        $data = array( 'status' => 'error', 'message' => $post_id->get_error_message() );
        if ( ! $scheduled ) {
            emetrio_scriptor_add_log( 'error', 'WP Post creation failed: ' . $post_id->get_error_message(), $params, $data );
        }
        return new WP_Error( 'post_creation_failed', 'Failed to create post', array( 'status' => 500 ) );
    }

    // handle one-or-many categories
    $cat_input = $params['categories'] ?? ( isset( $params['category'] ) ? $params['category'] : '' );
    $cat_ids = emetrio_scriptor_resolve_category_ids( $cat_input );
    if ( ! empty( $cat_ids ) ) {
        wp_set_post_categories( $post_id, $cat_ids );
    }

    // featured image
    if ( ! empty( $feat ) ) {
        emetrio_scriptor_attach_featured_image( $post_id, $feat );
    }

    // custom css (store as post meta)
    if ( ! empty( $css ) ) {
        $css = str_replace( '</style>', '', $css );
        $css = trim( $css );
        $css = substr( $css, 0, 10000 );
        update_post_meta( $post_id, '_emetrio_scriptor_custom_css', $css );
    }

    $tags_input = $params['tags'] ?? ( isset( $params['tag'] ) ? $params['tag'] : '' );
    if ( ! empty( $tags_input ) ) {
        if ( ! is_array( $tags_input ) ) {
            $tags_input = is_string( $tags_input ) ? ( false !== strpos( $tags_input, ',' ) ? array_map( 'trim', explode( ',', $tags_input ) ) : array( $tags_input ) ) : array();
        }
        $tags_input = array_map( 'sanitize_text_field', $tags_input );
        wp_set_post_terms( $post_id, $tags_input, 'post_tag' );
    }

    $data = array(
        'status'      => 'success',
        'message'     => $publish === 'publish' ? 'Post published' : 'Post created as draft',
        'post_id'     => $post_id,
        'post_status' => get_post_status( $post_id ),
        'post_url'    => ( 'publish' === get_post_status( $post_id ) ) ? get_permalink( $post_id ) : null,
        'edit_url'    => get_edit_post_link( $post_id ),
    );

    if ( ! $scheduled ) {
        emetrio_scriptor_add_log( 'success', 'Post created successfully via API.', $params, $data );
    }

    $res = rest_ensure_response( $data );
    $res->set_status( 201 );
    if ( ! empty( $data['post_url'] ) ) {
        $res->header( 'Location', $data['post_url'] );
    }
    return $res;
}

// Returns current user if logged in (e.g. REST/Quick Tester), else the first administrator account
function emetrio_scriptor_get_default_author_id() {
    $current = get_current_user_id();
    if ( $current ) {
        return $current;
    }
    $admins = get_users( array(
        'role'    => 'administrator',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 1,
        'fields'  => 'ID',
    ) );
    return ! empty( $admins ) ? (int) $admins[0] : 1;
}

/* -------------------------
   Print custom CSS on single pages
   ------------------------- */
add_action( 'wp_enqueue_scripts', 'emetrio_scriptor_print_custom_css' );
function emetrio_scriptor_print_custom_css() {
    if ( is_admin() || ! is_singular() ) {
        return;
    }
    $post_id = get_queried_object_id();
    if ( ! $post_id ) {
        return;
    }
    $css = get_post_meta( $post_id, '_emetrio_scriptor_custom_css', true );
    if ( empty( $css ) ) {
        return;
    }
    wp_register_style( 'emetrio-scriptor-custom-css', false, array(), '1.0' );
    wp_enqueue_style( 'emetrio-scriptor-custom-css' );
    wp_add_inline_style( 'emetrio-scriptor-custom-css', wp_strip_all_tags( $css ) );
}

/* -------------------------
   Admin Dashboard & Settings
   ------------------------- */
add_action( 'admin_menu', 'emetrio_scriptor_add_admin_menu' );
function emetrio_scriptor_add_admin_menu() {
    add_menu_page(
        'Emetrio Scriptor',
        'Emetrio Scriptor',
        'manage_options',
        'emetrio-scriptor',
        'emetrio_scriptor_render_admin_page',
        'dashicons-airplane',
        80
    );
}

add_action( 'admin_init', 'emetrio_scriptor_register_settings' );
function emetrio_scriptor_register_settings() {
    register_setting( 'emetrio_scriptor_settings_group', 'emetrio_scriptor_rate_limit', array( 'type' => 'integer', 'sanitize_callback' => 'emetrio_scriptor_sanitize_positive_int', 'default' => 10 ) );
    register_setting( 'emetrio_scriptor_settings_group', 'emetrio_scriptor_rate_window', array( 'type' => 'integer', 'sanitize_callback' => 'emetrio_scriptor_sanitize_positive_int', 'default' => 60 ) );
    register_setting( 'emetrio_scriptor_settings_group', 'emetrio_scriptor_default_status', array( 'type' => 'string', 'sanitize_callback' => emetrio_scriptor_choice_sanitizer( array( 'draft', 'pending' ), 'draft' ), 'default' => 'draft' ) );
    register_setting( 'emetrio_scriptor_settings_group', 'emetrio_scriptor_duplicate_behavior', array( 'type' => 'string', 'sanitize_callback' => emetrio_scriptor_choice_sanitizer( array( 'block', 'allow' ), 'block' ), 'default' => 'block' ) );
    register_setting( 'emetrio_scriptor_settings_group', 'emetrio_scriptor_required_capability', array( 'type' => 'string', 'sanitize_callback' => emetrio_scriptor_choice_sanitizer( array( 'publish_posts', 'edit_posts', 'manage_options' ), 'publish_posts' ), 'default' => 'publish_posts' ) );

    // Scheduler settings
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_enabled', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_frequency', array( 'type' => 'string', 'sanitize_callback' => emetrio_scriptor_choice_sanitizer( array( 'daily', 'weekdays', 'monthly', 'once' ), 'daily' ), 'default' => 'daily' ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_once_date', array( 'sanitize_callback' => 'emetrio_scriptor_sanitize_once_date', 'default' => '' ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_provider', array( 'sanitize_callback' => 'sanitize_key', 'default' => 'default' ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_weekdays', array( 'sanitize_callback' => 'emetrio_scriptor_sanitize_weekdays', 'default' => array() ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_dayofmonth', array( 'type' => 'integer', 'sanitize_callback' => function ( $v ) { return min( 31, max( 1, absint( $v ) ) ); }, 'default' => 1 ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_hour', array( 'type' => 'integer', 'sanitize_callback' => function ( $v ) { return min( 23, absint( $v ) ); }, 'default' => 0 ) );
    register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_schedule_time', array( 'sanitize_callback' => 'emetrio_scriptor_sanitize_schedule_time', 'default' => '00:00' ) );
    // register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_ollama_host', array( 'sanitize_callback' => 'esc_url_raw', 'default' => 'http://localhost:11434' ) );
    // register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_ollama_model', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'llama3' ) );
    // register_setting( 'emetrio_scriptor_scheduler_group', 'emetrio_scriptor_ollama_api_key', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );

    // Brand DNA settings
    register_setting( 'emetrio_scriptor_brand_dna_group', 'emetrio_scriptor_brand_dna_language', array( 'type' => 'string', 'sanitize_callback' => function ( $v ) { $v = sanitize_key( $v ); return preg_match( '/^[a-z]{2,3}$/', $v ) ? $v : 'en'; }, 'default' => 'en' ) );
    register_setting( 'emetrio_scriptor_brand_dna_group', 'emetrio_scriptor_brand_dna_business_type', array( 'sanitize_callback' => 'wp_filter_nohtml_kses', 'default' => '' ) );
    register_setting( 'emetrio_scriptor_brand_dna_group', 'emetrio_scriptor_brand_dna_voice_tone', array( 'sanitize_callback' => 'wp_filter_nohtml_kses', 'default' => '' ) );
    register_setting( 'emetrio_scriptor_brand_dna_group', 'emetrio_scriptor_brand_dna_target_audience', array( 'sanitize_callback' => 'wp_filter_nohtml_kses', 'default' => '' ) );
    register_setting( 'emetrio_scriptor_brand_dna_group', 'emetrio_scriptor_brand_dna_service_area', array( 'sanitize_callback' => 'wp_filter_nohtml_kses', 'default' => '' ) );
    register_setting( 'emetrio_scriptor_brand_dna_group', 'emetrio_scriptor_brand_dna_topics_to_avoid', array( 'sanitize_callback' => 'wp_filter_nohtml_kses', 'default' => '' ) );
}

function emetrio_scriptor_choice_sanitizer( array $allowed, $default ) {
    return function ( $value ) use ( $allowed, $default ) {
        $value = sanitize_key( $value );
        return in_array( $value, $allowed, true ) ? $value : $default;
    };
}

function emetrio_scriptor_sanitize_positive_int( $value ) {
    return max( 1, absint( $value ) );
}

function emetrio_scriptor_sanitize_weekdays( $input ) {
    update_option( 'emetrio_scriptor_settings_last_updated', current_time( 'timestamp' ) );
    if ( ! is_array( $input ) ) {
        return array();
    }
    return array_values( array_unique( array_intersect( array_map( 'absint', $input ), range( 0, 6 ) ) ) );
}

function emetrio_scriptor_sanitize_schedule_time( $time_str ) {
    $time_str = sanitize_text_field( $time_str );
    if ( ! empty( $time_str ) ) {
        $parts = explode( ':', $time_str );
        $hour  = (int) $parts[0];
        update_option( 'emetrio_scriptor_schedule_hour', $hour );
    }
    update_option( 'emetrio_scriptor_settings_last_updated', current_time( 'timestamp' ) );
    
    // --- NEW: Clear last_used_time when settings change so new time can be used for generation ---
    update_option( 'emetrio_scriptor_last_used_time', '' );
    update_option( 'emetrio_scriptor_last_used_day_of_week', false );
    
    return $time_str;
}

add_action( 'admin_enqueue_scripts', 'emetrio_scriptor_enqueue_admin_assets' );
function emetrio_scriptor_enqueue_admin_assets( $hook ) {
    $js_url = plugin_dir_url( __FILE__ ) . 'admin/js/admin-script.js';

    if ( 'toplevel_page_emetrio-scriptor' === $hook ) {
        wp_enqueue_style( 'emetrio-scriptor-admin-style', plugin_dir_url( __FILE__ ) . 'admin/css/admin-style.css', array(), '1.0' );
        wp_enqueue_media();
        wp_enqueue_script( 'emetrio-scriptor-admin', $js_url, array( 'jquery' ), '1.0', true );
        wp_localize_script( 'emetrio-scriptor-admin', 'emetrioScriptorData', array(
            'context' => 'admin_page',
            'aiNonce' => wp_create_nonce( 'emetrio_scriptor_ai_nonce' ),
            'nextRun' => emetrio_scriptor_get_next_scheduled_time(),
            'i18n'    => array(
                'generating'     => __( 'Generating with AI', 'emetrio-scriptor' ),
                'aiFailed'       => __( 'AI generation failed — check debug.log', 'emetrio-scriptor' ),
                'serverError'    => __( 'Error communicating with the server — check debug.log', 'emetrio-scriptor' ),
                'targetDate'     => __( 'Target local date/time: ', 'emetrio-scriptor' ),
                'scheduled'      => __( 'Scheduled:', 'emetrio-scriptor' ),
                'checkingRun'    => __( 'Checking/Running scheduled execution...', 'emetrio-scriptor' ),
                'scheduledPassed'=> __( 'Scheduled time has passed. To run again, update the scheduler settings.', 'emetrio-scriptor' ),
                'loading'        => __( 'Loading...', 'emetrio-scriptor' ),
                'logsError'      => __( 'Error loading more logs — check debug.log', 'emetrio-scriptor' ),
                'selectImage'    => __( 'Select Featured Image', 'emetrio-scriptor' ),
                'useImage'       => __( 'Use this image', 'emetrio-scriptor' ),
            ),
        ) );
    }

    $screen = get_current_screen();
    if ( 'edit.php' === $hook && $screen && 'post' === $screen->post_type ) {
        wp_enqueue_style( 'emetrio-scriptor-admin-style', plugin_dir_url( __FILE__ ) . 'admin/css/admin-style.css', array(), '1.0' );
        wp_enqueue_script( 'emetrio-scriptor-admin', $js_url, array( 'jquery' ), '1.0', true );
        wp_localize_script( 'emetrio-scriptor-admin', 'emetrioScriptorData', array(
            'context'  => 'posts_list',
            'adminUrl' => admin_url( 'admin.php?page=emetrio-scriptor' ),
            'i18n'     => array(
                'tryScriptor' => __( 'Try Emetrio Scriptor', 'emetrio-scriptor' ),
            ),
        ) );
    }
}

// Converts an internally-stored Y-m-d H:i:s / Y-m-d string to dd-mm-yyyy for display
function emetrio_scriptor_display_date( $date_str ) {
    if ( empty( $date_str ) ) {
        return '';
    }
    $has_time = false !== strpos( $date_str, ':' );
    $dt = DateTime::createFromFormat( $has_time ? 'Y-m-d H:i:s' : 'Y-m-d', $date_str );
    if ( ! $dt ) {
        return $date_str;
    }
    return $dt->format( $has_time ? 'd-m-Y H:i:s' : 'd-m-Y' );
}

function emetrio_scriptor_format_log_timestamp( $log_id ) {
    $gmt_str = get_post_field( 'post_date_gmt', $log_id );
    if ( empty( $gmt_str ) || '0000-00-00 00:00:00' === $gmt_str ) {
        return '';
    }
    $dt = new DateTime( $gmt_str, new DateTimeZone( 'GMT' ) );
    $dt->setTimezone( emetrio_scriptor_get_schedule_timezone() );
    return $dt->format( 'd-m-Y H:i:s' );
}

function emetrio_scriptor_render_log_row( $log_id, $row_number ) {
    $status   = get_post_meta( $log_id, '_emetrio_scriptor_log_status', true );
    $ip       = get_post_meta( $log_id, '_emetrio_scriptor_log_ip', true );
    $content  = get_post_field( 'post_content', $log_id );
    ?>
    <tr>
        <td><?php echo esc_html( $row_number ); ?></td>
        <td><?php echo esc_html( emetrio_scriptor_format_log_timestamp( $log_id ) ); ?></td>
        <td><code><?php echo esc_html( $ip ); ?></code></td>
        <td><?php echo wp_kses_post( wpautop( esc_html( $content ) ) ); ?></td>
        <td>
            <span class="emetrio-scriptor-status <?php echo esc_attr( $status ); ?>">
                <?php echo esc_html( strtoupper( $status ) ); ?>
            </span>
        </td>
    </tr>
    <?php
}

/* -------------------------
   Render Admin Settings Page
   ------------------------- */
function emetrio_scriptor_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'emetrio-scriptor' ) );
    }

    // Handle Quick Local Tester Form
    $test_response = '';
    if ( isset( $_POST['emetrio_scriptor_test_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['emetrio_scriptor_test_nonce'] ), 'emetrio_scriptor_test_action' ) ) {
        $test_payload = array(
            'title'              => sanitize_text_field( wp_unslash( $_POST['test_title'] ?? '' ) ),
            'content'            => wp_kses_post( wp_unslash( $_POST['test_content'] ?? '' ) ),
            'category'           => sanitize_text_field( wp_unslash( $_POST['test_category'] ?? '' ) ),
            'excerpt'            => sanitize_text_field( wp_unslash( $_POST['test_excerpt'] ?? '' ) ),
            'featured_image_url' => esc_url_raw( wp_unslash( $_POST['test_featured_image'] ?? '' ) ),
            'publish'            => filter_var( wp_unslash( $_POST['test_publish'] ?? false ), FILTER_VALIDATE_BOOLEAN ),
            'tags'               => isset( $_POST['test_tags'] ) ? array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $_POST['test_tags'] ) ) ) ) : array(),
        );

        $response = emetrio_scriptor_handle_publish( $test_payload );
        if ( is_wp_error( $response ) ) {
            $test_response = wp_json_encode( array(
                'code'    => $response->get_error_code(),
                'message' => $response->get_error_message(),
                'data'    => $response->get_error_data(),
            ), JSON_PRETTY_PRINT );
        } else {
            if ( method_exists( $response, 'get_data' ) ) {
                $test_response = wp_json_encode( $response->get_data(), JSON_PRETTY_PRINT );
            } else {
                $test_response = wp_json_encode( $response, JSON_PRETTY_PRINT );
            }
        }
    }

    $api_url = get_rest_url( null, 'blog-api/v1/publish' );
    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
    ?>
    <div class="wrap emetrio-scriptor-wrap">
        <h2 style="display:none;"></h2>
        <div class="emetrio-scriptor-header">
            <div class="emetrio-scriptor-logo">
                <h1>Emetrio Scriptor</h1>
            </div>
            <div>
                <a href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>" class="emetrio-scriptor-btn-secondary" style="text-decoration: none;"><?php esc_html_e( 'Back to Posts', 'emetrio-scriptor' ); ?></a>
            </div>
        </div>

        <h2 class="nav-tab-wrapper" style="display: none;"></h2> <!-- Keep WP spacing clean -->

        <div class="emetrio-scriptor-tabs-nav">
            <button class="emetrio-scriptor-tab-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" onclick="emetrioScriptorSwitchTab(event, 'settings')"><?php esc_html_e( 'Configuration', 'emetrio-scriptor' ); ?></button>
            <button class="emetrio-scriptor-tab-link <?php echo $active_tab === 'brand-dna' ? 'active' : ''; ?>" onclick="emetrioScriptorSwitchTab(event, 'brand-dna')"><?php esc_html_e( 'Brand DNA', 'emetrio-scriptor' ); ?></button>
            <button class="emetrio-scriptor-tab-link <?php echo $active_tab === 'scheduler' ? 'active' : ''; ?>" onclick="emetrioScriptorSwitchTab(event, 'scheduler')"><?php esc_html_e( 'Scheduler', 'emetrio-scriptor' ); ?></button>
            <button class="emetrio-scriptor-tab-link <?php echo $active_tab === 'instructions' ? 'active' : ''; ?>" onclick="emetrioScriptorSwitchTab(event, 'instructions')"><?php esc_html_e( 'API Reference', 'emetrio-scriptor' ); ?></button>
            <button class="emetrio-scriptor-tab-link <?php echo $active_tab === 'tester' ? 'active' : ''; ?>" onclick="emetrioScriptorSwitchTab(event, 'tester')"><?php esc_html_e( 'Quick Tester', 'emetrio-scriptor' ); ?></button>
            <button class="emetrio-scriptor-tab-link <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" onclick="emetrioScriptorSwitchTab(event, 'logs')"><?php esc_html_e( 'API Logs', 'emetrio-scriptor' ); ?></button>
        </div>

        <!-- Settings Configuration -->
        <div id="settings" class="emetrio-scriptor-tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
            <form method="post" action="options.php" class="emetrio-scriptor-card">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( add_query_arg( 'tab', 'settings', admin_url( 'admin.php?page=emetrio-scriptor' ) ) ); ?>" />
                <h2><?php esc_html_e( 'Plugin Settings', 'emetrio-scriptor' ); ?></h2>
                <?php
                settings_fields( 'emetrio_scriptor_settings_group' );
                do_settings_sections( 'emetrio-scriptor' );
                ?>
                <div class="emetrio-scriptor-form-grid">
                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Rate Limit (Requests)', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <input type="number" name="emetrio_scriptor_rate_limit" value="<?php echo esc_attr( get_option( 'emetrio_scriptor_rate_limit', 10 ) ); ?>" min="1" />
                            <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Max requests allowed within the window timeframe.', 'emetrio-scriptor' ); ?></p>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Rate Limit Window (Seconds)', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <input type="number" name="emetrio_scriptor_rate_window" value="<?php echo esc_attr( get_option( 'emetrio_scriptor_rate_window', 60 ) ); ?>" min="1" />
                            <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Time frame sliding window in seconds.', 'emetrio-scriptor' ); ?></p>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Default Post Status', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <select name="emetrio_scriptor_default_status">
                                <option value="draft" <?php selected( get_option( 'emetrio_scriptor_default_status', 'draft' ), 'draft' ); ?>><?php esc_html_e( 'Draft', 'emetrio-scriptor' ); ?></option>
                                <option value="pending" <?php selected( get_option( 'emetrio_scriptor_default_status', 'draft' ), 'pending' ); ?>><?php esc_html_e( 'Pending Review', 'emetrio-scriptor' ); ?></option>
                            </select>
                            <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Status used for posts created via the REST API and scheduled publishing.', 'emetrio-scriptor' ); ?></p>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Duplicate Titles Behavior', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <select name="emetrio_scriptor_duplicate_behavior">
                                <option value="block" <?php selected( get_option( 'emetrio_scriptor_duplicate_behavior', 'block' ), 'block' ); ?>><?php esc_html_e( 'Block post & return 409 error', 'emetrio-scriptor' ); ?></option>
                                <option value="allow" <?php selected( get_option( 'emetrio_scriptor_duplicate_behavior', 'block' ), 'allow' ); ?>><?php esc_html_e( 'Allow duplicates (creates new with unique slug)', 'emetrio-scriptor' ); ?></option>
                            </select>
                            <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Choose what happens when an incoming post matches an existing post title.', 'emetrio-scriptor' ); ?></p>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Required Capability', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <select name="emetrio_scriptor_required_capability">
                                <option value="publish_posts" <?php selected( get_option( 'emetrio_scriptor_required_capability', 'publish_posts' ), 'publish_posts' ); ?>><?php esc_html_e( 'Publish Posts (Author/Editor/Admin)', 'emetrio-scriptor' ); ?></option>
                                <option value="edit_posts" <?php selected( get_option( 'emetrio_scriptor_required_capability', 'publish_posts' ), 'edit_posts' ); ?>><?php esc_html_e( 'Edit Posts (Contributor and up)', 'emetrio-scriptor' ); ?></option>
                                <option value="manage_options" <?php selected( get_option( 'emetrio_scriptor_required_capability', 'publish_posts' ), 'manage_options' ); ?>><?php esc_html_e( 'Manage Options (Admin Only)', 'emetrio-scriptor' ); ?></option>
                            </select>
                            <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Select user capability constraints for publishing API posts.', 'emetrio-scriptor' ); ?></p>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 30px;">
                    <?php submit_button( __( 'Save Configuration', 'emetrio-scriptor' ), 'emetrio-scriptor-btn-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
        
        <!-- Brand DNA -->
        <div id="brand-dna" class="emetrio-scriptor-tab-content <?php echo $active_tab === 'brand-dna' ? 'active' : ''; ?>">
            <div class="emetrio-scriptor-card">
                <h2><?php esc_html_e( 'Brand DNA', 'emetrio-scriptor' ); ?></h2>
                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Define your brand identity so AI-generated blog content stays on-brand every time.', 'emetrio-scriptor' ); ?></p>

                <form method="post" action="options.php">
                    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( add_query_arg( 'tab', 'brand-dna', admin_url( 'admin.php?page=emetrio-scriptor' ) ) ); ?>" />
                    <?php settings_fields( 'emetrio_scriptor_brand_dna_group' ); ?>
                    <div class="emetrio-scriptor-form-grid">
                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Content Language', 'emetrio-scriptor' ); ?><span style="color:red;"> * </span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <?php
                                $languages = array(
                                    'ar' => 'Arabic',
                                    'bn' => 'Bengali',
                                    'en' => 'English',
                                    'fr' => 'French',
                                    'gu' => 'Gujarati',
                                    'hi' => 'Hindi',
                                    'id' => 'Indonesian',
                                    'kn' => 'Kannada',
                                    'zh' => 'Mandarin Chinese',
                                    'mr' => 'Marathi',
                                    'or' => 'Odia',
                                    'pt' => 'Portuguese',
                                    'es' => 'Spanish',
                                    'ta' => 'Tamil',
                                    'te' => 'Telugu',
                                    'ur' => 'Urdu',
                                );
                                $cur_lang = get_option( 'emetrio_scriptor_brand_dna_language', 'en' );
                                ?>
                                <select name="emetrio_scriptor_brand_dna_language" id="emetrio_scriptor_brand_dna_language">
                                    <?php foreach ( $languages as $code => $label ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $cur_lang, $code ); ?>>
                                            <?php echo esc_html( $code . ' — ' . $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Business Type', 'emetrio-scriptor' ); ?><span style="color:red;"> * </span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <textarea name="emetrio_scriptor_brand_dna_business_type" style="width:100%;" rows="6" placeholder="eg: B2B SaaS providing cloud-based CRM solutions for small and medium businesses" required><?php echo esc_textarea( get_option( 'emetrio_scriptor_brand_dna_business_type', '' ) ); ?></textarea>
                                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Describe your business, industry, and what you offer.', 'emetrio-scriptor' ); ?></p>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Brand Voice & Tone', 'emetrio-scriptor' ); ?><span style="color:red;"> * </span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <textarea name="emetrio_scriptor_brand_dna_voice_tone" style="width:100%;" rows="6" placeholder="eg: Professional yet approachable, authoritative but not condescending, use data-driven insights" required><?php echo esc_textarea( get_option( 'emetrio_scriptor_brand_dna_voice_tone', '' ) ); ?></textarea>
                                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'How should the AI sound? (e.g., formal, casual, witty, inspirational)', 'emetrio-scriptor' ); ?></p>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Target Audience', 'emetrio-scriptor' ); ?><span style="color:red;"> * </span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <textarea name="emetrio_scriptor_brand_dna_target_audience" style="width:100%;" rows="6" placeholder="eg: Marketing managers at mid-size tech companies, decision-makers who need CRM tools" required><?php echo esc_textarea( get_option( 'emetrio_scriptor_brand_dna_target_audience', '' ) ); ?></textarea>
                                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Who are you writing for? Be specific.', 'emetrio-scriptor' ); ?></p>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Service Area', 'emetrio-scriptor' ); ?> <span style="color:#999;">— optional</span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <textarea name="emetrio_scriptor_brand_dna_service_area" style="width:100%;" rows="6" placeholder="eg: North America, Europe, Asia-Pacific (or Global / Worldwide)"><?php echo esc_textarea( get_option( 'emetrio_scriptor_brand_dna_service_area', '' ) ); ?></textarea>
                                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Geographic regions you serve.', 'emetrio-scriptor' ); ?></p>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Topics to Avoid', 'emetrio-scriptor' ); ?> <span style="color:#999;">— optional</span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <textarea name="emetrio_scriptor_brand_dna_topics_to_avoid" style="width:100%;" rows="6" placeholder="eg: Competitor names, political topics, controversial social issues"><?php echo esc_textarea( get_option( 'emetrio_scriptor_brand_dna_topics_to_avoid', '' ) ); ?></textarea>
                                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Comma-separated list of topics the AI should not cover.', 'emetrio-scriptor' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 30px;">
                        <?php submit_button( __( 'Save Brand DNA', 'emetrio-scriptor' ), 'emetrio-scriptor-btn-primary', 'submit', false ); ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Scheduler Settings -->
        <div id="scheduler" class="emetrio-scriptor-tab-content <?php echo $active_tab === 'scheduler' ? 'active' : ''; ?>">
            <form method="post" action="options.php" class="emetrio-scriptor-card">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( add_query_arg( 'tab', 'scheduler', admin_url( 'admin.php?page=emetrio-scriptor' ) ) ); ?>" />
                <h2><?php esc_html_e( 'Auto-Generation Scheduler', 'emetrio-scriptor' ); ?></h2>
                <?php
                settings_fields( 'emetrio_scriptor_scheduler_group' );
                ?>
                <div class="emetrio-scriptor-form-grid">
                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Enable Auto Generation', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <label style="display:inline-block; cursor:pointer;">
                                <input type="checkbox" name="emetrio_scriptor_schedule_enabled" value="1" <?php checked( get_option( 'emetrio_scriptor_schedule_enabled', 0 ), 1 ); ?> />
                                <span style="font-size:13px;color:#57606a;"><?php esc_html_e( 'Enable background AI blog generation', 'emetrio-scriptor' ); ?></span>
                            </label>
                        </div>
                    </div>
                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'AI Provider', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <select name="emetrio_scriptor_schedule_provider">
                                <option value="default" <?php selected( get_option( 'emetrio_scriptor_schedule_provider', 'default' ), 'default' ); ?>><?php esc_html_e( 'Default preferred AI Provider', 'emetrio-scriptor' ); ?></option>
                                <?php
                                if ( function_exists( 'WordPress\AI\get_ai_connectors' ) ) {
                                    $connectors = WordPress\AI\get_ai_connectors( false );
                                    foreach ( $connectors as $cid => $cdata ) {
                                        if ( empty( $cdata ) || ! is_array( $cdata ) ) {
                                            continue;
                                        }
                                        // consider connector connected if any common flag is truthy
                                        $is_connected = ! empty( $cdata['connected'] ) || ! empty( $cdata['active'] ) || ! empty( $cdata['enabled'] );
                                        if ( ! $is_connected && function_exists( 'WordPress\AI\has_connector_authentication' ) ) {
                                            $is_connected = WordPress\AI\has_connector_authentication( $cid );
                                        }
                                        if ( ! $is_connected && function_exists( 'WordPress\AI\is_connector_configured' ) ) {
                                            $is_connected = WordPress\AI\is_connector_configured( $cid );
                                        }
                                        if ( ! $is_connected ) {
                                            continue;
                                        }
                                        $label = emetrio_scriptor_get_provider_label( $cid );
                                        if ( '' === $label ) {
                                            $label = ucfirst( $cid );
                                        }
                                        printf(
                                            '<option value="%s" %s>%s</option>',
                                            esc_attr( $cid ),
                                            selected( get_option( 'emetrio_scriptor_schedule_provider', 'default' ), $cid, false ),
                                            esc_html( $label )
                                        );
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <?php $schedule_frequency = get_option( 'emetrio_scriptor_schedule_frequency', 'daily' ); ?>
                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Schedule Frequency', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <select name="emetrio_scriptor_schedule_frequency" id="emetrio_scriptor_schedule_frequency">
                                <option value="daily" <?php selected( $schedule_frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'emetrio-scriptor' ); ?></option>
                                <option value="weekdays" <?php selected( $schedule_frequency, 'weekdays' ); ?>><?php esc_html_e( 'Specific Weekdays', 'emetrio-scriptor' ); ?></option>
                                <option value="monthly" <?php selected( $schedule_frequency, 'monthly' ); ?>><?php esc_html_e( 'Day of the Month', 'emetrio-scriptor' ); ?></option>
                                <option value="once" <?php selected( $schedule_frequency, 'once' ); ?>><?php esc_html_e( 'Once (specific date)', 'emetrio-scriptor' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row" id="emetrio-scriptor-weekdays-row" style="<?php echo ( $schedule_frequency === 'weekdays' ? '' : 'display:none;' ); ?>">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Specific Weekdays', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <?php
                            $saved_days = get_option( 'emetrio_scriptor_schedule_weekdays', array() );
                            if ( ! is_array( $saved_days ) ) {
                                $saved_days = array();
                            }
                            $days = array(
                                1 => __( 'Monday', 'emetrio-scriptor' ),
                                2 => __( 'Tuesday', 'emetrio-scriptor' ),
                                3 => __( 'Wednesday', 'emetrio-scriptor' ),
                                4 => __( 'Thursday', 'emetrio-scriptor' ),
                                5 => __( 'Friday', 'emetrio-scriptor' ),
                                6 => __( 'Saturday', 'emetrio-scriptor' ),
                                0 => __( 'Sunday', 'emetrio-scriptor' ),
                            );
                            foreach ( $days as $val => $label ) {
                                $checked = in_array( $val, array_map( 'absint', (array) $saved_days ) ) ? 'checked' : '';
                                ?>
                                <label style="display:block; margin-bottom:6px; cursor:pointer;">
                                    <input type="checkbox" name="emetrio_scriptor_schedule_weekdays[]" value="<?php echo esc_attr( $val ); ?>" <?php echo esc_attr( $checked ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                                <?php
                            }
                            ?>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row" id="emetrio-scriptor-monthly-row" style="<?php echo ( $schedule_frequency === 'monthly' ? '' : 'display:none;' ); ?>">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Day of the Month', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <select name="emetrio_scriptor_schedule_dayofmonth">
                                <?php
                                $saved_dom = (int) get_option( 'emetrio_scriptor_schedule_dayofmonth', 1 );
                                for ( $d = 1; $d <= 31; $d++ ) {
                                    printf(
                                        '<option value="%d" %s>%d</option>',
                                        (int) $d,
                                        selected( $saved_dom, $d, false ),
                                        (int) $d
                                    );
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row" id="emetrio-scriptor-once-row" style="<?php echo ( $schedule_frequency === 'once' ? '' : 'display:none;' ); ?>">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Run Once On', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <input type="date" name="emetrio_scriptor_schedule_once_date" value="<?php echo esc_attr( get_option( 'emetrio_scriptor_schedule_once_date', '' ) ); ?>" />
                            <p class="emetrio-scriptor-desc"><?php esc_html_e( 'If "Once" selected, generation will run on this date (server time).', 'emetrio-scriptor' ); ?></p>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Trigger Hour', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <input type="time" name="emetrio_scriptor_schedule_time" value="<?php echo esc_attr( get_option( 'emetrio_scriptor_schedule_time', '00:00' ) ); ?>" />
                            <p class="emetrio-scriptor-desc">
                                <?php
                                /* translators: %s: configured schedule timezone name */
                                printf( esc_html__( 'Select the time of the day when the generation should occur. (Timezone: %s)', 'emetrio-scriptor' ), esc_html( EMETRIO_SCRIPTOR_SCHEDULE_TIMEZONE ) );
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="emetrio-scriptor-row" id="emetrio-scriptor-next-run-row" style="<?php echo ( get_option( 'emetrio_scriptor_schedule_enabled', 0 ) ? '' : 'display:none;' ); ?>">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Next Scheduled Run', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <div id="emetrio-scriptor-countdown-timer" style="font-weight: 600; color: #0969da; font-size: 15px;"></div>
                            <p class="emetrio-scriptor-desc" id="emetrio-scriptor-next-run-date-desc"></p>
                        </div>
                    </div>
                    <div class="emetrio-scriptor-row">
                        <div class="emetrio-scriptor-label"><?php esc_html_e( 'Last Action', 'emetrio-scriptor' ); ?></div>
                        <div class="emetrio-scriptor-input-wrap">
                            <p class="emetrio-scriptor-desc">
                                <?php
                                $last_action_time = get_option( 'emetrio_scriptor_cron_last_action_time', '' );
                                $last_result       = get_option( 'emetrio_scriptor_cron_last_result', '' );

                                if ( $last_action_time && $last_result ) {
                                    printf(
                                        /* translators: %1$s: last scheduled run date/time, %2$s: outcome message */
                                        esc_html__( '%1$s — %2$s', 'emetrio-scriptor' ),
                                        esc_html( emetrio_scriptor_display_date( $last_action_time ) ),
                                        esc_html( $last_result )
                                    );
                                } else {
                                    esc_html_e( 'No scheduled action has run yet.', 'emetrio-scriptor' );
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 30px;">
                    <?php submit_button( __( 'Save Scheduler', 'emetrio-scriptor' ), 'emetrio-scriptor-btn-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>

        <!-- API Instructions Reference -->
        <div id="instructions" class="emetrio-scriptor-tab-content <?php echo $active_tab === 'instructions' ? 'active' : ''; ?>">
            <div class="emetrio-scriptor-card">
                <h2><?php esc_html_e( 'API Reference', 'emetrio-scriptor' ); ?></h2>
                
                <p><strong><?php esc_html_e( 'REST Endpoint URL:', 'emetrio-scriptor' ); ?></strong></p>
                <div class="emetrio-scriptor-code-box">
                    <span class="emetrio-scriptor-badge post">POST</span> <?php echo esc_html( $api_url ); ?>
                    <button class="emetrio-scriptor-copy-btn" onclick="emetrioScriptorCopyToClipboard('<?php echo esc_js( $api_url ); ?>', this)"><?php esc_html_e( 'Copy', 'emetrio-scriptor' ); ?></button>
                </div>

                <p style="margin-top: 25px;"><strong><?php esc_html_e( 'Example JSON Request Payload:', 'emetrio-scriptor' ); ?></strong></p>
                <?php
                $sample_json = '{
    "title": "My Auto Generated Blog Post",
    "content": "<p>This is the HTML content of the blog post. Custom styles are allowed.</p>",
    "excerpt": "Short excerpt for the post search previews.",
    "categories": ["Technology", "Automations"],
    "tags": ["AI", "API", "WordPress"],
    "featured_image_url": "https://example.com/images/blog-banner.jpg",
    "custom_css": ".my-post-class { color: #3f51b5; }",
    "date": "2026-08-20 10:00:00"
}';
                ?>
                <div class="emetrio-scriptor-code-box" style="white-space: pre;"><?php echo esc_html( $sample_json ); ?><button class="emetrio-scriptor-copy-btn" onclick="emetrioScriptorCopyToClipboard(<?php echo esc_attr( wp_json_encode( $sample_json ) ); ?>, this)"><?php esc_html_e( 'Copy', 'emetrio-scriptor' ); ?></button></div>

                <p><strong><?php esc_html_e( 'How to Setup Authentication (Application Passwords):', 'emetrio-scriptor' ); ?></strong></p>
                <ol style="margin-left: 20px; list-style-type: decimal; color: #57606a; font-size: 13.5px; line-height: 1.6; margin-bottom: 20px;">
                    <li>
                        <?php
                        /* translators: %1$s: opening link tag, %2$s: closing link tag */
                        echo sprintf( esc_html__( 'Go to %1$sUsers -> Profile%2$s in your WordPress Dashboard.', 'emetrio-scriptor' ), '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '" target="_blank">', '</a>' );
                        ?>
                    </li>
                    <li><?php esc_html_e( 'Scroll down to the "Application Passwords" section.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Enter an App Name (e.g. "Emetrio Scriptor Client") and click "Add New Application Password".', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Copy the generated 24-character password (it will not be shown again).', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'In your external agent (like OpenClaw, Python script, or Postman), use standard HTTP Basic Authentication with:', 'emetrio-scriptor' ); ?>
                        <ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px;">
                            <li><strong><?php esc_html_e( 'Username:', 'emetrio-scriptor' ); ?></strong> <?php esc_html_e( 'Your WordPress login username', 'emetrio-scriptor' ); ?></li>
                            <li><strong><?php esc_html_e( 'Password:', 'emetrio-scriptor' ); ?></strong> <?php esc_html_e( 'The 24-character application password (including spaces)', 'emetrio-scriptor' ); ?></li>
                        </ul>
                    </li>
                </ol>

                <p><strong><?php esc_html_e( 'How to Setup & Authorize AI Connectors:', 'emetrio-scriptor' ); ?></strong></p>
                <ol style="margin-left: 20px; list-style-type: decimal; color: #57606a; font-size: 13.5px; line-height: 1.6; margin-bottom: 25px;">
                    <li><?php esc_html_e( 'Ensure an AI provider or AI connector plugin is active on your WordPress site (using the standard WordPress AI / AI Client framework).', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Navigate to Settings → Connector Approvals in your WordPress Dashboard and accept/allow access for Emetrio Scriptor.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Under Emetrio Scriptor → Scheduler, select your preferred AI provider or leave it as "Default preferred AI Provider".', 'emetrio-scriptor' ); ?></li>
                </ol>

                <p><strong><?php esc_html_e( 'Test in Postman:', 'emetrio-scriptor' ); ?></strong></p>
                <ol style="margin-left:20px; list-style-type:decimal; color:#57606a; font-size:13.5px; line-height:1.6; margin-bottom:20px;">
                    <li><?php esc_html_e( 'Create a new POST request in Postman.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Enter the REST Endpoint URL shown above.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Go to Authorization → Basic Auth.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Username: your WordPress username.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Password: your WordPress Application Password.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Go to Body → raw → JSON and paste the example JSON payload above.', 'emetrio-scriptor' ); ?></li>
                    <li><?php esc_html_e( 'Click Send.', 'emetrio-scriptor' ); ?></li>
                </ol>

                <p><strong><?php esc_html_e( 'Important Production / Live Server Tips:', 'emetrio-scriptor' ); ?></strong></p>
                <ul style="margin-left: 20px; list-style-type: disc; color: #57606a; font-size: 13.5px; line-height: 1.6; margin-bottom: 25px;">
                    <li><strong><?php esc_html_e( 'HTTPS Requirement:', 'emetrio-scriptor' ); ?></strong> <?php esc_html_e( 'WordPress Application Passwords are only active by default when your site is served over secure HTTPS connections.', 'emetrio-scriptor' ); ?></li>
                    <li><strong><?php esc_html_e( 'Authorization Headers Stripped:', 'emetrio-scriptor' ); ?></strong> <?php esc_html_e( 'Many shared hosts (like Apache or Nginx) filter out the HTTP Authorization header by default. If you receive "Insufficient permissions" (403) or "unauthorized" errors on live servers, add the following to your site\'s .htaccess file:', 'emetrio-scriptor' ); ?>
                        <div class="emetrio-scriptor-code-box" style="margin-top: 5px; margin-bottom: 5px;">CGIPassAuth On</div>
                        <?php esc_html_e( 'Alternatively, for older Apache versions, try:', 'emetrio-scriptor' ); ?>
                        <div class="emetrio-scriptor-code-box" style="margin-top: 5px; margin-bottom: 5px;">RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</div>
                    </li>
                </ul>

            </div>
        </div>

        <!-- Quick Local Tester -->
        <div id="tester" class="emetrio-scriptor-tab-content <?php echo $active_tab === 'tester' ? 'active' : ''; ?>">
            <div class="emetrio-scriptor-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="margin: 0;"><?php esc_html_e( 'Local API Tester', 'emetrio-scriptor' ); ?></h2>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" id="emetrio-scriptor-ai-gen-btn" class="button button-secondary" style="margin:0; display:inline-flex; align-items:center; gap:8px;">
                            <!-- inline sparkles SVG -->
                            <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="flex:0 0 20px;">
                                <path fill="currentColor" d="M12 2l1.8 3.6L18 7l-3.6 1.4L12 12 10.6 8.4 7 7l4.2-1.4L12 2zM4 13l.6 1.2L6 16l-1.4.6L4 18l-.6-1.4L2 16l1.4-.6L4 13zM20 16l.6 1.2L22 18l-1.4.6L20 20l-.6-1.4L18 18l1.4-.6L20 16z"/>
                            </svg>
                            <span class="emetrio-scriptor-ai-label"><?php esc_html_e( 'Generate with AI', 'emetrio-scriptor' ); ?></span>
                            <span id="emetrio-scriptor-ai-inline-spinner" class="emetrio-scriptor-inline-spinner" style="display:none;"></span>
                        </button>
                        <span id="emetrio-scriptor-ai-gen-msg" style="color:#d63638;font-size:13px;"></span>
                    </div>
                </div>
                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Simulate sending a payload to your Emetrio Scriptor publish endpoint local to check validation, media handling, and categories.', 'emetrio-scriptor' ); ?></p>

                <form method="post" action="" style="margin-top: 20px;">
                    <?php wp_nonce_field( 'emetrio_scriptor_test_action', 'emetrio_scriptor_test_nonce' ); ?>
                    <div class="emetrio-scriptor-form-grid">
                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Title', 'emetrio-scriptor' ); ?><span style="color:red;"> * </span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <input type="text" name="test_title" placeholder="Title" required />
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Content', 'emetrio-scriptor' ); ?><span style="color:red;"> * </span></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <textarea name="test_content" placeholder="Content" required></textarea>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Category (comma-separated)', 'emetrio-scriptor' ); ?></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <input type="text" name="test_category" placeholder="eg: Tech News, Information Technology" />
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Excerpt', 'emetrio-scriptor' ); ?></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <input type="text" name="test_excerpt" placeholder="Excerpt" />
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Featured Image', 'emetrio-scriptor' ); ?></div>
                            <div class="emetrio-scriptor-input-wrap emetrio-scriptor-feature-image-input" style="display:flex; gap:8px;">
                                <input type="text" id="emetrio-scriptor-test-featured-image" name="test_featured_image" placeholder="https://example.com/image.jpg" style="flex:1;" />
                                <button type="button" id="emetrio-scriptor-media-upload-btn" class="emetrio-scriptor-btn-secondary" style="white-space:nowrap; display:inline-flex; align-items:center; gap:6px;" title="Upload Image">
                                    <span class="dashicons dashicons-upload"></span>
                                </button>
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Tags (comma-separated)', 'emetrio-scriptor' ); ?></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <input type="text" name="test_tags" placeholder="eg: AI, Technology, General" />
                            </div>
                        </div>

                        <div class="emetrio-scriptor-row">
                            <div class="emetrio-scriptor-label"><?php esc_html_e( 'Publish Status', 'emetrio-scriptor' ); ?></div>
                            <div class="emetrio-scriptor-input-wrap">
                                <label style="display:inline-block; cursor:pointer;">
                                    <input type="checkbox" name="test_publish" value="1" />
                                    <span style="font-size:13px;color:#57606a;"><?php esc_html_e( 'Publish instantly (uncheck to save as draft)', 'emetrio-scriptor' ); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 30px;">
                        <input type="submit" id="emetrio-scriptor-run-local-test-btn" class="emetrio-scriptor-btn-primary" value="<?php esc_attr_e( 'Run Local Tester', 'emetrio-scriptor' ); ?>" />
                    </div>
                </form>

                <div id="emetrio-scriptor-test-result" style="<?php echo empty( $test_response ) ? 'display:none;' : ''; ?>">
                    <!-- <h3 style="margin-top: 30px;"><?php esc_html_e( 'Simulation API Response:', 'emetrio-scriptor' ); ?></h3> -->
                    <pre id="emetrio-scriptor-test-result-box" class="emetrio-scriptor-code-box" style="white-space: pre-wrap;border-left: 4px solid darkgrey;"><?php echo esc_html( $test_response ); ?></pre>
                </div>
            </div>
        </div>

        <!-- API Request Logs -->
        <div id="logs" class="emetrio-scriptor-tab-content <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
            <div class="emetrio-scriptor-card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <h2 style="margin:0;"><?php esc_html_e( 'Emetrio Scriptor API Logs', 'emetrio-scriptor' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all API logs? This cannot be undone.', 'emetrio-scriptor' ) ); ?>');">
                        <?php wp_nonce_field( 'emetrio_scriptor_clear_logs' ); ?>
                        <input type="hidden" name="action" value="emetrio_scriptor_clear_logs" />
                        <button type="submit" class="emetrio-scriptor-btn-secondary"><?php esc_html_e( 'Clear Logs', 'emetrio-scriptor' ); ?></button>
                    </form>
                </div>
                <p class="emetrio-scriptor-desc"><?php esc_html_e( 'Recent API requests and publication outcomes logged via Emetrio Scriptor.', 'emetrio-scriptor' ); ?></p>
                
                <?php
                $logs_query = new WP_Query( array(
                    'post_type'      => 'emetrio_scriptor_log',
                    'posts_per_page' => 15,
                    'post_status'    => array( 'private', 'publish' ),
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );

                if ( $logs_query->have_posts() ) :
                    ?>
                    <div class="emetrio-scriptor-table-wrapper">
                        <table class="emetrio-scriptor-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( '#', 'emetrio-scriptor' ); ?></th>
                                    <th><?php esc_html_e( 'Timestamp', 'emetrio-scriptor' ); ?></th>
                                    <th><?php esc_html_e( 'IP Address', 'emetrio-scriptor' ); ?></th>
                                    <th><?php esc_html_e( 'Outcome / Message', 'emetrio-scriptor' ); ?></th>
                                    <th><?php esc_html_e( 'Status Code', 'emetrio-scriptor' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="emetrio-scriptor-logs-tbody">
                                <?php
                                $key = 1;
                                while ( $logs_query->have_posts() ) :
                                    $logs_query->the_post();
                                    emetrio_scriptor_render_log_row( get_the_ID(), $key );
                                    $key++;
                                endwhile;
                                wp_reset_postdata();
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ( $logs_query->max_num_pages > 1 ) : ?>
                        <div style="margin-top:16px; text-align:center;">
                            <button
                                type="button"
                                id="emetrio-scriptor-load-more-logs"
                                class="emetrio-scriptor-btn-secondary"
                                data-next-page="2"
                                data-row-count="<?php echo esc_attr( $key - 1 ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'emetrio_scriptor_logs_nonce' ) ); ?>"
                            ><?php esc_html_e( 'Load More', 'emetrio-scriptor' ); ?></button>
                            <p id="emetrio-scriptor-load-more-msg" style="color:#d63638;font-size:13px;margin-top:8px;"></p>
                        </div>
                    <?php endif; ?>
                    <?php
                else :
                    echo '<p style="margin-top: 20px;">' . esc_html__( 'No logged requests found yet.', 'emetrio-scriptor' ) . '</p>';
                endif;
                ?>
            </div>
        </div>
    </div>
    <?php
}

/* -------------------------
   AI Generation & Scheduler Helpers
   ------------------------- */

add_action( 'wp_ajax_emetrio_scriptor_generate_ai_preview', 'emetrio_scriptor_ajax_generate_ai_preview' );
function emetrio_scriptor_ajax_generate_ai_preview() {
    // Verify nonce without dying
    if ( empty( $_REQUEST['_ajax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) ), 'emetrio_scriptor_ai_nonce' ) ) {
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    try {
        $niche = '';
        $post_data = emetrio_scriptor_generate_ai_content( $niche );

        if ( is_wp_error( $post_data ) ) {
            wp_send_json_error( $post_data->get_error_message() );
        }

        wp_send_json_success( $post_data );
    } catch ( Throwable $e ) {
        wp_send_json_error( 'Server error' );
    }
}

function emetrio_scriptor_generate_ai_content( $niche = '' ) {
    $provider = get_option( 'emetrio_scriptor_schedule_provider', 'default' );

    $prompt = "Generate a new blog post of approximately 600-800 words. Output MUST be in raw JSON format only with these keys: 'title', 'content', 'excerpt', 'categories', 'tags'. Keep the JSON complete and well-formed — do not truncate. Do not include markdown code block formatting (like ```json).";

    // Inject Brand DNA into the prompt
    $language    = get_option( 'emetrio_scriptor_brand_dna_language', 'en' );
    $business    = get_option( 'emetrio_scriptor_brand_dna_business_type', '' );
    $voice_tone  = get_option( 'emetrio_scriptor_brand_dna_voice_tone', '' );
    $audience    = get_option( 'emetrio_scriptor_brand_dna_target_audience', '' );
    $service     = get_option( 'emetrio_scriptor_brand_dna_service_area', '' );
    $avoid       = get_option( 'emetrio_scriptor_brand_dna_topics_to_avoid', '' );

    if ( ! empty( $business ) )    $prompt .= "\n\nBrand Context: Business is: " . $business;
    if ( ! empty( $voice_tone ) )  $prompt .= "\nBrand Voice & Tone: " . $voice_tone;
    if ( ! empty( $audience ) )    $prompt .= "\nTarget Audience: " . $audience;
    if ( ! empty( $service ) )     $prompt .= "\nService Area: " . $service;
    if ( ! empty( $avoid ) )       $prompt .= "\nTopics to Avoid: " . $avoid;

    $lang_name = array(
        'ar' => 'Arabic',
        'bn' => 'Bengali',
        'en' => 'English',
        'fr' => 'French',
        'gu' => 'Gujarati',
        'hi' => 'Hindi',
        'id' => 'Indonesian',
        'kn' => 'Kannada',
        'zh' => 'Mandarin Chinese',
        'mr' => 'Marathi',
        'or' => 'Odia',
        'pt' => 'Portuguese',
        'es' => 'Spanish',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'ur' => 'Urdu',
    );
    $lang_label = isset( $lang_name[ $language ] ) ? $lang_name[ $language ] : 'English';
    $prompt .= "\nContent Language: " . $lang_label;

    if ( ! empty( $niche ) ) {
        $prompt .= "\nNiche/Keywords provided in scheduler: " . $niche . ".";
    } else {
        $prompt .= "\nIf no niche provided, generate a post on a random interesting technology or lifestyle topic.";
    }

    if ( ! function_exists( '\\WordPress\\AI\\get_ai_service' ) ) {
        return new WP_Error( 'ai_missing', 'WordPress AI feature is not enabled or AI plugin is missing.' );
    }

    $service = WordPress\AI\get_ai_service();
    try {
        $builder = $service->create_textgen_prompt( $prompt, array(
            'temperature' => 0.8,
            'max_tokens'  => 4096
        ) );

        if ( 'default' !== $provider && class_exists( 'WordPress\AiClient\AiClient' ) ) {
            try {
                $ai_registry = WordPress\AiClient\AiClient::defaultRegistry();
                if ( method_exists( $ai_registry, 'hasProvider' ) && $ai_registry->hasProvider( $provider ) ) {
                    $models = array();

                    // Preferred safe API if available
                    if ( method_exists( $ai_registry, 'getProviderModels' ) ) {
                        $models = $ai_registry->getProviderModels( $provider );
                    } elseif ( method_exists( $ai_registry, 'getProviderClassName' ) ) {
                        $provider_class = $ai_registry->getProviderClassName( $provider );
                        if ( $provider_class && method_exists( $provider_class, 'modelMetadataDirectory' ) ) {
                            $directory = $provider_class::modelMetadataDirectory();
                            if ( is_object( $directory ) && method_exists( $directory, 'getModelMetadataList' ) ) {
                                $models = $directory->getModelMetadataList();
                            }
                        }
                    }

                    if ( ! empty( $models ) && is_array( $models ) ) {
                        $preferences = array();
                        foreach ( $models as $model ) {
                            if ( method_exists( $model, 'hasSupportedCapability' )
                                && $model->hasSupportedCapability( WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() )
                                && method_exists( $model, 'getId' ) ) {
                                $preferences[] = array( $provider, $model->getId() );
                            }
                        }
                        if ( ! empty( $preferences ) && method_exists( $builder, 'usingModelPreference' ) ) {
                            $builder = $builder->usingModelPreference( ...$preferences );
                        }
                    }
                }
            } catch ( Throwable $e ) {
            }
        }

        if ( ! $builder->is_supported_for_text_generation() ) {
            return new WP_Error( 'ai_unsupported', 'No active AI connector supports text generation.' );
        }
        $result = $builder->generate_text();

        if ( is_wp_error( $result ) ) {

            return new WP_Error(
                'ai_generation_failed',
                $result->get_error_message()
            );
        }

        if ( empty( $result ) ) {
            return new WP_Error(
                'ai_empty',
                'AI returned empty result.'
            );
        }

    } catch ( Throwable $e ) {
        return new WP_Error( 'ai_exception', $e->getMessage() );
    }

    $result_clean = trim( $result );
    if ( strpos( $result_clean, '```json' ) === 0 ) {
        $result_clean = substr( $result_clean, 7 );
    } elseif ( strpos( $result_clean, '```' ) === 0 ) {
        $result_clean = substr( $result_clean, 3 );
    }
    $result_clean = rtrim( $result_clean, '`' );
    $result_clean = trim( $result_clean );

    $data = json_decode( $result_clean, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
        $looks_truncated = ( substr( rtrim( $result_clean ), -1 ) !== '}' );
        $reason = $looks_truncated ? 'Response appears truncated (increase max_tokens or shorten requested content length).' : 'Response was not valid JSON.';
        return new WP_Error( 'ai_parse_error', 'Failed to parse AI output as JSON — ' . $reason );
    }

    return array(
        'title'      => $data['title'] ?? 'AI Post',
        'content'    => $data['content'] ?? '',
        'excerpt'    => $data['excerpt'] ?? '',
        'categories' => (array) ( $data['categories'] ?? array() ),
        'tags'       => (array) ( $data['tags'] ?? array() ),
    );
}

function emetrio_scriptor_get_next_scheduled_time() {
    $enabled = get_option( 'emetrio_scriptor_schedule_enabled', 0 );
    if ( ! $enabled ) {
        return false;
    }

    $frequency = get_option( 'emetrio_scriptor_schedule_frequency', 'daily' );
    $time_str  = get_option( 'emetrio_scriptor_schedule_time', '00:00' );
    if ( empty( $time_str ) ) {
        $time_str = '00:00';
    }

    $tz   = emetrio_scriptor_get_schedule_timezone();
    $now  = new DateTime( 'now', $tz );
    list( $h, $m ) = array_map( 'absint', explode( ':', $time_str ) );

    if ( 'once' === $frequency ) {
        $once_date = get_option( 'emetrio_scriptor_schedule_once_date', '' );
        if ( empty( $once_date ) ) return false;
        $candidate = new DateTime( $once_date . ' ' . $time_str, $tz );
        if ( $candidate <= $now ) {
            return false;
        }
        return array(
            'timestamp' => $candidate->getTimestamp(),
            'timezone'  => $tz->getName(),
            'label'     => $candidate->format( 'c' ),
        );
    }

    // For daily/weekdays: use today's date at configured time
    if ( 'daily' === $frequency || 'weekdays' === $frequency ) {
        $today = clone $now;
        $today->setDate( (int) $today->format('Y'), (int) $today->format('m'), (int) $today->format('d') );
        $today->setTime( $h, $m, 0 );

        if ( 'weekdays' === $frequency ) {
            $weekdays = get_option( 'emetrio_scriptor_schedule_weekdays', array() );
            if ( empty( $weekdays ) ) return false;
            for ( $i = 0; $i < 8; $i++ ) {
                $check = clone $today;
                if ( $i > 0 ) {
                    $check->modify( '+' . $i . ' days' );
                }
                if ( in_array( (int) $check->format('w'), array_map( 'absint', $weekdays ) ) && $check > $now ) {
                    $candidate = $check;
                    break;
                }
            }
        } else {
            $candidate = $today;
        }
        // If today's configured time has passed, show tomorrow's countdown for daily
        if ( 'daily' === $frequency && $candidate <= $now ) {
            $candidate->modify( '+1 day' );
        }
    } elseif ( 'monthly' === $frequency ) {
        $dom = (int) get_option( 'emetrio_scriptor_schedule_dayofmonth', 1 );

        // Build candidate with selected DOM in current month
        $current_month_candidate = new DateTime(
            sprintf('%04d-%02d-%02d %02d:%02d:00',
                (int) $now->format('Y'),
                (int) $now->format('m'),
                $dom,
                $h, $m
            ),
            $tz
        );

        // If current month's selected date/time is in the future, use it
        if ( $current_month_candidate > $now ) {
            $candidate = $current_month_candidate;
        } else {
            // Use PHP's auto-date-normalization: day 32+ rolls over to next month safely
            $candidate = new DateTime(
                sprintf('%04d-%02d-%02d %02d:%02d:00',
                    (int) $now->format('Y'),
                    (int) $now->format('m') + 1,
                    $dom,
                    $h, $m
                ),
                $tz
            );
        }
    }

    if ( $candidate <= $now ) {
        return false;
    }

    return array(
        'timestamp' => $candidate->getTimestamp(),
        'timezone'  => $tz->getName(),
        'label'     => $candidate->format( 'c' ),
    );
}

// NEW — add these two lines together, right before emetrio_scriptor_schedule_cron's registration
add_filter( 'cron_schedules', 'emetrio_scriptor_add_cron_interval' );
function emetrio_scriptor_add_cron_interval( $schedules ) {
    $schedules['emetrio_scriptor_five_min'] = array(
        'interval' => 300,
        'display'  => __( 'Every 5 Minutes (Emetrio Scriptor)', 'emetrio-scriptor' ),
    );
    return $schedules;
}

add_action( 'init', 'emetrio_scriptor_schedule_cron' );
function emetrio_scriptor_schedule_cron() {
    // Always clear legacy hooks
    wp_clear_scheduled_hook( 'emetrio_scriptor_daily_cron_hook' );
    wp_clear_scheduled_hook( 'emetrio_scriptor_once_cron_hook' );

    $enabled = get_option( 'emetrio_scriptor_schedule_enabled', 0 );
    if ( $enabled && ! wp_next_scheduled( 'emetrio_scriptor_hourly_cron_hook' ) ) {
        wp_schedule_event( time(), 'emetrio_scriptor_five_min', 'emetrio_scriptor_hourly_cron_hook' );
    } elseif ( ! $enabled ) {
        wp_clear_scheduled_hook( 'emetrio_scriptor_hourly_cron_hook' );
    }
}

// Clear scheduled cron immediately when scheduler configuration changes to apply new schedule
add_action( 'update_option_emetrio_scriptor_schedule_enabled', 'emetrio_scriptor_clear_cron_on_change' );
add_action( 'update_option_emetrio_scriptor_schedule_frequency', 'emetrio_scriptor_clear_cron_on_change' );
add_action( 'update_option_emetrio_scriptor_schedule_time', 'emetrio_scriptor_clear_cron_on_change' );
add_action( 'update_option_emetrio_scriptor_schedule_weekdays', 'emetrio_scriptor_clear_cron_on_change' );
add_action( 'update_option_emetrio_scriptor_schedule_dayofmonth', 'emetrio_scriptor_clear_cron_on_change' );
add_action( 'update_option_emetrio_scriptor_schedule_once_date', 'emetrio_scriptor_clear_cron_on_change' );
function emetrio_scriptor_clear_cron_on_change() {
    wp_clear_scheduled_hook( 'emetrio_scriptor_hourly_cron_hook' );
}

add_action( 'emetrio_scriptor_hourly_cron_hook', 'emetrio_scriptor_run_scheduled_generation' );
add_action( 'emetrio_scriptor_once_cron_hook', 'emetrio_scriptor_run_scheduled_generation' );

/**
 * Optional safety net: only used if real WP-Cron is confirmed disabled on this host
 * (DISABLE_WP_CRON true) AND no external system cron has been set up to hit
 * wp-cron.php. Restricted to logged-in admin screens only, never public page loads.
 */
add_action( 'admin_init', 'emetrio_scriptor_maybe_run_scheduled_generation_inline', 20 );
function emetrio_scriptor_maybe_run_scheduled_generation_inline() {
    if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
        return; // Real WP-Cron is active — nothing to fall back on.
    }
    if ( ! is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( get_transient( 'emetrio_scriptor_inline_check_lock' ) ) {
        return;
    }
    set_transient( 'emetrio_scriptor_inline_check_lock', 1, HOUR_IN_SECONDS );
    emetrio_scriptor_run_scheduled_generation();
}

function emetrio_scriptor_run_scheduled_generation() {
    $tz = emetrio_scriptor_get_schedule_timezone();
    $now = new DateTime( 'now', $tz );
    $today_date = $now->format( 'Y-m-d' );
    update_option( 'emetrio_scriptor_cron_last_check', $now->format( 'Y-m-d H:i:s' ) );

    $enabled = get_option( 'emetrio_scriptor_schedule_enabled', 0 );
    if ( ! $enabled ) {
        update_option( 'emetrio_scriptor_cron_last_result', __( 'Skipped — scheduler disabled', 'emetrio-scriptor' ) );
        emetrio_scriptor_touch_cron_last_action( $now );
        return;
    }

    $frequency = get_option( 'emetrio_scriptor_schedule_frequency', 'daily' );
    $time_str  = get_option( 'emetrio_scriptor_schedule_time', '00:00' );
    list( $h, $m ) = array_map( 'absint', explode( ':', $time_str ) );
    
    $scheduled_time_today = new DateTime(
        sprintf( '%s %02d:%02d:00', $today_date, $h, $m ),
        $tz
    );

    // 1. Determine if today is a scheduled day based on selected frequency
    $is_scheduled_day = false;
    if ( 'daily' === $frequency ) {
        $is_scheduled_day = true;
    } elseif ( 'once' === $frequency ) {
        $once_date = get_option( 'emetrio_scriptor_schedule_once_date', '' );
        if ( ! empty( $once_date ) && $once_date === $today_date ) {
            $is_scheduled_day = true;
        }
    } elseif ( 'weekdays' === $frequency ) {
        $weekdays = get_option( 'emetrio_scriptor_schedule_weekdays', array() );
        $today_w  = (int) $now->format( 'w' );
        if ( in_array( $today_w, array_map( 'absint', $weekdays ) ) ) {
            $is_scheduled_day = true;
        }
    } elseif ( 'monthly' === $frequency ) {
        $configured_dom = (int) get_option( 'emetrio_scriptor_schedule_dayofmonth', 1 );
        if ( (int) $now->format( 'j' ) === $configured_dom ) {
            $is_scheduled_day = true;
        }
    }

    // If today is not scheduled, exit silently to preserve previous status
    if ( ! $is_scheduled_day ) {
        return;
    }

    // 2. Today is a scheduled day. Check if the target hour/minute has arrived yet.
    if ( $now < $scheduled_time_today ) {
        return;
    }

    // 3. Time has arrived. Check if we already generated a post today.
    $last_run_date = get_option( 'emetrio_scriptor_last_run_date', '' );
    if ( $last_run_date === $today_date ) {
        return;
    }

    // Concurrency lock: prevent duplicate runs from concurrent page loads
    if ( get_transient( 'emetrio_scriptor_generating_lock' ) ) {
        return;
    }
    set_transient( 'emetrio_scriptor_generating_lock', 1, 10 * MINUTE_IN_SECONDS );

    // 4. Run content generation
    $post_data = emetrio_scriptor_generate_ai_content( '' );
    
    // Retry once for transient errors
    if ( is_wp_error( $post_data ) || ! is_array( $post_data ) || empty( $post_data['content'] ) ) {
        usleep( 500000 );
        $post_data = emetrio_scriptor_generate_ai_content( '' );
        
        if ( is_wp_error( $post_data ) || ! is_array( $post_data ) || empty( $post_data['content'] ) ) {
            delete_transient( 'emetrio_scriptor_generating_lock' );

            $error_code = is_wp_error( $post_data ) ? $post_data->get_error_code() : 'ai_empty';
            $msg        = is_wp_error( $post_data ) ? $post_data->get_error_message() : __( 'AI returned empty result.', 'emetrio-scriptor' );

            $provider_label = emetrio_scriptor_get_provider_label( get_option( 'emetrio_scriptor_schedule_provider', 'default' ) );
            $full_msg       = $provider_label ? sprintf( '%1$s (%2$s)', $msg, $provider_label ) : $msg;

            emetrio_scriptor_add_log(
                'error',
                sprintf( '[%1$s] %2$s', $error_code, $full_msg ),
                array(),
                array( 'scheduled' => true, 'error_code' => $error_code )
            );

            update_option(
                'emetrio_scriptor_cron_last_result',
                sprintf(
                    /* translators: %1$s: error code, %2$s: error message */
                    __( 'Failed — [%1$s] %2$s', 'emetrio-scriptor' ),
                    $error_code,
                    $full_msg
                )
            );
            emetrio_scriptor_touch_cron_last_action( $now );
            return;
        }
    }

    $post_data['schedule_metadata'] = array(
        'frequency' => $frequency,
        'time'      => $time_str,
        'provider'  => get_option( 'emetrio_scriptor_schedule_provider', 'default' ),
    );

    // Publish post
    $pub_res = emetrio_scriptor_handle_publish( $post_data, true );
    delete_transient( 'emetrio_scriptor_generating_lock' );

    if ( is_wp_error( $pub_res ) ) {
        $msg = $pub_res->get_error_message();
        emetrio_scriptor_add_log( 'error', $msg, array(), array( 'scheduled' => true ) );
        update_option( 'emetrio_scriptor_cron_last_result', 'Failed — ' . $msg );
        emetrio_scriptor_touch_cron_last_action( $now );
    } else {
        $generated_time_str = $now->format( 'H:i' );
        $response_data = is_array( $pub_res ) ? $pub_res : ( method_exists( $pub_res, 'get_data' ) ? $pub_res->get_data() : array() );

        $provider_label = emetrio_scriptor_get_provider_label( get_option( 'emetrio_scriptor_schedule_provider', 'default' ) );
        $suffix         = $provider_label ? sprintf( ' via %s', $provider_label ) : '';

        update_option(
            'emetrio_scriptor_cron_last_result',
            sprintf(
                /* translators: %1$s: generation time, %2$s: AI provider suffix (may be empty) */
                __( 'Success — generated today at %1$s%2$s.', 'emetrio-scriptor' ),
                $generated_time_str,
                $suffix
            )
        );
        update_option( 'emetrio_scriptor_last_run_date', $today_date );
        update_option( 'emetrio_scriptor_last_generated_at', $generated_time_str );
        emetrio_scriptor_touch_cron_last_action( $now );

        $status_label = ( ! empty( $response_data['post_status'] ) && 'publish' === $response_data['post_status'] ) ? 'Published' : 'Draft';
        emetrio_scriptor_add_log( 'success', 'AI-generated post created as ' . strtolower( $status_label ) . $suffix, $response_data );
    }
}

// Normalize user-entered "Run Once" date to Y-m-d using WP timezone
function emetrio_scriptor_sanitize_once_date( $date_str ) {
    $date_str = sanitize_text_field( $date_str );

    if ( empty( $date_str ) ) {
        return '';
    }
    $tz = emetrio_scriptor_get_schedule_timezone();
    $dt = DateTime::createFromFormat( 'Y-m-d', $date_str, $tz );
   // Reject impossible dates (e.g. 2026-02-31) that DateTime would roll over.
    if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date_str ) {
        return '';
    }
    return $date_str;
}