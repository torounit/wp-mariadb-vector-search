<?php
/**
 * Content hash utility.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Produces a stable fingerprint for a post's indexable content.
 *
 * The hash is stored alongside each indexed post so that unchanged content
 * is not re-embedded on every save.
 */
class Content_Hash {

	/**
	 * Compute a SHA-256 hex digest of the title and body.
	 *
	 * A length-prefixed separator is used so that ("AB", "") ≠ ("A", "B").
	 *
	 * @param string $title Post title.
	 * @param string $body  Raw post content (may contain HTML/blocks).
	 * @return string 64-character lowercase hex string.
	 */
	public static function compute( string $title, string $body ): string {
		$payload = strlen( $title ) . ':' . $title . "\x00" . $body;
		return hash( 'sha256', $payload );
	}
}
