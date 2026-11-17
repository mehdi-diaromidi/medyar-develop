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
		add_action( 'wp_ajax_psc_torob_import', array( __CLASS__, 'ajax_torob_import' ) );
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
		// Hidden page the Torob bookmarklet opens; it relays the data to admin-ajax.
		add_submenu_page( '', __( 'Receive from Torob', 'product-scout' ), '', self::CAP, 'product-scout-receive', array( __CLASS__, 'render_receiver' ) );
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
		wp_safe_redirect( self::store_report( $report ) );
		exit;
	}

	/** Saves a report for the current user and returns its admin URL. */
	private static function store_report( array $report ) {
		$id = wp_generate_password( 12, false );
		set_transient( 'psc_r_' . $id, $report, DAY_IN_SECONDS );
		return add_query_arg( array( 'page' => 'product-scout', 'report' => $id ), admin_url( 'admin.php' ) );
	}

	/** Seller data fetched by the admin's own browser on torob.com (bookmarklet). */
	public static function ajax_torob_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'product-scout' ) ), 403 );
		}
		check_ajax_referer( 'psc_torob_import' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- raw JSON, sanitized field by field in PSC_Torob::from_browser().
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$status   = isset( $_POST['status'] ) ? (int) $_POST['status'] : 0;
		$api_raw  = isset( $_POST['api'] ) ? (string) wp_unslash( $_POST['api'] ) : '';
		$next_raw = isset( $_POST['next'] ) ? (string) wp_unslash( $_POST['next'] ) : '';
		// phpcs:enable

		if ( strlen( $api_raw ) + strlen( $next_raw ) > 6 * MB_IN_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Data too large.', 'product-scout' ) ), 413 );
		}
		if ( ! PSC_Torob::is_torob_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'This only works on a Torob product page.', 'product-scout' ) ), 400 );
		}

		$api   = json_decode( $api_raw, true );
		$block = PSC_Torob::from_browser( $url, $api, '' !== $next_raw ? json_decode( $next_raw, true ) : null, $status );
		if ( ! $block['ok'] && null === $api && '' !== $api_raw ) {
			$block['diag']['api_snippet'] = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $api_raw ) ) ), 0, 400 );
		}

		$redirect = self::store_report(
			array(
				'user'  => get_current_user_id(),
				'rows'  => array(),
				'torob' => array( $block ),
			)
		);
		wp_send_json_success( array( 'redirect' => $redirect ) );
	}

	private static function receiver_url() {
		return admin_url( 'admin.php?page=product-scout-receive' );
	}

	/** javascript: link the admin drags to the bookmarks bar and clicks on a Torob product page. */
	private static function bookmarklet() {
		$receiver = self::receiver_url();
		$origin   = (string) wp_parse_url( $receiver, PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( $receiver, PHP_URL_HOST );
		$port     = wp_parse_url( $receiver, PHP_URL_PORT );
		if ( $port ) {
			$origin .= ':' . (int) $port;
		}
		$msg = __( 'Open a Torob product page (torob.com/p/...) first, then click this bookmark.', 'product-scout' );

		$js = <<<'JS'
(function(){
var R=__R__,O=__O__;
var m=location.pathname.match(/\/p\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i);
if(!/(^|\.)torob\.com$/.test(location.hostname)||!m){alert(__M__);return;}
var w=window.open(R,'psc_receive');
var api='https://api.torob.com/v4/base-product/sellers/?prk='+m[1]+'&list_type=products_info';
function get(c){return fetch(api,{credentials:c}).then(function(r){return r.text().then(function(t){return{s:r.status,t:t};});});}
var data=get('include').catch(function(){return get('omit');}).catch(function(){return{s:0,t:''};}).then(function(d){
var nd='';if(d.s!==200){var el=document.getElementById('__NEXT_DATA__');if(el){nd=el.textContent;}}
return{psc:1,url:location.href,status:d.s,api:d.t,next:nd};});
var got=false;
window.addEventListener('message',function(e){if(e.origin!==O||e.data!=='psc-ready')return;got=true;data.then(function(p){e.source.postMessage(p,O);});});
setTimeout(function(){if(got)return;data.then(function(p){
var d=document.createElement('div');d.style.cssText='position:fixed;z-index:2147483647;inset:10% 10% auto;background:#fff;border:1px solid #c3c4c7;padding:16px;box-shadow:0 4px 20px rgba(0,0,0,.3);direction:ltr;font:13px sans-serif';
var t=document.createElement('textarea');t.value=JSON.stringify(p);t.style.cssText='width:100%;height:120px';
var b=document.createElement('button');b.textContent='Copy and close';b.onclick=function(){t.select();try{navigator.clipboard.writeText(t.value);}catch(x){document.execCommand('copy');}d.remove();};
d.append('Product Scout: the plugin tab could not be reached. Copy this and paste it on the plugin page.',t,b);document.body.append(d);});},8000);
})();
JS;
		$js = strtr(
			$js,
			array(
				'__R__' => wp_json_encode( $receiver ),
				'__O__' => wp_json_encode( $origin ),
				'__M__' => wp_json_encode( $msg ),
			)
		);
		return 'javascript:' . rawurlencode( preg_replace( '/\n/', '', $js ) );
	}

	/** Receives the bookmarklet's data (postMessage, or a manual paste) and imports it. */
	public static function render_receiver() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Receiving from Torob', 'product-scout' ); ?></h1>
			<div id="psc-rx-status" class="notice notice-info inline"><p><span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span><span class="psc-rx-text"><?php esc_html_e( 'Waiting for the Torob tab…', 'product-scout' ); ?></span></p></div>
			<div id="psc-rx-paste" class="postbox" style="margin-top:16px;display:none"><div class="inside">
				<p><label for="psc-rx-json"><?php esc_html_e( 'If the Torob tab showed a box with data, paste it here:', 'product-scout' ); ?></label></p>
				<textarea id="psc-rx-json" class="large-text code" rows="6" dir="ltr"></textarea>
				<p><button type="button" class="button button-primary" id="psc-rx-send"><?php esc_html_e( 'Import', 'product-scout' ); ?></button></p>
			</div></div>
		</div>
		<script>
		( function () {
			var allowed = /^https:\/\/(www\.)?torob\.com$/, done = false;
			var box = document.getElementById( 'psc-rx-status' ), text = box.querySelector( '.psc-rx-text' );
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'psc_torob_import' ) ); ?>;
			var i18n = <?php echo wp_json_encode( array(
				'working' => __( 'Data received. Building the seller list…', 'product-scout' ),
				'failed'  => __( 'Import failed:', 'product-scout' ),
				'noTab'   => __( 'No Torob tab is connected. Open a Torob product page and click the bookmarklet, or paste the data below.', 'product-scout' ),
				'badJson' => __( 'The pasted text is not valid data from the bookmarklet.', 'product-scout' ),
			) ); ?>;

			function state( cls, msg, spin ) {
				box.className = 'notice inline notice-' + cls;
				text.textContent = msg;
				box.querySelector( '.spinner' ).style.display = spin ? '' : 'none';
			}
			function send( p ) {
				if ( done ) { return; }
				done = true;
				state( 'info', i18n.working, true );
				var fd = new FormData();
				fd.append( 'action', 'psc_torob_import' );
				fd.append( '_wpnonce', nonce );
				fd.append( 'url', String( p.url || '' ) );
				fd.append( 'status', String( p.status || 0 ) );
				fd.append( 'api', String( p.api || '' ) );
				fd.append( 'next', String( p.next || '' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( j && j.success ) { window.location.href = j.data.redirect; return; }
						done = false;
						state( 'error', i18n.failed + ' ' + ( j && j.data && j.data.message ? j.data.message : '' ), false );
					} )
					.catch( function ( e ) { done = false; state( 'error', i18n.failed + ' ' + e, false ); } );
			}
			window.addEventListener( 'message', function ( e ) {
				if ( allowed.test( e.origin ) && e.data && 1 === e.data.psc ) { send( e.data ); }
			} );
			document.getElementById( 'psc-rx-send' ).addEventListener( 'click', function () {
				try { send( JSON.parse( document.getElementById( 'psc-rx-json' ).value ) ); }
				catch ( x ) { state( 'error', i18n.badJson, false ); }
			} );
			var tries = 0;
			( function ping() {
				if ( done ) { return; }
				if ( window.opener && tries < 40 ) {
					try { window.opener.postMessage( 'psc-ready', '*' ); } catch ( x ) {}
					tries++;
					setTimeout( ping, 500 );
					return;
				}
				state( 'warning', i18n.noTab, false );
				document.getElementById( 'psc-rx-paste' ).style.display = '';
			} )();
		} )();
		</script>
		<?php
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
		self::render_bookmarklet_box();

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

	private static function render_bookmarklet_box() {
		?>
		<div class="postbox" style="max-width:900px"><div class="inside">
			<h2 style="padding:0;margin:8px 0"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Torob via your browser', 'product-scout' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Torob blocks requests from servers, so the seller list is read by your own browser. Drag this button to your bookmarks bar once. Then, on any Torob product page, click the bookmark: a new tab opens here with the sellers.', 'product-scout' ); ?></p>
			<p><a class="button button-secondary" href="<?php echo esc_attr( self::bookmarklet() ); ?>" onclick="return false;" title="<?php esc_attr_e( 'Drag me to the bookmarks bar', 'product-scout' ); ?>"><span class="dashicons dashicons-download" style="vertical-align:text-bottom"></span> <?php esc_html_e( 'Send to Product Scout', 'product-scout' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( self::receiver_url() ); ?>" style="margin-inline-start:12px"><?php esc_html_e( 'Paste data manually', 'product-scout' ); ?></a></p>
		</div></div>
		<?php
	}

	private static function render_torob( array $block ) {
		echo '<h2>' . esc_html__( 'Torob sellers', 'product-scout' ) . ' <a class="page-title-action" href="' . esc_url( $block['url'] ?? '' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open on Torob', 'product-scout' ) . '</a></h2>';

		if ( empty( $block['ok'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $block['error'] ) . '</p>';
			if ( 'browser' !== ( $block['diag']['via'] ?? '' ) ) {
				echo '<p>' . esc_html__( 'Use the "Send to Product Scout" bookmark on the Torob page instead; it reads the sellers through your browser.', 'product-scout' ) . '</p>';
			}
			echo '</div>';
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
