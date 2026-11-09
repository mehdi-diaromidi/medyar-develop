<?php
namespace DrPlus\Utils;

use DrPlus\Utils;
use MJ\Whitebox\Utils\Elementor as WhiteboxElementor;

class Elementor extends WhiteboxElementor {
	public static function get_button_args( array $settings, string $prefix = 'button_' ) {
		$icon = '';
		if( isset( $settings['button_icon'] ) && is_array( $settings['button_icon'] ) ) {
			$icon = $settings['button_icon']['value'];
			if( is_array( $icon ) && !empty( $icon['url'] ) ) {
				$icon = $icon['url'];
			}
		}

		$args = [];
		if( isset( $settings['button_type'] ) ) {
			$args["{$prefix}type"] = $settings['button_type'];
		}
		if( isset( $settings['button_transparent'] ) ) {
			$args["{$prefix}transparent"] = Utils::to_bool( $settings['button_transparent'] );
		}
		if( isset( $settings['button_small'] ) ) {
			$args["{$prefix}small"] = Utils::to_bool( $settings['button_small'] );
		}
		if( $icon ) {
			$args["{$prefix}icon"] = $icon;
		}
		if( isset( $settings['button_text'] ) ) {
			$args["{$prefix}text"] = $settings['button_text'];
		}
		if( isset( $settings['button_link'] ) ) {
			$args["{$prefix}link"] = $settings['button_link'];
		}
		if( isset( $settings['button_new_tab'] ) ) {
			$args["{$prefix}new_tab"] = Utils::to_bool( $settings['button_new_tab'] );
			if( $args["{$prefix}new_tab"] ) {
				$args["{$prefix}link"]['is_external'] = 'on';
			}
		}
		if( isset( $settings['button_icon_align'] ) ) {
			$args["{$prefix}icon_align"] = $settings['button_icon_align'];
		}
		if( isset( $settings['button_style'] ) ) {
			$args["{$prefix}style"] = $settings['button_style'];
		}
		if( isset( $settings['button_fullwidth'] ) ) {
			$args["{$prefix}fullwidth"] = $settings['button_fullwidth'];
		}
		if( isset( $settings['button_align'] ) ) {
			$args["{$prefix}align"] = $settings['button_align'];
		}
		
		return $args;
	}

	public static function check_button_defaults( array $args, string $prefix = 'button_' ) {
		if( isset( $args["{$prefix}link"] ) && !is_array( $args["{$prefix}link"] ) ) {
			$args["{$prefix}link"] = [
				'url'				=> $args["{$prefix}link"],
				'is_external'		=> !empty( $args["{$prefix}new_tab"] ),
				'nofollow'			=> false,
				'custom_attributes'	=> '',
			];
		}

		$args = Utils::check_default( $args, [
			"{$prefix}transparent"	=> false,
			"{$prefix}type"			=> 'primary',
			"{$prefix}small"		=> false,
			"{$prefix}icon"			=> '',
			"{$prefix}text"			=> '',
			"{$prefix}title"		=> '',
			"{$prefix}link"			=> [],
			"{$prefix}new_tab"		=> false,
			"{$prefix}icon_align"	=> 'start',
			"{$prefix}style"		=> 'rounded',
			"{$prefix}align"		=> 'start',
			"{$prefix}fullwidth"	=> false,
			"{$prefix}classes"		=> [],
			"{$prefix}id"			=> '',
			"{$prefix}disabled"		=> false,
			"{$prefix}loading"		=> false,
			"{$prefix}button_type"	=> '',
			"{$prefix}popup"		=> '',
		], ["{$prefix}icon"] );
		return $args;
	}

	public static function get_elementor_template_type() {
		if ( ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return false;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return false;
		}

		if ( get_post_type($post_id) !== 'elementor_library' ) {
			return false;
		}

		return get_post_meta($post_id, '_elementor_template_type', true);
	}

	public static function is_allowed_context( $allowed ) {
		$template_type = self::get_elementor_template_type();

		if ( in_array( $template_type, $allowed ) ) {
			return true;
		}

		return false;
	}
}