# BusinessAPI Forms: Australian registration forms for WooCommerce

Sell ASIC company registrations, ABN and business name registrations, PPSR searches and entity monitoring on your own WordPress site. Your clients fill in hosted forms and pay at your WooCommerce checkout. [Business API](https://businessapi.com.au) then lodges with ASIC, the ATO and the PPSR.

It is built for accountants, registered agents and business service providers who want to offer registrations under their own brand, without building or maintaining forms.

## Forms included

| Form | `product=` | Lodged with | Your client receives |
|---|---|---|---|
| Company registration (Pty Ltd), with an optional ABN add-on: GST, PAYG withholding, FBT, LCT, FTC and WET | `company_registration` | ASIC (Form 201), then the ATO | ACN, certificate of registration and company documents, plus the ABN with the add-on |
| ABN registration for an existing company or a sole trader | `abn_registration` | ATO (Australian Business Register) | ABN and the tax registrations chosen |
| Business name registration, 1 or 3 years | `business_name` | ASIC | Business name registration |
| PPSR search | `ppsr_search` | PPSR | Search certificate (PDF) |
| Entity monitoring | `entity_monitoring` | Business API | Alerts on changes to a company or ABN, renewed yearly |

All forms share one design: step by step, with save and resume, address autocomplete and a review page. They validate against the same rules used at lodgement, so most problems are caught before they reach ASIC or the ATO.

## How it works

1. Add `[bapi_form product="company_registration"]`, or the BusinessAPI Forms block, to a page.
2. Your client completes the form in an iframe served by Business API.
3. The application lands in your WooCommerce cart as one line at your price. The government fee is added at cost as a separate GST-free fee.
4. Payment releases the application. Business API lodges it and bills your Business API account.
5. Status, corrections and documents appear in My Account and on the admin order screen. If ASIC or the ATO rejects an application, your client amends and resubmits it at no extra charge.

You can switch any product to pay first, where the client buys first and the form opens after payment.

## Applicant data

- What your client types goes from their browser straight to Business API. It is never stored in your WordPress database, files or logs.
- WooCommerce orders hold only an opaque application reference, the status, request IDs and the resulting ACN, ABN or document number.
- Documents are streamed through links on your own domain and never saved on your site.
- Your secret API key stays on your server, encrypted at rest.

## Requirements

- WordPress 6.5+, PHP 8.1+
- WooCommerce 8.2+ (classic or block checkout, HPOS or posts order storage)
- A free Business API account

## Getting started

1. Install the plugin and WooCommerce.
2. Create an account at [businessapi.com.au/register](https://businessapi.com.au/register). Under Onboarding, choose "Forms on my WordPress site", agree to the terms and submit. Your sandbox key works straight away.
3. Paste the key into BusinessAPI Forms > Settings > Connection.
4. Switch on products and set your service fees on the Products tab.
5. Add a form to a page and test it in sandbox. Nothing is lodged or billed.

Before you go live you need: HTTPS, an approved onboarding, two-factor authentication for every administrator and shop manager, passing security and integrity checks, and each product enabled on your Business API account. The plugin's Help and Security tabs walk you through it.

## Shortcodes

| Shortcode | Shows |
|---|---|
| `[bapi_form product="abn_registration"]` | The form, or a Buy now card for a pay-first product |
| `[bapi_buy product="business_name" choice="term=3" label="Register now"]` | A buy button for a pay-first product |

## Links

- WordPress.org listing: pending review
- Business API: https://businessapi.com.au
- Terms: https://businessapi.com.au/terms-of-service
- Privacy: https://businessapi.com.au/privacy-policy

Full details, the external services list and the FAQ are in [readme.txt](readme.txt).

Licensed under GPLv2 or later.
