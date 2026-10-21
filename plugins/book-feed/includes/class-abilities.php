<?php
/**
 * BKF_Abilities — Book Feed in the WordPress Abilities API (1.10.0).
 *
 * What the buttons in WP admin do, for an AI or a remote tool that runs
 * abilities (e.g. Site Bridge's ability_run), with the same capability
 * (manage_woocommerce) and the same code paths:
 *
 *  book-feed/status         job, last run, cover job, daily time, rules (read)
 *  book-feed/history        the history, newest first, with filters (read)
 *  book-feed/price-check    the publisher's price, page and cover for one
 *                           product, read now (read; refreshes the cache)
 *  book-feed/sync-start     "Sync now", or one rule's "Run now"
 *  book-feed/sync-cancel    stops the running job
 *  book-feed/square-start   Tools → "Make the existing covers square"
 *  book-feed/product-sync   the product box's "Sync": that product from
 *                           the feed now; edited fields stay locked
 *
 * Nothing here returns the feed's password or account. Registered only on
 * WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Abilities {

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
		$none = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$pid  = array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array(
					'type'        => 'integer',
					'description' => 'A product imported by Book Feed.',
				),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		);
		$defs = array(
			'status'       => array(
				'label'       => 'Book Feed: status',
				'description' => 'The running job (phase, counts), the last finished run, the cover job, the next daily run and the rules (id, name, on, every, last run, due). Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'history'      => array(
				'label'       => 'Book Feed: history',
				'description' => 'History entries, newest first: time, act (import, update, price, link, unlink, gone, back, locked, error, run, bulk), product, ISBN, title, fields, message. Filters: act, product_id; limit (default 50, at most 200).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'act'        => array(
							'type' => 'string',
							'enum' => BKF_Log::ACTS,
						),
						'product_id' => array( 'type' => 'integer' ),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'history' ),
			),
			'price-check'  => array(
				'label'       => 'Book Feed: read the publisher\'s price',
				'description' => 'Reads the publisher\'s page for one imported product now (the daily cache is refreshed): catalogue price, publisher price or the reason there is none, the book\'s page and the cover found. The product itself is not changed.',
				'input'       => $pid,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'price_check' ),
			),
			'sync-start'   => array(
				'label'       => 'Book Feed: sync now',
				'description' => 'Starts the background sync, as "Sync now" does (our products from the feed, then the rules that are due). With "rule" (a rule id from book-feed/status), runs that rule alone now. Edited fields stay locked. Follow it with book-feed/status.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'rule' => array(
							'type'        => 'string',
							'description' => 'A rule id (8 hex characters).',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'sync_start' ),
			),
			'sync-cancel'  => array(
				'label'       => 'Book Feed: stop the sync',
				'description' => 'Stops the running sync job; it is reported as stopped.',
				'input'       => $none,
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'sync_cancel' ),
			),
			'square-start' => array(
				'label'       => 'Book Feed: make the covers square',
				'description' => 'Starts the background job that turns the covers Book Feed downloaded into square PNGs (Tools). Covers uploaded by hand are never touched.',
				'input'       => $none,
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'square_start' ),
			),
			'product-sync' => array(
				'label'       => 'Book Feed: sync one product',
				'description' => 'Brings one imported product up to date from the feed now (price, stock, publisher\'s price, page and cover, and the fields we have not edited), as the product box\'s "Sync" does.',
				'input'       => $pid,
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'product_sync' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'book-feed/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/* ---------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------- */

	public static function status( $in = null ): array {
		$s     = BKF_Settings::get();
		$job   = BKF_Sync::job();
		$last  = BKF_Sync::last();
		$rules = array();
		foreach ( BKF_Settings::rules() as $r ) {
			$rules[] = array(
				'id'     => $r['id'],
				'name'   => BKF_Sync::rule_name( $r ),
				'on'     => $r['on'],
				'every'  => $r['every'],
				'status' => $r['status'],
				'last'   => $r['last'] ? gmdate( 'c', $r['last'] ) : null,
				'due'    => BKF_Settings::rule_due( $r ),
			);
		}
		return array(
			'version' => BKF_VERSION,
			'ready'   => BKF_Settings::ready(),
			'daily'   => $s['sync_on'] ? gmdate( 'c', BKF_Sync::next_daily_time() ) : null,
			'job'     => $job ? array(
				'why'   => $job['why'],
				'phase' => $job['phase'],
				'began' => gmdate( 'c', (int) $job['began'] ),
				'steps' => (int) $job['steps'],
				'stats' => $job['stats'],
			) : null,
			'last'    => $last ? array(
				'at'    => gmdate( 'c', (int) $last['t'] ),
				'stats' => $last['stats'] ?? array(),
				'error' => (string) ( $last['error'] ?? '' ),
			) : null,
			'covers'  => array(
				'job'  => BKF_Square::get(),
				'last' => BKF_Square::last(),
				'left' => BKF_Square::left(),
			),
			'rules'   => $rules,
		);
	}

	public static function history( $in = null ): array {
		$in    = is_array( $in ) ? $in : array();
		$act   = isset( $in['act'] ) && in_array( $in['act'], BKF_Log::ACTS, true ) ? $in['act'] : '';
		$pid   = isset( $in['product_id'] ) ? absint( $in['product_id'] ) : 0;
		$limit = isset( $in['limit'] ) ? max( 1, min( 200, (int) $in['limit'] ) ) : 50;
		$out   = array();
		foreach ( BKF_Log::all() as $e ) {
			if ( ( '' !== $act && $e['act'] !== $act ) || ( $pid && $e['pid'] !== $pid ) ) {
				continue;
			}
			$e['t'] = gmdate( 'c', $e['t'] );
			$out[]  = $e;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array( 'entries' => $out );
	}

	public static function price_check( $in = null ) {
		$r = self::record( $in );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( '' === BKF_Pub_Price::template( $r ) ) {
			return new WP_Error( 'bkf_no_page', 'No page is set for this publisher in the settings.' );
		}
		$why = '';
		$p   = BKF_Pub_Price::price( $r, $why, true );
		return array(
			'title'           => $r['title'],
			'isbn'            => $r['isbn'],
			'catalogue_price' => $r['price'],
			'publisher_price' => $p,
			'reason'          => $why,
			'page'            => BKF_Pub_Price::link( $r ),
			'cover'           => BKF_Pub_Price::cover( $r ),
			'saved_page'      => $r['pub_url'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------- */

	public static function sync_start( $in = null ) {
		if ( ! BKF_Settings::ready() ) {
			return new WP_Error( 'bkf_not_ready', 'Book Feed has no working connection to the feed yet (Settings).' );
		}
		$rule = is_array( $in ) && isset( $in['rule'] ) && is_string( $in['rule'] ) ? strtolower( trim( $in['rule'] ) ) : '';
		if ( '' !== $rule && ! preg_match( '/^[a-f0-9]{8}$/', $rule ) ) {
			return new WP_Error( 'bkf_rule', 'Unknown rule id.' );
		}
		if ( BKF_Sync::job() ) {
			return array(
				'started' => false,
				'reason'  => 'A sync is already running.',
				'status'  => self::status(),
			);
		}
		$ok = '' !== $rule ? BKF_Sync::start( 'rule', true, $rule ) : BKF_Sync::start( 'manual' );
		if ( ! $ok ) {
			return new WP_Error( 'bkf_rule', 'Unknown rule id.' );
		}
		return array(
			'started' => true,
			'status'  => self::status(),
		);
	}

	public static function sync_cancel( $in = null ): array {
		$had = (bool) BKF_Sync::job();
		BKF_Sync::cancel();
		return array( 'stopped' => $had );
	}

	public static function square_start( $in = null ) {
		if ( ! BKF_Square::available() ) {
			return new WP_Error( 'bkf_square', 'This server has neither GD with PNG nor Imagick.' );
		}
		return array(
			'started' => BKF_Square::start(),
			'left'    => BKF_Square::left(),
		);
	}

	public static function product_sync( $in = null ) {
		$r = self::record( $in );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$pid = (int) $r['_pid'];
		unset( $r['_pid'], $r['pub_url'] );
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		$changed = BKF_Sync::with_lock(
			static function () use ( $pid, $r ) {
				$c = BKF_Importer::update( $pid, $r, false );
				update_post_meta( $pid, '_bkf_synced', time() );
				BKF_Log::flush();
				return $c;
			}
		);
		if ( false === $changed ) {
			return new WP_Error( 'bkf_busy', 'A sync step is running; try again in a minute.' );
		}
		$ids = BKF_Importer::take_changed();
		if ( $ids ) {
			do_action( 'noxpress_products_changed', $ids );
		}
		return array(
			'product_id' => $pid,
			'changed'    => array_values( (array) $changed ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/** The feed record of an imported product (with _pid and pub_url), or WP_Error. */
	private static function record( $in ) {
		$pid = is_array( $in ) && isset( $in['product_id'] ) ? absint( $in['product_id'] ) : 0;
		$id  = $pid ? (string) get_post_meta( $pid, '_bkf_source_id', true ) : '';
		if ( ! $pid || '' === $id || '' === (string) get_post_meta( $pid, '_bkf_source', true ) ) {
			return new WP_Error( 'bkf_product', 'Not a product imported by Book Feed.' );
		}
		if ( strlen( $id ) > 64 || preg_match( '/[\x00-\x1f]/', $id ) ) {
			return new WP_Error( 'bkf_product', 'The product\'s feed id is not valid.' );
		}
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( array( 'ids' => array( $id ) ) ),
				'fl'   => BKF_Client::BOOK_FIELDS,
				'rows' => 1,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( BKF_Record::from_docs( $res['docs'] ) as $r ) {
			if ( $r['id'] === $id ) {
				$r['_pid']    = $pid;
				$r['pub_url'] = (string) get_post_meta( $pid, '_bkf_pub_url', true );
				return $r;
			}
		}
		return new WP_Error( 'bkf_missing', 'The title was not found in the feed.' );
	}
}
