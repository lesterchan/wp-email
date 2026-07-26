<?php
/**
 * WP-EMail class-email-wpstats.php
 *
 * @package WP-EMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contributes WP-EMail's numbers to the WP-Stats page.
 *
 * Only loaded when WP-Stats is active.
 *
 * @since 3.0.0
 */
class Email_WpStats {

	/**
	 * Hook into WP-Stats.
	 */
	public function __construct() {
		add_filter( 'wp_stats_page_admin_plugins', array( $this, 'admin_general' ) );
		add_filter( 'wp_stats_page_admin_most', array( $this, 'admin_most' ) );
		add_filter( 'wp_stats_page_plugins', array( $this, 'general' ) );
		add_filter( 'wp_stats_page_most', array( $this, 'most' ) );
	}

	/**
	 * Whether a WP-Stats display toggle is on.
	 *
	 * @param string $key Toggle name.
	 *
	 * @return bool
	 */
	private function is_displayed( $key ) {
		$stats_display = get_option( 'stats_display' );

		if ( ! is_array( $stats_display ) ) {
			return false;
		}

		return isset( $stats_display[ $key ] ) && 1 === (int) $stats_display[ $key ];
	}

	/**
	 * How many "most" entries WP-Stats is configured to show.
	 *
	 * @return int
	 */
	private function most_limit() {
		return (int) get_option( 'stats_mostlimit' );
	}

	/**
	 * A WP-Stats options checkbox.
	 *
	 * @param string $value Checkbox value.
	 * @param string $label Checkbox label.
	 *
	 * @return string
	 */
	private function checkbox( $value, $label ) {
		return sprintf(
			'<input type="checkbox" name="stats_display[]" id="wpstats_%1$s" value="%1$s"%2$s />&nbsp;&nbsp;<label for="wpstats_%1$s">%3$s</label><br />' . "\n",
			esc_attr( $value ),
			checked( $this->is_displayed( $value ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * The general-stats toggle on the WP-Stats options screen.
	 *
	 * @param string $content Accumulated markup.
	 *
	 * @return string
	 */
	public function admin_general( $content ) {
		return $content . $this->checkbox( 'email', __( 'WP-EMail', 'wp-email' ) );
	}

	/**
	 * The most-emailed toggles on the WP-Stats options screen.
	 *
	 * @param string $content Accumulated markup.
	 *
	 * @return string
	 */
	public function admin_most( $content ) {
		$limit = $this->most_limit();

		$content .= $this->checkbox(
			'emailed_most_post',
			sprintf(
				/* translators: %s: Number of posts. */
				_n( '%s Most Emailed Post', '%s Most Emailed Posts', $limit, 'wp-email' ),
				number_format_i18n( $limit )
			)
		);

		$content .= $this->checkbox(
			'emailed_most_page',
			sprintf(
				/* translators: %s: Number of pages. */
				_n( '%s Most Emailed Page', '%s Most Emailed Pages', $limit, 'wp-email' ),
				number_format_i18n( $limit )
			)
		);

		return $content;
	}

	/**
	 * The general-stats block on the WP-Stats page.
	 *
	 * @param string $content Accumulated markup.
	 *
	 * @return string
	 */
	public function general( $content ) {
		if ( ! $this->is_displayed( 'email' ) ) {
			return $content;
		}

		$success = Email_Logs::count_by_status( Email_Logs::STATUS_SUCCESS );
		$failed  = Email_Logs::count_by_status( Email_Logs::STATUS_FAILED );
		$total   = Email_Logs::count_all();

		$content .= '<p><strong>' . esc_html__( 'WP-EMail', 'wp-email' ) . '</strong></p>' . "\n";
		$content .= '<ul>' . "\n";

		$content .= '<li>' . sprintf(
			/* translators: %s: Number of e-mails. */
			wp_kses_post( _n( '<strong>%s</strong> email was sent.', '<strong>%s</strong> emails were sent.', $total, 'wp-email' ) ),
			esc_html( number_format_i18n( $total ) )
		) . '</li>' . "\n";

		$content .= '<li>' . sprintf(
			/* translators: %s: Number of e-mails. */
			wp_kses_post( _n( '<strong>%s</strong> email was sent successfully.', '<strong>%s</strong> emails were sent successfully.', $success, 'wp-email' ) ),
			esc_html( number_format_i18n( $success ) )
		) . '</li>' . "\n";

		$content .= '<li>' . sprintf(
			/* translators: %s: Number of e-mails. */
			wp_kses_post( _n( '<strong>%s</strong> email failed to send.', '<strong>%s</strong> emails failed to send.', $failed, 'wp-email' ) ),
			esc_html( number_format_i18n( $failed ) )
		) . '</li>' . "\n";

		$content .= '</ul>' . "\n";

		return $content;
	}

	/**
	 * The most-emailed blocks on the WP-Stats page.
	 *
	 * @param string $content Accumulated markup.
	 *
	 * @return string
	 */
	public function most( $content ) {
		$limit = $this->most_limit();

		if ( $this->is_displayed( 'emailed_most_post' ) ) {
			$content .= '<p><strong>' . sprintf(
				/* translators: %s: Number of posts. */
				esc_html( _n( '%s Most Emailed Post', '%s Most Emailed Posts', $limit, 'wp-email' ) ),
				esc_html( number_format_i18n( $limit ) )
			) . '</strong></p>' . "\n";
			$content .= '<ul>' . "\n" . get_mostemailed( 'post', $limit, 0, false ) . '</ul>' . "\n";
		}

		if ( $this->is_displayed( 'emailed_most_page' ) ) {
			$content .= '<p><strong>' . sprintf(
				/* translators: %s: Number of pages. */
				esc_html( _n( '%s Most Emailed Page', '%s Most Emailed Pages', $limit, 'wp-email' ) ),
				esc_html( number_format_i18n( $limit ) )
			) . '</strong></p>' . "\n";
			$content .= '<ul>' . "\n" . get_mostemailed( 'page', $limit, 0, false ) . '</ul>' . "\n";
		}

		return $content;
	}
}
