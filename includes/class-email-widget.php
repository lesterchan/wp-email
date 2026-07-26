<?php
/**
 * WP-EMail class-email-widget.php
 *
 * @package WP-EMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The most-emailed widget.
 *
 * Renamed from WP_Widget_Email in 3.0.0. Core reserves the WP_ prefix, and the
 * class was internal.
 *
 * @since 3.0.0
 */
class Email_Widget extends WP_Widget {

	/**
	 * Register the widget.
	 */
	public function __construct() {
		parent::__construct(
			'email',
			__( 'Email', 'wp-email' ),
			array(
				'description'                 => __( 'WP-EMail emails statistics', 'wp-email' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * The widget's defaults.
	 *
	 * @return array
	 */
	private function defaults() {
		return array(
			'title' => __( 'EMail', 'wp-email' ),
			'type'  => 'most_emailed',
			'mode'  => 'both',
			'limit' => 10,
			'chars' => 200,
		);
	}

	/**
	 * Render the widget.
	 *
	 * @param array $args     Sidebar arguments.
	 * @param array $instance Widget settings.
	 *
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );

		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sidebar markup from the theme.

		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sidebar markup from the theme.
		}

		echo '<ul>' . "\n";

		if ( 'most_emailed' === $instance['type'] ) {
			echo get_mostemailed( $instance['mode'], (int) $instance['limit'], (int) $instance['chars'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_mostemailed().
		}

		echo '</ul>' . "\n";

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sidebar markup from the theme.
	}

	/**
	 * Sanitize submitted widget settings.
	 *
	 * @param array $new_instance Submitted settings.
	 * @param array $old_instance Current settings.
	 *
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = wp_parse_args( (array) $old_instance, $this->defaults() );

		$instance['title'] = isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['type']  = 'most_emailed';
		$instance['mode']  = isset( $new_instance['mode'] ) && in_array( $new_instance['mode'], array( 'both', 'post', 'page' ), true )
			? $new_instance['mode']
			: 'both';
		$instance['limit'] = isset( $new_instance['limit'] ) ? max( 1, absint( $new_instance['limit'] ) ) : 10;
		$instance['chars'] = isset( $new_instance['chars'] ) ? absint( $new_instance['chars'] ) : 200;

		return $instance;
	}

	/**
	 * The widget's settings form.
	 *
	 * @param array $instance Current settings.
	 *
	 * @return void
	 */
	public function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );

		$modes = array(
			'both' => __( 'Posts &amp; Pages', 'wp-email' ),
			'post' => __( 'Posts Only', 'wp-email' ),
			'page' => __( 'Pages Only', 'wp-email' ),
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'wp-email' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'mode' ) ); ?>"><?php esc_html_e( 'Include Views From:', 'wp-email' ); ?></label>
			<select name="<?php echo esc_attr( $this->get_field_name( 'mode' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'mode' ) ); ?>" class="widefat">
				<?php foreach ( $modes as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $instance['mode'] ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'No. Of Records To Show:', 'wp-email' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" type="number" min="1" value="<?php echo esc_attr( $instance['limit'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'chars' ) ); ?>"><?php esc_html_e( 'Maximum Post Title Length (Characters):', 'wp-email' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'chars' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'chars' ) ); ?>" type="number" min="0" value="<?php echo esc_attr( $instance['chars'] ); ?>" />
			<br />
			<small><?php esc_html_e( '0 to disable.', 'wp-email' ); ?></small>
		</p>
		<?php
	}
}
