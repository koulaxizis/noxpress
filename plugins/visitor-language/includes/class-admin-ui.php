<?php
/**
 * VLG_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Visitor Language» of the shared "Noxpress" menu (admin_menu
 * priority 395), slug vlg-settings, capability manage_options. Tabs:
 *  - status   the switch, English locale, language switch, note, hreflang,
 *             and every page / product that has English texts
 *  - phrases  "Ελληνικά = English" for short texts
 *  - menus    a menu per theme location for English
 *  - more     the suite language switch (§9, WordPress-only exception), developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient vlg_aui_msg_{uid}); every save purges the page
 * cache, because the Greek pages carry the switch and the hreflang links.
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'vlg-settings';
	const NONCE       = 'vlg_admin';
	const NONCE_FIELD = 'vlg_nonce';
	const PRIORITY    = 395;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), self::PRIORITY );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VLG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'status'  => __( 'Κατάσταση', 'visitor-language' ),
			'phrases' => __( 'Σύντομα κείμενα', 'visitor-language' ),
			'menus'   => __( 'Μενού', 'visitor-language' ),
			'more'    => __( 'Γλώσσα και developers', 'visitor-language' ),
		);
	}

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Visitor Language', 'visitor-language' ),
			__( 'Visitor Language', 'visitor-language' ),
			Visitor_Language::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'visitor-language' ) . '</a>' );
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $tab ] ) ? $tab : 'status';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG === self::current_page() ) {
			wp_enqueue_style( 'vlg-admin', VLG_URL . 'assets/admin.css', array(), VLG_VERSION );
		}
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Visitor_Language::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'visitor-language' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap vlg-wrap">
			<h1><?php esc_html_e( 'Visitor Language', 'visitor-language' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Μια αγγλική εκδοχή του site στο /en/, για επισκέπτες από το εξωτερικό. Το WordPress, το WooCommerce, το θέμα και τα plugins Noxpress μιλούν αγγλικά μόνα τους· εσύ γράφεις στα αγγλικά μόνο όσα προϊόντα και σελίδες θέλεις. Το ελληνικό site δεν αλλάζει.', 'visitor-language' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Visitor_Language::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το VLG_DISABLE είναι ενεργό στο wp-config.php: το /en/ δεν απαντά, ό,τι κι αν λένε οι ρυθμίσεις.', 'visitor-language' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper vlg-tabs">
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
				case 'phrases':
					self::render_phrases();
					break;
				case 'menus':
					self::render_menus();
					break;
				case 'more':
					self::render_more();
					break;
				default:
					self::render_status();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="vlg_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="vlg-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="vlg-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	/** Posts with English texts (at most 200, newest first). */
	private static function translated(): array {
		$q = new WP_Query(
			array(
				'post_type'      => VLG_Meta_Box::types(),
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin page, at most 200 rows.
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_vlg_title', 'compare' => 'EXISTS' ),
					array( 'key' => '_vlg_excerpt', 'compare' => 'EXISTS' ),
					array( 'key' => '_vlg_content', 'compare' => 'EXISTS' ),
				),
			)
		);
		return array_map( 'intval', (array) $q->posts );
	}

	private static function render_status(): void {
		$s    = VLG_Settings::get();
		$home = VLG_Router::add_prefix( home_url( '/' ) );
		?>
		<form method="post">
			<?php self::form_head( 'save_status' ); ?>
			<div class="vlg-area">
				<h2 class="vlg-h2"><?php esc_html_e( 'Διακόπτης', 'visitor-language' ); ?></h2>
				<?php self::checkbox( 'vlg_on', $s['on'], __( 'Η αγγλική εκδοχή απαντά στο /en/', 'visitor-language' ) ); ?>
				<p class="vlg-hint">
					<?php
					if ( $s['on'] ) {
						echo '<a href="' . esc_url( $home ) . '" target="_blank" rel="noopener">' . esc_html( $home ) . '</a>';
					} else {
						esc_html_e( 'Κλειστό: το /en/ δίνει 404, όπως πριν.', 'visitor-language' );
					}
					?>
				</p>
				<h2 class="vlg-h2"><?php esc_html_e( 'Αγγλικά', 'visitor-language' ); ?></h2>
				<?php
				self::radio( 'vlg_locale', 'en_US', $s['locale'], __( 'Αγγλικά ΗΠΑ (en_US)', 'visitor-language' ) );
				self::radio( 'vlg_locale', 'en_GB', $s['locale'], __( 'Αγγλικά Βρετανίας (en_GB, χρειάζεται τη γλώσσα εγκατεστημένη)', 'visitor-language' ) );
				?>
				<h2 class="vlg-h2"><?php esc_html_e( 'Επιλογή γλώσσας', 'visitor-language' ); ?></h2>
				<?php
				self::radio( 'vlg_switch', 'float', $s['switch'], __( 'Μικρό κουμπί EL / EN κάτω αριστερά σε κάθε σελίδα', 'visitor-language' ) );
				self::radio( 'vlg_switch', 'products', $s['switch'], __( 'Το ίδιο κουμπί μόνο στα προϊόντα με αγγλικά κείμενα (π.χ. όσα στέλνονται στο εξωτερικό)', 'visitor-language' ) );
				self::radio( 'vlg_switch', 'none', $s['switch'], __( 'Μόνο όπου βάζω το shortcode [vlg_switch]', 'visitor-language' ) );
				self::checkbox( 'vlg_abroad', $s['abroad'], __( 'Και σε κάθε σελίδα για επισκέπτες εκτός Ελλάδας (η χώρα έρχεται από το Cloudflare του site, χωρίς υπηρεσία τρίτων)', 'visitor-language' ) );
				?>
				<h2 class="vlg-h2"><?php esc_html_e( 'Σελίδες χωρίς αγγλικά', 'visitor-language' ); ?></h2>
				<?php self::checkbox( 'vlg_note', $s['note'], __( 'Σημείωση «This page is only available in Greek» στην αρχή τους', 'visitor-language' ) ); ?>
				<?php self::checkbox( 'vlg_hreflang', $s['hreflang'], __( 'hreflang el / en στις σελίδες με αγγλικά κείμενα, για τις μηχανές αναζήτησης', 'visitor-language' ) ); ?>
			</div>
			<p class="vlg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'visitor-language' ); ?></button></p>
		</form>

		<h2 class="vlg-h2"><?php esc_html_e( 'Με αγγλικά κείμενα', 'visitor-language' ); ?></h2>
		<?php
		$ids = self::translated();
		if ( ! $ids ) {
			echo '<p class="description">' . esc_html__( 'Κανένα ακόμα. Γράψε τα αγγλικά στο κουτί «English» του προϊόντος ή της σελίδας.', 'visitor-language' ) . '</p>';
			return;
		}
		echo '<table class="widefat vlg-table"><thead><tr><th>' . esc_html__( 'Τίτλος', 'visitor-language' ) . '</th><th>' . esc_html__( 'Τύπος', 'visitor-language' ) . '</th><th>' . esc_html__( 'Αγγλικά', 'visitor-language' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$en    = VLG_Content::get( $id, '_vlg_title' );
			$parts = array();
			foreach ( array( '_vlg_title' => __( 'τίτλος', 'visitor-language' ), '_vlg_excerpt' => __( 'σύντομο', 'visitor-language' ), '_vlg_content' => __( 'κείμενο', 'visitor-language' ) ) as $k => $label ) {
				if ( '' !== VLG_Content::get( $id, $k ) ) {
					$parts[] = $label;
				}
			}
			printf(
				'<tr><td><a href="%1$s">%2$s</a>%3$s</td><td>%4$s</td><td>%5$s · <a href="%6$s" target="_blank" rel="noopener">/en/</a></td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( (string) get_the_title( $id ) ),
				'' !== $en ? ' <span class="vlg-muted">(' . esc_html( $en ) . ')</span>' : '',
				esc_html( (string) get_post_type( $id ) ),
				esc_html( implode( ', ', $parts ) ),
				esc_url( VLG_Router::add_prefix( (string) get_permalink( $id ) ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_phrases(): void {
		$s = VLG_Settings::get();
		?>
		<form method="post">
			<?php self::form_head( 'save_phrases' ); ?>
			<p class="description"><?php esc_html_e( 'Μία γραμμή για κάθε κείμενο, «Ελληνικά = English». Ισχύει για ονόματα και τιμές χαρακτηριστικών (π.χ. Μέγεθος, Χρώμα), κατηγορίες και ετικέτες προϊόντων, στοιχεία μενού, τρόπους αποστολής και πληρωμής. Έως 300 γραμμές.', 'visitor-language' ); ?></p>
			<textarea name="vlg_phrases" class="vlg-wide" rows="16" placeholder="Μέγεθος = Size&#10;Χρώμα = Colour&#10;Μαύρο = Black"><?php echo esc_textarea( VLG_Settings::phrases_text( $s['phrases'] ) ); ?></textarea>
			<p class="vlg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'visitor-language' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_menus(): void {
		$s     = VLG_Settings::get();
		$locs  = (array) get_registered_nav_menus();
		$menus = wp_get_nav_menus();
		?>
		<form method="post">
			<?php self::form_head( 'save_menus' ); ?>
			<p class="description"><?php esc_html_e( 'Για κάθε θέση μενού του θέματος, το μενού που βλέπει ο επισκέπτης στα αγγλικά. «Ίδιο» = το ελληνικό, με τα σύντομα κείμενα μεταφρασμένα.', 'visitor-language' ); ?></p>
			<?php if ( ! $locs ) : ?>
				<p class="description"><?php esc_html_e( 'Το θέμα δεν έχει θέσεις μενού.', 'visitor-language' ); ?></p>
			<?php else : ?>
				<table class="widefat vlg-table"><tbody>
				<?php foreach ( $locs as $loc => $label ) : ?>
					<tr><th><?php echo esc_html( (string) $label ); ?> <code><?php echo esc_html( (string) $loc ); ?></code></th><td>
						<select name="vlg_menus[<?php echo esc_attr( (string) $loc ); ?>]">
							<option value="0"><?php esc_html_e( 'Ίδιο', 'visitor-language' ); ?></option>
							<?php foreach ( $menus as $m ) : ?>
								<option value="<?php echo esc_attr( (string) $m->term_id ); ?>"<?php selected( isset( $s['menus'][ $loc ] ) ? (int) $s['menus'][ $loc ] : 0, (int) $m->term_id ); ?>><?php echo esc_html( $m->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
			<p class="vlg-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'visitor-language' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_more(): void {
		if ( ! VLG_Lang::rs_active() ) {
			$choice = (string) get_user_meta( get_current_user_id(), 'rs_lang', true );
			?>
			<form method="post">
				<?php self::form_head( 'save_lang' ); ?>
				<h2 class="vlg-h2"><?php esc_html_e( 'Γλώσσα των σελίδων Noxpress', 'visitor-language' ); ?></h2>
				<?php
				self::radio( 'vlg_ui_lang', '', in_array( $choice, array( 'el', 'en' ), true ) ? $choice : '', __( 'Όπως η γλώσσα του λογαριασμού', 'visitor-language' ) );
				self::radio( 'vlg_ui_lang', 'el', $choice, 'Ελληνικά' );
				self::radio( 'vlg_ui_lang', 'en', $choice, 'English' );
				?>
				<p class="vlg-submit"><button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'visitor-language' ); ?></button></p>
			</form>
			<?php
		}
		?>
		<h2 class="vlg-h2"><?php esc_html_e( 'Για developers', 'visitor-language' ); ?></h2>
		<table class="widefat vlg-table"><tbody>
			<tr><td><code>vlg_lang()</code></td><td><?php esc_html_e( '«el» ή «en» για το τρέχον αίτημα.', 'visitor-language' ); ?></td></tr>
			<tr><td><code>vlg_url( $url, $lang )</code></td><td><?php esc_html_e( 'Ένας σύνδεσμος του site στην ελληνική ή στην αγγλική εκδοχή.', 'visitor-language' ); ?></td></tr>
			<tr><td><code>[vlg_switch]</code></td><td><?php esc_html_e( 'Η επιλογή EL / EN όπου το βάλεις.', 'visitor-language' ); ?></td></tr>
			<tr><td><code>vlg_post_types</code></td><td><?php esc_html_e( 'Φίλτρο: οι τύποι περιεχομένου με κουτί «English» (page, post, product).', 'visitor-language' ); ?></td></tr>
			<tr><td><code>_vlg_title, _vlg_excerpt, _vlg_content</code></td><td><?php esc_html_e( 'Τα αγγλικά κείμενα, ως post meta, και στο REST API (wp/v2 και wc/v3, με δικαίωμα επεξεργασίας).', 'visitor-language' ); ?></td></tr>
			<tr><td><code>VLG_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά αμέσως το /en/.', 'visitor-language' ); ?></td></tr>
		</tbody></table>
		<?php
	}

	/* =====================================================================
	 * POST
	 * =================================================================== */

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in route().
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by each caller.
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['vlg_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Visitor_Language::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'visitor-language' ) );
		}
		$s     = VLG_Settings::get();
		$saved = self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'visitor-language' ) );
		switch ( sanitize_key( self::post( 'vlg_action' ) ) ) {
			case 'save_status':
				$s['on']       = '1' === self::post( 'vlg_on' );
				$s['locale']   = sanitize_text_field( self::post( 'vlg_locale' ) );
				$s['switch']   = sanitize_key( self::post( 'vlg_switch' ) );
				$s['abroad']   = '1' === self::post( 'vlg_abroad' );
				$s['note']     = '1' === self::post( 'vlg_note' );
				$s['hreflang'] = '1' === self::post( 'vlg_hreflang' );
				VLG_Settings::save( $s );
				Visitor_Language::purge();
				self::redirect( 'status', array( $saved ) );
				break;
			case 'save_phrases':
				list( $map, $skip ) = VLG_Settings::parse_phrases( sanitize_textarea_field( self::post( 'vlg_phrases' ) ) );
				$s['phrases']       = $map;
				$out                = VLG_Settings::save( $s );
				$msgs               = array( $saved );
				if ( $skip > 0 || count( $out['phrases'] ) < count( $map ) ) {
					$msgs[] = self::msg( 'warning', __( 'Κάποιες γραμμές δεν είχαν τη μορφή «Ελληνικά = English» ή ξεπέρασαν τις 300, και αγνοήθηκαν.', 'visitor-language' ) );
				}
				self::redirect( 'phrases', $msgs );
				break;
			case 'save_menus':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
				$raw        = isset( $_POST['vlg_menus'] ) && is_array( $_POST['vlg_menus'] ) ? wp_unslash( $_POST['vlg_menus'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in VLG_Settings::clean().
				$s['menus'] = is_array( $raw ) ? $raw : array();
				VLG_Settings::save( $s );
				self::redirect( 'menus', array( $saved ) );
				break;
			case 'save_lang':
				if ( ! VLG_Lang::rs_active() ) {
					$lang = self::post( 'vlg_ui_lang' );
					if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
						update_user_meta( get_current_user_id(), 'rs_lang', $lang );
					} else {
						delete_user_meta( get_current_user_id(), 'rs_lang' );
					}
					VLG_Lang::reset();
				}
				self::redirect( 'more', array( $saved ) );
				break;
			default:
				self::redirect( 'status', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'visitor-language' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array( 'type' => $type, 'text' => $text );
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'vlg_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function take_msgs(): array {
		$key  = 'vlg_aui_msg_' . get_current_user_id();
		$msgs = get_transient( $key );
		if ( false !== $msgs ) {
			delete_transient( $key );
		}
		return is_array( $msgs ) ? $msgs : array();
	}

	private static function print_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			if ( ! is_array( $n ) || empty( $n['text'] ) ) {
				continue;
			}
			$type = in_array( $n['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) $n['text'] ) . '</p></div>';
		}
	}

	private static function footer(): void {
		?>
		<p class="vlg-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'visitor-language' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="vlg-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="vlg-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'visitor-language' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="vlg-footer-cta">
			<a class="vlg-support" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'visitor-language' ); ?>
			</a>
			<a class="vlg-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'visitor-language' ); ?>
			</a>
		</p>
		<?php
	}
}
