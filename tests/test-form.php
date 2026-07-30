<?php
/**
 * The form: rendering, the flood interval, IP attribution and the send flow.
 *
 * @package WP-EMail
 */

/**
 * The e-mail form and its endpoint.
 *
 * @covers WP_Email_Form
 */
class WP_Email_Form_Test extends WP_Email_Ajax_TestCase {

	/**
	 * Post fixture.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * The arguments the last wp_mail() call was made with, or null if none was.
	 *
	 * Declared rather than assigned into: PHP 8.2 deprecates creating a dynamic
	 * property, and the shared PHPUnit config turns deprecations into
	 * exceptions, so an undeclared property fails every test in this class.
	 *
	 * @var array|null
	 */
	private $mail;

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Harness Post',
				'post_content' => 'One two three four five six seven eight nine ten.',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$_SERVER['REMOTE_ADDR'] = '198.51.100.200';

		// WP_Ajax_UnitTestCase::_handleAjax() fires admin_init the way
		// admin-ajax.php does, which drags in core's update checks. They cannot
		// reach wordpress.org from the container and raise an error that has
		// nothing to do with this plugin.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );

		$this->mail = null;

		// Nothing here has an MTA, and short-circuiting is the only way to
		// assert on what would have gone out.
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				unset( $short_circuit );
				$this->mail = $atts;
				return true;
			},
			10,
			2
		);

		$options                             = WP_Email_Options::all();
		$options['sending']['imageverify']   = 0;
		$options['templates']['subject']     = 'S: %EMAIL_YOUR_NAME% -> %EMAIL_POST_TITLE%';
		$options['templates']['body']        = 'B: %EMAIL_FRIEND_NAME% | %EMAIL_YOUR_REMARKS% | %EMAIL_POST_CONTENT%';
		$options['templates']['sentsuccess'] = 'OK: %EMAIL_POST_TITLE% -> %EMAIL_FRIEND_NAME%';
		$options['templates']['error']       = 'ERR: %EMAIL_ERROR_MSG%';
		WP_Email_Options::update( $options );
	}

	/**
	 * Clear the fixtures after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );

		parent::tear_down();
	}

	/**
	 * Drive the AJAX endpoint and return what it printed.
	 *
	 * @param array $post Fields to submit.
	 *
	 * @return string
	 */
	private function submit( array $post ) {
		$_POST = array_merge(
			array(
				'action'                  => 'email',
				'p'                       => $this->post_id,
				WP_Email_Form::NONCE_NAME => wp_create_nonce( WP_Email_Form::NONCE_ACTION ),
			),
			$post
		);

		$_REQUEST = $_POST;

		try {
			$this->_handleAjax( 'nopriv_email' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e ); // wp_die() with a 200, which is how the handler finishes.
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e ); // The same, on the early-exit paths.
		}

		return $this->_last_response;
	}


	public function test_form_renders_its_fields() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$form = WP_Email_Form::render( '', false );

		$this->assertStringContainsString( 'id="wp-email-content"', $form );
		$this->assertStringContainsString( 'name="friendemail"', $form );
		$this->assertStringContainsString( 'name="yourname"', $form );
		$this->assertStringContainsString( 'id="wp-email-submit"', $form );
		$this->assertStringContainsString( 'id="wp-email-loading"', $form );
		$this->assertStringContainsString( WP_Email_Form::NONCE_NAME, $form );
	}

	public function test_form_submit_button_has_no_inline_handler() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$form = WP_Email_Form::render( '', false );

		$this->assertStringNotContainsString( 'onclick', $form );
		$this->assertStringNotContainsString( 'onkeypress', $form );
	}

	public function test_disabling_a_field_removes_it_from_the_form() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options                          = WP_Email_Options::all();
		$options['fields']['yourremarks'] = 0;
		WP_Email_Options::update( $options );

		$this->assertStringNotContainsString( 'name="yourremarks"', WP_Email_Form::render( '', false ) );
	}

	public function test_form_action_points_at_a_registered_endpoint() {
		// The endpoint form of the URL only exists with pretty permalinks on.
		$this->set_permalink_structure( '/%postname%/' );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );
		the_post();

		$header = WP_Email_Form::header( $page_id, false );

		// 'emailpage/' was never registered as an endpoint.
		$this->assertStringNotContainsString( 'emailpage/', $header );
		$this->assertStringContainsString( 'email/', $header );

		$popup = WP_Email_Form::header( $page_id, true );

		$this->assertStringNotContainsString( 'emailpopuppage/', $popup );
		$this->assertStringContainsString( 'emailpopup/', $popup );
	}


	public function test_remote_addr_is_used_by_default() {
		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address() );
	}

	public function test_forwarded_for_is_ignored_unless_opted_in() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.1.2.3';

		// Trusting this by default let anyone bypass the flood interval by
		// sending a different value on each request.
		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address() );
	}

	public function test_a_configured_header_is_honoured() {
		$_SERVER['HTTP_X_REAL_IP'] = '10.9.9.9';

		$options                         = WP_Email_Options::all();
		$options['sending']['ip_header'] = 'HTTP_X_REAL_IP';
		WP_Email_Options::update( $options );

		$this->assertSame( '10.9.9.9', WP_Email_Form::ip_address() );

		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	public function test_a_garbage_header_value_falls_back_to_remote_addr() {
		$_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';

		$options                         = WP_Email_Options::all();
		$options['sending']['ip_header'] = 'HTTP_X_REAL_IP';
		WP_Email_Options::update( $options );

		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address() );

		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	public function test_the_ip_filter_wins() {
		add_filter( 'wp_email_ipaddress', static fn() => '1.2.3.4' );

		$this->assertSame( '1.2.3.4', WP_Email_Form::ip_address() );
	}


	public function test_flood_interval_blocks_a_repeat_from_the_same_ip() {
		$this->assertTrue( WP_Email_Form::not_spamming() );

		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Recent',
				'youremail'   => 'r@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $this->post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => $this->local_timestamp(),
				'ip'          => '198.51.100.200',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);

		$this->assertFalse( WP_Email_Form::not_spamming() );
	}

	public function test_a_zero_interval_disables_the_check() {
		$options                        = WP_Email_Options::all();
		$options['sending']['interval'] = 0;
		WP_Email_Options::update( $options );

		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Recent',
				'youremail'   => 'r@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $this->post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => $this->local_timestamp(),
				'ip'          => '198.51.100.200',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);

		$this->assertTrue( WP_Email_Form::not_spamming() );
	}


	public function test_valid_name_rejects_markup_characters() {
		$this->assertTrue( WP_Email_Form::is_valid_name( 'Mary Jane' ) );
		$this->assertFalse( WP_Email_Form::is_valid_name( 'Mary <b>' ) );
		$this->assertFalse( WP_Email_Form::is_valid_name( 'Bad #Name$' ) );
	}

	public function test_valid_remarks_rejects_header_injection() {
		$this->assertTrue( WP_Email_Form::is_valid_remarks( 'Hello there' ) );
		$this->assertFalse( WP_Email_Form::is_valid_remarks( "hi\nbcc: x@y.com\ncontent-type: text/html" ) );
	}


	public function test_a_successful_send() {
		$response = $this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'yourremarks' => 'Worth a read',
				'friendname'  => 'Friend One, Friend Two',
				'friendemail' => 'one@example.com,two@example.com',
			)
		);

		$this->assertStringContainsString( 'OK: Harness Post', $response );
		$this->assertStringNotContainsString( '%EMAIL_', $response );

		$this->assertIsArray( $this->mail );
		$this->assertSame( 'S: Sender Name -> Harness Post', $this->mail['subject'] );
		$this->assertStringContainsString( 'Worth a read', $this->mail['message'] );
		$this->assertStringContainsString( 'One two three', $this->mail['message'] );
		$this->assertStringNotContainsString( '%EMAIL_', $this->mail['message'] );

		$to = is_array( $this->mail['to'] ) ? implode( ', ', $this->mail['to'] ) : $this->mail['to'];
		$this->assertStringContainsString( 'one@example.com', $to );
		$this->assertStringContainsString( 'two@example.com', $to );

		$headers = implode( "\n", (array) $this->mail['headers'] );
		$this->assertStringContainsString( 'sender@example.com', $headers );
		$this->assertStringContainsString( 'text/html', $headers );
	}

	public function test_a_successful_send_logs_one_row_per_recipient() {
		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'yourremarks' => 'Worth a read',
				'friendname'  => 'Friend One, Friend Two',
				'friendemail' => 'one@example.com,two@example.com',
			)
		);

		$this->assertSame( 2, WP_Email_Logs::count_all() );

		$rows = WP_Email_Logs::query(
			array(
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$this->assertSame( 'Sender Name', $rows[0]->email_yourname );
		$this->assertSame( 'Friend One', $rows[0]->email_friendname );
		$this->assertSame( 'Friend Two', $rows[1]->email_friendname );
		$this->assertSame( WP_Email_Logs::STATUS_SUCCESS, $rows[0]->email_status );
		$this->assertSame( (string) $this->post_id, (string) $rows[0]->email_postid );
	}

	public function test_logged_values_are_not_double_escaped() {
		// The apostrophe goes in the remarks, not the name: is_valid_name()
		// rejects one outright, so a quoted name never reaches the log.
		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'yourremarks' => "It's good, and it's a \"quote\"",
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$rows = WP_Email_Logs::query();

		$this->assertCount( 1, $rows );

		// addslashes() before $wpdb->insert() used to store a second layer of
		// backslashes that the logs screen then had to strip back out.
		$this->assertStringContainsString( "It's good", $rows[0]->email_yourremarks );
		$this->assertStringNotContainsString( '\\', $rows[0]->email_yourremarks );
	}

	public function test_validation_failure_sends_nothing_and_logs_nothing() {
		$response = $this->submit(
			array(
				'yourname'    => 'Bad #Name$',
				'youremail'   => 'not-an-email',
				'yourremarks' => "hi\ncontent-type: text/html",
				'friendname'  => 'Friend One',
				'friendemail' => 'nope',
			)
		);

		$this->assertStringContainsString( 'ERR:', $response );
		$this->assertStringContainsString( 'Your Name is invalid', $response );
		$this->assertStringContainsString( 'Your Email is invalid', $response );
		$this->assertStringContainsString( 'Your Remarks is invalid', $response );
		$this->assertStringContainsString( 'Friend Email is invalid', $response );

		$this->assertNull( $this->mail );
		$this->assertSame( 0, WP_Email_Logs::count_all() );
	}

	public function test_the_first_error_is_not_truncated() {
		$response = $this->submit(
			array(
				'yourname'    => 'Bad #Name$',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		// substr( $error, 21 ) assumed a 21-character separator prefix, but
		// '<br /><strong>&raquo;</strong> ' is 31, so it ate nine characters of
		// the first message and left a stray closing tag in front of it.
		$this->assertStringNotContainsString( 'ERR: </strong>', $response );
		$this->assertStringContainsString( 'Your Name is invalid', $response );
	}

	public function test_a_failed_submission_comes_back_with_the_typed_values() {
		$response = $this->submit(
			array(
				'yourname'    => 'Bad #Name$',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'nope',
			)
		);

		// email_form() used to assign the field-values filter result
		// straight over its $error_field parameter, discarding the lot.
		$this->assertStringContainsString( 'Bad #Name$', $response );
		$this->assertStringContainsString( 'value="nope"', $response );
	}

	public function test_a_bad_nonce_stops_the_handler() {
		$_POST = array(
			'action'                  => 'email',
			'p'                       => $this->post_id,
			'yourname'                => 'Sender Name',
			'youremail'               => 'sender@example.com',
			'friendname'              => 'Friend One',
			'friendemail'             => 'one@example.com',
			WP_Email_Form::NONCE_NAME => 'not-a-valid-nonce',
		);

		$_REQUEST = $_POST;

		try {
			$this->_handleAjax( 'nopriv_email' );
		} catch ( WPAjaxDieContinueException $e ) {
			$unused = $e;
		} catch ( WPAjaxDieStopException $e ) {
			$unused = $e;
		}

		$this->assertStringContainsString( 'Failed To Verify Referrer', $this->_last_response );
		$this->assertNull( $this->mail );
		$this->assertSame( 0, WP_Email_Logs::count_all() );
	}

	public function test_recipients_beyond_the_maximum_are_rejected() {
		$options                        = WP_Email_Options::all();
		$options['sending']['multiple'] = 2;
		WP_Email_Options::update( $options );

		$response = $this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'A, B, C',
				'friendemail' => 'a@example.com,b@example.com,c@example.com',
			)
		);

		$this->assertStringContainsString( 'Maximum', $response );
		$this->assertNull( $this->mail );
	}

	public function test_a_send_for_an_unpublished_post_is_refused() {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$response = $this->submit(
			array(
				'p'           => $draft,
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$this->assertStringContainsString( 'Invalid post', $response );
		$this->assertNull( $this->mail );
		$this->assertSame( 0, WP_Email_Logs::count_all() );
	}

	public function test_a_plain_text_send_uses_the_alternate_body() {
		$options                           = WP_Email_Options::all();
		$options['sending']['contenttype'] = 'text/plain';
		$options['templates']['bodyalt']   = 'ALT: %EMAIL_POST_CONTENT%';
		WP_Email_Options::update( $options );

		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$this->assertIsArray( $this->mail );
		$this->assertStringContainsString( 'ALT:', $this->mail['message'] );
		$this->assertStringNotContainsString( '<p>', $this->mail['message'] );

		$headers = implode( "\n", (array) $this->mail['headers'] );
		$this->assertStringContainsString( 'text/plain', $headers );
	}

	public function test_an_html_send_on_an_rtl_site_is_wrapped() {
		// is_rtl() reads $wp_locale->text_direction directly; core has no
		// filter for it.
		$GLOBALS['wp_locale']->text_direction = 'rtl';

		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$GLOBALS['wp_locale']->text_direction = 'ltr';

		$this->assertIsArray( $this->mail );
		$this->assertStringContainsString( 'direction: rtl', $this->mail['message'] );
	}

	public function test_a_plain_text_send_is_never_wrapped() {
		$options                           = WP_Email_Options::all();
		$options['sending']['contenttype'] = 'text/plain';
		WP_Email_Options::update( $options );

		// is_rtl() reads $wp_locale->text_direction directly; core has no
		// filter for it.
		$GLOBALS['wp_locale']->text_direction = 'rtl';

		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$GLOBALS['wp_locale']->text_direction = 'ltr';

		$this->assertStringNotContainsString( 'direction: rtl', $this->mail['message'] );
	}

	public function test_a_refused_delivery_is_logged_as_failed() {
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$options                            = WP_Email_Options::all();
		$options['templates']['sentfailed'] = 'FAILED: %EMAIL_FRIEND_NAME%';
		WP_Email_Options::update( $options );

		$response = $this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$this->assertStringContainsString( 'FAILED: Friend One', $response );

		$rows = WP_Email_Logs::query();
		$this->assertSame( WP_Email_Logs::STATUS_FAILED, $rows[0]->email_status );
	}

	public function test_a_send_without_friend_names_still_addresses_everyone() {
		$options                         = WP_Email_Options::all();
		$options['fields']['friendname'] = 0;
		WP_Email_Options::update( $options );

		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendemail' => 'one@example.com,two@example.com',
			)
		);

		$this->assertIsArray( $this->mail );

		$to = is_array( $this->mail['to'] ) ? implode( ', ', $this->mail['to'] ) : $this->mail['to'];

		$this->assertStringContainsString( 'one@example.com', $to );
		$this->assertStringContainsString( 'two@example.com', $to );
		$this->assertSame( 2, WP_Email_Logs::count_all() );
	}

	public function test_an_empty_remark_becomes_not_applicable() {
		$options                      = WP_Email_Options::all();
		$options['templates']['body'] = 'R: %EMAIL_YOUR_REMARKS%';
		WP_Email_Options::update( $options );

		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		$this->assertStringContainsString( 'R: N/A', $this->mail['message'] );
	}

	public function test_the_subject_is_decoded_for_the_header() {
		$options                         = WP_Email_Options::all();
		$options['templates']['subject'] = 'Read &amp; enjoy: %EMAIL_POST_TITLE%';
		WP_Email_Options::update( $options );

		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'one@example.com',
			)
		);

		// Entities belong in a body, not in a Subject: header.
		$this->assertStringContainsString( 'Read & enjoy', $this->mail['subject'] );
		$this->assertStringNotContainsString( '&amp;', $this->mail['subject'] );
	}

	public function test_a_blocked_visitor_is_told_to_wait() {
		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Recent',
				'youremail'   => 'r@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $this->post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => $this->local_timestamp(),
				'ip'          => '198.51.100.200',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);

		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$form = WP_Email_Form::render( '', false );

		$this->assertStringContainsString( 'Please wait', $form );
		$this->assertStringNotContainsString( 'name="friendemail"', $form );
	}

	public function test_a_protected_post_shows_the_password_form() {
		$protected = self::factory()->post->create(
			array( 'post_password' => 'hunter2' )
		);

		$this->go_to( get_permalink( $protected ) );
		the_post();

		$form = WP_Email_Form::render( '', false );

		$this->assertStringNotContainsString( 'name="friendemail"', $form );
		$this->assertStringContainsString( 'post_password', $form );
	}

	public function test_the_form_action_falls_back_to_a_query_var() {
		$this->set_permalink_structure( '' );

		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertStringContainsString( 'wp_email=1', WP_Email_Form::header( $this->post_id, false ) );
		$this->assertStringContainsString( 'wp_email_popup=1', WP_Email_Form::header( $this->post_id, true ) );
	}

	public function test_the_header_names_the_right_id_field() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertStringContainsString( 'name="p"', WP_Email_Form::header( $this->post_id, false ) );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );
		the_post();

		$this->assertStringContainsString( 'name="page_id"', WP_Email_Form::header( $page_id, false ) );
	}

	public function test_the_recipient_cap_has_a_floor() {
		$options                        = WP_Email_Options::all();
		$options['sending']['multiple'] = 0;
		WP_Email_Options::update( $options );

		$this->assertSame( 1, WP_Email_Form::max_recipients() );
	}

	public function test_the_multiple_hint_appears_only_when_useful() {
		$options                        = WP_Email_Options::all();
		$options['sending']['multiple'] = 1;
		WP_Email_Options::update( $options );

		$this->assertSame( '', WP_Email_Form::multiple_hint() );

		$options['sending']['multiple'] = 4;
		WP_Email_Options::update( $options );

		$this->assertStringContainsString( 'Maximum 4 entries', WP_Email_Form::multiple_hint() );
	}

	public function test_recipients_may_be_separated_by_semicolons() {
		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One; Friend Two',
				'friendemail' => 'one@example.com;two@example.com',
			)
		);

		$this->assertSame( 2, WP_Email_Logs::count_all() );
	}

	public function test_stray_separators_are_ignored() {
		$this->submit(
			array(
				'yourname'    => 'Sender Name',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One,',
				'friendemail' => 'one@example.com,',
			)
		);

		$this->assertSame( 1, WP_Email_Logs::count_all() );
	}

	public function test_the_trust_proxy_filter_opts_in() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address() );

		add_filter( 'wp_email_trust_proxy', '__return_true' );

		$this->assertSame( '203.0.113.7', WP_Email_Form::ip_address() );

		remove_filter( 'wp_email_trust_proxy', '__return_true' );
	}

	public function test_the_trust_proxy_filter_can_decide_per_request() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

		// The documented pattern: trust the header only when the request
		// actually arrives from a known load balancer.
		$only_from_balancer = static function () {
			return isset( $_SERVER['REMOTE_ADDR'] ) && '10.0.0.1' === $_SERVER['REMOTE_ADDR'];
		};

		add_filter( 'wp_email_trust_proxy', $only_from_balancer );

		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address() );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

		$this->assertSame( '203.0.113.7', WP_Email_Form::ip_address() );

		remove_filter( 'wp_email_trust_proxy', $only_from_balancer );
	}

	public function test_a_named_header_wins_over_the_filter() {
		$options                         = WP_Email_Options::all();
		$options['sending']['ip_header'] = 'HTTP_X_REAL_IP';
		WP_Email_Options::update( $options );

		$_SERVER['HTTP_X_REAL_IP']       = '203.0.113.20';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.21';

		add_filter( 'wp_email_trust_proxy', '__return_true' );

		$this->assertSame( '203.0.113.20', WP_Email_Form::ip_address() );

		remove_filter( 'wp_email_trust_proxy', '__return_true' );
		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	public function test_the_trust_proxy_filter_defaults_to_false() {
		$seen = null;

		add_filter(
			'wp_email_trust_proxy',
			function ( $trust ) use ( &$seen ) {
				$seen = $trust;
				return $trust;
			}
		);

		WP_Email_Form::ip_address();

		remove_all_filters( 'wp_email_trust_proxy' );

		$this->assertFalse( $seen );
	}
}
