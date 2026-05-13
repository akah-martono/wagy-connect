<?php
namespace Wagy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Registers and renders the "Messages Log" admin sub-page.
 *
 * Displays a filterable, paginated table of all WhatsApp messages
 * sent through the WAGY API. Supports filtering by status, recipient,
 * and date range.
 *
 * @package Wagy\Admin
 */
final class MessagesLogPage {

    /**
     * Hooks into WordPress to register the admin sub-menu entry.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
    }

    /**
     * Registers the "Messages Log" sub-menu page under the Wagy menu.
     *
     * Capability is read from the plugin setting, defaulting to 'manage_options'.
     *
     * @return void
     */
    public function add_admin_menu() {
        // Access capability is dynamically handled by AccessControl.
        $capability = 'wagy_access_messages';
        add_submenu_page(
            'wagy-status',
            __( 'Messages Log', 'wagy-connect' ),
            __( 'Messages Log', 'wagy-connect' ),
            $capability,
            'wagy-messages',
            [ $this, 'messages_page_html' ]
        );
    }

    /**
     * Renders the full HTML for the Messages Log page.
     *
     * Reads and sanitizes filter parameters from $_GET, fetches messages
     * from the WAGY API, then displays a filter form, pagination controls,
     * and a data table.
     *
     * @return void
     */
    public function messages_page_html() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter params, no state change.
        $page       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $status     = isset( $_GET['wagy_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wagy_status'] ) ) : '';
        $recipient  = isset( $_GET['wagy_recipient'] ) ? sanitize_text_field( wp_unslash( $_GET['wagy_recipient'] ) ) : '';
        $start_date = isset( $_GET['wagy_start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wagy_start_date'] ) ) : '';
        $end_date   = isset( $_GET['wagy_end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wagy_end_date'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Process Bulk Actions
        if ( isset( $_POST['wagy_bulk_action'], $_POST['message_ids'], $_POST['wagy_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wagy_nonce'] ) ), 'wagy_bulk_action' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['wagy_bulk_action'] ) );
            $ids    = array_map( 'absint', wp_unslash( $_POST['message_ids'] ) );
            $count  = 0;

            if ( $action === 'cancel' ) {
                foreach ( $ids as $id ) {
                    $res = Wagy::cancel_message( $id );
                    if ( isset( $res['status'] ) && $res['status'] === 'success' ) {
                        $count++;
                    }
                }
                if ( $count > 0 ) {
                    /* translators: %d: number of messages */
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d messages cancelled successfully.', 'wagy-connect' ), $count ) ) . '</p></div>';
                }
            } elseif ( $action === 'resend' ) {
                foreach ( $ids as $id ) {
                    $msg_response = Wagy::get_message( $id );
                    if ( isset( $msg_response['status'], $msg_response['data'] ) && $msg_response['status'] === 'success' ) {
                        $msg_data  = $msg_response['data'];
                        $send_args = [
                            'phone'   => $msg_data['phone'] ?? $msg_data['recipient'] ?? '',
                            'message' => $msg_data['message'],
                        ];
                        if ( ! empty( $msg_data['media_url'] ) ) {
                            $send_args['media_url'] = $msg_data['media_url'];
                        }
                        $res = Wagy::send_message( $send_args );
                        if ( isset( $res['status'] ) && ( $res['status'] === 'queued' || $res['status'] === 'success' || $res['status'] === 'accepted' ) ) {
                            $count++;
                        }
                    }
                }
                if ( $count > 0 ) {
                    /* translators: %d: number of messages */
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d messages queued for resending.', 'wagy-connect' ), $count ) ) . '</p></div>';
                }
            }
        }

        $args = [
            'page'  => $page,
            'limit' => 20,
        ];

        if ( ! empty( $status ) )     $args['status'] = $status;
        if ( ! empty( $recipient ) )  $args['phone']  = $recipient;
        if ( ! empty( $start_date ) ) $args['start_date'] = $start_date;
        if ( ! empty( $end_date ) )   $args['end_date']   = $end_date;

        $response = Wagy::get_messages( $args );

        $messages = [];
        $meta     = [
            'page'        => 1,
            'limit'       => 20,
            'total_data'  => 0,
            'total_pages' => 1,
        ];

        if ( isset( $response['status'] ) && $response['status'] === 'success' ) {
            $messages = $response['data'] ?? [];
            if ( isset( $response['meta'] ) ) {
                $meta = [
                    'page'        => $response['meta']['page'] ?? 1,
                    'limit'       => $response['meta']['limit'] ?? 20,
                    'total_data'  => $response['meta']['total_data'] ?? $response['meta']['total'] ?? 0,
                    'total_pages' => $response['meta']['pages'] ?? $response['meta']['total_pages'] ?? 1,
                ];
            }
        }
        ?>
        <style>
            .wagy-message-cell { max-width: 300px; }
            .wagy-message-text {
                display: -webkit-box;
                -webkit-line-clamp: 1;
                line-clamp: 1;
                -webkit-box-orient: vertical;
                overflow: hidden;
                cursor: pointer;
                color: #2271b1;
                transition: color 0.1s;
            }
            .wagy-message-text:hover { color: #135e96; }
            .wagy-message-text.expanded {
                display: block;
                -webkit-line-clamp: unset;
                line-clamp: unset;
                color: inherit;
            }
            .wagy-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 11px;
            }
            .wagy-status-PENDING   { background: #f0f0f1; color: #3c434a; }
            .wagy-status-SENT      { background: #edfaef; color: #008a20; }
            .wagy-status-DELIVERED { background: #dcf8c6; color: #075e54; }
            .wagy-status-READ      { background: #e1f5fe; color: #0288d1; }
            .wagy-status-FAILED    { background: #fcf0f1; color: #d63638; }
            .wagy-status-EXPIRED   { background: #fff8e5; color: #dba617; }
            .wagy-status-CANCELLED { background: #e0e0e0; color: #646970; }
            .wagy-media-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 11px;
                text-decoration: none;
                transition: opacity 0.15s;
            }
            .wagy-media-badge:hover { opacity: 0.8; }
            .wagy-media-image    { background: #e8f4fd; color: #0070ba; }
            .wagy-media-video    { background: #f3eafd; color: #7c3aed; }
            .wagy-media-audio    { background: #fef3e8; color: #d97706; }
            .wagy-media-document { background: #edf7f0; color: #166534; }
            .wagy-media-other    { background: #f0f0f1; color: #3c434a; }
        </style>

        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Messages Log', 'wagy-connect' ); ?></h1>
            <?php
            // close wrap if device is not connected
            $device_status = Wagy::get_device_status();
            if ( $device_status['status'] !== 'success' ) {
                echo '</div>';
                return;
            }
            ?>
            <hr class="wp-header-end">

            <!-- Forms Configuration -->
            <form method="POST" action="" id="wagy-bulk-form">
                <?php wp_nonce_field( 'wagy_bulk_action', 'wagy_nonce' ); ?>
            </form>

            <form method="GET" action="" id="wagy-filter-form">
                <input type="hidden" name="page" value="wagy-messages" />
            </form>

            <div class="tablenav top" style="display: flex; justify-content: space-between; align-items: center; clear: both; height: auto; min-height: 30px; padding-bottom: 5px;">
                <div class="alignleft actions bulkactions" style="margin: 0; padding: 0;">
                    <?php if ( $status === 'PENDING' || $status === 'EXPIRED' ) : ?>
                        <select name="wagy_bulk_action" form="wagy-bulk-form" required>
                            <option value=""><?php esc_html_e( 'Bulk Actions', 'wagy-connect' ); ?></option>
                            <?php if ( $status === 'PENDING' ) : ?>
                                <option value="cancel"><?php esc_html_e( 'Cancel', 'wagy-connect' ); ?></option>
                            <?php elseif ( $status === 'EXPIRED' ) : ?>
                                <option value="resend"><?php esc_html_e( 'Resend', 'wagy-connect' ); ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'wagy-connect' ); ?>" form="wagy-bulk-form">
                    <?php endif; ?>
                </div>

                <div class="wagy-filters" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; flex-grow: 1;">
                    <select name="wagy_status" form="wagy-filter-form">
                        <option value=""><?php esc_html_e( 'All Statuses', 'wagy-connect' ); ?></option>
                        <option value="PENDING"   <?php selected( $status, 'PENDING' ); ?>>PENDING</option>
                        <option value="SENT"      <?php selected( $status, 'SENT' ); ?>>SENT</option>
                        <option value="DELIVERED" <?php selected( $status, 'DELIVERED' ); ?>>DELIVERED</option>
                        <option value="READ"      <?php selected( $status, 'READ' ); ?>>READ</option>
                        <option value="FAILED"    <?php selected( $status, 'FAILED' ); ?>>FAILED</option>
                        <option value="EXPIRED"   <?php selected( $status, 'EXPIRED' ); ?>>EXPIRED</option>
                        <option value="CANCELLED" <?php selected( $status, 'CANCELLED' ); ?>>CANCELLED</option>
                    </select>
                    <input
                        type="text"
                        name="wagy_recipient"
                        placeholder="<?php esc_attr_e( 'Recipient Number', 'wagy-connect' ); ?>"
                        value="<?php echo esc_attr( $recipient ); ?>"
                        form="wagy-filter-form"
                    >
                    <label><?php esc_html_e( 'From:', 'wagy-connect' ); ?> </label>
                    <input type="date" name="wagy_start_date" value="<?php echo esc_attr( $start_date ); ?>" title="<?php esc_attr_e( 'Start Date', 'wagy-connect' ); ?>" form="wagy-filter-form">
                    <label><?php esc_html_e( 'To:', 'wagy-connect' ); ?> </label>
                    <input type="date" name="wagy_end_date" value="<?php echo esc_attr( $end_date ); ?>" title="<?php esc_attr_e( 'End Date', 'wagy-connect' ); ?>" form="wagy-filter-form">
                    <input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'wagy-connect' ); ?>" form="wagy-filter-form">

                    <?php if ( $meta['total_pages'] > 1 ) : ?>
                    <div class="tablenav-pages" style="margin: 0; padding-left: 10px;">
                        <span class="displaying-num">
                            <?php
                            printf(
                                /* translators: %d: number of items */
                                esc_html( _n( '%d item', '%d items', $meta['total_data'], 'wagy-connect' ) ),
                                esc_html( $meta['total_data'] )
                            );
                            ?>
                        </span>
                        <span class="pagination-links">
                            <?php if ( $meta['page'] > 1 ) : ?>
                                <a class="prev-page button" href="?page=wagy-messages&paged=<?php echo absint( $meta['page'] - 1 ); ?>&wagy_status=<?php echo esc_attr( $status ); ?>&wagy_recipient=<?php echo esc_attr( $recipient ); ?>&wagy_start_date=<?php echo esc_attr( $start_date ); ?>&wagy_end_date=<?php echo esc_attr( $end_date ); ?>">&#8249;</a>
                            <?php else : ?>
                                <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&#8249;</span>
                            <?php endif; ?>

                            <span class="paging-input">
                                <span class="tablenav-paging-text">
                                    <?php echo esc_html( $meta['page'] ); ?>
                                    <?php esc_html_e( 'of', 'wagy-connect' ); ?>
                                    <span class="total-pages"><?php echo esc_html( $meta['total_pages'] ); ?></span>
                                </span>
                            </span>

                            <?php if ( $meta['page'] < $meta['total_pages'] ) : ?>
                                <a class="next-page button" href="?page=wagy-messages&paged=<?php echo absint( $meta['page'] + 1 ); ?>&wagy_status=<?php echo esc_attr( $status ); ?>&wagy_recipient=<?php echo esc_attr( $recipient ); ?>&wagy_start_date=<?php echo esc_attr( $start_date ); ?>&wagy_end_date=<?php echo esc_attr( $end_date ); ?>">&#8250;</a>
                            <?php else : ?>
                                <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&#8250;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages Table -->
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <?php if ( $status === 'PENDING' || $status === 'EXPIRED' ) : ?>
                            <td id="cb" class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'wagy-connect' ); ?></label>
                                <input id="cb-select-all-1" type="checkbox">
                            </td>
                        <?php endif; ?>
                        <th scope="col" class="manage-column" style="width: 120px;"><?php esc_html_e( 'Recipient', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Message', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column" style="width: 80px;"><?php esc_html_e( 'Media', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column" style="width: 100px;"><?php esc_html_e( 'Status', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column" style="width: 150px;"><?php esc_html_e( 'Created / Sent At', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Notes', 'wagy-connect' ); ?></th>
                    </tr>
                </thead>

                <tbody id="the-list">
                    <?php if ( empty( $messages ) ) : ?>
                        <tr class="no-items">
                            <td class="colspanchange" colspan="<?php echo ( $status === 'PENDING' || $status === 'EXPIRED' ) ? 7 : 6; ?>"><?php esc_html_e( 'No messages found.', 'wagy-connect' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $messages as $msg ) : ?>
                            <tr>
                                <?php if ( $status === 'PENDING' || $status === 'EXPIRED' ) : ?>
                                    <th scope="row" class="check-column">
                                        <label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $msg['id'] ); ?>"><?php esc_html_e( 'Select message', 'wagy-connect' ); ?></label>
                                        <input id="cb-select-<?php echo esc_attr( $msg['id'] ); ?>" type="checkbox" name="message_ids[]" value="<?php echo esc_attr( $msg['id'] ); ?>" form="wagy-bulk-form">
                                    </th>
                                <?php endif; ?>
                                <td><?php echo esc_html( $msg['phone'] ?? $msg['recipient'] ?? '' ); ?></td>
                                <td class="wagy-message-cell">
                                    <div
                                        class="wagy-message-text"
                                        title="<?php esc_attr_e( 'Click to expand', 'wagy-connect' ); ?>"
                                        onclick="this.classList.toggle('expanded')"
                                    >
                                        <?php echo wp_kses_post( nl2br( esc_html( $msg['message'] ) ) ); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $media_url = $msg['media_url'] ?? '';
                                    if ( ! empty( $media_url ) ) :
                                        // Detect media type from URL extension.
                                        $ext = strtolower( pathinfo( wp_parse_url( $media_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
                                        if ( in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp' ], true ) ) :
                                            $media_type  = 'image';
                                            $media_icon  = '🖼️';
                                            $media_class = 'wagy-media-image';
                                        elseif ( in_array( $ext, [ 'mp4', 'mov', 'avi', 'mkv', 'webm', '3gp' ], true ) ) :
                                            $media_type  = 'video';
                                            $media_icon  = '🎬';
                                            $media_class = 'wagy-media-video';
                                        elseif ( in_array( $ext, [ 'mp3', 'ogg', 'wav', 'aac', 'm4a', 'opus' ], true ) ) :
                                            $media_type  = 'audio';
                                            $media_icon  = '🎵';
                                            $media_class = 'wagy-media-audio';
                                        elseif ( in_array( $ext, [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip' ], true ) ) :
                                            $media_type  = 'document';
                                            $media_icon  = '📄';
                                            $media_class = 'wagy-media-document';
                                        else :
                                            $media_type  = 'file';
                                            $media_icon  = '📎';
                                            $media_class = 'wagy-media-other';
                                        endif;
                                        ?>
                                        <a
                                            href="<?php echo esc_url( $media_url ); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="wagy-media-badge <?php echo esc_attr( $media_class ); ?>"
                                            title="<?php echo esc_attr( $media_url ); ?>"
                                        >
                                            <?php echo $media_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- emoji literal ?>
                                            <?php echo esc_html( $media_type ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#aaa;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="wagy-status-badge wagy-status-<?php echo esc_attr( $msg['status'] ); ?>">
                                        <?php echo esc_html( $msg['status'] ); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="margin-bottom: 4px;">
                                        <span class="dashicons dashicons-calendar-alt" style="font-size:14px; margin-top:2px;"></span>
                                        <?php echo esc_html( Wagy::convert_utc_to_wptz( $msg['created_at'] ) ); ?>
                                    </div>
                                    <?php if ( ! empty( $msg['sent_at'] ) ) : ?>
                                        <div>
                                            <span class="dashicons dashicons-saved" style="font-size:14px; margin-top:2px; color:#008a20;"></span>
                                            <?php echo esc_html( Wagy::convert_utc_to_wptz( $msg['sent_at'] ) ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo wp_kses_post( nl2br( esc_html( $msg['notes'] ?? '' ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

                <tfoot>
                    <tr>
                        <?php if ( $status === 'PENDING' || $status === 'EXPIRED' ) : ?>
                            <td class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all-2"><?php esc_html_e( 'Select All', 'wagy-connect' ); ?></label>
                                <input id="cb-select-all-2" type="checkbox">
                            </td>
                        <?php endif; ?>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Recipient', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Message', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Media', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Status', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Created / Sent At', 'wagy-connect' ); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e( 'Notes', 'wagy-connect' ); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }
}
