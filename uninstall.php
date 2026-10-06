<?php
/**
 * Optional data cleanup. By default Bookzyra keeps merchant data on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'bookzyra_settings', array() );
if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bookzyra_appointments' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bookzyra_services' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'bookzyra_settings' );
delete_option( 'bookzyra_db_version' );

$administrator = get_role( 'administrator' );
if ( $administrator ) {
	$administrator->remove_cap( 'manage_bookzyra' );
}
