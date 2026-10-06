<?php
/**
 * Database setup and default plugin settings.
 *
 * @package Bookzyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookzyra_Installer {

	/**
	 * Default settings used on first activation and as a fallback for new options.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings() {
		return array(
			'business_name'             => get_bloginfo( 'name' ),
			'currency'                  => 'EUR',
			'billing_country'            => 'CY',
			'slot_interval'              => 30,
			'min_notice_hours'            => 2,
			'booking_window_days'         => 60,
			'buffer_minutes'              => 0,
			'auto_confirm'                => 0,
			'availability'                => array(
				0 => array( 'enabled' => 0, 'start' => '09:00', 'end' => '17:00' ),
				1 => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
				2 => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
				3 => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
				4 => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
				5 => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
				6 => array( 'enabled' => 0, 'start' => '09:00', 'end' => '17:00' ),
			),
			'pay_later_enabled'          => 1,
			'pay_later_label'            => __( 'Pay at your appointment', 'bookzyra' ),
			'pay_later_instructions'    => __( 'Pay in person when you arrive.', 'bookzyra' ),
			'vpayments_enabled'         => 0,
			'wallee_space_id'           => '',
			'wallee_user_id'            => '',
			'wallee_auth_key'           => '',
			'custom_methods'             => array(),
			'notification_email'         => get_option( 'admin_email' ),
			'privacy_url'                => '',
			'delete_data_on_uninstall'   => 0,
		);
	}

	/**
	 * Create tables and grant the management capability.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$services_table  = $wpdb->prefix . 'bookzyra_services';
		$bookings_table  = $wpdb->prefix . 'bookzyra_appointments';

		$services_sql = "CREATE TABLE {$services_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			description text NOT NULL,
			duration smallint(5) unsigned NOT NULL DEFAULT 30,
			price decimal(10,2) NOT NULL DEFAULT 0.00,
			color char(7) NOT NULL DEFAULT '#6257e8',
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};";

		$bookings_sql = "CREATE TABLE {$bookings_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			service_name varchar(190) NOT NULL,
			service_price decimal(10,2) NOT NULL DEFAULT 0.00,
			starts_at datetime NOT NULL,
			ends_at datetime NOT NULL,
			customer_name varchar(190) NOT NULL,
			customer_email varchar(190) NOT NULL,
			customer_phone varchar(50) NOT NULL DEFAULT '',
			customer_note text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			payment_method varchar(60) NOT NULL DEFAULT 'offline',
			payment_label varchar(190) NOT NULL DEFAULT '',
			payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
			payment_reference varchar(100) NOT NULL DEFAULT '',
			gateway_transaction_id bigint(20) unsigned NULL DEFAULT NULL,
			access_token_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY service_time (service_id, starts_at),
			KEY starts_at (starts_at),
			KEY status (status),
			KEY customer_email (customer_email),
			KEY gateway_transaction_id (gateway_transaction_id)
		) {$charset_collate};";

		dbDelta( $services_sql );
		dbDelta( $bookings_sql );

		$existing = get_option( 'bookzyra_settings', false );
		if ( false === $existing ) {
			add_option( 'bookzyra_settings', self::default_settings(), '', false );
		} elseif ( is_array( $existing ) ) {
			update_option( 'bookzyra_settings', array_merge( self::default_settings(), $existing ), false );
		}

		update_option( 'bookzyra_db_version', BOOKZYRA_VERSION, false );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( 'manage_bookzyra' );
		}
	}

	/**
	 * Remove the plugin capability without deleting merchant booking data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->remove_cap( 'manage_bookzyra' );
		}
	}
}
