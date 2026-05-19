<?php
/**
 * Multilingual compatibility (WPML + Polylang).
 *
 * Only translatable strings (field labels, messages) are localised. The
 * technical configuration of a form (AgileCRM keys, captcha, mappings) is
 * shared across languages and is never duplicated per locale.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_I18n
 */
class BF_I18n {

	const STRING_GROUP = 'Bomedia Forms';

	/**
	 * Hook registration. Called on `init`.
	 *
	 * @return void
	 */
	public function init() {
		// Polylang: register form labels as translatable strings.
		if ( function_exists( 'pll_register_string' ) ) {
			add_action( 'wp_loaded', array( $this, 'register_polylang_strings' ) );
		}
	}

	/**
	 * Resolve the current language code.
	 *
	 * Detection order: WPML, then Polylang, then browser/site locale.
	 *
	 * @return string Two-letter language code (lowercase), e.g. "en".
	 */
	public function current_lang() {
		// WPML.
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			return strtolower( substr( ICL_LANGUAGE_CODE, 0, 2 ) );
		}

		// Polylang.
		if ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language( 'slug' );
			if ( $pll ) {
				return strtolower( substr( $pll, 0, 2 ) );
			}
		}

		// Browser Accept-Language.
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
			$accept = strtolower( substr( $accept, 0, 2 ) );
			if ( preg_match( '/^[a-z]{2}$/', $accept ) ) {
				return $accept;
			}
		}

		// Site locale fallback.
		return strtolower( substr( get_locale(), 0, 2 ) );
	}

	/**
	 * Translate a string through the active multilingual plugin.
	 *
	 * Falls back to the original string when no plugin is active.
	 *
	 * @param string $string  Source string.
	 * @param string $context Context / name for the string.
	 * @return string
	 */
	public function translate( $string, $context = '' ) {
		if ( '' === (string) $string ) {
			return $string;
		}

		// Polylang.
		if ( function_exists( 'pll__' ) ) {
			return pll__( $string );
		}

		// WPML.
		if ( function_exists( 'icl_t' ) ) {
			return icl_t( self::STRING_GROUP, $context ? $context : $string, $string );
		}

		return $string;
	}

	/**
	 * Register all form labels with Polylang as translatable strings.
	 *
	 * @return void
	 */
	public function register_polylang_strings() {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		$forms = get_posts(
			array(
				'post_type'      => 'bf_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $forms as $form_id ) {
			$fields = get_post_meta( $form_id, '_bf_fields', true );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! empty( $field['label'] ) ) {
					pll_register_string( 'bf_label_' . $form_id, $field['label'], self::STRING_GROUP );
				}
				if ( ! empty( $field['placeholder'] ) ) {
					pll_register_string( 'bf_ph_' . $form_id, $field['placeholder'], self::STRING_GROUP );
				}
			}

			$success = get_post_meta( $form_id, '_bf_success_message', true );
			if ( $success ) {
				pll_register_string( 'bf_success_' . $form_id, $success, self::STRING_GROUP );
			}
		}
	}
}
