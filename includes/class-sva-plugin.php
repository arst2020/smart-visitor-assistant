<?php
/**
 * Core: settings, activation, merged site profile.
 */
defined( 'ABSPATH' ) || exit;

class SVA_Plugin {

	const OPT     = 'sva_settings';
	const PROFILE = 'sva_profile';

	public static function defaults() {
		return array(
			'enabled'            => 1,
			'assistant_name'     => '',
			'welcome'            => '',
			'color'              => '#2563eb',
			'position'           => 'right',
			'show_prices'        => 'auto',       // auto | yes | no.
			'mode'               => 'local',      // local | ai.
			'provider'           => 'anthropic',  // anthropic | openai.
			'api_key'            => '',
			'api_base'           => 'https://api.openai.com/v1',
			'model'              => '',
			'max_tokens'         => 600,
			'custom_knowledge'   => '',
			'faq'                => '',
			'ai_instructions'    => '',
			'logging'            => 1,
			'rate_limit'         => 15,
			'site_type_override' => '',
			'key_overrides'      => array(),
			'contact_phone'      => '',
			'contact_email'      => '',
			'contact_address'    => '',
			'contact_hours'      => '',
		);
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function init() {
		if ( get_option( 'sva_db_version' ) !== SVA_DB_VERSION ) {
			SVA_DB::install();
		}
		// After an update, re-scan in the background so the index uses the new format.
		if ( get_option( 'sva_version' ) !== SVA_VERSION ) {
			update_option( 'sva_version', SVA_VERSION );
			if ( get_option( self::PROFILE ) && ! wp_next_scheduled( 'sva_cron_scan', array( 'start' ) ) ) {
				wp_schedule_single_event( time() + 30, 'sva_cron_scan', array( 'start' ) );
			}
		}
		SVA_Analyzer::hooks();
		SVA_Chat::hooks();
		SVA_Frontend::hooks();
		if ( is_admin() ) {
			SVA_Admin::hooks();
		}
	}

	public static function activate() {
		SVA_DB::install();
		add_option( self::OPT, self::defaults() );
		if ( ! wp_next_scheduled( 'sva_cron_scan', array( 'start' ) ) ) {
			wp_schedule_single_event( time() + 10, 'sva_cron_scan', array( 'start' ) );
		}
		if ( ! wp_next_scheduled( 'sva_weekly_scan' ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'sva_weekly_scan' );
		}
		set_transient( 'sva_activated', 1, 300 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'sva_weekly_scan' );
		wp_unschedule_hook( 'sva_cron_scan' );
	}

	/** Site language: fa, de or en. */
	public static function site_lang() {
		$loc = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$l   = strtolower( substr( (string) $loc, 0, 2 ) );
		$l   = in_array( $l, array( 'fa', 'de' ), true ) ? $l : 'en';
		// Persian content on an English-locale install: answer in Persian.
		$p = get_option( self::PROFILE, array() );
		if ( 'en' === $l && ! empty( $p['content_lang'] ) && 'fa' === $p['content_lang'] ) {
			$l = 'fa';
		}
		return apply_filters( 'sva_site_lang', $l );
	}

	public static function assistant_name( $lang = null ) {
		$s = self::settings();
		if ( '' !== trim( $s['assistant_name'] ) ) {
			return $s['assistant_name'];
		}
		return SVA_I18n::t( 'default_name', $lang ? $lang : self::site_lang() );
	}

	/** Detected profile merged with the owner's manual overrides. */
	public static function profile() {
		$p = get_option( self::PROFILE, array() );
		$p = wp_parse_args(
			is_array( $p ) ? $p : array(),
			array(
				'scanned_at' => 0,
				'site_type'  => 'general',
				'key_pages'  => array(),
				'contact'    => array(),
				'services'   => array(),
				'categories' => array(),
				'counts'     => array(),
				'ai'         => array(),
			)
		);
		$p['contact'] = wp_parse_args(
			(array) $p['contact'],
			array( 'phones' => array(), 'emails' => array(), 'address' => '', 'hours' => '', 'social' => array() )
		);
		$s = self::settings();

		if ( $s['site_type_override'] && isset( SVA_I18n::types()[ $s['site_type_override'] ] ) ) {
			$p['site_type'] = $s['site_type_override'];
		}
		foreach ( (array) $s['key_overrides'] as $role => $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			if ( '-' === $url ) {
				unset( $p['key_pages'][ $role ] );
				continue;
			}
			$pid   = url_to_postid( $url );
			$title = $pid ? get_the_title( $pid ) : SVA_I18n::role_label( $role, self::site_lang() );
			$p['key_pages'][ $role ] = array( 'title' => wp_strip_all_tags( $title ), 'url' => $url, 'source' => 'manual' );
		}
		if ( '' !== trim( $s['contact_phone'] ) ) {
			$p['contact']['phones'] = array_values( array_filter( array_map( 'trim', preg_split( '/[,،\n]+/u', $s['contact_phone'] ) ) ) );
		}
		if ( '' !== trim( $s['contact_email'] ) ) {
			$p['contact']['emails'] = array_values( array_filter( array_map( 'trim', preg_split( '/[,،\s]+/u', $s['contact_email'] ) ) ) );
		}
		if ( '' !== trim( $s['contact_address'] ) ) {
			$p['contact']['address'] = trim( $s['contact_address'] );
		}
		if ( '' !== trim( $s['contact_hours'] ) ) {
			$p['contact']['hours'] = trim( $s['contact_hours'] );
		}
		return apply_filters( 'sva_profile', $p );
	}

	/**
	 * Show product prices in chat? "auto" follows what the scan found on the product pages.
	 */
	public static function show_prices() {
		$s = self::settings();
		if ( 'yes' === $s['show_prices'] ) {
			$v = true;
		} elseif ( 'no' === $s['show_prices'] ) {
			$v = false;
		} else {
			$p = get_option( self::PROFILE, array() );
			$v = ! isset( $p['prices_visible'] ) || false !== $p['prices_visible'];
		}
		return (bool) apply_filters( 'sva_show_prices', $v );
	}

	public static function has_products() {
		$p = get_option( self::PROFILE, array() );
		return ! empty( $p['counts']['product'] ) && function_exists( 'wc_get_product' );
	}
}
