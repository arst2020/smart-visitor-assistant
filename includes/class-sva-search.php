<?php
/**
 * Keyword retrieval over the knowledge index (Persian-aware, no external service needed).
 */
defined( 'ABSPATH' ) || exit;

class SVA_Search {

	/**
	 * @param string|array $query  Text or token list.
	 * @param array        $args   limit, types, exclude_types.
	 * @return array[] Each: type, id, title, url, body, score, coverage, meta.
	 */
	public static function search( $query, $args = array() ) {
		global $wpdb;
		$args   = wp_parse_args( $args, array( 'limit' => 5, 'types' => array(), 'exclude_types' => array( 'sva_faq' ) ) );
		$tokens = is_array( $query ) ? $query : SVA_Text::tokens( $query );
		$tokens = array_slice( array_values( array_unique( $tokens ) ), 0, 8 );
		if ( ! $tokens ) {
			return array();
		}
		$table  = SVA_DB::t( 'index' );
		$likes  = array();
		$params = array();
		foreach ( $tokens as $tok ) {
			$likes[]  = 'norm LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $tok ) . '%';
		}
		$where = '(' . implode( ' OR ', $likes ) . ')';
		if ( $args['types'] ) {
			$where .= ' AND object_type IN (' . implode( ',', array_fill( 0, count( $args['types'] ), '%s' ) ) . ')';
			$params = array_merge( $params, array_values( $args['types'] ) );
		}
		if ( $args['exclude_types'] ) {
			$where .= ' AND object_type NOT IN (' . implode( ',', array_fill( 0, count( $args['exclude_types'] ), '%s' ) ) . ')';
			$params = array_merge( $params, array_values( $args['exclude_types'] ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id, object_type, chunk, title, url, body, norm, meta FROM $table WHERE $where LIMIT 500", $params ) ); // phpcs:ignore
		if ( ! $rows ) {
			return array();
		}

		$total = max( 1, SVA_DB::count_rows() );
		$df    = array_fill_keys( $tokens, 0 );
		foreach ( $rows as $r ) {
			foreach ( $tokens as $tok ) {
				if ( false !== strpos( $r->norm, $tok ) ) {
					$df[ $tok ]++;
				}
			}
		}
		// Words that appear nowhere on the site ("میکشه", typos) should not lower coverage.
		$effective = max( 1, count( array_filter( $df ) ) );
		$best      = array();
		foreach ( $rows as $r ) {
			$norm    = ' ' . $r->norm . ' ';
			$tnorm   = ' ' . SVA_Text::normalize( $r->title ) . ' ';
			$score   = 0;
			$matched = 0;
			foreach ( $tokens as $tok ) {
				$c = substr_count( $norm, ' ' . $tok );          // Word or word-prefix match.
				if ( ! $c && mb_strlen( $tok ) >= 4 ) {
					$c = 0.3 * substr_count( $norm, $tok );      // Inside a word (long tokens only).
				}
				if ( $c <= 0 ) {
					continue;
				}
				$matched++;
				$idf    = log( 1 + $total / ( 1 + $df[ $tok ] ) );
				$score += $idf * ( 1 + log( 1 + $c ) );
				if ( false !== strpos( $tnorm, ' ' . $tok ) ) {
					$score += 2.5 * $idf;                         // Title hit.
				}
			}
			if ( ! $matched ) {
				continue;
			}
			$coverage = $matched / $effective;
			$score   *= ( 0.4 + $coverage );
			if ( 'sva_custom' === $r->object_type ) {
				$score *= 1.2;
			}
			$key = $r->object_type . ':' . $r->object_id;
			if ( ! isset( $best[ $key ] ) || $score > $best[ $key ]['score'] ) {
				$best[ $key ] = array(
					'type'     => $r->object_type,
					'id'       => (int) $r->object_id,
					'title'    => $r->title,
					'url'      => $r->url,
					'body'     => $r->body,
					'score'    => round( $score, 3 ),
					'coverage' => $coverage,
					'meta'     => json_decode( (string) $r->meta, true ),
				);
			}
		}
		uasort(
			$best,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( array_values( $best ), 0, (int) $args['limit'] );
	}
}
