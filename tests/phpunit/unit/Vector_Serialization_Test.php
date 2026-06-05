<?php
/**
 * Unit tests for vector literal serialization.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WP_MariaDB_Vector_Search\Repository;

/**
 * Class Vector_Serialization_Test
 */
class Vector_Serialization_Test extends \WP_UnitTestCase {

	/** Simple float vector serializes to bracketed comma-separated string. */
	public function test_serializes_a_simple_vector(): void {
		$result = Repository::format_vector_literal( array( 0.1, 0.2, 0.3 ) );
		$this->assertSame( '[0.1,0.2,0.3]', $result );
	}

	/** Output uses '.' as decimal separator regardless of LC_NUMERIC locale. */
	public function test_is_locale_independent(): void {
		$original = setlocale( LC_NUMERIC, '0' );
		setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German' );

		$result = Repository::format_vector_literal( array( 1.5, 2.5 ) );

		setlocale( LC_NUMERIC, $original );

		// The only commas in the output are element separators, not decimal points.
		$this->assertSame( '[1.5,2.5]', $result );
	}

	/** Output retains at least 7 significant decimal digits. */
	public function test_preserves_sufficient_precision(): void {
		$result = Repository::format_vector_literal( array( 0.123456789 ) );
		// Should have at least 7 significant decimal digits.
		$this->assertMatchesRegularExpression( '/\[0\.1234567/', $result );
	}

	/** Zero and negative values are serialized correctly. */
	public function test_handles_zero_and_negative_values(): void {
		$result = Repository::format_vector_literal( array( 0.0, -1.0, 1.0 ) );
		$this->assertSame( '[0,-1,1]', $result );
	}

	/** NAN input throws InvalidArgumentException. */
	public function test_rejects_nan(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( array( NAN ) );
	}

	/** INF input throws InvalidArgumentException. */
	public function test_rejects_infinite_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( array( INF ) );
	}

	/** Negative INF input throws InvalidArgumentException. */
	public function test_rejects_negative_infinite_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( array( -INF ) );
	}

	/** Empty array input throws InvalidArgumentException. */
	public function test_rejects_empty_array(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( array() );
	}
}
