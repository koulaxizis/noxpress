<?php
/**
 * RS_Emails — Email υπηρεσίες: monthly reports, key reset dispatch,
 * opt-in διαχείριση δικαιούχων.
 *
 * Options:
 *  - rs_beneficiary_emails (JSON όνομα => email, lowercase)
 *  - rs_email_reports      (JSON όνομα => 1 — opt-in μηνιαίας αναφοράς)
 *  - rs_last_report_run    ('YYYY-MM' — dedup του cron)
 *
 * Dispatch: ΠΑΝΤΑ wp_mail() — κανένας SMTP provider dependency.
 * Ο καθένας δένει τον δικό του (SMTPfluent, WP Mail SMTP κ.λπ.).
 *
 * Cron: ΔΕΝ υπάρχει native 'monthly' schedule στο WP — τρέχει ΔΗΜΕΡΗΝΟΣ
 * έλεγχος 'είμαστε σε νέο μήνα και δεν έχει σταλεί ο προηγούμενος;'
 * (dedup μέσω rs_last_report_run). Το cron είναι self-scheduled στο init()
 * και αφαιρείται στην απενεργοποίηση (register_deactivation_hook στο
 * κύριο αρχείο, v1.7.0) και στο uninstall.
 *
 * Σημείωση: το WP-Cron εξαρτάται από επισκέψεις/motion του site — σε
 * χαμηλή κίνηση συνιστάται system cron με wp-cron disabled ή
 * 'wp rs ...' trigger (CLI support σε επόμενο wave αν το θες).
 *
 * vNext (#1): μηνιαία αναφορά πωλήσεων (opt-in από portal/settings).
 * vNext (#3): αποστολή νέου κλειδιού στο email (reset ροή — το
 * RS_Portal κάνει rate-limit + reverse lookup και καλεί εδώ ΜΟΝΟ
 * το send_key()).
 */

defined( 'ABSPATH' ) || exit;

final class RS_Emails {

	const OPT_EMAILS   = 'rs_beneficiary_emails';
	const OPT_REPORTS  = 'rs_email_reports';
	const OPT_LAST_RUN = 'rs_last_report_run';
	const CRON_HOOK    = 'rs_email_monthly_check';

	/** @var array|null Cache του email map (name => email). */
	private static $emails_cache = null;

	/** @var array|null Cache του opt-in map (name => 1). */
	private static $reports_cache = null;

	public static function init(): void {

		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send_monthly' ) );

		// Cache καθαρισμός μαζί με όλο το plugin (νέα ονόματα → νέα map).
		add_action( 'rs_invalidate_cache', array( __CLASS__, 'clear_cache' ) );

		// Self-scheduling: daily safety-net check (idempotent).
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cache(): void {
		self::$emails_cache  = null;
		self::$reports_cache = null;
	}

	/* =====================================================================
	 * Email map (όνομα => email)
	 * =================================================================== */

	/**
	 * Το email map — ΠΑΝΤΑ strict-validated στο διάβασμα (is_email),
	 * ό,τι κι αν υπάρχει αποθηκευμένο. Lowercase normalization.
	 *
	 * @return array name => email
	 */
	public static function get_email_map(): array {

		if ( null !== self::$emails_cache ) {
			return self::$emails_cache;
		}

		self::$emails_cache = array();

		$raw = (string) get_option( self::OPT_EMAILS, '' );
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $name => $email ) {
					if ( is_string( $name ) && '' !== $name
						&& is_string( $email )
						&& false !== is_email( $email ) ) {
						self::$emails_cache[ $name ] = strtolower( $email );
					}
				}
			}
		}

		return self::$emails_cache;
	}

	/** Email δικαιούχου ('' αν δεν έχει οριστεί). */
	public static function get_email( string $name ): string {
		return self::get_email_map()[ $name ] ?? '';
	}

	/**
	 * Reverse lookup: ποιος δικαιούχος έχει αυτό το email ('' = κανείς).
	 * Timing-safe compare (hash_equals) — case-insensitive.
	 */
	public static function name_for_email( string $email ): string {

		$email = strtolower( trim( $email ) );

		if ( '' === $email || false === is_email( $email ) ) {
			return '';
		}

		foreach ( self::get_email_map() as $name => $mail ) {
			if ( hash_equals( $mail, $email ) ) {
				return (string) $name;
			}
		}

		return '';
	}

	/**
	 * Γράφει ολόκληρο το email map (replace semantics — όπως τα colors).
	 * STRICT: οποιοδήποτε άκυρο email → false, ΚΑΜΙΑ αλλαγή.
	 * Κενό string email = διαγραφή του συγκεκριμένου ονόματος.
	 *
	 * @param array $map name => email (raw input).
	 */
	public static function set_email_map( array $map ): bool {

		$clean = array();

		foreach ( $map as $name => $email ) {

			$name  = trim( sanitize_text_field( (string) $name ) );
			$email = strtolower( trim( (string) $email ) );

			if ( '' === $name || '' === $email ) {
				continue; // Κενό όνομα/email (διαγραμμένη γραμμή) — παραλείπεται.
			}

			if ( false === is_email( $email ) ) {
				return false; // Rip-through, ενιαία με τη φιλοσοφία των colors.
			}

			$clean[ $name ] = $email;
		}

		self::$emails_cache = null;

		if ( empty( $clean ) ) {
			delete_option( self::OPT_EMAILS );
		} else {
			update_option( self::OPT_EMAILS, wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ) );
		}

		return true;
	}

	/**
	 * Merge semantics για το metabox προϊόντος: προσθέτει/ενημερώνει/
	 * διαγράφει ΖΥΓΙΚΑ τα emails των ονομάτων που υποβάλλονται, ΧΩΡΙΣ
	 * να αγγίζει emails δικαιούχων που δεν εμφανίζονται στο συγκεκρι-
	 * μένο προϊόν. Κενό string = διαγραφή του email για αυτό το όνομα.
	 *
	 * @param  array $map name => email (raw input).
	 * @return string[] Τα ονόματα των ΠΑΡΑλειφθέντων (μη έγκυρων) emails.
	 */
	public static function merge_email_map( array $map ): array {

		$existing = self::get_email_map();
		$invalid  = array();

		foreach ( $map as $name => $email ) {

			$name  = trim( sanitize_text_field( (string) $name ) );
			$email = strtolower( trim( (string) $email ) );

			if ( '' === $name ) {
				continue;
			}

			if ( '' === $email ) {
				unset( $existing[ $name ] ); // Κενό = σκόπιμη διαγραφή.
				continue;
			}

			if ( false === is_email( $email ) ) {
				$invalid[] = $name; // Άκυρο — ΔΕΝ σβήνει το παλιό, δεν γράφει τίποτα.
				continue;
			}

			$existing[ $name ] = $email;
		}

		self::$emails_cache = null;

		if ( empty( $existing ) ) {
			delete_option( self::OPT_EMAILS );
		} else {
			update_option( self::OPT_EMAILS, wp_json_encode( $existing, JSON_UNESCAPED_UNICODE ) );
		}

		return $invalid;
	}

	/* =====================================================================
	 * Opt-in μηνιαίας αναφοράς (όνομα => 1)
	 * =================================================================== */

	/** @return array name => 1 */
	public static function get_report_map(): array {

		if ( null !== self::$reports_cache ) {
			return self::$reports_cache;
		}

		self::$reports_cache = array();

		$raw = (string) get_option( self::OPT_REPORTS, '' );
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $name => $on ) {
					if ( is_string( $name ) && '' !== $name && 1 === (int) $on ) {
						self::$reports_cache[ $name ] = 1;
					}
				}
			}
		}

		return self::$reports_cache;
	}

	public static function is_opted_in( string $name ): bool {
		return isset( self::get_report_map()[ $name ] );
	}

	/**
	 * Opt-in/out δικαιούχου. Guard: opt-in ΕΠΙΤΡΕΠΕΤΑΙ μόνο όταν υπάρχει
	 * email (το frontend toggle το ελέγχει ήδη — εδώ διπλή άμυνα).
	 */
	public static function set_optin( string $name, bool $on ): void {

		$map = self::get_report_map();

		if ( $on && '' !== self::get_email( $name ) ) {
			$map[ $name ] = 1;
		} else {
			unset( $map[ $name ] );
		}

		self::$reports_cache = null;

		if ( empty( $map ) ) {
			delete_option( self::OPT_REPORTS );
		} else {
			update_option( self::OPT_REPORTS, wp_json_encode( $map, JSON_UNESCAPED_UNICODE ) );
		}
	}

	/* =====================================================================
	 * #1 — Μηνιαία αναφορά (cron)
	 * =================================================================== */

	/**
	 * vNext (#3): Κοινός branded wrapper ΟΛΩΝ των emails του plugin.
	 *
	 * Dark identity του plugin (ίδια παλέτα με admin.css/portal):
	 * μωβ header band + σκούρο card, πάνω σε ανοιχτό εξωτερικό φόντο
	 * (σκόπιμος συμβιβασμός συμβατότητας με email clients — Outlook
	 * desktop/preview panes αποδίδουν απρόβλεπτα full-dark body).
	 *
	 * INLINE styles ΜΟΝΟ (τα email clients δεν φορτώνουν <style>
	 * αξιόπιστα), system fonts, υψηλή αντίθεση κειμένου (#eae8fa στο
	 * #101218). Worst case υποβάθμιση: απλό μαύρο-σε-λευκό κείμενο.
	 */
	private static function wrap( string $inner ): string {

		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
		$html .= '<body style="margin:0;padding:24px;background:#f4f2fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';
		$html .= '<div style="max-width:560px;margin:0 auto;background:#101218;border:1px solid #2b2e36;border-radius:8px;overflow:hidden;">';
		$html .= '<div style="background:#6d4aff;color:#ffffff;padding:14px 22px;font-size:1.1em;font-weight:600;">Revenue Splitter</div>';
		$html .= '<div style="padding:22px;color:#eae8fa;font-size:14px;line-height:1.65;">';
		$html .= $inner;
		$html .= '</div></div>';
		$html .= '<p style="max-width:560px;margin:12px auto 0;color:#8b87a3;font-size:11px;">';
		$html .= esc_html( sprintf( __( 'Made with ❤ by %s', 'revenue-splitter' ), 'Christos Koulaxizis' ) ) . ' · <a href="https://glarolykoi.net" style="color:#8b87a3;text-decoration:none;">glarolykoi.net</a> · <a href="https://noxpress.tech" style="color:#8b87a3;text-decoration:none;">noxpress.tech</a>';
		$html .= '</p></body></html>';

		return $html;
	}

	/**
	 * Daily safety-net: στέλνει την αναφορά του ΠΡΟΗΓΟΥΜΕΝΟΥ μήνα, μία
	 * φορά, το νωρίτερο την 1η του νέου μήνα. Dedup μέσω OPT_LAST_RUN
	 * ('YYYY-MM' του μήνα που ΣΤΕΛΘΗΚΕ — όχι της ημέρας).
	 *
	 * Catch-up semantics: αν χαθούν πολλοί μήνες (site κάτω), στέλνεται
	 * ΜΟΝΟ ο αμέσως προηγούμενος — εκουσίως, όχι backlog spam.
	 */
	public static function maybe_send_monthly(): void {

		$prev = new DateTimeImmutable( 'first day of previous month', wp_timezone() );
		$key  = $prev->format( 'Y-m' );

		if ( (string) get_option( self::OPT_LAST_RUN, '' ) === $key ) {
			return; // Ήδη σταλεί για αυτόν τον μήνα.
		}

		$start = $prev->format( 'Y-m-01' );
		$end   = $prev->format( 'Y-m-t' );

		foreach ( array_keys( self::get_report_map() ) as $name ) {

			$email = self::get_email( $name );
			if ( '' === $email ) {
				continue; // Opt-in χωρίς email (άλλαξε email) — skip.
			}

			$body = self::build_monthly_body( $name, $start, $end );

			self::send(
				$email,
				sprintf(
					/* translators: %s: μήνας/έτος αναφοράς */
					__( 'Μηνιαία αναφορά πωλήσεων — %s', 'revenue-splitter' ),
					wp_date( 'F Y', $prev->getTimestamp(), wp_timezone() )
				),
				$body
			);
		}

		// Mark-άρουμε ΑΝΕΞΑΡΤΗΤΑ από το πλήθος των αποστολών — μία φορά
		// ανά μήνα, χωρίς retries (αποφυγή double-send σε flaky cron).
		update_option( self::OPT_LAST_RUN, $key );
	}

	/**
	 * Το HTML body της μηνιαίας αναφοράς ενός δικαιούχου.
	 *
	 * Δομή: χαιρετισμός, KPI μεριδίου μήνα, KPI αποπληρωτέου υπολοίπου,
	 * πίνακας «Τα προϊόντα σου» (τίτλος/τεμ./μερίδιο), υποσημείωση.
	 * Escape: ΠΑΝΤΑ esc_html/esc_attr — no exceptions.
	 */
	private static function build_monthly_body( string $who, string $start, string $end ): string {

		$report = RS_Reports::run(
			array(
				'date_start' => $start,
				'date_end'   => $end,
			)
		);

		$share = 0.0;
		$rows  = array();
		foreach ( $report['products'] as $p ) {
			foreach ( $p['splits'] as $s ) {
				if ( $s['name'] === $who ) {
					$share += (float) $s['amount'];
					$rows[] = array(
						'title'  => (string) $p['title'],
						'qty'    => (int) $p['qty'],
						'amount' => (float) $s['amount'],
						'pct'    => (float) $s['percent'],
					);
					break;
				}
			}
		}

		$today  = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$sales_l = RS_Reports::lifetime_beneficiaries()[ $who ] ?? 0.0;
		$inc_l   = RS_Ledger::sum( $who, '2000-01-01', $today, 'income' );
		$pay_l   = RS_Ledger::sum( $who, '2000-01-01', $today, 'payment' );
		$remain  = round( $sales_l + $inc_l - $pay_l, 2 );

		$cur  = self::currency_fmt();
		$from = RS_Lang::fmt_date( $start );
		$to   = RS_Lang::fmt_date( $end );

		$inner  = '<h2 style="margin:0 0 4px;color:#f6f4ff;">' . esc_html__( 'Μηνιαία αναφορά πωλήσεων', 'revenue-splitter' ) . '</h2>';
		$inner .= '<p style="margin:0 0 18px;color:#b7b2d6;font-size:0.9em;">';
		$inner .= esc_html( sprintf( '%s: %s → %s', __( 'Περίοδος', 'revenue-splitter' ), $from, $to ) );
		$inner .= '</p>';

		$inner .= '<p style="margin:0 0 14px;">' . esc_html__( 'Γεια σου', 'revenue-splitter' ) . ' <strong>' . esc_html( $who ) . '</strong>,</p>';

		// KPI boxes — ίδιο pattern με τα .rs-kpi του portal (dark card, μωβ accent).
		$inner .= '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px;">';
		$inner .= '<div style="flex:1 1 200px;background:#17191f;border:1px solid #2e3240;border-left:3px solid #6d4aff;border-radius:6px;padding:10px 14px;">';
		$inner .= '<span style="font-size:0.72em;text-transform:uppercase;letter-spacing:0.06em;color:#b7b2d6;">' . esc_html__( 'Το μερίδιό σου τον μήνα', 'revenue-splitter' ) . '</span>';
		$inner .= '<strong style="display:block;font-size:1.25em;margin-top:2px;color:#f6f4ff;">' . esc_html( $cur( $share ) ) . '</strong>';
		$inner .= '</div>';
		$inner .= '<div style="flex:1 1 200px;background:#17191f;border:1px solid #2e3240;border-left:3px solid #6d4aff;border-radius:6px;padding:10px 14px;">';
		$inner .= '<span style="font-size:0.72em;text-transform:uppercase;letter-spacing:0.06em;color:#b7b2d6;">' . esc_html__( 'Αποπληρωτέο υπόλοιπο', 'revenue-splitter' ) . '</span>';
		$inner .= '<strong style="display:block;font-size:1.25em;margin-top:2px;color:#f6f4ff;">' . esc_html( $cur( $remain ) ) . '</strong>';
		$inner .= '</div>';
		$inner .= '</div>';

		$inner .= '<h3 style="margin:0 0 6px;font-size:1em;color:#f6f4ff;">' . esc_html__( 'Τα προϊόντα σου', 'revenue-splitter' ) . '</h3>';
		$inner .= '<table style="width:100%;border-collapse:collapse;">';
		$inner .= '<tr>';
		$inner .= '<th style="border:1px solid #2e3240;padding:6px 10px;text-align:left;background:#1d2029;color:#e6e1fd;">' . esc_html__( 'Προϊόν', 'revenue-splitter' ) . '</th>';
		$inner .= '<th style="border:1px solid #2e3240;padding:6px 10px;text-align:right;background:#1d2029;color:#e6e1fd;">' . esc_html__( 'Τεμ.', 'revenue-splitter' ) . '</th>';
		$inner .= '<th style="border:1px solid #2e3240;padding:6px 10px;text-align:right;background:#1d2029;color:#e6e1fd;">' . esc_html__( 'Ποσοστό', 'revenue-splitter' ) . '</th>';
		$inner .= '<th style="border:1px solid #2e3240;padding:6px 10px;text-align:right;background:#1d2029;color:#e6e1fd;">' . esc_html__( 'Μερίδιο', 'revenue-splitter' ) . '</th>';
		$inner .= '</tr>';

		if ( empty( $rows ) ) {
			$inner .= '<tr><td colspan="4" style="border:1px solid #2e3240;padding:10px;text-align:center;color:#b7b2d6;font-style:italic;">' . esc_html__( 'Καμία πώληση αυτόν τον μήνα.', 'revenue-splitter' ) . '</td></tr>';
		} else {
			foreach ( $rows as $r ) {
				$inner .= '<tr>';
				$inner .= '<td style="border:1px solid #2e3240;padding:6px 10px;">' . esc_html( $r['title'] ) . '</td>';
				$inner .= '<td style="border:1px solid #2e3240;padding:6px 10px;text-align:right;">' . esc_html( number_format_i18n( $r['qty'] ) ) . '</td>';
				$inner .= '<td style="border:1px solid #2e3240;padding:6px 10px;text-align:right;">' . esc_html( number_format_i18n( $r['pct'], 1 ) ) . '%</td>';
				$inner .= '<td style="border:1px solid #2e3240;padding:6px 10px;text-align:right;font-weight:600;">' . esc_html( $cur( $r['amount'] ) ) . '</td>';
				$inner .= '</tr>';
			}
		}

		$inner .= '</table>';

		$inner .= '<p style="margin:16px 0 0;color:#b7b2d6;font-size:0.85em;">';
		$inner .= esc_html__( 'Η αναφορά στέλνεται επειδή έχεις ενεργοποιήσει τη μηνιαία αναφορά στο portal σου. Μπορείς να την απενεργοποιήσεις εκεί ανά πάσα στιγμή.', 'revenue-splitter' );
		$inner .= '</p>';

		return self::wrap( $inner );
	}

	/* =====================================================================
	 * #3 — Αποστολή νέου κλειδιού (καλείται ΜΟΝΟ από το RS_Portal)
	 * =================================================================== */

	/**
	 * Στέλνει το νέο plaintext κλειδί στο email του δικαιούχου.
	 * Ο caller (RS_Portal) έχει ήδη κάνει rate-limit + reverse lookup
	 * + rotate_key() — εδώ γίνεται ΜΟΝΟ η αποστολή.
	 *
	 * @return bool Επιτυχία αποστολής (wp_mail).
	 */
	public static function send_key( string $who, string $plain, string $to_email ): bool {

		if ( '' === $to_email || false === is_email( $to_email ) ) {
			return false;
		}

		$inner  = '<h2 style="margin:0 0 14px;color:#f6f4ff;">' . esc_html__( 'Νέο κλειδί portal', 'revenue-splitter' ) . '</h2>';
		$inner .= '<p style="margin:0 0 10px;">' . esc_html__( 'Γεια σου', 'revenue-splitter' ) . ' <strong>' . esc_html( $who ) . '</strong>,</p>';
		$inner .= '<p style="margin:0 0 10px;">' . esc_html__( 'Ζητήθηκε επαναφορά του κλειδιού σου για το Author Portal. Το νέο σου κλειδί είναι:', 'revenue-splitter' ) . '</p>';
		$inner .= '<p style="background:#1a1d26;border:1px solid #3a3f52;border-radius:4px;padding:12px 14px;font-family:monospace;font-size:1.05em;word-break:break-all;margin:0 0 10px;color:#f0eefc;"><strong>' . esc_html( $plain ) . '</strong></p>';
		$inner .= '<p style="margin:0 0 0;color:#ff9a9a;font-weight:600;">' . esc_html__( 'Το παλιό σου κλειδί ακυρώθηκε. Αν ΔΕΝ ζήτησες εσύ την επαναφορά, ενημέρωσε τον εκδότη.', 'revenue-splitter' ) . '</p>';

		$html = self::wrap( $inner );

		return self::send(
			$to_email,
			sprintf(
				/* translators: %s: όνομα δικαιούχου */
				__( 'Νέο κλειδί portal — %s', 'revenue-splitter' ),
				$who
			),
			$html
		);
	}

	/**
	 * Ειδοποίηση στον admin για frontend key-rotation (anti-abuse:
	 * ο κυβερνώτας admin πρέπει να βλέπει rotations που ΔΕΝ έκανε ο ίδιος).
	 */
	public static function notify_admin_rotation( string $who ): void {

		$admin = (string) get_option( 'admin_email', '' );
		if ( '' === $admin || false === is_email( $admin ) ) {
			return;
		}

		$inner = '<p style="margin:0 0 10px;">' . esc_html__( 'Ζητήθηκε επαναφορά κλειδιού portal μέσω του frontend (forgot-key flow).', 'revenue-splitter' ) . '</p>'
			/* translators: %s: όνομα δικαιούχου */
			. '<p style="margin:0;">' . esc_html( sprintf( __( 'Δικαιούχος: %s', 'revenue-splitter' ), $who ) ) . '</p>';

		self::send(
			$admin,
			sprintf(
				/* translators: %s: όνομα δικαιούχου */
				__( '[Revenue Splitter] Επαναφορά κλειδιού portal — %s', 'revenue-splitter' ),
				$who
			),
			self::wrap( $inner )
		);
	}

	/**
	 * vNext (#4): Δοκιμαστικό email (καλείται από τις Ρυθμίσεις).
	 *
	 * Περνά από το ΙΔΙΟ send()/wp_mail() path με τις μηνιαίες αναφορές
	 * και τα key resets — δηλαδή δοκιμάζει την πραγματική οδό παράδοσης
	 * του plugin, όχι μια παράλληλη υλοποίηση.
	 *
	 * @return bool Επιτυχία αποστολής.
	 */
	public static function send_test( string $to ): bool {

		if ( '' === $to || false === is_email( $to ) ) {
			return false;
		}

		$inner  = '<p style="margin:0 0 12px;">' . esc_html__( 'Αυτό είναι ένα δοκιμαστικό email από το plugin Revenue Splitter.', 'revenue-splitter' ) . '</p>';
		$inner .= '<p style="margin:0 0 12px;">' . esc_html__( 'Αν το διαβάζεις, η αποστολή email λειτουργεί σωστά — η μηνιαία αναφορά θα φτάνει κανονικά.', 'revenue-splitter' ) . '</p>';
		$inner .= '<p style="margin:0;color:#b7b2d6;font-size:12px;">' . esc_html( sprintf( __( 'Στάλθηκε: %s', 'revenue-splitter' ), wp_date( 'd/m/Y H:i' ) ) ) . '</p>';

		return self::send(
			$to,
			sprintf(
				/* translators: %s: όνομα site */
				__( '[Revenue Splitter] Δοκιμαστικό email — %s', 'revenue-splitter' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			self::wrap( $inner )
		);
	}

	/* =====================================================================
	 * Core sender
	 * =================================================================== */

	private static function send( string $to, string $subject, string $html ): bool {

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $to, $subject, $html, $headers );
	}

	private static function currency_fmt(): callable {

		// HTML entity (π.χ. &euro;) → χαρακτήρας· το esc_html το ξανα-κωδικοποιεί.
		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			: '€';

		return static function ( $amount ) use ( $symbol ) {
			return number_format_i18n( (float) $amount, 2 ) . ' ' . $symbol;
		};
	}
}