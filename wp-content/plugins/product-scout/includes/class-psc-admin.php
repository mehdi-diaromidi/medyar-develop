<?php
defined( 'ABSPATH' ) || exit;

class PSC_Admin {

	const MAX_URLS = 10;
	const CAP      = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_psc_fetch', array( __CLASS__, 'handle_fetch' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Product Scout', 'product-scout' ),
			__( 'Product Scout', 'product-scout' ),
			self::CAP,
			'product-scout',
			array( __CLASS__, 'render' ),
			'dashicons-search',
			58
		);
	}

	public static function handle_fetch() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'product-scout' ), 403 );
		}
		check_admin_referer( 'psc_fetch' );

		$raw  = isset( $_POST['psc_urls'] ) ? wp_unslash( $_POST['psc_urls'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$urls = array_filter( array_map( 'trim', preg_split( '/\R+/', (string) $raw ) ) );
		$urls = array_slice( array_unique( $urls ), 0, self::MAX_URLS );

		$rows = array();
		foreach ( $urls as $url ) {
			$res = PSC_Fetcher::get( $url );
			if ( $res['ok'] ) {
				$rows[] = array( 'error' => '' ) + PSC_Extractor::extract( $res['html'], $res['final_url'] );
			} else {
				$rows[] = array(
					'error' => $res['error'],
					'url'   => esc_url_raw( $url ),
					'host'  => (string) wp_parse_url( $url, PHP_URL_HOST ),
				);
			}
		}

		$id = wp_generate_password( 12, false );
		set_transient( 'psc_r_' . $id, array( 'user' => get_current_user_id(), 'rows' => $rows ), DAY_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => 'product-scout', 'report' => $id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Product Scout', 'product-scout' ) . '</h1>';
		self::render_form();

		$id = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
		if ( $id ) {
			$report = get_transient( 'psc_r_' . $id );
			if ( is_array( $report ) && (int) $report['user'] === get_current_user_id() ) {
				self::render_report( $report['rows'] );
			} else {
				echo '<p>' . esc_html__( 'Report expired.', 'product-scout' ) . '</p>';
			}
		}
		echo '</div>';
	}

	private static function render_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="psc_fetch">
			<?php wp_nonce_field( 'psc_fetch' ); ?>
			<p><label for="psc_urls"><?php
				/* translators: %d: max number of links */
				echo esc_html( sprintf( __( 'Product page links, one per line (max %d):', 'product-scout' ), self::MAX_URLS ) );
			?></label></p>
			<textarea id="psc_urls" name="psc_urls" rows="8" class="large-text code" dir="ltr" required></textarea>
			<?php submit_button( __( 'Fetch and build report', 'product-scout' ) ); ?>
		</form>
		<?php
	}

	private static function render_report( array $rows ) {
		$fields = array(
			'name'        => __( 'Name', 'product-scout' ),
			'brand'       => __( 'Brand', 'product-scout' ),
			'description' => __( 'Description', 'product-scout' ),
			'sku'         => 'SKU',
			'gtin'        => 'GTIN',
			'price'       => __( 'Price (reference)', 'product-scout' ),
			'source'      => __( 'Extracted from', 'product-scout' ),
		);

		echo '<h2>' . esc_html__( 'Report', 'product-scout' ) . '</h2>';
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th></th>';
		foreach ( $rows as $r ) {
			echo '<th><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $r['host'] ) . '</a></th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $fields as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th>';
			foreach ( $rows as $r ) {
				if ( ! empty( $r['error'] ) ) {
					echo '<td>' . ( 'name' === $key ? '<em>' . esc_html( $r['error'] ) . '</em>' : '' ) . '</td>';
					continue;
				}
				$val = $r[ $key ] ?? '';
				if ( 'price' === $key && $val ) {
					$val .= ' ' . ( $r['currency'] ?? '' );
				}
				echo '<td>' . esc_html( $val ) . '</td>';
			}
			echo '</tr>';
		}

		echo '<tr><th>' . esc_html__( 'Attributes', 'product-scout' ) . '</th>';
		foreach ( $rows as $r ) {
			echo '<td>';
			foreach ( (array) ( $r['attributes'] ?? array() ) as $k => $v ) {
				echo '<div><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '</div>';
			}
			echo '</td>';
		}
		echo '</tr><tr><th>' . esc_html__( 'Images', 'product-scout' ) . '</th>';
		foreach ( $rows as $r ) {
			echo '<td>';
			foreach ( array_slice( (array) ( $r['images'] ?? array() ), 0, 4 ) as $img ) {
				echo '<img src="' . esc_url( $img ) . '" alt="" style="max-width:90px;height:auto;margin:2px" loading="lazy" referrerpolicy="no-referrer"> ';
			}
			echo '</td>';
		}
		echo '</tr></tbody></table></div>';
	}
}
