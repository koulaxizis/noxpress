<?php
/**
 * PL_Rates — the price list of materials (1.3.0) and the promo package.
 *
 * A material is anything bought in bulk and used per title or parcel:
 * a carrier zone, bookmarks, note cards, envelopes, business cards.
 * Each material keeps every price it has had (versions with a «valid
 * from» date), so a new price never overwrites the old one and the
 * title analysis can show how a cost moves from year to year.
 *
 * The promo package is a fixed list of materials and quantities that
 * goes with every new title (e.g. three review parcels). apply() writes
 * one ledger entry per material for a title, category «promotion», with
 * the prices valid on that day; each entry carries the material key in
 * its item column and the source reference promo:<product>:<item>:<date>,
 * so the same package is never written twice for the same day.
 *
 * Storage: option pl_rates (array, autoload off, Bible §12):
 *   items: key => { label, versions: [ { from, net, rate, supplier,
 *                   invoice, note } ] }   net = unit price ex VAT, ≤ 4 decimals
 *   pkg:   key => quantity per title
 * The first read seeds the prices found on the publisher's invoices
 * (17/11/2026). Messages for abilities and REST are in English.
 */

defined( 'ABSPATH' ) || exit;

final class PL_Rates {

	const OPT     = 'pl_rates';
	const MAX     = 60;   // Materials.
	const MAX_VER = 100;  // Versions per material.

	/** @var array|null */
	private static $cache = null;

	/** The prices of 17/11/2026 (Allover, Ektyposi, Sticker Mule, Canva Print). */
	private static function seed(): array {
		$v = static function ( string $from, float $net, float $rate, string $sup, string $inv ): array {
			return array(
				'from'     => $from,
				'net'      => $net,
				'rate'     => $rate,
				'supplier' => $sup,
				'invoice'  => $inv,
				'note'     => '',
			);
		};
		return array(
			'items' => array(
				'ship_island' => array(
					'label'    => 'Μεταφορικά νησιωτική',
					'versions' => array( $v( '2026-09-17', 2.41, 24, 'Allover (Turnaround)', '17/09/2026' ) ),
				),
				'ship_city'   => array(
					'label'    => 'Μεταφορικά εντός πόλης',
					'versions' => array( $v( '2026-09-17', 2.18, 24, 'Allover (Turnaround)', '17/09/2026' ) ),
				),
				'ship_land'   => array(
					'label'    => 'Μεταφορικά χερσαία',
					'versions' => array( $v( '2026-09-17', 2.35, 24, 'Allover (Turnaround)', '17/09/2026' ) ),
				),
				'ship_remote' => array(
					'label'    => 'Μεταφορικά δυσπρόσιτη',
					'versions' => array( $v( '2026-07-23', 4.54, 24, 'Allover (Turnaround)', '23/07/2026' ) ),
				),
				'bookmark'    => array(
					'label'    => 'Σελιδοδείκτης',
					'versions' => array( $v( '2026-03-13', 0.10, 24, 'Ektyposi', '13/03/2026' ) ),
				),
				'notecard'    => array(
					'label'    => 'Καρτελάκι',
					'versions' => array( $v( '2026-03-20', 0.25, 24, 'Ektyposi', '20/03/2026' ) ),
				),
				'envelope'    => array(
					'label'    => 'Φάκελος αποστολής',
					'versions' => array( $v( '2025-09-15', 0.42, 0, 'Sticker Mule', '15/09/2025' ) ),
				),
				'bizcard'     => array(
					'label'    => 'Επαγγελματική κάρτα',
					'versions' => array( $v( '2026-02-25', 0.2016, 24, 'Canva Print', '25/02/2026' ) ),
				),
			),
			'pkg'   => array(
				'ship_island' => 1,
				'ship_city'   => 1,
				'ship_land'   => 1,
				'bookmark'    => 3,
				'notecard'    => 3,
				'envelope'    => 3,
				'bizcard'     => 3,
			),
		);
	}

	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$d = get_option( self::OPT, null );
		if ( ! is_array( $d ) || ! isset( $d['items'] ) || ! is_array( $d['items'] ) ) {
			$d = self::seed();
			update_option( self::OPT, $d, false );
		}
		$d['pkg'] = isset( $d['pkg'] ) && is_array( $d['pkg'] ) ? $d['pkg'] : array();
		foreach ( $d['items'] as $k => $it ) {
			$vs = isset( $it['versions'] ) && is_array( $it['versions'] ) ? array_values( $it['versions'] ) : array();
			usort(
				$vs,
				static function ( $a, $b ) {
					return strcmp( (string) $a['from'], (string) $b['from'] );
				}
			);
			$d['items'][ $k ]['versions'] = $vs;
		}
		self::$cache = $d;
		return $d;
	}

	private static function save( array $d ): void {
		update_option( self::OPT, $d, false );
		self::$cache = null;
	}

	public static function exists( string $key ): bool {
		$d = self::get();
		return isset( $d['items'][ $key ] );
	}

	/** The version valid on $day (the latest with from ≤ day), or null. */
	public static function at( string $key, string $day ): ?array {
		$d = self::get();
		if ( ! isset( $d['items'][ $key ] ) ) {
			return null;
		}
		$hit = null;
		foreach ( $d['items'][ $key ]['versions'] as $v ) {
			if ( (string) $v['from'] <= $day ) {
				$hit = $v;
			}
		}
		return $hit;
	}

	public static function label( string $key ): string {
		$d = self::get();
		return isset( $d['items'][ $key ] ) ? (string) $d['items'][ $key ]['label'] : $key;
	}

	/** A unit price from "0,2016" / 0.2016: ≥ 0, ≤ 4 decimals, or null. */
	public static function unit( $v ): ?float {
		$s = is_int( $v ) || is_float( $v ) ? number_format( (float) $v, 6, '.', '' ) : str_replace( array( ' ', '€' ), '', str_replace( ',', '.', trim( (string) $v ) ) );
		if ( ! preg_match( '/^\d{1,7}(\.\d+)?$/', $s ) ) {
			return null;
		}
		$f = (float) $s;
		return abs( round( $f, 4 ) - $f ) > 0.0000001 ? null : round( $f, 4 );
	}

	/**
	 * Adds a material, or a new price of one (a new version; the old ones
	 * stay). $in: key, label (new material), from (Y-m-d), net (unit ex
	 * VAT), vat_rate, supplier, invoice, note.
	 *
	 * @return array|WP_Error The material after the change.
	 */
	public static function add_price( array $in ) {
		$d   = self::get();
		$key = sanitize_key( (string) ( $in['key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 40 ) {
			return new WP_Error( 'pl_rate_key', 'key must be 1–40 characters a-z, 0-9, _ or -.' );
		}
		$new = ! isset( $d['items'][ $key ] );
		$lab = mb_substr( trim( sanitize_text_field( (string) ( $in['label'] ?? '' ) ) ), 0, 80 );
		if ( $new && '' === $lab ) {
			return new WP_Error( 'pl_rate_label', 'a new material needs a label.' );
		}
		if ( $new && count( $d['items'] ) >= self::MAX ) {
			return new WP_Error( 'pl_rate_max', 'the price list holds at most ' . self::MAX . ' materials.' );
		}
		$from = (string) ( $in['from'] ?? '' );
		if ( ! PL_Settings::valid_date( $from ) ) {
			return new WP_Error( 'pl_rate_from', 'from must be YYYY-MM-DD.' );
		}
		$net = self::unit( $in['net'] ?? null );
		if ( null === $net ) {
			return new WP_Error( 'pl_rate_net', 'net must be a unit price ≥ 0 with at most 4 decimals.' );
		}
		$rate = (float) str_replace( ',', '.', (string) ( $in['vat_rate'] ?? 24 ) );
		if ( $rate < 0 || $rate > 100 ) {
			return new WP_Error( 'pl_rate_vat', 'vat_rate must be 0–100.' );
		}
		$ver = array(
			'from'     => $from,
			'net'      => $net,
			'rate'     => round( $rate, 2 ),
			'supplier' => mb_substr( trim( sanitize_text_field( (string) ( $in['supplier'] ?? '' ) ) ), 0, 120 ),
			'invoice'  => mb_substr( trim( sanitize_text_field( (string) ( $in['invoice'] ?? '' ) ) ), 0, 60 ),
			'note'     => mb_substr( trim( sanitize_text_field( (string) ( $in['note'] ?? '' ) ) ), 0, 200 ),
		);
		if ( $new ) {
			$d['items'][ $key ] = array(
				'label'    => $lab,
				'versions' => array(),
			);
		} elseif ( '' !== $lab ) {
			$d['items'][ $key ]['label'] = $lab;
		}
		$vs = array();
		foreach ( $d['items'][ $key ]['versions'] as $v ) {
			if ( (string) $v['from'] !== $from ) { // The same day replaces its own price (a typo fix).
				$vs[] = $v;
			}
		}
		$vs[] = $ver;
		if ( count( $vs ) > self::MAX_VER ) {
			return new WP_Error( 'pl_rate_ver', 'a material keeps at most ' . self::MAX_VER . ' prices.' );
		}
		$d['items'][ $key ]['versions'] = $vs;
		self::save( $d );
		return self::item_out( $key );
	}

	/**
	 * Sets the promo package: key => quantity (0 removes the material).
	 *
	 * @return array|WP_Error The package.
	 */
	public static function set_package( array $pkg ) {
		$d   = self::get();
		$out = array();
		foreach ( $pkg as $k => $q ) {
			$k = sanitize_key( (string) $k );
			$q = (int) $q;
			if ( ! isset( $d['items'][ $k ] ) ) {
				return new WP_Error( 'pl_pkg_item', 'unknown material: ' . $k . '.' );
			}
			if ( $q < 0 || $q > 1000 ) {
				return new WP_Error( 'pl_pkg_qty', 'quantities must be 0–1000.' );
			}
			if ( $q > 0 ) {
				$out[ $k ] = $q;
			}
		}
		$d['pkg'] = $out;
		self::save( $d );
		return self::package( wp_date( 'Y-m-d' ) );
	}

	/**
	 * The package priced on $day: lines with unit, net and VAT per line
	 * (net and VAT rounded to the cent per line, VAT taken on the exact amount) and the totals.
	 */
	public static function package( string $day ): array {
		$d     = self::get();
		$lines = array();
		$net   = 0.0;
		$vat   = 0.0;
		$miss  = array();
		foreach ( $d['pkg'] as $k => $q ) {
			$v = self::at( (string) $k, $day );
			if ( null === $v ) {
				$miss[] = (string) $k;
				continue;
			}
			$ln      = round( (float) $v['net'] * (int) $q, 2 );
			$lv      = round( (float) $v['net'] * (int) $q * (float) $v['rate'] / 100, 2 ); // VAT on the exact amount, as the invoice.
			$lines[] = array(
				'item'     => (string) $k,
				'label'    => self::label( (string) $k ),
				'qty'      => (int) $q,
				'unit'     => (float) $v['net'],
				'vat_rate' => (float) $v['rate'],
				'net'      => $ln,
				'vat'      => $lv,
				'supplier' => (string) $v['supplier'],
				'invoice'  => (string) $v['invoice'],
				'from'     => (string) $v['from'],
			);
			$net += $ln;
			$vat += $lv;
		}
		return array(
			'day'     => $day,
			'lines'   => $lines,
			'net'     => round( $net, 2 ),
			'vat'     => round( $vat, 2 ),
			'gross'   => round( $net + $vat, 2 ),
			'missing' => $miss,
		);
	}

	public static function ref( int $pid, string $item, string $day ): string {
		return 'promo:' . $pid . ':' . $item . ':' . $day;
	}

	/**
	 * Writes the package for a title: one «promotion» entry per material.
	 * A material already written for the same title and day is skipped.
	 *
	 * @param string $source manual | ability | oros.
	 * @return array|WP_Error { dry_run, package, ids, skipped }
	 */
	public static function apply( int $pid, string $day, bool $dry, string $source = 'ability' ) {
		$p = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $p instanceof WC_Product ) {
			return new WP_Error( 'pl_product', 'product_id is not a product of this shop.' );
		}
		if ( $p instanceof WC_Product_Variation ) {
			$pid = (int) $p->get_parent_id();
		}
		if ( ! PL_Settings::valid_date( $day ) ) {
			return new WP_Error( 'pl_date', 'date must be YYYY-MM-DD.' );
		}
		$pkg = self::package( $day );
		if ( ! $pkg['lines'] ) {
			return new WP_Error( 'pl_pkg_empty', 'the promo package is empty or has no price valid on ' . $day . '.' );
		}
		$todo = array();
		$skip = array();
		foreach ( $pkg['lines'] as $l ) {
			if ( PL_Entries::by_ref( self::ref( $pid, $l['item'], $day ) ) ) {
				$skip[] = $l['item'];
				continue;
			}
			$todo[] = array(
				'ref' => self::ref( $pid, $l['item'], $day ),
				'in'  => array(
					'date'        => $day,
					'product_id'  => $pid,
					'category'    => 'promotion',
					'item'        => $l['item'],
					'supplier'    => $l['supplier'],
					'invoice_no'  => $l['invoice'],
					/* translators: 1: material, 2: quantity, 3: unit price */
					'description' => sprintf( __( 'Πακέτο προώθησης · %1$s %2$d × %3$s', 'production-ledger' ), $l['label'], $l['qty'], wc_format_localized_decimal( (string) $l['unit'] ) ),
					'net'         => $l['net'],
					'vat'         => $l['vat'],
					'vat_rate'    => $l['vat_rate'],
					'qty_basis'   => $l['qty'],
				),
			);
		}
		foreach ( $todo as $t ) {
			$row = PL_Entries::validate( $t['in'] );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
		}
		$ids = array();
		if ( ! $dry ) {
			foreach ( $todo as $t ) {
				$id = PL_Entries::add( $t['in'], $source, $t['ref'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$ids[] = $id;
			}
		}
		return array(
			'dry_run' => $dry,
			'product' => $pid,
			'package' => $pkg,
			'ids'     => $ids,
			'skipped' => $skip,
		);
	}

	/** One material with its prices, oldest first, and the change of each. */
	public static function item_out( string $key ): array {
		$d    = self::get();
		$it   = $d['items'][ $key ];
		$prev = null;
		$vers = array();
		foreach ( $it['versions'] as $v ) {
			$gross  = round( (float) $v['net'] * ( 1 + (float) $v['rate'] / 100 ), 4 );
			$vers[] = array(
				'from'     => (string) $v['from'],
				'net'      => (float) $v['net'],
				'vat_rate' => (float) $v['rate'],
				'gross'    => $gross,
				'supplier' => (string) $v['supplier'],
				'invoice'  => (string) $v['invoice'],
				'note'     => (string) $v['note'],
				'change'   => null === $prev || $prev <= 0 ? null : round( ( (float) $v['net'] - $prev ) / $prev * 100, 1 ),
			);
			$prev   = (float) $v['net'];
		}
		$cur = self::at( $key, wp_date( 'Y-m-d' ) );
		return array(
			'key'      => $key,
			'label'    => (string) $it['label'],
			'current'  => null === $cur ? null : (float) $cur['net'],
			'in_pkg'   => (int) ( $d['pkg'][ $key ] ?? 0 ),
			'versions' => $vers,
		);
	}

	/** The whole list for abilities, REST and the admin. */
	public static function out(): array {
		$d     = self::get();
		$items = array();
		foreach ( array_keys( $d['items'] ) as $k ) {
			$items[] = self::item_out( (string) $k );
		}
		return array(
			'items'   => $items,
			'package' => self::package( wp_date( 'Y-m-d' ) ),
		);
	}
}
