<?php
/**
 * Sends a PDF held in memory straight to the browser: no disk write, nothing cached on the way.
 *
 * @package BapiForms
 */

namespace BapiForms\Documents;

defined( 'ABSPATH' ) || exit;

final class Pdf {

	/** Exits. */
	public static function send( string $body, string $filename ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $filename ) . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0' );
		self::no_store_headers();
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/** CDNs and reverse proxies that ignore Cache-Control. */
	public static function no_store_headers(): void {
		header( 'CDN-Cache-Control: no-store' );
		header( 'Surrogate-Control: no-store' );
	}
}
