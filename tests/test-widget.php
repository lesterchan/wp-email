<?php
/**
 * The most-emailed widget.
 *
 * @package WP-EMail
 */

/**
 * The most-emailed widget.
 *
 * @covers WP_Email_Widget
 */
class Test_Email_Widget extends WP_UnitTestCase {

	/**
	 * Sidebar arguments.
	 *
	 * @return array
	 */
	private function args() {
		return array(
			'before_widget' => '<aside>',
			'after_widget'  => '</aside>',
			'before_title'  => '<h2>',
			'after_title'   => '</h2>',
		);
	}

	/**
	 * Render the widget.
	 *
	 * @param array $instance Widget settings.
	 *
	 * @return string
	 */
	private function render( array $instance ) {
		$widget = new WP_Email_Widget();

		ob_start();
		$widget->widget( $this->args(), $instance );
		return ob_get_clean();
	}

	/**
	 * The widget is registered under its new name.
	 */
	public function test_the_widget_is_registered_under_its_new_name() {
		$this->assertTrue( class_exists( 'WP_Email_Widget' ) );

		$this->assertNotFalse(
			has_action( 'widgets_init', array( WP_Email::get_instance(), 'register_widget' ) )
		);

		// Invoked directly: widgets_init has already fired by the time a test
		// runs, and the test case resets the widget factory between tests.
		WP_Email::get_instance()->register_widget();

		// Keyed by class name for a string registration and by object hash for
		// an instance, so look for the instance rather than guessing the key.
		$registered = false;

		foreach ( $GLOBALS['wp_widget_factory']->widgets as $widget ) {
			if ( $widget instanceof WP_Email_Widget ) {
				$registered = true;
				$this->assertSame( 'email', $widget->id_base );
			}
		}

		$this->assertTrue( $registered, 'WP_Email_Widget was not registered' );
	}

	/**
	 * The widget supports selective refresh.
	 */
	public function test_the_widget_supports_selective_refresh() {
		$widget = new WP_Email_Widget();

		$this->assertTrue( $widget->widget_options['customize_selective_refresh'] );
	}

	/**
	 * The widget lists the most emailed post.
	 */
	public function test_the_widget_lists_the_most_emailed_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Harness Post',
				'post_date'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Alice',
				'youremail'   => 'a@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => time(),
				'ip'          => '198.51.100.1',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);

		$html = $this->render(
			array(
				'title' => 'Most Emailed',
				'type'  => 'most_emailed',
				'mode'  => 'both',
				'limit' => 5,
				'chars' => 0,
			)
		);

		$this->assertStringContainsString( '<aside>', $html );
		$this->assertStringContainsString( '<h2>Most Emailed</h2>', $html );
		$this->assertStringContainsString( 'Harness Post', $html );
	}

	/**
	 * An empty title renders no heading markup.
	 */
	public function test_an_empty_title_renders_no_heading_markup() {
		$html = $this->render( array( 'title' => '' ) );

		$this->assertStringNotContainsString( '<h2>', $html );
	}

	/**
	 * The widget title is escaped.
	 */
	public function test_the_widget_title_is_escaped() {
		$html = $this->render( array( 'title' => '<script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	/**
	 * Update sanitizes its input.
	 */
	public function test_update_sanitizes_its_input() {
		$widget = new WP_Email_Widget();

		$instance = $widget->update(
			array(
				'title' => '  <b>Titled</b>  ',
				'mode'  => 'nonsense',
				'limit' => '-4',
				'chars' => 'abc',
			),
			array()
		);

		$this->assertSame( 'Titled', $instance['title'] );
		$this->assertSame( 'both', $instance['mode'] );
		$this->assertSame( 4, $instance['limit'] );
		$this->assertSame( 0, $instance['chars'] );
	}

	/**
	 * The old update() returned false unless a hidden field was posted.
	 */
	public function test_update_saves_without_the_legacy_submit_marker() {
		$widget = new WP_Email_Widget();

		// The old update() returned false unless a hidden 'submit' field was
		// posted, which the block widget editor never sends.
		$instance = $widget->update( array( 'title' => 'Saved' ), array() );

		$this->assertIsArray( $instance );
		$this->assertSame( 'Saved', $instance['title'] );
	}

	/**
	 * The form renders its controls.
	 */
	public function test_the_form_renders_its_controls() {
		$widget = new WP_Email_Widget();
		$widget->_set( 1 );

		ob_start();
		$widget->form( array() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Title:', $html );
		$this->assertStringContainsString( 'Include Views From:', $html );
		$this->assertStringContainsString( 'No. Of Records To Show:', $html );
	}
}
