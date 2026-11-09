<?php

use DrPlus\Utils;
use MJ\Whitebox\Utils\Files;

defined( 'ABSPATH' ) || exit;

$max_upload_size_bytes = Files::get_max_upload_size();

Redux::set_section( // Specialists settings
	$opt_name,
	array(
		'title'			=> esc_html__( 'Specialists settings', 'drplus' ),
		'id'			=> 'specialists-general-section',
		'subsection'	=> true,
		'fields'		=> array(
			[ // single_specialist_mode
				'id'		=> 'single_specialist_mode',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Single-Specialist mode', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Disable', 'drplus' ) ),
				'on'		=> esc_html__( 'Enable', 'drplus' ),
				'off'		=> esc_html__( 'Disable', 'drplus' ),
				'desc'		=> esc_html__( 'By enabling Single-Specialist mode, all features and sections related to Multi-Expert functionality will be disabled.', 'drplus' ) . '<br>' . sprintf( __( 'After Change this option, go to <a href="%s" target="_blank">permalink options</a> and save the settings.', 'drplus' ), admin_url( "options-permalink.php" ) ),
				'default'	=> false,
			],
			[ // single_specialist_id
				'id'			=> 'single_specialist_id',
				'type'			=> 'select',
				'title'			=> esc_html__( 'Specialist', 'drplus' ),
				'subtitle'		=> esc_html__( 'Select the specialist', 'drplus' ),
				'placeholder'	=> esc_html__( 'Search and select', 'drplus' ),
				'data'		=> 'posts',
				'args'     => [
					'post_type' => ['specialist'],
					'post_status' => 'publish',
				],
				'ajax'     => true,
				'required'	=> [
					['single_specialist_mode','=',true]
				]
			],
			[ // specialist_identity_max_upload_size
				'id'		=> 'specialist_identity_max_upload_size',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Max upload size for identity files (MB)", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), Files::convert_bytes_to_mb( $max_upload_size_bytes ) ),
				'min'		=> 1,
				'max'		=> Files::convert_bytes_to_mb( $max_upload_size_bytes, false ),
				'default'	=> Files::convert_bytes_to_mb( $max_upload_size_bytes, false ),
			],
		),
	)
);

Redux::set_section( // Specialists single settings
	$opt_name,
	array(
		'title'			=> esc_html__( 'Single specialist settings', 'drplus' ),
		'id'			=> 'specialists-single-section',
		'subsection'	=> true,
		'fields'		=> array(
			[ // single_specialist_show_breadcrumb
				'id'		=> 'single_specialist_show_breadcrumb',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show breadcrumb', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_reviews
				'id'		=> 'single_specialist_show_reviews',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show reviews', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_reviews_stars
				'id'		=> 'single_specialist_show_reviews_stars',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show reviews stars', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_specialist_code
				'id'		=> 'single_specialist_show_specialist_code',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show specialist code', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_reserve_btn
				'id'		=> 'single_specialist_show_reserve_btn',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show reserve button', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_services
				'id'		=> 'single_specialist_show_services',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show services', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_insurances
				'id'		=> 'single_specialist_show_insurances',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show insurances', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
				'required'	=> [
					['insurance','=',true]
				]
			],
			[ // single_specialist_show_offices
				'id'		=> 'single_specialist_show_offices',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show offices', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_patients_review_stat
				'id'		=> 'single_specialist_show_patients_review_stat',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show patient satisfaction stat', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_online_consultation_stat
				'id'		=> 'single_specialist_show_online_consultation_stat',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show online consultation stat', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_visits_count_stat
				'id'		=> 'single_specialist_show_visits_count_stat',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show visits count stat', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_articles_stat
				'id'		=> 'single_specialist_show_articles_stat',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show number of articles stat', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_introduction
				'id'		=> 'single_specialist_show_introduction',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show introduction', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[
				'id'		=> 'single_specialist_limit_introduction_height',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Limit introduction height and show "show more" button', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'No', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> false,
				'required'	=> [
					['single_specialist_show_introduction', '=', true]
				],
			],
			[
				'id'		=> 'single_specialist_introduction_limited_height',
				'type'		=> 'dimensions',
				'title'		=> esc_html__( 'Introduction limited height', 'drplus' ),
				'subtitle'	=> sprintf( __( "Default: %s", 'drplus' ), '400px' ),
				'mode'		=> [
					'width'		=> false,
					'height'	=> true
				],
				'width'		=> false,
				'units'    => array('em','px','%', 'vh'),
				'units_extended'	=> true,
				'default'		=> [
					'height'	=> '500'
				],
				'required'	=> [
					['single_specialist_limit_introduction_height', '=', true],
					['single_specialist_show_introduction', '=', true]
				]
			],
			[ // single_specialist_show_certificates
				'id'		=> 'single_specialist_show_certificates',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show certificates', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_certificate_image
				'id'		=> 'single_specialist_show_certificate_image',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show certificate image by click on it', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'	=> [
					['single_specialist_show_certificates','=',true]
				]
			],
			[ // single_specialist_show_certificates_verified
				'id'		=> 'single_specialist_show_certificates_verified',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show certificates verified text', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
				'required'	=> [
					['single_specialist_show_certificates','=',true]
				]
			],
			[ // single_specialist_certificates_verified_text
				'id'			=> 'single_specialist_certificates_verified_text',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Verified certificates text', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), sprintf( esc_html__( "All of {name}'s credentials have been verified by %s", 'drplus' ), get_bloginfo( 'name' ) ) ),
				'default'		=> sprintf( esc_html__( "All of {name}'s credentials have been verified by %s", 'drplus' ), get_bloginfo( 'name' ) ),
				'placeholder'	=> sprintf( esc_html__( "All of {name}'s credentials have been verified by %s", 'drplus' ), get_bloginfo( 'name' ) ),
			],
			[ // single_specialist_show_faqs
				'id'		=> 'single_specialist_show_faqs',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show FAQs', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // single_specialist_show_related_specialists
				'id'		=> 'single_specialist_show_related_specialists',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show related specialists', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // specialist-code-label
				'id'			=> 'specialist-code-label',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Specialist code label', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), esc_html__( 'Medical system number', 'drplus' ) ),
				'default'		=> esc_html__( 'Medical system number', 'drplus' ),
				'placeholder'	=> esc_html__( 'Medical system number', 'drplus' ),
			],
			[ // single_specialist_not_available_reserve_text
				'id'			=> 'single_specialist_not_available_reserve_text',
				'type'			=> 'text',
				'title'			=> esc_html__( 'notice text when reserve is not available', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), esc_html__( 'Online appointment booking is not yet available for {name}', 'drplus' ) ),
				'desc'			=> esc_html__( 'You can use \'{name}\' for show specialist name', 'drplus' ),
				'default'		=> esc_html__( 'Online appointment booking is not yet available for {name}', 'drplus' ),
				'placeholder'	=> esc_html__( 'Online appointment booking is not yet available for {name}', 'drplus' ),
			],
			[ // single_specialist_related_specialists_verified_text
				'id'			=> 'archive_specialist_verified_text',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Verified text', 'drplus' ),
				'desc'			=> esc_html__( 'If you leave it empty, the verified badge will not be shown.', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), sprintf( esc_html__( 'Verified by %s', 'drplus' ), get_bloginfo( 'name' ) ) ),
				'default'		=> sprintf( esc_html__( 'Verified by %s', 'drplus' ), get_bloginfo( 'name' ) ),
				'placeholder'	=> sprintf( esc_html__( 'Verified by %s', 'drplus' ), get_bloginfo( 'name' ) ),
			],
		),
	)
);

Redux::set_section( // Specialists archive settings
	$opt_name,
	array(
		'title'			=> esc_html__( 'Specialists archive settings', 'drplus' ),
		'id'			=> 'specialists-archive-section',
		'subsection'	=> true,
		'fields'		=> array(
			[ // archive_specialist_breadcrumb
				'id'		=> 'archive_specialist_breadcrumb',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show breadcrumb', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // archive_specialist_show_title
				'id'		=> 'archive_specialist_show_title',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show archive title', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // archive_specialist_title_icon
				'id'			=> 'archive_specialist_title_icon',
				'type'			=> 'icon_select',
				'title'			=> esc_html__( 'Archive title icon', 'drplus' ),
				'compiler'		=> true,
				'default'		=> 'drplus-icon-stethoscope',
				'enqueue_frontend'	=> false,
				'stylesheet'	=> [
					[
						'title'	=> __( 'Doctor plus icons', 'drplus' ),
						'icons'	=> Utils::get_local_icons(),
					],
				],
				'required'		=> [
					['archive_specialist_show_title','=',true]
				]
			],
			[ // archive_specialist_show_sidebar
				'id'		=> 'archive_specialist_show_sidebar',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show archive sidebar', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
			],
			[ // archive_specialist_sidebar
				'id'		=> 'archive_specialist_sidebar',
				'type'		=> 'select',
				'title'		=> esc_html__( 'Archive sidebar', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Specialist archive sidebar', 'drplus' ) ),
				'data'		=> 'sidebars',
				'default'	=> 'archive_specialist',
				'required'	=> [
					['archive_specialist_show_sidebar','=',true],
				]
			],
			[ // archive_specialist_desktop_cols
				'id'		=> 'archive_specialist_desktop_cols',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Desktop columns", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), '4' ),
				'min'		=> 1,
				'max'		=> 6,
				'default'	=> 4,
			],
			[ // archive_specialist_desktop_gap
				'id'		=> 'archive_specialist_desktop_gap',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Desktop gap (px)", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), '24' ),
				'min'		=> 1,
				'max'		=> 64,
				'default'	=> 16,
			],
			[ // archive_specialist_tablet_cols
				'id'		=> 'archive_specialist_tablet_cols',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Tablet columns", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), '2' ),
				'min'		=> 1,
				'max'		=> 6,
				'default'	=> 2,
			],
			[ // archive_specialist_tablet_gap
				'id'		=> 'archive_specialist_tablet_gap',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Tablet gap (px)", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), '24' ),
				'min'		=> 1,
				'max'		=> 64,
				'default'	=> 16,
			],
			[ // archive_specialist_mobile_cols
				'id'		=> 'archive_specialist_mobile_cols',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Mobile columns", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), '1' ),
				'min'		=> 1,
				'max'		=> 6,
				'default'	=> 1,
			],
			[ // archive_specialist_mobile_gap
				'id'		=> 'archive_specialist_mobile_gap',
				'type'		=> 'spinner',
				'title'		=> esc_html__( "Mobile gap (px)", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), '24' ),
				'min'		=> 1,
				'max'		=> 64,
				'default'	=> 16,
			],
			[ // archive_specialist_card_type
				'id'		=> 'archive_specialist_card_type',
				'type'		=> 'image_select',
				'title'		=> esc_html__( "Specialists display type", 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( "Card 2", 'drplus' ) ),
				'options'	=> [
					'card-1'	=> [
						'alt'	=> esc_html__( "Card 1", 'drplus' ),
						'img'	=> DRPLUS_URI . "assets/images/backend/specialist-card-1.jpg",
						'title'	=> esc_html__( "Card 1", 'drplus' ),
					],
					'card-2'	=> [
						'alt'	=> esc_html__( "Card 2", 'drplus' ),
						'img'	=> DRPLUS_URI . "assets/images/backend/specialist-card-2.jpg",
						'title'	=> esc_html__( "Card 2", 'drplus' ),
					],
					'list'	=> [
						'alt'	=> esc_html__( "List", 'drplus' ),
						'img'	=> DRPLUS_URI . "assets/images/backend/specialist-list.jpg",
						'title'	=> esc_html__( "List", 'drplus' ),
					],
				],
				'default'	=> 'card-2',
			],
			[ // archive_specialist_verified_text
				'id'			=> 'archive_specialist_verified_text',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Verified text', 'drplus' ),
				'desc'			=> esc_html__( 'If you leave it empty, the verified badge will not be shown.', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), sprintf( esc_html__( 'Verified by %s', 'drplus' ), get_bloginfo( 'name' ) ) ),
				'default'		=> sprintf( esc_html__( 'Verified by %s', 'drplus' ), get_bloginfo( 'name' ) ),
				'placeholder'	=> sprintf( esc_html__( 'Verified by %s', 'drplus' ), get_bloginfo( 'name' ) ),
			],
		),
	)
);