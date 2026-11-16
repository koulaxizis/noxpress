<?php
/**
 * EBD_Abilities — Ebook Delivery in the WordPress Abilities API, for an AI
 * or a remote tool that runs abilities (e.g. Site Bridge's ability_run),
 * with the same capability as the settings (manage_woocommerce):
 *
 *  ebook-delivery/status       mode, what is stamped, cache (count and
 *                              size), the latest 30 issues. Read only.
 *  ebook-delivery/lookup-code  the order, product and download count of a
 *                              fingerprint code «ED-…». Read only; the
 *                              buyer's e-mail comes back masked.
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$meta = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => false,
		);
		wp_register_ability(
			'ebook-delivery/status',
			array(
				'label'               => 'Ebook Delivery: status',
				'description'         => 'Buyer watermarks in PDF and EPUB downloads: mode (off / test / live), which formats are stamped, size limit, cache days, clean file names, library page, number and size of cached stamped copies, and the latest 30 issues (time, product id, file name, reason: encrypted, damaged, unsupported, too_big, disk, folder, remote, io, error). Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'meta'                => $meta,
			)
		);
		wp_register_ability(
			'ebook-delivery/lookup-code',
			array(
				'label'               => 'Ebook Delivery: find a fingerprint code',
				'description'         => 'Finds which order a watermarked file came from, by the code printed in it («ED-» and 8 characters). Returns order id and number, product id and name, download count and when access was granted; the buyer e-mail is masked. Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'The code, e.g. ED-7K3QX9AB.',
							'maxLength'   => 32,
						),
					),
					'required'             => array( 'code' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'lookup' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'meta'                => $meta,
			)
		);
	}

	public static function allowed(): bool {
		return current_user_can( Ebook_Delivery::cap() );
	}

	public static function status( $in = null ): array {
		$s      = EBD_Settings::get();
		$st     = EBD_Store::stats();
		$issues = array();
		foreach ( array_slice( EBD_Store::issues(), 0, 30 ) as $r ) {
			$issues[] = array(
				'time'       => gmdate( 'c', $r['t'] ),
				'product_id' => $r['p'],
				'file'       => $r['f'],
				'reason'     => $r['r'],
			);
		}
		return array(
			'version'      => EBD_VERSION,
			'mode'         => $s['mode'],
			'disabled'     => Ebook_Delivery::killed(),
			'pdf'          => (bool) $s['pdf'],
			'epub'         => (bool) $s['epub'],
			'max_mb'       => (int) $s['max_mb'],
			'cache_days'   => (int) $s['cache_days'],
			'clean_names'  => (bool) $s['clean_names'],
			'library'      => (bool) $s['library'],
			'cached_files' => $st['count'],
			'cached_bytes' => $st['bytes'],
			'issues'       => $issues,
		);
	}

	public static function lookup( $in = null ): array {
		$code = is_array( $in ) && isset( $in['code'] ) && is_string( $in['code'] ) ? EBD_Code::normalise( $in['code'] ) : '';
		if ( '' === $code ) {
			return array(
				'code'    => '',
				'found'   => false,
				'message' => 'Not a valid code (ED- and 8 characters).',
			);
		}
		$out = array();
		foreach ( EBD_Code::lookup( $code ) as $r ) {
			$order   = wc_get_order( $r['order_id'] );
			$product = wc_get_product( $r['product_id'] );
			$out[]   = array(
				'order_id'       => $r['order_id'],
				'order_number'   => $order ? (string) $order->get_order_number() : '',
				'product_id'     => $r['product_id'],
				'product'        => $product ? wp_strip_all_tags( $product->get_name() ) : '',
				'email'          => EBD_Delivery_Mask::email( $r['user_email'] ),
				'download_count' => $r['download_count'],
				'access_granted' => $r['access_granted'],
			);
		}
		return array(
			'code'    => $code,
			'found'   => ! empty( $out ),
			'matches' => $out,
		);
	}
}

/** Masking without loading the delivery hooks (EBD_DISABLE). */
final class EBD_Delivery_Mask {
	public static function email( string $email ): string {
		$at = strpos( $email, '@' );
		return ( false === $at || $at < 1 ) ? '' : mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}
}
