<?php
/**
 * EWD_Admin_UI — menu, pages, PRG routes, CSV, order screen (Bible §3–§8, §11).
 *
 * Pages (submenus of the shared "Noxpress" menu, admin_menu priority 70):
 *  - ewd-requests  status block, list of requests (filters by status,
 *                  search by order number, request id or email, paging,
 *                  CSV export) and the detail of one order's requests
 *                  (&order=ID) with status changes and the refund button
 *  - ewd-settings  mode, page, period, texts, recipients, exclusions,
 *                  consent, links
 *
 * Order screen (HPOS and legacy): a metabox with the order's requests, and
 * the refund pre-fill (&ewd_refund=<request id>: opens WooCommerce's refund
 * form with the request's quantities; the admin reviews and refunds).
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation, PRG with the message in the transient
 * ewd_aui_msg_{uid}.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG_MAIN   = 'ewd-requests';
	const SLUG_SET    = 'ewd-settings';
	const NONCE       = 'ewd_admin';
	const NONCE_FIELD = 'ewd_nonce';
	const NONCE_CSV   = 'ewd_csv';
	const PER_PAGE    = 20;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 70 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ), 30, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( EWD_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		$new   = current_user_can( self::CAP ) ? EWD_Requests::count_new() : 0;
		$badge = $new > 0 ? ' <span class="awaiting-mod count-' . (int) $new . '"><span class="pending-count">' . ( $new >= 100 ? '99+' : (int) $new ) . '</span></span>' : '';

		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): submenus only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Easy Withdrawal', 'easy-withdrawal' ),
			__( 'Υπαναχωρήσεις', 'easy-withdrawal' ) . $badge,
			self::CAP,
			self::SLUG_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Easy Withdrawal — Ρυθμίσεις', 'easy-withdrawal' ),
			__( 'EWD Ρυθμίσεις', 'easy-withdrawal' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'easy-withdrawal' ) . '</a>'
		);
		return $links;
	}

	/** Current admin page slug (sanitized) — '' outside admin.php?page=. */
	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only routing). */
	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	/** True on the order edit screen (HPOS or legacy). */
	private static function on_order_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$ids = array( 'shop_order', 'woocommerce_page_wc-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		return in_array( $screen->id, $ids, true );
	}

	public static function assets(): void {
		$page = self::current_page();
		if ( self::SLUG_MAIN === $page || self::SLUG_SET === $page ) {
			wp_enqueue_style( 'ewd-admin', EWD_URL . 'assets/admin.css', array(), EWD_VERSION );
			return;
		}
		if ( ! self::on_order_screen() ) {
			return;
		}
		wp_enqueue_style( 'ewd-order', EWD_URL . 'assets/order.css', array(), EWD_VERSION );

		$req_id = self::q( 'ewd_refund' );
		$order  = self::order_from_screen();
		if ( '' === $req_id || ! $order ) {
			return;
		}
		$req = EWD_Requests::get( $order, $req_id );
		if ( ! $req ) {
			return;
		}
		$qty = array();
		foreach ( $req['items'] as $it ) {
			$qty[ (int) $it['item_id'] ] = (int) $it['qty'];
		}
		wp_enqueue_script( 'ewd-refund', EWD_URL . 'assets/refund.js', array( 'jquery', 'wc-admin-order-meta-boxes' ), EWD_VERSION, true );
		wp_localize_script(
			'ewd-refund',
			'ewdRefund',
			array(
				'items'  => $qty,
				'reason' => sprintf( /* translators: %s: request id */ __( 'Υπαναχώρηση %s', 'easy-withdrawal' ), $req['id'] ),
			)
		);
	}

	/** The order being edited on the order screen. */
	private static function order_from_screen(): ?WC_Order {
		$id = self::q( 'id' );
		if ( '' === $id ) {
			$id = self::q( 'post' );
		}
		$order = ctype_digit( $id ) ? wc_get_order( (int) $id ) : false;
		return $order instanceof WC_Order ? $order : null;
	}

	/** Reminder until a page holds the form (once the plugin runs live or in test). */
	public static function setup_notice(): void {
		if ( ! current_user_can( self::CAP ) || '' !== EWD_Settings::page_url() || 'off' === EWD_Settings::get()['mode'] ) {
			return;
		}
		$page = self::current_page();
		if ( self::SLUG_SET === $page ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Easy Withdrawal: δεν έχει οριστεί ακόμα η σελίδα της φόρμας υπαναχώρησης.', 'easy-withdrawal' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'easy-withdrawal' ) . '</a></p></div>';
	}

	/* =====================================================================
	 * Page: requests
	 * =================================================================== */

	public static function render_main(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'easy-withdrawal' ) );
		}

		$notices = self::take_msgs();
		$oid     = self::q( 'order' );
		?>
		<div class="wrap ewd-wrap">
			<h1><?php esc_html_e( 'Easy Withdrawal', 'easy-withdrawal' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Οι δηλώσεις υπαναχώρησης των πελατών. Η δήλωση ισχύει από τη στιγμή της υποβολής: εδώ καταγράφεις την πορεία της επιστροφής.', 'easy-withdrawal' ); ?></p>

			<?php self::print_notices( $notices ); ?>

			<?php
			$order = ctype_digit( $oid ) ? wc_get_order( (int) $oid ) : false;
			if ( $order instanceof WC_Order && EWD_Requests::for_order( $order ) ) {
				self::render_detail( $order );
			} else {
				self::render_status();
				self::render_list();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function render_status(): void {
		$s    = EWD_Settings::get();
		$url  = EWD_Settings::page_url();
		$page = (int) $s['page_id'];
		$has  = $page && has_shortcode( (string) get_post_field( 'post_content', $page ), EWD_Form::SHORTCODE );
		$mode = array(
			'off'  => __( 'Ανενεργό', 'easy-withdrawal' ),
			'test' => __( 'Λειτουργία δοκιμής — η φόρμα και οι σύνδεσμοι φαίνονται μόνο στους διαχειριστές του καταστήματος', 'easy-withdrawal' ),
			'live' => __( 'Ενεργό για όλους τους πελάτες', 'easy-withdrawal' ),
		);
		$mailer  = WC()->mailer();
		$emails  = $mailer ? $mailer->get_emails() : array();
		$receipt = isset( $emails['EWD_Email_Receipt'] ) ? $emails['EWD_Email_Receipt']->is_enabled() : false;
		?>
		<h2 class="ewd-h2"><?php esc_html_e( 'Κατάσταση', 'easy-withdrawal' ); ?></h2>
		<div class="ewd-zone ewd-status">
			<p>
				<strong><?php esc_html_e( 'Easy Withdrawal:', 'easy-withdrawal' ); ?></strong>
				<?php if ( Easy_Withdrawal::killed() ) : ?>
					<span class="ewd-badge ewd-badge--danger"><?php esc_html_e( 'Απενεργοποιημένο από το EWD_DISABLE (wp-config.php)', 'easy-withdrawal' ); ?></span>
				<?php else : ?>
					<span class="ewd-badge <?php echo 'live' === $s['mode'] ? 'ewd-badge--ok' : ( 'test' === $s['mode'] ? 'ewd-badge--warn' : 'ewd-badge--muted' ); ?>"><?php echo esc_html( $mode[ $s['mode'] ] ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Αλλαγή', 'easy-withdrawal' ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'Σελίδα φόρμας:', 'easy-withdrawal' ); ?></strong>
				<?php if ( '' === $url ) : ?>
					<span class="ewd-badge ewd-badge--warn"><?php esc_html_e( 'Δεν έχει οριστεί', 'easy-withdrawal' ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $page ) ); ?></a>
					<?php if ( ! $has ) : ?>
						<span class="ewd-badge ewd-badge--warn"><?php esc_html_e( 'Δεν περιέχει το shortcode [nox_withdrawal]', 'easy-withdrawal' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Απόδειξη στον πελάτη:', 'easy-withdrawal' ); ?></strong>
				<?php if ( $receipt ) : ?>
					<span class="ewd-badge ewd-badge--ok"><?php esc_html_e( 'Ενεργή', 'easy-withdrawal' ); ?></span>
				<?php else : ?>
					<span class="ewd-badge ewd-badge--danger"><?php esc_html_e( 'Ανενεργή: η Οδηγία ζητά απόδειξη σε σταθερό μέσο', 'easy-withdrawal' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=ewd_email_receipt' ) ); ?>"><?php esc_html_e( 'Emails', 'easy-withdrawal' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function render_list(): void {
		$state  = self::q( 'state' );
		$state  = in_array( $state, EWD_Requests::STATUSES, true ) ? $state : '';
		$search = self::q( 's' );
		$paged  = max( 1, (int) self::q( 'paged' ) );
		$res    = EWD_Requests::query(
			array(
				'state'    => $state,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => self::PER_PAGE,
			)
		);
		$base   = admin_url( 'admin.php?page=' . self::SLUG_MAIN );
		?>
		<h2 class="ewd-h2"><?php esc_html_e( 'Αιτήματα', 'easy-withdrawal' ); ?></h2>
		<form method="get" class="ewd-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_MAIN ); ?>" />
			<select name="state">
				<option value=""><?php esc_html_e( 'Όλες οι καταστάσεις', 'easy-withdrawal' ); ?></option>
				<?php foreach ( EWD_Requests::status_labels() as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $state, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Αριθμός παραγγελίας, αιτήματος ή email', 'easy-withdrawal' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Φιλτράρισμα', 'easy-withdrawal' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'ewd_csv' => 1, 'state' => $state, 's' => $search ), $base ), self::NONCE_CSV ) ); ?>"><?php esc_html_e( 'Εξαγωγή CSV', 'easy-withdrawal' ); ?></a>
		</form>
		<p class="description"><?php esc_html_e( 'Το φίλτρο κατάστασης δείχνει τις παραγγελίες όπου το πιο επείγον αίτημα έχει αυτή την κατάσταση (σειρά: Νέα, Σε εξέλιξη, Αμφισβητείται, Ολοκληρώθηκε).', 'easy-withdrawal' ); ?></p>

		<table class="widefat ewd-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Αίτημα', 'easy-withdrawal' ); ?></th>
					<th><?php esc_html_e( 'Υποβλήθηκε', 'easy-withdrawal' ); ?></th>
					<th><?php esc_html_e( 'Παραγγελία', 'easy-withdrawal' ); ?></th>
					<th><?php esc_html_e( 'Πελάτης', 'easy-withdrawal' ); ?></th>
					<th><?php esc_html_e( 'Προϊόντα', 'easy-withdrawal' ); ?></th>
					<th><?php esc_html_e( 'Αξία', 'easy-withdrawal' ); ?></th>
					<th><?php esc_html_e( 'Κατάσταση', 'easy-withdrawal' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$rows = 0;
				foreach ( $res['orders'] as $order ) {
					if ( ! $order instanceof WC_Order ) {
						continue;
					}
					foreach ( array_reverse( EWD_Requests::for_order( $order ) ) as $r ) {
						++$rows;
						$link = add_query_arg( 'order', $order->get_id(), $base );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $link ); ?>"><strong><?php echo esc_html( (string) $r['id'] ); ?></strong></a></td>
							<td><?php echo esc_html( EWD_Lang::datetime( (int) $r['created'] ) ); ?></td>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a> <span class="ewd-muted"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></td>
							<td><?php echo esc_html( (string) $r['name'] ); ?><br /><span class="ewd-muted"><?php echo esc_html( (string) $r['email'] ); ?></span></td>
							<td><?php echo esc_html( EWD_Requests::items_text( $r['items'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( EWD_Requests::amount( $r ), array( 'currency' => $order->get_currency() ) ) ); ?></td>
							<td><span class="ewd-badge ewd-st-<?php echo esc_attr( (string) $r['status'] ); ?>"><?php echo esc_html( EWD_Requests::status_label( (string) $r['status'] ) ); ?></span></td>
						</tr>
						<?php
					}
				}
				if ( ! $rows ) {
					echo '<tr><td colspan="7" class="ewd-empty">' . esc_html__( 'Δεν υπάρχουν αιτήματα.', 'easy-withdrawal' ) . '</td></tr>';
				}
				?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $res['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="ewd-pager">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg( array( 'state' => $state, 's' => $search, 'paged' => $i ), $base );
				echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	private static function render_detail( WC_Order $order ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG_MAIN );
		?>
		<p><a href="<?php echo esc_url( $base ); ?>">← <?php esc_html_e( 'Όλα τα αιτήματα', 'easy-withdrawal' ); ?></a></p>
		<h2 class="ewd-h2">
			<?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Παραγγελία #%s', 'easy-withdrawal' ), $order->get_order_number() ) ); ?>
		</h2>
		<p>
			<a class="button" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'Άνοιγμα παραγγελίας', 'easy-withdrawal' ); ?></a>
			<span class="ewd-muted"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) . ' · ' . trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . ' · ' . $order->get_billing_email() ); ?></span>
		</p>
		<?php
		foreach ( array_reverse( EWD_Requests::for_order( $order ) ) as $r ) {
			self::render_request( $order, $r );
		}
	}

	private static function render_request( WC_Order $order, array $r ): void {
		$users = array();
		?>
		<div class="ewd-zone ewd-request">
			<h3 class="ewd-h3">
				<?php echo esc_html( (string) $r['id'] ); ?>
				<span class="ewd-badge ewd-st-<?php echo esc_attr( (string) $r['status'] ); ?>"><?php echo esc_html( EWD_Requests::status_label( (string) $r['status'] ) ); ?></span>
			</h3>
			<p>
				<?php esc_html_e( 'Υποβλήθηκε:', 'easy-withdrawal' ); ?> <strong><?php echo esc_html( EWD_Lang::datetime( (int) $r['created'] ) ); ?></strong>
				· <?php echo esc_html( (string) $r['name'] . ' · ' . (string) $r['email'] ); ?>
				· <?php echo esc_html( 'en' === $r['lang'] ? 'English' : 'Ελληνικά' ); ?>
			</p>
			<?php if ( '' !== (string) $r['reason'] ) : ?>
				<p><?php esc_html_e( 'Αιτιολογία:', 'easy-withdrawal' ); ?> <?php echo nl2br( esc_html( (string) $r['reason'] ) ); ?></p>
			<?php endif; ?>

			<table class="widefat ewd-table">
				<thead><tr><th><?php esc_html_e( 'Προϊόν', 'easy-withdrawal' ); ?></th><th><?php esc_html_e( 'Ποσότητα', 'easy-withdrawal' ); ?></th><th><?php esc_html_e( 'Αξία', 'easy-withdrawal' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $r['items'] as $it ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $it['name'] ); ?></td>
							<td><?php echo (int) $it['qty']; ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $it['unit'] * (int) $it['qty'], array( 'currency' => $order->get_currency() ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="ewd-mt-8">
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'ewd_refund', rawurlencode( (string) $r['id'] ), $order->get_edit_order_url() ) ); ?>"><?php esc_html_e( 'Επιστροφή χρημάτων στο WooCommerce', 'easy-withdrawal' ); ?></a>
				<span class="ewd-hint"><?php esc_html_e( 'Ανοίγει την παραγγελία με τη φόρμα επιστροφής χρημάτων συμπληρωμένη με τα προϊόντα του αιτήματος. Ελέγχεις τα ποσά (και τα μεταφορικά) και πατάς εσύ την επιστροφή.', 'easy-withdrawal' ); ?></span>
			</p>

			<form method="post" class="ewd-status-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="ewd_action" value="set_status" />
				<input type="hidden" name="ewd_order" value="<?php echo (int) $order->get_id(); ?>" />
				<input type="hidden" name="ewd_req" value="<?php echo esc_attr( (string) $r['id'] ); ?>" />
				<label class="ewd-label" for="ewd-st-<?php echo esc_attr( (string) $r['id'] ); ?>"><?php esc_html_e( 'Νέα κατάσταση', 'easy-withdrawal' ); ?></label>
				<select id="ewd-st-<?php echo esc_attr( (string) $r['id'] ); ?>" name="ewd_status">
					<?php foreach ( EWD_Requests::status_labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<textarea name="ewd_note" rows="2" placeholder="<?php esc_attr_e( 'Σημείωση (υποχρεωτική για την αμφισβήτηση: τη λαμβάνει ο πελάτης)', 'easy-withdrawal' ); ?>"></textarea>
				<button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'easy-withdrawal' ); ?></button>
				<span class="ewd-hint"><?php esc_html_e( 'Τα «Ολοκληρώθηκε» και «Αμφισβητείται» στέλνουν email στον πελάτη.', 'easy-withdrawal' ); ?></span>
			</form>

			<details class="ewd-details">
				<summary><?php esc_html_e( 'Ιστορικό', 'easy-withdrawal' ); ?></summary>
				<ul class="ewd-log">
					<?php foreach ( (array) $r['log'] as $l ) : ?>
						<?php
						$uid = (int) ( $l['user'] ?? 0 );
						if ( $uid && ! isset( $users[ $uid ] ) ) {
							$u             = get_userdata( $uid );
							$users[ $uid ] = $u ? $u->display_name : '#' . $uid;
						}
						$who = $uid ? $users[ $uid ] : __( 'Επισκέπτης', 'easy-withdrawal' );
						?>
						<li>
							<?php echo esc_html( EWD_Lang::datetime( (int) ( $l['t'] ?? 0 ) ) . ' · ' . EWD_Requests::status_label( (string) ( $l['status'] ?? '' ) ) . ' · ' . $who ); ?>
							<?php if ( '' !== (string) ( $l['note'] ?? '' ) ) : ?>
								<br /><span class="ewd-muted"><?php echo nl2br( esc_html( (string) $l['note'] ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
		<?php
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'easy-withdrawal' ) );
		}

		$notices = self::take_msgs();
		$s       = EWD_Settings::get();
		$cats    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$cats    = is_array( $cats ) ? $cats : array();
		?>
		<div class="wrap ewd-wrap">
			<h1><?php esc_html_e( 'Easy Withdrawal — Ρυθμίσεις', 'easy-withdrawal' ); ?></h1>

			<?php self::print_notices( $notices ); ?>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="ewd_action" value="save_settings" />

				<h2 class="ewd-h2"><?php esc_html_e( 'Λειτουργία', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone">
					<label class="ewd-check"><input type="radio" name="ewd_mode" value="test" <?php checked( $s['mode'], 'test' ); ?> /> <?php esc_html_e( 'Λειτουργία δοκιμής: η φόρμα, τα κουμπιά και η συναίνεση στο checkout φαίνονται μόνο στους διαχειριστές του καταστήματος', 'easy-withdrawal' ); ?></label>
					<label class="ewd-check"><input type="radio" name="ewd_mode" value="live" <?php checked( $s['mode'], 'live' ); ?> /> <?php esc_html_e( 'Ενεργό για όλους τους πελάτες', 'easy-withdrawal' ); ?></label>
					<label class="ewd-check"><input type="radio" name="ewd_mode" value="off" <?php checked( $s['mode'], 'off' ); ?> /> <?php esc_html_e( 'Ανενεργό', 'easy-withdrawal' ); ?></label>
					<p class="description">
						<?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'easy-withdrawal' ); ?>
						<code>define( 'EWD_DISABLE', true );</code>
					</p>
				</div>

				<h2 class="ewd-h2"><?php esc_html_e( 'Σελίδα φόρμας', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone">
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'ewd_page_id',
							'selected'          => (int) $s['page_id'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- int.
							'show_option_none'  => esc_html__( '— Καμία —', 'easy-withdrawal' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
							'option_none_value' => '0',
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Η σελίδα πρέπει να περιέχει το shortcode [nox_withdrawal]. Τα κουμπιά του λογαριασμού και οι σύνδεσμοι των emails οδηγούν εδώ. Βάλε έναν σύνδεσμο προς αυτή τη σελίδα στο μενού ή στο footer του site, ώστε η φόρμα να είναι εύκολα προσβάσιμη.', 'easy-withdrawal' ); ?></p>
				</div>

				<h2 class="ewd-h2"><?php esc_html_e( 'Προθεσμία', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone ewd-grid">
					<div>
						<label class="ewd-label" for="ewd-days"><?php esc_html_e( 'Ημέρες υπαναχώρησης', 'easy-withdrawal' ); ?></label>
						<input type="number" id="ewd-days" name="ewd_days" min="14" max="365" value="<?php echo (int) $s['days']; ?>" class="ewd-num" />
						<span class="ewd-hint"><?php esc_html_e( 'Τουλάχιστον 14 (Οδηγία 2011/83).', 'easy-withdrawal' ); ?></span>
					</div>
					<div>
						<label class="ewd-label" for="ewd-grace"><?php esc_html_e( 'Ημέρες παράδοσης μετά το «Ολοκληρώθηκε»', 'easy-withdrawal' ); ?></label>
						<input type="number" id="ewd-grace" name="ewd_grace" min="0" max="60" value="<?php echo (int) $s['grace']; ?>" class="ewd-num" />
						<span class="ewd-hint"><?php esc_html_e( 'Η προθεσμία μετρά από την παραλαβή, που το κατάστημα δεν γνωρίζει. Για αγαθά η φόρμα μένει ανοιχτή από τη δημιουργία της παραγγελίας μέχρι «Ολοκληρώθηκε» + παράδοση + ημέρες υπαναχώρησης. Για εικονικά προϊόντα: ημέρες υπαναχώρησης από την πληρωμή.', 'easy-withdrawal' ); ?></span>
					</div>
				</div>

				<h2 class="ewd-h2"><?php esc_html_e( 'Κείμενα', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone ewd-grid">
					<div>
						<label class="ewd-label" for="ewd-label-el"><?php esc_html_e( 'Κουμπί και σύνδεσμοι (Ελληνικά)', 'easy-withdrawal' ); ?></label>
						<input type="text" id="ewd-label-el" name="ewd_label_el" class="ewd-wide" value="<?php echo esc_attr( $s['label_el'] ); ?>" />
						<label class="ewd-label" for="ewd-instr-el"><?php esc_html_e( 'Οδηγίες επιστροφής (Ελληνικά)', 'easy-withdrawal' ); ?></label>
						<textarea id="ewd-instr-el" name="ewd_instr_el" rows="4" class="ewd-wide"><?php echo esc_textarea( $s['instr_el'] ); ?></textarea>
					</div>
					<div>
						<label class="ewd-label" for="ewd-label-en"><?php esc_html_e( 'Κουμπί και σύνδεσμοι (Αγγλικά)', 'easy-withdrawal' ); ?></label>
						<input type="text" id="ewd-label-en" name="ewd_label_en" class="ewd-wide" value="<?php echo esc_attr( $s['label_en'] ); ?>" />
						<label class="ewd-label" for="ewd-instr-en"><?php esc_html_e( 'Οδηγίες επιστροφής (Αγγλικά)', 'easy-withdrawal' ); ?></label>
						<textarea id="ewd-instr-en" name="ewd_instr_en" rows="4" class="ewd-wide"><?php echo esc_textarea( $s['instr_en'] ); ?></textarea>
					</div>
				</div>
				<p class="description"><?php esc_html_e( 'Η Οδηγία ζητά ετικέτα σαν «Υπαναχώρηση από τη σύμβαση εδώ» ή εξίσου σαφή. Οι οδηγίες επιστροφής (διεύθυνση, τρόπος αποστολής, προθεσμία 14 ημερών για την επιστροφή των προϊόντων) μπαίνουν στην απόδειξη και στη σελίδα επιβεβαίωσης.', 'easy-withdrawal' ); ?></p>

				<h2 class="ewd-h2"><?php esc_html_e( 'Ειδοποιήσεις', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone">
					<label class="ewd-label" for="ewd-rcpt"><?php esc_html_e( 'Παραλήπτες της ειδοποίησης για νέα δήλωση', 'easy-withdrawal' ); ?></label>
					<input type="text" id="ewd-rcpt" name="ewd_recipients" class="ewd-wide" value="<?php echo esc_attr( implode( ', ', $s['recipients'] ) ); ?>" placeholder="<?php echo esc_attr( implode( ', ', EWD_Settings::recipients() ) ); ?>" />
					<span class="ewd-hint"><?php esc_html_e( 'Χωρισμένοι με κόμμα, έως 10. Κενό = η διεύθυνση αποστολέα του WooCommerce.', 'easy-withdrawal' ); ?></span>
					<p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ); ?>"><?php esc_html_e( 'Θέμα, επικεφαλίδα και μορφή των emails: WooCommerce → Ρυθμίσεις → Emails', 'easy-withdrawal' ); ?></a></p>
				</div>

				<h2 class="ewd-h2"><?php esc_html_e( 'Εξαιρέσεις', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone">
					<p class="description"><?php esc_html_e( 'Τα εξαιρούμενα προϊόντα φαίνονται στη φόρμα γκρι, με την αιτία. Για εξατομικευμένα προϊόντα υπάρχει το πεδίο «Εξατομικευμένο» στη σελίδα κάθε προϊόντος (Γενικά).', 'easy-withdrawal' ); ?></p>
					<label class="ewd-label"><?php esc_html_e( 'Κατηγορίες (μαζί με τις υποκατηγορίες τους)', 'easy-withdrawal' ); ?></label>
					<div class="ewd-scroll">
						<?php foreach ( $cats as $c ) : ?>
							<label class="ewd-check"><input type="checkbox" name="ewd_excl_cats[]" value="<?php echo (int) $c->term_id; ?>" <?php checked( in_array( (int) $c->term_id, $s['excl_cats'], true ) ); ?> /> <?php echo esc_html( $c->name ); ?> <span class="ewd-muted">(<?php echo (int) $c->count; ?>)</span></label>
						<?php endforeach; ?>
						<?php if ( ! $cats ) : ?>
							<span class="ewd-muted"><?php esc_html_e( 'Δεν υπάρχουν κατηγορίες.', 'easy-withdrawal' ); ?></span>
						<?php endif; ?>
					</div>
					<label class="ewd-label" for="ewd-xp"><?php esc_html_e( 'Προϊόντα (IDs χωρισμένα με κόμμα)', 'easy-withdrawal' ); ?></label>
					<input type="text" id="ewd-xp" name="ewd_excl_products" class="ewd-wide" value="<?php echo esc_attr( implode( ', ', $s['excl_products'] ) ); ?>" />
					<?php if ( $s['excl_products'] ) : ?>
						<span class="ewd-hint">
							<?php
							$names = array();
							foreach ( $s['excl_products'] as $pid ) {
								$p       = wc_get_product( $pid );
								$names[] = $p ? '#' . $pid . ' ' . $p->get_name() : '#' . $pid . ' ' . __( '(δεν βρέθηκε)', 'easy-withdrawal' );
							}
							echo esc_html( implode( ' · ', $names ) );
							?>
						</span>
					<?php endif; ?>

					<label class="ewd-label"><?php esc_html_e( 'Ψηφιακό περιεχόμενο (εικονικά ή με λήψη αρχείου)', 'easy-withdrawal' ); ?></label>
					<label class="ewd-check"><input type="checkbox" name="ewd_consent" value="1" <?php checked( $s['consent'] ); ?> /> <?php esc_html_e( 'Ζήτα στο checkout τη συναίνεση του πελάτη και εξαίρεσε τα ψηφιακά προϊόντα των παραγγελιών όπου δόθηκε', 'easy-withdrawal' ); ?></label>
					<p class="description"><?php esc_html_e( 'Χωρίς τη συναίνεση, τα ψηφιακά προϊόντα μένουν επιστρέψιμα: η εξαίρεση του νόμου ισχύει μόνο όταν ο πελάτης ζήτησε άμεση παράδοση και αναγνώρισε ότι χάνει το δικαίωμα. Δουλεύει στο κλασικό checkout και στο checkout block (WooCommerce 9.9+).', 'easy-withdrawal' ); ?></p>
					<div class="ewd-grid">
						<div>
							<label class="ewd-label" for="ewd-c-el"><?php esc_html_e( 'Κείμενο συναίνεσης (Ελληνικά)', 'easy-withdrawal' ); ?></label>
							<textarea id="ewd-c-el" name="ewd_consent_el" rows="3" class="ewd-wide"><?php echo esc_textarea( $s['consent_el'] ); ?></textarea>
						</div>
						<div>
							<label class="ewd-label" for="ewd-c-en"><?php esc_html_e( 'Κείμενο συναίνεσης (Αγγλικά)', 'easy-withdrawal' ); ?></label>
							<textarea id="ewd-c-en" name="ewd_consent_en" rows="3" class="ewd-wide"><?php echo esc_textarea( $s['consent_en'] ); ?></textarea>
						</div>
					</div>
				</div>

				<h2 class="ewd-h2"><?php esc_html_e( 'Σύνδεσμοι', 'easy-withdrawal' ); ?></h2>
				<div class="ewd-zone">
					<label class="ewd-check"><input type="checkbox" name="ewd_email_links" value="1" <?php checked( $s['email_links'] ); ?> /> <?php esc_html_e( 'Σύνδεσμος υπαναχώρησης στα emails παραγγελίας του πελάτη και στη σελίδα ευχαριστίας', 'easy-withdrawal' ); ?></label>
					<label class="ewd-check"><input type="checkbox" name="ewd_footer_link" value="1" <?php checked( $s['footer_link'] ); ?> /> <?php esc_html_e( 'Αυτόματος σύνδεσμος στο τέλος κάθε σελίδας (wp_footer)', 'easy-withdrawal' ); ?></label>
					<p class="description"><?php esc_html_e( 'Ο αυτόματος σύνδεσμος βγαίνει όπου το θέμα τυπώνει το wp_footer, σε κάποια θέματα κάτω από το footer. Ένας σύνδεσμος στο μενού του footer είναι συνήθως καλύτερος.', 'easy-withdrawal' ); ?></p>
				</div>

				<p class="ewd-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'easy-withdrawal' ); ?></button>
				</p>
			</form>

			<h2 class="ewd-h2"><?php esc_html_e( 'Νέα σελίδα φόρμας', 'easy-withdrawal' ); ?></h2>
			<form method="post" class="ewd-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="ewd_action" value="create_page" />
				<button type="submit" class="button"><?php esc_html_e( 'Δημιουργία σελίδας με το shortcode', 'easy-withdrawal' ); ?></button>
			</form>
			<p class="description ewd-mt-8"><?php esc_html_e( 'Δημιουργεί μια δημοσιευμένη σελίδα «Υπαναχώρηση» με το [nox_withdrawal] και την ορίζει ως σελίδα φόρμας.', 'easy-withdrawal' ); ?></p>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Order screen metabox
	 * =================================================================== */

	public static function metabox( $post_type, $post_or_order = null ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : ( $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof WC_Order || ! EWD_Requests::for_order( $order ) ) {
			return;
		}
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'ewd-requests', __( 'Υπαναχωρήσεις', 'easy-withdrawal' ), array( __CLASS__, 'render_metabox' ), $screen, 'side', 'high' );
	}

	public static function render_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : ( $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		echo '<ul class="ewd-mb">';
		foreach ( array_reverse( EWD_Requests::for_order( $order ) ) as $r ) {
			echo '<li><strong>' . esc_html( (string) $r['id'] ) . '</strong> · ' . esc_html( EWD_Requests::status_label( (string) $r['status'] ) ) . '<br />';
			echo '<span>' . esc_html( EWD_Lang::datetime( (int) $r['created'] ) ) . '</span><br />';
			echo '<span>' . esc_html( EWD_Requests::items_text( $r['items'] ) ) . '</span><br />';
			echo '<a href="' . esc_url( add_query_arg( 'ewd_refund', rawurlencode( (string) $r['id'] ), $order->get_edit_order_url() ) ) . '">' . esc_html__( 'Επιστροφή χρημάτων', 'easy-withdrawal' ) . '</a>';
			echo '</li>';
		}
		echo '</ul>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&order=' . $order->get_id() ) ) . '">' . esc_html__( 'Διαχείριση αιτημάτων', 'easy-withdrawal' ) . '</a></p>';
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {

		$page = self::current_page();
		if ( self::SLUG_MAIN !== $page && self::SLUG_SET !== $page ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['ewd_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'easy-withdrawal' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['ewd_action'] ) );
		$back   = $page;

		switch ( $action ) {
			case 'save_settings':
				$msgs = self::do_save_settings();
				break;
			case 'create_page':
				$msgs = self::do_create_page();
				break;
			case 'set_status':
				list( $msgs, $back ) = self::do_set_status();
				break;
			default:
				$msgs = array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'easy-withdrawal' ) ) );
		}

		self::redirect( $back, $msgs );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	/** @param string $target Page slug plus an optional, already safe query tail. */
	private static function redirect( string $target, array $msgs ): void {
		set_transient( 'ewd_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $target ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (callers sanitize). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by EWD_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save_settings(): array {
		$raw = array(
			'mode'          => sanitize_key( self::post( 'ewd_mode' ) ),
			'page_id'       => (int) self::post( 'ewd_page_id' ),
			'days'          => (int) self::post( 'ewd_days' ),
			'grace'         => (int) self::post( 'ewd_grace' ),
			'label_el'      => self::post( 'ewd_label_el' ),
			'label_en'      => self::post( 'ewd_label_en' ),
			'instr_el'      => self::post( 'ewd_instr_el' ),
			'instr_en'      => self::post( 'ewd_instr_en' ),
			'recipients'    => self::post( 'ewd_recipients' ),
			'excl_cats'     => self::post_array( 'ewd_excl_cats' ),
			'excl_products' => self::post( 'ewd_excl_products' ),
			'consent'       => '1' === self::post( 'ewd_consent' ),
			'consent_el'    => self::post( 'ewd_consent_el' ),
			'consent_en'    => self::post( 'ewd_consent_en' ),
			'email_links'   => '1' === self::post( 'ewd_email_links' ),
			'footer_link'   => '1' === self::post( 'ewd_footer_link' ),
		);
		EWD_Settings::save( $raw );
		$s    = EWD_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'easy-withdrawal' ) ) );

		$typed = (int) self::post( 'ewd_days' );
		if ( $typed > 0 && $typed !== (int) $s['days'] ) {
			$msgs[] = self::msg( 'warning', __( 'Οι ημέρες υπαναχώρησης είναι από 14 έως 365: η τιμή διορθώθηκε.', 'easy-withdrawal' ) );
		}
		if ( 'live' === $s['mode'] && '' === EWD_Settings::page_url() ) {
			$msgs[] = self::msg( 'warning', __( 'Το plugin είναι ενεργό αλλά δεν έχει οριστεί δημοσιευμένη σελίδα φόρμας: τα κουμπιά και οι σύνδεσμοι δεν εμφανίζονται.', 'easy-withdrawal' ) );
		}
		$raw_rcpt = trim( self::post( 'ewd_recipients' ) );
		if ( '' !== $raw_rcpt && count( $s['recipients'] ) < count( array_filter( preg_split( '/[\s,;]+/', $raw_rcpt ) ) ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποιες διευθύνσεις παραληπτών δεν ήταν έγκυρες και αγνοήθηκαν.', 'easy-withdrawal' ) );
		}
		return $msgs;
	}

	private static function do_create_page(): array {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return array( self::msg( 'error', __( 'Χρειάζεται δικαίωμα δημοσίευσης σελίδων.', 'easy-withdrawal' ) ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'el' === EWD_Lang::site() ? 'Υπαναχώρηση' : 'Withdrawal',
				'post_content' => '<!-- wp:shortcode -->[nox_withdrawal]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return array( self::msg( 'error', $id->get_error_message() ) );
		}
		$s            = EWD_Settings::get();
		$s['page_id'] = (int) $id;
		EWD_Settings::save( $s );
		return array( self::msg( 'success', __( 'Η σελίδα δημιουργήθηκε και ορίστηκε ως σελίδα φόρμας.', 'easy-withdrawal' ) ) );
	}

	private static function do_set_status(): array {
		$oid   = (int) self::post( 'ewd_order' );
		$order = wc_get_order( $oid );
		$back  = self::SLUG_MAIN . ( $oid > 0 ? '&order=' . $oid : '' );
		if ( ! $order instanceof WC_Order ) {
			return array( array( self::msg( 'error', __( 'Το αίτημα δεν βρέθηκε.', 'easy-withdrawal' ) ) ), self::SLUG_MAIN );
		}
		$res = EWD_Requests::set_status(
			$order,
			sanitize_text_field( self::post( 'ewd_req' ) ),
			sanitize_key( self::post( 'ewd_status' ) ),
			sanitize_textarea_field( self::post( 'ewd_note' ) )
		);
		if ( is_wp_error( $res ) ) {
			return array( array( self::msg( 'error', $res->get_error_message() ) ), $back );
		}
		return array( array( self::msg( 'success', __( 'Η κατάσταση αποθηκεύτηκε.', 'easy-withdrawal' ) ) ), $back );
	}

	/* =====================================================================
	 * CSV export (privileged GET: slug + nonce + capability)
	 * =================================================================== */

	public static function route_csv(): void {
		if ( self::SLUG_MAIN !== self::current_page() || '' === self::q( 'ewd_csv' ) ) {
			return;
		}
		$nonce = self::q( '_wpnonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_CSV ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'easy-withdrawal' ) );
		}
		$state = self::q( 'state' );
		$args  = array(
			'state'    => in_array( $state, EWD_Requests::STATUSES, true ) ? $state : '',
			'search'   => self::q( 's' ),
			'per_page' => 200,
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="withdrawals-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for Excel.
		fputcsv( $out, array( 'request', 'submitted_utc', 'submitted_local', 'order', 'order_status', 'name', 'email', 'items', 'amount', 'currency', 'status', 'reason', 'lang' ) );
		$page = 1;
		do {
			$args['page'] = $page;
			$res          = EWD_Requests::query( $args );
			foreach ( $res['orders'] as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				foreach ( EWD_Requests::for_order( $order ) as $r ) {
					fputcsv(
						$out,
						array(
							self::csv_cell( (string) $r['id'] ),
							gmdate( 'Y-m-d H:i:s', (int) $r['created'] ),
							wp_date( 'Y-m-d H:i:s P', (int) $r['created'] ),
							self::csv_cell( (string) $order->get_order_number() ),
							$order->get_status(),
							self::csv_cell( (string) $r['name'] ),
							self::csv_cell( (string) $r['email'] ),
							self::csv_cell( EWD_Requests::items_text( $r['items'] ) ),
							wc_format_decimal( EWD_Requests::amount( $r ), wc_get_price_decimals() ),
							$order->get_currency(),
							(string) $r['status'],
							self::csv_cell( (string) $r['reason'] ),
							(string) $r['lang'],
						)
					);
				}
			}
			++$page;
		} while ( $page <= (int) ceil( $res['total'] / 200 ) && $page <= 100 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Neutralise spreadsheet formulas (CSV injection). */
	private static function csv_cell( string $v ): string {
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'ewd_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'ewd_aui_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	private static function print_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			if ( ! is_array( $n ) || ! isset( $n['type'], $n['text'] ) ) {
				continue;
			}
			$type = in_array( $n['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible inline"><p>' . esc_html( (string) $n['text'] ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="ewd-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'easy-withdrawal' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="ewd-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="ewd-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'easy-withdrawal' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="ewd-footer-cta">
			<a class="ewd-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'easy-withdrawal' ); ?>
			</a>
			<a class="ewd-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'easy-withdrawal' ); ?>
			</a>
		</p>
		<?php
	}
}
