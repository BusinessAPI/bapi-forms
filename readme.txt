=== BusinessAPI Forms ===
Contributors: businessapi
Tags: company registration, abn registration, business name registration, woocommerce, australia
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell Australian company, ABN and business name registrations, PPSR searches and entity monitoring in your WooCommerce shop.

== Description ==

BusinessAPI Forms is for accountants, registered agents and business service providers who want to offer Australian registrations to their clients without building forms. Add a shortcode or block to a page. Your client fills in a form hosted by Business API, then pays in your WooCommerce shop at your price.

Forms included:

* Company registration (Pty Ltd) with ASIC (Form 201), with an optional ABN add-on covering GST, PAYG withholding, FBT, LCT, FTC and WET. Your client receives the ACN, certificate of registration and company documents.
* ABN registration with the ATO for an existing company or a sole trader, with the tax registrations chosen.
* Business name registration with ASIC for 1 or 3 years. You pay the ASIC fee by card with "Pay ASIC fee" on the admin order screen.
* PPSR search reports with downloadable certificates.
* Entity monitoring: alerts on changes to a company or ABN, with yearly renewals and no subscription plugin needed.

All forms share one design: step by step, with save and resume, address autocomplete and a review page. They are checked against the same rules used at lodgement, so most problems are caught before they reach ASIC or the ATO.

How it works:

* The form runs in an iframe served by Business API. What your client types is saved with Business API, never in your WordPress database, files or logs.
* By default the client fills in the form first and pays at checkout. You can switch a product to "pay first", where the form opens after payment.
* Payment releases the application. Business API lodges it with ASIC or the ATO and bills your Business API account as it does for direct API use.
* Your client sees the status, corrections (amend and resubmit at no extra charge) and documents on the order in My Account, and gets emails when action is needed, when it is completed and, for pay first, a link to start the application. Documents are streamed from Business API through links on your own domain and are not stored on your site.
* Entity monitoring adds a Monitoring tab to My Account, and emails your client about changes, renewals and expiry.
* Government fees are added at cost as a separate GST-free fee. You set only your service fees.
* Shortcodes: `[bapi_form product="..."]` shows a form, `[bapi_buy product="..."]` a buy button for pay-first products. There is also a block.

You need a free Business API account (https://businessapi.com.au/register) with the "Forms on my WordPress site" onboarding submitted. With a sandbox key nothing is lodged or billed by Business API; use your payment gateway's test mode for checkout.

Live mode needs: HTTPS, an approved "Forms on my WordPress site" onboarding in your Business API dashboard, two-factor authentication for every administrator and shop manager, the required security checks passing, a passing plugin integrity check, a service fee above $0 for each product, and each product enabled for live on your Business API account.

WooCommerce 8.2 or newer is needed to sell. The plugin activates without it but serves no forms until WooCommerce is active. Classic and block checkout and both order storage modes (HPOS and posts) are supported.

== External services ==

This plugin is a client for Business API (https://businessapi.com.au), the service that hosts the forms and lodges applications with ASIC, the ATO and the PPSR. It does nothing useful without it.

No request is made until an administrator saves a Business API key under BusinessAPI Forms > Settings. After that, the plugin calls the Business API REST API from your server, with your secret key, to:

* check the key and your account status, read prices, and register your website address and server IP (when a key is saved, and in the background to keep the status current);
* create a form session when a visitor clicks Start, and load the hosted form from Business API in an iframe in the visitor's browser;
* release, cancel, pause and track applications, and fetch their documents and ASIC payment links, when orders are paid, refunded or changed and while they are being processed;
* manage entity monitoring and renewals, and register a webhook so Business API can notify your site of status changes;
* fetch the published file hashes for the installed version once a day and report the result of the integrity check (version and the names of changed, missing or added plugin files). Business API refuses form sessions for your site while the check fails or the installed version is blocked, and live form sessions while no hashes are published for that version;
* send an activity log, part of the Business API site security service. Staff events (settings changed, with field names only, key rotations, live switches, release retries, ASIC fee links, 2FA blocks): WordPress user id and login, IP address, time, and the order id or application reference involved. Customer events (document downloads, amends, prepaid form starts and submissions): WordPress user id, order id, application reference and document id, and time. No names, emails or form data.

What is sent: your API key, website address, server IP, the plugin and WordPress versions, opaque application references, the products and add-ons chosen, your branding settings, and, if you choose to receive ASIC notices yourself, your service email, phone and address. The details your clients enter in the form go from their browser straight to Business API inside the iframe; the plugin never sees or sends them.

Address lookups in the form use Checkify (https://checkify.com.au). They run inside the Business API hosted form, not from this plugin or your site.

Business API terms of service: https://businessapi.com.au/terms-of-service
Business API privacy policy: https://businessapi.com.au/privacy-policy

== Installation ==

1. Install and activate WooCommerce.
2. Install BusinessAPI Forms from Plugins > Add New and activate it. On multisite, each site that sells needs its own Business API key and settings.
3. Create a free account at https://businessapi.com.au/register. In the dashboard go to Onboarding, choose "Forms on my WordPress site", agree to the terms and submit. Sandbox works straight away.
4. Copy your sandbox key into BusinessAPI Forms > Settings > Connection.
5. Switch on products and set your service fees on the Products tab.
6. Add a shortcode such as `[bapi_form product="company_registration"]`, or the "BusinessAPI Forms: Registration form" block, to a page.
7. Test with the sandbox key, then follow the live checklist on the Help and Security tabs before switching to live.

== Frequently Asked Questions ==

= Is applicant data stored on my site? =

No. Orders hold only an opaque application reference, the status, request IDs and the resulting ACN, ABN or document number. For logged-in customers, unfinished forms are listed in user meta (`_bapi_forms_drafts`) as references only: application reference, product, environment, form page and time.

= The form does not load =

Open BusinessAPI Forms > Help > Site compatibility. Common causes: a firewall blocking the REST API for visitors (allow /wp-json/bapi-forms/v1/), a Content Security Policy without Business API in frame-src, a cookie consent tool blocking iframes, a script optimiser delaying bapi-forms.js, or visiting the site on an address other than the registered one (www or not).

= Do I need to exclude pages from caching? =

No. The form page only holds a Start card. Security tokens come from an uncached request.

= My site is behind Cloudflare or a load balancer =

Set the trusted proxy header on the Connection tab (CF-Connecting-IP for Cloudflare, otherwise X-Forwarded-For with the number of proxies). Only do this when every request passes through that proxy.

= Which payments release an application? =

Paid orders from an online gateway. Bank transfer and cheque orders are released only if you allow it in the settings. Cash on delivery and $0 orders are never released.

== Screenshots ==

1. Company registration form, hosted by Business API, on a partner page.
2. The ABN form on a phone.
3. Cart with one line per application and the government fee as a separate fee.
4. Application status and documents in My Account.
5. Order screen with the BusinessAPI Forms box: status, request IDs and the ASIC fee action.
6. Settings: products, service fees and the partner's Business API cost.

== Changelog ==

= 1.0.0 =
* First public release: company, ABN and business name registrations, PPSR searches and entity monitoring through Business API hosted forms, with WooCommerce checkout, pay-first and form-first flows, status tracking, amendments, document downloads, monitoring renewals and partner site security checks.
