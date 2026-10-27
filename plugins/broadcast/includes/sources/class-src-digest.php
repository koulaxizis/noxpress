<?php
/**
 * BRC_Src_Digest — module "digest" (WooCommerce + Book Feed): one weekly
 * message with the other publishers' titles published that week, instead
 * of one post per title.
 *
 * At dg_day / dg_hour (site time) the tick collects the products published
 * since the previous slot that Book Feed imported (bkf_get_source(), not
 * the ones it only linked) and queues one text message: {count}, {list}
 * (up to dg_max titles) and {url} (dg_url, or the shop page). A week
 * without new titles posts nothing. The first slot after the module is
 * switched on is only noted, so an old week is never posted.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Src_Digest {

	public static function init(): void {
		// Work happens in the tick (BRC_Jobs::run → maybe_run).
	}

	/** The latest slot (timestamp) at or before $now. */
	public static function slot( int $now = 0 ): int {
		$s   = BRC_Settings::get();
		$now = $now ? $now : time();
		$tz  = wp_timezone();
		$d   = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$at  = $d->setTime( $s['dg_hour'], 0, 0 );
		$at  = $at->modify( '-' . ( ( (int) $at->format( 'w' ) - $s['dg_day'] + 7 ) % 7 ) . ' days' );
		if ( $at->getTimestamp() > $now ) {
			$at = $at->modify( '-7 days' );
		}
		return $at->getTimestamp();
	}

	public static function maybe_run(): void {
		if ( ! function_exists( 'bkf_get_source' ) ) {
			return;
		}
		$slot  = self::slot();
		$key   = wp_date( 'Y-m-d', $slot );
		$notes = BRC_Queue::notes();
		$done  = isset( $notes['dg_slot'] ) ? (string) $notes['dg_slot'] : '';
		if ( $done === $key ) {
			return;
		}
		BRC_Queue::note( 'dg_slot', $key );
		if ( '' === $done ) {
			return; // First slot after switching on: noted only.
		}
		self::run( $slot - 7 * DAY_IN_SECONDS, $slot, $key );
	}

	/** Titles Book Feed imported and published between two timestamps. */
	public static function titles( int $from, int $to ): array {
		$q   = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', $from ),
						'before'    => gmdate( 'Y-m-d H:i:s', $to ),
						'inclusive' => false,
					),
				),
			)
		);
		$out = array();
		foreach ( $q->posts as $id ) {
			$src = bkf_get_source( (int) $id );
			if ( is_array( $src ) && empty( $src['linked'] ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/** The digest message for a list of product ids. */
	public static function message( array $ids ): array {
		$s     = BRC_Settings::get();
		$lines = array();
		foreach ( array_slice( $ids, 0, $s['dg_max'] ) as $id ) {
			$lines[] = '• ' . wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES );
		}
		$url = '' !== $s['dg_url'] ? $s['dg_url'] : ( function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
		$el  = 'el' === BRC_Lang::from_locale( (string) get_locale() );
		return array(
			'title' => ( $el ? 'Εβδομαδιαίο digest: ' : 'Weekly digest: ' ) . count( $ids ),
			'text'  => BRC_Template::render(
				$s['tpl_digest'],
				array(
					'count' => (string) count( $ids ),
					'list'  => implode( "\n", $lines ),
					'url'   => $url,
				)
			),
			'url'   => $url,
			'kind'  => 'text',
		);
	}

	private static function run( int $from, int $to, string $key ): void {
		$ids = self::titles( $from, $to );
		if ( ! $ids ) {
			return;
		}
		BRC_Queue::add( 'digest', 'dg:' . $key, self::message( $ids ), array( 'same_url' => false ) );
	}
}
