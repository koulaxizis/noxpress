<?php
/**
 * RS_Ledger — Έσοδα εκτός πωλήσεων + Πληρωμές (v1.3.0).
 *
 * Ενιαίο ledger ανά δικαιούχο, δύο τύποι εγγραφών:
 *  - 'income'  : custom έσοδα/προσαρμογές εκτός πωλήσεων. Επιτρέπονται
 *                ΑΡΝΗΤΙΚΕΣ εγγραφές (διορθώσεις/κρατήσεις) — όχι μηδέν.
 *  - 'payment' : αποπληρωμή προς τον δικαιούχο — ΠΑΝΤΑ θετικό ποσό.
 *
 * Κανόνες:
 *  - Κάθε εγγραφή έχει ΥΠΟΧΡΕΩΤΙΚΗ αιτιολογία (note).
 *  - Ο δικαιούχος πρέπει να είναι γνωστός (collect_names).
 *  - Delete μόνο από admin (nonce + capability).
 *
 * v1.3.1 FIX (#12): Static per-request cache στο all() — το dashboard
 * ζητά 4× sums ανά δικαιούχο (περίοδο + lifetime × income/payment),
 * δηλαδή δεκάδες πλήρεις json_decode + usort του option ανά render.
 * Τώρα: το option διαβάζεται/decode-τάρει ΜΙΑ φορά ανά request,
 * Οι mutations (add/delete/wipe) κρατούν το cache συγχρονισμένο.
 *
 * v1.3.1 (#12-b): Νέο public wipe() — μαζικός καθαρισμός για το
 * state import (πρώην: loop delete() ανά εγγραφή = O(n²) πλήρη
 * rewrites του option).
 *
 * v1.3.1 FIX (#11): Η αποτυχημένη διαγραφή στο admin δεν είναι πλέον
 * σιωπηλή — επιστρέφει ρητό error notice (η εγγραφή δεν βρέθηκε /
 * άκυρο ID).
 */

defined( 'ABSPATH' ) || exit;

final class RS_Ledger {

	const OPT_LEDGER = 'rs_ledger';
	const NONCE_ACT  = 'rs_ledger_admin';

	/** @var array[]|null v1.3.1 (#12): per-request cache του decoded ledger. */
	private static $all_cache = null;

	public static function init(): void {
		// v1.3.0 FIX (#5): routing του POST στο admin_init (πριν από output).
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
	}

	/* ---------------------------------------------------------------------
	 * Διαβάσματα
	 * ------------------------------------------------------------------- */

	/**
	 * Όλες οι έγκυρες εγγραφές, νεότερες πρώτα.
	 *
	 * v1.3.1 (#12): Το option διαβάζεται + json_decoded + ταξινομείται
	 * ΜΙΑ φορά ανά request — οι επόμενες κλήσεις σερβίρονται από το
	 * static cache. Οι mutations (add/delete/wipe) το συγχρονίζουν.
	 *
	 * Σχέδιο εγγραφής:
	 *   {id:int, date:'Y-m-d', type:'income'|'payment',
	 *    beneficiary:string, amount:float, note:string}
	 *
	 * @return array[]
	 */
	public static function all(): array {

		if ( null !== self::$all_cache ) {
			return self::$all_cache;
		}

		$raw     = get_option( self::OPT_LEDGER, '' );
		$decoded = ( '' !== $raw && is_string( $raw ) ) ? json_decode( $raw, true ) : null;

		$out = array();
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $e ) {
				if ( ! is_array( $e )
					|| ! isset( $e['id'], $e['date'], $e['type'], $e['beneficiary'], $e['amount'], $e['note'] ) ) {
					continue;
				}
				if ( ! in_array( $e['type'], array( 'income', 'payment' ), true ) ) {
					continue;
				}
				$out[] = array(
					'id'          => (int) $e['id'],
					'date'        => (string) $e['date'],
					'type'        => (string) $e['type'],
					'beneficiary' => (string) $e['beneficiary'],
					'amount'      => (float) $e['amount'],
					'note'        => (string) $e['note'],
				);
			}
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$cmp = strcmp( $b['date'], $a['date'] );
				return 0 !== $cmp ? $cmp : ( $b['id'] <=> $a['id'] );
			}
		);

		self::$all_cache = $out;

		return $out;
	}

	/** Εγγραφές ΕΝΟΣ δικαιούχου, προαιρετικά φιλτραρισμένες σε τύπο. */
	public static function for_beneficiary( string $who, ?string $type = null ): array {

		$out = array();
		foreach ( self::all() as $e ) {
			if ( $e['beneficiary'] !== $who ) {
				continue;
			}
			if ( null !== $type && $e['type'] !== $type ) {
				continue;
			}
			$out[] = $e;
		}
		return $out;
	}

	/**
	 * Άθροισμα εγγραφών δικαιούχου σε περίοδο [start, end] (inclusive),
	 * προαιρετικά ανά τύπο. Θετικές/αρνητικές τιμές αθροίζονται ως έχουν.
	 */
	public static function sum( string $who, string $start, string $end, ?string $type = null ): float {

		$total = 0.0;

		foreach ( self::for_beneficiary( $who, $type ) as $e ) {
			if ( $e['date'] >= $start && $e['date'] <= $end ) {
				$total += (float) $e['amount'];
			}
		}

		return round( $total, 2 );
	}

	/* -----------------------------------------------------------------
	 * Mutations
	 * ----------------------------------------------------------------- */

	/**
	 * Καταχώριση νέας εγγραφής.
	 *
	 * @param array $entry {type, date, beneficiary, amount, note}
	 * @return true|string true σε επιτυχία, αλλιώς μήνυμα λάθους.
	 */
	public static function add( array $entry ) {

		$type = isset( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : '';
		if ( ! in_array( $type, array( 'income', 'payment' ), true ) ) {
			return __( 'Άκυρος τύπος εγγραφής.', 'revenue-splitter' );
		}

		$date = isset( $entry['date'] ) ? trim( (string) $entry['date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date )
			|| ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) {
			return __( 'Μη έγκυρη ημερομηνία.', 'revenue-splitter' );
		}

		$who = sanitize_text_field( (string) ( $entry['beneficiary'] ?? '' ) );
		if ( '' === $who ) {
			return __( 'Επίλεξε δικαιούχο.', 'revenue-splitter' );
		}

		if ( class_exists( 'RS_Beneficiaries' ) && ! in_array( $who, RS_Beneficiaries::collect_names(), true ) ) {
			return __( 'Άγνωστος δικαιούχος — αποθηκεύεις πρώτα τους δικαιούχους στις Ρυθμίσεις;', 'revenue-splitter' );
		}

		$amount_raw = isset( $entry['amount'] ) ? wc_format_decimal( $entry['amount'] ) : '';
		if ( '' === $amount_raw || ! is_numeric( $amount_raw ) ) {
			return __( 'Μη έγκυρο ποσό.', 'revenue-splitter' );
		}
		$amount_f = round( (float) $amount_raw, 2 );

		if ( 'payment' === $type && $amount_f <= 0 ) {
			return __( 'Η πληρωμή πρέπει να είναι θετικό ποσό (ποσά που αφαιρούνται καταχωρούνται ως «Έσοδο» με αρνητική τιμή).', 'revenue-splitter' );
		}
		if ( 'income' === $type && 0.0 === $amount_f ) {
			return __( 'Το ποσό δεν μπορεί να είναι μηδέν.', 'revenue-splitter' );
		}

		$note = trim( sanitize_text_field( (string) ( $entry['note'] ?? '' ) ) );
		if ( '' === $note ) {
			return __( 'Η αιτιολογία είναι υποχρεωτική.', 'revenue-splitter' );
		}

		// v1.3.1 (#12): $all έρχεται πλέον από το per-request cache —
		// γράφεται πίσω (option + cache συγχρονισμένα).
		$all    = self::all();
		$new_id = 0;
		foreach ( $all as $e ) {
			$new_id = max( $new_id, (int) $e['id'] );
		}
		$new_id++;

		$all[] = array(
			'id'          => $new_id,
			'date'        => $date,
			'type'        => $type,
			'beneficiary' => $who,
			'amount'      => $amount_f,
			'note'        => $note,
		);

		update_option( self::OPT_LEDGER, wp_json_encode( $all ) );
		self::$all_cache = $all;

		return true;
	}

	/** Διαγραφή εγγραφής με id. */
	public static function delete( int $id ): bool {

		$keep = array();
		$gone = false;

		foreach ( self::all() as $e ) {
			if ( (int) $e['id'] === $id ) {
				$gone = true;
				continue;
			}
			$keep[] = $e;
		}

		if ( ! $gone ) {
			return false;
		}

		update_option( self::OPT_LEDGER, wp_json_encode( array_values( $keep ) ) );
		self::$all_cache = array_values( $keep );

		return true;
	}

	/**
	 * Μαζικός καθαρισμός ΟΛΟΥ του ledger (v1.3.1, #12-b).
	 *
	 * Χρησιμοποιείται από το state import (RS_Admin_UI::import_state)
	 * αντί για loop delete() ανά εγγραφή. Ένα option write, μηδέν O(n²).
	 */
	public static function wipe(): void {
		delete_option( self::OPT_LEDGER );
		self::$all_cache = array();
	}

	/* -----------------------------------------------------------------
	 * Routing (v1.3.0 FIX #5 + v1.3.1 FIX #11) — POST στο admin_init + PRG
	 * ----------------------------------------------------------------- */

	/** Transient key των notices του τρέχοντος χρήστη. */
	private static function msg_key(): string {
		return 'rs_ledger_msg_' . get_current_user_id();
	}

	/**
	 * Χειρισμός POST του ledger στο admin_init — ΠΡΙΝ από κάθε admin output.
	 *
	 * Διαδοχή PRG: POST → επεξεργασία → notice σε transient (60") →
	 * redirect στη σελίδα Ρυθμίσεων (GET). Το refresh/back του browser
	 * επαναλάβει το GET, όχι το POST — καμία διπλή καταχώριση.
	 */
	public static function route(): void {

		// Μόνο στη σελίδα Ρυθμίσεων του plugin.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- το nonce ελέγχεται παρακάτω.
		if ( ! isset( $_GET['page'] ) || RS_Admin_UI::SLUG_SET !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_POST['rs_ledger_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_ledger_nonce'] ) ), self::NONCE_ACT ) ) {
			return;
		}

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			return;
		}

		$notices = array();

		// ---- Delete (button name: rs_ledger_del_submit) ----
		if ( isset( $_POST['rs_ledger_del_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$gid = absint( wp_unslash( $_POST['rs_ledger_del_id'] ?? 0 ) );

			// v1.3.1 FIX (#11): καμία σιωπηλή αποτυχία — ρητά μηνύματα.
			if ( $gid <= 0 ) {
				$notices[] = array(
					'type' => 'error',
					'text' => __( 'Άκυρο ID εγγραφής.', 'revenue-splitter' ),
				);
			} elseif ( self::delete( $gid ) ) {
				$notices[] = array(
					'type' => 'success',
					'text' => __( 'Η εγγραφή διαγράφηκε.', 'revenue-splitter' ),
				);
			} else {
				$notices[] = array(
					'type' => 'error',
					'text' => __( 'Η εγγραφή δεν βρέθηκε — ίσως έχει ήδη διαγραφεί.', 'revenue-splitter' ),
				);
			}

			self::finish_route( $notices );
		}

		// ---- Add (button name: rs_ledger_add) ----
		if ( isset( $_POST['rs_ledger_add'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing

			$entry = array(
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				'type'        => isset( $_POST['rs_ledger_type'] ) ? sanitize_key( wp_unslash( $_POST['rs_ledger_type'] ) ) : '',
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				'date'        => isset( $_POST['rs_ledger_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_ledger_date'] ) ) : '',
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				'beneficiary' => isset( $_POST['rs_ledger_ben'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_ledger_ben'] ) ) : '',
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				'amount'      => isset( $_POST['rs_ledger_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_ledger_amount'] ) ) : '',
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				'note'        => isset( $_POST['rs_ledger_note'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_ledger_note'] ) ) : '',
			);

			$res = self::add( $entry );

			if ( true === $res ) {
				$notices[] = array(
					'type' => 'success',
					'text' => __( 'Η εγγραφή καταχωρήθηκε.', 'revenue-splitter' ),
				);
			} else {
				$notices[] = array(
					'type' => 'error',
					'text' => (string) $res,
				);
			}

			self::finish_route( $notices );
		}
	}

	/** Αποθήκευση notices + redirect + exit (κλείνει το PRG κύκλωμα). */
	private static function finish_route( array $notices ): void {

		if ( ! empty( $notices ) ) {
			set_transient( self::msg_key(), $notices, 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . RS_Admin_UI::SLUG_SET ) );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Admin UI — notices reader + rendering (καλείται από RS_Admin_UI)
	 * ----------------------------------------------------------------- */

	/**
	 * Διαβάζει (και αδειάζει) τα pending notices του redirect.
	 *
	 * Καλείται από το render_settings() — το υπάρχον call στο
	 * RS_Admin_UI ΔΕΝ χρειάζεται καμία αλλαγή.
	 *
	 * @return array[] Notices για το render.
	 */
	public static function handle_admin_post(): array {

		$raw = get_transient( self::msg_key() );

		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( self::msg_key() );
			return $raw;
		}

		return array();
	}

	/** Πίνακας ledger + φόρμα καταχώρισης (καλείται από render_settings). */
	public static function render_admin(): void {

		$entries = self::all();
		$names   = RS_Beneficiaries::collect_names();

		if ( empty( $names ) ) {
			echo '<hr /><p class="rs-empty">' . esc_html__( 'Δεν υπάρχουν δικαιούχοι ακόμη — δεν μπορεί να συντηρηθεί ledger.', 'revenue-splitter' ) . '</p>';
			return;
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Έσοδα εκτός πωλήσεων & Πληρωμές', 'revenue-splitter' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Προσαρμογές εκτός WooCommerce: bonus, υποτροφίες, διορθώσεις («Έσοδο», + ή −) και αποπληρωμές («Πληρωμή», πάντα θετικές). Κάθε εγγραφή χρειάζεται αιτιολογία.', 'revenue-splitter' ); ?>
		</p>

		<table class="widefat striped rs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Τύπος', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Ποσό', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Αιτιολογία', 'revenue-splitter' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr><td colspan="6" class="rs-empty"><?php esc_html_e( 'Καμία εγγραφή.', 'revenue-splitter' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $entries as $e ) : ?>
				<tr>
					<td><?php echo esc_html( $e['date'] ); ?></td>
					<td><?php echo esc_html( 'income' === $e['type'] ? __( 'Έσοδο', 'revenue-splitter' ) : __( 'Πληρωμή', 'revenue-splitter' ) ); ?></td>
					<td><strong><?php echo esc_html( $e['beneficiary'] ); ?></strong></td>
					<td class="num"><strong><?php echo esc_html( ( 'income' === $e['type'] ? '+' : '−' ) . number_format_i18n( abs( (float) $e['amount'] ), 2 ) ); ?></strong></td>
					<td><?php echo esc_html( $e['note'] ); ?></td>
					<td>
						<form method="post" style="display:inline; margin:0;">
							<?php wp_nonce_field( self::NONCE_ACT, 'rs_ledger_nonce' ); ?>
							<input type="hidden" name="rs_ledger_del_id" value="<?php echo esc_attr( (string) $e['id'] ); ?>" />
							<button type="submit" name="rs_ledger_del_submit" value="1"
								class="button-link rs-remove-row"
								aria-label="<?php esc_attr_e( 'Διαγραφή εγγραφής', 'revenue-splitter' ); ?>">×</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<form method="post" class="rs-period-form" style="margin-top:14px;">
			<?php wp_nonce_field( self::NONCE_ACT, 'rs_ledger_nonce' ); ?>

			<select name="rs_ledger_type">
				<option value="income"><?php esc_html_e( 'Έσοδο (+/−)', 'revenue-splitter' ); ?></option>
				<option value="payment"><?php esc_html_e( 'Πληρωμή', 'revenue-splitter' ); ?></option>
			</select>

			<select name="rs_ledger_ben">
				<?php foreach ( $names as $name ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>

			<input type="date" name="rs_ledger_date"
				value="<?php echo esc_attr( ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ) ); ?>" />

			<input type="text" name="rs_ledger_amount" class="rs-search" style="min-width:110px;"
				placeholder="<?php esc_attr_e( 'Ποσό (π.χ. 150 ή -40)', 'revenue-splitter' ); ?>" required />

			<input type="text" name="rs_ledger_note" class="rs-search" style="min-width:280px;"
				placeholder="<?php esc_attr_e( 'Αιτιολογία (υποχρεωτική)…', 'revenue-splitter' ); ?>" required />

			<button type="submit" name="rs_ledger_add" value="1" class="button button-primary">
				<?php esc_html_e( 'Καταχώριση', 'revenue-splitter' ); ?>
			</button>
		</form>
		<?php
	}
}