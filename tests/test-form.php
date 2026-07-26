<?php
/**
 * The form: rendering, the flood interval, IP attribution and the send flow.
 *
 * @package WP-EMail
 */

/**
 * The e-mail form and its endpoint.
 *
 * @covers Email_Form
 */
class Test_Email_Form extends WP_Ajax_UnitTestCase {

	/**
	 * Post fixture.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * What wp_mail() was asked to send, if anything.
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

		$options                             = Email_Options::all();
		$options['sending']['imageverify']   = 0;
		$options['templates']['subject']     = 'S: %EMAIL_YOUR_NAME% -> %EMAIL_POST_TITLE%';
		$options['templates']['body']        = 'B: %EMAIL_FRIEND_NAME% | %EMAIL_YOUR_REMARKS% | %EMAIL_POST_CONTENT%';
		$options['templates']['sentsuccess'] = 'OK: %EMAIL_POST_TITLE% -> %EMAIL_FRIEND_NAME%';
		$options['templates']['error']       = 'ERR: %EMAIL_ERROR_MSG%';
		Email_Options::update( $options );
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
				'action'               => 'email',
				'p'                    => $this->post_id,
				Email_Form::NONCE_NAME => wp_create_nonce( Email_Form::NONCE_ACTION ),
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

	// ------------------------------------------------------------ rendering --

	/**
	 * Form renders its fields.
	 */
	public function test_form_renders_its_fields() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$form = Email_Form::render( '', false );

		$this->assertStringContainsString( 'id="wp-email-content"', $form );
		$this->assertStringContainsString( 'name="friendemail"', $form );
		$this->assertStringContainsString( 'name="yourname"', $form );
		$this->assertStringContainsString( 'id="wp-email-submit"', $form );
		$this->assertStringContainsString( 'id="wp-email-loading"', $form );
		$this->assertStringContainsString( Email_Form::NONCE_NAME, $form );
	}

	/**
	 * Form submit button has no inline handler.
	 */
	public function test_form_submit_button_has_no_inline_handler() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$form = Email_Form::render( '', false );

		$this->assertStringNotContainsString( 'onclick', $form );
		$this->assertStringNotContainsString( 'onkeypress', $form );
	}

	/**
	 * Disabling a field removes it from the form.
	 */
	public function test_disabling_a_field_removes_it_from_the_form() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options                          = Email_Options::all();
		$options['fields']['yourremarks'] = 0;
		Email_Options::update( $options );

		$this->assertStringNotContainsString( 'name="yourremarks"', Email_Form::render( '', false ) );
	}

	/**
	 * 'emailpage/' and 'emailpopuppage/' were never registered.
	 */
	public function test_form_action_points_at_a_registered_endpoint() {
		// The endpoint form of the URL only exists with pretty permalinks on.
		$this->set_permalink_structure( '/%postname%/' );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );
		the_post();

		$header = Email_Form::header( $page_id, false );

		// 'emailpage/' was never registered as an endpoint.
		$this->assertStringNotContainsString( 'emailpage/', $header );
		$this->assertStringContainsString( 'email/', $header );

		$popup = Email_Form::header( $page_id, true );

		$this->assertStringNotContainsString( 'emailpopuppage/', $popup );
		$this->assertStringContainsString( 'emailpopup/', $popup );
	}

	// ------------------------------------------------------ IP attribution --

	/**
	 * Remote addr is used by default.
	 */
	public function test_remote_addr_is_used_by_default() {
		$this->assertSame( '198.51.100.200', Email_Form::ip_address() );
	}

	/**
	 * Trusting the header by default let anyone bypass the flood interval.
	 */
	public function test_forwarded_for_is_ignored_unless_opted_in() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.1.2.3';

		// Trusting this by default let anyone bypass the flood interval by
		// sending a different value on each request.
		$this->assertSame( '198.51.100.200', Email_Form::ip_address() );
	}

	/**
	 * A configured header is honoured.
	 */
	public function test_a_configured_header_is_honoured() {
		$_SERVER['HTTP_X_REAL_IP'] = '10.9.9.9';

		$options                         = Email_Options::all();
		$options['sending']['ip_header'] = 'HTTP_X_REAL_IP';
		Email_Options::update( $options );

		$this->assertSame( '10.9.9.9', Email_Form::ip_address() );

		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	/**
	 * A garbage header value falls back to remote addr.
	 */
	public function test_a_garbage_header_value_falls_back_to_remote_addr() {
		$_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';

		$options                         = Email_Options::all();
		$options['sending']['ip_header'] = 'HTTP_X_REAL_IP';
		Email_Options::update( $options );

		$this->assertSame( '198.51.100.200', Email_Form::ip_address() );

		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	/**
	 * The ip filter wins.
	 */
	public function test_the_ip_filter_wins() {
		add_filter( 'wp_email_ipaddress', static fn() => '1.2.3.4' );

		$this->assertSame( '1.2.3.4', Email_Form::ip_address() );
	}

	// ------------------------------------------------------ flood interval --

	/**
	 * Flood interval blocks a repeat from the same ip.
	 */
	public function test_flood_interval_blocks_a_repeat_from_the_same_ip() {
		$this->assertTrue( Email_Form::not_spamming() );

		Email_Logs::insert(
			array(
				'yourname'    => 'Recent',
				'youremail'   => 'r@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $this->post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => current_time( 'timestamp' ),
				'ip'          => '198.51.100.200',
				'host'        => '',
				'status'      => Email_Logs::STATUS_SUCCESS,
			)
		);

		$this->assertFalse( Email_Form::not_spamming() );
	}

	/**
	 * A zero interval disables the check.
	 */
	public function test_a_zero_interval_disables_the_check() {
		$options                        = Email_Options::all();
		$options['sending']['interval'] = 0;
		Email_Options::update( $options );

		Email_Logs::insert(
			array(
				'yourname'    => 'Recent',
				'youremail'   => 'r@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $this->post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => current_time( 'timestamp' ),
				'ip'          => '198.51.100.200',
				'host'        => '',
				'status'      => Email_Logs::STATUS_SUCCESS,
			)
		);

		$this->assertTrue( Email_Form::not_spamming() );
	}

	// --------------------------------------------------------- validation ---

	/**
	 * Valid name rejects markup characters.
	 */
	public function test_valid_name_rejects_markup_characters() {
		$this->assertTrue( Email_Form::is_valid_name( 'Mary Jane' ) );
		$this->assertFalse( Email_Form::is_valid_name( 'Mary <b>' ) );
		$this->assertFalse( Email_Form::is_valid_name( 'Bad #Name$' ) );
	}

	/**
	 * Valid remarks rejects header injection.
	 */
	public function test_valid_remarks_rejects_header_injection() {
		$this->assertTrue( Email_Form::is_valid_remarks( 'Hello there' ) );
		$this->assertFalse( Email_Form::is_valid_remarks( "hi\nbcc: x@y.com\ncontent-type: text/html" ) );
	}

	// -------------------------------------------------------- the send flow --

	/**
	 * A successful send.
	 */
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

	/**
	 * A successful send logs one row per recipient.
	 */
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

		$this->assertSame( 2, Email_Logs::count_all() );

		$rows = Email_Logs::query(
			array(
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$this->assertSame( 'Sender Name', $rows[0]->email_yourname );
		$this->assertSame( 'Friend One', $rows[0]->email_friendname );
		$this->assertSame( 'Friend Two', $rows[1]->email_friendname );
		$this->assertSame( Email_Logs::STATUS_SUCCESS, $rows[0]->email_status );
		$this->assertSame( (string) $this->post_id, (string) $rows[0]->email_postid );
	}

	/**
	 * Addslashes() before $wpdb->insert() stored a second layer of backslashes.
	 */
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

		$rows = Email_Logs::query();

		$this->assertCount( 1, $rows );

		// addslashes() before $wpdb->insert() used to store a second layer of
		// backslashes that the logs screen then had to strip back out.
		$this->assertStringContainsString( "It's good", $rows[0]->email_yourremarks );
		$this->assertStringNotContainsString( '\\', $rows[0]->email_yourremarks );
	}

	/**
	 * Validation failure sends nothing and logs nothing.
	 */
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
		$this->assertSame( 0, Email_Logs::count_all() );
	}

	/**
	 * The old substr() ate nine characters of the first validation message.
	 */
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

	/**
	 * The form used to discard everything the visitor typed on any error.
	 */
	public function test_a_failed_submission_comes_back_with_the_typed_values() {
		$response = $this->submit(
			array(
				'yourname'    => 'Bad #Name$',
				'youremail'   => 'sender@example.com',
				'friendname'  => 'Friend One',
				'friendemail' => 'nope',
			)
		);

		// email_form() used to assign the email_form-fieldvalues filter result
		// straight over its $error_field parameter, discarding the lot.
		$this->assertStringContainsString( 'Bad #Name$', $response );
		$this->assertStringContainsString( 'value="nope"', $response );
	}

	/**
	 * A bad nonce stops the handler.
	 */
	public function test_a_bad_nonce_stops_the_handler() {
		$_POST = array(
			'action'               => 'email',
			'p'                    => $this->post_id,
			'yourname'             => 'Sender Name',
			'youremail'            => 'sender@example.com',
			'friendname'           => 'Friend One',
			'friendemail'          => 'one@example.com',
			Email_Form::NONCE_NAME => 'not-a-valid-nonce',
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
		$this->assertSame( 0, Email_Logs::count_all() );
	}

	/**
	 * Recipients beyond the maximum are rejected.
	 */
	public function test_recipients_beyond_the_maximum_are_rejected() {
		$options                        = Email_Options::all();
		$options['sending']['multiple'] = 2;
		Email_Options::update( $options );

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

	/**
	 * A send for an unpublished post is refused.
	 */
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
		$this->assertSame( 0, Email_Logs::count_all() );
	}
}
