<?php
/**
 * PTB_Admin_UI — menu, settings page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Καρτέλες» of the shared "Noxpress" menu (admin_menu
 * priority 350), slug ptb-settings, with tabs:
 *  - settings  mode, visitor language and texts, video posters, bridge,
 *              page cache, uninstall, order of the page's tabs
 *  - shared    the shared tabs and their rules
 *  - import    from "Custom Product Tabs for WooCommerce" (preview,
 *              import, undo), settings export / import
 *  - dev       API
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation, PRG with the message in the transient
 * ptb_aui_msg_{uid}. Export: privileged GET (slug + nonce + capability).
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'ptb-settings';
	const NONCE       = 'ptb_admin';
	const NONCE_FIELD = 'ptb_nonce';
	const NONCE_BAK   = 'ptb_backup';
	const MAX_IMPORT  = 1048576;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 350 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'wb_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PTB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'settings' => __( 'Ρυθμίσεις', 'product-tabs' ),
			'shared'   => __( 'Κοινές καρτέλες', 'product-tabs' ),
			'import'   => __( 'Εισαγωγή και backup', 'product-tabs' ),
			'dev'      => __( 'Για προγραμματιστές', 'product-tabs' ),
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
			__( 'Product Tabs', 'product-tabs' ),
			__( 'Καρτέλες', 'product-tabs' ),
			Product_Tabs::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'product-tabs' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'settings';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'ptb-admin', PTB_URL . 'assets/admin.css', array(), PTB_VERSION );
	}

	/** Reminder to deactivate the old plugin while it runs (Bible §14). */
	public static function wb_notice(): void {
		if ( ! current_user_can( Product_Tabs::CAP ) || ! PTB_Import::wb_plugin_active() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'noxpress_page_' . self::SLUG ), true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Το «Custom Product Tabs for WooCommerce» είναι ακόμα ενεργό. Κάνε την εισαγωγή από το Product Tabs, έλεγξε μερικά προϊόντα και μετά απενεργοποίησέ το.', 'product-tabs' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=import' ) ) . '">' . esc_html__( 'Εισαγωγή', 'product-tabs' ) . '</a></p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Product_Tabs::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-tabs' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap ptb-wrap">
			<h1><?php esc_html_e( 'Product Tabs', 'product-tabs' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Επιπλέον καρτέλες στη σελίδα προϊόντος, ανά προϊόν και κοινές, βίντεο που φορτώνουν μόνο με κλικ και μία σειρά για όλες τις καρτέλες.', 'product-tabs' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper ptb-tabs">
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
			if ( 'shared' === $tab ) {
				self::render_shared();
			} elseif ( 'import' === $tab ) {
				self::render_import();
			} elseif ( 'dev' === $tab ) {
				self::render_dev();
			} else {
				self::render_settings();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Mode radios (off / test / live). */
	private static function mode_field( string $value ): void {
		$opts = array(
			'off'  => __( 'Ανενεργό', 'product-tabs' ),
			'test' => __( 'Λειτουργία δοκιμής: οι καρτέλες και η νέα σειρά φαίνονται μόνο στους διαχειριστές του καταστήματος', 'product-tabs' ),
			'live' => __( 'Ενεργό για όλους', 'product-tabs' ),
		);
		foreach ( $opts as $k => $label ) {
			echo '<label class="ptb-check"><input type="radio" name="ptb_mode" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'product-tabs' ) . ' <code>define( \'PTB_DISABLE\', true );</code></p>';
	}

	/** Labels of the known tabs (order setting). */
	private static function known_labels(): array {
		return array(
			'description'            => __( 'Περιγραφή (WooCommerce)', 'product-tabs' ),
			'additional_information' => __( 'Πρόσθετες πληροφορίες (WooCommerce)', 'product-tabs' ),
			'reviews'                => __( 'Κριτικές (WooCommerce)', 'product-tabs' ),
			'tp_care'                => __( 'Φροντίδα & Ασφάλεια (Theme Patcher)', 'product-tabs' ),
			'crt_creators'           => __( 'Δημιουργοί (Featured Creators)', 'product-tabs' ),
			'bpv_preview'            => __( 'Ξεφυλλίστε το βιβλίο (Book Preview)', 'product-tabs' ),
		);
	}

	private static function text_labels(): array {
		return array(
			'trailer'      => __( 'Τίτλος καρτέλας trailer', 'product-tabs' ),
			'presentation' => __( 'Τίτλος καρτέλας παρουσίασης', 'product-tabs' ),
			'play'         => __( 'Ετικέτα κουμπιού αναπαραγωγής (για αναγνώστες οθόνης, %s = ο τίτλος)', 'product-tabs' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Tab: settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s = PTB_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="ptb_action" value="save_settings" />

			<h2 class="ptb-h2"><?php esc_html_e( 'Λειτουργία', 'product-tabs' ); ?></h2>
			<div class="ptb-zone">
				<?php
				if ( Product_Tabs::killed() ) {
					echo '<p class="ptb-warn">' . esc_html__( 'Το PTB_DISABLE είναι ενεργό στο wp-config.php: καμία καρτέλα δεν αλλάζει.', 'product-tabs' ) . '</p>';
				}
				self::mode_field( $s['mode'] );
				?>
				<label class="ptb-check ptb-mt-8"><input type="checkbox" name="ptb_thumbs" value="1" <?php checked( $s['thumbs'] ); ?> /> <?php esc_html_e( 'Εικόνα αφίσας για τα βίντεο YouTube (από το i.ytimg.com· χωρίς αυτό, σκούρα αφίσα με κουμπί)', 'product-tabs' ); ?></label>
				<label class="ptb-check"><input type="checkbox" name="ptb_bridge" value="1" <?php checked( $s['bridge'] ); ?> /> <?php esc_html_e( 'Το shortcode [wb_cpt_youtube_embed_shortcode] δουλεύει και μετά την απενεργοποίηση του «Custom Product Tabs for WooCommerce»', 'product-tabs' ); ?></label>
				<label class="ptb-check"><input type="checkbox" name="ptb_hide_wb" value="1" <?php checked( $s['hide_wb'] ); ?> /> <?php esc_html_e( 'Όσο τρέχουν και τα δύο, κρύβονται οι καρτέλες του παλιού plugin στα προϊόντα που έχουν εισαχθεί', 'product-tabs' ); ?></label>
				<label class="ptb-check"><input type="checkbox" name="ptb_purge" value="1" <?php checked( $s['purge'] ); ?> /> <?php esc_html_e( 'Καθαρισμός της page cache σε κάθε αποθήκευση', 'product-tabs' ); ?></label>
				<label class="ptb-check"><input type="checkbox" name="ptb_wipe" value="1" <?php checked( $s['wipe'] ); ?> /> <?php esc_html_e( 'Διαγραφή όλων των καρτελών (ανά προϊόν και κοινών) όταν διαγραφεί το plugin', 'product-tabs' ); ?></label>
			</div>

			<h2 class="ptb-h2"><?php esc_html_e( 'Κείμενα για τους επισκέπτες', 'product-tabs' ); ?></h2>
			<div class="ptb-zone">
				<span class="ptb-label"><?php esc_html_e( 'Γλώσσα των κειμένων', 'product-tabs' ); ?></span>
				<label class="ptb-inline"><input type="radio" name="ptb_lang" value="auto" <?php checked( $s['lang'], 'auto' ); ?> /> <?php esc_html_e( 'Αυτόματα (γλώσσα του site)', 'product-tabs' ); ?></label>
				<label class="ptb-inline"><input type="radio" name="ptb_lang" value="el" <?php checked( $s['lang'], 'el' ); ?> /> Ελληνικά</label>
				<label class="ptb-inline"><input type="radio" name="ptb_lang" value="en" <?php checked( $s['lang'], 'en' ); ?> /> English</label>
				<table class="ptb-table ptb-texts ptb-mt-8">
					<thead><tr><th></th><th><?php esc_html_e( 'Ελληνικά', 'product-tabs' ); ?></th><th><?php esc_html_e( 'Αγγλικά', 'product-tabs' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( self::text_labels() as $k => $label ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><input type="text" class="ptb-wide" name="ptb_texts[<?php echo esc_attr( $k ); ?>][el]" value="<?php echo esc_attr( $s['texts'][ $k ]['el'] ); ?>" placeholder="<?php echo esc_attr( PTB_Settings::TEXTS[ $k ][0] ); ?>" maxlength="<?php echo (int) PTB_Settings::MAX_TITLE; ?>" /></td>
							<td><input type="text" class="ptb-wide" name="ptb_texts[<?php echo esc_attr( $k ); ?>][en]" value="<?php echo esc_attr( $s['texts'][ $k ]['en'] ); ?>" placeholder="<?php echo esc_attr( PTB_Settings::TEXTS[ $k ][1] ); ?>" maxlength="<?php echo (int) PTB_Settings::MAX_TITLE; ?>" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Κενό πεδίο = το προεπιλεγμένο κείμενο. Κενό αγγλικό κείμενο δείχνει το ελληνικό.', 'product-tabs' ); ?></p>
			</div>

			<h2 class="ptb-h2"><?php esc_html_e( 'Σειρά των καρτελών', 'product-tabs' ); ?></h2>
			<div class="ptb-zone">
				<p class="description"><?php esc_html_e( 'Θέση: μικρότερος αριθμός = πιο αριστερά (το WooCommerce βάζει Περιγραφή 10, Πρόσθετες πληροφορίες 20, Κριτικές 30). Κενό = όπως είναι. Οι καρτέλες κάθε προϊόντος και οι κοινές έχουν τη δική τους θέση.', 'product-tabs' ); ?></p>
				<table class="ptb-table ptb-mt-8">
					<thead><tr><th><?php esc_html_e( 'Καρτέλα', 'product-tabs' ); ?></th><th><?php esc_html_e( 'Θέση', 'product-tabs' ); ?></th><th><?php esc_html_e( 'Νέος τίτλος (ελληνικά)', 'product-tabs' ); ?></th><th><?php esc_html_e( 'Νέος τίτλος (αγγλικά)', 'product-tabs' ); ?></th><th><?php esc_html_e( 'Απόκρυψη', 'product-tabs' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( self::known_labels() as $k => $label ) : ?>
						<?php $o = $s['order'][ $k ]; ?>
						<tr>
							<td><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $k ); ?></code></td>
							<td><input type="number" class="ptb-num" min="1" max="200" name="ptb_order[<?php echo esc_attr( $k ); ?>][priority]" value="<?php echo $o['priority'] > 0 ? (int) $o['priority'] : ''; ?>" /></td>
							<td><input type="text" name="ptb_order[<?php echo esc_attr( $k ); ?>][el]" value="<?php echo esc_attr( $o['el'] ); ?>" maxlength="<?php echo (int) PTB_Settings::MAX_TITLE; ?>" /></td>
							<td><input type="text" name="ptb_order[<?php echo esc_attr( $k ); ?>][en]" value="<?php echo esc_attr( $o['en'] ); ?>" maxlength="<?php echo (int) PTB_Settings::MAX_TITLE; ?>" /></td>
							<td><input type="checkbox" name="ptb_order[<?php echo esc_attr( $k ); ?>][hide]" value="1" <?php checked( $o['hide'] ); ?> /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p class="ptb-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'product-tabs' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: shared
	 * ------------------------------------------------------------------- */

	private static function render_shared(): void {
		$tabs = PTB_Settings::shared();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="ptb_action" value="save_shared" />
			<p class="description"><?php esc_html_e( 'Μία καρτέλα για πολλά προϊόντα, π.χ. «Αποστολές & επιστροφές» σε όλα ή «Οδηγός μεγεθών» σε μια κατηγορία. Ένα προϊόν δείχνει την καρτέλα όταν ταιριάζει σε έναν τουλάχιστον κανόνα· στο κουτί «Καρτέλες προϊόντος» μπορείς να την κρύψεις ή να γράψεις δικό του κείμενο.', 'product-tabs' ); ?></p>
			<?php
			$i = 0;
			foreach ( $tabs as $t ) {
				self::shared_card( $i, $t );
				++$i;
			}
			if ( $i < PTB_Settings::MAX_SHARED ) {
				self::shared_card( $i, null );
			}
			?>
			<p class="ptb-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'product-tabs' ); ?></button></p>
		</form>
		<?php
	}

	/** Options of a term select. */
	private static function term_options( string $tax, array $selected ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
			)
		);
		$out = '';
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof WP_Term ) {
				$out .= '<option value="' . (int) $term->term_id . '" ' . selected( in_array( (int) $term->term_id, $selected, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		return $out;
	}

	/**
	 * One shared tab card (an empty one for a new tab).
	 *
	 * @param int        $i Row index.
	 * @param array|null $t Tab, or null for a new one.
	 */
	private static function shared_card( int $i, ?array $t ): void {
		$new   = null === $t;
		$t     = $new ? PTB_Settings::shared_defaults() : $t;
		$n     = 'ptb_s[' . $i . ']';
		$kinds = array(
			'simple'       => __( 'Απλά', 'product-tabs' ),
			'variable'     => __( 'Με παραλλαγές', 'product-tabs' ),
			'grouped'      => __( 'Ομαδοποιημένα', 'product-tabs' ),
			'external'     => __( 'Εξωτερικά', 'product-tabs' ),
			'downloadable' => __( 'Downloadable', 'product-tabs' ),
			'virtual'      => __( 'Εικονικά', 'product-tabs' ),
		);
		?>
		<fieldset class="ptb-zone ptb-rule-card<?php echo $new ? ' ptb-rule-card--new' : ''; ?>">
			<legend><?php echo $new ? esc_html__( 'Νέα κοινή καρτέλα', 'product-tabs' ) : esc_html( $t['title_el'] ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $t['id'] ); ?>" />
			<div class="ptb-grid">
				<label class="ptb-label"><?php esc_html_e( 'Τίτλος (ελληνικά)', 'product-tabs' ); ?> <input type="text" class="ptb-wide" name="<?php echo esc_attr( $n ); ?>[title_el]" value="<?php echo esc_attr( $t['title_el'] ); ?>" maxlength="<?php echo (int) PTB_Settings::MAX_TITLE; ?>" /></label>
				<label class="ptb-label"><?php esc_html_e( 'Τίτλος (αγγλικά)', 'product-tabs' ); ?> <input type="text" class="ptb-wide" name="<?php echo esc_attr( $n ); ?>[title_en]" value="<?php echo esc_attr( $t['title_en'] ); ?>" maxlength="<?php echo (int) PTB_Settings::MAX_TITLE; ?>" /></label>
				<label class="ptb-label"><?php esc_html_e( 'Είδος', 'product-tabs' ); ?>
					<select name="<?php echo esc_attr( $n ); ?>[type]">
						<option value="text" <?php selected( $t['type'], 'text' ); ?>><?php esc_html_e( 'Κείμενο', 'product-tabs' ); ?></option>
						<option value="video" <?php selected( $t['type'], 'video' ); ?>><?php esc_html_e( 'Βίντεο', 'product-tabs' ); ?></option>
					</select>
				</label>
				<label class="ptb-label"><?php esc_html_e( 'Θέση', 'product-tabs' ); ?> <input type="number" class="ptb-num" min="1" max="200" name="<?php echo esc_attr( $n ); ?>[priority]" value="<?php echo (int) $t['priority']; ?>" /></label>
			</div>
			<label class="ptb-check"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[on]" value="1" <?php checked( $t['on'] ); ?> /> <?php esc_html_e( 'Εμφανίζεται', 'product-tabs' ); ?></label>
			<?php if ( ! $new ) : ?>
				<label class="ptb-check"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[delete]" value="1" /> <?php esc_html_e( 'Διαγραφή', 'product-tabs' ); ?></label>
			<?php endif; ?>

			<label class="ptb-label ptb-mt-8"><?php esc_html_e( 'Διεύθυνση YouTube ή Vimeo (για είδος «Βίντεο»)', 'product-tabs' ); ?> <input type="text" class="ptb-wide" name="<?php echo esc_attr( $n ); ?>[video]" value="<?php echo esc_attr( PTB_Tabs::video_url( $t['video'] ) ); ?>" /></label>

			<span class="ptb-label ptb-mt-8"><?php esc_html_e( 'Κείμενο (ελληνικά)', 'product-tabs' ); ?></span>
			<?php
			wp_editor(
				$t['content_el'],
				'ptbse' . $i . 'el',
				array(
					'textarea_name' => $n . '[content_el]',
					'textarea_rows' => 6,
					'media_buttons' => true,
				)
			);
			?>
			<span class="ptb-label ptb-mt-8"><?php esc_html_e( 'Κείμενο (αγγλικά, κενό = το ελληνικό)', 'product-tabs' ); ?></span>
			<?php
			wp_editor(
				$t['content_en'],
				'ptbse' . $i . 'en',
				array(
					'textarea_name' => $n . '[content_en]',
					'textarea_rows' => 4,
					'media_buttons' => true,
				)
			);
			?>

			<h3 class="ptb-h3"><?php esc_html_e( 'Σε ποια προϊόντα', 'product-tabs' ); ?></h3>
			<label class="ptb-check"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[all]" value="1" <?php checked( $t['all'] ); ?> /> <?php esc_html_e( 'Σε όλα τα προϊόντα', 'product-tabs' ); ?></label>
			<div class="ptb-grid">
				<label class="ptb-label"><?php esc_html_e( 'Κατηγορίες (και οι υποκατηγορίες τους)', 'product-tabs' ); ?>
					<select multiple size="6" class="ptb-wide" name="<?php echo esc_attr( $n ); ?>[cats][]"><?php echo self::term_options( 'product_cat', $t['cats'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in term_options(). ?></select>
				</label>
				<label class="ptb-label"><?php esc_html_e( 'Ετικέτες', 'product-tabs' ); ?>
					<select multiple size="6" class="ptb-wide" name="<?php echo esc_attr( $n ); ?>[tags][]"><?php echo self::term_options( 'product_tag', $t['tags'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in term_options(). ?></select>
				</label>
			</div>
			<span class="ptb-label"><?php esc_html_e( 'Είδη προϊόντων', 'product-tabs' ); ?></span>
			<?php foreach ( $kinds as $k => $label ) : ?>
				<label class="ptb-inline"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[kinds][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $t['kinds'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
			<label class="ptb-label ptb-mt-8"><?php esc_html_e( 'Εκτός από τα προϊόντα (IDs, χωρισμένα με κόμμα)', 'product-tabs' ); ?> <input type="text" class="ptb-wide" name="<?php echo esc_attr( $n ); ?>[exclude]" value="<?php echo esc_attr( implode( ', ', $t['exclude'] ) ); ?>" /></label>
			<p class="description"><?php esc_html_e( 'Στο Ctrl / Cmd + κλικ διαλέγεις πολλά. Χωρίς κανέναν κανόνα η καρτέλα δεν φαίνεται πουθενά.', 'product-tabs' ); ?></p>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: import and backup
	 * ------------------------------------------------------------------- */

	private static function render_import(): void {
		$p = PTB_Import::preview();
		?>
		<h2 class="ptb-h2"><?php esc_html_e( 'Εισαγωγή από το Custom Product Tabs for WooCommerce', 'product-tabs' ); ?></h2>
		<div class="ptb-zone">
			<p class="description"><?php esc_html_e( 'Διαβάζει μόνο τις καρτέλες του παλιού plugin (meta wb_custom_tabs): τίποτα δεν αλλάζει ή σβήνεται εκεί. Το [wb_cpt_youtube_embed_shortcode] γίνεται καρτέλα βίντεο (θέση 30 = trailer, 31 = παρουσίαση)· κάθε άλλο περιεχόμενο μεταφέρεται όπως είναι, με τα shortcodes του. Δεύτερη εισαγωγή δεν διπλασιάζει τίποτα.', 'product-tabs' ); ?></p>
			<ul class="ptb-mt-8">
				<?php /* translators: 1: products with the old meta, 2: of them with tabs */ ?>
				<li><?php echo esc_html( sprintf( __( 'Προϊόντα με δεδομένα του παλιού plugin: %1$d (με καρτέλες: %2$d)', 'product-tabs' ), $p['products'], $p['with_tabs'] ) ); ?></li>
				<?php /* translators: 1: tabs, 2: videos, 3: text tabs */ ?>
				<li><?php echo esc_html( sprintf( __( 'Καρτέλες: %1$d (βίντεο: %2$d, κείμενο: %3$d)', 'product-tabs' ), $p['tabs'], $p['videos'], $p['text'] ) ); ?></li>
				<?php if ( $p['book_preview'] ) : ?>
					<?php /* translators: %d: tabs with [book_preview] */ ?>
					<li><?php echo esc_html( sprintf( __( 'Με [book_preview]: %d. Όταν το Book Preview δείχνει δική του καρτέλα, αυτές κρύβονται για να μη φαίνεται δύο φορές.', 'product-tabs' ), $p['book_preview'] ) ); ?></li>
				<?php endif; ?>
				<?php if ( $p['skipped'] ) : ?>
					<?php /* translators: %d: tabs left out */ ?>
					<li><?php echo esc_html( sprintf( __( 'Δεν μεταφέρονται: %d (κενές ή κοινές καρτέλες του παλιού plugin).', 'product-tabs' ), $p['skipped'] ) ); ?></li>
				<?php endif; ?>
				<?php /* translators: %d: products already imported */ ?>
				<li><?php echo esc_html( sprintf( __( 'Ήδη εισηγμένα προϊόντα: %d', 'product-tabs' ), $p['imported'] ) ); ?></li>
			</ul>
			<form method="post" class="ptb-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="ptb_action" value="import_wb" />
				<button type="submit" class="button button-primary" <?php disabled( 0 === $p['tabs'] || $p['imported'] >= $p['with_tabs'] ); ?>><?php esc_html_e( 'Εισαγωγή', 'product-tabs' ); ?></button>
			</form>
			<?php if ( $p['imported'] > 0 ) : ?>
				<form method="post" class="ptb-inline-form">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="ptb_action" value="undo_wb" />
					<button type="submit" class="button"><?php esc_html_e( 'Αναίρεση εισαγωγής', 'product-tabs' ); ?></button>
				</form>
				<p class="description ptb-mt-8"><?php esc_html_e( 'Η αναίρεση αφαιρεί μόνο τις καρτέλες που έφερε η εισαγωγή· όσες πρόσθεσες εσύ μένουν.', 'product-tabs' ); ?></p>
			<?php endif; ?>
			<p class="description ptb-mt-8"><?php esc_html_e( 'Σειρά: λειτουργία δοκιμής → εισαγωγή → έλεγχος μερικών προϊόντων → ενεργό για όλους → απενεργοποίηση του παλιού plugin.', 'product-tabs' ); ?></p>
		</div>

		<h2 class="ptb-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'product-tabs' ); ?></h2>
		<div class="ptb-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&ptb_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'product-tabs' ); ?></a></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_BAK, 'ptb_backup_nonce' ); ?>
				<input type="file" name="ptb_import_file" accept="application/json,.json" />
				<button type="submit" name="ptb_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'product-tabs' ); ?></button>
			</form>
			<p class="description ptb-mt-8"><?php esc_html_e( 'Περιέχει τις ρυθμίσεις και τις κοινές καρτέλες. Οι καρτέλες κάθε προϊόντος μένουν στο προϊόν (και στο backup του site).', 'product-tabs' ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: developers
	 * ------------------------------------------------------------------- */

	private static function render_dev(): void {
		$rows = array(
			'ptb_get_video( $product_id, $role )'  => __( 'Το YouTube ID του βίντεο ενός ρόλου (trailer, presentation): πρώτα οι καρτέλες του προϊόντος, μετά όσες μοιράζονται οι άλλες μορφές του έργου. Κενό όταν δεν υπάρχει.', 'product-tabs' ),
			'ptb_get_tabs( $product_id )'          => __( 'Οι καρτέλες του προϊόντος όπως είναι αποθηκευμένες.', 'product-tabs' ),
			'apply_filters( ptb_tabs, $tabs, $id )' => __( 'Φίλτρο στις καρτέλες του προϊόντος πριν φτάσουν στο WooCommerce.', 'product-tabs' ),
			'apply_filters( ptb_tab_content, … )'  => __( 'Φίλτρο στο HTML μιας καρτέλας κειμένου.', 'product-tabs' ),
			'apply_filters( ptb_text, … )'         => __( 'Φίλτρο στα κείμενα για τους επισκέπτες (κείμενο, κλειδί, γλώσσα).', 'product-tabs' ),
			'[nox_video src="…" title="…"]'        => __( 'Το ίδιο κουμπί βίντεο σε οποιαδήποτε σελίδα.', 'product-tabs' ),
			"define( 'PTB_DISABLE', true )"        => __( 'Σταθερά στο wp-config.php που σταματά κάθε αλλαγή στις καρτέλες.', 'product-tabs' ),
		);
		?>
		<h2 class="ptb-h2"><?php esc_html_e( 'Για προγραμματιστές', 'product-tabs' ); ?></h2>
		<div class="ptb-zone">
			<table class="ptb-table">
				<tbody>
				<?php foreach ( $rows as $code => $text ) : ?>
					<tr><td><code><?php echo esc_html( $code ); ?></code></td><td><?php echo esc_html( $text ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * Routes
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		// Settings import (own nonce, multipart form).
		if ( isset( $_POST['ptb_import'] ) ) {
			if ( ! isset( $_POST['ptb_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ptb_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Product_Tabs::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-tabs' ) );
			}
			self::redirect( 'import', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['ptb_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Product_Tabs::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-tabs' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['ptb_action'] ) ) ) {
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'save_shared':
				self::redirect( 'shared', self::do_save_shared() );
				break;
			case 'import_wb':
				self::redirect( 'import', self::do_import_wb() );
				break;
			case 'undo_wb':
				self::redirect( 'import', self::do_undo_wb() );
				break;
			default:
				self::redirect( 'settings', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'product-tabs' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'ptb_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by PTB_Settings). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by PTB_Settings.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/** Page cache after a change that may touch every product page. */
	private static function purged( array $msgs ): array {
		if ( PTB_Settings::get()['purge'] && class_exists( 'Noxpress_Core' ) ) {
			$done = Noxpress_Core::purge_page_cache();
			if ( $done ) {
				/* translators: %s: names of the page caches that were cleared */
				$msgs[] = self::msg( 'info', sprintf( __( 'Καθαρίστηκε η page cache: %s.', 'product-tabs' ), implode( ', ', $done ) ) );
			}
		}
		return $msgs;
	}

	private static function do_save_settings(): array {
		$raw = array(
			'mode'    => sanitize_key( self::post( 'ptb_mode' ) ),
			'lang'    => sanitize_key( self::post( 'ptb_lang' ) ),
			'thumbs'  => '1' === self::post( 'ptb_thumbs' ),
			'bridge'  => '1' === self::post( 'ptb_bridge' ),
			'hide_wb' => '1' === self::post( 'ptb_hide_wb' ),
			'purge'   => '1' === self::post( 'ptb_purge' ),
			'wipe'    => '1' === self::post( 'ptb_wipe' ),
			'texts'   => self::post_array( 'ptb_texts' ),
			'order'   => self::post_array( 'ptb_order' ),
		);
		foreach ( PTB_Settings::KNOWN as $k ) {
			if ( isset( $raw['order'][ $k ] ) && is_array( $raw['order'][ $k ] ) ) {
				$raw['order'][ $k ]['hide'] = ! empty( $raw['order'][ $k ]['hide'] );
			}
		}
		PTB_Settings::save( $raw );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'product-tabs' ) ) );
		if ( 'off' === PTB_Settings::get()['mode'] ) {
			$msgs[] = self::msg( 'info', __( 'Η λειτουργία είναι «Ανενεργό»: οι καρτέλες δεν φαίνονται ακόμα στο κατάστημα.', 'product-tabs' ) );
		}
		return self::purged( $msgs );
	}

	private static function do_save_shared(): array {
		$rows = array();
		$drop = 0;
		foreach ( self::post_array( 'ptb_s' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['delete'] ) ) {
				continue;
			}
			$row['on']  = ! empty( $row['on'] );
			$row['all'] = ! empty( $row['all'] );
			$filled     = '' !== trim( (string) ( $row['title_el'] ?? '' ) ) || '' !== trim( wp_strip_all_tags( (string) ( $row['content_el'] ?? '' ) ) ) || '' !== trim( (string) ( $row['video'] ?? '' ) );
			if ( ! $filled ) {
				continue; // The empty "new tab" card.
			}
			if ( null === PTB_Settings::shared_tab( $row, true ) ) {
				++$drop;
				continue;
			}
			$rows[] = $row;
		}
		$kept = PTB_Settings::save_shared( $rows, current_user_can( 'unfiltered_html' ) );
		$msgs = array( self::msg( 'success', __( 'Οι κοινές καρτέλες αποθηκεύτηκαν.', 'product-tabs' ) ) );
		if ( $drop ) {
			$msgs[] = self::msg( 'warning', __( 'Μια καρτέλα δεν αποθηκεύτηκε: θέλει ελληνικό τίτλο και κείμενο (ή διεύθυνση βίντεο).', 'product-tabs' ) );
		}
		foreach ( PTB_Settings::shared() as $t ) {
			if ( ! $t['all'] && ! $t['cats'] && ! $t['tags'] && ! $t['kinds'] ) {
				/* translators: %s: tab title */
				$msgs[] = self::msg( 'warning', sprintf( __( 'Η καρτέλα «%s» δεν έχει κανόνα και δεν φαίνεται σε κανένα προϊόν.', 'product-tabs' ), $t['title_el'] ) );
			}
		}
		if ( $kept >= PTB_Settings::MAX_SHARED ) {
			$msgs[] = self::msg( 'warning', __( 'Κρατήθηκαν μόνο οι πρώτες 30 κοινές καρτέλες.', 'product-tabs' ) );
		}
		return self::purged( $msgs );
	}

	private static function do_import_wb(): array {
		$r = PTB_Import::run( ! current_user_can( 'unfiltered_html' ) );
		if ( 0 === $r['products'] ) {
			return array( self::msg( 'info', __( 'Δεν βρέθηκε τίποτα καινούργιο για εισαγωγή.', 'product-tabs' ) ) );
		}
		$msgs = array(
			/* translators: 1: tabs, 2: products */
			self::msg( 'success', sprintf( __( 'Εισήχθησαν %1$d καρτέλες σε %2$d προϊόντα. Έλεγξε μερικά σε λειτουργία δοκιμής πριν απενεργοποιήσεις το παλιό plugin.', 'product-tabs' ), $r['tabs'], $r['products'] ) ),
		);
		if ( 'off' === PTB_Settings::get()['mode'] ) {
			$msgs[] = self::msg( 'info', __( 'Η λειτουργία είναι «Ανενεργό»: οι καρτέλες δεν φαίνονται ακόμα στο κατάστημα.', 'product-tabs' ) );
		}
		return $msgs;
	}

	private static function do_undo_wb(): array {
		$r = PTB_Import::undo();
		/* translators: %d: products */
		return array( self::msg( 'success', sprintf( __( 'Η εισαγωγή αναιρέθηκε σε %d προϊόντα.', 'product-tabs' ), $r['products'] ) ) );
	}

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'ptb_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Product_Tabs::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-tabs' ) );
		}
		$state = array(
			'plugin'  => 'product-tabs',
			'version' => PTB_VERSION,
			'options' => array(
				PTB_Settings::OPT        => PTB_Settings::get(),
				PTB_Settings::OPT_SHARED => array( 'tabs' => PTB_Settings::shared() ),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="product-tabs-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob goes through the same validation as the
	 * forms; HTML through wp_kses_post unless the user has unfiltered_html.
	 * An invalid file leaves the current settings untouched.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['ptb_import_file']['error'] ) ? (int) $_FILES['ptb_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['ptb_import_file']['size'] ) ? (int) $_FILES['ptb_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'product-tabs' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['ptb_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['ptb_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'product-tabs' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['ptb_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'product-tabs' ) ) );
		}
		$set    = isset( $state['options'][ PTB_Settings::OPT ] ) && is_array( $state['options'][ PTB_Settings::OPT ] ) ? $state['options'][ PTB_Settings::OPT ] : null;
		$shared = isset( $state['options'][ PTB_Settings::OPT_SHARED ]['tabs'] ) && is_array( $state['options'][ PTB_Settings::OPT_SHARED ]['tabs'] ) ? $state['options'][ PTB_Settings::OPT_SHARED ]['tabs'] : null;
		if ( null === $set && null === $shared ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Product Tabs).', 'product-tabs' ) ) );
		}
		if ( null !== $set ) {
			PTB_Settings::save( $set );
		}
		if ( null !== $shared ) {
			$in   = count( $shared );
			$kept = PTB_Settings::save_shared( $shared, current_user_can( 'unfiltered_html' ) );
			if ( $kept < $in ) {
				/* translators: 1: tabs kept, 2: tabs in the file */
				return self::purged( array( self::msg( 'warning', sprintf( __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: κρατήθηκαν %1$d από %2$d κοινές καρτέλες.', 'product-tabs' ), $kept, $in ) ) ) );
			}
		}
		return self::purged( array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'product-tabs' ) ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'ptb_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'ptb_aui_msg_' . get_current_user_id() );
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
		<p class="ptb-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'product-tabs' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="ptb-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="ptb-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'product-tabs' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="ptb-footer-cta">
			<a class="ptb-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'product-tabs' ); ?>
			</a>
			<a class="ptb-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'product-tabs' ); ?>
			</a>
		</p>
		<?php
	}
}
