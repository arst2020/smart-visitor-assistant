<?php
/**
 * AI providers: Anthropic Claude and any OpenAI-compatible Chat Completions API
 * (OpenAI, OpenRouter, Groq, local Ollama/LM Studio, …). Keys never leave the server.
 */
defined( 'ABSPATH' ) || exit;

class SVA_AI {

	public static function api_key() {
		if ( defined( 'SVA_API_KEY' ) && SVA_API_KEY ) {
			return SVA_API_KEY;
		}
		return trim( (string) SVA_Plugin::settings()['api_key'] );
	}

	public static function model() {
		$s = SVA_Plugin::settings();
		if ( '' !== trim( $s['model'] ) ) {
			return trim( $s['model'] );
		}
		return 'anthropic' === $s['provider'] ? 'claude-haiku-5-5' : 'gpt-4o-mini';
	}

	public static function available() {
		$s = SVA_Plugin::settings();
		if ( 'ai' !== $s['mode'] ) {
			return false;
		}
		// Local OpenAI-compatible servers may not need a key.
		return '' !== self::api_key() || ( 'openai' === $s['provider'] && ! preg_match( '#api\.openai\.com#', $s['api_base'] ) );
	}

	/**
	 * @param string $system   System prompt.
	 * @param array  $messages [ ['role'=>'user'|'assistant','content'=>string], … ] starting with user.
	 * @return string|WP_Error
	 */
	public static function chat( $system, $messages, $max_tokens = null ) {
		$s          = SVA_Plugin::settings();
		$max_tokens = $max_tokens ? (int) $max_tokens : max( 100, (int) $s['max_tokens'] );
		$messages   = self::alternate( $messages );

		if ( 'anthropic' === $s['provider'] ) {
			$res = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				array(
					'timeout' => 45,
					'headers' => array(
						'x-api-key'         => self::api_key(),
						'anthropic-version' => '2023-06-01',
						'content-type'      => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'      => self::model(),
							'max_tokens' => $max_tokens,
							'system'     => $system,
							'messages'   => $messages,
						)
					),
				)
			);
			$data = self::decode( $res );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$text = '';
			foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
			return '' !== trim( $text ) ? trim( $text ) : new WP_Error( 'sva_ai_empty', 'Empty response' );
		}

		$base    = rtrim( $s['api_base'] ? $s['api_base'] : 'https://api.openai.com/v1', '/' );
		$headers = array( 'content-type' => 'application/json' );
		if ( self::api_key() ) {
			$headers['authorization'] = 'Bearer ' . self::api_key();
		}
		$res  = wp_remote_post(
			$base . '/chat/completions',
			array(
				'timeout' => 45,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'model'      => self::model(),
						'max_tokens' => $max_tokens,
						'messages'   => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $messages ),
					)
				),
			)
		);
		$data = self::decode( $res );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		return '' !== trim( $text ) ? trim( $text ) : new WP_Error( 'sva_ai_empty', 'Empty response' );
	}

	private static function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'sva_ai_http', $msg );
		}
		return is_array( $data ) ? $data : new WP_Error( 'sva_ai_json', 'Invalid JSON' );
	}

	/** Merge consecutive same-role messages and make sure the list starts with "user". */
	private static function alternate( $messages ) {
		$out = array();
		foreach ( (array) $messages as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$text = trim( (string) ( isset( $m['content'] ) ? $m['content'] : '' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( ! $out && 'assistant' === $role ) {
				continue;
			}
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ]['role'] === $role ) {
				$out[ $last ]['content'] .= "\n" . $text;
			} else {
				$out[] = array( 'role' => $role, 'content' => $text );
			}
		}
		return $out;
	}
}
