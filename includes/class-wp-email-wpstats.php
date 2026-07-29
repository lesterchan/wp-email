<?php
/**
 * WP-EMail class-wp-email-wpstats.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contributes WP-EMail's numbers to the WP-Stats page.
 *
 * One filter, one entry, and nothing else. Before 3.0.0 this hooked five
 * WP-Stats filters, appended a string to each, and read WP-Stats' own
 * stats_display row to find out whether it was allowed to -- so WP-Stats
 * decided whether a plugin it knew nothing about got to draw a panel, and the
 * two shared a wp_options row that neither of them owned.
 *
 * WP-EMail now answers wp_stats_sections with one entry keyed 'wp_email',
 * decides for itself out of its own settings whether to answer at all, and lets
 * WP-Stats echo the heading and call the render callback. A theme takes this
 * block over by hooking wp_stats_section_wp_email earlier and removing
 * WP-Stats' own listener.
 *
 * Loaded unconditionally: with WP-Stats absent nothing ever fires the filter,
 * so the class is inert and there is nothing to probe for.
 *
 * @since 3.0.0
 */
class WP_Email_WPStats {

	/**
	 * Hook into WP-Stats.
	 */
	public function __construct() {
		add_filter( 'wp_stats_sections', array( $this, 'register_section' ) );
	}

	/**
	 * Contribute WP-EMail's section to the statistics page.
	 *
	 * Reads only this plugin's own settings row, per STANDARDS.md 13. A site
	 * that has switched the block off gets $sections back untouched rather than
	 * an entry with an empty body.
	 *
	 * @param array $sections Sections keyed by plugin slug with underscores.
	 *
	 * @return array
	 */
	public function register_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		if ( ! WP_Email_Options::stats_display() ) {
			return $sections;
		}

		$sections['wp_email'] = array(
			'title'    => __( 'E-Mails', 'wp-email' ),
			'priority' => 10,
			'render'   => array( $this, 'render' ),
		);

		return $sections;
	}

	/**
	 * Echo the section body.
	 *
	 * Echoes rather than returns: WP-Stats assembles its page inside
	 * ob_start(), so a returned string is silently dropped. The heading above
	 * this is WP-Stats' to print, not WP-EMail's.
	 *
	 * @return void
	 */
	public function render() {
		$limit = WP_Email_Options::stats_most_limit();

		$this->render_totals();
		$this->render_most_emailed( 'post', $limit );
		$this->render_most_emailed( 'page', $limit );
	}

	/**
	 * The three running totals.
	 *
	 * @return void
	 */
	protected function render_totals() {
		$lines = array(
			array(
				WP_Email_Logs::count_all(),
				/* translators: %s: Number of e-mails. */
				_n_noop( '<strong>%s</strong> email was sent.', '<strong>%s</strong> emails were sent.', 'wp-email' ),
			),
			array(
				WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS ),
				/* translators: %s: Number of e-mails. */
				_n_noop( '<strong>%s</strong> email was sent successfully.', '<strong>%s</strong> emails were sent successfully.', 'wp-email' ),
			),
			array(
				WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_FAILED ),
				/* translators: %s: Number of e-mails. */
				_n_noop( '<strong>%s</strong> email failed to send.', '<strong>%s</strong> emails failed to send.', 'wp-email' ),
			),
		);

		echo '<ul>' . "\n";

		foreach ( $lines as $line ) {
			list( $count, $strings ) = $line;

			// The strings carry their own <strong>, so wp_kses_post() rather
			// than esc_html(), which would print the tags as text.
			echo '<li>' . wp_kses_post(
				sprintf(
					translate_nooped_plural( $strings, $count, 'wp-email' ),
					number_format_i18n( $count )
				)
			) . '</li>' . "\n";
		}

		echo '</ul>' . "\n";
	}

	/**
	 * One "most emailed" list.
	 *
	 * @param string $mode  'post' or 'page'.
	 * @param int    $limit Maximum entries.
	 *
	 * @return void
	 */
	protected function render_most_emailed( $mode, $limit ) {
		$heading = 'page' === $mode
			/* translators: %s: Number of pages. */
			? _n( '%s Most Emailed Page', '%s Most Emailed Pages', $limit, 'wp-email' )
			/* translators: %s: Number of posts. */
			: _n( '%s Most Emailed Post', '%s Most Emailed Posts', $limit, 'wp-email' );

		echo '<p><strong>' . esc_html( sprintf( $heading, number_format_i18n( $limit ) ) ) . '</strong></p>' . "\n";

		echo '<ul>' . "\n" . get_mostemailed( $mode, $limit, 0, false ) . '</ul>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_mostemailed() escapes every value it prints.
	}
}
