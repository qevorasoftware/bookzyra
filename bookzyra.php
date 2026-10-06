<?php
/**
 * Plugin Name:       Bookzyra - Booking & Appointments
 * Plugin URI:        https://bookzyra.com/
 * Description:       A polished appointment booking system for WordPress with service schedules, booking management, custom payment instructions and VPayments (Wallee) checkout.
 * Version:           1.0.4
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Bookzyra
 * Text Domain:       bookzyra
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BOOKZYRA_VERSION', '1.0.4' );
define( 'BOOKZYRA_FILE', __FILE__ );
define( 'BOOKZYRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOOKZYRA_URL', plugin_dir_url( __FILE__ ) );

require_once BOOKZYRA_PATH . 'includes/class-bookzyra-installer.php';
require_once BOOKZYRA_PATH . 'includes/class-bookzyra-booking.php';
require_once BOOKZYRA_PATH . 'includes/class-bookzyra-payments.php';
require_once BOOKZYRA_PATH . 'includes/class-bookzyra-public.php';
require_once BOOKZYRA_PATH . 'includes/class-bookzyra-admin.php';
require_once BOOKZYRA_PATH . 'includes/class-bookzyra-privacy.php';

/**
 * Return the plugin settings merged with safe defaults.
 *
 * @return array<string, mixed>
 */
function bookzyra_get_settings() {
	$stored = get_option( 'bookzyra_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return array_merge( Bookzyra_Installer::default_settings(), $stored );
}

/**
 * Start the plugin's admin and front-end integrations.
 *
 * @return void
 */
function bookzyra_init() {
	load_plugin_textdomain( 'bookzyra', false, dirname( plugin_basename( BOOKZYRA_FILE ) ) . '/languages' );

	new Bookzyra_Public();
	new Bookzyra_Privacy();

	if ( is_admin() ) {
		new Bookzyra_Admin();
	}
}
add_action( 'plugins_loaded', 'bookzyra_init' );

register_activation_hook( BOOKZYRA_FILE, array( 'Bookzyra_Installer', 'activate' ) );
register_deactivation_hook( BOOKZYRA_FILE, array( 'Bookzyra_Installer', 'deactivate' ) );
