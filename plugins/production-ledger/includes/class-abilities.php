<?php
/**
 * PL_Abilities — Production Ledger in the WordPress Abilities API (6.9+),
 * for Site Bridge's ability_run and the Publisher desk, with the same
 * capability (manage_woocommerce) and the same code as the admin:
 *
 *  production-ledger/report        the computed report (read)
 *  production-ledger/entries       entries with filters (read)
 *  production-ledger/export-csv    a month as CSV text (read)
 *  production-ledger/add-entry     1–100 entries from invoices (write)
 *  production-ledger/update-entry  changes one entry (write)
 *  production-ledger/analysis      title analysis: channels, delivery, promo (read, 1.3.0)
 *  production-ledger/rates         price list + promo package (read, 1.3.0)
 *  production-ledger/rate-add      a material or a new price (write, 1.3.0)
 *  production-ledger/package-set   the promo package (write, 1.3.0)
 *  production-ledger/promo-apply   the package as entries of a title (write, 1.3.0)
 *  production-ledger/event-sale    a sale at an event as an order (write, 1.3.0)
 *  production-ledger/delete-entry  soft delete or restore (write; not
 *                                  destructive: the row stays and comes
 *                                  back with restore, Bible §21)
 *
 * Messages are in English (Bible §21).
 */

defined( 'ABSPATH' ) || exit;

final class PL_Abilities {

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

	private static function entry_schema( bool $for_update ): array {
		$props = array(
			'date'           => array(
				'type'        => 'string',
				'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				'description' => 'Invoice date, YYYY-MM-DD, not before the ledger start date.',
			),
			'product_id'     => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'The product (a variation counts as its product). 0 = not one product: with pool = spread over the month\'s orders, without pool = general expense.',
			),
			'category'       => array(
				'type' => 'string',
				'enum' => array_keys( PL_Entries::categories() ),
			),
			'pool'           => array(
				'type'        => 'string',
				'maxLength'   => 20,
				'description' => 'Only with product_id 0. shipping: a carrier key; consumables: all; payment_fees: a payment group key (see report.settings).',
			),
			'supplier'       => array(
				'type'      => 'string',
				'maxLength' => 120,
			),
			'invoice_no'     => array(
				'type'        => 'string',
				'maxLength'   => 60,
				'description' => 'Supplier invoice / receipt number (for bank reconciliation).',
			),
			'description'    => array(
				'type'      => 'string',
				'maxLength' => 255,
			),
			'net'            => array(
				'type'        => 'number',
				'minimum'     => 0,
				'description' => 'Net amount excl. VAT, shop currency, ≤ 2 decimals.',
			),
			'vat'            => array(
				'type'        => 'number',
				'minimum'     => 0,
				'description' => 'VAT amount; computed from vat_rate when left out.',
			),
			'vat_rate'       => array(
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 100,
			),
			'qty_basis'      => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Optional count behind the amount, e.g. parcels on a carrier invoice.',
			),
			'item'           => array(
				'type'        => 'string',
				'maxLength'   => 40,
				'description' => 'The key of a material of the price list (production-ledger/rates), e.g. bookmark or ship_island; groups promotion costs by material.',
			),
			'attachment_url' => array(
				'type'        => 'string',
				'maxLength'   => 500,
				'description' => 'Link to the invoice (media library or project files).',
			),
			'test'           => array(
				'type'        => 'boolean',
				'description' => 'A test row: never in the report or the orOS feed; purgeable.',
			),
			'historical'     => array(
				'type'        => 'boolean',
				'description' => 'A cost from before the ledger start date, kept only for statistics: must be dated before the start date; never in the report, totals, break-even, the orOS feed or the monthly CSV.',
			),
		);
		if ( $for_update ) {
			$props = array_merge(
				array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				$props
			);
		}
		return $props;
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$defs = array(
			'report'       => array(
				'label'       => 'Production Ledger: report',
				'description' => 'Net result per product for a period (all amounts net of VAT): retail and trade revenue, off-site revenue, creator payouts from Revenue Splitter, costs per category, shipping charged vs carrier cost, consumables and payment-fee shares, net, break-even units since the start date; then general expenses (and pools with no order to spread over) and the grand total. Also the settings the numbers depend on (start date, publisher names, carriers, payment groups).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'       => $date,
						'to'         => $date,
						'product_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'required'             => array( 'from', 'to' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'report' ),
			),
			'entries'      => array(
				'label'       => 'Production Ledger: entries',
				'description' => 'Ledger entries, newest first, with filters: from, to, product_id, scope (product | pool | general), category, search (supplier, description, invoice number), deleted (only deleted rows), test (only test / only real).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'       => $date,
						'to'         => $date,
						'product_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'scope'      => array(
							'type' => 'string',
							'enum' => array( '', 'product', 'pool', 'general' ),
						),
						'category'   => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), array_keys( PL_Entries::categories() ) ),
						),
						'search'     => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'deleted'    => array( 'type' => 'boolean' ),
						'test'       => array( 'type' => 'boolean' ),
						'historical' => array(
							'type'        => 'boolean',
							'description' => 'true = only historical entries (before the start date); default: left out.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
							'default' => 100,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'entries' ),
			),
			'export-csv'   => array(
				'label'       => 'Production Ledger: export CSV',
				'description' => 'A month as CSV text (UTF-8 with BOM): what = entries (one row per entry), summary (one row per product, then general expenses and totals) or historical (every entry from before the start date, kept only for statistics; month is ignored); format = excel (; and decimal comma) or standard (, and decimal point).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'month'  => array(
							'type'    => 'string',
							'pattern' => '^\d{4}-\d{2}$',
						),
						'what'   => array(
							'type'    => 'string',
							'enum'    => array( 'entries', 'summary', 'historical' ),
							'default' => 'entries',
						),
						'format' => array(
							'type'    => 'string',
							'enum'    => array( 'excel', 'standard' ),
							'default' => 'standard',
						),
					),
					'required'             => array( 'month' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'export' ),
			),
			'add-entry'    => array(
				'label'       => 'Production Ledger: add entries',
				'description' => 'Adds 1–100 entries typed from supplier invoices. Each is validated (category from the fixed list, amounts ≥ 0 with ≤ 2 decimals, the product exists, date not before the start date, pool valid for its category); if any entry fails, NONE is saved and the errors are listed by index. Returns the new ids. Duplicate guard: an entry with the same supplier, invoice_no, product, category and net as a live entry is refused unless allow_duplicate is true.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'entries'         => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => PL_Entries::MAX_BATCH,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => self::entry_schema( false ),
								'required'             => array( 'date', 'category', 'net' ),
								'additionalProperties' => false,
							),
						),
						'allow_duplicate' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'entries' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'add' ),
			),
			'update-entry' => array(
				'label'       => 'Production Ledger: update an entry',
				'description' => 'Changes the given fields of one live entry (the rest stay). A new net or vat_rate without vat recomputes the VAT.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => self::entry_schema( true ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'update' ),
			),
			'delete-entry' => array(
				'label'       => 'Production Ledger: delete or restore an entry',
				'description' => 'Soft-deletes one entry (it leaves the report and the orOS feed marks it deleted) or, with restore = true, brings a deleted one back.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'restore' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'delete' ),
			),
		);
		$defs = array_merge( $defs, self::analysis_defs( $date ) );
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'production-ledger/' . $name,
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

	/** 1.3.0: the title analysis, the price list, the promo package and event sales. */
	private static function analysis_defs( array $date ): array {
		$obj = static function ( array $props, array $req = array() ): array {
			$o = array(
				'type'                 => 'object',
				'properties'           => $props,
				'additionalProperties' => false,
			);
			if ( $req ) {
				$o['required'] = $req;
			}
			return $o;
		};
		return array(
			'analysis'    => array(
				'label'       => 'Production Ledger: title analysis',
				'description' => 'Where the copies went and what promotion cost, for one title (product_id) or every title in a period (default: from Revenue Splitter\'s «sales since» to today). Channels (copies and net revenue ex VAT): e-shop full price, discount (coupon, discount rule or sale price), bundle, free order, old imported sales, events and festivals, bookstore purchase (Trade Accounts), consignment (sold; plus per bookstore given, sold, returned, on the shelf, owed), author purchase and free copies from the copies register with their recipients. Delivery (copies): Allover, BOX NOW, warehouse pick-up, GrafoExMachina pick-up, other pick-up, by hand from the publisher, no record. Only paid orders; cancelled, failed and refunded orders and refunded copies are left out; a bundle\'s books count once each under their own title. Promotion: the title\'s promotion entries by material; for all titles also the cost per material and year. Changes nothing.',
				'input'       => $obj(
					array(
						'product_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'from'       => $date,
						'to'         => $date,
					)
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'analysis' ),
			),
			'rates'       => array(
				'label'       => 'Production Ledger: price list',
				'description' => 'The materials of the price list (carrier zones, bookmarks, note cards, envelopes, business cards…) with every price they have had (valid from, unit net, VAT rate, supplier, invoice, change % against the previous price), the current price, and the promo package priced today. Changes nothing.',
				'input'       => $obj( array() ),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'rates' ),
			),
			'rate-add'    => array(
				'label'       => 'Production Ledger: add a price',
				'description' => 'Adds a material (key + label) or a new price of one (a new version valid from a date; older prices stay for the history; a second price on the same date replaces that day\'s). net = unit price ex VAT, up to 4 decimals.',
				'input'       => $obj(
					array(
						'key'      => array(
							'type'      => 'string',
							'maxLength' => 40,
						),
						'label'    => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'from'     => $date,
						'net'      => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'vat_rate' => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 100,
						),
						'supplier' => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'invoice'  => array(
							'type'      => 'string',
							'maxLength' => 60,
						),
						'note'     => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
					),
					array( 'key', 'from', 'net' )
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'rate_add' ),
			),
			'package-set' => array(
				'label'       => 'Production Ledger: set the promo package',
				'description' => 'Sets the promo package that goes with every title: material key => quantity (0 or left out removes it). Returns the package priced today.',
				'input'       => $obj(
					array(
						'pkg' => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => 1000,
							),
						),
					),
					array( 'pkg' )
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'package_set' ),
			),
			'promo-apply' => array(
				'label'       => 'Production Ledger: write the promo package for a title',
				'description' => 'Writes one «promotion» entry per material of the promo package for a title, with the prices valid on the date. A material already written for the same title and date is skipped (never twice). dry_run = true only shows the lines.',
				'input'       => $obj(
					array(
						'product_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'date'       => $date,
						'dry_run'    => array( 'type' => 'boolean' ),
					),
					array( 'product_id' )
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'promo_apply' ),
			),
			'event-sale'  => array(
				'label'       => 'Production Ledger: record a sale at an event',
				'description' => 'Records copies sold at a book fair or festival as a completed WooCommerce order (channel «event», the event\'s name, delivery by hand, payment cash or card, no e-mails), so stock and Revenue Splitter count it like any sale. price = unit price with VAT (default the product\'s price). dry_run = true only shows it.',
				'input'       => $obj(
					array(
						'date'    => $date,
						'event'   => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'payment' => array(
							'type' => 'string',
							'enum' => array( 'cash', 'card' ),
						),
						'note'    => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'lines'   => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => PL_Sales::MAX_ROWS,
							'items'    => $obj(
								array(
									'product_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'qty'        => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => 999,
									),
									'price'      => array(
										'type'    => 'number',
										'minimum' => 0,
									),
								),
								array( 'product_id', 'qty' )
							),
						),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					array( 'event', 'lines' )
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'event_sale' ),
			),
		);
	}

	public static function analysis( $in = null ) {
		$in   = is_array( $in ) ? $in : array();
		$from = isset( $in['from'] ) && PL_Settings::valid_date( (string) $in['from'] ) ? (string) $in['from'] : PL_Analysis::default_from();
		$to   = isset( $in['to'] ) && PL_Settings::valid_date( (string) $in['to'] ) ? (string) $in['to'] : wp_date( 'Y-m-d' );
		if ( $from > $to ) {
			return new WP_Error( 'pl_range', 'from is after to.' );
		}
		$pid = (int) ( $in['product_id'] ?? 0 );
		return $pid > 0 ? PL_Analysis::title( $pid, $from, $to ) : PL_Analysis::all( $from, $to );
	}

	public static function rates( $in = null ): array {
		return PL_Rates::out();
	}

	public static function rate_add( $in = null ) {
		return PL_Rates::add_price( is_array( $in ) ? $in : array() );
	}

	public static function package_set( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		return PL_Rates::set_package( isset( $in['pkg'] ) && is_array( $in['pkg'] ) ? $in['pkg'] : array() );
	}

	public static function promo_apply( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		return PL_Rates::apply( (int) ( $in['product_id'] ?? 0 ), isset( $in['date'] ) ? (string) $in['date'] : wp_date( 'Y-m-d' ), ! empty( $in['dry_run'] ), 'ability' );
	}

	public static function event_sale( $in = null ) {
		return PL_Sales::event_sale( is_array( $in ) ? $in : array(), 'ability' );
	}

	public static function allowed(): bool {
		return current_user_can( Production_Ledger::CAP );
	}

	private static function settings_out(): array {
		$s = PL_Settings::get();
		return array(
			'start'              => $s['start'],
			'publisher_names'    => $s['house'],
			'carriers'           => $s['carriers'],
			'payment_groups'     => $s['pay'],
			'spread_consumables' => $s['spread_consumables'],
			'modules'            => $s['mods'],
		);
	}

	public static function report( $in = null ) {
		$in  = is_array( $in ) ? $in : array();
		$rep = PL_Report::get( (string) ( $in['from'] ?? '' ), (string) ( $in['to'] ?? '' ), (int) ( $in['product_id'] ?? 0 ) );
		if ( is_wp_error( $rep ) ) {
			return $rep;
		}
		$rep['settings'] = self::settings_out();
		if ( empty( $rep['settings']['publisher_names'] ) ) {
			$rep['warnings'][] = 'No publisher names are set: every Revenue Splitter share counts as a creator payout. Set them in Noxpress → Κόστη & Απόδοση → Ρυθμίσεις.';
		}
		if ( ! $rep['rs']['available'] ) {
			$rep['warnings'][] = 'Revenue Splitter is not active: creator payouts are 0.';
		}
		return $rep;
	}

	public static function entries( $in = null ): array {
		$in = is_array( $in ) ? $in : array();
		$f  = array(
			'from'     => $in['from'] ?? '',
			'to'       => $in['to'] ?? '',
			'scope'    => $in['scope'] ?? '',
			'category' => $in['category'] ?? '',
			'search'   => $in['search'] ?? '',
			'deleted'  => ! empty( $in['deleted'] ),
			'limit'    => $in['limit'] ?? 100,
			'offset'   => $in['offset'] ?? 0,
		);
		if ( isset( $in['product_id'] ) ) {
			$f['product_id'] = (int) $in['product_id'];
		}
		if ( isset( $in['test'] ) ) {
			$f['test'] = (bool) $in['test'];
		}
		$f['hist'] = ! empty( $in['historical'] );
		$q         = PL_Entries::query( $f );
		$rows = array();
		foreach ( $q['rows'] as $r ) {
			$rows[] = PL_Entries::out( $r );
		}
		return array(
			'total'   => $q['total'],
			'entries' => $rows,
		);
	}

	public static function export( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		if ( ! class_exists( 'PL_Export' ) ) {
			require_once PL_PATH . 'includes/class-export.php';
		}
		$csv = PL_Export::csv( (string) ( $in['what'] ?? 'entries' ), (string) ( $in['month'] ?? '' ), (string) ( $in['format'] ?? 'standard' ) );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		return array(
			'filename' => PL_Export::filename( (string) ( $in['what'] ?? 'entries' ), (string) $in['month'] ),
			'csv'      => $csv,
		);
	}

	/**
	 * True when a live entry with the same supplier, invoice number,
	 * product, category and net already exists.
	 */
	public static function is_duplicate( array $row ): bool {
		if ( '' === $row['invoice_no'] && '' === $row['supplier'] ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . PL_Entries::table() . ' WHERE deleted_at IS NULL AND supplier = %s AND invoice_no = %s AND product_id = %d AND category = %s AND net_amount = %s LIMIT 1',
				$row['supplier'],
				$row['invoice_no'],
				$row['product_id'],
				$row['category'],
				number_format( $row['net_amount'], 2, '.', '' )
			)
		);
		return null !== $id;
	}

	public static function add( $in = null ) {
		$in    = is_array( $in ) ? $in : array();
		$list  = isset( $in['entries'] ) && is_array( $in['entries'] ) ? array_values( $in['entries'] ) : array();
		$allow = ! empty( $in['allow_duplicate'] );
		if ( ! $list || count( $list ) > PL_Entries::MAX_BATCH ) {
			return new WP_Error( 'pl_batch', 'entries must hold 1–' . PL_Entries::MAX_BATCH . ' items.' );
		}
		// Validate all first: one bad entry saves none.
		$errors = array();
		$seen   = array();
		foreach ( $list as $i => $e ) {
			$row = PL_Entries::validate( is_array( $e ) ? $e : array() );
			if ( is_wp_error( $row ) ) {
				$errors[] = '#' . $i . ': ' . $row->get_error_message();
				continue;
			}
			$sig = md5( $row['supplier'] . '|' . $row['invoice_no'] . '|' . $row['product_id'] . '|' . $row['category'] . '|' . $row['net_amount'] . '|' . $row['entry_date'] );
			if ( ! $allow && ( isset( $seen[ $sig ] ) || self::is_duplicate( $row ) ) ) {
				$errors[] = '#' . $i . ': looks like a duplicate of an existing entry (same supplier, invoice_no, product, category and net). Pass allow_duplicate = true if it is really a second line.';
			}
			$seen[ $sig ] = true;
		}
		if ( $errors ) {
			return new WP_Error( 'pl_invalid', 'Nothing was saved. ' . implode( ' ', $errors ) );
		}
		$ids = array();
		foreach ( $list as $e ) {
			$id = PL_Entries::add( $e, 'ability' );
			if ( is_wp_error( $id ) ) {
				return new WP_Error( 'pl_partial', 'Saved ' . count( $ids ) . ' of ' . count( $list ) . ' (ids ' . implode( ', ', $ids ) . '), then: ' . $id->get_error_message() );
			}
			$ids[] = $id;
		}
		return array(
			'ids'   => $ids,
			'count' => count( $ids ),
		);
	}

	public static function update( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		$id = (int) ( $in['id'] ?? 0 );
		unset( $in['id'] );
		$r = PL_Entries::update( $id, $in );
		return is_wp_error( $r ) ? $r : PL_Entries::out( $r );
	}

	public static function delete( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		$id = (int) ( $in['id'] ?? 0 );
		if ( ! empty( $in['restore'] ) ) {
			return PL_Entries::restore( $id ) ? array( 'restored' => $id ) : new WP_Error( 'pl_restore', 'No deleted entry with this id (or its source made a newer row).' );
		}
		return PL_Entries::delete( $id ) ? array( 'deleted' => $id, 'undo' => 'Run delete-entry with restore = true.' ) : new WP_Error( 'pl_missing', 'No live entry with this id.' );
	}
}
