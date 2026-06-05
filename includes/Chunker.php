<?php
/**
 * Text chunker for embedding generation.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Splits post body text into overlapping chunks suitable for embedding.
 *
 * Each chunk is prefixed with the post title so that isolated chunks
 * retain topical context.
 */
class Chunker {

	/**
	 * Constructor.
	 *
	 * @param int $chunk_size  Target body length per chunk in characters (default 2000).
	 * @param int $overlap     How many characters of the previous chunk to repeat at the
	 *                         start of the next one (default 300).
	 */
	public function __construct(
		private int $chunk_size = 2000,
		private int $overlap = 300,
	) {}

	/**
	 * Split $body into overlapping chunks, each prefixed with $title.
	 *
	 * @param string $body  Raw post body (may contain HTML / block markup).
	 * @param string $title Post title prepended to every chunk.
	 * @return string[] Non-empty array of chunk strings.
	 */
	public function chunk( string $body, string $title ): array {
		$body = wp_strip_all_tags( $body );
		$body = trim( $body );

		if ( '' === $body ) {
			return array( $title );
		}

		$body_chunks = $this->split_body( $body );

		return array_map(
			static fn( string $c ): string => $title . "\n\n" . $c,
			$body_chunks
		);
	}

	/**
	 * Split plain text into overlapping body chunks.
	 *
	 * @param string $text Non-empty plain text.
	 * @return string[]
	 */
	private function split_body( string $text ): array {
		if ( mb_strlen( $text ) <= $this->chunk_size ) {
			return array( $text );
		}

		$chunks = array();
		$offset = 0;
		$length = mb_strlen( $text );

		while ( $offset < $length ) {
			$max_end = $offset + $this->chunk_size;

			if ( $max_end >= $length ) {
				$chunks[] = mb_substr( $text, $offset );
				break;
			}

			$break    = $this->find_break( $text, $offset, $max_end );
			$chunks[] = mb_substr( $text, $offset, $break - $offset );

			// Next chunk overlaps by starting $overlap chars before the break.
			$offset = max( $offset + 1, $break - $this->overlap );
		}

		return array_values(
			array_filter( $chunks, static fn( string $c ): bool => '' !== $c )
		);
	}

	/**
	 * Find the best split position at or before $max_end, searching from the
	 * midpoint of the current chunk to avoid over-splitting short paragraphs.
	 *
	 * Preference order: paragraph break → sentence end → word boundary → hard cut.
	 *
	 * @param string $text    Full text.
	 * @param int    $start   Start offset of current chunk.
	 * @param int    $max_end Maximum end offset (exclusive).
	 * @return int  Position in $text after which to cut (≤ $max_end).
	 */
	private function find_break( string $text, int $start, int $max_end ): int {
		$search_from   = $start + intval( $this->chunk_size * 0.5 );
		$search_region = mb_substr( $text, $search_from, $max_end - $search_from );

		$pos = mb_strrpos( $search_region, "\n\n" );
		if ( false !== $pos ) {
			return $search_from + $pos + 2;
		}

		$pos = mb_strrpos( $search_region, '。' ); // Japanese full stop.
		if ( false !== $pos ) {
			return $search_from + $pos + 1;
		}

		$pos = mb_strrpos( $search_region, '. ' );
		if ( false !== $pos ) {
			return $search_from + $pos + 2;
		}

		$pos = mb_strrpos( $search_region, ' ' );
		if ( false !== $pos ) {
			return $search_from + $pos + 1;
		}

		return $max_end;
	}
}
