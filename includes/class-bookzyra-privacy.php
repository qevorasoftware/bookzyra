<?php
/**
 * WordPress personal-data export and erasure integration.
 *
 * @package Bookzyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookzyra_Privacy {

	/** Register the built-in WordPress privacy tools. */
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $exporters Existing exporters.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Bookzyra appointments', 'bookzyra' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array<int, array<string, mixed>> $erasers Existing erasers.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_eraser( $erasers ) {
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Bookzyra appointments', 'bookzyra' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export all appointment records associated with an email address.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number (Bookzyra returns all matches in one page).
	 * @return array<string, mixed>
	 */
	public function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email_address );
		$table = Bookzyra_Booking::bookings_table();
		$rows  = is_email( $email ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at ASC", $email ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data  = array();

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'bookzyra_appointments',
				'group_label' => __( 'Bookzyra appointments', 'bookzyra' ),
				'item_id'     => 'bookzyra-' . absint( $row['id'] ),
				'data'        => array(
					array( 'name' => __( 'Name', 'bookzyra' ), 'value' => $row['customer_name'] ),
					array( 'name' => __( 'Email', 'bookzyra' ), 'value' => $row['customer_email'] ),
					array( 'name' => __( 'Phone', 'bookzyra' ), 'value' => $row['customer_phone'] ),
					array( 'name' => __( 'Service', 'bookzyra' ), 'value' => $row['service_name'] ),
					array( 'name' => __( 'Appointment date and time', 'bookzyra' ), 'value' => Bookzyra_Booking::format_datetime( $row['starts_at'] ) ),
					array( 'name' => __( 'Appointment status', 'bookzyra' ), 'value' => Bookzyra_Booking::status_label( $row['status'] ) ),
					array( 'name' => __( 'Payment method', 'bookzyra' ), 'value' => $row['payment_label'] ),
					array( 'name' => __( 'Payment status', 'bookzyra' ), 'value' => Bookzyra_Booking::payment_status_label( $row['payment_status'] ) ),
					array( 'name' => __( 'Customer note', 'bookzyra' ), 'value' => $row['customer_note'] ),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Anonymize appointments associated with an email address while retaining
	 * non-personal service and accounting records.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number (unused; batches are drained from the start).
	 * @return array<string, mixed>
	 */
	public function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email_address );
		$table = Bookzyra_Booking::bookings_table();
		$rows  = is_email( $email ) ? $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT 100", $email ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$erased = 0;

		foreach ( (array) $rows as $row ) {
			$updated = $wpdb->update(
				$table,
				array(
					'customer_name'     => __( 'Erased personal data', 'bookzyra' ),
					'customer_email'    => '',
					'customer_phone'    => '',
					'customer_note'     => '',
					'access_token_hash'  => hash( 'sha256', wp_generate_password( 32, false, false ) ),
					'updated_at'        => current_time( 'mysql' ),
				),
				array( 'id' => absint( $row['id'] ) ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false !== $updated ) {
				++$erased;
			}
		}

		$remaining = is_email( $email ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE customer_email = %s", $email ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items_removed'  => $erased > 0,
			'items_retained' => false,
			'messages'       => array( $erased ? __( 'Appointment details were anonymized.', 'bookzyra' ) : __( 'No appointment details were found for this email address.', 'bookzyra' ) ),
			'done'           => 0 === $remaining,
		);
	}
}
