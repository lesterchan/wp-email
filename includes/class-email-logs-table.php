<?php
/**
 * WP-EMail class-email-logs-table.php
 *
 * @package WP-EMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The e-mail log, on core's list table.
 *
 * Replaces the hand-rolled table, pager and sort form the plugin carried
 * before 3.0.0, along with the interpolated ORDER BY / LIMIT that went with
 * them.
 *
 * @since 3.0.0
 */
class Email_Logs_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'email-log',
				'plural'   => 'email-logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Whether remarks are shown.
	 *
	 * @return bool
	 */
	private function show_remarks() {
		return defined( 'EMAIL_SHOW_REMARKS' ) && EMAIL_SHOW_REMARKS;
	}

	/**
	 * The table's columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'email_id'        => __( 'ID', 'wp-email' ),
			'from'            => __( 'From', 'wp-email' ),
			'to'              => __( 'To', 'wp-email' ),
			'email_timestamp' => __( 'Date / Time', 'wp-email' ),
			'ip'              => __( 'IP / Host', 'wp-email' ),
		);

		if ( $this->show_remarks() ) {
			$columns['email_yourremarks'] = __( 'Remarks', 'wp-email' );
		}

		$columns['email_posttitle'] = __( 'Post Title', 'wp-email' );
		$columns['email_status']    = __( 'Status', 'wp-email' );

		return $columns;
	}

	/**
	 * The sortable columns, keyed by the query value they map to.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'email_id'        => array( 'id', false ),
			'from'            => array( 'fromname', false ),
			'to'              => array( 'toname', false ),
			'email_timestamp' => array( 'date', true ),
			'ip'              => array( 'ip', false ),
			'email_posttitle' => array( 'posttitle', false ),
			'email_status'    => array( 'status', false ),
		);
	}

	/**
	 * Load the current page of rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'email_logs_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sorting and paging of an admin screen.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total = Email_Logs::count_all();

		$this->items = Email_Logs::query(
			array(
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $this->get_pagenum(),
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Message shown when there is nothing to list.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No E-Mail Logs Found', 'wp-email' );
	}

	/**
	 * Default rendering for a column.
	 *
	 * @param object $item        Log row.
	 * @param string $column_name Column being rendered.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'email_id':
				return esc_html( number_format_i18n( $item->email_id ) );

			case 'from':
				return esc_html( $item->email_yourname ) . '<br />' . esc_html( $item->email_youremail );

			case 'to':
				return esc_html( $item->email_friendname ) . '<br />' . esc_html( $item->email_friendemail );

			case 'email_timestamp':
				return esc_html(
					mysql2date(
						sprintf(
							/* translators: 1: Date format, 2: Time format. */
							_x( '%1$s @ %2$s', 'date and time format', 'wp-email' ),
							get_option( 'date_format' ),
							get_option( 'time_format' )
						),
						gmdate( 'Y-m-d H:i:s', (int) $item->email_timestamp )
					)
				);

			case 'ip':
				return esc_html( $item->email_ip ) . '<br />' . esc_html( $item->email_host );

			case 'email_status':
				return esc_html( Email_Logs::status_label( $item->email_status ) );

			default:
				return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
		}
	}
}
