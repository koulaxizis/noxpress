<?php
/**
 * DSR_Rules — the discount rules (option dsr_rules, not autoloaded on its
 * own: read once per request), their validation, status and change
 * history.
 *
 * A rule (every field validated with whitelists, Bible §11):
 *  - id, name (80), on 'yes'|'no', created, modified, since (when it was
 *    last switched on, for the lowest 30-day price)
 *  - type   'sale'  (Α) a lower price on products: kind + amount
 *           'tiers' (Β) quantity tiers: [ { min, kind, amount } ] (2–6)
 *           'xy'    (Γ) buy X pay Y: buy, pay (the cheapest units are free)
 *           'cart'  (Δ) the cart total: kind + amount, from min
 *  - kind   'percent' (0–100) | 'fixed' (an amount per unit; for 'cart',
 *           off the cart), amount
 *  - count  'product' (each product on its own, variations together) |
 *           'set' (all the rule's products together) — tiers and xy
 *  - from, to  'Y-m-d H:i' in the site's time zone, or '' (no limit)
 *  - who    'all' | 'guests' | 'customers' (logged in)
 *  - min    the cart subtotal the rule needs (0 = none)
 *  - all    'yes' = every product; else products, cats, tags, brands
 *  - ex_products, ex_cats  never these
 *  - label  { el, en } the visitor label (empty = made from the rule)
 *
 * At most 200 rules. Every change is written to the history (dsr_log, the
 * last 200 changes: who, when, what, old → new), fires dsr_rules_changed
 * and purges the page cache, and the start and end of each rule are
 * scheduled (dsr_rule_edge) so the cache is purged then too.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Rules {

	const OPT      = 'dsr_rules';
	const OPT_NEXT = 'dsr_next_id';
	const OPT_LOG  = 'dsr_log';
	const GROUP    = 'discount-rules';
	const MAX      = 200;
	const MAX_LOG  = 200;
	const MAX_IDS  = 500;
	const MAX_TIER = 6;

	const TYPES = array( 'sale', 'tiers', 'xy', 'cart' );

	/** @var array|null Per-request cache: id => rule. */
	private static $cache = null;

	public static function init(): void {
		add_action( 'dsr_rule_edge', array( __CLASS__, 'edge' ), 10, 1 );
	}

	/* =====================================================================
	 * Storage
	 * =================================================================== */

	/** Every rule, id => rule, sanitized. */
	public static function all(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$clean = self::sanitize( $r )['rule'];
					if ( $clean['id'] > 0 ) {
						self::$cache[ $clean['id'] ] = $clean;
					}
				}
			}
		}
		return self::$cache;
	}

	public static function get( int $id ): ?array {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	private static function store( array $rules ): void {
		self::$cache = $rules;
		update_option( self::OPT, array_values( $rules ), false );
	}

	/**
	 * Saves a rule (new when its id is 0 or unknown).
	 *
	 * @param array $raw Rule fields.
	 * @return array { id: int (0 = not saved), errors: string[], warnings: string[] }
	 */
	public static function save( array $raw ): array {
		$res = self::sanitize( $raw );
		if ( ! empty( $res['errors'] ) ) {
			return array(
				'id'       => 0,
				'errors'   => $res['errors'],
				'warnings' => array(),
			);
		}
		$rule = $res['rule'];
		$all  = self::all();
		$old  = $rule['id'] > 0 && isset( $all[ $rule['id'] ] ) ? $all[ $rule['id'] ] : null;
		if ( null === $old ) {
			if ( count( $all ) >= self::MAX ) {
				return array(
					'id'       => 0,
					'errors'   => array( sprintf( /* translators: %d: most rules */ __( 'Έως %d κανόνες.', 'discount-rules' ), self::MAX ) ),
					'warnings' => array(),
				);
			}
			$rule['id']      = self::next_id();
			$rule['created'] = time();
		} else {
			$rule['created'] = $old['created'];
			$rule['since']   = $old['since'];
		}
		$rule['modified'] = time();
		if ( 'yes' === $rule['on'] && ( null === $old || 'yes' !== $old['on'] ) ) {
			$rule['since'] = time();
		}
		$all[ $rule['id'] ] = $rule;
		self::store( $all );
		self::log( null === $old ? 'created' : 'updated', $rule, null === $old ? '' : self::diff( $old, $rule ) );
		self::changed( array( $rule['id'] ) );
		return array(
			'id'       => $rule['id'],
			'errors'   => array(),
			'warnings' => self::warnings( $rule ),
		);
	}

	/** Switches a rule on or off. */
	public static function set_on( int $id, bool $on ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$was = $all[ $id ]['on'];
		$now = $on ? 'yes' : 'no';
		if ( $was === $now ) {
			return true;
		}
		$all[ $id ]['on']       = $now;
		$all[ $id ]['modified'] = time();
		if ( $on ) {
			$all[ $id ]['since'] = time();
		}
		self::store( $all );
		self::log( $on ? 'enabled' : 'disabled', $all[ $id ], '' );
		self::changed( array( $id ) );
		return true;
	}

	/** A copy of a rule, switched off, named «(αντίγραφο)». Returns the new id or 0. */
	public static function copy( int $id ): int {
		$rule = self::get( $id );
		if ( null === $rule ) {
			return 0;
		}
		$rule['id']   = 0;
		$rule['on']   = 'no';
		$rule['name'] = mb_substr( $rule['name'] . ' ' . __( '(αντίγραφο)', 'discount-rules' ), 0, 80 );
		return (int) self::save( $rule )['id'];
	}

	public static function delete( int $id ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$rule = $all[ $id ];
		unset( $all[ $id ] );
		self::store( $all );
		self::log( 'deleted', $rule, '' );
		self::changed( array( $id ) );
		return true;
	}

	/**
	 * Replaces every rule (import). Returns how many were kept; nothing
	 * changes when none is valid.
	 *
	 * @param array $list Rules.
	 */
	public static function replace_all( array $list ): int {
		$out = array();
		$max = 0;
		foreach ( $list as $raw ) {
			if ( ! is_array( $raw ) || count( $out ) >= self::MAX ) {
				continue;
			}
			$res = self::sanitize( $raw );
			if ( ! empty( $res['errors'] ) || $res['rule']['id'] <= 0 || isset( $out[ $res['rule']['id'] ] ) ) {
				continue;
			}
			$r              = $res['rule'];
			$r['created']   = $r['created'] > 0 ? $r['created'] : time();
			$r['modified']  = time();
			$r['since']     = 'yes' === $r['on'] ? time() : $r['since'];
			$out[ $r['id'] ] = $r;
			$max            = max( $max, $r['id'] );
		}
		if ( empty( $out ) ) {
			return 0;
		}
		$old = array_keys( self::all() );
		self::store( $out );
		update_option( self::OPT_NEXT, max( $max + 1, (int) get_option( self::OPT_NEXT, 1 ) ), false );
		self::log(
			'imported',
			array(
				'id'   => 0,
				'name' => '',
			),
			sprintf( /* translators: %d: number of rules */ __( '%d κανόνες', 'discount-rules' ), count( $out ) )
		);
		self::changed( array_values( array_unique( array_merge( $old, array_keys( $out ) ) ) ) );
		return count( $out );
	}

	private static function next_id(): int {
		$all  = self::all();
		$next = max( 1, (int) get_option( self::OPT_NEXT, 1 ), empty( $all ) ? 1 : max( array_keys( $all ) ) + 1 );
		update_option( self::OPT_NEXT, $next + 1, false );
		return $next;
	}

	/* =====================================================================
	 * Validation
	 * =================================================================== */

	public static function blank(): array {
		return array(
			'id'          => 0,
			'name'        => '',
			'on'          => 'no',
			'type'        => 'sale',
			'kind'        => 'percent',
			'amount'      => 0.0,
			'tiers'       => array(),
			'count'       => 'product',
			'buy'         => 3,
			'pay'         => 2,
			'from'        => '',
			'to'          => '',
			'who'         => 'all',
			'min'         => 0.0,
			'all'         => 'no',
			'products'    => array(),
			'cats'        => array(),
			'tags'        => array(),
			'brands'      => array(),
			'ex_products' => array(),
			'ex_cats'     => array(),
			'label'       => array(
				'el' => '',
				'en' => '',
			),
			'created'     => 0,
			'modified'    => 0,
			'since'       => 0,
		);
	}

	/**
	 * Strict validation of a rule: unknown fields are dropped.
	 *
	 * @param array $raw Rule fields.
	 * @return array { rule: array, errors: string[] }
	 */
	public static function sanitize( array $raw ): array {
		$r      = self::blank();
		$errors = array();

		$r['id']       = isset( $raw['id'] ) && is_numeric( $raw['id'] ) ? max( 0, (int) $raw['id'] ) : 0;
		$r['name']     = isset( $raw['name'] ) && is_string( $raw['name'] ) ? DSR_Settings::line( $raw['name'], 80 ) : '';
		$r['on']       = self::pick( $raw, 'on', array( 'yes', 'no' ), 'no' );
		$r['type']     = self::pick( $raw, 'type', self::TYPES, 'sale' );
		$r['kind']     = self::pick( $raw, 'kind', array( 'percent', 'fixed' ), 'percent' );
		$r['count']    = self::pick( $raw, 'count', array( 'product', 'set' ), 'product' );
		$r['who']      = self::pick( $raw, 'who', array( 'all', 'guests', 'customers' ), 'all' );
		$r['all']      = self::pick( $raw, 'all', array( 'yes', 'no' ), 'no' );
		$r['amount']   = self::money( $raw, 'amount' );
		$r['min']      = self::money( $raw, 'min' );
		$r['created']  = isset( $raw['created'] ) && is_numeric( $raw['created'] ) ? max( 0, (int) $raw['created'] ) : 0;
		$r['modified'] = isset( $raw['modified'] ) && is_numeric( $raw['modified'] ) ? max( 0, (int) $raw['modified'] ) : 0;
		$r['since']    = isset( $raw['since'] ) && is_numeric( $raw['since'] ) ? max( 0, (int) $raw['since'] ) : 0;

		foreach ( array( 'products', 'cats', 'tags', 'brands', 'ex_products', 'ex_cats' ) as $k ) {
			$r[ $k ] = self::ids( $raw, $k );
		}

		foreach ( array( 'from', 'to' ) as $k ) {
			$v = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? trim( $raw[ $k ] ) : '';
			if ( '' === $v ) {
				continue;
			}
			$v = str_replace( 'T', ' ', $v );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$v .= 'from' === $k ? ' 00:00' : ' 23:59';
			}
			if ( self::ts( $v ) > 0 ) {
				$r[ $k ] = $v;
			} else {
				$errors[] = 'from' === $k ? __( 'Η ημερομηνία έναρξης δεν είναι έγκυρη.', 'discount-rules' ) : __( 'Η ημερομηνία λήξης δεν είναι έγκυρη.', 'discount-rules' );
			}
		}
		if ( '' !== $r['from'] && '' !== $r['to'] && self::ts( $r['to'] ) <= self::ts( $r['from'] ) ) {
			$errors[] = __( 'Η λήξη πρέπει να είναι μετά την έναρξη.', 'discount-rules' );
		}

		if ( isset( $raw['label'] ) && is_array( $raw['label'] ) ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$r['label'][ $l ] = isset( $raw['label'][ $l ] ) && is_string( $raw['label'][ $l ] ) ? DSR_Settings::line( $raw['label'][ $l ], 40 ) : '';
			}
		}

		if ( '' === $r['name'] ) {
			$errors[] = __( 'Δώσε ένα όνομα στον κανόνα.', 'discount-rules' );
		}

		// The discount itself.
		if ( 'sale' === $r['type'] || 'cart' === $r['type'] ) {
			$err = self::check_amount( $r['kind'], $r['amount'] );
			if ( '' !== $err ) {
				$errors[] = $err;
			}
		}
		if ( 'cart' === $r['type'] && $r['min'] <= 0 && 'fixed' === $r['kind'] ) {
			$errors[] = __( 'Μια σταθερή έκπτωση στο καλάθι θέλει ελάχιστο ποσό καλαθιού.', 'discount-rules' );
		}
		if ( 'tiers' === $r['type'] ) {
			$tiers = array();
			$raw_t = isset( $raw['tiers'] ) && is_array( $raw['tiers'] ) ? $raw['tiers'] : array();
			foreach ( $raw_t as $t ) {
				if ( ! is_array( $t ) || ! isset( $t['min'] ) || '' === $t['min'] || ! is_numeric( $t['min'] ) ) {
					continue;
				}
				$min    = (int) $t['min'];
				$kind   = isset( $t['kind'] ) && 'fixed' === $t['kind'] ? 'fixed' : 'percent';
				$amount = self::money( $t, 'amount' );
				if ( $min < 2 || $min > 9999 ) {
					$errors[] = __( 'Κάθε κλίμακα ξεκινά από 2 έως 9999 τεμάχια.', 'discount-rules' );
					continue;
				}
				$err = self::check_amount( $kind, $amount );
				if ( '' !== $err ) {
					$errors[] = $err;
					continue;
				}
				$tiers[ $min ] = array(
					'min'    => $min,
					'kind'   => $kind,
					'amount' => $amount,
				);
			}
			ksort( $tiers );
			$r['tiers'] = array_slice( array_values( $tiers ), 0, self::MAX_TIER );
			if ( empty( $r['tiers'] ) ) {
				$errors[] = __( 'Βάλε τουλάχιστον μία κλίμακα (από πόσα τεμάχια και πόση έκπτωση).', 'discount-rules' );
			}
		}
		if ( 'xy' === $r['type'] ) {
			$buy      = isset( $raw['buy'] ) && is_numeric( $raw['buy'] ) ? (int) $raw['buy'] : 0;
			$pay      = isset( $raw['pay'] ) && is_numeric( $raw['pay'] ) ? (int) $raw['pay'] : 0;
			$r['buy'] = $buy;
			$r['pay'] = $pay;
			if ( $buy < 2 || $buy > 100 || $pay < 1 || $pay >= $buy ) {
				$errors[] = __( 'Στο «πάρε Χ πλήρωσε Υ» το Χ είναι 2 έως 100 και το Υ από 1 έως Χ − 1.', 'discount-rules' );
				$r['buy'] = 3;
				$r['pay'] = 2;
			}
		}

		// Which products.
		if ( 'yes' !== $r['all'] && empty( $r['products'] ) && empty( $r['cats'] ) && empty( $r['tags'] ) && empty( $r['brands'] ) ) {
			$errors[] = __( 'Διάλεξε σε ποια προϊόντα ισχύει (ή «Όλα τα προϊόντα»).', 'discount-rules' );
		}

		return array(
			'rule'   => $r,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	private static function check_amount( string $kind, float $amount ): string {
		if ( 'percent' === $kind && ( $amount <= 0 || $amount > 100 ) ) {
			return __( 'Το ποσοστό έκπτωσης είναι από 0,01 έως 100.', 'discount-rules' );
		}
		if ( 'fixed' === $kind && ( $amount <= 0 || $amount > 1000000 ) ) {
			return __( 'Το ποσό έκπτωσης πρέπει να είναι πάνω από 0.', 'discount-rules' );
		}
		return '';
	}

	/** Things the shop manager should look at again (not errors). */
	public static function warnings( array $r ): array {
		$out = array();
		$cap = (int) DSR_Settings::get()['cap'];
		$max = 0.0;
		if ( 'percent' === $r['kind'] && in_array( $r['type'], array( 'sale', 'cart' ), true ) ) {
			$max = $r['amount'];
		}
		if ( 'tiers' === $r['type'] ) {
			foreach ( $r['tiers'] as $t ) {
				if ( 'percent' === $t['kind'] ) {
					$max = max( $max, $t['amount'] );
				}
			}
		}
		if ( 'xy' === $r['type'] && $r['buy'] > 0 ) {
			$max = 100 * ( $r['buy'] - $r['pay'] ) / $r['buy'];
		}
		if ( $max > $cap ) {
			$out[] = sprintf(
				/* translators: 1: rule discount in percent, 2: order cap in percent */
				__( 'Ο κανόνας δίνει έως %1$s%%, πάνω από το ανώτατο όριο ανά παραγγελία (%2$d%%): η έκπτωση θα κόβεται στο όριο.', 'discount-rules' ),
				wc_format_localized_decimal( round( $max, 2 ) ),
				$cap
			);
		}
		if ( '' !== $r['to'] && self::ts( $r['to'] ) <= time() ) {
			$out[] = __( 'Η λήξη του κανόνα έχει ήδη περάσει.', 'discount-rules' );
		}
		return $out;
	}

	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/** A non-negative amount, from "12,50" or "12.50" (at most 6 decimals). */
	private static function money( array $raw, string $key ): float {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
			return 0.0;
		}
		$v = str_replace( array( ' ', ',' ), array( '', '.' ), (string) $raw[ $key ] );
		if ( ! is_numeric( $v ) ) {
			return 0.0;
		}
		return max( 0.0, round( (float) $v, 6 ) );
	}

	/** Positive unique ids, at most MAX_IDS. */
	private static function ids( array $raw, string $key ): array {
		if ( ! isset( $raw[ $key ] ) ) {
			return array();
		}
		$v   = is_array( $raw[ $key ] ) ? $raw[ $key ] : explode( ',', (string) $raw[ $key ] );
		$out = array();
		foreach ( $v as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[ (int) $id ] = (int) $id;
			}
			if ( count( $out ) >= self::MAX_IDS ) {
				break;
			}
		}
		return array_values( $out );
	}

	/* =====================================================================
	 * Time and status
	 * =================================================================== */

	/** A 'Y-m-d H:i' local time as a timestamp (0 when invalid or empty). */
	public static function ts( string $v ): int {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/', $v, $m ) ) {
			return 0;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return 0;
		}
		$dt = date_create_immutable( $v . ':00', wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * The status of a rule at a time.
	 *
	 * @param array $r  Rule.
	 * @param int   $ts Time (0 = now).
	 * @return string 'off' | 'scheduled' | 'expired' | 'active'
	 */
	public static function status( array $r, int $ts = 0 ): string {
		$ts = $ts > 0 ? $ts : time();
		if ( 'yes' !== $r['on'] ) {
			return 'off';
		}
		if ( '' !== $r['from'] && self::ts( $r['from'] ) > $ts ) {
			return 'scheduled';
		}
		if ( '' !== $r['to'] && self::ts( $r['to'] ) <= $ts ) {
			return 'expired';
		}
		return 'active';
	}

	/** The rule's visitor label (its own, or one made from the rule). */
	public static function label( array $r ): string {
		$lang = DSR_Settings::lang();
		$own  = ( 'en' === $lang && '' !== $r['label']['en'] ) ? $r['label']['en'] : $r['label']['el'];
		if ( '' !== $own ) {
			return $own;
		}
		if ( 'xy' === $r['type'] ) {
			return DSR_Settings::fill(
				't_xy',
				array(
					'buy' => $r['buy'],
					'pay' => $r['pay'],
				)
			);
		}
		if ( 'sale' === $r['type'] && 'percent' === $r['kind'] ) {
			return DSR_Settings::fill( 't_badge', array( 'percent' => wc_format_localized_decimal( round( $r['amount'], 2 ) ) . '%' ) );
		}
		return $r['name'];
	}

	/* =====================================================================
	 * Changes: history, cache, scheduled edges
	 * =================================================================== */

	/**
	 * One line of the history.
	 *
	 * @param string $act  created | updated | enabled | disabled | deleted | imported | settings.
	 * @param array  $rule Rule (id, name).
	 * @param string $diff What changed (old → new), plain text.
	 */
	public static function log( string $act, array $rule, string $diff ): void {
		$log = get_option( self::OPT_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$u   = wp_get_current_user();
		array_unshift(
			$log,
			array(
				't'    => time(),
				'uid'  => (int) $u->ID,
				'user' => $u->ID ? mb_substr( (string) $u->user_login, 0, 60 ) : 'system',
				'act'  => sanitize_key( $act ),
				'id'   => isset( $rule['id'] ) ? (int) $rule['id'] : 0,
				'name' => isset( $rule['name'] ) ? mb_substr( (string) $rule['name'], 0, 80 ) : '',
				'diff' => mb_substr( $diff, 0, 1000 ),
			)
		);
		update_option( self::OPT_LOG, array_slice( $log, 0, self::MAX_LOG ), false );
	}

	/** The history, newest first. */
	public static function history(): array {
		$log = get_option( self::OPT_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/** What changed between two versions of a rule, as «field: old → new; …». */
	public static function diff( array $a, array $b ): string {
		$out = array();
		foreach ( self::blank() as $k => $unused ) {
			if ( in_array( $k, array( 'id', 'created', 'modified', 'since' ), true ) ) {
				continue;
			}
			$x = self::flat( $a[ $k ] ?? '' );
			$y = self::flat( $b[ $k ] ?? '' );
			if ( $x !== $y ) {
				$out[] = $k . ': ' . ( '' === $x ? '—' : $x ) . ' → ' . ( '' === $y ? '—' : $y );
			}
		}
		return implode( '; ', $out );
	}

	private static function flat( $v ): string {
		if ( is_array( $v ) ) {
			return (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
		}
		if ( is_float( $v ) ) {
			return rtrim( rtrim( number_format( $v, 6, '.', '' ), '0' ), '.' );
		}
		return (string) $v;
	}

	/**
	 * After a change: schedule the edges again, tell the suite, purge the
	 * page cache (the shop pages show the rules).
	 *
	 * @param int[] $ids Changed rules.
	 */
	public static function changed( array $ids ): void {
		self::schedule_edges();
		/**
		 * Fires after rules are created, changed, switched or deleted, and
		 * when a rule starts or ends.
		 *
		 * @param int[] $rule_ids Rules.
		 */
		do_action( 'dsr_rules_changed', array_map( 'intval', $ids ) );
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			Noxpress_Core::purge_page_cache();
		}
	}

	/** One Action Scheduler job at each future start and end of a rule that is on. */
	public static function schedule_edges(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( 'dsr_rule_edge', null, self::GROUP );
		$now = time();
		foreach ( self::all() as $r ) {
			if ( 'yes' !== $r['on'] ) {
				continue;
			}
			foreach ( array( 'from', 'to' ) as $k ) {
				$ts = '' !== $r[ $k ] ? self::ts( $r[ $k ] ) : 0;
				if ( $ts > $now ) {
					as_schedule_single_action( $ts + 5, 'dsr_rule_edge', array( (int) $r['id'] ), self::GROUP );
				}
			}
		}
	}

	/**
	 * A rule started or ended: purge the page cache and tell the suite.
	 *
	 * @param mixed $id Rule id.
	 */
	public static function edge( $id ): void {
		do_action( 'dsr_rules_changed', array( (int) $id ) );
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			Noxpress_Core::purge_page_cache();
		}
	}
}
