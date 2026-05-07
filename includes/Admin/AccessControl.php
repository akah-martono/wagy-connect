<?php
namespace Wagy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles per-page Access Control logic.
 *
 * Implements standard role/user access and "Strict Mode" where
 * only explicitly allowed users can view or manage the access settings.
 */
final class AccessControl {

    const OPTION_NAME = 'wagy_access_control';

    /**
     * Map of page slugs to their human-readable names.
     */
    public static function get_pages() {
        return [
            'wagy-status'    => __( 'Status & Quota', 'wagy-connect' ),
            'wagy-messages'  => __( 'Messages Log', 'wagy-connect' ),
            'wagy-broadcast' => __( 'Broadcast', 'wagy-connect' ),
        ];
    }

    /**
     * Hooks into WordPress.
     */
    public static function init() {
        add_filter( 'user_has_cap', [ __CLASS__, 'filter_user_caps' ], 10, 4 );
    }

    /**
     * Dynamically grants or denies access capabilities based on our settings.
     *
     * We map our page slugs to capabilities like 'wagy_access_status'.
     */
    public static function filter_user_caps( $allcaps, $caps, $args, $user ) {
        $pages = array_keys( self::get_pages() );
        $settings = get_option( self::OPTION_NAME, [] );

        foreach ( $pages as $page ) {
            $cap_name = 'wagy_access_' . str_replace( 'wagy-', '', $page );
            
            // If WordPress is checking for this specific Wagy capability...
            if ( in_array( $cap_name, $caps, true ) ) {
                $page_settings = $settings[ $page ] ?? [];
                $strict        = ! empty( $page_settings['strict'] );
                $roles         = $page_settings['roles'] ?? [ 'administrator' ];
                $viewers       = array_map( 'sanitize_user', $page_settings['viewers'] ?? [] );

                if ( $strict ) {
                    // Strict Mode: Role is ignored. Must be in viewers list.
                    $allcaps[ $cap_name ] = in_array( $user->user_login, $viewers, true );
                } else {
                    // Standard Mode: Check Role OR Specific User
                    $has_role = false;
                    foreach ( $roles as $role ) {
                        // In user_has_cap, checking role might be tricky because $allcaps contains role names sometimes.
                        // Better to check if user has the role directly.
                        if ( in_array( $role, $user->roles, true ) ) {
                            $has_role = true;
                            break;
                        }
                    }
                    // For administrator, they usually have manage_options, but we are explicit.
                    if ( in_array( 'administrator', $user->roles, true ) && in_array( 'administrator', $roles, true ) ) {
                         $has_role = true;
                    }
                    
                    $is_viewer = in_array( $user->user_login, $viewers, true );
                    $allcaps[ $cap_name ] = ( $has_role || $is_viewer );
                }
            }
        }

        return $allcaps;
    }

    /**
     * Checks if the current user is allowed to *manage* the access settings for a page.
     * In Standard mode, any Administrator can manage.
     * In Strict mode, only users in the 'managers' list can manage.
     */
    public static function current_user_can_manage( $page ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $settings      = get_option( self::OPTION_NAME, [] );
        $page_settings = $settings[ $page ] ?? [];
        $strict        = ! empty( $page_settings['strict'] );
        $managers      = array_map( 'sanitize_user', $page_settings['managers'] ?? [] );

        if ( ! $strict ) {
            return true; // Any admin can manage standard settings
        }

        $current_user = wp_get_current_user();
        return in_array( $current_user->user_login, $managers, true );
    }
}
