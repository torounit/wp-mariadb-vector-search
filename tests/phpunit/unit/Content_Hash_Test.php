<?php
/**
 * Unit tests for content hashing.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WP_MariaDB_Vector_Search\Content_Hash;

/**
 * Class Content_Hash_Test
 */
class Content_Hash_Test extends \WP_UnitTestCase {

	/** Hash is a 64-character lowercase hex string. */
	public function test_returns_64_char_hex_string(): void {
		$hash = Content_Hash::compute( 'Title', 'Body' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	/** Same inputs always produce the same hash. */
	public function test_same_input_returns_same_hash(): void {
		$hash1 = Content_Hash::compute( 'Title', 'Body' );
		$hash2 = Content_Hash::compute( 'Title', 'Body' );
		$this->assertSame( $hash1, $hash2 );
	}

	/** Different titles produce different hashes. */
	public function test_different_title_changes_hash(): void {
		$hash1 = Content_Hash::compute( 'Title A', 'Body' );
		$hash2 = Content_Hash::compute( 'Title B', 'Body' );
		$this->assertNotSame( $hash1, $hash2 );
	}

	/** Different bodies produce different hashes. */
	public function test_different_body_changes_hash(): void {
		$hash1 = Content_Hash::compute( 'Title', 'Body A' );
		$hash2 = Content_Hash::compute( 'Title', 'Body B' );
		$this->assertNotSame( $hash1, $hash2 );
	}

	/** Empty strings are valid inputs. */
	public function test_empty_strings_are_accepted(): void {
		$hash = Content_Hash::compute( '', '' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	/** Title and body values are not interchangeable (collision-safe concatenation). */
	public function test_title_and_body_not_interchangeable(): void {
		// "AB" + "" must differ from "A" + "B" to avoid collision.
		$hash1 = Content_Hash::compute( 'AB', '' );
		$hash2 = Content_Hash::compute( 'A', 'B' );
		$this->assertNotSame( $hash1, $hash2 );
	}
}
