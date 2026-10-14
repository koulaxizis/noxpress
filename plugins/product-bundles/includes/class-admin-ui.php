<?php
/**
 * PBN_Admin_UI — menu, pages, PRG routes, backup (Bible §3–§8, §11).
 *
 * Two submenus of the shared "Noxpress" menu (admin_menu priority 160):
 *  - «Πακέτα» (pbn-bundles): every bundle with price, availability,
 *    sales and problems, "Rebuild all"; tab «Μετατροπή»: an existing
 *    product becomes a bundle in place, with backup and undo.
 *  - «PB Ρυθμίσεις» (pbn-settings): general (mode, discount, coupons,
 *    product page), visitor texts, tools (backup, uninstall choice,
 *    developers).
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation, PRG with the message in the transient
 * pbn_aui_msg_{uid}. Export: privileged GET (slug + nonce + capability).
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'pbn-settings';
	const SLUG_LIST   = 'pbn-bundles';
	const NONCE       = 'pbn_admin';
	const NONCE_FIELD = 'pbn_nonce';
	const NONCE_BAK   = 'pbn_backup';
	const MAX_IMPORT  = 1048576;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 160 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'others_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PBN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Settings tabs: slug => label. */
	private static function tabs(): array {
		return array(
			'general' => __( 'Γενικά', 'product-bundles' ),
			'texts'   => __( 'Κείμενα', 'product-bundles' ),
			'tools'   => __( 'Εργαλεία', 'product-bundles' ),
		);
	}

	/** Bundles page tabs. */
	private static function list_tabs(): array {
		return array(
			'list'    => __( 'Πακέτα', 'product-bundles' ),
			'convert' => __( 'Μετατροπή', 'product-bundles' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core (Bible §16).
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Product Bundles', 'product-bundles' ),
			__( 'Πακέτα', 'product-bundles' ),
			self::CAP,
			self::SLUG_LIST,
			array( __CLASS__, 'render_list_page' )
		);
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Product Bundles — Ρυθμίσεις', 'product-bundles' ),
			__( 'PB Ρυθμίσεις', 'product-bundles' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'product-bundles' ) . '</a>'
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

	private static function current_tab( array $tabs, string $default ): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( $tabs[ $tab ] ) ? $tab : $default;
	}

	public static function assets(): void {
		if ( ! in_array( self::current_page(), array( self::SLUG, self::SLUG_LIST ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pbn-admin', PBN_URL . 'assets/admin.css', array(), PBN_VERSION );
		if ( self::SLUG_LIST === self::current_page() && 'convert' === self::current_tab( self::list_tabs(), 'list' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/** Another bundles plugin is active: one notice on our pages (Bible §14.23). */
	public static function others_notice(): void {
		if ( ! in_array( self::current_page(), array( self::SLUG, self::SLUG_LIST ), true ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		$others = PBN_Settings::others();
		if ( ! $others ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: plugin names */
				__( 'Είναι ενεργό και άλλο plugin για πακέτα: %s. Όσο είναι ενεργό, το Product Bundles μένει σε λειτουργία δοκιμής (μόνο οι διαχειριστές αγοράζουν πακέτα). Απενεργοποίησέ το όταν είσαι έτοιμος.', 'product-bundles' ),
				implode( ', ', $others )
			)
		) . '</p></div>';
	}

	/* =====================================================================
	 * Shared bits
	 * =================================================================== */

	private static function header( string $title, string $page, array $tabs, string $tab ): void {
		?>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p class="description"><?php esc_html_e( 'Πακέτα προϊόντων: κάθε προϊόν μπαίνει στην παραγγελία με το δικό του απόθεμα, ΦΠΑ και ποσοστά, και η έκπτωση του πακέτου φαίνεται ως έκπτωση.', 'product-bundles' ); ?></p>
		<?php self::print_notices( self::take_msgs() ); ?>
		<nav class="nav-tab-wrapper pbn-tabs">
			<?php
			foreach ( $tabs as $slug => $label ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
					esc_url( admin_url( 'admin.php?page=' . $page . '&tab=' . $slug ) ),
					$slug === $tab ? ' nav-tab-active' : '',
					esc_html( $label )
				);
			}
			?>
		</nav>
		<?php
	}

	/**
	 * A <select> from value => label.
	 *
	 * @param string $name    Name.
	 * @param array  $opts    Options.
	 * @param string $current Current value.
	 * @param string $id      Optional id.
	 */
	private static function select( string $name, array $opts, string $current, string $id = '' ): string {
		$html = '<select name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		foreach ( $opts as $k => $label ) {
			$html .= '<option value="' . esc_attr( (string) $k ) . '" ' . selected( $current, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * A checkbox line.
	 *
	 * @param string $name  Name.
	 * @param bool   $on    Checked.
	 * @param string $label Label.
	 */
	private static function check( string $name, bool $on, string $label ): string {
		return '<label class="pbn-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	/* =====================================================================
	 * Page: Bundles
	 * =================================================================== */

	public static function render_list_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-bundles' ) );
		}
		$tab = self::current_tab( self::list_tabs(), 'list' );
		echo '<div class="wrap pbn-wrap">';
		self::header( __( 'Product Bundles', 'product-bundles' ), self::SLUG_LIST, self::list_tabs(), $tab );
		if ( 'convert' === $tab ) {
			self::render_convert();
		} else {
			self::render_list();
		}
		self::footer();
		echo '</div>';
	}

	private static function render_list(): void {
		$ids  = PBN_Bundle::bundle_ids();
		$mode = PBN_Settings::mode();
		if ( 'live' !== $mode ) {
			$text = 'test' === $mode
				? __( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος αγοράζουν πακέτα.', 'product-bundles' )
				: __( 'Τα πακέτα δεν πουλιούνται ακόμη (λειτουργία «Ανενεργό» στις PB Ρυθμίσεις).', 'product-bundles' );
			echo '<p class="pbn-warn">' . esc_html( $text ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'PB Ρυθμίσεις', 'product-bundles' ) . '</a></p>';
		}
		?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Νέο πακέτο', 'product-bundles' ); ?></a>
			<span class="description"><?php esc_html_e( 'Νέο προϊόν με τύπο «Πακέτο» και τα προϊόντα του στην καρτέλα «Περιεχόμενο πακέτου».', 'product-bundles' ); ?></span>
		</p>
		<?php if ( ! $ids ) : ?>
			<p class="pbn-zone"><?php esc_html_e( 'Δεν υπάρχουν ακόμη πακέτα.', 'product-bundles' ); ?></p>
		<?php else : ?>
		<table class="widefat striped pbn-table">
			<thead><tr>
				<th><?php esc_html_e( 'Πακέτο', 'product-bundles' ); ?></th>
				<th><?php esc_html_e( 'Προϊόντα', 'product-bundles' ); ?></th>
				<th><?php esc_html_e( 'Τιμή', 'product-bundles' ); ?></th>
				<th><?php esc_html_e( 'Διαθέσιμα', 'product-bundles' ); ?></th>
				<th><?php esc_html_e( 'Πωλήσεις', 'product-bundles' ); ?></th>
				<th><?php esc_html_e( 'Προβλήματα', 'product-bundles' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $ids as $id ) {
				$b = wc_get_product( $id );
				if ( ! $b instanceof PBN_Product ) {
					continue;
				}
				$rows  = PBN_Bundle::rows( $b );
				$q     = PBN_Bundle::quote( $b, array(), 'min' );
				$avail = PBN_Bundle::availability( $b );
				$prob  = array();
				if ( ! $rows ) {
					$prob[] = __( 'Χωρίς προϊόντα', 'product-bundles' );
				} elseif ( ! $q['ok'] ) {
					$prob[] = __( 'Κάποιο προϊόν δεν έχει τιμή ή επιλογή', 'product-bundles' );
				}
				if ( 'outofstock' === $avail['status'] && $rows ) {
					$prob[] = __( 'Εξαντλημένο', 'product-bundles' );
				}
				foreach ( $rows as $row ) {
					$p = PBN_Bundle::product( (int) $row['p'] );
					if ( ! $p ) {
						$prob[] = sprintf( /* translators: %d: product id */ __( 'Το προϊόν #%d δεν υπάρχει', 'product-bundles' ), (int) $row['p'] );
					} elseif ( 'publish' !== $p->get_status() ) {
						$prob[] = sprintf( /* translators: %s: product name */ __( '«%s» δεν είναι δημοσιευμένο', 'product-bundles' ), $p->get_name() );
					}
				}
				$status = 'publish' !== $b->get_status() ? ' <span class="pbn-muted">(' . esc_html( (string) ( get_post_status_object( $b->get_status() ) ? get_post_status_object( $b->get_status() )->label : $b->get_status() ) ) . ')</span>' : '';
				echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><strong>' . esc_html( $b->get_name() ) . '</strong></a>' . $status . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				echo '<td>' . esc_html( (string) count( $rows ) ) . '</td>';
				echo '<td>' . wp_kses_post( $b->get_price_html() ) . '</td>';
				$count = -1 === $avail['count'] ? __( 'Χωρίς όριο', 'product-bundles' ) : (string) $avail['count'];
				echo '<td>' . esc_html( 'outofstock' === $avail['status'] ? '0' : $count ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $b->get_total_sales( 'edit' ) ) . '</td>';
				echo '<td>' . ( $prob ? '<span class="pbn-warn-inline">' . esc_html( implode( ' · ', array_unique( $prob ) ) ) . '</span>' : '<span class="pbn-ok">✓</span>' ) . '</td></tr>';
			}
			?>
			</tbody>
		</table>
		<?php endif; ?>

		<h2 class="pbn-h2"><?php esc_html_e( 'Ξαναϋπολογισμός', 'product-bundles' ); ?></h2>
		<div class="pbn-zone">
			<p class="description"><?php esc_html_e( 'Τιμή, απόθεμα και βάρος κάθε πακέτου ενημερώνονται μόνα τους όταν αλλάζει ένα προϊόν του. Αυτό το κουμπί τα ξαναϋπολογίζει όλα (π.χ. μετά από εισαγωγή προϊόντων με CSV ή αλλαγές απευθείας στη βάση).', 'product-bundles' ); ?></p>
			<form method="post" class="pbn-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="pbn_action" value="rebuild_all" />
				<button type="submit" class="button"><?php esc_html_e( 'Ξαναϋπολογισμός όλων', 'product-bundles' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: convert
	 * ------------------------------------------------------------------- */

	private static function render_convert(): void {
		$pid = absint( self::q( 'product' ) );
		$p   = $pid ? wc_get_product( $pid ) : null;
		?>
		<h2 class="pbn-h2"><?php esc_html_e( 'Μετατροπή υπάρχοντος προϊόντος σε πακέτο', 'product-bundles' ); ?></h2>
		<div class="pbn-zone">
			<p class="description"><?php esc_html_e( 'Το ίδιο προϊόν αλλάζει τύπο σε «Πακέτο»: μένουν id, διεύθυνση, φωτογραφίες, κριτικές και SEO. Πριν από την αλλαγή γράφεται αντίγραφο του παλιού τύπου, των τιμών, του αποθέματος και των παραλλαγών, και η αλλαγή αναιρείται με ένα κλικ. Οι παραλλαγές ενός μεταβλητού προϊόντος πάνε στον κάδο, γιατί το μέγεθος το διαλέγει ο πελάτης από το προϊόν μέσα στο πακέτο. Πάρε backup της βάσης πριν από τη μετατροπή στο live site.', 'product-bundles' ); ?></p>
			<form method="get" class="pbn-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_LIST ); ?>" />
				<input type="hidden" name="tab" value="convert" />
				<select name="product" class="wc-product-search" style="width:420px" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'product-bundles' ); ?>" data-action="woocommerce_json_search_products" data-exclude_type="grouped,external,<?php echo esc_attr( PBN_Product::TYPE ); ?>" data-minimum_input_length="3">
					<?php if ( $p ) : ?>
						<option value="<?php echo esc_attr( (string) $pid ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Προεπισκόπηση', 'product-bundles' ); ?></button>
			</form>
		</div>
		<?php
		if ( $p ) {
			self::render_convert_preview( $p );
		}

		$done = PBN_Convert::converted();
		?>
		<h2 class="pbn-h2"><?php esc_html_e( 'Μετατροπές με αντίγραφο', 'product-bundles' ); ?></h2>
		<div class="pbn-zone">
			<?php if ( ! $done ) : ?>
				<p><?php esc_html_e( 'Καμία ακόμη.', 'product-bundles' ); ?></p>
			<?php else : ?>
				<table class="widefat striped pbn-table"><thead><tr>
					<th><?php esc_html_e( 'Προϊόν', 'product-bundles' ); ?></th>
					<th><?php esc_html_e( 'Παλιός τύπος', 'product-bundles' ); ?></th>
					<th><?php esc_html_e( 'Ημερομηνία', 'product-bundles' ); ?></th>
					<th></th>
				</tr></thead><tbody>
				<?php
				foreach ( $done as $id ) {
					$bk = get_post_meta( $id, PBN_Convert::BACKUP, true );
					if ( ! is_array( $bk ) ) {
						continue;
					}
					echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></td>';
					echo '<td><code>' . esc_html( isset( $bk['type'] ) ? (string) $bk['type'] : '' ) . '</code>' . ( ! empty( $bk['children'] ) ? ' · ' . esc_html( sprintf( /* translators: %d: variations */ __( '%d παραλλαγές στον κάδο', 'product-bundles' ), count( (array) $bk['children'] ) ) ) : '' ) . '</td>';
					echo '<td>' . esc_html( isset( $bk['date'] ) ? (string) $bk['date'] : '' ) . '</td><td>';
					echo '<form method="post" class="pbn-inline-form" onsubmit="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Να αναιρεθεί η μετατροπή; Το προϊόν παίρνει πίσω τον παλιό τύπο, τις τιμές και τις παραλλαγές του.', 'product-bundles' ) . '">';
					self::nonce_field();
					echo '<input type="hidden" name="pbn_action" value="undo_convert" /><input type="hidden" name="pbn_product" value="' . esc_attr( (string) $id ) . '" />';
					echo '<button type="submit" class="button">' . esc_html__( 'Αναίρεση', 'product-bundles' ) . '</button></form>';
					echo '<form method="post" class="pbn-inline-form" onsubmit="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Να σβηστεί το αντίγραφο; Μετά η μετατροπή δεν αναιρείται.', 'product-bundles' ) . '">';
					self::nonce_field();
					echo '<input type="hidden" name="pbn_action" value="forget_backup" /><input type="hidden" name="pbn_product" value="' . esc_attr( (string) $id ) . '" />';
					echo '<button type="submit" class="button-link">' . esc_html__( 'Κράτα την αλλαγή και σβήσε το αντίγραφο', 'product-bundles' ) . '</button></form>';
					echo '</td></tr>';
				}
				?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Step 2: suggested contents, discount and the confirmation.
	 *
	 * @param WC_Product $p Product.
	 */
	private static function render_convert_preview( WC_Product $p ): void {
		echo '<h2 class="pbn-h2">' . esc_html( sprintf( /* translators: %s: product name */ __( 'Προεπισκόπηση: %s', 'product-bundles' ), $p->get_name() ) ) . '</h2><div class="pbn-zone">';
		if ( ! PBN_Convert::convertible( $p ) ) {
			echo '<p class="pbn-warn">' . esc_html__( 'Μετατρέπονται μόνο απλά και μεταβλητά προϊόντα.', 'product-bundles' ) . '</p></div>';
			return;
		}
		if ( get_post_meta( $p->get_id(), PBN_Convert::BACKUP, true ) ) {
			echo '<p class="pbn-warn">' . esc_html__( 'Αυτό το προϊόν έχει ήδη αντίγραφο από προηγούμενη μετατροπή. Αναίρεσέ την πρώτα.', 'product-bundles' ) . '</p></div>';
			return;
		}
		$rows = PBN_Convert::suggest( $p );
		$cur  = (float) $p->get_price( 'edit' );

		echo '<p>' . esc_html( sprintf( /* translators: 1: type 2: price */ __( 'Τώρα: τύπος %1$s, τιμή %2$s.', 'product-bundles' ), $p->get_type(), wp_strip_all_tags( wc_price( $cur ) ) ) ) . '</p>';
		if ( $p->is_type( 'variable' ) ) {
			echo '<p class="pbn-warn">' . esc_html( sprintf( /* translators: %d: variations */ __( 'Οι %d παραλλαγές του θα πάνε στον κάδο (με αντίγραφο).', 'product-bundles' ), count( $p->get_children() ) ) ) . '</p>';
		}
		echo '<form method="post">';
		self::nonce_field();
		echo '<input type="hidden" name="pbn_action" value="convert" /><input type="hidden" name="pbn_product" value="' . esc_attr( (string) $p->get_id() ) . '" />';
		echo '<p class="description">' . esc_html__( 'Προϊόντα που βρέθηκαν στους συνδέσμους της περιγραφής. Τσέκαρε όσα ανήκουν στο πακέτο. Τα υπόλοιπα (ή άλλα) τα προσθέτεις μετά στην καρτέλα «Περιεχόμενο πακέτου» του προϊόντος.', 'product-bundles' ) . '</p>';

		$value = 0.0;
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Δεν βρέθηκαν σύνδεσμοι προς προϊόντα. Μπορείς να μετατρέψεις με ένα προϊόν από την αναζήτηση και να συμπληρώσεις τα υπόλοιπα μετά.', 'product-bundles' ) . '</p>';
		}
		echo '<table class="widefat pbn-table"><tbody>';
		foreach ( $rows as $i => $row ) {
			$c = wc_get_product( (int) $row['p'] );
			if ( ! $c ) {
				continue;
			}
			$min = $c->is_type( 'variable' ) ? (float) $c->get_variation_price( 'min' ) : (float) $c->get_price();
			$value += $min;
			echo '<tr><td><label class="pbn-check"><input type="checkbox" name="pbn_rows[' . esc_attr( (string) $i ) . '][p]" value="' . esc_attr( (string) $c->get_id() ) . '" checked /> ' . esc_html( wp_strip_all_tags( $c->get_formatted_name() ) ) . '</label>'
				. ( $c->is_type( 'variable' ) ? ' <span class="description">' . esc_html__( '(ο πελάτης διαλέγει παραλλαγή)', 'product-bundles' ) . '</span>' : '' )
				. '</td><td>' . esc_html__( 'Ποσότητα', 'product-bundles' ) . ' <input type="number" class="small-text" name="pbn_rows[' . esc_attr( (string) $i ) . '][q]" value="1" min="1" max="99" /></td><td>' . wp_kses_post( wc_price( $min ) ) . '</td></tr>';
		}
		echo '<tr><td colspan="3"><select name="pbn_rows[extra][p]" class="wc-product-search" style="width:420px" data-placeholder="' . esc_attr__( 'Ένα ακόμη προϊόν (προαιρετικό)…', 'product-bundles' ) . '" data-action="woocommerce_json_search_products_and_variations" data-exclude_type="grouped,external,' . esc_attr( PBN_Product::TYPE ) . '" data-exclude="' . esc_attr( (string) $p->get_id() ) . '" data-minimum_input_length="3"></select> <input type="hidden" name="pbn_rows[extra][q]" value="1" /></td></tr>';
		echo '</tbody></table>';

		$pct = $value > 0 && $cur > 0 && $cur < $value ? (int) round( ( 1 - $cur / $value ) * 100 ) : (int) PBN_Settings::get()['discount'];
		echo '<p class="pbn-field"><span>' . esc_html__( 'Έκπτωση', 'product-bundles' ) . '</span>';
		echo '<label class="pbn-check"><input type="radio" name="pbn_dtype" value="percent" checked /> ' . esc_html__( 'Ποσοστό', 'product-bundles' ) . ' <input type="number" class="small-text" name="pbn_disc_pct" value="' . esc_attr( (string) $pct ) . '" min="0" max="99" /> %</label> ';
		echo '<label class="pbn-check"><input type="radio" name="pbn_dtype" value="price" /> ' . esc_html__( 'Τελική τιμή', 'product-bundles' ) . ' <input type="text" class="short" name="pbn_disc_price" value="' . esc_attr( wc_format_localized_price( (string) $cur ) ) . '" /></label></p>';
		if ( $value > 0 ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: 1: value 2: price */ __( 'Αξία χωριστά (φθηνότερες επιλογές): %1$s. Τωρινή τιμή του προϊόντος: %2$s.', 'product-bundles' ), wp_strip_all_tags( wc_price( $value ) ), wp_strip_all_tags( wc_price( $cur ) ) ) ) . '</p>';
		}
		echo '<p>' . self::check( 'pbn_confirm', false, __( 'Έχω backup της βάσης και θέλω να μετατρέψω αυτό το προϊόν.', 'product-bundles' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in check().
		echo '<p class="pbn-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Μετατροπή σε πακέτο', 'product-bundles' ) . '</button></p>';
		echo '</form></div>';
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-bundles' ) );
		}
		$tab = self::current_tab( self::tabs(), 'general' );
		echo '<div class="wrap pbn-wrap">';
		self::header( __( 'Product Bundles — Ρυθμίσεις', 'product-bundles' ), self::SLUG, self::tabs(), $tab );
		switch ( $tab ) {
			case 'texts':
				self::render_texts();
				break;
			case 'tools':
				self::render_tools();
				break;
			default:
				self::render_general();
		}
		self::footer();
		echo '</div>';
	}

	private static function render_general(): void {
		$s = PBN_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pbn_action" value="save_general" />

			<h2 class="pbn-h2"><?php esc_html_e( 'Λειτουργία', 'product-bundles' ); ?></h2>
			<div class="pbn-zone">
				<?php
				if ( Product_Bundles::killed() ) {
					echo '<p class="pbn-warn">' . esc_html__( 'Το PBN_DISABLE είναι ενεργό στο wp-config.php: τα πακέτα δεν πουλιούνται και η σελίδα τους δεν δείχνει τη φόρμα. Τα πακέτα που είναι ήδη σε καλάθια μένουν σωστά.', 'product-bundles' ) . '</p>';
				}
				$modes = array(
					'off'  => __( 'Ανενεργό: τα πακέτα δεν αγοράζονται', 'product-bundles' ),
					'test' => __( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος αγοράζουν πακέτα', 'product-bundles' ),
					'live' => __( 'Ενεργό για όλους', 'product-bundles' ),
				);
				foreach ( $modes as $k => $label ) {
					echo '<label class="pbn-check"><input type="radio" name="pbn_mode" value="' . esc_attr( $k ) . '" ' . checked( $s['mode'], $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
				}
				echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'product-bundles' ) . ' <code>define( \'PBN_DISABLE\', true );</code></p>';
				echo '<p class="description">' . esc_html__( 'Αν απενεργοποιήσεις το plugin, τα πακέτα γίνονται «Εξαντλημένο» ώστε να μην πουληθούν χωρίς τα προϊόντα τους· με την ενεργοποίηση ξαναϋπολογίζονται.', 'product-bundles' ) . '</p>';
				?>
			</div>

			<h2 class="pbn-h2"><?php esc_html_e( 'Πακέτα', 'product-bundles' ); ?></h2>
			<div class="pbn-zone">
				<p class="pbn-field"><span><?php esc_html_e( 'Προεπιλεγμένη έκπτωση νέων πακέτων (%)', 'product-bundles' ); ?></span><input type="number" name="pbn_discount" min="0" max="90" value="<?php echo (int) $s['discount']; ?>" /></p>
				<p class="pbn-field"><span><?php esc_html_e( 'Κουπόνια στα προϊόντα ενός πακέτου', 'product-bundles' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'pbn_coupons',
					array(
						'no'  => __( 'Δεν ισχύουν (εκτός από κουπόνια 100 %, π.χ. δωρεάν αντίτυπα)', 'product-bundles' ),
						'yes' => __( 'Ισχύουν, πάνω στην τιμή του πακέτου', 'product-bundles' ),
					),
					$s['coupons']
				);
				?>
				</p>
				<p class="description"><?php esc_html_e( 'Ένα ποσοστιαίο κουπόνι πάνω σε πακέτο δίνει διπλή έκπτωση· γι\' αυτό η προεπιλογή είναι «Δεν ισχύουν».', 'product-bundles' ); ?></p>
			</div>

			<h2 class="pbn-h2"><?php esc_html_e( 'Σελίδα πακέτου', 'product-bundles' ); ?></h2>
			<div class="pbn-zone">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in check().
				echo self::check( 'pbn_show_contents', $s['show_contents'], __( 'Λίστα με τα προϊόντα του πακέτου (εικόνα, όνομα, τιμή)', 'product-bundles' ) );
				echo self::check( 'pbn_show_savings', $s['show_savings'], __( 'Γραμμή «Χωριστά … · Εξοικονομείς …»', 'product-bundles' ) );
				// phpcs:enable
				?>
			</div>

			<p class="pbn-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'product-bundles' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_texts(): void {
		$s    = PBN_Settings::get();
		$rows = array(
			't_contains'   => __( 'Τίτλος της λίστας προϊόντων', 'product-bundles' ),
			't_savings'    => __( 'Εξοικονόμηση (%1$s = αξία χωριστά, %2$s = ποσό, %3$s = ποσοστό)', 'product-bundles' ),
			't_part'       => __( 'Σημείωση σε κάθε γραμμή καλαθιού και παραγγελίας («Πακέτο: …»)', 'product-bundles' ),
			't_choose'     => __( 'Επιλογή παραλλαγής', 'product-bundles' ),
			't_options'    => __( 'Κουμπί στο κατάστημα όταν το πακέτο έχει επιλογές', 'product-bundles' ),
			't_from'       => __( 'Πριν από την τιμή όταν οι επιλογές αλλάζουν την τιμή', 'product-bundles' ),
			't_removed'    => __( 'Αφαίρεση ολόκληρου πακέτου από το καλάθι (%s = πακέτο)', 'product-bundles' ),
			't_unavail'    => __( 'Πακέτο που δεν είναι πια διαθέσιμο (%s = πακέτο)', 'product-bundles' ),
			't_qty_locked' => __( 'Αλλαγή ποσότητας σε προϊόν πακέτου', 'product-bundles' ),
		);
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pbn_action" value="save_texts" />
			<h2 class="pbn-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'product-bundles' ); ?></h2>
			<div class="pbn-zone">
				<p class="pbn-field"><span><?php esc_html_e( 'Γλώσσα κειμένων', 'product-bundles' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'pbn_lang',
					array(
						'auto' => __( 'Αυτόματα (γλώσσα του site)', 'product-bundles' ),
						'el'   => __( 'Ελληνικά', 'product-bundles' ),
						'en'   => __( 'English', 'product-bundles' ),
					),
					$s['lang']
				);
				?>
				</p>
				<table class="widefat pbn-table pbn-texts"><thead><tr><th><?php esc_html_e( 'Κείμενο', 'product-bundles' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'product-bundles' ); ?></th><th><?php esc_html_e( 'English', 'product-bundles' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $rows as $key => $label ) {
					echo '<tr><td>' . esc_html( $label ) . '</td>';
					foreach ( array( 'el', 'en' ) as $l ) {
						echo '<td><input type="text" name="pbn_t[' . esc_attr( $key ) . '][' . esc_attr( $l ) . ']" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) PBN_Settings::TEXTS[ $key ] . '" /></td>';
					}
					echo '</tr>';
				}
				?>
				</tbody></table>
				<p class="description pbn-mt-8"><?php esc_html_e( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Η σημείωση «Πακέτο» γράφεται στη γραμμή της παραγγελίας τη στιγμή της αγοράς, στη γλώσσα του πελάτη.', 'product-bundles' ); ?></p>
			</div>
			<p class="pbn-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'product-bundles' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_tools(): void {
		$s = PBN_Settings::get();
		?>
		<h2 class="pbn-h2"><?php esc_html_e( 'Backup', 'product-bundles' ); ?></h2>
		<div class="pbn-zone">
			<form method="post" enctype="multipart/form-data" class="pbn-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'pbn_backup_nonce' ); ?>
				<input type="file" name="pbn_import_file" accept=".json,application/json" />
				<button type="submit" name="pbn_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή (JSON)', 'product-bundles' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&pbn_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή (JSON)', 'product-bundles' ); ?></a>
			<p class="description pbn-mt-8"><?php esc_html_e( 'Το backup έχει τις ρυθμίσεις και το περιεχόμενο και την έκπτωση κάθε πακέτου. Η εισαγωγή ξαναγράφει το περιεχόμενο μόνο σε πακέτα που υπάρχουν στο site με το ίδιο id (ή το ίδιο SKU), με τον ίδιο έλεγχο όπως στην οθόνη προϊόντος.', 'product-bundles' ); ?></p>
		</div>

		<h2 class="pbn-h2"><?php esc_html_e( 'Διαγραφή του plugin', 'product-bundles' ); ?></h2>
		<div class="pbn-zone">
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="pbn_action" value="save_uninstall" />
				<?php echo self::check( 'pbn_delete_data', $s['delete_data'], __( 'Διαγραφή του περιεχομένου και της έκπτωσης των πακέτων (και των αντιγράφων μετατροπής) όταν διαγραφεί το plugin', 'product-bundles' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Χωρίς το τικ μένουν, ώστε μια νέα εγκατάσταση να τα βρει. Τα ίδια τα πακέτα (προϊόντα) και οι παραγγελίες δεν σβήνονται ποτέ· οι γραμμές παραγγελιών κρατούν τη σημείωση «Πακέτο: …». Οι ρυθμίσεις, ο δείκτης και οι εργασίες φεύγουν πάντα.', 'product-bundles' ); ?></p>
				<p class="pbn-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'product-bundles' ); ?></button></p>
			</form>
		</div>

		<h2 class="pbn-h2"><?php esc_html_e( 'Για προγραμματιστές', 'product-bundles' ); ?></h2>
		<div class="pbn-zone">
			<table class="widefat pbn-table">
				<tbody>
					<tr><td><code>pbn_get_item_bundle( $item )</code></td><td><?php esc_html_e( 'Για μια γραμμή παραγγελίας: το πακέτο από το οποίο ήρθε (id, όνομα, ομάδα, πλήθος πακέτων, ποσότητα ανά πακέτο, τιμή χωριστά, μερίδιο) ή null.', 'product-bundles' ); ?></td></tr>
					<tr><td><code>pbn_text</code></td><td><?php esc_html_e( 'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).', 'product-bundles' ); ?></td></tr>
					<tr><td><code>noxpress_products_changed</code></td><td><?php esc_html_e( 'Action που τρέχει όταν ξαναϋπολογίζονται τιμή ή απόθεμα πακέτων (ids).', 'product-bundles' ); ?></td></tr>
					<tr><td><code>_pbn</code></td><td><?php esc_html_e( 'Κρυφό meta στις γραμμές παραγγελίας που ήρθαν από πακέτο.', 'product-bundles' ); ?></td></tr>
					<tr><td><code>PBN_DISABLE</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php: σταματά τις πωλήσεις πακέτων και κάθε αλλαγή στο front end.', 'product-bundles' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		$page = self::current_page();
		if ( ! in_array( $page, array( self::SLUG, self::SLUG_LIST ), true ) ) {
			return;
		}

		if ( self::SLUG === $page && isset( $_POST['pbn_import'] ) ) {
			if ( ! isset( $_POST['pbn_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pbn_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-bundles' ) );
			}
			self::redirect( self::SLUG, 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['pbn_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-bundles' ) );
		}

		$saved  = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'product-bundles' ) ) );
		$action = sanitize_key( wp_unslash( $_POST['pbn_action'] ) );

		if ( self::SLUG_LIST === $page ) {
			switch ( $action ) {
				case 'rebuild_all':
					$ids = PBN_Bundle::bundle_ids();
					PBN_Bundle::rebuild_index();
					if ( count( $ids ) <= 200 ) {
						PBN_Bundle::sync_many( $ids );
						$msg = self::msg( 'success', sprintf( /* translators: %d: bundles */ __( 'Ξαναϋπολογίστηκαν %d πακέτα.', 'product-bundles' ), count( $ids ) ) );
					} elseif ( PBN_Bundle::enqueue_all() ) {
						$msg = self::msg( 'success', __( 'Ο ξαναϋπολογισμός ξεκίνησε στο παρασκήνιο. Ανανέωσε τη σελίδα σε λίγο.', 'product-bundles' ) );
					} else {
						$msg = self::msg( 'error', __( 'Το Action Scheduler του WooCommerce δεν είναι διαθέσιμο.', 'product-bundles' ) );
					}
					self::redirect( self::SLUG_LIST, 'list', array( $msg ) );
					break;
				case 'convert':
					self::do_convert();
					break;
				case 'undo_convert':
					$id  = absint( self::post( 'pbn_product' ) );
					$res = PBN_Convert::undo( $id );
					self::redirect( self::SLUG_LIST, 'convert', array( true === $res ? self::msg( 'success', __( 'Η μετατροπή αναιρέθηκε.', 'product-bundles' ) ) : self::msg( 'error', (string) $res ) ) );
					break;
				case 'forget_backup':
					$id = absint( self::post( 'pbn_product' ) );
					if ( $id ) {
						delete_post_meta( $id, PBN_Convert::BACKUP );
					}
					self::redirect( self::SLUG_LIST, 'convert', array( self::msg( 'success', __( 'Το αντίγραφο σβήστηκε.', 'product-bundles' ) ) ) );
					break;
				default:
					self::redirect( self::SLUG_LIST, 'list', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'product-bundles' ) ) ) );
			}
			return;
		}

		switch ( $action ) {
			case 'save_general':
				$s                  = PBN_Settings::get();
				$s['mode']          = sanitize_key( self::post( 'pbn_mode' ) );
				$s['discount']      = self::post( 'pbn_discount' );
				$s['coupons']       = sanitize_key( self::post( 'pbn_coupons' ) );
				$s['show_contents'] = '1' === self::post( 'pbn_show_contents' );
				$s['show_savings']  = '1' === self::post( 'pbn_show_savings' );
				PBN_Settings::save( $s );
				self::redirect( self::SLUG, 'general', $saved );
				break;
			case 'save_texts':
				$s         = PBN_Settings::get();
				$s['lang'] = sanitize_key( self::post( 'pbn_lang' ) );
				$t         = self::post_array( 'pbn_t' );
				foreach ( array_keys( PBN_Settings::TEXTS ) as $k ) {
					if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
						$s[ $k ] = array(
							'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
							'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
						);
					}
				}
				PBN_Settings::save( $s );
				self::redirect( self::SLUG, 'texts', $saved );
				break;
			case 'save_uninstall':
				$s                = PBN_Settings::get();
				$s['delete_data'] = '1' === self::post( 'pbn_delete_data' );
				PBN_Settings::save( $s );
				self::redirect( self::SLUG, 'tools', $saved );
				break;
			default:
				self::redirect( self::SLUG, 'general', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'product-bundles' ) ) ) );
		}
	}

	private static function do_convert(): void {
		$id   = absint( self::post( 'pbn_product' ) );
		$back = array( 'product' => $id );
		if ( '1' !== self::post( 'pbn_confirm' ) ) {
			self::redirect( self::SLUG_LIST, 'convert', array( self::msg( 'error', __( 'Τσέκαρε την επιβεβαίωση για να γίνει η μετατροπή.', 'product-bundles' ) ) ), $back );
		}
		$rows = array();
		foreach ( self::post_array( 'pbn_rows' ) as $r ) {
			if ( is_array( $r ) && isset( $r['p'] ) && is_scalar( $r['p'] ) && absint( $r['p'] ) > 0 ) {
				$rows[] = array(
					'p' => absint( $r['p'] ),
					'q' => isset( $r['q'] ) && is_scalar( $r['q'] ) ? max( 1, absint( $r['q'] ) ) : 1,
				);
			}
		}
		$dtype = 'price' === self::post( 'pbn_dtype' ) ? 'price' : 'percent';
		$disc  = 'price' === $dtype ? (float) wc_format_decimal( sanitize_text_field( self::post( 'pbn_disc_price' ) ) ) : (float) absint( self::post( 'pbn_disc_pct' ) );
		$res   = PBN_Convert::convert( $id, $rows, $dtype, $disc );
		if ( true !== $res ) {
			self::redirect( self::SLUG_LIST, 'convert', array( self::msg( 'error', (string) $res ) ), $back );
		}
		self::redirect( self::SLUG_LIST, 'convert', array( self::msg( 'success', __( 'Το προϊόν έγινε πακέτο. Έλεγξε το περιεχόμενο στην καρτέλα «Περιεχόμενο πακέτου» του προϊόντος.', 'product-bundles' ) ) ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $page, string $tab, array $msgs, array $args = array() ): void {
		set_transient( 'pbn_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page . '&tab=' . $tab ) ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (callers sanitize). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by the callers.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	/** Definitions of every bundle, for the backup. */
	private static function definitions(): array {
		$out = array();
		foreach ( PBN_Bundle::bundle_ids() as $id ) {
			$b = wc_get_product( $id );
			if ( ! $b instanceof PBN_Product ) {
				continue;
			}
			$max   = (int) $b->get_meta( PBN_Bundle::META_MAX, true, 'edit' );
			$out[] = array(
				'id'       => $id,
				'sku'      => (string) $b->get_sku( 'edit' ),
				'name'     => $b->get_name( 'edit' ),
				'items'    => PBN_Bundle::rows( $b ),
				'discount' => array(
					'type'  => 'price' === (string) $b->get_meta( PBN_Bundle::META_DTYPE, true, 'edit' ) ? 'price' : 'percent',
					'value' => (float) $b->get_meta( PBN_Bundle::META_DISC, true, 'edit' ),
					'max'   => $max,
				),
			);
		}
		return $out;
	}

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'pbn_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-bundles' ) );
		}
		$state = array(
			'plugin'  => 'product-bundles',
			'version' => PBN_VERSION,
			'options' => array(
				PBN_Settings::OPT => PBN_Settings::get(),
			),
			'bundles' => self::definitions(),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="product-bundles-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import (Bible §11): settings through PBN_Settings::sanitize(),
	 * bundle contents through validate_rows(), only on bundles that exist.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['pbn_import_file']['error'] ) ? (int) $_FILES['pbn_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['pbn_import_file']['size'] ) ? (int) $_FILES['pbn_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'product-bundles' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['pbn_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pbn_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'product-bundles' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['pbn_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'product-bundles' ) ) );
		}
		$blob = isset( $state['options'][ PBN_Settings::OPT ] ) ? $state['options'][ PBN_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Product Bundles).', 'product-bundles' ) ) );
		}
		PBN_Settings::save( $blob );

		$done = 0;
		$skip = 0;
		$defs = isset( $state['bundles'] ) && is_array( $state['bundles'] ) ? $state['bundles'] : array();
		foreach ( array_slice( $defs, 0, 2000 ) as $d ) {
			if ( ! is_array( $d ) ) {
				++$skip;
				continue;
			}
			$b = isset( $d['id'] ) && is_numeric( $d['id'] ) ? wc_get_product( (int) $d['id'] ) : null;
			if ( ! $b instanceof PBN_Product && ! empty( $d['sku'] ) && is_string( $d['sku'] ) ) {
				$sid = wc_get_product_id_by_sku( $d['sku'] );
				$b   = $sid ? wc_get_product( $sid ) : null;
			}
			if ( ! $b instanceof PBN_Product || ! isset( $d['items'] ) || ! is_array( $d['items'] ) ) {
				++$skip;
				continue;
			}
			$rows = PBN_Bundle::validate_rows( $d['items'], (int) $b->get_id() )['rows'];
			$disc = isset( $d['discount'] ) && is_array( $d['discount'] ) ? $d['discount'] : array();
			$type = isset( $disc['type'] ) && 'price' === $disc['type'] ? 'price' : 'percent';
			$val  = isset( $disc['value'] ) && is_numeric( $disc['value'] ) ? (float) $disc['value'] : 0.0;
			$val  = 'percent' === $type ? min( 99.0, max( 0.0, $val ) ) : max( 0.0, $val );
			$max  = isset( $disc['max'] ) && is_numeric( $disc['max'] ) ? max( 0, (int) $disc['max'] ) : 0;
			$b->update_meta_data( PBN_Bundle::META_ITEMS, wp_json_encode( $rows ) );
			$b->update_meta_data( PBN_Bundle::META_DTYPE, $type );
			$b->update_meta_data( PBN_Bundle::META_DISC, wc_format_decimal( $val ) );
			if ( $max > 0 ) {
				$b->update_meta_data( PBN_Bundle::META_MAX, $max );
			} else {
				$b->delete_meta_data( PBN_Bundle::META_MAX );
			}
			$b->save();
			++$done;
		}

		if ( $skip ) {
			return array( self::msg( 'warning', sprintf( /* translators: 1: imported 2: skipped */ __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: ρυθμίσεις και %1$d πακέτα· %2$d πακέτα του αρχείου δεν βρέθηκαν στο site ή δεν ήταν έγκυρα και έμειναν ως είχαν.', 'product-bundles' ), $done, $skip ) ) );
		}
		return array( self::msg( 'success', sprintf( /* translators: %d: bundles */ __( 'Η εισαγωγή ολοκληρώθηκε: ρυθμίσεις και %d πακέτα.', 'product-bundles' ), $done ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'pbn_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'pbn_aui_msg_' . get_current_user_id() );
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
		<p class="pbn-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'product-bundles' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="pbn-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="pbn-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'product-bundles' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="pbn-footer-cta">
			<a class="pbn-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'product-bundles' ); ?>
			</a>
			<a class="pbn-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'product-bundles' ); ?>
			</a>
		</p>
		<?php
	}
}
