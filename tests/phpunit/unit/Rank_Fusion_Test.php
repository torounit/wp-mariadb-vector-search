<?php
/**
 * Unit tests for reciprocal rank fusion.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WP_MariaDB_Vector_Search\Rank_Fusion;

/**
 * Class Rank_Fusion_Test
 */
class Rank_Fusion_Test extends \WP_UnitTestCase {

	/** An empty list of ranked lists fuses to an empty result. */
	public function test_no_lists_returns_empty_array(): void {
		$this->assertSame( array(), Rank_Fusion::fuse( array() ) );
	}

	/** Lists containing only empty arrays fuse to an empty result. */
	public function test_only_empty_lists_returns_empty_array(): void {
		$this->assertSame( array(), Rank_Fusion::fuse( array( array(), array() ) ) );
	}

	/** A single ranked list keeps its original order (RRF score decreases monotonically with rank). */
	public function test_single_list_preserves_order(): void {
		$this->assertSame( array( 1, 2, 3 ), Rank_Fusion::fuse( array( array( 1, 2, 3 ) ) ) );
	}

	/** An ID ranked first in both lists outranks everything else. */
	public function test_id_ranked_first_in_both_lists_wins(): void {
		$result = Rank_Fusion::fuse( array( array( 1, 2, 3 ), array( 1, 4, 5 ) ) );
		$this->assertSame( 1, $result[0] );
	}

	/** The fused result is the union of all input lists. */
	public function test_result_is_union_of_input_lists(): void {
		$result = Rank_Fusion::fuse( array( array( 1, 2 ), array( 3, 4 ) ) );
		sort( $result );
		$this->assertSame( array( 1, 2, 3, 4 ), $result );
	}

	/** An ID present in multiple lists outranks an ID present in only one. */
	public function test_id_in_multiple_lists_outranks_id_in_one_list(): void {
		$result = Rank_Fusion::fuse( array( array( 1, 2 ), array( 3, 1 ) ) );
		$this->assertSame( 1, $result[0] );
	}

	/** Duplicate IDs within a single list do not inflate the score beyond a true match in another list. */
	public function test_duplicate_ids_within_a_list_are_deduplicated(): void {
		$result = Rank_Fusion::fuse( array( array( 1, 1, 2 ), array( 2 ) ) );
		sort( $result );
		$this->assertSame( array( 1, 2 ), $result );
	}

	/** A smaller k weights top ranks more heavily relative to lower ranks. */
	public function test_smaller_k_increases_score_for_top_rank(): void {
		$scores_small_k = Rank_Fusion::scores( array( array( 1 ) ), 1 );
		$scores_large_k = Rank_Fusion::scores( array( array( 1 ) ), 100 );
		$this->assertGreaterThan( $scores_large_k[1], $scores_small_k[1] );
	}

	/** Returned IDs are normalized to integers. */
	public function test_returned_ids_are_integers(): void {
		$result = Rank_Fusion::fuse( array( array( '1', '2' ) ) );
		$this->assertSame( array( 1, 2 ), $result );
	}

	/** A negative k (e.g. via the rrf_k filter) does not cause division by zero. */
	public function test_negative_k_does_not_divide_by_zero(): void {
		$scores = Rank_Fusion::scores( array( array( 1, 2 ) ), -1 );
		$this->assertSame( array( 1, 2 ), array_keys( $scores ) );
	}

	/** A very negative k is also clamped safely. */
	public function test_very_negative_k_does_not_divide_by_zero(): void {
		$result = Rank_Fusion::fuse( array( array( 1, 2 ) ), -100 );
		$this->assertSame( array( 1, 2 ), $result );
	}
}
