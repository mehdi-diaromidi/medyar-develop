<?php
/**
 * Affiliates who joined with this user's inviter code.
 *
 * @var WP_User $user
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id     = (int) $user->ID;
$invite_code = Arp_Inviter::ensure_code( $user_id );
$invite_url  = Arp_Inviter::share_url( $user_id );
$is_special  = Arp_Inviter::is_special_inviter( $user_id );
$per_page    = 20;
$paged       = isset( $_GET['arp_paged'] ) ? max( 1, absint( $_GET['arp_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$list        = Arp_Inviter::referred_affiliates( $user_id, $paged, $per_page );
$items       = $list['items'];
$total       = $list['total'];
$pages       = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

$gain          = null;
$services      = array();
$f_service     = 0;
$range         = array( 'from' => '', 'to' => '', 'date_from' => '', 'date_to' => '' );
$invitee_gains = array();
if ( $is_special ) {
	$gain      = Arp_Panel_Lists::invited_commissions( $user_id );
	$services  = Arp_Services_Repo::all( false );
	$f_service = $gain['f_service'];
	$range     = $gain['range'];
	if ( $items ) {
		$invitee_gains = Arp_Commissions_Repo::totals_by_affiliates( wp_list_pluck( $items, 'ID' ) );
	}
}
$cols       = $is_special ? 5 : 4;
$in_account = Arp_Pages::invited_in_account();
$title_tag  = $in_account ? 'h2' : 'h1';
?>
<section class="arp-refs arp-invited-panel<?php echo $in_account ? ' arp-invited-panel--myaccount' : ''; ?>" dir="rtl">

	<header class="arp-refs__head">
		<div class="arp-refs__intro">
			<<?php echo esc_attr( $title_tag ); ?> class="arp-refs__title">
				<span class="drplus-simple-icon-wrap icon-has-bg arp-refs__title-icon" aria-hidden="true">
					<i class="drplus-icon-user-group drplus-simple-icon"></i>
				</span>
				<span class="arp-refs__title-text">همکاران معرفی‌شده</span>
			</<?php echo esc_attr( $title_tag ); ?>>
			<p class="arp-refs__lead">همکارانی که با کد معرف شما (<code dir="ltr"><?php echo esc_html( $invite_code ); ?></code>) درخواست عضویت داده‌اند.</p>
		</div>
	</header>

	<?php if ( '' !== $invite_url ) : ?>
		<div class="arp-card arp-invite-card arp-invite-card--panel" id="arp-invite-url">
			<strong class="arp-invite-card__title">لینک دعوت</strong>
			<p class="arp-invite-card__desc">این لینک را برای دعوت همکاران جدید به اشتراک بگذارید.</p>
			<?php include __DIR__ . '/partials/invite-url.php'; ?>
		</div>
	<?php endif; ?>

	<?php if ( $is_special && is_array( $gain ) ) : ?>
		<form class="arp-card arp-refs-filters arp-invited-filters" method="get" action="<?php echo esc_url( $in_account ? Arp_MyAccount_Invited::url() : Arp_Pages::dashboard_url() ); ?>" data-arp-list="invited_commissions" role="search">
			<?php if ( ! $in_account ) : ?>
				<input type="hidden" name="arp_page" value="invited" />
			<?php endif; ?>

			<?php include __DIR__ . '/partials/date-range.php'; ?>

			<label class="arp-field-block">
				<span class="arp-field-block__label">نوع خدمت</span>
				<span class="arp-field-block__control arp-field-block__control--select">
					<select name="arp_service" class="arp-select-native" data-arp-select data-arp-autosubmit>
						<option value="">همه خدمات</option>
						<?php foreach ( $services as $svc ) : ?>
							<option value="<?php echo esc_attr( $svc['id'] ); ?>" <?php selected( $f_service, (int) $svc['id'] ); ?>><?php echo esc_html( $svc['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="arp-field-block__chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></span>
				</span>
			</label>

			<?php
			$list          = $gain;
			$arp_list_view = 'invited';
			include __DIR__ . '/partials/filters-reset.php';
			?>
		</form>

		<div class="arp-card arp-card--table arp-invited-gain-results" data-arp-results="invited_commissions"<?php echo $in_account ? ' data-arp-context="account"' : ''; ?> aria-live="polite">
			<?php echo Arp_Panel_Lists::render( 'invited_commissions', $gain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the partial. ?>
		</div>
	<?php endif; ?>

	<div class="arp-card arp-card--table" id="arp-invited-list">
		<div class="arp-table-wrap">
			<table class="arp-table">
				<thead>
					<tr>
						<th class="arp-table__num">ردیف</th>
						<th>نام</th>
						<th>موبایل</th>
						<th>تاریخ عضویت</th>
						<?php if ( $is_special ) : ?>
							<th>جمع پورسانت دریافتی</th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $items ) : ?>
						<tr>
							<td colspan="<?php echo esc_attr( (string) $cols ); ?>" class="arp-table__empty">هنوز همکاری با کد معرف شما ثبت نشده است.</td>
						</tr>
					<?php else : ?>
						<?php
						$offset = ( $paged - 1 ) * $per_page;
						foreach ( $items as $i => $row ) :
							$uid = (int) $row->ID;
							?>
							<tr>
								<td class="arp-table__num"><?php echo esc_html( Arp_Format::fa( $offset + $i + 1 ) ); ?></td>
								<td><?php echo esc_html( $row->display_name ); ?></td>
								<td class="arp-table__mobile" dir="ltr"><?php echo esc_html( Arp_Inviter::mask_mobile_display( Arp_Inviter::user_mobile_raw( $uid ) ) ); ?></td>
								<td><?php echo esc_html( Arp_Inviter::membership_date_display( $uid ) ); ?></td>
								<?php if ( $is_special ) : ?>
									<?php $earned = (float) ( $invitee_gains[ $uid ]['payable'] ?? 0 ) + (float) ( $invitee_gains[ $uid ]['paid'] ?? 0 ); ?>
									<td class="arp-comm__amount">
										<strong><?php echo esc_html( Arp_Format::money( $earned ) ); ?></strong>
										<span>تومان</span>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php
		$page_num = $paged;
		$per      = $per_page;
		$offset   = ( $paged - 1 ) * $per_page;
		$page_url = static function ( int $n ): string {
			return Arp_Pages::page_url( 'invited', array( 'arp_paged' => $n ) );
		};
		include __DIR__ . '/partials/pager.php';
		?>
	</div>

	<?php include __DIR__ . '/partials/invite-colleagues-cta.php'; ?>
</section>
