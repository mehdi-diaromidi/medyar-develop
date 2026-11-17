<?php
defined( 'ABSPATH' ) || exit;

class PSC_Admin {

	const MAX_URLS        = 10;
	const MAX_TOROB_PICKS = 8;
	const CAP             = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_psc_fetch', array( __CLASS__, 'handle_fetch' ) );
		add_action( 'admin_post_psc_torob_pick', array( __CLASS__, 'handle_torob_pick' ) );
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

	/* ------------------------------------------------------------------ */
	/* Handlers                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle_fetch() {
		self::guard( 'psc_fetch' );

		$raw  = isset( $_POST['psc_urls'] ) ? (string) wp_unslash( $_POST['psc_urls'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$urls = array_filter( array_map( 'trim', preg_split( '/\R+/', $raw ) ) );
		$urls = array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_URLS );

		self::save_and_redirect( self::build_report( $urls ) );
	}

	public static function handle_torob_pick() {
		self::guard( 'psc_torob_pick' );
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$picked = isset( $_POST['psc_links'] ) ? (array) wp_unslash( $_POST['psc_links'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$picked = array_slice( array_values( array_unique( array_map( 'strval', $picked ) ) ), 0, self::MAX_TOROB_PICKS );

		$shop_urls = array();
		$errors    = array();
		foreach ( $picked as $link ) {
			$res = PSC_Torob::resolve( $link );
			if ( $res['ok'] ) {
				$shop_urls[] = $res['url'];
			} else {
				$errors[] = array(
					'error' => $res['error'],
					'url'   => esc_url_raw( $link ),
					'host'  => 'torob.com',
				);
			}
		}

		$report           = self::build_report( $shop_urls );
		$report['rows']   = array_merge( $report['rows'], $errors );
		self::save_and_redirect( $report );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'product-scout' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** Torob links become seller lists; every other link is fetched and extracted. */
	private static function build_report( array $urls ) {
		$report = array(
			'user'  => get_current_user_id(),
			'rows'  => array(),
			'torob' => array(),
		);

		foreach ( $urls as $url ) {
			if ( PSC_Torob::is_torob_url( $url ) ) {
				$report['torob'][] = PSC_Torob::sellers( $url );
				continue;
			}

			$res = PSC_Fetcher::get( $url );
			if ( $res['ok'] ) {
				$report['rows'][] = array( 'error' => '' ) + PSC_Extractor::extract( $res['html'], $res['final_url'] );
			} else {
				$report['rows'][] = array(
					'error' => $res['error'],
					'url'   => esc_url_raw( $url ),
					'host'  => (string) wp_parse_url( $url, PHP_URL_HOST ),
				);
			}
		}
		return $report;
	}

	private static function save_and_redirect( array $report ) {
		$id = wp_generate_password( 12, false );
		set_transient( 'psc_r_' . $id, $report, DAY_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => 'product-scout', 'report' => $id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Product Scout', 'product-scout' ) . '</h1>';

		$left = PSC_Torob::cooldown_left();
		if ( $left > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				/* translators: %d: minutes */
				sprintf( __( 'Torob is paused for %d more minute(s) because it refused a request.', 'product-scout' ), (int) ceil( $left / 60 ) )
			) . '</p></div>';
		}

		self::render_form();

		$id = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id ) {
			$report = get_transient( 'psc_r_' . $id );
			if ( is_array( $report ) && (int) $report['user'] === get_current_user_id() ) {
				foreach ( (array) ( $report['torob'] ?? array() ) as $block ) {
					self::render_torob( $block );
				}
				if ( ! empty( $report['rows'] ) ) {
					self::render_report( $report['rows'] );
				}
			} else {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Report expired.', 'product-scout' ) . '</p></div>';
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
				echo esc_html( sprintf( __( 'Product page links, one per line (max %d). A Torob link lists the shops selling it.', 'product-scout' ), self::MAX_URLS ) );
			?></label></p>
			<textarea id="psc_urls" name="psc_urls" rows="6" class="large-text code" dir="ltr" required></textarea>
			<?php submit_button( __( 'Fetch and build report', 'product-scout' ) ); ?>
		</form>
		<?php
	}

	private static function render_torob( array $block ) {
		echo '<h2>' . esc_html__( 'Torob sellers', 'product-scout' ) . ' <a class="page-title-action" href="' . esc_url( $block['url'] ?? '' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open on Torob', 'product-scout' ) . '</a></h2>';

		if ( empty( $block['ok'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $block['error'] ) . '</p></div>';
			self::render_diag( (array) ( $block['diag'] ?? array() ) );
			return;
		}

		if ( empty( $block['sellers'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Torob lists no online sellers for this product.', 'product-scout' ) . '</p></div>';
			self::render_diag( (array) $block['diag'] );
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="psc_torob_pick">
			<?php wp_nonce_field( 'psc_torob_pick' ); ?>
			<p class="description"><?php
				/* translators: %d: max shops */
				echo esc_html( sprintf( __( 'Pick up to %d shops. Their Torob links are resolved to the real shop pages, which are then fetched.', 'product-scout' ), self::MAX_TOROB_PICKS ) );
			?></p>
			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"></td>
					<th><?php esc_html_e( 'Shop', 'product-scout' ); ?></th>
					<th><?php esc_html_e( 'City', 'product-scout' ); ?></th>
					<th><?php esc_html_e( 'Price', 'product-scout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'product-scout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $block['sellers'] as $i => $s ) : ?>
					<tr>
						<th scope="row" class="check-column">
							<?php if ( $s['link'] ) : ?>
								<input type="checkbox" name="psc_links[]" value="<?php echo esc_attr( $s['link'] ); ?>" <?php checked( $i < 5 && $s['available'] ); ?>>
							<?php endif; ?>
						</th>
						<td><strong><?php echo esc_html( $s['shop'] ); ?></strong></td>
						<td><?php echo esc_html( $s['city'] ); ?></td>
						<td><?php echo esc_html( $s['price'] ); ?></td>
						<td><?php echo $s['available'] ? '<span class="dashicons dashicons-yes" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-minus" style="color:#996800"></span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Fetch selected shops', 'product-scout' ) ); ?>
		</form>
		<?php
		self::render_diag( (array) $block['diag'] );
	}

	/** Raw request details, so unexpected Torob answers can be diagnosed. */
	private static function render_diag( array $diag ) {
		if ( ! $diag ) {
			return;
		}
		echo '<details style="margin:8px 0 24px"><summary>' . esc_html__( 'Diagnostics (for support)', 'product-scout' ) . '</summary>';
		echo '<pre dir="ltr" style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#f6f7f7;padding:8px">';
		echo esc_html( wp_json_encode( $diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		echo '</pre></details>';
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
