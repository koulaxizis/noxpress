<?php
/**
 * PFD_Channels — registry of the feed writers (includes/channels/).
 *
 * A writer is a final class with static methods:
 *  - head( array $feed ): string   start of the file
 *  - rows( array $items, array $feed ): string   the items of one product
 *  - foot(): string                end of the file
 *  - check( array $item, array $feed ): array [ errors[], warnings[] ]
 *
 * Every rule of a service (field names, accepted values, limits) lives in
 * its own writer, so a change of a service's specification stays local.
 */

defined( 'ABSPATH' ) || exit;

require_once PFD_PATH . 'includes/channels/class-google.php';
require_once PFD_PATH . 'includes/channels/class-meta.php';
require_once PFD_PATH . 'includes/channels/class-skroutz.php';
require_once PFD_PATH . 'includes/channels/class-bestprice.php';
require_once PFD_PATH . 'includes/channels/class-csv.php';

final class PFD_Channels {

	/** channel => [ class, label, extension ]. */
	private static function map(): array {
		return array(
			'google'    => array( 'PFD_Ch_Google', 'Google Merchant Center', 'xml' ),
			'skroutz'   => array( 'PFD_Ch_Skroutz', 'Skroutz', 'xml' ),
			'bestprice' => array( 'PFD_Ch_BestPrice', 'BestPrice', 'xml' ),
			'meta'      => array( 'PFD_Ch_Meta', 'Meta (Facebook / Instagram)', 'xml' ),
			'csv'       => array( 'PFD_Ch_Csv', 'CSV', 'csv' ),
		);
	}

	public static function all(): array {
		$out = array();
		foreach ( self::map() as $k => $v ) {
			$out[ $k ] = $v[1];
		}
		return $out;
	}

	public static function exists( string $ch ): bool {
		return isset( self::map()[ $ch ] );
	}

	public static function label( string $ch ): string {
		return self::map()[ $ch ][1] ?? $ch;
	}

	public static function ext( string $ch ): string {
		return self::map()[ $ch ][2] ?? 'xml';
	}

	/** @return string Class name of the writer. */
	public static function writer( string $ch ): string {
		return self::map()[ $ch ][0] ?? 'PFD_Ch_Google';
	}

	/** Shipping-time values a channel accepts (empty = no such field). */
	public static function ship_values( string $ch ): array {
		$w = self::writer( $ch );
		return method_exists( $w, 'ship_values' ) ? $w::ship_values() : array();
	}

	/** One product per item group (variations inside) or one row each. */
	public static function grouped( string $ch ): bool {
		return in_array( $ch, array( 'skroutz', 'bestprice' ), true );
	}

	/* =====================================================================
	 * Shared helpers of the writers
	 * =================================================================== */

	/** XML text: control characters out, escaped. */
	public static function x( string $s ): string {
		$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s );
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/** <name>escaped</name>, or '' for an empty value. */
	public static function el( string $name, $value, string $indent = "\t\t" ): string {
		$value = (string) $value;
		return '' === $value ? '' : $indent . '<' . $name . '>' . self::x( $value ) . '</' . $name . ">\n";
	}

	/** <name><![CDATA[…]]></name> (the closing sequence split when present). */
	public static function cdata( string $name, $value, string $indent = "\t\t" ): string {
		$value = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $value );
		if ( '' === (string) $value ) {
			return '';
		}
		return $indent . '<' . $name . '><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . ']]></' . $name . ">\n";
	}

	public static function money( float $v ): string {
		return number_format( $v, 2, '.', '' );
	}

	/** ISO 8601 of a local 'Y-m-d' day at 00:00 in the site's time zone. */
	public static function iso_day( string $ymd ): string {
		try {
			$d = new DateTimeImmutable( $ymd . ' 00:00:00', wp_timezone() );
			return $d->format( 'Y-m-d\TH:iO' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Common checks every channel shares. */
	public static function base_check( array $it ): array {
		$err  = array();
		$warn = array();
		if ( '' === $it['title'] ) {
			$err[] = 'no_title';
		}
		if ( '' === $it['image'] ) {
			$err[] = 'no_image';
		}
		if ( $it['price'] <= 0 ) {
			$err[] = 'no_price';
		}
		if ( '' === $it['desc'] ) {
			$warn[] = 'no_desc';
		}
		if ( '' === $it['gtin'] ) {
			$warn[] = 'no_gtin';
		} elseif ( ! PFD_Item::gtin_ok( $it['gtin'] ) ) {
			$warn[] = 'bad_gtin';
		}
		if ( '' === $it['brand'] ) {
			$warn[] = 'no_brand';
		}
		return array( $err, $warn );
	}
}
