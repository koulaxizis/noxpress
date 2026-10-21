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
 * For the Site Bridge phone app (1.11.0):
 *
 *  book-feed/rules          every rule with all its filters (read)
 *  book-feed/rule-save      a new rule, or changes to one (its category,
 *                           display mode and last run are kept)
 *  book-feed/rule-delete    removes a rule
 *  book-feed/rule-preview   what a rule would bring on its next run,
 *                           importing nothing (read, 1.12.0)
 *  book-feed/publishers     publisher autocomplete, 3+ characters (read)
 *  book-feed/options        kinds and publishing statuses seen (read)
 *  book-feed/isbn           one ISBN: the catalogue's title and whether
 *                           the store has it (read)
 *  book-feed/import         one ISBN as a draft, never published
 *  book-feed/notices        what happened since a time: titles imported,
 *                           errors, titles gone, the last finished run
 *                           (read; the app's notifications)
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
		$none       = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$pid        = array(
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
		$rule_props = array(
			'id'        => array( 'type' => 'string' ),
			'name'      => array( 'type' => 'string' ),
			'on'        => array( 'type' => 'boolean' ),
			'pub_id'    => array( 'type' => 'string' ),
			'pub_name'  => array( 'type' => 'string' ),
			'title'     => array( 'type' => 'string' ),
			'author'    => array( 'type' => 'string' ),
			'kind'      => array( 'type' => 'string' ),
			'pstat'     => array( 'type' => 'string' ),
			'year_from' => array( 'type' => 'integer' ),
			'year_to'   => array( 'type' => 'integer' ),
			'every'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 90,
			),
			'status'    => array(
				'type' => 'string',
				'enum' => array( 'draft', 'publish' ),
			),
			'max'       => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 500,
			),
		);
		$defs       = array(
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
			'rules'        => array(
				'label'       => 'Book Feed: rules',
				'description' => 'Every rule with its filters: id, name, on, publisher (pub_id, pub_name), title words, contributor, kind, publishing status, years, every (days), status of new titles, max per run, last run and whether it is due. Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'rules' ),
			),
			'rule-save'    => array(
				'label'       => 'Book Feed: save a rule',
				'description' => 'Creates a rule, or with "id" changes that rule. A rule needs a publisher (pub_id from book-feed/publishers), title words or a contributor. The category, display mode and last run of an existing rule are kept; a new rule uses the category of the settings.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $rule_props,
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'rule_save' ),
			),
			'rule-preview' => array(
				'label'       => 'Book Feed: preview a rule',
				'description' => 'What a rule would bring on its next run, without importing anything: the titles that match, how many the store has already, how many are new and will be imported (up to its max), and the first new titles. Takes the same fields as book-feed/rule-save; with "id" the saved rule is the base (its last run decides which titles count as changed), without it the fields alone. Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => $rule_props,
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'rule_preview' ),
			),
			'rule-delete'  => array(
				'label'       => 'Book Feed: delete a rule',
				'description' => 'Removes one rule. The titles it brought stay in the store.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array( 'type' => 'string' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'rule_delete' ),
			),
			'publishers'   => array(
				'label'       => 'Book Feed: find a publisher',
				'description' => 'Publishers of the catalogue whose name has these words (3 characters at least): id and name, for a rule.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'text' => array( 'type' => 'string' ),
					),
					'required'             => array( 'text' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'publishers' ),
			),
			'options'      => array(
				'label'       => 'Book Feed: values for a rule',
				'description' => 'The kinds and publishing statuses seen in the catalogue, for a rule\'s choices.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'options' ),
			),
			'isbn'         => array(
				'label'       => 'Book Feed: look up an ISBN',
				'description' => 'The catalogue\'s title for one ISBN (title, contributors, publisher, year, price, publishing status) and the store\'s product with that ISBN, if any (id, status, whether Book Feed manages it). Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'isbn' => array( 'type' => 'string' ),
					),
					'required'             => array( 'isbn' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'isbn' ),
			),
			'import'       => array(
				'label'       => 'Book Feed: import an ISBN as a draft',
				'description' => 'Imports the catalogue\'s title for one ISBN as a draft, with the category of the settings. Refused when the store already has a product with that ISBN.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'isbn' => array( 'type' => 'string' ),
					),
					'required'             => array( 'isbn' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'import' ),
			),
			'notices'      => array(
				'label'       => 'Book Feed: what happened since',
				'description' => 'Since a Unix time (at most 7 days back): titles imported (count and the first titles), errors of the sync and of the rules, titles that left the catalogue, and the last finished run if it ended since. "now" is the time to ask from next.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'since' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'notices' ),
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
	 * Phone app (1.11.0)
	 * ------------------------------------------------------------------- */

	public static function rules( $in = null ): array {
		$out = array();
		foreach ( BKF_Settings::rules() as $r ) {
			$out[] = array(
				'id'        => $r['id'],
				'name'      => $r['name'],
				'label'     => BKF_Sync::rule_name( $r ),
				'on'        => $r['on'],
				'pub_id'    => $r['pub_id'],
				'pub_name'  => $r['pub_name'],
				'title'     => $r['title'],
				'author'    => $r['author'],
				'kind'      => $r['kind'],
				'pstat'     => $r['pstat'],
				'year_from' => $r['year_from'],
				'year_to'   => $r['year_to'],
				'every'     => $r['every'],
				'status'    => $r['status'],
				'max'       => $r['max'],
				'last'      => $r['last'] ? gmdate( 'c', $r['last'] ) : null,
				'due'       => BKF_Settings::rule_due( $r ),
			);
		}
		return array( 'rules' => $out );
	}

	/**
	 * A rule from the input: merged into the saved rule with that "id", or
	 * a new one. Returns { rule, at, rules } or WP_Error.
	 */
	private static function rule_input( $in, bool $adding ) {
		$in    = is_array( $in ) ? $in : array();
		$rules = BKF_Settings::rules();
		$id    = isset( $in['id'] ) && is_string( $in['id'] ) ? strtolower( trim( $in['id'] ) ) : '';
		$at    = null;
		if ( '' !== $id ) {
			foreach ( $rules as $i => $r ) {
				if ( $r['id'] === $id ) {
					$at = $i;
				}
			}
			if ( null === $at ) {
				return new WP_Error( 'bkf_rule', 'Unknown rule id.' );
			}
		} elseif ( $adding && count( $rules ) >= BKF_Settings::MAX_RULES ) {
			return new WP_Error( 'bkf_rule', sprintf( 'At most %d rules.', BKF_Settings::MAX_RULES ) );
		}
		$base = null !== $at ? $rules[ $at ] : array(
			'on'     => true,
			'every'  => 7,
			'max'    => 50,
			'status' => 'draft',
		);
		foreach ( array( 'name', 'pub_id', 'pub_name', 'title', 'author', 'kind', 'pstat', 'status' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_string( $in[ $k ] ) ) {
				$base[ $k ] = $in[ $k ];
			}
		}
		foreach ( array( 'year_from', 'year_to', 'every', 'max' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				$base[ $k ] = (int) $in[ $k ];
			}
		}
		if ( isset( $in['on'] ) ) {
			$base['on'] = (bool) $in['on'];
		}
		// A publisher name without its id would narrow nothing.
		if ( '' === (string) ( $base['pub_id'] ?? '' ) ) {
			$base['pub_name'] = '';
		}
		$rule = BKF_Settings::clean_rule( $base );
		if ( ! $rule ) {
			return new WP_Error( 'bkf_rule', 'A rule needs a publisher, title words or a contributor.' );
		}
		return array(
			'rule'  => $rule,
			'at'    => $at,
			'rules' => $rules,
		);
	}

	public static function rule_save( $in = null ) {
		$got = self::rule_input( $in, true );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$rule  = $got['rule'];
		$at    = $got['at'];
		$rules = $got['rules'];
		if ( null !== $at ) {
			$rules[ $at ] = $rule;
		} else {
			$rules[] = $rule;
		}
		BKF_Settings::save_rules( $rules );
		return array(
			'saved' => true,
			'id'    => $rule['id'],
			'rules' => self::rules()['rules'],
		);
	}

	public static function rule_delete( $in = null ) {
		$id    = is_array( $in ) && isset( $in['id'] ) && is_string( $in['id'] ) ? strtolower( trim( $in['id'] ) ) : '';
		$rules = BKF_Settings::rules();
		$keep  = array();
		foreach ( $rules as $r ) {
			if ( $r['id'] !== $id ) {
				$keep[] = $r;
			}
		}
		if ( count( $keep ) === count( $rules ) ) {
			return new WP_Error( 'bkf_rule', 'Unknown rule id.' );
		}
		BKF_Settings::save_rules( $keep );
		return array(
			'deleted' => true,
			'rules'   => self::rules()['rules'],
		);
	}

	/** What the rule (saved, or as typed) would bring on its next run; imports nothing. */
	public static function rule_preview( $in = null ) {
		$got = self::rule_input( $in, false );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		return BKF_Sync::preview( $got['rule'] );
	}

	public static function publishers( $in = null ) {
		$text = is_array( $in ) && isset( $in['text'] ) && is_string( $in['text'] ) ? BKF_Settings::text( $in['text'], 100 ) : '';
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) < 3 ) {
			return array( 'publishers' => array() );
		}
		$res = BKF_Client::publishers( $text );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'publishers' => array_values( (array) $res ) );
	}

	public static function options( $in = null ): array {
		$seen = BKF_Settings::seen();
		return array(
			'kinds'    => $seen['kind'],
			'statuses' => array_values( array_unique( array_merge( array( 'Κυκλοφορεί', 'Εξαντλημένο' ), $seen['status'] ) ) ),
		);
	}

	public static function isbn( $in = null ) {
		$isbn = self::isbn_in( $in );
		if ( is_wp_error( $isbn ) ) {
			return $isbn;
		}
		$rec = self::by_isbn( $isbn );
		if ( is_wp_error( $rec ) ) {
			return $rec;
		}
		$pid = BKF_Importer::find_by_isbn( $isbn );
		$out = array(
			'isbn'    => $isbn,
			'found'   => null !== $rec,
			'title'   => null,
			'product' => null,
		);
		if ( $rec ) {
			$names = array();
			foreach ( (array) $rec['contribs'] as $c ) {
				if ( is_array( $c ) && ! empty( $c['name'] ) ) {
					$names[] = (string) $c['name'];
				}
			}
			$out['title'] = array(
				'id'        => $rec['id'],
				'title'     => $rec['title'],
				'subtitle'  => (string) ( $rec['subtitle'] ?? '' ),
				'people'    => array_slice( array_values( array_unique( $names ) ), 0, 6 ),
				'publisher' => (string) ( $rec['pub_name'] ?? '' ),
				'year'      => (int) ( $rec['year'] ?? 0 ),
				'price'     => $rec['price'],
				'pstat'     => (string) ( $rec['status'] ?? '' ),
				'kind'      => (string) ( $rec['kind'] ?? '' ),
			);
		}
		if ( $pid ) {
			$p              = wc_get_product( $pid );
			$out['product'] = array(
				'id'     => $pid,
				'name'   => $p ? $p->get_name() : '',
				'status' => (string) get_post_status( $pid ),
				'price'  => $p ? (string) $p->get_price() : '',
				'feed'   => '' !== (string) get_post_meta( $pid, '_bkf_source_id', true ),
			);
		}
		return $out;
	}

	public static function import( $in = null ) {
		if ( ! BKF_Settings::ready() ) {
			return new WP_Error( 'bkf_not_ready', 'Book Feed has no working connection to the feed yet (Settings).' );
		}
		$isbn = self::isbn_in( $in );
		if ( is_wp_error( $isbn ) ) {
			return $isbn;
		}
		if ( BKF_Importer::find_by_isbn( $isbn ) ) {
			return new WP_Error( 'bkf_exists', 'The store already has a product with this ISBN.' );
		}
		$rec = self::by_isbn( $isbn );
		if ( is_wp_error( $rec ) ) {
			return $rec;
		}
		if ( ! $rec ) {
			return new WP_Error( 'bkf_missing', 'The catalogue has no title with this ISBN.' );
		}
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		$row = BKF_Sync::with_lock(
			static function () use ( $rec ) {
				$row = BKF_Importer::import(
					$rec,
					array(
						'status' => 'draft',
						'cat'    => 0,
						'mode'   => 'sell',
					)
				);
				BKF_Log::flush();
				return $row;
			}
		);
		if ( false === $row ) {
			return new WP_Error( 'bkf_busy', 'A sync step is running; try again in a minute.' );
		}
		$ids = BKF_Importer::take_changed();
		if ( $ids ) {
			do_action( 'noxpress_products_changed', $ids );
		}
		return array(
			'result'     => (string) $row['result'],
			'product_id' => (int) $row['pid'],
			'message'    => (string) ( $row['msg'] ?? '' ),
		);
	}

	public static function notices( $in = null ): array {
		$now   = time();
		$since = is_array( $in ) && isset( $in['since'] ) ? (int) $in['since'] : 0;
		$since = max( $now - 7 * DAY_IN_SECONDS, min( $now, $since ) );
		$out   = array(
			'now'      => $now,
			'since'    => $since,
			'imported' => 0,
			'titles'   => array(),
			'errors'   => array(),
			'gone'     => 0,
			'last_run' => null,
		);
		$last  = BKF_Sync::last();
		if ( $last && (int) $last['t'] > $since ) {
			$out['last_run'] = array(
				'at'    => (int) $last['t'],
				'why'   => (string) ( $last['why'] ?? '' ),
				'stats' => $last['stats'] ?? array(),
				'error' => (string) ( $last['error'] ?? '' ),
			);
		}
		foreach ( BKF_Log::all() as $e ) {
			if ( $e['t'] <= $since ) {
				break;
			}
			switch ( $e['act'] ) {
				case 'import':
					++$out['imported'];
					if ( count( $out['titles'] ) < 5 && '' !== $e['title'] ) {
						$out['titles'][] = $e['title'];
					}
					break;
				case 'error':
					// Job-level errors only: a product's cover or price error is in the history.
					if ( ! $e['pid'] && count( $out['errors'] ) < 5 ) {
						$out['errors'][] = $e['msg'];
					}
					break;
				case 'gone':
					++$out['gone'];
					break;
			}
		}
		return $out;
	}

	/** The ISBN digits from the input (10 or 13, X allowed last), or WP_Error. */
	private static function isbn_in( $in ) {
		$raw = is_array( $in ) && isset( $in['isbn'] ) && is_scalar( $in['isbn'] ) ? strtoupper( (string) $in['isbn'] ) : '';
		$d   = preg_replace( '/[^0-9X]/', '', $raw );
		if ( ! preg_match( '/^(\d{9}[\dX]|\d{13})$/', $d ) ) {
			return new WP_Error( 'bkf_isbn', 'Not an ISBN (10 or 13 digits).' );
		}
		return $d;
	}

	/** The catalogue's record for an ISBN, null when none, or WP_Error. */
	private static function by_isbn( string $isbn ) {
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( array( 'isbn' => $isbn ) ),
				'fl'   => BKF_Client::BOOK_FIELDS,
				'rows' => 5,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$recs = BKF_Record::from_docs( $res['docs'] );
		foreach ( $recs as $r ) {
			if ( $r['isbn_digits'] === $isbn || substr( $r['isbn_digits'], -10, 9 ) === substr( $isbn, 0, 9 ) ) {
				return $r;
			}
		}
		return $recs ? $recs[0] : null;
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
