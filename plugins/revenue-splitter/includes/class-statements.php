<?php
/**
 * RS_Statements — monthly creator statements (1.15.0).
 *
 * Builds on the monthly report of RS_Emails: one statement per beneficiary
 * and month, with the opening balance, the month's sales shares per title,
 * the Ledger's other income, deductions (1.17.0) and payments, and the
 * closing balance. It goes
 * out as a short email with the full statement as a PDF (RS_PDF).
 *
 * Flow:
 *  1. The daily check (RS_Emails::CRON_HOOK) makes the previous month's
 *     statements once, when the feature is on. They can also be made (or
 *     made again) by hand for any month, from Noxpress → RS Statements.
 *  2. Each statement is frozen when made: what is checked is what is sent.
 *     Statements not yet sent can be made again (the numbers refresh).
 *  3. Mode «review» (default): every statement waits until the publisher
 *     presses Send. Mode «auto»: the daily check sends at once, EXCEPT
 *     statements with a warning, December statements (the yearly
 *     settlement) and beneficiaries without an email, which always wait.
 *     A statement made by hand is never sent by itself.
 *  4. Settlement: the yearly payout happens at the end of the year. A
 *     December statement can be marked «Paid», which adds a Ledger payment
 *     of its closing balance (through RS_Ledger::add_many, same checks).
 *
 * Warnings (they hold a statement in either mode):
 *  - vat: RS removes a VAT rate that does not match the product (no tax in
 *    WooCommerce, or ISBN → 6% / other GTIN → 24%).
 *  - qty: more copies of one title in the month than the set limit.
 *  - negative: the closing balance is below zero.
 *  - no_email: the beneficiary has no email on file (cannot be sent).
 *
 * Who gets one: every beneficiary with an opening or closing balance or any
 * movement in the month, except the ones excluded in the settings. No opt-in
 * is needed (rs_email_reports stays the switch of the old summary only).
 *
 * Storage: one option per month, rs_stmt_m_YYYY-MM (JSON, autoload off),
 * listed in rs_stmt_months; settings in rs_stmt_settings; the last month
 * made by the daily check in rs_stmt_last.
 *
 * Deviation from the Bible (§9): the texts the creators read (email + PDF)
 * are fixed Greek, not gettext msgids, so a publisher whose admin is in
 * English never sends a creator an English statement by accident. The admin
 * screen is translated as usual.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Statements {

	const SLUG       = 'revenue-splitter-statements';
	const OPT_SET    = 'rs_stmt_settings';
	const OPT_MONTHS = 'rs_stmt_months';
	const OPT_LAST   = 'rs_stmt_last';
	const OPT_PREFIX = 'rs_stmt_m_';
	const NONCE      = 'rs_stmt_admin';

	/** Greek month names (nominative) for the creator-facing texts. */
	const MONTHS_EL = array( 1 => 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος' );

	public static function init(): void {
		add_action( RS_Emails::CRON_HOOK, array( __CLASS__, 'maybe_generate' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_post_rs_stmt_pdf', array( __CLASS__, 'stream_pdf' ) );
	}

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	/** @return array{enabled:bool,mode:string,exclude:string[],qty_limit:int,issuer:string} */
	public static function settings(): array {
		$raw = json_decode( (string) get_option( self::OPT_SET, '' ), true );
		$raw = is_array( $raw ) ? $raw : array();
		$exc = isset( $raw['exclude'] ) && is_array( $raw['exclude'] ) ? array_values( array_filter( array_map( 'strval', $raw['exclude'] ) ) ) : array();
		$iss = isset( $raw['issuer'] ) && is_string( $raw['issuer'] ) ? trim( $raw['issuer'] ) : '';
		return array(
			'enabled'   => ! empty( $raw['enabled'] ),
			'mode'      => ( isset( $raw['mode'] ) && 'auto' === $raw['mode'] ) ? 'auto' : 'review',
			'exclude'   => $exc,
			'qty_limit' => isset( $raw['qty_limit'] ) ? max( 1, (int) $raw['qty_limit'] ) : 50,
			'issuer'    => '' !== $iss ? $iss : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	private static function save_settings( array $s ): void {
		update_option( self::OPT_SET, wp_json_encode( $s, JSON_UNESCAPED_UNICODE ), false );
	}

	/* =====================================================================
	 * Store
	 * =================================================================== */

	/** @return array<string,array> ben => statement */
	public static function month_all( string $month ): array {
		if ( ! self::is_month( $month ) ) {
			return array();
		}
		$raw = json_decode( (string) get_option( self::OPT_PREFIX . $month, '' ), true );
		return is_array( $raw ) ? $raw : array();
	}

	private static function month_save( string $month, array $all ): void {
		update_option( self::OPT_PREFIX . $month, wp_json_encode( $all, JSON_UNESCAPED_UNICODE ), false );
		$months = self::months();
		if ( ! in_array( $month, $months, true ) ) {
			$months[] = $month;
			rsort( $months );
			update_option( self::OPT_MONTHS, wp_json_encode( array_values( $months ) ), false );
		}
	}

	/** @return string[] Months that have statements, newest first. */
	public static function months(): array {
		$raw = json_decode( (string) get_option( self::OPT_MONTHS, '' ), true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $m ) {
			if ( is_string( $m ) && self::is_month( $m ) ) {
				$out[] = $m;
			}
		}
		rsort( $out );
		return array_values( array_unique( $out ) );
	}

	public static function get( string $month, string $ben ): ?array {
		$all = self::month_all( $month );
		return isset( $all[ $ben ] ) && is_array( $all[ $ben ] ) ? $all[ $ben ] : null;
	}

	private static function put( array $st ): void {
		$all               = self::month_all( $st['month'] );
		$all[ $st['ben'] ] = $st;
		self::month_save( $st['month'], $all );
	}

	/* =====================================================================
	 * Making statements
	 * =================================================================== */

	/**
	 * Daily check: makes the previous month's statements once (and, in auto
	 * mode, sends the ones that need no review).
	 */
	public static function maybe_generate(): void {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		$prev = ( new DateTimeImmutable( 'first day of previous month', wp_timezone() ) )->format( 'Y-m' );
		if ( (string) get_option( self::OPT_LAST, '' ) >= $prev ) {
			return;
		}
		update_option( self::OPT_LAST, $prev, false ); // Before the work: a failure never repeats the sends.
		self::generate( $prev );
		if ( 'auto' === $s['mode'] ) {
			foreach ( self::month_all( $prev ) as $st ) {
				if ( 'draft' === $st['status'] && empty( $st['flags'] ) && ! $st['settlement'] ) {
					self::send( $prev, (string) $st['ben'] );
				}
			}
		}
	}

	/**
	 * Makes (or makes again) a month's statements. Sent and skipped ones are
	 * kept as they are.
	 *
	 * @return array{made:int,kept:int}
	 */
	public static function generate( string $month ): array {

		$out = array(
			'made' => 0,
			'kept' => 0,
		);
		if ( ! self::is_month( $month ) ) {
			return $out;
		}

		$set   = self::settings();
		$first = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', wp_timezone() );
		$start = $first->format( 'Y-m-d' );
		$end   = $first->format( 'Y-m-t' );
		$prior = $first->modify( '-1 day' )->format( 'Y-m-d' );

		$month_r = RS_Reports::run(
			array(
				'date_start' => $start,
				'date_end'   => $end,
			)
		);
		$open_s  = self::sales_until( $prior );
		$led_n   = array();
		foreach ( RS_Ledger::all() as $e ) {
			$led_n[] = (string) $e['beneficiary'];
		}
		$names = array_values( array_unique( array_merge( RS_Beneficiaries::collect_names(), $led_n, array_keys( $open_s ), array_keys( self::sales_until( $end ) ) ) ) );
		$all     = self::month_all( $month );
		$seq     = 0;
		foreach ( $all as $st ) {
			$seq = max( $seq, (int) substr( (string) ( $st['number'] ?? '' ), -3 ) );
		}

		foreach ( $names as $ben ) {
			$ben = (string) $ben;
			if ( '' === $ben || in_array( $ben, $set['exclude'], true ) ) {
				continue;
			}
			$old = $all[ $ben ] ?? null;
			if ( is_array( $old ) && in_array( $old['status'] ?? '', array( 'sent', 'skipped' ), true ) ) {
				$out['kept']++;
				continue;
			}

			$st = self::compute( $ben, $month, $start, $end, $prior, $month_r, $open_s, $set );
			if ( null === $st ) {
				continue; // Nothing to report.
			}
			if ( is_array( $old ) && ! empty( $old['number'] ) ) {
				$st['number'] = (string) $old['number'];
			} else {
				$seq++;
				$st['number'] = $month . '-' . sprintf( '%03d', $seq );
			}
			$all[ $ben ] = $st;
			$out['made']++;
		}

		ksort( $all );
		self::month_save( $month, $all );

		return $out;
	}

	/** One beneficiary's statement, or null when there is nothing in it. */
	private static function compute( string $ben, string $month, string $start, string $end, string $prior, array $month_r, array $open_s, array $set ): ?array {

		$opening = round( (float) ( $open_s[ $ben ] ?? 0.0 ) + RS_Ledger::net( $ben, '2000-01-01', $prior ), 2 );

		$rows  = array();
		$share = 0.0;
		$flags = array();
		foreach ( $month_r['products'] as $p ) {
			foreach ( $p['splits'] as $sp ) {
				if ( (string) $sp['name'] !== $ben ) {
					continue;
				}
				$amt    = round( (float) $sp['amount'], 2 );
				$share += $amt;
				$rows[] = array(
					'pid'    => (int) $p['product_id'],
					'title'  => (string) $p['title'],
					'qty'    => (int) $p['qty'],
					'pct'    => (float) $sp['percent'],
					'amount' => $amt,
				);
				$vat = self::vat_issue( (int) $p['product_id'], (float) $p['vat_rate'] );
				if ( '' !== $vat ) {
					$flags[] = array(
						'code'   => 'vat',
						'detail' => (string) $p['title'] . ': ' . $vat,
					);
				}
				if ( (int) $p['qty'] > $set['qty_limit'] ) {
					$flags[] = array(
						'code'   => 'qty',
						'detail' => (string) $p['title'] . ': ' . (int) $p['qty'],
					);
				}
				break;
			}
		}

		$ledger   = array();
		$income   = 0.0;
		$payments = 0.0;
		$deduct   = 0.0;
		foreach ( array_reverse( RS_Ledger::for_beneficiary( $ben ) ) as $e ) {
			if ( $e['date'] < $start || $e['date'] > $end ) {
				continue;
			}
			$ledger[] = array(
				'id'     => (int) $e['id'],
				'date'   => (string) $e['date'],
				'type'   => (string) $e['type'],
				'amount' => round( (float) $e['amount'], 2 ),
				// 1.17.0: a deduction's note carries its product's title.
				'note'   => RS_Ledger::describe( $e ),
			);
			if ( 'payment' === $e['type'] ) {
				$payments += (float) $e['amount'];
			} elseif ( 'expense' === $e['type'] ) {
				$deduct += (float) $e['amount'];
			} else {
				$income += (float) $e['amount'];
			}
		}

		$share   = round( $share, 2 );
		$income  = round( $income, 2 );
		$pay     = round( $payments, 2 );
		$deduct  = round( $deduct, 2 );
		$closing = round( $opening + $share + $income - $deduct - $pay, 2 );

		if ( abs( $opening ) < 0.005 && abs( $closing ) < 0.005 && empty( $rows ) && empty( $ledger ) ) {
			return null;
		}

		if ( $closing < -0.004 ) {
			$flags[] = array(
				'code'   => 'negative',
				'detail' => number_format( $closing, 2, ',', '.' ),
			);
		}
		$email = RS_Emails::get_email( $ben );
		if ( '' === $email ) {
			$flags[] = array(
				'code'   => 'no_email',
				'detail' => '',
			);
		}

		return array(
			'ben'        => $ben,
			'month'      => $month,
			'number'     => '',
			'made'       => gmdate( 'c' ),
			'start'      => $start,
			'end'        => $end,
			'opening'    => $opening,
			'rows'       => $rows,
			'share'      => $share,
			'ledger'     => $ledger,
			'income'     => $income,
			'deductions' => $deduct, // 1.17.0; statements made before lack it (read with ?? 0).
			'payments'   => $pay,
			'closing'    => $closing,
			'settlement' => '12' === substr( $month, 5, 2 ),
			'flags'      => $flags,
			'status'     => 'draft',
			'sent_at'    => '',
			'sent_to'    => '',
			'paid_entry' => 0,
			'issuer'     => $set['issuer'],
		);
	}

	/** @return float[] name => sales share from the start up to $date (inclusive). */
	private static function sales_until( string $date ): array {
		$r   = RS_Reports::run(
			array(
				'date_start' => '2000-01-01',
				'date_end'   => $date,
			)
		);
		$out = array();
		foreach ( $r['beneficiaries'] as $b ) {
			$out[ (string) $b['name'] ] = (float) $b['amount'];
		}
		return $out;
	}

	/**
	 * Does the VAT that RS removes fit the product? '' when it does.
	 * Rules of the publisher: books with an ISBN are 6%, other products
	 * with a GTIN (music UPC/EAN, merchandise) 24%; a product without tax in
	 * WooCommerce should not lose VAT in RS.
	 */
	public static function vat_issue( int $pid, float $rate ): string {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $p ) {
			return '';
		}
		$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : null;
		$taxed  = 'none' !== $p->get_tax_status() && ! ( $parent && 'none' === $parent->get_tax_status() && 'parent' === $p->get_tax_class() );
		if ( ! $taxed && $rate > 0 ) {
			/* translators: %s: VAT rate */
			return sprintf( 'WooCommerce χωρίς ΦΠΑ, RS %s%%', self::pct( $rate ) );
		}
		$gtin = '';
		if ( method_exists( $p, 'get_global_unique_id' ) ) {
			$gtin = preg_replace( '/\D/', '', (string) $p->get_global_unique_id() );
			if ( '' === $gtin && $parent && method_exists( $parent, 'get_global_unique_id' ) ) {
				$gtin = preg_replace( '/\D/', '', (string) $parent->get_global_unique_id() );
			}
		}
		if ( '' === $gtin ) {
			return '';
		}
		$isbn   = 13 === strlen( $gtin ) && in_array( substr( $gtin, 0, 3 ), array( '978', '979' ), true );
		$expect = $isbn ? 6.0 : 24.0;
		if ( abs( $rate - $expect ) > 0.001 ) {
			return sprintf( '%s %s → %s%%, RS %s%%', $isbn ? 'ISBN' : 'GTIN', $gtin, self::pct( $expect ), self::pct( $rate ) );
		}
		return '';
	}

	/* =====================================================================
	 * Sending, skipping, paying
	 * =================================================================== */

	/** @return string '' on success, else the reason. */
	public static function send( string $month, string $ben ): string {

		$st = self::get( $month, $ben );
		if ( null === $st ) {
			return 'not_found';
		}
		if ( 'sent' === $st['status'] ) {
			return 'already_sent';
		}
		$to = RS_Emails::get_email( $ben );
		if ( '' === $to ) {
			return 'no_email';
		}
		if ( ! RS_PDF::available() ) {
			return 'no_pdf';
		}

		$file = trailingslashit( get_temp_dir() ) . 'katastasi-' . sanitize_file_name( (string) $st['number'] ) . '.pdf';
		if ( false === file_put_contents( $file, self::pdf( $st ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp attachment, deleted right after.
			return 'no_file';
		}

		$ok = wp_mail(
			$to,
			self::subject( $st ),
			self::email_html( $st ),
			array( 'Content-Type: text/html; charset=UTF-8' ),
			array( $file )
		);
		wp_delete_file( $file );

		$st['status']  = $ok ? 'sent' : 'failed';
		$st['sent_at'] = $ok ? gmdate( 'c' ) : '';
		$st['sent_to'] = $ok ? $to : '';
		self::put( $st );

		return $ok ? '' : 'mail_failed';
	}

	public static function skip( string $month, string $ben ): bool {
		$st = self::get( $month, $ben );
		if ( null === $st || 'sent' === $st['status'] ) {
			return false;
		}
		$st['status'] = 'skipped';
		self::put( $st );
		return true;
	}

	/** Back to draft (a skipped statement can be made again). */
	public static function unskip( string $month, string $ben ): bool {
		$st = self::get( $month, $ben );
		if ( null === $st || 'skipped' !== $st['status'] ) {
			return false;
		}
		$st['status'] = 'draft';
		self::put( $st );
		return true;
	}

	/**
	 * Settlement: adds a Ledger payment of the December closing balance.
	 *
	 * @return string '' on success, else the reason.
	 */
	public static function mark_paid( string $month, string $ben, string $date ): string {
		$st = self::get( $month, $ben );
		if ( null === $st || empty( $st['settlement'] ) ) {
			return 'not_settlement';
		}
		if ( ! empty( $st['paid_entry'] ) ) {
			return 'already_paid';
		}
		if ( (float) $st['closing'] <= 0.004 ) {
			return 'nothing_to_pay';
		}
		$r = RS_Ledger::add_many(
			array(
				array(
					'type'        => 'payment',
					'date'        => $date,
					'beneficiary' => $ben,
					'amount'      => (float) $st['closing'],
					'note'        => 'Εκκαθάριση ' . substr( $month, 0, 4 ) . ' (κατάσταση ' . $st['number'] . ')',
				),
			)
		);
		if ( empty( $r['ok'] ) || empty( $r['entries'][0]['id'] ) ) {
			return 'ledger_refused';
		}
		$st['paid_entry'] = (int) $r['entries'][0]['id'];
		self::put( $st );
		return '';
	}

	/* =====================================================================
	 * Creator-facing texts (fixed Greek, see the docblock)
	 * =================================================================== */

	public static function title( array $st ): string {
		$y = substr( (string) $st['month'], 0, 4 );
		$m = (int) substr( (string) $st['month'], 5, 2 );
		return ! empty( $st['settlement'] )
			? 'Εκκαθάριση έτους ' . $y
			: 'Κατάσταση αμοιβών · ' . ( self::MONTHS_EL[ $m ] ?? '' ) . ' ' . $y;
	}

	private static function subject( array $st ): string {
		return self::title( $st ) . ' · ' . $st['issuer'];
	}

	private static function email_html( array $st ): string {
		$cur   = static function ( $v ) {
			return self::money( (float) $v );
		};
		$inner = '<h2 style="margin:0 0 4px;color:#f6f4ff;">' . esc_html( self::title( $st ) ) . '</h2>';
		$inner .= '<p style="margin:0 0 16px;color:#b7b2d6;font-size:0.9em;">' . esc_html( self::dmy( $st['start'] ) . ' – ' . self::dmy( $st['end'] ) . ' · αρ. ' . $st['number'] ) . '</p>';
		$inner .= '<p style="margin:0 0 14px;">Γεια σου <strong>' . esc_html( $st['ben'] ) . '</strong>,</p>';
		$inner .= '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;">';
		$lines  = array(
			array( 'Υπόλοιπο ' . self::dmy( $st['start'] ), $cur( $st['opening'] ), false ),
			array( 'Μερίδιο από πωλήσεις', $cur( $st['share'] ), false ),
		);
		if ( abs( (float) $st['income'] ) > 0.004 ) {
			$lines[] = array( 'Άλλα έσοδα', $cur( $st['income'] ), false );
		}
		if ( (float) ( $st['deductions'] ?? 0 ) > 0.004 ) {
			$lines[] = array( 'Κρατήσεις', $cur( -1 * (float) $st['deductions'] ), false );
		}
		if ( (float) $st['payments'] > 0.004 ) {
			$lines[] = array( 'Πληρωμές', $cur( -1 * (float) $st['payments'] ), false );
		}
		$lines[] = array( 'Υπόλοιπο ' . self::dmy( $st['end'] ), $cur( $st['closing'] ), true );
		foreach ( $lines as $l ) {
			$b      = $l[2] ? 'font-weight:700;color:#f6f4ff;border-top:1px solid #3a3f52;' : '';
			$inner .= '<tr><td style="padding:5px 0;' . $b . '">' . esc_html( $l[0] ) . '</td><td style="padding:5px 0;text-align:right;' . $b . '">' . esc_html( $l[1] ) . '</td></tr>';
		}
		$inner .= '</table>';
		$inner .= '<p style="margin:0 0 10px;">' . esc_html( self::closing_note( $st ) ) . '</p>';
		$inner .= '<p style="margin:0 0 10px;">Η αναλυτική κατάσταση, ανά τίτλο, είναι στο συνημμένο PDF.</p>';
		$inner .= '<p style="margin:16px 0 0;color:#b7b2d6;font-size:0.85em;">' . esc_html( $st['issuer'] ) . '</p>';
		return RS_Emails::wrap( $inner );
	}

	private static function closing_note( array $st ): string {
		if ( ! empty( $st['settlement'] ) ) {
			return (float) $st['closing'] > 0.004
				? 'Εκκαθάριση έτους: θα σου καταβληθούν ' . self::money( (float) $st['closing'] ) . '.'
				: 'Εκκαθάριση έτους: δεν υπάρχει ποσό προς καταβολή.';
		}
		return 'Η κατάσταση είναι ενημερωτική. Η εκκαθάριση και η πληρωμή γίνονται στο τέλος του έτους.';
	}

	/** The statement as a PDF (binary string). */
	public static function pdf( array $st ): string {

		$pdf  = new RS_PDF( self::title( $st ) . ' · ' . $st['ben'] );
		$dark = array( 0.11, 0.11, 0.14 );
		$mute = array( 0.42, 0.41, 0.5 );
		$acc  = array( 0.427, 0.29, 1.0 );
		$l    = 48.0;
		$r    = RS_PDF::W - 48.0;

		$head = static function () use ( $pdf, $st, $acc, $l, $r ) {
			$pdf->add_page();
			$pdf->rect( 0, 0, RS_PDF::W, 64, $acc );
			$pdf->text( $l, 40, (string) $st['issuer'], 15, true, 'L', array( 1, 1, 1 ) );
			$pdf->text( $r, 40, 'αρ. ' . $st['number'], 10, false, 'R', array( 1, 1, 1 ) );
			return 104.0;
		};

		$y = $head();
		$pdf->text( $l, $y, self::title( $st ), 18, true, 'L', $dark );
		$y += 24;
		$pdf->text( $l, $y, 'Δικαιούχος: ' . $st['ben'], 11, false, 'L', $dark );
		$y += 16;
		$pdf->text( $l, $y, 'Περίοδος: ' . self::dmy( $st['start'] ) . ' – ' . self::dmy( $st['end'] ) . '   ·   Έκδοση: ' . wp_date( 'd/m/Y' ), 9.5, false, 'L', $mute );
		$y += 30;

		$c_qty = $r - 150;
		$c_pct = $r - 90;
		$row   = static function ( string $label, string $qty, string $pct, string $amount, bool $bold = false ) use ( &$y, $pdf, $l, $r, $c_qty, $c_pct, $dark, $head ) {
			if ( $y > RS_PDF::H - 90 ) {
				$y = $head();
			}
			$lines = $pdf->wrap( $label, 10, $c_qty - $l - 60 );
			foreach ( $lines as $i => $ln ) {
				$pdf->text( $l, $y + $i * 13, $ln, 10, $bold, 'L', $dark );
			}
			$pdf->text( $c_qty, $y, $qty, 10, $bold, 'R', $dark );
			$pdf->text( $c_pct, $y, $pct, 10, $bold, 'R', $dark );
			$pdf->text( $r, $y, $amount, 10, $bold, 'R', $dark );
			$y += 13 * max( 1, count( $lines ) ) + 5;
			$pdf->line( $l, $y - 12, $r, $y - 12, array( 0.88, 0.88, 0.91 ), 0.5 );
		};
		$section = static function ( string $t ) use ( &$y, $pdf, $l, $acc, $head ) {
			if ( $y > RS_PDF::H - 110 ) {
				$y = $head();
			}
			$y += 8;
			$pdf->text( $l, $y, $t, 10.5, true, 'L', $acc );
			$y += 18;
		};

		// Column heads.
		$pdf->text( $l, $y, 'Περιγραφή', 9, true, 'L', $mute );
		$pdf->text( $c_qty, $y, 'Τεμ.', 9, true, 'R', $mute );
		$pdf->text( $c_pct, $y, '%', 9, true, 'R', $mute );
		$pdf->text( $r, $y, 'Ποσό', 9, true, 'R', $mute );
		$y += 8;
		$pdf->line( $l, $y, $r, $y, $dark, 0.8 );
		$y += 16;

		$row( 'Υπόλοιπο ' . self::dmy( $st['start'] ), '', '', self::money( (float) $st['opening'] ), true );

		$section( 'Πωλήσεις' );
		if ( empty( $st['rows'] ) ) {
			$row( 'Καμία πώληση αυτόν τον μήνα', '', '', self::money( 0 ) );
		}
		foreach ( $st['rows'] as $rw ) {
			$row( (string) $rw['title'], (string) (int) $rw['qty'], self::pct( (float) $rw['pct'] ) . '%', self::money( (float) $rw['amount'] ) );
		}

		$inc = array_filter(
			$st['ledger'],
			static function ( $e ) {
				return 'income' === $e['type'];
			}
		);
		$pay = array_filter(
			$st['ledger'],
			static function ( $e ) {
				return 'payment' === $e['type'];
			}
		);
		if ( ! empty( $inc ) ) {
			$section( 'Άλλα έσοδα' );
			foreach ( $inc as $e ) {
				$row( self::dmy( $e['date'] ) . ' · ' . $e['note'], '', '', self::money( (float) $e['amount'] ) );
			}
		}
		// 1.17.0: «Κρατήσεις», money kept from the balance (e.g. a share in a production).
		$ded = array_filter(
			$st['ledger'],
			static function ( $e ) {
				return 'expense' === $e['type'];
			}
		);
		if ( ! empty( $ded ) ) {
			$section( 'Κρατήσεις' );
			foreach ( $ded as $e ) {
				$row( self::dmy( $e['date'] ) . ' · ' . $e['note'], '', '', self::money( -1 * (float) $e['amount'] ) );
			}
		}
		if ( ! empty( $pay ) ) {
			$section( 'Πληρωμές' );
			foreach ( $pay as $e ) {
				$row( self::dmy( $e['date'] ) . ' · ' . $e['note'], '', '', self::money( -1 * (float) $e['amount'] ) );
			}
		}

		$y += 6;
		$pdf->line( $l, $y - 12, $r, $y - 12, $dark, 0.8 );
		$row( 'Υπόλοιπο ' . self::dmy( $st['end'] ), '', '', self::money( (float) $st['closing'] ), true );

		$y += 10;
		foreach ( array( self::closing_note( $st ), 'Τα ποσά είναι το μερίδιό σου από τις καθαρές πωλήσεις, χωρίς ΦΠΑ, μετά τις επιστροφές. Τα δωρεάν αντίτυπα δεν μετρούν.' ) as $note ) {
			foreach ( $pdf->wrap( $note, 9.5, $r - $l ) as $ln ) {
				if ( $y > RS_PDF::H - 60 ) {
					$y = $head();
				}
				$pdf->text( $l, $y, $ln, 9.5, false, 'L', $dark );
				$y += 13;
			}
			$y += 4;
		}

		$pdf->text( $l, RS_PDF::H - 32, (string) $st['issuer'] . ' · Revenue Splitter', 8, false, 'L', $mute );

		return $pdf->output();
	}

	/* =====================================================================
	 * Formatting
	 * =================================================================== */

	public static function money( float $v ): string {
		$s = number_format( abs( $v ), 2, ',', '.' ) . ' €';
		return $v < -0.004 ? '−' . $s : $s;
	}

	private static function pct( float $v ): string {
		return rtrim( rtrim( number_format( $v, 2, ',', '' ), '0' ), ',' );
	}

	private static function dmy( string $ymd ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );
		return $d ? $d->format( 'd/m/Y' ) : $ymd;
	}

	public static function is_month( string $m ): bool {
		return (bool) preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $m );
	}

	/** Flag codes → admin labels. */
	private static function flag_label( string $code ): string {
		switch ( $code ) {
			case 'vat':
				return __( 'ΦΠΑ', 'revenue-splitter' );
			case 'qty':
				return __( 'Πολλά τεμάχια', 'revenue-splitter' );
			case 'negative':
				return __( 'Αρνητικό υπόλοιπο', 'revenue-splitter' );
			case 'no_email':
				return __( 'Χωρίς email', 'revenue-splitter' );
		}
		return $code;
	}

	private static function status_label( array $st ): string {
		switch ( $st['status'] ) {
			case 'sent':
				return __( 'Στάλθηκε', 'revenue-splitter' );
			case 'skipped':
				return __( 'Παραλείφθηκε', 'revenue-splitter' );
			case 'failed':
				return __( 'Απέτυχε', 'revenue-splitter' );
		}
		return empty( $st['flags'] ) ? __( 'Πρόχειρο', 'revenue-splitter' ) : __( 'Κρατήθηκε', 'revenue-splitter' );
	}

	/* =====================================================================
	 * Admin
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			RS_Admin_UI::SLUG_MENU,
			__( 'Revenue Splitter — Καταστάσεις', 'revenue-splitter' ),
			__( 'RS Καταστάσεις', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/** POST actions (nonce + capability, PRG). */
	public static function route(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked right below.
		if ( ! isset( $_POST['rs_stmt_act'] ) ) {
			return;
		}
		if ( ! isset( $_POST['rs_stmt_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_stmt_nonce'] ) ), self::NONCE )
			|| ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$act   = sanitize_key( wp_unslash( $_POST['rs_stmt_act'] ) );
		$month = isset( $_POST['rs_stmt_month'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_stmt_month'] ) ) : '';
		$ben   = isset( $_POST['rs_stmt_ben'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_stmt_ben'] ) ) : '';
		$msg   = array();

		if ( 'settings' === $act ) {
			$old     = self::settings();
			$exclude = isset( $_POST['rs_stmt_exclude'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rs_stmt_exclude'] ) ) : array();
			$known   = RS_Beneficiaries::collect_names();
			$new     = array(
				'enabled'   => ! empty( $_POST['rs_stmt_enabled'] ),
				'mode'      => ( isset( $_POST['rs_stmt_mode'] ) && 'auto' === $_POST['rs_stmt_mode'] ) ? 'auto' : 'review',
				'exclude'   => array_values( array_intersect( $exclude, $known ) ),
				'qty_limit' => isset( $_POST['rs_stmt_qty'] ) ? max( 1, absint( $_POST['rs_stmt_qty'] ) ) : 50,
				'issuer'    => isset( $_POST['rs_stmt_issuer'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_stmt_issuer'] ) ) : '',
			);
			// Turning it on never sends an older month by itself: the daily
			// check starts with the month that ends after today.
			if ( $new['enabled'] && ! $old['enabled'] ) {
				$prev = ( new DateTimeImmutable( 'first day of previous month', wp_timezone() ) )->format( 'Y-m' );
				if ( (string) get_option( self::OPT_LAST, '' ) < $prev ) {
					update_option( self::OPT_LAST, $prev, false );
				}
			}
			self::save_settings( $new );
			$msg[] = 'saved';
		} elseif ( 'generate' === $act && self::is_month( $month ) ) {
			$r     = self::generate( $month );
			$msg[] = 'made:' . $r['made'] . ':' . $r['kept'];
		} elseif ( 'send' === $act ) {
			$e     = self::send( $month, $ben );
			$msg[] = '' === $e ? 'sent:1' : 'err:' . $e;
		} elseif ( 'send_sel' === $act ) {
			$sel = isset( $_POST['rs_stmt_sel'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rs_stmt_sel'] ) ) : array();
			$n   = 0;
			$bad = 0;
			foreach ( $sel as $b ) {
				$st = self::get( $month, $b );
				if ( null === $st || ! empty( $st['flags'] ) || ! in_array( $st['status'], array( 'draft', 'failed' ), true ) ) {
					$bad++;
					continue; // Held ones are sent one by one, on purpose.
				}
				'' === self::send( $month, $b ) ? $n++ : $bad++;
			}
			$msg[] = 'sent:' . $n . ':' . $bad;
		} elseif ( 'skip' === $act ) {
			$msg[] = self::skip( $month, $ben ) ? 'skipped' : 'err:not_found';
		} elseif ( 'unskip' === $act ) {
			$msg[] = self::unskip( $month, $ben ) ? 'unskipped' : 'err:not_found';
		} elseif ( 'paid' === $act ) {
			$date  = isset( $_POST['rs_stmt_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_stmt_date'] ) ) : '';
			$e     = self::mark_paid( $month, $ben, $date );
			$msg[] = '' === $e ? 'paid' : 'err:' . $e;
		}

		set_transient( 'rs_stmt_msg_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . ( self::is_month( $month ) ? '&month=' . $month : '' ) ) );
		exit;
	}

	/** admin-post.php?action=rs_stmt_pdf — shows one statement's PDF. */
	public static function stream_pdf(): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_stmt_pdf' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$ben   = isset( $_GET['ben'] ) ? sanitize_text_field( wp_unslash( $_GET['ben'] ) ) : '';
		$st    = self::get( $month, $ben );
		if ( null === $st || ! RS_PDF::available() ) {
			wp_die( esc_html__( 'Η κατάσταση δεν βρέθηκε.', 'revenue-splitter' ) );
		}
		$bin = self::pdf( $st );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="katastasi-' . sanitize_file_name( (string) $st['number'] ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $bin ) );
		echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	private static function notices(): void {
		$msg = get_transient( 'rs_stmt_msg_' . get_current_user_id() );
		if ( ! is_array( $msg ) ) {
			return;
		}
		delete_transient( 'rs_stmt_msg_' . get_current_user_id() );
		$errs = array(
			'not_found'      => __( 'Η κατάσταση δεν βρέθηκε.', 'revenue-splitter' ),
			'already_sent'   => __( 'Η κατάσταση έχει ήδη σταλεί.', 'revenue-splitter' ),
			'no_email'       => __( 'Ο δικαιούχος δεν έχει email.', 'revenue-splitter' ),
			'no_pdf'         => __( 'Λείπει η γραμματοσειρά του PDF (assets/fonts/rs-sans.ttf).', 'revenue-splitter' ),
			'no_file'        => __( 'Δεν γράφτηκε το προσωρινό αρχείο του PDF.', 'revenue-splitter' ),
			'mail_failed'    => __( 'Το WordPress δεν δέχτηκε το email. Δοκίμασε το δοκιμαστικό email στις Ρυθμίσεις.', 'revenue-splitter' ),
			'not_settlement' => __( 'Η πληρωμή σημειώνεται μόνο στην κατάσταση Δεκεμβρίου.', 'revenue-splitter' ),
			'already_paid'   => __( 'Η πληρωμή έχει ήδη σημειωθεί.', 'revenue-splitter' ),
			'nothing_to_pay' => __( 'Δεν υπάρχει ποσό προς πληρωμή.', 'revenue-splitter' ),
			'ledger_refused' => __( 'Το Καθολικό δεν δέχτηκε την πληρωμή (έλεγξε την ημερομηνία).', 'revenue-splitter' ),
		);
		foreach ( $msg as $m ) {
			$p  = explode( ':', (string) $m );
			$ok = true;
			if ( 'saved' === $p[0] ) {
				$t = __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'revenue-splitter' );
			} elseif ( 'made' === $p[0] ) {
				/* translators: 1: statements made, 2: statements kept (already sent or skipped) */
				$t = sprintf( __( 'Φτιάχτηκαν %1$d καταστάσεις. Έμειναν ως είχαν %2$d (σταλμένες ή παραλειμμένες).', 'revenue-splitter' ), (int) ( $p[1] ?? 0 ), (int) ( $p[2] ?? 0 ) );
			} elseif ( 'sent' === $p[0] ) {
				/* translators: 1: statements sent, 2: statements not sent */
				$t  = sprintf( __( 'Στάλθηκαν %1$d. Δεν στάλθηκαν %2$d.', 'revenue-splitter' ), (int) ( $p[1] ?? 0 ), (int) ( $p[2] ?? 0 ) );
				$ok = 0 === (int) ( $p[2] ?? 0 );
			} elseif ( 'skipped' === $p[0] ) {
				$t = __( 'Η κατάσταση παραλείφθηκε.', 'revenue-splitter' );
			} elseif ( 'unskipped' === $p[0] ) {
				$t = __( 'Η κατάσταση έγινε ξανά πρόχειρο.', 'revenue-splitter' );
			} elseif ( 'paid' === $p[0] ) {
				$t = __( 'Η πληρωμή γράφτηκε στο Καθολικό.', 'revenue-splitter' );
			} else {
				$t  = $errs[ $p[1] ?? '' ] ?? (string) $m;
				$ok = false;
			}
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . esc_html( $t ) . '</p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$set    = self::settings();
		$months = self::months();
		$prev   = ( new DateTimeImmutable( 'first day of previous month', wp_timezone() ) )->format( 'Y-m' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ( $months[0] ?? $prev );
		$month = self::is_month( $month ) ? $month : $prev;
		$list  = self::month_all( $month );
		$names = RS_Beneficiaries::collect_names();
		$today = wp_date( 'Y-m-d' );
		$pdf_n = wp_create_nonce( 'rs_stmt_pdf' );
		$hid   = static function ( string $act, string $ben = '' ) use ( $month ) {
			wp_nonce_field( self::NONCE, 'rs_stmt_nonce', false );
			echo '<input type="hidden" name="rs_stmt_act" value="' . esc_attr( $act ) . '" />';
			echo '<input type="hidden" name="rs_stmt_month" value="' . esc_attr( $month ) . '" />';
			if ( '' !== $ben ) {
				echo '<input type="hidden" name="rs_stmt_ben" value="' . esc_attr( $ben ) . '" />';
			}
		};
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Καταστάσεις', 'revenue-splitter' ); ?></h1>
			<?php self::notices(); ?>

			<p class="description">
				<?php esc_html_e( 'Μηνιαία κατάσταση αμοιβών για κάθε δικαιούχο: υπόλοιπο έναρξης, μερίδια ανά τίτλο, άλλα έσοδα και πληρωμές του Καθολικού, υπόλοιπο λήξης. Φεύγει ως email με συνημμένο PDF. Η κατάσταση Δεκεμβρίου είναι η εκκαθάριση του έτους.', 'revenue-splitter' ); ?>
			</p>
			<?php if ( ! RS_PDF::available() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Λείπει η γραμματοσειρά του PDF (assets/fonts/rs-sans.ttf).', 'revenue-splitter' ); ?></p></div>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Μήνας', 'revenue-splitter' ); ?></h2>
			<form method="get" class="rs-stmt-bar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<select name="month">
					<?php foreach ( array_unique( array_merge( array( $month ), $months ) ) as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $month ); ?>><?php echo esc_html( $m ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Προβολή', 'revenue-splitter' ); ?></button>
			</form>
			<form method="post" class="rs-stmt-bar">
				<?php
				wp_nonce_field( self::NONCE, 'rs_stmt_nonce', false );
				?>
				<input type="hidden" name="rs_stmt_act" value="generate" />
				<label><?php esc_html_e( 'Φτιάξε ή ανανέωσε τις καταστάσεις του μήνα', 'revenue-splitter' ); ?>
					<input type="month" name="rs_stmt_month" value="<?php echo esc_attr( $month ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m' ) ); ?>" required />
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Φτιάξε πρόχειρα', 'revenue-splitter' ); ?></button>
				<span class="rs-muted"><?php esc_html_e( 'Δεν στέλνεται τίποτα. Οι σταλμένες καταστάσεις μένουν ως έχουν.', 'revenue-splitter' ); ?></span>
			</form>

			<h2 class="rs-h2">
				<?php
				/* translators: %s: month YYYY-MM */
				echo esc_html( sprintf( __( 'Καταστάσεις %s', 'revenue-splitter' ), $month ) );
				?>
			</h2>
			<?php if ( empty( $list ) ) : ?>
				<p class="rs-muted"><?php esc_html_e( 'Δεν υπάρχουν καταστάσεις για αυτόν τον μήνα.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<form method="post" id="rs-stmt-sel">
					<?php $hid( 'send_sel' ); ?>
				</form>
				<table class="widefat striped rs-table rs-stmt-table">
					<thead>
						<tr>
							<th></th>
							<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Υπόλοιπο έναρξης', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Μερίδιο', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Καθολικό', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Υπόλοιπο λήξης', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Έλεγχοι', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Κατάσταση', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Ενέργειες', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $list as $st ) : ?>
						<?php
						$ben     = (string) $st['ben'];
						$can     = in_array( $st['status'], array( 'draft', 'failed' ), true );
						$pdf_url = add_query_arg(
							array(
								'action'   => 'rs_stmt_pdf',
								'month'    => $month,
								'ben'      => rawurlencode( $ben ),
								'_wpnonce' => $pdf_n,
							),
							admin_url( 'admin-post.php' )
						);
						?>
						<tr>
							<td>
								<?php if ( $can && empty( $st['flags'] ) ) : ?>
									<input type="checkbox" form="rs-stmt-sel" name="rs_stmt_sel[]" value="<?php echo esc_attr( $ben ); ?>" />
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html( $ben ); ?></strong><br /><small class="rs-muted"><?php echo esc_html( (string) $st['number'] ); ?></small></td>
							<td class="num"><?php echo esc_html( self::money( (float) $st['opening'] ) ); ?></td>
							<td class="num"><?php echo esc_html( self::money( (float) $st['share'] ) ); ?></td>
							<td class="num"><?php echo esc_html( self::money( (float) $st['income'] - (float) ( $st['deductions'] ?? 0 ) - (float) $st['payments'] ) ); ?></td>
							<td class="num"><strong><?php echo esc_html( self::money( (float) $st['closing'] ) ); ?></strong></td>
							<td>
								<?php if ( empty( $st['flags'] ) ) : ?>
									<span class="rs-muted">—</span>
								<?php else : ?>
									<?php foreach ( $st['flags'] as $f ) : ?>
										<span class="rs-stmt-flag" title="<?php echo esc_attr( (string) $f['detail'] ); ?>"><?php echo esc_html( self::flag_label( (string) $f['code'] ) ); ?></span>
										<?php if ( '' !== (string) $f['detail'] ) : ?>
											<small class="rs-muted"><?php echo esc_html( (string) $f['detail'] ); ?></small>
										<?php endif; ?>
										<br />
									<?php endforeach; ?>
								<?php endif; ?>
								<?php if ( ! empty( $st['settlement'] ) ) : ?>
									<span class="rs-stmt-flag rs-stmt-settle"><?php esc_html_e( 'Εκκαθάριση', 'revenue-splitter' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<span class="rs-stmt-st rs-stmt-st-<?php echo esc_attr( (string) $st['status'] ); ?>"><?php echo esc_html( self::status_label( $st ) ); ?></span>
								<?php if ( 'sent' === $st['status'] && '' !== (string) $st['sent_at'] ) : ?>
									<br /><small class="rs-muted"><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) strtotime( (string) $st['sent_at'] ) ) ); ?></small>
								<?php endif; ?>
								<?php if ( ! empty( $st['paid_entry'] ) ) : ?>
									<br /><small class="rs-muted">
									<?php
									/* translators: %d: ledger entry id */
									echo esc_html( sprintf( __( 'Πληρώθηκε (Καθολικό #%d)', 'revenue-splitter' ), (int) $st['paid_entry'] ) );
									?>
									</small>
								<?php endif; ?>
							</td>
							<td class="rs-stmt-actions">
								<a class="button" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener noreferrer">PDF</a>
								<?php if ( $can ) : ?>
									<form method="post">
										<?php $hid( 'send', $ben ); ?>
										<button type="submit" class="button button-primary" onclick="return confirm(this.dataset.q);"
											data-q="<?php echo esc_attr( empty( $st['flags'] ) ? __( 'Να σταλεί η κατάσταση στον δικαιούχο; Δεν παίρνεται πίσω.', 'revenue-splitter' ) : __( 'Η κατάσταση έχει ελέγχους που δεν πέρασαν. Να σταλεί παρ’ όλα αυτά; Δεν παίρνεται πίσω.', 'revenue-splitter' ) ); ?>">
											<?php esc_html_e( 'Αποστολή', 'revenue-splitter' ); ?>
										</button>
									</form>
									<form method="post">
										<?php $hid( 'skip', $ben ); ?>
										<button type="submit" class="button"><?php esc_html_e( 'Παράλειψη', 'revenue-splitter' ); ?></button>
									</form>
								<?php elseif ( 'skipped' === $st['status'] ) : ?>
									<form method="post">
										<?php $hid( 'unskip', $ben ); ?>
										<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά σε πρόχειρο', 'revenue-splitter' ); ?></button>
									</form>
								<?php endif; ?>
								<?php if ( ! empty( $st['settlement'] ) && empty( $st['paid_entry'] ) && (float) $st['closing'] > 0.004 ) : ?>
									<form method="post">
										<?php $hid( 'paid', $ben ); ?>
										<input type="date" name="rs_stmt_date" value="<?php echo esc_attr( $today ); ?>" required />
										<button type="submit" class="button" onclick="return confirm(this.dataset.q);"
											data-q="<?php echo esc_attr( sprintf( /* translators: %s: amount */ __( 'Να γραφτεί πληρωμή %s στο Καθολικό;', 'revenue-splitter' ), self::money( (float) $st['closing'] ) ) ); ?>">
											<?php esc_html_e( 'Πληρώθηκε', 'revenue-splitter' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" form="rs-stmt-sel" class="button button-primary" onclick="return confirm(this.dataset.q);"
						data-q="<?php esc_attr_e( 'Να σταλούν οι επιλεγμένες καταστάσεις; Δεν παίρνονται πίσω.', 'revenue-splitter' ); ?>">
						<?php esc_html_e( 'Αποστολή επιλεγμένων', 'revenue-splitter' ); ?>
					</button>
					<span class="rs-muted"><?php esc_html_e( 'Όσες έχουν έλεγχο που δεν πέρασε στέλνονται μόνο μία μία.', 'revenue-splitter' ); ?></span>
				</p>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Ρυθμίσεις καταστάσεων', 'revenue-splitter' ); ?></h2>
			<form method="post">
				<?php
				wp_nonce_field( self::NONCE, 'rs_stmt_nonce', false );
				?>
				<input type="hidden" name="rs_stmt_act" value="settings" />
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Μηνιαίες καταστάσεις', 'revenue-splitter' ); ?></th>
						<td><label><input type="checkbox" name="rs_stmt_enabled" value="1" <?php checked( $set['enabled'] ); ?> /> <?php esc_html_e( 'Φτιάχνονται αυτόματα κάθε 1η του μήνα για τον προηγούμενο μήνα', 'revenue-splitter' ); ?></label>
						<p class="description"><?php esc_html_e( 'Όταν το ενεργοποιείς, ο πρώτος αυτόματος μήνας είναι ο τρέχων. Παλαιότερους μήνες τους φτιάχνεις με το χέρι πιο πάνω.', 'revenue-splitter' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Αποστολή', 'revenue-splitter' ); ?></th>
						<td>
							<label><input type="radio" name="rs_stmt_mode" value="review" <?php checked( 'review', $set['mode'] ); ?> /> <?php esc_html_e( 'Έλεγχος από μένα: κάθε κατάσταση περιμένει να πατήσω Αποστολή', 'revenue-splitter' ); ?></label><br />
							<label><input type="radio" name="rs_stmt_mode" value="auto" <?php checked( 'auto', $set['mode'] ); ?> /> <?php esc_html_e( 'Αυτόματη αποστολή: φεύγουν μόλις φτιαχτούν', 'revenue-splitter' ); ?></label>
							<p class="description"><?php esc_html_e( 'Και στην αυτόματη αποστολή περιμένουν πάντα: οι καταστάσεις με έλεγχο που δεν πέρασε, όσοι δικαιούχοι δεν έχουν email και η εκκαθάριση του Δεκεμβρίου.', 'revenue-splitter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-stmt-issuer"><?php esc_html_e( 'Εκδότης', 'revenue-splitter' ); ?></label></th>
						<td><input type="text" class="regular-text" id="rs-stmt-issuer" name="rs_stmt_issuer" value="<?php echo esc_attr( $set['issuer'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Το όνομα στην κεφαλίδα του PDF και στο θέμα του email.', 'revenue-splitter' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-stmt-qty"><?php esc_html_e( 'Όριο τεμαχίων', 'revenue-splitter' ); ?></label></th>
						<td><input type="number" min="1" step="1" id="rs-stmt-qty" name="rs_stmt_qty" value="<?php echo esc_attr( (string) $set['qty_limit'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Πάνω από τόσα τεμάχια ενός τίτλου σε έναν μήνα, η κατάσταση κρατιέται για έλεγχο.', 'revenue-splitter' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Χωρίς κατάσταση', 'revenue-splitter' ); ?></th>
						<td class="rs-stmt-exclude">
							<?php foreach ( $names as $n ) : ?>
								<label><input type="checkbox" name="rs_stmt_exclude[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, $set['exclude'], true ) ); ?> /> <?php echo esc_html( $n ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Δικαιούχοι που δεν παίρνουν κατάσταση (π.χ. ο ίδιος ο εκδότης).', 'revenue-splitter' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'revenue-splitter' ); ?></button></p>
			</form>

			<?php RS_Admin_UI::footer(); ?>
		</div>
		<?php
	}
}
