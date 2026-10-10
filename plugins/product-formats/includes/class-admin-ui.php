<?php
/**
 * PFM_Admin_UI — menu, pages, PRG routes, AJAX, backup, footer (Bible §3–§8, §11).
 *
 * Pages (submenus of the shared "Noxpress" menu, admin_menu priority 60):
 *  - pfm-formats   tabs "Έργα" (list, edit, new, merge, delete),
 *                  "Προτάσεις" (scan, accept / reject one by one or the
 *                  checked ones), "Μορφές" (format registry), "Upsells"
 *                  (cleanup with preview, progress bar, restore)
 *  - pfm-settings  mode, test mode, block, list line, upsell hiding,
 *                  backup (export / import JSON)
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation, PRG with the message in the transient
 * pfm_aui_msg_{uid}. AJAX: check_ajax_referer + capability + whitelist.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG_MAIN   = 'pfm-formats';
	const SLUG_SET    = 'pfm-settings';
	const NONCE       = 'pfm_admin';
	const NONCE_FIELD = 'pfm_nonce';
	const NONCE_BAK   = 'pfm_backup';
	const NONCE_AJAX  = 'pfm_ajax';
	const MAX_IMPORT  = 2097152; // 2 MB.
	const PER_PAGE    = 50;
	const SCAN_TTL    = DAY_IN_SECONDS;
	const MAX_SHOWN   = 100;     // Suggestions shown at once.

	const TABS = array(
		'works'   => 'Έργα',
		'suggest' => 'Προτάσεις',
		'formats' => 'Μορφές',
		'upsells' => 'Upsells',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_pfm_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_pfm_upsells_start', array( __CLASS__, 'ajax_upsells_start' ) );
		add_action( 'wp_ajax_pfm_upsells_batch', array( __CLASS__, 'ajax_upsells_batch' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PFM_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		// The shared top-level "Noxpress" (with the hub as landing page) is
		// created by Noxpress Core (priority 5, Bible §16): submenus only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Product Formats', 'product-formats' ),
			__( 'Μορφές', 'product-formats' ),
			self::CAP,
			self::SLUG_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Product Formats — Ρυθμίσεις', 'product-formats' ),
			__( 'PFM Ρυθμίσεις', 'product-formats' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'product-formats' ) . '</a>'
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
		// Every selector is prefixed, so the product edit screen can load it too (autocomplete menu).
		wp_enqueue_style( 'pfm-admin', PFM_URL . 'assets/admin.css', array(), PFM_VERSION );
		wp_enqueue_script( 'pfm-admin', PFM_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-autocomplete', 'jquery-ui-sortable' ), PFM_VERSION, true );
		wp_localize_script(
			'pfm-admin',
			'PFM',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'confirm' => __( 'Να αφαιρεθούν τα upsells προς τις άλλες μορφές; Κρατιέται snapshot για επαναφορά.', 'product-formats' ),
				'failed'  => __( 'Ο καθαρισμός σταμάτησε. Δοκίμασε ξανά· όσα έγιναν έχουν snapshot.', 'product-formats' ),
			)
		);
	}

	/* =====================================================================
	 * Labels (msgids in one place)
	 * =================================================================== */

	private static function position_labels(): array {
		return array(
			'after_price' => __( 'Κάτω από την τιμή', 'product-formats' ),
			'after_cart'  => __( 'Κάτω από το κουμπί καλαθιού', 'product-formats' ),
			'before_tabs' => __( 'Πάνω από τα tabs της περιγραφής', 'product-formats' ),
			'manual'      => __( 'Μόνο με το shortcode [nox_formats]', 'product-formats' ),
		);
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'publish' => __( 'δημοσιευμένο', 'product-formats' ),
			'draft'   => __( 'πρόχειρο', 'product-formats' ),
			'pending' => __( 'σε αναμονή', 'product-formats' ),
			'private' => __( 'ιδιωτικό', 'product-formats' ),
			'future'  => __( 'προγραμματισμένο', 'product-formats' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function source_label( string $source ): string {
		$labels = array(
			'work'   => __( 'από το έργο', 'product-formats' ),
			'suffix' => __( 'από το slug', 'product-formats' ),
			'cat'    => __( 'από την κατηγορία', 'product-formats' ),
		);
		return $labels[ $source ] ?? '';
	}

	private static function current_tab(): string {
		$tab = self::q( 'tab' );
		return isset( self::TABS[ $tab ] ) ? $tab : 'works';
	}

	/* =====================================================================
	 * Page: Product Formats (main)
	 * =================================================================== */

	public static function render_main(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-formats' ) );
		}

		$notices = self::take_msgs();
		$tab     = self::current_tab();
		?>
		<div class="wrap pfm-wrap">
			<h1><?php esc_html_e( 'Product Formats', 'product-formats' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Κάθε έργο ενώνει τα προϊόντα που είναι το ίδιο έργο σε άλλη μορφή. Η σελίδα κάθε προϊόντος δείχνει το μπλοκ «Διαθέσιμες μορφές» με ετικέτα, τιμή και σύνδεσμο. Τα προϊόντα μένουν χωριστά.', 'product-formats' ); ?></p>

			<?php self::print_notices( $notices ); ?>
			<?php self::render_status(); ?>

			<nav class="nav-tab-wrapper pfm-tabs">
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=' . $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( __( $label, 'product-formats' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from TABS. ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'suggest' === $tab ) {
				self::render_suggest();
			} elseif ( 'formats' === $tab ) {
				self::render_formats();
			} elseif ( 'upsells' === $tab ) {
				self::render_upsells();
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
				$work_id = isset( $_GET['work'] ) ? absint( $_GET['work'] ) : 0;
				if ( $work_id && PFM_Works::work( $work_id ) ) {
					self::render_work_edit( $work_id );
				} elseif ( '1' === self::q( 'new' ) ) {
					self::render_work_new();
				} else {
					self::render_works();
				}
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function render_status(): void {
		$s = PFM_Settings::get();
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Κατάσταση', 'product-formats' ); ?></h2>
		<div class="pfm-zone pfm-status">
			<p>
				<strong><?php esc_html_e( 'Product Formats:', 'product-formats' ); ?></strong>
				<?php if ( Product_Formats::killed() ) : ?>
					<span class="pfm-badge pfm-badge--danger"><?php esc_html_e( 'Απενεργοποιημένο από το PFM_DISABLE (wp-config.php)', 'product-formats' ); ?></span>
				<?php elseif ( empty( $s['enabled'] ) ) : ?>
					<span class="pfm-badge pfm-badge--muted"><?php esc_html_e( 'Ανενεργό στο front end', 'product-formats' ); ?></span>
				<?php elseif ( ! empty( $s['test_mode'] ) ) : ?>
					<span class="pfm-badge pfm-badge--warn"><?php esc_html_e( 'Λειτουργία δοκιμής — το μπλοκ φαίνεται μόνο στους διαχειριστές του καταστήματος', 'product-formats' ); ?></span>
				<?php else : ?>
					<span class="pfm-badge pfm-badge--ok"><?php esc_html_e( 'Ενεργό για όλους τους επισκέπτες', 'product-formats' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Αλλαγή', 'product-formats' ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( 'Έργα:', 'product-formats' ); ?></strong>
				<?php echo (int) PFM_Works::count(); ?>
				<span class="pfm-muted"> · </span>
				<strong><?php esc_html_e( 'Θέση μπλοκ:', 'product-formats' ); ?></strong>
				<?php
				$pos = self::position_labels();
				echo esc_html( $pos[ $s['position'] ] ?? '' );
				?>
			</p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Tab: works
	 * =================================================================== */

	private static function render_works(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		list( $terms, $total ) = PFM_Works::page( $search, $paged, self::PER_PAGE );
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Έργα', 'product-formats' ); ?></h2>
		<div class="pfm-toolbar">
			<form method="get" class="pfm-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_MAIN ); ?>" />
				<input type="hidden" name="tab" value="works" />
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Αναζήτηση έργου', 'product-formats' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Αναζήτηση', 'product-formats' ); ?></button>
			</form>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=works&new=1' ) ); ?>"><?php esc_html_e( 'Νέο έργο', 'product-formats' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=suggest' ) ); ?>"><?php esc_html_e( 'Προτάσεις έργων', 'product-formats' ); ?></a>
		</div>

		<table class="widefat pfm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Έργο', 'product-formats' ); ?></th>
					<th><?php esc_html_e( 'Μορφές', 'product-formats' ); ?></th>
					<th class="pfm-col-narrow"><?php esc_html_e( 'Ενέργειες', 'product-formats' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $terms ) : ?>
					<tr><td colspan="3" class="pfm-empty"><?php esc_html_e( 'Δεν υπάρχουν έργα ακόμα. Ξεκίνα από τις «Προτάσεις» ή φτιάξε ένα «Νέο έργο».', 'product-formats' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $terms as $t ) : ?>
					<?php $edit = admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=works&work=' . (int) $t->term_id ); ?>
					<tr>
						<td><a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $t->name ); ?></strong></a></td>
						<td><?php self::chips( (int) $t->term_id ); ?></td>
						<td><a class="button" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Επεξεργασία', 'product-formats' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="pfm-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=works&paged=' . $i . ( '' !== $search ? '&s=' . rawurlencode( $search ) : '' ) );
				echo $i === $paged
					? '<span class="pfm-page pfm-page--current">' . (int) $i . '</span> '
					: '<a class="pfm-page" href="' . esc_url( $url ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	/** Format chips of a work: label, product title, status. */
	private static function chips( int $work_id ): void {
		foreach ( PFM_Works::members( $work_id ) as $m ) {
			$label = PFM_Settings::label( $m['format'] );
			$cls   = 'publish' === $m['status'] && $m['visible'] ? 'pfm-badge' : 'pfm-badge pfm-badge--muted';
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( (string) get_edit_post_link( $m['id'] ) ) . '" title="' . esc_attr( get_the_title( $m['id'] ) ) . '">'
				. esc_html( '' !== $label ? $label : __( 'χωρίς μορφή', 'product-formats' ) )
				. ( '' !== $m['variant'] ? ' · ' . esc_html( $m['variant'] ) : '' )
				. ( 'publish' !== $m['status'] ? ' · ' . esc_html( self::status_label( $m['status'] ) ) : '' )
				. ( 'publish' === $m['status'] && ! $m['visible'] ? ' · ' . esc_html__( 'κρυφό', 'product-formats' ) : '' )
				. '</a>';
		}
	}

	private static function render_work_edit( int $work_id ): void {
		$work    = PFM_Works::work( $work_id );
		$members = PFM_Works::members( $work_id );
		$back    = admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=works' );
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Όλα τα έργα', 'product-formats' ); ?></a></p>
		<h2 class="pfm-h2"><?php echo esc_html( $work ? $work->name : '' ); ?></h2>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="save_work" />
			<input type="hidden" name="pfm_work" value="<?php echo (int) $work_id; ?>" />
			<div class="pfm-zone">
				<label class="pfm-label" for="pfm-name"><?php esc_html_e( 'Όνομα έργου', 'product-formats' ); ?></label>
				<input type="text" id="pfm-name" name="pfm_name" class="regular-text" value="<?php echo esc_attr( $work ? $work->name : '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Το όνομα φαίνεται μόνο στο admin.', 'product-formats' ); ?></p>
			</div>

			<table class="widefat pfm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Προϊόν', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Μορφή', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Υπότιτλος', 'product-formats' ); ?></th>
						<th class="pfm-col-narrow"><?php esc_html_e( 'Αφαίρεση', 'product-formats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $members as $m ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $m['id'] ) ); ?>"><?php echo esc_html( get_the_title( $m['id'] ) ); ?></a>
								<span class="pfm-hint">#<?php echo (int) $m['id']; ?><?php echo 'publish' !== $m['status'] ? ' · ' . esc_html( self::status_label( $m['status'] ) ) : ''; ?><?php echo 'publish' === $m['status'] && ! $m['visible'] ? ' · ' . esc_html__( 'κρυφό', 'product-formats' ) : ''; ?></span>
								<?php if ( 'publish' === $m['status'] ) : ?>
									<a class="pfm-hint" href="<?php echo esc_url( (string) get_permalink( $m['id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Προβολή', 'product-formats' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php PFM_Product_Tab::format_select( 'pfm_m[' . (int) $m['id'] . '][format]', '', $m['format'] ); ?></td>
							<td><input type="text" name="pfm_m[<?php echo (int) $m['id']; ?>][variant]" value="<?php echo esc_attr( $m['variant'] ); ?>" maxlength="<?php echo (int) PFM_Works::MAX_VARIANT; ?>" /></td>
							<td><input type="checkbox" name="pfm_m[<?php echo (int) $m['id']; ?>][remove]" value="1" aria-label="<?php esc_attr_e( 'Αφαίρεση', 'product-formats' ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					<tr class="pfm-add-row">
						<td>
							<input type="text" class="regular-text" placeholder="<?php esc_attr_e( 'Προσθήκη προϊόντος (3+ γράμματα ή ID)', 'product-formats' ); ?>" data-pfm-ac="product" data-pfm-target="pfm-add-id" autocomplete="off" />
							<input type="hidden" id="pfm-add-id" name="pfm_add[id]" value="" />
						</td>
						<td><?php PFM_Product_Tab::format_select( 'pfm_add[format]', '', '' ); ?></td>
						<td><input type="text" name="pfm_add[variant]" value="" maxlength="<?php echo (int) PFM_Works::MAX_VARIANT; ?>" /></td>
						<td></td>
					</tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Ένα προϊόν που ανήκει σε άλλο έργο μετακινείται σε αυτό. Η σειρά στο μπλοκ ακολουθεί τη σειρά των μορφών.', 'product-formats' ); ?></p>
			<p class="pfm-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση έργου', 'product-formats' ); ?></button>
			</p>
		</form>

		<h2 class="pfm-h2"><?php esc_html_e( 'Συγχώνευση', 'product-formats' ); ?></h2>
		<form method="post" class="pfm-zone">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="merge_work" />
			<input type="hidden" name="pfm_work" value="<?php echo (int) $work_id; ?>" />
			<label class="pfm-label"><?php esc_html_e( 'Μετακίνησε όλα τα προϊόντα αυτού του έργου στο έργο:', 'product-formats' ); ?></label>
			<input type="text" class="regular-text" data-pfm-ac="work" data-pfm-target="pfm-merge-id" autocomplete="off" placeholder="<?php esc_attr_e( 'Όνομα έργου (3+ γράμματα)', 'product-formats' ); ?>" />
			<input type="hidden" id="pfm-merge-id" name="pfm_target" value="" />
			<button type="submit" class="button"><?php esc_html_e( 'Συγχώνευση', 'product-formats' ); ?></button>
		</form>

		<h2 class="pfm-h2"><?php esc_html_e( 'Διαγραφή έργου', 'product-formats' ); ?></h2>
		<form method="post" class="pfm-zone">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="delete_work" />
			<input type="hidden" name="pfm_work" value="<?php echo (int) $work_id; ?>" />
			<label class="pfm-check"><input type="checkbox" name="pfm_confirm" value="1" /> <?php esc_html_e( 'Ναι, διάγραψε το έργο (τα προϊόντα μένουν ως έχουν)', 'product-formats' ); ?></label>
			<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή', 'product-formats' ); ?></button>
		</form>
		<?php
	}

	private static function render_work_new(): void {
		$back = admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=works' );
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Όλα τα έργα', 'product-formats' ); ?></a></p>
		<h2 class="pfm-h2"><?php esc_html_e( 'Νέο έργο', 'product-formats' ); ?></h2>
		<form method="post" class="pfm-zone">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="new_work" />
			<label class="pfm-label" for="pfm-name"><?php esc_html_e( 'Όνομα έργου', 'product-formats' ); ?></label>
			<input type="text" id="pfm-name" name="pfm_name" class="regular-text" value="" />
			<label class="pfm-label"><?php esc_html_e( 'Πρώτο προϊόν', 'product-formats' ); ?></label>
			<input type="text" class="regular-text" placeholder="<?php esc_attr_e( 'Προσθήκη προϊόντος (3+ γράμματα ή ID)', 'product-formats' ); ?>" data-pfm-ac="product" data-pfm-target="pfm-add-id" autocomplete="off" />
			<input type="hidden" id="pfm-add-id" name="pfm_add[id]" value="" />
			<?php PFM_Product_Tab::format_select( 'pfm_add[format]', '', '' ); ?>
			<p class="pfm-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Δημιουργία', 'product-formats' ); ?></button></p>
		</form>
		<?php
	}

	/* =====================================================================
	 * Tab: suggestions
	 * =================================================================== */

	private static function scan_key(): string {
		return 'pfm_scan_' . get_current_user_id();
	}

	private static function scan_get(): ?array {
		$raw = get_transient( self::scan_key() );
		return is_array( $raw ) && isset( $raw['time'], $raw['items'] ) && is_array( $raw['items'] ) ? $raw : null;
	}

	private static function render_suggest(): void {
		$scan = self::scan_get();
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Προτάσεις έργων', 'product-formats' ); ?></h2>
		<div class="pfm-zone">
			<p class="description"><?php esc_html_e( 'Η σάρωση βρίσκει προϊόντα με το ίδιο slug χωρίς την κατάληξη μορφής (π.χ. -ebook) ή με τον ίδιο τίτλο χωρίς τα [..] και (..). Τα upsells μετρούν μόνο για τη βεβαιότητα. Τίποτα δεν αλλάζει πριν πατήσεις «Αποδοχή».', 'product-formats' ); ?></p>
			<p class="description">
				<?php esc_html_e( 'Όταν η μορφή δεν φαίνεται από το slug, βγαίνει από την κατηγορία του προϊόντος. Όρισε τις κατηγορίες κάθε μορφής στην καρτέλα', 'product-formats' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=formats' ) ); ?>"><?php esc_html_e( 'Μορφές', 'product-formats' ); ?></a>.
			</p>
			<form method="post" class="pfm-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="pfm_action" value="scan" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Σάρωση καταλόγου', 'product-formats' ); ?></button>
			</form>
			<?php if ( PFM_Settings::rejected() ) : ?>
				<form method="post" class="pfm-inline-form">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="pfm_action" value="clear_rejected" />
					<button type="submit" class="button">
						<?php
						/* translators: %d: number of rejected suggestions */
						echo esc_html( sprintf( __( 'Ξανά οι απορριφθείσες (%d)', 'product-formats' ), count( PFM_Settings::rejected() ) ) );
						?>
					</button>
				</form>
			<?php endif; ?>
			<?php if ( $scan ) : ?>
				<p class="pfm-hint pfm-mt-8">
					<?php
					/* translators: 1: date and time, 2: number of suggestions */
					echo esc_html( sprintf( __( 'Τελευταία σάρωση: %1$s · %2$d προτάσεις που μένουν', 'product-formats' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $scan['time'] ), count( $scan['items'] ) ) );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		if ( ! $scan ) {
			return;
		}
		if ( ! $scan['items'] ) {
			echo '<p class="pfm-empty">' . esc_html__( 'Καμία πρόταση. Όλα τα προϊόντα με κοινό slug ή τίτλο είναι ήδη σε έργα.', 'product-formats' ) . '</p>';
			return;
		}
		$notes = array(
			'missing' => __( 'κάποια μέλη χωρίς μορφή', 'product-formats' ),
			'dupe'    => __( 'ίδια μορφή δύο φορές: συμπλήρωσε υπότιτλο', 'product-formats' ),
			'upsells' => __( 'συνδέονται ήδη με upsells', 'product-formats' ),
		);
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="suggest" />
			<p class="pfm-submit">
				<button type="submit" name="pfm_bulk" value="1" class="button button-primary"><?php esc_html_e( 'Αποδοχή επιλεγμένων', 'product-formats' ); ?></button>
				<label class="pfm-inline"><input type="checkbox" data-pfm-checkall=".pfm-pick" /> <?php esc_html_e( 'Επιλογή όλων με υψηλή βεβαιότητα', 'product-formats' ); ?></label>
			</p>
			<?php foreach ( array_slice( $scan['items'], 0, self::MAX_SHOWN ) as $sug ) : ?>
				<?php $sig = $sug['sig']; ?>
				<div class="pfm-sug pfm-sug--<?php echo esc_attr( $sug['confidence'] ); ?>">
					<div class="pfm-sug__head">
						<input type="checkbox" name="pfm_pick[]" value="<?php echo esc_attr( $sig ); ?>" class="<?php echo 'high' === $sug['confidence'] ? 'pfm-pick' : ''; ?>" aria-label="<?php esc_attr_e( 'Επιλογή', 'product-formats' ); ?>" />
						<input type="text" name="pfm_sug[<?php echo esc_attr( $sig ); ?>][name]" value="<?php echo esc_attr( $sug['name'] ); ?>" class="regular-text" <?php echo $sug['target'] ? 'readonly' : ''; ?> />
						<?php if ( 'high' === $sug['confidence'] ) : ?>
							<span class="pfm-badge pfm-badge--ok"><?php esc_html_e( 'υψηλή βεβαιότητα', 'product-formats' ); ?></span>
						<?php else : ?>
							<span class="pfm-badge pfm-badge--warn"><?php esc_html_e( 'έλεγξέ το', 'product-formats' ); ?></span>
						<?php endif; ?>
						<?php if ( $sug['target'] ) : ?>
							<span class="pfm-badge"><?php esc_html_e( 'προσθήκη σε υπάρχον έργο', 'product-formats' ); ?></span>
						<?php endif; ?>
						<?php foreach ( $sug['notes'] as $n ) : ?>
							<?php if ( isset( $notes[ $n ] ) ) : ?>
								<span class="pfm-hint"><?php echo esc_html( $notes[ $n ] ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
						<span class="pfm-sug__actions">
							<button type="submit" name="pfm_one" value="<?php echo esc_attr( $sig ); ?>" class="button button-primary"><?php esc_html_e( 'Αποδοχή', 'product-formats' ); ?></button>
							<button type="submit" name="pfm_reject" value="<?php echo esc_attr( $sig ); ?>" class="button"><?php esc_html_e( 'Απόρριψη', 'product-formats' ); ?></button>
						</span>
					</div>
					<table class="widefat pfm-table pfm-table--compact">
						<tbody>
							<?php foreach ( $sug['members'] as $m ) : ?>
								<?php $base = 'pfm_sug[' . $sig . '][m][' . (int) $m['id'] . ']'; ?>
								<tr>
									<td class="pfm-col-check"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[in]" value="1" checked <?php echo $m['in_work'] ? 'disabled' : ''; ?> aria-label="<?php esc_attr_e( 'Μέλος', 'product-formats' ); ?>" /></td>
									<td>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $m['id'] ) ); ?>"><?php echo esc_html( $m['title'] ); ?></a>
										<span class="pfm-hint"><code><?php echo esc_html( $m['slug'] ); ?></code><?php echo 'publish' !== $m['status'] ? ' · ' . esc_html( self::status_label( $m['status'] ) ) : ''; ?><?php echo $m['in_work'] ? ' · ' . esc_html__( 'ήδη στο έργο', 'product-formats' ) : ''; ?></span>
									</td>
									<td>
										<?php if ( $m['in_work'] ) : ?>
											<?php echo esc_html( '' !== PFM_Settings::label( $m['format'] ) ? PFM_Settings::label( $m['format'] ) : __( 'χωρίς μορφή', 'product-formats' ) ); ?>
										<?php else : ?>
											<?php PFM_Product_Tab::format_select( $base . '[format]', '', $m['format'] ); ?>
											<span class="pfm-hint"><?php echo esc_html( self::source_label( $m['source'] ) ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! $m['in_work'] ) : ?>
											<input type="text" name="<?php echo esc_attr( $base ); ?>[variant]" value="<?php echo esc_attr( $m['variant'] ); ?>" placeholder="<?php esc_attr_e( 'Υπότιτλος', 'product-formats' ); ?>" maxlength="<?php echo (int) PFM_Works::MAX_VARIANT; ?>" />
										<?php else : ?>
											<?php echo esc_html( $m['variant'] ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
			<?php if ( count( $scan['items'] ) > self::MAX_SHOWN ) : ?>
				<p class="pfm-hint">
					<?php
					/* translators: %d: number of suggestions shown */
					echo esc_html( sprintf( __( 'Φαίνονται οι πρώτες %d. Οι υπόλοιπες εμφανίζονται όταν τελειώσεις με αυτές.', 'product-formats' ), self::MAX_SHOWN ) );
					?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/* =====================================================================
	 * Tab: formats
	 * =================================================================== */

	private static function render_formats(): void {
		$builtin = PFM_Settings::builtin();
		$cats    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$cats    = is_array( $cats ) ? $cats : array();
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Μορφές', 'product-formats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Η σειρά εδώ είναι η σειρά στο μπλοκ (σύρε τις γραμμές). Οι καταλήξεις slug και οι κατηγορίες χρησιμοποιούνται μόνο στις προτάσεις. Μια κατηγορία ισχύει και για τις υποκατηγορίες της.', 'product-formats' ); ?></p>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pfm_action" value="save_formats" />
			<table class="widefat pfm-table pfm-sortable">
				<thead>
					<tr>
						<th class="pfm-col-check"></th>
						<th class="pfm-col-check"><?php esc_html_e( 'Ενεργή', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Κλειδί', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Ετικέτα (ελληνικά)', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Ετικέτα (αγγλικά)', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Καταλήξεις slug', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Κατηγορίες', 'product-formats' ); ?></th>
						<th class="pfm-col-check"><?php esc_html_e( 'Διαγραφή', 'product-formats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( PFM_Settings::formats() as $i => $f ) : ?>
						<?php $base = 'pfm_f[' . (int) $i . ']'; ?>
						<tr>
							<td class="pfm-handle" title="<?php esc_attr_e( 'Σύρε για αλλαγή σειράς', 'product-formats' ); ?>">⋮⋮</td>
							<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( $f['enabled'] ); ?> /></td>
							<td><code><?php echo esc_html( $f['key'] ); ?></code><input type="hidden" name="<?php echo esc_attr( $base ); ?>[key]" value="<?php echo esc_attr( $f['key'] ); ?>" /></td>
							<td><input type="text" name="<?php echo esc_attr( $base ); ?>[label_el]" value="<?php echo esc_attr( $f['label_el'] ); ?>" placeholder="<?php echo esc_attr( $builtin[ $f['key'] ] ?? '' ); ?>" maxlength="<?php echo (int) PFM_Settings::MAX_LABEL; ?>" /></td>
							<td><input type="text" name="<?php echo esc_attr( $base ); ?>[label_en]" value="<?php echo esc_attr( $f['label_en'] ); ?>" placeholder="<?php echo esc_attr( isset( $builtin[ $f['key'] ] ) ? PFM_Lang::english( $builtin[ $f['key'] ] ) : '' ); ?>" maxlength="<?php echo (int) PFM_Settings::MAX_LABEL; ?>" /></td>
							<td><input type="text" name="<?php echo esc_attr( $base ); ?>[suffixes]" value="<?php echo esc_attr( implode( ', ', $f['suffixes'] ) ); ?>" /></td>
							<td><?php self::category_picker( $base . '[cats][]', $f['cats'], $cats ); ?></td>
							<td>
								<?php if ( ! $f['builtin'] ) : ?>
									<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[delete]" value="1" />
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 class="pfm-h3"><?php esc_html_e( 'Νέα μορφή', 'product-formats' ); ?></h3>
			<div class="pfm-zone pfm-grid">
				<div>
					<label class="pfm-label" for="pfm-new-key"><?php esc_html_e( 'Κλειδί', 'product-formats' ); ?></label>
					<input type="text" id="pfm-new-key" name="pfm_new[key]" value="" placeholder="vinyl" maxlength="20" />
					<p class="description"><?php esc_html_e( 'Λατινικά πεζά, αριθμοί, - και _.', 'product-formats' ); ?></p>
				</div>
				<div>
					<label class="pfm-label" for="pfm-new-el"><?php esc_html_e( 'Ετικέτα (ελληνικά)', 'product-formats' ); ?></label>
					<input type="text" id="pfm-new-el" name="pfm_new[label_el]" value="" placeholder="Βινύλιο" maxlength="<?php echo (int) PFM_Settings::MAX_LABEL; ?>" />
				</div>
				<div>
					<label class="pfm-label" for="pfm-new-en"><?php esc_html_e( 'Ετικέτα (αγγλικά)', 'product-formats' ); ?></label>
					<input type="text" id="pfm-new-en" name="pfm_new[label_en]" value="" placeholder="Vinyl" maxlength="<?php echo (int) PFM_Settings::MAX_LABEL; ?>" />
				</div>
				<div>
					<label class="pfm-label" for="pfm-new-suf"><?php esc_html_e( 'Καταλήξεις slug', 'product-formats' ); ?></label>
					<input type="text" id="pfm-new-suf" name="pfm_new[suffixes]" value="" placeholder="vinyl, lp" />
				</div>
			</div>
			<p class="pfm-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση μορφών', 'product-formats' ); ?></button></p>
		</form>
		<?php
	}

	/** Category checklist in a <details> (indented by depth). */
	private static function category_picker( string $name, array $checked, array $cats ): void {
		if ( ! $cats ) {
			echo '<span class="pfm-hint">' . esc_html__( 'Δεν υπάρχουν κατηγορίες προϊόντων.', 'product-formats' ) . '</span>';
			return;
		}
		$children = array();
		foreach ( $cats as $c ) {
			$children[ (int) $c->parent ][] = $c;
		}
		$names = array();
		foreach ( $cats as $c ) {
			if ( in_array( (int) $c->term_id, $checked, true ) ) {
				$names[] = $c->name;
			}
		}
		echo '<details class="pfm-details"><summary>'
			. esc_html( $names ? implode( ', ', $names ) : __( '— Καμία —', 'product-formats' ) )
			. '</summary><div class="pfm-cats">';
		$walk = static function ( int $parent, int $depth ) use ( &$walk, $children, $checked, $name ): void {
			foreach ( $children[ $parent ] ?? array() as $c ) {
				echo '<label class="pfm-check pfm-depth-' . (int) min( $depth, 4 ) . '"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . (int) $c->term_id . '"'
					. ( in_array( (int) $c->term_id, $checked, true ) ? ' checked' : '' ) . ' /> ' . esc_html( $c->name ) . '</label>';
				$walk( (int) $c->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		echo '</div></details>';
	}

	/* =====================================================================
	 * Tab: upsells
	 * =================================================================== */

	private static function render_upsells(): void {
		$plan = PFM_Upsells::plan();
		$runs = PFM_Upsells::runs();
		$s    = PFM_Settings::get();
		?>
		<h2 class="pfm-h2"><?php esc_html_e( 'Upsells προς τις άλλες μορφές', 'product-formats' ); ?></h2>
		<div class="pfm-zone">
			<p class="description"><?php esc_html_e( 'Πριν από το Product Formats, οι μορφές ενός έργου συνδέονταν συχνά με upsells. Δύο τρόποι να φύγουν από τη σελίδα:', 'product-formats' ); ?></p>
			<p>
				<strong><?php esc_html_e( '1. Απόκρυψη', 'product-formats' ); ?></strong>
				<?php if ( $s['hide_upsells'] ) : ?>
					<span class="pfm-badge pfm-badge--ok"><?php esc_html_e( 'ενεργή', 'product-formats' ); ?></span>
				<?php else : ?>
					<span class="pfm-badge pfm-badge--muted"><?php esc_html_e( 'ανενεργή', 'product-formats' ); ?></span>
				<?php endif; ?>
				<span class="pfm-hint"><?php esc_html_e( 'Μόνο στο front end, χωρίς αλλαγή στα προϊόντα. Ανοίγει από τις Ρυθμίσεις.', 'product-formats' ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Ρυθμίσεις', 'product-formats' ); ?></a>
			</p>
			<p>
				<strong><?php esc_html_e( '2. Καθαρισμός', 'product-formats' ); ?></strong>
				<span class="pfm-hint"><?php esc_html_e( 'Αφαιρεί από τα upsells μόνο τα προϊόντα του ίδιου έργου και κρατά τα υπόλοιπα. Πριν από κάθε αλλαγή κρατά snapshot για επαναφορά.', 'product-formats' ); ?></span>
			</p>
		</div>

		<h3 class="pfm-h3">
			<?php
			/* translators: %d: number of products */
			echo esc_html( sprintf( __( 'Προεπισκόπηση: %d προϊόντα', 'product-formats' ), count( $plan ) ) );
			?>
		</h3>
		<?php if ( $plan ) : ?>
			<table class="widefat pfm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Προϊόν', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Φεύγουν από τα upsells', 'product-formats' ); ?></th>
						<th><?php esc_html_e( 'Μένουν', 'product-formats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $plan as $item ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $item['id'] ) ); ?>"><?php echo esc_html( get_the_title( $item['id'] ) ); ?></a> <span class="pfm-hint">#<?php echo (int) $item['id']; ?></span></td>
							<td><?php self::id_list( $item['remove'] ); ?></td>
							<td><?php $item['keep'] ? self::id_list( $item['keep'] ) : print( '<span class="pfm-hint">—</span>' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="pfm-submit">
				<button type="button" class="button button-primary" id="pfm-clean" data-total="<?php echo (int) count( $plan ); ?>"><?php esc_html_e( 'Καθαρισμός upsells', 'product-formats' ); ?></button>
			</p>
			<div class="pfm-progress" id="pfm-progress" hidden><div class="pfm-progress__bar"></div></div>
		<?php else : ?>
			<p class="pfm-empty"><?php esc_html_e( 'Κανένα προϊόν δεν έχει upsells προς άλλες μορφές του έργου του.', 'product-formats' ); ?></p>
		<?php endif; ?>

		<h3 class="pfm-h3"><?php esc_html_e( 'Snapshots', 'product-formats' ); ?></h3>
		<table class="widefat pfm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Πότε', 'product-formats' ); ?></th>
					<th><?php esc_html_e( 'Χρήστης', 'product-formats' ); ?></th>
					<th><?php esc_html_e( 'Προϊόντα', 'product-formats' ); ?></th>
					<th><?php esc_html_e( 'Ενέργειες', 'product-formats' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $runs ) : ?>
					<tr><td colspan="4" class="pfm-empty"><?php esc_html_e( 'Δεν έχει γίνει καθαρισμός ακόμα.', 'product-formats' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $runs as $r ) : ?>
					<?php $u = get_userdata( $r['user'] ); ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['time'] ) ); ?></td>
						<td><?php echo esc_html( $u ? $u->display_name : '—' ); ?></td>
						<td>
							<?php echo (int) count( $r['items'] ); ?>
							<?php if ( $r['restored'] ) : ?>
								<span class="pfm-badge pfm-badge--muted"><?php esc_html_e( 'επαναφέρθηκε', 'product-formats' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" class="pfm-inline-form">
								<?php self::nonce_field(); ?>
								<input type="hidden" name="pfm_run" value="<?php echo esc_attr( $r['id'] ); ?>" />
								<button type="submit" name="pfm_action" value="restore_run" class="button" <?php disabled( ! $r['items'] ); ?>><?php esc_html_e( 'Επαναφορά', 'product-formats' ); ?></button>
								<button type="submit" name="pfm_action" value="delete_run" class="button"><?php esc_html_e( 'Διαγραφή snapshot', 'product-formats' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Product titles with edit links. */
	private static function id_list( array $ids ): void {
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $id ) ) . '">' . esc_html( get_the_title( (int) $id ) ) . '</a> <span class="pfm-hint">#' . (int) $id . '</span>';
		}
		echo implode( '<br />', $out ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-formats' ) );
		}

		$notices = self::take_msgs();
		$s       = PFM_Settings::get();
		?>
		<div class="wrap pfm-wrap">
			<h1><?php esc_html_e( 'Product Formats — Ρυθμίσεις', 'product-formats' ); ?></h1>

			<?php self::print_notices( $notices ); ?>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="pfm_action" value="save_settings" />

				<h2 class="pfm-h2"><?php esc_html_e( 'Λειτουργία', 'product-formats' ); ?></h2>
				<div class="pfm-zone">
					<label class="pfm-check">
						<input type="checkbox" name="pfm_enabled" value="1" <?php checked( $s['enabled'] ); ?> />
						<?php esc_html_e( 'Ενεργοποίηση στο front end', 'product-formats' ); ?>
					</label>
					<label class="pfm-check">
						<input type="checkbox" name="pfm_test_mode" value="1" <?php checked( $s['test_mode'] ); ?> />
						<?php esc_html_e( 'Λειτουργία δοκιμής: το μπλοκ, η γραμμή στις λίστες και η απόκρυψη των upsells ισχύουν μόνο για όσους διαχειρίζονται το κατάστημα', 'product-formats' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'product-formats' ); ?>
						<code>define( 'PFM_DISABLE', true );</code>
					</p>
					<?php if ( Product_Formats::killed() ) : ?>
						<p class="pfm-warn"><?php esc_html_e( 'Το PFM_DISABLE είναι ενεργό: τίποτα δεν εμφανίζεται στο front end.', 'product-formats' ); ?></p>
					<?php endif; ?>
				</div>

				<h2 class="pfm-h2"><?php esc_html_e( 'Μπλοκ «Διαθέσιμες μορφές»', 'product-formats' ); ?></h2>
				<div class="pfm-zone">
					<label class="pfm-label"><?php esc_html_e( 'Θέση στη σελίδα προϊόντος', 'product-formats' ); ?></label>
					<?php foreach ( self::position_labels() as $k => $l ) : ?>
						<label class="pfm-check"><input type="radio" name="pfm_position" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['position'], $k ); ?> /> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Το shortcode [nox_formats] δουλεύει σε κάθε θέση (και [nox_formats id="123"] για άλλο προϊόν). Στα block themes χρησιμοποίησέ το μέσα σε block Shortcode, αν θέλεις άλλη θέση.', 'product-formats' ); ?></p>

					<div class="pfm-grid">
						<div>
							<label class="pfm-label" for="pfm-title-el"><?php esc_html_e( 'Τίτλος (ελληνικά)', 'product-formats' ); ?></label>
							<input type="text" id="pfm-title-el" name="pfm_title_el" value="<?php echo esc_attr( $s['title_el'] ); ?>" placeholder="Διαθέσιμες μορφές" maxlength="<?php echo (int) PFM_Settings::MAX_TITLE; ?>" />
						</div>
						<div>
							<label class="pfm-label" for="pfm-title-en"><?php esc_html_e( 'Τίτλος (αγγλικά)', 'product-formats' ); ?></label>
							<input type="text" id="pfm-title-en" name="pfm_title_en" value="<?php echo esc_attr( $s['title_en'] ); ?>" placeholder="Available formats" maxlength="<?php echo (int) PFM_Settings::MAX_TITLE; ?>" />
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'Η γλώσσα του front end ακολουθεί τη γλώσσα του site.', 'product-formats' ); ?></p>

					<label class="pfm-check">
						<input type="checkbox" name="pfm_show_stock" value="1" <?php checked( $s['show_stock'] ); ?> />
						<?php esc_html_e( 'Ένδειξη «Εξαντλημένο» στις μορφές χωρίς απόθεμα', 'product-formats' ); ?>
					</label>
				</div>

				<h2 class="pfm-h2"><?php esc_html_e( 'Λίστες προϊόντων', 'product-formats' ); ?></h2>
				<div class="pfm-zone">
					<label class="pfm-check">
						<input type="checkbox" name="pfm_loop_line" value="1" <?php checked( $s['loop_line'] ); ?> />
						<?php esc_html_e( 'Γραμμή «Επίσης: …» με τις άλλες μορφές, κάτω από την τιμή στις λίστες', 'product-formats' ); ?>
					</label>
				</div>

				<h2 class="pfm-h2"><?php esc_html_e( 'Upsells', 'product-formats' ); ?></h2>
				<div class="pfm-zone">
					<label class="pfm-check">
						<input type="checkbox" name="pfm_hide_upsells" value="1" <?php checked( $s['hide_upsells'] ); ?> />
						<?php esc_html_e( 'Απόκρυψη των upsells που δείχνουν σε άλλες μορφές του ίδιου έργου (μόνο στο front end, τα προϊόντα δεν αλλάζουν)', 'product-formats' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Για μόνιμο καθαρισμό με επαναφορά:', 'product-formats' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=upsells' ) ); ?>"><?php esc_html_e( 'Upsells', 'product-formats' ); ?></a>
					</p>
				</div>

				<p class="pfm-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'product-formats' ); ?></button>
				</p>
			</form>

			<h2 class="pfm-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'product-formats' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="pfm-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'pfm_backup_nonce' ); ?>
				<input type="file" name="pfm_import_file" accept=".json,application/json" />
				<button type="submit" name="pfm_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή (JSON)', 'product-formats' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&pfm_backup_export=1' ), self::NONCE_BAK ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή (JSON)', 'product-formats' ); ?>
			</a>
			<p class="description pfm-mt-8">
				<?php esc_html_e( 'Περιλαμβάνονται οι ρυθμίσεις, οι μορφές και τα έργα. Τα προϊόντα αναγνωρίζονται με ID και slug (ή SKU), άρα τα έργα μεταφέρονται σε αντίγραφο του ίδιου site. Η εισαγωγή αντικαθιστά ό,τι περιέχει το αρχείο, μετά από πλήρη έλεγχο εγκυρότητας.', 'product-formats' ); ?>
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
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['pfm_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-formats' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['pfm_action'] ) );
		$back   = self::SLUG_SET === $page ? self::SLUG_SET : self::SLUG_MAIN;

		switch ( $action ) {
			case 'save_settings':
				$msgs = self::do_save_settings();
				break;
			case 'save_work':
				list( $msgs, $back ) = self::do_save_work();
				break;
			case 'new_work':
				list( $msgs, $back ) = self::do_new_work();
				break;
			case 'merge_work':
				list( $msgs, $back ) = self::do_merge_work();
				break;
			case 'delete_work':
				list( $msgs, $back ) = self::do_delete_work();
				break;
			case 'scan':
				$msgs = self::do_scan();
				$back = self::SLUG_MAIN . '&tab=suggest';
				break;
			case 'clear_rejected':
				PFM_Settings::clear_rejected();
				$msgs = array( self::msg( 'success', __( 'Οι απορριφθείσες προτάσεις θα ξαναφανούν στην επόμενη σάρωση.', 'product-formats' ) ) );
				$back = self::SLUG_MAIN . '&tab=suggest';
				break;
			case 'suggest':
				$msgs = self::do_suggest();
				$back = self::SLUG_MAIN . '&tab=suggest';
				break;
			case 'save_formats':
				$msgs = self::do_save_formats();
				$back = self::SLUG_MAIN . '&tab=formats';
				break;
			case 'restore_run':
				$msgs = self::do_restore_run();
				$back = self::SLUG_MAIN . '&tab=upsells';
				break;
			case 'delete_run':
				$ok   = PFM_Upsells::delete_run( self::post( 'pfm_run' ) );
				$msgs = array( $ok ? self::msg( 'success', __( 'Το snapshot διαγράφηκε.', 'product-formats' ) ) : self::msg( 'error', __( 'Το snapshot δεν βρέθηκε.', 'product-formats' ) ) );
				$back = self::SLUG_MAIN . '&tab=upsells';
				break;
			default:
				$msgs = array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'product-formats' ) ) );
		}

		PFM_Works::flush();

		$s = PFM_Settings::get();
		if ( 'save_settings' !== $action && empty( $s['enabled'] ) && PFM_Works::count() ) {
			$msgs[] = self::msg( 'warning', __( 'Το μπλοκ δεν φαίνεται ακόμα στο front end: ενεργοποίησέ το από τις Ρυθμίσεις (με τη λειτουργία δοκιμής ενεργή, το βλέπεις μόνο εσύ).', 'product-formats' ) );
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
		set_transient( 'pfm_aui_msg_' . get_current_user_id(), $msgs, 60 );
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
		PFM_Settings::save(
			array(
				'enabled'      => '1' === self::post( 'pfm_enabled' ),
				'test_mode'    => '1' === self::post( 'pfm_test_mode' ),
				'position'     => self::post( 'pfm_position' ),
				'title_el'     => self::post( 'pfm_title_el' ),
				'title_en'     => self::post( 'pfm_title_en' ),
				'show_stock'   => '1' === self::post( 'pfm_show_stock' ),
				'loop_line'    => '1' === self::post( 'pfm_loop_line' ),
				'hide_upsells' => '1' === self::post( 'pfm_hide_upsells' ),
			)
		);
		// Pages with the block change: purge the product pages of every work.
		self::purge_all();
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'product-formats' ) ) );
	}

	/** Purges the page cache of every product in a work (settings changed). */
	private static function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			return;
		}
		$ids = get_objects_in_term( wp_list_pluck( get_terms( array( 'taxonomy' => PFM_Works::TAX, 'hide_empty' => false ) ), 'term_id' ), PFM_Works::TAX );
		if ( is_array( $ids ) ) {
			PFM_Works::purge( array_map( 'intval', $ids ) );
		}
	}

	/** A product id that exists (any status but trash), or 0. */
	private static function product_id( $raw ): int {
		$id   = absint( $raw );
		$post = $id ? get_post( $id ) : null;
		return $post instanceof WP_Post && 'product' === $post->post_type && 'trash' !== $post->post_status ? $id : 0;
	}

	/** Adds the "pfm_add" row to a work; returns messages. */
	private static function add_member( int $work_id ): array {
		$add = self::post_array( 'pfm_add' );
		$pid = self::product_id( $add['id'] ?? 0 );
		if ( ! $pid ) {
			return array();
		}
		$old  = PFM_Works::work_of( $pid );
		$msgs = array();
		if ( $old && $old !== $work_id ) {
			$t = PFM_Works::work( $old );
			/* translators: 1: product title, 2: work name */
			$msgs[] = self::msg( 'warning', sprintf( __( 'Το «%1$s» μετακινήθηκε από το έργο «%2$s».', 'product-formats' ), get_the_title( $pid ), $t ? $t->name : '' ) );
		}
		PFM_Works::assign( $pid, $work_id, (string) ( $add['format'] ?? '' ), (string) ( $add['variant'] ?? '' ) );
		return $msgs;
	}

	private static function do_save_work(): array {
		$work_id = absint( self::post( 'pfm_work' ) );
		$back    = self::SLUG_MAIN . '&tab=works&work=' . $work_id;
		if ( ! PFM_Works::work( $work_id ) ) {
			return array( array( self::msg( 'error', __( 'Το έργο δεν βρέθηκε.', 'product-formats' ) ) ), self::SLUG_MAIN . '&tab=works' );
		}
		$name = trim( sanitize_text_field( self::post( 'pfm_name' ) ) );
		if ( '' !== $name ) {
			PFM_Works::rename( $work_id, $name );
		}
		$current = wp_list_pluck( PFM_Works::members( $work_id ), 'id' );
		foreach ( self::post_array( 'pfm_m' ) as $pid => $row ) {
			$pid = (int) $pid;
			if ( ! in_array( $pid, $current, true ) || ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['remove'] ) ) {
				PFM_Works::unassign( $pid );
				continue;
			}
			PFM_Works::set_format( $pid, is_scalar( $row['format'] ?? '' ) ? (string) ( $row['format'] ?? '' ) : '', is_scalar( $row['variant'] ?? '' ) ? (string) ( $row['variant'] ?? '' ) : '' );
		}
		$msgs = self::add_member( $work_id );
		PFM_Works::flush();
		if ( ! PFM_Works::work( $work_id ) ) {
			$msgs[] = self::msg( 'success', __( 'Το έργο έμεινε χωρίς προϊόντα και διαγράφηκε.', 'product-formats' ) );
			return array( $msgs, self::SLUG_MAIN . '&tab=works' );
		}
		array_unshift( $msgs, self::msg( 'success', __( 'Το έργο αποθηκεύτηκε.', 'product-formats' ) ) );
		return array( $msgs, $back );
	}

	private static function do_new_work(): array {
		$name = trim( sanitize_text_field( self::post( 'pfm_name' ) ) );
		$add  = self::post_array( 'pfm_add' );
		$pid  = self::product_id( $add['id'] ?? 0 );
		if ( '' === $name && $pid ) {
			$name = PFM_Suggest::clean_title( get_the_title( $pid ) );
		}
		if ( '' === $name ) {
			return array( array( self::msg( 'error', __( 'Γράψε όνομα έργου ή διάλεξε προϊόν.', 'product-formats' ) ) ), self::SLUG_MAIN . '&tab=works&new=1' );
		}
		$work_id = PFM_Works::create( $name );
		if ( ! $work_id ) {
			return array( array( self::msg( 'error', __( 'Το έργο δεν δημιουργήθηκε.', 'product-formats' ) ) ), self::SLUG_MAIN . '&tab=works&new=1' );
		}
		$msgs = self::add_member( $work_id );
		array_unshift( $msgs, self::msg( 'success', __( 'Το έργο δημιουργήθηκε. Πρόσθεσε τις άλλες μορφές.', 'product-formats' ) ) );
		return array( $msgs, self::SLUG_MAIN . '&tab=works&work=' . $work_id );
	}

	private static function do_merge_work(): array {
		$from = absint( self::post( 'pfm_work' ) );
		$to   = absint( self::post( 'pfm_target' ) );
		if ( ! PFM_Works::work( $from ) || ! PFM_Works::work( $to ) || $from === $to ) {
			return array( array( self::msg( 'error', __( 'Διάλεξε ένα άλλο υπάρχον έργο από τη λίστα.', 'product-formats' ) ) ), self::SLUG_MAIN . '&tab=works&work=' . $from );
		}
		$n = PFM_Works::merge( $from, $to );
		/* translators: %d: number of products */
		return array( array( self::msg( 'success', sprintf( __( 'Μετακινήθηκαν %d προϊόντα.', 'product-formats' ), $n ) ) ), self::SLUG_MAIN . '&tab=works&work=' . $to );
	}

	private static function do_delete_work(): array {
		$work_id = absint( self::post( 'pfm_work' ) );
		if ( '1' !== self::post( 'pfm_confirm' ) ) {
			return array( array( self::msg( 'error', __( 'Επιβεβαίωσε τη διαγραφή.', 'product-formats' ) ) ), self::SLUG_MAIN . '&tab=works&work=' . $work_id );
		}
		if ( PFM_Works::work( $work_id ) ) {
			PFM_Works::delete( $work_id );
		}
		return array( array( self::msg( 'success', __( 'Το έργο διαγράφηκε. Τα προϊόντα δεν άλλαξαν.', 'product-formats' ) ) ), self::SLUG_MAIN . '&tab=works' );
	}

	private static function do_scan(): array {
		$items = PFM_Suggest::scan();
		set_transient(
			self::scan_key(),
			array(
				'time'  => time(),
				'items' => $items,
			),
			self::SCAN_TTL
		);
		/* translators: %d: number of suggestions */
		return array( self::msg( 'success', sprintf( __( 'Η σάρωση βρήκε %d προτάσεις.', 'product-formats' ), count( $items ) ) ) );
	}

	/** Accept one, accept the checked ones, or reject one suggestion. */
	private static function do_suggest(): array {
		$scan = self::scan_get();
		if ( ! $scan ) {
			return array( self::msg( 'error', __( 'Οι προτάσεις έληξαν: κάνε ξανά σάρωση.', 'product-formats' ) ) );
		}
		$by_sig = array();
		foreach ( $scan['items'] as $i => $sug ) {
			$by_sig[ $sug['sig'] ] = $i;
		}

		$reject = sanitize_key( self::post( 'pfm_reject' ) );
		if ( '' !== $reject ) {
			if ( isset( $by_sig[ $reject ] ) ) {
				PFM_Settings::reject( $reject );
				unset( $scan['items'][ $by_sig[ $reject ] ] );
				self::scan_save( $scan );
				return array( self::msg( 'success', __( 'Η πρόταση απορρίφθηκε και δεν θα ξαναφανεί.', 'product-formats' ) ) );
			}
			return array( self::msg( 'error', __( 'Η πρόταση δεν βρέθηκε.', 'product-formats' ) ) );
		}

		$one  = sanitize_key( self::post( 'pfm_one' ) );
		$sigs = '' !== $one ? array( $one ) : array_map( 'sanitize_key', array_filter( self::post_array( 'pfm_pick' ), 'is_string' ) );
		if ( ! $sigs ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε καμία πρόταση.', 'product-formats' ) ) );
		}

		$posted = self::post_array( 'pfm_sug' );
		$done   = 0;
		$msgs   = array();
		foreach ( $sigs as $sig ) {
			if ( ! isset( $by_sig[ $sig ] ) ) {
				continue;
			}
			$sug = $scan['items'][ $by_sig[ $sig ] ];
			$res = self::accept( $sug, is_array( $posted[ $sig ] ?? null ) ? $posted[ $sig ] : array() );
			if ( null === $res ) {
				/* translators: %s: work name */
				$msgs[] = self::msg( 'error', sprintf( __( 'Η πρόταση «%s» δεν εφαρμόστηκε (χρειάζονται τουλάχιστον δύο προϊόντα).', 'product-formats' ), $sug['name'] ) );
				continue;
			}
			++$done;
			unset( $scan['items'][ $by_sig[ $sig ] ] );
		}
		self::scan_save( $scan );
		/* translators: %d: number of works */
		array_unshift( $msgs, self::msg( $done ? 'success' : 'error', sprintf( __( 'Εφαρμόστηκαν %d προτάσεις.', 'product-formats' ), $done ) ) );
		return $msgs;
	}

	private static function scan_save( array $scan ): void {
		$scan['items'] = array_values( $scan['items'] );
		set_transient( self::scan_key(), $scan, self::SCAN_TTL );
	}

	/**
	 * Applies a suggestion with the edits of the form. Member ids come from
	 * the stored scan, never from the form (the form can only leave some out).
	 *
	 * @return int|null Work id, null when fewer than two products remain.
	 */
	private static function accept( array $sug, array $form ): ?int {
		$rows    = is_array( $form['m'] ?? null ) ? $form['m'] : array();
		$members = array();
		foreach ( $sug['members'] as $m ) {
			if ( $m['in_work'] ) {
				$members[] = array( 'id' => (int) $m['id'], 'keep' => true );
				continue;
			}
			$row = is_array( $rows[ $m['id'] ] ?? null ) ? $rows[ $m['id'] ] : array();
			if ( empty( $row['in'] ) || ! self::product_id( $m['id'] ) ) {
				continue;
			}
			$members[] = array(
				'id'      => (int) $m['id'],
				'keep'    => false,
				'format'  => is_scalar( $row['format'] ?? '' ) ? (string) ( $row['format'] ?? '' ) : '',
				'variant' => is_scalar( $row['variant'] ?? '' ) ? (string) ( $row['variant'] ?? '' ) : '',
			);
		}
		if ( count( $members ) < 2 ) {
			return null;
		}
		$work_id = (int) $sug['target'];
		if ( ! $work_id || ! PFM_Works::work( $work_id ) ) {
			$name    = isset( $form['name'] ) && is_scalar( $form['name'] ) ? trim( sanitize_text_field( (string) $form['name'] ) ) : '';
			$work_id = PFM_Works::create( '' !== $name ? $name : $sug['name'] );
		}
		if ( ! $work_id ) {
			return null;
		}
		foreach ( $members as $m ) {
			if ( $m['keep'] ) {
				continue;
			}
			// A product put in a work since the scan stays where it is.
			$old = PFM_Works::work_of( $m['id'] );
			if ( $old && $old !== $work_id ) {
				continue;
			}
			PFM_Works::assign( $m['id'], $work_id, $m['format'], $m['variant'] );
		}
		return $work_id;
	}

	private static function do_save_formats(): array {
		$rows = array();
		foreach ( self::post_array( 'pfm_f' ) as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}
			$rows[] = array(
				'key'      => $row['key'] ?? '',
				'label_el' => $row['label_el'] ?? '',
				'label_en' => $row['label_en'] ?? '',
				'suffixes' => is_scalar( $row['suffixes'] ?? '' ) ? (string) ( $row['suffixes'] ?? '' ) : '',
				'cats'     => is_array( $row['cats'] ?? null ) ? $row['cats'] : array(),
				'enabled'  => ! empty( $row['enabled'] ),
			);
		}
		$msgs = array();
		$new  = self::post_array( 'pfm_new' );
		$key  = sanitize_key( (string) ( $new['key'] ?? '' ) );
		if ( '' !== $key ) {
			$label = trim( sanitize_text_field( (string) ( $new['label_el'] ?? '' ) ) );
			if ( '' === $label || null !== PFM_Settings::format( $key ) ) {
				$msgs[] = self::msg( 'error', __( 'Η νέα μορφή χρειάζεται κλειδί που δεν υπάρχει ήδη και ελληνική ετικέτα.', 'product-formats' ) );
			} else {
				$rows[] = array(
					'key'      => $key,
					'label_el' => $label,
					'label_en' => (string) ( $new['label_en'] ?? '' ),
					'suffixes' => (string) ( $new['suffixes'] ?? '' ),
					'cats'     => array(),
					'enabled'  => true,
				);
			}
		}
		PFM_Settings::save_formats( $rows );
		self::purge_all();
		array_unshift( $msgs, self::msg( 'success', __( 'Οι μορφές αποθηκεύτηκαν.', 'product-formats' ) ) );
		return $msgs;
	}

	private static function do_restore_run(): array {
		$n = PFM_Upsells::restore( self::post( 'pfm_run' ) );
		if ( null === $n ) {
			return array( self::msg( 'error', __( 'Το snapshot δεν βρέθηκε.', 'product-formats' ) ) );
		}
		/* translators: %d: number of products */
		return array( self::msg( 'success', sprintf( __( 'Τα upsells επανήλθαν σε %d προϊόντα.', 'product-formats' ), $n ) ) );
	}

	/* =====================================================================
	 * AJAX
	 * =================================================================== */

	/** Autocomplete (Bible §5): from the 3rd character, 30 results, LIKE with esc_like. */
	public static function ajax_search(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( null, 403 );
		}
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';
		$q    = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) ) : '';
		if ( ! in_array( $type, array( 'work', 'product' ), true ) ) {
			wp_send_json_error( null, 400 );
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
		if ( $len < 3 && ! ( 'product' === $type && ctype_digit( $q ) ) ) {
			wp_send_json_success( array() );
		}
		$out = array();
		if ( 'work' === $type ) {
			$terms = get_terms(
				array(
					'taxonomy'   => PFM_Works::TAX,
					'hide_empty' => false,
					'name__like' => $q,
					'number'     => 30,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$out[] = array(
					'id'    => (int) $t->term_id,
					'label' => $t->name,
					'value' => $t->name,
				);
			}
		} else {
			global $wpdb;
			$statuses = "'" . implode( "','", PFM_Suggest::STATUSES ) . "'";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed status list.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ($statuses) AND ( post_title LIKE %s OR ID = %d ) ORDER BY post_title LIMIT 30",
					'%' . $wpdb->esc_like( $q ) . '%',
					ctype_digit( $q ) ? (int) $q : 0
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$id    = (int) $r['ID'];
				$work  = PFM_Works::work( PFM_Works::work_of( $id ) );
				$label = $r['post_title'] . ' (#' . $id . ( 'publish' !== $r['post_status'] ? ', ' . self::status_label( (string) $r['post_status'] ) : '' ) . ')';
				if ( $work ) {
					/* translators: %s: work name */
					$label .= ' — ' . sprintf( __( 'στο έργο «%s»', 'product-formats' ), $work->name );
				}
				$out[] = array(
					'id'    => $id,
					'label' => $label,
					'value' => (string) $r['post_title'],
				);
			}
		}
		wp_send_json_success( $out );
	}

	public static function ajax_upsells_start(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( array( 'run' => PFM_Upsells::start() ) );
	}

	public static function ajax_upsells_batch(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$run = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['run'] ) ) : '';
		$res = preg_match( '/^\d{14}-[0-9a-f]{6}$/', $run ) ? PFM_Upsells::batch( $run ) : null;
		if ( null === $res ) {
			wp_send_json_error( null, 400 );
		}
		if ( 0 === $res['left'] || 0 === $res['done'] ) {
			$runs  = PFM_Upsells::runs();
			$count = 0;
			foreach ( $runs as $r ) {
				if ( $r['id'] === $run ) {
					$count = count( $r['items'] );
				}
			}
			set_transient(
				'pfm_aui_msg_' . get_current_user_id(),
				array(
					0 === $res['left']
						/* translators: %d: number of products */
						? self::msg( 'success', sprintf( __( 'Τα upsells καθαρίστηκαν σε %d προϊόντα. Το snapshot είναι παρακάτω.', 'product-formats' ), $count ) )
						: self::msg( 'warning', __( 'Ο καθαρισμός σταμάτησε. Δοκίμασε ξανά· όσα έγιναν έχουν snapshot.', 'product-formats' ) ),
				),
				60
			);
		}
		wp_send_json_success( $res );
	}

	/* =====================================================================
	 * Backup (export / import JSON, Bible §11)
	 * =================================================================== */

	public static function route_backup(): void {

		if ( self::SLUG_SET !== self::current_page() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.
		if ( isset( $_GET['pfm_backup_export'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-formats' ) );
			}

			$works = array();
			$terms = get_terms(
				array(
					'taxonomy'   => PFM_Works::TAX,
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$members = array();
				foreach ( PFM_Works::members( (int) $t->term_id ) as $m ) {
					$p         = wc_get_product( $m['id'] );
					$members[] = array(
						'id'      => $m['id'],
						'slug'    => (string) get_post_field( 'post_name', $m['id'] ),
						'sku'     => $p ? (string) $p->get_sku( 'edit' ) : '',
						'format'  => $m['format'],
						'variant' => $m['variant'],
					);
				}
				$works[] = array(
					'name'    => $t->name,
					'members' => $members,
				);
			}
			$state = array(
				'plugin'  => 'product-formats',
				'version' => PFM_VERSION,
				'options' => array(
					PFM_Settings::OPT_SETTINGS => PFM_Settings::get(),
					PFM_Settings::OPT_FORMATS  => PFM_Settings::formats(),
				),
				'works'   => $works,
			);

			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="product-formats-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		if ( isset( $_POST['pfm_import'] ) ) {

			if ( ! isset( $_POST['pfm_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pfm_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-formats' ) );
			}

			$msgs = array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'product-formats' ) ) );

			$up_err  = isset( $_FILES['pfm_import_file']['error'] ) ? (int) $_FILES['pfm_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['pfm_import_file']['size'] ) ? (int) $_FILES['pfm_import_file']['size'] : 0;

			if ( UPLOAD_ERR_OK === $up_err && $up_size > 0 && $up_size <= self::MAX_IMPORT
				&& ! empty( $_FILES['pfm_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['pfm_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp path from PHP.

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['pfm_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );

				$msgs = is_array( $state )
					? self::import_state( $state )
					: array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'product-formats' ) ) );
			} elseif ( $up_size > self::MAX_IMPORT ) {
				$msgs = array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'product-formats' ) ) );
			}

			PFM_Works::flush();
			self::redirect( self::SLUG_SET, $msgs );
		}
	}

	/**
	 * Strict import, per part (Bible §11): an invalid part leaves the current
	 * data untouched with an explicit error; partial success is stated.
	 */
	private static function import_state( array $state ): array {
		$opts = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();
		if ( ( $state['plugin'] ?? '' ) !== 'product-formats' || ( ! $opts && ! isset( $state['works'] ) ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν τα δεδομένα του Product Formats).', 'product-formats' ) ) );
		}
		$ok   = 0;
		$bad  = 0;
		$msgs = array();

		if ( isset( $opts[ PFM_Settings::OPT_SETTINGS ] ) ) {
			if ( is_array( $opts[ PFM_Settings::OPT_SETTINGS ] ) ) {
				PFM_Settings::save( $opts[ PFM_Settings::OPT_SETTINGS ] );
				++$ok;
			} else {
				++$bad;
				$msgs[] = self::msg( 'error', __( 'Οι γενικές ρυθμίσεις δεν ήταν έγκυρες και δεν άλλαξαν.', 'product-formats' ) );
			}
		}
		if ( isset( $opts[ PFM_Settings::OPT_FORMATS ] ) ) {
			if ( is_array( $opts[ PFM_Settings::OPT_FORMATS ] ) ) {
				PFM_Settings::save_formats( $opts[ PFM_Settings::OPT_FORMATS ] );
				++$ok;
			} else {
				++$bad;
				$msgs[] = self::msg( 'error', __( 'Οι μορφές δεν ήταν έγκυρες και δεν άλλαξαν.', 'product-formats' ) );
			}
		}
		if ( isset( $state['works'] ) ) {
			if ( is_array( $state['works'] ) ) {
				list( $w_ok, $p_skip ) = self::import_works( $state['works'] );
				if ( $w_ok ) {
					++$ok;
				}
				if ( $p_skip ) {
					++$bad;
					/* translators: %d: number of products */
					$msgs[] = self::msg( 'warning', sprintf( __( '%d προϊόντα του αρχείου δεν βρέθηκαν σε αυτό το site και παραλείφθηκαν.', 'product-formats' ), $p_skip ) );
				}
			} else {
				++$bad;
				$msgs[] = self::msg( 'error', __( 'Τα έργα δεν ήταν έγκυρα και δεν άλλαξαν.', 'product-formats' ) );
			}
		}
		if ( 0 === $ok ) {
			$msgs[] = self::msg( 'error', __( 'Δεν εισήχθη τίποτα.', 'product-formats' ) );
		} elseif ( $bad > 0 ) {
			array_unshift( $msgs, self::msg( 'warning', __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ.', 'product-formats' ) ) );
		} else {
			array_unshift( $msgs, self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'product-formats' ) ) );
		}
		return $msgs;
	}

	/**
	 * Works from a backup. A product is matched by id when the slug (or
	 * SKU) agrees, else by SKU, else by slug.
	 *
	 * @return array{0: int, 1: int} Works imported, products skipped.
	 */
	private static function import_works( array $works ): array {
		$w_ok   = 0;
		$p_skip = 0;
		foreach ( $works as $w ) {
			if ( ! is_array( $w ) || ! isset( $w['name'], $w['members'] ) || ! is_scalar( $w['name'] ) || ! is_array( $w['members'] ) ) {
				continue;
			}
			$found = array();
			foreach ( array_slice( $w['members'], 0, PFM_Suggest::MAX_MEMBERS ) as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}
				$pid = self::match_product( $m );
				if ( $pid ) {
					$found[] = array( $pid, is_scalar( $m['format'] ?? '' ) ? (string) ( $m['format'] ?? '' ) : '', is_scalar( $m['variant'] ?? '' ) ? (string) ( $m['variant'] ?? '' ) : '' );
				} else {
					++$p_skip;
				}
			}
			if ( ! $found ) {
				continue;
			}
			$name    = trim( sanitize_text_field( (string) $w['name'] ) );
			$work_id = PFM_Works::find_by_name( $name );
			if ( ! $work_id ) {
				$work_id = PFM_Works::create( $name );
			}
			if ( ! $work_id ) {
				continue;
			}
			foreach ( $found as $f ) {
				PFM_Works::assign( $f[0], $work_id, $f[1], $f[2] );
			}
			++$w_ok;
		}
		return array( $w_ok, $p_skip );
	}

	private static function match_product( array $m ): int {
		$slug = isset( $m['slug'] ) && is_scalar( $m['slug'] ) ? sanitize_title( (string) $m['slug'] ) : '';
		$sku  = isset( $m['sku'] ) && is_scalar( $m['sku'] ) ? sanitize_text_field( (string) $m['sku'] ) : '';
		$id   = self::product_id( $m['id'] ?? 0 );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( ( '' !== $slug && get_post_field( 'post_name', $id ) === $slug ) || ( '' !== $sku && $p && $p->get_sku( 'edit' ) === $sku ) ) {
				return $id;
			}
		}
		if ( '' !== $sku ) {
			$by_sku = (int) wc_get_product_id_by_sku( $sku );
			if ( $by_sku && self::product_id( $by_sku ) ) {
				return $by_sku;
			}
		}
		if ( '' !== $slug ) {
			$post = get_page_by_path( $slug, OBJECT, 'product' );
			if ( $post instanceof WP_Post && self::product_id( $post->ID ) ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'pfm_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'pfm_aui_msg_' . get_current_user_id() );
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
		<p class="pfm-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'product-formats' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="pfm-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="pfm-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'product-formats' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="pfm-footer-cta">
			<a class="pfm-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'product-formats' ); ?>
			</a>
			<a class="pfm-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'product-formats' ); ?>
			</a>
		</p>
		<?php
	}
}
