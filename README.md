# Bookzyra — Booking & Appointments for WordPress

Bookzyra is a lightweight WordPress plugin for service businesses that want a clean, guided appointment booking flow without a WooCommerce dependency. It includes a responsive front-end booking form, weekly availability, appointment management, customer email notifications, offline/custom payment choices and a hosted Vpayments checkout through its Wallee gateway.

## Highlights

- Create, archive, restore and permanently delete bookable services; deleting a service preserves existing appointment history.
- Set opening hours for each day, appointment intervals, minimum notice, booking window and a buffer between appointments.
- Show available appointment times in the WordPress site's timezone and block overlapping reservations.
- Manage pending, confirmed and cancelled appointments, plus unpaid, awaiting, paid and failed payment states.
- Collect customer details and send booking notifications to the customer and the site owner.
- Offer pay-at-appointment, a no-payment-required choice for free services, Vpayments hosted checkout and as many manually managed custom payment methods as needed.
- Vpayments checkout uses Wallee's hosted payment page: payment credentials stay server-side, card details are never stored in WordPress, and appointment payment state is verified server-to-server before being updated.
- Includes WordPress personal-data export/erasure tools. Booking data is retained on uninstall by default; optional deletion is available in settings.

## Install

1. Upload the `bookzyra` plugin folder to `wp-content/plugins/`, or zip the folder and upload it in **Plugins → Add New → Upload Plugin**.
2. Activate **Bookzyra — Booking & Appointments**.
3. Open **Bookzyra → Services** and create one or more services.
4. Open **Bookzyra → Settings** and review business details, currency and weekly availability.
5. Add the shortcode below to a WordPress page (or a Shortcode block):

   ```text
   [bookzyra_booking]
   ```

   To preselect a service by its ID, use `[bookzyra_booking service="123"]`.

6. Preview the page and make a test appointment. Customers see a thank-you screen with the booking reference and appointment details, plus an honest email hand-off status.
7. Open **Bookzyra → Settings → Booking emails** and use **Send test email** to check the site's mail setup. The test goes to the saved booking notification address (or the WordPress admin address if none is valid).

## Booking confirmations and email delivery

After a booking is saved, Bookzyra attempts to email the customer and the configured booking notification address with the reference, service, date/time, appointment and payment status, and any relevant payment instructions. The confirmation screen reports whether WordPress accepted the customer email for hand-off. For Vpayments, Bookzyra attempts an initial booking update and a further email after payment status is verified.

Bookzyra uses WordPress `wp_mail()`. A successful result means WordPress accepted the message; it does **not** guarantee arrival in the inbox. Delivery depends on the host and its mail transport, provider policies and spam filtering. If the test email does not arrive—or booking emails frequently fail—configure an SMTP or transactional email provider and check the inbox and spam folder. No email test has been performed against your live hosting environment.

## Payment methods

### Pay at your appointment

The built-in offline method can be enabled, renamed and given custom instructions in **Bookzyra → Settings → Payment methods**.

### Add your own payment method

Use **Add custom method** to add a customer-facing name, description and payment instructions—for example, bank transfer, invoice, deposit link or payment on arrival. These methods are managed manually; Bookzyra does not claim or automatically verify their payment status. Update payment status from **Bookzyra → Appointments** after checking the payment yourself.

### Vpayments hosted checkout (Wallee Gateway)

Vpayments' [online payments page](https://vpayments.com.cy/online-payment/) lists both Saferpay and the Wallee Gateway. Bookzyra's hosted-checkout adapter is for the **Wallee Gateway**; it is not a Saferpay API connector. Confirm which product is enabled on your Vpayments merchant account before configuring it.

1. Obtain a Wallee **Space ID**, **Application User ID** and **Authentication Key** for the Vpayments/Wallee space. The application user needs permission to create and read transactions.
2. In **Bookzyra → Settings**, enable Vpayments and save those credentials. The authentication key is only accepted over HTTPS on the server and is not shown again after saving.
3. Add the webhook URL displayed in Bookzyra to the Wallee Space's webhook URLs. Create a Transaction listener for transaction state changes and point it at that URL.
4. Check the currency against the currencies enabled in your Wallee space, and make a test booking using the gateway's test setup before accepting live payments.

Bookzyra creates a transaction, redirects the customer to Wallee's hosted payment page and listens for a webhook. The browser's return redirect is **not** treated as proof of payment: Bookzyra retrieves the transaction from Wallee's API and checks its state before changing the appointment payment status. Pending Vpayments reservations stop blocking a slot after 30 minutes if no payment update arrives. Configure HTTPS and the webhook in Wallee for reliable payment-state updates.

The Vpayments/Wallee payment integration uses Wallee's documented web-service API and needs PHP's standard `hash_hmac()` support and outbound HTTPS requests from WordPress. No card data is collected or stored by Bookzyra.

## Availability and appointment status

- Opening hours, slot labels and stored appointment times use the timezone selected in **WordPress → Settings → General**.
- A time is offered only if the full service duration fits within that day's hours.
- A short service-row lock is used during booking creation to reduce double-booking under concurrent requests.
- New bookings are pending for approval by default. Enable **Automatically confirm new requests** if that fits your workflow. A successful online payment is only recorded after Wallee confirms it.
- Failed/cancelled online transactions release pending appointments. Custom/offline payment status is always under the site owner's control.

## Privacy and uninstall

Booking forms collect the customer's name, email address, optional phone number and optional note. The plugin provides an exporter and eraser in **Tools → Export Personal Data** and **Tools → Erase Personal Data**. Erasure anonymizes booking contact details but retains non-personal service and payment records. Set **Delete Bookzyra data when the plugin is uninstalled** in the plugin settings only if you explicitly want both plugin tables and settings permanently deleted on uninstall. The default is to keep merchant records.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later
- MySQL/MariaDB with InnoDB recommended for concurrent slot reservations
- HTTPS strongly recommended; required for a secure live payment checkout

## Development notes

- REST namespace: `bookzyra/v1`
- Public shortcode: `[bookzyra_booking]`
- Main admin capability: `manage_bookzyra`
- No third-party front-end libraries or hosted fonts are loaded.
