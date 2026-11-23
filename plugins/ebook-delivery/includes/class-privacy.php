<?php
/**
 * EBD_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the stamped copies of an address.
 *
 * A stamped copy (the private folder, EBD_Store) carries the buyer's name,
 * the order and the download code, and is kept for cache_days after it
 * was made. Copies are named "<download permission id>-<hex>", so the
 * address's copies are the copies of its WooCommerce download
 * permissions. The download permissions and the download log themselves
 * are WooCommerce's data, exported and erased by WooCommerce. The
 * fingerprint code is an HMAC of the order, product and file, and the
 * issue log holds no personal data.
 *
 * Export: the stamped copies kept for the address (product, file type,
 * when made). Erase: those copies are deleted (a later download makes a
 * new one for a buyer who still has the permission).
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list                   = is_array( $list ) ? $list : array();
		$list['ebook-delivery'] = array(
			'exporter_friendly_name' => __( 'Ebook Delivery: προσωποποιημένα αντίτυπα', 'ebook-delivery' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                   = is_array( $list ) ? $list : array();
		$list['ebook-delivery'] = array(
			'eraser_friendly_name' => __( 'Ebook Delivery: προσωποποιημένα αντίτυπα', 'ebook-delivery' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * The address's copies: permission id => array( product id, paths ).
	 *
	 * @param string $email Address.
	 * @return array
	 */
	private static function copies( string $email ): array {
		$dir = EBD_Store::dir();
		if ( '' === $dir || ! is_dir( $dir ) || ! is_email( $email ) ) {
			return array();
		}
		$perms = array();
		try {
			$store = WC_Data_Store::load( 'customer-download' );
			foreach ( (array) $store->get_downloads( array( 'user_email' => $email, 'limit' => -1 ) ) as $d ) {
				if ( $d instanceof WC_Customer_Download ) {
					$perms[ (int) $d->get_id() ] = (int) $d->get_product_id();
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		if ( ! $perms ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $f ) {
			$f = (string) $f;
			if ( 1 !== preg_match( EBD_Store::FILE_RE, $f ) ) {
				continue;
			}
			$pid = (int) strtok( $f, '-' );
			if ( isset( $perms[ $pid ] ) ) {
				$out[] = array(
					'product' => $perms[ $pid ],
					'path'    => $dir . '/' . $f,
				);
			}
		}
		return $out;
	}

	public static function export( $email, $page = 1 ): array {
		$items = array();
		foreach ( self::copies( strtolower( trim( (string) $email ) ) ) as $i => $c ) {
			$p       = wc_get_product( (int) $c['product'] );
			$items[] = array(
				'group_id'    => 'ebook-delivery',
				'group_label' => __( 'Προσωποποιημένα αντίτυπα', 'ebook-delivery' ),
				'item_id'     => 'ebd-' . (int) $i,
				'data'        => array(
					array(
						'name'  => __( 'Προϊόν', 'ebook-delivery' ),
						'value' => $p ? wp_strip_all_tags( $p->get_name() ) : '#' . (int) $c['product'],
					),
					array(
						'name'  => __( 'Αρχείο', 'ebook-delivery' ),
						'value' => strtoupper( pathinfo( $c['path'], PATHINFO_EXTENSION ) ),
					),
					array(
						'name'  => __( 'Δημιουργία', 'ebook-delivery' ),
						'value' => gmdate( 'Y-m-d H:i', (int) @filemtime( $c['path'] ) ) . ' UTC', // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$removed  = false;
		$retained = false;
		foreach ( self::copies( strtolower( trim( (string) $email ) ) ) as $c ) {
			if ( EBD_Store::is_ours( $c['path'] ) && @unlink( $c['path'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$removed = true;
			} else {
				$retained = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Κάποια αντίτυπα δεν σβήστηκαν (δικαιώματα αρχείων). Σβήνονται μόνα τους με τον ημερήσιο καθαρισμό.', 'ebook-delivery' ) ) : array(),
			'done'           => true,
		);
	}
}
