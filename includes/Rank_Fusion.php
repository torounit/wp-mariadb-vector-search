<?php
/**
 * Reciprocal rank fusion utility.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Combines multiple ranked ID lists into a single ranked list.
 *
 * Used to merge vector similarity results with WordPress's default
 * LIKE-based search results without needing comparable numeric scores
 * from either source.
 */
class Rank_Fusion {

	/**
	 * Fuse multiple ranked ID lists using Reciprocal Rank Fusion (RRF).
	 *
	 * @param array<int, array<int, int|string>> $ranked_lists Ranked lists of post IDs, best first.
	 * @param int                                $k            RRF constant. Higher values flatten the
	 *                                                          influence of top ranks. Default 60.
	 * @return int[] Post IDs ordered by descending fused score (union of all input lists).
	 */
	public static function fuse( array $ranked_lists, int $k = 60 ): array {
		$scores = self::scores( $ranked_lists, $k );
		arsort( $scores );
		return array_map( 'intval', array_keys( $scores ) );
	}

	/**
	 * Compute the raw RRF score for each ID across the given ranked lists.
	 *
	 * Duplicate IDs within a single list are deduplicated, keeping the
	 * earliest (best) rank.
	 *
	 * @param array<int, array<int, int|string>> $ranked_lists Ranked lists of post IDs, best first.
	 * @param int                                $k            RRF constant.
	 * @return array<int, float> Map of post ID to fused score.
	 */
	public static function scores( array $ranked_lists, int $k = 60 ): array {
		$scores = array();

		foreach ( $ranked_lists as $list ) {
			$seen = array();
			$rank = 0;
			foreach ( $list as $id ) {
				$id = (int) $id;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				++$rank;

				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + 1.0 / ( $k + $rank );
			}
		}

		return $scores;
	}
}
