<?php
/**
 * Public booking widget and REST API.
 *
 * @package Bookzyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookzyra_Public {

	/**
	 * Register public hooks and routes.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_return_styles' ) );
		add_filter( 'the_content', array( $this, 'render_payment_return' ), 20 );
	}

	/**
	 * Register the booking shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'bookzyra_booking', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register the read-only booking data routes, public booking endpoint and gateway webhook.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'bookzyra/v1',
			'/services',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_services' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'bookzyra/v1',
			'/slots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_slots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'date'       => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			'bookzyra/v1',
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'bookzyra/v1',
			'/wallee-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_wallee_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Render the booking flow shell. Services and availability are loaded from REST endpoints.
	 *
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $attributes ) {
		$attributes = shortcode_atts(
			array(
				'service' => '',
			),
			$attributes,
			'bookzyra_booking'
		);

		$settings = bookzyra_get_settings();
		$this->enqueue_assets( absint( $attributes['service'] ) );
		$instance_id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'bookzyra-' ) : 'bookzyra-' . wp_rand( 1000, 999999 );
		$business    = ! empty( $settings['business_name'] ) ? $settings['business_name'] : get_bloginfo( 'name' );

		ob_start();
		?>
		<div class="bookzyra-widget" id="<?php echo esc_attr( $instance_id ); ?>" data-bookzyra-widget data-preselected-service="<?php echo esc_attr( absint( $attributes['service'] ) ); ?>">
			<div class="bz-hero">
				<div class="bz-brand-lockup">
					<span class="bz-brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
					<span class="bz-brand-name">Bookzyra</span>
				</div>
				<div class="bz-hero-copy">
					<p class="bz-eyebrow"><?php esc_html_e( 'BOOK AN APPOINTMENT', 'bookzyra' ); ?></p>
					<h2><?php echo esc_html( sprintf( __( 'Make time for %s.', 'bookzyra' ), $business ) ); ?></h2>
					<p><?php esc_html_e( 'Choose a service and a time that works for you. It only takes a moment.', 'bookzyra' ); ?></p>
				</div>
				<div class="bz-hero-orbit bz-orbit-one" aria-hidden="true"></div>
				<div class="bz-hero-orbit bz-orbit-two" aria-hidden="true"></div>
			</div>

			<div class="bz-booking-shell">
				<nav class="bz-stepper" aria-label="<?php esc_attr_e( 'Booking progress', 'bookzyra' ); ?>">
					<div class="bz-stepper-item is-current" data-step-indicator="1"><span class="bz-step-number">01</span><span><?php esc_html_e( 'Service', 'bookzyra' ); ?></span></div>
					<span class="bz-stepper-line" aria-hidden="true"></span>
					<div class="bz-stepper-item" data-step-indicator="2"><span class="bz-step-number">02</span><span><?php esc_html_e( 'Date & time', 'bookzyra' ); ?></span></div>
					<span class="bz-stepper-line" aria-hidden="true"></span>
					<div class="bz-stepper-item" data-step-indicator="3"><span class="bz-step-number">03</span><span><?php esc_html_e( 'Your details', 'bookzyra' ); ?></span></div>
				</nav>

				<div class="bz-content-grid">
					<main class="bz-flow" aria-live="polite">
						<div class="bz-alert" data-booking-alert role="alert" hidden></div>

						<section class="bz-panel is-active" data-step="1" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-services-title">
							<div class="bz-panel-heading">
								<div><p class="bz-overline"><?php esc_html_e( 'STEP 1 OF 3', 'bookzyra' ); ?></p><h3 id="<?php echo esc_attr( $instance_id ); ?>-services-title"><?php esc_html_e( 'What can we help with?', 'bookzyra' ); ?></h3></div>
								<p class="bz-small-note"><?php esc_html_e( 'Pick the service you need', 'bookzyra' ); ?></p>
							</div>
							<div class="bz-service-list" data-service-list>
								<div class="bz-loading-card"><span class="bz-spinner" aria-hidden="true"></span><?php esc_html_e( 'Loading services…', 'bookzyra' ); ?></div>
							</div>
							<div class="bz-panel-actions bz-actions-end">
								<button class="bz-button bz-button-primary" type="button" data-next-step="2" disabled><?php esc_html_e( 'Choose a time', 'bookzyra' ); ?><span aria-hidden="true">→</span></button>
							</div>
						</section>

						<section class="bz-panel" data-step="2" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-time-title" hidden>
							<div class="bz-panel-heading">
								<div><p class="bz-overline"><?php esc_html_e( 'STEP 2 OF 3', 'bookzyra' ); ?></p><h3 id="<?php echo esc_attr( $instance_id ); ?>-time-title"><?php esc_html_e( 'Find your perfect time', 'bookzyra' ); ?></h3></div>
								<p class="bz-small-note"><?php esc_html_e( 'Times shown in your local timezone', 'bookzyra' ); ?></p>
							</div>
							<div class="bz-selected-service" data-selected-service-summary></div>
							<label class="bz-field-label" for="<?php echo esc_attr( $instance_id ); ?>-date"><?php esc_html_e( 'Choose a date', 'bookzyra' ); ?></label>
							<div class="bz-date-field"><span class="bz-date-icon" aria-hidden="true">▦</span><input id="<?php echo esc_attr( $instance_id ); ?>-date" type="date" data-date-input required><span class="bz-field-hint"><?php esc_html_e( 'Select a day to see available times', 'bookzyra' ); ?></span></div>
							<div class="bz-slot-heading"><span><?php esc_html_e( 'Available times', 'bookzyra' ); ?></span><span class="bz-small-note" data-slot-date-label></span></div>
							<div class="bz-slot-grid" data-slot-list><p class="bz-placeholder-text"><?php esc_html_e( 'Choose a date to see available appointments.', 'bookzyra' ); ?></p></div>
							<div class="bz-panel-actions bz-actions-between">
								<button class="bz-button bz-button-quiet" type="button" data-prev-step="1"><span aria-hidden="true">←</span><?php esc_html_e( 'Back', 'bookzyra' ); ?></button>
								<button class="bz-button bz-button-primary" type="button" data-next-step="3" disabled><?php esc_html_e( 'Add your details', 'bookzyra' ); ?><span aria-hidden="true">→</span></button>
							</div>
						</section>

						<section class="bz-panel" data-step="3" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-details-title" hidden>
							<div class="bz-panel-heading">
								<div><p class="bz-overline"><?php esc_html_e( 'STEP 3 OF 3', 'bookzyra' ); ?></p><h3 id="<?php echo esc_attr( $instance_id ); ?>-details-title"><?php esc_html_e( 'Just a few details', 'bookzyra' ); ?></h3></div>
								<p class="bz-small-note"><?php esc_html_e( 'We’ll send your booking update by email', 'bookzyra' ); ?></p>
							</div>
							<div class="bz-selected-service bz-selected-service-compact" data-final-service-summary></div>
							<form class="bz-booking-form" data-booking-form novalidate>
								<div class="bz-form-row">
									<label class="bz-form-field"><span><?php esc_html_e( 'Full name', 'bookzyra' ); ?> <b>*</b></span><input type="text" name="name" autocomplete="name" maxlength="190" placeholder="<?php esc_attr_e( 'Your name', 'bookzyra' ); ?>" required></label>
									<label class="bz-form-field"><span><?php esc_html_e( 'Email address', 'bookzyra' ); ?> <b>*</b></span><input type="email" name="email" autocomplete="email" maxlength="190" placeholder="<?php esc_attr_e( 'you@example.com', 'bookzyra' ); ?>" required></label>
								</div>
								<label class="bz-form-field"><span><?php esc_html_e( 'Phone number', 'bookzyra' ); ?> <i><?php esc_html_e( 'Optional', 'bookzyra' ); ?></i></span><input type="tel" name="phone" autocomplete="tel" maxlength="50" placeholder="<?php esc_attr_e( 'Include your country code', 'bookzyra' ); ?>"></label>
								<label class="bz-form-field"><span><?php esc_html_e( 'Anything we should know?', 'bookzyra' ); ?> <i><?php esc_html_e( 'Optional', 'bookzyra' ); ?></i></span><textarea name="note" rows="3" maxlength="1000" placeholder="<?php esc_attr_e( 'Add a note for your appointment', 'bookzyra' ); ?>"></textarea></label>
								<div class="bz-payment-section"><div class="bz-payment-heading"><div><span class="bz-field-label"><?php esc_html_e( 'How would you like to pay?', 'bookzyra' ); ?></span><span class="bz-small-note"><?php esc_html_e( 'Choose a payment option', 'bookzyra' ); ?></span></div><span class="bz-lock-icon" aria-label="<?php esc_attr_e( 'Secure checkout', 'bookzyra' ); ?>">♢</span></div><div class="bz-payment-list" data-payment-list></div></div>
								<label class="bz-consent"><input type="checkbox" name="consent" required><span><?php esc_html_e( 'I agree to be contacted about this appointment.', 'bookzyra' ); ?><?php if ( ! empty( $settings['privacy_url'] ) ) : ?> <a href="<?php echo esc_url( $settings['privacy_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy notice', 'bookzyra' ); ?></a><?php endif; ?></span></label>
								<label class="bz-honeypot" aria-hidden="true">Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
								<div class="bz-panel-actions bz-actions-between">
									<button class="bz-button bz-button-quiet" type="button" data-prev-step="2"><span aria-hidden="true">←</span><?php esc_html_e( 'Back', 'bookzyra' ); ?></button>
									<button class="bz-button bz-button-primary" type="submit" data-submit-booking><span data-submit-label><?php esc_html_e( 'Request appointment', 'bookzyra' ); ?></span><span aria-hidden="true">→</span></button>
								</div>
							</form>
						</section>

						<section class="bz-panel bz-success-panel" data-step="success" hidden aria-live="polite"></section>
					</main>

					<aside class="bz-summary-card" aria-label="<?php esc_attr_e( 'Appointment summary', 'bookzyra' ); ?>">
						<div class="bz-summary-top"><div class="bz-summary-icon" aria-hidden="true">✦</div><div><p class="bz-overline"><?php esc_html_e( 'YOUR VISIT', 'bookzyra' ); ?></p><h3><?php esc_html_e( 'Booking summary', 'bookzyra' ); ?></h3></div></div>
						<div class="bz-summary-empty" data-summary-empty><div class="bz-summary-illustration" aria-hidden="true"><span>◷</span></div><p><?php esc_html_e( 'Your appointment details will appear here as you go.', 'bookzyra' ); ?></p></div>
						<div class="bz-summary-details" data-summary-details hidden>
							<div class="bz-summary-service"><span class="bz-summary-dot" data-summary-dot></span><div><span class="bz-summary-caption"><?php esc_html_e( 'SERVICE', 'bookzyra' ); ?></span><strong data-summary-service></strong><small data-summary-duration></small></div></div>
							<div class="bz-summary-divider"></div>
							<div class="bz-summary-row"><span class="bz-summary-icon-sm" aria-hidden="true">▦</span><div><span class="bz-summary-caption"><?php esc_html_e( 'DATE', 'bookzyra' ); ?></span><strong data-summary-date><?php esc_html_e( 'Not selected', 'bookzyra' ); ?></strong></div></div>
							<div class="bz-summary-row"><span class="bz-summary-icon-sm" aria-hidden="true">◷</span><div><span class="bz-summary-caption"><?php esc_html_e( 'TIME', 'bookzyra' ); ?></span><strong data-summary-time><?php esc_html_e( 'Not selected', 'bookzyra' ); ?></strong></div></div>
							<div class="bz-summary-total"><span><?php esc_html_e( 'Total', 'bookzyra' ); ?></span><strong data-summary-price></strong></div>
						</div>
						<div class="bz-summary-trust"><span aria-hidden="true">✓</span><p><?php esc_html_e( 'Your information is only used to manage your appointment.', 'bookzyra' ); ?></p></div>
					</aside>
				</div>
				<div class="bz-widget-footer"><span><?php esc_html_e( 'Need help? Contact us directly.', 'bookzyra' ); ?></span><span><?php esc_html_e( 'Powered by Bookzyra', 'bookzyra' ); ?></span></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Load front-end assets and localize safe public configuration.
	 *
	 * @param int $preselected_service Optional service ID from shortcode.
	 * @return void
	 */
	private function enqueue_assets( $preselected_service = 0 ) {
		wp_enqueue_style( 'bookzyra-booking', BOOKZYRA_URL . 'assets/css/frontend.css', array(), BOOKZYRA_VERSION );
		wp_enqueue_script( 'bookzyra-booking', BOOKZYRA_URL . 'assets/js/frontend.js', array(), BOOKZYRA_VERSION, true );

		$settings = bookzyra_get_settings();
		$today    = new DateTimeImmutable( 'today', wp_timezone() );
		$last_day = $today->modify( '+' . absint( $settings['booking_window_days'] ) . ' days' );

		wp_localize_script(
			'bookzyra-booking',
			'BookzyraFront',
			array(
				'apiUrl'             => esc_url_raw( rest_url( 'bookzyra/v1/' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'currency'           => sanitize_text_field( $settings['currency'] ),
				'today'              => $today->format( 'Y-m-d' ),
				'maxDate'            => $last_day->format( 'Y-m-d' ),
				'preselectedService' => absint( $preselected_service ),
				'methods'            => Bookzyra_Payments::get_available_methods(),
				'text'               => array(
					'loadFailed'       => __( 'We couldn’t load services just now. Please refresh and try again.', 'bookzyra' ),
					'noServices'       => __( 'There are no services available to book right now. Please check back soon.', 'bookzyra' ),
					'noSlots'          => __( 'No times are available on this date. Try another day.', 'bookzyra' ),
					'slotsFailed'      => __( 'We couldn’t load times for this date. Please choose the date again.', 'bookzyra' ),
				'selectService'    => __( 'Please choose a service first.', 'bookzyra' ),
				'selectTime'       => __( 'Please choose an available appointment time.', 'bookzyra' ),
				'selectPayment'    => __( 'Please choose a payment method.', 'bookzyra' ),
				'formRequired'     => __( 'Please complete your name, a valid email address and the consent checkbox.', 'bookzyra' ),
				'bookingFailed'    => __( 'We couldn’t complete your booking. Please try again.', 'bookzyra' ),
				'sending'          => __( 'Sending your request…', 'bookzyra' ),
				'requestBooking'   => __( 'Request appointment', 'bookzyra' ),
				'payNow'           => __( 'Continue to secure payment', 'bookzyra' ),
				'service'          => __( 'Service', 'bookzyra' ),
				'minute'           => __( 'min', 'bookzyra' ),
				'notSelected'      => __( 'Not selected', 'bookzyra' ),
				'free'             => __( 'Free', 'bookzyra' ),
				'bookingReceived'  => __( 'Appointment request received', 'bookzyra' ),
				'paymentPending'   => __( 'Your appointment is reserved while your secure payment is being verified. We’ll email you when it is confirmed.', 'bookzyra' ),
				'bookingReference' => __( 'Booking reference', 'bookzyra' ),
				'chooseAnother'    => __( 'Make another booking', 'bookzyra' ),
				'booked'           => __( 'Your appointment request has been received. We’ll email you with the next steps.', 'bookzyra' ),
				'processing'       => __( 'Processing…', 'bookzyra' ),
				'noPaymentMethod'  => __( 'Online payment is not available for free appointments. Please choose another method.', 'bookzyra' ),
				)
			)
		);
	}

	/**
	 * Public service catalogue.
	 *
	 * @return WP_REST_Response
	 */
	public function get_services() {
		$settings = bookzyra_get_settings();
		$services = array_map(
			static function ( $service ) {
				$color = preg_match( '/^#[0-9a-fA-F]{6}$/', $service['color'] ) ? $service['color'] : '#6257e8';
				return array(
					'id'          => absint( $service['id'] ),
					'name'        => sanitize_text_field( $service['name'] ),
					'description' => wp_strip_all_tags( $service['description'] ),
					'duration'    => absint( $service['duration'] ),
					'price'       => (float) $service['price'],
					'color'       => $color,
				);
			},
			Bookzyra_Booking::get_active_services()
		);

		return rest_ensure_response(
			array(
				'services' => $services,
				'currency' => sanitize_text_field( $settings['currency'] ),
			)
		);
	}

	/**
	 * Available appointment slots for a service/date pair.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_slots( $request ) {
		$service_id = $request->get_param( 'service_id' );
		$date       = $request->get_param( 'date' );
		$slots      = Bookzyra_Booking::get_available_slots( $service_id, $date );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		return rest_ensure_response( array( 'slots' => $slots ) );
	}

	/**
	 * Create a customer booking and, when chosen, start a hosted Vpayments checkout.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_booking( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'bookzyra_expired_form', __( 'This booking form has expired. Please refresh the page and try again.', 'bookzyra' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		if ( ! empty( $params['website'] ) ) {
			return new WP_Error( 'bookzyra_spam_detected', __( 'We could not process that request.', 'bookzyra' ), array( 'status' => 400 ) );
		}
		$consent_value = isset( $params['consent'] ) ? $params['consent'] : false;
		if ( ! in_array( $consent_value, array( true, 1, '1' ), true ) ) {
			return new WP_Error( 'bookzyra_consent_required', __( 'Please agree to be contacted about this appointment.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rate_key = 'bookzyra_rate_' . md5( $ip . wp_salt( 'nonce' ) );
		$attempts = absint( get_transient( $rate_key ) );
		if ( $attempts >= 12 ) {
			return new WP_Error( 'bookzyra_rate_limited', __( 'Too many booking attempts. Please wait a few minutes and try again.', 'bookzyra' ), array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );

		$booking = Bookzyra_Booking::create_booking(
			array(
				'service_id'     => isset( $params['service_id'] ) ? $params['service_id'] : 0,
				'date'           => isset( $params['date'] ) ? $params['date'] : '',
				'time'           => isset( $params['time'] ) ? $params['time'] : '',
				'name'           => isset( $params['name'] ) ? $params['name'] : '',
				'email'          => isset( $params['email'] ) ? $params['email'] : '',
				'phone'          => isset( $params['phone'] ) ? $params['phone'] : '',
				'note'           => isset( $params['note'] ) ? $params['note'] : '',
				'payment_method' => isset( $params['payment_method'] ) ? $params['payment_method'] : '',
			)
		);
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		if ( 'vpayments' === $booking['payment_method'] ) {
			$checkout = Bookzyra_Payments::start_checkout( $booking );
			if ( is_wp_error( $checkout ) ) {
				return $checkout;
			}
			Bookzyra_Booking::send_notification( absint( $booking['id'] ), 'created' );

			return rest_ensure_response(
				array(
					'redirect_url' => esc_url_raw( $checkout['redirect_url'] ),
					'reference'    => sprintf( 'BZ-%05d', absint( $booking['id'] ) ),
					'payment'      => true,
				)
			);
		}

		Bookzyra_Booking::send_notification( absint( $booking['id'] ), 'created' );
		$method = $booking['method'];
		$message = __( 'Your appointment request has been received. We’ll email you with the next steps.', 'bookzyra' );
		if ( 'confirmed' === $booking['status'] ) {
			$message = __( 'Your appointment is confirmed. A confirmation email is on its way.', 'bookzyra' );
		}

		return rest_ensure_response(
			array(
				'reference'    => sprintf( 'BZ-%05d', absint( $booking['id'] ) ),
				'message'      => $message,
				'instructions' => isset( $method['instructions'] ) ? $method['instructions'] : '',
				'payment'      => false,
			)
		);
	}

	/**
	 * Verify the Wallee transaction server-to-server before changing a booking.
	 * Webhook request data is only used to locate the transaction; it never marks
	 * a booking paid on its own.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_wallee_webhook( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		$transaction_id = isset( $payload['entityId'] ) && is_scalar( $payload['entityId'] ) ? absint( $payload['entityId'] ) : 0;
		$space_id       = isset( $payload['spaceId'] ) && is_scalar( $payload['spaceId'] ) ? absint( $payload['spaceId'] ) : 0;
		$settings       = bookzyra_get_settings();
		$expected_space = absint( $settings['wallee_space_id'] );

		if ( ! $transaction_id || ! $expected_space || $space_id !== $expected_space || ! Bookzyra_Payments::is_configured() ) {
			return new WP_Error( 'bookzyra_invalid_webhook', __( 'Invalid payment notification.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$booking = Bookzyra_Booking::find_by_gateway_transaction( $transaction_id );
		if ( ! $booking ) {
			return rest_ensure_response( array( 'received' => true, 'matched' => false ) );
		}

		$transaction = Bookzyra_Payments::retrieve_transaction( $transaction_id );
		if ( is_wp_error( $transaction ) ) {
			return new WP_Error( 'bookzyra_webhook_verification_failed', __( 'The payment notification could not be verified yet.', 'bookzyra' ), array( 'status' => 503 ) );
		}
		if ( ! is_array( $transaction ) || absint( isset( $transaction['id'] ) ? $transaction['id'] : 0 ) !== $transaction_id ) {
			return new WP_Error( 'bookzyra_webhook_mismatch', __( 'The payment notification did not match a transaction.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		$transaction_space = absint( isset( $transaction['linkedSpaceId'] ) ? $transaction['linkedSpaceId'] : ( isset( $transaction['spaceId'] ) ? $transaction['spaceId'] : 0 ) );
		if ( $transaction_space && $transaction_space !== $expected_space ) {
			return new WP_Error( 'bookzyra_webhook_mismatch', __( 'The payment notification did not match this payment space.', 'bookzyra' ), array( 'status' => 400 ) );
		}

		if ( isset( $transaction['state'] ) && is_scalar( $transaction['state'] ) && '' !== (string) $transaction['state'] ) {
			Bookzyra_Booking::apply_gateway_state( absint( $booking['id'] ), (string) $transaction['state'] );
		}

		return rest_ensure_response( array( 'received' => true, 'matched' => true ) );
	}

	/**
	 * Load the result-card stylesheet when a payment gateway returns to the site homepage.
	 *
	 * @return void
	 */
	public function enqueue_return_styles() {
		if ( isset( $_GET['bookzyra_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_style( 'bookzyra-booking', BOOKZYRA_URL . 'assets/css/frontend.css', array(), BOOKZYRA_VERSION );
		}
	}

	/**
	 * Show an authenticated payment result at the top of the returned page.
	 * The webhook, not the browser redirect, is the source of truth for payment status.
	 *
	 * @param string $content Existing page content.
	 * @return string
	 */
	public function render_payment_return( $content ) {
		if ( ! isset( $_GET['bookzyra_result'], $_GET['bookzyra_booking'], $_GET['bookzyra_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $content;
		}
		if ( ! is_scalar( $_GET['bookzyra_booking'] ) || ! is_scalar( $_GET['bookzyra_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $content;
		}

		$booking_id = absint( wp_unslash( $_GET['bookzyra_booking'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token      = sanitize_text_field( wp_unslash( $_GET['bookzyra_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$booking    = Bookzyra_Booking::get_booking_by_token( $booking_id, $token );
		if ( ! $booking ) {
			return '<section class="bookzyra-result bookzyra-result-error"><span class="bz-result-icon">!</span><div><h2>' . esc_html__( 'We couldn’t verify this booking link.', 'bookzyra' ) . '</h2><p>' . esc_html__( 'Please check your booking email or contact the site owner for help.', 'bookzyra' ) . '</p></div></section>' . $content;
		}

		// The return page may arrive before the webhook. Fetch the transaction from Wallee;
		// never trust the browser's success/failed query value as payment confirmation.
		if ( 'vpayments' === $booking['payment_method'] && 'awaiting' === $booking['payment_status'] && ! empty( $booking['gateway_transaction_id'] ) ) {
			$transaction = Bookzyra_Payments::retrieve_transaction( absint( $booking['gateway_transaction_id'] ) );
			if ( is_array( $transaction ) && absint( isset( $transaction['id'] ) ? $transaction['id'] : 0 ) === absint( $booking['gateway_transaction_id'] ) && ! empty( $transaction['state'] ) ) {
				Bookzyra_Booking::apply_gateway_state( absint( $booking['id'] ), $transaction['state'] );
				$booking = Bookzyra_Booking::get_booking( $booking_id );
			}
		}

		$reference = sprintf( 'BZ-%05d', absint( $booking['id'] ) );
		if ( 'paid' === $booking['payment_status'] ) {
			$title   = __( 'Payment confirmed', 'bookzyra' );
			$message = __( 'Thank you. Your payment has been verified and your appointment status has been updated.', 'bookzyra' );
			$icon    = '✓';
			$class   = 'is-success';
		} elseif ( 'failed' === $booking['payment_status'] || 'cancelled' === $booking['status'] ) {
			$title   = __( 'Payment not completed', 'bookzyra' );
			$message = __( 'Your payment was not completed. Your appointment time is no longer held. Please contact us if you would like to try again.', 'bookzyra' );
			$icon    = '!';
			$class   = 'is-error';
		} else {
			$title   = __( 'Payment is being verified', 'bookzyra' );
			$message = __( 'Your appointment is reserved while we confirm the payment. We’ll send you an email when the status is updated.', 'bookzyra' );
			$icon    = '…';
			$class   = 'is-pending';
		}

		$html  = '<section class="bookzyra-result ' . esc_attr( $class ) . '">';
		$html .= '<span class="bz-result-icon" aria-hidden="true">' . esc_html( $icon ) . '</span><div><p class="bz-result-eyebrow">' . esc_html__( 'BOOKZYRA PAYMENT UPDATE', 'bookzyra' ) . '</p>';
		$html .= '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $message ) . '</p><span class="bz-result-reference">' . esc_html__( 'Booking reference', 'bookzyra' ) . ': <strong>' . esc_html( $reference ) . '</strong></span></div></section>';

		return $html . $content;
	}
}
