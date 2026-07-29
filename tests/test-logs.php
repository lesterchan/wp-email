<?php
/**
 * The log table: schema, queries, sorting and status normalisation.
 *
 * @package WP-EMail
 */

/**
 * The log table.
 *
 * @covers WP_Email_Logs
 */
class WP_Email_Logs_Test extends WP_Email_TestCase {

	/**
	 * Insert a log row.
	 *
	 * @param array $overrides Column overrides.
	 *
	 * @return void
	 */
	private function log( array $overrides = array() ) {
		WP_Email_Logs::insert(
			array_merge(
				array(
					'yourname'    => 'Alice',
					'youremail'   => 'alice@example.com',
					'yourremarks' => 'remark',
					'friendname'  => 'Friend',
					'friendemail' => 'friend@example.com',
					'postid'      => 1,
					'posttitle'   => 'Title',
					'timestamp'   => time(),
					'ip'          => '198.51.100.1',
					'host'        => 'host.example.com',
					'status'      => WP_Email_Logs::STATUS_SUCCESS,
				),
				$overrides
			)
		);
	}

	public function test_the_table_exists_with_its_indexes() {
		global $wpdb;

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->email}" );
		$names   = wp_list_pluck( $indexes, 'Key_name' );

		$this->assertContains( 'PRIMARY', $names );

		// Every query the plugin runs filters on one of these, and none of them
		// was indexed before 3.0.0.
		$this->assertContains( 'email_postid', $names );
		$this->assertContains( 'email_status', $names );
		$this->assertContains( 'email_ip', $names );
	}

	public function test_the_table_is_registered_for_multisite_prefixing() {
		global $wpdb;

		// $wpdb->tables[] is what keeps the name correct across
		// switch_to_blog(); a bare $wpdb->email assignment is not enough.
		$this->assertContains( 'email', $wpdb->tables );
		$this->assertSame( $wpdb->prefix . 'email', $wpdb->email );
	}

	public function test_counts() {
		$this->log();
		$this->log();
		$this->log( array( 'status' => WP_Email_Logs::STATUS_FAILED ) );
		$this->log( array( 'postid' => 99 ) );

		$this->assertSame( 4, WP_Email_Logs::count_all() );
		$this->assertSame( 3, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ) );
		$this->assertSame( 1, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_FAILED ) );
		$this->assertSame( 3, WP_Email_Logs::count_for_post( 1 ) );
		$this->assertSame( 1, WP_Email_Logs::count_for_post( 99 ) );
	}

	public function test_last_sent_at_only_counts_successes() {
		$this->log(
			array(
				'ip'        => '203.0.113.1',
				'status'    => WP_Email_Logs::STATUS_FAILED,
				'timestamp' => 1000,
			)
		);

		$this->assertSame( 0, WP_Email_Logs::last_sent_at( '203.0.113.1' ) );

		$this->log(
			array(
				'ip'        => '203.0.113.1',
				'status'    => WP_Email_Logs::STATUS_SUCCESS,
				'timestamp' => 2000,
			)
		);

		$this->assertSame( 2000, WP_Email_Logs::last_sent_at( '203.0.113.1' ) );
	}

	public function test_query_sorts_on_a_whitelisted_column_only() {
		$this->log( array( 'yourname' => 'Zoe' ) );
		$this->log( array( 'yourname' => 'Adam' ) );

		$asc = WP_Email_Logs::query(
			array(
				'orderby' => 'fromname',
				'order'   => 'ASC',
			)
		);
		$this->assertSame( 'Adam', $asc[0]->email_yourname );

		$desc = WP_Email_Logs::query(
			array(
				'orderby' => 'fromname',
				'order'   => 'DESC',
			)
		);
		$this->assertSame( 'Zoe', $desc[0]->email_yourname );
	}

	public function test_an_unknown_orderby_falls_back_instead_of_reaching_sql() {
		$this->log();

		// An identifier cannot be bound, so the only safe handling is a lookup
		// against a fixed list; anything else must not reach the query.
		$rows = WP_Email_Logs::query( array( 'orderby' => 'email_id; DROP TABLE wp_posts' ) );

		$this->assertCount( 1, $rows );
	}

	public function test_query_paginates() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->log( array( 'yourname' => 'Name ' . $i ) );
		}

		$page_one = WP_Email_Logs::query(
			array(
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => 2,
				'paged'    => 1,
			)
		);
		$page_two = WP_Email_Logs::query(
			array(
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => 2,
				'paged'    => 2,
			)
		);

		$this->assertCount( 2, $page_one );
		$this->assertCount( 2, $page_two );
		$this->assertSame( 'Name 0', $page_one[0]->email_yourname );
		$this->assertSame( 'Name 2', $page_two[0]->email_yourname );
	}

	public function test_delete_all_empties_the_table() {
		$this->log();
		$this->log();

		WP_Email_Logs::delete_all();

		$this->assertSame( 0, WP_Email_Logs::count_all() );
	}

	public function test_status_is_stored_untranslated() {
		$this->log();

		$rows = WP_Email_Logs::query();

		// Storing __( 'Success' ) meant changing site language orphaned every
		// historical row from its own counters.
		$this->assertSame( 'Success', $rows[0]->email_status );
	}

	public function test_status_labels_are_translated_on_the_way_out() {
		$this->assertSame( __( 'Success', 'wp-email' ), WP_Email_Logs::status_label( WP_Email_Logs::STATUS_SUCCESS ) );
		$this->assertSame( 'Something else', WP_Email_Logs::status_label( 'Something else' ) );
	}

	public function test_install_is_safe_to_run_twice() {
		$this->log();

		WP_Email_Logs::install();

		$this->assertSame( 1, WP_Email_Logs::count_all() );
	}

	public function test_most_emailed_orders_by_volume() {
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$quiet = self::factory()->post->create(
			array(
				'post_title' => 'Quiet',
				'post_date'  => $past,
			)
		);
		$loud  = self::factory()->post->create(
			array(
				'post_title' => 'Loud',
				'post_date'  => $past,
			)
		);

		$this->log( array( 'postid' => $quiet ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->log( array( 'postid' => $loud ) );
		}

		$rows = WP_Email_Logs::most_emailed( 'post', 10 );

		$this->assertSame( $loud, (int) $rows[0]->ID );
		$this->assertSame( 3, (int) $rows[0]->email_total );
	}

	public function test_most_emailed_respects_its_limit() {
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		foreach ( range( 1, 4 ) as $i ) {
			$id = self::factory()->post->create( array( 'post_date' => $past ) );
			$this->log( array( 'postid' => $id ) );
		}

		$this->assertCount( 2, WP_Email_Logs::most_emailed( 'post', 2 ) );
	}

	public function test_most_emailed_skips_drafts_and_password_protected_posts() {
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$draft     = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_date'   => $past,
			)
		);
		$protected = self::factory()->post->create(
			array(
				'post_password' => 'secret',
				'post_date'     => $past,
			)
		);

		$this->log( array( 'postid' => $draft ) );
		$this->log( array( 'postid' => $protected ) );

		$this->assertSame( array(), WP_Email_Logs::most_emailed( 'post', 10 ) );
	}

	public function test_normalize_statuses_rewrites_translated_rows() {
		global $wpdb;

		$this->log();

		// Simulate what a non-English 2.x install left behind.
		$wpdb->update(
			$wpdb->email,
			array( 'email_status' => 'Réussi' ),
			array( 'email_status' => WP_Email_Logs::STATUS_SUCCESS ),
			array( '%s' ),
			array( '%s' )
		);

		$this->assertSame( 0, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ) );

		add_filter( 'gettext_wp-email', array( $this, 'translate_success' ), 10, 2 );
		WP_Email_Logs::normalize_statuses();
		remove_filter( 'gettext_wp-email', array( $this, 'translate_success' ), 10 );

		$this->assertSame( 1, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ) );
	}

	/**
	 * Stand in for a locale that translates the status.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 *
	 * @return string
	 */
	public function translate_success( $translation, $text ) {
		return 'Success' === $text ? 'Réussi' : $translation;
	}

	public function test_normalize_statuses_is_a_no_op_in_english() {
		$this->log();

		WP_Email_Logs::normalize_statuses();

		$this->assertSame( 1, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ) );
	}

	public function test_every_sortable_column_exists_on_the_table() {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->email}" );

		foreach ( WP_Email_Logs::sortable_columns() as $key => $column ) {
			$this->assertContains( $column, $columns, "{$key} maps to a column that does not exist" );
		}
	}

	public function test_counts_are_zero_on_an_empty_table() {
		$this->assertSame( 0, WP_Email_Logs::count_all() );
		$this->assertSame( 0, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ) );
		$this->assertSame( 0, WP_Email_Logs::count_for_post( 1 ) );
		$this->assertSame( array(), WP_Email_Logs::query() );
	}

	public function test_the_table_name_is_prefixed_for_the_current_site() {
		global $wpdb;

		$this->assertSame( $wpdb->email, WP_Email_Logs::table() );
		$this->assertStringEndsWith( 'email', WP_Email_Logs::table() );
	}
}
