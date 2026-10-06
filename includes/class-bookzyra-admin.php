<?php
/**
 * WordPress admin screens for services, appointments and settings.
 *
 * @package Bookzyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bookzyra_Admin {

	/** Currency display glyphs. */
	const CURRENCY_SYMBOLS = array(
		'EUR' => '€',
		'USD' => '$',
		'GBP' => '£',
		'CHF' => 'CHF',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'JPY' => '¥',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'PLN' => 'zł',
		'CZK' => 'Kč',
	);

	/**
	 * Attach the menu, assets and admin-post handlers.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_bookzyra_save_service', array( $this, 'save_service' ) );
		add_action( 'admin_post_bookzyra_archive_service', array( $this, 'archive_service' ) );
		add_action( 'admin_post_bookzyra_restore_service', array( $this, 'restore_service' ) );
		add_action( 'admin_post_bookzyra_delete_service', array( $this, 'delete_service' ) );
		add_action( 'admin_post_bookzyra_update_booking', array( $this, 'update_booking' ) );
		add_action( 'admin_post_bookzyra_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_bookzyra_test_email', array( $this, 'send_test_email' ) );
	}

	/**
	 * Register Bookzyra's dashboard pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Bookzyra', 'bookzyra' ),
			__( 'Bookzyra', 'bookzyra' ),
			'manage_bookzyra',
			'bookzyra',
			array( $this, 'render_dashboard' ),
			'dashicons-calendar-alt',
			26
		);
		add_submenu_page( 'bookzyra', __( 'Overview', 'bookzyra' ), __( 'Overview', 'bookzyra' ), 'manage_bookzyra', 'bookzyra', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'bookzyra', __( 'Appointments', 'bookzyra' ), __( 'Appointments', 'bookzyra' ), 'manage_bookzyra', 'bookzyra-bookings', array( $this, 'render_bookings' ) );
		add_submenu_page( 'bookzyra', __( 'Services', 'bookzyra' ), __( 'Services', 'bookzyra' ), 'manage_bookzyra', 'bookzyra-services', array( $this, 'render_services' ) );
		add_submenu_page( 'bookzyra', __( 'Settings', 'bookzyra' ), __( 'Settings', 'bookzyra' ), 'manage_bookzyra', 'bookzyra-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Only load styles/scripts on plugin pages.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'bookzyra' ) ) {
			return;
		}
		wp_enqueue_style( 'bookzyra-admin', BOOKZYRA_URL . 'assets/css/admin.css', array(), BOOKZYRA_VERSION );
		wp_enqueue_script( 'bookzyra-admin', BOOKZYRA_URL . 'assets/js/admin.js', array(), BOOKZYRA_VERSION, true );
	}

	/**
	 * Dashboard overview.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->require_capability();
		global $wpdb;

		$settings       = bookzyra_get_settings();
		$bookings_table = Bookzyra_Booking::bookings_table();
		$services_table = Bookzyra_Booking::services_table();
		$timezone       = wp_timezone();
		$today          = new DateTimeImmutable( 'today', $timezone );
		$tomorrow       = $today->modify( '+1 day' );
		$now            = new DateTimeImmutable( 'now', $timezone );
		$week_end       = $now->modify( '+7 days' );

		$today_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings_table} WHERE starts_at >= %s AND starts_at < %s AND status <> 'cancelled'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today->format( 'Y-m-d H:i:s' ),
				$tomorrow->format( 'Y-m-d H:i:s' )
			)
		);
		$pending_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending_summary = $pending_count > 0
			? sprintf( _n( '%s request waiting', '%s requests waiting', $pending_count, 'bookzyra' ), number_format_i18n( $pending_count ) )
			: __( 'No requests waiting', 'bookzyra' );
		$week_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings_table} WHERE starts_at >= %s AND starts_at < %s AND status = 'confirmed'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now->format( 'Y-m-d H:i:s' ),
				$week_end->format( 'Y-m-d H:i:s' )
			)
		);
		$service_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$services_table} WHERE active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$upcoming = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$bookings_table} WHERE starts_at >= %s AND status <> 'cancelled' ORDER BY starts_at ASC LIMIT 6", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		$this->page_header( __( 'Dashboard', 'bookzyra' ), __( 'A clear view of your schedule, requests and services.', 'bookzyra' ), 'bookzyra' );
		$this->render_notice();
		?>
		<div class="bz-admin-welcome">
			<div class="bz-admin-welcome-copy"><span class="bz-admin-kicker"><?php esc_html_e( 'YOUR BOOKING DESK', 'bookzyra' ); ?></span><h2><?php echo esc_html( sprintf( __( 'Welcome to %s', 'bookzyra' ), ! empty( $settings['business_name'] ) ? $settings['business_name'] : get_bloginfo( 'name' ) ) ); ?></h2><p><?php esc_html_e( 'Services, schedules, payments and every appointment — all in one calm little workspace.', 'bookzyra' ); ?></p></div>
			<div class="bz-welcome-actions"><a class="bz-admin-button bz-admin-button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services' ) ); ?>"><span aria-hidden="true">＋</span><?php esc_html_e( 'Add a service', 'bookzyra' ); ?></a><a class="bz-admin-button bz-admin-button-light" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-settings' ) ); ?>"><?php esc_html_e( 'Booking settings', 'bookzyra' ); ?><span aria-hidden="true">↗</span></a></div>
			<div class="bz-welcome-sparkle" aria-hidden="true">✳</div>
		</div>

		<div class="bz-stat-grid">
			<div class="bz-stat-card"><span class="bz-stat-icon is-violet" aria-hidden="true">▦</span><div><span class="bz-stat-label"><?php esc_html_e( 'Appointments today', 'bookzyra' ); ?></span><strong><?php echo esc_html( number_format_i18n( $today_count ) ); ?></strong></div><span class="bz-stat-note"><?php echo esc_html( wp_date( 'D, M j', $today->getTimestamp(), $timezone ) ); ?></span></div>
			<div class="bz-stat-card"><span class="bz-stat-icon is-amber" aria-hidden="true">◷</span><div><span class="bz-stat-label"><?php esc_html_e( 'Needs your attention', 'bookzyra' ); ?></span><strong><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></strong></div><a class="bz-stat-link" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-bookings&status=pending' ) ); ?>"><?php esc_html_e( 'Review requests', 'bookzyra' ); ?> →</a></div>
			<div class="bz-stat-card"><span class="bz-stat-icon is-mint" aria-hidden="true">✓</span><div><span class="bz-stat-label"><?php esc_html_e( 'Confirmed bookings', 'bookzyra' ); ?></span><strong><?php echo esc_html( number_format_i18n( $week_count ) ); ?></strong></div><span class="bz-stat-note"><?php esc_html_e( 'Next 7 days', 'bookzyra' ); ?></span></div>
			<div class="bz-stat-card"><span class="bz-stat-icon is-blue" aria-hidden="true">✦</span><div><span class="bz-stat-label"><?php esc_html_e( 'Active services', 'bookzyra' ); ?></span><strong><?php echo esc_html( number_format_i18n( $service_count ) ); ?></strong></div><a class="bz-stat-link" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services' ) ); ?>"><?php esc_html_e( 'Manage services', 'bookzyra' ); ?> →</a></div>
		</div>

		<div class="bz-admin-columns">
			<section class="bz-admin-card bz-upcoming-card">
				<div class="bz-card-heading"><div><span class="bz-admin-kicker"><?php esc_html_e( 'YOUR SCHEDULE', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Coming up', 'bookzyra' ); ?></h2></div><a class="bz-text-link" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-bookings' ) ); ?>"><?php esc_html_e( 'View all appointments', 'bookzyra' ); ?> <span aria-hidden="true">→</span></a></div>
				<?php if ( empty( $upcoming ) ) : ?>
					<div class="bz-empty-state"><span class="bz-empty-icon" aria-hidden="true">◷</span><h3><?php esc_html_e( 'Your schedule is clear', 'bookzyra' ); ?></h3><p><?php esc_html_e( 'Upcoming appointments will show here once a client books.', 'bookzyra' ); ?></p><a class="bz-text-link" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services' ) ); ?>"><?php esc_html_e( 'Set up your first service', 'bookzyra' ); ?> →</a></div>
				<?php else : ?>
					<div class="bz-upcoming-list">
						<?php foreach ( $upcoming as $booking ) : ?>
							<div class="bz-upcoming-row"><div class="bz-date-tile"><strong><?php echo esc_html( wp_date( 'j', self::datetime_timestamp( $booking['starts_at'] ), $timezone ) ); ?></strong><span><?php echo esc_html( strtoupper( wp_date( 'M', self::datetime_timestamp( $booking['starts_at'] ), $timezone ) ) ); ?></span></div><div class="bz-upcoming-main"><strong><?php echo esc_html( $booking['customer_name'] ); ?></strong><span><?php echo esc_html( $booking['service_name'] ); ?></span></div><div class="bz-upcoming-time"><strong><?php echo esc_html( wp_date( 'g:i a', self::datetime_timestamp( $booking['starts_at'] ), $timezone ) ); ?></strong><span><?php echo esc_html( Bookzyra_Booking::format_datetime( $booking['starts_at'], 'D' ) ); ?></span></div><span class="bz-status-pill is-<?php echo esc_attr( $booking['status'] ); ?>"><?php echo esc_html( Bookzyra_Booking::status_label( $booking['status'] ) ); ?></span></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<aside class="bz-admin-card bz-setup-card">
				<?php if ( 0 === $service_count ) : ?>
					<span class="bz-setup-illustration" aria-hidden="true">✳</span><span class="bz-admin-kicker"><?php esc_html_e( 'GET STARTED', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Set up your booking desk', 'bookzyra' ); ?></h2><p><?php esc_html_e( 'Create your first service, set weekly hours, then add the booking form to a page.', 'bookzyra' ); ?></p>
					<ol class="bz-setup-list"><li><span>1</span><a href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services' ) ); ?>"><?php esc_html_e( 'Create a service', 'bookzyra' ); ?></a></li><li><span>2</span><a href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-settings' ) ); ?>#schedule"><?php esc_html_e( 'Set your availability', 'bookzyra' ); ?></a></li><li><span>3</span><span><?php esc_html_e( 'Add', 'bookzyra' ); ?> <code>[bookzyra_booking]</code> <?php esc_html_e( 'to a page', 'bookzyra' ); ?></span></li></ol>
				<?php else : ?>
					<div class="bz-admin-kicker"><?php esc_html_e( 'QUICK ACTIONS', 'bookzyra' ); ?></div><h2><?php esc_html_e( 'Keep things moving', 'bookzyra' ); ?></h2><p><?php esc_html_e( 'Jump straight to the tasks you use most.', 'bookzyra' ); ?></p>
					<div class="bz-dashboard-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-bookings&status=pending' ) ); ?>"><span class="bz-dashboard-action-icon is-amber" aria-hidden="true">◷</span><span><strong><?php esc_html_e( 'Review requests', 'bookzyra' ); ?></strong><small><?php echo esc_html( $pending_summary ); ?></small></span><span class="bz-dashboard-action-arrow" aria-hidden="true">→</span></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services' ) ); ?>"><span class="bz-dashboard-action-icon is-violet" aria-hidden="true">✦</span><span><strong><?php esc_html_e( 'Manage services', 'bookzyra' ); ?></strong><small><?php esc_html_e( 'Edit your bookable menu', 'bookzyra' ); ?></small></span><span class="bz-dashboard-action-arrow" aria-hidden="true">→</span></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-settings' ) ); ?>"><span class="bz-dashboard-action-icon is-blue" aria-hidden="true">⚙</span><span><strong><?php esc_html_e( 'Booking settings', 'bookzyra' ); ?></strong><small><?php esc_html_e( 'Hours, payments and email', 'bookzyra' ); ?></small></span><span class="bz-dashboard-action-arrow" aria-hidden="true">→</span></a>
					</div>
				<?php endif; ?>
			</aside>
		</div>
		</div>
		<?php
	}

	/**
	 * Service editor and catalogue.
	 *
	 * @return void
	 */
	public function render_services() {
		$this->require_capability();
		global $wpdb;

		$table     = Bookzyra_Booking::services_table();
		$services  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY active DESC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edit_id   = isset( $_GET['edit'] ) && is_scalar( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing   = $edit_id ? Bookzyra_Booking::get_service( $edit_id, false ) : null;
		$currency  = bookzyra_get_settings()['currency'];
		$defaults  = array( 'id' => 0, 'name' => '', 'description' => '', 'duration' => 30, 'price' => '0.00', 'color' => '#6257e8', 'active' => 1 );
		$service   = $editing ? array_merge( $defaults, $editing ) : $defaults;

		$this->page_header( __( 'Your services', 'bookzyra' ), __( 'Shape a booking menu that feels like your business.', 'bookzyra' ), 'bookzyra-services' );
		$this->render_notice();
		?>
		<div class="bz-service-layout">
			<section class="bz-admin-card bz-service-form-card">
				<div class="bz-card-heading"><div><span class="bz-admin-kicker"><?php echo $editing ? esc_html__( 'EDIT SERVICE', 'bookzyra' ) : esc_html__( 'BUILD YOUR MENU', 'bookzyra' ); ?></span><h2><?php echo $editing ? esc_html__( 'Update service', 'bookzyra' ) : esc_html__( 'Add a service', 'bookzyra' ); ?></h2></div><?php if ( $editing ) : ?><a class="bz-text-link" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services' ) ); ?>"><?php esc_html_e( '＋ New service', 'bookzyra' ); ?></a><?php endif; ?></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bz-admin-form">
					<input type="hidden" name="action" value="bookzyra_save_service"><input type="hidden" name="service[id]" value="<?php echo esc_attr( $service['id'] ); ?>">
					<?php wp_nonce_field( 'bookzyra_save_service' ); ?>
					<label class="bz-admin-field"><span><?php esc_html_e( 'Service name', 'bookzyra' ); ?> <b>*</b></span><input type="text" name="service[name]" value="<?php echo esc_attr( $service['name'] ); ?>" maxlength="190" placeholder="<?php esc_attr_e( 'e.g. Initial consultation', 'bookzyra' ); ?>" required></label>
					<label class="bz-admin-field"><span><?php esc_html_e( 'Short description', 'bookzyra' ); ?></span><textarea name="service[description]" rows="3" maxlength="1000" placeholder="<?php esc_attr_e( 'Help clients understand what this appointment includes.', 'bookzyra' ); ?>"><?php echo esc_textarea( $service['description'] ); ?></textarea><small><?php esc_html_e( 'A sentence or two works best.', 'bookzyra' ); ?></small></label>
					<div class="bz-admin-form-row"><label class="bz-admin-field"><span><?php esc_html_e( 'Duration', 'bookzyra' ); ?> <b>*</b></span><span class="bz-input-suffix"><input type="number" name="service[duration]" min="5" max="720" step="5" value="<?php echo esc_attr( absint( $service['duration'] ) ); ?>" required><em><?php esc_html_e( 'minutes', 'bookzyra' ); ?></em></span></label><label class="bz-admin-field"><span><?php esc_html_e( 'Price', 'bookzyra' ); ?></span><span class="bz-input-prefix"><i><?php echo esc_html( self::currency_symbol( $currency ) ); ?></i><input type="number" name="service[price]" min="0" max="99999999" step="0.01" value="<?php echo esc_attr( number_format( (float) $service['price'], 2, '.', '' ) ); ?>"></span></label></div>
					<label class="bz-admin-field"><span><?php esc_html_e( 'Service accent colour', 'bookzyra' ); ?></span><span class="bz-color-input"><input type="color" name="service[color]" value="<?php echo esc_attr( $service['color'] ); ?>"><small><?php esc_html_e( 'Used to identify this service in the booking form.', 'bookzyra' ); ?></small></span></label>
					<label class="bz-switch-row"><span><strong><?php esc_html_e( 'Available for booking', 'bookzyra' ); ?></strong><small><?php esc_html_e( 'Archived services stay in your past appointment records.', 'bookzyra' ); ?></small></span><input type="checkbox" name="service[active]" value="1" <?php checked( ! empty( $service['active'] ) ); ?>><span class="bz-switch" aria-hidden="true"></span></label>
					<button class="bz-admin-button bz-admin-button-primary bz-full-button" type="submit"><?php echo $editing ? esc_html__( 'Save changes', 'bookzyra' ) : esc_html__( 'Create service', 'bookzyra' ); ?><span aria-hidden="true">→</span></button>
				</form>
			</section>

			<section class="bz-service-catalogue">
				<div class="bz-catalogue-heading"><div><span class="bz-admin-kicker"><?php esc_html_e( 'BOOKABLE MENU', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Your services', 'bookzyra' ); ?> <span class="bz-count-badge"><?php echo esc_html( number_format_i18n( is_array( $services ) ? count( $services ) : 0 ) ); ?></span></h2></div><span class="bz-currency-note"><?php echo esc_html( $currency ); ?> <?php esc_html_e( 'pricing', 'bookzyra' ); ?></span></div>
				<?php if ( empty( $services ) ) : ?>
					<div class="bz-admin-card bz-empty-state"><span class="bz-empty-icon" aria-hidden="true">✦</span><h3><?php esc_html_e( 'Your service menu starts here', 'bookzyra' ); ?></h3><p><?php esc_html_e( 'Add your first service with its duration and price. It will appear automatically in the booking form.', 'bookzyra' ); ?></p></div>
				<?php else : ?>
					<div class="bz-service-list-admin">
						<?php foreach ( $services as $row ) : ?>
							<div class="bz-service-admin-card <?php echo empty( $row['active'] ) ? 'is-archived' : ''; ?>">
								<span class="bz-service-color" style="--service-color: <?php echo esc_attr( preg_match( '/^#[0-9a-fA-F]{6}$/', $row['color'] ) ? $row['color'] : '#6257e8' ); ?>"></span>
								<div class="bz-service-admin-main"><div class="bz-service-name-row"><h3><?php echo esc_html( $row['name'] ); ?></h3><?php if ( empty( $row['active'] ) ) : ?><span class="bz-archived-tag"><?php esc_html_e( 'Archived', 'bookzyra' ); ?></span><?php endif; ?></div><p><?php echo esc_html( $row['description'] ? wp_trim_words( wp_strip_all_tags( $row['description'] ), 16 ) : __( 'No description yet.', 'bookzyra' ) ); ?></p><div class="bz-service-meta"><span><b>◷</b> <?php echo esc_html( absint( $row['duration'] ) ); ?> <?php esc_html_e( 'min', 'bookzyra' ); ?></span><span><b><?php echo esc_html( self::currency_symbol( $currency ) ); ?></b> <?php echo esc_html( number_format_i18n( (float) $row['price'], 2 ) ); ?></span></div></div>
								<div class="bz-service-admin-actions">
									<a class="bz-icon-button" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-services&edit=' . absint( $row['id'] ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Edit %s', 'bookzyra' ), $row['name'] ) ); ?>" title="<?php esc_attr_e( 'Edit service', 'bookzyra' ); ?>">✎</a>
									<?php if ( ! empty( $row['active'] ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Archive this service? It will stop appearing in the booking form. Existing appointments will be kept.', 'bookzyra' ) ); ?>');">
											<input type="hidden" name="action" value="bookzyra_archive_service">
											<input type="hidden" name="service_id" value="<?php echo esc_attr( absint( $row['id'] ) ); ?>">
											<?php wp_nonce_field( 'bookzyra_archive_service_' . absint( $row['id'] ) ); ?>
											<button class="bz-service-action bz-service-action-archive" type="submit"><?php esc_html_e( 'Archive', 'bookzyra' ); ?></button>
										</form>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="bookzyra_restore_service">
											<input type="hidden" name="service_id" value="<?php echo esc_attr( absint( $row['id'] ) ); ?>">
											<?php wp_nonce_field( 'bookzyra_restore_service_' . absint( $row['id'] ) ); ?>
											<button class="bz-service-action bz-service-action-restore" type="submit"><?php esc_html_e( 'Restore', 'bookzyra' ); ?></button>
										</form>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this service? Existing appointment records will be kept. This action cannot be undone.', 'bookzyra' ) ); ?>');">
										<input type="hidden" name="action" value="bookzyra_delete_service">
										<input type="hidden" name="service_id" value="<?php echo esc_attr( absint( $row['id'] ) ); ?>">
										<?php wp_nonce_field( 'bookzyra_delete_service_' . absint( $row['id'] ) ); ?>
										<button class="bz-service-action bz-service-action-delete" type="submit"><?php esc_html_e( 'Delete', 'bookzyra' ); ?></button>
									</form>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		</div>
		<?php
	}

	/**
	 * Appointment list and status management.
	 *
	 * @return void
	 */
	public function render_bookings() {
		$this->require_capability();
		global $wpdb;

		$table  = Bookzyra_Booking::bookings_table();
		$status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] )
			? max( 1, absint( wp_unslash( $_GET['paged'] ) ) )
			: 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;
		$valid_statuses = array( 'pending', 'confirmed', 'cancelled' );
		$where = 'WHERE 1=1';
		$args  = array();
		if ( in_array( $status, $valid_statuses, true ) ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		} else {
			$status = '';
		}
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s OR service_name LIKE %s OR CAST(id AS CHAR) LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count     = (int) $wpdb->get_var( empty( $args ) ? $count_sql : $wpdb->prepare( $count_sql, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$list_sql  = "SELECT * FROM {$table} {$where} ORDER BY starts_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_args = array_merge( $args, array( $per_page, $offset ) );
		$bookings  = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pages     = max( 1, (int) ceil( $count / $per_page ) );

		$this->page_header( __( 'Appointments', 'bookzyra' ), __( 'Review requests, keep the day moving, and update payment status.', 'bookzyra' ), 'bookzyra-bookings' );
		$this->render_notice();
		?>
		<div class="bz-admin-card bz-booking-table-card">
			<div class="bz-booking-toolbar"><div class="bz-filter-tabs"><a class="<?php echo '' === $status ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=bookzyra-bookings' ) ); ?>"><?php esc_html_e( 'All appointments', 'bookzyra' ); ?><span><?php echo esc_html( number_format_i18n( $count ) ); ?></span></a><?php foreach ( array( 'pending' => __( 'Pending', 'bookzyra' ), 'confirmed' => __( 'Confirmed', 'bookzyra' ), 'cancelled' => __( 'Cancelled', 'bookzyra' ) ) as $filter_status => $filter_label ) : ?><a class="<?php echo $status === $filter_status ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'bookzyra-bookings', 'status' => $filter_status ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $filter_label ); ?></a><?php endforeach; ?></div>
				<form class="bz-booking-search" method="get"><input type="hidden" name="page" value="bookzyra-bookings"><label class="screen-reader-text" for="bz-booking-search"><?php esc_html_e( 'Search appointments', 'bookzyra' ); ?></label><input id="bz-booking-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, service or email', 'bookzyra' ); ?>"><button type="submit" aria-label="<?php esc_attr_e( 'Search', 'bookzyra' ); ?>">⌕</button></form>
			</div>
			<?php if ( empty( $bookings ) ) : ?>
				<div class="bz-empty-state"><span class="bz-empty-icon" aria-hidden="true">◷</span><h3><?php esc_html_e( 'No appointments found', 'bookzyra' ); ?></h3><p><?php esc_html_e( 'When someone books through your website, their appointment will appear here.', 'bookzyra' ); ?></p></div>
			<?php else : ?>
				<div class="bz-table-scroll"><table class="bz-bookings-table"><thead><tr><th><?php esc_html_e( 'Appointment', 'bookzyra' ); ?></th><th><?php esc_html_e( 'Client', 'bookzyra' ); ?></th><th><?php esc_html_e( 'Payment', 'bookzyra' ); ?></th><th><?php esc_html_e( 'Update status', 'bookzyra' ); ?></th></tr></thead><tbody>
				<?php foreach ( $bookings as $booking ) : ?>
					<tr><td><div class="bz-booking-date"><span class="bz-booking-day"><?php echo esc_html( Bookzyra_Booking::format_datetime( $booking['starts_at'], 'M j' ) ); ?></span><strong><?php echo esc_html( Bookzyra_Booking::format_datetime( $booking['starts_at'], 'g:i a' ) ); ?></strong></div><span class="bz-table-service"><?php echo esc_html( $booking['service_name'] ); ?></span><span class="bz-table-reference">BZ-<?php echo esc_html( str_pad( (string) absint( $booking['id'] ), 5, '0', STR_PAD_LEFT ) ); ?></span></td>
						<td><div class="bz-client-name"><?php echo esc_html( $booking['customer_name'] ); ?></div><a class="bz-client-email" href="mailto:<?php echo esc_attr( $booking['customer_email'] ); ?>"><?php echo esc_html( $booking['customer_email'] ); ?></a><?php if ( $booking['customer_phone'] ) : ?><span class="bz-client-phone"><?php echo esc_html( $booking['customer_phone'] ); ?></span><?php endif; ?><?php if ( $booking['customer_note'] ) : ?><details class="bz-client-note"><summary><?php esc_html_e( 'Client note', 'bookzyra' ); ?></summary><p><?php echo esc_html( $booking['customer_note'] ); ?></p></details><?php endif; ?></td>
						<td><span class="bz-payment-name"><?php echo esc_html( $booking['payment_label'] ); ?></span><span class="bz-status-pill is-payment-<?php echo esc_attr( $booking['payment_status'] ); ?>"><?php echo esc_html( Bookzyra_Booking::payment_status_label( $booking['payment_status'] ) ); ?></span><strong class="bz-payment-amount"><?php echo esc_html( self::format_price( $booking['service_price'], bookzyra_get_settings()['currency'] ) ); ?></strong></td>
						<td><form class="bz-row-update-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bookzyra_update_booking"><input type="hidden" name="booking_id" value="<?php echo esc_attr( absint( $booking['id'] ) ); ?>"><?php wp_nonce_field( 'bookzyra_update_booking_' . absint( $booking['id'] ) ); ?><label><span class="screen-reader-text"><?php esc_html_e( 'Appointment status', 'bookzyra' ); ?></span><select name="status"><option value="pending" <?php selected( $booking['status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'bookzyra' ); ?></option><option value="confirmed" <?php selected( $booking['status'], 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'bookzyra' ); ?></option><option value="cancelled" <?php selected( $booking['status'], 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'bookzyra' ); ?></option></select></label><label><span class="screen-reader-text"><?php esc_html_e( 'Payment status', 'bookzyra' ); ?></span><select name="payment_status"><option value="not_required" <?php selected( $booking['payment_status'], 'not_required' ); ?>><?php esc_html_e( 'No payment required', 'bookzyra' ); ?></option><option value="unpaid" <?php selected( $booking['payment_status'], 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'bookzyra' ); ?></option><option value="awaiting" <?php selected( $booking['payment_status'], 'awaiting' ); ?>><?php esc_html_e( 'Awaiting payment', 'bookzyra' ); ?></option><option value="paid" <?php selected( $booking['payment_status'], 'paid' ); ?>><?php esc_html_e( 'Paid', 'bookzyra' ); ?></option><option value="failed" <?php selected( $booking['payment_status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'bookzyra' ); ?></option></select></label><button class="bz-save-row-button" type="submit"><?php esc_html_e( 'Save', 'bookzyra' ); ?></button></form></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
			<?php if ( $pages > 1 ) : ?><div class="bz-pagination"><span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'bookzyra' ), $paged, $pages ) ); ?></span><div><?php if ( $paged > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'bookzyra-bookings', 'status' => $status, 's' => $search, 'paged' => $paged - 1 ), admin_url( 'admin.php' ) ) ); ?>">← <?php esc_html_e( 'Previous', 'bookzyra' ); ?></a><?php endif; ?><?php if ( $paged < $pages ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'bookzyra-bookings', 'status' => $status, 's' => $search, 'paged' => $paged + 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'bookzyra' ); ?> →</a><?php endif; ?></div></div><?php endif; ?>
		</div>
		</div>
		<?php
	}

	/**
	 * Booking schedule, currency, notification and payment setup.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->require_capability();
		$settings = bookzyra_get_settings();
		$test_recipient = isset( $settings['notification_email'] ) && is_scalar( $settings['notification_email'] )
			? sanitize_email( (string) $settings['notification_email'] )
			: '';
		if ( ! is_email( $test_recipient ) ) {
			$test_recipient = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		$days     = array(
			0 => __( 'Sunday', 'bookzyra' ),
			1 => __( 'Monday', 'bookzyra' ),
			2 => __( 'Tuesday', 'bookzyra' ),
			3 => __( 'Wednesday', 'bookzyra' ),
			4 => __( 'Thursday', 'bookzyra' ),
			5 => __( 'Friday', 'bookzyra' ),
			6 => __( 'Saturday', 'bookzyra' ),
		);

		$this->page_header( __( 'Booking settings', 'bookzyra' ), __( 'Set the rhythm of your appointments and how clients can pay.', 'bookzyra' ), 'bookzyra-settings' );
		$this->render_notice();
		?>
		<div class="bz-mail-test-panel">
			<div>
				<strong><?php esc_html_e( 'Booking emails', 'bookzyra' ); ?></strong>
				<p>
					<?php if ( is_email( $test_recipient ) ) : ?>
						<?php esc_html_e( 'A test email will be sent to', 'bookzyra' ); ?> <strong><?php echo esc_html( $test_recipient ); ?></strong>.
					<?php else : ?>
						<?php esc_html_e( 'Add a valid booking notification address or WordPress admin address to send a test.', 'bookzyra' ); ?>
					<?php endif; ?>
					<?php esc_html_e( 'A successful test means WordPress accepted the message; inbox delivery depends on your mail provider. If it does not arrive, configure SMTP and check spam.', 'bookzyra' ); ?>
				</p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bookzyra_test_email">
				<?php wp_nonce_field( 'bookzyra_test_email' ); ?>
				<button class="bz-admin-button bz-admin-button-light" type="submit"><?php esc_html_e( 'Send test email', 'bookzyra' ); ?></button>
			</form>
		</div>
		<form class="bz-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bookzyra_save_settings"><input type="hidden" name="settings[custom_methods_present]" value="1">
			<?php wp_nonce_field( 'bookzyra_save_settings' ); ?>

			<div class="bz-settings-intro"><span class="bz-settings-intro-icon" aria-hidden="true">⚙</span><div><strong><?php esc_html_e( 'A quick note about your schedule', 'bookzyra' ); ?></strong><p><?php esc_html_e( 'Times use the timezone set in WordPress → Settings → General. Bookzyra only offers a start time when the full service duration fits inside your opening hours.', 'bookzyra' ); ?></p></div></div>

			<section class="bz-admin-card bz-settings-card" id="general">
				<div class="bz-card-heading"><div><span class="bz-admin-kicker"><?php esc_html_e( 'THE BASICS', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Business & booking rules', 'bookzyra' ); ?></h2></div><span class="bz-card-icon" aria-hidden="true">✦</span></div>
				<div class="bz-settings-grid"><label class="bz-admin-field"><span><?php esc_html_e( 'Business name', 'bookzyra' ); ?></span><input type="text" name="settings[business_name]" value="<?php echo esc_attr( $settings['business_name'] ); ?>" maxlength="190"></label><label class="bz-admin-field"><span><?php esc_html_e( 'Appointment currency', 'bookzyra' ); ?></span><select name="settings[currency]"><?php foreach ( array_keys( self::CURRENCY_SYMBOLS ) as $currency ) : ?><option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $settings['currency'], $currency ); ?>><?php echo esc_html( $currency . ' — ' . self::currency_symbol( $currency ) ); ?></option><?php endforeach; ?></select><small><?php esc_html_e( 'Choose a currency supported by your payment provider.', 'bookzyra' ); ?></small></label><label class="bz-admin-field"><span><?php esc_html_e( 'Merchant country code', 'bookzyra' ); ?></span><input type="text" name="settings[billing_country]" value="<?php echo esc_attr( $settings['billing_country'] ); ?>" maxlength="2" placeholder="CY"><small><?php esc_html_e( 'Two-letter ISO country code used for online payment billing details.', 'bookzyra' ); ?></small></label></div>
				<div class="bz-settings-grid bz-settings-grid-four"><label class="bz-admin-field"><span><?php esc_html_e( 'Booking interval', 'bookzyra' ); ?></span><select name="settings[slot_interval]"><?php foreach ( array( 15, 20, 30, 45, 60 ) as $minutes ) : ?><option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( (int) $settings['slot_interval'], $minutes ); ?>><?php echo esc_html( sprintf( __( 'Every %d minutes', 'bookzyra' ), $minutes ) ); ?></option><?php endforeach; ?></select></label><label class="bz-admin-field"><span><?php esc_html_e( 'Minimum notice', 'bookzyra' ); ?></span><span class="bz-input-suffix"><input type="number" name="settings[min_notice_hours]" min="0" max="168" value="<?php echo esc_attr( absint( $settings['min_notice_hours'] ) ); ?>"><em><?php esc_html_e( 'hours', 'bookzyra' ); ?></em></span></label><label class="bz-admin-field"><span><?php esc_html_e( 'Booking window', 'bookzyra' ); ?></span><span class="bz-input-suffix"><input type="number" name="settings[booking_window_days]" min="1" max="365" value="<?php echo esc_attr( absint( $settings['booking_window_days'] ) ); ?>"><em><?php esc_html_e( 'days', 'bookzyra' ); ?></em></span></label><label class="bz-admin-field"><span><?php esc_html_e( 'Between appointments', 'bookzyra' ); ?></span><span class="bz-input-suffix"><input type="number" name="settings[buffer_minutes]" min="0" max="180" value="<?php echo esc_attr( absint( $settings['buffer_minutes'] ) ); ?>"><em><?php esc_html_e( 'min buffer', 'bookzyra' ); ?></em></span></label></div>
				<label class="bz-switch-row"><span><strong><?php esc_html_e( 'Automatically confirm new requests', 'bookzyra' ); ?></strong><small><?php esc_html_e( 'Turn this off if you want to approve each appointment yourself. Online payments are only marked paid after the gateway verifies them.', 'bookzyra' ); ?></small></span><input type="checkbox" name="settings[auto_confirm]" value="1" <?php checked( ! empty( $settings['auto_confirm'] ) ); ?>><span class="bz-switch" aria-hidden="true"></span></label>
			</section>

			<section class="bz-admin-card bz-settings-card" id="schedule">
				<div class="bz-card-heading"><div><span class="bz-admin-kicker"><?php esc_html_e( 'WHEN YOU’RE AVAILABLE', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Weekly opening hours', 'bookzyra' ); ?></h2></div><span class="bz-card-icon is-mint" aria-hidden="true">◷</span></div>
				<div class="bz-week-schedule"><?php foreach ( $days as $day_number => $day_name ) : $day_settings = isset( $settings['availability'][ $day_number ] ) && is_array( $settings['availability'][ $day_number ] ) ? $settings['availability'][ $day_number ] : array( 'enabled' => 0, 'start' => '09:00', 'end' => '17:00' ); ?><div class="bz-day-row"><label class="bz-day-toggle"><input type="checkbox" name="settings[availability][<?php echo esc_attr( $day_number ); ?>][enabled]" value="1" <?php checked( ! empty( $day_settings['enabled'] ) ); ?>><span class="bz-mini-switch" aria-hidden="true"></span><strong><?php echo esc_html( $day_name ); ?></strong></label><div class="bz-day-hours"><label><span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%s opens at', 'bookzyra' ), $day_name ) ); ?></span><input type="time" name="settings[availability][<?php echo esc_attr( $day_number ); ?>][start]" value="<?php echo esc_attr( $day_settings['start'] ); ?>"></label><span class="bz-hours-dash">—</span><label><span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%s closes at', 'bookzyra' ), $day_name ) ); ?></span><input type="time" name="settings[availability][<?php echo esc_attr( $day_number ); ?>][end]" value="<?php echo esc_attr( $day_settings['end'] ); ?>"></label></div></div><?php endforeach; ?></div>
			</section>

			<section class="bz-admin-card bz-settings-card" id="payments">
				<div class="bz-card-heading"><div><span class="bz-admin-kicker"><?php esc_html_e( 'GETTING PAID', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Payment methods', 'bookzyra' ); ?></h2><p><?php esc_html_e( 'Offer a hosted online checkout, collect payment in person, or add your own instructions.', 'bookzyra' ); ?></p></div><span class="bz-card-icon is-violet" aria-hidden="true">↗</span></div>
				<div class="bz-payment-admin-method">
					<div class="bz-payment-admin-heading"><div class="bz-payment-admin-logo is-offline" aria-hidden="true">◷</div><div><strong><?php esc_html_e( 'Pay at your appointment', 'bookzyra' ); ?></strong><span><?php esc_html_e( 'Built-in offline payment', 'bookzyra' ); ?></span></div><label class="bz-inline-toggle"><input type="checkbox" name="settings[pay_later_enabled]" value="1" <?php checked( ! empty( $settings['pay_later_enabled'] ) ); ?>><span><?php esc_html_e( 'Enabled', 'bookzyra' ); ?></span></label></div>
					<div class="bz-settings-grid"><label class="bz-admin-field"><span><?php esc_html_e( 'Option label', 'bookzyra' ); ?></span><input type="text" name="settings[pay_later_label]" value="<?php echo esc_attr( $settings['pay_later_label'] ); ?>" maxlength="100"></label><label class="bz-admin-field"><span><?php esc_html_e( 'Payment instructions', 'bookzyra' ); ?></span><input type="text" name="settings[pay_later_instructions]" value="<?php echo esc_attr( $settings['pay_later_instructions'] ); ?>" maxlength="500"></label></div>
				</div>

				<div class="bz-payment-admin-method bz-vpayments-method">
					<div class="bz-payment-admin-heading"><div class="bz-payment-admin-logo is-vpayments" aria-hidden="true">V</div><div><strong><?php esc_html_e( 'Vpayments online checkout', 'bookzyra' ); ?></strong><span><?php esc_html_e( 'Hosted payment page via the Wallee gateway', 'bookzyra' ); ?></span></div><label class="bz-inline-toggle"><input type="checkbox" name="settings[vpayments_enabled]" value="1" <?php checked( ! empty( $settings['vpayments_enabled'] ) ); ?>><span><?php esc_html_e( 'Enabled', 'bookzyra' ); ?></span></label></div>
					<div class="bz-vpayments-note"><span class="bz-info-mark" aria-hidden="true">i</span><p><?php esc_html_e( 'Vpayments’ online-payments page lists Wallee as a gateway. Add your Wallee Space ID, Application User ID and Authentication Key from your Vpayments/Wallee account. Bookzyra redirects customers to the hosted checkout; card details are never stored in WordPress.', 'bookzyra' ); ?> <a href="https://vpayments.com.cy/online-payment/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'About Vpayments online payments', 'bookzyra' ); ?> ↗</a></p></div>
					<div class="bz-settings-grid"><label class="bz-admin-field"><span><?php esc_html_e( 'Space ID', 'bookzyra' ); ?></span><input type="text" inputmode="numeric" name="settings[wallee_space_id]" value="<?php echo esc_attr( $settings['wallee_space_id'] ); ?>" placeholder="<?php esc_attr_e( 'Your payment space ID', 'bookzyra' ); ?>"></label><label class="bz-admin-field"><span><?php esc_html_e( 'Application User ID', 'bookzyra' ); ?></span><input type="text" inputmode="numeric" name="settings[wallee_user_id]" value="<?php echo esc_attr( $settings['wallee_user_id'] ); ?>" placeholder="<?php esc_attr_e( 'Your application user ID', 'bookzyra' ); ?>"></label><label class="bz-admin-field bz-field-span-two"><span><?php esc_html_e( 'Authentication Key', 'bookzyra' ); ?></span><input type="password" name="settings[wallee_auth_key]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( Bookzyra_Payments::is_configured() ? __( 'Saved — leave blank to keep the current key', 'bookzyra' ) : __( 'Paste the base64 authentication key', 'bookzyra' ) ); ?>"><small><?php esc_html_e( 'The key is stored privately in your WordPress options. It is never displayed after saving.', 'bookzyra' ); ?></small></label><label class="bz-clear-secret"><input type="checkbox" name="settings[clear_wallee_key]" value="1"> <?php esc_html_e( 'Remove the saved key', 'bookzyra' ); ?></label></div>
					<div class="bz-gateway-status <?php echo Bookzyra_Payments::is_configured() ? 'is-configured' : 'is-incomplete'; ?>"><span class="bz-gateway-status-dot"></span><span><?php echo Bookzyra_Payments::is_configured() ? esc_html__( 'Credentials saved', 'bookzyra' ) : esc_html__( 'Credentials needed before online checkout can be shown', 'bookzyra' ); ?></span></div>
					<div class="bz-webhook-box"><div><strong><?php esc_html_e( 'Payment status webhook', 'bookzyra' ); ?></strong><p><?php esc_html_e( 'Add this endpoint in your Wallee Space → Settings → General → Webhook URLs, then create a Transaction listener for status changes. Payment status is verified against the gateway API before Bookzyra updates an appointment.', 'bookzyra' ); ?></p></div><code><?php echo esc_html( rest_url( 'bookzyra/v1/wallee-webhook' ) ); ?></code></div>
				</div>

				<div class="bz-custom-payment-section"><div class="bz-custom-heading"><div><h3><?php esc_html_e( 'Custom payment options', 'bookzyra' ); ?></h3><p><?php esc_html_e( 'Add bank transfer, invoice, a deposit link or any payment method you manage yourself.', 'bookzyra' ); ?></p></div><button class="bz-admin-button bz-admin-button-light" type="button" data-add-payment-method><span aria-hidden="true">＋</span><?php esc_html_e( 'Add custom method', 'bookzyra' ); ?></button></div>
					<div class="bz-custom-methods" data-custom-methods><?php if ( ! empty( $settings['custom_methods'] ) && is_array( $settings['custom_methods'] ) ) : ?><?php foreach ( array_values( $settings['custom_methods'] ) as $index => $method ) : $this->render_custom_method_row( $index, $method ); endforeach; ?><?php else : ?><div class="bz-custom-empty" data-custom-empty><?php esc_html_e( 'No custom methods yet. Add one to give customers another way to pay.', 'bookzyra' ); ?></div><?php endif; ?></div>
				</div>
			</section>

			<section class="bz-admin-card bz-settings-card" id="notifications">
				<div class="bz-card-heading"><div><span class="bz-admin-kicker"><?php esc_html_e( 'STAY IN THE LOOP', 'bookzyra' ); ?></span><h2><?php esc_html_e( 'Notifications & privacy', 'bookzyra' ); ?></h2></div><span class="bz-card-icon is-blue" aria-hidden="true">✉</span></div>
				<div class="bz-settings-grid"><label class="bz-admin-field"><span><?php esc_html_e( 'Booking notification email', 'bookzyra' ); ?></span><input type="email" name="settings[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>"><small><?php esc_html_e( 'New bookings and booking updates are sent here.', 'bookzyra' ); ?></small></label><label class="bz-admin-field"><span><?php esc_html_e( 'Privacy policy URL', 'bookzyra' ); ?></span><input type="url" name="settings[privacy_url]" value="<?php echo esc_url( $settings['privacy_url'] ); ?>" placeholder="https://example.com/privacy-policy/"><small><?php esc_html_e( 'Shown beside the customer consent checkbox.', 'bookzyra' ); ?></small></label></div>
				<p class="bz-privacy-note"><span aria-hidden="true">✓</span> <?php esc_html_e( 'Bookzyra includes personal-data export and erasure tools in WordPress → Tools → Export Personal Data / Erase Personal Data.', 'bookzyra' ); ?></p>
				<label class="bz-switch-row bz-danger-switch"><span><strong><?php esc_html_e( 'Delete Bookzyra data when the plugin is uninstalled', 'bookzyra' ); ?></strong><small><?php esc_html_e( 'Off by default. Turn this on only if you want appointments, services and settings permanently removed when deleting the plugin.', 'bookzyra' ); ?></small></span><input type="checkbox" name="settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>><span class="bz-switch" aria-hidden="true"></span></label>
			</section>
			<div class="bz-settings-submit"><span><?php esc_html_e( 'Your settings are saved to this WordPress site.', 'bookzyra' ); ?></span><button class="bz-admin-button bz-admin-button-primary" type="submit"><?php esc_html_e( 'Save all settings', 'bookzyra' ); ?><span aria-hidden="true">→</span></button></div>
		</form>
		</div>
		<template id="bookzyra-custom-method-template"><?php $this->render_custom_method_row( '__index__', array( 'id' => '', 'label' => '', 'description' => '', 'instructions' => '', 'active' => 1 ) ); ?></template>
		<?php
	}

	/**
	 * Render one custom payment method editor row.
	 *
	 * @param int|string              $index   Row index.
	 * @param array<string, mixed>    $method  Method data.
	 * @return void
	 */
	private function render_custom_method_row( $index, $method ) {
		$defaults = array( 'id' => '', 'label' => '', 'description' => '', 'instructions' => '', 'active' => 1 );
		$method   = array_merge( $defaults, is_array( $method ) ? $method : array() );
		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $method[ $key ] ) || ! is_scalar( $method[ $key ] ) ) {
				$method[ $key ] = $default;
			}
		}
		?>
		<div class="bz-custom-method-row" data-custom-method-row>
			<input type="hidden" name="settings[custom_methods][<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $method['id'] ); ?>">
			<div class="bz-custom-method-row-head"><div><span class="bz-custom-method-icon" aria-hidden="true">↗</span><strong><?php echo $method['label'] ? esc_html( $method['label'] ) : esc_html__( 'New payment option', 'bookzyra' ); ?></strong></div><div class="bz-custom-row-actions"><label class="bz-mini-enabled"><input type="checkbox" name="settings[custom_methods][<?php echo esc_attr( $index ); ?>][active]" value="1" <?php checked( ! empty( $method['active'] ) ); ?>><span><?php esc_html_e( 'Enabled', 'bookzyra' ); ?></span></label><button type="button" class="bz-remove-method" data-remove-payment-method><?php esc_html_e( 'Remove', 'bookzyra' ); ?></button></div></div>
			<div class="bz-custom-fields"><label class="bz-admin-field"><span><?php esc_html_e( 'Customer-facing label', 'bookzyra' ); ?> <b>*</b></span><input type="text" name="settings[custom_methods][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $method['label'] ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Bank transfer', 'bookzyra' ); ?>"></label><label class="bz-admin-field"><span><?php esc_html_e( 'Short description', 'bookzyra' ); ?></span><input type="text" name="settings[custom_methods][<?php echo esc_attr( $index ); ?>][description]" value="<?php echo esc_attr( $method['description'] ); ?>" maxlength="160" placeholder="<?php esc_attr_e( 'e.g. Pay securely by bank transfer', 'bookzyra' ); ?>"></label><label class="bz-admin-field bz-field-span-two"><span><?php esc_html_e( 'Payment instructions', 'bookzyra' ); ?></span><textarea name="settings[custom_methods][<?php echo esc_attr( $index ); ?>][instructions]" rows="2" maxlength="800" placeholder="<?php esc_attr_e( 'Add account details or explain the next steps. These are shown to the customer after booking.', 'bookzyra' ); ?>"><?php echo esc_textarea( $method['instructions'] ); ?></textarea></label></div>
		</div>
		<?php
	}

	/**
	 * Save a service created or edited by an administrator.
	 *
	 * @return void
	 */
	public function save_service() {
		$this->require_post_request();
		$this->require_capability();
		check_admin_referer( 'bookzyra_save_service' );
		$input = isset( $_POST['service'] ) && is_array( $_POST['service'] ) ? wp_unslash( $_POST['service'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$name  = isset( $input['name'] ) ? self::safe_text( $input['name'] ) : '';
		if ( '' === $name ) {
			$this->redirect( 'bookzyra-services', 'service-error' );
		}

		$duration = isset( $input['duration'] ) ? min( 720, max( 5, self::safe_absint( $input['duration'], 30 ) ) ) : 30;
		$price    = isset( $input['price'] ) && is_scalar( $input['price'] ) && is_numeric( $input['price'] ) ? max( 0, min( 99999999.99, (float) $input['price'] ) ) : 0;
		$color    = isset( $input['color'] ) ? self::safe_text( $input['color'] ) : '';
		$color    = preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? $color : '#6257e8';
		$now      = current_time( 'mysql' );
		$data     = array(
			'name'        => $name,
			'description' => isset( $input['description'] ) ? self::safe_text( $input['description'], true ) : '',
			'duration'    => $duration,
			'price'       => number_format( $price, 2, '.', '' ),
			'color'       => $color,
			'active'      => self::is_checked_value( isset( $input['active'] ) ? $input['active'] : null ) ? 1 : 0,
			'updated_at'  => $now,
		);

		global $wpdb;
		$table = Bookzyra_Booking::services_table();
		$id    = isset( $input['id'] ) ? self::safe_absint( $input['id'] ) : 0;
		if ( $id ) {
			if ( ! Bookzyra_Booking::get_service( $id, false ) ) {
				$this->redirect( 'bookzyra-services', 'service-not-found' );
			}
			$saved  = $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' ), array( '%d' ) );
			$notice = false === $saved ? 'service-save-error' : 'service-updated';
		} else {
			$data['created_at'] = $now;
			$saved = $wpdb->insert( $table, $data, array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ) );
			$notice = false === $saved ? 'service-save-error' : 'service-created';
		}

		$this->redirect( 'bookzyra-services', $notice );
	}

	/**
	 * Archive a service without affecting existing appointments.
	 *
	 * @return void
	 */
	public function archive_service() {
		$this->require_post_request();
		$this->require_capability();
		$id = isset( $_POST['service_id'] ) && is_scalar( $_POST['service_id'] ) ? absint( wp_unslash( $_POST['service_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			$this->redirect( 'bookzyra-services', 'service-not-found' );
		}
		check_admin_referer( 'bookzyra_archive_service_' . $id );
		if ( ! Bookzyra_Booking::get_service( $id, false ) ) {
			$this->redirect( 'bookzyra-services', 'service-not-found' );
		}

		global $wpdb;
		$updated = $wpdb->update(
			Bookzyra_Booking::services_table(),
			array( 'active' => 0, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		$this->redirect( 'bookzyra-services', false === $updated ? 'service-operation-error' : 'service-archived' );
	}

	/**
	 * Restore an archived service and make it available in the public booking form.
	 *
	 * @return void
	 */
	public function restore_service() {
		$this->require_post_request();
		$this->require_capability();
		$id = isset( $_POST['service_id'] ) && is_scalar( $_POST['service_id'] ) ? absint( wp_unslash( $_POST['service_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			$this->redirect( 'bookzyra-services', 'service-not-found' );
		}
		check_admin_referer( 'bookzyra_restore_service_' . $id );
		if ( ! Bookzyra_Booking::get_service( $id, false ) ) {
			$this->redirect( 'bookzyra-services', 'service-not-found' );
		}

		global $wpdb;
		$updated = $wpdb->update(
			Bookzyra_Booking::services_table(),
			array( 'active' => 1, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		$this->redirect( 'bookzyra-services', false === $updated ? 'service-operation-error' : 'service-restored' );
	}

	/**
	 * Permanently delete a service while keeping its appointment history intact.
	 *
	 * Appointment rows store a name and price snapshot, so deleting the service
	 * does not delete or corrupt existing bookings.
	 *
	 * @return void
	 */
	public function delete_service() {
		$this->require_post_request();
		$this->require_capability();
		$id = isset( $_POST['service_id'] ) && is_scalar( $_POST['service_id'] ) ? absint( wp_unslash( $_POST['service_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			$this->redirect( 'bookzyra-services', 'service-not-found' );
		}
		check_admin_referer( 'bookzyra_delete_service_' . $id );
		if ( ! Bookzyra_Booking::get_service( $id, false ) ) {
			$this->redirect( 'bookzyra-services', 'service-not-found' );
		}

		global $wpdb;
		$deleted = $wpdb->delete( Bookzyra_Booking::services_table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			$this->redirect( 'bookzyra-services', 'service-delete-error' );
		}
		$this->redirect( 'bookzyra-services', $deleted ? 'service-deleted' : 'service-not-found' );
	}

	/**
	 * Update an appointment and its payment status.
	 *
	 * @return void
	 */
	public function update_booking() {
		$this->require_post_request();
		$this->require_capability();
		$id = isset( $_POST['booking_id'] ) && is_scalar( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			$this->redirect( 'bookzyra-bookings', 'booking-update-error' );
		}
		check_admin_referer( 'bookzyra_update_booking_' . $id );
		$status = isset( $_POST['status'] ) ? sanitize_key( self::safe_text( wp_unslash( $_POST['status'] ) ) ) : 'pending'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$payment = isset( $_POST['payment_status'] ) ? sanitize_key( self::safe_text( wp_unslash( $_POST['payment_status'] ) ) ) : 'unpaid'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! in_array( $status, array( 'pending', 'confirmed', 'cancelled' ), true ) ) {
			$status = 'pending';
		}
		if ( ! in_array( $payment, array( 'not_required', 'unpaid', 'awaiting', 'paid', 'failed' ), true ) ) {
			$payment = 'unpaid';
		}
		$updated = Bookzyra_Booking::update_booking_status( $id, $status, $payment );
		$this->redirect( 'bookzyra-bookings', $updated ? 'booking-updated' : 'booking-update-error' );
	}

	/**
	 * Sanitize and save the plugin settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		$this->require_post_request();
		$this->require_capability();
		check_admin_referer( 'bookzyra_save_settings' );
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$settings = bookzyra_get_settings();

		if ( isset( $input['business_name'] ) ) {
			$settings['business_name'] = self::safe_text( $input['business_name'] );
		}
		$currencies = array_keys( self::CURRENCY_SYMBOLS );
		if ( isset( $input['currency'] ) && in_array( strtoupper( self::safe_text( $input['currency'] ) ), $currencies, true ) ) {
			$settings['currency'] = strtoupper( self::safe_text( $input['currency'] ) );
		}
		if ( isset( $input['billing_country'] ) ) {
			$country = strtoupper( self::safe_text( $input['billing_country'] ) );
			$settings['billing_country'] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'CY';
		}
		if ( isset( $input['slot_interval'] ) ) {
			$interval = self::safe_absint( $input['slot_interval'], 30 );
			$settings['slot_interval'] = in_array( $interval, array( 15, 20, 30, 45, 60 ), true ) ? $interval : 30;
		}
		if ( isset( $input['min_notice_hours'] ) ) {
			$settings['min_notice_hours'] = min( 168, self::safe_absint( $input['min_notice_hours'] ) );
		}
		if ( isset( $input['booking_window_days'] ) ) {
			$settings['booking_window_days'] = min( 365, max( 1, self::safe_absint( $input['booking_window_days'], 60 ) ) );
		}
		if ( isset( $input['buffer_minutes'] ) ) {
			$settings['buffer_minutes'] = min( 180, self::safe_absint( $input['buffer_minutes'] ) );
		}
		$settings['auto_confirm'] = self::is_checked_value( isset( $input['auto_confirm'] ) ? $input['auto_confirm'] : null ) ? 1 : 0;

		if ( isset( $input['availability'] ) && is_array( $input['availability'] ) ) {
			$availability = array();
			for ( $day = 0; $day <= 6; $day++ ) {
				$day_input = isset( $input['availability'][ $day ] ) && is_array( $input['availability'][ $day ] ) ? $input['availability'][ $day ] : array();
				$start = isset( $day_input['start'] ) ? self::safe_text( $day_input['start'] ) : '09:00';
				$end   = isset( $day_input['end'] ) ? self::safe_text( $day_input['end'] ) : '17:00';
				$availability[ $day ] = array(
					'enabled' => self::is_checked_value( isset( $day_input['enabled'] ) ? $day_input['enabled'] : null ) ? 1 : 0,
					'start'   => self::valid_time( $start ) ? $start : '09:00',
					'end'     => self::valid_time( $end ) ? $end : '17:00',
				);
			}
			$settings['availability'] = $availability;
		}

		$settings['pay_later_enabled'] = self::is_checked_value( isset( $input['pay_later_enabled'] ) ? $input['pay_later_enabled'] : null ) ? 1 : 0;
		if ( isset( $input['pay_later_label'] ) ) {
			$settings['pay_later_label'] = self::safe_text( $input['pay_later_label'] );
		}
		if ( isset( $input['pay_later_instructions'] ) ) {
			$settings['pay_later_instructions'] = self::safe_text( $input['pay_later_instructions'], true );
		}
		$settings['vpayments_enabled'] = self::is_checked_value( isset( $input['vpayments_enabled'] ) ? $input['vpayments_enabled'] : null ) ? 1 : 0;

		if ( isset( $input['wallee_space_id'] ) ) {
			$space_id = trim( self::safe_text( $input['wallee_space_id'] ) );
			$settings['wallee_space_id'] = preg_match( '/^\d{1,18}$/', $space_id ) ? $space_id : '';
		}
		if ( isset( $input['wallee_user_id'] ) ) {
			$user_id = trim( self::safe_text( $input['wallee_user_id'] ) );
			$settings['wallee_user_id'] = preg_match( '/^\d{1,18}$/', $user_id ) ? $user_id : '';
		}
		if ( self::is_checked_value( isset( $input['clear_wallee_key'] ) ? $input['clear_wallee_key'] : null ) ) {
			$settings['wallee_auth_key'] = '';
		} elseif ( isset( $input['wallee_auth_key'] ) && '' !== trim( self::safe_text( $input['wallee_auth_key'] ) ) ) {
			$settings['wallee_auth_key'] = self::safe_text( trim( self::safe_text( $input['wallee_auth_key'] ) ) );
		}

		if ( self::is_checked_value( isset( $input['custom_methods_present'] ) ? $input['custom_methods_present'] : null ) ) {
			$methods = array();
			$seen    = array();
			if ( ! empty( $input['custom_methods'] ) && is_array( $input['custom_methods'] ) ) {
				foreach ( array_slice( $input['custom_methods'], 0, 20, true ) as $custom ) {
					if ( ! is_array( $custom ) ) {
						continue;
					}
					$label = isset( $custom['label'] ) ? self::safe_text( $custom['label'] ) : '';
					if ( '' === $label ) {
						continue;
					}
					$id = isset( $custom['id'] ) ? sanitize_key( self::safe_text( $custom['id'] ) ) : '';
					if ( '' === $id || in_array( $id, array( 'offline', 'vpayments' ), true ) || in_array( $id, $seen, true ) ) {
						$id = 'custom_' . strtolower( wp_generate_password( 8, false, false ) );
					}
					$seen[] = $id;
					$methods[] = array(
						'id'           => $id,
						'label'        => $label,
						'description'  => isset( $custom['description'] ) ? self::safe_text( $custom['description'] ) : '',
						'instructions' => isset( $custom['instructions'] ) ? self::safe_text( $custom['instructions'], true ) : '',
						'active'       => self::is_checked_value( isset( $custom['active'] ) ? $custom['active'] : null ) ? 1 : 0,
					);
				}
			}
			$settings['custom_methods'] = $methods;
		}

		if ( isset( $input['notification_email'] ) ) {
			$email = sanitize_email( self::safe_text( $input['notification_email'] ) );
			$settings['notification_email'] = is_email( $email ) ? $email : get_option( 'admin_email' );
		}
		if ( isset( $input['privacy_url'] ) ) {
			$settings['privacy_url'] = esc_url_raw( self::safe_text( $input['privacy_url'] ) );
		}
		$settings['delete_data_on_uninstall'] = self::is_checked_value( isset( $input['delete_data_on_uninstall'] ) ? $input['delete_data_on_uninstall'] : null ) ? 1 : 0;

		update_option( 'bookzyra_settings', $settings, false );
		$this->redirect( 'bookzyra-settings', 'settings-saved' );
	}

	/**
	 * Send a plain-text email to the saved booking notification address.
	 *
	 * @return void
	 */
	public function send_test_email() {
		$this->require_post_request();
		$this->require_capability();
		check_admin_referer( 'bookzyra_test_email' );

		$settings  = bookzyra_get_settings();
		$recipient = isset( $settings['notification_email'] ) && is_scalar( $settings['notification_email'] )
			? sanitize_email( (string) $settings['notification_email'] )
			: '';
		if ( ! is_email( $recipient ) ) {
			$recipient = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		if ( ! is_email( $recipient ) ) {
			$this->redirect( 'bookzyra-settings', 'email-test-invalid' );
		}

		$business = isset( $settings['business_name'] ) && is_scalar( $settings['business_name'] )
			? sanitize_text_field( (string) $settings['business_name'] )
			: get_bloginfo( 'name' );
		$subject = sprintf( __( 'Bookzyra email delivery test — %s', 'bookzyra' ), $business );
		$body    = __( 'This is a test email from Bookzyra. WordPress accepted this message for delivery.', 'bookzyra' ) . "\n\n" . home_url( '/' );
		$sent    = wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );

		$this->redirect( 'bookzyra-settings', $sent ? 'email-test-sent' : 'email-test-failed' );
	}

	/**
	 * Shared page header with local navigation.
	 *
	 * @param string $title       Screen title.
	 * @param string $description Screen summary.
	 * @param string $current     Current page slug.
	 * @return void
	 */
	private function page_header( $title, $description, $current ) {
		$tabs = array(
			'bookzyra'          => array( __( 'Overview', 'bookzyra' ), 'admin.php?page=bookzyra' ),
			'bookzyra-bookings' => array( __( 'Appointments', 'bookzyra' ), 'admin.php?page=bookzyra-bookings' ),
			'bookzyra-services' => array( __( 'Services', 'bookzyra' ), 'admin.php?page=bookzyra-services' ),
			'bookzyra-settings' => array( __( 'Settings', 'bookzyra' ), 'admin.php?page=bookzyra-settings' ),
		);
		?>
		<div class="wrap bookzyra-admin-wrap">
			<div class="bz-admin-brandbar"><div class="bz-admin-logo"><span class="bz-admin-logo-mark" aria-hidden="true"><i></i><i></i><i></i></span><span>Bookzyra</span></div><span class="bz-admin-brand-tag"><?php esc_html_e( 'APPOINTMENT WORKSPACE', 'bookzyra' ); ?></span><span class="bz-admin-today"><span aria-hidden="true"></span><?php echo esc_html( wp_date( 'D, M j, Y' ) ); ?></span></div>
			<div class="bz-admin-page-heading"><div><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div><?php if ( 'bookzyra' !== $current ) : ?><span class="bz-heading-chip"><span></span><?php esc_html_e( 'Your booking desk', 'bookzyra' ); ?></span><?php endif; ?></div>
			<nav class="bz-admin-tabs" aria-label="<?php esc_attr_e( 'Bookzyra pages', 'bookzyra' ); ?>"><?php foreach ( $tabs as $slug => $tab ) : ?><a class="<?php echo $current === $slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( $tab[1] ) ); ?>" <?php echo $current === $slug ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $tab[0] ); ?></a><?php endforeach; ?></nav>
		<?php
	}

	/**
	 * Render a safe, known admin notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		if ( ! isset( $_GET['notice'] ) || ! is_scalar( $_GET['notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'settings-saved'          => array( 'success', __( 'Settings saved. Your booking flow is up to date.', 'bookzyra' ) ),
			'service-created'         => array( 'success', __( 'Service created and ready to book.', 'bookzyra' ) ),
			'service-updated'         => array( 'success', __( 'Service updated.', 'bookzyra' ) ),
			'service-archived'        => array( 'success', __( 'Service archived. Existing appointments are untouched.', 'bookzyra' ) ),
			'service-restored'        => array( 'success', __( 'Service restored and available for booking.', 'bookzyra' ) ),
			'service-deleted'         => array( 'success', __( 'Service permanently deleted. Existing appointment records have been kept.', 'bookzyra' ) ),
			'service-error'           => array( 'error', __( 'Please add a name for this service before saving.', 'bookzyra' ) ),
			'service-not-found'       => array( 'warning', __( 'That service no longer exists. Refresh the page and try again.', 'bookzyra' ) ),
			'service-save-error'      => array( 'error', __( 'The service could not be saved. Please try again.', 'bookzyra' ) ),
			'service-operation-error' => array( 'error', __( 'The service could not be updated. Please try again.', 'bookzyra' ) ),
			'service-delete-error'    => array( 'error', __( 'The service could not be deleted. No appointment records were removed.', 'bookzyra' ) ),
			'booking-updated'         => array( 'success', __( 'Appointment status updated.', 'bookzyra' ) ),
			'booking-update-error'    => array( 'error', __( 'The appointment could not be updated. Please refresh and try again.', 'bookzyra' ) ),
			'email-test-sent'         => array( 'success', __( 'WordPress accepted the test email for delivery. Check the inbox and spam folder; actual delivery depends on your mail provider.', 'bookzyra' ) ),
			'email-test-failed'       => array( 'error', __( 'WordPress could not send the test email. Configure SMTP or a transactional email provider, then try again.', 'bookzyra' ) ),
			'email-test-invalid'      => array( 'warning', __( 'Add a valid booking notification email address before sending a test.', 'bookzyra' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			$type = $messages[ $notice ][0];
			$text = $messages[ $notice ][1];
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible bz-wp-notice"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	/**
	 * Reject state-changing requests that do not use the expected HTTP method.
	 *
	 * @return void
	 */
	private function require_post_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $method ) {
			wp_die(
				esc_html__( 'This action requires a POST request.', 'bookzyra' ),
				esc_html__( 'Method not allowed', 'bookzyra' ),
				array( 'response' => 405 )
			);
		}
	}

	/**
	 * Render a custom permission failure rather than leaking the page.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( 'manage_bookzyra' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Bookzyra.', 'bookzyra' ) );
		}
	}

	/**
	 * Redirect to a plugin screen with a fixed notice slug.
	 *
	 * @param string $page   Plugin page slug.
	 * @param string $notice Known notice key.
	 * @return void
	 */
	private function redirect( $page, $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Safely sanitize scalar input from admin forms.
	 *
	 * @param mixed $value     Input value.
	 * @param bool  $multiline Preserve line breaks.
	 * @return string
	 */
	private static function safe_text( $value, $multiline = false ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}

	/**
	 * Convert a scalar input to an absolute integer without coercing arrays.
	 *
	 * @param mixed $value   Input value.
	 * @param int   $default Value used for non-scalars.
	 * @return int
	 */
	private static function safe_absint( $value, $default = 0 ) {
		return is_scalar( $value ) ? absint( $value ) : absint( $default );
	}

	/**
	 * Check a value from a checkbox with the plugin's standard value of 1.
	 *
	 * @param mixed $value Checkbox value.
	 * @return bool
	 */
	private static function is_checked_value( $value ) {
		return is_scalar( $value ) && '1' === (string) $value;
	}

	/**
	 * Validate a 24-hour HH:MM clock value.
	 *
	 * @param string $value Input value.
	 * @return bool
	 */
	private static function valid_time( $value ) {
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Format a decimal amount with a small currency symbol map.
	 *
	 * @param mixed  $amount   Amount.
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	private static function format_price( $amount, $currency ) {
		return self::currency_symbol( $currency ) . number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * Return a safe display symbol or the currency code.
	 *
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	private static function currency_symbol( $currency ) {
		return isset( self::CURRENCY_SYMBOLS[ $currency ] ) ? self::CURRENCY_SYMBOLS[ $currency ] : sanitize_text_field( $currency );
	}

	/**
	 * Turn the local MySQL date/time into a Unix timestamp in the WordPress timezone.
	 *
	 * @param string $value Local MySQL datetime.
	 * @return int
	 */
	private static function datetime_timestamp( $value ) {
		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		return $datetime ? $datetime->getTimestamp() : time();
	}
}
