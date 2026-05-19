<?php
/**
 * Admin UI: top-level menu, form list, and the tabbed form editor.
 *
 * v0.1.0 ships the menu scaffold and a tabbed meta box with basic field
 * editing. The drag-and-drop field editor and the "Submissions" sub-menu
 * (filterable table + CSV export) arrive in later releases.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Admin
 */
class BF_Admin {

	const MENU_SLUG = 'bomedia-forms';

	/**
	 * Register the top-level menu and sub-menus.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Bomedia Forms', 'bomedia-forms' ),
			__( 'Bomedia Forms', 'bomedia-forms' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_list_page' ),
			'dashicons-feedback',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Forms', 'bomedia-forms' ),
			__( 'All Forms', 'bomedia-forms' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add Form', 'bomedia-forms' ),
			__( 'Add Form', 'bomedia-forms' ),
			'edit_posts',
			'post-new.php?post_type=' . BF_CPT::POST_TYPE
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Submissions', 'bomedia-forms' ),
			__( 'Submissions', 'bomedia-forms' ),
			'edit_posts',
			self::MENU_SLUG . '-submissions',
			array( $this, 'render_submissions_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'bomedia-forms' ),
			__( 'Settings', 'bomedia-forms' ),
			'manage_options',
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_cpt = $screen && BF_CPT::POST_TYPE === $screen->post_type;
		$is_bf  = false !== strpos( (string) $hook, self::MENU_SLUG );

		if ( ! $is_cpt && ! $is_bf ) {
			return;
		}

		wp_enqueue_style(
			'bomedia-forms-admin',
			BF_PLUGIN_URL . 'assets/css/form.css',
			array(),
			BF_VERSION
		);

		if ( false !== strpos( (string) $hook, self::MENU_SLUG . '-submissions' ) ) {
			wp_enqueue_script(
				'bomedia-forms-submissions',
				BF_PLUGIN_URL . 'assets/js/admin-submissions.js',
				array(),
				BF_VERSION,
				true
			);
			wp_localize_script(
				'bomedia-forms-submissions',
				'BomediaFormsSub',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'bf_submissions' ),
					'confirmDel' => __( 'Permanently delete the selected submissions? This cannot be undone.', 'bomedia-forms' ),
					'none'       => __( 'No submissions selected.', 'bomedia-forms' ),
					'err'        => __( 'Request failed.', 'bomedia-forms' ),
				)
			);
		}

		if ( $is_cpt ) {
			wp_enqueue_script(
				'bomedia-forms-admin',
				BF_PLUGIN_URL . 'assets/js/admin-form-editor.js',
				array(),
				BF_VERSION,
				true
			);

			wp_localize_script(
				'bomedia-forms-admin',
				'BomediaFormsAdmin',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'previewNonce' => wp_create_nonce( 'bf_get_form' ),
					'testNonce'    => wp_create_nonce( 'bf_agilecrm_test' ),
					'fieldTypes'   => self::field_type_labels(),
					'widths'       => array(
						'full'  => __( 'Full width', 'bomedia-forms' ),
						'half'  => __( 'Half', 'bomedia-forms' ),
						'third' => __( 'Third', 'bomedia-forms' ),
					),
					'i18n'         => array(
						'untitled'  => __( 'Untitled field', 'bomedia-forms' ),
						'dupKeys'   => __( 'Duplicate field keys are not allowed. Please make every field key unique.', 'bomedia-forms' ),
						'confirmRm' => __( 'Remove this field?', 'bomedia-forms' ),
						'previewer' => __( 'Form preview', 'bomedia-forms' ),
						'loading'   => __( 'Loading preview…', 'bomedia-forms' ),
						'prevErr'   => __( 'Could not load preview.', 'bomedia-forms' ),
						'close'     => __( 'Close', 'bomedia-forms' ),
					),
				)
			);
		}
	}

	/**
	 * Admin notice when AUTH_KEY is missing (encryption is weakened).
	 *
	 * @return void
	 */
	public function admin_notice_auth_key() {
		if ( BF_Encryption::auth_key_available() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Bomedia Forms:</strong> ' .
			esc_html__( '⚠️ AUTH_KEY is not defined in wp-config.php. API keys are not encrypted and the captcha is disabled. Define AUTH_KEY before exposing the plugin in production.', 'bomedia-forms' ) .
			'</p></div>';
	}

	/**
	 * AJAX: test AgileCRM credentials without saving the form.
	 *
	 * @return void
	 */
	public function ajax_test_agilecrm() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'bomedia-forms' ) ), 403 );
		}
		check_ajax_referer( 'bf_agilecrm_test', 'nonce' );

		$subdomain = isset( $_POST['subdomain'] ) ? sanitize_text_field( wp_unslash( $_POST['subdomain'] ) ) : '';
		$email     = isset( $_POST['account_email'] ) ? sanitize_email( wp_unslash( $_POST['account_email'] ) ) : '';
		$api_key   = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$form_id   = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;

		// Blank key in the form means "use the stored, encrypted one".
		if ( '' === $api_key && $form_id ) {
			$stored = get_post_meta( $form_id, BF_Settings::META_AGILECRM, true );
			if ( is_array( $stored ) && ! empty( $stored['api_key'] ) ) {
				$api_key = BF_Encryption::decrypt( $stored['api_key'] );
			}
		}

		$result = BF_AgileCRM_Client::test_connection( $subdomain, $email, $api_key );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Selectable field type labels.
	 *
	 * @return array
	 */
	private static function field_type_labels() {
		return array(
			'text'     => __( 'Text', 'bomedia-forms' ),
			'email'    => __( 'Email', 'bomedia-forms' ),
			'tel'      => __( 'Phone', 'bomedia-forms' ),
			'textarea' => __( 'Textarea', 'bomedia-forms' ),
			'select'   => __( 'Select', 'bomedia-forms' ),
			'checkbox' => __( 'Checkbox', 'bomedia-forms' ),
			'radio'    => __( 'Radio', 'bomedia-forms' ),
			'hidden'   => __( 'Hidden', 'bomedia-forms' ),
			'custom'   => __( 'Custom', 'bomedia-forms' ),
		);
	}

	/**
	 * Forms list page (table of bf_form posts).
	 *
	 * @return void
	 */
	public function render_list_page() {
		$forms = get_posts(
			array(
				'post_type'      => BF_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Bomedia Forms', 'bomedia-forms' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'post-new.php?post_type=' . BF_CPT::POST_TYPE ) ),
			esc_html__( 'Add Form', 'bomedia-forms' )
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Submissions (30d)', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'bomedia-forms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $forms ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No forms yet.', 'bomedia-forms' ) . '</td></tr>';
		}

		foreach ( $forms as $form ) {
			$edit = get_edit_post_link( $form->ID );
			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $form->post_title ) . '</a></strong></td>';
			echo '<td><code>' . esc_html( $form->post_name ) . '</code></td>';
			echo '<td>' . esc_html( $this->form_language( $form->ID ) ) . '</td>';
			echo '<td>' . esc_html( (string) $this->submission_count( $form->ID ) ) . '</td>';
			echo '<td><code>[bomedia_form id="' . esc_html( (string) $form->ID ) . '"]</code></td>';
			echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'bomedia-forms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Collect submission-list query filters from the request.
	 *
	 * @return array
	 */
	private function submission_filters() {
		return array(
			'form_id'   => isset( $_GET['bf_form'] ) ? (int) $_GET['bf_form'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'status'    => isset( $_GET['bf_status'] ) ? sanitize_key( wp_unslash( $_GET['bf_status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'date_from' => isset( $_GET['bf_from'] ) ? sanitize_text_field( wp_unslash( $_GET['bf_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'date_to'   => isset( $_GET['bf_to'] ) ? sanitize_text_field( wp_unslash( $_GET['bf_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'search'    => isset( $_GET['bf_s'] ) ? sanitize_text_field( wp_unslash( $_GET['bf_s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'per_page'  => isset( $_GET['bf_pp'] ) ? max( 25, min( 100, (int) $_GET['bf_pp'] ) ) : 25, // phpcs:ignore WordPress.Security.NonceVerification
			'paged'     => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification
		);
	}

	/**
	 * Query submissions with filters. Returns [ rows, total ].
	 *
	 * @param array $f         Filters.
	 * @param bool  $all       Ignore pagination (used for CSV export).
	 * @return array
	 */
	private function query_submissions( array $f, $all = false ) {
		global $wpdb;
		$table = BF_Submission_Handler::table_name();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $f['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = $f['form_id'];
		}
		if ( ! empty( $f['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $f['status'];
		}
		if ( ! empty( $f['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $f['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $f['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $f['date_to'] . ' 23:59:59';
		}
		if ( '' !== $f['search'] ) {
			$where[]  = 'data LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $f['search'] ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql        = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";
		$sql_params = $params;
		if ( ! $all ) {
			$offset       = ( $f['paged'] - 1 ) * $f['per_page'];
			$sql         .= ' LIMIT %d OFFSET %d';
			$sql_params[] = $f['per_page'];
			$sql_params[] = $offset;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A ); // phpcs:ignore
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Stats for the cards above the table.
	 *
	 * @return array
	 */
	private function submission_stats() {
		global $wpdb;
		$table = BF_Submission_Handler::table_name();
		$month = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$today = current_time( 'Y-m-d' ) . ' 00:00:00';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total_month = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $month ) );
		$total_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today ) );
		$sent_month  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status = 'sent'", $month ) );
		$top         = $wpdb->get_results( $wpdb->prepare( "SELECT form_id, COUNT(*) c FROM {$table} WHERE created_at >= %s GROUP BY form_id ORDER BY c DESC LIMIT 3", $month ), ARRAY_A );
		// phpcs:enable

		$top_forms = array();
		foreach ( (array) $top as $t ) {
			$p           = get_post( (int) $t['form_id'] );
			$top_forms[] = array(
				'name'  => $p ? $p->post_title : ( '#' . $t['form_id'] ),
				'count' => (int) $t['c'],
			);
		}

		return array(
			'total_month' => $total_month,
			'total_today' => $total_today,
			'rate'        => $total_month > 0 ? round( $sent_month / $total_month * 100 ) : 0,
			'top_forms'   => $top_forms,
		);
	}

	/**
	 * Submissions admin page: stats, filters, table, pagination.
	 *
	 * @return void
	 */
	public function render_submissions_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$f      = $this->submission_filters();
		$result = $this->query_submissions( $f );
		$rows   = $result['rows'];
		$total  = $result['total'];
		$pages  = max( 1, (int) ceil( $total / $f['per_page'] ) );
		$stats  = $this->submission_stats();

		$forms = get_posts(
			array(
				'post_type'      => BF_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$form_titles = array();
		foreach ( $forms as $fp ) {
			$form_titles[ $fp->ID ] = $fp->post_title;
		}

		$statuses = array(
			''                => __( 'All statuses', 'bomedia-forms' ),
			'sent'            => __( 'Sent', 'bomedia-forms' ),
			'agilecrm_failed' => __( 'AgileCRM failed', 'bomedia-forms' ),
			'spam'            => __( 'Spam', 'bomedia-forms' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Submissions', 'bomedia-forms' ) . '</h1>';

		// Stats cards.
		echo '<div class="bf-stats">';
		printf( '<div class="bf-stat"><span class="bf-stat__n">%d</span><span class="bf-stat__l">%s</span></div>', (int) $stats['total_month'], esc_html__( 'Last 30 days', 'bomedia-forms' ) );
		printf( '<div class="bf-stat"><span class="bf-stat__n">%d</span><span class="bf-stat__l">%s</span></div>', (int) $stats['total_today'], esc_html__( 'Today', 'bomedia-forms' ) );
		printf( '<div class="bf-stat"><span class="bf-stat__n">%d%%</span><span class="bf-stat__l">%s</span></div>', (int) $stats['rate'], esc_html__( 'Success rate (30d)', 'bomedia-forms' ) );
		$top_html = '';
		foreach ( $stats['top_forms'] as $tf ) {
			$top_html .= esc_html( $tf['name'] ) . ' (' . (int) $tf['count'] . ')<br />';
		}
		echo '<div class="bf-stat bf-stat--wide"><span class="bf-stat__l">' . esc_html__( 'Top forms (30d)', 'bomedia-forms' ) . '</span><span class="bf-stat__top">' . ( $top_html ? $top_html : '—' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		// Filters.
		echo '<form method="get" class="bf-sub-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG . '-submissions' ) . '" />';
		echo '<select name="bf_form"><option value="0">' . esc_html__( 'All forms', 'bomedia-forms' ) . '</option>';
		foreach ( $form_titles as $id => $title ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $f['form_id'], $id, false ), esc_html( $title ) );
		}
		echo '</select> ';
		echo '<select name="bf_status">';
		foreach ( $statuses as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $f['status'], $val, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<input type="date" name="bf_from" value="' . esc_attr( $f['date_from'] ) . '" /> ';
		echo '<input type="date" name="bf_to" value="' . esc_attr( $f['date_to'] ) . '" /> ';
		echo '<input type="search" name="bf_s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search content…', 'bomedia-forms' ) . '" /> ';
		echo '<select name="bf_pp">';
		foreach ( array( 25, 50, 100 ) as $pp ) {
			printf( '<option value="%d"%s>%d / page</option>', $pp, selected( $f['per_page'], $pp, false ), $pp );
		}
		echo '</select> ';
		echo '<button class="button">' . esc_html__( 'Filter', 'bomedia-forms' ) . '</button> ';
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=bf_export_csv&' . http_build_query(
				array(
					'bf_form'   => $f['form_id'],
					'bf_status' => $f['status'],
					'bf_from'   => $f['date_from'],
					'bf_to'     => $f['date_to'],
					'bf_s'      => $f['search'],
				)
			) ),
			'bf_export_csv'
		);
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'bomedia-forms' ) . '</a>';
		echo '</form>';

		// Table.
		echo '<form id="bf-sub-bulk"><table class="wp-list-table widefat fixed striped bf-sub-table">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="bf-sub-all" /></td>';
		echo '<th>' . esc_html__( 'ID', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Form', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Lang', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'IP', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bomedia-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'bomedia-forms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No submissions found.', 'bomedia-forms' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$fname = isset( $form_titles[ $r['form_id'] ] ) ? $form_titles[ $r['form_id'] ] : ( '#' . $r['form_id'] );
			echo '<tr>';
			echo '<th class="check-column"><input type="checkbox" class="bf-sub-cb" value="' . esc_attr( $r['id'] ) . '" /></th>';
			echo '<td>' . esc_html( $r['id'] ) . '</td>';
			echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $fname ) . '</td>';
			echo '<td>' . esc_html( $r['lang'] ) . '</td>';
			echo '<td>' . esc_html( $r['ip'] ) . '</td>';
			echo '<td><span class="bf-badge bf-badge--' . esc_attr( $r['status'] ) . '">' . esc_html( $r['status'] ) . '</span></td>';
			echo '<td><button type="button" class="button-link bf-sub-view" data-id="' . esc_attr( $r['id'] ) . '">' . esc_html__( 'View', 'bomedia-forms' ) . '</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="bf-sub-delete">' . esc_html__( 'Delete selection', 'bomedia-forms' ) . '</button> ';
		echo '<span class="description">' . esc_html(
			/* translators: %d: total submissions. */
			sprintf( __( '%d total', 'bomedia-forms' ), $total )
		) . '</span></p>';
		echo '</form>';

		// Pagination.
		if ( $pages > 1 ) {
			$base = remove_query_arg( 'paged' );
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'      => add_query_arg( 'paged', '%#%', $base ),
					'format'    => '',
					'current'   => $f['paged'],
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			);
			echo '</div></div>';
		}

		// Detail modal.
		echo '<div id="bf-sub-modal" class="bf-preview-modal" hidden><div class="bf-preview-modal__overlay" data-close="1"></div>';
		echo '<div class="bf-preview-modal__dialog" role="dialog" aria-modal="true"><button type="button" class="bf-preview-modal__close" data-close="1">&times;</button><div class="bf-preview-modal__body"></div></div></div>';

		echo '</div>';
	}

	/**
	 * AJAX: submission detail.
	 *
	 * @return void
	 */
	public function ajax_submission_detail() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'bf_submissions', 'nonce' );

		global $wpdb;
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$table = BF_Submission_Handler::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}

		$post = get_post( (int) $row['form_id'] );
		$data = json_decode( $row['data'], true );
		$data = is_array( $data ) ? $data : array();

		ob_start();
		echo '<h2>' . esc_html__( 'Submission', 'bomedia-forms' ) . ' #' . esc_html( $row['id'] ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Form', 'bomedia-forms' ), esc_html( $post ? $post->post_title : ( '#' . $row['form_id'] ) ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Date', 'bomedia-forms' ), esc_html( $row['created_at'] ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Status', 'bomedia-forms' ), esc_html( $row['status'] ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Language', 'bomedia-forms' ), esc_html( $row['lang'] ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'IP', 'bomedia-forms' ), esc_html( $row['ip'] ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'User agent', 'bomedia-forms' ), esc_html( $row['user_agent'] ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'AgileCRM contact', 'bomedia-forms' ), esc_html( $row['agilecrm_contact_id'] ? $row['agilecrm_contact_id'] : '—' ) );
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Fields', 'bomedia-forms' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $data as $k => $v ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) );
		}
		echo '</tbody></table>';
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: delete selected submissions (physical, GDPR).
	 *
	 * @return void
	 */
	public function ajax_delete_submissions() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'bf_submissions', 'nonce' );

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nothing selected.', 'bomedia-forms' ) ), 400 );
		}

		global $wpdb;
		$table        = BF_Submission_Handler::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

		$user = wp_get_current_user();
		BF_Logger::log(
			'admin',
			sprintf(
				'delete_submissions user=%s (id=%d) ids=%s count=%d',
				$user ? $user->user_login : '?',
				$user ? $user->ID : 0,
				implode( ',', $ids ),
				(int) $deleted
			)
		);

		wp_send_json_success( array( 'deleted' => (int) $deleted ) );
	}

	/**
	 * admin-post: stream a filtered CSV export (never written to disk).
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'bomedia-forms' ), 403 );
		}
		check_admin_referer( 'bf_export_csv' );

		$f      = $this->submission_filters();
		$result = $this->query_submissions( $f, true );
		$rows   = $result['rows'];

		// Union of all field keys across the result set.
		$field_keys = array();
		foreach ( $rows as $r ) {
			$d = json_decode( $r['data'], true );
			if ( is_array( $d ) ) {
				foreach ( array_keys( $d ) as $k ) {
					$field_keys[ $k ] = true;
				}
			}
		}
		$field_keys = array_keys( $field_keys );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bomedia-forms-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_merge( array( 'id', 'form_name', 'lang', 'date', 'ip', 'status', 'agilecrm_contact_id' ), $field_keys ) );

		foreach ( $rows as $r ) {
			$post = get_post( (int) $r['form_id'] );
			$d    = json_decode( $r['data'], true );
			$d    = is_array( $d ) ? $d : array();
			$line = array(
				$r['id'],
				$post ? $post->post_title : ( '#' . $r['form_id'] ),
				$r['lang'],
				$r['created_at'],
				$r['ip'],
				$r['status'],
				$r['agilecrm_contact_id'],
			);
			foreach ( $field_keys as $k ) {
				$v      = $d[ $k ] ?? '';
				$line[] = is_array( $v ) ? wp_json_encode( $v ) : $v;
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Global settings page (submission retention).
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['bf_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bf_settings_nonce'] ) ), 'bf_save_settings' ) ) {
			$days = isset( $_POST['bf_retention_days'] ) ? max( 0, (int) $_POST['bf_retention_days'] ) : 30;
			update_option( 'bf_retention_days', $days );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'bomedia-forms' ) . '</p></div>';
		}

		$days = (int) get_option( 'bf_retention_days', 30 );

		echo '<div class="wrap"><h1>' . esc_html__( 'Bomedia Forms — Settings', 'bomedia-forms' ) . '</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'bf_save_settings', 'bf_settings_nonce' );
		echo '<table class="form-table"><tr><th scope="row">' . esc_html__( 'Submission retention (days)', 'bomedia-forms' ) . '</th><td>';
		echo '<input type="number" name="bf_retention_days" min="0" value="' . esc_attr( (string) $days ) . '" class="small-text" /> ';
		echo '<p class="description">' . esc_html__( 'Submissions older than this are deleted by the daily cron. 0 = keep forever. Each form can override this in its Anti-spam tab.', 'bomedia-forms' ) . '</p>';
		echo '</td></tr></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Register the tabbed editor meta box.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'bf_form_editor',
			__( 'Form Configuration', 'bomedia-forms' ),
			array( $this, 'render_meta_box' ),
			BF_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the tabbed editor.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$config = BF_Settings::get_config( $post->ID );
		wp_nonce_field( 'bf_save_meta_' . $post->ID, 'bf_meta_nonce' );

		echo '<div class="notice notice-info inline" style="margin:0 0 12px"><p>' .
			esc_html__( 'If you use a caching plugin, exclude this page from the page cache so the form works correctly (the anti-spam token and Math captcha are generated per page load).', 'bomedia-forms' ) .
			'</p></div>';

		$tabs = array(
			'fields'        => __( 'Fields', 'bomedia-forms' ),
			'agilecrm'      => __( 'AgileCRM', 'bomedia-forms' ),
			'captcha'       => __( 'Captcha', 'bomedia-forms' ),
			'notifications' => __( 'Notifications', 'bomedia-forms' ),
			'post_submit'   => __( 'Post-submit', 'bomedia-forms' ),
			'antispam'      => __( 'Anti-spam', 'bomedia-forms' ),
		);

		echo '<div class="bf-admin-tabs"><nav class="bf-admin-tabs__nav">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			printf(
				'<button type="button" class="bf-admin-tabs__tab%s" data-tab="%s">%s</button>',
				$first ? ' is-active' : '',
				esc_attr( $key ),
				esc_html( $label )
			);
			$first = false;
		}
		echo '</nav>';

		// Fields tab.
		$this->panel_open( 'fields', true );
		$this->render_fields_editor( $config['fields'] );
		$this->panel_close();

		// AgileCRM tab.
		$this->panel_open( 'agilecrm' );
		$agile   = $config['agilecrm'];
		$has_key = ! empty( $agile['api_key'] );
		if ( ! BF_Encryption::auth_key_available() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'AUTH_KEY is not defined in wp-config.php. API keys cannot be securely encrypted until it is set.', 'bomedia-forms' ) . '</p></div>';
		}
		$this->text_row( 'bf_agilecrm[subdomain]', __( 'Subdomain (e.g. boprint24)', 'bomedia-forms' ), $agile['subdomain'] );
		$this->text_row( 'bf_agilecrm[account_email]', __( 'Account email', 'bomedia-forms' ), $agile['account_email'] );
		echo '<p><label>' . esc_html__( 'API key', 'bomedia-forms' ) . '<br />';
		echo '<input type="password" class="regular-text" id="bf-agile-key" name="bf_agilecrm[api_key]" autocomplete="new-password" placeholder="' . ( $has_key ? esc_attr__( '•••••• (stored, leave blank to keep)', 'bomedia-forms' ) : '' ) . '" /></label></p>';
		$this->text_row( 'bf_agilecrm[default_tags]', __( 'Default tags (comma separated)', 'bomedia-forms' ), implode( ', ', (array) $agile['default_tags'] ) );

		echo '<p><button type="button" class="button" id="bf-agile-test" data-form-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html__( 'Test connection', 'bomedia-forms' ) . '</button> <span id="bf-agile-test-result" class="bf-test-result" role="status" aria-live="polite"></span></p>';

		echo '<h4>' . esc_html__( 'Field mapping', 'bomedia-forms' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Map each form field to an AgileCRM property. Defaults to a snake_case of the field label. Email/phone/name fields are auto-detected.', 'bomedia-forms' ) . '</p>';
		echo '<table class="widefat striped bf-map-table"><thead><tr><th>' . esc_html__( 'Form field', 'bomedia-forms' ) . '</th><th>' . esc_html__( 'AgileCRM property', 'bomedia-forms' ) . '</th></tr></thead><tbody>';
		$mapping = is_array( $agile['field_mapping'] ?? null ) ? $agile['field_mapping'] : array();
		foreach ( (array) $config['fields'] as $field ) {
			$key = $field['name'] ?? '';
			if ( '' === $key || 'hidden' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$default = BF_Submission_Handler::default_property_name( $field );
			$current = isset( $mapping[ $key ] ) ? $mapping[ $key ] : $default;
			printf(
				'<tr><td><code>%s</code><br /><span class="description">%s</span></td><td><input type="text" class="regular-text" name="bf_agilecrm[field_mapping][%s]" value="%s" placeholder="%s" /></td></tr>',
				esc_html( $key ),
				esc_html( $field['label'] ?? '' ),
				esc_attr( $key ),
				esc_attr( $current ),
				esc_attr( $default )
			);
		}
		echo '</tbody></table>';
		$this->panel_close();

		// Captcha tab.
		$this->panel_open( 'captcha' );
		$cap       = $config['captcha'];
		$providers = array(
			'none'         => __( 'None', 'bomedia-forms' ),
			'recaptcha_v2' => 'reCAPTCHA v2',
			'recaptcha_v3' => 'reCAPTCHA v3',
			'math'         => __( 'Math', 'bomedia-forms' ),
			'turnstile'    => 'Turnstile',
			'hcaptcha'     => 'hCaptcha',
		);
		echo '<p><label>' . esc_html__( 'Provider', 'bomedia-forms' ) . '<br /><select name="bf_captcha[provider]">';
		foreach ( $providers as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $cap['provider'], $val, false ), esc_html( $label ) );
		}
		echo '</select></label></p>';
		$this->text_row( 'bf_captcha[site_key]', __( 'Site key', 'bomedia-forms' ), $cap['site_key'] );
		echo '<p><label>' . esc_html__( 'Secret key', 'bomedia-forms' ) . '<br /><input type="password" class="regular-text" name="bf_captcha[secret_key]" autocomplete="new-password" placeholder="' . ( ! empty( $cap['secret_key'] ) ? esc_attr__( '•••••• (stored, leave blank to keep)', 'bomedia-forms' ) : '' ) . '" /></label></p>';
		$this->panel_close();

		// Notifications tab.
		$this->panel_open( 'notifications' );
		$notif = $config['notifications'];
		$this->text_row( 'bf_notifications[recipient]', __( 'Recipient email (comma-separated for multiple)', 'bomedia-forms' ), $notif['recipient'] );
		$this->text_row( 'bf_notifications[subject]', __( 'Subject', 'bomedia-forms' ), $notif['subject'] );
		// TODO v1.x: optionally swap this textarea for wp_editor (TinyMCE
		// init inside a hidden tab panel needs extra handling).
		echo '<p><label>' . esc_html__( 'Body (HTML allowed; {{fields}} inserts the data table)', 'bomedia-forms' ) . '<br />';
		echo '<textarea name="bf_notifications[body_html]" rows="8" class="large-text code">' . esc_textarea( $notif['body_html'] ) . '</textarea></label></p>';
		$this->text_row( 'bf_notifications[reply_to]', __( 'Reply-To (blank = submitter’s email)', 'bomedia-forms' ), $notif['reply_to'] ?? '' );
		echo '<p class="description">' . esc_html__( 'Variables: {form_name}, {date}, {captcha_passed}, and {field_KEY} for any field (e.g. {field_email}). Leave subject/body blank for sensible defaults.', 'bomedia-forms' ) . '</p>';
		$this->panel_close();

		// Post-submit tab.
		$this->panel_open( 'post_submit' );
		$ps = $config['post_submit'];
		echo '<p><label><input type="radio" name="bf_post_submit[mode]" value="message"' . checked( $ps['mode'], 'message', false ) . '> ' . esc_html__( 'Show success message', 'bomedia-forms' ) . '</label><br />';
		echo '<label><input type="radio" name="bf_post_submit[mode]" value="redirect"' . checked( $ps['mode'], 'redirect', false ) . '> ' . esc_html__( 'Redirect', 'bomedia-forms' ) . '</label></p>';
		$this->text_row( 'bf_post_submit[success_message]', __( 'Success message', 'bomedia-forms' ), $ps['success_message'] );
		$this->text_row( 'bf_post_submit[redirect_url]', __( 'Redirect URL', 'bomedia-forms' ), $ps['redirect_url'] );
		$this->text_row( 'bf_post_submit[submit_label]', __( 'Submit button text', 'bomedia-forms' ), $ps['submit_label'] ?? __( 'Send', 'bomedia-forms' ) );
		$this->panel_close();

		// Anti-spam tab.
		$this->panel_open( 'antispam' );
		$as = $config['antispam'];
		echo '<p><label><input type="checkbox" name="bf_antispam[honeypot]" value="1"' . checked( ! empty( $as['honeypot'] ), true, false ) . '> ' . esc_html__( 'Enable honeypot', 'bomedia-forms' ) . '</label></p>';
		$this->text_row( 'bf_antispam[rate_limit_count]', __( 'Max submissions per hour per IP', 'bomedia-forms' ), (string) $as['rate_limit_count'] );
		$this->text_row( 'bf_antispam[min_seconds]', __( 'Minimum seconds before submit (faster = bot)', 'bomedia-forms' ), (string) ( $as['min_seconds'] ?? 2 ) );
		echo '<p><label>' . esc_html__( 'Blocked words (one per line, case-insensitive)', 'bomedia-forms' ) . '<br />';
		echo '<textarea name="bf_antispam[blocked_words]" rows="5" class="large-text code">' . esc_textarea( $as['blocked_words'] ?? '' ) . '</textarea></label></p>';
		echo '<p class="description">' . esc_html__( 'If any submitted field contains a blocked word the submission is silently discarded and logged.', 'bomedia-forms' ) . '</p>';
		$retention = (int) get_post_meta( $post->ID, '_bf_retention', true );
		$this->text_row( 'bf_retention', __( 'Retention override in days (0 = use global setting)', 'bomedia-forms' ), (string) $retention );
		$this->panel_close();

		echo '</div>';

		// Minimal inline tab switcher (no build step).
		echo '<script>(function(){var w=document.currentScript.closest(".bf-admin-tabs");if(!w)return;w.querySelectorAll(".bf-admin-tabs__tab").forEach(function(b){b.addEventListener("click",function(){w.querySelectorAll(".bf-admin-tabs__tab").forEach(function(x){x.classList.remove("is-active")});w.querySelectorAll(".bf-admin-panel").forEach(function(p){p.style.display="none"});b.classList.add("is-active");var t=w.querySelector(\'.bf-admin-panel[data-panel="\'+b.dataset.tab+\'"]\');if(t)t.style.display="block"})})})();</script>';
	}

	/**
	 * Persist meta on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['bf_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bf_meta_nonce'] ) ), 'bf_save_meta_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Fields (JSON).
		if ( isset( $_POST['bf_fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['bf_fields_json'] ), true );
			if ( is_array( $decoded ) ) {
				update_post_meta( $post_id, BF_Settings::META_FIELDS, self::sanitize_fields( $decoded ) );
			}
		}

		// AgileCRM (encrypt api_key; keep existing when blank).
		if ( isset( $_POST['bf_agilecrm'] ) && is_array( $_POST['bf_agilecrm'] ) ) {
			$in       = wp_unslash( $_POST['bf_agilecrm'] );
			$existing = get_post_meta( $post_id, BF_Settings::META_AGILECRM, true );
			$existing = is_array( $existing ) ? $existing : array();

			$map = array();
			if ( isset( $in['field_mapping'] ) && is_array( $in['field_mapping'] ) ) {
				foreach ( $in['field_mapping'] as $fkey => $prop ) {
					$prop = sanitize_text_field( $prop );
					if ( '' !== $prop ) {
						$map[ sanitize_key( $fkey ) ] = $prop;
					}
				}
			}

			$agile = array(
				'subdomain'     => sanitize_text_field( $in['subdomain'] ?? '' ),
				'account_email' => sanitize_email( $in['account_email'] ?? '' ),
				'default_tags'  => array_values( array_filter( array_map( 'trim', explode( ',', $in['default_tags'] ?? '' ) ) ) ),
				'field_mapping' => $map,
				'api_key'       => $existing['api_key'] ?? '',
			);
			if ( ! empty( $in['api_key'] ) ) {
				$agile['api_key'] = BF_Encryption::encrypt( sanitize_text_field( $in['api_key'] ) );
			}
			update_post_meta( $post_id, BF_Settings::META_AGILECRM, $agile );
		}

		// Captcha (encrypt secret; keep existing when blank).
		if ( isset( $_POST['bf_captcha'] ) && is_array( $_POST['bf_captcha'] ) ) {
			$in       = wp_unslash( $_POST['bf_captcha'] );
			$existing = get_post_meta( $post_id, BF_Settings::META_CAPTCHA, true );
			$existing = is_array( $existing ) ? $existing : array();

			$cap = array(
				'provider'   => sanitize_key( $in['provider'] ?? 'none' ),
				'site_key'   => sanitize_text_field( $in['site_key'] ?? '' ),
				'secret_key' => $existing['secret_key'] ?? '',
			);
			if ( ! empty( $in['secret_key'] ) ) {
				$cap['secret_key'] = BF_Encryption::encrypt( sanitize_text_field( $in['secret_key'] ) );
			}
			update_post_meta( $post_id, BF_Settings::META_CAPTCHA, $cap );
		}

		// Notifications.
		if ( isset( $_POST['bf_notifications'] ) && is_array( $_POST['bf_notifications'] ) ) {
			$in = wp_unslash( $_POST['bf_notifications'] );
			update_post_meta(
				$post_id,
				BF_Settings::META_NOTIFICATIONS,
				array(
					'recipient' => implode(
						', ',
						array_filter(
							array_map(
								static function ( $e ) {
									return sanitize_email( trim( $e ) );
								},
								explode( ',', (string) ( $in['recipient'] ?? '' ) )
							)
						)
					),
					'subject'   => sanitize_text_field( $in['subject'] ?? '' ),
					'body_html' => wp_kses_post( $in['body_html'] ?? '' ),
					'reply_to'  => sanitize_email( $in['reply_to'] ?? '' ),
				)
			);
		}

		// Post-submit.
		if ( isset( $_POST['bf_post_submit'] ) && is_array( $_POST['bf_post_submit'] ) ) {
			$in = wp_unslash( $_POST['bf_post_submit'] );
			update_post_meta(
				$post_id,
				BF_Settings::META_POST_SUBMIT,
				array(
					'mode'            => 'redirect' === ( $in['mode'] ?? '' ) ? 'redirect' : 'message',
					'success_message' => sanitize_text_field( $in['success_message'] ?? '' ),
					'redirect_url'    => esc_url_raw( $in['redirect_url'] ?? '' ),
					'submit_label'    => sanitize_text_field( $in['submit_label'] ?? __( 'Send', 'bomedia-forms' ) ),
				)
			);
		}

		// Per-form retention override.
		if ( isset( $_POST['bf_retention'] ) ) {
			update_post_meta( $post_id, '_bf_retention', max( 0, (int) $_POST['bf_retention'] ) );
		}

		// Anti-spam.
		if ( isset( $_POST['bf_antispam'] ) && is_array( $_POST['bf_antispam'] ) ) {
			$in = wp_unslash( $_POST['bf_antispam'] );
			update_post_meta(
				$post_id,
				BF_Settings::META_ANTISPAM,
				array(
					'honeypot'         => ! empty( $in['honeypot'] ),
					'rate_limit_count' => max( 0, (int) ( $in['rate_limit_count'] ?? 5 ) ),
					'rate_limit_hours' => 1,
					'min_seconds'      => max( 0, (int) ( $in['min_seconds'] ?? 2 ) ),
					'blocked_words'    => sanitize_textarea_field( $in['blocked_words'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Sanitize a decoded fields array.
	 *
	 * Enforces unique field keys (auto-suffixing collisions) and a known
	 * width keyword. Options accept either ["v|Label", ...] strings or
	 * [{value,label}, ...] and are normalised to {value,label}.
	 *
	 * @param array $fields Raw fields.
	 * @return array
	 */
	public static function sanitize_fields( array $fields ) {
		$allowed = array( 'text', 'email', 'tel', 'textarea', 'select', 'checkbox', 'radio', 'hidden', 'custom' );
		$widths  = array( 'full', 'half', 'third' );
		$clean   = array();
		$seen    = array();
		$auto    = 0;

		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}

			$key = sanitize_key( $f['name'] ?? '' );
			if ( '' === $key ) {
				$key = 'field_' . ( ++$auto );
			}
			while ( isset( $seen[ $key ] ) ) {
				$key = preg_replace( '/_\d+$/', '', $key ) . '_' . ( ++$auto );
			}
			$seen[ $key ] = true;

			$clean[] = array(
				'type'        => in_array( ( $f['type'] ?? 'text' ), $allowed, true ) ? $f['type'] : 'text',
				'name'        => $key,
				'label'       => sanitize_text_field( $f['label'] ?? '' ),
				'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
				'required'    => ! empty( $f['required'] ),
				'pattern'     => isset( $f['pattern'] ) ? (string) $f['pattern'] : '',
				'default'     => sanitize_text_field( $f['default'] ?? '' ),
				'width'       => in_array( ( $f['width'] ?? 'full' ), $widths, true ) ? $f['width'] : 'full',
				'options'     => self::sanitize_options( $f['options'] ?? array() ),
			);
		}
		return $clean;
	}

	/**
	 * Normalise field options to a list of {value,label} pairs.
	 *
	 * @param mixed $options Raw options (array of strings or pairs).
	 * @return array
	 */
	private static function sanitize_options( $options ) {
		if ( ! is_array( $options ) ) {
			return array();
		}
		$out = array();
		foreach ( $options as $opt ) {
			if ( is_array( $opt ) ) {
				$val = sanitize_text_field( $opt['value'] ?? '' );
				$lbl = sanitize_text_field( $opt['label'] ?? $val );
			} else {
				$parts = explode( '|', (string) $opt, 2 );
				$val   = sanitize_text_field( trim( $parts[0] ) );
				$lbl   = sanitize_text_field( trim( isset( $parts[1] ) ? $parts[1] : $parts[0] ) );
			}
			if ( '' === $val && '' === $lbl ) {
				continue;
			}
			$out[] = array(
				'value' => $val,
				'label' => $lbl,
			);
		}
		return $out;
	}

	/**
	 * Render the drag-and-drop fields editor shell.
	 *
	 * Rows are built client-side from the JSON payload below and serialised
	 * back into the hidden #bf_fields_json input on save.
	 *
	 * @param array $fields Current field definitions.
	 * @return void
	 */
	private function render_fields_editor( array $fields ) {
		?>
		<div id="bf-fields-editor" class="bf-fields-editor">
			<div class="bf-fields-editor__toolbar">
				<button type="button" class="button" id="bf-preview-btn">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<?php esc_html_e( 'Preview', 'bomedia-forms' ); ?>
				</button>
				<span class="bf-fields-editor__add">
					<select id="bf-add-type" aria-label="<?php esc_attr_e( 'New field type', 'bomedia-forms' ); ?>">
						<?php foreach ( self::field_type_labels() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-primary" id="bf-add-field">
						<?php esc_html_e( '+ Add field', 'bomedia-forms' ); ?>
					</button>
				</span>
			</div>

			<div id="bf-fields-list" class="bf-fields-list" aria-live="polite"></div>
			<p class="bf-fields-empty description"><?php esc_html_e( 'No fields yet. Use “+ Add field” to start.', 'bomedia-forms' ); ?></p>

			<input type="hidden" name="bf_fields_json" id="bf_fields_json" />
			<script type="application/json" id="bf-fields-data">
				<?php echo wp_json_encode( array_values( $fields ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</script>
		</div>

		<div id="bf-preview-modal" class="bf-preview-modal" hidden>
			<div class="bf-preview-modal__overlay" data-close="1"></div>
			<div class="bf-preview-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Form preview', 'bomedia-forms' ); ?>">
				<button type="button" class="bf-preview-modal__close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'bomedia-forms' ); ?>">&times;</button>
				<div class="bf-preview-modal__body"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Resolve a form's configured language for the list table.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	private function form_language( $form_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $form_id );
		}
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return (string) apply_filters( 'wpml_post_language_details', null, $form_id )['language_code'] ?? '';
		}
		return strtolower( substr( get_locale(), 0, 2 ) );
	}

	/**
	 * Count submissions for a form in the last 30 days.
	 *
	 * @param int $form_id Form ID.
	 * @return int
	 */
	private function submission_count( $form_id ) {
		global $wpdb;
		$table = BF_Submission_Handler::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND created_at >= %s",
				$form_id,
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Open a tab panel container.
	 *
	 * @param string $key    Panel key.
	 * @param bool   $active Whether visible by default.
	 * @return void
	 */
	private function panel_open( $key, $active = false ) {
		printf(
			'<div class="bf-admin-panel" data-panel="%s" style="display:%s">',
			esc_attr( $key ),
			$active ? 'block' : 'none'
		);
	}

	/**
	 * Close a tab panel container.
	 *
	 * @return void
	 */
	private function panel_close() {
		echo '</div>';
	}

	/**
	 * Render a labelled text input row.
	 *
	 * @param string $name  Input name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @return void
	 */
	private function text_row( $name, $label, $value ) {
		printf(
			'<p><label>%s<br /><input type="text" class="regular-text" name="%s" value="%s" /></label></p>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}
}
