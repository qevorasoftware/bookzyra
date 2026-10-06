<?php
/**
 * Payment method registry and VPayments/Wallee payment-page integration.
 *
 * Vpayments lists Wallee as one of its online payment gateways. This adapter
 * uses Wallee's hosted payment page so card data never passes through WordPress.
 *
 * @package Bookzyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookzyra_Payments {

	const API_ROOT = 'https://app-wallee.com';

	/**
	 * Whether the Wallee credentials are complete enough to make API requests.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = bookzyra_get_settings();
		$space_id = isset( $settings['wallee_space_id'] ) && is_scalar( $settings['wallee_space_id'] ) ? (string) $settings['wallee_space_id'] : '';
		$user_id  = isset( $settings['wallee_user_id'] ) && is_scalar( $settings['wallee_user_id'] ) ? (string) $settings['wallee_user_id'] : '';
		$auth_key = isset( $settings['wallee_auth_key'] ) && is_scalar( $settings['wallee_auth_key'] ) ? (string) $settings['wallee_auth_key'] : '';

		return '' !== $space_id && ctype_digit( $space_id )
			&& '' !== $user_id && ctype_digit( $user_id )
			&& '' !== $auth_key;
	}

	/**
	 * Payment choices the visitor is allowed to use right now.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_available_methods() {
		$settings = bookzyra_get_settings();
		$methods  = array();

		if ( ! empty( $settings['pay_later_enabled'] ) ) {
			$methods[] = array(
				'id'           => 'offline',
				'type'         => 'offline',
				'label'        => ! empty( $settings['pay_later_label'] ) ? $settings['pay_later_label'] : __( 'Pay at your appointment', 'bookzyra' ),
				'description'  => __( 'No online payment is needed to request your appointment.', 'bookzyra' ),
				'instructions' => ! empty( $settings['pay_later_instructions'] ) ? $settings['pay_later_instructions'] : '',
			);
		}

		if ( ! empty( $settings['vpayments_enabled'] ) && self::is_configured() ) {
			$methods[] = array(
				'id'           => 'vpayments',
				'type'         => 'online',
				'label'        => __( 'Pay securely with Vpayments', 'bookzyra' ),
				'description'  => __( 'Continue to the secure Vpayments checkout. Available payment methods depend on your gateway account.', 'bookzyra' ),
				'instructions' => '',
			);
		}

		$methods[] = array(
			'id'           => 'free',
			'type'         => 'free',
			'label'        => __( 'No payment required', 'bookzyra' ),
			'description'  => __( 'This service does not require payment.', 'bookzyra' ),
			'instructions' => '',
		);

		if ( ! empty( $settings['custom_methods'] ) && is_array( $settings['custom_methods'] ) ) {
			foreach ( $settings['custom_methods'] as $custom ) {
				if ( ! is_array( $custom ) || ! isset( $custom['active'] ) || ! is_scalar( $custom['active'] ) || empty( $custom['active'] ) || empty( $custom['id'] ) || empty( $custom['label'] ) || ! is_scalar( $custom['id'] ) || ! is_scalar( $custom['label'] ) ) {
					continue;
				}
				$methods[] = array(
					'id'           => sanitize_key( (string) $custom['id'] ),
					'type'         => 'manual',
					'label'        => sanitize_text_field( (string) $custom['label'] ),
					'description'  => isset( $custom['description'] ) && is_scalar( $custom['description'] ) ? sanitize_text_field( (string) $custom['description'] ) : '',
					'instructions' => isset( $custom['instructions'] ) && is_scalar( $custom['instructions'] ) ? sanitize_textarea_field( (string) $custom['instructions'] ) : '',
				);
			}
		}

		return $methods;
	}

	/**
	 * Find an enabled payment method by its public ID.
	 *
	 * @param string $method_id Payment method ID.
	 * @return array<string, string>|null
	 */
	public static function get_method( $method_id ) {
		if ( ! is_scalar( $method_id ) ) {
			return null;
		}
		$method_id = sanitize_key( (string) $method_id );
		foreach ( self::get_available_methods() as $method ) {
			if ( $method['id'] === $method_id ) {
				return $method;
			}
		}
		return null;
	}

	/**
	 * Create a Wallee transaction and return its hosted payment-page URL.
	 *
	 * @param array<string, mixed> $booking Booking row, including access token.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start_checkout( $booking ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'bookzyra_gateway_not_configured', __( 'Online payments are not available right now. Please choose another payment method or contact the site owner.', 'bookzyra' ), array( 'status' => 503 ) );
		}

		$settings  = bookzyra_get_settings();
		$space_id  = absint( $settings['wallee_space_id'] );
		$service   = Bookzyra_Booking::get_service( absint( $booking['service_id'] ), false );
		$first_name = sanitize_text_field( $booking['customer_name'] );
		$last_name  = '';
		$name_parts = preg_split( '/\s+/', trim( $first_name ), 2 );
		if ( is_array( $name_parts ) && count( $name_parts ) > 1 ) {
			$first_name = $name_parts[0];
			$last_name  = $name_parts[1];
		} else {
			$last_name = $first_name;
		}

		$success_url = add_query_arg(
			array(
				'bookzyra_result'   => 'success',
				'bookzyra_booking'  => absint( $booking['id'] ),
				'bookzyra_key'      => $booking['access_token'],
			),
			home_url( '/' )
		);
		$failed_url = add_query_arg( 'bookzyra_result', 'failed', $success_url );

		$transaction = array(
			'autoConfirmationEnabled' => true,
			'currency'                => strtoupper( sanitize_text_field( $settings['currency'] ) ),
			'language'                => str_replace( '_', '-', determine_locale() ),
			'timeZone'                => wp_timezone_string(),
			'merchantReference'       => sprintf( 'Bookzyra appointment #%d', absint( $booking['id'] ) ),
			'successUrl'              => $success_url,
			'failedUrl'                => $failed_url,
			'billingAddress'          => array(
				'givenName'     => $first_name,
				'familyName'    => $last_name,
				'emailAddress'  => sanitize_email( $booking['customer_email'] ),
				'country'       => strtoupper( sanitize_text_field( $settings['billing_country'] ) ),
			),
			'lineItems'               => array(
				array(
					'name'              => $service ? sanitize_text_field( $service['name'] ) : sanitize_text_field( $booking['service_name'] ),
					'uniqueId'          => 'bookzyra-' . absint( $booking['id'] ),
					'sku'               => 'BOOKZYRA-' . absint( $booking['id'] ),
					'quantity'          => '1',
					'amountIncludingTax' => number_format( (float) $booking['service_price'], 2, '.', '' ),
					'type'              => 'PRODUCT',
				),
			),
		);

		if ( ! empty( $booking['customer_phone'] ) ) {
			$transaction['billingAddress']['phoneNumber'] = sanitize_text_field( $booking['customer_phone'] );
		}

		$created = self::api_request( 'POST', '/api/v2.0/payment/transactions', $transaction );
		if ( is_wp_error( $created ) || ! is_array( $created ) || empty( $created['id'] ) ) {
			Bookzyra_Booking::update_booking_status( absint( $booking['id'] ), 'cancelled', 'failed' );
			return new WP_Error( 'bookzyra_gateway_error', __( 'Vpayments could not start the payment. Your appointment was not confirmed. Please try again or choose another payment method.', 'bookzyra' ), array( 'status' => 502 ) );
		}

		$transaction_id = absint( $created['id'] );
		global $wpdb;
		$wpdb->update(
			Bookzyra_Booking::bookings_table(),
			array(
				'gateway_transaction_id' => $transaction_id,
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => absint( $booking['id'] ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$page_response = self::api_request( 'GET', '/api/v2.0/payment/transactions/' . $transaction_id . '/payment-page-url' );
		if ( is_wp_error( $page_response ) ) {
			Bookzyra_Booking::update_booking_status( absint( $booking['id'] ), 'cancelled', 'failed' );
			return new WP_Error( 'bookzyra_gateway_error', __( 'Vpayments could not open the secure checkout. Your appointment was not confirmed. Please contact the site owner or choose another payment method.', 'bookzyra' ), array( 'status' => 502 ) );
		}

		$payment_url = self::extract_payment_url( $page_response );
		if ( ! $payment_url ) {
			Bookzyra_Booking::update_booking_status( absint( $booking['id'] ), 'cancelled', 'failed' );
			return new WP_Error( 'bookzyra_gateway_error', __( 'Vpayments returned an invalid checkout link. Your appointment was not confirmed. Please contact the site owner.', 'bookzyra' ), array( 'status' => 502 ) );
		}

		return array(
			'transaction_id' => $transaction_id,
			'redirect_url'   => $payment_url,
		);
	}

	/**
	 * Retrieve a transaction directly from Wallee to validate webhook notifications.
	 *
	 * @param int $transaction_id Wallee transaction ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function retrieve_transaction( $transaction_id ) {
		return self::api_request( 'GET', '/api/v2.0/payment/transactions/' . absint( $transaction_id ) );
	}

	/**
	 * Make an authenticated request to the Wallee web service.
	 *
	 * @param string                $method HTTP method.
	 * @param string                $path   API path including /api/v2.0.
	 * @param array<string, mixed>|null $body Optional request body.
	 * @return array<string, mixed>|string|WP_Error
	 */
	private static function api_request( $method, $path, $body = null ) {
		$settings = bookzyra_get_settings();
		$method   = strtoupper( $method );
		$token    = self::create_jwt( $settings['wallee_user_id'], $settings['wallee_auth_key'], $path, $method );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json; charset=utf-8',
				'Space'         => (string) absint( $settings['wallee_space_id'] ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = self::API_ROOT . $path;
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bookzyra_wallee_unreachable', __( 'The payment gateway could not be reached.', 'bookzyra' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'bookzyra_wallee_rejected', __( 'The payment gateway did not accept the request.', 'bookzyra' ), array( 'status' => $code ) );
		}

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}

		return $raw;
	}

	/**
	 * Create a short-lived HS256 token for a single Wallee API request.
	 *
	 * @param string $user_id Application user ID.
	 * @param string $secret  Base64-encoded authentication key.
	 * @param string $path    Exact request path (without host).
	 * @param string $method  Uppercase HTTP method.
	 * @return string|WP_Error
	 */
	private static function create_jwt( $user_id, $secret, $path, $method ) {
		$key = base64_decode( (string) $secret, true );
		if ( false === $key || '' === $key ) {
			return new WP_Error( 'bookzyra_invalid_wallee_key', __( 'The Vpayments authentication key is invalid. Check the gateway settings.', 'bookzyra' ) );
		}

		$header  = array( 'alg' => 'HS256', 'typ' => 'JWT', 'ver' => 1 );
		$payload = array(
			'sub'          => (string) $user_id,
			'iat'          => time(),
			'requestPath'  => $path,
			'requestMethod' => strtoupper( $method ),
		);

		$encoded_header  = self::base64url_encode( wp_json_encode( $header ) );
		$encoded_payload = self::base64url_encode( wp_json_encode( $payload ) );
		$unsigned        = $encoded_header . '.' . $encoded_payload;
		$signature       = hash_hmac( 'sha256', $unsigned, $key, true );

		return $unsigned . '.' . self::base64url_encode( $signature );
	}

	/**
	 * URL-safe base64 encoding without padding.
	 *
	 * @param string $value String to encode.
	 * @return string
	 */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Find a hosted payment URL in the API's plain-text or JSON response.
	 *
	 * @param array<string, mixed>|string $response API response.
	 * @return string|false
	 */
	private static function extract_payment_url( $response ) {
		$url = '';
		if ( is_string( $response ) ) {
			$url = $response;
		} elseif ( is_array( $response ) ) {
			foreach ( array( 'paymentPageUrl', 'url', 'paymentUrl' ) as $key ) {
				if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
					$url = $response[ $key ];
					break;
				}
			}
		}

		$url = esc_url_raw( trim( $url ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return $url;
	}
}
