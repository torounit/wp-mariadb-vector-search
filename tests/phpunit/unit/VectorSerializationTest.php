<?php
/**
 * Unit tests for vector literal serialization.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_MariaDB_Vector_Search\Repository;

class VectorSerializationTest extends TestCase {

	/** @test */
	public function it_serializes_a_simple_vector(): void {
		$result = Repository::format_vector_literal( [ 0.1, 0.2, 0.3 ] );
		$this->assertSame( '[0.1,0.2,0.3]', $result );
	}

	/** @test */
	public function it_is_locale_independent(): void {
		$original = setlocale( LC_NUMERIC, '0' );
		setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German' );

		$result = Repository::format_vector_literal( [ 1.5, 2.5 ] );

		setlocale( LC_NUMERIC, $original );

		// The only commas in the output are element separators, not decimal points.
		$this->assertSame( '[1.5,2.5]', $result );
	}

	/** @test */
	public function it_preserves_sufficient_precision(): void {
		$vec    = [ 0.123456789 ];
		$result = Repository::format_vector_literal( $vec );
		// Should have at least 6 significant digits.
		$this->assertMatchesRegularExpression( '/\[0\.1234567/', $result );
	}

	/** @test */
	public function it_handles_zero_and_negative_values(): void {
		$result = Repository::format_vector_literal( [ 0.0, -1.0, 1.0 ] );
		$this->assertSame( '[0,-1,1]', $result );
	}

	/** @test */
	public function it_rejects_nan(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( [ NAN ] );
	}

	/** @test */
	public function it_rejects_infinite_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( [ INF ] );
	}

	/** @test */
	public function it_rejects_negative_infinite_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( [ -INF ] );
	}

	/** @test */
	public function it_rejects_empty_array(): void {
		$this->expectException( \InvalidArgumentException::class );
		Repository::format_vector_literal( [] );
	}
}
