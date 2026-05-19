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
			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					if ( ! empty( $field['label'] ) ) {
						pll_register_string( 'bf_label_' . $form_id, $field['label'], self::STRING_GROUP );
					}
					if ( ! empty( $field['placeholder'] ) ) {
						pll_register_string( 'bf_ph_' . $form_id, $field['placeholder'], self::STRING_GROUP );
					}
					if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
						foreach ( $field['options'] as $opt ) {
							$opt_label = is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt;
							if ( '' !== (string) $opt_label ) {
								pll_register_string( 'bf_opt_' . $form_id, $opt_label, self::STRING_GROUP );
							}
						}
					}
				}
			}

			$post_submit = get_post_meta( $form_id, '_bf_post_submit', true );
			if ( is_array( $post_submit ) ) {
				if ( ! empty( $post_submit['success_message'] ) ) {
					pll_register_string( 'bf_success_' . $form_id, $post_submit['success_message'], self::STRING_GROUP );
				}
				if ( ! empty( $post_submit['submit_label'] ) ) {
					pll_register_string( 'bf_submit_' . $form_id, $post_submit['submit_label'], self::STRING_GROUP );
				}
			}

			$notif = get_post_meta( $form_id, '_bf_notifications', true );
			if ( is_array( $notif ) ) {
				if ( ! empty( $notif['subject'] ) ) {
					pll_register_string( 'bf_subject_' . $form_id, $notif['subject'], self::STRING_GROUP, false );
				}
				if ( ! empty( $notif['body_html'] ) ) {
					pll_register_string( 'bf_body_' . $form_id, $notif['body_html'], self::STRING_GROUP, true );
				}
			}
		}
	}
}
