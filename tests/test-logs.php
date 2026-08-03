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

		$this->assertContains( 'PRIMARY', $names, 'The table has its primary key.' );

		// Every query the plugin runs filters on one of these, and none of them
		// was indexed before 3.0.0.
		$this->assertContains( 'email_postid', $names, 'An index on the post.' );
		$this->assertContains( 'email_status', $names, 'On the status.' );
		$this->assertContains( 'email_ip', $names, 'And on the address, which is what the interval check reads.' );
	}

	public function test_the_table_is_registered_for_multisite_prefixing() {
		global $wpdb;

		// $wpdb->tables[] is what keeps the name correct across
		// switch_to_blog(); a bare $wpdb->email assignment is not enough.
		$this->assertContains( 'email', $wpdb->tables, 'The table is registered as blog scoped.' );
		$this->assertSame( $wpdb->prefix . 'email', $wpdb->email, 'Under the table prefix.' );
	}

	public function test_counts() {
		$this->log();
		$this->log();
		$this->log( array( 'status' => WP_Email_Logs::STATUS_FAILED ) );
		$this->log( array( 'postid' => 99 ) );

		$this->assertSame( 4, WP_Email_Logs::count_all(), 'Every row is counted.' );
		$this->assertSame( 3, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ), 'The successes are counted on their own.' );
		$this->assertSame( 1, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_FAILED ), 'And the failures.' );
		$this->assertSame( 3, WP_Email_Logs::count_for_post( 1 ), 'A post is counted on its own.' );
		$this->assertSame( 1, WP_Email_Logs::count_for_post( 99 ), 'And so is another, so the two are not sharing a count.' );
	}

	public function test_last_sent_at_only_counts_successes() {
		$this->log(
			array(
				'ip'        => '203.0.113.1',
				'status'    => WP_Email_Logs::STATUS_FAILED,
				'timestamp' => 1000,
			)
		);

		$this->assertSame( 0, WP_Email_Logs::last_sent_at( '203.0.113.1' ), 'A failure is not a send, so there is nothing to wait for.' );

		$this->log(
			array(
				'ip'        => '203.0.113.1',
				'status'    => WP_Email_Logs::STATUS_SUCCESS,
				'timestamp' => 2000,
			)
		);

		$this->assertSame( 2000, WP_Email_Logs::last_sent_at( '203.0.113.1' ), 'While a success is, and is what the interval counts from.' );
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
		$this->assertSame( 'Adam', $asc[0]->email_yourname, 'Ascending puts the first name first.' );

		$desc = WP_Email_Logs::query(
			array(
				'orderby' => 'fromname',
				'order'   => 'DESC',
			)
		);
		$this->assertSame( 'Zoe', $desc[0]->email_yourname, 'And descending puts the last one first.' );
	}

	public function test_an_unknown_orderby_falls_back_instead_of_reaching_sql() {
		$this->log();

		// An identifier cannot be bound, so the only safe handling is a lookup
		// against a fixed list; anything else must not reach the query.
		$rows = WP_Email_Logs::query( array( 'orderby' => 'email_id; DROP TABLE wp_posts' ) );

		$this->assertCount( 1, $rows, 'The query returns the one row that matches.' );
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

		$this->assertCount( 2, $page_one, 'The first page holds its two rows.' );
		$this->assertCount( 2, $page_two, 'The second page holds the other two.' );
		$this->assertSame( 'Name 0', $page_one[0]->email_yourname, 'The first page starts at the first row.' );
		$this->assertSame( 'Name 2', $page_two[0]->email_yourname, 'And the second page starts where it left off.' );
	}

	public function test_delete_all_empties_the_table() {
		$this->log();
		$this->log();

		WP_Email_Logs::delete_all();

		$this->assertSame( 0, WP_Email_Logs::count_all(), 'Deleting all empties the table.' );
	}

	public function test_status_is_stored_untranslated() {
		$this->log();

		$rows = WP_Email_Logs::query();

		// Storing __( 'Success' ) meant changing site language orphaned every
		// historical row from its own counters.
		$this->assertSame( 'Success', $rows[0]->email_status, 'The status is stored in English, whatever the site language is.' );
	}

	public function test_status_labels_are_translated_on_the_way_out() {
		$this->assertSame( __( 'Success', 'wp-email' ), WP_Email_Logs::status_label( WP_Email_Logs::STATUS_SUCCESS ), 'And is translated on the way out.' );
		$this->assertSame( 'Something else', WP_Email_Logs::status_label( 'Something else' ), 'While a status with no translation is passed through as it stands.' );
	}

	public function test_install_is_safe_to_run_twice() {
		$this->log();

		WP_Email_Logs::install();

		$this->assertSame( 1, WP_Email_Logs::count_all(), 'Installing twice does not lose the rows already there.' );
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

		$this->assertSame( $loud, (int) $rows[0]->ID, 'The most emailed post is first.' );
		$this->assertSame( 3, (int) $rows[0]->email_total, 'With its count, so the order can be checked against something.' );
	}

	public function test_most_emailed_respects_its_limit() {
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		foreach ( range( 1, 4 ) as $i ) {
			$id = self::factory()->post->create( array( 'post_date' => $past ) );
			$this->log( array( 'postid' => $id ) );
		}

		$this->assertCount( 2, WP_Email_Logs::most_emailed( 'post', 2 ), 'The limit caps the most-emailed listing.' );
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

		$this->assertSame( array(), WP_Email_Logs::most_emailed( 'post', 10 ), 'A draft or a password protected post is never listed.' );
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

		$this->assertSame( 0, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ), 'A translated status is not counted as a success.' );

		add_filter( 'gettext_wp-email', array( $this, 'translate_success' ), 10, 2 );
		WP_Email_Logs::normalize_statuses();
		remove_filter( 'gettext_wp-email', array( $this, 'translate_success' ), 10 );

		$this->assertSame( 1, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ), 'Until it is rewritten, which is what the normaliser is for.' );
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

		$this->assertSame( 1, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ), 'On an English site there is nothing to rewrite, and the count is unchanged.' );
	}

	public function test_every_sortable_column_exists_on_the_table() {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->email}" );

		foreach ( WP_Email_Logs::sortable_columns() as $key => $column ) {
			$this->assertContains( $column, $columns, "{$key} maps to a column that does not exist" );
		}
	}

	public function test_counts_are_zero_on_an_empty_table() {
		$this->assertSame( 0, WP_Email_Logs::count_all(), 'An empty table counts no rows.' );
		$this->assertSame( 0, WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ), 'No successes.' );
		$this->assertSame( 0, WP_Email_Logs::count_for_post( 1 ), 'Nothing for a post.' );
		$this->assertSame( array(), WP_Email_Logs::query(), 'And returns an empty list rather than nothing at all.' );
	}

	public function test_the_table_name_is_prefixed_for_the_current_site() {
		global $wpdb;

		$this->assertSame( $wpdb->email, WP_Email_Logs::table(), 'The table name is the one wpdb prefixed for this site.' );
		$this->assertStringEndsWith( 'email', WP_Email_Logs::table(), 'Ending in the table name itself.' );
	}
}
