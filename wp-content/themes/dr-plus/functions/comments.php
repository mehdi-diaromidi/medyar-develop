<?php

use DrPlus\Utils;
use DrPlus\Utils\UI;
use DrPlus\Utils\User;
use DrPlus\Utils\UtilsSpecialists;

/**
 * Appointment review categories (patient survey after completed booking).
 *
 * @return array<string,string> key => label
 */
if( !function_exists( 'drplus_review_category_defs' ) ) {
	function drplus_review_category_defs() {
		return [
			'behavior'     => __( 'Doctor behavior & manner', 'drplus' ),
			'quality'      => __( 'Consultation quality & medical explanation', 'drplus' ),
			'punctuality'  => __( 'Punctuality & waiting time', 'drplus' ),
			'booking'      => __( 'Booking & reception experience', 'drplus' ),
		];
	}
}

/**
 * Override Persian labels used on Medyar (theme strings may not be translated yet).
 *
 * @return array<string,string>
 */
if( !function_exists( 'drplus_review_category_labels' ) ) {
	function drplus_review_category_labels() {
		return [
			'behavior'     => 'رفتار و برخورد پزشک',
			'quality'      => 'کیفیت مشاوره و توضیحات پزشکی',
			'punctuality'  => 'نظم و زمان انتظار',
			'booking'      => 'تجربه نوبت‌گیری و پذیرش',
		];
	}
}

if( !function_exists( 'drplus_is_appointment_review_form' ) ) {
	function drplus_is_appointment_review_form() {
		return ! empty( $GLOBALS['drplus_appointment_review_form'] );
	}
}

/**
 * @return array<string,int>
 */
if( !function_exists( 'drplus_get_comment_category_scores' ) ) {
	function drplus_get_comment_category_scores( $comment_id ) {
		$raw = get_comment_meta( $comment_id, '_drplus_star_categories', true );
		if( is_array( $raw ) && $raw ) {
			$out = [];
			foreach( array_keys( drplus_review_category_defs() ) as $key ) {
				$out[ $key ] = max( 0, min( 5, absint( $raw[ $key ] ?? 0 ) ) );
			}
			return $out;
		}
		// Legacy single-meta fallback per category key.
		$out = [];
		$found = false;
		foreach( array_keys( drplus_review_category_defs() ) as $key ) {
			$v = absint( get_comment_meta( $comment_id, '_drplus_star_' . $key, true ) );
			$out[ $key ] = max( 0, min( 5, $v ) );
			if( $v > 0 ) {
				$found = true;
			}
		}
		return $found ? $out : [];
	}
}

if( !function_exists( 'drplus_clamp_star' ) ) {
	function drplus_clamp_star( $value ) {
		$value = absint( $value );
		if( $value < 0 ) {
			$value = 0;
		}
		if( $value > 5 ) {
			$value = 5;
		}
		return $value;
	}
}

if( !function_exists( 'drplus_comment_fields' ) ) {
	function drplus_comment_fields( $fields ) {
		$fields = Utils::unset( $fields, ['url', 'cookies'] );
		Utils::reposition_array_element( $fields, 'comment', 3 );
		return $fields;
	}
}
add_filter( 'comment_form_fields', 'drplus_comment_fields' );

if( !function_exists( "drplus_comment_reply_link_args" ) ) {
	function drplus_comment_reply_link_args( $args ) {
		$args['reply_text'] = '<i class="drplus-icon-redo"></i><span class="reply-btn-text">' . esc_html__( 'Reply', 'drplus' ) .'</span>';
		return $args;
	}
}
add_filter( 'comment_reply_link_args', 'drplus_comment_reply_link_args' );

if( !function_exists( 'drplus_comment_stars_form' ) ) {
	function drplus_comment_stars_form() {
		static $printed = false;
		if( $printed ) {
			return;
		}
		$printed = true;

		if( drplus_is_appointment_review_form() ) {
			$labels = drplus_review_category_labels();
			?>
			<div class="drplus_comment_stars-wrap drplus_comment_stars-categories">
				<div class="drplus_comment_star-title"><?php esc_html_e( 'Your score:', 'drplus' ) ?></div>
				<div class="drplus_comment_stars-categories-list">
					<?php foreach( $labels as $key => $label ) : ?>
						<div class="drplus_comment_stars-category-row">
							<span class="drplus_comment_stars-category-label"><?php echo esc_html( $label ); ?></span>
							<?php UI::stars( 0, 5, true, 'drplus_star_' . $key ); ?>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="drplus_comment_stars-hint description"><?php echo esc_html( 'میانگین این چهار امتیاز، امتیاز کلی شما به این نوبت خواهد بود.' ); ?></p>
			</div>
			<?php
			return;
		}

		$radio_name = is_singular( 'product' ) ? 'rating' : 'drplus_star';
		?>
		<div class="drplus_comment_stars-wrap">
			<div class="drplus_comment_star-title"><?php esc_html_e( 'Your score:', 'drplus' ) ?></div>
			<?php UI::stars( 0, 5, true, $radio_name ) ?>
		</div>
		<?php
	}
}
// Inside the <form> (logged-in: after identity; guests: after fields).
add_action( 'comment_form_logged_in_after', 'drplus_comment_stars_form' );
add_action( 'comment_form_after_fields', 'drplus_comment_stars_form' );

if( !function_exists( 'drplus_preprocess_appointment_review_scores' ) ) {
	function drplus_preprocess_appointment_review_scores( $commentdata ) {
		$post_id = absint( $commentdata['comment_post_ID'] ?? 0 );
		if( get_post_type( $post_id ) !== 'specialist' || empty( $_POST['drplus_comment_order_id'] ) ) {
			return $commentdata;
		}

		foreach( array_keys( drplus_review_category_defs() ) as $key ) {
			$field = 'drplus_star_' . $key;
			$val   = isset( $_POST[ $field ] ) ? drplus_clamp_star( Utils::convert_chars( wp_unslash( (string) $_POST[ $field ] ), true, 'absint' ) ) : 0;
			if( $val < 1 ) {
				wp_die(
					esc_html( 'لطفاً به هر چهار مورد امتیاز ستاره بدهید.' ),
					esc_html__( 'Comment Submission Failure' ),
					[ 'response' => 400, 'back_link' => true ]
				);
			}
		}

		return $commentdata;
	}
}
add_filter( 'preprocess_comment', 'drplus_preprocess_appointment_review_scores' );

if( !function_exists( 'drplus_save_comment_stars' ) ) {
	function drplus_save_comment_stars( $comment_id, $comment_approved, $commentdata ) {
		$post_id = $commentdata['comment_post_ID'];
		// Prevent to save score for not visited users
		if( get_post_type( $post_id ) == 'specialist' && empty( $_POST['drplus_comment_order_id'] ) ) {
			return;
		}

		$star = 0;
		$categories = [];

		if( ! empty( $_POST['drplus_comment_order_id'] ) ) {
			foreach( array_keys( drplus_review_category_defs() ) as $key ) {
				$field = 'drplus_star_' . $key;
				$val   = isset( $_POST[ $field ] ) ? drplus_clamp_star( Utils::convert_chars( wp_unslash( (string) $_POST[ $field ] ), true, 'absint' ) ) : 0;
				$categories[ $key ] = $val;
				update_comment_meta( $comment_id, '_drplus_star_' . $key, $val );
			}
			if( count( array_filter( $categories ) ) === 4 ) {
				$star = round( array_sum( $categories ) / 4, 1 );
				update_comment_meta( $comment_id, '_drplus_star_categories', $categories );
			}
		}

		if( $star <= 0 && ! empty( $_POST['drplus_star'] ) ) {
			$star = drplus_clamp_star( Utils::convert_chars( wp_unslash( (string) $_POST['drplus_star'] ), true, 'absint' ) );
		}

		update_comment_meta( $comment_id, '_drplus_star', $star );

		// Update average and other calcs
		if( $comment_approved ) {
			$total_scores = (float) get_post_meta( $post_id, '_drplus_total_scores', true );
			$total_scores += (float) $star;
			update_post_meta( $post_id, '_drplus_total_scores', $total_scores );

			$is_patient_review = get_comment_meta( $comment_id, '_drplus_patient_review', true );
			if( $is_patient_review ) {
				$comment = get_comment( $comment_id );
				$post_id = (int) $comment->comment_post_ID;
				if( get_post_type( $post_id ) === 'specialist' ) {
					UtilsSpecialists::update_specialist_score( $post_id );
				}
			}
		}
	}
}
add_action( 'comment_post', 'drplus_save_comment_stars', 10, 3 );

if( !function_exists( "drplus_edit_comment" ) ) {
	function drplus_edit_comment( $comment_id, $data ) {
		if( empty( $data['comment_approved'] ) || empty( $data['comment_post_ID'] ) ) return;

		$post_id = $data['comment_post_ID'];
		$is_patient_review = false;
		if( get_post_type( $post_id ) == 'specialist' ) {
			$is_patient_review = get_comment_meta( $comment_id, '_drplus_patient_review', true );
			if( !$is_patient_review ) return;
		}

		$total_scores = (float) get_post_meta( $post_id, '_drplus_total_scores', true );
		$total_scores += (float) get_comment_meta( $comment_id, '_drplus_star', true );
		update_post_meta( $post_id, '_drplus_total_scores', $total_scores );

		if( $is_patient_review ) UtilsSpecialists::update_specialist_score( $post_id );
	}
}
add_action( 'edit_comment', 'drplus_edit_comment', 10, 2 );

if( !function_exists( 'drplus_comment_columns' ) ) {
	function drplus_comment_columns( $columns ) {
		$columns['stars'] = __( 'Stars', 'drplus' );
		return $columns;
	}
}
add_filter( 'manage_edit-comments_columns', 'drplus_comment_columns' );

if( !function_exists( 'drplus_comment_star_column' ) ) {
	function drplus_comment_star_column( $column, $comment_id ) {
		if( $column != 'stars' ) return;

		$stars = get_comment_meta( $comment_id, '_drplus_star', true );
		$stars = Utils::convert_chars( $stars, true, 'absint' );
		$stars = $stars < 0 ? 0 : $stars;
		$stars = $stars > 5 ? 5 : $stars;
		if( $stars === 0 ) {
			echo "-----";
		} else {
			echo '<div style="display:flex;gap:4px">';
			for( $index = 1; $index <= $stars; $index++ ) {
				echo '<div style="width: 20px;height:20px;">' . file_get_contents( DRPLUS_DIR . "assets/icons/star.svg" ) . "</div>";
			}
			echo "</div>";
		}
	}
}
add_action( 'manage_comments_custom_column', 'drplus_comment_star_column', 10, 2 );

if( !function_exists( 'drplus_comment_field_comment' ) ) {
	function drplus_comment_field_comment( $field ) {
		$placeholder = esc_attr__( 'Comment' );
		return str_replace( "<textarea ", "<textarea placeholder=\"{$placeholder}\" ", $field );
	}
}
add_filter( 'comment_form_field_comment', 'drplus_comment_field_comment' );

if( !function_exists( 'drplus_comment_field_author' ) ) {
	function drplus_comment_field_author( $field ) {
		$placeholder = esc_attr__( 'Full Name', 'drplus' );
		$field = str_replace( "<input ", "<input placeholder=\"{$placeholder}\" ", $field );
		$field = str_replace( "</p>", '<i class="drplus-icon-user comment-field-icon"></i></p>', $field );
		return $field;
	}
}
add_filter( 'comment_form_field_author', 'drplus_comment_field_author' );

if( !function_exists( 'drplus_comment_field_email' ) ) {
	function drplus_comment_field_email( $field ) {
		$placeholder = esc_attr__( 'Email' );
		$field = str_replace( "<input ", "<input placeholder=\"{$placeholder}\" ", $field );
		$field = str_replace( "</p>", '<i class="drplus-icon-mail comment-field-icon"></i></p>', $field );
		return $field;
	}
}
add_filter( 'comment_form_field_email', 'drplus_comment_field_email' );

if( !function_exists( 'save_appointment_comment_order_id' ) ) {
	function save_appointment_comment_order_id( $comment_id ) {
		if( isset( $_POST['drplus_comment_order_id'] ) ) {
			$comment = get_comment( $comment_id );
	
			// Ensure the comment has a valid user
			if( $comment && $comment->user_id ) {
				$order_id = Utils::convert_chars( $_POST['drplus_comment_order_id'], true, 'absint' );
				User::update_user_appointments_reviews( $order_id, $comment_id, $comment->user_id );
				update_comment_meta( $comment_id, '_drplus_patient_review', true );
			}
		}
	}
}
add_action( 'comment_post', 'save_appointment_comment_order_id', 9 );

if( !function_exists( 'drplus_comment_walker' ) ) {
	function drplus_comment_walker( $comment, $args, $depth ) {
		if ( 'div' === $args['style'] ) {
			$tag       = 'div';
			$add_below = 'comment';
		} else {
			$tag       = 'li';
			$add_below = 'div-comment';
		} ?>
		<<?php echo $tag; ?> <?php comment_class( empty( $args['has_children'] ) ? '' : 'parent' ); ?> id="comment-<?php comment_ID() ?>">
		<?php if ( 'div' != $args['style'] ) { ?>
			<div id="div-comment-<?php comment_ID() ?>" class="comment-body">
		<?php } ?>
			<div class="comment-header-info comment-author vcard">
				<?php if( $args['avatar_size'] != 0 ) { ?>
					<div class="comment-author-avatar">
						<?php echo get_avatar( $comment, $args['avatar_size'] );  ?>
					</div>
				<?php } ?>
				<div class="comment-author-name">
					<?php printf( __( '<cite class="fn">%s</cite>' ), get_comment_author_link() ); ?>
					<?php if( !empty( get_comment_meta( $comment->comment_ID, '_drplus_patient_review', true ) ) ) { ?>
						<span class="drplus-comment-patient-review">
							<?php esc_html_e( 'Visited', 'drplus' ) ?>
						</span>
						<div class="drplus-comment-patient-score">
							<i class="drplus-icon-star-fill"></i>
							<span><?php echo esc_html( get_comment_meta( $comment->comment_ID, '_drplus_star', true ) ) ?></span>
						</div>
					<?php } ?>
					<?php if ( $comment->comment_approved == '0' ) { ?>
						<em class="comment-awaiting-moderation"><?php _e( 'Your comment is awaiting moderation.' ); ?></em>
					<?php } ?>
				</div>
				<div class="comment-meta">
					<a href="<?php echo esc_url( get_comment_link( $comment->comment_ID ) ); ?>">
						<span class="review-date-label"><?php esc_html_e( 'Date', 'drplus' ) ?>:</span>
						<time class="review-date"><?php echo get_comment_date( '', $comment ) ?></time>
					</a>
					<?php edit_comment_link( __( '(Edit)' ), '  ', '' ); ?>
				</div>
				<div class="reply">
					<?php
					if( get_post_type() != 'specialist' ) {
						$stars = absint( get_comment_meta( $comment->comment_ID, '_drplus_star', true ) );
						if( $stars )
						UI::stars( $stars, 5 );
					}
					comment_reply_link( 
						array_merge( 
							$args, 
							array( 
								'add_below' => $add_below,
								'depth'     => $depth, 
								'max_depth' => $args['max_depth'] 
							) 
						) 
					);
					?>
				</div>
			</div>
			<?php
			$category_scores = drplus_get_comment_category_scores( $comment->comment_ID );
			$overall_score   = (float) get_comment_meta( $comment->comment_ID, '_drplus_star', true );
			if( $category_scores && ! empty( get_comment_meta( $comment->comment_ID, '_drplus_patient_review', true ) ) ) {
				$labels = drplus_review_category_labels();
				?>
				<div class="drplus-comment-survey">
					<ul class="drplus-comment-rating-categories">
						<?php foreach( $category_scores as $key => $score ) : ?>
							<?php if( $score < 1 || empty( $labels[ $key ] ) ) { continue; } ?>
							<li class="drplus-comment-rating-category">
								<span class="drplus-comment-rating-category-label"><?php echo esc_html( $labels[ $key ] ); ?></span>
								<?php UI::stars( (float) $score, 5, false, 'drplus_star', true ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php
			}
			?>
			<div class="comment-text-wrap">
				<?php comment_text(); ?>
			</div>
		<?php if ( 'div' != $args['style'] ) : ?>
			</div>
		<?php endif;
	}
}