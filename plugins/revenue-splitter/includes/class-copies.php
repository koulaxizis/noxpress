<?php
/**
 * Revenue Splitter — «Κινήσεις αντιτύπων», the copies register (1.19.0).
 *
 * Asked by Chris on 2026-11-16 (Γλαρόλυκοι, «Πλήρης εικόνα στο Author
 * portal», cards 3, 5, 6 and 7). Every copy that leaves the warehouse
 * without being an e-shop sale or a consignment is written here: promotion
 * or gift, review / press, legal deposit, author copies per contract
 * (free), author purchase, damaged / destroyed (all take copies out) and
 * return to the warehouse (puts copies back). No WooCommerce order is made.
 *
 *  - Stock: when the product manages stock, the movement changes it and
 *    keeps the change, so «Αναίρεση» (undo) puts it back exactly.
 *  - Author purchase: a unit price and, per entry (card 16/11 10:54Z), how
 *    it is paid: «offset» makes a Ledger «Κράτηση» (expense) of price ×
 *    copies for the beneficiary, linked to the movement (undo deletes it);
 *    «paid separately» changes no balance (the money goes to the Budget).
 *  - Warning, not a block: the same author has a WooCommerce order of the
 *    same title in the last 30 days (a possible double count).
 *  - When a creator's «Αίτημα αντιτύπων» is marked done, the form opens
 *    prefilled for confirmation.
 *  - The register starts empty from go-live (card 6): no back-entry.
 *  - Recipients: shown to the creator for media / press; a private person
 *    shows only as «ιδιώτης» (card 5).
 *
 * Storage: option rs_copies (JSON, autoload off), a list of movements
 * {id, date, pid, qty, type, rcpt, rkind (media | private | ''), ben, unit,
 * pay (offset | separate | ''), note, stock (signed change made, 0 = none),
 * ledger (Ledger entry id, 0 = none), req (request id), by, at, undone,
 * undone_at, undone_by}. Abilities: copies-list (read), copies-add (write,
 * dry_run), copies-undo (write).
 *
 * @package RevenueSplitter
 */

defined( 'ABSPATH' ) || exit;

final class RS_Copies {

	const OPT     = 'rs_copies';
	const SLUG    = 'revenue-splitter-copies';
	const NONCE   = 'rs_cp_admin';
	const MAX     = 5000;
	const QTY_MAX = 9999;
	const RCPT_MAX = 120;
	const NOTE_MAX = 500;
	const WARN_DAYS = 30;

	/** @var array[]|null */
	private static $cache = null;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'route_admin' ) );
	}

	/** Type => label, in the order of the form. */
	public static function types(): array {
		return array(
			'promo'       => __( 'Προώθηση / δώρο', 'revenue-splitter' ),
			'review'      => __( 'Κριτική / Τύπος', 'revenue-splitter' ),
			'legal'       => __( 'Νόμιμη κατάθεση', 'revenue-splitter' ),
			'author_free' => __( 'Αντίτυπα συγγραφέα από συμβόλαιο (δωρεάν)', 'revenue-splitter' ),
			'author_buy'  => __( 'Αγορά από τον συγγραφέα', 'revenue-splitter' ),
			'damaged'     => __( 'Φθαρμένα / καταστροφή', 'revenue-splitter' ),
			'return'      => __( 'Επιστροφή στην αποθήκη', 'revenue-splitter' ),
		);
	}

	/** Short labels for the portal's columns. */
	public static function short_types(): array {
		return array(
			'promo'       => __( 'Προώθηση', 'revenue-splitter' ),
			'review'      => __( 'Τύπος', 'revenue-splitter' ),
			'legal'       => __( 'Κατάθεση', 'revenue-splitter' ),
			'author_free' => __( 'δωρεάν', 'revenue-splitter' ),
			'author_buy'  => __( 'αγορά', 'revenue-splitter' ),
			'damaged'     => __( 'Φθαρμένα', 'revenue-splitter' ),
			'return'      => __( 'Επιστροφή', 'revenue-splitter' ),
		);
	}

	/** +1 when the type puts copies back in the warehouse, −1 otherwise. */
	public static function sign( string $type ): int {
		return 'return' === $type ? 1 : -1;
	}

	private static function needs_ben( string $type ): bool {
		return in_array( $type, array( 'author_free', 'author_buy' ), true );
	}

	/* =====================================================================
	 * Storage
	 * =================================================================== */

	/** @return array[] Every movement, newest first. */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPT, '' );
		$d   = is_array( $raw ) ? $raw : json_decode( is_string( $raw ) ? $raw : '', true );
		if ( is_string( $d ) ) {
			$d = json_decode( $d, true ); // Encoded twice by a remote tool.
		}
		$out = array();
		foreach ( is_array( $d ) ? $d : array() as $m ) {
			if ( is_array( $m ) && isset( $m['id'], $m['date'], $m['pid'], $m['qty'], $m['type'] ) && isset( self::types()[ (string) $m['type'] ] ) ) {
				$out[] = $m;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] ) ?: (int) $b['id'] <=> (int) $a['id'];
			}
		);
		self::$cache = $out;
		return $out;
	}

	private static function save( array $all ): void {
		update_option( self::OPT, (string) wp_json_encode( array_values( $all ), JSON_UNESCAPED_UNICODE ), false );
		self::$cache = null;
		do_action( 'rs_invalidate_cache' );
	}

	public static function get( int $id ): ?array {
		foreach ( self::all() as $m ) {
			if ( (int) $m['id'] === $id ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Movements, filtered: product (id), ben, type, from, to (Y-m-d),
	 * undone (include undone ones; default false).
	 */
	public static function query( array $f = array() ): array {
		$out = array();
		foreach ( self::all() as $m ) {
			if ( ! empty( $m['undone'] ) && empty( $f['undone'] ) ) {
				continue;
			}
			if ( ! empty( $f['product'] ) && (int) $m['pid'] !== (int) $f['product'] ) {
				continue;
			}
			if ( ! empty( $f['ben'] ) && (string) ( $m['ben'] ?? '' ) !== (string) $f['ben'] ) {
				continue;
			}
			if ( ! empty( $f['type'] ) && (string) $m['type'] !== (string) $f['type'] ) {
				continue;
			}
			if ( ! empty( $f['from'] ) && (string) $m['date'] < (string) $f['from'] ) {
				continue;
			}
			if ( ! empty( $f['to'] ) && (string) $m['date'] > (string) $f['to'] ) {
				continue;
			}
			$out[] = $m;
		}
		return $out;
	}

	/* =====================================================================
	 * Changes
	 * =================================================================== */

	private static function valid_date( string $d ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Money from a decimal string ("12,50", "12.5") or number, or null. */
	private static function amount( $v ): ?float {
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v >= 0 ? round( (float) $v, 2 ) : null;
		}
		$v = str_replace( array( ' ', '€' ), '', str_replace( ',', '.', trim( (string) $v ) ) );
		return ( '' !== $v && is_numeric( $v ) && (float) $v >= 0 ) ? round( (float) $v, 2 ) : null;
	}

	/**
	 * A movement, checked and normalised. $in: date (default today),
	 * product, qty, type, recipient, recipient_kind (media | private), ben,
	 * unit_price (author purchase), payment (offset | separate), note, req.
	 *
	 * @return array|WP_Error
	 */
	public static function validate( array $in ) {
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$date  = isset( $in['date'] ) && '' !== trim( (string) $in['date'] ) ? trim( (string) $in['date'] ) : $today;
		if ( ! self::valid_date( $date ) ) {
			return new WP_Error( 'rs_cp_date', __( 'Μη έγκυρη ημερομηνία.', 'revenue-splitter' ) );
		}
		$pid     = isset( $in['product'] ) ? absint( $in['product'] ) : 0;
		$product = $pid > 0 ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'rs_cp_product', __( 'Διάλεξε τίτλο.', 'revenue-splitter' ) );
		}
		$qty = isset( $in['qty'] ) ? (int) $in['qty'] : 0;
		if ( $qty < 1 || $qty > self::QTY_MAX ) {
			return new WP_Error( 'rs_cp_qty', __( 'Τα αντίτυπα πρέπει να είναι από 1 έως 9999.', 'revenue-splitter' ) );
		}
		$type = isset( $in['type'] ) ? sanitize_key( (string) $in['type'] ) : '';
		if ( ! isset( self::types()[ $type ] ) ) {
			return new WP_Error( 'rs_cp_type', __( 'Διάλεξε τύπο κίνησης.', 'revenue-splitter' ) );
		}
		$rcpt  = isset( $in['recipient'] ) ? mb_substr( trim( sanitize_text_field( (string) $in['recipient'] ) ), 0, self::RCPT_MAX ) : '';
		$rkind = isset( $in['recipient_kind'] ) ? sanitize_key( (string) $in['recipient_kind'] ) : '';
		if ( '' === $rcpt ) {
			$rkind = '';
		} elseif ( ! in_array( $rkind, array( 'media', 'private' ), true ) ) {
			$rkind = 'private'; // Personal data stays hidden unless said otherwise.
		}
		$ben = isset( $in['ben'] ) ? trim( sanitize_text_field( (string) $in['ben'] ) ) : '';
		if ( self::needs_ben( $type ) ) {
			if ( '' === $ben || ! in_array( $ben, RS_Beneficiaries::collect_names(), true ) ) {
				return new WP_Error( 'rs_cp_ben', __( 'Διάλεξε τον δικαιούχο (συγγραφέα).', 'revenue-splitter' ) );
			}
		} else {
			$ben = '';
		}
		$unit = 0.0;
		$pay  = '';
		if ( 'author_buy' === $type ) {
			$u = isset( $in['unit_price'] ) ? self::amount( $in['unit_price'] ) : null;
			if ( null === $u || $u <= 0 ) {
				return new WP_Error( 'rs_cp_price', __( 'Γράψε την τιμή ανά αντίτυπο της αγοράς.', 'revenue-splitter' ) );
			}
			$unit = $u;
			$pay  = isset( $in['payment'] ) ? sanitize_key( (string) $in['payment'] ) : '';
			if ( ! in_array( $pay, array( 'offset', 'separate' ), true ) ) {
				return new WP_Error( 'rs_cp_pay', __( 'Διάλεξε πώς πληρώνεται η αγορά: συμψηφισμός με το υπόλοιπο ή χωριστή πληρωμή.', 'revenue-splitter' ) );
			}
		}
		$note = isset( $in['note'] ) ? mb_substr( trim( sanitize_textarea_field( (string) $in['note'] ) ), 0, self::NOTE_MAX ) : '';
		return array(
			'date'  => $date,
			'pid'   => $pid,
			'qty'   => $qty,
			'type'  => $type,
			'rcpt'  => $rcpt,
			'rkind' => $rkind,
			'ben'   => $ben,
			'unit'  => $unit,
			'pay'   => $pay,
			'note'  => $note,
			'req'   => isset( $in['req'] ) ? mb_substr( sanitize_text_field( (string) $in['req'] ), 0, 40 ) : '',
		);
	}

	/**
	 * Possible double count: WooCommerce orders of the same title by the
	 * beneficiary (their email on file) in the last 30 days.
	 *
	 * @return int[] Order ids.
	 */
	public static function recent_orders( string $ben, int $pid, string $date ): array {
		$email = class_exists( 'RS_Emails' ) ? RS_Emails::get_email( $ben ) : '';
		if ( '' === $email ) {
			return array();
		}
		try {
			$to   = new DateTimeImmutable( $date . ' 23:59:59', wp_timezone() );
			$from = $to->modify( '-' . self::WARN_DAYS . ' days' );
		} catch ( Exception $e ) {
			return array();
		}
		$args   = array(
			'limit'        => 50,
			'type'         => 'shop_order',
			'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'date_created' => $from->getTimestamp() . '...' . $to->getTimestamp(),
			'return'       => 'objects',
		);
		$orders = wc_get_orders( $args + array( 'billing_email' => $email ) );
		$user   = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			$orders = array_merge( $orders, wc_get_orders( $args + array( 'customer_id' => (int) $user->ID ) ) );
		}
		$out = array();
		foreach ( $orders as $o ) {
			if ( ! $o instanceof WC_Order ) {
				continue;
			}
			foreach ( $o->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product && ( (int) $item->get_product_id() === $pid || (int) $item->get_variation_id() === $pid ) ) {
					$out[ $o->get_id() ] = $o->get_id();
				}
			}
		}
		return array_values( $out );
	}

	/** The Ledger note of an offset author purchase. */
	private static function ledger_note( array $m, int $id ): string {
		/* translators: 1: copies, 2: title, 3: movement number */
		return sprintf( __( 'Αγορά αντιτύπων: %1$d × %2$s (κίνηση αντιτύπων #%3$d)', 'revenue-splitter' ), (int) $m['qty'], wp_strip_all_tags( (string) get_the_title( (int) $m['pid'] ) ), $id );
	}

	/**
	 * Records a movement. $dry = true only checks it and shows what would
	 * happen (stock change, Ledger deduction, warnings).
	 *
	 * @return array|WP_Error {dry_run, movement, stock: {managed, before, after}, ledger: {amount, note}|null, warnings: string[], orders: int[]}
	 */
	public static function add( array $in, bool $dry = false ) {
		$m = self::validate( $in );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		$all = self::all();
		if ( count( $all ) >= self::MAX ) {
			return new WP_Error( 'rs_cp_full', __( 'Το μητρώο έχει φτάσει το όριο των 5.000 κινήσεων.', 'revenue-splitter' ) );
		}
		$id = 0;
		foreach ( $all as $e ) {
			$id = max( $id, (int) $e['id'] );
		}
		++$id;

		$product = wc_get_product( (int) $m['pid'] );
		$managed = $product instanceof WC_Product && $product->managing_stock();
		$before  = $managed ? (int) $product->get_stock_quantity() : null;
		$delta   = $managed ? self::sign( $m['type'] ) * (int) $m['qty'] : 0;

		$warn   = array();
		$orders = array();
		if ( self::needs_ben( $m['type'] ) ) {
			$orders = self::recent_orders( $m['ben'], (int) $m['pid'], $m['date'] );
			if ( $orders ) {
				/* translators: 1: order numbers, 2: days */
				$warn[] = sprintf( __( 'Προσοχή: ο δικαιούχος έχει παραγγελία του ίδιου τίτλου στο e-shop τις τελευταίες %2$d ημέρες (%1$s). Αν είναι η ίδια αγορά, μην την καταχωρήσεις εδώ, για να μη μετρηθεί δύο φορές.', 'revenue-splitter' ), '#' . implode( ', #', $orders ), self::WARN_DAYS );
			}
		}
		if ( $managed && $delta < 0 && null !== $before && $before + $delta < 0 ) {
			$warn[] = __( 'Προσοχή: το απόθεμα θα γίνει αρνητικό.', 'revenue-splitter' );
		}
		$ledger = null;
		if ( 'author_buy' === $m['type'] && 'offset' === $m['pay'] ) {
			$ledger = array(
				'amount' => round( $m['unit'] * $m['qty'], 2 ),
				'note'   => self::ledger_note( $m, $id ),
			);
		}

		$out = array(
			'dry_run'  => $dry,
			'movement' => array( 'id' => $dry ? 0 : $id ) + $m,
			'stock'    => array(
				'managed' => $managed,
				'before'  => $before,
				'after'   => $managed ? $before + $delta : null,
			),
			'ledger'   => $ledger,
			'warnings' => $warn,
			'orders'   => $orders,
		);
		if ( $dry ) {
			return $out;
		}

		$ledger_id = 0;
		if ( null !== $ledger ) {
			$ok = RS_Ledger::add(
				array(
					'type'        => 'expense',
					'date'        => $m['date'],
					'beneficiary' => $m['ben'],
					'amount'      => $ledger['amount'],
					'note'        => $ledger['note'],
					'product_id'  => (int) $m['pid'],
				)
			);
			if ( true !== $ok ) {
				return new WP_Error( 'rs_cp_ledger', (string) $ok );
			}
			foreach ( RS_Ledger::for_beneficiary( $m['ben'], 'expense' ) as $e ) {
				if ( $e['note'] === $ledger['note'] ) {
					$ledger_id = max( $ledger_id, (int) $e['id'] );
				}
			}
		}
		if ( 0 !== $delta && $product instanceof WC_Product ) {
			wc_update_product_stock( $product, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
		}

		$row  = array( 'id' => $id ) + $m + array(
			'stock'  => $delta,
			'ledger' => $ledger_id,
			'by'     => get_current_user_id(),
			'at'     => gmdate( 'c' ),
			'undone' => false,
		);
		$all[] = $row;
		self::save( $all );
		$out['movement'] = $row;
		do_action( 'rs_copies_changed', $id, $row );
		return $out;
	}

	/**
	 * «Αναίρεση»: the stock goes back by the change the movement made, its
	 * Ledger deduction is deleted, and the movement stays, marked undone.
	 *
	 * @return array|WP_Error The movement.
	 */
	public static function undo( int $id ) {
		$all = self::all();
		foreach ( $all as $i => $m ) {
			if ( (int) $m['id'] !== $id ) {
				continue;
			}
			if ( ! empty( $m['undone'] ) ) {
				return new WP_Error( 'rs_cp_undone', __( 'Η κίνηση έχει ήδη αναιρεθεί.', 'revenue-splitter' ) );
			}
			$delta = (int) ( $m['stock'] ?? 0 );
			if ( 0 !== $delta ) {
				$product = wc_get_product( (int) $m['pid'] );
				if ( $product instanceof WC_Product && $product->managing_stock() ) {
					wc_update_product_stock( $product, abs( $delta ), $delta < 0 ? 'increase' : 'decrease' );
				}
			}
			if ( (int) ( $m['ledger'] ?? 0 ) > 0 ) {
				RS_Ledger::delete( (int) $m['ledger'] );
			}
			$all[ $i ]['undone']    = true;
			$all[ $i ]['undone_at'] = gmdate( 'c' );
			$all[ $i ]['undone_by'] = get_current_user_id();
			self::save( $all );
			do_action( 'rs_copies_changed', $id, $all[ $i ] );
			return $all[ $i ];
		}
		return new WP_Error( 'rs_cp_none', __( 'Η κίνηση δεν βρέθηκε.', 'revenue-splitter' ) );
	}

	/* =====================================================================
	 * Totals for the portal
	 * =================================================================== */

	/** Copies per product and type (not undone): pid => type => qty. */
	public static function totals( array $pids = array() ): array {
		$want = array_flip( array_map( 'intval', $pids ) );
		$out  = array();
		foreach ( self::all() as $m ) {
			if ( ! empty( $m['undone'] ) || ( $want && ! isset( $want[ (int) $m['pid'] ] ) ) ) {
				continue;
			}
			$out[ (int) $m['pid'] ][ (string) $m['type'] ] = ( $out[ (int) $m['pid'] ][ (string) $m['type'] ] ?? 0 ) + (int) $m['qty'];
		}
		return $out;
	}

	/** What the creator sees of a recipient: media names, «ιδιώτης» for a private person. */
	public static function recipient_shown( array $m ): string {
		if ( '' === (string) ( $m['rcpt'] ?? '' ) ) {
			return '';
		}
		return 'media' === ( $m['rkind'] ?? '' ) ? (string) $m['rcpt'] : __( 'ιδιώτης', 'revenue-splitter' );
	}

	/**
	 * «Πορεία αντιτύπων» per title of the beneficiary (titles where they have
	 * a share), all time: sold on the e-shop, sold via bookstores, on
	 * consignment, free by type, the author's copies, stock.
	 */
	public static function render_portal( string $who ): void {
		$life = RS_Reports::run(
			array(
				'date_start' => '2000-01-01',
				'date_end'   => ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ),
			)
		);
		$rep  = array();
		foreach ( $life['products'] as $p ) {
			$rep[ (int) $p['product_id'] ] = $p;
		}
		$cons = RS_Consignment::available() ? (array) tra_consignment_summary() : array();
		$cp   = self::totals();
		$pids = array_unique( array_merge( array_keys( $rep ), array_map( 'intval', array_keys( $cons ) ), array_keys( $cp ) ) );
		$mine = array();
		foreach ( $pids as $pid ) {
			if ( null !== RS_Consignment::percent_of( (int) $pid, $who ) ) {
				$mine[] = (int) $pid;
			}
		}
		if ( empty( $mine ) ) {
			return;
		}
		$short = self::short_types();
		$rows  = array();
		foreach ( $mine as $pid ) {
			$p      = $rep[ $pid ] ?? array();
			$tra_q  = (int) ( $p['consign']['qty'] ?? 0 );
			$book_s = 0;
			$shelf  = 0;
			foreach ( (array) ( $cons[ $pid ] ?? array() ) as $r ) {
				$book_s += (int) $r['sold'];
				$shelf  += (int) $r['open'];
			}
			$t    = $cp[ $pid ] ?? array();
			$free = array();
			if ( (int) ( $p['qty_free'] ?? 0 ) > 0 ) {
				$free[] = __( 'e-shop', 'revenue-splitter' ) . ' ' . number_format_i18n( (int) $p['qty_free'] );
			}
			foreach ( array( 'promo', 'review', 'legal' ) as $k ) {
				if ( ! empty( $t[ $k ] ) ) {
					$free[] = $short[ $k ] . ' ' . number_format_i18n( (int) $t[ $k ] );
				}
			}
			$auth = array();
			foreach ( array( 'author_free', 'author_buy' ) as $k ) {
				if ( ! empty( $t[ $k ] ) ) {
					$auth[] = number_format_i18n( (int) $t[ $k ] ) . ' ' . $short[ $k ];
				}
			}
			$rows[] = array(
				'title' => wp_strip_all_tags( (string) get_the_title( $pid ) ),
				'shop'  => max( 0, (int) ( $p['qty'] ?? 0 ) - $tra_q ),
				'books' => $book_s,
				'shelf' => $shelf,
				'free'  => $free,
				'auth'  => $auth,
				'stock' => RS_Admin_UI::product_stock( $pid, true ),
			);
		}
		$list = array();
		foreach ( self::all() as $m ) {
			if ( empty( $m['undone'] ) && in_array( (int) $m['pid'], $mine, true ) ) {
				$list[] = $m;
			}
		}
		$types = self::types();
		?>
		<h3 id="rs-copies-path"><?php esc_html_e( 'Πορεία αντιτύπων', 'revenue-splitter' ); ?></h3>
		<div class="rs-pt-scroll">
		<table class="rs-pt-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Πουλήθηκαν στο e-shop', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Πουλήθηκαν σε βιβλιοπωλεία', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Σε παρακαταθήκη', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Δωρεάν', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Του συγγραφέα', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Στοκ', 'revenue-splitter' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['title'] ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['shop'] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['books'] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['shelf'] ) ); ?></td>
					<td><?php echo $r['free'] ? esc_html( implode( ' · ', $r['free'] ) ) : '<span class="rs-pt-muted">—</span>'; ?></td>
					<td><?php echo $r['auth'] ? esc_html( implode( ' · ', $r['auth'] ) ) : '<span class="rs-pt-muted">—</span>'; ?></td>
					<td class="num"><?php echo esc_html( $r['stock'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ( ! empty( $list ) ) : ?>
			<details class="rs-pf-copies">
				<summary><?php esc_html_e( 'Κινήσεις αντιτύπων εκτός πωλήσεων', 'revenue-splitter' ); ?></summary>
				<div class="rs-pt-scroll">
				<table class="rs-pt-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Τύπος', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Αντίτυπα', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Παραλήπτης', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $list, 0, 200 ) as $m ) : ?>
						<?php
						$shown = self::needs_ben( (string) $m['type'] ) ? __( 'συγγραφέας', 'revenue-splitter' ) : self::recipient_shown( $m );
						?>
						<tr>
							<td><?php echo esc_html( RS_Contracts::dmy( (string) $m['date'] ) ); ?></td>
							<td><?php echo esc_html( wp_strip_all_tags( (string) get_the_title( (int) $m['pid'] ) ) ); ?></td>
							<td><?php echo esc_html( $types[ (string) $m['type'] ] ); ?></td>
							<td class="num"><?php echo esc_html( ( self::sign( (string) $m['type'] ) > 0 ? '+' : '−' ) . number_format_i18n( (int) $m['qty'] ) ); ?></td>
							<td><?php echo '' !== $shown ? esc_html( $shown ) : '<span class="rs-pt-muted">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</details>
		<?php endif; ?>
		<p class="rs-pt-note"><?php esc_html_e( 'Όλη η ιστορία. «Σε βιβλιοπωλεία» = αντίτυπα που δήλωσε πουλημένα το βιβλιοπωλείο, πληρωμένα ή όχι. Τα δωρεάν αντίτυπα και τα αντίτυπα του συγγραφέα δεν φέρνουν μερίδιο. Οι αγορές του συγγραφέα με συμψηφισμό φαίνονται ως «Κράτηση» στο ιστορικό συναλλαγών.', 'revenue-splitter' ); ?></p>
		<?php
	}

	/* =====================================================================
	 * Admin: «RS Αντίτυπα»
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			RS_Admin_UI::SLUG_MENU,
			__( 'Revenue Splitter — Αντίτυπα', 'revenue-splitter' ),
			__( 'RS Αντίτυπα', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			self::SLUG,
			array( __CLASS__, 'render_admin' )
		);
	}

	private static function msg_key(): string {
		return 'rs_cp_msg_' . get_current_user_id();
	}

	public static function route_admin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked right below.
		if ( ! isset( $_POST['rs_cp_act'] ) ) {
			return;
		}
		if ( ! isset( $_POST['rs_cp_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_cp_nonce'] ) ), self::NONCE )
			|| ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$act = sanitize_key( wp_unslash( $_POST['rs_cp_act'] ) );
		$msg = array();
		if ( 'add' === $act ) {
			$in  = array();
			foreach ( array( 'date', 'product', 'qty', 'type', 'recipient', 'recipient_kind', 'ben', 'unit_price', 'payment', 'note', 'req' ) as $k ) {
				$in[ $k ] = isset( $_POST[ 'rs_cp_' . $k ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ 'rs_cp_' . $k ] ) ) : '';
			}
			$res = self::add( $in );
			if ( is_wp_error( $res ) ) {
				$msg = array( 'error' => $res->get_error_message() );
			} else {
				$msg = array(
					'ok'   => sprintf( /* translators: %d: movement number */ __( 'Η κίνηση #%d καταχωρήθηκε.', 'revenue-splitter' ), (int) $res['movement']['id'] ),
					'warn' => $res['warnings'],
				);
			}
		} elseif ( 'undo' === $act ) {
			$res = self::undo( isset( $_POST['rs_cp_id'] ) ? absint( $_POST['rs_cp_id'] ) : 0 );
			$msg = is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : array( 'ok' => sprintf( /* translators: %d: movement number */ __( 'Η κίνηση #%d αναιρέθηκε.', 'revenue-splitter' ), (int) $res['id'] ) );
		}
		set_transient( self::msg_key(), $msg, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&rs_cp_done=1' ) );
		exit;
	}

	public static function render_admin(): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$msg = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		if ( isset( $_GET['rs_cp_done'] ) ) {
			$msg = get_transient( self::msg_key() );
			delete_transient( self::msg_key() );
			$msg = is_array( $msg ) ? $msg : array();
		}
		// Prefill from a creator's request marked done (RS_Profile).
		$pre = array(
			'type' => 'promo',
			'qty'  => 1,
			'pid'  => 0,
			'ben'  => '',
			'note' => '',
			'req'  => '',
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
		$rid = isset( $_GET['rs_cp_req'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_cp_req'] ) ) : '';
		$req = '' !== $rid ? RS_Profile::request( $rid ) : null;
		if ( is_array( $req ) && 'copies' === ( $req['type'] ?? '' ) ) {
			$pre = array(
				'type' => 'author_free',
				'qty'  => (int) $req['qty'],
				'pid'  => (int) $req['product_id'],
				'ben'  => (string) $req['ben'],
				/* translators: %s: date */
				'note' => sprintf( __( 'Αίτημα αντιτύπων του %s', 'revenue-splitter' ), wp_date( 'd/m/Y', (int) strtotime( (string) $req['at'] ) ) ),
				'req'  => (string) $req['id'],
			);
		}
		$products = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page'   => 2000,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		$names = RS_Beneficiaries::collect_names();
		$types = self::types();
		$list  = array_slice( self::query( array( 'undone' => true ) ), 0, 300 );
		$cur   = static function ( $v ) {
			return wp_strip_all_tags( wc_price( (float) $v ) );
		};
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Αντίτυπα', 'revenue-splitter' ); ?></h1>
			<?php if ( ! empty( $msg['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( (string) $msg['error'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $msg['ok'] ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( (string) $msg['ok'] ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( (array) ( $msg['warn'] ?? array() ) as $w ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( (string) $w ); ?></p></div>
			<?php endforeach; ?>
			<?php if ( '' !== $pre['req'] ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Το αίτημα αντιτύπων σημειώθηκε ως ολοκληρωμένο. Έλεγξε την κίνηση παρακάτω και πάτησε «Καταχώριση» αν τα αντίτυπα δόθηκαν.', 'revenue-splitter' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Κάθε αντίτυπο που βγαίνει από την αποθήκη χωρίς να είναι πώληση του e-shop ή παρακαταθήκη. Δεν φτιάχνει παραγγελίες. Όταν ο τίτλος έχει ενεργό απόθεμα WooCommerce, η κίνηση το αλλάζει και η «Αναίρεση» το επαναφέρει.', 'revenue-splitter' ); ?></p>

			<h2 class="rs-h2"><?php esc_html_e( 'Νέα κίνηση', 'revenue-splitter' ); ?></h2>
			<form method="post" class="rs-cp-form">
				<?php wp_nonce_field( self::NONCE, 'rs_cp_nonce' ); ?>
				<input type="hidden" name="rs_cp_act" value="add" />
				<input type="hidden" name="rs_cp_req" value="<?php echo esc_attr( $pre['req'] ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rs-cp-date"><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></label></th>
						<td><input type="date" id="rs-cp-date" name="rs_cp_date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-product"><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></label></th>
						<td>
							<select id="rs-cp-product" name="rs_cp_product" required>
								<option value=""><?php esc_html_e( '— Διάλεξε —', 'revenue-splitter' ); ?></option>
								<?php foreach ( $products as $pid ) : ?>
									<option value="<?php echo esc_attr( (string) $pid ); ?>"<?php selected( (int) $pid, $pre['pid'] ); ?>><?php echo esc_html( wp_strip_all_tags( (string) get_the_title( (int) $pid ) ) . ' (#' . $pid . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-type"><?php esc_html_e( 'Τύπος', 'revenue-splitter' ); ?></label></th>
						<td>
							<select id="rs-cp-type" name="rs_cp_type">
								<?php foreach ( $types as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $k, $pre['type'] ); ?>><?php echo esc_html( $label . ' (' . ( self::sign( $k ) > 0 ? '+' : '−' ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-qty"><?php esc_html_e( 'Αντίτυπα', 'revenue-splitter' ); ?></label></th>
						<td><input type="number" id="rs-cp-qty" name="rs_cp_qty" min="1" max="<?php echo esc_attr( (string) self::QTY_MAX ); ?>" value="<?php echo esc_attr( (string) $pre['qty'] ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-ben"><?php esc_html_e( 'Δικαιούχος (συγγραφέας)', 'revenue-splitter' ); ?></label></th>
						<td>
							<select id="rs-cp-ben" name="rs_cp_ben">
								<option value=""><?php esc_html_e( '—', 'revenue-splitter' ); ?></option>
								<?php foreach ( $names as $n ) : ?>
									<option value="<?php echo esc_attr( $n ); ?>"<?php selected( $n, $pre['ben'] ); ?>><?php echo esc_html( $n ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Μόνο για αντίτυπα και αγορά του συγγραφέα.', 'revenue-splitter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-recipient"><?php esc_html_e( 'Παραλήπτης (προαιρετικό)', 'revenue-splitter' ); ?></label></th>
						<td>
							<input type="text" id="rs-cp-recipient" name="rs_cp_recipient" maxlength="<?php echo esc_attr( (string) self::RCPT_MAX ); ?>" class="regular-text" />
							<select name="rs_cp_recipient_kind" aria-label="<?php esc_attr_e( 'Είδος παραλήπτη', 'revenue-splitter' ); ?>">
								<option value="private"><?php esc_html_e( 'Ιδιώτης (ο δημιουργός βλέπει «ιδιώτης»)', 'revenue-splitter' ); ?></option>
								<option value="media"><?php esc_html_e( 'Μέσο / Τύπος (ο δημιουργός βλέπει το όνομα)', 'revenue-splitter' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-unit"><?php esc_html_e( 'Τιμή ανά αντίτυπο', 'revenue-splitter' ); ?></label></th>
						<td>
							<input type="text" id="rs-cp-unit" name="rs_cp_unit_price" inputmode="decimal" class="small-text" placeholder="0,00" />
							<select name="rs_cp_payment" aria-label="<?php esc_attr_e( 'Πληρωμή', 'revenue-splitter' ); ?>">
								<option value=""><?php esc_html_e( '— Πληρωμή —', 'revenue-splitter' ); ?></option>
								<option value="offset"><?php esc_html_e( 'Συμψηφισμός με το υπόλοιπο (Κράτηση στο μητρώο)', 'revenue-splitter' ); ?></option>
								<option value="separate"><?php esc_html_e( 'Πληρώθηκε χωριστά (χωρίς αλλαγή στο υπόλοιπο)', 'revenue-splitter' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Μόνο για «Αγορά από τον συγγραφέα». Αν η αγορά έγινε από το e-shop, μην την καταχωρήσεις εδώ.', 'revenue-splitter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-cp-note"><?php esc_html_e( 'Σημείωση', 'revenue-splitter' ); ?></label></th>
						<td><textarea id="rs-cp-note" name="rs_cp_note" rows="2" class="large-text" maxlength="<?php echo esc_attr( (string) self::NOTE_MAX ); ?>"><?php echo esc_textarea( $pre['note'] ); ?></textarea></td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Καταχώριση', 'revenue-splitter' ); ?></button></p>
			</form>

			<h2 class="rs-h2"><?php esc_html_e( 'Κινήσεις', 'revenue-splitter' ); ?></h2>
			<?php if ( empty( $list ) ) : ?>
				<p><?php esc_html_e( 'Καμία κίνηση ακόμα.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th>#</th>
						<th><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Τύπος', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Αντίτυπα', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Παραλήπτης', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Ποσό', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Απόθεμα', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Σημείωση', 'revenue-splitter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $list as $m ) : ?>
					<tr<?php echo ! empty( $m['undone'] ) ? ' style="opacity:.55"' : ''; ?>>
						<td><?php echo esc_html( (string) (int) $m['id'] ); ?></td>
						<td><?php echo esc_html( RS_Contracts::dmy( (string) $m['date'] ) ); ?></td>
						<td><?php echo esc_html( wp_strip_all_tags( (string) get_the_title( (int) $m['pid'] ) ) ); ?></td>
						<td><?php echo esc_html( $types[ (string) $m['type'] ] ); ?></td>
						<td class="num"><?php echo esc_html( ( self::sign( (string) $m['type'] ) > 0 ? '+' : '−' ) . (int) $m['qty'] ); ?></td>
						<td><?php echo esc_html( '' !== (string) ( $m['ben'] ?? '' ) ? (string) $m['ben'] : '—' ); ?></td>
						<td><?php echo esc_html( '' !== (string) ( $m['rcpt'] ?? '' ) ? $m['rcpt'] . ' (' . ( 'media' === $m['rkind'] ? __( 'μέσο', 'revenue-splitter' ) : __( 'ιδιώτης', 'revenue-splitter' ) ) . ')' : '—' ); ?></td>
						<td>
							<?php
							if ( 'author_buy' === $m['type'] ) {
								echo esc_html( $cur( (float) $m['unit'] * (int) $m['qty'] ) . ' · ' . ( 'offset' === $m['pay'] ? __( 'συμψηφισμός', 'revenue-splitter' ) . ( (int) $m['ledger'] > 0 ? ' (#' . (int) $m['ledger'] . ')' : '' ) : __( 'χωριστά', 'revenue-splitter' ) ) );
							} else {
								echo '—';
							}
							?>
						</td>
						<td><?php echo esc_html( 0 !== (int) $m['stock'] ? sprintf( '%+d', (int) $m['stock'] ) : __( 'χωρίς αλλαγή', 'revenue-splitter' ) ); ?></td>
						<td><?php echo esc_html( (string) $m['note'] ); ?></td>
						<td>
							<?php if ( empty( $m['undone'] ) ) : ?>
								<form method="post" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Αναίρεση της κίνησης; Το απόθεμα και η κράτηση επανέρχονται.', 'revenue-splitter' ) ) ); ?>);">
									<?php wp_nonce_field( self::NONCE, 'rs_cp_nonce' ); ?>
									<input type="hidden" name="rs_cp_act" value="undo" />
									<input type="hidden" name="rs_cp_id" value="<?php echo esc_attr( (string) (int) $m['id'] ); ?>" />
									<button type="submit" class="button button-small"><?php esc_html_e( 'Αναίρεση', 'revenue-splitter' ); ?></button>
								</form>
							<?php else : ?>
								<em><?php esc_html_e( 'αναιρέθηκε', 'revenue-splitter' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Abilities
	 * =================================================================== */

	/** The movement for callers: field names in plain words. */
	private static function api_row( array $m ): array {
		return array(
			'id'             => (int) $m['id'],
			'date'           => (string) $m['date'],
			'product_id'     => (int) $m['pid'],
			'title'          => wp_strip_all_tags( (string) get_the_title( (int) $m['pid'] ) ),
			'qty'            => (int) $m['qty'],
			'type'           => (string) $m['type'],
			'direction'      => self::sign( (string) $m['type'] ),
			'recipient'      => (string) ( $m['rcpt'] ?? '' ),
			'recipient_kind' => (string) ( $m['rkind'] ?? '' ),
			'ben'            => (string) ( $m['ben'] ?? '' ),
			'unit_price'     => round( (float) ( $m['unit'] ?? 0 ), 2 ),
			'payment'        => (string) ( $m['pay'] ?? '' ),
			'note'           => (string) ( $m['note'] ?? '' ),
			'stock_change'   => (int) ( $m['stock'] ?? 0 ),
			'ledger_entry'   => (int) ( $m['ledger'] ?? 0 ),
			'request'        => (string) ( $m['req'] ?? '' ),
			'undone'         => ! empty( $m['undone'] ),
		);
	}

	public static function ability_list( $in = null ): array {
		$in   = is_array( $in ) ? $in : array();
		$f    = array(
			'product' => isset( $in['product_id'] ) ? absint( $in['product_id'] ) : 0,
			'ben'     => isset( $in['ben'] ) ? (string) $in['ben'] : '',
			'type'    => isset( $in['type'] ) ? sanitize_key( (string) $in['type'] ) : '',
			'from'    => isset( $in['date_start'] ) ? (string) $in['date_start'] : '',
			'to'      => isset( $in['date_end'] ) ? (string) $in['date_end'] : '',
			'undone'  => ! empty( $in['include_undone'] ),
		);
		$rows = array_map( array( __CLASS__, 'api_row' ), self::query( $f ) );
		return array(
			'count'     => count( $rows ),
			'types'     => self::types(),
			'movements' => array_slice( $rows, 0, 1000 ),
		);
	}

	public static function ability_add( $in = null ) {
		$in  = is_array( $in ) ? $in : array();
		$map = array(
			'date'           => 'date',
			'product_id'     => 'product',
			'qty'            => 'qty',
			'type'           => 'type',
			'recipient'      => 'recipient',
			'recipient_kind' => 'recipient_kind',
			'ben'            => 'ben',
			'unit_price'     => 'unit_price',
			'payment'        => 'payment',
			'note'           => 'note',
		);
		$args = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $in[ $from ] ) ) {
				$args[ $to ] = $in[ $from ];
			}
		}
		$res = self::add( $args, ! empty( $in['dry_run'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$res['movement'] = self::api_row( $res['movement'] + array( 'id' => 0 ) );
		return $res;
	}

	public static function ability_undo( $in = null ) {
		$in  = is_array( $in ) ? $in : array();
		$res = self::undo( isset( $in['id'] ) ? absint( $in['id'] ) : 0 );
		return is_wp_error( $res ) ? $res : self::api_row( $res );
	}
}
