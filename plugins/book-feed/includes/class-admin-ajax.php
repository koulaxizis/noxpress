<?php
/**
 * BKF_Admin_Ajax — the search tab, imports, the product box.
 *
 *  - render_search()   GET form (own nonce, since it calls the service),
 *                      results with "in the store" / "same ISBN" marks,
 *                      import of the ticked titles
 *  - bkf_pubs          publisher autocomplete (from the 3rd character)
 *  - bkf_import        imports up to 5 titles per request; each title is
 *                      fetched again from the feed by its id, never taken
 *                      from the browser; waits while a sync step runs
 *  - bkf_link          marks an existing product (same ISBN) as a feed title
 *  - bkf_product       the product box: sync | take (rewrite every field
 *                      from the feed) | unlink
 *  - bkf_bulk          progress of an "import all results" (BKF_Bulk)
 *  - do_import_post / do_link: the same without JavaScript (PRG)
 *  - do_import_all     "import all results": the search's filters come
 *                      back in the form, are cleaned again and asked of
 *                      the feed again (never a list of ids) (1.7.0)
 *  - do_save_rule      "save the search as a rule" (1.7.0)
 *
 * Every request: nonce + capability; ids validated; writes run under the
 * job's lock so a sync step and an import never touch a product together.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Admin_Ajax {

	const NONCE    = 'bkf_ajax';
	const MAX_AJAX = 5;
	const MAX_POST = 10;
	const MIN_PUB  = 3;

	public static function init(): void {
		add_action( 'wp_ajax_bkf_pubs', array( __CLASS__, 'ajax_pubs' ) );
		add_action( 'wp_ajax_bkf_import', array( __CLASS__, 'ajax_import' ) );
		add_action( 'wp_ajax_bkf_link', array( __CLASS__, 'ajax_link' ) );
		add_action( 'wp_ajax_bkf_product', array( __CLASS__, 'ajax_product' ) );
		add_action( 'wp_ajax_bkf_job', array( __CLASS__, 'ajax_job' ) );
		add_action( 'wp_ajax_bkf_bulk', array( __CLASS__, 'ajax_bulk' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_box' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_box' ) );
	}

	/* =====================================================================
	 * Shared
	 * =================================================================== */

	/** A feed id from the request: short, no control characters. */
	private static function clean_id( $v ): string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		return ( '' !== $v && strlen( $v ) <= 64 && ! preg_match( '/[\x00-\x1f]/', $v ) ) ? $v : '';
	}

	/** Records for feed ids, keyed by id; WP_Error on a failed request. */
	public static function fetch( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean_id' ), $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( array( 'ids' => $ids ) ),
				'fl'   => BKF_Client::BOOK_FIELDS,
				'rows' => count( $ids ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( BKF_Record::from_docs( $res['docs'] ) as $r ) {
			if ( in_array( $r['id'], $ids, true ) ) {
				$out[ $r['id'] ] = $r;
			}
		}
		return $out;
	}

	/** Import options from the request (status, category). */
	private static function import_opts( array $in ): array {
		$status = isset( $in['status'] ) && is_scalar( $in['status'] ) && in_array( (string) $in['status'], array( 'draft', 'publish' ), true ) ? (string) $in['status'] : BKF_Settings::get()['status'];
		$cat    = isset( $in['cat'] ) && is_scalar( $in['cat'] ) ? absint( $in['cat'] ) : 0;
		if ( $cat && ! term_exists( $cat, 'product_cat' ) ) {
			$cat = 0;
		}
		return array(
			'status' => $status,
			'cat'    => $cat,
			'mode'   => 'sell',
		);
	}

	/**
	 * Imports the ids.
	 *
	 * @return array|string|WP_Error id => { result, pid, edit, msg }; 'busy'
	 *                               while a sync step holds the lock.
	 */
	private static function run_import( array $ids, array $opt ) {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 300 );
		}
		$recs = self::fetch( $ids );
		if ( is_wp_error( $recs ) ) {
			return $recs;
		}
		$out = BKF_Sync::with_lock(
			static function () use ( $ids, $recs, $opt ) {
				$out = array();
				foreach ( $ids as $id ) {
					$id = self::clean_id( $id );
					if ( '' === $id ) {
						continue;
					}
					if ( ! isset( $recs[ $id ] ) ) {
						$out[ $id ] = array(
							'result' => 'missing',
							'pid'    => 0,
							'msg'    => '',
						);
						continue;
					}
					$out[ $id ] = BKF_Importer::import( $recs[ $id ], $opt );
				}
				BKF_Log::flush();
				return $out;
			}
		);
		if ( false === $out ) {
			return 'busy';
		}
		self::changed();
		foreach ( $out as $id => $row ) {
			$out[ $id ]['edit'] = $row['pid'] ? (string) get_edit_post_link( $row['pid'], 'raw' ) : '';
		}
		return $out;
	}

	private static function changed(): void {
		$ids = BKF_Importer::take_changed();
		if ( $ids ) {
			do_action( 'noxpress_products_changed', $ids );
		}
	}

	/** Links a product that has the record's ISBN. */
	private static function run_link( string $id, int $pid ) {
		$p = $pid ? wc_get_product( $pid ) : null;
		if ( ! $p || ! current_user_can( 'edit_post', $pid ) ) {
			return new WP_Error( 'bkf_pid', __( 'Το προϊόν δεν βρέθηκε.', 'book-feed' ) );
		}
		$recs = self::fetch( array( $id ) );
		if ( is_wp_error( $recs ) ) {
			return $recs;
		}
		if ( ! isset( $recs[ $id ] ) ) {
			return new WP_Error( 'bkf_missing', __( 'Ο τίτλος δεν βρέθηκε στην υπηρεσία.', 'book-feed' ) );
		}
		$r = $recs[ $id ];
		if ( '' === $r['isbn_digits'] || BKF_Importer::find_by_isbn( $r['isbn_digits'] ) !== $pid ) {
			return new WP_Error( 'bkf_isbn', __( 'Το προϊόν δεν έχει το ISBN αυτού του τίτλου.', 'book-feed' ) );
		}
		$ok = BKF_Sync::with_lock(
			static function () use ( $pid, $r ) {
				$ok = BKF_Importer::link( $pid, $r );
				BKF_Log::flush();
				return $ok ? 'ok' : 'taken';
			}
		);
		if ( false === $ok ) {
			return 'busy';
		}
		if ( 'taken' === $ok ) {
			return new WP_Error( 'bkf_taken', __( 'Αυτός ο τίτλος είναι ήδη συνδεδεμένος με άλλο προϊόν.', 'book-feed' ) );
		}
		return true;
	}

	private static function check_ajax(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( BKF_Admin_UI::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχεις δικαίωμα για αυτή την ενέργεια.', 'book-feed' ) ), 403 );
		}
	}

	/** POST array, unslashed. */
	private static function post( string $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified by the caller; validated per field.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
	}

	/* =====================================================================
	 * AJAX
	 * =================================================================== */

	public static function ajax_pubs(): void {
		self::check_ajax();
		$term = self::post( 'term' );
		$term = is_string( $term ) ? BKF_Settings::text( $term, 100 ) : '';
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term ) ) < self::MIN_PUB ) {
			wp_send_json_success( array() );
		}
		$res = BKF_Client::publishers( $term );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	/** The running job, for the status box. */
	public static function ajax_job(): void {
		self::check_ajax();
		$job = BKF_Sync::job();
		wp_send_json_success(
			array(
				'running' => null !== $job,
				'text'    => $job ? BKF_Admin_UI::stats_text( $job['stats'] ) : '',
			)
		);
	}

	/** The running bulk import, for its box. */
	public static function ajax_bulk(): void {
		self::check_ajax();
		$b = BKF_Bulk::get();
		wp_send_json_success(
			array(
				'running' => null !== $b,
				'text'    => $b ? BKF_Bulk::text( $b ) : '',
			)
		);
	}

	public static function ajax_import(): void {
		self::check_ajax();
		$ids = self::post( 'ids' );
		$ids = is_array( $ids ) ? array_slice( array_values( array_filter( array_map( array( __CLASS__, 'clean_id' ), $ids ) ) ), 0, self::MAX_AJAX ) : array();
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Τσέκαρε τουλάχιστον έναν τίτλο.', 'book-feed' ) ) );
		}
		$res = self::run_import(
			$ids,
			self::import_opts(
				array(
					'status' => self::post( 'status' ),
					'cat'    => self::post( 'cat' ),
				)
			)
		);
		if ( 'busy' === $res ) {
			wp_send_json_success( array( 'busy' => true ) );
		}
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'items' => $res ) );
	}

	public static function ajax_link(): void {
		self::check_ajax();
		$res = self::run_link( self::clean_id( self::post( 'id' ) ), absint( self::post( 'pid' ) ) );
		if ( 'busy' === $res ) {
			wp_send_json_success( array( 'busy' => true ) );
		}
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'linked' => true ) );
	}

	public static function ajax_product(): void {
		self::check_ajax();
		$pid = absint( self::post( 'pid' ) );
		$op  = self::post( 'op' );
		$op  = is_string( $op ) && in_array( $op, array( 'sync', 'take', 'unlink' ), true ) ? $op : '';
		if ( ! $pid || '' === $op || ! current_user_can( 'edit_post', $pid ) || '' === (string) get_post_meta( $pid, '_bkf_source', true ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχεις δικαίωμα για αυτή την ενέργεια.', 'book-feed' ) ) );
		}

		if ( 'unlink' === $op ) {
			$ok = BKF_Sync::with_lock(
				static function () use ( $pid ) {
					BKF_Importer::unlink( $pid );
					BKF_Log::flush();
					return true;
				}
			);
			if ( false === $ok ) {
				wp_send_json_success( array( 'busy' => true ) );
			}
			wp_send_json_success( array( 'message' => __( 'Το προϊόν αποσυνδέθηκε από τον κατάλογο.', 'book-feed' ) ) );
		}

		// The page typed in the box counts even before the product form is saved (1.4.0).
		$page = self::post( 'pub_url' );
		if ( is_string( $page ) && 'unlink' !== $op ) {
			self::store_pub_url( $pid, $page );
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		$id   = (string) get_post_meta( $pid, '_bkf_source_id', true );
		$recs = self::fetch( array( $id ) );
		if ( is_wp_error( $recs ) ) {
			wp_send_json_error( array( 'message' => $recs->get_error_message() ) );
		}
		if ( ! isset( $recs[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Ο τίτλος δεν βρέθηκε στην υπηρεσία.', 'book-feed' ) ) );
		}
		$r       = $recs[ $id ];
		$changed = BKF_Sync::with_lock(
			static function () use ( $pid, $r, $op ) {
				$c = BKF_Importer::update( $pid, $r, 'take' === $op );
				update_post_meta( $pid, '_bkf_synced', time() );
				BKF_Log::flush();
				return $c;
			}
		);
		if ( false === $changed ) {
			wp_send_json_success( array( 'busy' => true ) );
		}
		self::changed();
		$msg = $changed
			/* translators: %s: list of fields */
			? sprintf( __( 'Ενημερώθηκαν: %s.', 'book-feed' ), BKF_Log::fields_text( implode( ',', $changed ) ) )
			: __( 'Το προϊόν είναι ήδη ενημερωμένο.', 'book-feed' );
		wp_send_json_success(
			array(
				'message' => $msg,
				'reload'  => true,
			)
		);
	}

	/* =====================================================================
	 * Without JavaScript (POST from the search form, PRG)
	 * =================================================================== */

	/** Back to the page the form came from (keeps the search), with messages. */
	public static function back( array $msgs ): void {
		set_transient( 'bkf_aui_msg_' . get_current_user_id(), $msgs, 60 );
		$ref  = wp_get_referer();
		$home = BKF_Admin_UI::url( 'search' );
		$ok   = $ref && 0 === strpos( $ref, admin_url( 'admin.php' ) ) && false !== strpos( $ref, 'page=' . BKF_Admin_UI::SLUG );
		wp_safe_redirect( $ok ? $ref : $home );
		exit;
	}

	public static function do_import_post(): array {
		$ids = self::post( 'bkf_ids' );
		$ids = is_array( $ids ) ? array_values( array_filter( array_map( array( __CLASS__, 'clean_id' ), $ids ) ) ) : array();
		if ( ! $ids ) {
			return array( BKF_Admin_UI::msg( 'error', __( 'Τσέκαρε τουλάχιστον έναν τίτλο.', 'book-feed' ) ) );
		}
		$more = count( $ids ) > self::MAX_POST;
		$ids  = array_slice( $ids, 0, self::MAX_POST );
		$res  = self::run_import(
			$ids,
			self::import_opts(
				array(
					'status' => self::post( 'bkf_status' ),
					'cat'    => self::post( 'bkf_cat' ),
				)
			)
		);
		if ( 'busy' === $res ) {
			return array( BKF_Admin_UI::msg( 'warning', __( 'Τρέχει συγχρονισμός· ξαναδοκίμασε σε λίγο.', 'book-feed' ) ) );
		}
		if ( is_wp_error( $res ) ) {
			return array( BKF_Admin_UI::msg( 'error', $res->get_error_message() ) );
		}
		$n = array_fill_keys( array( 'imported', 'updated', 'exists', 'missing', 'error' ), 0 );
		foreach ( $res as $row ) {
			if ( isset( $n[ $row['result'] ] ) ) {
				++$n[ $row['result'] ];
			}
		}
		/* translators: 1: imported, 2: updated, 3: already in the store, 4: not found, 5: errors */
		$msgs = array( BKF_Admin_UI::msg( $n['error'] ? 'warning' : 'success', sprintf( __( 'Εισήχθησαν %1$d, ενημερώθηκαν %2$d, υπάρχουν ήδη %3$d, δεν βρέθηκαν %4$d, σφάλματα %5$d.', 'book-feed' ), $n['imported'], $n['updated'], $n['exists'], $n['missing'], $n['error'] ) ) );
		if ( $more ) {
			/* translators: %d: maximum titles */
			$msgs[] = BKF_Admin_UI::msg( 'warning', sprintf( __( 'Χωρίς JavaScript εισάγονται έως %d τίτλοι τη φορά.', 'book-feed' ), self::MAX_POST ) );
		}
		return $msgs;
	}

	/** The search's filters as hidden fields of the results form. */
	private static function filter_fields( array $f ): void {
		$map = array(
			'bkf_t'    => $f['title'],
			'bkf_a'    => $f['author'],
			'bkf_i'    => $f['isbn'],
			'bkf_pub'  => $f['pub_id'],
			'bkf_pubn' => $f['pub_name'],
			'bkf_y1'   => $f['year_from'] ? (string) $f['year_from'] : '',
			'bkf_y2'   => $f['year_to'] ? (string) $f['year_to'] : '',
			'bkf_kind' => $f['kind'],
			'bkf_ps'   => $f['pstat'],
		);
		foreach ( $map as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
	}

	/** True when the filters narrow the feed at all. */
	private static function narrowed( array $f ): bool {
		return '' !== $f['title'] . $f['author'] . $f['isbn'] . $f['pub_id'] . $f['kind'] || $f['year_from'] || $f['year_to'];
	}

	/** "Import all results": starts a background bulk import. */
	public static function do_import_all(): array {
		$f = self::filters( true );
		unset( $f['pub_name'] );
		if ( ! self::narrowed( $f ) ) {
			return array( BKF_Admin_UI::msg( 'error', __( 'Κάνε πρώτα μια αναζήτηση.', 'book-feed' ) ) );
		}
		if ( BKF_Bulk::get() ) {
			return array( BKF_Admin_UI::msg( 'warning', __( 'Τρέχει ήδη μαζική εισαγωγή.', 'book-feed' ) ) );
		}
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( $f ),
				'fl'   => 'product_id',
				'rows' => 1,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( BKF_Admin_UI::msg( 'error', $res->get_error_message() ) );
		}
		if ( ! $res['num'] ) {
			return array( BKF_Admin_UI::msg( 'warning', __( 'Δεν βρέθηκαν τίτλοι.', 'book-feed' ) ) );
		}
		$opt = self::import_opts(
			array(
				'status' => self::post( 'bkf_status' ),
				'cat'    => self::post( 'bkf_cat' ),
			)
		);
		BKF_Bulk::start( $f, $opt['status'], $opt['cat'], $res['num'] );
		$b    = BKF_Bulk::get();
		$msgs = array(
			BKF_Admin_UI::msg(
				'success',
				/* translators: %d: titles */
				sprintf( __( 'Η μαζική εισαγωγή ξεκίνησε στο παρασκήνιο: έως %d τίτλοι. Μπορείς να κλείσεις τη σελίδα· η πρόοδος φαίνεται εδώ.', 'book-feed' ), $b ? (int) $b['limit'] : 0 )
			),
		);
		if ( $b && $b['limit'] < $res['num'] ) {
			/* translators: 1: the limit, 2: results */
			$msgs[] = BKF_Admin_UI::msg( 'warning', sprintf( __( 'Η αναζήτηση έχει %2$s τίτλους· εισάγονται οι πρώτοι %1$d (όριο στις Ρυθμίσεις).', 'book-feed' ), (int) $b['limit'], number_format_i18n( $res['num'] ) ) );
		}
		return $msgs;
	}

	/** "Save the search as a rule": a new rule with every filter of the search. */
	public static function do_save_rule(): array {
		$f = self::filters( true );
		if ( '' === $f['pub_id'] . $f['title'] . $f['author'] ) {
			return array( BKF_Admin_UI::msg( 'error', __( 'Ένας κανόνας θέλει εκδότη, λέξεις του τίτλου ή συντελεστή.', 'book-feed' ) ) );
		}
		$rules = BKF_Settings::rules();
		if ( count( $rules ) >= BKF_Settings::MAX_RULES ) {
			/* translators: %d: maximum rules */
			return array( BKF_Admin_UI::msg( 'error', sprintf( __( 'Έως %d κανόνες.', 'book-feed' ), BKF_Settings::MAX_RULES ) ) );
		}
		$opt  = self::import_opts(
			array(
				'status' => self::post( 'bkf_status' ),
				'cat'    => self::post( 'bkf_cat' ),
			)
		);
		$rule = BKF_Settings::clean_rule(
			array(
				'on'        => true,
				'pub_id'    => $f['pub_id'],
				'pub_name'  => $f['pub_name'],
				'title'     => $f['title'],
				'author'    => $f['author'],
				'kind'      => $f['kind'],
				'pstat'     => $f['pstat'],
				'year_from' => $f['year_from'],
				'year_to'   => $f['year_to'],
				'cat'       => $opt['cat'],
				'status'    => $opt['status'],
				'every'     => 7,
				'max'       => 50,
			)
		);
		if ( ! $rule ) {
			return array( BKF_Admin_UI::msg( 'error', __( 'Ένας κανόνας θέλει εκδότη, λέξεις του τίτλου ή συντελεστή.', 'book-feed' ) ) );
		}
		$rules[] = $rule;
		BKF_Settings::save_rules( $rules );
		$msgs = array( BKF_Admin_UI::msg( 'success', __( 'Ο κανόνας αποθηκεύτηκε: τρέχει κάθε 7 ημέρες και φέρνει μόνο νέους τίτλους. Άλλαξέ τον εδώ.', 'book-feed' ) ) );
		if ( '' !== $f['isbn'] ) {
			$msgs[] = BKF_Admin_UI::msg( 'warning', __( 'Το ISBN δεν κρατήθηκε: ένας κανόνας ψάχνει νέους τίτλους.', 'book-feed' ) );
		}
		return $msgs;
	}

	public static function do_link(): array {
		$v     = self::post( 'bkf_link' );
		$parts = is_string( $v ) ? explode( '|', $v, 2 ) : array();
		$res   = 2 === count( $parts ) ? self::run_link( self::clean_id( $parts[0] ), absint( $parts[1] ) ) : new WP_Error( 'bkf_bad', __( 'Άγνωστη ενέργεια.', 'book-feed' ) );
		if ( 'busy' === $res ) {
			return array( BKF_Admin_UI::msg( 'warning', __( 'Τρέχει συγχρονισμός· ξαναδοκίμασε σε λίγο.', 'book-feed' ) ) );
		}
		if ( is_wp_error( $res ) ) {
			return array( BKF_Admin_UI::msg( 'error', $res->get_error_message() ) );
		}
		return array( BKF_Admin_UI::msg( 'success', __( 'Το προϊόν συνδέθηκε με τον κατάλογο. Τα πεδία του δεν αλλάζουν μέχρι να πατήσεις «Ενημέρωση από τον κατάλογο» στο προϊόν.', 'book-feed' ) ) );
	}

	/* =====================================================================
	 * Search tab
	 * =================================================================== */

	/**
	 * The search's filters, from the query string or (from the results
	 * form, $post) from the hidden fields that carry them.
	 */
	private static function filters( bool $post = false ): array {
		$q = $post
			? static function ( string $k ): string {
				$v = self::post( $k );
				return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
			}
			: array( 'BKF_Admin_UI', 'q' );
		return array(
			'title'     => BKF_Settings::text( $q( 'bkf_t' ), 200 ),
			'author'    => BKF_Settings::text( $q( 'bkf_a' ), 200 ),
			'isbn'      => BKF_Settings::text( $q( 'bkf_i' ), 30 ),
			'pub_id'    => preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $q( 'bkf_pub' ) ) ? $q( 'bkf_pub' ) : '',
			'pub_name'  => BKF_Settings::text( $q( 'bkf_pubn' ), 150 ),
			'year_from' => min( 2100, absint( $q( 'bkf_y1' ) ) ),
			'year_to'   => min( 2100, absint( $q( 'bkf_y2' ) ) ),
			'kind'      => BKF_Settings::text( $q( 'bkf_kind' ), 60 ),
			'pstat'     => BKF_Settings::text( $q( 'bkf_ps' ), 80 ),
		);
	}

	/** The URL of the search with these filters (and a fresh nonce). */
	private static function search_url( array $f, int $paged ): string {
		$args = array(
			'bkf_t'    => $f['title'],
			'bkf_a'    => $f['author'],
			'bkf_i'    => $f['isbn'],
			'bkf_pub'  => $f['pub_id'],
			'bkf_pubn' => $f['pub_name'],
			'bkf_y1'   => $f['year_from'] ? $f['year_from'] : '',
			'bkf_y2'   => $f['year_to'] ? $f['year_to'] : '',
			'bkf_kind' => $f['kind'],
			'bkf_ps'   => $f['pstat'],
			'paged'    => $paged > 1 ? $paged : '',
		);
		return wp_nonce_url( BKF_Admin_UI::url( 'search', array_filter( $args, 'strlen' ) ), BKF_Admin_UI::NONCE_FIND, 'bkf_f' );
	}

	/** The running bulk import (with stop), else the last one for a day. */
	public static function bulk_box(): void {
		$b    = BKF_Bulk::get();
		$last = $b ? null : BKF_Bulk::last();
		if ( ! $b && ( ! $last || time() - $last['t'] > DAY_IN_SECONDS ) ) {
			return;
		}
		echo '<div class="bkf-status-row bkf-bulk">';
		if ( $b ) {
			echo '<span class="bkf-badge bkf-badge--warn bkf-running">' . esc_html__( 'Μαζική εισαγωγή σε εξέλιξη', 'book-feed' ) . '</span> ';
			echo '<span class="bkf-muted bkf-bulk-text">' . esc_html( BKF_Bulk::text( $b ) ) . '</span>';
			echo '<form method="post" class="bkf-inline-form">';
			BKF_Admin_UI::nonce_field();
			echo '<input type="hidden" name="bkf_action" value="bulk_stop" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Διακοπή', 'book-feed' ) . '</button></form>';
		} else {
			/* translators: 1: date and time, 2: summary */
			echo '<span class="bkf-muted">' . esc_html( sprintf( __( 'Τελευταία μαζική εισαγωγή %1$s: %2$s', 'book-feed' ), BKF_Admin_UI::when( $last['t'] ), BKF_Bulk::text( $last ) ) ) . '</span>';
			if ( '' !== $last['error'] ) {
				echo ' <span class="bkf-badge bkf-badge--danger">' . esc_html( $last['error'] ) . '</span>';
			}
		}
		echo '</div>';
	}

	public static function render_search(): void {
		$f     = self::filters();
		$paged = max( 1, absint( BKF_Admin_UI::q( 'paged' ) ) );
		$seen  = BKF_Settings::seen();
		$asked = '' !== $f['title'] . $f['author'] . $f['isbn'] . $f['pub_id'] . $f['pub_name'] . $f['kind'] . $f['pstat'] || $f['year_from'] || $f['year_to'];
		$valid = $asked && wp_verify_nonce( BKF_Admin_UI::q( 'bkf_f' ), BKF_Admin_UI::NONCE_FIND );
		?>
		<form method="get" class="bkf-zone bkf-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( BKF_Admin_UI::SLUG ); ?>" />
			<input type="hidden" name="tab" value="search" />
			<?php wp_nonce_field( BKF_Admin_UI::NONCE_FIND, 'bkf_f', false ); ?>
			<div class="bkf-grid">
				<p class="bkf-field"><span><?php esc_html_e( 'Τίτλος', 'book-feed' ); ?></span>
					<input type="text" name="bkf_t" value="<?php echo esc_attr( $f['title'] ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Συγγραφέας ή άλλος συντελεστής', 'book-feed' ); ?></span>
					<input type="text" name="bkf_a" value="<?php echo esc_attr( $f['author'] ); ?>" />
				</p>
				<p class="bkf-field"><span>ISBN</span>
					<input type="text" name="bkf_i" value="<?php echo esc_attr( $f['isbn'] ); ?>" />
				</p>
				<p class="bkf-field bkf-pub-wrap"><span><?php esc_html_e( 'Εκδότης', 'book-feed' ); ?></span>
					<input type="text" class="bkf-pub-name" name="bkf_pubn" value="<?php echo esc_attr( $f['pub_name'] ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Γράψε 3 γράμματα…', 'book-feed' ); ?>" />
					<input type="hidden" class="bkf-pub-id" name="bkf_pub" value="<?php echo esc_attr( $f['pub_id'] ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Έτος από – έως', 'book-feed' ); ?></span>
					<span class="bkf-pair">
						<input type="number" min="0" max="2100" name="bkf_y1" value="<?php echo esc_attr( $f['year_from'] ? (string) $f['year_from'] : '' ); ?>" />
						<input type="number" min="0" max="2100" name="bkf_y2" value="<?php echo esc_attr( $f['year_to'] ? (string) $f['year_to'] : '' ); ?>" />
					</span>
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Είδος', 'book-feed' ); ?></span>
					<select name="bkf_kind">
						<option value=""><?php esc_html_e( 'Όλα', 'book-feed' ); ?></option>
						<?php
						$kinds = $seen['kind'];
						if ( '' !== $f['kind'] && ! in_array( $f['kind'], $kinds, true ) ) {
							$kinds[] = $f['kind'];
						}
						foreach ( $kinds as $k ) {
							echo '<option value="' . esc_attr( $k ) . '" ' . selected( $f['kind'], $k, false ) . '>' . esc_html( $k ) . '</option>';
						}
						?>
					</select>
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Κατάσταση κυκλοφορίας', 'book-feed' ); ?></span>
					<?php BKF_Admin_UI::pstat_select( 'bkf_ps', $f['pstat'] ); ?>
				</p>
			</div>
			<p><button type="submit" class="button button-primary" <?php disabled( ! BKF_Settings::ready() ); ?>><?php esc_html_e( 'Αναζήτηση', 'book-feed' ); ?></button></p>
		</form>
		<?php
		if ( ! BKF_Settings::ready() || ! $valid ) {
			if ( $asked && BKF_Settings::ready() ) {
				echo '<p class="bkf-muted">' . esc_html__( 'Πάτησε «Αναζήτηση» για να τρέξει η αναζήτηση.', 'book-feed' ) . '</p>';
			}
			return;
		}

		// A publisher typed but not picked from the list: resolve the name.
		if ( '' === $f['pub_id'] && '' !== $f['pub_name'] ) {
			$pubs = BKF_Client::publishers( $f['pub_name'] );
			if ( is_wp_error( $pubs ) ) {
				self::error_box( $pubs->get_error_message() );
				return;
			}
			if ( 1 === count( $pubs ) ) {
				$f['pub_id'] = $pubs[0]['id'];
			} else {
				self::publisher_choice( $f, $pubs );
				return;
			}
		}

		$res = BKF_Client::query(
			array(
				'q'     => BKF_Client::book_query( $f ),
				'fl'    => BKF_Client::BOOK_FIELDS,
				'rows'  => BKF_Admin_UI::PER_PAGE,
				'start' => ( $paged - 1 ) * BKF_Admin_UI::PER_PAGE,
				'sort'  => '' === $f['title'] . $f['author'] ? 'pub_year desc, product_id asc' : '',
			)
		);
		if ( is_wp_error( $res ) ) {
			self::error_box( $res->get_error_message() );
			return;
		}
		$recs  = BKF_Record::from_docs( $res['docs'] );
		$marks = BKF_Importer::lookup( $recs );
		$pages = max( 1, (int) ceil( $res['num'] / BKF_Admin_UI::PER_PAGE ) );
		self::results( $recs, $marks, $res['num'], $paged, $pages, $f );
	}

	private static function error_box( string $msg ): void {
		/* translators: %s: error */
		echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Η αναζήτηση απέτυχε: %s', 'book-feed' ), $msg ) ) . '</p></div>';
	}

	private static function publisher_choice( array $f, array $pubs ): void {
		echo '<div class="bkf-zone">';
		if ( ! $pubs ) {
			echo '<p>' . esc_html__( 'Δεν βρέθηκε εκδότης με αυτό το όνομα.', 'book-feed' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Βρέθηκαν πολλοί εκδότες· διάλεξε έναν:', 'book-feed' ) . '</p><ul class="bkf-list">';
		foreach ( $pubs as $p ) {
			$g = array_merge(
				$f,
				array(
					'pub_id'   => $p['id'],
					'pub_name' => $p['name'],
				)
			);
			echo '<li><a href="' . esc_url( self::search_url( $g, 1 ) ) . '">' . esc_html( $p['name'] ) . '</a> <span class="bkf-muted">' . esc_html( $p['id'] ) . '</span></li>';
		}
		echo '</ul></div>';
	}

	private static function results( array $recs, array $marks, int $num, int $paged, int $pages, array $f ): void {
		$s    = BKF_Settings::get();
		$cap  = min( $num, (int) $s['bulk_max'] );
		$busy = null !== BKF_Bulk::get();
		?>
		<p class="bkf-muted">
			<?php
			/* translators: 1: number of titles, 2: page, 3: pages */
			echo esc_html( sprintf( _n( '%1$s τίτλος · σελίδα %2$d από %3$d', '%1$s τίτλοι · σελίδα %2$d από %3$d', $num, 'book-feed' ), number_format_i18n( $num ), $paged, $pages ) );
			?>
		</p>
		<?php if ( ! $recs ) : ?>
			<p class="bkf-empty"><?php esc_html_e( 'Δεν βρέθηκαν τίτλοι.', 'book-feed' ); ?></p>
			<?php
			return;
		endif;
		?>
		<form method="post" id="bkf-results">
			<?php BKF_Admin_UI::nonce_field(); ?>
			<input type="hidden" name="bkf_action" value="import" />
			<?php self::filter_fields( $f ); ?>
			<div class="bkf-zone bkf-import-bar">
				<label class="bkf-inline"><?php esc_html_e( 'Κατάσταση', 'book-feed' ); ?>
					<select name="bkf_status" id="bkf-status-sel">
						<option value="draft" <?php selected( $s['status'], 'draft' ); ?>><?php esc_html_e( 'Πρόχειρο', 'book-feed' ); ?></option>
						<option value="publish" <?php selected( $s['status'], 'publish' ); ?>><?php esc_html_e( 'Δημοσιευμένο', 'book-feed' ); ?></option>
					</select>
				</label>
				<label class="bkf-inline"><?php esc_html_e( 'Κατηγορία', 'book-feed' ); ?>
					<?php echo BKF_Admin_UI::cat_select( 'bkf_cat', 0, __( '— Από την αντιστοίχιση των Ρυθμίσεων —', 'book-feed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?>
				</label>
				<button type="submit" class="button button-primary" id="bkf-import-btn"><?php esc_html_e( 'Εισαγωγή επιλεγμένων', 'book-feed' ); ?></button>
				<?php if ( $pages > 1 ) : ?>
					<button type="submit" class="button bkf-all-btn" name="bkf_all" value="1" data-bkf-count="<?php echo esc_attr( (string) $cap ); ?>" <?php disabled( $busy ); ?> title="<?php echo $busy ? esc_attr__( 'Τρέχει ήδη μαζική εισαγωγή.', 'book-feed' ) : esc_attr__( 'Όλα τα αποτελέσματα, σε όλες τις σελίδες, στο παρασκήνιο. Όσοι τίτλοι υπάρχουν ήδη παρακάμπτονται.', 'book-feed' ); ?>">
						<?php
						/* translators: %s: number of titles */
						echo esc_html( sprintf( __( 'Εισαγωγή όλων των αποτελεσμάτων (%s)', 'book-feed' ), number_format_i18n( $cap ) ) );
						?>
					</button>
				<?php endif; ?>
				<?php if ( '' !== $f['pub_id'] . $f['title'] . $f['author'] ) : ?>
					<button type="submit" class="button bkf-rule-btn" name="bkf_save_rule" value="1" title="<?php esc_attr_e( 'Νέος κανόνας με τα φίλτρα αυτής της αναζήτησης, την κατάσταση και την κατηγορία που διάλεξες: κάθε 7 ημέρες φέρνει μόνο τους νέους τίτλους.', 'book-feed' ); ?>"><?php esc_html_e( 'Αποθήκευση αναζήτησης ως κανόνα', 'book-feed' ); ?></button>
				<?php endif; ?>
				<span class="bkf-progress" id="bkf-progress" aria-live="polite"></span>
				<?php if ( $num > $cap && $pages > 1 ) : ?>
					<span class="bkf-hint">
						<?php
						/* translators: 1: the limit, 2: results */
						echo esc_html( sprintf( __( 'Η αναζήτηση έχει %2$s τίτλους· η εισαγωγή όλων φέρνει τους πρώτους %1$s (όριο στις Ρυθμίσεις).', 'book-feed' ), number_format_i18n( $cap ), number_format_i18n( $num ) ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="bkf-scroll">
				<table class="widefat bkf-table bkf-results">
					<thead><tr>
						<th class="bkf-cb"><input type="checkbox" id="bkf-all" aria-label="<?php esc_attr_e( 'Όλοι', 'book-feed' ); ?>" /></th>
						<th></th>
						<th><?php esc_html_e( 'Τίτλος', 'book-feed' ); ?></th>
						<th><?php esc_html_e( 'Εκδότης', 'book-feed' ); ?></th>
						<th><?php esc_html_e( 'Έτος', 'book-feed' ); ?></th>
						<th>ISBN</th>
						<th><?php esc_html_e( 'Τιμή', 'book-feed' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση', 'book-feed' ); ?></th>
						<th><?php esc_html_e( 'Στο κατάστημα', 'book-feed' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $recs as $r ) : ?>
						<?php $m = $marks[ $r['id'] ] ?? null; ?>
						<tr data-bkf-id="<?php echo esc_attr( $r['id'] ); ?>">
							<td class="bkf-cb">
								<?php if ( ! $m ) : ?>
									<input type="checkbox" class="bkf-pick" name="bkf_ids[]" value="<?php echo esc_attr( $r['id'] ); ?>" />
								<?php endif; ?>
							</td>
							<td class="bkf-thumb">
								<?php if ( '' !== $r['cover'] ) : ?>
									<img src="<?php echo esc_url( $r['cover'] ); ?>" alt="" loading="lazy" referrerpolicy="no-referrer" width="40" />
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo esc_html( $r['title'] ); ?></strong>
								<?php if ( '' !== $r['subtitle'] ) : ?>
									<br /><span class="bkf-muted"><?php echo esc_html( $r['subtitle'] ); ?></span>
								<?php endif; ?>
								<?php if ( $r['contribs'] ) : ?>
									<br /><span class="bkf-small"><?php echo esc_html( self::contribs_text( $r['contribs'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $r['kind'] ) : ?>
									<br /><span class="bkf-badge bkf-badge--muted"><?php echo esc_html( $r['kind'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $r['pub_name'] ); ?></td>
							<td class="bkf-num"><?php echo $r['year'] ? esc_html( (string) $r['year'] ) : ''; ?></td>
							<td class="bkf-num"><?php echo esc_html( $r['isbn'] ); ?></td>
							<td class="bkf-num"><?php echo null !== $r['price'] ? wp_kses_post( wc_price( $r['price'] ) ) : '—'; ?></td>
							<td><span class="bkf-badge<?php echo 'instock' === BKF_Settings::stock_for( $r['status'] ) ? ' bkf-badge--ok' : ' bkf-badge--warn'; ?>"><?php echo esc_html( '' !== $r['status'] ? $r['status'] : '—' ); ?></span></td>
							<td class="bkf-mark">
								<?php
								if ( $m && $m['ours'] ) {
									echo '<a class="bkf-badge bkf-badge--ok" href="' . esc_url( (string) get_edit_post_link( $m['pid'] ) ) . '">' . esc_html__( 'Στο κατάστημα', 'book-feed' ) . '</a>';
								} elseif ( $m ) {
									echo '<a class="bkf-badge bkf-badge--warn" href="' . esc_url( (string) get_edit_post_link( $m['pid'] ) ) . '">' . esc_html__( 'Ίδιο ISBN', 'book-feed' ) . '</a> ';
									echo '<button type="submit" class="button button-small bkf-link" name="bkf_link" value="' . esc_attr( $r['id'] . '|' . $m['pid'] ) . '" data-bkf-pid="' . esc_attr( (string) $m['pid'] ) . '" title="' . esc_attr__( 'Το υπάρχον προϊόν παίρνει μόνο το σημάδι του καταλόγου· τα πεδία του δεν αλλάζουν.', 'book-feed' ) . '">' . esc_html__( 'Σύνδεση', 'book-feed' ) . '</button>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php if ( $pages > 1 ) : ?>
			<p class="bkf-pager">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( self::search_url( $f, $paged - 1 ) ); ?>">&larr; <?php esc_html_e( 'Προηγούμενη', 'book-feed' ); ?></a>
				<?php endif; ?>
				<?php if ( $paged < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( self::search_url( $f, $paged + 1 ) ); ?>"><?php esc_html_e( 'Επόμενη', 'book-feed' ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function contribs_text( array $contribs ): string {
		$out = array();
		foreach ( array_slice( $contribs, 0, 6 ) as $c ) {
			$out[] = $c['name'] . ( '' !== $c['role'] ? ' (' . $c['role'] . ')' : '' );
		}
		return implode( ', ', $out ) . ( count( $contribs ) > 6 ? ' …' : '' );
	}

	/* =====================================================================
	 * Product box
	 * =================================================================== */

	public static function add_box( $post ): void {
		if ( ! $post || '' === (string) get_post_meta( $post->ID, '_bkf_source', true ) || ! current_user_can( BKF_Admin_UI::CAP ) ) {
			return;
		}
		add_meta_box( 'bkf-box', __( 'Book Feed', 'book-feed' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'default' );
	}

	public static function render_box( $post ): void {
		$pid    = (int) $post->ID;
		$src    = bkf_get_source( $pid );
		$p      = wc_get_product( $pid );
		$linked = $src && $src['linked'];
		$gone   = '1' === (string) get_post_meta( $pid, '_bkf_gone', true );
		$locked = $linked ? array() : BKF_Importer::locked_fields( $pid );
		$until  = (string) get_post_meta( $pid, '_bkf_fixed_until', true );
		$feed   = (string) get_post_meta( $pid, '_bkf_feed_price', true );
		$warn   = $p ? BKF_Guard::check( $p ) : '';
		?>
		<div class="bkf-box" data-bkf-pid="<?php echo esc_attr( (string) $pid ); ?>">
			<p>
				<?php if ( $linked ) : ?>
					<span class="bkf-badge bkf-badge--warn"><?php esc_html_e( 'Συνδεδεμένο', 'book-feed' ); ?></span>
				<?php else : ?>
					<span class="bkf-badge bkf-badge--ok"><?php esc_html_e( 'Από τον κατάλογο', 'book-feed' ); ?></span>
				<?php endif; ?>
				<?php if ( $gone ) : ?>
					<span class="bkf-badge bkf-badge--danger"><?php esc_html_e( 'Εκτός καταλόγου', 'book-feed' ); ?></span>
				<?php endif; ?>
			</p>
			<p class="bkf-small">
				<?php
				echo esc_html( 'ISBN: ' . ( '' !== $src['isbn'] ? $src['isbn'] : '—' ) );
				echo '<br />';
				/* translators: %s: feed id */
				echo esc_html( sprintf( __( 'Κωδικός καταλόγου: %s', 'book-feed' ), $src['id'] ) );
				echo '<br />';
				echo esc_html(
					$src['synced']
						/* translators: %s: date and time */
						? sprintf( __( 'Συγχρονίστηκε: %s', 'book-feed' ), BKF_Admin_UI::when( $src['synced'] ) )
						: __( 'Δεν έχει συγχρονιστεί ακόμα.', 'book-feed' )
				);
				if ( '' !== $feed ) {
					echo '<br />';
					/* translators: %s: price */
					echo wp_kses_post( sprintf( __( 'Τιμή καταλόγου: %s', 'book-feed' ), wc_price( (float) $feed ) ) );
				}
				$pub  = (string) get_post_meta( $pid, '_bkf_pub_price', true );
				$note = (string) get_post_meta( $pid, '_bkf_pub_note', true );
				if ( is_numeric( $pub ) ) {
					echo '<br />';
					/* translators: %s: price */
					echo wp_kses_post( sprintf( __( 'Τιμή στο site του εκδότη: %s', 'book-feed' ), wc_price( (float) $pub ) ) );
				}
				if ( '' !== $note ) {
					echo '<br /><span class="bkf-muted">' . esc_html( $note ) . '</span>';
				}
				if ( '' !== $until && $until >= wp_date( 'Y-m-d' ) ) {
					echo '<br />';
					/* translators: %s: date */
					echo esc_html( sprintf( __( 'Ενιαία τιμή έως: %s', 'book-feed' ), date_i18n( get_option( 'date_format' ), (int) strtotime( $until ) ) ) );
				}
				?>
			</p>
			<p class="bkf-small">
				<label for="bkf-pub-url"><?php esc_html_e( 'Σελίδα στο site του εκδότη', 'book-feed' ); ?></label>
				<?php wp_nonce_field( 'bkf_box_' . $pid, 'bkf_box_nonce' ); ?>
				<input type="url" id="bkf-pub-url" class="widefat" name="bkf_pub_url" maxlength="300" placeholder="https://…" value="<?php echo esc_attr( (string) get_post_meta( $pid, '_bkf_pub_url', true ) ); ?>" />
				<span class="bkf-muted"><?php esc_html_e( 'Για εκδότες που δεν έχουν σελίδα ανά ISBN. Η τιμή και το εξώφυλλο διαβάζονται από εδώ σε κάθε συγχρονισμό· ο «Συγχρονισμός τώρα» κρατάει και τη διεύθυνση.', 'book-feed' ); ?></span>
			</p>
			<?php if ( $linked ) : ?>
				<p class="bkf-small"><?php esc_html_e( 'Υπήρχε ήδη στο κατάστημα: ο κατάλογος δεν αλλάζει τα πεδία του, ούτε τιμή και διαθεσιμότητα, μέχρι να πατήσεις «Ενημέρωση από τον κατάλογο».', 'book-feed' ); ?></p>
			<?php elseif ( $locked ) : ?>
				<p class="bkf-small">
					<?php
					/* translators: %s: list of fields */
					echo esc_html( sprintf( __( 'Κλειδωμένα (τα άλλαξες με το χέρι, ο συγχρονισμός δεν τα αγγίζει): %s.', 'book-feed' ), BKF_Log::fields_text( implode( ',', $locked ) ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $warn ) : ?>
				<p class="bkf-warn bkf-small"><?php echo esc_html( $warn ); ?></p>
			<?php endif; ?>
			<p class="bkf-box-actions">
				<?php if ( ! $linked ) : ?>
					<button type="button" class="button bkf-op" data-bkf-op="sync"><?php esc_html_e( 'Συγχρονισμός τώρα', 'book-feed' ); ?></button>
				<?php endif; ?>
				<button type="button" class="button bkf-op" data-bkf-op="take"><?php echo $linked ? esc_html__( 'Ενημέρωση από τον κατάλογο', 'book-feed' ) : esc_html__( 'Ξεκλείδωμα και πλήρης ενημέρωση', 'book-feed' ); ?></button>
				<button type="button" class="button-link bkf-op bkf-danger-link" data-bkf-op="unlink"><?php esc_html_e( 'Αποσύνδεση', 'book-feed' ); ?></button>
			</p>
			<p class="bkf-small bkf-box-msg" aria-live="polite"></p>
		</div>
		<?php
	}

	/** Saves the product's page on the publisher's site (product form). */
	public static function save_box( $post_id ): void {
		$pid = (int) $post_id;
		if ( ! isset( $_POST['bkf_box_nonce'], $_POST['bkf_pub_url'] ) || ! is_string( $_POST['bkf_box_nonce'] ) || ! is_string( $_POST['bkf_pub_url'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bkf_box_nonce'] ) ), 'bkf_box_' . $pid ) || ! current_user_can( BKF_Admin_UI::CAP ) || ! current_user_can( 'edit_post', $pid ) ) {
			return;
		}
		self::store_pub_url( $pid, wp_unslash( $_POST['bkf_pub_url'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in store_pub_url().
	}

	/** Keeps a product's page on the publisher's site; a bad or empty address removes it. */
	private static function store_pub_url( int $pid, string $raw ): void {
		$url = BKF_Settings::text( esc_url_raw( $raw, array( 'http', 'https' ) ), 300 );
		if ( '' !== $url && BKF_Settings::page_url_ok( $url ) ) {
			update_post_meta( $pid, '_bkf_pub_url', $url );
		} else {
			delete_post_meta( $pid, '_bkf_pub_url' );
		}
	}
}
