<?php
/**
 * The WP_EMAIL_TRUST_PROXY opt-in.
 *
 * @package WP-EMail
 */

/**
 * Tests for the reverse-proxy opt-in constant.
 *
 * This is the migration path 3.0.0 hands to sites behind Cloudflare or another
 * reverse proxy, so it needs covering. It runs in its own process because the
 * opt-in is a constant: defining it in the shared run would silently change
 * what every other IP test is asserting.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @coversNothing
 */
class WP_Email_Trust_Proxy_Test extends WP_Email_TestCase {

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.200';
	}

	/**
	 * Clear the request state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_CF_CONNECTING_IP']
		);

		parent::tear_down();
	}

	public function test_the_constant_opts_into_the_proxy_headers() {
		define( 'WP_EMAIL_TRUST_PROXY', true );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

		$this->assertSame( '203.0.113.7', WP_Email_Form::ip_address(), 'The constant opts into the proxy headers.' );
	}

	public function test_the_cloudflare_header_wins() {
		define( 'WP_EMAIL_TRUST_PROXY', true );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.8';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.9';

		$this->assertSame( '203.0.113.8', WP_Email_Form::ip_address(), 'The Cloudflare header wins over the forwarded one.' );
	}

	public function test_the_first_address_in_a_chain_is_used() {
		define( 'WP_EMAIL_TRUST_PROXY', true );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10, 70.41.3.18, 150.172.238.178';

		$this->assertSame( '203.0.113.10', WP_Email_Form::ip_address(), 'Only the first address in a chain is used.' );
	}

	public function test_a_junk_header_falls_back() {
		define( 'WP_EMAIL_TRUST_PROXY', true );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, still-not-an-ip';

		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address(), 'A junk header falls back to the remote address.' );
	}

	public function test_a_named_header_still_wins() {
		define( 'WP_EMAIL_TRUST_PROXY', true );

		$options                         = WP_Email_Options::all();
		$options['sending']['ip_header'] = 'HTTP_X_REAL_IP';
		WP_Email_Options::update( $options );

		$_SERVER['HTTP_X_REAL_IP']       = '203.0.113.20';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.21';

		$this->assertSame( '203.0.113.20', WP_Email_Form::ip_address(), 'A named header still wins, so the more specific setting is what applies.' );

		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	public function test_the_filter_can_override_the_constant() {
		define( 'WP_EMAIL_TRUST_PROXY', true );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

		add_filter( 'wp_email_trust_proxy', '__return_false' );

		// The filter is the last word, so a site can keep the constant in
		// wp-config.php and still refuse the header for particular requests.
		$this->assertSame( '198.51.100.200', WP_Email_Form::ip_address(), 'And the filter can override the constant, so a site can opt back out.' );

		remove_filter( 'wp_email_trust_proxy', '__return_false' );
	}
}
