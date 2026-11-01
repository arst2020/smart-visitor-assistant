<?php
/**
 * Site scanner and analyzer.
 * - Indexes published pages, posts, products and other public post types (in batches).
 * - Reads menus and the rendered home page (header/footer, tel:/mailto: links).
 * - Detects site type, key pages (appointment, contact, cart …), contact info and services.
 */
defined( 'ABSPATH' ) || exit;

class SVA_Analyzer {

	const STATE = 'sva_scan_state';
	const BATCH = 25;

	public static function hooks() {
		add_action( 'sva_cron_scan', array( __CLASS__, 'cron_scan' ) );
		add_action( 'sva_weekly_scan', array( __CLASS__, 'cron_weekly' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 30, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_update' ), 30 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'update_option_' . SVA_Plugin::OPT, array( __CLASS__, 'index_custom' ) );
	}

	/* ---------------------------------------------------------------- *
	 * Scan control
	 * ---------------------------------------------------------------- */

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( array( 'attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_navigation', 'e-floating-buttons' ) as $x ) {
			unset( $types[ $x ] );
		}
		return array_values( apply_filters( 'sva_post_types', $types ) );
	}

	public static function state() {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function start() {
		$ids   = get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'numberposts'      => (int) apply_filters( 'sva_max_posts', 3000 ),
				'fields'           => 'ids',
				'has_password'     => false,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$state = array(
			'ids'     => array_map( 'intval', $ids ),
			'pos'     => 0,
			'total'   => count( $ids ),
			'started' => time(),
			'status'  => 'running',
		);
		update_option( self::STATE, $state, false );
		return $state;
	}

	public static function run_batch( $n = self::BATCH ) {
		$s = self::state();
		if ( empty( $s['status'] ) || 'running' !== $s['status'] ) {
			return $s;
		}
		$slice = array_slice( $s['ids'], $s['pos'], $n );
		foreach ( $slice as $id ) {
			self::index_post( $id );
		}
		$s['pos'] += count( $slice );
		if ( $s['pos'] >= $s['total'] ) {
			$s['status'] = 'indexed';
		}
		update_option( self::STATE, $s, false );
		return $s;
	}

	public static function finalize() {
		$s       = self::state();
		$started = ! empty( $s['started'] ) ? (int) $s['started'] : time();
		self::index_terms();
		self::index_custom();
		$home = self::scan_home();
		SVA_DB::delete_older_than( gmdate( 'Y-m-d H:i:s', $started ) );

		$profile = self::build_profile( $home );
		if ( SVA_AI::available() ) {
			$profile = self::ai_enrich( $profile, $home );
		}
		update_option( SVA_Plugin::PROFILE, $profile, false );

		$s['status']   = 'done';
		$s['finished'] = time();
		$s['ids']      = array();
		update_option( self::STATE, $s, false );
		return $profile;
	}

	/** Full scan in one request (used by WP-Cron), resumable. */
	public static function cron_scan( $mode = 'start' ) {
		if ( get_transient( 'sva_scan_lock' ) ) {
			return;
		}
		set_transient( 'sva_scan_lock', 1, 120 );
		$s = self::state();
		if ( 'start' === $mode || empty( $s['status'] ) || 'done' === $s['status'] ) {
			$s = self::start();
		}
		$t0 = time();
		while ( 'running' === $s['status'] && time() - $t0 < 20 ) {
			$s = self::run_batch();
		}
		if ( 'indexed' === $s['status'] ) {
			self::finalize();
		} elseif ( 'running' === $s['status'] ) {
			wp_schedule_single_event( time() + 5, 'sva_cron_scan', array( 'continue-' . $s['pos'] ) );
		}
		delete_transient( 'sva_scan_lock' );
	}

	public static function cron_weekly() {
		self::cron_scan( 'start' );
	}

	/* ---------------------------------------------------------------- *
	 * Incremental updates
	 * ---------------------------------------------------------------- */

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_type, self::post_types(), true ) ) {
			self::index_post( $post );
		}
	}

	public static function on_product_update( $product_id ) {
		self::index_post( $product_id );
	}

	public static function on_delete( $post_id ) {
		$p = get_post( $post_id );
		if ( $p ) {
			SVA_DB::delete_object( $p->post_type, $p->ID );
		}
	}

	/* ---------------------------------------------------------------- *
	 * Indexing
	 * ---------------------------------------------------------------- */

	public static function index_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, self::post_types(), true ) ) {
			SVA_DB::delete_object( $post->post_type, $post->ID );
			return false;
		}
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$url   = get_permalink( $post );
		$text  = self::post_text( $post );
		$meta  = array();

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$prod = wc_get_product( $post->ID );
			if ( ! $prod || 'hidden' === $prod->get_catalog_visibility() ) {
				SVA_DB::delete_object( $post->post_type, $post->ID );
				return false;
			}
			list( $ptext, $meta ) = self::product_info( $prod );
			$text = $ptext . "\n" . $text;
		} elseif ( has_excerpt( $post ) ) {
			$text = wp_strip_all_tags( $post->post_excerpt ) . "\n" . $text;
		}
		if ( 'product' !== $post->post_type && has_post_thumbnail( $post ) ) {
			$meta['image'] = get_the_post_thumbnail_url( $post, 'thumbnail' );
		}
		$meta['parent'] = (int) $post->post_parent;

		$chunks = SVA_Text::chunks( $text, 900, 15 );
		if ( empty( $chunks ) ) {
			$chunks = array( '' );
		}
		SVA_DB::replace_object( $post->post_type, $post->ID, $title, $url, $chunks, $meta );
		return true;
	}

	public static function post_text( $post ) {
		$content = (string) $post->post_content;
		$text    = SVA_Text::html_to_text( $content );
		// Page builders (Elementor) keep their content in post meta.
		if ( mb_strlen( $text ) < 40 ) {
			$el = get_post_meta( $post->ID, '_elementor_data', true );
			if ( $el ) {
				$data = is_array( $el ) ? $el : json_decode( (string) $el, true );
				if ( is_array( $data ) ) {
					$parts = array();
					self::walk_builder( $data, $parts );
					$text = trim( $text . "\n" . implode( "\n", array_unique( $parts ) ) );
				}
			}
		}
		return apply_filters( 'sva_post_text', $text, $post );
	}

	private static function walk_builder( $node, &$out ) {
		$keys = array( 'title', 'editor', 'text', 'description', 'content', 'tab_title', 'tab_content', 'title_text', 'description_text', 'item_description', 'testimonial_content', 'price', 'heading', 'sub_heading', 'html' );
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) ) {
				self::walk_builder( $v, $out );
			} elseif ( is_string( $v ) && in_array( $k, $keys, true ) ) {
				$t = SVA_Text::html_to_text( $v );
				if ( '' !== $t && preg_match( '/\p{L}{2}/u', $t ) ) {
					$out[] = $t;
				}
			}
		}
	}

	private static function product_info( $p ) {
		$lang  = SVA_Plugin::site_lang();
		$ph    = preg_replace( '#<span[^>]*screen-reader-text[^>]*>.*?</span>#is', '', (string) $p->get_price_html() );
		$ph    = str_replace( array( '</span>', '</del>', '</ins>' ), array( '</span> ', '</del> ', '</ins> ' ), $ph );
		$price = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $ph ), ENT_QUOTES, 'UTF-8' ) ) );
		$cats  = wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$tags  = wp_get_post_terms( $p->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		$cats  = is_wp_error( $cats ) ? array() : $cats;
		$tags  = is_wp_error( $tags ) ? array() : $tags;
		$attrs = array();
		foreach ( (array) $p->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_name' ) ) {
				continue;
			}
			$vals = $attr->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			if ( $vals ) {
				$attrs[] = wc_attribute_label( $attr->get_name() ) . ': ' . implode( '، ', (array) $vals );
			}
		}
		$stock = $p->is_in_stock();
		$L     = array(
			'fa' => array( 'قیمت', 'دسته', 'برچسب', 'وضعیت', 'موجود', 'ناموجود', 'کد' ),
			'de' => array( 'Preis', 'Kategorie', 'Tags', 'Status', 'auf Lager', 'nicht vorrätig', 'Art.-Nr.' ),
			'en' => array( 'Price', 'Category', 'Tags', 'Status', 'in stock', 'out of stock', 'SKU' ),
		);
		$L     = $L[ $lang ];
		$lines = array();
		// The price is kept only in meta (not in the searchable text) so it can be hidden when the site hides prices.
		$lines[] = $L[3] . ': ' . ( $stock ? $L[4] : $L[5] );
		if ( $cats ) {
			$lines[] = $L[1] . ': ' . implode( '، ', $cats );
		}
		if ( $tags ) {
			$lines[] = $L[2] . ': ' . implode( '، ', $tags );
		}
		if ( $p->get_sku() ) {
			$lines[] = $L[6] . ': ' . $p->get_sku();
		}
		$lines   = array_merge( $lines, $attrs );
		$short   = SVA_Text::html_to_text( $p->get_short_description() );
		if ( $short ) {
			$lines[] = $short;
		}
		$img = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : '';
		return array(
			implode( "\n", $lines ),
			array(
				'price'    => $price,
				'in_stock' => $stock,
				'on_sale'  => $p->is_on_sale(),
				'image'    => $img ? $img : '',
				'cats'     => $cats,
			),
		);
	}

	public static function index_terms() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 300 ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $t ) {
			$link = get_term_link( $t );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$desc = SVA_Text::html_to_text( $t->description );
			SVA_DB::replace_object( 'term', $t->term_id, $t->name, $link, array( trim( $t->name . "\n" . $desc ) ), array( 'count' => (int) $t->count ) );
		}
	}

	/** Owner-written knowledge and FAQ from settings. */
	public static function index_custom() {
		$s = SVA_Plugin::settings();
		SVA_DB::delete_object( 'sva_custom', 1 );
		if ( '' !== trim( $s['custom_knowledge'] ) ) {
			SVA_DB::replace_object( 'sva_custom', 1, get_bloginfo( 'name' ), home_url( '/' ), SVA_Text::chunks( $s['custom_knowledge'], 900, 20 ) );
		}
		global $wpdb;
		$wpdb->delete( SVA_DB::t( 'index' ), array( 'object_type' => 'sva_faq' ) );
		foreach ( self::faq_pairs( $s['faq'] ) as $i => $pair ) {
			SVA_DB::replace_object( 'sva_faq', $i + 1, $pair[0], '', array( $pair[1] ) );
		}
	}

	public static function faq_pairs( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) $raw ) as $line ) {
			$parts = explode( '|', $line, 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) && '' !== trim( $parts[1] ) ) {
				$out[] = array( trim( $parts[0] ), trim( $parts[1] ) );
			}
		}
		return $out;
	}

	/** Fetch the rendered home page: header/footer text, tel:, mailto:, social and booking links. */
	public static function scan_home() {
		$out = array( 'links' => array(), 'tel' => array(), 'mail' => array(), 'social' => array(), 'footer' => '', 'text' => '', 'ok' => false );
		$res = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'    => 20,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent' => 'Mozilla/5.0 (compatible; SmartVisitorAssistant/' . SVA_VERSION . ')',
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return $out;
		}
		$html = (string) wp_remote_retrieve_body( $res );
		if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
			return $out;
		}
		$out['ok'] = true;
		$html      = preg_replace( '/>\s*</', "> <", $html );
		$html      = preg_replace( '#</(p|div|li|h[1-6]|tr|section|footer|header|ul)>#i', "$0\n", $html );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xp = new DOMXPath( $dom );

		$kill = array();
		foreach ( $xp->query( '//script|//style|//noscript|//svg|//template|//iframe' ) as $n ) {
			$kill[] = $n;
		}
		foreach ( $kill as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$social_re = '#(instagram\.com|t\.me|telegram\.me|wa\.me|whatsapp\.com|api\.whatsapp|linkedin\.com|facebook\.com|x\.com|twitter\.com|youtube\.com|aparat\.com|eitaa\.com|rubika\.ir|bale\.ai)#i';
		$seen      = array();
		foreach ( $xp->query( '//a[@href]' ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			$txt  = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
			if ( '' === $txt ) {
				$txt = trim( $a->getAttribute( 'title' ) . ' ' . $a->getAttribute( 'aria-label' ) );
			}
			if ( 0 === stripos( $href, 'tel:' ) ) {
				$out['tel'][] = trim( rawurldecode( substr( $href, 4 ) ) );
				continue;
			}
			if ( 0 === stripos( $href, 'mailto:' ) ) {
				$out['mail'][] = trim( strtok( rawurldecode( substr( $href, 7 ) ), '?' ) );
				continue;
			}
			if ( preg_match( $social_re, $href, $m ) ) {
				$out['social'][ strtolower( $m[1] ) ] = esc_url_raw( $href );
				continue;
			}
			if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'javascript:' ) || isset( $seen[ $href ] ) ) {
				continue;
			}
			$seen[ $href ] = 1;
			$host          = wp_parse_url( $href, PHP_URL_HOST );
			if ( count( $out['links'] ) < 400 && ( ! $host || $host === $home_host || self::is_booking_url( $href ) ) ) {
				$out['links'][] = array( 'title' => mb_substr( $txt, 0, 80 ), 'url' => esc_url_raw( $href ) );
			}
		}

		// Footer = the longest footer-like element.
		$best = '';
		foreach ( $xp->query( '//footer | //*[contains(@class,"footer")] | //*[contains(@id,"footer")]' ) as $n ) {
			$t = trim( preg_replace( "/[ \t]+/u", ' ', $n->textContent ) );
			if ( mb_strlen( $t ) > mb_strlen( $best ) ) {
				$best = $t;
			}
		}
		$out['footer'] = mb_substr( trim( preg_replace( "/\s*\n\s*/u", "\n", $best ) ), 0, 5000 );
		$body          = $xp->query( '//body' );
		if ( $body->length ) {
			$out['text'] = mb_substr( trim( preg_replace( "/\s*\n\s*/u", "\n", preg_replace( "/[ \t]+/u", ' ', $body->item( 0 )->textContent ) ) ), 0, 30000 );
		}
		$out['tel']  = array_values( array_unique( array_filter( $out['tel'] ) ) );
		$out['mail'] = array_values( array_unique( array_filter( $out['mail'], 'is_email' ) ) );

		if ( '' !== $out['footer'] ) {
			SVA_DB::replace_object( 'sva_home', 1, get_bloginfo( 'name' ), home_url( '/' ), SVA_Text::chunks( $out['footer'], 900, 5 ) );
		}
		return $out;
	}

	public static function is_booking_url( $url ) {
		foreach ( SVA_I18n::booking_domains() as $d ) {
			if ( false !== stripos( (string) $url, $d ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------- *
	 * Analysis
	 * ---------------------------------------------------------------- */

	public static function build_profile( $home ) {
		$counts                 = SVA_DB::counts_by_type();
		$counts['chunks']       = SVA_DB::count_rows();
		list( $type, $scores )  = self::detect_type( $counts );
		$cands                  = self::candidates( $home );
		$key                    = self::detect_key_pages( $cands );

		// Text sources for contact extraction (most reliable first).
		$src = array();
		if ( ! empty( $key['contact']['id'] ) ) {
			$src[] = SVA_DB::object_text( 'page', $key['contact']['id'] );
		}
		$src[]   = $home['footer'];
		$src[]   = SVA_Plugin::settings()['custom_knowledge'];
		$src[]   = $home['text'];
		$contact = self::extract_contact( implode( "\n", $src ), $home );

		global $wpdb;
		$titles  = implode( ' ', (array) $wpdb->get_col( 'SELECT title FROM ' . SVA_DB::t( 'index' ) . ' WHERE chunk = 0 LIMIT 300' ) ); // phpcs:ignore
		$profile = array(
			'scanned_at' => time(),
			'content_lang' => SVA_I18n::detect( get_bloginfo( 'name' ) . ' ' . $titles, 'en' ),
			'site_name'  => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'tagline'    => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'site_type'  => $type,
			'heuristic'  => $type,
			'scores'     => $scores,
			'counts'     => $counts,
			'key_pages'  => $key,
			'contact'    => $contact,
			'services'   => self::detect_services( $key, $cands, $type ),
			'categories' => self::product_categories(),
			'home_ok'    => ! empty( $home['ok'] ),
			'prices_visible' => self::detect_prices_visible(),
			'ai'         => array(),
		);
		return apply_filters( 'sva_build_profile', $profile, $home );
	}

	/** Score site types from indexed text + structural signals. */
	public static function detect_type( $counts ) {
		global $wpdb;
		$rows   = $wpdb->get_results( 'SELECT object_type, title, SUBSTR(norm, 1, 1500) AS n FROM ' . SVA_DB::t( 'index' ) . " WHERE chunk = 0 AND object_type NOT IN ('sva_faq') LIMIT 800" ); // phpcs:ignore
		$titles = '';
		$bodies = '';
		foreach ( (array) $rows as $r ) {
			$titles .= ' ' . SVA_Text::normalize( $r->title );
			$bodies .= ' ' . $r->n;
		}
		$titles .= ' ' . SVA_Text::normalize( get_bloginfo( 'name' ) . ' ' . get_bloginfo( 'description' ) );
		$scores  = array();
		foreach ( SVA_I18n::type_keywords() as $type => $kws ) {
			$sc = 0;
			foreach ( $kws as $kw ) {
				$sc += 4 * min( 10, SVA_Text::count_kw( $titles, $kw ) ) + min( 30, SVA_Text::count_kw( $bodies, $kw ) );
			}
			$scores[ $type ] = $sc;
		}
		$products = isset( $counts['product'] ) ? (int) $counts['product'] : 0;
		if ( $products >= 3 ) {
			$scores['shop'] += 60 + min( 140, $products * 2 );
		}
		if ( defined( 'AMELIA_VERSION' ) || class_exists( '\Bookly\Lib\Plugin' ) ) {
			$scores['medical'] = (int) ( $scores['medical'] * 1.2 );
		}
		// "services" and "blog" are generic: damp them so specific types win.
		$scores['services'] = (int) ( $scores['services'] * 0.6 );
		$scores['blog']     = (int) ( $scores['blog'] * 0.5 );
		$posts              = isset( $counts['post'] ) ? (int) $counts['post'] : 0;
		$pages              = isset( $counts['page'] ) ? (int) $counts['page'] : 0;
		if ( $posts > 20 && $posts > 4 * max( 1, $pages ) && $products < 3 ) {
			$scores['blog'] += 40;
		}
		arsort( $scores );
		$type = key( $scores );
		if ( reset( $scores ) < 8 ) {
			$type = 'general';
		}
		return array( $type, $scores );
	}

	/** Candidate links: pages, menu items and links found on the home page. */
	private static function candidates( $home ) {
		$c = array();
		foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 500 ) ) as $p ) {
			$c[] = array(
				'title'   => wp_strip_all_tags( $p->post_title ),
				'slug'    => urldecode( $p->post_name ),
				'url'     => get_permalink( $p ),
				'id'      => $p->ID,
				'weight'  => 3,
				'source'  => 'page',
				'content' => $p->post_content,
				'parent'  => 0,
			);
		}
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			foreach ( (array) $items as $it ) {
				if ( empty( $it->url ) || '#' === $it->url ) {
					$it->url = '';
				}
				$c[] = array(
					'title'   => wp_strip_all_tags( $it->title ),
					'slug'    => '',
					'url'     => $it->url,
					'id'      => ( 'post_type' === $it->type ) ? (int) $it->object_id : 0,
					'weight'  => 4,
					'source'  => 'menu',
					'content' => '',
					'item_id' => (int) $it->ID,
					'parent'  => (int) $it->menu_item_parent,
				);
			}
		}
		foreach ( $home['links'] as $l ) {
			$c[] = array(
				'title'   => $l['title'],
				'slug'    => '',
				'url'     => $l['url'],
				'id'      => 0,
				'weight'  => 2,
				'source'  => 'home',
				'content' => '',
				'parent'  => 0,
			);
		}
		return $c;
	}

	public static function detect_key_pages( $cands ) {
		$best = array();
		$kws  = SVA_I18n::role_keywords();
		foreach ( $cands as $c ) {
			if ( '' === $c['url'] ) {
				continue;
			}
			$nt   = SVA_Text::normalize( $c['title'] );
			$ns   = SVA_Text::normalize( str_replace( array( '-', '_' ), ' ', $c['slug'] ) );
			$path = SVA_Text::normalize( str_replace( array( '-', '_', '/' ), ' ', (string) wp_parse_url( $c['url'], PHP_URL_PATH ) ) );
			foreach ( $kws as $role => $list ) {
				$sc = 0;
				foreach ( $list as $kw ) {
					if ( '' !== $nt && SVA_Text::has_kw( $nt, $kw ) ) {
						// Short titles that are exactly the keyword ("تماس با ما") are the strongest hit.
						$sc += ( mb_strlen( $nt ) <= mb_strlen( SVA_Text::normalize( $kw ) ) + 6 ) ? 3 : 1.5;
					}
					if ( SVA_Text::has_kw( $ns . ' ' . $path, $kw ) ) {
						$sc += 1;
					}
				}
				if ( 'appointment' === $role ) {
					if ( self::is_booking_url( $c['url'] ) ) {
						$sc += 5;
					}
					if ( $c['content'] && preg_match( '/\[(bookly-form|ameliabooking|ameliastepbooking|booked-calendar|booked-appointments|ea_bootstrap|ea_standard|latepoint_book|simply_booking|wpbs|booking|salon_booking|appointment)/i', $c['content'] ) ) {
						$sc += 6;
					}
				}
				if ( 'contact' === $role && $c['content'] && preg_match( '/\[(contact-form-7|wpforms|gravityform|fluentform|formidable|ninja_form)/i', $c['content'] ) ) {
					$sc += 1;
				}
				if ( $sc <= 0 ) {
					continue;
				}
				$sc *= $c['weight'];
				if ( ! isset( $best[ $role ] ) || $sc > $best[ $role ]['score'] ) {
					$best[ $role ] = array(
						'title'  => $c['title'] ? $c['title'] : SVA_I18n::role_label( $role, SVA_Plugin::site_lang() ),
						'url'    => $c['url'],
						'id'     => $c['id'],
						'source' => $c['source'],
						'score'  => $sc,
					);
				}
			}
		}
		foreach ( $best as $role => $b ) {
			if ( $b['score'] < 6 ) {
				unset( $best[ $role ] );
			}
		}
		// WooCommerce pages are known exactly.
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop' => 'shop', 'cart' => 'cart', 'checkout' => 'checkout', 'account' => 'myaccount' ) as $role => $wc ) {
				$pid = wc_get_page_id( $wc );
				if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
					$best[ $role ] = array( 'title' => get_the_title( $pid ), 'url' => get_permalink( $pid ), 'id' => $pid, 'source' => 'woocommerce', 'score' => 100 );
				}
			}
		}
		// Avoid one URL being used for unrelated roles (e.g. contact page also matched as "location").
		$used = array();
		uasort(
			$best,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		foreach ( $best as $role => $b ) {
			$u = untrailingslashit( $b['url'] );
			if ( isset( $used[ $u ] ) && ! in_array( $role, array( 'location', 'pricing' ), true ) && 'woocommerce' !== $b['source'] ) {
				unset( $best[ $role ] );
				continue;
			}
			$used[ $u ] = $role;
		}
		return apply_filters( 'sva_key_pages', $best, $cands );
	}

	public static function extract_contact( $text, $home ) {
		$t      = SVA_Text::en_digits( $text );
		$phones = array();
		foreach ( (array) $home['tel'] as $tel ) {
			$phones[] = SVA_Text::en_digits( $tel );
		}
		if ( preg_match_all( '/(?<![\d\/\.])(\+|00|0)(\d[\d \-\(\)\.\/]{6,17}\d)(?![\d\/])/u', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$num    = trim( $x[1] . $x[2] );
				$digits = preg_replace( '/\D/', '', $num );
				if ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 && ! preg_match( '/^(19|20|13|14)\d\d[\/\.\-]/', $num ) ) {
					$phones[] = $num;
				}
			}
		}
		$uniq = array();
		foreach ( $phones as $p ) {
			$d = preg_replace( '/\D/', '', $p );
			if ( $d && ! isset( $uniq[ $d ] ) ) {
				$uniq[ $d ] = $p;
			}
		}
		$emails = (array) $home['mail'];
		if ( preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $t, $m ) ) {
			foreach ( $m[0] as $e ) {
				if ( ! preg_match( '/\.(png|jpe?g|gif|webp|svg)$|example\.|sentry|wixpress/i', $e ) ) {
					$emails[] = strtolower( $e );
				}
			}
		}
		$address = '';
		if ( preg_match( '/(?:آدرس|نشانی|address|adresse|anschrift)\s*(?:ما|دفتر|مطب|کلینیک|فروشگاه)?\s*[:：\-]\s*(.{8,180}?)(?=\s*(?:تلفن|تماس|شماره|ایمیل|ساعت|کدپستی|کد پستی|phone|tel\b|telefon|e-?mail|öffnungs|opening|hours|\n|$))/iu', $t, $m ) ) {
			$address = self::mb_trim( $m[1] );
		}
		$hours = '';
		if ( preg_match( '/(?:ساعات?\s*(?:کاری|کار|پذیرش|حضور|مراجعه)|روزهای\s*کاری|opening hours|business hours|office hours|öffnungszeiten|sprechzeiten)\s*[:：\-]?\s*(.{5,160}?)(?=\s*(?:آدرس|نشانی|تلفن|تماس|ایمیل|address|phone|tel\b|telefon|e-?mail|\n|$))/iu', $t, $m ) ) {
			$hours = self::mb_trim( $m[1] );
		}
		return array(
			'phones'  => array_slice( array_values( $uniq ), 0, 4 ),
			'emails'  => array_slice( array_values( array_unique( $emails ) ), 0, 3 ),
			'address' => $address,
			'hours'   => $hours,
			'social'  => (array) $home['social'],
		);
	}

	/**
	 * Does the shop show prices on its product pages? Checks a few product pages (ignoring
	 * related/upsell blocks, header and footer). Returns null when it cannot tell.
	 */
	public static function detect_prices_visible() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return null;
		}
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 3, 'return' => 'ids', 'visibility' => 'visible' ) );
		if ( ! $ids ) {
			return null;
		}
		$checked = 0;
		$visible = 0;
		foreach ( $ids as $id ) {
			$prod = wc_get_product( $id );
			if ( $prod && '' === trim( wp_strip_all_tags( $prod->get_price_html() ) ) ) {
				$checked++; // Price removed by a filter / catalog-mode plugin.
				continue;
			}
			$res = wp_remote_get( get_permalink( $id ), array( 'timeout' => 15, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! class_exists( 'DOMDocument' ) ) {
				continue;
			}
			$dom = new DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8"?>' . wp_remote_retrieve_body( $res ) );
			libxml_clear_errors();
			$xp   = new DOMXPath( $dom );
			$kill = array();
			foreach ( $xp->query( '//header|//footer|//script|//style|//*[contains(@class,"related") or contains(@class,"upsells") or contains(@class,"up-sells") or contains(@class,"cross-sells") or contains(@class,"mini-cart") or contains(@class,"widget") or contains(@class,"recently")]' ) as $n ) {
				$kill[] = $n;
			}
			foreach ( $kill as $n ) {
				if ( $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
			$checked++;
			foreach ( $xp->query( '//*[contains(@class,"woocommerce-Price-amount")]' ) as $n ) {
				$hidden = false;
				for ( $a = $n; $a instanceof DOMElement; $a = $a->parentNode ) {
					if ( preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/i', $a->getAttribute( 'style' ) ) || preg_match( '/(^|\s)(hidden|d-none|screen-reader-text)(\s|$)/', $a->getAttribute( 'class' ) ) ) {
						$hidden = true;
						break;
					}
				}
				if ( ! $hidden ) {
					$visible++;
					break;
				}
			}
		}
		return $checked ? ( $visible > 0 ) : null;
	}

	private static function mb_trim( $s ) {
		return trim( (string) preg_replace( '/^[\s،,.\-:]+|[\s،,.\-:]+$/u', '', $s ) );
	}

	/** Services: children of the services menu item/page, else important pages. */
	private static function detect_services( $key, $cands, $type ) {
		$out  = array();
		$svkw = array_merge( SVA_I18n::role_keywords()['services'], array( 'درمان', 'خدمات درمانی', 'treatments', 'behandlungen', 'دوره ها', 'courses' ) );
		// Menu: items whose parent is a "services" item.
		$parents = array();
		foreach ( $cands as $c ) {
			if ( 'menu' === $c['source'] && SVA_Text::matched( SVA_Text::normalize( $c['title'] ), $svkw ) ) {
				$parents[ $c['item_id'] ] = true;
			}
		}
		foreach ( $cands as $c ) {
			if ( 'menu' === $c['source'] && $c['parent'] && isset( $parents[ $c['parent'] ] ) && $c['url'] ) {
				$out[ $c['url'] ] = array( 'title' => $c['title'], 'url' => $c['url'] );
			}
		}
		// Child pages of the services page.
		if ( ! $out && ! empty( $key['services']['id'] ) ) {
			foreach ( get_pages( array( 'child_of' => (int) $key['services']['id'], 'post_status' => 'publish', 'number' => 30 ) ) as $p ) {
				$out[ get_permalink( $p ) ] = array( 'title' => $p->post_title, 'url' => get_permalink( $p ) );
			}
		}
		// A "services" custom post type (e.g. service, treatment, course).
		if ( ! $out ) {
			foreach ( self::post_types() as $pt ) {
				if ( preg_match( '/servic|treat|leistung|course|kurs|khadamat|dars/i', $pt ) ) {
					foreach ( get_posts( array( 'post_type' => $pt, 'numberposts' => 20, 'post_status' => 'publish' ) ) as $p ) {
						$out[ get_permalink( $p ) ] = array( 'title' => $p->post_title, 'url' => get_permalink( $p ) );
					}
				}
			}
		}
		// Fallback: pages that are not key pages (non-shop sites).
		if ( ! $out && 'shop' !== $type ) {
			$skip = array();
			foreach ( $key as $k ) {
				$skip[ (int) $k['id'] ] = true;
			}
			$front = (int) get_option( 'page_on_front' );
			foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 40, 'sort_column' => 'menu_order' ) ) as $p ) {
				if ( isset( $skip[ $p->ID ] ) || $p->ID === $front || (int) get_option( 'wp_page_for_privacy_policy' ) === $p->ID ) {
					continue;
				}
				$out[ get_permalink( $p ) ] = array( 'title' => $p->post_title, 'url' => get_permalink( $p ) );
				if ( count( $out ) >= 10 ) {
					break;
				}
			}
		}
		return array_slice( array_values( $out ), 0, 12 );
	}

	private static function product_categories() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 12 ) );
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( 'uncategorized' === $t->slug ) {
					continue;
				}
				$out[] = array( 'title' => $t->name, 'url' => get_term_link( $t ), 'count' => (int) $t->count );
			}
		}
		return $out;
	}

	/** Optional: ask the AI to summarize the site, confirm its type and suggest starter questions. */
	public static function ai_enrich( $profile, $home ) {
		$lang   = SVA_Plugin::site_lang();
		$langn  = array( 'fa' => 'Persian (Farsi)', 'de' => 'German', 'en' => 'English' );
		$titles = array();
		global $wpdb;
		foreach ( (array) $wpdb->get_results( 'SELECT object_type, title FROM ' . SVA_DB::t( 'index' ) . " WHERE chunk = 0 AND object_type NOT LIKE 'sva_%' ORDER BY CASE object_type WHEN 'page' THEN 0 WHEN 'product' THEN 1 WHEN 'term' THEN 2 ELSE 3 END, id LIMIT 120" ) as $r ) { // phpcs:ignore
			$titles[] = $r->object_type . ': ' . $r->title;
		}
		$keyp = array();
		foreach ( $profile['key_pages'] as $role => $k ) {
			$keyp[] = $role . ': ' . $k['title'];
		}
		$info = "Site name: {$profile['site_name']}\nTagline: {$profile['tagline']}\nHeuristic type: {$profile['site_type']}\n"
			. 'Key pages: ' . implode( '; ', $keyp ) . "\n"
			. "Content titles:\n" . implode( "\n", $titles ) . "\n"
			. "Footer:\n" . mb_substr( $home['footer'], 0, 800 ) . "\n"
			. "Home page text:\n" . mb_substr( $home['text'], 0, 2500 );
		$types  = implode( ', ', array_keys( SVA_I18n::types() ) );
		$system = 'You analyze websites for a chat assistant. Reply with ONLY one JSON object, no markdown.';
		$user   = "Analyze this website.\n\n{$info}\n\nReturn JSON: {\"site_type\": one of [{$types}], \"summary\": \"2 sentences in {$langn[$lang]} describing what this business/site offers to visitors\", \"services\": [up to 8 main services or product groups, in {$langn[$lang]}], \"suggested_questions\": [4 short questions a visitor would ask, in {$langn[$lang]}, max 6 words each]}";
		$res    = SVA_AI::chat( $system, array( array( 'role' => 'user', 'content' => $user ) ), 700 );
		if ( is_wp_error( $res ) ) {
			$profile['ai_error'] = $res->get_error_message();
			return $profile;
		}
		$a = strpos( $res, '{' );
		$b = strrpos( $res, '}' );
		$j = ( false !== $a && false !== $b ) ? json_decode( substr( $res, $a, $b - $a + 1 ), true ) : null;
		if ( is_array( $j ) ) {
			$profile['ai'] = array(
				'summary'   => isset( $j['summary'] ) ? sanitize_text_field( $j['summary'] ) : '',
				'services'  => isset( $j['services'] ) ? array_slice( array_map( 'sanitize_text_field', (array) $j['services'] ), 0, 8 ) : array(),
				'questions' => isset( $j['suggested_questions'] ) ? array_slice( array_map( 'sanitize_text_field', (array) $j['suggested_questions'] ), 0, 4 ) : array(),
			);
			if ( ! empty( $j['site_type'] ) && isset( SVA_I18n::types()[ $j['site_type'] ] ) ) {
				$profile['site_type'] = $j['site_type'];
			}
		}
		return $profile;
	}
}
