<?php
/**
 * WP-EMail class-wp-email-logs.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every read and write against the plugin's own table.
 *
 * @since 3.0.0
 */
class WP_Email_Logs {

	/**
	 * Status stored for a message that was handed to wp_mail() successfully.
	 *
	 * Stored untranslated. Before 3.0.0 the translated string went into the
	 * column, so a site that changed language could no longer match its own
	 * historical rows.
	 */
	const STATUS_SUCCESS = 'Success';

	/**
	 * Status stored for a message wp_mail() refused.
	 */
	const STATUS_FAILED = 'Failed';

	/**
	 * The plugin's own table, prefixed for whichever site is current.
	 *
	 * WP_Email::register_table() adds 'email' to $wpdb->tables, so $wpdb->email
	 * is re-prefixed across switch_to_blog(). The fallback is for uninstall.php,
	 * which loads this class without booting the plugin: $wpdb->prefix tracks
	 * the switched site by itself.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return isset( $wpdb->email ) ? $wpdb->email : $wpdb->prefix . 'email';
	}

	/**
	 * The columns the logs screen may sort on.
	 *
	 * @return array Query key => column name.
	 */
	public static function sortable_columns() {
		return array(
			'id'        => 'email_id',
			'fromname'  => 'email_yourname',
			'fromemail' => 'email_youremail',
			'toname'    => 'email_friendname',
			'toemail'   => 'email_friendemail',
			'date'      => 'email_timestamp',
			'postid'    => 'email_postid',
			'posttitle' => 'email_posttitle',
			'ip'        => 'email_ip',
			'host'      => 'email_host',
			'status'    => 'email_status',
		);
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->email} (
				email_id bigint(20) unsigned NOT NULL auto_increment,
				email_yourname varchar(200) NOT NULL default '',
				email_youremail varchar(200) NOT NULL default '',
				email_yourremarks text NOT NULL,
				email_friendname varchar(200) NOT NULL default '',
				email_friendemail varchar(200) NOT NULL default '',
				email_postid bigint(20) unsigned NOT NULL default 0,
				email_posttitle text NOT NULL,
				email_timestamp varchar(20) NOT NULL default '',
				email_ip varchar(100) NOT NULL default '',
				email_host varchar(200) NOT NULL default '',
				email_status varchar(20) NOT NULL default '',
				PRIMARY KEY  (email_id),
				KEY email_postid (email_postid),
				KEY email_status (email_status),
				KEY email_ip (email_ip)
			) {$charset_collate};"
		);
	}

	/**
	 * Rewrite statuses that were stored translated.
	 *
	 * Before 3.0.0 the column held __( 'Success' ) rather than 'Success', so on
	 * a translated site every count and every flood-interval lookup compared
	 * against whatever the active locale happened to render. Run once from the
	 * upgrade routine, against the locale the rows were written in.
	 *
	 * @return void
	 */
	public static function normalize_statuses() {
		global $wpdb;

		$map = array(
			self::STATUS_SUCCESS => __( 'Success', 'wp-email' ),
			self::STATUS_FAILED  => __( 'Failed', 'wp-email' ),
		);

		foreach ( $map as $canonical => $translated ) {
			if ( $canonical === $translated ) {
				continue;
			}

			$wpdb->update(
				$wpdb->email,
				array( 'email_status' => $canonical ),
				array( 'email_status' => $translated ),
				array( '%s' ),
				array( '%s' )
			);
		}
	}

	/**
	 * A status rendered for display.
	 *
	 * @param string $status Stored status.
	 *
	 * @return string
	 */
	public static function status_label( $status ) {
		if ( self::STATUS_SUCCESS === $status ) {
			return __( 'Success', 'wp-email' );
		}

		if ( self::STATUS_FAILED === $status ) {
			return __( 'Failed', 'wp-email' );
		}

		return $status;
	}

	/**
	 * Record one delivery.
	 *
	 * @param array $row Column => value.
	 *
	 * @return void
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->email,
			array(
				'email_yourname'    => $row['yourname'],
				'email_youremail'   => $row['youremail'],
				'email_yourremarks' => $row['yourremarks'],
				'email_friendname'  => $row['friendname'],
				'email_friendemail' => $row['friendemail'],
				'email_postid'      => $row['postid'],
				'email_posttitle'   => $row['posttitle'],
				'email_timestamp'   => $row['timestamp'],
				'email_ip'          => $row['ip'],
				'email_host'        => $row['host'],
				'email_status'      => $row['status'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Total number of logged messages.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(email_id) FROM {$wpdb->email}" );
	}

	/**
	 * Number of logged messages with a given status.
	 *
	 * @param string $status Either self::STATUS_SUCCESS or self::STATUS_FAILED.
	 *
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(email_id) FROM {$wpdb->email} WHERE email_status = %s", $status )
		);
	}

	/**
	 * Number of logged messages for one post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int
	 */
	public static function count_for_post( $post_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(email_id) FROM {$wpdb->email} WHERE email_postid = %d", $post_id )
		);
	}

	/**
	 * When the given IP last sent something successfully.
	 *
	 * @param string $ip IP address.
	 *
	 * @return int Unix timestamp, or 0 when it never has.
	 */
	public static function last_sent_at( $ip ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT email_timestamp FROM {$wpdb->email} WHERE email_ip = %s AND email_status = %s ORDER BY email_timestamp DESC LIMIT 1",
				$ip,
				self::STATUS_SUCCESS
			)
		);
	}

	/**
	 * The most e-mailed posts.
	 *
	 * @param string $mode  'post', 'page', 'both', or any post type.
	 * @param int    $limit Maximum rows.
	 *
	 * @return array
	 */
	public static function most_emailed( $mode = 'both', $limit = 10 ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );

		// Two whole queries rather than one with an assembled WHERE clause:
		// prepare() has to be called at the query site for the sniff to see it,
		// and a $sql built above and passed in reads as unprepared however it
		// was built.
		if ( empty( $mode ) || 'both' === $mode ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$wpdb->posts}.*, COUNT({$wpdb->email}.email_postid) AS email_total
					FROM {$wpdb->email}
					LEFT JOIN {$wpdb->posts} ON {$wpdb->email}.email_postid = {$wpdb->posts}.ID
					WHERE post_date < %s AND post_password = '' AND post_status = 'publish'
					GROUP BY {$wpdb->email}.email_postid
					ORDER BY email_total DESC
					LIMIT %d",
					current_time( 'mysql' ),
					$limit
				)
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$wpdb->posts}.*, COUNT({$wpdb->email}.email_postid) AS email_total
				FROM {$wpdb->email}
				LEFT JOIN {$wpdb->posts} ON {$wpdb->email}.email_postid = {$wpdb->posts}.ID
				WHERE post_date < %s AND post_type = %s AND post_password = '' AND post_status = 'publish'
				GROUP BY {$wpdb->email}.email_postid
				ORDER BY email_total DESC
				LIMIT %d",
				current_time( 'mysql' ),
				$mode,
				$limit
			)
		);
	}

	/**
	 * A page of log rows.
	 *
	 * @param array $args orderby, order, per_page, paged.
	 *
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'orderby'  => 'date',
				'order'    => 'DESC',
				'per_page' => 20,
				'paged'    => 1,
			)
		);

		// Whitelisted, never interpolated from the request: an identifier
		// cannot be bound, so the only safe form is a lookup against a fixed
		// list.
		$columns = self::sortable_columns();
		$orderby = isset( $columns[ $args['orderby'] ] ) ? $columns[ $args['orderby'] ] : 'email_timestamp';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		// The column goes through prepare()'s %i identifier placeholder and the
		// direction is one of two literal queries, so nothing reaches the SQL by
		// interpolation. The whitelist above still stands: %i would quote an
		// unknown column name rather than reject it.
		if ( 'ASC' === $order ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->email} ORDER BY %i ASC LIMIT %d, %d",
					$orderby,
					$offset,
					$per_page
				)
			);
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->email} ORDER BY %i DESC LIMIT %d, %d",
				$orderby,
				$offset,
				$per_page
			)
		);
	}

	/**
	 * Delete every log row.
	 *
	 * @return int Rows removed.
	 */
	public static function delete_all() {
		global $wpdb;

		return (int) $wpdb->query( "DELETE FROM {$wpdb->email}" );
	}

	/**
	 * Drop the table, for uninstall.
	 *
	 * The name goes through prepare()'s %i identifier placeholder rather than
	 * being interpolated: it is built from $wpdb->prefix and a literal so there
	 * is nothing to escape, but %i is the form the standard asks for and it
	 * costs nothing to use it.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}
}
