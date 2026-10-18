<?php
/**
 * POR_Admin_UI — menu, plugin page, PRG routes, CSV, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Προπαραγγελίες» of the shared "Noxpress" menu
 * (admin_menu priority 180), slug por-settings, with tabs:
 *  - upcoming  the products on pre-order: format (Product Formats, when
 *              active), release date, open orders, units, revenue, a link
 *              to the orders list filtered for the product, and the CSV
 *              shipping list (Bible §5 recipe)
 *  - settings  mode, badge, mixed cart, date format, the two emails
 *  - texts     visitor text language and texts
 *  - tools     settings export / import, catalog data rebuild, shortcode
 *              and block, filters and API
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (POR_Settings::sanitize), PRG with the message in the
 * transient por_aui_msg_{uid}. Export and CSV: privileged GET (slug +
 * nonce + capability). The counts are computed here, in the admin, never
 * on a page view (Bible §14.10).
 */

defined( 'ABSPATH' ) || exit;

final class POR_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'por-settings';
	const NONCE       = 'por_admin';
	const NONCE_FIELD = 'por_nonce';
	const NONCE_BAK   = 'por_backup';
	const NONCE_CSV   = 'por_csv';
	const CSV_LAST    = 'por_csv_format';
	const MAX_IMPORT  = 262144;
	const MAX_ORDERS  = 1000;
	const MAX_ROWS    = 200;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 180 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'rival_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( POR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'upcoming' => __( 'Επερχόμενες κυκλοφορίες', 'pre-orders' ),
			'settings' => __( 'Ρυθμίσεις', 'pre-orders' ),
			'texts'    => __( 'Κείμενα', 'pre-orders' ),
			'tools'    => __( 'Εργαλεία', 'pre-orders' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Pre-Orders', 'pre-orders' ),
			__( 'Προπαραγγελίες', 'pre-orders' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'pre-orders' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only routing). */
	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'upcoming';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'por-admin', POR_URL . 'assets/admin.css', array(), POR_VERSION );
	}

	/**
	 * Another pre-order plugin is active: say so on the plugins screen, the
	 * products list and our page (Bible §14.23: we stay in test mode).
	 */
	public static function rival_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rival = POR_Settings::rival();
		if ( '' === $rival || 'live' !== POR_Settings::get()['mode'] ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ok     = self::SLUG === self::current_page() || ( $screen && in_array( $screen->id, array( 'plugins', 'edit-product' ), true ) );
		if ( ! $ok ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: plugin file of the other pre-order plugin */
				__( 'Pre-Orders: ένα άλλο plugin προπαραγγελιών είναι ενεργό (%s). Το Pre-Orders μένει σε λειτουργία δοκιμής μέχρι να το απενεργοποιήσεις, ώστε να μη δουλεύουν δύο μαζί.', 'pre-orders' ),
				$rival
			)
		) . '</p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'pre-orders' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap por-wrap">
			<h1><?php esc_html_e( 'Pre-Orders', 'pre-orders' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Προπαραγγελίες για προϊόντα που δεν έχουν κυκλοφορήσει ακόμα. Τα προϊόντα σημειώνονται στη σελίδα επεξεργασίας τους ή μαζικά από τη λίστα προϊόντων.', 'pre-orders' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper por-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'settings':
					self::render_settings();
					break;
				case 'texts':
					self::render_texts();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_upcoming();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** One line on the mode in effect, shown on top of the tabs that need it. */
	private static function mode_line(): void {
		if ( Pre_Orders::killed() ) {
			echo '<p class="por-warn">' . esc_html__( 'Το POR_DISABLE είναι ενεργό στο wp-config.php: οι επισκέπτες δεν βλέπουν προπαραγγελίες και τα προϊόντα πωλούνται ως κανονικά.', 'pre-orders' ) . '</p>';
			return;
		}
		$s = POR_Settings::get();
		if ( 'off' === $s['mode'] ) {
			echo '<p class="por-warn">' . esc_html__( 'Η λειτουργία είναι ανενεργή: οι επισκέπτες δεν βλέπουν προπαραγγελίες. Άλλαξέ τη στην καρτέλα Ρυθμίσεις.', 'pre-orders' ) . '</p>';
		} elseif ( 'test' === POR_Settings::mode() ) {
			echo '<p class="por-warn">' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος βλέπουν τις προπαραγγελίες.', 'pre-orders' ) . '</p>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Tab: upcoming
	 * ------------------------------------------------------------------- */

	/**
	 * The products and variations on pre-order, nearest release first.
	 *
	 * @return array[] { id, parent, name, date, edit }
	 */
	private static function upcoming_items(): array {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'   => self::MAX_ROWS,
				'fields'           => 'ids',
				'orderby'          => 'meta_value',
				'meta_key'         => POR_Product::M_SORT, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin page.
				'order'            => 'ASC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- admin page.
					array(
						'key'     => POR_Product::M_SORT,
						'value'   => POR_Settings::today(),
						'compare' => '>',
					),
				),
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$items = array();
		foreach ( $ids as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p instanceof WC_Product ) {
				continue;
			}
			$list = $p->is_type( 'variable' ) ? $p->get_children() : array( (int) $pid );
			foreach ( $list as $id ) {
				$x = (int) $id === (int) $pid ? $p : wc_get_product( (int) $id );
				$a = $x instanceof WC_Product ? POR_Product::active( $x ) : null;
				if ( null === $a ) {
					continue;
				}
				$items[] = array(
					'id'     => (int) $id,
					'parent' => (int) $pid,
					'name'   => wp_strip_all_tags( $x->get_name() ),
					'date'   => $a['date'],
					'edit'   => (string) get_edit_post_link( (int) $pid, 'raw' ),
				);
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				$da = '' !== $a['date'] ? $a['date'] : POR_Product::TBA;
				$db = '' !== $b['date'] ? $b['date'] : POR_Product::TBA;
				return array( $da, $a['name'] ) <=> array( $db, $b['name'] );
			}
		);
		return $items;
	}

	/**
	 * The open order lines waiting for a product (or variation).
	 *
	 * @return array[] { order, item }
	 */
	private static function open_lines( int $id ): array {
		$orders = POR_Orders::find(
			array(
				'status'  => POR_Orders::OPEN,
				'type'    => 'shop_order',
				'limit'   => self::MAX_ORDERS,
				'orderby' => 'date',
				'order'   => 'ASC',
			),
			$id
		);
		$out = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			foreach ( POR_Orders::lines( $order, $id ) as $item ) {
				if ( POR_Orders::line_product( $item ) !== $id || '' !== (string) $item->get_meta( POR_Orders::I_RELEASED, true ) ) {
					continue;
				}
				$out[] = array(
					'order' => $order,
					'item'  => $item,
				);
			}
		}
		return $out;
	}

	/** Format label from Product Formats, '' without it. */
	private static function format_of( int $parent ): string {
		if ( ! function_exists( 'pfm_get_work' ) ) {
			return '';
		}
		$work = pfm_get_work( $parent );
		if ( ! is_array( $work ) || empty( $work['members'] ) ) {
			return '';
		}
		foreach ( (array) $work['members'] as $m ) {
			if ( is_array( $m ) && isset( $m['id'], $m['format'] ) && (int) $m['id'] === $parent && '' !== (string) $m['format'] ) {
				$label = class_exists( 'PFM_Settings' ) && method_exists( 'PFM_Settings', 'label' ) ? (string) PFM_Settings::label( (string) $m['format'] ) : '';
				return '' !== $label ? $label : (string) $m['format'];
			}
		}
		return '';
	}

	/** The orders list filtered for a product (HPOS or legacy screen). */
	private static function orders_url( int $id ): string {
		$base = POR_Orders::hpos() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order';
		return admin_url( $base . '&por_filter=all&por_product=' . $id );
	}

	private static function render_upcoming(): void {
		self::mode_line();
		$items = self::upcoming_items();
		$fmt   = self::csv_format();
		?>
		<h2 class="por-h2"><?php esc_html_e( 'Επερχόμενες κυκλοφορίες', 'pre-orders' ); ?></h2>
		<?php if ( ! $items ) : ?>
			<div class="por-zone">
				<p><?php esc_html_e( 'Κανένα προϊόν δεν είναι σε προπαραγγελία. Άνοιξε ένα προϊόν, σημείωσε «Προπαραγγελία» στα Γενικά (ή σε μια παραλλαγή) και δώσε ημερομηνία κυκλοφορίας.', 'pre-orders' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Προϊόντα', 'pre-orders' ); ?></a></p>
			</div>
			<?php
			return;
		endif;
		?>
		<table class="widefat por-table">
			<thead><tr>
				<th><?php esc_html_e( 'Προϊόν', 'pre-orders' ); ?></th>
				<th><?php esc_html_e( 'Μορφή', 'pre-orders' ); ?></th>
				<th><?php esc_html_e( 'Κυκλοφορία', 'pre-orders' ); ?></th>
				<th class="por-num-col"><?php esc_html_e( 'Παραγγελίες', 'pre-orders' ); ?></th>
				<th class="por-num-col"><?php esc_html_e( 'Τεμάχια', 'pre-orders' ); ?></th>
				<th class="por-num-col"><?php esc_html_e( 'Έσοδα', 'pre-orders' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			$capped = false;
			foreach ( $items as $it ) {
				$lines  = self::open_lines( $it['id'] );
				$orders = array();
				$qty    = 0;
				$money  = 0.0;
				foreach ( $lines as $l ) {
					$orders[ $l['order']->get_id() ] = true;
					$qty   += (int) $l['item']->get_quantity();
					$money += (float) $l['item']->get_total() + (float) $l['item']->get_total_tax();
				}
				if ( count( $orders ) >= self::MAX_ORDERS ) {
					$capped = true;
				}
				$date = '' !== $it['date'] ? POR_Settings::format_date( $it['date'] ) : __( 'χωρίς ημερομηνία', 'pre-orders' );
				$csv  = wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&por_csv=' . $it['id'] . '&por_fmt=' . $fmt ), self::NONCE_CSV );
				?>
				<tr>
					<td><a href="<?php echo esc_url( $it['edit'] ); ?>"><?php echo esc_html( $it['name'] ); ?></a></td>
					<td><?php echo esc_html( self::format_of( $it['parent'] ) ); ?></td>
					<td><?php echo esc_html( $date ); ?></td>
					<td class="por-num-col"><a href="<?php echo esc_url( self::orders_url( $it['id'] ) ); ?>"><?php echo (int) count( $orders ); ?></a></td>
					<td class="por-num-col"><?php echo (int) $qty; ?></td>
					<td class="por-num-col"><?php echo wp_kses_post( wc_price( $money ) ); ?></td>
					<td><?php if ( $lines ) : ?><a href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'CSV', 'pre-orders' ); ?></a><?php endif; ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<p class="description por-mt-8"><?php esc_html_e( 'Μετρούν οι παραγγελίες «Σε επεξεργασία» και «Σε αναμονή». Τα έσοδα είναι οι γραμμές του προϊόντος με ΦΠΑ, χωρίς μεταφορικά.', 'pre-orders' ); ?></p>
		<?php if ( $capped ) : ?>
			<p class="por-warn"><?php esc_html_e( 'Μετρήθηκαν οι πρώτες 1000 παραγγελίες ανά προϊόν.', 'pre-orders' ); ?></p>
		<?php endif; ?>
		<?php if ( count( $items ) >= self::MAX_ROWS ) : ?>
			<p class="por-warn"><?php esc_html_e( 'Φαίνονται τα πρώτα 200 προϊόντα.', 'pre-orders' ); ?></p>
		<?php endif; ?>

		<h2 class="por-h2"><?php esc_html_e( 'Λίστα αποστολής (CSV)', 'pre-orders' ); ?></h2>
		<div class="por-zone">
			<form method="get" class="por-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="por_csv" value="all" />
				<?php wp_nonce_field( self::NONCE_CSV, '_wpnonce', false ); ?>
				<label class="por-inline"><?php esc_html_e( 'Μορφή', 'pre-orders' ); ?>
					<select name="por_fmt">
						<option value="el" <?php selected( $fmt, 'el' ); ?>><?php esc_html_e( 'Excel ελληνικά (; και δεκαδικό κόμμα)', 'pre-orders' ); ?></option>
						<option value="std" <?php selected( $fmt, 'std' ); ?>><?php esc_html_e( 'Τυπικό (, και δεκαδική τελεία)', 'pre-orders' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Λήψη CSV για όλα', 'pre-orders' ); ?></button>
			</form>
			<p class="description por-mt-8"><?php esc_html_e( 'Μία γραμμή ανά γραμμή παραγγελίας που περιμένει: παραγγελία, ημερομηνία, κατάσταση, πελάτης, email, τηλέφωνο, διεύθυνση αποστολής, προϊόν, τεμάχια, ποσό, κυκλοφορία. Ο σύνδεσμος CSV σε κάθε γραμμή του πίνακα δίνει μόνο εκείνο το προϊόν.', 'pre-orders' ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: settings
	 * ------------------------------------------------------------------- */

	/** Radio buttons. */
	private static function radios( string $name, string $value, array $opts ): void {
		foreach ( $opts as $k => $label ) {
			echo '<label class="por-check"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
	}

	private static function render_settings(): void {
		$s = POR_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="por_action" value="save_settings" />

			<h2 class="por-h2"><?php esc_html_e( 'Λειτουργία', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<?php
				if ( Pre_Orders::killed() ) {
					echo '<p class="por-warn">' . esc_html__( 'Το POR_DISABLE είναι ενεργό στο wp-config.php: οι επισκέπτες δεν βλέπουν προπαραγγελίες και τα προϊόντα πωλούνται ως κανονικά.', 'pre-orders' ) . '</p>';
				}
				self::radios(
					'por_mode',
					$s['mode'],
					array(
						'off'  => __( 'Ανενεργό', 'pre-orders' ),
						'test' => __( 'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος', 'pre-orders' ),
						'live' => __( 'Ενεργό για όλους', 'pre-orders' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Όσο είναι ανενεργό, τα προϊόντα σε προπαραγγελία πωλούνται ως κανονικά προϊόντα. Η ημέρα κυκλοφορίας και οι εργασίες των παραγγελιών τρέχουν πάντα.', 'pre-orders' ); ?></p>
				<p class="description"><?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'pre-orders' ); ?> <code>define( 'POR_DISABLE', true );</code></p>
			</div>

			<h2 class="por-h2"><?php esc_html_e( 'Σήμα «Προπαραγγελία»', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<?php
				self::radios(
					'por_badge',
					$s['badge'],
					array(
						'both'   => __( 'Στις κάρτες προϊόντων και στη σελίδα προϊόντος', 'pre-orders' ),
						'loop'   => __( 'Μόνο στις κάρτες προϊόντων', 'pre-orders' ),
						'single' => __( 'Μόνο στη σελίδα προϊόντος', 'pre-orders' ),
						'none'   => __( 'Πουθενά (μένουν το κουμπί και η ημερομηνία)', 'pre-orders' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Το χρώμα αλλάζει από το θέμα με τις μεταβλητές CSS --por-badge-bg και --por-badge-fg.', 'pre-orders' ); ?></p>
			</div>

			<h2 class="por-h2"><?php esc_html_e( 'Καλάθι με προπαραγγελίες και διαθέσιμα προϊόντα', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<?php
				self::radios(
					'por_mixed',
					$s['mixed'],
					array(
						'allow' => __( 'Επιτρέπεται, με σημείωση στο καλάθι και στο ταμείο', 'pre-orders' ),
						'warn'  => __( 'Επιτρέπεται, με προειδοποίηση που προτείνει δύο παραγγελίες', 'pre-orders' ),
						'block' => __( 'Δεν επιτρέπεται: οι προπαραγγελίες αγοράζονται σε ξεχωριστή παραγγελία', 'pre-orders' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Η σημείωση και η προειδοποίηση φαίνονται στο κλασικό καλάθι και ταμείο. Στα blocks Καλάθι και Ταμείο κάθε προϊόν σε προπαραγγελία δείχνει την ημερομηνία του, και ο κανόνας «Δεν επιτρέπεται» ισχύει παντού.', 'pre-orders' ); ?></p>
			</div>

			<h2 class="por-h2"><?php esc_html_e( 'Μορφή ημερομηνίας', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<?php
				$sample = POR_Settings::today();
				self::radios(
					'por_date_fmt',
					$s['date_fmt'],
					array(
						'store'  => sprintf(
							/* translators: %s: example date */
							__( 'Του καταστήματος (Ρυθμίσεις › Γενικά), π.χ. %s', 'pre-orders' ),
							wp_date( (string) get_option( 'date_format', 'Y-m-d' ), POR_Settings::timestamp( $sample ) )
						),
						'custom' => __( 'Δική μου', 'pre-orders' ),
					)
				);
				?>
				<p class="por-field"><span><?php esc_html_e( 'Δική μου μορφή (PHP)', 'pre-orders' ); ?></span>
					<input type="text" name="por_date_custom" value="<?php echo esc_attr( $s['date_custom'] ); ?>" maxlength="30" class="por-short" />
					<span class="description"><?php echo esc_html( wp_date( $s['date_custom'], POR_Settings::timestamp( $sample ) ) ); ?></span>
				</p>
			</div>

			<h2 class="por-h2"><?php esc_html_e( 'Emails στους πελάτες', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<p class="description"><?php esc_html_e( 'Δύο emails του WooCommerce, κλειστά μέχρι να τα ανοίξεις. Θέμα, επικεφαλίδα και πρόσθετο κείμενο ρυθμίζονται εκεί.', 'pre-orders' ); ?></p>
				<table class="widefat por-table">
					<tbody>
					<?php
					foreach ( array(
						'POR_Email_Released' => array( 'por_email_released', __( '«Κυκλοφόρησε»: την ημέρα κυκλοφορίας', 'pre-orders' ) ),
						'POR_Email_New_Date' => array( 'por_email_new_date', __( '«Νέα ημερομηνία κυκλοφορίας»: όταν αλλάζεις την ημερομηνία και ζητάς ενημέρωση', 'pre-orders' ) ),
					) as $class => $row ) {
						$on = POR_Emails::enabled( $class );
						echo '<tr><td>' . esc_html( $row[1] ) . '</td><td>'
							. ( $on ? '<span class="por-badge por-badge--ok">' . esc_html__( 'ενεργό', 'pre-orders' ) . '</span>' : '<span class="por-badge por-badge--muted">' . esc_html__( 'ανενεργό', 'pre-orders' ) . '</span>' )
							. '</td><td><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . $row[0] ) ) . '">' . esc_html__( 'Ρυθμίσεις email', 'pre-orders' ) . '</a></td></tr>';
					}
					?>
					</tbody>
				</table>
			</div>

			<p class="por-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'pre-orders' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: texts
	 * ------------------------------------------------------------------- */

	private static function render_texts(): void {
		$s    = POR_Settings::get();
		$rows = array(
			't_badge'       => __( 'Σήμα στις κάρτες και στη σελίδα προϊόντος', 'pre-orders' ),
			't_button'      => __( 'Κουμπί αντί για «Προσθήκη στο καλάθι»', 'pre-orders' ),
			't_date'        => __( 'Διαθεσιμότητα με ημερομηνία ({date} = η ημερομηνία)', 'pre-orders' ),
			't_tba'         => __( 'Διαθεσιμότητα χωρίς ημερομηνία', 'pre-orders' ),
			't_line'        => __( 'Ετικέτα στη γραμμή του καλαθιού και της παραγγελίας', 'pre-orders' ),
			't_mixed_allow' => __( 'Μικτό καλάθι: σημείωση («Επιτρέπεται»)', 'pre-orders' ),
			't_mixed_warn'  => __( 'Μικτό καλάθι: προειδοποίηση', 'pre-orders' ),
			't_mixed_block' => __( 'Μικτό καλάθι: σφάλμα («Δεν επιτρέπεται»)', 'pre-orders' ),
		);
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="por_action" value="save_texts" />
			<h2 class="por-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<p class="por-field"><span><?php esc_html_e( 'Γλώσσα κειμένων', 'pre-orders' ); ?></span><select name="por_lang">
				<?php
				foreach ( array(
					'auto' => __( 'Αυτόματα (γλώσσα του site)', 'pre-orders' ),
					'el'   => __( 'Ελληνικά', 'pre-orders' ),
					'en'   => __( 'English', 'pre-orders' ),
				) as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['lang'], $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				?>
				</select></p>
				<p class="description"><?php esc_html_e( 'Η ίδια γλώσσα ισχύει και για τα δύο emails. Κάθε προϊόν μπορεί να έχει και δικό του κείμενο διαθεσιμότητας στη σελίδα επεξεργασίας του.', 'pre-orders' ); ?></p>
				<table class="widefat por-table por-texts"><thead><tr><th><?php esc_html_e( 'Κείμενο', 'pre-orders' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'pre-orders' ); ?></th><th><?php esc_html_e( 'English', 'pre-orders' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $rows as $key => $label ) {
					$len  = (int) POR_Settings::TEXTS[ $key ];
					$long = $len > 120;
					echo '<tr><td>' . esc_html( $label ) . '</td>';
					foreach ( array( 'el', 'en' ) as $l ) {
						$name = 'por_t[' . $key . '][' . $l . ']';
						if ( $long ) {
							echo '<td><textarea rows="3" name="' . esc_attr( $name ) . '" maxlength="' . (int) $len . '">' . esc_textarea( $s[ $key ][ $l ] ) . '</textarea></td>';
						} else {
							echo '<td><input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) $len . '" /></td>';
						}
					}
					echo '</tr>';
				}
				?>
				</tbody></table>
				<p class="description por-mt-8"><?php esc_html_e( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό.', 'pre-orders' ); ?></p>
			</div>
			<p class="por-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'pre-orders' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$rival = POR_Settings::rival();
		?>
		<?php if ( '' !== $rival ) : ?>
			<h2 class="por-h2"><?php esc_html_e( 'Άλλο plugin προπαραγγελιών', 'pre-orders' ); ?></h2>
			<div class="por-zone">
				<p class="por-warn"><?php echo esc_html( $rival ); ?></p>
				<p class="description"><?php esc_html_e( 'Όσο είναι ενεργό, το Pre-Orders μένει σε λειτουργία δοκιμής, ακόμα κι αν το έχεις βάλει «Ενεργό για όλους». Σημείωσε τα προϊόντα εδώ, δοκίμασέ τα ως διαχειριστής, και μετά απενεργοποίησε το άλλο plugin. Τα δεδομένα του άλλου plugin δεν αλλάζουν.', 'pre-orders' ); ?></p>
			</div>
		<?php endif; ?>

		<h2 class="por-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'pre-orders' ); ?></h2>
		<div class="por-zone">
			<form method="post" enctype="multipart/form-data" class="por-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'por_backup_nonce' ); ?>
				<input type="file" name="por_import_file" accept=".json,application/json" />
				<button type="submit" name="por_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'pre-orders' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&por_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'pre-orders' ); ?></a>
			<p class="description por-mt-8"><?php esc_html_e( 'Το backup έχει τις ρυθμίσεις και τα κείμενα. Οι ημερομηνίες των προϊόντων μένουν στα προϊόντα.', 'pre-orders' ); ?></p>
		</div>

		<h2 class="por-h2"><?php esc_html_e( 'Δεδομένα καταλόγου', 'pre-orders' ); ?></h2>
		<div class="por-zone">
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="por_action" value="resync" />
				<button type="submit" class="button"><?php esc_html_e( 'Ξαναχτίσιμο στο παρασκήνιο', 'pre-orders' ); ?></button>
			</form>
			<p class="description por-mt-8"><?php esc_html_e( 'Ξαναγράφει τη σειρά ταξινόμησης, τις εργασίες της ημέρας κυκλοφορίας και το αν το κατάστημα έχει προπαραγγελίες, για όλα τα προϊόντα. Χρειάζεται μόνο αν άλλαξες προϊόντα με εισαγωγή CSV ή απευθείας στη βάση.', 'pre-orders' ); ?></p>
		</div>

		<h2 class="por-h2"><?php esc_html_e( 'Λίστα «Έρχονται σύντομα»', 'pre-orders' ); ?></h2>
		<div class="por-zone">
			<p><code>[nox_preorders limit="12" columns="4"]</code> <?php esc_html_e( 'ή το block «Προπαραγγελίες» στον επεξεργαστή: τα προϊόντα σε προπαραγγελία με τις κάρτες του θέματος, πρώτα όσα κυκλοφορούν νωρίτερα. Όσο η λειτουργία είναι ανενεργή δεν τυπώνει τίποτα, και στη λειτουργία δοκιμής φαίνεται μόνο στους διαχειριστές.', 'pre-orders' ); ?></p>
		</div>

		<h2 class="por-h2"><?php esc_html_e( 'Για προγραμματιστές', 'pre-orders' ); ?></h2>
		<div class="por-zone">
			<table class="widefat por-table">
				<tbody>
					<tr><td><code>por_get_release( $product )</code></td><td><?php esc_html_e( 'Η προπαραγγελία ενός προϊόντος (active, date, text, και variations για τα μεταβλητά), ή null.', 'pre-orders' ); ?></td></tr>
					<tr><td><code>por_is_preorder( $on, $product )</code></td><td><?php esc_html_e( 'Φίλτρο: αν ένα απλό προϊόν ή μια παραλλαγή είναι τώρα σε προπαραγγελία (false = πουλιέται κανονικά).', 'pre-orders' ); ?></td></tr>
					<tr><td><code>por_text</code></td><td><?php esc_html_e( 'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).', 'pre-orders' ); ?></td></tr>
					<tr><td><code>por_released( $product_id, $date )</code></td><td><?php esc_html_e( 'Action την ημέρα κυκλοφορίας, αφού το προϊόν έγινε κανονικό.', 'pre-orders' ); ?></td></tr>
					<tr><td><code>shn_available_from</code></td><td><?php esc_html_e( 'Το Pre-Orders δίνει την ημερομηνία κυκλοφορίας στο Shipping Notes, ώστε ο χρόνος παράδοσης να μετρά από την κυκλοφορία.', 'pre-orders' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		// Import (own nonce, multipart form).
		if ( isset( $_POST['por_import'] ) ) {
			if ( ! isset( $_POST['por_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['por_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'pre-orders' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['por_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'pre-orders' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['por_action'] ) ) ) {
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'save_texts':
				self::redirect( 'texts', self::do_save_texts() );
				break;
			case 'resync':
				self::redirect( 'tools', self::do_resync() );
				break;
			default:
				self::redirect( 'upcoming', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'pre-orders' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'por_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by POR_Settings::sanitize()). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by POR_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save_settings(): array {
		$raw                = POR_Settings::get();
		$raw['mode']        = sanitize_key( self::post( 'por_mode' ) );
		$raw['badge']       = sanitize_key( self::post( 'por_badge' ) );
		$raw['mixed']       = sanitize_key( self::post( 'por_mixed' ) );
		$raw['date_fmt']    = sanitize_key( self::post( 'por_date_fmt' ) );
		$raw['date_custom'] = self::post( 'por_date_custom' );
		POR_Settings::save( $raw );

		$s    = POR_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'pre-orders' ) ) );
		if ( 'live' === $s['mode'] && '' !== POR_Settings::rival() ) {
			$msgs[] = self::msg( 'warning', __( 'Ένα άλλο plugin προπαραγγελιών είναι ενεργό: το Pre-Orders μένει σε λειτουργία δοκιμής μέχρι να το απενεργοποιήσεις.', 'pre-orders' ) );
		}
		if ( 'custom' === $s['date_fmt'] && '' === trim( wp_date( $s['date_custom'], time() ) ) ) {
			$msgs[] = self::msg( 'warning', __( 'Η δική σου μορφή ημερομηνίας δεν βγάζει κείμενο.', 'pre-orders' ) );
		}
		return $msgs;
	}

	private static function do_save_texts(): array {
		$raw         = POR_Settings::get();
		$raw['lang'] = sanitize_key( self::post( 'por_lang' ) );
		$t           = self::post_array( 'por_t' );
		foreach ( array_keys( POR_Settings::TEXTS ) as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$raw[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		POR_Settings::save( $raw );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'pre-orders' ) ) );
	}

	private static function do_resync(): array {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return array( self::msg( 'error', __( 'Το Action Scheduler του WooCommerce δεν είναι διαθέσιμο.', 'pre-orders' ) ) );
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'por_resync', null, POR_Product::GROUP ) ) {
			as_enqueue_async_action( 'por_resync', array( 0 ), POR_Product::GROUP );
		}
		return array( self::msg( 'success', __( 'Το ξαναχτίσιμο ξεκίνησε στο παρασκήνιο.', 'pre-orders' ) ) );
	}

	/* =====================================================================
	 * CSV shipping list (Bible §5 recipe)
	 * =================================================================== */

	/** The CSV format: last choice of the user, else by admin language. */
	private static function csv_format(): string {
		$last = (string) get_user_option( self::CSV_LAST );
		if ( 'el' === $last || 'std' === $last ) {
			return $last;
		}
		return 'en' === POR_Lang::lang() ? 'std' : 'el';
	}

	/** admin_init: ?page=por-settings&por_csv=all|ID&por_fmt=el|std + nonce + capability. */
	public static function route_csv(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'por_csv' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_CSV ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'pre-orders' ) );
		}
		$fmt = 'std' === self::q( 'por_fmt' ) ? 'std' : 'el';
		update_user_option( get_current_user_id(), self::CSV_LAST, $fmt );

		$what  = self::q( 'por_csv' );
		$items = self::upcoming_items();
		if ( 'all' !== $what ) {
			$id    = (int) $what;
			$items = array_values(
				array_filter(
					$items,
					static function ( $it ) use ( $id ) {
						return $it['id'] === $id;
					}
				)
			);
		}

		$sep  = 'el' === $fmt ? ';' : ',';
		$dec  = 'el' === $fmt ? ',' : '.';
		$name = 'pre-orders-' . ( 'all' === $what ? 'all' : (int) $what ) . '-' . POR_Settings::today() . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- output stream.
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		fputcsv(
			$fh,
			array(
				__( 'Παραγγελία', 'pre-orders' ),
				__( 'Ημερομηνία', 'pre-orders' ),
				__( 'Κατάσταση', 'pre-orders' ),
				__( 'Πελάτης', 'pre-orders' ),
				__( 'Email', 'pre-orders' ),
				__( 'Τηλέφωνο', 'pre-orders' ),
				__( 'Διεύθυνση αποστολής', 'pre-orders' ),
				__( 'Προϊόν', 'pre-orders' ),
				__( 'SKU', 'pre-orders' ),
				__( 'Τεμάχια', 'pre-orders' ),
				__( 'Ποσό', 'pre-orders' ),
				__( 'Κυκλοφορία', 'pre-orders' ),
			),
			$sep
		);
		foreach ( $items as $it ) {
			$p   = wc_get_product( $it['id'] );
			$sku = $p instanceof WC_Product ? (string) $p->get_sku() : '';
			foreach ( self::open_lines( $it['id'] ) as $l ) {
				$o    = $l['order'];
				$item = $l['item'];
				$addr = $o->get_formatted_shipping_address();
				$addr = '' !== (string) $addr ? $addr : $o->get_formatted_billing_address();
				$made = $o->get_date_created();
				fputcsv(
					$fh,
					array(
						self::csv_text( (string) $o->get_order_number() ),
						$made ? $made->date_i18n( 'Y-m-d' ) : '',
						self::csv_text( wc_get_order_status_name( $o->get_status() ) ),
						self::csv_text( trim( $o->get_formatted_billing_full_name() ) ),
						self::csv_text( (string) $o->get_billing_email() ),
						self::csv_text( (string) $o->get_billing_phone() ),
						self::csv_text( trim( wp_strip_all_tags( str_replace( array( '<br/>', '<br>', '<br />' ), ', ', (string) $addr ) ) ) ),
						self::csv_text( wp_strip_all_tags( $item->get_name() ) ),
						self::csv_text( $sku ),
						(string) (int) $item->get_quantity(),
						number_format( (float) $item->get_total() + (float) $item->get_total_tax(), 2, $dec, '' ),
						'' !== $it['date'] ? $it['date'] : self::csv_text( __( 'χωρίς ημερομηνία', 'pre-orders' ) ),
					),
					$sep
				);
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- output stream.
		exit;
	}

	/** Formula-injection guard (same rule as RS_Admin_UI::csv_cell, Bible §5). */
	private static function csv_text( string $cell ): string {
		if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'por_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'pre-orders' ) );
		}
		$state = array(
			'plugin'  => 'pre-orders',
			'version' => POR_VERSION,
			'options' => array(
				POR_Settings::OPT => POR_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="pre-orders-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob must be an object; it goes through
	 * POR_Settings::sanitize() (the same validation as the forms). An invalid
	 * file leaves the current settings untouched and says so.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['por_import_file']['error'] ) ? (int) $_FILES['por_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['por_import_file']['size'] ) ? (int) $_FILES['por_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'pre-orders' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['por_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['por_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'pre-orders' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['por_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'pre-orders' ) ) );
		}
		$blob = isset( $state['options'][ POR_Settings::OPT ] ) ? $state['options'][ POR_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Pre-Orders).', 'pre-orders' ) ) );
		}
		POR_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'pre-orders' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'por_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'por_aui_msg_' . get_current_user_id() );
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
		<p class="por-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'pre-orders' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="por-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="por-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'pre-orders' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="por-footer-cta">
			<a class="por-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'pre-orders' ); ?>
			</a>
			<a class="por-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'pre-orders' ); ?>
			</a>
		</p>
		<?php
	}
}
