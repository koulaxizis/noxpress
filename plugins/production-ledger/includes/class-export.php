<?php
/**
 * PL_Export — CSV per Bible §5: UTF-8 with BOM, headers in the admin
 * language, «Excel ελληνικά» (; and decimal comma) or «Τυπικό» (, and
 * decimal point), numbers without thousands separators, formula guard on
 * text cells only. Two files per month: the entries (one row each) and
 * the summary (one row per product, then the general ledger and totals).
 *
 * The same text is returned by the export-csv ability; the admin sends it
 * as a download (privileged GET on admin_init, Bible §11).
 */

defined( 'ABSPATH' ) || exit;

final class PL_Export {

	/** @return array{0:string,1:string} [separator, decimal mark] */
	private static function fmt( string $format ): array {
		return 'standard' === $format ? array( ',', '.' ) : array( ';', ',' );
	}

	private static function num( float $v, string $dec ): string {
		$s = number_format( $v, 2, '.', '' );
		return ',' === $dec ? str_replace( '.', ',', $s ) : $s;
	}

	private static function text( string $v ): string {
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $v;
		}
		return $v;
	}

	private static function build( array $rows, string $sep ): string {
		$fh = fopen( 'php://temp', 'w+' );
		if ( false === $fh ) {
			return '';
		}
		fwrite( $fh, "\xEF\xBB\xBF" );
		foreach ( $rows as $r ) {
			fputcsv( $fh, $r, $sep, '"', '\\' );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/** First and last day of a Y-m month, or null. */
	public static function month_range( string $month ): ?array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			return null;
		}
		return array( $month . '-01', gmdate( 'Y-m-t', strtotime( $month . '-01' ) ) );
	}

	/**
	 * CSV text.
	 *
	 * @param string $what   entries | summary.
	 * @param string $month  Y-m.
	 * @param string $format excel | standard.
	 * @return string|WP_Error
	 */
	public static function csv( string $what, string $month, string $format ) {
		$range = self::month_range( $month );
		if ( null === $range ) {
			return new WP_Error( 'pl_month', 'month must be YYYY-MM.' );
		}
		list( $sep, $dec ) = self::fmt( $format );
		return 'summary' === $what ? self::summary( $range, $sep, $dec ) : self::entries( $range, $sep, $dec );
	}

	private static function entries( array $range, string $sep, string $dec ): string {
		$rows   = array();
		$rows[] = array(
			__( 'Α/Α', 'production-ledger' ),
			__( 'Ημερομηνία', 'production-ledger' ),
			__( 'Είδος', 'production-ledger' ),
			__( 'Προϊόν (ID)', 'production-ledger' ),
			__( 'Προϊόν', 'production-ledger' ),
			__( 'Κατηγορία', 'production-ledger' ),
			__( 'Κατανομή', 'production-ledger' ),
			__( 'Προμηθευτής', 'production-ledger' ),
			__( 'Αρ. παραστατικού', 'production-ledger' ),
			__( 'Περιγραφή', 'production-ledger' ),
			__( 'Καθαρό', 'production-ledger' ),
			__( 'ΦΠΑ', 'production-ledger' ),
			__( 'Συντελεστής ΦΠΑ %', 'production-ledger' ),
			__( 'Νόμισμα', 'production-ledger' ),
			__( 'Πηγή', 'production-ledger' ),
			__( 'Παραστατικό', 'production-ledger' ),
		);
		$scopes = array(
			'product' => __( 'Προϊόν', 'production-ledger' ),
			'pool'    => __( 'Μοιρασμένο', 'production-ledger' ),
			'general' => __( 'Γενικό', 'production-ledger' ),
		);
		$all    = PL_Entries::query(
			array(
				'from'  => $range[0],
				'to'    => $range[1],
				'test'  => false,
				'limit' => 5000,
			)
		)['rows'];
		foreach ( array_reverse( $all ) as $e ) {
			$o      = PL_Entries::out( $e );
			$rows[] = array(
				$o['id'],
				$o['date'],
				$scopes[ $o['scope'] ],
				$o['product_id'] > 0 ? $o['product_id'] : '',
				self::text( $o['product'] ),
				self::text( PL_Entries::label( $o['category'] ) ),
				self::text( $o['pool'] ),
				self::text( $o['supplier'] ),
				self::text( $o['invoice_no'] ),
				self::text( $o['description'] ),
				self::num( $o['net'], $dec ),
				self::num( $o['vat'], $dec ),
				self::num( $o['vat_rate'], $dec ),
				$o['currency'],
				$o['source'],
				self::text( $o['attachment_url'] ),
			);
		}
		return self::build( $rows, $sep );
	}

	private static function summary( array $range, string $sep, string $dec ): string {
		$rep = PL_Report::get( $range[0], $range[1] );
		if ( is_wp_error( $rep ) ) {
			return '';
		}
		$cats = array();
		foreach ( PL_Entries::categories() as $slug => $c ) {
			if ( 'cost' === $c[1] && 'general' !== $slug ) {
				$cats[ $slug ] = $c[0];
			}
		}
		$head = array(
			__( 'Προϊόν (ID)', 'production-ledger' ),
			__( 'Προϊόν', 'production-ledger' ),
			__( 'Τεμάχια', 'production-ledger' ),
			__( 'Λιανική', 'production-ledger' ),
			__( 'Χονδρική', 'production-ledger' ),
			__( 'Έσοδα εκτός site', 'production-ledger' ),
			__( 'ΦΠΑ πωλήσεων', 'production-ledger' ),
			__( 'Μεταφορικά που πλήρωσαν οι πελάτες', 'production-ledger' ),
			__( 'Αμοιβές δημιουργών (RS)', 'production-ledger' ),
		);
		foreach ( $cats as $label ) {
			$head[] = $label;
		}
		$head  = array_merge(
			$head,
			array(
				__( 'Κόστος αποστολής (μεταφορέας)', 'production-ledger' ),
				__( 'Αναλώσιμα (μερίδιο)', 'production-ledger' ),
				__( 'Προμήθειες πληρωμών (μερίδιο)', 'production-ledger' ),
				__( 'ΦΠΑ αγορών', 'production-ledger' ),
				__( 'Καθαρό αποτέλεσμα', 'production-ledger' ),
				__( 'Νεκρό σημείο (τεμάχια)', 'production-ledger' ),
			)
		);
		$rows  = array( $head );
		foreach ( $rep['products'] as $p ) {
			$r = array(
				$p['product_id'],
				self::text( $p['title'] ),
				$p['units'],
				self::num( $p['retail'], $dec ),
				self::num( $p['trade'], $dec ),
				self::num( $p['offsite'], $dec ),
				self::num( $p['vat_out'], $dec ),
				self::num( $p['shipping_charged'], $dec ),
				self::num( $p['creators_rs'], $dec ),
			);
			foreach ( array_keys( $cats ) as $slug ) {
				$r[] = self::num( isset( $p['costs'][ $slug ] ) ? $p['costs'][ $slug ] : 0.0, $dec );
			}
			$r[]    = self::num( $p['shipping_cost'], $dec );
			$r[]    = self::num( $p['consumables'], $dec );
			$r[]    = self::num( $p['payment_fees'], $dec );
			$r[]    = self::num( $p['vat_in'], $dec );
			$r[]    = self::num( $p['net'], $dec );
			$r[]    = null === $p['break_even'] ? '' : $p['break_even'];
			$rows[] = $r;
		}
		$rows[] = array();
		$rows[] = array( '', __( 'Σύνολο προϊόντων', 'production-ledger' ), '', '', '', '', '', '', '', self::num( $rep['totals']['net'], $dec ) );
		foreach ( $rep['general']['by_category'] as $cat => $v ) {
			$rows[] = array( '', self::text( __( 'Γενικά έξοδα', 'production-ledger' ) . ': ' . PL_Entries::label( (string) $cat ) ), '', '', '', '', '', '', '', self::num( -$v, $dec ) );
		}
		foreach ( $rep['general']['unallocated'] as $k => $v ) {
			$rows[] = array( '', self::text( __( 'Χωρίς παραγγελίες για κατανομή', 'production-ledger' ) . ': ' . $k ), '', '', '', '', '', '', '', self::num( -$v, $dec ) );
		}
		$rows[] = array( '', __( 'Τελικό αποτέλεσμα', 'production-ledger' ), '', '', '', '', '', '', '', self::num( $rep['grand_total'], $dec ) );
		return self::build( $rows, $sep );
	}

	/** File name per §5: <slug>-<what>-<from>_<to>.csv. */
	public static function filename( string $what, string $month ): string {
		$r = self::month_range( $month );
		return 'production-ledger-' . ( 'summary' === $what ? 'summary' : 'entries' ) . '-' . ( $r ? $r[0] . '_' . $r[1] : $month ) . '.csv';
	}
}
