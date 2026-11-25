<?php
/**
 * Settings screen. Each tab posts only its own fields, so saving one never touches another.
 *
 * @package BapiForms
 */

namespace BapiForms\Admin;

use BapiForms\Products\PriceList;
use BapiForms\Account\Sites;
use BapiForms\Frontend\Shortcode;
use BapiForms\Plugin;
use BapiForms\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'bapi-forms';

	private const SAVE_ACTION    = 'bapi_forms_save_settings';
	private const REFRESH_ACTION = 'bapi_forms_refresh_status';
	private const SITE_ACTION    = 'bapi_forms_register_site';

	private const PRODUCT_ICONS = array(
		'company_registration' => 'dashicons-building',
		'abn_addon'            => 'dashicons-plus-alt',
		'abn_registration'     => 'dashicons-id',
		'business_name'        => 'dashicons-store',
		'ppsr_search'          => 'dashicons-search',
		'entity_monitoring'    => 'dashicons-visibility',
	);

	public function __construct( private readonly Plugin $plugin ) {}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @return array<string, string> Tab slug => label.
	 */
	public static function tabs(): array {
		return array(
			'connection' => __( 'Connection', 'businessapi-forms' ),
			'products'   => __( 'Products', 'businessapi-forms' ),
			'notices'    => __( 'ASIC notices', 'businessapi-forms' ),
			'appearance' => __( 'Appearance', 'businessapi-forms' ),
			'security'   => __( 'Security', 'businessapi-forms' ),
			'help'       => __( 'Help', 'businessapi-forms' ),
		);
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'refresh' ) );
		add_action( 'admin_post_' . self::SITE_ACTION, array( $this, 'register_site' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BAPI_FORMS_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'BusinessAPI Forms', 'businessapi-forms' ),
			__( 'BusinessAPI Forms', 'businessapi-forms' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-building',
			56
		);
	}

	/** Styles and script of this screen only, plus the colour picker on the Appearance tab. */
	public function enqueue(): void {
		if ( self::SLUG !== ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$deps = array();
		if ( 'appearance' === $this->current_tab() ) {
			wp_enqueue_style( 'wp-color-picker' );
			$deps = array( 'jquery', 'wp-color-picker' );
		}
		wp_enqueue_style( 'bapi-forms-admin', BAPI_FORMS_URL . 'assets/css/admin.css', array(), BAPI_FORMS_VERSION );
		wp_enqueue_script( 'bapi-forms-admin', BAPI_FORMS_URL . 'assets/js/admin.js', $deps, BAPI_FORMS_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'bapi-forms-admin',
			'bapiFormsAdmin',
			array(
				/* translators: 1: "White" or "Dark", 2: APCA contrast value, 3: the other text colour. */
				'lowText'   => __( '%1$s text is hard to read on this colour (APCA Lc %2$s, under 45). Consider %3$s text or a different accent colour.', 'businessapi-forms' ),
				/* translators: %s: contrast ratio. */
				'fillText'  => __( 'This colour is light on white (contrast %s:1, under 3:1), so buttons, selected options and focus rings may be hard to see. Text in the accent colour (links, step names) is darkened automatically to stay readable.', 'businessapi-forms' ),
				'white'     => Options::WHITE_TEXT,
				'dark'      => Options::DARK_TEXT,
				'whiteName' => __( 'White', 'businessapi-forms' ),
				'darkName'  => __( 'Dark', 'businessapi-forms' ),
				'bias'      => Options::APCA_WHITE_BIAS,
				'copied'    => __( 'Copied', 'businessapi-forms' ),
			)
		);
	}

	public function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'businessapi-forms' ) ) );
		return $links;
	}

	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::tabs()[ $tab ] ) ? $tab : 'connection';
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		check_admin_referer( self::SAVE_ACTION );

		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$errors = match ( $tab ) {
			'connection' => $this->save_connection(),
			'products'   => $this->save_products(),
			'notices'    => $this->save_notices(),
			'appearance' => $this->save_appearance(),
			default      => array(),
		};

		set_transient( 'bapi_forms_notice_' . get_current_user_id(), array( 'errors' => $errors ), 60 );
		wp_safe_redirect(
			self::url(
				array(
					'tab'     => '' !== (string) $tab ? $tab : 'connection',
					'updated' => 1,
				)
			)
		);
		exit;
	}

	/** @return list<string> */
	private function save_connection(): array {
		$options     = $this->plugin->options;
		$previous    = $options->environment();
		$errors      = array();
		$environment = isset( $_POST['environment'] ) && Options::ENV_LIVE === $_POST['environment'] ? Options::ENV_LIVE : Options::ENV_TEST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
		$settings    = array( 'environment' => $environment );

		// Visitor protection (Connection tab).
		$cap                      = isset( $_POST['session_cap'] ) ? absint( wp_unslash( $_POST['session_cap'] ) ) : Options::DEFAULT_SESSION_CAP; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$settings['session_cap']  = min( $cap, 100000 );
		$header                   = isset( $_POST['proxy_header'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy_header'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$settings['proxy_header'] = isset( Options::PROXY_HEADERS[ $header ] ) ? $header : '';
		$hops                     = isset( $_POST['proxy_hops'] ) ? absint( wp_unslash( $_POST['proxy_hops'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$settings['proxy_hops']   = min( Options::MAX_PROXY_HOPS, max( 1, $hops ) );

		// Live never sells at $0: every enabled product needs a service price first.
		$unpriced = Options::ENV_LIVE === $environment ? $this->plugin->products->unpriced() : array();
		if ( $unpriced ) {
			$settings['environment'] = $previous;
			$labels                  = array_map( fn ( $slug ) => (string) ( $this->plugin->products->all()[ $slug ]['label'] ?? $slug ), $unpriced );
			$errors[]                = sprintf(
				/* translators: %s: product names. */
				__( 'Live mode was not switched on: these enabled products have no service price above $0 yet: %s.', 'businessapi-forms' ),
				implode( ', ', $labels )
			);
			$environment = $previous;
		}

		// Live needs 2FA for every admin and shop manager, the required checks and a clean plugin.
		$blockers = Options::ENV_LIVE === $environment && Options::ENV_LIVE !== $previous ? $this->plugin->security->live_blockers() : array();
		if ( $blockers ) {
			$settings['environment'] = $previous;
			$environment             = $previous;
			$errors[]                = __( 'Live mode was not switched on:', 'businessapi-forms' ) . ' ' . implode( ' ', $blockers );
		}

		// API keys: blank keeps the stored key, "remove" clears it.
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			$field = $env . '_key';

			if ( ! empty( $_POST[ 'remove_' . $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$settings[ $field ] = '';
				continue;
			}

			$value = isset( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $value ) {
				continue;
			}

			if ( ! Options::key_matches_environment( $value, $env ) ) {
				$errors[] = sprintf(
					/* translators: 1: environment name, 2: expected key prefix. */
					__( 'The %1$s key was not saved. It must be a secret key starting with %2$s.', 'businessapi-forms' ),
					$env,
					'bapi_sk_' . $env . '_'
				);
				continue;
			}

			$settings[ $field ] = $value;
		}

		$this->plugin->prices->flush(); // The old key's cached prices.
		$options->update( $settings );
		$this->plugin->capabilities->flush();
		$this->plugin->prices->flush();

		// First connection: register this site and server IP with Business API.
		foreach ( array( Options::ENV_TEST, Options::ENV_LIVE ) as $env ) {
			if ( empty( $settings[ $env . '_key' ] ) ) {
				continue;
			}
			$registered = $this->plugin->sites->register_if_missing( $env );
			if ( null !== $registered && ! $registered->ok() ) {
				/* translators: %s: error from Business API. */
				$errors[] = sprintf( __( 'This website could not be registered with Business API: %s', 'businessapi-forms' ), (string) $registered->error );
			} elseif ( null !== $registered && 'rate_limited' === ( $registered->data['email'] ?? null ) ) {
				$errors[] = __( 'This website was registered, but Business API did not send another confirmation email yet. Please wait before requesting another email.', 'businessapi-forms' );
			}
		}

		if ( $environment !== $previous && ! $options->has_key( $environment ) ) {
			$errors[] = __( 'There is no API key for the selected environment yet, so forms will not be served.', 'businessapi-forms' );
		}

		return $errors;
	}

	/** @return list<string> */
	private function save_products(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); only the keys are used, each through sanitize_key().
		$submitted = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['products'] ) ) ) : array();
		$products  = array();

		// Prices first, so a product priced in the same save can be switched on live.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in save(); each value cast below.
		$posted  = isset( $_POST['pricing'] ) && is_array( $_POST['pricing'] ) ? wp_unslash( $_POST['pricing'] ) : array();
		$pricing = array();
		$errors  = array();
		foreach ( $this->plugin->products->all() as $slug => $product ) {
			$row     = isset( $posted[ $slug ] ) && is_array( $posted[ $slug ] ) ? $posted[ $slug ] : array();
			$raw     = isset( $row['service'] ) && is_string( $row['service'] ) ? trim( str_replace( array( '$', ',' ), '', $row['service'] ) ) : '';
			$service = '' === $raw ? 0.0 : ( is_numeric( $raw ) ? round( (float) $raw, 2 ) : -1.0 );
			if ( $service < 0 || $service > 100000 ) {
				/* translators: %s: product name. */
				$errors[] = sprintf( __( '%s: enter a service fee between $0 and $100,000.', 'businessapi-forms' ), $product['label'] );
				$service  = $this->plugin->options->service_fee( $slug );
			}
			$pricing[ $slug ] = array( 'service' => $service );
		}
		$this->plugin->options->update( array( 'pricing' => $pricing ) );

		// In live mode, products that cannot be lodged live cannot be switched on.
		foreach ( array_keys( $this->plugin->products->all() ) as $slug ) {
			$wanted            = in_array( $slug, $submitted, true );
			$products[ $slug ] = $wanted && $this->plugin->products->eligibility( $slug )['allowed'];
		}

		// Add-ons follow their product. Pay first needs WooCommerce and, live, a service fee above $0.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in save(); values compared below.
		$posted_flows = isset( $_POST['flows'] ) && is_array( $_POST['flows'] ) ? wp_unslash( $_POST['flows'] ) : array();
		$flows        = array();
		$woo          = class_exists( 'WooCommerce' ) && $this->plugin->commerce->has_active();
		foreach ( $this->plugin->products->all() as $slug => $product ) {
			if ( ! empty( $product['requires'] ) || Options::FLOW_PAY_FIRST !== ( $posted_flows[ $slug ] ?? '' ) ) {
				continue;
			}
			if ( ! $woo ) {
				/* translators: %s: product name. */
				$errors[] = sprintf( __( '%s: "Pay first" needs WooCommerce. Kept on form first.', 'businessapi-forms' ), $product['label'] );
				continue;
			}
			if ( $this->plugin->options->is_live() && $pricing[ $slug ]['service'] <= 0 ) {
				/* translators: %s: product name. */
				$errors[] = sprintf( __( '%s: "Pay first" needs a service fee above $0 in live mode. Kept on form first.', 'businessapi-forms' ), $product['label'] );
				continue;
			}
			$flows[ $slug ] = Options::FLOW_PAY_FIRST;
		}

		$this->plugin->options->update(
			array(
				'products'        => $products,
				'flows'           => $flows,
				'release_offline' => ! empty( $_POST['release_offline'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
			)
		);
		if ( $woo && class_exists( \BapiForms\Commerce\WooCommerce\PayFirstProducts::class ) ) {
			\BapiForms\Commerce\WooCommerce\PayFirstProducts::sync( 20 );
		}

		return $errors;
	}

	/** @return list<string> */
	private function save_notices(): array {
		$fields  = array( 'email', 'mobile', 'unit', 'street_number', 'street_name', 'street_type', 'suburb', 'state', 'postcode' );
		$posted  = isset( $_POST['asic_notices'] ) && is_array( $_POST['asic_notices'] ) ? wp_unslash( $_POST['asic_notices'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
		$notices = array( 'mode' => isset( $posted['mode'] ) && Options::NOTICES_PARTNER === $posted['mode'] ? Options::NOTICES_PARTNER : Options::NOTICES_CLIENT );

		foreach ( $fields as $field ) {
			$value = isset( $posted[ $field ] ) && is_string( $posted[ $field ] ) ? trim( sanitize_text_field( $posted[ $field ] ) ) : '';
			if ( 'email' === $field ) {
				$value = sanitize_email( $value );
			} elseif ( 'mobile' === $field ) {
				$value = (string) preg_replace( '/[\s()-]/', '', $value );
			} elseif ( in_array( $field, array( 'state', 'street_type', 'street_name', 'suburb' ), true ) ) {
				$value = strtoupper( $value );
			}
			$notices[ $field ] = $value;
		}

		$errors = Options::NOTICES_PARTNER === $notices['mode'] ? Options::validate_service_details( $notices ) : array();
		if ( $errors ) {
			// Keep the previous ASIC notice settings untouched.
			return array_merge( array( __( 'ASIC notice settings were not saved:', 'businessapi-forms' ) ), $errors );
		}

		$this->plugin->options->update( array( 'asic_notices' => $notices ) );

		return array();
	}

	/** @return list<string> */
	private function save_appearance(): array {
		$posted = isset( $_POST['branding'] ) && is_array( $_POST['branding'] ) ? wp_unslash( $_POST['branding'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in save(), validated below.
		$input  = array();
		foreach ( array_keys( Options::BRANDING_DEFAULTS ) as $field ) {
			$input[ $field ] = isset( $posted[ $field ] ) && is_string( $posted[ $field ] ) ? sanitize_text_field( $posted[ $field ] ) : '';
		}

		[ $clean, $errors ] = Options::validate_branding( $input );
		if ( $errors ) {
			return array_merge( array( __( 'Appearance was not saved:', 'businessapi-forms' ) ), $errors );
		}

		$this->plugin->options->update( array( 'branding' => $clean ) );

		return array();
	}

	/** "Register this site" on the Connection tab. */
	public function register_site(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		check_admin_referer( self::SITE_ACTION );

		$response = $this->plugin->sites->register();
		$errors   = $response->ok() ? array() : array(
			/* translators: %s: error from Business API. */
			sprintf( __( 'This website could not be registered with Business API: %s', 'businessapi-forms' ), (string) $response->error ),
		);

		set_transient(
			'bapi_forms_notice_' . get_current_user_id(),
			array(
				'errors' => $errors,
				'email'  => $response->ok() ? self::email_result( $response->data['email'] ?? null ) : null,
			),
			60
		);
		wp_safe_redirect(
			self::url(
				array(
					'tab'        => 'connection',
					'registered' => $response->ok() ? 1 : 0,
				)
			)
		);
		exit;
	}

	public function refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'businessapi-forms' ), 403 );
		}
		check_admin_referer( self::REFRESH_ACTION );

		$this->plugin->capabilities->flush();
		$this->plugin->prices->flush();
		// Stale notices: re-learnt on the next refused call.
		delete_option( Sites::REFUSED_IP_OPTION );
		delete_transient( 'bapi_forms_notice_' . get_current_user_id() );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'connection';
		wp_safe_redirect(
			self::url(
				array(
					'tab'       => $tab,
					'refreshed' => 1,
				)
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab    = $this->current_tab();
		$notice = get_transient( 'bapi_forms_notice_' . get_current_user_id() );
		delete_transient( 'bapi_forms_notice_' . get_current_user_id() );
		?>
		<div class="wrap bapi-forms">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'BusinessAPI Forms', 'businessapi-forms' ); ?></h1>
			<?php $this->render_environment_badge(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'BusinessAPI Forms settings', 'businessapi-forms' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $slug ) ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" <?php echo $tab === $slug ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php $this->render_messages( is_array( $notice ) ? $notice : array() ); ?>

			<?php
			if ( 'help' === $tab ) {
				( new HelpPage( $this->plugin ) )->render_content();
			} elseif ( 'security' === $tab ) {
				( new \BapiForms\Security\SecurityTab( $this->plugin->security ) )->render();
			} else {
				$this->render_form( $tab );
			}
			?>
		</div>
		<?php
	}

	private function render_form( string $tab ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bapi-forms-tab-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>
			<?php
			match ( $tab ) {
				'products' => $this->render_products(),
				'notices'    => $this->render_notices(),
				'appearance' => $this->render_appearance(),
				default      => $this->render_connection(),
			};
			submit_button( __( 'Save changes', 'businessapi-forms' ) );
		?>
		</form>
		<?php
		if ( 'connection' === $tab && $this->plugin->options->has_key() ) {
			$this->render_website();
		}
		?>
		<?php if ( in_array( $tab, array( 'connection', 'products' ), true ) && $this->plugin->options->has_key() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
				<?php submit_button( __( 'Refresh account status', 'businessapi-forms' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	private function render_environment_badge(): void {
		$live = $this->plugin->options->is_live();
		printf(
			'<span class="bapi-forms-badge %s">%s</span>',
			$live ? 'bapi-forms-badge-live' : 'bapi-forms-badge-sandbox',
			$live ? esc_html__( 'Live', 'businessapi-forms' ) : esc_html__( 'Sandbox', 'businessapi-forms' )
		);
	}

	/** The /embed-sites `email` result: sent, rate_limited or null. */
	private static function email_result( mixed $email ): ?string {
		return in_array( $email, array( 'sent', 'rate_limited' ), true ) ? $email : null;
	}

	private function render_messages( array $notice ): void {
		foreach ( $notice['errors'] ?? array() as $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
		if ( isset( $_GET['updated'] ) && empty( $notice['errors'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved.', 'businessapi-forms' ) );
		}
		if ( ! empty( $_GET['registered'] ) && empty( $notice['errors'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$email = $notice['email'] ?? null;
			if ( 'rate_limited' === $email ) {
				printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html__( 'Registered, but no new email was sent: Business API already sent several confirmation emails this hour. Please wait before requesting another email, or use the link in the last one.', 'businessapi-forms' ) );
			} elseif ( 'sent' === $email ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Confirmation email sent to your Business API account owner. New entries work once the link in it is confirmed.', 'businessapi-forms' ) );
			} else {
				$message = $this->plugin->options->is_live()
					? __( 'Registered. Nothing new needed confirming.', 'businessapi-forms' )
					: __( 'Registered for sandbox.', 'businessapi-forms' );
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			}
		}
		if ( isset( $_GET['refreshed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Account status refreshed.', 'businessapi-forms' ) );
		}
	}

	private function render_connection(): void {
		$options     = $this->plugin->options;
		$environment = $options->environment();
		$status      = $options->has_key() ? $this->plugin->capabilities->status() : null;
		?>
		<section class="bapi-forms-panel">
		<h2><?php esc_html_e( 'Business API connection', 'businessapi-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Environment', 'businessapi-forms' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="environment" value="test" <?php checked( Options::ENV_TEST, $environment ); ?>> <?php esc_html_e( 'Sandbox (test key, nothing is lodged)', 'businessapi-forms' ); ?></label><br>
						<label><input type="radio" name="environment" value="live" <?php checked( Options::ENV_LIVE, $environment ); ?>> <?php esc_html_e( 'Live (real lodgements, billed to your Business API account)', 'businessapi-forms' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<?php $this->render_key_row( Options::ENV_TEST, __( 'Sandbox API key', 'businessapi-forms' ) ); ?>
			<?php $this->render_key_row( Options::ENV_LIVE, __( 'Live API key', 'businessapi-forms' ) ); ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'businessapi-forms' ); ?></th>
				<td><?php $this->render_status( $status ); ?></td>
			</tr>
		</table>
		<h2><?php esc_html_e( 'Visitor protection', 'businessapi-forms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="session_cap"><?php esc_html_e( 'Applications to checkout per hour', 'businessapi-forms' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" class="small-text" id="session_cap" name="session_cap" value="<?php echo esc_attr( (string) $options->session_cap() ); ?>">
					<p class="description"><?php esc_html_e( 'Site-wide safety limit (0 = no limit): once this many completed applications reached checkout in the current hour, new forms cannot be started until the next hour. Forms started but not completed do not count. Separately, each visitor network can start up to 10 new applications per hour. Reopening an application in progress never counts.', 'businessapi-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="proxy_header"><?php esc_html_e( 'Trusted proxy header', 'businessapi-forms' ); ?></label></th>
				<td>
					<select id="proxy_header" name="proxy_header">
						<option value=""><?php esc_html_e( 'None (use the connection IP)', 'businessapi-forms' ); ?></option>
						<?php foreach ( array_keys( Options::PROXY_HEADERS ) as $header ) : ?>
							<option value="<?php echo esc_attr( $header ); ?>" <?php selected( $options->proxy_header(), $header ); ?>><?php echo esc_html( $header ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only if every request reaches your site through that proxy (e.g. CF-Connecting-IP on Cloudflare, X-Forwarded-For behind a load balancer). Warning: choosing CF-Connecting-IP when your site is not behind Cloudflare (or still accepts traffic that bypasses it) lets any visitor send that header with a made-up IP, spoof their address and get around the per-visitor limits. If unsure, leave this on None.', 'businessapi-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="proxy_hops"><?php esc_html_e( 'Trusted proxies', 'businessapi-forms' ); ?></label></th>
				<td>
					<input type="number" min="1" max="<?php echo esc_attr( (string) Options::MAX_PROXY_HOPS ); ?>" step="1" class="small-text" id="proxy_hops" name="proxy_hops" value="<?php echo esc_attr( (string) $options->proxy_hops() ); ?>">
					<p class="description"><?php esc_html_e( 'X-Forwarded-For only: how many proxies you run in front of the site. 1 uses the right-most address (one load balancer or CDN); 2 the second from the right (e.g. a CDN in front of a load balancer). Entries further left can be faked by visitors.', 'businessapi-forms' ); ?></p>
				</td>
			</tr>
		</table>
		</section>
		<?php
	}

	/** "Your website" panel: this hostname and server IP as Business API sees them. */
	private function render_website(): void {
		$site     = $this->plugin->sites->status();
		$pill     = static function ( string $status ): void {
			$map               = array(
				Sites::ACTIVE         => array( 'bapi-forms-ok', __( 'Active', 'businessapi-forms' ) ),
				Sites::PENDING        => array( 'bapi-forms-warn', __( 'Awaiting email confirmation', 'businessapi-forms' ) ),
				Sites::NOT_REGISTERED => array( 'bapi-forms-error', __( 'Not registered', 'businessapi-forms' ) ),
			);
			[ $class, $label ] = $map[ $status ] ?? $map[ Sites::NOT_REGISTERED ];
			printf( '<span class="bapi-forms-pill %s">%s</span>', esc_attr( $class ), esc_html( $label ) );
		};
		$complete = Sites::ACTIVE === $site['domain_status'] && Sites::ACTIVE === $site['ip_status'];
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Your website', 'businessapi-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Forms only load on registered websites, and with a live key Business API only accepts form requests from registered server IPs. Live entries start working once your account owner confirms the email Business API sends.', 'businessapi-forms' ); ?></p>
			<?php if ( ! $site['ok'] ) : ?>
				<p><span class="bapi-forms-pill bapi-forms-error"><?php esc_html_e( 'Unavailable', 'businessapi-forms' ); ?></span> <?php echo esc_html( (string) $site['error'] ); ?></p>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Website', 'businessapi-forms' ); ?></th>
						<td><code><?php echo esc_html( $site['host'] ); ?></code> <?php $pill( $site['domain_status'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Server IP', 'businessapi-forms' ); ?></th>
						<td>
							<code><?php echo esc_html( '' !== $site['ip'] ? $site['ip'] : __( 'unknown', 'businessapi-forms' ) ); ?></code> <?php $pill( $site['ip_status'] ); ?>
							<p class="description"><?php esc_html_e( 'As Business API sees it. If your host changes this server\'s IP, register again.', 'businessapi-forms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php if ( ! $complete ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SITE_ACTION ); ?>">
						<?php wp_nonce_field( self::SITE_ACTION ); ?>
						<?php submit_button( __( 'Register this site', 'businessapi-forms' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_key_row( string $environment, string $label ): void {
		$options = $this->plugin->options;
		$field   = $environment . '_key';
		$stored  = $options->key( $environment );
		?>
		<tr class="bapi-forms-key-row" data-env="<?php echo esc_attr( $environment ); ?>" <?php echo $options->environment() === $environment ? '' : 'hidden'; ?>>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $options->key_is_constant( $environment ) ) : ?>
					<code><?php echo esc_html( Options::mask_key( $stored ) ); ?></code>
					<p class="description"><?php esc_html_e( 'Set in wp-config.php.', 'businessapi-forms' ); ?></p>
				<?php else : ?>
					<input type="password" class="regular-text code" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="" autocomplete="off"
						placeholder="<?php echo esc_attr( '' !== $stored ? Options::mask_key( $stored ) : 'bapi_sk_' . $environment . '_...' ); ?>">
					<?php if ( '' !== $stored ) : ?>
						<label><input type="checkbox" name="remove_<?php echo esc_attr( $field ); ?>" value="1"> <?php esc_html_e( 'Remove', 'businessapi-forms' ); ?></label>
						<p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'businessapi-forms' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'A secret key from your Business API dashboard. It stays on this server and is never sent to browsers.', 'businessapi-forms' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_status( ?array $status ): void {
		if ( null === $status ) {
			printf( '<span class="bapi-forms-pill bapi-forms-warn">%s</span>', esc_html__( 'No API key for this environment', 'businessapi-forms' ) );
			return;
		}

		if ( ! $status['ok'] ) {
			printf( '<span class="bapi-forms-pill bapi-forms-error">%s</span> %s', esc_html__( 'Not connected', 'businessapi-forms' ), esc_html( (string) $status['error'] ) );
			return;
		}

		$name = trim( (string) ( $status['account']['companyName'] ?? '' ) );
		$name = '' !== $name ? $name : (string) ( $status['account']['accountName'] ?? '' );
		printf(
			'<span class="bapi-forms-pill bapi-forms-ok">%s</span> %s',
			esc_html__( 'Connected', 'businessapi-forms' ),
			esc_html(
				sprintf(
					/* translators: 1: account name, 2: time of last check. */
					__( 'as %1$s (checked %2$s ago)', 'businessapi-forms' ),
					$name,
					human_time_diff( (int) $status['checked_at'] )
				)
			)
		);

		if ( false === ( $status['forms_enabled'] ?? null ) ) {
			printf(
				'<p><span class="bapi-forms-pill bapi-forms-warn">%s</span> %s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
				esc_html__( 'Forms not enabled', 'businessapi-forms' ),
				esc_html( \BapiForms\Account\Capabilities::forms_disabled_message() ),
				esc_url( \BapiForms\Frontend\RestController::bapi_origin() . '/dashboard' ),
				esc_html__( 'Open your Business API dashboard', 'businessapi-forms' )
			);
		}

		if ( ! $this->plugin->commerce->has_active() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: approved ecommerce plugins. */
						__( 'Forms are not served until an approved ecommerce plugin is active (%s).', 'businessapi-forms' ),
						$this->plugin->commerce->approved_labels()
					)
				)
			);
		}
	}

	private function render_products(): void {
		$registry = $this->plugin->products;
		$enabled  = $this->plugin->options->enabled_products();
		$is_live  = $this->plugin->options->is_live();
		$preview  = array();

		// Sandbox: preview live readiness so partners know what to sort out before going live.
		if ( ! $is_live && $this->plugin->options->has_key() ) {
			$preview = $this->plugin->capabilities->status()['products'] ?? array();
		}
		?>
		<p class="description bapi-forms-intro">
			<?php
			echo $is_live
				? esc_html__( 'Live mode: only products your Business API account can lodge live can be switched on. Onboarding is managed in your Business API dashboard.', 'businessapi-forms' )
				: esc_html__( 'Sandbox mode: every product can be switched on for testing. It only needs the "Forms on my WordPress site" onboarding submitted in your Business API dashboard.', 'businessapi-forms' );
			?>
		</p>
		<div class="bapi-forms-cards">
			<?php foreach ( $registry->all() as $slug => $product ) : ?>
				<?php
				$eligibility = $registry->eligibility( $slug );
				$allowed     = $eligibility['allowed'];
				$checked     = $allowed && ! empty( $enabled[ $slug ] );
				$parent      = $product['requires'] ? ( $registry->all()[ $product['requires'] ]['label'] ?? $product['requires'] ) : null;
				$live        = $is_live ? null : ( $preview[ $product['capability'] ] ?? null );
				$id          = 'product-' . $slug;
				?>
				<div class="bapi-forms-card <?php echo $product['requires'] ? 'bapi-forms-card-child' : ''; ?> <?php echo $allowed ? '' : 'is-disabled'; ?>">
					<span class="bapi-forms-card-icon dashicons <?php echo esc_attr( self::PRODUCT_ICONS[ $slug ] ?? 'dashicons-forms' ); ?>" aria-hidden="true"></span>
					<div class="bapi-forms-card-body">
						<div class="bapi-forms-card-title">
							<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $product['label'] ); ?></label>
							<?php if ( ! $allowed ) : ?>
								<span class="bapi-forms-pill bapi-forms-error"><?php esc_html_e( 'Cannot lodge live', 'businessapi-forms' ); ?></span>
							<?php elseif ( $is_live ) : ?>
								<span class="bapi-forms-pill bapi-forms-ok"><?php esc_html_e( 'Ready to lodge live', 'businessapi-forms' ); ?></span>
							<?php elseif ( is_array( $live ) && ! empty( $live['live'] ) ) : ?>
								<span class="bapi-forms-pill bapi-forms-ok"><?php esc_html_e( 'Live ready', 'businessapi-forms' ); ?></span>
							<?php elseif ( is_array( $live ) ) : ?>
								<span class="bapi-forms-pill bapi-forms-neutral"><?php esc_html_e( 'Sandbox only for now', 'businessapi-forms' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="bapi-forms-card-desc"><?php echo esc_html( $product['description'] ); ?></p>
						<?php
						$reason = ! $allowed
							? trim( ( $eligibility['reason'] ?? '' ) . ' ' . ( $eligibility['instructions'] ?? '' ) )
							: ( is_array( $live ) && empty( $live['live'] ) ? trim( ( $live['reason'] ?? '' ) . ' ' . ( $live['instructions'] ?? '' ) ) : '' );
						?>
						<?php if ( '' !== $reason && ! $allowed ) : ?>
							<p class="bapi-forms-card-reason"><?php echo esc_html( $reason ); ?></p>
						<?php elseif ( '' !== $reason ) : ?>
							<?php // Sandbox: a preview of live readiness, not a problem. ?>
							<p class="bapi-forms-card-note">
								<?php
								/* translators: %s: what Business API needs before this product can lodge live. */
								echo esc_html( sprintf( __( 'Sandbox works now. For live: %s', 'businessapi-forms' ), $reason ) );
								?>
							</p>
						<?php endif; ?>
						<?php $this->render_price_row( $slug ); ?>
						<?php $this->render_flow_row( $slug, $product ); ?>
						<?php if ( $parent ) : ?>
							<p class="bapi-forms-card-meta">
								<?php
								/* translators: %s: parent product name. */
								echo esc_html( sprintf( __( 'Shown inside the %s form.', 'businessapi-forms' ), $parent ) );
								?>
							</p>
						<?php else : ?>
							<p class="bapi-forms-card-meta"><code><?php echo esc_html( sprintf( '[%s product="%s"]', Shortcode::TAG, $slug ) ); ?></code></p>
						<?php endif; ?>
					</div>
					<label class="bapi-forms-toggle">
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="products[<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( $checked ); ?> <?php disabled( ! $allowed ); ?>>
						<span class="bapi-forms-toggle-track" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( $product['label'] ); ?></span>
					</label>
				</div>
			<?php endforeach; ?>
		</div>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Releasing orders', 'businessapi-forms' ); ?></h2>
			<p>
				<label>
					<input type="checkbox" name="release_offline" value="1" <?php checked( $this->plugin->options->release_offline() ); ?>>
					<?php esc_html_e( 'Release bank transfer and cheque orders when I mark them paid', 'businessapi-forms' ); ?>
				</label>
			</p>
			<p class="description"><?php esc_html_e( 'Off by default: those orders are never lodged automatically. When on, an order paid by bank transfer or cheque is released once you set it to Processing or Completed, so only do that after the money has arrived. Cash on delivery is never released. Card and other online payments release as soon as the payment is confirmed.', 'businessapi-forms' ); ?></p>
		</section>
		<?php
	}

	/**
	 * "When clients pay" for a main product; add-ons follow their product. With pay first, the
	 * shop product's links and the [bapi_buy] shortcode.
	 *
	 * @param array<string, mixed> $product Product definition.
	 */
	private function render_flow_row( string $slug, array $product ): void {
		$id = 'flow-' . $slug;
		if ( ! empty( $product['requires'] ) ) {
			$parent = (string) ( $this->plugin->products->all()[ $product['requires'] ]['label'] ?? $product['requires'] );
			/* translators: %s: parent product name. */
			echo '<p class="bapi-forms-card-meta">' . esc_html( sprintf( __( 'When clients pay: follows %s.', 'businessapi-forms' ), $parent ) ) . '</p>';
			return;
		}
		$flow = $this->plugin->options->flow( $slug );
		?>
		<p class="bapi-forms-card-meta">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'When clients pay', 'businessapi-forms' ); ?></label>
			<select id="<?php echo esc_attr( $id ); ?>" name="flows[<?php echo esc_attr( $slug ); ?>]">
				<option value="<?php echo esc_attr( Options::FLOW_FORM_FIRST ); ?>" <?php selected( $flow, Options::FLOW_FORM_FIRST ); ?>><?php esc_html_e( 'Form first, pay at the end', 'businessapi-forms' ); ?></option>
				<option value="<?php echo esc_attr( Options::FLOW_PAY_FIRST ); ?>" <?php selected( $flow, Options::FLOW_PAY_FIRST ); ?>><?php esc_html_e( 'Pay first, then the form', 'businessapi-forms' ); ?></option>
			</select>
		</p>
		<?php
		if ( Options::FLOW_PAY_FIRST !== $flow || ! class_exists( \BapiForms\Commerce\WooCommerce\PayFirstProducts::class ) ) {
			return;
		}
		$shop = \BapiForms\Commerce\WooCommerce\PayFirstProducts::product_for( $slug );
		if ( null !== $shop && 'publish' === $shop->get_status() ) {
			?>
			<p class="bapi-forms-card-meta">
				<?php esc_html_e( 'Shop product:', 'businessapi-forms' ); ?>
				<a href="<?php echo esc_url( $shop->get_permalink() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'businessapi-forms' ); ?></a> |
				<a href="<?php echo esc_url( (string) get_edit_post_link( $shop->get_id() ) ); ?>"><?php esc_html_e( 'Edit', 'businessapi-forms' ); ?></a>
				<code><?php echo esc_html( sprintf( '[%s product="%s"]', \BapiForms\Commerce\WooCommerce\PayFirstCart::SHORTCODE, $slug ) ); ?></code>
			</p>
			<?php
		} else {
			echo '<p class="bapi-forms-card-meta">' . esc_html__( 'The shop product appears once this product is switched on and saved.', 'businessapi-forms' ) . '</p>';
		}
	}

	/**
	 * Service fee input, government fee (information or pass-through) and the partner's own
	 * Business API cost and margin, for one product card. Margin is ex GST, assuming 10% GST.
	 */
	private function render_price_row( string $slug ): void {
		$options = $this->plugin->options;
		$prices  = $this->plugin->prices;
		$service = $options->service_fee( $slug );
		$your    = $prices->your_price( $slug );
		$money   = static fn( float $v ): string => ( $v < 0 ? '-$' : '$' ) . number_format( abs( $v ), 2 );
		$ex_gst  = static fn( float $v ): float => $v / 1.1;
		// Taxes off in WooCommerce: no GST is charged or claimed, so no GST maths at all.
		$taxes   = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
		$entered = $taxes && function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();
		$net     = $entered ? $ex_gst( $service ) : $service;
		$asic    = in_array( $slug, PriceList::ASIC_PAID_BY_PARTNER, true );
		$by_bapi = in_array( $slug, PriceList::CHARGED_BY_BAPI, true );
		$terms   = 'business_name' === $slug ? PriceList::BN_TERMS : array( 0 );
		$id      = 'price-' . $slug;
		?>
		<div class="bapi-forms-price">
			<p>
				<label for="<?php echo esc_attr( $id ); ?>"><strong><?php esc_html_e( 'Your service fee', 'businessapi-forms' ); ?></strong></label>
				$<input type="number" class="small-text bapi-forms-price-input" id="<?php echo esc_attr( $id ); ?>" name="pricing[<?php echo esc_attr( $slug ); ?>][service]" value="<?php echo esc_attr( $service > 0 ? number_format( $service, 2, '.', '' ) : '' ); ?>" min="0" max="100000" step="0.01" placeholder="0.00">
				<span class="description">
					<?php
					if ( ! $taxes ) {
						esc_html_e( '(taxes are off in WooCommerce, so no GST is added)', 'businessapi-forms' );
					} elseif ( $entered ) {
						esc_html_e( 'incl. GST (your shop enters prices with tax)', 'businessapi-forms' );
					} else {
						esc_html_e( 'plus GST (your shop adds tax at checkout)', 'businessapi-forms' );
					}
					?>
				</span>
			</p>
			<?php if ( $asic || $by_bapi ) : ?>
				<p class="bapi-forms-price-gov">
					<?php
					$fees = array();
					if ( 'company_registration' === $slug ) {
						// v1 hosted company form: proprietary companies only (APTY/PROP).
						$fee = $prices->government_fee( $slug, array( 'company' => PriceList::V1_COMPANY_KEY ) );
						if ( null !== $fee ) {
							/* translators: %s: amount. */
							$fees[] = sprintf( __( '%s (proprietary company)', 'businessapi-forms' ), $money( $fee ) );
						}
					} else {
						foreach ( $terms as $term ) {
							$fee = $prices->government_fee( $slug, array( 'term' => $term ? $term : 3 ) );
							if ( null !== $fee ) {
								/* translators: 1: number of years, 2: amount. */
								$fees[] = $term ? sprintf( __( '%1$d year %2$s', 'businessapi-forms' ), $term, $money( $fee ) ) : $money( $fee );
							}
						}
					}
					$fee_text = $fees ? implode( '; ', $fees ) : __( 'unavailable right now', 'businessapi-forms' );
					if ( $asic ) {
						/* translators: %s: fee amounts. */
						echo esc_html( sprintf( __( 'ASIC fee %s, charged to your client at cost (ASIC bills you directly). Added automatically as a separate GST-free fee for each application.', 'businessapi-forms' ), $fee_text ) );
						if ( 'company_registration' === $slug ) {
							echo ' ' . esc_html__( 'The form registers proprietary companies (Pty Ltd) only.', 'businessapi-forms' );
						}
					} else {
						/* translators: %s: fee amount. */
						echo esc_html( sprintf( __( 'PPSR search fee %s, charged to your client at cost (Business API bills you at cost). Added automatically as a separate GST-free fee for each application.', 'businessapi-forms' ), $fee_text ) );
					}
					?>
				</p>
			<?php endif; ?>
			<p class="bapi-forms-price-cost">
				<?php
				if ( null === $your ) {
					esc_html_e( 'Your Business API cost: unavailable right now (refresh account status).', 'businessapi-forms' );
				} else {
					// Government fees are recovered from the client at cost, so they do not touch the margin.
					printf(
						/* translators: 1: BAPI price incl. GST, 2: margin. */
						$taxes ? esc_html__( 'Your Business API cost: %1$s incl. GST. Your margin (ex GST): %2$s', 'businessapi-forms' ) : esc_html__( 'Your Business API cost: %1$s incl. GST. Your margin: %2$s', 'businessapi-forms' ),
						esc_html( $money( $your ) ),
						'<strong>' . esc_html( $money( $taxes ? $net - $ex_gst( $your ) : $service - $your ) ) . '</strong>'
					);
				}
				?>
			</p>
		</div>
		<?php
	}

	private function render_notices(): void {
		$notices = $this->plugin->options->asic_notices();
		$name    = static fn( string $field ): string => 'asic_notices[' . $field . ']';
		$hidden  = Options::NOTICES_PARTNER === $notices['mode'] ? '' : ' hidden';
		$text    = function ( string $field, string $label, array $extra = array() ) use ( $notices, $name, $hidden ): void {
			$attrs = '';
			foreach ( $extra as $attr => $value ) {
				$attrs .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $value ) );
			}
			printf(
				'<tr class="bapi-forms-notices-field"%6$s><th scope="row"><label for="asic-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="asic-%1$s" name="%3$s" value="%4$s"%5$s></td></tr>',
				esc_attr( $field ),
				esc_html( $label ),
				esc_attr( $name( $field ) ),
				esc_attr( $notices[ $field ] ),
				$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each name and value escaped above.
				'' === $hidden ? '' : ' hidden'
			);
		};
		?>
		<section class="bapi-forms-panel">
		<h2><?php esc_html_e( 'ASIC notices', 'businessapi-forms' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Who ASIC contacts about registrations, such as business name renewal notices. Using your details keeps renewals and the client relationship with you.', 'businessapi-forms' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send ASIC notices to', 'businessapi-forms' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="<?php echo esc_attr( $name( 'mode' ) ); ?>" value="client" <?php checked( Options::NOTICES_CLIENT, $notices['mode'] ); ?>> <?php esc_html_e( 'The client (the form asks for their email and service address)', 'businessapi-forms' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $name( 'mode' ) ); ?>" value="partner" <?php checked( Options::NOTICES_PARTNER, $notices['mode'] ); ?>> <?php esc_html_e( 'Me (use my service email and address below)', 'businessapi-forms' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<?php
			$text(
				'email',
				__( 'Service email', 'businessapi-forms' ),
				array(
					'autocomplete' => 'email',
					'inputmode'    => 'email',
				)
			);
			$text(
				'mobile',
				__( 'Service mobile (optional)', 'businessapi-forms' ),
				array(
					'autocomplete' => 'tel',
					'inputmode'    => 'tel',
				)
			);
			$text( 'unit', __( 'Unit / office (optional)', 'businessapi-forms' ) );
			$text( 'street_number', __( 'Street number', 'businessapi-forms' ) );
			$text( 'street_name', __( 'Street name', 'businessapi-forms' ) );
			$text( 'street_type', __( 'Street type (e.g. ST, RD)', 'businessapi-forms' ) );
			$text( 'suburb', __( 'Suburb', 'businessapi-forms' ) );
			?>
			<tr class="bapi-forms-notices-field"<?php echo '' !== $hidden ? ' hidden' : ''; ?>>
				<th scope="row"><label for="asic-state"><?php esc_html_e( 'State', 'businessapi-forms' ); ?></label></th>
				<td>
					<select id="asic-state" name="<?php echo esc_attr( $name( 'state' ) ); ?>">
						<option value=""><?php esc_html_e( 'Select', 'businessapi-forms' ); ?></option>
						<?php foreach ( Options::STATES as $state ) : ?>
							<option value="<?php echo esc_attr( $state ); ?>" <?php selected( $state, $notices['state'] ); ?>><?php echo esc_html( $state ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php
			$text(
				'postcode',
				__( 'Postcode', 'businessapi-forms' ),
				array(
					'inputmode' => 'numeric',
					'maxlength' => '4',
					'pattern'   => '[0-9]{4}',
				)
			);
			?>
		</table>
		</section>
		<?php
	}

	/** BAPI's default accent colour (used by the preview when none is set). */
	private const DEFAULT_PRIMARY = '#2563eb';

	private function render_appearance(): void {
		$branding = $this->plugin->options->branding();
		$primary  = '' !== $branding['primary'] ? $branding['primary'] : self::DEFAULT_PRIMARY;
		$radius   = '' !== $branding['radius'] ? $branding['radius'] : '8';
		// '' follows the APCA recommendation (worked out by admin.js and by Business API).
		$on_primary  = $branding['on_primary'];
		$text_labels = array(
			Options::WHITE_TEXT => __( 'White', 'businessapi-forms' ),
			Options::DARK_TEXT  => __( 'Dark', 'businessapi-forms' ),
		);
		?>
		<section class="bapi-forms-panel">
			<h2><?php esc_html_e( 'Form appearance', 'businessapi-forms' ); ?></h2>
			<p class="description bapi-forms-intro"><?php esc_html_e( 'How the hosted forms look on your pages. Leave a field empty to use the Business API default.', 'businessapi-forms' ); ?></p>
			<div class="bapi-forms-appearance">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bapi-forms-primary"><?php esc_html_e( 'Accent colour', 'businessapi-forms' ); ?></label></th>
						<td>
							<input type="text" id="bapi-forms-primary" name="branding[primary]" value="<?php echo esc_attr( $branding['primary'] ); ?>" class="bapi-forms-color" data-default-color="#2563eb" maxlength="7" pattern="#?[0-9A-Fa-f]{6}">
							<p class="description"><?php esc_html_e( 'Buttons, selected options and focus rings, plus accent text such as links (darkened where needed so it stays readable on white).', 'businessapi-forms' ); ?></p>
							<p class="bapi-forms-fill-contrast" hidden><span class="bapi-forms-pill bapi-forms-warn"><?php esc_html_e( 'Light colour', 'businessapi-forms' ); ?></span> <span class="bapi-forms-fill-contrast-text"></span></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Button text', 'businessapi-forms' ); ?></th>
						<td>
							<fieldset class="bapi-forms-on-primary" data-choice="<?php echo esc_attr( $on_primary ); ?>">
								<legend class="screen-reader-text"><?php esc_html_e( 'Button text', 'businessapi-forms' ); ?></legend>
								<?php foreach ( $text_labels as $value => $label ) : ?>
									<label>
										<input type="radio" name="branding[on_primary]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $on_primary, $value ); ?>>
										<span class="bapi-forms-swatch" style="background: <?php echo esc_attr( $value ); ?>;" aria-hidden="true"></span>
										<?php echo esc_html( $label ); ?>
										<span class="bapi-forms-pill bapi-forms-ok bapi-forms-recommended" data-value="<?php echo esc_attr( $value ); ?>" hidden><?php esc_html_e( 'Recommended', 'businessapi-forms' ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Text on buttons and selected options in the accent colour. Recommended is the one that reads best on your colour (APCA perceptual contrast).', 'businessapi-forms' ); ?></p>
							<p class="bapi-forms-contrast" hidden><span class="bapi-forms-pill bapi-forms-warn"><?php esc_html_e( 'Low contrast', 'businessapi-forms' ); ?></span> <span class="bapi-forms-contrast-text"></span></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bapi-forms-radius"><?php esc_html_e( 'Corner radius', 'businessapi-forms' ); ?></label></th>
						<td>
							<input type="range" id="bapi-forms-radius-range" min="0" max="24" step="1" value="<?php echo esc_attr( $radius ); ?>" aria-label="<?php esc_attr_e( 'Corner radius slider', 'businessapi-forms' ); ?>">
							<input type="number" id="bapi-forms-radius" name="branding[radius]" min="0" max="24" step="1" value="<?php echo esc_attr( $branding['radius'] ); ?>" placeholder="8" class="small-text"> px
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bapi-forms-font"><?php esc_html_e( 'Font', 'businessapi-forms' ); ?></label></th>
						<td>
							<select id="bapi-forms-font" name="branding[font]">
								<option value=""><?php esc_html_e( 'Default', 'businessapi-forms' ); ?></option>
								<?php foreach ( Options::SAFE_FONTS as $font ) : ?>
									<option value="<?php echo esc_attr( $font ); ?>" <?php selected( $branding['font'], $font ); ?>><?php echo esc_html( $font ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Common fonts installed on most devices. The form does not download fonts, so where one is missing it falls back to the device\'s system font (system-ui).', 'businessapi-forms' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="bapi-forms-preview" aria-label="<?php esc_attr_e( 'Preview', 'businessapi-forms' ); ?>" style="--bp: <?php echo esc_attr( $primary ); ?>; --bpo: <?php echo esc_attr( '' !== $on_primary ? $on_primary : Options::WHITE_TEXT ); ?>; --br: <?php echo esc_attr( $radius ); ?>px;">
					<p class="bapi-forms-preview-label"><?php esc_html_e( 'Preview', 'businessapi-forms' ); ?></p>
					<label class="bapi-forms-p-field"><span><?php esc_html_e( 'Company name', 'businessapi-forms' ); ?></span><span class="bapi-forms-p-input is-focused"><?php esc_html_e( 'Example Pty Ltd', 'businessapi-forms' ); ?></span></label>
					<div class="bapi-forms-p-row"><span class="bapi-forms-p-radio is-on" aria-hidden="true"></span> <?php esc_html_e( 'Proprietary company', 'businessapi-forms' ); ?></div>
					<div class="bapi-forms-p-row"><span class="bapi-forms-p-check is-on" aria-hidden="true"></span> <?php esc_html_e( 'I agree to the declaration', 'businessapi-forms' ); ?></div>
					<div class="bapi-forms-p-row"><span class="bapi-forms-p-toggle is-on" aria-hidden="true"></span> <?php esc_html_e( 'Register for GST', 'businessapi-forms' ); ?></div>
					<div class="bapi-forms-p-choices" aria-hidden="true"><span class="bapi-forms-p-choice is-on"><?php esc_html_e( 'Yes', 'businessapi-forms' ); ?></span><span class="bapi-forms-p-choice"><?php esc_html_e( 'No', 'businessapi-forms' ); ?></span></div>
					<span class="bapi-forms-p-button"><?php esc_html_e( 'Continue', 'businessapi-forms' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}
}
