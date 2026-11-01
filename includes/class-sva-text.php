<?php
/**
 * Text helpers with Persian-aware normalization.
 */
defined( 'ABSPATH' ) || exit;

class SVA_Text {

	private static $stop    = null;
	private static $kwcache = array();

	public static function en_digits( $s ) {
		return strtr(
			(string) $s,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/** Lowercase, unify Arabic/Persian letters and digits, drop diacritics and punctuation. */
	public static function normalize( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = self::en_digits( $s );
		$s = strtr(
			$s,
			array(
				'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
				"\xE2\x80\x8C" => ' ', "\xE2\x80\x8B" => '', "\xC2\xA0" => ' ',
			)
		);
		$s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		return trim( (string) $s );
	}

	public static function stopwords() {
		if ( null === self::$stop ) {
			$w = 'از به با در که این ان را و یا برای تا هم ها های می من تو ما شما او است هست هستید هستن بود شد شده کنم کنید کنی کن کرد چه چی چیه چطور چگونه ایا یک هر اگر ولی اما نیز بر روی پس سلام لطفا ممنون مرسی دارید دارم دارین داری داره میخوام میخواستم میخواهم خواهم خوام میشه ممکن بگید بگین بفرمایید راجع مورد درباره کدوم کدام کجا چرا چند خیلی باید هست؟ رو هم اون همین میکشه میکنه میشن کنه بکنم بشه بدم بدید بدین شه دیگه '
				. 'the a an is are am to of for in on at and or i you we me my our your it this that what how can could do does did please with about want would like need there be have has get any some '
				. 'der die das ein eine ist sind und oder ich sie wir mir mein ihr ihre es zu von mit für auf bitte was wie kann können gibt haben habe ein einen';
			self::$stop = array_fill_keys( explode( ' ', $w ), true );
		}
		return self::$stop;
	}

	public static function tokens( $s, $keep_stop = false ) {
		$n = self::normalize( $s );
		if ( '' === $n ) {
			return array();
		}
		$stop = self::stopwords();
		$out  = array();
		foreach ( explode( ' ', $n ) as $t ) {
			if ( '' === $t || ( mb_strlen( $t ) < 2 && ! ctype_digit( $t ) ) ) {
				continue;
			}
			if ( ! $keep_stop && isset( $stop[ $t ] ) ) {
				continue;
			}
			$out[ $t ] = true;
		}
		return array_keys( $out );
	}

	private static function nkw( $kw ) {
		if ( ! isset( self::$kwcache[ $kw ] ) ) {
			self::$kwcache[ $kw ] = self::normalize( $kw );
		}
		return self::$kwcache[ $kw ];
	}

	/**
	 * Does normalized $text contain keyword $kw? Short keywords must match a whole word,
	 * longer ones may be a word prefix (نوبت → نوبتدهی, book → booking).
	 */
	public static function has_kw( $norm_text, $kw ) {
		$k = self::nkw( $kw );
		if ( '' === $k ) {
			return false;
		}
		$hay = ' ' . $norm_text . ' ';
		if ( mb_strlen( $k ) <= 3 ) {
			return false !== strpos( $hay, ' ' . $k . ' ' );
		}
		return false !== strpos( $hay, ' ' . $k );
	}

	public static function count_kw( $norm_text, $kw ) {
		$k = self::nkw( $kw );
		if ( '' === $k ) {
			return 0;
		}
		$hay = ' ' . $norm_text . ' ';
		return mb_strlen( $k ) <= 3 ? substr_count( $hay, ' ' . $k . ' ' ) : substr_count( $hay, ' ' . $k );
	}

	/** Returns matched keywords from a list. */
	public static function matched( $norm_text, $kws ) {
		$m = array();
		foreach ( $kws as $kw ) {
			if ( self::has_kw( $norm_text, $kw ) ) {
				$m[] = $kw;
			}
		}
		return $m;
	}

	/** Clean HTML to readable plain text with line breaks. */
	public static function html_to_text( $html ) {
		$html = preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
		$html = preg_replace( '#<!--.*?-->#s', ' ', $html );
		$html = preg_replace( '/\[\/?[a-zA-Z0-9_\-]+(?:\s[^\]]*)?\]/', ' ', $html ); // Keep text inside shortcodes.
		$html = preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/td|/th)\b[^>]*>#i', "$0\n", $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = preg_replace( "/\s*\n\s*/u", "\n", $text );
		return trim( (string) $text );
	}

	/** Split long text into ~$size character chunks on sentence boundaries. */
	public static function chunks( $text, $size = 900, $max = 15 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$parts  = preg_split( '/(?<=[\.\!\?؟\n])\s+/u', $text );
		$chunks = array();
		$cur    = '';
		foreach ( $parts as $p ) {
			$pieces = mb_strlen( $p ) > $size ? self::mb_split_len( $p, $size ) : array( $p );
			foreach ( $pieces as $piece ) {
				if ( '' !== $cur && mb_strlen( $cur ) + mb_strlen( $piece ) + 1 > $size ) {
					$chunks[] = $cur;
					$cur      = '';
					if ( count( $chunks ) >= $max ) {
						return $chunks;
					}
				}
				$cur .= ( '' === $cur ? '' : ' ' ) . $piece;
			}
		}
		if ( '' !== trim( $cur ) && count( $chunks ) < $max ) {
			$chunks[] = $cur;
		}
		return $chunks;
	}

	private static function mb_split_len( $s, $len ) {
		$out = array();
		$n   = mb_strlen( $s );
		for ( $i = 0; $i < $n; $i += $len ) {
			$out[] = mb_substr( $s, $i, $len );
		}
		return $out;
	}

	/** Short snippet of $text around the first matching token. */
	public static function excerpt( $text, $tokens = array(), $len = 240 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= $len ) {
			return $text;
		}
		$pos = false;
		$cmp = self::en_digits( strtr( $text, array( 'ي' => 'ی', 'ك' => 'ک', "\xE2\x80\x8C" => ' ' ) ) );
		foreach ( (array) $tokens as $t ) {
			$p = mb_stripos( $cmp, $t );
			if ( false !== $p ) {
				$pos = $p;
				break;
			}
		}
		$start = ( false === $pos ) ? 0 : max( 0, $pos - (int) ( $len / 3 ) );
		if ( $start > 0 ) {
			$sp = mb_strpos( $text, ' ', $start );
			if ( false !== $sp && $sp - $start < 30 ) {
				$start = $sp + 1;
			}
		}
		$out = mb_substr( $text, $start, $len );
		$sp  = mb_strrpos( $out, ' ' );
		if ( false !== $sp && $sp > $len * 0.6 ) {
			$out = mb_substr( $out, 0, $sp );
		}
		return ( $start > 0 ? '… ' : '' ) . trim( $out ) . ' …';
	}
}
