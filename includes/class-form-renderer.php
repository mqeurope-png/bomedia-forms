<?php
/**
 * Frontend form renderer.
 *
 * Outputs semantic, accessible (WCAG AA) HTML. The form works without
 * JavaScript; AJAX is a progressive enhancement layered on top by
 * assets/js/form.js. All CSS is scoped under `.bf-form`.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Form_Renderer
 */
class BF_Form_Renderer {

	/**
	 * Tracks whether assets have been enqueued for this request.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Register (but do not yet print) the frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_register_style(
			'bomedia-forms',
			BF_PLUGIN_URL . 'assets/css/form.css',
			array(),
			BF_VERSION
		);

		wp_register_script(
			'bomedia-forms',
			BF_PLUGIN_URL . 'assets/js/form.js',
			array(),
			BF_VERSION,
			true
		);

		wp_localize_script(
			'bomedia-forms',
			'BomediaForms',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'bf_submit',
			)
		);
	}

	/**
	 * Ensure assets are actually loaded (called lazily from the shortcode).
	 *
	 * @return void
	 */
	private function ensure_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		wp_enqueue_style( 'bomedia-forms' );
		wp_enqueue_script( 'bomedia-forms' );
		$this->assets_enqueued = true;
	}

	/**
	 * Shortcode handler: [bomedia_form id="N"] | [bomedia_form slug="..."].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'slug' => '',
				'lang' => '',
			),
			$atts,
			'bomedia_form'
		);

		$post = null;
		if ( ! empty( $atts['slug'] ) ) {
			$post = BF_CPT::get_by_slug( $atts['slug'] );
		} elseif ( (int) $atts['id'] > 0 ) {
			$candidate = get_post( (int) $atts['id'] );
			if ( $candidate && BF_CPT::POST_TYPE === $candidate->post_type ) {
				$post = $candidate;
			}
		}

		if ( ! $post ) {
			return '';
		}

		$this->ensure_assets();

		return $this->render( $post, $atts['lang'] );
	}

	/**
	 * Render a single form post to HTML.
	 *
	 * @param WP_Post $post Form post.
	 * @param string  $lang Optional forced language code.
	 * @return string
	 */
	public function render( WP_Post $post, $lang = '' ) {
		$config = BF_Settings::get_config( $post->ID );
		$i18n   = Bomedia_Forms::instance()->i18n;
		$lang   = $lang ? sanitize_text_field( $lang ) : $i18n->current_lang();

		$form_id = (int) $post->ID;
		$dom_id  = 'bf-form-' . $form_id;
		$nonce   = wp_create_nonce( 'bf_submit_' . $form_id );

		ob_start();
		?>
		<form
			class="bf-form"
			id="<?php echo esc_attr( $dom_id ); ?>"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			novalidate
			data-form-id="<?php echo esc_attr( $form_id ); ?>"
		>
			<div class="bf-form__messages" role="status" aria-live="polite"></div>

			<input type="hidden" name="action" value="bf_submit" />
			<input type="hidden" name="bf_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
			<input type="hidden" name="bf_lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="bf_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<?php // Honeypot — visually hidden, must stay empty. ?>
			<?php if ( ! empty( $config['antispam']['honeypot'] ) ) : ?>
				<div class="bf-form__hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $dom_id ); ?>-website">
						<?php esc_html_e( 'Leave this field empty', 'bomedia-forms' ); ?>
					</label>
					<input type="text" id="<?php echo esc_attr( $dom_id ); ?>-website" name="bf_hp_website" tabindex="-1" autocomplete="off" />
				</div>
			<?php endif; ?>

			<?php
			foreach ( (array) $config['fields'] as $index => $field ) {
				echo $this->render_field( $field, $dom_id, $index, $i18n ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<div class="bf-form__actions">
				<button type="submit" class="bf-form__submit">
					<span class="bf-form__submit-label"><?php esc_html_e( 'Send', 'bomedia-forms' ); ?></span>
					<span class="bf-form__spinner" aria-hidden="true"></span>
				</button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render an individual field.
	 *
	 * @param array   $field  Field definition.
	 * @param string  $dom_id Form DOM id prefix.
	 * @param int     $index  Field index.
	 * @param BF_I18n $i18n   I18n helper.
	 * @return string
	 */
	private function render_field( array $field, $dom_id, $index, BF_I18n $i18n ) {
		$type        = isset( $field['type'] ) ? $field['type'] : 'text';
		$name        = isset( $field['name'] ) ? $field['name'] : 'field_' . $index;
		$label       = isset( $field['label'] ) ? $i18n->translate( $field['label'] ) : '';
		$placeholder = isset( $field['placeholder'] ) ? $i18n->translate( $field['placeholder'] ) : '';
		$required    = ! empty( $field['required'] );
		$pattern     = isset( $field['pattern'] ) ? $field['pattern'] : '';
		$default     = isset( $field['default'] ) ? $field['default'] : '';
		$options     = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		$field_id   = $dom_id . '-' . sanitize_html_class( $name );
		$input_name = 'bf_field[' . esc_attr( $name ) . ']';
		$req_attr   = $required ? ' required aria-required="true"' : '';
		$req_mark   = $required ? ' <span class="bf-form__req" aria-hidden="true">*</span>' : '';

		if ( 'hidden' === $type ) {
			return sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $input_name ),
				esc_attr( $default )
			);
		}

		ob_start();
		echo '<div class="bf-form__row bf-form__row--' . esc_attr( $type ) . '">';

		if ( in_array( $type, array( 'text', 'email', 'tel', 'textarea', 'select', 'custom' ), true ) ) {
			printf(
				'<label class="bf-form__label" for="%s">%s%s</label>',
				esc_attr( $field_id ),
				esc_html( $label ),
				$req_mark // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea class="bf-form__input" id="%s" name="%s" placeholder="%s"%s rows="5">%s</textarea>',
					esc_attr( $field_id ),
					esc_attr( $input_name ),
					esc_attr( $placeholder ),
					$req_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_textarea( $default )
				);
				break;

			case 'select':
				echo '<select class="bf-form__input" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $input_name ) . '"' . $req_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<option value="">' . esc_html__( '— Select —', 'bomedia-forms' ) . '</option>';
				foreach ( $options as $opt ) {
					$val = is_array( $opt ) ? ( isset( $opt['value'] ) ? $opt['value'] : '' ) : $opt;
					$txt = is_array( $opt ) ? ( isset( $opt['label'] ) ? $opt['label'] : $val ) : $opt;
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $val ),
						selected( $default, $val, false ),
						esc_html( $i18n->translate( $txt ) )
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
			case 'radio':
				echo '<fieldset class="bf-form__group"><legend class="bf-form__label">' . esc_html( $label ) . $req_mark . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$i = 0;
				foreach ( $options as $opt ) {
					$val    = is_array( $opt ) ? ( isset( $opt['value'] ) ? $opt['value'] : '' ) : $opt;
					$txt    = is_array( $opt ) ? ( isset( $opt['label'] ) ? $opt['label'] : $val ) : $opt;
					$opt_id = $field_id . '-' . $i++;
					$cname  = 'checkbox' === $type ? $input_name . '[]' : $input_name;
					printf(
						'<label class="bf-form__choice"><input type="%s" id="%s" name="%s" value="%s"%s /> %s</label>',
						esc_attr( $type ),
						esc_attr( $opt_id ),
						esc_attr( $cname ),
						esc_attr( $val ),
						$required && 0 === ( $i - 1 ) ? ' required' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $i18n->translate( $txt ) )
					);
				}
				echo '</fieldset>';
				break;

			case 'custom':
			case 'text':
			case 'email':
			case 'tel':
			default:
				$input_type = in_array( $type, array( 'email', 'tel' ), true ) ? $type : 'text';
				printf(
					'<input type="%s" class="bf-form__input" id="%s" name="%s" placeholder="%s" value="%s"%s%s />',
					esc_attr( $input_type ),
					esc_attr( $field_id ),
					esc_attr( $input_name ),
					esc_attr( $placeholder ),
					esc_attr( $default ),
					$pattern ? ' pattern="' . esc_attr( $pattern ) . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$req_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				break;
		}

		echo '<span class="bf-form__error" data-for="' . esc_attr( $name ) . '" role="alert"></span>';
		echo '</div>';

		return ob_get_clean();
	}
}
