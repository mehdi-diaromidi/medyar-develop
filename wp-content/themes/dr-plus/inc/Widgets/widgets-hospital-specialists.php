<?php
namespace DrPlus\Widgets;

use DrPlus\Backend\WidgetsPage;
use DrPlus\Components\SimpleIcon;
use DrPlus\Utils;
use DrPlus\Utils\AdminUI;
use DrPlus\Utils\User;
use DrPlus\Utils\UtilsSpecialists;
use DrPlus\Utils\Widgets;

if( !defined( 'ABSPATH' ) ) exit;

class HospitalSpecialists extends \WP_Widget {
	private $defaults = [
		'title'					=> '',
		'icon'					=> 'drplus-icon-users-fill',
		'orderby'				=> 'user_registered',
		'order'					=> 'DESC',
		'subtitle'				=> true,
		'only_verified'			=> false,
		'only_offline_visits'	=> false,
		'only_online_visits'	=> false,
		'number'				=> 5,
	];

	public function __construct() {
		$this->defaults['title'] = sprintf( esc_html__( "{name} specialists", 'drplus' ) );
		parent::__construct(
			'drplus_hospital_specialists', // Base ID
			esc_html__( 'Doctor Plus - Hospital specialists', 'drplus' ), // Name
			[
				'description'	=> __( 'List of specialists of the hospital', 'drplus' )
			]
		);
	}

	public function form( $instance ) {
		$values = Utils::check_default( $instance, $this->defaults );

		// Widget admin form
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php esc_html_e( 'Title', 'drplus' ); ?>:</label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $values['title'] ); ?>" >
		</p>

		<p>
			<?php esc_html_e( 'You can use these variables in the title:', 'drplus' ); ?>
			<?php Utils::variables_html( WidgetsPage::hospital_variables(), true );
			?>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'icon' ); ?>"><?php esc_html_e( 'Icon', 'drplus' ); ?>:</label>
			<?php
			AdminUI::icon_picker( [
				'name'		=> $this->get_field_name( 'icon' ),
				'id'		=> $this->get_field_id( 'icon' ),
				'icon'		=> $values['icon'],
				'modal_id'	=> "drplus-icon-picker-modal"
			] );
			?>
		</p>

		<p>
			<label><?php esc_html_e( 'Order By', 'drplus' ) ?></label>
			<select name="<?php echo $this->get_field_name( 'orderby' ) ?>" class="widefat">
				<?php foreach( User::order_by() as $key => $label ) { ?>
					<option value="<?php echo esc_attr( $key ) ?>" <?php selected( $key, $values['orderby'] ) ?>><?php echo esc_html( $label ) ?></option>
				<?php } ?>
			</select>
		</p>

		<p>
			<label><?php esc_html_e( 'Order', 'drplus' ) ?></label>
			<select name="<?php echo $this->get_field_name( 'order' ) ?>" class="widefat">
				<option value="ASC" <?php selected( 'ASC', $values['order'] ) ?>><?php echo esc_html__( 'ASC', 'drplus' ) ?></option>
				<option value="DESC" <?php selected( 'DESC', $values['order'] ) ?>><?php echo esc_html__( 'DESC', 'drplus' ) ?></option>
			</select>
		</p>

		<p>
			<input class="checkbox" id="<?php echo $this->get_field_id( 'subtitle' ); ?>" name="<?php echo $this->get_field_name( 'subtitle' ) ?>" type="checkbox" value="1" <?php checked( true, $values['subtitle'] ) ?>>
			<label for="<?php echo $this->get_field_id( 'subtitle' ); ?>"><?php esc_html_e( 'Show specialist subtitle', 'drplus' ); ?></label>
		</p>

		<p>
			<input class="checkbox" id="<?php echo $this->get_field_id( 'only_verified' ); ?>" name="<?php echo $this->get_field_name( 'only_verified' ) ?>" type="checkbox" value="1" <?php checked( true, $values['only_verified'] ) ?>>
			<label for="<?php echo $this->get_field_id( 'only_verified' ); ?>"><?php esc_html_e( 'Show only verified specialist', 'drplus' ); ?></label>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>"><?php esc_html_e( 'Count', 'drplus' ); ?>:</label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="number" min="1" value="<?php echo esc_attr( $values['number'] ); ?>" >
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $this->defaults;

		$instance['title'] = !empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : $instance['title'];
		$instance['icon'] = !empty( $new_instance['icon'] ) ? Utils::convert_chars( $new_instance['icon'] ) : $instance['icon'];
		$instance['orderby'] = !empty( $new_instance['orderby'] ) ? sanitize_text_field( $new_instance['orderby'] ) : $instance['orderby'];
		$instance['order'] = !empty( $new_instance['order'] ) ? $new_instance['order'] : $instance['order'];
		$instance['subtitle'] = !empty( $new_instance['subtitle'] ) ? $new_instance['subtitle'] : $instance['subtitle'];
		$instance['only_verified'] = !empty( $new_instance['only_verified'] ) ? $new_instance['only_verified'] : $instance['only_verified'];
		$instance['number'] = !empty( $new_instance['number'] ) ? $new_instance['number'] : $instance['number'];

		return $instance;
	}

	public function widget( $args, $instance ) {
		$instance = Utils::check_default( $instance, $this->defaults );

		// before and after widget arguments are defined by themes
		$specialists = UtilsSpecialists::get_specialists_by_user_query( [
			'number'		=> $instance['number'],
			'orderby'		=> $instance['orderby'],
			'order'			=> $instance['order'],
			'only_verified'	=> Utils::to_bool( $instance['only_verified'] ),
			'hospital'		=> get_the_ID(),
		] )['specialists'];

		if( empty( $specialists ) ) return;

		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );
		$title = Widgets::apply_hospital_variables( $title );
		$title = apply_filters( 'widget_title', $title );

		$args['before_title'] .= SimpleIcon::view( [
			'icon'	=> $instance['icon']
		], false );

		$wrap_classes = ['specialists', "specialists-style-list"];
		if( Utils::to_bool( $instance['only_verified'] ) ) {
			$wrap_classes[] = 'verified-specialists';
		}
		$wrap_attrs = [
			'class'	=> $wrap_classes,
		];
		
		// before and after widget arguments are defined by themes
		echo $args['before_widget'];
		if( !empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}
		echo '<div ' . Utils::get_html_attributes( $wrap_attrs ) . '>';
			UtilsSpecialists::list_html( [
				'specialists'	=> $specialists,
				'settings'		=> [
					'style'			=> 'list',
					'only_verified'	=> $instance['only_verified'],
					'style_args'	=> [
						'avatar_size'	=> 80,
					]
				],
				'remove_wrap'	=> true,
			] );
		echo '</div>';
		echo $args['after_widget'];
	}
}