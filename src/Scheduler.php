<?php
/**
 * Action Scheduler helpers.
 *
 * @package BapiForms
 */

namespace BapiForms;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	/** Action Scheduler group of every action the plugin schedules. */
	public const GROUP = 'bapi-forms';

	/**
	 * Whether a pending action exists for the hook (and args, when given).
	 *
	 * as_has_scheduled_action() also counts the running action, so a retry chain would see
	 * itself and stop. Batch chains that must not overlap a running batch keep using it.
	 *
	 * @param array|null $args Exact args, or null for any.
	 */
	public static function pending( string $hook, ?array $args, string $group ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, $group );
		}
		$query = array(
			'hook'     => $hook,
			'group'    => $group,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		);
		if ( null !== $args ) {
			$query['args'] = $args;
		}
		return array() !== (array) as_get_scheduled_actions( $query, 'ids' );
	}
}
