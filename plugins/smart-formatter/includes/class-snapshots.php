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
 *    encoded snapshot υπερβαίνει το cap, γράφεται ρητά entry με
 *    truncated=true (ο χρήστης το βλέπει και αποφασίζει).
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

				// Byte-cap guard: αν ξεπεράστηκε, truncate σημασία —
				// περικόπτει τα ΠΑΛΙΑ units του ίδιου run (τα πρόσφατα
				// μένουν — πιο χρήσιμα για targeted restore).
				$encoded = wp_json_encode( $snap, JSON_UNESCAPED_UNICODE );
				if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_BYTES ) {
					$snap['truncated'] = true;
					$snap['units']     = array_slice( $snap['units'], -50 ); // κράτα τα 50 τελευταία.
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

	/** Τα sanitized units ενός snapshot (για restore UI). */
	public static function units_of( string $run_id ): array {

		foreach ( self::load() as $snap ) {
			if ( ( $snap['run_id'] ?? '' ) === $run_id ) {
				$out = array();
				foreach ( ( $snap['units'] ?? array() ) as $i => $u ) {
					$c = self::clean_unit( $u );
					if ( null === $c ) {
						continue;
					}
					$c['idx']         = $i;
					$c['field_label'] = self::field_label( $c['field'] );
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
	 *   ΟΛΑ τα units (null $unit_indexes) ή SELECTIVE (array offsets
	 *   στα units του snapshot, όπως τα δίνει το restore UI).
	 *
	 * Το restore ΔΕΝ περνά από το SF_Engine — γράφει ΠΑΝΤΑ το
	 * 'before' value πίσω AS-IS (οπότε ακόμα και με ένα παλιό snapshot μετά
	 * από αλλαγές του rules registry στο μέλλον, το restore μένει
	 * πιστό).
	 *
	 * Terms: wp_update_term(). Products: WC CRUD setters + save()
	 * (ίδια channels με το SF_Targets — τα Woo hooks τρέχουν, cache
	 * invalidation κ.λπ.).
	 *
	 * @return array notices {restored:int, skipped:int, errors:string[]}
	 */
	public static function restore( string $run_id, array $unit_indexes = array() ): array {

		$result = array(
			'restored' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$snap = null;
		foreach ( self::load() as $s ) {
			if ( ( $s['run_id'] ?? '' ) === $run_id ) {
				$snap = $s;
				break;
			}
		}

		if ( null === $snap ) {
			$result['errors'][] = 'Το snapshot δεν βρέθηκε.';
			return $result;
		}

		// BLOCK: truncated snapshot — μερικό restore = ρίσκο δεδομένων.
		if ( ! empty( $snap['truncated'] ) ) {
			$result['errors'][] = __(
				'Το snapshot είναι ελλιπές (truncated) — η επαναφορά μπλοκαρίστηκε για ασφάλεια.',
				'smart-formatter'
			);
			return $result;
		}

		$units   = ( $snap['units'] ?? array() );
		$select  = empty( $unit_indexes ) ? array_keys( $units ) : $unit_indexes;

		// Group by product id — ώστε ένα προϊόν που έχουν πολλά units
		// να γράφεται ΜΙΑ φορά (product->save() πολλά-φορές = αργό,
		// duplicate webhooks/notifications).
		$by_product = array();
		$terms      = array();

		foreach ( $select as $idx ) {

			$idx = (int) $idx;
			if ( ! isset( $units[ $idx ] ) ) {
				$result['skipped']++;
				continue;
			}

			$u = self::clean_unit( $units[ $idx ] );

			if ( null === $u ) {
				$result['skipped']++;
				continue;
			}

			if ( 'product' === $u['kind'] ) {
				$pid = (int) $u['id'];
				if ( ! isset( $by_product[ $pid ] ) ) {
					$by_product[ $pid ] = array();
				}
				$by_product[ $pid ][ $u['field'] ] = $u['before'];
			} elseif ( 'term' === $u['kind'] ) {
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
				continue;
			}

			$did = false;

			if ( isset( $fieldmap[ SF_Targets::F_SHORT_DESC ] ) ) {
				$product->set_short_description( (string) $fieldmap[ SF_Targets::F_SHORT_DESC ] );
				$did = true;
			}
			if ( isset( $fieldmap[ SF_Targets::F_LONG_DESC ] ) ) {
				$product->set_description( (string) $fieldmap[ SF_Targets::F_LONG_DESC ] );
				$did = true;
			}
			if ( isset( $fieldmap[ SF_Targets::F_PURCHASE ] ) ) {
				$product->set_purchase_note( (string) $fieldmap[ SF_Targets::F_PURCHASE ] );
				$did = true;
			}
			if ( isset( $fieldmap[ SF_Targets::F_ATTRIBUTES ] ) ) {
				$before_blob = json_decode( (string) $fieldmap[ SF_Targets::F_ATTRIBUTES ], true );
				if ( is_array( $before_blob ) ) {
					$attrs = $product->get_attributes();
					foreach ( $before_blob as $akey => $opts ) {
						if ( isset( $attrs[ $akey ] ) && $attrs[ $akey ] instanceof WC_Product_Attribute
							&& is_array( $opts ) ) {
							$attrs[ $akey ]->set_options( $opts );
						}
					}
					$product->set_attributes( $attrs );
					$did = true;
				}
			}

			if ( $did ) {
				$product->save();
				$result['restored']++;
			}
		}

		// ---- Terms ----
		foreach ( $terms as $t ) {

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

		// Re-encode: το internal format κρατά 'units' στου εαυτού
		// του· αλλαγή ονόματος σε 'units_raw' δίνει και future-proof
		// schema marker στο persisted blob.
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
	 * ΚΑΙ after-read (double defence).
	 */
	private static function clean_unit( $u ): ?array {

		if ( ! is_array( $u ) ) {
			return null;
		}

		if ( ! in_array( (string) ( $u['kind'] ?? '' ), array( 'product', 'term' ), true ) ) {
			return null;
		}

		return array(
			'kind'     => (string) $u['kind'],
			'id'       => absint( $u['id'] ?? 0 ),
			'taxonomy' => in_array( (string) ( $u['taxonomy'] ?? '' ), array( 'product_cat', 'product_tag' ), true ) ? (string) $u['taxonomy'] : '',
			'field'    => isset( self::field_whitelist()[ (string) ( $u['field'] ?? '' ) ] ) ? (string) $u['field'] : '',
			'before'   => is_string( $u['before'] ?? null ) ? $u['before'] : '',
		);
	}

	/** Field whitelist (ΚΑΙ reference για το clean_unit). */
	private static function field_whitelist(): array {
		$map = array();
		foreach ( SF_Targets::fields() as $fid => $def ) {
			$map[ $fid ] = true;
		}
		return $map;
	}

	/** Label πεδίου για το restore UI. */
	private static function field_label( string $fid ): string {
		$fields = SF_Targets::fields();
		return isset( $fields[ $fid ] ) ? (string) $fields[ $fid ]['label'] : $fid;
	}

}