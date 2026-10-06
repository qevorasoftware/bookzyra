<?php
/**
 * Services, availability, reservations and booking notifications.
 *
 * @package Bookzyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookzyra_Booking {

	/** @return string */
	public static function services_table() {
		global $wpdb;
		return $wpdb->prefix . 'bookzyra_services';
	}

	/** @return string */
	public static function bookings_table() {
		global $wpdb;
		return $wpdb->prefix . 'bookzyra_appointments';
	}

	/**
	 * Get the currently bookable services.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_active_services() {
		global $wpdb;
		$table = self::services_table();
		$rows  = $wpdb->get_results( "SELECT id, name, description, duration, price, color FROM {$table} WHERE active = 1 ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get one service.
	 *
	 * @param int  $service_id Service ID.
	 * @param bool $active_only Only return active services.
	 * @return array<string, mixed>|null
	 */
	public static function get_service( $service_id, $active_only = true ) {
		if ( ! is_scalar( $service_id ) || ! absint( $service_id ) ) {
			return null;
		}
		global $wpdb;
		$table = self::services_table();
		$sql   = "SELECT * FROM {$table} WHERE id = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $active_only ) {
			$sql .= ' AND active = 1';
		}
		$row = $wpdb->get_row( $wpdb->prepare( $sql, absint( $service_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get available start times for a service and local calendar date.
	 *
	 * @param int    $service_id Service ID.
	 * @param string $date       Y-m-d in the site's timezone.
	 * @return array<int, string>|WP_Error
	 */
	public static function get_available_slots( $service_id, $date ) {
		global $wpdb;

		if ( ! is_scalar( $service_id ) || ! is_scalar( $date ) ) {
			return new WP_Error( 'bookzyra_invalid_request', __( 'Please choose a valid service and date.', 'bookzyra' ), array( 'status' => 400 ) );
		}
		$service = self::get_service( $service_id );
		if ( ! $service ) {
			return new WP_Error( 'bookzyra_service_not_found', __( 'That service is no longer available. Please choose another service.', 'bookzyra' ), array( 'status' => 404 ) );
		}

		$date = sanitize_text_field( $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'bookzyra_invalid_date', __( 'Please choose a valid appointment date.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$settings = bookzyra_get_settings();
		$timezone = wp_timezone();
		$date_obj = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();
		if ( ! $date_obj || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date_obj->format( 'Y-m-d' ) !== $date ) {
			return new WP_Error( 'bookzyra_invalid_date', __( 'Please choose a valid appointment date.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$today            = new DateTimeImmutable( 'today', $timezone );
		$last_bookable_day = $today->modify( '+' . absint( $settings['booking_window_days'] ) . ' days' );
		if ( $date_obj < $today || $date_obj > $last_bookable_day ) {
			return new WP_Error( 'bookzyra_date_out_of_range', __( 'That date is outside the current booking window. Please choose another date.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$day        = (int) $date_obj->format( 'w' );
		$availability = isset( $settings['availability'][ $day ] ) && is_array( $settings['availability'][ $day ] )
			? $settings['availability'][ $day ]
			: array();
		if ( empty( $availability['enabled'] ) || empty( $availability['start'] ) || empty( $availability['end'] ) ) {
			return array();
		}

		$open  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $availability['start'], $timezone );
		$close = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $availability['end'], $timezone );
		if ( ! $open || ! $close || $close <= $open ) {
			return array();
		}

		$interval       = max( 5, absint( $settings['slot_interval'] ) );
		$duration       = max( 5, absint( $service['duration'] ) );
		$buffer         = min( 180, absint( $settings['buffer_minutes'] ) );
		$minimum_start  = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '+' . absint( $settings['min_notice_hours'] ) . ' hours' );
		$day_start      = $date_obj->setTime( 0, 0, 0 );
		$day_end        = $day_start->modify( '+1 day' );
		$stale_checkout = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '-30 minutes' )->format( 'Y-m-d H:i:s' );
		$table          = self::bookings_table();

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT starts_at, ends_at FROM {$table}
				WHERE service_id = %d AND status <> 'cancelled'
				AND starts_at < %s AND ends_at > %s
				AND NOT (payment_method = 'vpayments' AND payment_status = 'awaiting' AND created_at < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $service_id ),
				$day_end->modify( '+' . $buffer . ' minutes' )->format( 'Y-m-d H:i:s' ),
				$day_start->modify( '-' . $buffer . ' minutes' )->format( 'Y-m-d H:i:s' ),
				$stale_checkout
			),
			ARRAY_A
		);

		$slots = array();
		for ( $candidate = $open; $candidate->modify( '+' . $duration . ' minutes' ) <= $close; $candidate = $candidate->modify( '+' . $interval . ' minutes' ) ) {
			if ( $candidate < $minimum_start ) {
				continue;
			}

			$candidate_end = $candidate->modify( '+' . $duration . ' minutes' );
			$is_taken      = false;

			foreach ( (array) $bookings as $booking ) {
				$existing_start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $booking['starts_at'], $timezone );
				$existing_end   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $booking['ends_at'], $timezone );
				if ( ! $existing_start || ! $existing_end ) {
					continue;
				}

				$buffered_candidate_end = $candidate_end->modify( '+' . $buffer . ' minutes' );
				$buffered_existing_end  = $existing_end->modify( '+' . $buffer . ' minutes' );
				if ( $candidate < $buffered_existing_end && $buffered_candidate_end > $existing_start ) {
					$is_taken = true;
					break;
				}
			}

			if ( ! $is_taken ) {
				$slots[] = $candidate->format( 'H:i' );
			}
		}

		return $slots;
	}

	/**
	 * Create an appointment. The service row is locked while availability is checked
	 * and the appointment is inserted, which prevents simultaneous double bookings.
	 *
	 * @param array<string, mixed> $data Customer and appointment details.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_booking( $data ) {
		global $wpdb;
		$data = is_array( $data ) ? $data : array();

		$service_id = isset( $data['service_id'] ) && is_scalar( $data['service_id'] ) ? absint( $data['service_id'] ) : 0;
		$date       = isset( $data['date'] ) && is_scalar( $data['date'] ) ? sanitize_text_field( (string) $data['date'] ) : '';
		$time       = isset( $data['time'] ) && is_scalar( $data['time'] ) ? sanitize_text_field( (string) $data['time'] ) : '';
		$name       = isset( $data['name'] ) && is_scalar( $data['name'] ) ? self::truncate_text( sanitize_text_field( (string) $data['name'] ), 190 ) : '';
		$email      = isset( $data['email'] ) && is_scalar( $data['email'] ) ? self::truncate_text( sanitize_email( (string) $data['email'] ), 190 ) : '';
		$phone      = isset( $data['phone'] ) && is_scalar( $data['phone'] ) ? self::truncate_text( sanitize_text_field( (string) $data['phone'] ), 50 ) : '';
		$note       = isset( $data['note'] ) && is_scalar( $data['note'] ) ? self::truncate_text( sanitize_textarea_field( (string) $data['note'] ), 1000 ) : '';
		$method_id  = isset( $data['payment_method'] ) && is_scalar( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '';

		if ( ! $service_id || '' === $name || ! is_email( $email ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new WP_Error( 'bookzyra_invalid_booking', __( 'Please check the details in the form and try again.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$method = Bookzyra_Payments::get_method( $method_id );
		if ( ! $method ) {
			return new WP_Error( 'bookzyra_invalid_payment_method', __( 'Please choose an available payment method.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$timezone = wp_timezone();
		$start    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();
		if ( ! $start || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $start->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return new WP_Error( 'bookzyra_invalid_time', __( 'Please choose a valid appointment time.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$services_table = self::services_table();
		$bookings_table = self::bookings_table();
		$wpdb->query( 'START TRANSACTION' );

		$service = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$services_table} WHERE id = %d AND active = 1 FOR UPDATE", $service_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $service ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookzyra_service_not_found', __( 'That service is no longer available. Please choose another service.', 'bookzyra' ), array( 'status' => 404 ) );
		}
		if ( 'vpayments' === $method_id && (float) $service['price'] <= 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookzyra_free_online_payment', __( 'Online payment is not available for free appointments. Please choose another payment method.', 'bookzyra' ), array( 'status' => 400 ) );
		}
		if ( 'free' === $method_id && (float) $service['price'] > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookzyra_payment_required', __( 'Please choose a payment method for this paid appointment.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$available = self::get_available_slots( $service_id, $date );
		if ( is_wp_error( $available ) || ! in_array( $time, $available, true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookzyra_slot_unavailable', __( 'Sorry, that time was just booked. Please choose another available time.', 'bookzyra' ), array( 'status' => 409 ) );
		}

		$end      = $start->modify( '+' . absint( $service['duration'] ) . ' minutes' );
		$settings = bookzyra_get_settings();
		$status   = ( 'vpayments' !== $method_id && ! empty( $settings['auto_confirm'] ) ) ? 'confirmed' : 'pending';
		$payment_status = 'free' === $method['type'] ? 'not_required' : ( 'offline' === $method['type'] ? 'unpaid' : 'awaiting' );
		$token          = wp_generate_password( 48, false, false );
		$now            = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$bookings_table,
			array(
				'service_id'             => $service_id,
				'service_name'           => sanitize_text_field( $service['name'] ),
				'service_price'          => number_format( (float) $service['price'], 2, '.', '' ),
				'starts_at'              => $start->format( 'Y-m-d H:i:s' ),
				'ends_at'                => $end->format( 'Y-m-d H:i:s' ),
				'customer_name'          => $name,
				'customer_email'         => $email,
				'customer_phone'         => $phone,
				'customer_note'          => $note,
				'status'                 => $status,
				'payment_method'         => $method_id,
				'payment_label'          => sanitize_text_field( $method['label'] ),
				'payment_status'         => $payment_status,
				'access_token_hash'      => hash( 'sha256', $token ),
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookzyra_booking_save_failed', __( 'We could not save your appointment. Please try again in a moment.', 'bookzyra' ), array( 'status' => 500 ) );
		}

		$booking_id = absint( $wpdb->insert_id );
		$committed  = $wpdb->query( 'COMMIT' );
		if ( false === $committed ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookzyra_booking_save_failed', __( 'We could not save your appointment. Please try again in a moment.', 'bookzyra' ), array( 'status' => 500 ) );
		}

		$booking = self::get_booking( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'bookzyra_booking_save_failed', __( 'Your appointment was saved, but we could not load its details. Please contact the site owner.', 'bookzyra' ), array( 'status' => 500 ) );
		}

		$booking['access_token'] = $token;
		$booking['method']       = $method;

		return $booking;
	}

	/**
	 * Find a booking by its public ID and one-time return token.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $token      Random browser-return token.
	 * @return array<string, mixed>|null
	 */
	public static function get_booking_by_token( $booking_id, $token ) {
		$booking = self::get_booking( $booking_id );
		if ( ! $booking || empty( $booking['access_token_hash'] ) || ! hash_equals( $booking['access_token_hash'], hash( 'sha256', (string) $token ) ) ) {
			return null;
		}

		return $booking;
	}

	/**
	 * Fetch an appointment by its ID.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_booking( $booking_id ) {
		global $wpdb;
		$table = self::bookings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $booking_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a booking associated with a Wallee transaction.
	 *
	 * @param int $transaction_id Wallee transaction ID.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_gateway_transaction( $transaction_id ) {
		global $wpdb;
		$table = self::bookings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway_transaction_id = %d LIMIT 1", absint( $transaction_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Save an appointment's admin-managed status fields.
	 *
	 * @param int    $booking_id    Booking ID.
	 * @param string $status        Appointment status.
	 * @param string $payment_state Payment state.
	 * @return bool
	 */
	public static function update_booking_status( $booking_id, $status, $payment_state ) {
		global $wpdb;
		$table = self::bookings_table();
		$before = self::get_booking( $booking_id );
		if ( ! $before ) {
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'        => $status,
				'payment_status' => $payment_state,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => absint( $booking_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated && ( $before['status'] !== $status || $before['payment_status'] !== $payment_state ) ) {
			self::send_notification( $booking_id, 'updated' );
		}

		return false !== $updated;
	}

	/**
	 * Apply a verified Wallee transaction state to a booking.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $state      Wallee transaction state.
	 * @return void
	 */
	public static function apply_gateway_state( $booking_id, $state ) {
		if ( ! is_scalar( $state ) ) {
			return;
		}
		global $wpdb;
		$booking = self::get_booking( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$state = strtoupper( sanitize_text_field( $state ) );
		$next_status = $booking['status'];
		$next_payment = $booking['payment_status'];
		$event = '';

		if ( in_array( $state, array( 'AUTHORIZED', 'COMPLETED', 'FULFILL' ), true ) ) {
			$next_payment = 'paid';
			if ( 'pending' === $next_status && ! empty( bookzyra_get_settings()['auto_confirm'] ) ) {
				$next_status = 'confirmed';
			}
			if ( 'paid' !== $booking['payment_status'] ) {
				$event = 'payment_received';
			}
		} elseif ( in_array( $state, array( 'FAILED', 'DECLINE', 'VOIDED' ), true ) ) {
			$next_payment = 'failed';
			if ( 'pending' === $next_status ) {
				$next_status = 'cancelled';
			}
			if ( 'failed' !== $booking['payment_status'] ) {
				$event = 'payment_failed';
			}
		} else {
			return;
		}

		$table   = self::bookings_table();
		$updated = $wpdb->update(
			$table,
			array(
				'status'        => $next_status,
				'payment_status' => $next_payment,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => absint( $booking_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated && '' !== $event ) {
			self::send_notification( $booking_id, $event );
		}
	}

	/**
	 * Send plain-text customer and owner notifications.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $event      created|updated|payment_received|payment_failed.
	 * @return array{customer_accepted: bool, admin_accepted: bool}
	 */
	public static function send_notification( $booking_id, $event ) {
		$delivery = array(
			'customer_accepted' => false,
			'admin_accepted'    => false,
		);
		$booking = self::get_booking( $booking_id );
		if ( ! $booking ) {
			return $delivery;
		}

		$settings      = bookzyra_get_settings();
		$business      = ! empty( $settings['business_name'] ) && is_scalar( $settings['business_name'] ) ? sanitize_text_field( (string) $settings['business_name'] ) : get_bloginfo( 'name' );
		$customer_name = sanitize_text_field( $booking['customer_name'] );
		$reference     = sprintf( 'BZ-%05d', absint( $booking['id'] ) );
		$when          = self::format_datetime( $booking['starts_at'] );
		$status_label  = self::status_label( $booking['status'] );
		$payment_label = self::payment_status_label( $booking['payment_status'] );
		$lines         = array(
			sprintf( __( 'Appointment reference: %s', 'bookzyra' ), $reference ),
			sprintf( __( 'Service: %s', 'bookzyra' ), $booking['service_name'] ),
			sprintf( __( 'When: %s', 'bookzyra' ), $when ),
			sprintf( __( 'Appointment status: %s', 'bookzyra' ), $status_label ),
			sprintf( __( 'Payment: %s (%s)', 'bookzyra' ), $booking['payment_label'], $payment_label ),
		);
		if ( '' !== $booking['customer_phone'] ) {
			$lines[] = sprintf( __( 'Phone: %s', 'bookzyra' ), $booking['customer_phone'] );
		}
		if ( '' !== $booking['customer_note'] ) {
			$lines[] = sprintf( __( 'Customer note: %s', 'bookzyra' ), $booking['customer_note'] );
		}
		if ( in_array( $event, array( 'created', 'payment_received' ), true ) ) {
			$method = Bookzyra_Payments::get_method( $booking['payment_method'] );
			if ( $method && ! empty( $method['instructions'] ) ) {
				$lines[] = '';
				$lines[] = __( 'Payment instructions:', 'bookzyra' );
				$lines[] = $method['instructions'];
			}
		}

		switch ( $event ) {
			case 'payment_received':
				$subject = sprintf( __( 'Payment confirmed for appointment %s', 'bookzyra' ), $reference );
				$intro   = sprintf( __( 'Hello %1$s, thank you for booking with %2$s. Your online payment has been verified.', 'bookzyra' ), $customer_name, $business );
				break;
			case 'payment_failed':
				$subject = sprintf( __( 'Payment update for appointment %s', 'bookzyra' ), $reference );
				$intro   = sprintf( __( 'Hello %1$s, your online payment for the appointment below was not completed. Please contact %2$s if you would like to arrange another payment method.', 'bookzyra' ), $customer_name, $business );
				break;
			case 'updated':
				$subject = sprintf( __( 'Appointment update: %s', 'bookzyra' ), $reference );
				$intro   = sprintf( __( 'Hello %1$s, there has been an update to your appointment with %2$s.', 'bookzyra' ), $customer_name, $business );
				break;
			default:
				$subject = sprintf( __( 'Thank you for booking with %1$s — %2$s', 'bookzyra' ), $business, $reference );
				$intro   = 'confirmed' === $booking['status']
					? sprintf( __( 'Hello %1$s, thank you for booking with %2$s. Your appointment is confirmed.', 'bookzyra' ), $customer_name, $business )
					: sprintf( __( 'Hello %1$s, thank you for booking with %2$s. We have received your appointment request and will contact you with an update.', 'bookzyra' ), $customer_name, $business );
				break;
		}

		$body = $intro . "\n\n" . implode( "\n", $lines ) . "\n\n" . $business;
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( is_email( $booking['customer_email'] ) ) {
			$delivery['customer_accepted'] = (bool) wp_mail( $booking['customer_email'], $subject, $body, $headers );
		}

		$admin_email = isset( $settings['notification_email'] ) && is_scalar( $settings['notification_email'] )
			? sanitize_email( (string) $settings['notification_email'] )
			: '';
		if ( ! is_email( $admin_email ) ) {
			$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		if ( is_email( $admin_email ) && $admin_email !== $booking['customer_email'] ) {
			$admin_subject = sprintf( __( '[%1$s] Appointment %2$s', 'bookzyra' ), $business, $reference );
			$admin_body    = sprintf( __( 'New appointment notification for %1$s.', 'bookzyra' ), $business ) . "\n\n" . sprintf( __( 'Customer: %1$s (%2$s)', 'bookzyra' ), $customer_name, $booking['customer_email'] ) . "\n" . implode( "\n", $lines );
			$delivery['admin_accepted'] = (bool) wp_mail( $admin_email, $admin_subject, $admin_body, $headers );
		}

		set_transient(
			'bookzyra_mail_delivery_' . absint( $booking['id'] ),
			array(
				'event'         => sanitize_key( $event ),
				'customer_accepted' => $delivery['customer_accepted'],
			),
			DAY_IN_SECONDS
		);

		return $delivery;
	}

	/**
	 * Truncate validated free text without splitting a multibyte character.
	 *
	 * @param string $value Text value.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function truncate_text( $value, $limit ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit, 'UTF-8' );
		}
		if ( preg_match( '/^.{0,' . absint( $limit ) . '}/us', $value, $matches ) ) {
			return $matches[0];
		}
		return substr( $value, 0, $limit );
	}

	/**
	 * Format a site's local MySQL datetime for customer-facing display.
	 *
	 * @param string $datetime Local MySQL datetime.
	 * @param string $format   PHP date format.
	 * @return string
	 */
	public static function format_datetime( $datetime, $format = 'D, M j, Y · g:i a' ) {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $datetime, wp_timezone() );
		if ( ! $parsed ) {
			return (string) $datetime;
		}

		return wp_date( $format, $parsed->getTimestamp(), wp_timezone() );
	}

	/**
	 * Human-readable appointment state.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'bookzyra' ),
			'confirmed' => __( 'Confirmed', 'bookzyra' ),
			'cancelled' => __( 'Cancelled', 'bookzyra' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( sanitize_text_field( $status ) );
	}

	/**
	 * Human-readable payment state.
	 *
	 * @param string $status Payment state slug.
	 * @return string
	 */
	public static function payment_status_label( $status ) {
		$labels = array(
			'not_required' => __( 'No payment required', 'bookzyra' ),
			'unpaid'       => __( 'Unpaid', 'bookzyra' ),
			'awaiting'     => __( 'Awaiting payment', 'bookzyra' ),
			'paid'         => __( 'Paid', 'bookzyra' ),
			'failed'       => __( 'Failed', 'bookzyra' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( sanitize_text_field( $status ) );
	}
}
