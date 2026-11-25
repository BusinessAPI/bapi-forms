<?php
/**
 * Remove plugin settings on uninstall (every site of a multisite network).
 *
 * @package BapiForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function bapi_forms_uninstall_site(): void {
	global $wpdb;

	delete_option( 'bapi_forms_settings' );
	// Carrier products stay (past orders reference them) but go private, then their ids are forgotten.
	foreach ( array_map( 'intval', (array) get_option( 'bapi_forms_carriers', array() ) ) as $bapi_forms_id ) {
		if ( $bapi_forms_id > 0 && 'publish' === get_post_status( $bapi_forms_id ) ) {
			wp_update_post(
				array(
					'ID'          => $bapi_forms_id,
					'post_status' => 'private',
				)
			);
		}
	}
	delete_option( 'bapi_forms_carriers' );
	// Pay-first products likewise.
	foreach ( array_map( 'intval', (array) get_option( 'bapi_forms_pay_first_products', array() ) ) as $bapi_forms_id ) {
		if ( $bapi_forms_id > 0 && in_array( get_post_status( $bapi_forms_id ), array( 'publish', 'draft' ), true ) ) {
			wp_update_post(
				array(
					'ID'          => $bapi_forms_id,
					'post_status' => 'private',
				)
			);
		}
	}
	delete_option( 'bapi_forms_pay_first_products' );
	delete_option( 'bapi_forms_pay_first_synced' );
	delete_option( 'bapi_forms_resume_jobs' );
	delete_option( 'bapi_forms_ip_refused' );
	delete_option( 'bapi_forms_integrity' );
	delete_option( 'bapi_forms_audit_queue' );
	// The endpoints themselves stay in Business API until the key is revoked.
	delete_option( 'bapi_forms_webhooks' );
	// Customers' draft refs. User meta is network-wide on multisite; the key is ours alone.
	delete_metadata( 'user', 0, '_bapi_forms_drafts', '', true );

	// Everything else we keep in wp_options is prefixed bapi_forms_.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bapi\\_forms\\_%' OR option_name LIKE '\\_transient\\_bapi\\_forms\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bapi\\_forms\\_%'"
	);
	// phpcs:enable
	foreach ( (array) $names as $name ) {
		if ( str_starts_with( $name, '_transient_timeout_' ) ) {
			continue; // Removed with its transient.
		}
		if ( str_starts_with( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		} else {
			delete_option( $name );
		}
	}

	bapi_forms_uninstall_actions();
	// WP-Cron fallback of the account status refresh.
	wp_unschedule_hook( 'bapi_forms_refresh_account_status' );
}

/**
 * Remove our Action Scheduler actions. Without WooCommerce loaded, delete our rows from its
 * tables directly.
 */
function bapi_forms_uninstall_actions(): void {
	global $wpdb;

	if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
		as_unschedule_all_actions( '', array(), 'bapi-forms' );
		return;
	}

	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$groups  = $wpdb->prefix . 'actionscheduler_groups';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Action Scheduler tables while WooCommerce is inactive.
	if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) {
		return;
	}
	$has_logs = $logs === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs ) ) );
	$group_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, 'bapi-forms' ) );
	$ids      = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT action_id FROM %i WHERE group_id = %d OR hook LIKE %s',
			$actions,
			$group_id > 0 ? $group_id : -1,
			$wpdb->esc_like( 'bapi_forms_' ) . '%'
		)
	);
	foreach ( array_chunk( array_map( 'intval', (array) $ids ), 500 ) as $chunk ) {
		$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		if ( $has_logs ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %d placeholders.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ({$in})", $logs, ...$chunk ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %d placeholders.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ({$in})", $actions, ...$chunk ) );
	}
	if ( $group_id > 0 ) {
		$wpdb->delete( $groups, array( 'group_id' => $group_id ), array( '%d' ) );
	}
	// phpcs:enable
}

if ( is_multisite() ) {
	$bapi_forms_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $bapi_forms_sites as $bapi_forms_site_id ) {
		switch_to_blog( (int) $bapi_forms_site_id );
		bapi_forms_uninstall_site();
		restore_current_blog();
	}
} else {
	bapi_forms_uninstall_site();
}
