<?php
/**
 * BPV_Admin_UI — menu, pages, PRG routes, AJAX, backup, footer (Bible §3–§8, §11).
 *
 * Pages (submenus of the shared "Noxpress" menu, admin_menu priority 80):
 *  - bpv-samples   status, bulk creation ("first N pages" for a category),
 *                  list of the products with a PDF and their sample state
 *  - bpv-settings  front end, button position, visitor texts, end card,
 *                  defaults, engines and server check, backup (JSON)
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation, PRG with the message in the transient
 * bpv_aui_msg_{uid}. AJAX: check_ajax_referer + capability + whitelist.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG_MAIN   = 'bpv-samples';
	const SLUG_SET    = 'bpv-settings';
	const NONCE       = 'bpv_admin';
	const NONCE_FIELD = 'bpv_nonce';
	const NONCE_BAK   = 'bpv_backup';
	const NONCE_AJAX  = 'bpv_ajax';
	const OPT_BULK    = 'bpv_bulk';
	const MAX_IMPORT  = 262144; // 256 KB.
	const PER_PAGE    = 50;
	const BULK_STEP   = 2;      // Products per request.
	const BULK_SECS   = 20;     // Stop a step early after this many seconds.
	const STATES      = array( 'none', 'ready', 'stale', 'error' );
	const MODES       = array( 'missing', 'stale', 'all' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 80 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_bpv_bulk_start', array( __CLASS__, 'ajax_bulk_start' ) );
		add_action( 'wp_ajax_bpv_bulk_step', array( __CLASS__, 'ajax_bulk_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BPV_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		// The shared top-level "Noxpress" (with the hub as landing page) is
		// created by Noxpress Core (priority 5, Bible §16): submenus only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Book Preview', 'book-preview' ),
			__( 'Δείγματα', 'book-preview' ),
			self::CAP,
			self::SLUG_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Book Preview — Ρυθμίσεις', 'book-preview' ),
			__( 'BP Ρυθμίσεις', 'book-preview' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'book-preview' ) . '</a>'
		);
		return $links;
	}

	/** Current admin page slug (sanitized) — '' outside admin.php?page=. */
	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only routing). */
	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	public static function assets( $hook ): void {
		$page    = self::current_page();
		$ours    = self::SLUG_MAIN === $page || self::SLUG_SET === $page;
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$product = $screen && 'product' === $screen->post_type && in_array( (string) $hook, array( 'post.php', 'post-new.php' ), true );
		if ( ! $ours && ! $product ) {
			return;
		}
		if ( $product ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'bpv-admin', BPV_URL . 'assets/admin.css', array(), BPV_VERSION );
		wp_enqueue_script( 'bpv-admin', BPV_URL . 'assets/admin.js', array( 'jquery' ), BPV_VERSION, true );
		wp_localize_script(
			'bpv-admin',
			'BPV',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_AJAX ),
				'boxNonce'  => wp_create_nonce( BPV_Metabox::NONCE ),
				'mediaTitle' => __( 'Επιλογή PDF', 'book-preview' ),
				'mediaButton' => __( 'Χρήση αυτού του PDF', 'book-preview' ),
				'mediaGroup' => __( 'Βιβλιοθήκη πολυμέσων', 'book-preview' ),
				'confirmDelete' => __( 'Να διαγραφεί το αρχείο του δείγματος;', 'book-preview' ),
				'working'   => __( 'Δημιουργία…', 'book-preview' ),
				'failed'    => __( 'Το αίτημα απέτυχε. Δοκίμασε ξανά.', 'book-preview' ),
				/* translators: 1: products done, 2: products in the run */
				'progress'  => __( '%1$d από %2$d προϊόντα', 'book-preview' ),
				'stopped'   => __( 'Η μαζική δημιουργία σταμάτησε. Ό,τι έγινε έχει αποθηκευτεί· πάτα ξανά για να συνεχίσει.', 'book-preview' ),
				'none'      => __( 'Κανένα προϊόν δεν ταιριάζει σε αυτή την επιλογή.', 'book-preview' ),
			)
		);
	}

	/* =====================================================================
	 * Labels (msgids in one place)
	 * =================================================================== */

	private static function state_labels(): array {
		return array(
			'none'  => __( 'Χωρίς δείγμα', 'book-preview' ),
			'ready' => __( 'Έτοιμο', 'book-preview' ),
			'stale' => __( 'Χρειάζεται αναδημιουργία', 'book-preview' ),
			'error' => __( 'Σφάλμα', 'book-preview' ),
		);
	}

	private static function position_labels(): array {
		return array(
			'after_excerpt' => __( 'Κάτω από τη σύντομη περιγραφή', 'book-preview' ),
			'after_cart'    => __( 'Κάτω από το κουμπί καλαθιού', 'book-preview' ),
			'manual'        => __( 'Μόνο με το shortcode [book_preview] ή το block', 'book-preview' ),
		);
	}

	private static function mode_labels(): array {
		return array(
			'missing' => __( 'Μόνο όσα δεν έχουν δείγμα', 'book-preview' ),
			'stale'   => __( 'Όσα δεν έχουν δείγμα και όσα χρειάζονται αναδημιουργία', 'book-preview' ),
			'all'     => __( 'Όλα (αναδημιουργία και των έτοιμων)', 'book-preview' ),
		);
	}

	/* =====================================================================
	 * Products with a PDF
	 * =================================================================== */

	/**
	 * Products (not variations) that have a PDF download or a chosen source.
	 *
	 * @param int  $cat      product_cat term (0 = all).
	 * @param bool $children Include the sub-categories.
	 * @return int[]
	 */
	public static function products( int $cat = 0, bool $children = true ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- admin listing, read only.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				 WHERE p.post_type IN ('product', 'product_variation')
				   AND p.post_status NOT IN ('trash', 'auto-draft')
				   AND ( ( m.meta_key = '_downloadable_files' AND LOWER(m.meta_value) LIKE %s ) OR m.meta_key = '_bpv_source' )",
				'%' . $wpdb->esc_like( '.pdf' ) . '%'
			)
		);
		// phpcs:enable
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $rows ) ) ) );
		if ( $ids && $cat > 0 ) {
			$terms = array( $cat );
			if ( $children ) {
				$terms = array_merge( $terms, array_map( 'intval', (array) get_term_children( $cat, 'product_cat' ) ) );
			}
			$in  = get_objects_in_term( $terms, 'product_cat' );
			$ids = array_values( array_intersect( $ids, array_map( 'intval', is_array( $in ) ? $in : array() ) ) );
		}
		// Only published-or-draft products that still exist as products.
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id );
				}
			)
		);
		sort( $ids );
		return $ids;
	}

	/* =====================================================================
	 * Page: samples
	 * =================================================================== */

	public static function render_main(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-preview' ) );
		}

		$notices = self::take_msgs();
		$cat     = absint( self::q( 'cat' ) );
		$filter  = self::q( 'state' );
		$filter  = in_array( $filter, self::STATES, true ) ? $filter : '';
		$ids     = self::products( $cat );
		$rows    = array();
		$counts  = array_fill_keys( self::STATES, 0 );
		foreach ( $ids as $id ) {
			$st = BPV_Samples::state( $id );
			++$counts[ $st['state'] ];
			if ( '' === $filter || $filter === $st['state'] ) {
				$rows[ $id ] = $st;
			}
		}
		$total = count( $rows );
		$paged = max( 1, absint( self::q( 'paged' ) ) );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$shown = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		$s     = BPV_Settings::get();
		?>
		<div class="wrap bpv-wrap">
			<h1><?php esc_html_e( 'Book Preview', 'book-preview' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Για κάθε βιβλίο φτιάχνεται στον server ξεχωριστό PDF μόνο με τις σελίδες του δείγματος. Ο επισκέπτης το διαβάζει μέσα στη σελίδα του προϊόντος· το πλήρες PDF δεν φτάνει ποτέ στον browser.', 'book-preview' ); ?></p>

			<?php self::print_notices( $notices ); ?>

			<h2 class="bpv-h2"><?php esc_html_e( 'Κατάσταση', 'book-preview' ); ?></h2>
			<div class="bpv-zone bpv-status">
				<p>
					<strong><?php esc_html_e( 'Book Preview:', 'book-preview' ); ?></strong>
					<?php if ( Book_Preview::killed() ) : ?>
						<span class="bpv-badge bpv-badge--danger"><?php esc_html_e( 'Απενεργοποιημένο από το BPV_DISABLE (wp-config.php)', 'book-preview' ); ?></span>
					<?php elseif ( empty( $s['enabled'] ) ) : ?>
						<span class="bpv-badge bpv-badge--muted"><?php esc_html_e( 'Ανενεργό στο front end', 'book-preview' ); ?></span>
					<?php elseif ( ! empty( $s['test_mode'] ) ) : ?>
						<span class="bpv-badge bpv-badge--warn"><?php esc_html_e( 'Λειτουργία δοκιμής — το κουμπί φαίνεται μόνο στους διαχειριστές του καταστήματος', 'book-preview' ); ?></span>
					<?php else : ?>
						<span class="bpv-badge bpv-badge--ok"><?php esc_html_e( 'Ενεργό για όλους τους επισκέπτες', 'book-preview' ); ?></span>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Αλλαγή', 'book-preview' ); ?></a>
				</p>
				<p>
					<strong><?php esc_html_e( 'Προϊόντα με PDF:', 'book-preview' ); ?></strong> <?php echo (int) count( $ids ); ?>
					<span class="bpv-muted"> · </span>
					<?php
					$labels = self::state_labels();
					$parts  = array();
					foreach ( self::STATES as $k ) {
						$parts[] = esc_html( $labels[ $k ] ) . ': ' . (int) $counts[ $k ];
					}
					echo implode( ' <span class="bpv-muted">·</span> ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
					?>
				</p>
			</div>

			<h2 class="bpv-h2"><?php esc_html_e( 'Μαζική δημιουργία', 'book-preview' ); ?></h2>
			<div class="bpv-zone">
				<p class="description"><?php esc_html_e( 'Φτιάχνει δείγματα για τα προϊόντα μιας κατηγορίας που έχουν PDF. Όπου δεν έχει οριστεί PDF ή σελίδες, παίρνει το πρώτο PDF των αρχείων για λήψη και τις πρώτες σελίδες με τις τιμές από κάτω. Βιβλία με λιγότερες σελίδες παίρνουν όσες έχουν.', 'book-preview' ); ?></p>
				<div class="bpv-grid">
					<div>
						<label class="bpv-label" for="bpv-bulk-cat"><?php esc_html_e( 'Κατηγορία', 'book-preview' ); ?></label>
						<?php self::category_select( 'bpv-bulk-cat', $cat ); ?>
						<label class="bpv-check bpv-mt-8"><input type="checkbox" id="bpv-bulk-children" value="1" checked /> <?php esc_html_e( 'Μαζί με τις υποκατηγορίες', 'book-preview' ); ?></label>
					</div>
					<div>
						<label class="bpv-label" for="bpv-bulk-n"><?php esc_html_e( 'Πρώτες σελίδες', 'book-preview' ); ?></label>
						<input type="number" class="bpv-num" id="bpv-bulk-n" min="1" max="200" value="<?php echo (int) $s['first_pages']; ?>" />
						<label class="bpv-label" for="bpv-bulk-skip"><?php esc_html_e( 'Παράλειψη των πρώτων σελίδων (εξώφυλλο, λευκές, copyright)', 'book-preview' ); ?></label>
						<input type="number" class="bpv-num" id="bpv-bulk-skip" min="0" max="100" value="<?php echo (int) $s['skip_pages']; ?>" />
					</div>
					<div>
						<span class="bpv-label"><?php esc_html_e( 'Ποια προϊόντα', 'book-preview' ); ?></span>
						<?php foreach ( self::mode_labels() as $k => $l ) : ?>
							<label class="bpv-check"><input type="radio" name="bpv_bulk_mode" value="<?php echo esc_attr( $k ); ?>" <?php checked( 'missing', $k ); ?> /> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
					</div>
				</div>
				<p class="bpv-submit">
					<button type="button" class="button button-primary" id="bpv-bulk-start"><?php esc_html_e( 'Δημιουργία δειγμάτων', 'book-preview' ); ?></button>
				</p>
				<div class="bpv-progress" id="bpv-progress" hidden><div class="bpv-progress__bar"></div></div>
				<p class="bpv-progress-text" id="bpv-progress-text" aria-live="polite"></p>
			</div>

			<h2 class="bpv-h2"><?php esc_html_e( 'Προϊόντα', 'book-preview' ); ?></h2>
			<form method="get" class="bpv-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_MAIN ); ?>" />
				<?php self::category_select( 'bpv-filter-cat', $cat, 'cat' ); ?>
				<select name="state">
					<option value=""><?php esc_html_e( 'Όλες οι καταστάσεις', 'book-preview' ); ?></option>
					<?php foreach ( self::state_labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Φίλτρο', 'book-preview' ); ?></button>
			</form>

			<table class="widefat bpv-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Προϊόν', 'book-preview' ); ?></th>
						<th><?php esc_html_e( 'PDF', 'book-preview' ); ?></th>
						<th><?php esc_html_e( 'Σελίδες', 'book-preview' ); ?></th>
						<th><?php esc_html_e( 'Μέγεθος', 'book-preview' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση', 'book-preview' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $shown ) : ?>
						<tr><td colspan="5" class="bpv-empty"><?php esc_html_e( 'Κανένα προϊόν με PDF.', 'book-preview' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $shown as $id => $st ) : ?>
						<?php self::row( (int) $id, $st ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::paging( $paged, $pages, $cat, $filter ); ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	private static function row( int $id, array $st ): void {
		$labels = self::state_labels();
		$sm     = $st['sample'];
		$badge  = array(
			'none'  => 'bpv-badge--muted',
			'ready' => 'bpv-badge--ok',
			'stale' => 'bpv-badge--warn',
			'error' => 'bpv-badge--danger',
		);
		$src    = $st['source'];
		$name   = $src['name'];
		if ( '' === $name ) {
			$first = BPV_Source::downloads( $id );
			$first = $first ? reset( $first ) : null;
			$name  = $first ? $first['name'] . ' (' . __( 'πρώτο', 'book-preview' ) . ')' : '—';
		}
		?>
		<tr>
			<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a> <span class="bpv-hint">#<?php echo (int) $id; ?></span></td>
			<td><?php echo esc_html( $name ); ?><?php echo $src['ok'] && $src['public'] ? ' <span class="bpv-badge bpv-badge--warn">' . esc_html__( 'δημόσιο', 'book-preview' ) . '</span>' : ''; ?></td>
			<td>
				<?php
				if ( $sm ) {
					/* translators: 1: pages in the sample, 2: pages in the book, 3: page list */
					echo esc_html( sprintf( __( '%1$d από %2$d (%3$s)', 'book-preview' ), (int) $sm['count'], (int) $sm['total'], (string) $sm['pages'] ) );
				} else {
					echo esc_html( '' !== $st['pages'] ? $st['pages'] : '—' );
				}
				?>
			</td>
			<td><?php echo $sm ? esc_html( size_format( (int) $sm['size'], 1 ) ) : '—'; ?></td>
			<td>
				<span class="bpv-badge <?php echo esc_attr( $badge[ $st['state'] ] ); ?>"><?php echo esc_html( $labels[ $st['state'] ] ); ?></span>
				<?php if ( $st['error'] ) : ?>
					<br /><span class="bpv-error">
					<?php
					echo esc_html(
						BPV_Samples::error_message(
							(string) $st['error']['error'],
							array(
								'error' => (string) ( $st['error']['perr'] ?? '' ),
								'page'  => (int) ( $st['error']['page'] ?? 0 ),
							)
						)
					);
					?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function paging( int $paged, int $pages, int $cat, string $filter ): void {
		if ( $pages < 2 ) {
			return;
		}
		echo '<p class="bpv-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg(
				array_filter(
					array(
						'page'  => self::SLUG_MAIN,
						'cat'   => $cat ? $cat : null,
						'state' => '' !== $filter ? $filter : null,
						'paged' => $i,
					)
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<a class="bpv-page%s" href="%s">%d</a>',
				$i === $paged ? ' bpv-page--current' : '',
				esc_url( $url ),
				(int) $i
			);
		}
		echo '</p>';
	}

	private static function category_select( string $id, int $current, string $name = '' ): void {
		wp_dropdown_categories(
			array(
				'taxonomy'         => 'product_cat',
				'hierarchical'     => true,
				'hide_empty'       => false,
				'show_option_all'  => __( 'Όλες οι κατηγορίες', 'book-preview' ),
				'selected'         => $current,
				'id'               => $id,
				'name'             => '' !== $name ? $name : $id,
				'orderby'          => 'name',
				'value_field'      => 'term_id',
			)
		);
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-preview' ) );
		}

		require_once BPV_PATH . 'includes/class-engine.php';

		$notices = self::take_msgs();
		$s       = BPV_Settings::get();
		$texts   = array(
			'button'     => __( 'Κουμπί', 'book-preview' ),
			'end_title'  => __( 'Τίτλος της κάρτας', 'book-preview' ),
			'end_button' => __( 'Κουμπί αγοράς', 'book-preview' ),
		);
		?>
		<div class="wrap bpv-wrap">
			<h1><?php esc_html_e( 'Book Preview — Ρυθμίσεις', 'book-preview' ); ?></h1>

			<?php self::print_notices( $notices ); ?>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="bpv_action" value="save_settings" />

				<h2 class="bpv-h2"><?php esc_html_e( 'Λειτουργία', 'book-preview' ); ?></h2>
				<div class="bpv-zone">
					<label class="bpv-check">
						<input type="checkbox" name="bpv_enabled" value="1" <?php checked( $s['enabled'] ); ?> />
						<?php esc_html_e( 'Ενεργοποίηση στο front end', 'book-preview' ); ?>
					</label>
					<label class="bpv-check">
						<input type="checkbox" name="bpv_test_mode" value="1" <?php checked( $s['test_mode'] ); ?> />
						<?php esc_html_e( 'Λειτουργία δοκιμής: το κουμπί φαίνεται μόνο σε όσους διαχειρίζονται το κατάστημα', 'book-preview' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'book-preview' ); ?>
						<code>define( 'BPV_DISABLE', true );</code>
					</p>
					<?php if ( Book_Preview::killed() ) : ?>
						<p class="bpv-warn"><?php esc_html_e( 'Το BPV_DISABLE είναι ενεργό: τίποτα δεν εμφανίζεται στο front end.', 'book-preview' ); ?></p>
					<?php endif; ?>
				</div>

				<h2 class="bpv-h2"><?php esc_html_e( 'Κουμπί δείγματος', 'book-preview' ); ?></h2>
				<div class="bpv-zone">
					<span class="bpv-label"><?php esc_html_e( 'Θέση στη σελίδα προϊόντος', 'book-preview' ); ?></span>
					<?php foreach ( self::position_labels() as $k => $l ) : ?>
						<label class="bpv-check"><input type="radio" name="bpv_position" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['position'], $k ); ?> /> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Το shortcode [book_preview] δουλεύει παντού ([book_preview id="123"] για άλλο προϊόν, label="…" για άλλο κείμενο). Στο block editor υπάρχει και το block «Δείγμα βιβλίου».', 'book-preview' ); ?></p>

					<span class="bpv-label"><?php esc_html_e( 'Γλώσσα των κειμένων', 'book-preview' ); ?></span>
					<label class="bpv-inline"><input type="radio" name="bpv_lang" value="auto" <?php checked( $s['lang'], 'auto' ); ?> /> <?php esc_html_e( 'Αυτόματα (γλώσσα του site)', 'book-preview' ); ?></label>
					<label class="bpv-inline"><input type="radio" name="bpv_lang" value="el" <?php checked( $s['lang'], 'el' ); ?> /> Ελληνικά</label>
					<label class="bpv-inline"><input type="radio" name="bpv_lang" value="en" <?php checked( $s['lang'], 'en' ); ?> /> English</label>

					<label class="bpv-check bpv-mt-8">
						<input type="checkbox" name="bpv_end_card" value="1" <?php checked( $s['end_card'] ); ?> />
						<?php esc_html_e( 'Κάρτα «Συνεχίστε την ανάγνωση» μετά την τελευταία σελίδα, με κουμπί αγοράς', 'book-preview' ); ?>
					</label>

					<?php foreach ( $texts as $key => $label ) : ?>
						<div class="bpv-grid">
							<div>
								<label class="bpv-label" for="bpv-<?php echo esc_attr( $key ); ?>-el">
									<?php
									/* translators: %s: which text */
									echo esc_html( sprintf( __( '%s (ελληνικά)', 'book-preview' ), $label ) );
									?>
								</label>
								<input type="text" id="bpv-<?php echo esc_attr( $key ); ?>-el" name="bpv_<?php echo esc_attr( $key ); ?>_el" value="<?php echo esc_attr( $s[ $key . '_el' ] ); ?>" placeholder="<?php echo esc_attr( BPV_Settings::TEXTS[ $key ][0] ); ?>" maxlength="<?php echo (int) BPV_Settings::MAX_TEXT; ?>" />
							</div>
							<div>
								<label class="bpv-label" for="bpv-<?php echo esc_attr( $key ); ?>-en">
									<?php
									/* translators: %s: which text */
									echo esc_html( sprintf( __( '%s (αγγλικά)', 'book-preview' ), $label ) );
									?>
								</label>
								<input type="text" id="bpv-<?php echo esc_attr( $key ); ?>-en" name="bpv_<?php echo esc_attr( $key ); ?>_en" value="<?php echo esc_attr( $s[ $key . '_en' ] ); ?>" placeholder="<?php echo esc_attr( BPV_Settings::TEXTS[ $key ][1] ); ?>" maxlength="<?php echo (int) BPV_Settings::MAX_TEXT; ?>" />
							</div>
						</div>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Κενό πεδίο = το προεπιλεγμένο κείμενο. Κενό αγγλικό κείμενο δείχνει το ελληνικό.', 'book-preview' ); ?></p>
				</div>

				<h2 class="bpv-h2"><?php esc_html_e( 'Δημιουργία δειγμάτων', 'book-preview' ); ?></h2>
				<div class="bpv-zone">
					<div class="bpv-grid">
						<div>
							<label class="bpv-label" for="bpv-first"><?php esc_html_e( 'Σελίδες όταν το πεδίο του προϊόντος είναι κενό', 'book-preview' ); ?></label>
							<input type="number" class="bpv-num" id="bpv-first" name="bpv_first_pages" min="1" max="200" value="<?php echo (int) $s['first_pages']; ?>" />
						</div>
						<div>
							<label class="bpv-label" for="bpv-skip"><?php esc_html_e( 'Παράλειψη στη μαζική δημιουργία (προεπιλογή)', 'book-preview' ); ?></label>
							<input type="number" class="bpv-num" id="bpv-skip" name="bpv_skip_pages" min="0" max="100" value="<?php echo (int) $s['skip_pages']; ?>" />
						</div>
						<div>
							<label class="bpv-label" for="bpv-warn-mb"><?php esc_html_e( 'Προειδοποίηση για δείγματα πάνω από (MB)', 'book-preview' ); ?></label>
							<input type="number" class="bpv-num" id="bpv-warn-mb" name="bpv_warn_mb" min="1" max="500" value="<?php echo (int) $s['warn_mb']; ?>" />
						</div>
					</div>
					<label class="bpv-check">
						<input type="checkbox" name="bpv_auto_regen" value="1" <?php checked( $s['auto_regen'] ); ?> />
						<?php esc_html_e( 'Αυτόματη αναδημιουργία στο παρασκήνιο όταν αλλάξει το PDF ή οι σελίδες ενός προϊόντος', 'book-preview' ); ?>
					</label>
					<label class="bpv-check">
						<input type="checkbox" name="bpv_external" value="1" <?php checked( $s['external'] ); ?> />
						<?php esc_html_e( 'Χρήση qpdf ή Ghostscript όταν η μηχανή του plugin δεν διαβάζει ένα PDF (μόνο αν υπάρχουν στον server)', 'book-preview' ); ?>
					</label>
				</div>

				<p class="bpv-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'book-preview' ); ?></button>
				</p>
			</form>

			<h2 class="bpv-h2"><?php esc_html_e( 'Έλεγχος server', 'book-preview' ); ?></h2>
			<div class="bpv-zone bpv-status">
				<?php self::diagnostics(); ?>
			</div>

			<h2 class="bpv-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'book-preview' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="bpv-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'bpv_backup_nonce' ); ?>
				<input type="file" name="bpv_import_file" accept=".json,application/json" />
				<button type="submit" name="bpv_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή (JSON)', 'book-preview' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&bpv_backup_export=1' ), self::NONCE_BAK ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή (JSON)', 'book-preview' ); ?>
			</a>
			<p class="description bpv-mt-8"><?php esc_html_e( 'Περιλαμβάνονται οι ρυθμίσεις. Τα δείγματα και οι επιλογές των προϊόντων μένουν στα προϊόντα.', 'book-preview' ); ?></p>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	private static function diagnostics(): void {
		$dir   = BPV_Samples::dir()['path'];
		$ok    = '<span class="bpv-tool-ok">✓</span> ';
		$no    = '<span class="bpv-muted">✗</span> ';
		$lines = array();

		$lines[] = $ok . esc_html__( 'Μηχανή του plugin (καθαρή PHP): διαβάζει PDF με κλασικό και με συμπιεσμένο cross-reference (PDF 1.5+).', 'book-preview' );

		$writable = is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( wp_get_upload_dir()['basedir'] );
		$lines[]  = ( $writable ? $ok : $no ) . esc_html__( 'Φάκελος δειγμάτων', 'book-preview' ) . ' <code>' . esc_html( str_replace( ABSPATH, '', $dir ) ) . '</code>';

		if ( ! BPV_Engine::can_exec() ) {
			$lines[] = $no . esc_html__( 'Ο server δεν επιτρέπει εξωτερικά προγράμματα (proc_open): qpdf και Ghostscript δεν θα χρησιμοποιηθούν.', 'book-preview' );
		} else {
			foreach ( array(
				'qpdf' => 'qpdf',
				'gs'   => 'Ghostscript',
			) as $tool => $label ) {
				$v       = BPV_Engine::tool_version( $tool );
				$lines[] = ( '' !== $v ? $ok . esc_html( $label ) . ' <code>' . esc_html( $v ) . '</code>' : $no . esc_html( $label ) . ' — ' . esc_html__( 'δεν βρέθηκε', 'book-preview' ) );
			}
		}
		/* translators: 1: memory limit, 2: time limit in seconds */
		$lines[] = esc_html( sprintf( __( 'Όριο μνήμης PHP: %1$s · Όριο χρόνου: %2$s s', 'book-preview' ), (string) ini_get( 'memory_limit' ), (string) ini_get( 'max_execution_time' ) ) );
		$lines[] = esc_html( function_exists( 'as_enqueue_async_action' ) ? __( 'Αναδημιουργία στο παρασκήνιο με το Action Scheduler του WooCommerce.', 'book-preview' ) : __( 'Αναδημιουργία στο παρασκήνιο με το WP-Cron.', 'book-preview' ) );

		foreach ( $lines as $l ) {
			echo '<p>' . $l . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {

		$page = self::current_page();
		if ( self::SLUG_MAIN !== $page && self::SLUG_SET !== $page ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['bpv_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-preview' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['bpv_action'] ) );
		if ( 'save_settings' === $action ) {
			$in = array();
			foreach ( array( 'enabled', 'test_mode', 'end_card', 'auto_regen', 'external' ) as $k ) {
				$in[ $k ] = '1' === self::post( 'bpv_' . $k );
			}
			foreach ( array( 'position', 'lang', 'first_pages', 'skip_pages', 'warn_mb' ) as $k ) {
				$in[ $k ] = self::post( 'bpv_' . $k );
			}
			foreach ( array_keys( BPV_Settings::TEXTS ) as $t ) {
				$in[ $t . '_el' ] = self::post( 'bpv_' . $t . '_el' );
				$in[ $t . '_en' ] = self::post( 'bpv_' . $t . '_en' );
			}
			BPV_Settings::save( $in );
			self::purge_all();
			$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'book-preview' ) ) );
		} else {
			$msgs = array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'book-preview' ) ) );
		}
		self::redirect( self::SLUG_SET === $page ? self::SLUG_SET : self::SLUG_MAIN, $msgs );
	}

	/** Purges the pages of every product with a sample (settings changed). */
	private static function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			return;
		}
		foreach ( self::products() as $id ) {
			if ( BPV_Samples::sample( $id ) ) {
				BPV_Samples::purge( $id );
			}
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $target, array $msgs ): void {
		set_transient( 'bpv_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $target ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/* =====================================================================
	 * Bulk creation (AJAX, batches with a progress bar, Bible §5)
	 * =================================================================== */

	private static function ajax_guard(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	public static function ajax_bulk_start(): void {
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_guard().
		$cat      = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$children = isset( $_POST['children'] ) && '1' === $_POST['children'];
		$n        = isset( $_POST['n'] ) ? max( 1, min( 200, absint( $_POST['n'] ) ) ) : 10;
		$skip     = isset( $_POST['skip'] ) ? max( 0, min( 100, absint( $_POST['skip'] ) ) ) : 0;
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : '';
		// phpcs:enable
		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'missing';

		$ids = array();
		foreach ( self::products( $cat, $children ) as $id ) {
			$st = BPV_Samples::state( $id );
			if ( 'missing' === $mode && $st['sample'] ) {
				continue;
			}
			if ( 'stale' === $mode && 'ready' === $st['state'] ) {
				continue;
			}
			$key = (string) get_post_meta( $id, '_bpv_source', true );
			if ( '' === $key && '' === BPV_Source::first_key( $id ) ) {
				continue; // No local PDF to use.
			}
			$ids[] = $id;
		}
		update_option(
			self::OPT_BULK,
			array(
				'ids'  => $ids,
				'pos'  => 0,
				'n'    => $n,
				'skip' => $skip,
				'ok'   => 0,
				'fail' => array(),
				'user' => get_current_user_id(),
				'time' => time(),
			),
			false
		);
		wp_send_json_success( array( 'total' => count( $ids ) ) );
	}

	public static function ajax_bulk_step(): void {
		self::ajax_guard();
		$state = get_option( self::OPT_BULK );
		if ( ! is_array( $state ) || ! isset( $state['ids'], $state['pos'] ) || (int) ( $state['user'] ?? 0 ) !== get_current_user_id() ) {
			wp_send_json_error( null, 400 );
		}
		$start = time();
		$done  = 0;
		$total = count( $state['ids'] );
		while ( $state['pos'] < $total && $done < self::BULK_STEP && time() - $start < self::BULK_SECS ) {
			$id = (int) $state['ids'][ $state['pos'] ];
			++$state['pos'];
			++$done;
			if ( 'product' !== get_post_type( $id ) ) {
				continue;
			}
			if ( '' === (string) get_post_meta( $id, '_bpv_source', true ) ) {
				$key = BPV_Source::first_key( $id );
				if ( '' === $key ) {
					continue;
				}
				update_post_meta( $id, '_bpv_source', $key );
			}
			if ( '' === trim( (string) get_post_meta( $id, '_bpv_pages', true ) ) ) {
				update_post_meta( $id, '_bpv_pages', BPV_Pages::first( (int) $state['n'], (int) $state['skip'] ) );
			}
			$res = BPV_Samples::generate( $id, true );
			if ( $res['ok'] ) {
				++$state['ok'];
			} elseif ( count( $state['fail'] ) < 200 ) {
				$state['fail'][] = $id;
			}
		}
		update_option( self::OPT_BULK, $state, false );

		$left = $total - (int) $state['pos'];
		if ( 0 === $left ) {
			/* translators: %d: number of products */
			$msgs = array( self::msg( 'success', sprintf( __( 'Δημιουργήθηκαν %d δείγματα.', 'book-preview' ), (int) $state['ok'] ) ) );
			if ( $state['fail'] ) {
				/* translators: %d: number of products */
				$msgs[] = self::msg( 'warning', sprintf( __( '%d προϊόντα απέτυχαν· η αιτία φαίνεται στη λίστα (κατάσταση «Σφάλμα»).', 'book-preview' ), count( $state['fail'] ) ) );
			}
			set_transient( 'bpv_aui_msg_' . get_current_user_id(), $msgs, 60 );
			delete_option( self::OPT_BULK );
		}
		wp_send_json_success(
			array(
				'done'  => (int) $state['pos'],
				'total' => $total,
				'left'  => $left,
			)
		);
	}

	/* =====================================================================
	 * Backup (export / import JSON, Bible §11)
	 * =================================================================== */

	public static function route_backup(): void {

		if ( self::SLUG_SET !== self::current_page() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.
		if ( isset( $_GET['bpv_backup_export'] ) ) {
			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-preview' ) );
			}
			$state = array(
				'plugin'  => 'book-preview',
				'version' => BPV_VERSION,
				'options' => array(
					BPV_Settings::OPT => BPV_Settings::get(),
				),
			);
			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="book-preview-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		if ( isset( $_POST['bpv_import'] ) ) {
			if ( ! isset( $_POST['bpv_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bpv_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-preview' ) );
			}

			$msgs    = array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'book-preview' ) ) );
			$up_err  = isset( $_FILES['bpv_import_file']['error'] ) ? (int) $_FILES['bpv_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['bpv_import_file']['size'] ) ? (int) $_FILES['bpv_import_file']['size'] : 0;

			if ( UPLOAD_ERR_OK === $up_err && $up_size > 0 && $up_size <= self::MAX_IMPORT
				&& ! empty( $_FILES['bpv_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['bpv_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp path from PHP.

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['bpv_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );
				if ( ! is_array( $state ) ) {
					$msgs = array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'book-preview' ) ) );
				} elseif ( ( $state['plugin'] ?? '' ) !== 'book-preview' || ! isset( $state['options'][ BPV_Settings::OPT ] ) ) {
					$msgs = array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν τα δεδομένα του Book Preview).', 'book-preview' ) ) );
				} elseif ( ! is_array( $state['options'][ BPV_Settings::OPT ] ) ) {
					$msgs = array( self::msg( 'error', __( 'Οι ρυθμίσεις του αρχείου δεν ήταν έγκυρες και δεν άλλαξαν.', 'book-preview' ) ) );
				} else {
					BPV_Settings::save( $state['options'][ BPV_Settings::OPT ] );
					self::purge_all();
					$msgs = array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'book-preview' ) ) );
				}
			} elseif ( $up_size > self::MAX_IMPORT ) {
				$msgs = array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'book-preview' ) ) );
			}
			self::redirect( self::SLUG_SET, $msgs );
		}
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'bpv_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'bpv_aui_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	private static function print_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			if ( ! is_array( $n ) || ! isset( $n['type'], $n['text'] ) ) {
				continue;
			}
			$type = in_array( $n['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible inline"><p>' . esc_html( (string) $n['text'] ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="bpv-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'book-preview' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="bpv-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="bpv-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'book-preview' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="bpv-footer-cta">
			<a class="bpv-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'book-preview' ); ?>
			</a>
			<a class="bpv-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'book-preview' ); ?>
			</a>
		</p>
		<?php
	}
}
