<?php
/**
 * The link settings collapsing into one template.
 *
 * @package WP-EMail
 */

/**
 * Every shape a stored install can be in when the link settings collapse.
 *
 * Four settings became one. The style picker chose between an icon, some text,
 * both, or markup the site wrote; post_text and page_text fed the %EMAIL_TEXT%
 * token that went with the first three. All three are gone and the markup is
 * all that is left, so the migration has to reproduce what each site was
 * already showing rather than hand everybody the default.
 *
 * The legacy shapes below are transcribed rather than built from
 * WP_Email_Options::defaults(): the whole point is to meet the row a real
 * install carries, and a fixture assembled from the current code would only
 * assert that the code agrees with itself.
 *
 * @covers WP_Email_Options
 */
class WP_Email_Migration_Link_Test extends WP_Email_TestCase {

	/**
	 * The link group exactly as the pre-collapse build stored it.
	 *
	 * @var array
	 */
	const LEGACY_LINK = array(
		'post_text' => 'Email This Post',
		'page_text' => 'Email This Page',
		'type'      => 1,
		'style'     => 1,
		'html'      => '<a href="%EMAIL_URL%" rel="nofollow" title="%EMAIL_TEXT%">%EMAIL_TEXT%</a>',
	);

	/**
	 * Write a stored install carrying the old link group, one schema behind.
	 *
	 * The db marker is '1' rather than absent, which is what tells maybe_upgrade() this is
	 * an install that has already been through the 2.x migration and needs only
	 * this one.
	 *
	 * @param array $link Overrides for the legacy link group.
	 *
	 * @return void
	 */
	private function seed_stored_install( array $link = array() ) {
		$options         = WP_Email_Options::defaults();
		$options['link'] = array_merge( self::LEGACY_LINK, $link );

		update_option( WP_Email_Options::OPTION, $options );
		update_option(
			WP_Email_Options::VERSION,
			array(
				'plugin' => WP_EMAIL_VERSION,
				'db'     => '1',
			)
		);

		WP_Email_Options::flush();
	}

	/**
	 * Write a 2.x install, whose settings are still in the flat email_options row.
	 *
	 * @param array $stored Overrides for the flat row.
	 *
	 * @return void
	 */
	private function seed_legacy_install( array $stored = array() ) {
		delete_option( WP_Email_Options::VERSION );
		delete_option( WP_Email_Options::OPTION );

		update_option(
			'email_options',
			array_merge(
				array(
					'post_text'   => 'Email This Post',
					'page_text'   => 'Email This Page',
					'email_type'  => 1,
					'email_style' => 1,
					'email_html'  => '<a href="%EMAIL_URL%">%EMAIL_TEXT%</a>',
				),
				$stored
			)
		);

		WP_Email_Options::flush();
	}

	/**
	 * The stored template after an upgrade.
	 *
	 * @return string
	 */
	private function migrated_html() {
		WP_Email_Options::flush();

		return (string) WP_Email_Options::get( 'link', 'html' );
	}


	public function test_the_stock_pair_of_texts_collapses_onto_the_post_type_token() {
		$this->seed_stored_install();

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		// One template cannot say "Post" on a post and "Page" on a page, so the
		// token is what expresses both -- and it only appears where the site had
		// not replaced the wording.
		$this->assertStringContainsString( '%POST_TYPE%', $html, 'The stock pair of texts collapses onto the post type token.' );
		$this->assertStringContainsString( 'Email This %POST_TYPE%', $html, 'Reading as the wording they shared.' );
		$this->assertStringNotContainsString( '%EMAIL_TEXT%', $html, 'And the old text token is gone.' );
	}

	public function test_an_icon_only_site_stays_icon_only() {
		$this->seed_stored_install( array( 'style' => 2 ) );

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertStringContainsString( '%EMAIL_ICON%', $html, 'An icon only site keeps its icon.' );
		$this->assertStringNotContainsString( '>Email This %POST_TYPE%<', $html, 'With no text beside it.' );
		// The wording survives as the link's accessible name, which is where the
		// icon-only style put it.
		$this->assertStringContainsString( 'title="Email This %POST_TYPE%"', $html, 'The text becomes the title instead.' );
	}

	public function test_a_text_only_site_gets_no_icon() {
		$this->seed_stored_install( array( 'style' => 3 ) );

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertStringNotContainsString( '%EMAIL_ICON%', $html, 'A text only site gets no icon.' );
		$this->assertStringContainsString( '>Email This %POST_TYPE%</a>', $html, 'Only the text it had.' );
	}

	public function test_an_icon_with_text_site_keeps_both() {
		$this->seed_stored_install( array( 'style' => 1 ) );

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertStringContainsString( '%EMAIL_ICON%', $html, 'An icon with text site keeps the icon.' );
		$this->assertStringContainsString( 'Email This %POST_TYPE%</a>', $html, 'And the text.' );
	}

	public function test_a_site_already_writing_its_own_markup_is_left_alone() {
		$mine = '<a class="mine" href="%EMAIL_URL%">%EMAIL_ICON%</a>';

		$this->seed_stored_install(
			array(
				'style' => 4,
				'html'  => $mine,
			)
		);

		WP_Email_Options::maybe_upgrade();

		$this->assertSame( $mine, $this->migrated_html(), 'A site already writing its own markup is left exactly as it was.' );
	}

	/**
	 * The one exception to leaving custom markup alone.
	 *
	 * %EMAIL_ICON_URL% named the URL of a bundled GIF and lived inside an
	 * <img src="...">. There is no URL to give it now, so leaving it draws a
	 * broken image and substituting the glyph into the attribute would produce
	 * <img src="<svg ...>">. The whole element is replaced instead.
	 */
	public function test_a_custom_template_has_its_icon_image_replaced_by_the_glyph() {
		$this->seed_stored_install(
			array(
				'style' => 4,
				'html'  => '<a href="%EMAIL_URL%"><img src="%EMAIL_ICON_URL%" alt="Email" /> %EMAIL_TEXT%</a>',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertSame(
			'<a href="%EMAIL_URL%">%EMAIL_ICON% %EMAIL_TEXT%</a>',
			$html,
			'An icon URL placeholder inside an image tag is replaced element and all by the glyph placeholder.'
		);
		$this->assertStringNotContainsString( '%EMAIL_ICON_URL%', $html, 'Leaving no retired icon placeholder behind.' );
		$this->assertStringContainsString( '%EMAIL_TEXT%', $html, 'While the retired text placeholder is deliberately left to be seen and edited.' );
	}

	/**
	 * A bare one outside an image tag is simply renamed: the glyph drops
	 * straight into the same position the URL occupied.
	 */
	public function test_a_custom_template_has_a_bare_icon_placeholder_renamed() {
		$this->seed_stored_install(
			array(
				'style' => 4,
				'html'  => '<a href="%EMAIL_URL%">%EMAIL_ICON_URL%</a>',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$this->assertSame(
			'<a href="%EMAIL_URL%">%EMAIL_ICON%</a>',
			$this->migrated_html(),
			'And a bare icon URL placeholder is renamed rather than dropped.'
		);
	}

	/**
	 * Two arbitrary strings do not fit in one template. The post wording is the
	 * one that is kept, because it is what the great majority of a site's links
	 * are; the page wording is lost, and the Upgrade Notice says so.
	 */
	public function test_customised_texts_that_differ_keep_the_post_wording_verbatim() {
		$this->seed_stored_install(
			array(
				'post_text' => 'Send this article on',
				'page_text' => 'Send this page on',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertStringContainsString( 'Send this article on', $html, 'Texts that differ keep the post wording verbatim.' );
		$this->assertStringNotContainsString( '%POST_TYPE%', $html, 'Rather than being collapsed onto a token that would change one of them.' );
		$this->assertStringNotContainsString( 'Send this page on', $html, 'And the page wording is not what survives.' );
	}

	public function test_customised_texts_that_agree_are_carried_across_once() {
		$this->seed_stored_install(
			array(
				'post_text' => 'Mail this on',
				'page_text' => 'Mail this on',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertSame( 1, substr_count( $html, 'Mail this on</a>' ), 'Texts that agree are carried across once, not twice.' );
		$this->assertStringNotContainsString( '%POST_TYPE%', $html, 'And with no token, because there is only one wording.' );
	}

	public function test_a_customised_post_text_survives_a_page_text_left_at_stock() {
		$this->seed_stored_install( array( 'post_text' => 'Mail this on' ) );

		WP_Email_Options::maybe_upgrade();

		$this->assertStringContainsString( 'Mail this on', $this->migrated_html(), 'A customised post text survives a page text left at stock.' );
	}

	public function test_markup_in_a_link_text_is_escaped_into_the_template() {
		$this->seed_stored_install(
			array(
				'post_text' => 'Say "hi" <b>now</b>',
				'page_text' => 'Something else',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		// The old renderer ran the text through esc_html() at the sink. The
		// template is echoed as written, so the escaping moves into it.
		$this->assertStringNotContainsString( '<b>now</b>', $html, 'Markup in a link text does not become markup in the template.' );
		$this->assertStringContainsString( '&lt;b&gt;now&lt;/b&gt;', $html, 'It is escaped into it.' );
		$this->assertStringNotContainsString( 'title="Say "hi"', $html, 'And a quote cannot close the title attribute early.' );
	}


	public function test_the_three_retired_keys_are_gone_from_the_stored_row() {
		$this->seed_stored_install();

		WP_Email_Options::maybe_upgrade();

		$stored = get_option( WP_Email_Options::OPTION );

		foreach ( WP_Email_Options::RETIRED_LINK_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $stored['link'], "{$key} should have been removed" );
		}

		$this->assertSame( array( 'type', 'html' ), array_keys( $stored['link'] ), 'The three retired keys are gone, leaving these two.' );
	}

	public function test_the_settings_the_migration_is_not_about_are_untouched() {
		$this->seed_stored_install( array( 'type' => 2 ) );

		$before = get_option( WP_Email_Options::OPTION );

		WP_Email_Options::maybe_upgrade();

		$after = get_option( WP_Email_Options::OPTION );

		$this->assertSame( 2, $after['link']['type'], 'A setting the migration is not about is left alone.' );

		foreach ( array( 'fields', 'sending', 'templates', 'stats_display', 'stats_most_limit' ) as $group ) {
			$this->assertSame( $before[ $group ], $after[ $group ], "{$group} is not what this migration touches." );
		}
	}


	public function test_a_2x_install_synthesises_its_template_from_the_flat_row() {
		$this->seed_legacy_install(
			array(
				'email_style' => 2,
				'post_text'   => 'Mail this on',
				'page_text'   => 'Mail this page on',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$html = $this->migrated_html();

		$this->assertStringContainsString( '%EMAIL_ICON%', $html, 'A 2.x install has its template built from the flat row.' );
		$this->assertStringContainsString( 'title="Mail this on"', $html, 'Carrying the text it had.' );
		$this->assertStringNotContainsString( '%EMAIL_TEXT%', $html, 'And no leftover text token.' );
		$this->assertArrayNotHasKey( 'style', get_option( WP_Email_Options::OPTION )['link'], 'The retired link style key is not carried into the new row.' );
	}

	public function test_a_2x_install_on_the_custom_style_keeps_its_markup() {
		$this->seed_legacy_install(
			array(
				'email_style' => 4,
				'email_html'  => '<a class="from-2x" href="%EMAIL_URL%">%EMAIL_TEXT%</a>',
			)
		);

		WP_Email_Options::maybe_upgrade();

		// Untouched, %EMAIL_TEXT% and all: an unrecognised token is left in the
		// markup rather than blanked, so the site sees that it needs editing
		// instead of finding an empty link.
		$this->assertSame(
			'<a class="from-2x" href="%EMAIL_URL%">%EMAIL_TEXT%</a>',
			$this->migrated_html(),
			'A 2.x install on the custom style keeps its own markup.'
		);
	}


	public function test_the_migration_runs_from_the_activation_hook() {
		$this->seed_stored_install( array( 'style' => 3 ) );

		WP_Email::get_instance()->activate();

		$this->assertStringNotContainsString( '%EMAIL_ICON%', $this->migrated_html(), 'The activation hook runs the migration.' );
		$this->assertSame( WP_EMAIL_DB_VERSION, WP_Email_Options::markers()['db'], 'And stamps the schema version, so it does not run again.' );
	}

	/**
	 * Updating through the plugins screen never fires the activation hook, so
	 * admin_init -> maybe_upgrade() is the path that carries every site that did
	 * not deactivate first.
	 */
	public function test_the_migration_runs_from_the_admin_init_upgrade_path() {
		$this->seed_stored_install( array( 'style' => 3 ) );

		WP_Email::get_instance()->maybe_upgrade();

		$this->assertStringNotContainsString( '%EMAIL_ICON%', $this->migrated_html(), 'The admin upgrade path runs it too.' );
		$this->assertSame( WP_EMAIL_DB_VERSION, WP_Email_Options::markers()['db'], 'And stamps the schema version.' );
	}

	public function test_the_migration_is_idempotent() {
		$this->seed_stored_install(
			array(
				'style'     => 2,
				'post_text' => 'Mail this on',
				'page_text' => 'Mail this page on',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$once = $this->migrated_html();

		// Sent round again the way a site owner "fixes" things -- deactivate,
		// reactivate -- and then with the marker wound back, which is the only
		// way the step itself can be made to run a second time.
		WP_Email::get_instance()->activate();

		update_option(
			WP_Email_Options::VERSION,
			array(
				'plugin' => WP_EMAIL_VERSION,
				'db'     => '1',
			)
		);

		WP_Email_Options::maybe_upgrade();

		$this->assertSame( $once, $this->migrated_html(), 'Running the migration twice leaves the same template.' );
	}

	public function test_a_fresh_install_is_left_on_the_shipped_template() {
		delete_option( WP_Email_Options::OPTION );
		delete_option( WP_Email_Options::VERSION );
		WP_Email_Options::flush();

		WP_Email::get_instance()->activate();

		$this->assertSame( WP_Email_Options::default_link_html(), $this->migrated_html(), 'A fresh install is left on the shipped template rather than migrated onto a copy of it.' );
	}


	public function test_a_migrated_template_still_renders_a_link() {
		$this->seed_stored_install(
			array(
				'style'     => 2,
				'post_text' => 'Mail this on',
				'page_text' => 'Mail this page on',
			)
		);

		WP_Email_Options::maybe_upgrade();
		WP_Email_Options::flush();

		$post_id = self::factory()->post->create();

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$link = WP_Email_Link::render();

		$this->assertStringContainsString( '<svg class="wp-email-icon"', $link, 'A migrated template still renders the icon.' );
		$this->assertStringContainsString( 'title="Mail this on"', $link, 'And the title it carried across.' );
		$this->assertStringNotContainsString( '%', $link, 'With no token left unexpanded.' );
	}
}
