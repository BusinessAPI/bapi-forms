<?php
/**
 * Help tab of the settings screen, plus the "Help" submenu link to it.
 *
 * @package BapiForms
 */

namespace BapiForms\Admin;

use BapiForms\Frontend\Shortcode;
use BapiForms\Plugin;

defined( 'ABSPATH' ) || exit;

final class HelpPage {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
	}

	public function menu(): void {
		// First submenu item shows as "Settings" instead of repeating "BusinessAPI Forms".
		add_submenu_page( SettingsPage::SLUG, __( 'BusinessAPI Forms', 'businessapi-forms' ), __( 'Settings', 'businessapi-forms' ), 'manage_options', SettingsPage::SLUG );
		// Link-only item to the Help tab of the same screen.
		add_submenu_page( SettingsPage::SLUG, __( 'BusinessAPI Forms', 'businessapi-forms' ), __( 'Help', 'businessapi-forms' ), 'manage_options', SettingsPage::SLUG . '&tab=help', '__return_null' );
	}

	public function render_content(): void {
		$plugin = $this->plugin;
		$checks = array(
			array( true === ( $plugin->options->has_key() ? ( $plugin->capabilities->status()['forms_enabled'] ?? null ) : null ), __( 'Free Business API account with "Forms on my WordPress site" onboarding submitted and the terms agreed (Dashboard > Onboarding)', 'businessapi-forms' ), \BapiForms\Account\Capabilities::REGISTER_URL ),
			array( $plugin->commerce->has_active(), __( 'An approved ecommerce plugin is active', 'businessapi-forms' ), admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ),
			array( $plugin->options->has_key(), __( 'API key saved for the current environment (copy the sandbox key from your Business API dashboard)', 'businessapi-forms' ), SettingsPage::url( array( 'tab' => 'connection' ) ) ),
			array( array() !== array_filter( $plugin->options->enabled_products() ), __( 'At least one product switched on', 'businessapi-forms' ), SettingsPage::url( array( 'tab' => 'products' ) ) ),
			array( true, __( 'ASIC notices: choose who receives them (you or the client)', 'businessapi-forms' ), SettingsPage::url( array( 'tab' => 'notices' ) ) ),
			array( array() === $plugin->products->unpriced(), __( 'Service fee above $0 for every enabled product, set on the Products tab (required before live)', 'businessapi-forms' ), SettingsPage::url( array( 'tab' => 'products' ) ) ),
			array( null === \BapiForms\Account\Sites::refused_ip(), __( 'Website and server IP registered (Connection tab), and the email confirmed for live', 'businessapi-forms' ), SettingsPage::url( array( 'tab' => 'connection' ) ) ),
			array( true, __( 'Appearance: accent colour, corners and font of the forms (optional)', 'businessapi-forms' ), SettingsPage::url( array( 'tab' => 'appearance' ) ) ),
		);
		?>
		<div class="bapi-forms-help">
			<section class="bapi-forms-panel">
				<h2><?php esc_html_e( 'Setup checklist', 'businessapi-forms' ); ?></h2>
				<ul class="bapi-forms-checklist">
					<?php foreach ( $checks as [ $done, $label, $url ] ) : ?>
						<li>
							<span class="dashicons <?php echo $done ? 'dashicons-yes-alt bapi-forms-done' : 'dashicons-marker bapi-forms-todo'; ?>" aria-hidden="true"></span>
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
							<span class="screen-reader-text"><?php echo $done ? esc_html__( '(done)', 'businessapi-forms' ) : esc_html__( '(to do)', 'businessapi-forms' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="bapi-forms-panel">
				<h2><?php esc_html_e( 'Shortcodes', 'businessapi-forms' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste a shortcode into any page or post. Visitors see the form\'s Start card when the product is switched on. In live, whether your account can lodge it is checked when they click Start (never while the page loads, so Business API can never slow your page down); if it cannot, they see "not available right now".', 'businessapi-forms' ); ?></p>
				<table class="widefat striped bapi-forms-shortcodes">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Product', 'businessapi-forms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Shortcode', 'businessapi-forms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'businessapi-forms' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $plugin->products->all() as $slug => $product ) : ?>
						<?php
						if ( $product['requires'] ) {
							continue; // Add-ons appear inside their parent form.
						}
						$code   = sprintf( '[%s product="%s"]', Shortcode::TAG, $slug );
						$active = $plugin->products->is_active( $slug );
						?>
						<tr>
							<td><?php echo esc_html( $product['label'] ); ?></td>
							<td>
								<code><?php echo esc_html( $code ); ?></code>
								<button type="button" class="button button-small bapi-forms-copy" data-copy="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copy', 'businessapi-forms' ); ?></button>
							</td>
							<td>
								<span class="bapi-forms-pill <?php echo $active ? 'bapi-forms-ok' : 'bapi-forms-muted'; ?>"><?php echo $active ? esc_html__( 'On', 'businessapi-forms' ) : esc_html__( 'Off', 'businessapi-forms' ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'The ABN add-on appears inside the company registration form when it is switched on.', 'businessapi-forms' ); ?></p>
				<p class="description"><?php esc_html_e( 'Block editor: add the "BusinessAPI Forms: Registration form" block (Widgets category) and pick the form. It shows the same as the shortcode; the editor shows a preview card only.', 'businessapi-forms' ); ?></p>
				<p class="description"><?php esc_html_e( 'Width: the form is up to 820px wide and uses your theme\'s wide width where it has one (block alignment Wide by default, or Full). With the shortcode add align="full", or align="none" to keep it inside the content column.', 'businessapi-forms' ); ?></p>
			</section>

			<div class="bapi-forms-accordion">
				<details class="bapi-forms-panel bapi-forms-acc" name="bapi-forms-help">
					<summary><h2><?php esc_html_e( 'How an order flows', 'businessapi-forms' ); ?></h2></summary>
					<ol class="bapi-forms-steps">
						<li><?php esc_html_e( 'Your client clicks "Start your application" and fills in the form on your page. Their details are saved securely with Business API, never on your website. Reloading the page reopens the same application. Each browser tab can run its own application; if the same one is open in two tabs, the older tab offers "Continue here".', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'At the end they check out in your shop and pay your price, in Australian dollars. If checkout cannot start for a moment, the form offers "Try again".', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Payment releases the application in the background, once the order is Processing or Completed, above $0, covers the government fees and, for online gateways, has a transaction id or a payment the gateway reported (a status set by hand is not a payment). Business API lodges it and bills your account.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Cancelling, fully refunding or trashing the order before release cancels the application; refunding one application\'s line and fee in full cancels just that one. If Business API already lodged it, the order note says so and it is tracked as released.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'A failed payment keeps the application so your client can retry. If your client checks out again, the application moves to the new order. An order paid after its application was cancelled gets a note and you get an email.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Each application is one cart line, quantity 1, with any add-ons merged in (e.g. "Company registration (with ABN)"), at your service fee plus add-on fees (GST applies). The government fee is a separate GST-free fee on the order; your client cannot remove or change it and coupons never touch it.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Your client sees the government fee inside the application line ("Includes: Service fee + ASIC fee"). The block cart and checkout show it as its own fee row instead. Removing the line removes the whole application; the cart notice links back to checkout.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Checkout refuses a cart whose line or fee no longer match, a currency other than AUD, and an application added before you switched between sandbox and live.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Before release the plugin checks the order against the application and the current government fee. If they do not match, the application is not released, the order gets a "price check failed" note and you get an email.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'If Business API refuses a release (IP not registered, onboarding not approved for live, product not live, an account check, no card), the application is blocked and the order note says what to fix. Fix it, then use "Retry release (BusinessAPI Forms)" in Order actions.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Temporary errors (Business API unreachable, busy or still lodging) are retried automatically for about 7 days, then blocked the same way.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Progress shows in the order notes, one note per change (e.g. "Lodged, waiting for ASIC"). Your client only sees Awaiting payment, In progress, Completed (with the ACN, ABN or ASIC document number), Action needed or Cancelled.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'If ASIC or the ATO asks for corrections, the order note says which Business API request to open in your dashboard. In sandbox an ABN is only validated by the ATO test service; no ABN is issued.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Corrections: when ASIC or the ATO refuses an application your client can fix, their order shows Action needed with an "Amend" button. "Resubmit" lodges it again with a new request ID, at no extra charge to them and without Business API billing you twice.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Amend is offered on paid orders only, is hidden while the order is on hold and ends once you refund or cancel it. Corrections not sent within 90 days are discarded. An ABN correction cannot change who the ABN is for.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Business names refused after you paid the ASIC fee: ASIC keeps that fee. The corrected application is a new lodgement with ASIC, so you pay the ASIC fee again by card for the resubmission ("Pay ASIC fee" on the same order, as for the first one). Business API does not bill you its own fee twice, but the new ASIC fee is yours to pay; decide with your client whether to recover it.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Continue later: logged-in customers see "Continue your application" on their My Account dashboard for forms they started and did not finish, which reopens them on your form page. Guests can carry on in the same browser (the form reopens on reload). An application already on an order is never reopened as a form. On a shared computer, logging out clears the form from the browser.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Documents: once an application is finished, your client downloads its documents from the order in My Account and from the "completed" email: companies get one PDF with all company documents including the certificate, ABNs an "ABN registration summary" prepared in your business name from the ATO\'s electronic answer (not an ATO or ABR document; sandbox ones are marked as a test), business names ASIC\'s certificate. You see the same links in the "Documents (BusinessAPI Forms)" box on the order. Links are on your own website (/bapi/download/...), work only for the customer who owns the order (guest orders: the link in their email or on their order page) and for your shop staff, and every file is fetched from Business API on the spot: nothing is stored on your site. Each download is recorded in the activity log.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Download links keep working while the order is not cancelled, refunded or failed and Business API still holds the document. Guest links carry the order key and a 7-day signature; after that the guest opens their order, confirms their email as WooCommerce asks, and gets fresh links. Downloads are rate limited per visitor (60 every 10 minutes).', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Client emails: "Application: action needed", "Application: completed" (numbers and download links) and, for pay first, "Application: start your application" with the form link. Sandbox emails are marked as a test. Change them under WooCommerce > Settings > Emails.', 'businessapi-forms' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'Business names: you pay ASIC\'s fee by card with "Pay ASIC fee" in the BusinessAPI Forms box on the order (link fetched fresh, never stored). You get an email when it is due, reminders after 1 and 3 days, and "Get new payment link" if it expires. Your client sees In progress, then Completed.', 'businessapi-forms' ); ?></p>
					<p class="description"><?php esc_html_e( 'Pricing (Products tab): you set only your service fees (GST applies). Government fees (ASIC company or business name fee, PPSR search fee) are charged at cost as a separate GST-free fee and cannot be changed or discounted. Each product shows your Business API cost and margin.', 'businessapi-forms' ); ?></p>
					<p class="description"><?php esc_html_e( 'Never released: applications whose line and fee total $0 (checkout refuses them; coupons of every type only discount your service fees), applications whose government fee was not paid in full (also after a partial refund of it) and cash on delivery. Bank transfer and cheque orders are only released if you switch on "Release bank transfer and cheque orders when I mark them paid" (Products tab), and then once you set them to Processing or Completed. Developers can override that per order with the bapi_forms_release_offline_payment filter.', 'businessapi-forms' ); ?></p>
					<p class="description"><?php esc_html_e( 'Developers: do_action( \'bapi_forms_release\', $ref ) releases an application and do_action( \'bapi_forms_cancel\', $ref ) cancels one, for other payment plugins. They skip every payment check above: call them only after you have confirmed the payment yourself.', 'businessapi-forms' ); ?></p>
					<p class="description"><?php esc_html_e( 'BusinessAPI Forms adds hidden products to your shop for checkout (one per fee, created on first use). They have no product page, are not indexed and are left out of your sitemap. Do not delete them. Deactivating the plugin makes them private and pauses its background jobs; activating it again picks those jobs back up.', 'businessapi-forms' ); ?></p>
				</details>

				<details class="bapi-forms-panel bapi-forms-acc" name="bapi-forms-help">
					<summary><h2><?php esc_html_e( 'Pay first', 'businessapi-forms' ); ?></h2></summary>
					<ul class="bapi-forms-bullets">
						<li><?php esc_html_e( 'Pay first: set it per product on the Products tab ("When clients pay"). The plugin adds a Registration product to your shop; link to it or use [bapi_buy product="..."] (optional choice="term=3" or choice="addons=abn", and label="Register now"). [bapi_form] for that product shows a "Buy now" card instead of the form.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Your client pays first, then gets a link (thank-you page, email, My Account) to fill in the form. It is lodged when they finish; Business API bills you only then.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Business names: 1 or 3 years is picked when buying and the form keeps it. Company: with or without ABN is picked when buying.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Not filled in yet? We remind your client after 2, 7 and 21 days and email you after 30 days (to the recipient of the "paid but not filled in" email under WooCommerce > Settings > Emails, else the site admin email). Nothing is refunded automatically: refunding or cancelling the order cancels the application.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Same payment rules as form first: the application is only created once the payment is confirmed (bank transfer and cheque only with "Release bank transfer and cheque orders" on). If the government fee goes up after your client paid, it is lodged anyway and the order note shows the difference.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Paused: while the order is on hold, failed or set back to Pending payment after it was paid, after a partial refund of the government fee, or while cancelling it keeps failing (you get an email if the plugin gives up cancelling it), Business API will not lodge it, even if your client finishes the form (they see "we have your details" and no new link opens). It resumes by itself once the order is paid and covers it again. The order notes say why.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Lines you add to an order after it was paid get no application (no payment was taken for them): sell them through checkout or a new order. If an application cannot be created, your client sees "we will be in touch" instead of a link.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'The BusinessAPI Forms box on the order shows each application\'s progress, with "Resend application link" and "Copy client link". If an application could not be created, the note says why; fix it and use "Retry release".', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Price and options of Registration products are managed here, not on the product screen. Do not delete them; switching back to form first hides them.', 'businessapi-forms' ); ?></li>
					</ul>
				</details>

				<details class="bapi-forms-panel bapi-forms-acc" name="bapi-forms-help">
					<summary><h2><?php esc_html_e( 'PPSR search', 'businessapi-forms' ); ?></h2></summary>
					<ul class="bapi-forms-bullets">
						<li><?php esc_html_e( 'Shortcode [bapi_form product="ppsr_search"]. Your client searches a vehicle by VIN, other goods by serial number (chassis, HIN, ship official number), a company by ACN, a business by ABN, or an individual (they must confirm an authorised purpose). One search per order line, at your service fee plus the PPSR search fee (GST-free, at cost).', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Payment runs the search, which usually finishes within a minute. The PPSR search certificates (one per security interest found, or one showing nothing was found) are then downloadable from the order through your site.', 'businessapi-forms' ); ?></li>
					</ul>
				</details>

				<details class="bapi-forms-panel bapi-forms-acc" name="bapi-forms-help">
					<summary><h2><?php esc_html_e( 'Entity monitoring', 'businessapi-forms' ); ?></h2></summary>
					<ul class="bapi-forms-bullets">
						<li><?php esc_html_e( 'Shortcode [bapi_form product="entity_monitoring"]. Your client enters an ACN or ABN, confirms the name from the register and buys 12 months of monitoring at your service fee (no government fee). Payment starts it in Business API, which bills you its monitoring price.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'One customer cannot buy the same company twice: a logged-in client who already monitors it (by ACN or ABN) is told so in the form and pointed to My Account > Monitoring (Resume when stopped, Renew when ended). The same company twice in one cart is refused for everyone. Other clients can still monitor a company someone else monitors, and nobody is ever told that someone else does.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Your client sees My Account > Monitoring: each company or business, its status (Active, Stopped, Ended), the date it is paid until, recent changes, and Stop, Resume and Renew buttons. Live entities also offer a PDF report, downloaded through your site.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Renewals need no subscription plugin: about 30 days before the 12 months end, the plugin creates a pending order for the same client at your current service fee and emails them a pay link (one order per period). Payment renews it in Business API for another 12 months from the current end date. Problems are noted on the order and emailed to you.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Your client is emailed when a change is found, when renewal is due (and a reminder in the last week) and when monitoring ends. Emails link to My Account; they never quote the change.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Each order is its own subscription: several of your clients can monitor the same company, and each sees, stops and renews only their own. Business API bills you for each one.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Refunding or cancelling the order that bought monitoring stops it in Business API (no refund from Business API for the time left). Unpaid renewal orders are cancelled automatically when they no longer apply, or 30 days after the period ended.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Switching monitoring off in Products stops new renewal orders. Clients keep what they paid for.', 'businessapi-forms' ); ?></li>
					</ul>
				</details>

				<details class="bapi-forms-panel bapi-forms-acc" name="bapi-forms-help">
					<summary><h2><?php esc_html_e( 'Sandbox and live', 'businessapi-forms' ); ?></h2></summary>
					<ul class="bapi-forms-bullets">
						<li><?php esc_html_e( 'Sandbox uses your test key: nothing is lodged or billed by Business API. Use your payment gateway\'s test mode to try checkout.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Sandbox works as soon as you submit the "Forms on my WordPress site" onboarding (Business API dashboard > Onboarding) with the terms agreed. Live also needs the signed agreement and Business API\'s approval.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Live uses your live key. A product can only be switched on once your Business API account can lodge it (card on file, plus ASIC or ATO onboarding for companies and ABNs) and it has a service price above $0.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Visitor limits and the trusted proxy header are on the Connection tab.', 'businessapi-forms' ); ?></li>
					</ul>
					<h2><?php esc_html_e( 'Your website and server IP', 'businessapi-forms' ); ?></h2>
					<ul class="bapi-forms-bullets">
						<li><?php esc_html_e( 'Saving a key registers this website and this server\'s IP with Business API. Use "Register this site" on the Connection tab to do it again.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Live: nothing works until your account owner clicks the confirmation link Business API emails them (registering again re-sends it, up to 3 times an hour). Live server IPs must be public addresses. Sandbox is active at once.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Moved host or the server IP changed? You will see a red notice. Register again and confirm the email.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Removing a live site also needs the owner\'s email confirmation. Until then it stays in force.', 'businessapi-forms' ); ?></li>
					</ul>
					<h2><?php esc_html_e( 'Site security (Security tab)', 'businessapi-forms' ); ?></h2>
					<ul class="bapi-forms-bullets">
						<li><?php esc_html_e( 'Two-factor authentication: before live can be switched on, every administrator and shop manager needs 2FA from a 2FA plugin (Two Factor, Wordfence Login Security, WP 2FA or Solid Security). While live, users without it cannot open BusinessAPI Forms screens, pay ASIC fees or retry releases. Sandbox is not affected.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Checklist (also in Tools > Site Health): HTTPS, PHP 8.1+, errors hidden from visitors and debug.log not downloadable are required for live. File editor off, no "admin" user, XML-RPC off, auto-updates, login limits and a security plugin are recommended.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Integrity: once a day the plugin compares its files with the official release. If a file was changed, added or removed you get a notice and an email and Business API pauses your forms. Reinstall it from WordPress.org (delete it, then Plugins > Add New), then click "Run checks now". A version Business API has not published pauses live forms only; a blocked version must be updated.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'API keys are stored encrypted with your wp-config.php security keys. If those keys change, a red notice asks you to paste the API key again. Rotate a key on the Security tab: paste the new one, it is checked with Business API, swapped in and the old one forgotten. Then revoke the old key in your dashboard. Keys set in wp-config.php (BAPI_FORMS_TEST_KEY / BAPI_FORMS_LIVE_KEY) are used as they are.', 'businessapi-forms' ); ?></li>
						<li><?php esc_html_e( 'Activity log: settings changes, key rotations, live switches, release retries, ASIC fee links, downloads and integrity failures are recorded in Business API: for staff the WordPress user, login, IP and time; for customers (downloads, amends, pay-first starts) the WordPress user id, order and application reference. Never names, emails or form data. See the Security tab and your Business API dashboard.', 'businessapi-forms' ); ?></li>
					</ul>
					<h2 id="bapi-forms-webhooks"><?php esc_html_e( 'Instant status updates (webhooks)', 'businessapi-forms' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Saving a key connects this site to Business API\'s signed status events, so orders update within a minute of a change instead of at the next scheduled check. Nothing to set up; the plugin checks the connection daily. Your site must be reachable over public HTTPS (local and staging sites behind a login cannot receive them and fall back to scheduled checks).', 'businessapi-forms' ); ?></p>
					<?php ( new \BapiForms\Webhooks\Webhooks( $plugin ) )->render_status(); ?>
					<h2><?php esc_html_e( 'Form not showing?', 'businessapi-forms' ); ?></h2>
					<p><?php esc_html_e( 'Open the page while logged in as an administrator: a red box explains what is missing. Visitors never see that message.', 'businessapi-forms' ); ?></p>
				</details>

			<details class="bapi-forms-panel bapi-forms-acc" name="bapi-forms-help" id="compatibility">
				<summary><h2><?php esc_html_e( 'Site compatibility', 'businessapi-forms' ); ?></h2></summary>
				<p class="description"><?php esc_html_e( 'BusinessAPI Forms is built to drop into an existing site. Most sites need nothing below; Tools > Site Health and the Security tab flag the first two automatically.', 'businessapi-forms' ); ?></p>
				<ul class="bapi-forms-bullets">
					<li><?php esc_html_e( 'REST API: the form calls /wp-json/bapi-forms/v1/ as a visitor (not logged in). If a security plugin or firewall blocks the REST API for visitors (e.g. Disable REST API, Perfmatters, Solid Security, All-In-One Security, WP Hide, Clearfy, Wordfence, Cloudflare or host rules), allow-list the bapi-forms/v1 namespace. Until then the form falls back to admin-ajax.php automatically, with the same checks.', 'businessapi-forms' ); ?></li>
					<li><?php esc_html_e( 'Proxy or CDN (Cloudflare, a load balancer, Sucuri, a host proxy): set Trusted proxy header on the Connection tab (CF-Connecting-IP on Cloudflare, otherwise X-Forwarded-For with Trusted proxies = how many proxies you run, usually 1). Without it every visitor shares one rate limit. Never set it when the site is not behind that proxy: visitors could fake the header.', 'businessapi-forms' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: Business API origin. */
							esc_html__( 'Content Security Policy: if your site or security plugin sends a CSP header, add %s to frame-src (or child-src). The form runs in an iframe from that address; nothing else needs allowing on your pages.', 'businessapi-forms' ),
							'<code>' . esc_html( \BapiForms\Frontend\RestController::bapi_origin() ) . '</code>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Cookie consent tools (Complianz, CookieYes, Cookiebot, Borlabs, iubenda and others) can block iframes until consent. The form iframe is strictly necessary (your client asked for the service, and it sets no tracking cookies on your site), so allow-list it or mark it as functional/necessary in the tool, otherwise the form stays blank or shows a consent placeholder.', 'businessapi-forms' ); ?></li>
					<li><?php esc_html_e( 'One web address: Business API only lets the form open on the exact website address registered on the Connection tab (https, with or without www). Redirect the other version to it (e.g. non-www to www) and use the same address in Settings > General. A site served on several domains (multilingual domains, a second shop domain) needs each one registered, or forms only work on the registered one.', 'businessapi-forms' ); ?></li>
					<li><?php esc_html_e( 'Page caching (WP Rocket, LiteSpeed, W3 Total Cache, Cloudflare, host caches): no exclusions needed. The page only holds a Start card; security tokens come from an uncached request and the form itself loads from Business API, so a cached page never serves stale or personal data. Cart, checkout and My Account stay excluded as WooCommerce already requires.', 'businessapi-forms' ); ?></li>
					<li><?php esc_html_e( '"Delay JavaScript" and Rocket Loader (WP Rocket, Perfmatters, LiteSpeed, FlyingPress, Cloudflare Rocket Loader): exclude bapi-forms.js (in wp-content/plugins/bapi-forms/assets/js/) so the Start button works at once. When it is delayed, the first click loads the script; most optimisers replay that click, otherwise the visitor clicks Start again.', 'businessapi-forms' ); ?></li>
					<li><?php esc_html_e( 'Minify and combine: safe, but if you combine scripts keep bapi-forms.js out of the combined file so updates take effect straight away.', 'businessapi-forms' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: minimum WooCommerce version. */
							esc_html__( 'WooCommerce %s or newer, with either the classic or the block cart and checkout, and either order storage (High-Performance Order Storage or posts).', 'businessapi-forms' ),
							esc_html( \BapiForms\Commerce\WooCommerceAdapter::MIN_VERSION )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Multisite: activate BusinessAPI Forms on each site that sells (not network wide). Each site needs its own Business API key and registered address.', 'businessapi-forms' ); ?></li>
				</ul>
			</details>
			</div>
		</div>
		<?php
	}
}
