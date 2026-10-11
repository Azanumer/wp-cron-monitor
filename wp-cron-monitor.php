<?php
/**
 * Plugin Name: WP Cron Monitor
 * Description: See every scheduled WP-Cron event, spot overdue ("missed") jobs, run or unschedule them with one click, and get an admin notice when jobs stop firing.
 * Version: 1.0.0
 * Author: Azan Umer
 * License: MIT
 * Text Domain: wcm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPCronMonitor {

    const PAGE = 'wp-cron-monitor';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_wcm_run', array( __CLASS__, 'handle_run' ) );
        add_action( 'admin_post_wcm_unschedule', array( __CLASS__, 'handle_unschedule' ) );
        add_action( 'admin_notices', array( __CLASS__, 'overdue_notice' ) );
    }

    public static function menu() {
        add_management_page(
            __( 'Cron Monitor', 'wcm' ),
            __( 'Cron Monitor', 'wcm' ),
            'manage_options',
            self::PAGE,
            array( __CLASS__, 'page' )
        );
    }

    /**
     * Flatten _get_cron_array() into a list of event rows.
     * Each row: hook, timestamp, recurrence slug, interval, args.
     */
    public static function events() {
        $crons  = _get_cron_array();
        $events = array();
        if ( ! is_array( $crons ) ) {
            return $events;
        }
        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $schedules ) {
                foreach ( $schedules as $sig => $data ) {
                    $events[] = array(
                        'hook'       => $hook,
                        'timestamp'  => (int) $timestamp,
                        'sig'        => $sig,
                        'schedule'   => isset( $data['schedule'] ) ? $data['schedule'] : false,
                        'interval'   => isset( $data['interval'] ) ? (int) $data['interval'] : 0,
                        'args'       => isset( $data['args'] ) ? $data['args'] : array(),
                    );
                }
            }
        }
        usort( $events, function ( $a, $b ) {
            return $a['timestamp'] <=> $b['timestamp'];
        } );
        return $events;
    }

    /**
     * Seconds of grace before an event counts as "overdue".
     * Filterable: add_filter( 'wcm_overdue_grace', fn() => 3600 );
     */
    public static function overdue_grace() {
        return (int) apply_filters( 'wcm_overdue_grace', 600 );
    }

    public static function overdue_events() {
        $now   = time();
        $grace = self::overdue_grace();
        return array_filter( self::events(), function ( $e ) use ( $now, $grace ) {
            return $e['timestamp'] + $grace < $now;
        } );
    }

    /* ---- Actions ---- */

    public static function handle_run() {
        self::verify_action( 'wcm_run' );
        $hook = isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '';
        $sig  = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
        $event = self::find_event( $hook, $sig );
        if ( $event ) {
            // Execute the hook exactly as cron would.
            do_action_ref_array( $hook, $event['args'] );
            add_settings_error( 'wcm', 'ran', sprintf( __( 'Executed "%s" now.', 'wcm' ), $hook ), 'updated' );
        } else {
            add_settings_error( 'wcm', 'missing', __( 'That event no longer exists.', 'wcm' ), 'error' );
        }
        set_transient( 'settings_errors', get_settings_errors(), 30 );
        wp_safe_redirect( self::page_url() );
        exit;
    }

    public static function handle_unschedule() {
        self::verify_action( 'wcm_unschedule' );
        $hook = isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '';
        $sig  = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
        $event = self::find_event( $hook, $sig );
        if ( $event ) {
            $ts = wp_next_scheduled( $hook, $event['args'] );
            if ( $ts ) {
                wp_unschedule_event( $ts, $hook, $event['args'] );
            }
            add_settings_error( 'wcm', 'unscheduled', sprintf( __( 'Unscheduled "%s".', 'wcm' ), $hook ), 'updated' );
        } else {
            add_settings_error( 'wcm', 'missing', __( 'That event no longer exists.', 'wcm' ), 'error' );
        }
        set_transient( 'settings_errors', get_settings_errors(), 30 );
        wp_safe_redirect( self::page_url() );
        exit;
    }

    public static function verify_action( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wcm' ), 403 );
        }
        check_admin_referer( $action );
    }

    /** Locate one scheduled event by hook + md5 signature of its args. */
    public static function find_event( $hook, $sig ) {
        foreach ( self::events() as $event ) {
            if ( $event['hook'] === $hook && $event['sig'] === $sig ) {
                return $event;
            }
        }
        return null;
    }

    public static function page_url() {
        return admin_url( 'tools.php?page=' . self::PAGE );
    }

    /* ---- Notices ---- */

    public static function overdue_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $overdue = self::overdue_events();
        if ( empty( $overdue ) ) {
            return;
        }
        // Show at most once per week.
        $last = (int) get_option( 'wcm_notice_shown', 0 );
        if ( $last > time() - WEEK_IN_SECONDS ) {
            return;
        }
        update_option( 'wcm_notice_shown', time(), 'no' );
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html( sprintf(
                /* translators: %d: number of overdue events */
                _n(
                    '%d WP-Cron event is overdue — your cron may be broken. Check Tools → Cron Monitor.',
                    '%d WP-Cron events are overdue — your cron may be broken. Check Tools → Cron Monitor.',
                    count( $overdue ),
                    'wcm'
                ),
                count( $overdue )
            ) )
        );
    }

    /* ---- Admin page ---- */

    public static function page() {
        $events  = self::events();
        $grace   = self::overdue_grace();
        $now     = time();
        $scheds  = wp_get_schedules();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Cron Monitor', 'wcm' ); ?></h1>
            <p><?php esc_html_e( 'Every event WordPress has scheduled. Overdue rows are highlighted — they usually mean cron is not firing (e.g. missing server cron for DISABLE_WP_CRON sites).', 'wcm' ); ?></p>
            <?php settings_errors( 'wcm' ); ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Hook', 'wcm' ); ?></th>
                        <th><?php esc_html_e( 'Next run', 'wcm' ); ?></th>
                        <th><?php esc_html_e( 'Recurrence', 'wcm' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wcm' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $events ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No scheduled events.', 'wcm' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $events as $e ) :
                            $overdue = $e['timestamp'] + $grace < $now;
                            $run_url = wp_nonce_url(
                                add_query_arg( array( 'action' => 'wcm_run', 'hook' => $e['hook'], 'sig' => $e['sig'] ), admin_url( 'admin-post.php' ) ),
                                'wcm_run'
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg( array( 'action' => 'wcm_unschedule', 'hook' => $e['hook'], 'sig' => $e['sig'] ), admin_url( 'admin-post.php' ) ),
                                'wcm_unschedule'
                            );
                        ?>
                        <tr<?php echo $overdue ? ' style="background:#fcf0f0;"' : ''; ?>>
                            <td><code><?php echo esc_html( $e['hook'] ); ?></code>
                                <?php if ( ! empty( $e['args'] ) ) : ?>
                                    <br><small><?php echo esc_html( wp_json_encode( $e['args'] ) ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $e['timestamp'] ) ); ?>
                                <br><small>(<?php echo esc_html( human_time_diff( $now, $e['timestamp'] ) ); ?> <?php echo $e['timestamp'] < $now ? esc_html__( 'ago', 'wcm' ) : esc_html__( 'from now', 'wcm' ); ?>)</small>
                                <?php if ( $overdue ) : ?>
                                    <br><strong style="color:#a00;"><?php esc_html_e( 'OVERDUE', 'wcm' ); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ( $e['schedule'] && isset( $scheds[ $e['schedule'] ] ) ) {
                                    echo esc_html( $scheds[ $e['schedule'] ]['display'] );
                                } else {
                                    esc_html_e( 'Single event', 'wcm' );
                                }
                                ?>
                            </td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url( $run_url ); ?>"><?php esc_html_e( 'Run now', 'wcm' ); ?></a>
                                <a class="button button-small" href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Unschedule this event?', 'wcm' ) ); ?>');"><?php esc_html_e( 'Unschedule', 'wcm' ); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

WPCronMonitor::init();
