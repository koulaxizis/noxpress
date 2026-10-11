<?php
/**
 * BPV_Metabox — "Book sample" box on the product screen.
 *
 * Fields: the PDF (downloadable files of the product and its variations,
 * or a PDF from the media library), the page field, "hide the button".
 * "Create sample" saves the fields and builds the file at once (AJAX);
 * the product's own "Update" saves them too, and a stale sample is then
 * rebuilt in the background (BPV_Samples::on_product_saved).
 *
 * Every request checks our nonce and edit_post for that product.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Metabox {

	const NONCE       = 'bpv_metabox';
	const NONCE_FIELD = 'bpv_box_nonce';

	public static function init(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add' ) );
		// Before WooCommerce saves the product (priority 10), so the save hooks see the new fields.
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 5 );
		add_action( 'wp_ajax_bpv_generate', array( __CLASS__, 'ajax_generate' ) );
		add_action( 'wp_ajax_bpv_delete', array( __CLASS__, 'ajax_delete' ) );
	}

	public static function add(): void {
		add_meta_box( 'bpv-sample', __( 'Δείγμα βιβλίου', 'book-preview' ), array( __CLASS__, 'render' ), 'product', 'side', 'default' );
	}

	public static function render( $post ): void {
		$pid    = $post instanceof WP_Post ? (int) $post->ID : 0;
		$source = (string) get_post_meta( $pid, '_bpv_source', true );
		$pages  = (string) get_post_meta( $pid, '_bpv_pages', true );
		$hidden = '1' === (string) get_post_meta( $pid, '_bpv_hidden', true );
		$s      = BPV_Settings::get();
		?>
		<div class="bpv-box" data-product="<?php echo (int) $pid; ?>">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<p>
				<label for="bpv_source" class="bpv-box__label"><?php esc_html_e( 'PDF του βιβλίου', 'book-preview' ); ?></label>
				<select id="bpv_source" name="bpv_source" class="bpv-box__field">
					<?php self::source_options( $pid, $source ); ?>
				</select>
				<button type="button" class="button-link bpv-media"><?php esc_html_e( 'Από τη βιβλιοθήκη πολυμέσων…', 'book-preview' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Νέα αρχεία στα «Αρχεία για λήψη» εμφανίζονται εδώ μετά την ενημέρωση του προϊόντος.', 'book-preview' ); ?></p>
			<p>
				<label for="bpv_pages" class="bpv-box__label"><?php esc_html_e( 'Σελίδες του δείγματος', 'book-preview' ); ?></label>
				<input type="text" id="bpv_pages" name="bpv_pages" class="bpv-box__field" value="<?php echo esc_attr( $pages ); ?>" placeholder="<?php echo esc_attr( BPV_Pages::first( (int) $s['first_pages'], 0 ) ); ?>" maxlength="<?php echo (int) BPV_Pages::MAX_LEN; ?>" autocomplete="off" />
				<span class="description">
					<?php
					/* translators: %d: number of pages */
					echo esc_html( sprintf( __( 'Π.χ. 1-12, 20, 35-40. Κενό = οι πρώτες %d σελίδες.', 'book-preview' ), (int) $s['first_pages'] ) );
					?>
				</span>
			</p>
			<p>
				<label><input type="checkbox" name="bpv_hidden" value="1" <?php checked( $hidden ); ?> /> <?php esc_html_e( 'Απόκρυψη του κουμπιού (το δείγμα μένει)', 'book-preview' ); ?></label>
			</p>
			<div class="bpv-box__status" aria-live="polite"><?php echo self::status_html( $pid ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status_html(). ?></div>
			<p class="bpv-box__actions">
				<button type="button" class="button button-primary bpv-generate"><?php esc_html_e( 'Δημιουργία δείγματος', 'book-preview' ); ?></button>
				<button type="button" class="button-link button-link-delete bpv-delete" <?php echo BPV_Samples::sample( $pid ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Διαγραφή', 'book-preview' ); ?></button>
				<span class="spinner"></span>
			</p>
		</div>
		<?php
	}

	private static function source_options( int $pid, string $current ): void {
		echo '<option value="">' . esc_html__( '— Χωρίς δείγμα —', 'book-preview' ) . '</option>';
		$downloads = BPV_Source::downloads( $pid );
		if ( $downloads ) {
			echo '<optgroup label="' . esc_attr__( 'Αρχεία για λήψη', 'book-preview' ) . '">';
			foreach ( $downloads as $key => $d ) {
				$label = $d['name'];
				if ( $d['variation'] ) {
					/* translators: %d: variation ID */
					$label .= ' ' . sprintf( __( '(παραλλαγή #%d)', 'book-preview' ), $d['variation'] );
				}
				if ( '' === $d['path'] ) {
					$label .= ' — ' . __( 'όχι τοπικό αρχείο', 'book-preview' );
				}
				printf(
					'<option value="%s"%s%s>%s</option>',
					esc_attr( $key ),
					selected( $current, $key, false ),
					'' === $d['path'] ? ' disabled' : '',
					esc_html( $label )
				);
			}
			echo '</optgroup>';
		}
		if ( 0 === strpos( $current, 'a:' ) ) {
			$aid = (int) substr( $current, 2 );
			echo '<optgroup label="' . esc_attr__( 'Βιβλιοθήκη πολυμέσων', 'book-preview' ) . '">';
			printf( '<option value="%s" selected>%s</option>', esc_attr( $current ), esc_html( wp_basename( (string) get_attached_file( $aid ) ) ) );
			echo '</optgroup>';
		} elseif ( '' !== $current && ! isset( $downloads[ $current ] ) ) {
			printf( '<option value="%s" selected>%s</option>', esc_attr( $current ), esc_html__( '(το αρχείο δεν υπάρχει πια)', 'book-preview' ) );
		}
	}

	/** Status, details and warnings of a product's sample. */
	public static function status_html( int $pid ): string {
		$st  = BPV_Samples::state( $pid );
		$s   = BPV_Settings::get();
		$out = '';

		if ( $st['sample'] ) {
			$sm   = $st['sample'];
			$url  = BPV_Samples::url( $pid );
			$line = sprintf(
				/* translators: 1: pages in the sample, 2: pages in the book, 3: page list, 4: file size */
				__( '%1$d από %2$d σελίδες (%3$s) · %4$s', 'book-preview' ),
				(int) $sm['count'],
				(int) $sm['total'],
				(string) $sm['pages'],
				size_format( (int) $sm['size'], 1 )
			);
			$badge = 'ready' === $st['state']
				? '<span class="bpv-badge bpv-badge--ok">' . esc_html__( 'Έτοιμο', 'book-preview' ) . '</span>'
				: '<span class="bpv-badge bpv-badge--warn">' . esc_html__( 'Χρειάζεται αναδημιουργία', 'book-preview' ) . '</span>';
			$out .= '<p>' . $badge . ' ' . esc_html( $line );
			if ( '' !== $url ) {
				$out .= ' · <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Άνοιγμα', 'book-preview' ) . '</a>';
			}
			$out .= '</p>';
			$out .= '<p class="description">';
			/* translators: %s: date and time */
			$out .= esc_html( sprintf( __( 'Δημιουργήθηκε %s', 'book-preview' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sm['created'] ) ) );
			if ( 'native' !== $sm['engine'] ) {
				/* translators: %s: qpdf or Ghostscript */
				$out .= ' · ' . esc_html( sprintf( __( 'με %s', 'book-preview' ), 'gs' === $sm['engine'] ? 'Ghostscript' : 'qpdf' ) );
			}
			$out .= '</p>';
			if ( 'stale' === $st['state'] && ! $st['error'] ) {
				$out .= '<p class="description">' . esc_html(
					$s['auto_regen']
						? __( 'Άλλαξε το PDF ή οι σελίδες. Το δείγμα ξαναφτιάχνεται στο παρασκήνιο μετά την ενημέρωση του προϊόντος· μέχρι τότε φαίνεται το προηγούμενο.', 'book-preview' )
						: __( 'Άλλαξε το PDF ή οι σελίδες. Πάτα «Δημιουργία δείγματος»· μέχρι τότε φαίνεται το προηγούμενο.', 'book-preview' )
				) . '</p>';
			}
			if ( (int) $sm['count'] >= (int) $sm['total'] ) {
				$out .= '<p class="bpv-box-warn">' . esc_html__( 'Το δείγμα περιέχει όλες τις σελίδες του βιβλίου.', 'book-preview' ) . '</p>';
			}
			if ( (int) $sm['size'] > (int) $s['warn_mb'] * 1048576 ) {
				$out .= '<p class="bpv-box-warn">' . esc_html__( 'Το δείγμα είναι μεγάλο και θα αργεί να ανοίξει σε κινητά. Χρησιμοποίησε ως πηγή την εκδοχή ebook (μικρότερες εικόνες) αντί για το PDF του τυπογραφείου.', 'book-preview' ) . '</p>';
			}
		} elseif ( ! $st['error'] ) {
			$out .= '<p class="description">' . esc_html__( 'Δεν υπάρχει δείγμα ακόμα.', 'book-preview' ) . '</p>';
		}

		if ( $st['error'] ) {
			$e    = $st['error'];
			$out .= '<p class="bpv-box-error">' . esc_html(
				BPV_Samples::error_message(
					(string) $e['error'],
					array(
						'error' => (string) ( $e['perr'] ?? '' ),
						'page'  => (int) ( $e['page'] ?? 0 ),
					)
				)
			) . '</p>';
		}

		if ( $st['source']['ok'] && $st['source']['public'] ) {
			$out .= '<p class="bpv-box-warn">' . esc_html__( 'Το πλήρες PDF έχει δημόσια διεύθυνση (βιβλιοθήκη πολυμέσων ή φάκελος εκτός woocommerce_uploads): όποιος τη βρει μπορεί να το κατεβάσει. Το plugin δεν τη δείχνει ποτέ, αλλά πιο ασφαλές είναι να μπει ως «Αρχείο για λήψη» του προϊόντος.', 'book-preview' ) . '</p>';
		} elseif ( $st['source']['ok'] && BPV_Source::nginx() ) {
			$out .= '<p class="description">' . esc_html__( 'Ο server είναι nginx: το .htaccess του woocommerce_uploads δεν ισχύει εκεί. Βεβαιώσου ότι ο φάκελος είναι κλειστός στη ρύθμιση του server.', 'book-preview' ) . '</p>';
		}

		if ( '1' === (string) get_post_meta( $pid, '_bpv_hidden', true ) && $st['sample'] ) {
			$out .= '<p class="description">' . esc_html__( 'Το κουμπί είναι κρυφό σε αυτό το προϊόν.', 'book-preview' ) . '</p>';
		}
		if ( empty( $s['enabled'] ) && $st['sample'] ) {
			$out .= '<p class="description">' . esc_html__( 'Το κουμπί δεν φαίνεται ακόμα στο κατάστημα: ενεργοποίησέ το στις ρυθμίσεις του Book Preview.', 'book-preview' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . BPV_Admin_UI::SLUG_SET ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'book-preview' ) . '</a></p>';
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------- */

	/** Validates and stores the three fields; true when source or pages changed. */
	private static function store( int $pid, string $source, string $pages, bool $hidden ): bool {
		$source = BPV_Source::valid_key( $source ) ? $source : '';
		$pages  = BPV_Settings::clip( sanitize_text_field( $pages ), BPV_Pages::MAX_LEN );

		$changed = $source !== (string) get_post_meta( $pid, '_bpv_source', true ) || $pages !== (string) get_post_meta( $pid, '_bpv_pages', true );
		'' !== $source ? update_post_meta( $pid, '_bpv_source', $source ) : delete_post_meta( $pid, '_bpv_source' );
		'' !== $pages ? update_post_meta( $pid, '_bpv_pages', $pages ) : delete_post_meta( $pid, '_bpv_pages' );
		$was_hidden = '1' === (string) get_post_meta( $pid, '_bpv_hidden', true );
		$hidden ? update_post_meta( $pid, '_bpv_hidden', '1' ) : delete_post_meta( $pid, '_bpv_hidden' );
		if ( $changed ) {
			delete_post_meta( $pid, '_bpv_error' ); // A new attempt starts clean.
		}
		if ( '' === $source && BPV_Samples::sample( $pid ) ) {
			BPV_Samples::delete( $pid ); // "No sample": nothing left on the front end.
		} elseif ( $was_hidden !== $hidden ) {
			BPV_Samples::purge( $pid );
		}
		return $changed;
	}

	/** woocommerce_process_product_meta: the product's own "Update". */
	public static function save( $post_id ): void {
		$pid = (int) $post_id;
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			return;
		}
		self::store(
			$pid,
			isset( $_POST['bpv_source'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['bpv_source'] ) ) : '',
			isset( $_POST['bpv_pages'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['bpv_pages'] ) ) : '',
			isset( $_POST['bpv_hidden'] ) && '1' === $_POST['bpv_hidden']
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	private static function ajax_product(): int {
		check_ajax_referer( self::NONCE, 'nonce' );
		$pid = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		if ( ! $pid || 'product' !== get_post_type( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( null, 403 );
		}
		return $pid;
	}

	public static function ajax_generate(): void {
		$pid = self::ajax_product();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_product().
		self::store(
			$pid,
			isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source'] ) ) : '',
			isset( $_POST['pages'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pages'] ) ) : '',
			isset( $_POST['hidden'] ) && '1' === $_POST['hidden']
		);
		// phpcs:enable
		$res = BPV_Samples::generate( $pid );
		wp_send_json_success(
			array(
				'ok'      => $res['ok'],
				'message' => $res['ok'] ? __( 'Το δείγμα δημιουργήθηκε.', 'book-preview' ) : $res['message'],
				'status'  => self::status_html( $pid ),
				'has'     => (bool) BPV_Samples::sample( $pid ),
			)
		);
	}

	public static function ajax_delete(): void {
		$pid = self::ajax_product();
		BPV_Samples::delete( $pid );
		wp_send_json_success(
			array(
				'ok'      => true,
				'message' => __( 'Το δείγμα διαγράφηκε.', 'book-preview' ),
				'status'  => self::status_html( $pid ),
				'has'     => false,
			)
		);
	}
}
