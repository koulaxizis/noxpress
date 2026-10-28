<?php
/**
 * LOP_Admin_UI — menu, settings page, status checks (Bible §3–§8, §11).
 *
 * Submenu «Live Operator» of the shared "Noxpress" menu (admin_menu
 * priority 250), slug lop-settings, capability manage_options. One page:
 *  - status   PurioChat Pro and its Live Chat, availability mode, waiting
 *             time, the switch, push support, this user's devices, links
 *  - settings the switch, automatic offline, inbox, preview, app name
 *  - schedule the weekly schedule, closed dates (1.3.0)
 *  - android  package and certificate fingerprints for assetlinks.json
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient lop_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Admin_UI {

	const CAP         = 'manage_options';
	const SLUG_MENU   = 'noxpress';
	const NONCE       = 'lop_admin';
	const NONCE_FIELD = 'lop_nonce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 250 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( 'LOP_Schedule', 'ensure' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'purio_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LOP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Live Operator', 'live-operator' ),
			__( 'Live Operator', 'live-operator' ),
			self::CAP,
			LOP_Settings::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . LOP_Settings::PAGE ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'live-operator' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	public static function assets(): void {
		if ( LOP_Settings::PAGE !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'lop-admin', LOP_URL . 'assets/admin.css', array(), LOP_VERSION );
		wp_enqueue_script( 'lop-admin', LOP_URL . 'assets/admin.js', array(), LOP_VERSION, true );
	}

	/** PurioChat Pro is needed: say so on the plugins screen and our page. */
	public static function purio_notice(): void {
		if ( LOP_Purio::present() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( LOP_Settings::PAGE !== self::current_page() && ( ! $screen || 'plugins' !== $screen->id ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Το Live Operator χρειάζεται το PurioChat Pro με ενεργό το Live Chat. Χωρίς αυτό δεν κάνει τίποτα.', 'live-operator' )
			. '</p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'live-operator' ) );
		}
		?>
		<div class="wrap lop-wrap">
			<h1><?php esc_html_e( 'Live Operator', 'live-operator' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Το live chat του PurioChat στο κινητό: διακόπτης online / offline, ειδοποιήσεις για κάθε αίτημα και μήνυμα, και απαντήσεις από την εφαρμογή.', 'live-operator' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<?php
			self::render_status();
			self::render_settings();
			self::footer();
			?>
		</div>
		<?php
	}

	private static function badge( string $type, string $text ): string {
		return '<span class="lop-badge lop-badge--' . esc_attr( $type ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function line( string $label, string $badge, string $hint = '' ): void {
		echo '<p><strong>' . esc_html( $label ) . '</strong> ' . $badge // phpcs:ignore WordPress.Security.EscapeOutput -- built by badge().
			. ( '' !== $hint ? '<span class="lop-hint">' . esc_html( $hint ) . '</span>' : '' ) . '</p>';
	}

	private static function action_button( string $action, string $label, bool $primary = false ): void {
		?>
		<form method="post" class="lop-inline-form">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="lop_action" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_status(): void {
		$set  = LOP_Settings::get();
		$mode = LOP_Purio::mode();
		$wait = LOP_Purio::timeout();
		$devs = count( LOP_Push::subscriptions( get_current_user_id() ) );
		?>
		<h2 class="lop-h2"><?php esc_html_e( 'Κατάσταση', 'live-operator' ); ?></h2>
		<div class="lop-zone lop-status">
			<?php
			if ( ! LOP_Purio::present() ) {
				self::line( __( 'PurioChat Pro', 'live-operator' ), self::badge( 'danger', __( 'δεν είναι ενεργό', 'live-operator' ) ) );
			} elseif ( ! LOP_Purio::live() ) {
				self::line( __( 'PurioChat Pro', 'live-operator' ), self::badge( 'warn', __( 'το Live Chat είναι κλειστό ή η άδεια δεν ισχύει', 'live-operator' ) ) );
			} else {
				self::line( __( 'PurioChat Pro', 'live-operator' ), self::badge( 'ok', __( 'Live Chat ανοιχτό', 'live-operator' ) ) );
			}

			if ( 'online' === $mode ) {
				self::line( __( 'Διαθεσιμότητα στο PurioChat', 'live-operator' ), self::badge( 'ok', __( 'online', 'live-operator' ) ) );
			} else {
				self::line(
					__( 'Διαθεσιμότητα στο PurioChat', 'live-operator' ),
					self::badge( 'warn', 'business_hours' === $mode ? __( 'ωράριο', 'live-operator' ) : __( 'πάντα', 'live-operator' ) ),
					__( 'Ο διακόπτης ισχύει μόνο με τη διαθεσιμότητα «online» (PurioChat › Live Chat).', 'live-operator' )
				);
			}

			self::line(
				__( 'Χρόνος αναμονής', 'live-operator' ),
				/* translators: %d: seconds */
				self::badge( $wait >= 180 ? 'ok' : 'warn', sprintf( __( '%d δευτ.', 'live-operator' ), $wait ) ),
				$wait >= 180 ? '' : __( 'Από το κινητό, μετά την ειδοποίηση, χρειάζεσαι λίγο χρόνο: προτείνεται 300 δευτερόλεπτα (PurioChat › Live Chat).', 'live-operator' )
			);

			if ( ! $set['enabled'] ) {
				self::line( __( 'Διακόπτης', 'live-operator' ), self::badge( 'muted', __( 'δεν είναι ενεργός', 'live-operator' ) ), __( 'Ενεργοποίησέ τον στις ρυθμίσεις παρακάτω.', 'live-operator' ) );
			} elseif ( LOP_Presence::online() ) {
				self::line(
					__( 'Διακόπτης', 'live-operator' ),
					/* translators: %s: time */
					self::badge( 'ok', sprintf( __( 'online έως τις %s', 'live-operator' ), LOP_Schedule::label( LOP_Presence::until() ) ) ),
					'schedule' === LOP_Presence::source() ? __( 'από το ωράριο', 'live-operator' ) : ''
				);
			} else {
				self::line( __( 'Διακόπτης', 'live-operator' ), self::badge( 'muted', __( 'offline', 'live-operator' ) ) );
			}

			if ( $set['enabled'] && LOP_Schedule::get()['on'] ) {
				$sp = LOP_Schedule::payload();
				if ( $sp['paused_until'] ) {
					/* translators: %s: time */
					self::line( __( 'Ωράριο', 'live-operator' ), self::badge( 'warn', sprintf( __( 'σε παύση έως τις %s', 'live-operator' ), $sp['end_label'] ) ), __( 'Πάτησες «Γίνε offline» μέσα στο ωράριο: το επόμενο διάστημα ανοίγει κανονικά.', 'live-operator' ) );
				} elseif ( $sp['active'] ) {
					/* translators: %s: time */
					self::line( __( 'Ωράριο', 'live-operator' ), self::badge( 'ok', sprintf( __( 'ανοιχτό έως τις %s', 'live-operator' ), $sp['end_label'] ) ) );
				} elseif ( $sp['next'] ) {
					/* translators: %s: day and time */
					self::line( __( 'Ωράριο', 'live-operator' ), self::badge( 'muted', sprintf( __( 'επόμενο: %s', 'live-operator' ), $sp['next_label'] ) ) );
				} else {
					self::line( __( 'Ωράριο', 'live-operator' ), self::badge( 'warn', __( 'κανένα διάστημα', 'live-operator' ) ), __( 'Πρόσθεσε ώρες σε κάποια μέρα παρακάτω.', 'live-operator' ) );
				}
			}

			if ( ! LOP_Push::available() ) {
				self::line( __( 'Ειδοποιήσεις', 'live-operator' ), self::badge( 'danger', __( 'ο server δεν τις υποστηρίζει', 'live-operator' ) ), __( 'Χρειάζεται PHP με OpenSSL (κλειδιά EC και AES-GCM).', 'live-operator' ) );
			} else {
				/* translators: %d: number of devices */
				self::line( __( 'Ειδοποιήσεις', 'live-operator' ), self::badge( $devs ? 'ok' : 'muted', sprintf( __( 'συσκευές σου: %d', 'live-operator' ), $devs ) ), $devs ? '' : __( 'Άνοιξε την εφαρμογή στο κινητό και πάτα «Άνοιγμα ειδοποιήσεων».', 'live-operator' ) );
			}

			if ( ! LOP_App::pretty() ) {
				self::line( __( 'Μόνιμοι σύνδεσμοι', 'live-operator' ), self::badge( 'warn', __( 'απλοί', 'live-operator' ) ), __( 'Η εφαρμογή θέλει όμορφους μόνιμους συνδέσμους (Ρυθμίσεις › Μόνιμοι σύνδεσμοι) για τις ειδοποιήσεις.', 'live-operator' ) );
			}
			?>
			<p class="lop-mt-8">
				<a class="button button-primary" href="<?php echo esc_url( LOP_App::url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Άνοιγμα εφαρμογής', 'live-operator' ); ?></a>
				<code class="lop-url"><?php echo esc_html( LOP_App::url() ); ?></code>
			</p>
			<p>
				<?php
				if ( $set['enabled'] ) {
					if ( LOP_Presence::online() ) {
						self::action_button( 'online', __( 'Παράταση', 'live-operator' ) );
						self::action_button( 'offline', __( 'Γίνε offline', 'live-operator' ) );
					} else {
						self::action_button( 'online', __( 'Γίνε online', 'live-operator' ) );
					}
				}
				if ( LOP_Push::available() && $devs ) {
					self::action_button( 'test', __( 'Δοκιμαστική ειδοποίηση', 'live-operator' ) );
				}
				?>
			</p>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label, string $hint = '' ): void {
		printf(
			'<label class="lop-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
		if ( '' !== $hint ) {
			echo '<p class="lop-hint lop-hint--under">' . esc_html( $hint ) . '</p>';
		}
	}

	private static function render_settings(): void {
		$s = LOP_Settings::get();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="lop_action" value="save" />

			<h2 class="lop-h2"><?php esc_html_e( 'Ρυθμίσεις', 'live-operator' ); ?></h2>
			<div class="lop-zone">
				<?php
				self::checkbox(
					'lop_enabled',
					$s['enabled'],
					__( 'Ο διακόπτης της εφαρμογής ορίζει πότε είμαι online στο live chat', 'live-operator' ),
					__( 'Online: οι επισκέπτες βλέπουν το «Μιλήστε με άνθρωπο» και ας είναι κλειστή η οθόνη του κινητού. Offline: δεν το βλέπουν. Κλειστό: το PurioChat δουλεύει όπως πριν.', 'live-operator' )
				);
				?>
				<label class="lop-label" for="lop_hours"><?php esc_html_e( 'Αυτόματο offline μετά από', 'live-operator' ); ?></label>
				<select id="lop_hours" name="lop_hours">
					<?php foreach ( LOP_Settings::HOURS as $h ) : ?>
						<option value="<?php echo esc_attr( (string) $h ); ?>"<?php selected( $s['hours'], $h ); ?>>
							<?php
							/* translators: %d: hours */
							echo esc_html( 1 === $h ? __( '1 ώρα', 'live-operator' ) : sprintf( __( '%d ώρες', 'live-operator' ), $h ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="lop-hint lop-hint--under"><?php esc_html_e( 'Για να μη μείνεις ξεχασμένος online. Το «Παράταση» ξαναμετρά από την αρχή, και στη λήξη έρχεται ειδοποίηση.', 'live-operator' ); ?></p>
				<?php
				self::checkbox(
					'lop_inbox',
					$s['inbox'],
					__( 'Η ανοιχτή σελίδα Live Chat του PurioChat με κάνει επίσης online', 'live-operator' ),
					__( 'Κλειστό: μόνο ο διακόπτης αποφασίζει, ακόμα κι αν η σελίδα του PurioChat έμεινε ανοιχτή σε κάποιον υπολογιστή.', 'live-operator' )
				);
				self::checkbox(
					'lop_preview',
					$s['preview'],
					__( 'Το κείμενο του μηνύματος στις ειδοποιήσεις', 'live-operator' ),
					__( 'Κλειστό: η ειδοποίηση λέει μόνο ποιος έγραψε. Το περιεχόμενο ταξιδεύει πάντα κρυπτογραφημένο.', 'live-operator' )
				);
				?>
				<label class="lop-label" for="lop_app_name"><?php esc_html_e( 'Όνομα εφαρμογής', 'live-operator' ); ?></label>
				<input type="text" class="regular-text" id="lop_app_name" name="lop_app_name" maxlength="40" value="<?php echo esc_attr( $s['app_name'] ); ?>" placeholder="<?php echo esc_attr( LOP_Settings::app_name() ); ?>" />
				<p class="lop-hint lop-hint--under"><?php esc_html_e( 'Όπως φαίνεται όταν μπαίνει στην αρχική οθόνη του κινητού.', 'live-operator' ); ?></p>
			</div>

			<?php self::render_schedule(); ?>

			<h2 class="lop-h2"><?php esc_html_e( 'Εφαρμογή Android', 'live-operator' ); ?></h2>
			<div class="lop-zone">
				<p class="lop-hint"><?php esc_html_e( 'Η εφαρμογή Android του Live Operator ανοίγει αυτή τη σελίδα σε πλήρη οθόνη. Για να μη φαίνεται η γραμμή διεύθυνσης, το site δηλώνει την εφαρμογή στο assetlinks.json με το αποτύπωμα του πιστοποιητικού της (γράφεται στις σημειώσεις κάθε έκδοσης της εφαρμογής).', 'live-operator' ); ?></p>
				<label class="lop-label" for="lop_package"><?php esc_html_e( 'Πακέτο εφαρμογής', 'live-operator' ); ?></label>
				<input type="text" class="regular-text code" id="lop_package" name="lop_package" value="<?php echo esc_attr( $s['package'] ); ?>" />
				<label class="lop-label" for="lop_fingerprints"><?php esc_html_e( 'Αποτυπώματα SHA-256 πιστοποιητικού (ένα ανά γραμμή)', 'live-operator' ); ?></label>
				<textarea class="large-text code" rows="3" id="lop_fingerprints" name="lop_fingerprints" placeholder="AB:CD:…"><?php echo esc_textarea( implode( "\n", $s['fingerprints'] ) ); ?></textarea>
				<p class="lop-hint lop-hint--under">
					<?php
					if ( $s['fingerprints'] ) {
						esc_html_e( 'Το site δίνει:', 'live-operator' );
						echo ' <a href="' . esc_url( LOP_App::assetlinks_url() ) . '" target="_blank" rel="noopener">' . esc_html( LOP_App::assetlinks_url() ) . '</a> ';
						esc_html_e( '(αν ανοίγει 404, ο server δεν στέλνει το /.well-known/ στο WordPress: ανέβασε το αρχείο με το χέρι).', 'live-operator' );
					} else {
						esc_html_e( 'Κενό: η εφαρμογή δουλεύει, με μια λεπτή γραμμή διεύθυνσης στο πάνω μέρος.', 'live-operator' );
					}
					?>
				</p>
			</div>

			<p class="lop-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'live-operator' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_schedule(): void {
		$sc    = LOP_Schedule::get();
		$names = LOP_Schedule::day_names();
		?>
		<h2 class="lop-h2"><?php esc_html_e( 'Ωράριο', 'live-operator' ); ?></h2>
		<div class="lop-zone">
			<?php
			self::checkbox(
				'lop_sched_on',
				$sc['on'],
				__( 'Αυτόματο ωράριο: online τις μέρες και ώρες παρακάτω', 'live-operator' ),
				__( 'Μέσα στο ωράριο είσαι online χωρίς να πατήσεις τίποτα. Το «Γίνε offline» κλείνει μόνο το τρέχον διάστημα· το επόμενο ανοίγει κανονικά. Εκτός ωραρίου ο διακόπτης δουλεύει όπως πριν. Χρειάζεται και τον διακόπτη παραπάνω.', 'live-operator' )
			);
			self::checkbox(
				'lop_sched_notify',
				$sc['notify_start'],
				__( 'Ειδοποίηση όταν ξεκινά ένα διάστημα', 'live-operator' ),
				__( 'Ώστε να ξέρεις ότι οι επισκέπτες σε βλέπουν διαθέσιμο. Στο τέλος του διαστήματος έρχεται πάντα ειδοποίηση.', 'live-operator' )
			);
			?>
			<table class="lop-sched" data-lop-sched>
				<tbody>
				<?php foreach ( $names as $day => $name ) : ?>
					<tr data-day="<?php echo esc_attr( (string) $day ); ?>">
						<th scope="row"><?php echo esc_html( $name ); ?></th>
						<?php for ( $i = 0; $i < LOP_Schedule::RANGES; $i++ ) : ?>
							<?php $r = isset( $sc['days'][ $day ][ $i ] ) ? $sc['days'][ $day ][ $i ] : array( '', '' ); ?>
							<td class="lop-sched__range">
								<input type="time" name="lop_sched[<?php echo esc_attr( (string) $day ); ?>][<?php echo (int) $i; ?>][0]" value="<?php echo esc_attr( $r[0] ); ?>" aria-label="<?php echo esc_attr( $name . ' ' . __( 'από', 'live-operator' ) ); ?>" />
								<span class="lop-muted">–</span>
								<input type="time" name="lop_sched[<?php echo esc_attr( (string) $day ); ?>][<?php echo (int) $i; ?>][1]" value="<?php echo esc_attr( '24:00' === $r[1] ? '00:00' : $r[1] ); ?>" aria-label="<?php echo esc_attr( $name . ' ' . __( 'έως', 'live-operator' ) ); ?>" />
							</td>
						<?php endfor; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" data-lop-copy><?php esc_html_e( 'Αντιγραφή της Δευτέρας σε όλες τις καθημερινές', 'live-operator' ); ?></button></p>
			<p class="lop-hint lop-hint--under">
				<?php
				/* translators: %s: time zone */
				echo esc_html( sprintf( __( 'Έως 3 διαστήματα τη μέρα, στη ζώνη ώρας του site (%s). Ένα διάστημα που τελειώνει νωρίτερα από την αρχή του (π.χ. 22:00–02:00) τελειώνει την επόμενη μέρα. Κενό: εκείνη τη μέρα δεν ανοίγει.', 'live-operator' ), wp_timezone_string() ) );
				?>
			</p>
			<label class="lop-label" for="lop_sched_closed"><?php esc_html_e( 'Κλειστές ημερομηνίες (μία ανά γραμμή)', 'live-operator' ); ?></label>
			<textarea class="regular-text code" rows="3" id="lop_sched_closed" name="lop_sched_closed" placeholder="25/12/2026"><?php echo esc_textarea( implode( "\n", array_map( array( __CLASS__, 'dmy' ), $sc['closed'] ) ) ); ?></textarea>
			<p class="lop-hint lop-hint--under"><?php esc_html_e( 'Αργίες ή άδεια: εκείνες τις μέρες το ωράριο δεν ανοίγει. Έως 30, π.χ. 25/12/2026· οι περασμένες σβήνονται μόνες τους.', 'live-operator' ); ?></p>
		</div>
		<?php
	}

	/** 'Y-m-d' → 'd/m/Y'. */
	private static function dmy( string $ymd ): string {
		$p = explode( '-', $ymd );
		return 3 === count( $p ) ? (int) $p[2] . '/' . (int) $p[1] . '/' . $p[0] : $ymd;
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( LOP_Settings::PAGE !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['lop_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'live-operator' ) );
		}
		if ( Live_Operator::killed() ) {
			self::redirect( array( self::msg( 'error', __( 'Το Live Operator είναι σταματημένο με τη σταθερά LOP_DISABLE.', 'live-operator' ) ) ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['lop_action'] ) ) ) {
			case 'save':
				self::redirect( self::do_save() );
				break;
			case 'online':
				if ( ! LOP_Settings::get()['enabled'] ) {
					self::redirect( array( self::msg( 'error', __( 'Ενεργοποίησε πρώτα τον διακόπτη στις ρυθμίσεις.', 'live-operator' ) ) ) );
				}
				LOP_Presence::go_online();
				/* translators: %s: time */
				self::redirect( array( self::msg( 'success', sprintf( __( 'Είσαι online έως τις %s.', 'live-operator' ), LOP_Schedule::label( LOP_Presence::until() ) ) ) ) );
				break;
			case 'offline':
				LOP_Presence::go_offline();
				self::redirect( array( self::msg( 'success', __( 'Είσαι offline.', 'live-operator' ) ) ) );
				break;
			case 'test':
				$r = LOP_Push::send(
					get_current_user_id(),
					array(
						'title' => __( 'Δοκιμαστική ειδοποίηση', 'live-operator' ),
						'body'  => __( 'Οι ειδοποιήσεις φτάνουν σε αυτή τη συσκευή.', 'live-operator' ),
						'tag'   => 'lop-test',
						'url'   => LOP_App::url(),
					)
				);
				self::redirect(
					array(
						$r['sent'] && ! $r['failed']
							/* translators: %d: devices */
							? self::msg( 'success', sprintf( __( 'Στάλθηκε σε %d συσκευές.', 'live-operator' ), $r['sent'] ) )
							/* translators: 1: sent, 2: failed */
							: self::msg( 'warning', sprintf( __( 'Στάλθηκε σε %1$d, απέτυχε σε %2$d συσκευές. Όσες δεν υπάρχουν πια αφαιρέθηκαν.', 'live-operator' ), $r['sent'], $r['failed'] ) ),
					)
				);
				break;
			default:
				self::redirect( array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'live-operator' ) ) ) );
		}
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function do_save(): array {
		$was = LOP_Settings::get();
		$fp  = self::post( 'lop_fingerprints' );
		LOP_Settings::save(
			array(
				'enabled'      => '1' === self::post( 'lop_enabled' ),
				'hours'        => (int) self::post( 'lop_hours' ),
				'inbox'        => '1' === self::post( 'lop_inbox' ),
				'preview'      => '1' === self::post( 'lop_preview' ),
				'app_name'     => self::post( 'lop_app_name' ),
				'package'      => self::post( 'lop_package' ),
				'fingerprints' => $fp,
			)
		);
		$now = LOP_Settings::get();
		if ( $was['enabled'] && ! $now['enabled'] ) {
			LOP_Presence::go_offline();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route(); every value is checked in LOP_Schedule::sanitize().
		$days    = isset( $_POST['lop_sched'] ) && is_array( $_POST['lop_sched'] ) ? map_deep( wp_unslash( $_POST['lop_sched'] ), 'sanitize_text_field' ) : array();
		$dropped = LOP_Schedule::save(
			array(
				'on'           => '1' === self::post( 'lop_sched_on' ),
				'notify_start' => '1' === self::post( 'lop_sched_notify' ),
				'days'         => self::days( $days ),
				'closed'       => self::post( 'lop_sched_closed' ),
			)
		);
		$msgs    = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'live-operator' ) ) );
		if ( $dropped > 0 ) {
			/* translators: %d: number of entries */
			$msgs[] = self::msg( 'warning', sprintf( __( '%d ώρες ή ημερομηνίες του ωραρίου αγνοήθηκαν: δεν ήταν έγκυρες (π.χ. ίδια ώρα αρχής και τέλους).', 'live-operator' ), $dropped ) );
		}
		$bad = LOP_Settings::count_invalid_fingerprints( $fp );
		if ( $bad > 0 ) {
			/* translators: %d: number of lines */
			$msgs[] = self::msg( 'warning', sprintf( __( '%d γραμμές αποτυπωμάτων αγνοήθηκαν: θέλουν 32 ζεύγη δεκαεξαδικών, π.χ. AB:CD:…', 'live-operator' ), $bad ) );
		}
		if ( '' !== trim( self::post( 'lop_package' ) ) && $now['package'] !== trim( self::post( 'lop_package' ) ) ) {
			$msgs[] = self::msg( 'warning', __( 'Το πακέτο δεν είναι έγκυρο όνομα Android· έμεινε το προεπιλεγμένο.', 'live-operator' ) );
		}
		return $msgs;
	}

	/**
	 * The form's rows → LOP_Schedule ranges. Midnight as an end (00:00) is
	 * the next midnight (00:00–00:00 = the whole day); a row with only one
	 * time stays, to be counted as not valid.
	 *
	 * @param array $posted lop_sched[day][row][0|1].
	 */
	private static function days( array $posted ): array {
		$out = array();
		foreach ( $posted as $day => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$from = is_array( $row ) && isset( $row[0] ) && is_scalar( $row[0] ) ? trim( (string) $row[0] ) : '';
				$to   = is_array( $row ) && isset( $row[1] ) && is_scalar( $row[1] ) ? trim( (string) $row[1] ) : '';
				if ( '' === $from && '' === $to ) {
					continue;
				}
				if ( '00:00' === $to && '' !== $from ) {
					$to = '24:00';
				}
				$out[ (int) $day ][] = array( $from, $to );
			}
		}
		return $out;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( array $msgs ): void {
		set_transient( 'lop_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LOP_Settings::PAGE ) );
		exit;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'lop_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'lop_aui_msg_' . get_current_user_id() );
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
		<p class="lop-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'live-operator' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="lop-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="lop-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'live-operator' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="lop-footer-cta">
			<a class="lop-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'live-operator' ); ?>
			</a>
			<a class="lop-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'live-operator' ); ?>
			</a>
		</p>
		<?php
	}
}
