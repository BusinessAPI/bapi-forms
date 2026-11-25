<?php
/**
 * 2FA gate for live mode: shop admins need 2FA from a detected plugin (or the
 * bapi_forms_user_has_2fa filter) before live can be switched on, and to use BAPI screens and
 * actions while live.
 *
 * @package BapiForms
 */

namespace BapiForms\Security;

defined( 'ABSPATH' ) || exit;

final class TwoFactor {

	public const CAPABILITIES = array( 'manage_options', 'manage_woocommerce', 'edit_shop_orders' );

	/**
	 * Detectable 2FA plugins.
	 *
	 * @return array<string, bool> Label => active.
	 */
	public static function providers(): array {
		return array(
			'Two Factor'               => class_exists( 'Two_Factor_Core' ),
			'Wordfence Login Security' => class_exists( '\WordfenceLS\Controller_Users' ),
			'WP 2FA'                   => class_exists( '\WP2FA\WP2FA' ) || defined( 'WP_2FA_VERSION' ),
			'Solid Security'           => class_exists( 'ITSEC_Core' ),
		);
	}

	public static function any_provider_active(): bool {
		return in_array( true, self::providers(), true );
	}

	public static function is_relevant( \WP_User $user ): bool {
		foreach ( self::CAPABILITIES as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	public static function user_has_2fa( \WP_User $user ): bool {
		$has = false;
		$id  = (int) $user->ID;

		// Two Factor (and Solid Security, which builds on it).
		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
			$has = (bool) \Two_Factor_Core::is_user_using_two_factor( $id );
		}

		// Wordfence Login Security.
		if ( ! $has && class_exists( '\WordfenceLS\Controller_Users' ) ) {
			try {
				$controller = \WordfenceLS\Controller_Users::shared();
				$has        = method_exists( $controller, 'has_2fa_active' ) && (bool) $controller->has_2fa_active( $user );
			} catch ( \Throwable $e ) {
				$has = false;
			}
		}

		// WP 2FA (Melapress).
		if ( ! $has && ( class_exists( '\WP2FA\WP2FA' ) || defined( 'WP_2FA_VERSION' ) ) ) {
			if ( class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) && method_exists( '\WP2FA\Admin\Helpers\User_Helper', 'is_user_using_two_factor' ) ) {
				$has = (bool) \WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $id );
			} else {
				$has = '' !== (string) get_user_meta( $id, 'wp_2fa_enabled_methods', true );
			}
		}

		// Solid Security (iThemes).
		if ( ! $has && class_exists( 'ITSEC_Core' ) ) {
			if ( class_exists( 'ITSEC_Two_Factor' ) && method_exists( 'ITSEC_Two_Factor', 'get_instance' ) ) {
				$itsec = \ITSEC_Two_Factor::get_instance();
				if ( method_exists( $itsec, 'is_user_using_two_factor' ) ) {
					$has = (bool) $itsec->is_user_using_two_factor( $id );
				}
			}
			if ( ! $has ) {
				$providers = get_user_meta( $id, '_two_factor_enabled_providers', true );
				$has       = is_array( $providers ) && array() !== $providers;
			}
		}

		/**
		 * Whether a user has two-factor authentication (for 2FA plugins BusinessAPI Forms does not detect).
		 *
		 * @param bool     $has  Detected result.
		 * @param \WP_User $user User.
		 */
		return (bool) apply_filters( 'bapi_forms_user_has_2fa', $has, $user );
	}

	public static function compliant( \WP_User $user ): bool {
		return ! self::is_relevant( $user ) || self::user_has_2fa( $user );
	}

	/**
	 * Users who need 2FA but do not have it.
	 *
	 * @return list<\WP_User>
	 */
	public static function missing(): array {
		$users = get_users(
			array(
				'capability__in' => self::CAPABILITIES,
				'number'         => 200,
				'fields'         => 'all',
			)
		);
		return array_values( array_filter( $users, static fn( \WP_User $u ): bool => ! self::user_has_2fa( $u ) ) );
	}
}
