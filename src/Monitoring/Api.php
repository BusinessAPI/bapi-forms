<?php
/**
 * Business API entity monitoring endpoints. Entity names and events are read live and never
 * stored; only ids, environment and dates go into order meta.
 *
 * @package BapiForms
 */

namespace BapiForms\Monitoring;

use BapiForms\Api\Client;
use BapiForms\Api\Response;

defined( 'ABSPATH' ) || exit;

final class Api {

	public function __construct( private readonly Client $client ) {}

	/** One entity: {id, acn, abn, entityName, status, startsAt, expiresAt, daysRemaining, ...}. */
	public function entity( int $id, string $env ): Response {
		return $this->client->get( 'monitoring/entities/' . $id, array(), $env );
	}

	/**
	 * A page of the partner's entities.
	 *
	 * @param array<string, scalar> $filters status, expiresBefore (Y-m-d), perPage, page.
	 */
	public function entities( array $filters, string $env ): Response {
		return $this->client->get( 'monitoring/entities', array_map( 'strval', $filters ), $env );
	}

	/**
	 * Entities by id, 100 per call. Only ids asked for are kept, in case BAPI ignores the filter.
	 *
	 * @param list<int> $ids Entity ids.
	 * @return array<int, array<string, mixed>>|null id => entity, or null when a call failed.
	 */
	public function entities_by_ids( array $ids, string $env ): ?array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( $id ) => $id > 0 ) ) );
		$out = array();
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$response = $this->entities(
				array(
					'ids'     => implode( ',', $chunk ),
					'perPage' => 100,
				),
				$env
			);
			if ( ! $response->ok() ) {
				return null;
			}
			foreach ( (array) ( $response->data['data'] ?? array() ) as $entity ) {
				$id = is_array( $entity ) ? (int) ( $entity['id'] ?? 0 ) : 0;
				if ( in_array( $id, $chunk, true ) ) {
					$out[ $id ] = $entity;
				}
			}
		}
		return $out;
	}

	/** One entity's events, newest first. */
	public function events( int $id, string $env, int $per_page = 5 ): Response {
		return $this->client->get( 'monitoring/entities/' . $id . '/events', array( 'perPage' => (string) $per_page ), $env );
	}

	/** Every event after event id $since, oldest first. */
	public function events_since( int $since, string $env, int $page = 1, int $per_page = 100 ): Response {
		$query = array(
			'perPage' => (string) $per_page,
			'page'    => (string) $page,
		);
		if ( $since > 0 ) {
			$query['since'] = (string) $since;
		}
		return $this->client->get( 'monitoring/events', $query, $env );
	}

	/** Buy the next 12 months. The Idempotency-Key stops a retry buying a second year (BAPI keeps it 24 hours). */
	public function renew( int $id, string $env, string $idempotency_key ): Response {
		return $this->client->request( 'POST', 'monitoring/entities/renew', array(), array( 'ids' => array( $id ) ), $env, array( 'Idempotency-Key' => $idempotency_key ) );
	}

	/** Free: a stopped entity with paid time left starts again. */
	public function resume( int $id, string $env ): Response {
		return $this->client->post( 'monitoring/entities/' . $id . '/resume', array(), $env );
	}

	/** Stop early (no refund); idempotent. */
	public function stop( int $id, string $env ): Response {
		return $this->client->delete( 'monitoring/entities/' . $id, $env );
	}

	/**
	 * The entity report PDF, held in memory only (see Client::pdf()).
	 *
	 * @return array{status: int, body: string, code: string, filename: string}
	 */
	public function report( int $id, string $env ): array {
		return $this->client->pdf( 'monitoring/entities/' . $id . '/report', $env ) + array(
			'filename' => 'entity-report-' . $id . '-' . gmdate( 'Y-m-d' ) . '.pdf',
		);
	}
}
