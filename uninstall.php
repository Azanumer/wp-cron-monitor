<?php
// Removes plugin data on uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wcm_notice_shown' );
