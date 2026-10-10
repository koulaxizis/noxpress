<?php
/**
 * SHF_Admin_UI — menu, pages, PRG routes, backup, footer (Bible §3–§8, §11).
 *
 * Pages (submenus of the shared "Noxpress" menu, admin_menu priority 50):
 *  - shf-filters   tabs "Σετ φίλτρων" (sets: categories, filters, order)
 *                  and "Ομάδες τιμών" (groups per attribute, term matrix,
 *                  suggestions that are only applied when saved)
 *  - shf-settings  mode, test mode, apply mode, empty options, SEO,
 *                  backup (export / import JSON)
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (SHF_Settings::sanitize_*), PRG with the message in
 * the transient shf_aui_msg_{uid}.
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG_MAIN   = 'shf-filters';
	const SLUG_SET    = 'shf-settings';
	const NONCE       = 'shf_admin';
	const NONCE_FIELD = 'shf_nonce';
	const NONCE_BAK   = 'shf_backup';
	const MAX_IMPORT  = 2097152; // 2 MB.
	const BIG_CATALOG = 5000;    // Products above which a persistent object cache is advised.

	const TABS = array(
		'sets'   => 'Σετ φίλτρων',
		'groups' => 'Ομάδες τιμών',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 50 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		// The shared top-level "Noxpress" (with the hub as landing page) is
		// created by Noxpress Core (priority 5, Bible §16): submenus only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Shop Filters', 'shop-filters' ),
			__( 'Shop Filters', 'shop-filters' ),
			self::CAP,
			self::SLUG_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Shop Filters — Ρυθμίσεις', 'shop-filters' ),
			__( 'SHF Ρυθμίσεις', 'shop-filters' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'shop-filters' ) . '</a>'
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

	public static function assets(): void {
		$page = self::current_page();
		if ( self::SLUG_MAIN !== $page && self::SLUG_SET !== $page ) {
			return;
		}
		wp_enqueue_style( 'shf-admin', SHF_URL . 'assets/admin.css', array(), SHF_VERSION );
		wp_enqueue_script( 'shf-admin', SHF_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), SHF_VERSION, true );
	}

	/* =====================================================================
	 * Labels (msgids in one place)
	 * =================================================================== */

	private static function type_label( array $f ): string {
		if ( 'cat' === $f['type'] ) {
			return __( 'Κατηγορία', 'shop-filters' );
		}
		if ( 'price' === $f['type'] ) {
			return __( 'Τιμή', 'shop-filters' );
		}
		$attrs = SHF_Settings::attributes();
		/* translators: %s: attribute name */
		return isset( $attrs[ $f['attr'] ] ) ? sprintf( __( 'Χαρακτηριστικό: %s', 'shop-filters' ), $attrs[ $f['attr'] ] ) : $f['attr'];
	}

	private static function apply_labels(): array {
		return array(
			'auto'    => __( 'Άμεσα στον υπολογιστή, με κουμπί «Εφαρμογή» στο κινητό', 'shop-filters' ),
			'instant' => __( 'Πάντα άμεσα (κάθε κλικ φορτώνει τα αποτελέσματα)', 'shop-filters' ),
			'button'  => __( 'Πάντα με κουμπί «Εφαρμογή»', 'shop-filters' ),
		);
	}

	private static function empty_labels(): array {
		return array(
			'hide' => __( 'Απόκρυψη', 'shop-filters' ),
			'dim'  => __( 'Εμφάνιση αχνά, χωρίς σύνδεσμο', 'shop-filters' ),
		);
	}

	private static function ungrouped_labels(): array {
		return array(
			'hide'  => __( 'Κρυφές από το φίλτρο', 'shop-filters' ),
			'other' => __( 'Σε μια αυτόματη ομάδα «Άλλο»', 'shop-filters' ),
			'show'  => __( 'Μόνες τους, μετά τις ομάδες', 'shop-filters' ),
		);
	}

	private static function unit_labels(): array {
		return array(
			'm' => __( 'Μήνες', 'shop-filters' ),
			'y' => __( 'Έτη', 'shop-filters' ),
		);
	}

	private static function current_tab(): string {
		$tab = self::q( 'tab' );
		return isset( self::TABS[ $tab ] ) ? $tab : 'sets';
	}

	/* =====================================================================
	 * Page: Shop Filters (main)
	 * =================================================================== */

	public static function render_main(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shop-filters' ) );
		}

		$notices = self::take_msgs();
		$tab     = self::current_tab();
		?>
		<div class="wrap shf-wrap">
			<h1><?php esc_html_e( 'Shop Filters', 'shop-filters' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Φίλτρα προϊόντων με κατηγορίες, χαρακτηριστικά και τιμή. Οι ομάδες τιμών αλλάζουν μόνο ό,τι δείχνει το φίλτρο: τα προϊόντα και οι σελίδες τους μένουν ίδια.', 'shop-filters' ); ?></p>

			<?php self::print_notices( $notices ); ?>
			<?php self::render_status(); ?>

			<nav class="nav-tab-wrapper shf-tabs">
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=' . $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( __( $label, 'shop-filters' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from TABS. ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'groups' === $tab ) {
				self::render_groups();
			} else {
				$set = SHF_Settings::set( self::q( 'set' ) );
				if ( null !== $set ) {
					self::render_set_edit( $set );
				} else {
					self::render_sets();
				}
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function render_status(): void {
		$s       = SHF_Settings::get();
		$widgets = is_active_widget( false, false, 'shf_filters', true );
		?>
		<h2 class="shf-h2"><?php esc_html_e( 'Κατάσταση', 'shop-filters' ); ?></h2>
		<div class="shf-zone shf-status">
			<p>
				<strong><?php esc_html_e( 'Shop Filters:', 'shop-filters' ); ?></strong>
				<?php if ( Shop_Filters::killed() ) : ?>
					<span class="shf-badge shf-badge--danger"><?php esc_html_e( 'Απενεργοποιημένο από το SHF_DISABLE (wp-config.php)', 'shop-filters' ); ?></span>
				<?php elseif ( empty( $s['enabled'] ) ) : ?>
					<span class="shf-badge shf-badge--muted"><?php esc_html_e( 'Ανενεργό', 'shop-filters' ); ?></span>
				<?php elseif ( ! empty( $s['test_mode'] ) ) : ?>
					<span class="shf-badge shf-badge--warn"><?php esc_html_e( 'Λειτουργία δοκιμής — τα φίλτρα φαίνονται μόνο στους διαχειριστές του καταστήματος', 'shop-filters' ); ?></span>
				<?php else : ?>
					<span class="shf-badge shf-badge--ok"><?php esc_html_e( 'Ενεργό για όλους τους επισκέπτες', 'shop-filters' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Αλλαγή', 'shop-filters' ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'Widget:', 'shop-filters' ); ?></strong>
				<?php if ( $widgets ) : ?>
					<span class="shf-badge shf-badge--ok"><?php esc_html_e( 'Υπάρχει σε sidebar', 'shop-filters' ); ?></span>
				<?php else : ?>
					<span class="shf-badge shf-badge--warn"><?php esc_html_e( 'Δεν έχει μπει σε sidebar', 'shop-filters' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>"><?php esc_html_e( 'Widgets', 'shop-filters' ); ?></a>
				<span class="shf-hint"><?php esc_html_e( 'Widget «Noxpress: Φίλτρα», ή το shortcode [shf_filters].', 'shop-filters' ); ?></span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Πηγή μετρητών:', 'shop-filters' ); ?></strong>
				<?php if ( 'lookup' === SHF_Index::source() ) : ?>
					<span class="shf-badge shf-badge--ok"><?php esc_html_e( 'Πίνακας χαρακτηριστικών του WooCommerce', 'shop-filters' ); ?></span>
					<span class="shf-hint"><?php esc_html_e( 'Μια παραλλαγή (π.χ. χρώμα) μετρά μόνο όταν είναι σε απόθεμα.', 'shop-filters' ); ?></span>
				<?php else : ?>
					<span class="shf-badge shf-badge--warn"><?php esc_html_e( 'Εφεδρεία: όροι προϊόντων', 'shop-filters' ); ?></span>
					<span class="shf-hint"><?php esc_html_e( 'Ο πίνακας χαρακτηριστικών του WooCommerce λείπει ή ξαναχτίζεται (WooCommerce → Ρυθμίσεις → Προϊόντα → Για προχωρημένους). Τα φίλτρα δουλεύουν, χωρίς έλεγχο αποθέματος ανά παραλλαγή.', 'shop-filters' ); ?></span>
				<?php endif; ?>
			</p>
			<?php
			// Counts are built per request without a persistent object cache:
			// fine for a few thousand products, slower above that.
			$products = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
			?>
			<p>
				<strong><?php esc_html_e( 'Object cache:', 'shop-filters' ); ?></strong>
				<?php if ( wp_using_ext_object_cache() ) : ?>
					<span class="shf-badge shf-badge--ok"><?php esc_html_e( 'Μόνιμη', 'shop-filters' ); ?></span>
				<?php else : ?>
					<span class="shf-badge<?php echo $products > self::BIG_CATALOG ? ' shf-badge--warn' : ''; ?>"><?php esc_html_e( 'Μόνο ανά αίτημα', 'shop-filters' ); ?></span>
					<?php if ( $products > self::BIG_CATALOG ) : ?>
						<span class="shf-hint">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of products */
									__( 'Με %d προϊόντα οι μετρητές χτίζονται σε κάθε σελίδα και αργούν. Μια μόνιμη object cache (Redis ή Memcached, από τη φιλοξενία) τους κρατά έτοιμους.', 'shop-filters' ),
									$products
								)
							);
							?>
						</span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
			<?php
			$attrs = SHF_Settings::attributes();
			foreach ( SHF_Request::allowed_attributes() as $attr ) {
				if ( ! SHF_Settings::has_groups( $attr ) ) {
					continue;
				}
				$n = count( SHF_Groups::ungrouped( $attr ) );
				if ( $n > 0 ) {
					$url = admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=groups&attr=' . $attr . '&only=ungrouped' );
					echo '<p class="shf-warn">' . esc_html(
						sprintf(
							/* translators: 1: number of values, 2: attribute name */
							__( '%1$d τιμές του «%2$s» δεν ανήκουν σε καμία ομάδα.', 'shop-filters' ),
							$n,
							$attrs[ $attr ] ?? $attr
						)
					) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Αντιστοίχιση', 'shop-filters' ) . '</a></p>';
				}
			}
			?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Tab: filter sets
	 * =================================================================== */

	private static function render_sets(): void {
		$sets = SHF_Settings::sets();
		?>
		<h2 class="shf-h2"><?php esc_html_e( 'Σετ φίλτρων', 'shop-filters' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Ένα σετ ορίζει ποια φίλτρα φαίνονται και με ποια σειρά. Το σετ χωρίς κατηγορίες είναι το προεπιλεγμένο. Ένα σετ με κατηγορίες ισχύει σε αυτές και στις υποκατηγορίες τους.', 'shop-filters' ); ?></p>
		<?php if ( $sets ) : ?>
			<table class="widefat shf-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Όνομα', 'shop-filters' ); ?></th>
						<th><?php esc_html_e( 'Κατηγορίες', 'shop-filters' ); ?></th>
						<th><?php esc_html_e( 'Φίλτρα', 'shop-filters' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $sets as $set ) : ?>
					<tr>
						<td><strong><?php echo esc_html( '' !== $set['name'] ? $set['name'] : $set['id'] ); ?></strong></td>
						<td>
							<?php
							if ( ! $set['cats'] ) {
								echo '<span class="shf-badge">' . esc_html__( 'Προεπιλογή', 'shop-filters' ) . '</span>';
							} else {
								$names = array();
								foreach ( $set['cats'] as $c ) {
									$t       = get_term( $c, 'product_cat' );
									$names[] = $t instanceof WP_Term ? $t->name : '#' . $c;
								}
								echo esc_html( implode( ', ', $names ) );
							}
							?>
						</td>
						<td>
							<?php
							$labels = array();
							foreach ( $set['filters'] as $f ) {
								$labels[] = self::type_label( $f );
							}
							echo esc_html( $labels ? implode( ' · ', $labels ) : '—' );
							?>
						</td>
						<td>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=sets&set=' . $set['id'] ) ); ?>"><?php esc_html_e( 'Επεξεργασία', 'shop-filters' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div class="shf-zone"><p><?php esc_html_e( 'Δεν υπάρχει ακόμα σετ φίλτρων. Το πρώτο σετ γίνεται το προεπιλεγμένο.', 'shop-filters' ); ?></p></div>
		<?php endif; ?>
		<form method="post" class="shf-submit">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shf_action" value="add_set" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Νέο σετ φίλτρων', 'shop-filters' ); ?></button>
		</form>
		<?php
	}

	private static function render_set_edit( array $set ): void {
		$attrs = SHF_Settings::attributes();
		$used  = array();
		foreach ( $set['filters'] as $f ) {
			$used[ $f['type'] . ':' . $f['attr'] ] = true;
		}
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=sets' ) ); ?>">&larr; <?php esc_html_e( 'Όλα τα σετ', 'shop-filters' ); ?></a></p>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shf_action" value="save_set" />
			<input type="hidden" name="shf_set" value="<?php echo esc_attr( $set['id'] ); ?>" />

			<h2 class="shf-h2"><?php esc_html_e( 'Σετ φίλτρων', 'shop-filters' ); ?></h2>
			<div class="shf-zone">
				<label class="shf-label" for="shf-name"><?php esc_html_e( 'Όνομα', 'shop-filters' ); ?></label>
				<input type="text" id="shf-name" name="shf_name" class="regular-text" value="<?php echo esc_attr( $set['name'] ); ?>" />
			</div>

			<h2 class="shf-h2"><?php esc_html_e( 'Φίλτρα', 'shop-filters' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Σύρε τις γραμμές για να αλλάξεις τη σειρά. «Ανοιχτό»: το φίλτρο ξεκινά ανοιχτό (ένα φίλτρο με ενεργή επιλογή ανοίγει πάντα).', 'shop-filters' ); ?></p>
			<div class="shf-zone">
				<ul class="shf-filters" id="shf-filters">
					<?php foreach ( $set['filters'] as $i => $f ) : ?>
						<li class="shf-frow">
							<span class="shf-handle" aria-hidden="true">&#9776;</span>
							<span class="shf-ftype"><?php echo esc_html( self::type_label( $f ) ); ?></span>
							<input type="hidden" name="shf_f[<?php echo (int) $i; ?>][type]" value="<?php echo esc_attr( $f['type'] ); ?>" />
							<input type="hidden" name="shf_f[<?php echo (int) $i; ?>][attr]" value="<?php echo esc_attr( $f['attr'] ); ?>" />
							<label><?php esc_html_e( 'Τίτλος', 'shop-filters' ); ?>
								<input type="text" name="shf_f[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $f['title'] ); ?>" placeholder="<?php echo esc_attr( 'attr' === $f['type'] ? ( $attrs[ $f['attr'] ] ?? '' ) : ( 'cat' === $f['type'] ? __( 'Κατηγορίες', 'shop-filters' ) : __( 'Τιμή', 'shop-filters' ) ) ); ?>" />
							</label>
							<label class="shf-inline"><input type="checkbox" name="shf_f[<?php echo (int) $i; ?>][open]" value="1" <?php checked( $f['open'] ); ?> /> <?php esc_html_e( 'Ανοιχτό', 'shop-filters' ); ?></label>
							<?php if ( 'price' !== $f['type'] ) : ?>
								<label class="shf-inline"><input type="checkbox" name="shf_f[<?php echo (int) $i; ?>][counts]" value="1" <?php checked( $f['counts'] ); ?> /> <?php esc_html_e( 'Μετρητές', 'shop-filters' ); ?></label>
							<?php else : ?>
								<input type="hidden" name="shf_f[<?php echo (int) $i; ?>][counts]" value="1" />
							<?php endif; ?>
							<label class="shf-inline"><input type="checkbox" name="shf_f[<?php echo (int) $i; ?>][remove]" value="1" /> <?php esc_html_e( 'Αφαίρεση', 'shop-filters' ); ?></label>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! $set['filters'] ) : ?>
					<p class="shf-muted"><?php esc_html_e( 'Δεν υπάρχουν φίλτρα σε αυτό το σετ.', 'shop-filters' ); ?></p>
				<?php endif; ?>
				<p>
					<label for="shf-add"><?php esc_html_e( 'Προσθήκη φίλτρου:', 'shop-filters' ); ?></label>
					<select id="shf-add" name="shf_add">
						<option value=""><?php esc_html_e( '— Καμία —', 'shop-filters' ); ?></option>
						<?php if ( empty( $used['cat:'] ) ) : ?>
							<option value="cat"><?php esc_html_e( 'Κατηγορία', 'shop-filters' ); ?></option>
						<?php endif; ?>
						<?php if ( empty( $used['price:'] ) ) : ?>
							<option value="price"><?php esc_html_e( 'Τιμή', 'shop-filters' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $attrs as $slug => $label ) : ?>
							<?php if ( empty( $used[ 'attr:' . $slug ] ) ) : ?>
								<option value="<?php echo esc_attr( 'attr:' . $slug ); ?>"><?php echo esc_html( sprintf( /* translators: %s: attribute name */ __( 'Χαρακτηριστικό: %s', 'shop-filters' ), $label ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</p>
				<?php if ( ! $attrs ) : ?>
					<p class="description"><?php esc_html_e( 'Δεν υπάρχουν καθολικά χαρακτηριστικά (Προϊόντα → Χαρακτηριστικά). Τα χαρακτηριστικά που γράφονται μόνο μέσα σε ένα προϊόν δεν φιλτράρονται.', 'shop-filters' ); ?></p>
				<?php endif; ?>
			</div>

			<h2 class="shf-h2"><?php esc_html_e( 'Κατηγορίες', 'shop-filters' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Χωρίς επιλογή: προεπιλεγμένο σετ (κατάστημα και κάθε κατηγορία χωρίς δικό της σετ). Με επιλογή: το σετ ισχύει σε αυτές τις κατηγορίες και στις υποκατηγορίες τους, αν δεν έχουν δικό τους.', 'shop-filters' ); ?></p>
			<div class="shf-scroll shf-cats">
				<?php self::category_checklist( $set['cats'] ); ?>
			</div>

			<p class="shf-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση σετ', 'shop-filters' ); ?></button>
			</p>
		</form>

		<h2 class="shf-h2"><?php esc_html_e( 'Διαγραφή σετ', 'shop-filters' ); ?></h2>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shf_action" value="delete_set" />
			<input type="hidden" name="shf_set" value="<?php echo esc_attr( $set['id'] ); ?>" />
			<label class="shf-check">
				<input type="checkbox" name="shf_confirm" value="1" required />
				<?php esc_html_e( 'Ναι, διάγραψε αυτό το σετ', 'shop-filters' ); ?>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή', 'shop-filters' ); ?></button>
		</form>
		<?php
	}

	/** Hierarchical product category checkboxes. */
	private static function category_checklist( array $checked ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			echo '<p class="shf-muted">' . esc_html__( 'Δεν υπάρχουν κατηγορίες προϊόντων.', 'shop-filters' ) . '</p>';
			return;
		}
		$children = array();
		foreach ( $terms as $t ) {
			$children[ (int) $t->parent ][] = $t;
		}
		$checked = array_flip( $checked );
		$walk    = static function ( int $parent, int $depth ) use ( &$walk, $children, $checked ): void {
			foreach ( $children[ $parent ] ?? array() as $t ) {
				$id = (int) $t->term_id;
				echo '<label class="shf-d' . (int) min( $depth, 6 ) . '"><input type="checkbox" name="shf_cats[]" value="' . (int) $id . '" ' . checked( isset( $checked[ $id ] ), true, false ) . ' /> '
					. esc_html( $t->name ) . ' <span class="shf-muted">(' . (int) $t->count . ')</span></label>';
				if ( $depth < 10 ) {
					$walk( $id, $depth + 1 );
				}
			}
		};
		$walk( 0, 0 );
	}

	/* =====================================================================
	 * Tab: value groups
	 * =================================================================== */

	private static function render_groups(): void {
		$attrs = SHF_Settings::attributes();
		if ( ! $attrs ) {
			echo '<div class="shf-zone"><p>' . esc_html__( 'Δεν υπάρχουν καθολικά χαρακτηριστικά (Προϊόντα → Χαρακτηριστικά).', 'shop-filters' ) . '</p></div>';
			return;
		}
		$attr = self::q( 'attr' );
		if ( ! isset( $attrs[ $attr ] ) ) {
			$used = SHF_Request::allowed_attributes();
			$attr = $used ? (string) reset( $used ) : (string) array_key_first( $attrs );
		}
		$cfg     = SHF_Settings::groups( $attr );
		$terms   = SHF_Groups::terms( $attr );
		$suggest = '1' === self::q( 'suggest' );
		$only    = 'ungrouped' === self::q( 'only' );
		$base    = admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=groups&attr=' . $attr );
		?>
		<h2 class="shf-h2"><?php esc_html_e( 'Χαρακτηριστικό', 'shop-filters' ); ?></h2>
		<p class="shf-attrs">
			<?php foreach ( $attrs as $slug => $label ) : ?>
				<a class="<?php echo $slug === $attr ? 'is-current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=groups&attr=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of values, 2: number of groups */
					__( '%1$d τιμές, %2$d ομάδες. Το φίλτρο δείχνει τις ομάδες· η σελίδα του προϊόντος συνεχίζει να δείχνει την αναλυτική τιμή.', 'shop-filters' ),
					count( $terms ),
					count( $cfg['groups'] )
				)
			);
			?>
		</p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shf_action" value="save_groups" />
			<input type="hidden" name="shf_attr" value="<?php echo esc_attr( $attr ); ?>" />

			<h2 class="shf-h2"><?php esc_html_e( 'Ομάδες', 'shop-filters' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Εύρος: για τιμές όπως «6-18M», «12 μηνών-5 ετών», «3 Ετών+». Το «Έως» δεν περιλαμβάνεται (0–6 και 6–12 δεν επικαλύπτονται)· κενό «Έως» = χωρίς όριο. Λέξεις: χωρισμένες με κόμμα, χωρίς διάκριση τόνων και πεζών-κεφαλαίων (π.χ. «γκρι, grey, gray, graphite»). Εύρος και λέξεις χρησιμοποιούνται μόνο για προτάσεις.', 'shop-filters' ); ?></p>
			<div class="shf-zone shf-zone-x">
				<p>
					<label><?php esc_html_e( 'Μονάδα εύρους:', 'shop-filters' ); ?>
						<select name="shf_unit">
							<?php foreach ( self::unit_labels() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['unit'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					&nbsp;
					<label><?php esc_html_e( 'Τιμές χωρίς ομάδα:', 'shop-filters' ); ?>
						<select name="shf_ungrouped">
							<?php foreach ( self::ungrouped_labels() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['ungrouped'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<table class="widefat shf-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ετικέτα', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Slug (URL)', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Εύρος από', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Έως', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Λέξεις', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Τιμές', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Διαγραφή', 'shop-filters' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$rows = $cfg['groups'];
					for ( $k = 0; $k < 3; $k++ ) {
						$rows[] = array(
							'slug'  => '',
							'label' => '',
							'terms' => array(),
							'rmin'  => null,
							'rmax'  => null,
							'kw'    => '',
						);
					}
					foreach ( $rows as $i => $g ) :
						?>
						<tr>
							<td>
								<input type="hidden" name="shf_g[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $g['slug'] ); ?>" />
								<input type="text" name="shf_g[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $g['label'] ); ?>" placeholder="<?php echo '' === $g['slug'] ? esc_attr__( 'Νέα ομάδα', 'shop-filters' ) : ''; ?>" />
							</td>
							<td><input type="text" name="shf_g[<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr( $g['slug'] ); ?>" size="12" /></td>
							<td><input type="number" class="shf-num" step="any" min="0" name="shf_g[<?php echo (int) $i; ?>][rmin]" value="<?php echo null === $g['rmin'] ? '' : esc_attr( (string) $g['rmin'] ); ?>" /></td>
							<td><input type="number" class="shf-num" step="any" min="0" name="shf_g[<?php echo (int) $i; ?>][rmax]" value="<?php echo null === $g['rmax'] ? '' : esc_attr( (string) $g['rmax'] ); ?>" /></td>
							<td><input type="text" class="shf-kw" name="shf_g[<?php echo (int) $i; ?>][kw]" value="<?php echo esc_attr( $g['kw'] ); ?>" /></td>
							<td><?php echo '' === $g['slug'] ? '' : (int) count( $g['terms'] ); ?></td>
							<td><?php if ( '' !== $g['slug'] ) : ?><input type="checkbox" name="shf_g[<?php echo (int) $i; ?>][delete]" value="1" /><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="shf-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ομάδων', 'shop-filters' ); ?></button>
				</p>
			</div>
		</form>

		<h2 class="shf-h2"><?php esc_html_e( 'Αντιστοίχιση τιμών', 'shop-filters' ); ?></h2>
		<?php
		$suggested = $suggest && $cfg['groups'] ? SHF_Groups::suggest( $attr, $cfg ) : array();
		$current   = array();
		foreach ( $cfg['groups'] as $g ) {
			foreach ( $g['terms'] as $id ) {
				$current[ $id ][ $g['slug'] ] = true;
			}
		}
		$ignore = array_flip( $cfg['ignore'] );
		?>
		<p>
			<?php if ( $cfg['groups'] ) : ?>
				<a class="button" href="<?php echo esc_url( $base . '&suggest=1' . ( $only ? '&only=ungrouped' : '' ) ); ?>"><?php esc_html_e( 'Πρότεινε αντιστοίχιση', 'shop-filters' ); ?></a>
			<?php endif; ?>
			<?php if ( $only ) : ?>
				<a class="button" href="<?php echo esc_url( $base . ( $suggest ? '&suggest=1' : '' ) ); ?>"><?php esc_html_e( 'Όλες οι τιμές', 'shop-filters' ); ?></a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( $base . '&only=ungrouped' . ( $suggest ? '&suggest=1' : '' ) ); ?>"><?php esc_html_e( 'Μόνο χωρίς ομάδα', 'shop-filters' ); ?></a>
			<?php endif; ?>
		</p>
		<?php if ( $suggest ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Οι προτάσεις είναι τσεκαρισμένες και σημειωμένες με πλαίσιο. Δεν αποθηκεύεται τίποτα πριν πατήσεις «Αποθήκευση αντιστοίχισης».', 'shop-filters' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! $cfg['groups'] ) : ?>
			<p class="description"><?php esc_html_e( 'Δεν υπάρχουν ομάδες: το φίλτρο δείχνει κάθε τιμή χωριστά. Μπορείς να κρύψεις λανθασμένες τιμές με το «Αγνόησε».', 'shop-filters' ); ?></p>
		<?php endif; ?>

		<form method="post" class="shf-matrix-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shf_action" value="save_matrix" />
			<input type="hidden" name="shf_attr" value="<?php echo esc_attr( $attr ); ?>" />
			<input type="hidden" name="shf_matrix_json" value="" />
			<?php if ( $only ) : ?>
				<input type="hidden" name="shf_only" value="1" />
			<?php endif; ?>
			<div class="shf-matrix-wrap">
				<table class="widefat shf-table shf-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Τιμή', 'shop-filters' ); ?></th>
							<th><?php esc_html_e( 'Προϊόντα', 'shop-filters' ); ?></th>
							<?php foreach ( $cfg['groups'] as $g ) : ?>
								<th class="shf-gcol"><?php echo esc_html( $g['label'] ); ?></th>
							<?php endforeach; ?>
							<th class="shf-gcol"><?php esc_html_e( 'Αγνόησε', 'shop-filters' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$shown = 0;
					foreach ( $terms as $t ) :
						$id      = $t['id'];
						$has_any = ! empty( $current[ $id ] ) || isset( $ignore[ $id ] );
						if ( $only && $has_any ) {
							continue;
						}
						++$shown;
						?>
						<tr data-term="<?php echo (int) $id; ?>" class="<?php echo isset( $ignore[ $id ] ) ? 'is-ignored' : ''; ?>">
							<td class="shf-tname">
								<?php echo esc_html( $t['name'] ); ?>
								<?php if ( SHF_Groups::is_suspicious( $t ) ) : ?>
									<span class="shf-badge shf-badge--warn"><?php esc_html_e( 'ύποπτη', 'shop-filters' ); ?></span>
								<?php endif; ?>
								<?php if ( $cfg['groups'] && ! $has_any ) : ?>
									<span class="shf-badge shf-badge--muted"><?php esc_html_e( 'χωρίς ομάδα', 'shop-filters' ); ?></span>
								<?php endif; ?>
								<?php if ( $suggest && empty( $suggested[ $id ] ) && ! $has_any ) : ?>
									<span class="shf-badge shf-badge--danger"><?php esc_html_e( 'δεν αναγνωρίστηκε', 'shop-filters' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo (int) $t['count']; ?></td>
							<?php
							foreach ( $cfg['groups'] as $g ) :
								$is_cur = ! empty( $current[ $id ][ $g['slug'] ] );
								$is_sug = ! $is_cur && in_array( $g['slug'], $suggested[ $id ] ?? array(), true );
								?>
								<td class="shf-gcol<?php echo $is_sug ? ' is-suggested' : ''; ?>">
									<input type="checkbox" name="shf_m[<?php echo (int) $id; ?>][]" value="<?php echo esc_attr( $g['slug'] ); ?>" data-group="<?php echo esc_attr( $g['slug'] ); ?>" <?php checked( $is_cur || $is_sug ); ?> aria-label="<?php echo esc_attr( $t['name'] . ' → ' . $g['label'] ); ?>" />
								</td>
							<?php endforeach; ?>
							<td class="shf-gcol">
								<input type="checkbox" name="shf_ignore[]" value="<?php echo (int) $id; ?>" data-group="__ignore" <?php checked( isset( $ignore[ $id ] ) ); ?> aria-label="<?php echo esc_attr( $t['name'] . ' → ' . __( 'Αγνόησε', 'shop-filters' ) ); ?>" />
								<input type="hidden" name="shf_rows[]" value="<?php echo (int) $id; ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( 0 === $shown ) : ?>
						<tr><td colspan="<?php echo (int) ( count( $cfg['groups'] ) + 3 ); ?>" class="shf-empty"><?php esc_html_e( 'Καμία τιμή για εμφάνιση.', 'shop-filters' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
			<p class="shf-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση αντιστοίχισης', 'shop-filters' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shop-filters' ) );
		}

		$notices = self::take_msgs();
		$s       = SHF_Settings::get();
		?>
		<div class="wrap shf-wrap">
			<h1><?php esc_html_e( 'Shop Filters — Ρυθμίσεις', 'shop-filters' ); ?></h1>

			<?php self::print_notices( $notices ); ?>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="shf_action" value="save_settings" />

				<h2 class="shf-h2"><?php esc_html_e( 'Λειτουργία', 'shop-filters' ); ?></h2>
				<div class="shf-zone">
					<label class="shf-check">
						<input type="checkbox" name="shf_enabled" value="1" <?php checked( $s['enabled'] ); ?> />
						<?php esc_html_e( 'Ενεργοποίηση των φίλτρων στο front end', 'shop-filters' ); ?>
					</label>
					<label class="shf-check">
						<input type="checkbox" name="shf_test_mode" value="1" <?php checked( $s['test_mode'] ); ?> />
						<?php esc_html_e( 'Λειτουργία δοκιμής: τα φίλτρα φαίνονται και φιλτράρουν μόνο για όσους διαχειρίζονται το κατάστημα', 'shop-filters' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'shop-filters' ); ?>
						<code>define( 'SHF_DISABLE', true );</code>
					</p>
					<?php if ( Shop_Filters::killed() ) : ?>
						<p class="shf-warn"><?php esc_html_e( 'Το SHF_DISABLE είναι ενεργό: τα φίλτρα δεν εμφανίζονται και δεν φιλτράρουν.', 'shop-filters' ); ?></p>
					<?php endif; ?>
				</div>

				<h2 class="shf-h2"><?php esc_html_e( 'Συμπεριφορά', 'shop-filters' ); ?></h2>
				<div class="shf-zone">
					<label class="shf-label"><?php esc_html_e( 'Εφαρμογή επιλογών', 'shop-filters' ); ?></label>
					<?php foreach ( self::apply_labels() as $k => $l ) : ?>
						<label class="shf-check"><input type="radio" name="shf_apply" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['apply'], $k ); ?> /> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Χωρίς JavaScript κάθε επιλογή εφαρμόζεται αμέσως.', 'shop-filters' ); ?></p>

					<label class="shf-label"><?php esc_html_e( 'Επιλογές χωρίς αποτελέσματα', 'shop-filters' ); ?></label>
					<?php foreach ( self::empty_labels() as $k => $l ) : ?>
						<label class="shf-check"><input type="radio" name="shf_empty" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['empty'], $k ); ?> /> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?>

					<label class="shf-label" for="shf-max"><?php esc_html_e( 'Μέγιστος αριθμός επιλεγμένων τιμών', 'shop-filters' ); ?></label>
					<input type="number" id="shf-max" name="shf_max_values" min="1" max="50" value="<?php echo (int) $s['max_values']; ?>" />
					<p class="description"><?php esc_html_e( 'Περισσότερες τιμές στο URL αγνοούνται.', 'shop-filters' ); ?></p>
				</div>

				<h2 class="shf-h2"><?php esc_html_e( 'SEO', 'shop-filters' ); ?></h2>
				<div class="shf-zone">
					<label class="shf-check">
						<input type="checkbox" name="shf_seo" value="1" <?php checked( $s['seo'] ); ?> />
						<?php esc_html_e( 'Οι σελίδες με ενεργά φίλτρα παίρνουν «noindex, follow» και canonical προς τη σελίδα χωρίς φίλτρα', 'shop-filters' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Συνεργάζεται με Yoast SEO και Rank Math. Οι σύνδεσμοι των φίλτρων έχουν πάντα rel="nofollow".', 'shop-filters' ); ?></p>
				</div>

				<p class="shf-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'shop-filters' ); ?></button>
				</p>
			</form>

			<h2 class="shf-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'shop-filters' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="shf-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'shf_backup_nonce' ); ?>
				<input type="file" name="shf_import_file" accept=".json,application/json" />
				<button type="submit" name="shf_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'shop-filters' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&shf_backup_export=1' ), self::NONCE_BAK ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'shop-filters' ); ?>
			</a>
			<p class="description shf-mt-8">
				<?php esc_html_e( 'Περιλαμβάνονται οι ρυθμίσεις, τα σετ φίλτρων και οι ομάδες τιμών. Οι ομάδες αναφέρονται σε όρους με το ID τους, άρα μεταφέρονται μόνο σε αντίγραφο του ίδιου site. Η εισαγωγή αντικαθιστά ό,τι περιέχει το αρχείο, μετά από πλήρη έλεγχο εγκυρότητας.', 'shop-filters' ); ?>
			</p>

			<?php self::footer(); ?>
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

		$page = self::current_page();
		if ( self::SLUG_MAIN !== $page && self::SLUG_SET !== $page ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['shf_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shop-filters' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['shf_action'] ) );
		$back   = self::SLUG_SET === $page ? self::SLUG_SET : self::SLUG_MAIN;

		switch ( $action ) {
			case 'save_settings':
				$msgs = self::do_save_settings();
				break;
			case 'add_set':
				list( $msgs, $back ) = self::do_add_set();
				break;
			case 'save_set':
				list( $msgs, $back ) = self::do_save_set();
				break;
			case 'delete_set':
				$msgs = self::do_delete_set();
				$back = self::SLUG_MAIN . '&tab=sets';
				break;
			case 'save_groups':
				list( $msgs, $back ) = self::do_save_groups();
				break;
			case 'save_matrix':
				list( $msgs, $back ) = self::do_save_matrix();
				break;
			default:
				$msgs = array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'shop-filters' ) ) );
		}

		$s = SHF_Settings::get();
		if ( 'save_settings' !== $action && empty( $s['enabled'] ) && SHF_Settings::sets() ) {
			$msgs[] = self::msg( 'warning', __( 'Το Shop Filters είναι ακόμη ανενεργό: ενεργοποίησέ το από τις Ρυθμίσεις (με τη λειτουργία δοκιμής ενεργή, τα φίλτρα φαίνονται μόνο σε εσένα).', 'shop-filters' ) );
		}

		self::redirect( $back, $msgs );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	/** @param string $target Page slug plus an optional, already safe query tail. */
	private static function redirect( string $target, array $msgs ): void {
		set_transient( 'shf_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $target ) );
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

	private static function do_save_settings(): array {
		SHF_Settings::save(
			array(
				'enabled'    => '' !== self::post( 'shf_enabled' ),
				'test_mode'  => '' !== self::post( 'shf_test_mode' ),
				'apply'      => sanitize_key( self::post( 'shf_apply' ) ),
				'empty'      => sanitize_key( self::post( 'shf_empty' ) ),
				'max_values' => (int) self::post( 'shf_max_values' ),
				'seo'        => '' !== self::post( 'shf_seo' ),
			)
		);
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'shop-filters' ) ) );
	}

	private static function do_add_set(): array {
		$sets = SHF_Settings::sets();
		if ( count( $sets ) >= SHF_Settings::MAX_SETS ) {
			return array( array( self::msg( 'error', __( 'Έφτασες το όριο των σετ φίλτρων.', 'shop-filters' ) ) ), self::SLUG_MAIN . '&tab=sets' );
		}
		$id     = SHF_Settings::new_set_id();
		$sets[] = array(
			'id'      => $id,
			/* translators: %d: set number */
			'name'    => sprintf( __( 'Σετ %d', 'shop-filters' ), count( $sets ) + 1 ),
			'cats'    => array(),
			'filters' => array(
				array(
					'type' => 'cat',
					'open' => true,
				),
				array(
					'type' => 'price',
					'open' => true,
				),
			),
		);
		SHF_Settings::save_sets( $sets );
		return array( array( self::msg( 'success', __( 'Το σετ δημιουργήθηκε. Πρόσθεσε φίλτρα χαρακτηριστικών και αποθήκευσε.', 'shop-filters' ) ) ), self::SLUG_MAIN . '&tab=sets&set=' . $id );
	}

	private static function do_save_set(): array {
		$id   = sanitize_key( self::post( 'shf_set' ) );
		$sets = SHF_Settings::sets();
		$pos  = null;
		foreach ( $sets as $i => $set ) {
			if ( $set['id'] === $id ) {
				$pos = $i;
			}
		}
		if ( null === $pos ) {
			return array( array( self::msg( 'error', __( 'Το σετ δεν βρέθηκε.', 'shop-filters' ) ) ), self::SLUG_MAIN . '&tab=sets' );
		}

		$filters = array();
		foreach ( self::post_array( 'shf_f' ) as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$f = SHF_Settings::sanitize_filter( $row );
			if ( null !== $f && ( 'attr' !== $f['type'] || SHF_Settings::is_attribute( $f['attr'] ) ) ) {
				$filters[] = $f;
			}
		}
		$add = self::post( 'shf_add' );
		if ( 'cat' === $add || 'price' === $add ) {
			$filters[] = array(
				'type' => $add,
				'open' => true,
			);
		} elseif ( 0 === strpos( $add, 'attr:' ) && SHF_Settings::is_attribute( substr( $add, 5 ) ) ) {
			$filters[] = array(
				'type' => 'attr',
				'attr' => substr( $add, 5 ),
				'open' => true,
			);
		}

		$cats = array();
		foreach ( self::post_array( 'shf_cats' ) as $c ) {
			$c = (int) $c;
			if ( $c > 0 && term_exists( $c, 'product_cat' ) ) {
				$cats[] = $c;
			}
		}

		$msgs = array();
		// A category belongs to one set only: the first set that has it keeps it.
		foreach ( $sets as $i => $other ) {
			if ( $i === $pos ) {
				continue;
			}
			$clash = array_intersect( $cats, $other['cats'] );
			if ( $clash ) {
				$cats   = array_values( array_diff( $cats, $clash ) );
				$msgs[] = self::msg(
					'warning',
					/* translators: %s: set name */
					sprintf( __( 'Κάποιες κατηγορίες ανήκουν ήδη στο σετ «%s» και δεν προστέθηκαν.', 'shop-filters' ), '' !== $other['name'] ? $other['name'] : $other['id'] )
				);
			}
		}
		if ( ! $cats ) {
			foreach ( $sets as $i => $other ) {
				if ( $i !== $pos && ! $other['cats'] ) {
					$msgs[] = self::msg( 'warning', __( 'Υπάρχει ήδη προεπιλεγμένο σετ (χωρίς κατηγορίες): ισχύει το πρώτο στη λίστα.', 'shop-filters' ) );
					break;
				}
			}
		}

		$sets[ $pos ]['name']    = self::post( 'shf_name' );
		$sets[ $pos ]['cats']    = $cats;
		$sets[ $pos ]['filters'] = $filters;
		SHF_Settings::save_sets( $sets );
		array_unshift( $msgs, self::msg( 'success', __( 'Το σετ αποθηκεύτηκε.', 'shop-filters' ) ) );
		return array( $msgs, self::SLUG_MAIN . '&tab=sets&set=' . $id );
	}

	private static function do_delete_set(): array {
		if ( '' === self::post( 'shf_confirm' ) ) {
			return array( self::msg( 'error', __( 'Επιβεβαίωσε τη διαγραφή.', 'shop-filters' ) ) );
		}
		$id   = sanitize_key( self::post( 'shf_set' ) );
		$sets = array_values(
			array_filter(
				SHF_Settings::sets(),
				static function ( $s ) use ( $id ) {
					return $s['id'] !== $id;
				}
			)
		);
		SHF_Settings::save_sets( $sets );
		return array( self::msg( 'success', __( 'Το σετ διαγράφηκε.', 'shop-filters' ) ) );
	}

	private static function do_save_groups(): array {
		$attr = sanitize_title( self::post( 'shf_attr' ) );
		if ( ! SHF_Settings::is_attribute( $attr ) ) {
			return array( array( self::msg( 'error', __( 'Άγνωστο χαρακτηριστικό.', 'shop-filters' ) ) ), self::SLUG_MAIN . '&tab=groups' );
		}
		$cfg = SHF_Settings::groups( $attr );
		$old = array();
		foreach ( $cfg['groups'] as $g ) {
			$old[ $g['slug'] ] = $g['terms'];
		}
		$groups = array();
		foreach ( self::post_array( 'shf_g' ) as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$key      = isset( $row['key'] ) ? (string) $row['key'] : '';
			$groups[] = array(
				'label' => $label,
				'slug'  => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'rmin'  => $row['rmin'] ?? null,
				'rmax'  => $row['rmax'] ?? null,
				'kw'    => isset( $row['kw'] ) ? (string) $row['kw'] : '',
				'terms' => $old[ $key ] ?? array(), // Assignments follow the row, even when the slug changes.
			);
		}
		$cfg['groups']    = $groups;
		$cfg['unit']      = sanitize_key( self::post( 'shf_unit' ) );
		$cfg['ungrouped'] = sanitize_key( self::post( 'shf_ungrouped' ) );
		SHF_Settings::save_groups( $attr, $cfg );
		SHF_Groups::reset();
		return array( array( self::msg( 'success', __( 'Οι ομάδες αποθηκεύτηκαν.', 'shop-filters' ) ) ), self::SLUG_MAIN . '&tab=groups&attr=' . $attr );
	}

	/**
	 * Term ↔ group matrix. admin.js sends it as one JSON field (large
	 * attributes would exceed max_input_vars); the checkboxes are the
	 * fallback without JavaScript. Only the rows shown on the page change.
	 */
	private static function do_save_matrix(): array {
		$attr = sanitize_title( self::post( 'shf_attr' ) );
		if ( ! SHF_Settings::is_attribute( $attr ) ) {
			return array( array( self::msg( 'error', __( 'Άγνωστο χαρακτηριστικό.', 'shop-filters' ) ) ), self::SLUG_MAIN . '&tab=groups' );
		}
		$back  = self::SLUG_MAIN . '&tab=groups&attr=' . $attr . ( '' !== self::post( 'shf_only' ) ? '&only=ungrouped' : '' );
		$valid = array();
		foreach ( SHF_Groups::terms( $attr ) as $t ) {
			$valid[ $t['id'] ] = true;
		}

		$rows   = array(); // term id → group slugs.
		$ignore = array();
		$json   = self::post( 'shf_matrix_json' );
		if ( '' !== $json ) {
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) || ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
				return array( array( self::msg( 'error', __( 'Μη έγκυρα δεδομένα αντιστοίχισης.', 'shop-filters' ) ) ), $back );
			}
			foreach ( $data['rows'] as $id => $slugs ) {
				$rows[ (int) $id ] = is_array( $slugs ) ? array_map( 'strval', $slugs ) : array();
			}
			foreach ( (array) ( $data['ignore'] ?? array() ) as $id ) {
				$ignore[ (int) $id ] = true;
			}
		} else {
			$m = self::post_array( 'shf_m' );
			foreach ( self::post_array( 'shf_rows' ) as $id ) {
				$id          = (int) $id;
				$rows[ $id ] = isset( $m[ $id ] ) && is_array( $m[ $id ] ) ? array_map( 'strval', $m[ $id ] ) : array();
			}
			foreach ( self::post_array( 'shf_ignore' ) as $id ) {
				$ignore[ (int) $id ] = true;
			}
		}

		$cfg     = SHF_Settings::groups( $attr );
		$slugs   = array_flip( array_column( $cfg['groups'], 'slug' ) );
		$members = array();
		foreach ( $cfg['groups'] as $g ) {
			$members[ $g['slug'] ] = array_flip( $g['terms'] );
		}
		$old_ignore = array_flip( $cfg['ignore'] );
		foreach ( $rows as $id => $chosen ) {
			if ( empty( $valid[ $id ] ) ) {
				continue;
			}
			foreach ( $members as $slug => $set ) {
				unset( $members[ $slug ][ $id ] );
			}
			foreach ( $chosen as $slug ) {
				if ( isset( $slugs[ $slug ] ) ) {
					$members[ $slug ][ $id ] = true;
				}
			}
			unset( $old_ignore[ $id ] );
			if ( isset( $ignore[ $id ] ) ) {
				$old_ignore[ $id ] = true;
			}
		}
		foreach ( $cfg['groups'] as $i => $g ) {
			$cfg['groups'][ $i ]['terms'] = array_keys( $members[ $g['slug'] ] );
		}
		$cfg['ignore'] = array_keys( $old_ignore );
		SHF_Settings::save_groups( $attr, $cfg );
		SHF_Groups::reset();
		return array( array( self::msg( 'success', __( 'Η αντιστοίχιση αποθηκεύτηκε.', 'shop-filters' ) ) ), $back );
	}

	/* =====================================================================
	 * Backup (export GET + nonce, import POST + nonce + PRG)
	 * =================================================================== */

	public static function route_backup(): void {

		if ( self::SLUG_SET !== self::current_page() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.
		if ( isset( $_GET['shf_backup_export'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shop-filters' ) );
			}

			$groups = array();
			foreach ( SHF_Settings::grouped_attributes() as $attr ) {
				$groups[ $attr ] = SHF_Settings::groups( $attr );
			}
			$state = array(
				'plugin'  => 'shop-filters',
				'version' => SHF_VERSION,
				'options' => array(
					SHF_Settings::OPT_SETTINGS => SHF_Settings::get(),
					SHF_Settings::OPT_SETS     => SHF_Settings::sets(),
					'shf_groups'               => $groups,
				),
			);

			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="shop-filters-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		if ( isset( $_POST['shf_import'] ) ) {

			if ( ! isset( $_POST['shf_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['shf_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shop-filters' ) );
			}

			$msgs = array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'shop-filters' ) ) );

			$up_err  = isset( $_FILES['shf_import_file']['error'] ) ? (int) $_FILES['shf_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['shf_import_file']['size'] ) ? (int) $_FILES['shf_import_file']['size'] : 0;

			if ( UPLOAD_ERR_OK === $up_err && $up_size > 0 && $up_size <= self::MAX_IMPORT
				&& ! empty( $_FILES['shf_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['shf_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp path from PHP.

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['shf_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );

				$msgs = is_array( $state )
					? self::import_state( $state )
					: array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'shop-filters' ) ) );
			} elseif ( $up_size > self::MAX_IMPORT ) {
				$msgs = array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'shop-filters' ) ) );
			}

			self::redirect( self::SLUG_SET, $msgs );
		}
	}

	/**
	 * Strict import, per option (Bible §11): an invalid blob leaves that
	 * option untouched with an explicit error; partial success is stated.
	 */
	private static function import_state( array $state ): array {
		$opts = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();
		if ( ( $state['plugin'] ?? '' ) !== 'shop-filters' || ! $opts ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Shop Filters).', 'shop-filters' ) ) );
		}
		$ok   = 0;
		$bad  = 0;
		$msgs = array();

		if ( isset( $opts[ SHF_Settings::OPT_SETTINGS ] ) ) {
			if ( is_array( $opts[ SHF_Settings::OPT_SETTINGS ] ) ) {
				SHF_Settings::save( $opts[ SHF_Settings::OPT_SETTINGS ] );
				++$ok;
			} else {
				++$bad;
				$msgs[] = self::msg( 'error', __( 'Οι γενικές ρυθμίσεις δεν ήταν έγκυρες και δεν άλλαξαν.', 'shop-filters' ) );
			}
		}
		if ( isset( $opts[ SHF_Settings::OPT_SETS ] ) ) {
			if ( is_array( $opts[ SHF_Settings::OPT_SETS ] ) ) {
				SHF_Settings::save_sets( $opts[ SHF_Settings::OPT_SETS ] );
				++$ok;
			} else {
				++$bad;
				$msgs[] = self::msg( 'error', __( 'Τα σετ φίλτρων δεν ήταν έγκυρα και δεν άλλαξαν.', 'shop-filters' ) );
			}
		}
		if ( isset( $opts['shf_groups'] ) && is_array( $opts['shf_groups'] ) ) {
			foreach ( $opts['shf_groups'] as $attr => $g ) {
				$attr = sanitize_title( (string) $attr );
				if ( SHF_Settings::is_attribute( $attr ) && is_array( $g ) ) {
					SHF_Settings::save_groups( $attr, $g );
					++$ok;
				} else {
					++$bad;
					/* translators: %s: attribute slug */
					$msgs[] = self::msg( 'error', sprintf( __( 'Οι ομάδες του «%s» παραλείφθηκαν (άγνωστο χαρακτηριστικό ή άκυρα δεδομένα).', 'shop-filters' ), $attr ) );
				}
			}
		}
		if ( 0 === $ok ) {
			$msgs[] = self::msg( 'error', __( 'Δεν εισήχθη τίποτα.', 'shop-filters' ) );
		} elseif ( $bad > 0 ) {
			array_unshift( $msgs, self::msg( 'warning', __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ.', 'shop-filters' ) ) );
		} else {
			array_unshift( $msgs, self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'shop-filters' ) ) );
		}
		return $msgs;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'shf_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'shf_aui_msg_' . get_current_user_id() );
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
		<p class="shf-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'shop-filters' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="shf-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="shf-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'shop-filters' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="shf-footer-cta">
			<a class="shf-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'shop-filters' ); ?>
			</a>
			<a class="shf-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'shop-filters' ); ?>
			</a>
		</p>
		<?php
	}
}
