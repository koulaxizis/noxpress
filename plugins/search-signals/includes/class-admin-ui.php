<?php
/**
 * SSG_Admin_UI — menu, tabs, forms (Bible §3–§8, §11, §20).
 *
 * Submenu «Search Signals» of the shared "Noxpress" menu (admin_menu
 * priority 330), slug ssg-settings, capability manage_options. Tabs:
 *  - overview  mode, modules, other SEO plugins, Switch over / back,
 *              uninstall, developers
 *  - titles    separator, templates and noindex per type, robots rules
 *  - schema    organization, phones, profiles, share image, Twitter, search
 *  - sitemaps  files, post types, taxonomies, status
 *  - llms      llms.txt contents, static files in the web root
 *  - import    Rank Math: scan → import → undo
 *  - compare   live page vs Search Signals, sitemaps
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient ssg_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'ssg-settings';
	const NONCE       = 'ssg_admin';
	const NONCE_FIELD = 'ssg_nonce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 330 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'other_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SSG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'overview' => __( 'Επισκόπηση', 'search-signals' ),
			'titles'   => __( 'Τίτλοι & meta', 'search-signals' ),
			'schema'   => __( 'Schema & social', 'search-signals' ),
			'sitemaps' => __( 'Sitemaps', 'search-signals' ),
			'llms'     => __( 'llms.txt', 'search-signals' ),
			'import'   => __( 'Εισαγωγή', 'search-signals' ),
			'compare'  => __( 'Σύγκριση', 'search-signals' ),
		);
	}

	private static function module_labels(): array {
		return array(
			'meta'     => array( __( 'Τίτλοι & meta', 'search-signals' ), __( '<title>, περιγραφή, robots, canonical, σελιδοποίηση, πεδία στον editor.', 'search-signals' ) ),
			'schema'   => array( __( 'Schema (JSON-LD)', 'search-signals' ), __( 'Ένα γράφημα ανά σελίδα: Organization, WebSite, WebPage, Product, Article, BreadcrumbList.', 'search-signals' ) ),
			'social'   => array( __( 'Social', 'search-signals' ), __( 'Open Graph και Twitter (summary_large_image). Ανοίγει μαζί και το «Τίτλοι & meta».', 'search-signals' ) ),
			'sitemaps' => array( __( 'Sitemaps', 'search-signals' ), __( '/sitemap_index.xml με τα ίδια ονόματα αρχείων με το Rank Math, εικόνες, γραμμή στο robots.txt.', 'search-signals' ) ),
			'llms'     => array( __( 'llms.txt', 'search-signals' ), __( 'Λίστα του site για τους βοηθούς AI, χωρίς προσωπικά δεδομένα.', 'search-signals' ) ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Search Signals', 'search-signals' ),
			__( 'Search Signals', 'search-signals' ),
			Search_Signals::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'search-signals' ) . '</a>' );
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'ssg-admin', SSG_URL . 'assets/admin.css', array(), SSG_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'ssg-editor', SSG_URL . 'assets/editor.js', array( 'jquery' ), SSG_VERSION, true );
		wp_localize_script(
			'ssg-editor',
			'ssgEditor',
			array(
				'pick'  => __( 'Διάλεξε εικόνα', 'search-signals' ),
				'use'   => __( 'Χρήση', 'search-signals' ),
				'chars' => __( '%d χαρακτήρες', 'search-signals' ),
			)
		);
	}

	/** While another SEO plugin runs, a reminder on our page and on the plugins screen. */
	public static function other_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->base || ! current_user_can( Search_Signals::CAP ) ) {
			return;
		}
		$other = SSG_Detect::other();
		if ( '' === $other || ! SSG_Settings::any_module() ) {
			return;
		}
		/* translators: %s: plugin name */
		echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( 'Search Signals: παθητικό όσο τρέχει το %s. Η μετάβαση γίνεται με ένα κουμπί από τη σελίδα του plugin, ώστε να μη μείνει κενό στους τίτλους, τα sitemaps και το schema.', 'search-signals' ), $other ) ) . ' <a href="' . esc_url( self::url( 'overview' ) ) . '">' . esc_html__( 'Search Signals', 'search-signals' ) . '</a></p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Search_Signals::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'search-signals' ) );
		}
		$tab   = self::current_tab();
		$s     = SSG_Settings::get();
		$other = SSG_Detect::other();
		?>
		<div class="wrap ssg-wrap">
			<h1><?php esc_html_e( 'Search Signals', 'search-signals' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Τίτλοι, περιγραφές, robots, canonical, schema, social, sitemaps και llms.txt, μόνο ό,τι χρειάζεται ένα κατάστημα. Χωρίς εξωτερικές κλήσεις και χωρίς cookies.', 'search-signals' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Search_Signals::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το SSG_DISABLE είναι ενεργό στο wp-config.php: το plugin δεν τυπώνει και δεν σερβίρει τίποτα.', 'search-signals' ) . '</p></div>';
			}
			if ( '' !== $other ) {
				/* translators: %s: plugin name */
				echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Παθητικό: τρέχει το %s. Το Search Signals δεν τυπώνει και δεν σερβίρει τίποτα, σε καμία λειτουργία, ώστε να μη δουλεύουν ποτέ δύο SEO plugins μαζί. Η εισαγωγή και η σύγκριση δουλεύουν κανονικά.', 'search-signals' ), $other ) ) . '</p></div>';
			} elseif ( 'test' === $s['mode'] && SSG_Settings::any_module() ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: τίτλοι, meta, schema, sitemaps και llms.txt φαίνονται μόνο σε διαχειριστές (εσένα), σε σελίδες χωρίς cache. Οι επισκέπτες δεν βλέπουν τίποτα από το plugin.', 'search-signals' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper ssg-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( (string) $slug ) ),
						(string) $slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'titles':
					self::render_titles();
					break;
				case 'schema':
					self::render_schema();
					break;
				case 'sitemaps':
					self::render_sitemaps();
					break;
				case 'llms':
					self::render_llms();
					break;
				case 'import':
					self::render_import();
					break;
				case 'compare':
					self::render_compare();
					break;
				default:
					self::render_overview();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="ssg-badge ssg-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="ssg-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="ssg-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="ssg_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function button_form( string $action, array $fields, string $label, string $class = 'button', string $confirm = '' ): void {
		echo '<form method="post" class="ssg-inline-form"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Attachment picker (hidden ID, preview, buttons). */
	private static function image_field( string $name, int $id, string $label ): void {
		$img = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		echo '<div class="ssg-ed ssg-field"><div class="ssg-ed-image"><span class="ssg-label">' . esc_html( $label ) . '</span>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" class="ssg-image-id" value="' . (int) $id . '" />';
		echo '<span class="ssg-image-preview">' . ( $img ? '<img src="' . esc_url( (string) $img ) . '" alt="" style="max-width:120px;height:auto;display:block;margin:6px 0" />' : '' ) . '</span>';
		echo '<button type="button" class="button ssg-image-pick">' . esc_html__( 'Επιλογή', 'search-signals' ) . '</button> ';
		echo '<button type="button" class="button-link ssg-image-clear">' . esc_html__( 'Αφαίρεση', 'search-signals' ) . '</button></div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Overview
	 * ------------------------------------------------------------------- */

	private static function render_overview(): void {
		$s     = SSG_Settings::get();
		$other = SSG_Detect::other();
		$st    = SSG_Switch::state();
		?>
		<form method="post">
			<?php self::form_head( 'save_overview' ); ?>
			<h2 class="ssg-h2"><?php esc_html_e( 'Λειτουργία', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<?php
				self::radio( 'ssg_mode', 'off', $s['mode'], __( 'Ανενεργό: τίποτα από το plugin', 'search-signals' ) );
				self::radio( 'ssg_mode', 'test', $s['mode'], __( 'Δοκιμή: μόνο οι διαχειριστές βλέπουν την έξοδο', 'search-signals' ) );
				self::radio( 'ssg_mode', 'live', $s['mode'], __( 'Ενεργό: όλοι οι επισκέπτες', 'search-signals' ) );
				?>
				<p class="description"><?php esc_html_e( 'Με άλλο SEO plugin ενεργό, καμία λειτουργία δεν τυπώνει τίποτα. Για να περάσεις από εκείνο σε αυτό χωρίς κενό, χρησιμοποίησε τη «Μετάβαση» πιο κάτω.', 'search-signals' ); ?></p>
			</div>

			<h2 class="ssg-h2"><?php esc_html_e( 'Modules', 'search-signals' ); ?></h2>
			<div class="ssg-zone ssg-mods">
				<p class="description"><?php esc_html_e( 'Κλειστό module = κανένας κώδικας του δεν φορτώνεται.', 'search-signals' ); ?></p>
				<?php
				foreach ( self::module_labels() as $m => $l ) {
					echo '<div class="ssg-mod">';
					self::checkbox( 'ssg_mods[' . $m . ']', ! empty( $s['mods'][ $m ] ), $l[0] );
					echo '<p class="description">' . esc_html( $l[1] ) . '</p></div>';
				}
				?>
			</div>

			<h2 class="ssg-h2"><?php esc_html_e( 'Απεγκατάσταση', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<?php self::checkbox( 'ssg_keep', $s['keep'], __( 'Να μείνουν οι τίτλοι, οι περιγραφές και τα robots των σελίδων όταν σβήσω το plugin', 'search-signals' ) ); ?>
			</div>
			<p class="ssg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'search-signals' ); ?></button></p>
		</form>

		<h2 class="ssg-h2"><?php esc_html_e( 'Μετάβαση', 'search-signals' ); ?></h2>
		<div class="ssg-zone">
			<?php if ( '' !== $other ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: plugin name */ __( 'Με ένα κλικ, στο ίδιο αίτημα: απενεργοποίηση του %s (όχι διαγραφή, τα δεδομένα του μένουν), Search Signals σε «Ενεργό», καθαρισμός της cache σελίδων.', 'search-signals' ), $other ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Πριν: εισαγωγή, σύγκριση των δειγμάτων και των sitemaps, μεταφορά των ανακατευθύνσεων στο Redirects, και τα στατικά llms.txt στην καρτέλα llms.txt.', 'search-signals' ); ?></p>
				<?php
				$why = SSG_Switch::blocked();
				if ( '' !== $why ) {
					echo '<p>' . self::badge( __( 'Δεν γίνεται ακόμα', 'search-signals' ), 'warn' ) . ' ' . esc_html( $why ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				} else {
					/* translators: %s: plugin name */
					self::button_form( 'switch_over', array(), sprintf( __( 'Μετάβαση από το %s', 'search-signals' ), $other ), 'button button-primary', sprintf( __( 'Απενεργοποίηση του %s και Search Signals σε «Ενεργό» τώρα;', 'search-signals' ), $other ) );
				}
				?>
			<?php elseif ( $st ) : ?>
				<p><?php echo self::badge( __( 'Έγινε', 'search-signals' ), 'ok' ) . ' ' . esc_html( sprintf( /* translators: 1: plugin name, 2: date */ __( 'Μετάβαση από το %1$s στις %2$s.', 'search-signals' ), (string) $st['other'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $st['time'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></p>
				<?php
				/* translators: %s: plugin name */
				self::button_form( 'switch_back', array(), sprintf( __( 'Επιστροφή στο %s', 'search-signals' ), (string) $st['other'] ), 'button', sprintf( __( 'Ενεργοποίηση του %s ξανά και Search Signals πίσω στη δοκιμή;', 'search-signals' ), (string) $st['other'] ) );
				?>
				<p class="description"><?php esc_html_e( 'Όταν όλα δουλεύουν για 1–2 εβδομάδες (Search Console: τα sitemaps διαβάστηκαν, καμία πτώση), μπορείς να σβήσεις το παλιό plugin μαζί με τα δεδομένα του.', 'search-signals' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Δεν τρέχει άλλο SEO plugin.', 'search-signals' ); ?></p>
			<?php endif; ?>
		</div>

		<h2 class="ssg-h2"><?php esc_html_e( 'Για developers', 'search-signals' ); ?></h2>
		<div class="ssg-zone">
			<table class="widefat striped ssg-table">
				<tbody>
					<tr><td><code>noxpress_seo_graph</code></td><td><?php esc_html_e( 'Οι κόμβοι του JSON-LD πριν τυπωθούν: ( $nodes, $context ). Ίδιο σχήμα με το rank_math/json_ld.', 'search-signals' ); ?></td></tr>
					<tr><td><code>noxpress_seo_title, _description, _robots, _canonical</code></td><td><?php esc_html_e( 'Τίτλος, περιγραφή, robots ([noindex, nofollow]) και canonical μιας σελίδας: ( $value, $context ).', 'search-signals' ); ?></td></tr>
					<tr><td><code>noxpress_seo_sitemap_entry</code></td><td><?php esc_html_e( 'Μία διεύθυνση του sitemap: ( $entry, $type, $object ). false = εκτός.', 'search-signals' ); ?></td></tr>
					<tr><td><code>noxpress_seo_llms_sections</code></td><td><?php esc_html_e( 'Οι ενότητες του llms.txt.', 'search-signals' ); ?></td></tr>
					<tr><td><code>noxpress_seo_is_active()</code>, <code>noxpress_seo_active</code></td><td><?php esc_html_e( 'Αν το plugin τυπώνει τα tags αυτού του αιτήματος· plugins με δικό τους canonical ή JSON-LD το παραλείπουν.', 'search-signals' ); ?></td></tr>
					<tr><td><code>search-signals/status, get-meta, set-meta, json-ld, sitemap-status, compare</code></td><td><?php esc_html_e( 'Abilities (WordPress 6.9+) για το Site Bridge.', 'search-signals' ); ?></td></tr>
					<tr><td><code>SSG_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: καμία έξοδος (και τα core sitemaps μένουν κλειστά).', 'search-signals' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Titles
	 * ------------------------------------------------------------------- */

	/** Keys of the template table with their labels. */
	private static function tpl_rows(): array {
		$rows = array();
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			$rows['home'] = __( 'Αρχική (τελευταία άρθρα)', 'search-signals' );
		}
		foreach ( SSG_Settings::public_types( true ) as $pt ) {
			$o = get_post_type_object( $pt );
			if ( $o ) {
				$rows[ 'pt_' . $pt ] = $o->labels->name;
				if ( $o->has_archive && 'product' !== $pt ) {
					/* translators: %s: post type name */
					$rows[ 'arch_' . $pt ] = sprintf( __( 'Αρχείο: %s', 'search-signals' ), $o->labels->name );
				}
			}
		}
		foreach ( SSG_Settings::public_taxes() as $tx ) {
			$o = get_taxonomy( $tx );
			if ( $o ) {
				$rows[ 'tax_' . $tx ] = $o->labels->name;
			}
		}
		$rows['author'] = __( 'Αρχεία συγγραφέων', 'search-signals' );
		$rows['date']   = __( 'Αρχεία ημερομηνιών', 'search-signals' );
		$rows['search'] = __( 'Αναζήτηση', 'search-signals' );
		$rows['404']    = __( 'Σελίδα 404', 'search-signals' );
		return $rows;
	}

	private static function render_titles(): void {
		$s = SSG_Settings::get();
		?>
		<form method="post">
			<?php self::form_head( 'save_titles' ); ?>
			<h2 class="ssg-h2"><?php esc_html_e( 'Templates', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<label class="ssg-field"><span class="ssg-label"><?php esc_html_e( 'Διαχωριστικό (%sep%)', 'search-signals' ); ?></span>
					<input type="text" class="ssg-num" name="ssg_sep" value="<?php echo esc_attr( $s['sep'] ); ?>" maxlength="5" /></label>
				<p class="description"><?php echo esc_html( __( 'Μεταβλητές:', 'search-signals' ) . ' %' . implode( '%, %', SSG_Vars::KNOWN ) . '%' ); ?></p>
				<p class="description"><?php esc_html_e( 'Η στατική αρχική σελίδα, η σελίδα του καταστήματος και η σελίδα των άρθρων ακολουθούν το template των σελίδων, όπως στο Rank Math.', 'search-signals' ); ?></p>
				<div class="ssg-table-wrap">
				<table class="widefat striped ssg-table">
					<thead><tr><th><?php esc_html_e( 'Τύπος', 'search-signals' ); ?></th><th><?php esc_html_e( 'Τίτλος', 'search-signals' ); ?></th><th><?php esc_html_e( 'Περιγραφή', 'search-signals' ); ?></th><th>noindex</th></tr></thead>
					<tbody>
					<?php
					foreach ( self::tpl_rows() as $key => $label ) {
						$t   = SSG_Settings::tpl( (string) $key );
						$unk = array_merge( SSG_Vars::unknown( $t['t'] ), SSG_Vars::unknown( $t['d'] ) );
						echo '<tr><td><strong>' . esc_html( (string) $label ) . '</strong><br /><code class="ssg-muted">' . esc_html( (string) $key ) . '</code>';
						if ( $unk ) {
							echo '<br />' . self::badge( '%' . implode( '%, %', array_unique( $unk ) ) . '%', 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
						}
						echo '</td>';
						echo '<td><input type="text" class="widefat" name="ssg_tpl[' . esc_attr( (string) $key ) . '][t]" value="' . esc_attr( $t['t'] ) . '" maxlength="300" /></td>';
						echo '<td><input type="text" class="widefat" name="ssg_tpl[' . esc_attr( (string) $key ) . '][d]" value="' . esc_attr( $t['d'] ) . '" maxlength="300" /></td>';
						echo '<td><input type="checkbox" name="ssg_tpl[' . esc_attr( (string) $key ) . '][n]" value="1"' . checked( $t['n'], true, false ) . ' /></td></tr>';
					}
					?>
					</tbody>
				</table>
				</div>
			</div>

			<h2 class="ssg-h2"><?php esc_html_e( 'Κανόνες', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<?php
				self::checkbox( 'ssg_noindex_empty', $s['noindex_empty'], __( 'noindex στις κατηγορίες και τα tags χωρίς περιεχόμενο', 'search-signals' ) );
				self::checkbox( 'ssg_paged_noindex', $s['paged_noindex'], __( 'noindex από τη 2η σελίδα των αρχείων (σελίδα 2, 3 …)', 'search-signals' ) );
				self::checkbox( 'ssg_wc_generator', $s['wc_generator'], __( 'Αφαίρεση του tag generator του WooCommerce', 'search-signals' ) );
				?>
				<p class="description"><?php esc_html_e( 'Σελίδες με κωδικό, πρόχειρα και ιδιωτικά είναι πάντα noindex. Ανά σελίδα αλλάζουν από τον editor.', 'search-signals' ); ?></p>
			</div>
			<p class="ssg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'search-signals' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Schema & social
	 * ------------------------------------------------------------------- */

	private static function render_schema(): void {
		$s      = SSG_Settings::get();
		$o      = $s['org'];
		$phones = array_pad( $o['phones'], max( 3, count( $o['phones'] ) + 1 ), array( 'number' => '', 'type' => '', 'lang' => '' ) );
		?>
		<form method="post">
			<?php self::form_head( 'save_schema' ); ?>
			<h2 class="ssg-h2"><?php esc_html_e( 'Ποιος είναι το site', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<?php
				self::radio( 'ssg_org_type', 'Organization', $o['type'], __( 'Επιχείρηση / οργανισμός', 'search-signals' ) );
				self::radio( 'ssg_org_type', 'Person', $o['type'], __( 'Πρόσωπο', 'search-signals' ) );
				?>
				<div class="ssg-grid">
					<label class="ssg-field"><span class="ssg-label"><?php esc_html_e( 'Όνομα (και og:site_name)', 'search-signals' ); ?></span>
						<input type="text" class="regular-text" name="ssg_org_name" value="<?php echo esc_attr( $o['name'] ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>" maxlength="200" /></label>
					<label class="ssg-field"><span class="ssg-label"><?php esc_html_e( 'Διεύθυνση', 'search-signals' ); ?></span>
						<input type="url" class="regular-text" name="ssg_org_url" value="<?php echo esc_attr( $o['url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" maxlength="500" /></label>
				</div>
				<?php self::image_field( 'ssg_org_logo', (int) $o['logo'], __( 'Logo', 'search-signals' ) ); ?>
				<span class="ssg-label"><?php esc_html_e( 'Τηλέφωνα επικοινωνίας (ContactPoint)', 'search-signals' ); ?></span>
				<table class="widefat striped ssg-table">
					<thead><tr><th><?php esc_html_e( 'Τηλέφωνο', 'search-signals' ); ?></th><th><?php esc_html_e( 'Είδος (π.χ. customer support)', 'search-signals' ); ?></th><th><?php esc_html_e( 'Γλώσσες (π.χ. Greek, English)', 'search-signals' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $phones as $i => $p ) : ?>
						<tr>
							<td><input type="text" name="ssg_phones[<?php echo (int) $i; ?>][number]" value="<?php echo esc_attr( (string) $p['number'] ); ?>" maxlength="40" /></td>
							<td><input type="text" name="ssg_phones[<?php echo (int) $i; ?>][type]" value="<?php echo esc_attr( (string) $p['type'] ); ?>" maxlength="60" /></td>
							<td><input type="text" name="ssg_phones[<?php echo (int) $i; ?>][lang]" value="<?php echo esc_attr( (string) $p['lang'] ); ?>" maxlength="60" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Τα δημόσια τηλέφωνα του καταστήματος. Το Live Operator προσθέτει εδώ τις ώρες εξυπηρέτησης.', 'search-signals' ); ?></p>
				<span class="ssg-label"><?php esc_html_e( 'Προφίλ (sameAs), ένα ανά γραμμή', 'search-signals' ); ?></span>
				<textarea name="ssg_same_as" class="ssg-patterns" rows="4"><?php echo esc_textarea( implode( "\n", $o['same_as'] ) ); ?></textarea>
			</div>

			<h2 class="ssg-h2"><?php esc_html_e( 'Social και αναζήτηση', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<?php self::image_field( 'ssg_image', (int) $s['image'], __( 'Προεπιλεγμένη εικόνα κοινοποίησης (αλλιώς το logo)', 'search-signals' ) ); ?>
				<label class="ssg-field"><span class="ssg-label"><?php esc_html_e( 'Λογαριασμός X / Twitter (@όνομα, προαιρετικό)', 'search-signals' ); ?></span>
					<input type="text" name="ssg_twitter" value="<?php echo esc_attr( $s['twitter'] ); ?>" maxlength="16" /></label>
				<?php self::checkbox( 'ssg_search_product', $s['search_product'], __( 'Το πλαίσιο αναζήτησης της Google ψάχνει στα προϊόντα (WooCommerce)', 'search-signals' ) ); ?>
			</div>
			<p class="ssg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'search-signals' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Sitemaps
	 * ------------------------------------------------------------------- */

	private static function render_sitemaps(): void {
		$s = SSG_Settings::get();
		?>
		<form method="post">
			<?php self::form_head( 'save_sitemaps' ); ?>
			<h2 class="ssg-h2"><?php esc_html_e( 'Περιεχόμενο', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<label class="ssg-field"><span class="ssg-label"><?php esc_html_e( 'Διευθύνσεις ανά αρχείο (10–2000)', 'search-signals' ); ?></span>
					<input type="number" class="ssg-num" name="ssg_sm_per" value="<?php echo (int) $s['sm_per']; ?>" min="10" max="2000" /></label>
				<?php self::checkbox( 'ssg_sm_images', $s['sm_images'], __( 'Εικόνες (κύρια εικόνα και gallery του προϊόντος)', 'search-signals' ) ); ?>
				<span class="ssg-label"><?php esc_html_e( 'Τύποι', 'search-signals' ); ?></span>
				<?php
				$types = SSG_Settings::sitemap_types();
				foreach ( SSG_Settings::public_types() as $pt ) {
					$o = get_post_type_object( $pt );
					self::checkbox( 'ssg_sm_types[' . $pt . ']', in_array( $pt, $types, true ), $o ? $o->labels->name . ' (' . $pt . ')' : $pt );
				}
				?>
				<span class="ssg-label"><?php esc_html_e( 'Ταξινομίες', 'search-signals' ); ?></span>
				<?php
				$taxes = SSG_Settings::sitemap_taxes();
				foreach ( SSG_Settings::public_taxes() as $tx ) {
					$o = get_taxonomy( $tx );
					self::checkbox( 'ssg_sm_taxes[' . $tx . ']', in_array( $tx, $taxes, true ), $o ? $o->labels->name . ' (' . $tx . ')' : $tx );
				}
				self::checkbox( 'ssg_sm_authors', $s['sm_authors'], __( 'Συγγραφείς (μόνο αν τα αρχεία τους δεν είναι noindex)', 'search-signals' ) );
				?>
				<p class="description"><?php esc_html_e( 'Ό,τι είναι noindex (ανά σελίδα ή ανά τύπο), κρυφά προϊόντα, καλάθι, ταμείο και λογαριασμός δεν μπαίνουν ποτέ.', 'search-signals' ); ?></p>
			</div>
			<p class="ssg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'search-signals' ); ?></button></p>
		</form>

		<h2 class="ssg-h2"><?php esc_html_e( 'Κατάσταση', 'search-signals' ); ?></h2>
		<div class="ssg-zone">
			<?php
			if ( ! SSG_Settings::module( 'sitemaps' ) ) {
				echo '<p>' . self::badge( __( 'Κλειστό', 'search-signals' ), 'muted' ) . ' ' . esc_html__( 'Το module Sitemaps είναι κλειστό.', 'search-signals' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
			if ( ! class_exists( 'SSG_Sitemaps', false ) ) {
				require_once SSG_PATH . 'includes/class-sitemaps.php';
			}
			$st = SSG_Sitemaps::status();
			/* translators: 1: number of URLs, 2: number of files */
			echo '<p>' . esc_html( sprintf( __( '%1$d διευθύνσεις σε %2$d αρχεία.', 'search-signals' ), (int) $st['urls'], count( $st['files'] ) ) ) . ' <a href="' . esc_url( $st['index'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) wp_parse_url( $st['index'], PHP_URL_PATH ) ) . '</a></p>';
			if ( ! $st['search_engines'] ) {
				echo '<p>' . self::badge( __( 'Προσοχή', 'search-signals' ), 'warn' ) . ' ' . esc_html__( 'Ρυθμίσεις → Ανάγνωση: «Αποθάρρυνση των μηχανών αναζήτησης» είναι ενεργό.', 'search-signals' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
			if ( false !== strpos( (string) $st['robots_txt'], 'static' ) ) {
				echo '<p>' . self::badge( __( 'Προσοχή', 'search-signals' ), 'warn' ) . ' ' . esc_html__( 'Υπάρχει πραγματικό αρχείο robots.txt στη ρίζα: η γραμμή Sitemap πρέπει να μπει με το χέρι.', 'search-signals' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
			?>
			<table class="widefat striped ssg-table">
				<thead><tr><th><?php esc_html_e( 'Αρχείο', 'search-signals' ); ?></th><th><?php esc_html_e( 'Τύπος', 'search-signals' ); ?></th><th><?php esc_html_e( 'Διευθύνσεις', 'search-signals' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $st['files'] as $f ) : ?>
					<tr><td><a href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) wp_parse_url( $f['url'], PHP_URL_PATH ) ); ?></a></td><td><code><?php echo esc_html( $f['type'] ); ?></code></td><td><?php echo (int) $f['urls']; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Στη δοκιμή τα αρχεία ανοίγουν μόνο για διαχειριστές· με άλλο SEO plugin ενεργό, τα URLs τα σερβίρει εκείνο.', 'search-signals' ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * llms.txt
	 * ------------------------------------------------------------------- */

	private static function render_llms(): void {
		$s = SSG_Settings::get();
		require_once SSG_PATH . 'includes/class-static.php';
		$found = SSG_Static::found();
		?>
		<h2 class="ssg-h2"><?php esc_html_e( 'Στατικά αρχεία στη ρίζα', 'search-signals' ); ?></h2>
		<div class="ssg-zone">
			<p class="description"><?php esc_html_e( 'Ένα αρχείο llms.txt ή llms-full.txt στη ρίζα το στέλνει ο server πριν τρέξει το WordPress, οπότε κρύβει το llms.txt του plugin. Μεταφέρεται μόνο με το κλικ σου, σε ιδιωτικό φάκελο backup στο uploads, και γυρίζει πίσω με ένα κλικ.', 'search-signals' ); ?></p>
			<?php if ( $found ) : ?>
				<table class="widefat striped ssg-table">
					<thead><tr><th><?php esc_html_e( 'Αρχείο', 'search-signals' ); ?></th><th><?php esc_html_e( 'Μέγεθος', 'search-signals' ); ?></th><th><?php esc_html_e( 'Αλλαγή', 'search-signals' ); ?></th><th><?php esc_html_e( 'Email μέσα', 'search-signals' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $found as $f ) : ?>
						<tr>
							<td><code><?php echo esc_html( $f['root'] . '/' . $f['name'] ); ?></code></td>
							<td><?php echo esc_html( size_format( (int) $f['size'] ) ); ?></td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $f['time'] ) ); ?></td>
							<td><?php echo $f['email'] ? self::badge( __( 'Ναι', 'search-signals' ), 'danger' ) : self::badge( __( 'Όχι', 'search-signals' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::button_form( 'static_move', array(), __( 'Μεταφορά στο backup', 'search-signals' ), 'button button-primary', __( 'Μεταφορά των στατικών llms αρχείων σε φάκελο backup;', 'search-signals' ) ); ?>
			<?php else : ?>
				<p><?php echo self::badge( __( 'Κανένα', 'search-signals' ), 'ok' ) . ' ' . esc_html__( 'Δεν υπάρχει στατικό llms.txt ή llms-full.txt στη ρίζα.', 'search-signals' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></p>
			<?php endif; ?>
			<?php
			$backups = SSG_Static::backups();
			if ( $backups ) {
				echo '<span class="ssg-label">' . esc_html__( 'Backups', 'search-signals' ) . '</span><table class="widefat striped ssg-table"><tbody>';
				foreach ( $backups as $i => $b ) {
					echo '<tr><td><code>' . esc_html( (string) $b['name'] ) . '</code> · ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $b['time'] ) ) . ' · ' . esc_html( size_format( (int) $b['size'] ) ) . '</td><td>';
					self::button_form( 'static_restore', array( 'ssg_index' => (int) $i ), __( 'Επαναφορά', 'search-signals' ), 'button', __( 'Επαναφορά του αρχείου στη ρίζα; Θα κρύψει ξανά το llms.txt του plugin.', 'search-signals' ) );
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
			$chk = get_transient( 'ssg_static_chk_' . get_current_user_id() );
			if ( is_array( $chk ) ) {
				$who = array(
					'ours'  => __( 'το Search Signals', 'search-signals' ),
					'other' => __( 'άλλος (στατικό αρχείο ή άλλο plugin)', 'search-signals' ),
					'none'  => __( 'κανείς', 'search-signals' ),
					'error' => __( 'σφάλμα αιτήματος', 'search-signals' ),
				);
				foreach ( $chk as $name => $r ) {
					/* translators: 1: file, 2: HTTP code, 3: who */
					echo '<p><code>/' . esc_html( (string) $name ) . '</code> ' . esc_html( sprintf( /* translators: 1: HTTP status code, 2: who answers */ __( 'απαντά %1$d από %2$s.', 'search-signals' ), (int) $r[0], isset( $who[ $r[1] ] ) ? $who[ $r[1] ] : (string) $r[1] ) ) . '</p>';
				}
			}
			self::button_form( 'static_check', array(), __( 'Έλεγχος: ποιος απαντά στο /llms.txt', 'search-signals' ) );
			?>
			<p class="description"><?php esc_html_e( 'Ο έλεγχος ζητά τις διευθύνσεις ως επισκέπτης: στη δοκιμή, ή με άλλο SEO plugin ενεργό, το Search Signals δεν απαντά ακόμα.', 'search-signals' ); ?></p>
		</div>

		<form method="post">
			<?php self::form_head( 'save_llms' ); ?>
			<h2 class="ssg-h2"><?php esc_html_e( 'Περιεχόμενο', 'search-signals' ); ?></h2>
			<div class="ssg-zone">
				<span class="ssg-label"><?php esc_html_e( 'Εισαγωγή (κενό = η περιγραφή του site)', 'search-signals' ); ?></span>
				<textarea name="ssg_llms_intro" class="ssg-patterns" rows="3" maxlength="1000"><?php echo esc_textarea( $s['llms_intro'] ); ?></textarea>
				<label class="ssg-field"><span class="ssg-label"><?php esc_html_e( 'Νεότερα ανά τύπο (0 = όλα)', 'search-signals' ); ?></span>
					<input type="number" class="ssg-num" name="ssg_llms_limit" value="<?php echo (int) $s['llms_limit']; ?>" min="0" max="5000" /></label>
				<span class="ssg-label"><?php esc_html_e( 'Τύποι', 'search-signals' ); ?></span>
				<?php
				$types = SSG_Settings::llms_types();
				foreach ( SSG_Settings::public_types() as $pt ) {
					$o = get_post_type_object( $pt );
					self::checkbox( 'ssg_llms_types[' . $pt . ']', in_array( $pt, $types, true ), $o ? $o->labels->name : $pt );
				}
				?>
				<span class="ssg-label"><?php esc_html_e( 'Ταξινομίες', 'search-signals' ); ?></span>
				<?php
				$taxes = SSG_Settings::llms_taxes();
				foreach ( SSG_Settings::public_taxes() as $tx ) {
					$o = get_taxonomy( $tx );
					self::checkbox( 'ssg_llms_taxes[' . $tx . ']', in_array( $tx, $taxes, true ), $o ? $o->labels->name : $tx );
				}
				?>
				<p class="description"><?php esc_html_e( 'Μόνο ό,τι είναι indexable. Ποτέ email, τηλέφωνα, ονόματα χρηστών ή διευθύνσεις. Το /llms-full.txt απαντά 410.', 'search-signals' ); ?></p>
			</div>
			<p class="ssg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'search-signals' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------- */

	private static function render_import(): void {
		echo '<h2 class="ssg-h2">' . esc_html__( 'Rank Math', 'search-signals' ) . '</h2><div class="ssg-zone">';
		if ( ! SSG_Import::available() ) {
			echo '<p>' . esc_html__( 'Δεν βρέθηκαν ρυθμίσεις του Rank Math σε αυτό το site.', 'search-signals' ) . '</p></div>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Διαβάζει μόνο τα δεδομένα του Rank Math, δεν τα αλλάζει ποτέ. Πρώτα η δοκιμαστική εκτέλεση δείχνει τι θα γραφτεί. Η λειτουργία και τα modules δεν αλλάζουν.', 'search-signals' ) . '</p>';
		self::button_form( 'scan', array(), __( 'Δοκιμαστική εκτέλεση', 'search-signals' ) );

		$scan = get_transient( 'ssg_scan_' . get_current_user_id() );
		if ( is_array( $scan ) ) {
			$st = $scan['settings'];
			echo '<h3 class="ssg-h3">' . esc_html__( 'Ρυθμίσεις', 'search-signals' ) . '</h3><table class="widefat striped ssg-table"><tbody>';
			echo '<tr><td>%sep%</td><td><code>' . esc_html( $st['sep'] ) . '</code></td></tr>';
			foreach ( $st['tpl'] as $k => $t ) {
				echo '<tr><td><code>' . esc_html( (string) $k ) . '</code></td><td>' . esc_html( $t['t'] ) . ( '' !== $t['d'] ? '<br /><span class="ssg-muted">' . esc_html( $t['d'] ) . '</span>' : '' ) . ( $t['n'] ? ' ' . self::badge( 'noindex', 'muted' ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
			}
			/* translators: 1: name, 2: number of phones */
			echo '<tr><td>' . esc_html__( 'Οργανισμός', 'search-signals' ) . '</td><td>' . esc_html( sprintf( __( '%1$s · logo %2$s · %3$d τηλέφωνα', 'search-signals' ), $st['org']['name'], $st['org']['logo'] ? '#' . $st['org']['logo'] : '—', count( $st['org']['phones'] ) ) ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Sitemaps', 'search-signals' ) . '</td><td>' . esc_html( (int) $st['sm_per'] . ' · ' . implode( ', ', (array) $st['sm_types'] ) . ' · ' . implode( ', ', (array) $st['sm_taxes'] ) ) . '</td></tr>';
			echo '</tbody></table>';
			foreach ( $scan['notes'] as $n ) {
				echo '<p>' . self::badge( __( 'Σημείωση', 'search-signals' ), 'warn' ) . ' ' . esc_html( (string) $n ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
			echo '<h3 class="ssg-h3">' . esc_html__( 'Meta σελίδων και όρων', 'search-signals' ) . '</h3><table class="widefat striped ssg-table"><tbody>';
			foreach ( $scan['counts'] as $k => $n ) {
				echo '<tr><td><code>' . esc_html( (string) $k ) . '</code></td><td>' . (int) $n . '</td></tr>';
			}
			/* translators: 1: same, 2: skipped */
			echo '</tbody></table><p class="ssg-muted">' . esc_html( sprintf( __( '%1$d ίδια ήδη, %2$d χωρίς κάτι να μεταφερθεί (π.χ. robots μόνο «index», κύρια κατηγορία που το προϊόν δεν έχει πια).', 'search-signals' ), (int) $scan['same'], (int) $scan['skipped'] ) ) . '</p>';
			if ( $scan['samples'] ) {
				echo '<details class="ssg-details"><summary>' . esc_html__( 'Δείγματα', 'search-signals' ) . '</summary><table class="widefat striped ssg-table"><tbody>';
				foreach ( $scan['samples'] as $smp ) {
					$link = 'post' === $smp['type'] ? get_edit_post_link( (int) $smp['id'] ) : get_edit_term_link( (int) $smp['id'] );
					echo '<tr><td><a href="' . esc_url( (string) $link ) . '">' . esc_html( $smp['type'] . ' #' . $smp['id'] ) . '</a></td><td><code>' . esc_html( $smp['key'] ) . '</code></td><td>' . esc_html( $smp['value'] ) . '</td></tr>';
				}
				echo '</tbody></table></details>';
			}
			if ( $scan['og_content'] ) {
				/* translators: %d: rows */
				echo '<p>' . self::badge( __( 'Δεν μεταφέρεται', 'search-signals' ), 'muted' ) . ' ' . esc_html( sprintf( __( 'rank_math_og_content_image: %d εγγραφές. Είναι η cache του Rank Math για την πρώτη εικόνα του περιεχομένου· το Search Signals τη βρίσκει μόνο του.', 'search-signals' ), count( $scan['og_content'] ) ) ) . '</p><details class="ssg-details"><summary>' . esc_html__( 'Τιμές', 'search-signals' ) . '</summary><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				foreach ( $scan['og_content'] as $og ) {
					echo '<li>#' . (int) $og['id'] . ' <code>' . esc_html( $og['value'] ) . '</code></li>';
				}
				echo '</ul></details>';
			}
			self::button_form( 'import', array(), __( 'Εισαγωγή', 'search-signals' ), 'button button-primary', __( 'Εισαγωγή των ρυθμίσεων και των meta από το Rank Math;', 'search-signals' ) );
		}

		$log = SSG_Import::log();
		if ( $log ) {
			/* translators: 1: date, 2: number of meta */
			echo '<h3 class="ssg-h3">' . esc_html__( 'Αναίρεση', 'search-signals' ) . '</h3><p>' . esc_html( sprintf( __( 'Εισαγωγή στις %1$s: %2$d meta.', 'search-signals' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $log['time'] ), count( (array) $log['meta'] ) ) ) . '</p>';
			self::button_form( 'undo', array(), __( 'Αναίρεση εισαγωγής', 'search-signals' ), 'button', __( 'Επαναφορά των ρυθμίσεων και των meta όπως ήταν πριν την εισαγωγή; Ό,τι άλλαξες με το χέρι μετά μένει.', 'search-signals' ) );
		}
		echo '<p class="description">' . esc_html__( 'Οι ανακατευθύνσεις και το log των 404 του Rank Math μεταφέρονται με το plugin Redirects, όχι εδώ.', 'search-signals' ) . '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * Compare
	 * ------------------------------------------------------------------- */

	private static function render_compare(): void {
		$res = get_transient( 'ssg_cmp_' . get_current_user_id() );
		$sm  = get_transient( 'ssg_cmp_sm_' . get_current_user_id() );
		?>
		<h2 class="ssg-h2"><?php esc_html_e( 'Σελίδες', 'search-signals' ); ?></h2>
		<div class="ssg-zone">
			<p class="description"><?php esc_html_e( 'Αριστερά ό,τι τυπώνει τώρα το site σε έναν επισκέπτη (π.χ. το Rank Math), δεξιά ό,τι θα τυπώσει το Search Signals. Πράσινο = ίδιο, κίτρινο = άλλο κείμενο ή κάτι επιπλέον, κόκκινο = λείπει.', 'search-signals' ); ?></p>
			<form method="post">
				<?php self::form_head( 'compare' ); ?>
				<textarea name="ssg_urls" class="ssg-patterns" rows="8"><?php echo esc_textarea( implode( "\n", SSG_Compare::urls() ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Μία διεύθυνση του site ανά γραμμή (έως 40). Κενό = αυτόματα δείγματα.', 'search-signals' ); ?></p>
				<p class="ssg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Σύγκριση', 'search-signals' ); ?></button></p>
			</form>
			<?php
			if ( is_array( $res ) && ! empty( $res['rows'] ) ) {
				/* translators: 1: same, 2: text, 3: missing, 4: extra */
				echo '<p>' . esc_html( sprintf( __( 'Ίδια %1$d · άλλο κείμενο %2$d · λείπουν %3$d · επιπλέον %4$d', 'search-signals' ), (int) $res['summary']['same'], (int) $res['summary']['text'], (int) $res['summary']['missing'], (int) $res['summary']['extra'] ) ) . '</p>';
				$kinds = array(
					'same'    => 'ok',
					'text'    => 'warn',
					'extra'   => 'warn',
					'missing' => 'danger',
				);
				foreach ( $res['rows'] as $row ) {
					$bad = count( array_intersect( array_values( (array) $row['fields'] ), array( 'missing', 'text' ) ) );
					echo '<details class="ssg-details"' . ( $bad ? ' open' : '' ) . '><summary><code>' . esc_html( (string) $row['url'] ) . '</code> ' . ( isset( $row['live']['error'] ) ? self::badge( (string) $row['live']['error'], 'danger' ) : ( $bad ? self::badge( (string) $bad, 'warn' ) : self::badge( '✓', 'ok' ) ) ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
					if ( $row['fields'] ) {
						echo '<table class="widefat striped ssg-table"><thead><tr><th></th><th>' . esc_html__( 'Τώρα', 'search-signals' ) . '</th><th>Search Signals</th></tr></thead><tbody>';
						foreach ( SSG_Compare::FIELDS as $k ) {
							$stt = isset( $row['fields'][ $k ] ) ? $row['fields'][ $k ] : 'same';
							echo '<tr><td>' . self::badge( $k, isset( $kinds[ $stt ] ) ? $kinds[ $stt ] : 'muted' ) . '</td><td>' . esc_html( (string) $row['live'][ $k ] ) . '</td><td>' . esc_html( (string) $row['ours'][ $k ] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
						}
						echo '<tr><td>' . esc_html__( 'canonical / JSON-LD', 'search-signals' ) . '</td><td>' . (int) $row['live']['canonicals'] . ' / ' . (int) $row['live']['ld'] . '</td><td>' . (int) $row['ours']['canonicals'] . ' / ' . (int) $row['ours']['ld'] . '</td></tr>';
						echo '</tbody></table>';
					}
					echo '</details>';
				}
			}
			?>
		</div>

		<h2 class="ssg-h2"><?php esc_html_e( 'Sitemaps', 'search-signals' ); ?></h2>
		<div class="ssg-zone">
			<?php self::button_form( 'compare_sitemaps', array(), __( 'Σύγκριση sitemaps', 'search-signals' ), 'button' ); ?>
			<?php
			if ( is_array( $sm ) ) {
				/* translators: 1: live URLs, 2: our URLs */
				echo '<p>' . esc_html( sprintf( __( 'Τώρα %1$d διευθύνσεις, Search Signals %2$d.', 'search-signals' ), (int) $sm['live'], (int) $sm['ours'] ) ) . '</p>';
				echo '<table class="widefat striped ssg-table"><thead><tr><th>' . esc_html__( 'Αρχείο', 'search-signals' ) . '</th><th>' . esc_html__( 'Τώρα', 'search-signals' ) . '</th><th>Search Signals</th></tr></thead><tbody>';
				foreach ( array_unique( array_merge( array_keys( (array) $sm['live_files'] ), array_keys( (array) $sm['our_files'] ) ) ) as $f ) {
					$a = isset( $sm['live_files'][ $f ] ) ? (int) $sm['live_files'][ $f ] : '—';
					$b = isset( $sm['our_files'][ $f ] ) ? (int) $sm['our_files'][ $f ] : '—';
					echo '<tr><td><code>' . esc_html( (string) $f ) . '</code></td><td>' . esc_html( (string) $a ) . '</td><td>' . esc_html( (string) $b ) . '</td></tr>';
				}
				echo '</tbody></table>';
				foreach ( array( 'only_live' => __( 'Μόνο στο τωρινό sitemap', 'search-signals' ), 'only_ours' => __( 'Μόνο στο Search Signals', 'search-signals' ) ) as $k => $label ) {
					if ( ! empty( $sm[ $k ] ) ) {
						echo '<details class="ssg-details"><summary>' . esc_html( $label ) . ' (' . count( $sm[ $k ] ) . ')</summary><ul>';
						foreach ( $sm[ $k ] as $u ) {
							echo '<li><code>' . esc_html( (string) $u ) . '</code></li>';
						}
						echo '</ul></details>';
					}
				}
			}
			?>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['ssg_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Search_Signals::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'search-signals' ) );
		}
		$uid = get_current_user_id();
		$ok  = __( 'Αποθηκεύτηκε.', 'search-signals' );

		switch ( sanitize_key( wp_unslash( $_POST['ssg_action'] ) ) ) {
			case 'save_overview':
				$s         = SSG_Settings::get();
				$s['mode'] = sanitize_key( self::post( 'ssg_mode' ) );
				$mods      = isset( $_POST['ssg_mods'] ) && is_array( $_POST['ssg_mods'] ) ? wp_unslash( $_POST['ssg_mods'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- keys whitelisted in sanitize().
				$s['mods'] = array();
				foreach ( SSG_Settings::MODULES as $m ) {
					$s['mods'][ $m ] = ! empty( $mods[ $m ] );
				}
				$s['keep'] = '' !== self::post( 'ssg_keep' );
				SSG_Settings::save( $s );
				self::redirect( 'overview', array( self::msg( 'success', $ok ) ) );
				break;
			case 'save_titles':
				$s        = SSG_Settings::get();
				$s['sep'] = self::post( 'ssg_sep' );
				$tpl      = isset( $_POST['ssg_tpl'] ) && is_array( $_POST['ssg_tpl'] ) ? wp_unslash( $_POST['ssg_tpl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in sanitize().
				foreach ( $tpl as $k => $row ) {
					$row               = is_array( $row ) ? $row : array();
					$row['n']          = ! empty( $row['n'] );
					$s['tpl'][ (string) $k ] = $row;
				}
				foreach ( array( 'noindex_empty', 'paged_noindex', 'wc_generator' ) as $k ) {
					$s[ $k ] = '' !== self::post( 'ssg_' . $k );
				}
				SSG_Settings::save( $s );
				self::redirect( 'titles', array( self::msg( 'success', $ok ) ) );
				break;
			case 'save_schema':
				$s                  = SSG_Settings::get();
				$s['org']['type']   = self::post( 'ssg_org_type' );
				$s['org']['name']   = self::post( 'ssg_org_name' );
				$s['org']['url']    = self::post( 'ssg_org_url' );
				$s['org']['logo']   = absint( self::post( 'ssg_org_logo' ) );
				$s['org']['phones'] = isset( $_POST['ssg_phones'] ) && is_array( $_POST['ssg_phones'] ) ? array_values( wp_unslash( $_POST['ssg_phones'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in sanitize().
				$s['org']['same_as'] = self::post( 'ssg_same_as' );
				$s['image']          = absint( self::post( 'ssg_image' ) );
				$s['twitter']        = self::post( 'ssg_twitter' );
				$s['search_product'] = '' !== self::post( 'ssg_search_product' );
				SSG_Settings::save( $s );
				self::redirect( 'schema', array( self::msg( 'success', $ok ) ) );
				break;
			case 'save_sitemaps':
				$s               = SSG_Settings::get();
				$s['sm_per']     = (int) self::post( 'ssg_sm_per' );
				$s['sm_images']  = '' !== self::post( 'ssg_sm_images' );
				$s['sm_authors'] = '' !== self::post( 'ssg_sm_authors' );
				$s['sm_types']   = self::posted_keys( 'ssg_sm_types' );
				$s['sm_taxes']   = self::posted_keys( 'ssg_sm_taxes' );
				SSG_Settings::save( $s );
				self::redirect( 'sitemaps', array( self::msg( 'success', $ok ) ) );
				break;
			case 'save_llms':
				$s               = SSG_Settings::get();
				$s['llms_intro'] = self::post( 'ssg_llms_intro' );
				$s['llms_limit'] = (int) self::post( 'ssg_llms_limit' );
				$s['llms_types'] = self::posted_keys( 'ssg_llms_types' );
				$s['llms_taxes'] = self::posted_keys( 'ssg_llms_taxes' );
				SSG_Settings::save( $s );
				self::redirect( 'llms', array( self::msg( 'success', $ok ) ) );
				break;
			case 'static_move':
				require_once SSG_PATH . 'includes/class-static.php';
				$r    = SSG_Static::move();
				$msgs = array();
				if ( $r['moved'] ) {
					/* translators: %s: files */
					$msgs[] = self::msg( 'success', sprintf( __( 'Μεταφέρθηκαν: %s', 'search-signals' ), implode( ', ', $r['moved'] ) ) );
				}
				if ( $r['failed'] ) {
					/* translators: %s: files */
					$msgs[] = self::msg( 'error', sprintf( __( 'Δεν μεταφέρθηκαν (δικαιώματα αρχείων;): %s', 'search-signals' ), implode( ', ', $r['failed'] ) ) );
				}
				set_transient( 'ssg_static_chk_' . $uid, SSG_Static::check(), 10 * MINUTE_IN_SECONDS );
				self::redirect( 'llms', $msgs );
				break;
			case 'static_restore':
				require_once SSG_PATH . 'includes/class-static.php';
				$why  = SSG_Static::restore( (int) self::post( 'ssg_index' ) );
				$text = array(
					''        => __( 'Το αρχείο γύρισε στη ρίζα.', 'search-signals' ),
					'exists'  => __( 'Υπάρχει ήδη αρχείο με αυτό το όνομα στη ρίζα· τίποτα δεν άλλαξε.', 'search-signals' ),
					'missing' => __( 'Το backup δεν βρέθηκε.', 'search-signals' ),
					'invalid' => __( 'Μη έγκυρο backup.', 'search-signals' ),
					'failed'  => __( 'Η μεταφορά απέτυχε (δικαιώματα αρχείων;).', 'search-signals' ),
				);
				self::redirect( 'llms', array( self::msg( '' === $why ? 'success' : 'error', isset( $text[ $why ] ) ? $text[ $why ] : $why ) ) );
				break;
			case 'static_check':
				require_once SSG_PATH . 'includes/class-static.php';
				set_transient( 'ssg_static_chk_' . $uid, SSG_Static::check(), 10 * MINUTE_IN_SECONDS );
				self::redirect( 'llms', array() );
				break;
			case 'scan':
				set_transient( 'ssg_scan_' . $uid, SSG_Import::scan(), HOUR_IN_SECONDS );
				self::redirect( 'import', array() );
				break;
			case 'import':
				$r = SSG_Import::run();
				delete_transient( 'ssg_scan_' . $uid );
				/* translators: %d: number of meta */
				self::redirect( 'import', array( self::msg( 'success', sprintf( __( 'Έγινε εισαγωγή των ρυθμίσεων και %d meta.', 'search-signals' ), (int) $r['meta'] ) ) ) );
				break;
			case 'undo':
				$r = SSG_Import::undo();
				if ( ! $r ) {
					self::redirect( 'import', array( self::msg( 'error', __( 'Δεν υπάρχει εισαγωγή για αναίρεση.', 'search-signals' ) ) ) );
				}
				/* translators: 1: restored, 2: kept */
				self::redirect( 'import', array( self::msg( 'success', sprintf( __( 'Αναίρεση: %1$d meta επανήλθαν, %2$d έμειναν γιατί άλλαξαν με το χέρι.', 'search-signals' ), (int) $r[0], (int) $r[1] ) ) ) );
				break;
			case 'compare':
				$urls = SSG_Compare::save_urls( self::post( 'ssg_urls' ) );
				set_transient( 'ssg_cmp_' . $uid, SSG_Compare::run( $urls ? $urls : SSG_Compare::samples() ), HOUR_IN_SECONDS );
				self::redirect( 'compare', array() );
				break;
			case 'compare_sitemaps':
				set_transient( 'ssg_cmp_sm_' . $uid, SSG_Compare::sitemaps(), HOUR_IN_SECONDS );
				self::redirect( 'compare', array() );
				break;
			case 'switch_over':
				$why = SSG_Switch::over();
				self::redirect( 'overview', array( '' === $why ? self::msg( 'success', __( 'Η μετάβαση έγινε: το Search Signals είναι ενεργό για όλους.', 'search-signals' ) ) : self::msg( 'error', $why ) ) );
				break;
			case 'switch_back':
				$why = SSG_Switch::back();
				self::redirect( 'overview', array( '' === $why ? self::msg( 'success', __( 'Η επιστροφή έγινε: το προηγούμενο SEO plugin είναι ξανά ενεργό.', 'search-signals' ) ) : self::msg( 'error', $why ) ) );
				break;
			default:
				self::redirect( 'overview', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'search-signals' ) ) ) );
		}
	}

	/** Keys of a posted checkbox group. */
	private static function posted_keys( string $name ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$raw = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? array_keys( wp_unslash( $_POST[ $name ] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return array_values( array_map( 'sanitize_key', array_map( 'strval', $raw ) ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		if ( $msgs ) {
			set_transient( 'ssg_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers or SSG_Settings sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'ssg_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'ssg_aui_msg_' . get_current_user_id() );
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
		<p class="ssg-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'search-signals' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="ssg-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="ssg-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'search-signals' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="ssg-footer-cta">
			<a class="ssg-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'search-signals' ); ?>
			</a>
			<a class="ssg-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'search-signals' ); ?>
			</a>
		</p>
		<?php
	}
}
