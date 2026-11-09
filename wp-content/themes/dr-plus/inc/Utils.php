<?php
namespace DrPlus;

use DrPlus\Utils\Options;
use MJ\Whitebox\Utils as WhiteboxUtils;

class Utils extends WhiteboxUtils {
	protected static string $project_dir = DRPLUS_DIR; // Used in get_icon_packs
	protected static string $project_uri = DRPLUS_URI; // Used in get_icon_packs
	protected static string $project_slug = 'drplus'; // Used in get_icon_packs
	protected static bool $DEV_MODE = DRPLUS_DEV;
	protected static string $version = DRPLUS_VERSION;
	protected static string $icons_dir = DRPLUS_DIR . "assets/icons";
	protected static string $icons_prefix = 'drplus-icon-';

	// Local cache variables
	private static $posts_avg_scores = [];

	public static function get_post_id( $post = null ) {
		if( $post === 0 ) {
			return 0;
		}
		
		if( is_a( $post, 'WP_Post' ) ) {
			return $post->ID;
		}

		if( is_numeric( $post ) && absint( $post ) !== 0 ) {
			return absint( $post );
		}

		if( absint( $post ) === 0 && !is_singular() ) return 0;

		return get_the_ID();
	}

	public static function get_post_options( $defaults, $post_id = 0, array $options = [] ) {
		$post_id = self::get_post_id( $post_id );

		if( !$options ) {
			$options = $defaults;
		} else {
			foreach( $options as $index => $option ) {
				if( isset( $defaults[$option] ) ) {
					$options[$option] = $defaults[$option];
				}
				unset( $options[$index] );
			}
		}
		foreach( $options as $key => $default ) {
			$value = get_post_meta( $post_id, "_{$key}", true );
			if( is_bool( $default ) ) {
				if( $value === '' ) {
					$value = $default;
				}
				$value = self::to_bool( $value );
			}
			if( $value === '' ) {
				if( !metadata_exists( 'post', $post_id, $key ) ) {
					$value = $defaults[$key];
				}
			}
			$options[$key] = $value;
		}
		return self::check_default( $options, $defaults );
	}

	public static function save_post_options( array $options, $defaults, $post_id = 0 ) {
		$post_id = self::get_post_id( $post_id );

		foreach( $defaults as $option_key => $value ) {
			if( isset( $options[$option_key] ) ) {
				$value = $options[$option_key];
				if( is_scalar( $value ) ) {
					if( is_bool( $value ) ) {
						$value = self::to_bool( $value );
						if( $value === false ) {
							$value = "false";
						}
					} else {
						$value = self::convert_chars( $value );
					}
				}
				update_post_meta( $post_id, "_{$option_key}", $value );
			}
		}
	}

	public static function button_types() {
		return [
			'primary'	=> esc_html_x( 'Primary', 'Button type', 'drplus' ),
			'secondary'	=> esc_html_x( 'Secondary', 'Button type', 'drplus' ),
			'gray'		=> esc_html_x( 'Gray', 'Button type', 'drplus' ),
			'white'		=> esc_html_x( 'White', 'Button type', 'drplus' ),
			'action'	=> esc_html_x( 'Action', 'Button type', 'drplus' ),
			'bordered'	=> esc_html_x( 'Bordered', 'Button type', 'drplus' ),
		];
	}

	public static function button_styles() {
		return [
			'normal'	=> esc_html_x( 'Normal', 'Button style', 'drplus' ),
			'rounded'	=> esc_html_x( 'Rounded', 'Button style', 'drplus' ),
			'circle'	=> esc_html_x( 'Circle', 'Button style', 'drplus' ),
		];
	}

	public static function custom_tags() {
		return [
			'h1'	=> __( "H1", 'drplus' ),
			'h2'	=> __( "H2", 'drplus' ),
			'h3'	=> __( "H3", 'drplus' ),
			'h4'	=> __( "H4", 'drplus' ),
			'h5'	=> __( "H5", 'drplus' ),
			'h6'	=> __( "H6", 'drplus' ),
			'div'	=> __( "div", 'drplus' ),
			'p'		=> __( "p", 'drplus' ),
			'span'	=> __( "span", 'drplus' )
		];
	}

	public static function fonts() {
		return apply_filters( 'drplus/fonts', [
			'IRANYekanX'		=> [
				'fa'	=> __( 'IRANYekanX', 'drplus' ),
				'en'	=> 'IRANYekanX'
			],
			'IRANYekanXFANum'	=> [
				'fa'	=> __( 'IRANYekanXFANum', 'drplus' ),
				'en'	=> 'IRANYekanXFANum'
			],
			'Anjoman'		=> [
				'fa'	=> __( 'Anjoman', 'drplus' ),
				'en'	=> 'Anjoman'
			],
			'AnjomanFANum'	=> [
				'fa'	=> __( 'AnjomanFANum', 'drplus' ),
				'en'	=> 'AnjomanFANum'
			],
			'AbiFANum'			=> [
				'fa'	=> __( 'AbiFANum', 'drplus' ),
				'en'	=> 'AbiFANum'
			],
			'Abi'				=> [
				'fa'	=> __( 'Abi', 'drplus' ),
				'en'	=> 'Abi'
			],
			'Rokh'				=> [
				'fa'	=> __( 'Rokh', 'drplus' ),
				'en'	=> 'Rokh'
			],
			'RokhFANum'			=> [
				'fa'	=> __( 'RokhFANum', 'drplus' ),
				'en'	=> 'RokhFANum'
			],
			'Vazirmatn'			=> [
				'fa'	=> __( 'Vazirmatn', 'drplus' ),
				'en'	=> 'Vazirmatn'
			],
			'VazirmatnFANum'	=> [
				'fa'	=> __( 'VazirmatnFANum', 'drplus' ),
				'en'	=> 'VazirmatnFANum'
			],
			'DanaFANum'	=> [
				'fa'	=> __( 'DanaFANum', 'drplus' ),
				'en'	=> 'DanaFANum'
			],
		] );
	}

	public static function default_active_fonts() {
		return apply_filters( 'drplus/fonts/default_actives', ['IRANYekanXFANum', 'AnjomanFANum'] );
	}

	public static function get_font_stylesheet( string $font_name ) {
		return apply_filters( "drplus/fonts/{$font_name}/stylesheet", DRPLUS_URI . "assets/css/fonts/{$font_name}.min.css" );
	}

	public static function get_active_fonts() {
		$fonts = array_keys( self::fonts() );
		$default_options = [];
		foreach( $fonts as $font ) {
			$default_options["font_{$font}"] = in_array( $font, self::default_active_fonts() );
		}
		$options = array_filter( Options::get_options( $default_options ) );
		
		$active_fonts = [];
		foreach( $fonts as $font ) {
			if( !empty( $options["font_{$font}"] ) ) {
				$active_fonts[] = $font;
			}
		}

		return apply_filters( 'drplus/fonts/active', $active_fonts );
	}

	public static function get_user_id( $user_id = 0 ) {
		if( !empty( $user_id ) ) {
			if( is_numeric( $user_id ) ) {
				return $user_id;
			}
			if( is_object( $user_id ) ) {
				if( !empty( $user_id->ID ) ) {
					return $user_id->ID;
				} else if( !empty( $user_id->id ) ) {
					return $user_id->id;
				}
			}
			if( is_array( $user_id ) ) {
				if( !empty( $user_id['ID'] ) ) {
					return $user_id['ID'];
				} else if( !empty( $user_id['id'] ) ) {
					return $user_id['id'];
				}
			}
			$user = self::get_user_object( $user_id );
			if( !empty( $user ) ) {
				return $user->ID;
			}
		}

		return get_current_user_id();
	}

	public static function get_user_object( $user = null ) {
		if( !empty( $user ) ) {
			if( is_numeric( $user ) ) {
				$user = get_user_by( 'id', $user );
			} else if( is_array( $user ) ) {
				$user = get_user_by( 'id', $user['ID'] );
			} else if( is_string( $user ) && is_email( $user ) ) {
				$user = get_user_by( 'email', $user );
			}
		} else {
			if( is_user_logged_in() ) {
				$user = wp_get_current_user();
			}
		}
		return $user;
	}

	public static function get_post( $post = null ) {
		if( is_object( $post ) || is_numeric( $post ) ) {
			return get_post( $post );
		} else if( is_array( $post ) ) {
			if( !empty( $post['ID'] ) ) {
				return get_post( $post['ID'] );
			} else if( !empty( $post['id'] ) ) {
				return get_post( $post['id'] );
			}
		}
		global $post;
		return $post;
	}

	/**
	 * Get HTML of the variables can be used in the string options
	 *
	 * @param array $variables. Key for variable and value for description
	 * @param bool $row
	 * @return void HTML
	 */
	public static function variables_html( array $variables, bool $row = false ) {
		?>
		<div class="drplus_variables">
			<?php foreach( $variables as $variable => $description ) { ?>
				<div class="drplus_variable<?php echo $row ? ' drplus_variable-row' : '' ?>" data-variable="<?php echo esc_attr( $variable ) ?>">
					<code class="drplus_variable-value">{<?php echo esc_html( $variable ) ?>}</code><span class="drplus_variable-description"><?php echo esc_html( $description ) ?></span>
				</div>
			<?php } ?>
		</div>
		<?php
	}

	public static function calc_product_discount_percentage( $regular_price, $sale_price ) : int {
		$percentage = absint( (100 - round( $sale_price / $regular_price * 100 )) );
		if( $percentage === 100 && !empty( $sale_price ) ) {
			$percentage = 99;
		}
		if( $percentage < 1 ) {
			$percentage = 1;
		}
		return $percentage;
	}

	public static function drplus_vars_localize() {
		$options = Options::get_options( [
			'booking-datepicker'			=> 'persian'
		] );
		$vars = [
			'ajaxUrl'					=> admin_url( 'admin-ajax.php' ),
			'restApiBaseUrl'			=> esc_url_raw( rest_url( 'drplus_chat/v1/' ) ),
			'isRtl'						=> is_rtl(),
			'isIranTimezone'			=> Utils::is_iran_timezone(),
			'BookingPersianDatePicker'	=> $options['booking-datepicker'] === 'persian',
			'GeneralPersianDatePicker'	=> true,
			'i18n'						=> [
				'today'		=> __( "Today", 'drplus' ),
				'submit'	=> _x( "Submit", 'Date picker', 'drplus' ),
				'dropzone'	=> [
					'dictDefaultMessage'	=> __( "Drop files here or click to upload", 'drplus' ),
				],
				'wrongIDCode'	=> __( 'Please enter a valid National ID', 'drplus' ),
				'wrongMobile'	=> __( 'Please enter a valid mobile', 'drplus' ),
			],
			'nonces'	=> [
				'dropzone'	=> wp_create_nonce( 'drplus-dropzone_upload_nonce' ),
			],
			'defaults'	=> [
				'avatar'	=> DRPLUS_URI . "assets/images/user.svg"
			],
		];

		wp_localize_script( 'drplus', 'drplusVars', $vars );
	}

	/**
	 * Get average score from post
	 *
	 * @param integer $post_id
	 * @param integer|boolean $decimals
	 * @param integer $comments_count You can pass the comments count. cache
	 * @return float
	 */
	public static function get_post_avg( $post_id = 0, $decimals = 1, int $comments_count = 0 ) : float {
		$post_id = self::get_post_id( $post_id );
		if( !$post_id ) return 0;

		$local_cache_key = "{$post_id}-{$decimals}";
		if( empty( self::$posts_avg_scores[$local_cache_key] ) ) {
			$total_scores = (float) get_post_meta( $post_id, '_drplus_total_scores', true );
			if( $comments_count === 0 ) {
				$comments_count = get_comment_count( $post_id )['approved'];
			}

			if( empty( $comments_count ) ) {
				return 0;
			}
			$result = $total_scores / $comments_count;
			if( $decimals !== false ) {
				$result = round( $result, $decimals );
			}
			self::$posts_avg_scores[$local_cache_key] = $result;
		}

		return self::$posts_avg_scores[$local_cache_key];
	}

	/**
	 * Merge two associative arrays with support for one-level deep array merging.
	 *
	 * If a key exists in both arrays and both values are arrays,
	 * their values will be merged using array_merge.
	 * Otherwise, the value from the second array will overwrite the first.
	 *
	 * This function is useful for merging configuration-like arrays
	 * where certain keys (e.g. "section") contain nested data.
	 *
	 * @param array $base   Base array (primary structure)
	 * @param array $extra  Extra array whose values will be merged into base
	 *
	 * @return array        The merged result array
	 */
	public static function merge_sectioned_array( array $base, array $extra ) : array {
		foreach ( $extra as $key => $value ) {
			if ( isset($base[$key]) && is_array($base[$key]) && is_array($value) ) {
				$base[$key] = array_merge($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}

	public static function normalize_map_src( string $input ): string {
		$input = trim( $input );

		if ( stripos( $input, '<iframe' ) !== false ) {
			return self::extract_iframe_src_dom( $input );
		}

		if ( filter_var( $input, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $input );
		}

		return '';
	}

	public static function extract_iframe_src_dom( string $input ): string {
		if ( stripos( $input, '<iframe' ) === false ) {
			return '';
		}

		libxml_use_internal_errors( true );

		$dom = new \DOMDocument();
		$dom->loadHTML( $input, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$iframes = $dom->getElementsByTagName( 'iframe' );

		if ( $iframes->length === 0 ) {
			return '';
		}

		$src = $iframes->item( 0 )->getAttribute( 'src' );

		return $src ? esc_url_raw( $src ) : '';
	}
}