<?php
/**
 * HIC_Admin_UI — menu, settings page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Απόκρυψη καταλόγου» of the shared "Noxpress" menu
 * (admin_menu priority 120), slug hic-settings, with tabs:
 *  - rules  mode, page cache clearing, quick setup for the default
 *           category, the rules (category, subcategories, where its
 *           products are hidden, the category itself, its page) with a
 *           preview of how many products each rule hides
 *  - tools  import from "Hide Categories Or Products On Shop Page",
 *           settings export / import, API
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (HIC_Settings::sanitize), PRG with the message in the
 * transient hic_aui_msg_{uid}. Export: privileged GET (slug + nonce +
 * capability). Saving clears known page caches when that setting is on.
 */

defined( 'ABSPATH' ) || exit;

final class HIC_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'hic-settings';
	const NONCE       = 'hic_admin';
	const NONCE_FIELD = 'hic_nonce';
	const NONCE_BAK   = 'hic_backup';
	const MAX_IMPORT  = 262144;

	/** The option of "Hide Categories Or Products On Shop Page". */
	const OLD_OPT = 'hcps_data';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 120 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HIC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'rules' => __( 'Κανόνες', 'hide-catalog' ),
			'tools' => __( 'Εργαλεία', 'hide-catalog' ),
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
			__( 'Hide Catalog', 'hide-catalog' ),
			__( 'Απόκρυψη καταλόγου', 'hide-catalog' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'hide-catalog' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'rules';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'hic-admin', HIC_URL . 'assets/admin.css', array(), HIC_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'hide-catalog' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap hic-wrap">
			<h1><?php esc_html_e( 'Hide Catalog', 'hide-catalog' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Απόκρυψη κατηγοριών και των προϊόντων τους από το shop, τις λίστες, την αναζήτηση και τα blocks, χωρίς αλλαγή στα προϊόντα.', 'hide-catalog' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper hic-tabs">
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
			if ( 'tools' === $tab ) {
				self::render_tools();
			} else {
				self::render_rules();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Mode radios (off / test / live). */
	private static function mode_field( string $value ): void {
		$opts = array(
			'off'  => __( 'Ανενεργό', 'hide-catalog' ),
			'test' => __( 'Λειτουργία δοκιμής: οι κανόνες ισχύουν μόνο για τους διαχειριστές του καταστήματος', 'hide-catalog' ),
			'live' => __( 'Ενεργό για όλους', 'hide-catalog' ),
		);
		foreach ( $opts as $k => $label ) {
			echo '<label class="hic-check"><input type="radio" name="hic_mode" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'hide-catalog' ) . ' <code>define( \'HIC_DISABLE\', true );</code></p>';
	}

	/** True when FiboSearch (free or Pro) is active. */
	private static function fibosearch_active(): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			if ( is_string( $p ) && 0 === strpos( $p, 'ajax-search-for-woocommerce' ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when "Hide Categories Or Products On Shop Page" is active. */
	private static function old_plugin_active(): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			if ( is_string( $p ) && 0 === strpos( $p, 'hide-categories-or-products-on-shop-page/' ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Tab: rules
	 * ------------------------------------------------------------------- */

	private static function render_rules(): void {
		$s       = HIC_Settings::get();
		$default = (int) get_option( 'default_product_cat', 0 );
		$has_def = false;
		foreach ( $s['rules'] as $r ) {
			if ( $r['cat'] === $default ) {
				$has_def = true;
			}
		}
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="hic_action" value="save_rules" />

			<h2 class="hic-h2"><?php esc_html_e( 'Λειτουργία', 'hide-catalog' ); ?></h2>
			<div class="hic-zone">
				<?php
				if ( Hide_Catalog::killed() ) {
					echo '<p class="hic-warn">' . esc_html__( 'Το HIC_DISABLE είναι ενεργό στο wp-config.php: κανένας κανόνας δεν ισχύει.', 'hide-catalog' ) . '</p>';
				}
				self::mode_field( $s['mode'] );
				?>
				<label class="hic-check hic-mt-8"><input type="checkbox" name="hic_purge" value="1" <?php checked( $s['purge'] ); ?> /> <?php esc_html_e( 'Καθαρισμός της page cache (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache) σε κάθε αποθήκευση', 'hide-catalog' ); ?></label>
				<?php if ( self::fibosearch_active() ) : ?>
					<p class="hic-warn hic-mt-8"><?php esc_html_e( 'Το FiboSearch έχει δικό του ευρετήριο αναζήτησης: τα αποτελέσματά του δεν ακολουθούν ακόμα την απόκρυψη από την αναζήτηση.', 'hide-catalog' ); ?></p>
				<?php endif; ?>
				<?php if ( self::old_plugin_active() ) : ?>
					<p class="hic-warn hic-mt-8"><?php esc_html_e( 'Το «Hide Categories Or Products On Shop Page» είναι ακόμα ενεργό. Μετέφερε τη ρύθμισή του από τα Εργαλεία και μετά απενεργοποίησέ το.', 'hide-catalog' ); ?></p>
				<?php endif; ?>
			</div>

			<h2 class="hic-h2"><?php esc_html_e( 'Κανόνες', 'hide-catalog' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ένας κανόνας ανά κατηγορία. Ό,τι δεν τσεκάρεις μένει όπως είναι. Τα προϊόντα δεν αλλάζουν: η απόκρυψη γίνεται τη στιγμή που φορτώνει η σελίδα και σταματά μόλις απενεργοποιηθεί το plugin.', 'hide-catalog' ); ?></p>
			<?php
			$i = 0;
			foreach ( $s['rules'] as $r ) {
				self::rule_card( $i, $r );
				++$i;
			}
			if ( $i < HIC_Settings::MAX_RULES ) {
				self::rule_card( $i, null );
			}
			?>
			<p class="hic-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'hide-catalog' ); ?></button></p>
		</form>

		<?php if ( $default > 0 && ! $has_def && term_exists( $default, 'product_cat' ) ) : ?>
			<h2 class="hic-h2"><?php esc_html_e( 'Γρήγορη ρύθμιση', 'hide-catalog' ); ?></h2>
			<div class="hic-zone">
				<form method="post" class="hic-inline-form">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="hic_action" value="preset_default" />
					<button type="submit" class="button">
						<?php
						$t = get_term( $default, 'product_cat' );
						/* translators: %s: name of the default product category (e.g. Uncategorized) */
						printf( esc_html__( 'Απόκρυψη της «%s» από τις λίστες κατηγοριών', 'hide-catalog' ), esc_html( $t instanceof WP_Term ? $t->name : '' ) );
						?>
					</button>
				</form>
				<p class="description hic-mt-8"><?php esc_html_e( 'Η προεπιλεγμένη κατηγορία του WooCommerce δεν σβήνεται. Αυτό την κρύβει από τις λίστες, τις κατηγορίες των προϊόντων και τα breadcrumbs· τα προϊόντα της μένουν ορατά.', 'hide-catalog' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * One rule card (an empty one for a new rule).
	 *
	 * @param int        $i Row index.
	 * @param array|null $r Rule, or null for a new one.
	 */
	private static function rule_card( int $i, ?array $r ): void {
		$new  = null === $r;
		$r    = $new ? HIC_Settings::rule_defaults() : $r;
		$name = 'hic_r[' . $i . ']';
		$term = $new ? null : get_term( $r['cat'], 'product_cat' );

		$dropdown = wp_dropdown_categories(
			array(
				'taxonomy'          => 'product_cat',
				'hide_empty'        => 0,
				'hierarchical'      => 1,
				'name'              => $name . '[cat]',
				'id'                => 'hic-cat-' . $i,
				'selected'          => (int) $r['cat'],
				'show_option_none'  => __( '— Διάλεξε κατηγορία —', 'hide-catalog' ),
				'option_none_value' => '0',
				'orderby'           => 'name',
				'echo'              => 0,
			)
		);

		$where = array(
			'shop'     => __( 'Σελίδα shop', 'hide-catalog' ),
			'listings' => __( 'Άλλες λίστες προϊόντων (άλλες κατηγορίες, ετικέτες, χαρακτηριστικά)', 'hide-catalog' ),
			'search'   => __( 'Αναζήτηση', 'hide-catalog' ),
			'related'  => __( 'Σχετικά προϊόντα, upsells και cross-sells', 'hide-catalog' ),
			'blocks'   => __( 'Shortcodes και blocks προϊόντων', 'hide-catalog' ),
		);
		$archive = array(
			'open'     => __( 'Ανοιχτή (προεπιλογή)', 'hide-catalog' ),
			'noindex'  => __( 'Ανοιχτή, χωρίς ευρετηρίαση (noindex)', 'hide-catalog' ),
			'404'      => __( 'Σελίδα 404', 'hide-catalog' ),
			'redirect' => __( 'Ανακατεύθυνση στο shop', 'hide-catalog' ),
		);
		?>
		<fieldset class="hic-zone hic-rule-card<?php echo $new ? ' hic-rule-card--new' : ''; ?>">
			<legend><?php echo $new ? esc_html__( 'Νέος κανόνας', 'hide-catalog' ) : esc_html( $term instanceof WP_Term ? $term->name : '#' . $r['cat'] ); ?></legend>
			<p class="hic-field"><span><?php esc_html_e( 'Κατηγορία', 'hide-catalog' ); ?></span><?php echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?></p>
			<label class="hic-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[children]" value="1" <?php checked( $r['children'] ); ?> /> <?php esc_html_e( 'Και οι υποκατηγορίες της', 'hide-catalog' ); ?></label>

			<h3 class="hic-h3"><?php esc_html_e( 'Τα προϊόντα της κρύβονται από', 'hide-catalog' ); ?></h3>
			<?php foreach ( $where as $k => $label ) : ?>
				<label class="hic-check"><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $k . ']' ); ?>" value="1" <?php checked( $r[ $k ] ); ?> /> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
			<label class="hic-check hic-mt-8"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[all_only]" value="1" <?php checked( $r['all_only'] ); ?> /> <?php esc_html_e( 'Μόνο όταν όλες οι κατηγορίες του προϊόντος είναι κρυφές', 'hide-catalog' ); ?></label>
			<span class="hic-hint"><?php esc_html_e( 'Χωρίς αυτό, ένα προϊόν που ανήκει και σε ορατή κατηγορία κρύβεται κι αυτό.', 'hide-catalog' ); ?></span>

			<h3 class="hic-h3"><?php esc_html_e( 'Η ίδια η κατηγορία', 'hide-catalog' ); ?></h3>
			<label class="hic-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[terms]" value="1" <?php checked( $r['terms'] ); ?> /> <?php esc_html_e( 'Κρυφή από τις λίστες κατηγοριών, τις κατηγορίες των προϊόντων και τα breadcrumbs', 'hide-catalog' ); ?></label>
			<p class="hic-field"><span><?php esc_html_e( 'Η σελίδα της κατηγορίας', 'hide-catalog' ); ?></span><select name="<?php echo esc_attr( $name ); ?>[archive]">
				<?php
				foreach ( $archive as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r['archive'], $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				?>
			</select></p>

			<?php if ( ! $new ) : ?>
				<p class="hic-row">
					<?php
					list( $total, $mixed ) = HIC_Rules::preview( $r );
					/* translators: 1: number of published products, 2: how many of them are also in another category */
					echo '<span class="hic-badge">' . esc_html( sprintf( __( '%1$d δημοσιευμένα προϊόντα, %2$d από αυτά και σε άλλη κατηγορία', 'hide-catalog' ), $total, $mixed ) ) . '</span>';
					if ( ! HIC_Settings::rule_does_something( $r ) ) {
						echo ' <span class="hic-badge hic-badge--warn">' . esc_html__( 'δεν κρύβει τίποτα', 'hide-catalog' ) . '</span>';
					}
					?>
				</p>
				<p class="hic-row"><label class="hic-inline hic-warn"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" /> <?php esc_html_e( 'Διαγραφή', 'hide-catalog' ); ?></label></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$old = get_option( self::OLD_OPT, null );
		?>
		<?php if ( is_array( $old ) && ! empty( $old['category'] ) ) : ?>
			<h2 class="hic-h2"><?php esc_html_e( 'Μεταφορά από το Hide Categories Or Products On Shop Page', 'hide-catalog' ); ?></h2>
			<div class="hic-zone">
				<p class="description"><?php esc_html_e( 'Φτιάχνει τον αντίστοιχο κανόνα από τη ρύθμιση του παλιού plugin (μόνο ανάγνωση: το παλιό plugin δεν αλλάζει). Μετά απενεργοποίησέ το και έλεγξε το shop.', 'hide-catalog' ); ?></p>
				<form method="post" class="hic-inline-form">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="hic_action" value="import_old" />
					<button type="submit" class="button"><?php esc_html_e( 'Μεταφορά ρύθμισης', 'hide-catalog' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<h2 class="hic-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'hide-catalog' ); ?></h2>
		<div class="hic-zone">
			<form method="post" enctype="multipart/form-data" class="hic-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'hic_backup_nonce' ); ?>
				<input type="file" name="hic_import_file" accept=".json,application/json" />
				<button type="submit" name="hic_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'hide-catalog' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&hic_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'hide-catalog' ); ?></a>
			<p class="description hic-mt-8"><?php esc_html_e( 'Οι κανόνες δείχνουν τις κατηγορίες με το id τους: σε άλλο site κρατιούνται μόνο όσοι βρίσκουν κατηγορία με το ίδιο id.', 'hide-catalog' ); ?></p>
		</div>

		<h2 class="hic-h2"><?php esc_html_e( 'Για προγραμματιστές', 'hide-catalog' ); ?></h2>
		<div class="hic-zone">
			<table class="widefat hic-table">
				<tbody>
					<tr><td><code>hic_hidden_terms( $context )</code></td><td><?php esc_html_e( 'Οι κρυφές κατηγορίες (ids, μαζί με τις υποκατηγορίες) για το τρέχον αίτημα. Context: shop, listings, search, related, blocks ή terms (η ίδια η κατηγορία). Κενό όταν το plugin δεν ισχύει για αυτό το αίτημα.', 'hide-catalog' ); ?></td></tr>
					<tr><td><code>hic_hidden_terms</code></td><td><?php esc_html_e( 'Φίλτρο στο αποτέλεσμα της hic_hidden_terms() (ids, context).', 'hide-catalog' ); ?></td></tr>
					<tr><td><code>HIC_DISABLE</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php που σταματά κάθε κανόνα.', 'hide-catalog' ); ?></td></tr>
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
		if ( isset( $_POST['hic_import'] ) ) {
			if ( ! isset( $_POST['hic_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['hic_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'hide-catalog' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['hic_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'hide-catalog' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['hic_action'] ) ) ) {
			case 'save_rules':
				self::redirect( 'rules', self::do_save_rules() );
				break;
			case 'preset_default':
				self::redirect( 'rules', self::do_preset_default() );
				break;
			case 'import_old':
				self::redirect( 'rules', self::do_import_old() );
				break;
			default:
				self::redirect( 'rules', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'hide-catalog' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'hic_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by HIC_Settings::sanitize()). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by HIC_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/** Saves, clears caches, and returns the messages. */
	private static function store( array $raw, array $msgs ): array {
		HIC_Settings::save( $raw );
		HIC_Rules::flush();
		$s = HIC_Settings::get();

		// Older product grid blocks cache their results under this version.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product_query', true );
		}
		if ( $s['purge'] ) {
			$done = self::purge_caches();
			if ( $done ) {
				/* translators: %s: names of the page caches that were cleared */
				$msgs[] = self::msg( 'info', sprintf( __( 'Καθαρίστηκε η page cache: %s.', 'hide-catalog' ), implode( ', ', $done ) ) );
			}
		}
		return $msgs;
	}

	/**
	 * Clears the page caches we know, through their own public functions.
	 *
	 * @return string[] Names of the caches cleared.
	 */
	private static function purge_caches(): array {
		$done = array();
		try {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
				$done[] = 'WP Rocket';
			}
			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- LiteSpeed Cache's own action.
				$done[] = 'LiteSpeed Cache';
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
				$done[] = 'W3 Total Cache';
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
				$done[] = 'WP Super Cache';
			}
		} catch ( \Throwable $e ) {
			return $done;
		}
		return $done;
	}

	private static function do_save_rules(): array {
		$raw          = HIC_Settings::get();
		$raw['mode']  = sanitize_key( self::post( 'hic_mode' ) );
		$raw['purge'] = '1' === self::post( 'hic_purge' );

		$rules = array();
		$dupe  = false;
		$seen  = array();
		foreach ( self::post_array( 'hic_r' ) as $row ) {
			if ( ! is_array( $row ) || ( isset( $row['delete'] ) && '1' === $row['delete'] ) ) {
				continue;
			}
			$cat = isset( $row['cat'] ) && is_scalar( $row['cat'] ) ? (int) $row['cat'] : 0;
			if ( $cat <= 0 ) {
				continue;
			}
			if ( isset( $seen[ $cat ] ) ) {
				$dupe = true;
				continue;
			}
			$seen[ $cat ] = true;
			$rule         = array( 'cat' => $cat );
			foreach ( array( 'children', 'shop', 'listings', 'search', 'related', 'blocks', 'terms', 'all_only' ) as $k ) {
				$rule[ $k ] = isset( $row[ $k ] ) && '1' === $row[ $k ];
			}
			$rule['archive'] = isset( $row['archive'] ) && is_string( $row['archive'] ) ? $row['archive'] : 'open';
			$rules[]         = $rule;
		}
		$raw['rules'] = $rules;

		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'hide-catalog' ) ) );
		if ( $dupe ) {
			$msgs[] = self::msg( 'warning', __( 'Δύο κανόνες είχαν την ίδια κατηγορία: κρατήθηκε ο πρώτος.', 'hide-catalog' ) );
		}
		if ( count( $rules ) > HIC_Settings::MAX_RULES ) {
			$msgs[] = self::msg( 'warning', __( 'Κρατήθηκαν μόνο οι πρώτοι 50 κανόνες.', 'hide-catalog' ) );
		}
		$msgs = self::store( $raw, $msgs );

		$s = HIC_Settings::get();
		if ( 'off' === $s['mode'] && $s['rules'] ) {
			$msgs[] = self::msg( 'info', __( 'Η λειτουργία είναι «Ανενεργό»: οι κανόνες δεν ισχύουν ακόμα.', 'hide-catalog' ) );
		}
		foreach ( $s['rules'] as $r ) {
			if ( ! HIC_Settings::rule_does_something( $r ) ) {
				$msgs[] = self::msg( 'warning', __( 'Ένας κανόνας δεν έχει τσεκαρισμένη καμία επιλογή και δεν κρύβει τίποτα.', 'hide-catalog' ) );
				break;
			}
		}
		return $msgs;
	}

	/** Quick setup: the default category leaves the category lists. */
	private static function do_preset_default(): array {
		$default = (int) get_option( 'default_product_cat', 0 );
		if ( $default <= 0 || ! term_exists( $default, 'product_cat' ) ) {
			return array( self::msg( 'error', __( 'Δεν βρέθηκε η προεπιλεγμένη κατηγορία.', 'hide-catalog' ) ) );
		}
		$raw = HIC_Settings::get();
		foreach ( $raw['rules'] as $r ) {
			if ( $r['cat'] === $default ) {
				return array( self::msg( 'info', __( 'Υπάρχει ήδη κανόνας για αυτή την κατηγορία.', 'hide-catalog' ) ) );
			}
		}
		$rule            = HIC_Settings::rule_defaults();
		$rule['cat']     = $default;
		$rule['terms']   = true;
		$raw['rules'][]  = $rule;
		$msgs            = array( self::msg( 'success', __( 'Ο κανόνας προστέθηκε.', 'hide-catalog' ) ) );
		$msgs            = self::store( $raw, $msgs );
		if ( 'off' === HIC_Settings::get()['mode'] ) {
			$msgs[] = self::msg( 'info', __( 'Η λειτουργία είναι «Ανενεργό»: οι κανόνες δεν ισχύουν ακόμα.', 'hide-catalog' ) );
		}
		return $msgs;
	}

	/**
	 * Builds the rule of "Hide Categories Or Products On Shop Page" (option
	 * hcps_data, read once on request). Its "category" option and its
	 * category widget option hide the category in lists; its "product"
	 * option hides the products on the shop, the product pages and the
	 * product search.
	 */
	private static function do_import_old(): array {
		$old = get_option( self::OLD_OPT, null );
		$cat = is_array( $old ) && isset( $old['category'] ) && is_scalar( $old['category'] ) ? (int) $old['category'] : 0;
		if ( $cat <= 0 || ! term_exists( $cat, 'product_cat' ) ) {
			return array( self::msg( 'error', __( 'Η ρύθμιση του παλιού plugin δεν έχει έγκυρη κατηγορία.', 'hide-catalog' ) ) );
		}
		$opts            = isset( $old['options'] ) && is_array( $old['options'] ) ? $old['options'] : array();
		$rule            = HIC_Settings::rule_defaults();
		$rule['cat']     = $cat;
		$rule['children'] = false;
		$rule['terms']   = in_array( 'category', $opts, true ) || ( isset( $old['widget'] ) && 'yes' === $old['widget'] );
		if ( in_array( 'product', $opts, true ) ) {
			$rule['shop']     = true;
			$rule['listings'] = true;
			$rule['search']   = true;
		}
		$raw   = HIC_Settings::get();
		$rules = array();
		foreach ( $raw['rules'] as $r ) {
			if ( $r['cat'] !== $cat ) {
				$rules[] = $r;
			}
		}
		$rules[]      = $rule;
		$raw['rules'] = $rules;
		$msgs         = array( self::msg( 'success', __( 'Η ρύθμιση μεταφέρθηκε. Έλεγξε τον κανόνα, βάλε τη λειτουργία σε δοκιμή ή ενεργό και απενεργοποίησε το παλιό plugin.', 'hide-catalog' ) ) );
		return self::store( $raw, $msgs );
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'hic_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'hide-catalog' ) );
		}
		$state = array(
			'plugin'  => 'hide-catalog',
			'version' => HIC_VERSION,
			'options' => array(
				HIC_Settings::OPT => HIC_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="hide-catalog-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob must be an object; it goes through
	 * HIC_Settings::sanitize() (the same validation as the forms). An invalid
	 * file leaves the current settings untouched and says so.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['hic_import_file']['error'] ) ? (int) $_FILES['hic_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['hic_import_file']['size'] ) ? (int) $_FILES['hic_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'hide-catalog' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['hic_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['hic_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'hide-catalog' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['hic_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'hide-catalog' ) ) );
		}
		$blob = isset( $state['options'][ HIC_Settings::OPT ] ) ? $state['options'][ HIC_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Hide Catalog).', 'hide-catalog' ) ) );
		}
		$in   = isset( $blob['rules'] ) && is_array( $blob['rules'] ) ? count( $blob['rules'] ) : 0;
		$msgs = self::store( $blob, array() );
		$kept = count( HIC_Settings::get()['rules'] );
		if ( $kept < $in ) {
			/* translators: 1: rules kept, 2: rules in the file */
			array_unshift( $msgs, self::msg( 'warning', sprintf( __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: κρατήθηκαν %1$d από %2$d κανόνες (οι υπόλοιποι δείχνουν κατηγορία που δεν υπάρχει εδώ).', 'hide-catalog' ), $kept, $in ) ) );
		} else {
			array_unshift( $msgs, self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'hide-catalog' ) ) );
		}
		return $msgs;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'hic_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'hic_aui_msg_' . get_current_user_id() );
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
		<p class="hic-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'hide-catalog' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="hic-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="hic-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'hide-catalog' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="hic-footer-cta">
			<a class="hic-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'hide-catalog' ); ?>
			</a>
			<a class="hic-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'hide-catalog' ); ?>
			</a>
		</p>
		<?php
	}
}
