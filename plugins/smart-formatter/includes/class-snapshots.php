<?php
/**
 * SF_Snapshots — Undo system: snapshot πριν από κάθε apply run,
 * ιστορικό τελευταίων runs, restore (selective + full).
 *
 * Architecture (ρητά semantics):
 *  - Snapshot ΔΗΜΙΟΥΡΓΕΙΤΑΙ ΜΟΝΟ σε apply runs (όχι σε dry run /
 *    preview — δεν υπάρχει τίποτα να αναιρέσουμε εκεί).
 *  - Snapshot = snapshot ΜΟΝΟ των units που ΘΑ ΑΛΛΑΞΟΥΝ. Units
 *    με changed=false ΔΕΝ καταγράφονται (minimal payload, no noise
 *    στο restore list).
 *  - Payload ανά unit: {kind, id, taxonomy?, field, before} — το
 *    'before' είναι το ΤΡΕΧΟΝ value του προϊόντος (crisp restore: set back
 *    το παλιό string, τίποτα transformations).
 *  - Ιστορικό: option 'sf_snapshots' (JSON array, newest first),
 *    cap = self::HISTORY (default 10). Παλιά runs εκπίπτουν FIFO.
 *  - Payload cap: αν ένα run άλλαξε ΟΛΑ τα πεδία εκατοντάδων
 *    προϊόντων το option μεγαλώνει. Guard: self::MAX_BYTES — αν το
 *    encoded snapshot υπερβαίνει το cap, πετιούνται τα ΠΑΛΑΙΟΤΕΡΑ units
 *    μέχρι να χωρέσει και σημειώνεται truncated=true. Τα units που
 *    κρατήθηκαν ΕΠΑΝΑΦΕΡΟΝΤΑΙ κανονικά (μερική επαναφορά, ρητά
 *    σημειωμένη στο UI και στο αποτέλεσμα του restore).
 *
 * Concurrency: τα snapshots γράφονται μόνο από το
 * SF_Admin_UI AJAX endpoint (capability + nonce gated). Το batching
 * κάνει append νέων units ΣΤΟ ΙΔΙΟ snapshot run_id μέχρι να
 * ολοκληρωθεί (flag 'running' → 'done' όταν το τελευταίο batch
 * κλείνει).
 *
 * Security: defined ABSPATH, no direct SQL, options API μόνο,
 * strict validation στο load (ό,τι δεν ταιριάζει το σχήμα ΑΓΝΟΕΙΤΑΙ
 * στο restore — corrupt entries δεν μπορούν να γράψουν σκουπίδια σε
 * products).
 */

defined( 'ABSPATH' ) || exit;

final class SF_Snapshots {

	const OPT_HISTORY = 'sf_snapshots';

	/** Πόσοι runs κρατούνται (FIFO eviction). */
	const HISTORY = 10;

	/** Payload cap ανά snapshot (bytes, JSON-encoded) — 4 MB. */
	const MAX_BYTES = 4194304;

	/** Lifecycle states του snapshot ενός run. */
	const STATE_RUNNING = 'running';
	const STATE_DONE    = 'done';

	/* =====================================================================
	 * Create / append / finalize
	 * =================================================================== */

	/**
	 * Δημιουργεί (ή συνεχίζει) το snapshot ενός run. Καλείται από το
	 * SF_Admin_UI στο ΠΡΩΤΟ batch ενός apply run, ΜΕΤΑ το plan() slice
	 * (ώστε να ξέρουμε ΠΟΙΑ units θα αλλάξουν και τα ΤΡΕΧΟΝΤΑ values).
	 *
	 * $sel πρέπει να είναι ήδη validated (SF_Admin_UI::plan_args()).
	 *
	 * @return string run_id (SHA-1 των συνθηκών + microtime).
	 */
	public static function begin( array $sel, array $fields, array $rules ): string {

		$run_id = sha1( wp_json_encode( array( $sel, $fields, $rules ) ) . microtime( true ) );

		$snap = array(
			'run_id'  => $run_id,
			'created' => current_time( 'mysql' ),
			'state'   => self::STATE_RUNNING,
			'sel'     => $sel,
			'fields'  => $fields,
			'rules'   => $rules,
			'units'   => array(),
			'truncated' => false,
		);

		self::prepend( $snap );

		return $run_id;
	}

	/**
	 * Append units στο snapshot του τρέχοντος run (κάθε batch).
	 *
	 * $units = array {kind:'product'|'term', id:int, taxonomy?:string,
	 *                 field:string, before:string|null}
	 * Το 'before' σε attributes είναι JSON-encoded blob (όπως το
	 * πήραμε από το plan entry) — αποθηκεύεται AS-IS.
	 */
	public static function append_units( string $run_id, array $units ): bool {

		$history = self::load();

		foreach ( $history as &$snap ) {
			if ( ( $snap['run_id'] ?? '' ) === $run_id ) {

				foreach ( $units as $u ) {
					$c = self::clean_unit( $u );
					if ( null !== $c ) {
						$snap['units'][] = $c;
					}
				}

				// Byte-cap guard: αν ξεπεράστηκε, πετιούνται τα ΠΑΛΑΙΟΤΕΡΑ
				// units του ίδιου run μέχρι να χωρέσει (κρατάμε όσα περισσότερα
				// γίνεται — όλα παραμένουν επαναφέρσιμα).
				$encoded = wp_json_encode( $snap, JSON_UNESCAPED_UNICODE );
				$size    = is_string( $encoded ) ? strlen( $encoded ) : 0;
				if ( $size > self::MAX_BYTES ) {
					$snap['truncated'] = true;
					$drop              = 0;
					$count             = count( $snap['units'] );
					while ( $drop < $count && $size > self::MAX_BYTES ) {
						$u_json = wp_json_encode( $snap['units'][ $drop ], JSON_UNESCAPED_UNICODE );
						$size  -= ( is_string( $u_json ) ? strlen( $u_json ) : 0 ) + 1;
						$drop++;
					}
					$snap['units'] = array_slice( $snap['units'], $drop );
				}

				return self::store( $history );
			}
		}

		return false; // Άγνωστο run_id — τίποτα δεν γράφεται.
	}

	/** Κλείνει το snapshot (τελευταίο batch του run πέρασε). */
	public static function finalize( string $run_id, int $changed_total ): bool {

		$history = self::load();

		foreach ( $history as &$snap ) {
			if ( ( $snap['run_id'] ?? '' ) === $run_id ) {
				$snap['state']         = self::STATE_DONE;
				$snap['changed_total'] = $changed_total;
				return self::store( $history );
			}
		}

		return false;
	}

	/* =====================================================================
	 * Read (UI) / Delete
	 * =================================================================== */

	/**
	 * Ιστορικό snapshots (newest first) — sanitized payload για το UI.
	 * Οι τιμές 'before' ΔΕΝ επιστρέφονται εδώ (μπορεί να είναι
	 * τεράστιες)· μόνο counts + ονόματα. Το UI παίρνει τα units ενός
	 * snapshot με ::units_of().
	 */
	public static function history(): array {

		$out = array();

		foreach ( self::load() as $snap ) {

			$units = ( $snap['units'] ?? array() );

			// Counts ανά kind+field (για το summary του UI).
			$counts = array();
			foreach ( $units as $u ) {
				$k = $u['kind'] . ':' . $u['field'];
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
			}

			$out[] = array(
				'run_id'        => (string) ( $snap['run_id'] ?? '' ),
				'created'       => (string) ( $snap['created'] ?? '' ),
				'state'         => (string) ( $snap['state'] ?? self::STATE_DONE ),
				'rules'         => is_array( $snap['rules'] ?? null ) ? $snap['rules'] : array(),
				'fields'        => is_array( $snap['fields'] ?? null ) ? $snap['fields'] : array(),
				'unit_count'    => count( $units ),
				'changed_total' => (int) ( $snap['changed_total'] ?? 0 ),
				'counts'        => $counts,
				'truncated'     => ! empty( $snap['truncated'] ),
			);
		}

		return $out;
	}

	/** Τα sanitized units ενός snapshot (για restore UI — χωρίς τα before payloads). */
	public static function units_of( string $run_id ): array {

		foreach ( self::load() as $snap ) {
			if ( ( $snap['run_id'] ?? '' ) === $run_id ) {
				$out = array();
				foreach ( ( $snap['units'] ?? array() ) as $i => $u ) {
					$c = self::clean_unit( $u );
					if ( null === $c ) {
						continue;
					}
					unset( $c['before'] ); // Το UI δεν το χρειάζεται — μπορεί να είναι MB.
					$c['idx']         = $i;
					$c['field_label'] = __( self::field_label( $c['field'] ), 'smart-formatter' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid από το fields registry.
					$out[]            = $c;
				}
				return $out;
			}
		}

		return array();
	}

	/**
	 * Delete snapshot (expunge) — το UI το καλεί με POST + nonce.
	 * ΔΕΝ επηρεάζει τα προϊόντα (είναι μόνο απώλεια του undo-opportunity).
	 */
	public static function delete( string $run_id ): bool {

		$history = self::load();

		$out = array();
		foreach ( $history as $snap ) {
			if ( ( $snap['run_id'] ?? '' ) !== $run_id ) {
				$out[] = $snap;
			}
		}

		if ( count( $out ) === count( $history ) ) {
			return false; // Δεν βρέθηκε το run_id.
		}

		return self::store( $out );
	}

	/* =====================================================================
	 * Restore
	 * =================================================================== */

	/**
	 * Restore snapshot — κάνει το UNDO:
	 *   ΟΛΑ τα units (κενό $unit_indexes) ή SELECTIVE (array offsets
	 *   στα units του snapshot, όπως τα δίνει το restore UI).
	 *
	 * Το restore ΔΕΝ περνά από το SF_Engine — γράφει ΠΑΝΤΑ το
	 * 'before' value πίσω AS-IS (οπότε ακόμα και με ένα παλιό snapshot μετά
	 * από αλλαγές του rules registry στο μέλλον, το restore μένει πιστό).
	 *
	 * Truncated snapshot: επαναφέρονται τα units που ΚΡΑΤΗΘΗΚΑΝ και το
	 * αποτέλεσμα φέρει partial=true (το UI το δηλώνει ρητά).
	 *
	 * Terms: wp_update_term(). Products: WC CRUD setters + save()
	 * (ίδια channels με το SF_Targets — τα Woo hooks τρέχουν, cache
	 * invalidation κ.λπ.).
	 *
	 * @return array {restored:int (units), skipped:int, errors:string[],
	 *                partial:bool, found:bool, product_ids:int[]}
	 */
	public static function restore( string $run_id, array $unit_indexes = array() ): array {

		$result = array(
			'restored'    => 0,
			'skipped'     => 0,
			'errors'      => array(),
			'partial'     => false,
			'found'       => false,
			'product_ids' => array(),
		);

		$snap = null;
		foreach ( self::load() as $s ) {
			if ( ( $s['run_id'] ?? '' ) === $run_id ) {
				$snap = $s;
				break;
			}
		}

		if ( null === $snap ) {
			$result['errors'][] = __( 'Το snapshot δεν βρέθηκε.', 'smart-formatter' );
			return $result;
		}

		$result['found']   = true;
		$result['partial'] = ! empty( $snap['truncated'] );

		$units  = ( $snap['units'] ?? array() );
		$select = empty( $unit_indexes ) ? array_keys( $units ) : array_values( array_unique( array_map( 'intval', $unit_indexes ) ) );

		// Group by product id — ώστε ένα προϊόν με πολλά units να
		// γράφεται ΜΙΑ φορά (product->save() πολλές φορές = αργό,
		// duplicate webhooks/notifications).
		$by_product = array();
		$terms      = array();

		foreach ( $select as $idx ) {

			$u = isset( $units[ $idx ] ) ? self::clean_unit( $units[ $idx ] ) : null;

			if ( null === $u ) {
				$result['skipped']++;
				continue;
			}

			if ( 'product' === $u['kind'] ) {
				$by_product[ $u['id'] ][ $u['field'] ] = $u['before'];
			} else {
				$terms[] = $u;
			}
		}

		// ---- Products ----
		foreach ( $by_product as $pid => $fieldmap ) {

			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				$result['errors'][] = sprintf(
					/* translators: %d: ID προϊόντος */
					__( 'Το προϊόν #%d δεν βρέθηκε — δεν επαναφέρθηκε.', 'smart-formatter' ),
					$pid
				);
				$result['skipped'] += count( $fieldmap );
				continue;
			}

			$did = 0;

			foreach ( $fieldmap as $field => $before ) {
				switch ( $field ) {
					case SF_Targets::F_SHORT_DESC:
						$product->set_short_description( $before );
						$did++;
						break;
					case SF_Targets::F_LONG_DESC:
						$product->set_description( $before );
						$did++;
						break;
					case SF_Targets::F_PURCHASE:
						$product->set_purchase_note( $before );
						$did++;
						break;
					case SF_Targets::F_ATTRIBUTES:
						$before_blob = json_decode( $before, true );
						if ( ! is_array( $before_blob ) ) {
							$result['skipped']++;
							break;
						}
						$attrs = $product->get_attributes();
						foreach ( $before_blob as $akey => $opts ) {
							if ( isset( $attrs[ $akey ] ) && $attrs[ $akey ] instanceof WC_Product_Attribute
								&& is_array( $opts ) ) {
								$attrs[ $akey ]->set_options( $opts );
							}
						}
						$product->set_attributes( $attrs );
						$did++;
						break;
				}
			}

			if ( $did > 0 ) {
				$product->save();
				$result['restored']     += $did;
				$result['product_ids'][] = (int) $pid;
			}
		}

		// ---- Terms ----
		foreach ( $terms as $t ) {

			$term = get_term( (int) $t['id'], (string) $t['taxonomy'] );
			if ( ! $term instanceof WP_Term ) {
				$result['errors'][] = sprintf(
					/* translators: %d: ID όρου */
					__( 'Ο όρος #%d δεν βρέθηκε — δεν επαναφέρθηκε.', 'smart-formatter' ),
					(int) $t['id']
				);
				$result['skipped']++;
				continue;
			}

			$res = wp_update_term(
				(int) $t['id'],
				(string) $t['taxonomy'],
				array( 'description' => (string) $t['before'] )
			);

			if ( is_wp_error( $res ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s: μήνυμα σφάλματος */
					__( 'Αποτυχία επαναφοράς όρου: %s', 'smart-formatter' ),
					$res->get_error_message()
				);
				$result['skipped']++;
			} else {
				$result['restored']++;
			}
		}

		return $result;
	}

	/* =====================================================================
	 * Storage (load / store / prepend) — strict validation
	 * =================================================================== */

	/** Load + strictly-validate το history option. */
	private static function load(): array {

		$raw = get_option( self::OPT_HISTORY, '' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $snap ) {

			if ( ! is_array( $snap ) ) {
				continue;
			}
			if ( ! is_string( $snap['run_id'] ?? null ) || '' === $snap['run_id'] ) {
				continue;
			}

			$snap['units'] = array();
			if ( is_array( $snap['units_raw'] ?? null ) ) {
				foreach ( $snap['units_raw'] as $u ) {
					$c = self::clean_unit( $u );
					if ( null !== $c ) {
						$snap['units'][] = $c;
					}
				}
			}
			unset( $snap['units_raw'] );

			$out[] = $snap;
		}

		return $out;
	}

	/** Store — persist το history (JSON-encoded, autoload off). */
	private static function store( array $history ): bool {

		// Re-encode: εσωτερικά το κλειδί είναι 'units'· στο persisted blob
		// γράφεται ως 'units_raw' (schema marker — το load() τα ξανακαθαρίζει).
		$persist = array();
		foreach ( $history as $snap ) {
			$copy = $snap;
			if ( isset( $copy['units'] ) ) {
				$copy['units_raw'] = $copy['units'];
				unset( $copy['units'] );
			}
			$persist[] = $copy;
		}

		if ( empty( $persist ) ) {
			return delete_option( self::OPT_HISTORY );
		}

		return false !== update_option(
			self::OPT_HISTORY,
			wp_json_encode( $persist, JSON_UNESCAPED_UNICODE ),
			false // autoload OFF — μπορεί να γίνει μεγάλο, δεν φορτώνεται σε κάθε request.
		);
	}

	private static function prepend( array $snap ): bool {
		$history = self::load();
		array_unshift( $history, $snap ); // Newest first — όλα τα υπάρχοντα μετατοπίζονται κατά ένα.
		$history = array_slice( $history, 0, self::HISTORY ); // FIFO cap.
		return self::store( $history );
	}

	/* =====================================================================
	 * Sanitizers / helpers
	 * =================================================================== */

	/**
	 * Strict unit cleaner — array ή null (null = discard).
	 * Το schema είναι αυστηρό, χωρίς εξαιρέσεις — εφαρμόζεται before-write
	 * ΚΑΙ after-read (double defence):
	 *  - kind 'product': id > 0, field ∈ product fields.
	 *  - kind 'term':    id > 0, field = term description, taxonomy ∈ {product_cat, product_tag}.
	 *  - before: string.
	 */
	private static function clean_unit( $u ): ?array {

		if ( ! is_array( $u ) ) {
			return null;
		}

		$kind  = (string) ( $u['kind'] ?? '' );
		$id    = isset( $u['id'] ) && is_numeric( $u['id'] ) ? absint( $u['id'] ) : 0;
		$field = is_string( $u['field'] ?? null ) ? $u['field'] : '';
		$tax   = is_string( $u['taxonomy'] ?? null ) ? $u['taxonomy'] : '';
		$defs  = SF_Targets::fields();

		if ( $id <= 0 || ! isset( $defs[ $field ] ) || ! is_string( $u['before'] ?? null ) ) {
			return null;
		}

		if ( 'product' === $kind ) {
			if ( 'product' !== $defs[ $field ]['kind'] ) {
				return null;
			}
			$tax = '';
		} elseif ( 'term' === $kind ) {
			if ( 'term' !== $defs[ $field ]['kind'] || ! in_array( $tax, array( 'product_cat', 'product_tag' ), true ) ) {
				return null;
			}
		} else {
			return null;
		}

		return array(
			'kind'     => $kind,
			'id'       => $id,
			'taxonomy' => $tax,
			'field'    => $field,
			'before'   => $u['before'],
		);
	}

	/**
	 * Import validation (Backup & Επαναφορά) — το blob ιστορικού από
	 * αρχείο είναι UNTRUSTED: θα γραφτεί σε προϊόντα με ένα Restore.
	 *  - Δομή: JSON array από snapshots· κάθε snapshot με run_id (40 hex),
	 *    created (Y-m-d H:i:s), state (running|done), units_raw array.
	 *  - Units: strict clean_unit() + το προϊόν/ο όρος ΠΡΕΠΕΙ να υπάρχει.
	 *  - before: wp_kses_post() (attributes: κάθε option value ξεχωριστά).
	 *  - sel/fields/rules: whitelists.
	 * Δομικά άκυρο blob → null (απορρίπτεται ολόκληρο). Units που δείχνουν
	 * σε ανύπαρκτα προϊόντα/όρους ή άκυρα units παραλείπονται (μετρώνται).
	 *
	 * @return array|null {json:string, dropped:int} ή null.
	 */
	public static function sanitize_import( string $raw ): ?array {

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ( ! empty( $decoded ) && array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) ) {
			return null;
		}

		$rules_reg = SF_Rules::registry();
		$fields    = SF_Targets::fields();
		$out       = array();
		$dropped   = 0;

		foreach ( $decoded as $snap ) {

			if ( ! is_array( $snap )
				|| ! is_string( $snap['run_id'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', $snap['run_id'] )
				|| ! is_string( $snap['created'] ?? null ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $snap['created'] )
				|| ! in_array( $snap['state'] ?? null, array( self::STATE_RUNNING, self::STATE_DONE ), true )
				|| ! is_array( $snap['units_raw'] ?? null ) ) {
				return null;
			}

			$units = array();
			foreach ( $snap['units_raw'] as $u ) {
				$c = self::clean_unit( $u );
				if ( null === $c || ! self::target_exists( $c ) ) {
					$dropped++;
					continue;
				}
				if ( SF_Targets::F_ATTRIBUTES === $c['field'] ) {
					$blob = json_decode( $c['before'], true );
					if ( ! is_array( $blob ) ) {
						$dropped++;
						continue;
					}
					$clean_blob = array();
					foreach ( $blob as $akey => $opts ) {
						if ( null === $opts ) {
							$clean_blob[ sanitize_title( (string) $akey ) ] = null;
						} elseif ( is_array( $opts ) ) {
							$clean_blob[ sanitize_title( (string) $akey ) ] = array_map(
								static function ( $v ) {
									return wp_kses_post( is_scalar( $v ) ? (string) $v : '' );
								},
								array_values( $opts )
							);
						}
					}
					$c['before'] = (string) wp_json_encode( $clean_blob, JSON_UNESCAPED_UNICODE );
				} else {
					$c['before'] = wp_kses_post( $c['before'] );
				}
				$units[] = $c;
			}

			$sel  = is_array( $snap['sel'] ?? null ) ? $snap['sel'] : array();
			$mode = in_array( $sel['mode'] ?? null, SF_Targets::MODES, true ) ? $sel['mode'] : SF_Targets::MODE_ALL;
			$csel = array( 'mode' => $mode );
			foreach ( array( 'products', 'terms' ) as $k ) {
				if ( is_array( $sel[ $k ] ?? null ) ) {
					$csel[ $k ] = array_values( array_filter( array_map( 'absint', $sel[ $k ] ) ) );
				}
			}

			$out[] = array(
				'run_id'        => $snap['run_id'],
				'created'       => $snap['created'],
				'state'         => $snap['state'],
				'sel'           => $csel,
				'fields'        => array_values( array_intersect( is_array( $snap['fields'] ?? null ) ? array_map( 'strval', $snap['fields'] ) : array(), array_keys( $fields ) ) ),
				'rules'         => array_values( array_intersect( is_array( $snap['rules'] ?? null ) ? array_map( 'strval', $snap['rules'] ) : array(), array_keys( $rules_reg ) ) ),
				'units_raw'     => $units,
				'truncated'     => ! empty( $snap['truncated'] ),
				'changed_total' => isset( $snap['changed_total'] ) ? absint( $snap['changed_total'] ) : 0,
			);

			if ( count( $out ) >= self::HISTORY ) {
				break;
			}
		}

		return array(
			'json'    => (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE ),
			'dropped' => $dropped,
		);
	}

	/** Υπάρχει ακόμη το προϊόν / ο όρος ενός unit; */
	private static function target_exists( array $u ): bool {
		if ( 'term' === $u['kind'] ) {
			return get_term( $u['id'], $u['taxonomy'] ) instanceof WP_Term;
		}
		$post = get_post( $u['id'] );
		return $post instanceof WP_Post && 'product' === $post->post_type;
	}

	/** Label πεδίου για το restore UI. */
	private static function field_label( string $fid ): string {
		$fields = SF_Targets::fields();
		return isset( $fields[ $fid ] ) ? (string) $fields[ $fid ]['label'] : $fid;
	}

}