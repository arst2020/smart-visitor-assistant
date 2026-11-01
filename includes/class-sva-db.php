<?php
/**
 * Database tables: knowledge index and chat log.
 */
defined( 'ABSPATH' ) || exit;

class SVA_DB {

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sva_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::t( 'index' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type varchar(40) NOT NULL DEFAULT '',
  chunk smallint(5) unsigned NOT NULL DEFAULT 0,
  title text NOT NULL,
  url text NOT NULL,
  body longtext NOT NULL,
  norm longtext NOT NULL,
  meta longtext NULL,
  updated datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY object_key (object_type,object_id)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'logs' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  session varchar(64) NOT NULL DEFAULT '',
  question text NOT NULL,
  answer text NOT NULL,
  intent varchar(40) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created (created)
) $c;"
		);
		update_option( 'sva_db_version', SVA_DB_VERSION );
	}

	public static function delete_object( $type, $id ) {
		global $wpdb;
		$wpdb->delete( self::t( 'index' ), array( 'object_type' => $type, 'object_id' => (int) $id ), array( '%s', '%d' ) );
	}

	/** Replace all chunks of one object. */
	public static function replace_object( $type, $id, $title, $url, $chunks, $meta = array() ) {
		global $wpdb;
		self::delete_object( $type, $id );
		$now = current_time( 'mysql', true );
		foreach ( array_values( $chunks ) as $i => $chunk ) {
			$wpdb->insert(
				self::t( 'index' ),
				array(
					'object_id'   => (int) $id,
					'object_type' => $type,
					'chunk'       => $i,
					'title'       => (string) $title,
					'url'         => (string) $url,
					'body'        => (string) $chunk,
					'norm'        => SVA_Text::normalize( $title . ' ' . $chunk ),
					'meta'        => wp_json_encode( $meta ),
					'updated'     => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	public static function delete_older_than( $gmt_datetime ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'index' ) . ' WHERE updated < %s', $gmt_datetime ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function clear_index() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::t( 'index' ) ); // phpcs:ignore
	}

	public static function count_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'index' ) ); // phpcs:ignore
	}

	public static function counts_by_type() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT object_type, COUNT(DISTINCT object_id) AS n FROM ' . self::t( 'index' ) . ' GROUP BY object_type' ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->object_type ] = (int) $r->n;
		}
		return $out;
	}

	public static function object_text( $type, $id, $limit = 6000 ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT body FROM ' . self::t( 'index' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY chunk ASC', $type, $id ) ); // phpcs:ignore
		return mb_substr( implode( "\n", (array) $rows ), 0, $limit );
	}

	public static function log( $session, $question, $answer, $intent, $mode ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'logs' ),
			array(
				'created'  => current_time( 'mysql', true ),
				'session'  => substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $session ), 0, 64 ),
				'question' => mb_substr( $question, 0, 1000 ),
				'answer'   => mb_substr( $answer, 0, 2000 ),
				'intent'   => substr( (string) $intent, 0, 40 ),
				'mode'     => substr( (string) $mode, 0, 10 ),
			)
		);
		// Keep the log table small.
		if ( 1 === wp_rand( 1, 50 ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'logs' ) . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		}
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( 'index' ) ); // phpcs:ignore
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( 'logs' ) ); // phpcs:ignore
	}
}
