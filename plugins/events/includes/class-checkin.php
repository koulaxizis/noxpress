<?php
/**
 * EVT_Checkin — entry at the door, the attendee list and the buyers'
 * notice (1.1.0, Π28–Π29).
 *
 *  - Role «Ταμείο εκδήλωσης» (evt_cashier): only `read` and `evt_checkin`.
 *    It gets its own top-level «Είσοδος» page and lands there after login.
 *    Shop managers (Events_Plugin::cap()) reach the same page under
 *    «Noxpress».
 *  - Check-in page (admin.php?page=evt-checkin), made for a phone: pick the
 *    event, scan the QR code with the camera (BarcodeDetector, where the
 *    browser has it) or type the code. The answer is «Έγκυρο», «Ήδη
 *    χρησιμοποιήθηκε στις 19:42», «Άλλη εκδήλωση» or «Άκυρο». A valid
 *    ticket is marked used atomically, so a second scan shows it as used.
 *    AJAX with nonce, capability and a limit of 120 checks a minute per user.
 *  - Attendee list (managers only): every ticket of the event with order,
 *    buyer, attendee name and entry time, print and CSV (cells that start a
 *    formula are prefixed), undo of a check-in.
 *  - «Ενημέρωσε τους αγοραστές»: one message to everyone with a valid
 *    ticket, through the WC email «Events: ενημέρωση εκδήλωσης» (off by
 *    default), in batches of 20 in the background, with an order note. No
 *    automatic refund: refunds stay manual on the order.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Checkin {

	const ROLE   = 'evt_cashier';
	const CAP    = 'evt_checkin';
	const SLUG   = 'evt-checkin';
	const NONCE  = 'evt_checkin';
	const BATCH  = 'evt_notify_batch';
	const PER    = 20;
	const LIMIT  = 120;

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'ensure_role' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 441 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_action( 'wp_ajax_evt_checkin', array( __CLASS__, 'ajax_check' ) );
		add_action( 'admin_post_evt_checkin_undo', array( __CLASS__, 'undo' ) );
		add_action( 'admin_post_evt_attendees_csv', array( __CLASS__, 'csv' ) );
		add_action( 'admin_post_evt_notify', array( __CLASS__, 'notify' ) );
		add_action( self::BATCH, array( __CLASS__, 'notify_batch' ), 10, 2 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'admin_access' ) );
	}

	/** WooCommerce keeps customers out of WP admin: the cashier needs the door page. */
	public static function admin_access( $prevent ) {
		return current_user_can( self::CAP ) ? false : $prevent;
	}

	/** «Συμμετέχοντες» under each event with tickets in the admin list. */
	public static function row_action( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();
		if ( $post instanceof WP_Post && EVT_CPT::PT === $post->post_type && self::manager() && (int) get_post_meta( $post->ID, '_evt_ticket', true ) > 0 ) {
			$actions['evt_attendees'] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&view=list&event=' . (int) $post->ID ) ) . '">' . esc_html__( 'Συμμετέχοντες', 'events' ) . '</a>';
		}
		return $actions;
	}

	/** The role (once per role version). Managers check in through Events_Plugin::cap(). */
	public static function ensure_role(): void {
		if ( (string) get_option( 'evt_role_v', '' ) === '1' ) {
			return;
		}
		remove_role( self::ROLE );
		add_role(
			self::ROLE,
			__( 'Ταμείο εκδήλωσης', 'events' ),
			array(
				'read'    => true,
				self::CAP => true,
			)
		);
		update_option( 'evt_role_v', '1', true );
	}

	public static function can(): bool {
		return current_user_can( self::CAP ) || current_user_can( Events_Plugin::cap() );
	}

	private static function manager(): bool {
		return current_user_can( Events_Plugin::cap() );
	}

	public static function menu(): void {
		if ( self::manager() ) {
			add_submenu_page( 'noxpress', __( 'Είσοδος εκδήλωσης', 'events' ), __( 'Εκδηλώσεις: είσοδος', 'events' ), Events_Plugin::cap(), self::SLUG, array( __CLASS__, 'render' ) );
		} elseif ( current_user_can( self::CAP ) ) {
			add_menu_page( __( 'Είσοδος εκδήλωσης', 'events' ), __( 'Είσοδος εκδήλωσης', 'events' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-tickets-alt', 2 );
		}
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, Events_Plugin::cap() ) ) {
			return admin_url( 'admin.php?page=' . self::SLUG );
		}
		return $to;
	}

	public static function assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing.
		if ( ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( (string) $_GET['page'] ) ) ) {
			return;
		}
		wp_enqueue_style( 'evt-checkin', EVT_URL . 'assets/checkin.css', array(), EVT_VERSION );
		wp_enqueue_script( 'evt-checkin', EVT_URL . 'assets/checkin.js', array(), EVT_VERSION, true );
		wp_localize_script(
			'evt-checkin',
			'evtCheckin',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'camera'  => __( 'Κάμερα', 'events' ),
				'stop'    => __( 'Σταμάτα την κάμερα', 'events' ),
				'nocam'   => __( 'Ο browser δεν διαβάζει QR από την κάμερα: πληκτρολόγησε τον κωδικό.', 'events' ),
				'error'   => __( 'Σφάλμα σύνδεσης. Δοκίμασε ξανά.', 'events' ),
			)
		);
	}

	/** Events with tickets from yesterday on (the door list). */
	private static function events(): array {
		$q = get_posts(
			array(
				'post_type'      => EVT_CPT::PT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery -- one post type, small set.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_evt_ticket',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_evt_end',
						'value'   => time() - DAY_IN_SECONDS,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
				'meta_key'       => '_evt_start', // phpcs:ignore WordPress.DB.SlowDBQuery -- order by start.
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);
		return $q;
	}

	private static function q( string $k ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $k ] ) && is_scalar( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) : '';
	}

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}
		$event = absint( self::q( 'event' ) );
		if ( 'list' === self::q( 'view' ) && self::manager() ) {
			self::render_list( $event );
			return;
		}
		$events = self::events();
		if ( ! $event && $events ) {
			$event = (int) $events[0]->ID;
		}
		$c = $event ? EVT_Ticket_Store::counts( $event ) : null;
		?>
		<div class="wrap evt-ci">
			<h1><?php esc_html_e( 'Είσοδος εκδήλωσης', 'events' ); ?></h1>
			<?php if ( ! $events ) : ?>
				<p><?php esc_html_e( 'Καμία εκδήλωση με εισιτήρια σήμερα ή αργότερα.', 'events' ); ?></p>
			<?php else : ?>
				<form method="get" class="evt-ci-pick">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<label><span class="screen-reader-text"><?php esc_html_e( 'Εκδήλωση', 'events' ); ?></span>
						<select name="event" onchange="this.form.submit()">
							<?php foreach ( $events as $p ) : ?>
								<?php $ev = EVT_CPT::event( (int) $p->ID, true ); ?>
								<option value="<?php echo (int) $p->ID; ?>" <?php selected( $event, (int) $p->ID ); ?>><?php echo esc_html( ( $ev ? wp_date( 'j/n H:i', $ev['start'] ) . ' · ' : '' ) . wp_strip_all_tags( get_the_title( $p ) ) ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<noscript><button class="button"><?php esc_html_e( 'Επιλογή', 'events' ); ?></button></noscript>
				</form>
				<div class="evt-ci-box" data-event="<?php echo (int) $event; ?>">
					<form class="evt-ci-form" autocomplete="off">
						<label for="evt-ci-code"><?php esc_html_e( 'Κωδικός εισιτηρίου', 'events' ); ?></label>
						<input type="text" id="evt-ci-code" inputmode="text" autocapitalize="characters" spellcheck="false" maxlength="14" placeholder="ABCDE-FGHJK" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Έλεγχος', 'events' ); ?></button>
						<button type="button" class="button evt-ci-cam" hidden><?php esc_html_e( 'Κάμερα', 'events' ); ?></button>
					</form>
					<video class="evt-ci-video" playsinline muted hidden></video>
					<div class="evt-ci-result" role="status" aria-live="assertive"></div>
					<p class="evt-ci-counts">
						<?php
						if ( $c ) {
							/* translators: 1: tickets checked in, 2: valid tickets */
							echo esc_html( sprintf( __( 'Μέσα: %1$d από %2$d', 'events' ), $c['used'], $c['valid'] ) );
						}
						?>
					</p>
				</div>
				<?php if ( self::manager() && $event ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&view=list&event=' . $event ) ); ?>"><?php esc_html_e( 'Λίστα συμμετεχόντων', 'events' ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Check (AJAX)
	 * =================================================================== */

	public static function ajax_check(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! self::can() ) {
			wp_send_json_error( array( 'msg' => __( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) ), 403 );
		}
		$uid = get_current_user_id();
		$key = 'evt_ci_' . $uid . '_' . gmdate( 'YmdHi' );
		$n   = (int) get_transient( $key );
		if ( $n >= self::LIMIT ) {
			wp_send_json_error( array( 'msg' => __( 'Πολλοί έλεγχοι σε ένα λεπτό. Περίμενε λίγο.', 'events' ) ), 429 );
		}
		set_transient( $key, $n + 1, 2 * MINUTE_IN_SECONDS );

		$code  = isset( $_POST['code'] ) && is_scalar( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
		$event = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$t     = EVT_Ticket_Store::by_code( $code );
		$out   = array( 'state' => 'invalid' );
		if ( ! $t ) {
			$out['msg'] = __( 'Άκυρο: δεν υπάρχει τέτοιο εισιτήριο.', 'events' );
		} elseif ( 'void' === $t['status'] ) {
			$out = array(
				'state' => 'void',
				'msg'   => __( 'Άκυρο: το εισιτήριο ακυρώθηκε (επιστροφή ή ακύρωση παραγγελίας).', 'events' ),
			);
		} elseif ( $event && $t['event_id'] !== $event ) {
			$out = array(
				'state' => 'other',
				/* translators: %s: event title */
				'msg'   => sprintf( __( 'Άλλη εκδήλωση: %s', 'events' ), wp_strip_all_tags( get_the_title( $t['event_id'] ) ) ),
			);
		} elseif ( EVT_Ticket_Store::use_ticket( $t['id'], $uid ) ) {
			$out = array(
				'state' => 'ok',
				'msg'   => __( 'Έγκυρο: καλή διασκέδαση!', 'events' ),
			);
		} else {
			$t   = EVT_Ticket_Store::get( $t['id'] );
			$at  = $t && $t['used'] ? wp_date( (string) get_option( 'time_format' ), strtotime( $t['used'] . ' UTC' ) ) : '';
			$out = array(
				'state' => 'used',
				/* translators: %s: time of the first entry */
				'msg'   => sprintf( __( 'Ήδη χρησιμοποιήθηκε στις %s', 'events' ), $at ),
			);
		}
		if ( $t && in_array( $out['state'], array( 'ok', 'used' ), true ) ) {
			$out['name'] = (string) $t['name'];
			$c           = EVT_Ticket_Store::counts( $t['event_id'] );
			/* translators: 1: tickets checked in, 2: valid tickets */
			$out['counts'] = sprintf( __( 'Μέσα: %1$d από %2$d', 'events' ), $c['used'], $c['valid'] );
		}
		wp_send_json_success( $out );
	}

	/* =====================================================================
	 * Attendee list (managers)
	 * =================================================================== */

	private static function render_list( int $event ): void {
		$e = $event ? EVT_CPT::event( $event, true ) : null;
		if ( ! $e ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Δεν βρέθηκαν εκδηλώσεις.', 'events' ) . '</p></div>';
			return;
		}
		$rows  = EVT_Ticket_Store::for_event( $event );
		$c     = EVT_Ticket_Store::counts( $event );
		$p     = EVT_Tickets::product_of( $event );
		$on    = class_exists( 'WC_Emails' ) && self::email_on();
		$msg   = self::q( 'evt_msg' );
		$notes = array(
			'sent'  => array( 'success', __( 'Η ενημέρωση φεύγει στο παρασκήνιο, σε παρτίδες.', 'events' ) ),
			'off'   => array( 'error', __( 'Το email «Events: ενημέρωση εκδήλωσης» είναι κλειστό στο WooCommerce → Ρυθμίσεις → Emails.', 'events' ) ),
			'empty' => array( 'error', __( 'Γράψε ένα μήνυμα.', 'events' ) ),
			'undo'  => array( 'success', __( 'Η είσοδος αναιρέθηκε.', 'events' ) ),
		);
		?>
		<div class="wrap evt-ci evt-ci--list">
			<h1><?php echo esc_html( $e['title'] ); ?></h1>
			<p><?php echo esc_html( EVT_CPT::when( $e ) ); ?>
				<?php
				/* translators: 1: valid tickets, 2: checked in, 3: cancelled */
				echo ' · ' . esc_html( sprintf( __( 'Έγκυρα: %1$d · Μέσα: %2$d · Ακυρωμένα: %3$d', 'events' ), $c['valid'], $c['used'], $c['void'] ) );
				if ( $p && $p->managing_stock() ) {
					/* translators: %d: seats left */
					echo ' · ' . esc_html( sprintf( __( 'Ελεύθερες θέσεις: %d', 'events' ), (int) $p->get_stock_quantity() ) );
				}
				?>
			</p>
			<?php if ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notes[ $msg ][0] ); ?> inline"><p><?php echo esc_html( $notes[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p class="evt-noprint">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&event=' . $event ) ); ?>"><?php esc_html_e( 'Είσοδος', 'events' ); ?></a>
				<button type="button" class="button" onclick="window.print()"><?php esc_html_e( 'Εκτύπωση', 'events' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=evt_attendees_csv&event=' . $event ), 'evt_csv_' . $event ) ); ?>"><?php esc_html_e( 'CSV', 'events' ); ?></a>
				<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $event ) ); ?>"><?php esc_html_e( 'Επεξεργασία εκδήλωσης', 'events' ); ?></a>
			</p>
			<table class="widefat striped evt-att">
				<thead><tr>
					<th>#</th><th><?php esc_html_e( 'Κωδικός', 'events' ); ?></th><th><?php esc_html_e( 'Παραγγελία', 'events' ); ?></th><th><?php esc_html_e( 'Αγοραστής', 'events' ); ?></th><th><?php esc_html_e( 'Όνομα συμμετέχοντα', 'events' ); ?></th><th><?php esc_html_e( 'Κατάσταση', 'events' ); ?></th><th class="evt-noprint"></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Κανένα εισιτήριο ακόμα.', 'events' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $i => $r ) : ?>
					<?php $o = wc_get_order( $r['order_id'] ); ?>
					<tr>
						<td><?php echo (int) ( $i + 1 ); ?></td>
						<td><code><?php echo esc_html( EVT_Ticket_Store::pretty( EVT_Ticket_Store::code_for( $r ) ) ); ?></code></td>
						<td><?php echo $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '#' . (int) $r['order_id']; ?></td>
						<td><?php echo esc_html( $o ? trim( $o->get_formatted_billing_full_name() ) : '' ); ?></td>
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td>
							<?php
							if ( 'void' === $r['status'] ) {
								esc_html_e( 'Ακυρώθηκε', 'events' );
							} elseif ( $r['used'] ) {
								/* translators: %s: time */
								echo esc_html( sprintf( __( 'Μέσα από τις %s', 'events' ), wp_date( 'j/n H:i', strtotime( $r['used'] . ' UTC' ) ) ) );
							} else {
								esc_html_e( 'Έγκυρο', 'events' );
							}
							?>
						</td>
						<td class="evt-noprint">
							<?php if ( 'valid' === $r['status'] && $r['used'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="evt_checkin_undo" />
									<input type="hidden" name="ticket" value="<?php echo (int) $r['id']; ?>" />
									<?php wp_nonce_field( 'evt_undo_' . $r['id'] ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( 'Αναίρεση εισόδου', 'events' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 class="evt-noprint"><?php esc_html_e( 'Ενημέρωσε τους αγοραστές', 'events' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="evt-noprint evt-notify">
				<input type="hidden" name="action" value="evt_notify" />
				<input type="hidden" name="event" value="<?php echo (int) $event; ?>" />
				<?php wp_nonce_field( 'evt_notify_' . $event ); ?>
				<textarea name="message" rows="5" maxlength="2000" class="large-text" placeholder="<?php esc_attr_e( 'π.χ. Η εκδήλωση μεταφέρεται στις 20:30. Τα εισιτήρια ισχύουν κανονικά.', 'events' ); ?>"></textarea>
				<p class="description"><?php esc_html_e( 'Ένα email σε κάθε παραγγελία με έγκυρο εισιτήριο, με σημείωση στην παραγγελία. Οι επιστροφές χρημάτων γίνονται από την παραγγελία.', 'events' ); ?>
					<?php if ( ! $on ) : ?><strong><?php esc_html_e( 'Το email «Events: ενημέρωση εκδήλωσης» είναι κλειστό στο WooCommerce → Ρυθμίσεις → Emails.', 'events' ); ?></strong><?php endif; ?></p>
				<?php
				$last = get_post_meta( $event, '_evt_notice', true );
				if ( is_array( $last ) && ! empty( $last['time'] ) ) {
					/* translators: %s: date and time */
					echo '<p class="description">' . esc_html( sprintf( __( 'Τελευταία ενημέρωση: %s', 'events' ), wp_date( 'j/n/Y H:i', (int) $last['time'] ) ) ) . '</p>';
				}
				?>
				<button type="submit" class="button button-primary" onclick="return confirm(this.dataset.q)" data-q="<?php esc_attr_e( 'Να σταλεί η ενημέρωση σε όλους τους αγοραστές;', 'events' ); ?>"><?php esc_html_e( 'Αποστολή', 'events' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function back( int $event, string $msg ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&view=list&event=' . $event . '&evt_msg=' . $msg ) );
		exit;
	}

	public static function undo(): void {
		$id = isset( $_POST['ticket'] ) ? absint( $_POST['ticket'] ) : 0;
		if ( ! self::manager() || ! check_admin_referer( 'evt_undo_' . $id ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}
		$t = EVT_Ticket_Store::get( $id );
		if ( $t ) {
			EVT_Ticket_Store::unuse( $id );
		}
		self::back( $t ? $t['event_id'] : 0, 'undo' );
	}

	/** A CSV cell that cannot start a formula in a spreadsheet. */
	private static function cell( string $v ): string {
		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	}

	public static function csv(): void {
		$event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		if ( ! self::manager() || ! check_admin_referer( 'evt_csv_' . $event ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="attendees-' . $event . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CSV stream.
		$head = array( __( 'Κωδικός', 'events' ), __( 'Παραγγελία', 'events' ), __( 'Αγοραστής', 'events' ), __( 'Email', 'events' ), __( 'Όνομα συμμετέχοντα', 'events' ), __( 'Κατάσταση', 'events' ), __( 'Είσοδος', 'events' ) );
		fputcsv( $out, $head, ',', '"', '' );
		foreach ( EVT_Ticket_Store::for_event( $event ) as $r ) {
			$o = wc_get_order( $r['order_id'] );
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'cell' ),
					array(
						EVT_Ticket_Store::pretty( EVT_Ticket_Store::code_for( $r ) ),
						$o ? (string) $o->get_order_number() : (string) $r['order_id'],
						$o ? trim( $o->get_formatted_billing_full_name() ) : '',
						$o ? (string) $o->get_billing_email() : '',
						(string) $r['name'],
						(string) $r['status'],
						$r['used'] ? wp_date( 'Y-m-d H:i', strtotime( $r['used'] . ' UTC' ) ) : '',
					)
				),
				',',
				'"',
				''
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CSV stream.
		exit;
	}

	/* =====================================================================
	 * Buyers' notice (Π29)
	 * =================================================================== */

	public static function email_class( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		if ( class_exists( 'WC_Email' ) ) {
			require_once EVT_PATH . 'includes/class-email-update.php';
			$emails['EVT_Email_Update'] = new EVT_Email_Update();
		}
		return $emails;
	}

	private static function email_on(): bool {
		$s = get_option( 'woocommerce_evt_update_settings', array() );
		return is_array( $s ) && isset( $s['enabled'] ) && 'yes' === $s['enabled'];
	}

	public static function notify(): void {
		$event = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		if ( ! self::manager() || ! check_admin_referer( 'evt_notify_' . $event ) || EVT_CPT::PT !== get_post_type( $event ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}
		$text = isset( $_POST['message'] ) && is_scalar( $_POST['message'] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) ), 0, 2000 ) : '';
		if ( '' === $text ) {
			self::back( $event, 'empty' );
		}
		if ( ! self::email_on() ) {
			self::back( $event, 'off' );
		}
		$n = array(
			'id'   => wp_generate_password( 12, false ),
			'text' => $text,
			'time' => time(),
			'by'   => get_current_user_id(),
		);
		update_post_meta( $event, '_evt_notice', $n );
		self::schedule( $event, 0 );
		self::back( $event, 'sent' );
	}

	private static function schedule( int $event, int $after ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5, self::BATCH, array( $event, $after ), 'events' );
		} else {
			wp_schedule_single_event( time() + 5, self::BATCH, array( $event, $after ) );
		}
	}

	public static function notify_batch( $event, $after ): void {
		$event = (int) $event;
		$n     = get_post_meta( $event, '_evt_notice', true );
		if ( ! is_array( $n ) || empty( $n['text'] ) || ! self::email_on() || ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$mail   = $emails['EVT_Email_Update'] ?? null;
		if ( ! $mail ) {
			return;
		}
		$ids = EVT_Ticket_Store::orders_of_event( $event, (int) $after, self::PER );
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || (string) $order->get_meta( '_evt_notice_' . $event ) === (string) $n['id'] ) {
				continue; // Already got this notice (a job that ran twice).
			}
			$mail->trigger( $order, $event, (string) $n['text'] );
			$order->update_meta_data( '_evt_notice_' . $event, (string) $n['id'] );
			/* translators: %s: event title */
			$order->add_order_note( sprintf( __( 'Events: στάλθηκε ενημέρωση για «%s».', 'events' ), wp_strip_all_tags( get_the_title( $event ) ) ) );
			$order->save();
		}
		if ( count( $ids ) === self::PER ) {
			self::schedule( $event, (int) end( $ids ) );
		}
	}
}
