<?php
/**
 * PBQ_Calc — the quote. Pure functions over the settings: no database
 * write, no output.
 *
 * Order of operations (design §4):
 *   net amount of each service → share of the hidden typesetting fee →
 *   minimum charge → VAT → rounding of the line → total = sum of the
 *   rounded lines (so what the visitor sees always adds up).
 *
 * All the math is in floats of the currency unit; every line becomes an
 * integer number of cents before it is rounded to the step, and totals are
 * sums of integers (no 0.30000000004).
 *
 * The result has a public part (labels, rounded amounts, total, notes) and
 * a breakdown (rates, pages, tiers, the typesetting fee and its shares) that
 * only managers see: PBQ_Calc::public_view() strips it before anything goes
 * to the browser.
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Calc {

	/* =====================================================================
	 * Input
	 * =================================================================== */

	private static function int_in( array $raw, string $k, int $min, int $max, int $def = 0 ): int {
		if ( ! isset( $raw[ $k ] ) || ! is_scalar( $raw[ $k ] ) || '' === trim( (string) $raw[ $k ] ) ) {
			return $def;
		}
		$v = preg_replace( '/[^0-9]/', '', (string) $raw[ $k ] );
		if ( '' === $v ) {
			return $def;
		}
		return max( $min, min( $max, (int) substr( $v, 0, 9 ) ) );
	}

	private static function bool_in( array $raw, string $k ): bool {
		return isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) && in_array( strtolower( (string) $raw[ $k ] ), array( '1', 'yes', 'on', 'true' ), true );
	}

	private static function id_in( array $raw, string $k, array $rows ): string {
		$v = isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) ? strtolower( (string) $raw[ $k ] ) : '';
		if ( isset( $rows[ $v ] ) ) {
			return $v;
		}
		return $rows ? (string) array_key_first( $rows ) : '';
	}

	/**
	 * The visitor's choices, whitelisted against the enabled templates.
	 * Unknown keys are dropped; out-of-range numbers are clamped.
	 *
	 * @param array $raw Form or JSON fields.
	 */
	public static function input( array $raw ): array {
		$s = PBQ_Settings::get();

		$finishes = PBQ_Settings::rows_on( 'finishes' );
		$one      = array();
		$adds     = array();
		foreach ( $finishes as $id => $f ) {
			if ( 'one' === $f['kind'] ) {
				$one[ $id ] = $f;
			} else {
				$adds[ $id ] = $f;
			}
		}
		$finish = isset( $raw['finish'] ) && is_scalar( $raw['finish'] ) && isset( $one[ strtolower( (string) $raw['finish'] ) ] ) ? strtolower( (string) $raw['finish'] ) : '';
		$picked = array();
		if ( isset( $raw['adds'] ) && is_array( $raw['adds'] ) ) {
			foreach ( $raw['adds'] as $k => $v ) {
				// Accept both [ id, id ] and { id: 1 }.
				$id = is_int( $k ) ? ( is_scalar( $v ) ? strtolower( (string) $v ) : '' ) : ( self::bool_in( array( 'v' => $v ), 'v' ) ? strtolower( (string) $k ) : '' );
				if ( isset( $adds[ $id ] ) ) {
					$picked[ $id ] = true;
				}
			}
		}

		$extras = array();
		$xin    = isset( $raw['x'] ) && is_array( $raw['x'] ) ? $raw['x'] : array();
		foreach ( PBQ_Settings::rows_on( 'extras' ) as $id => $x ) {
			if ( ! isset( $xin[ $id ] ) || ! is_scalar( $xin[ $id ] ) ) {
				continue;
			}
			if ( 'qty' === $x['kind'] ) {
				$q = self::int_in( $xin, $id, 0, (int) $x['qmax'] );
			} else {
				$q = self::bool_in( $xin, $id ) ? 1 : 0;
			}
			if ( $q > 0 ) {
				$extras[ $id ] = $q;
			}
		}

		$words_max = (int) $s['ed_max'] * (int) $s['ed_wpp'];
		return array(
			'size'    => self::id_in( $raw, 'size', PBQ_Settings::rows_on( 'sizes' ) ),
			'pages'   => self::int_in( $raw, 'pages', 0, (int) $s['ed_max'] ),
			'words'   => self::int_in( $raw, 'words', 0, $words_max ),
			'book'    => self::int_in( $raw, 'book', 0, 5000 ),
			'edit'    => $s['ed_on'] && self::bool_in( $raw, 'edit' ),
			'level'   => self::id_in( $raw, 'level', PBQ_Settings::rows_on( 'levels' ) ),
			'cover'   => $s['cv_on'] && self::bool_in( $raw, 'cover' ),
			'illus'   => $s['il_on'] && self::bool_in( $raw, 'illus' ),
			'icount'  => self::int_in( $raw, 'icount', 0, (int) $s['il_max'] ),
			'icolor'  => isset( $raw['icolor'] ) && 'color' === $raw['icolor'] ? 'color' : 'bw',
			'print'   => $s['pr_on'] && self::bool_in( $raw, 'print' ),
			'run'     => self::int_in( $raw, 'run', (int) $s['pr_run_min'], (int) $s['pr_run_max'], (int) $s['pr_run_def'] ),
			'paper'   => self::id_in( $raw, 'paper', PBQ_Settings::rows_on( 'papers' ) ),
			'body'    => $s['pr_color_on'] && isset( $raw['body'] ) && 'color' === $raw['body'] ? 'color' : 'bw',
			'binding' => self::id_in( $raw, 'binding', PBQ_Settings::rows_on( 'bindings' ) ),
			'finish'  => $finish,
			'adds'    => array_keys( $picked ),
			'x'       => $extras,
		);
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** The discount % of the highest tier whose "from" is reached. */
	public static function tier( array $tiers, int $count ): float {
		$pct = 0.0;
		foreach ( $tiers as $t ) {
			if ( $count >= (int) $t['from'] ) {
				$pct = (float) $t['pct'];
			}
		}
		return $pct;
	}

	/** Rounds cents to the step (1, 10, 100, 500, 1000 cents), up or to the nearest. */
	public static function round_step( float $cents, int $step, string $mode ): int {
		$cents = round( $cents, 6 ); // Float noise (e.g. 1200.0000000002) must not round up.
		if ( $step <= 1 ) {
			return (int) round( $cents );
		}
		$units = $cents / $step;
		$units = 'up' === $mode ? ceil( round( $units, 6 ) ) : round( $units );
		return (int) ( $units * $step );
	}

	/**
	 * Manuscript and book pages from the input.
	 *
	 * @return array ms (manuscript pages, float), words, book (printed pages,
	 *               a multiple of the size's signature), estimated (bool)
	 */
	public static function pages( array $in ): array {
		$s     = PBQ_Settings::get();
		$sizes = PBQ_Settings::rows_on( 'sizes' );
		$size  = isset( $sizes[ $in['size'] ] ) ? $sizes[ $in['size'] ] : array(
			'wpp' => 300,
			'sig' => 16,
		);
		$wpp   = max( 1, (int) $s['ed_wpp'] );
		$words = 'words' === $s['ed_unit'] ? (int) $in['words'] : (int) $in['pages'] * $wpp;
		$ms    = $words / $wpp;
		$est   = $in['book'] <= 0;
		$book  = $est ? ( $words > 0 ? (int) ceil( $words / max( 1, (int) $size['wpp'] ) ) + (int) $s['pr_front'] : 0 ) : (int) $in['book'];
		$sig   = max( 1, (int) $size['sig'] );
		$book  = $book > 0 ? (int) ( ceil( $book / $sig ) * $sig ) : 0;
		return array(
			'ms'        => $ms,
			'words'     => $words,
			'book'      => $book,
			'estimated' => $est,
		);
	}

	/* =====================================================================
	 * Quote
	 * =================================================================== */

	/**
	 * The full quote (with the breakdown).
	 *
	 * @param array  $in   PBQ_Calc::input() result.
	 * @param string $lang 'el' | 'en' for the labels.
	 * @return array lines[], typeset (bool), total, net, vat[], per_copy,
	 *               valid_until, errors[], fields[], pages, breakdown
	 */
	public static function quote( array $in, string $lang ): array {
		$s     = PBQ_Settings::get();
		$t     = static function ( string $k, array $vars = array() ) use ( $lang ): string {
			return PBQ_Settings::text( $k, $lang, $vars );
		};
		$pages = self::pages( $in );
		$lines = array();
		$bd    = array(
			'pages' => $pages,
		);
		$err    = array();
		$fields = array();

		$bindings = PBQ_Settings::rows_on( 'bindings' );
		$binding  = $in['print'] && isset( $bindings[ $in['binding'] ] ) ? $bindings[ $in['binding'] ] : null;

		if ( $pages['words'] <= 0 && ( $in['edit'] || ( $in['print'] && $in['book'] <= 0 ) ) ) {
			$fields[] = 'words' === $s['ed_unit'] ? 'words' : 'pages';
		}

		// Editing: rate per manuscript page.
		if ( $in['edit'] ) {
			$levels = PBQ_Settings::rows_on( 'levels' );
			$level  = isset( $levels[ $in['level'] ] ) ? $levels[ $in['level'] ] : reset( $levels );
			$net    = $pages['ms'] * (float) $level['rate'];
			$lines['editing'] = array(
				'key'   => 'editing',
				'label' => $t( 'l_editing' ),
				'net'   => $net,
				'min'   => (float) $s['ed_min'],
				'vat'   => PBQ_Settings::vat_of( (float) $s['ed_vat'] ),
				'core'  => true,
				'calc'  => sprintf( '%s × %s', self::num( $pages['ms'] ), self::num( (float) $level['rate'] ) ) . ' (' . PBQ_Settings::label( $level, $lang ) . ')',
			);
		}

		// Cover design: one price, plus the hardcover extra.
		if ( $in['cover'] ) {
			$hard = $binding && ! empty( $binding['hard'] );
			$net  = (float) $s['cv_price'] + ( $hard ? (float) $s['cv_hard'] : 0.0 );
			$lines['cover'] = array(
				'key'   => 'cover',
				'label' => $t( 'l_cover' ),
				'net'   => $net,
				'min'   => 0.0,
				'vat'   => PBQ_Settings::vat_of( (float) $s['cv_vat'] ),
				'core'  => true,
				'calc'  => self::num( (float) $s['cv_price'] ) . ( $hard ? ' + ' . self::num( (float) $s['cv_hard'] ) : '' ),
			);
		}

		// Illustrations: per drawing, black and white or colour, with tiers.
		if ( $in['illus'] ) {
			if ( $in['icount'] <= 0 ) {
				$fields[] = 'icount';
			} else {
				$unit = 'color' === $in['icolor'] ? (float) $s['il_color'] : (float) $s['il_bw'];
				$pct  = self::tier( $s['il_tiers'], $in['icount'] );
				$net  = $in['icount'] * $unit * ( 1 - $pct / 100 );
				$lines['illus'] = array(
					'key'   => 'illus',
					'label' => $t( 'l_illus' ),
					'net'   => $net,
					'min'   => (float) $s['il_min'],
					'vat'   => PBQ_Settings::vat_of( (float) $s['il_vat'] ),
					'core'  => true,
					'calc'  => sprintf( '%d × %s', $in['icount'], self::num( $unit ) ) . ( $pct > 0 ? ' − ' . self::num( $pct ) . '%' : '' ),
				);
			}
		}

		// Printing.
		if ( $in['print'] && $binding ) {
			$sizes  = PBQ_Settings::rows_on( 'sizes' );
			$papers = PBQ_Settings::rows_on( 'papers' );
			$size   = isset( $sizes[ $in['size'] ] ) ? $sizes[ $in['size'] ] : reset( $sizes );
			$paper  = isset( $papers[ $in['paper'] ] ) ? $papers[ $in['paper'] ] : reset( $papers );
			$bp     = $pages['book'];
			if ( ( $binding['pmin'] > 0 && $bp < $binding['pmin'] ) || ( $binding['pmax'] > 0 && $bp > $binding['pmax'] ) ) {
				$err[]    = $t(
					'err_pages',
					array(
						'min' => (int) $binding['pmin'],
						'max' => $binding['pmax'] > 0 ? (int) $binding['pmax'] : '∞',
					)
				);
				$fields[] = 'binding';
			}
			$colour  = 'color' === $in['body'] ? (float) $s['pr_color'] : 1.0;
			$fin     = PBQ_Settings::rows_on( 'finishes' );
			$f_copy  = 0.0;
			$f_setup = 0.0;
			$f_names = array();
			$chosen  = array_merge( '' !== $in['finish'] ? array( $in['finish'] ) : array(), $in['adds'] );
			foreach ( $chosen as $fid ) {
				if ( ! isset( $fin[ $fid ] ) || ( ! empty( $fin[ $fid ]['hard'] ) && empty( $binding['hard'] ) ) ) {
					continue;
				}
				$f_copy   += (float) $fin[ $fid ]['copy'];
				$f_setup  += (float) $fin[ $fid ]['setup'];
				$f_names[] = PBQ_Settings::label( $fin[ $fid ], $lang );
			}
			$body     = $bp * (float) $paper['page'] * (float) $size['factor'] * $colour;
			$per_copy = $body + (float) $binding['copy'] + (float) $s['pr_cover'] + $f_copy;
			$pct      = self::tier( $s['pr_tiers'], $in['run'] );
			$setup    = (float) $s['pr_setup'] + (float) $binding['setup'] + $f_setup;
			$net      = $per_copy * $in['run'] * ( 1 - $pct / 100 ) + $setup;
			$lines['print'] = array(
				'key'   => 'print',
				'label' => $t( 'l_print' ),
				'net'   => $net,
				'min'   => (float) $s['pr_min'],
				'vat'   => PBQ_Settings::vat_of( (float) $s['pr_vat'] ),
				'core'  => true,
				'calc'  => sprintf(
					'(%1$d × %2$s × %3$s × %4$s + %5$s + %6$s + %7$s) × %8$d%9$s + %10$s',
					$bp,
					self::num( (float) $paper['page'] ),
					self::num( (float) $size['factor'] ),
					self::num( $colour ),
					self::num( (float) $binding['copy'] ),
					self::num( (float) $s['pr_cover'] ),
					self::num( $f_copy ),
					$in['run'],
					$pct > 0 ? ' − ' . self::num( $pct ) . '%' : '',
					self::num( $setup )
				),
			);
			$bd['print'] = array(
				'size'     => PBQ_Settings::label( $size, $lang ),
				'paper'    => PBQ_Settings::label( $paper, $lang ),
				'binding'  => PBQ_Settings::label( $binding, $lang ),
				'finishes' => $f_names,
				'per_copy' => $per_copy,
				'tier'     => $pct,
				'setup'    => $setup,
			);
		}

		// Extras.
		$extras = PBQ_Settings::rows_on( 'extras' );
		foreach ( $in['x'] as $id => $q ) {
			if ( ! isset( $extras[ $id ] ) ) {
				continue;
			}
			$x = $extras[ $id ];
			switch ( $x['kind'] ) {
				case 'qty':
					$units = (int) $q;
					break;
				case 'copy':
					if ( ! $in['print'] ) {
						continue 2;
					}
					$units = (int) $in['run'];
					break;
				case 'words':
					$units = (int) $pages['words'];
					break;
				default:
					$units = 1;
			}
			$lines[ 'x_' . $id ] = array(
				'key'   => 'x_' . $id,
				'label' => PBQ_Settings::label( $x, $lang ),
				'net'   => $units * (float) $x['price'],
				'min'   => (float) $x['min'],
				'vat'   => PBQ_Settings::vat_of( (float) $x['vat'] ),
				'core'  => false,
				'ask'   => ! empty( $x['ask'] ),
				'calc'  => sprintf( '%d × %s', $units, self::num( (float) $x['price'] ) ),
			);
		}

		// The hidden typesetting fee (design §2.2).
		$need = false;
		foreach ( $s['ts_need'] as $k ) {
			if ( isset( $lines[ $k ] ) ) {
				$need = true;
			}
		}
		$fee        = $need ? (float) $s['ts_fixed'] + (float) $s['ts_page'] * $pages['book'] : 0.0;
		$bd['fee']  = $fee;
		$shares     = array();
		$hosts      = array();
		foreach ( $lines as $k => $l ) {
			if ( ! empty( $l['core'] ) ) {
				$hosts[ $k ] = $l['net'];
			}
		}
		if ( $fee > 0 && $hosts ) {
			if ( 'prop' !== $s['ts_mode'] && isset( $hosts[ $s['ts_mode'] ] ) ) {
				$shares[ $s['ts_mode'] ] = $fee;
			} else {
				$sum = array_sum( $hosts );
				foreach ( $hosts as $k => $n ) {
					// No base to share by (all zero): split evenly.
					$shares[ $k ] = $sum > 0 ? $fee * $n / $sum : $fee / count( $hosts );
				}
			}
		}

		// Share → minimum → VAT → rounding.
		$step    = (int) $s['step'];
		$mode    = (string) $s['round'];
		$incl    = 'incl' === $s['show'];
		$out     = array();
		$sum     = 0;
		$net_sum = 0;
		$vat_by  = array();
		foreach ( $lines as $k => $l ) {
			$ask = ! empty( $l['ask'] );
			$sh  = isset( $shares[ $k ] ) ? $shares[ $k ] : 0.0;
			$n   = $l['net'] + $sh;
			$min = $n < $l['min'] && ! $ask;
			$n   = $ask ? 0.0 : max( $n, $l['min'] );
			$g   = $n * ( 1 + $l['vat'] / 100 );
			$amt = $ask ? 0 : self::round_step( ( $incl ? $g : $n ) * 100, $step, $mode );
			if ( ! $ask ) {
				$sum += $amt;
				if ( ! $incl ) {
					$key            = number_format( $l['vat'], 2, '.', '' );
					$vat_by[ $key ] = ( isset( $vat_by[ $key ] ) ? $vat_by[ $key ] : 0 ) + $amt;
				}
			}
			$out[] = array(
				'key'    => $k,
				'label'  => $l['label'],
				'amount' => $amt,
				'ask'    => $ask,
				'vat'    => $l['vat'],
				// Breakdown only.
				'base'   => $l['net'],
				'share'  => $sh,
				'minimum' => $min,
				'calc'   => $l['calc'],
			);
		}

		$vat_lines = array();
		$total     = $sum;
		if ( ! $incl ) {
			$net_sum = $sum;
			ksort( $vat_by );
			foreach ( $vat_by as $rate => $base ) {
				$v           = (int) round( $base * (float) $rate / 100 );
				$vat_lines[] = array(
					'rate'   => (float) $rate,
					'amount' => $v,
				);
				$total      += $v;
			}
		}

		$valid = '';
		if ( (int) $s['valid'] > 0 ) {
			$valid = wp_date( 'Y-m-d', time() + (int) $s['valid'] * DAY_IN_SECONDS );
		}

		if ( $fields && ! $err ) {
			$err[] = $t( 'err_input' );
		}

		return array(
			'lines'       => $out,
			'typeset'     => $fee > 0,
			'total'       => $total,
			'net'         => $net_sum,
			'vat'         => $vat_lines,
			'incl'        => $incl,
			'per_copy'    => $in['print'] && isset( $lines['print'] ) && $in['run'] > 0 ? (int) round( $total / $in['run'] ) : 0,
			'run'         => $in['print'] ? (int) $in['run'] : 0,
			'valid_until' => $valid,
			'errors'      => array_values( array_unique( $err ) ),
			'fields'      => array_values( array_unique( $fields ) ),
			'empty'       => ! $out,
			'lang'        => $lang,
			'breakdown'   => $bd,
		);
	}

	/** A number for the breakdown, without trailing zeros. */
	private static function num( float $v ): string {
		return rtrim( rtrim( number_format( $v, 4, ',', '.' ), '0' ), ',' );
	}

	/**
	 * What may leave the server: labels and rounded amounts, formatted,
	 * plus the notes. No rate, no base, no share, no breakdown.
	 */
	public static function public_view( array $q ): array {
		$lang  = $q['lang'];
		$lines = array();
		foreach ( $q['lines'] as $l ) {
			$lines[] = array(
				'label'  => $l['label'],
				'amount' => $l['ask'] ? PBQ_Settings::text( 'ask', $lang ) : ( $l['amount'] > 0 ? PBQ_Settings::format( $l['amount'] ) : PBQ_Settings::text( 'included', $lang ) ),
			);
		}
		$vat = array();
		foreach ( $q['vat'] as $v ) {
			$vat[] = array(
				'label'  => PBQ_Settings::text( 'vat_line', $lang, array( 'rate' => PBQ_Settings::rate_label( $v['rate'] ) ) ),
				'amount' => PBQ_Settings::format( $v['amount'], 2 ),
			);
		}
		$notes = array();
		$notes[] = PBQ_Settings::text( $q['incl'] ? 'vat_incl' : 'vat_excl', $lang );
		if ( '' !== $q['valid_until'] ) {
			$date = date_i18n( (string) get_option( 'date_format', 'd/m/Y' ), strtotime( $q['valid_until'] . ' 12:00:00' ) );
			$v    = PBQ_Settings::text( 'validity', $lang, array( 'date' => $date ) );
			if ( '' !== $v ) {
				$notes[] = $v;
			}
		}
		$d = PBQ_Settings::text( 'disclaimer', $lang );
		if ( '' !== $d ) {
			$notes[] = $d;
		}
		return array(
			'ok'       => ! $q['errors'] && ! $q['empty'],
			'errors'   => $q['errors'],
			'fields'   => $q['fields'],
			'empty'    => $q['empty'] ? PBQ_Settings::text( 'empty', $lang ) : '',
			'title'    => PBQ_Settings::text( 'result', $lang ),
			'lines'    => $lines,
			'typeset'  => $q['typeset'] ? array(
				'label'  => PBQ_Settings::text( 'typeset', $lang ),
				'amount' => PBQ_Settings::text( 'included', $lang ),
			) : null,
			'net'      => $q['incl'] ? null : array(
				'label'  => PBQ_Settings::text( 'net', $lang ),
				'amount' => PBQ_Settings::format( $q['net'], 2 ),
			),
			'vat'      => $vat,
			'total'    => array(
				'label'  => PBQ_Settings::text( 'total', $lang ),
				'amount' => PBQ_Settings::format( $q['total'], $q['incl'] ? null : 2 ),
			),
			'per_copy' => $q['per_copy'] > 0 ? array(
				'label'  => PBQ_Settings::text( 'per_copy', $lang ),
				'amount' => PBQ_Settings::format( $q['per_copy'], 2 ),
			) : null,
			'notes'    => $notes,
		);
	}
}
