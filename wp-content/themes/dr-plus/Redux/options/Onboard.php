<?php

$separator = is_rtl() ? ' &rsaquo; ' : ' &lsaquo; ';

Redux::set_section( // General
	$opt_name,
	array(
		'title'			=> esc_html__( 'General', 'drplus' ),
		'id'			=> 'onboard-general-section',
		'subsection'	=> true,
		'fields'		=> array(
			[ // specialist_onboard
				'id'		=> 'specialist_onboard',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Onboarding', 'drplus' ),
				'desc'		=> esc_html__( 'Allow users to submit requests for specialist profiles', 'drplus' ) . "<br>" . sprintf( __( 'After enabling this option, go to <a href="%s" target="_blank">permalink options</a> and save the settings.', 'drplus' ), admin_url( "options-permalink.php" ) ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Enabled', 'drplus' ) ),
				'on'		=> esc_html__( 'Enabled', 'drplus' ),
				'off'		=> esc_html__( 'Disabled', 'drplus' ),
				'default'	=> true,
				'required'	=> [
					['single_specialist_mode', '!=', true],
				]
			],
			[ // onboard_page_title
				'id'			=> 'onboard_page_title',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Page title', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), get_bloginfo( 'name', 'display' ) . $separator . __( "Onboarding", "drplus" ) ),
				'default'		=> get_bloginfo( 'name', 'display' ) . $separator . __( "Onboarding", "drplus" ),
				'placeholder'	=> get_bloginfo( 'name', 'display' ) . $separator . __( "Onboarding", "drplus" ),
				'required'		=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
				],
			],
			[ // onboard_title
				'id'			=> 'onboard_title',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Form title', 'drplus' ),
				'subtitle'		=> sprintf( __( "Default: %s", 'drplus' ), __( "Onboarding", "drplus" ) ),
				'default'		=> __( "Onboarding", "drplus" ),
				'placeholder'	=> __( "Onboarding", "drplus" ),
				'required'		=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
				],
			],
			[ // onboard_show_bg_pattern
				'id'		=> 'onboard_show_bg_pattern',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show background pattern', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Show', 'drplus' ) ),
				'on'		=> esc_html__( 'Show', 'drplus' ),
				'off'		=> esc_html__( 'Hide', 'drplus' ),
				'default'	=> true,
				'required'	=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
				],
			],
			[ // onboard_show_logo
				'id'		=> 'onboard_show_logo',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show logo', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Show', 'drplus' ) ),
				'on'		=> esc_html__( 'Show', 'drplus' ),
				'off'		=> esc_html__( 'Hide', 'drplus' ),
				'default'	=> true,
				'required'	=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
				],
			],
			[ // onboard_logo_type
				'id'		=> 'onboard_logo_type',
				'type'		=> 'radio',
				'title'		=> esc_html__( 'Logo type', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Image', 'drplus' ) ),
				'data'		=> [
					'text'	=> esc_html__( 'Text', 'drplus' ),
					'img'	=> esc_html__( 'Image', 'drplus' ),
				],
				'default'	=> 'img',
				'required'	=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_logo','=',true],
				]
			],
			[ // onboard_logo_text_type
				'id'		=> 'onboard_logo_text_type',
				'type'		=> 'radio',
				'title'		=> esc_html__( 'Text type', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Site title', 'drplus' ) ),
				'data'		=> [
					'title'		=> esc_html__( 'Site title', 'drplus' ),
					'custom'	=> esc_html__( 'Custom', 'drplus' ),
				],
				'default'	=> 'title',
				'required'	=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_logo','=',true],
					['onboard_logo_type','=','text'],
				],
			],
			[ // onboard_logo_text_custom
				'id'		=> 'onboard_logo_text_custom',
				'type'		=> 'text',
				'title'		=> esc_html__( 'Logo text', 'drplus' ),
				'required'	=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_logo','=',true],
					['onboard_logo_type','=','text'],
					['onboard_logo_text_type','=','custom'],
				],
			],
			[ // onboard_logo_img
				'id'				=> 'onboard_logo_img',
				'type'		 		=> 'media',
				'title'				=> esc_html__( 'Logo image file', 'drplus' ),
				'compiler'	 		=> true,
				'url'				=> true,
				'preview_size'		=> 'full',
				'library_filter'	=> ['jpeg', 'gif', 'png', 'bmp', 'tiff', 'x-icon', 'svg', 'svg+xml', 'webp'],
				'default'			=> [
					'url'	=> DRPLUS_URI . "assets/images/logo.svg",
				],
				'required'			=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_logo','=',true],
					['onboard_logo_type','=','img'],
				],
			],
			[ // onboard_logo_img_size
				'id'		=> 'onboard_logo_img_size',
				'type'		=> 'dimensions',
				'title'		=> esc_html__( 'Logo size', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), 'W: 160 & H: 60' ),
				'desc'		=> esc_html__( 'Leave empty for full size', 'drplus' ),
				'default'	=> [
					'width'		=> 91,
					'height'	=> 51
				],
				'required'	=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_logo','=',true],
					['onboard_logo_type','=','img'],
				],
			],
			[ // onboard_logo_link
				'id'			=> 'onboard_logo_link',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Logo URL', 'drplus' ),
				'subtitle'		=> sprintf( esc_html__( 'Default: %s', 'drplus' ), home_url() ),
				'default'		=> home_url(),
				'placeholder'	=> home_url(),
				'required'		=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_logo','=',true],
				],
			],
			[ // onboard_show_back
				'id'		=> 'onboard_show_back',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Show back link', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'desc'		=> esc_html__( 'Show back link in footer of the form', 'drplus' ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
				],
			],
			[ // onboard_back_label
				'id'			=> 'onboard_back_label',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Back link text', 'drplus' ),
				'subtitle'		=> sprintf( esc_html__( 'Default: %s', 'drplus' ), __( "Return to home page", 'drplus' ) ),
				'default'		=> __( "Return to home page", 'drplus' ),
				'placeholder'	=> __( "Return to home page", 'drplus' ),
				'required'		=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_back','=',true],
				],
			],
			[ // onboard_back_url
				'id'			=> 'onboard_back_url',
				'type'			=> 'text',
				'title'			=> esc_html__( 'Back link', 'drplus' ),
				'subtitle'		=> sprintf( esc_html__( 'Default: %s', 'drplus' ), home_url() ),
				'default'		=> home_url(),
				'placeholder'	=> home_url(),
				'required'		=> [
					['specialist_onboard','=',true],
					['single_specialist_mode', '!=', true],
					['onboard_show_back','=',true],
				],
			],
		)
	)
);

Redux::set_section( // Fields
	$opt_name,
	array(
		'title'			=> esc_html__( 'Fields', 'drplus' ),
		'id'			=> 'onboard-fields-section',
		'subsection'	=> true,
		'fields'		=> array(
			[ // onboard-info-field-name-enabled
				'id'		=> 'onboard-info-field-name-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable name field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-name-required
				'id'		=> 'onboard-info-field-name-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is name field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-name-enabled','=',true]
				]
			],
			[ // onboard-info-field-subtitle-enabled
				'id'		=> 'onboard-info-field-subtitle-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable subtitle field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-subtitle-required
				'id'		=> 'onboard-info-field-subtitle-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is subtitle field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-subtitle-enabled','=',true]
				]
			],
			[ // onboard-info-field-email-enabled
				'id'		=> 'onboard-info-field-email-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable email field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-email-required
				'id'		=> 'onboard-info-field-email-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is email field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-email-enabled','=',true]
				]
			],
			[ // onboard-info-field-birthday-enabled
				'id'		=> 'onboard-info-field-birthday-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable birthday field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-birthday-required
				'id'		=> 'onboard-info-field-birthday-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is birthday field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-birthday-enabled','=',true]
				]
			],
			[ // onboard-info-field-nid-enabled
				'id'		=> 'onboard-info-field-nid-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable national ID field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-nid-required
				'id'		=> 'onboard-info-field-nid-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is national ID field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-nid-enabled','=',true]
				]
			],
			[ // onboard-info-field-specialist-code-enabled
				'id'		=> 'onboard-info-field-specialist-code-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable medical ID field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-specialist-code-required
				'id'		=> 'onboard-info-field-specialist-code-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is medical ID field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-specialist-code-enabled','=',true]
				]
			],
			[ // onboard-info-field-phone-enabled
				'id'		=> 'onboard-info-field-phone-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable phone field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-phone-required
				'id'		=> 'onboard-info-field-phone-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is phone field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-phone-enabled','=',true]
				]
			],
			[ // onboard-info-field-gender-enabled
				'id'		=> 'onboard-info-field-gender-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable gender field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-gender-required
				'id'		=> 'onboard-info-field-gender-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is gender field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-gender-enabled','=',true]
				]
			],
			[ // onboard-info-field-bio-enabled
				'id'		=> 'onboard-info-field-bio-enabled',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Enable biography field', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'Yes', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> true,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
			[ // onboard-info-field-bio-required
				'id'		=> 'onboard-info-field-bio-required',
				'type'		=> 'switch',
				'title'		=> esc_html__( 'Is biography field Required?', 'drplus' ),
				'subtitle'	=> sprintf( esc_html__( 'Default: %s', 'drplus' ), esc_html__( 'No', 'drplus' ) ),
				'on'		=> esc_html__( 'Yes', 'drplus' ),
				'off'		=> esc_html__( 'No', 'drplus' ),
				'default'	=> false,
				'required'		=> [
					['specialist_onboard','=',true],
					['onboard-info-field-bio-enabled','=',true]
				]
			],
		)
	)
);

Redux::set_section( // Specialities
	$opt_name,
	array(
		'title'			=> esc_html__( 'Specialities', 'drplus' ),
		'id'			=> 'onboard-specialities-section',
		'subsection'	=> true,
		'fields'		=> array(
			[ // onboard-specialities-count
				'id'			=> 'onboard-specialities-count',
				'type'			=> 'spinner',
				'title'			=> esc_html__( 'Specialities count', 'drplus' ),
				'subtitle'		=> sprintf( esc_html__( 'Default: %s', 'drplus' ), 9 ),
				'description'	 => __( 'To show all specialities, enter <code>-1</code>', 'drplus' ),
				'min'			=> -1,
				'max'			=> 999,
				'default'		=> 9,
				'required'		=> [
					['specialist_onboard','=',true],
				]
			],
		)
	)
);