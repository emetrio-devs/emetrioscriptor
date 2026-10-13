<?php
/**
 * Emetrio Scriptor Uninstall Cleanup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function emetrio_scriptor_uninstall_cleanup() {
    // 1. Delete all plugin settings options
    $options = array(
        'emetrio_scriptor_rate_limit',
        'emetrio_scriptor_rate_window',
        'emetrio_scriptor_default_status',
        'emetrio_scriptor_duplicate_behavior',
        'emetrio_scriptor_required_capability',
        'emetrio_scriptor_schedule_enabled',
        'emetrio_scriptor_schedule_frequency',
        'emetrio_scriptor_schedule_once_date',
        'emetrio_scriptor_schedule_provider',
        'emetrio_scriptor_schedule_weekdays',
        'emetrio_scriptor_schedule_dayofmonth',
        'emetrio_scriptor_schedule_hour',
        'emetrio_scriptor_schedule_time',
        'emetrio_scriptor_brand_dna_language',
        'emetrio_scriptor_brand_dna_business_type',
        'emetrio_scriptor_brand_dna_voice_tone',
        'emetrio_scriptor_brand_dna_target_audience',
        'emetrio_scriptor_brand_dna_service_area',
        'emetrio_scriptor_brand_dna_topics_to_avoid',
        'emetrio_scriptor_cron_last_action_time',
        'emetrio_scriptor_cron_last_result',
        'emetrio_scriptor_cron_last_check',
        'emetrio_scriptor_last_run_date',
        'emetrio_scriptor_last_generated_at',
        'emetrio_scriptor_last_used_time',
        'emetrio_scriptor_last_used_day_of_week',
        'emetrio_scriptor_settings_last_updated'
    );
    
    foreach ( $options as $option ) {
        delete_option( $option );
    }
    
    // 2. Delete all programmatically generated log entries
    $log_ids = get_posts( array(
        'post_type'      => 'emetrio_scriptor_log',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    
    if ( ! empty( $log_ids ) ) {
        foreach ( $log_ids as $log_id ) {
            wp_delete_post( $log_id, true );
        }
    }
}

emetrio_scriptor_uninstall_cleanup();