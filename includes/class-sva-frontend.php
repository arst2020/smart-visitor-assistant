<?php
/**
 * Front-end chat widget loader.
 */
defined( 'ABSPATH' ) || exit;

class SVA_Frontend {

	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		$s = SVA_Plugin::settings();
		if ( empty( $s['enabled'] ) || is_feed() || is_embed() || ! apply_filters( 'sva_show_widget', true ) ) {
			return;
		}
		wp_enqueue_style( 'sva-widget', SVA_URL . 'assets/css/widget.css', array(), SVA_VERSION );
		wp_enqueue_script( 'sva-widget', SVA_URL . 'assets/js/widget.js', array(), SVA_VERSION, true );
		wp_add_inline_script( 'sva-widget', 'window.SVA_CONFIG = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	public static function config() {
		$s    = SVA_Plugin::settings();
		$lang = SVA_Plugin::site_lang();
		$str  = array();
		foreach ( array( 'placeholder', 'send', 'typing', 'open', 'close', 'error', 'reset', 'online', 'view' ) as $k ) {
			$str[ $k ] = SVA_I18n::t( $k, $lang );
		}
		$color = sanitize_hex_color( $s['color'] );
		return array(
			'rest'     => esc_url_raw( rest_url( 'sva/v1/chat' ) ),
			'name'     => SVA_Plugin::assistant_name( $lang ),
			'welcome'  => SVA_Chat::greeting( $lang ),
			'quick'    => SVA_Chat::quick_replies( $lang ),
			'color'    => $color ? $color : '#2563eb',
			'position' => 'left' === $s['position'] ? 'left' : 'right',
			'rtl'      => is_rtl() || 'fa' === $lang,
			'lang'     => $lang,
			'strings'  => $str,
		);
	}
}
