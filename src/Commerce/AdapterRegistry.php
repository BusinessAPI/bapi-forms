<?php
/**
 * Approved commerce adapters.
 *
 * @package BapiForms
 */

namespace BapiForms\Commerce;

defined( 'ABSPATH' ) || exit;

final class AdapterRegistry {

	/** @var array<string, Adapter>|null */
	private ?array $adapters = null;

	/**
	 * @return array<string, Adapter>
	 */
	public function all(): array {
		if ( null === $this->adapters ) {
			$this->adapters = array();
			foreach ( array( new WooCommerceAdapter() ) as $adapter ) {
				$this->adapters[ $adapter->id() ] = $adapter;
			}
		}
		return $this->adapters;
	}

	/**
	 * @return array<string, Adapter>
	 */
	public function active(): array {
		return array_filter( $this->all(), static fn( Adapter $adapter ): bool => $adapter->is_available() );
	}

	/** Adapter that handles checkout when several are active (first approved one). */
	public function primary(): ?Adapter {
		$active = $this->active();
		return $active ? reset( $active ) : null;
	}

	public function has_active(): bool {
		return array() !== $this->active();
	}

	/** Comma separated names of approved plugins, for notices. */
	public function approved_labels(): string {
		return implode( ', ', array_map( static fn( Adapter $adapter ): string => $adapter->label(), $this->all() ) );
	}
}
