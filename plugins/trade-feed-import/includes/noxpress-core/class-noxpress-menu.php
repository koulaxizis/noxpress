<?php
/**
 * Noxpress_Menu — a short, personal "Noxpress" submenu (Bible §8, Core 1.5.0).
 *
 * Suite plugins keep registering their pages exactly as before; nothing in
 * them changes. Late in admin_menu this class only reorders the list
 * $submenu['noxpress'] and hides entries with a CSS class, so every page
 * stays registered, keeps its capability check, its title and its URL.
 *
 * Compact menu (the default, per user):
 *  - «Επισκόπηση» (the hub);
 *  - the user's favourites (★ in the hub), or the 5 suite plugins the user
 *    opened last when there are no favourites, one row per plugin, named
 *    after the plugin, with the badges of its hidden pages;
 *  - the plugin of the current page, so the user always sees where they are;
 *  - entries of third-party plugins under "noxpress", unchanged;
 *  - «Όλα τα εργαλεία», the categories of the hub.
 *
 * «Πλήρες μενού» (per user, in the hub) keeps the list exactly as WordPress
 * builds it. Plugins with several pages get a tab bar at the top of each
 * page in both modes, and every suite page is a command in WordPress's own
 * command palette (Ctrl/Cmd+K) with a fallback "Go to…" dialog of ours.
 *
 * Data: user meta noxpress_menu = { pins: string[], recent: string[],
 * full: bool }. Deleted by the uninstall of the last suite plugin (§16).
 */

defined( 'ABSPATH' ) || exit;

final class Noxpress_Menu {

	const META        = 'noxpress_menu';
	const RECENT      = 5;
	const MAX_PINS    = 60;
	const NONCE       = 'noxpress_menu';
	const NONCE_FIELD = '_noxpress_menu_nonce';
	const HIDE_CLASS  = 'nx-menu-hide';
	const TOOLS       = 'nx-tools';

	/** plugin slug => list of accessible submenu entries (WordPress format). */
	private static $groups = array();

	/** Plugin slug of the current admin page, '' when it is not a suite page. */
	private static $current = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'organize' ), PHP_INT_MAX - 100 );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_action( 'admin_post_noxpress_menu', array( __CLASS__, 'route' ) );
		add_action( 'admin_head', array( __CLASS__, 'head_css' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'tabs' ), 1 );
		add_action( 'admin_footer', array( __CLASS__, 'palette' ) );
	}

	/* =====================================================================
	 * Per-user state
	 * =================================================================== */

	/** { pins, recent, full } of the current user, always well-formed. */
	public static function state(): array {
		$uid   = get_current_user_id();
		$raw   = $uid ? get_user_meta( $uid, self::META, true ) : array();
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = static function ( $list ): array {
			$out = array();
			foreach ( is_array( $list ) ? $list : array() as $slug ) {
				$slug = (string) $slug;
				if ( preg_match( Noxpress_Core::SLUG_RE, $slug ) && ! in_array( $slug, $out, true ) ) {
					$out[] = $slug;
				}
			}
			return $out;
		};
		return array(
			'pins'   => array_slice( $clean( $raw['pins'] ?? array() ), 0, self::MAX_PINS ),
			'recent' => array_slice( $clean( $raw['recent'] ?? array() ), 0, self::RECENT ),
			'full'   => ! empty( $raw['full'] ),
		);
	}

	private static function save( array $state ): void {
		$uid = get_current_user_id();
		if ( $uid ) {
			update_user_meta( $uid, self::META, $state );
		}
	}

	public static function is_pinned( string $slug ): bool {
		return in_array( $slug, self::state()['pins'], true );
	}

	public static function is_full(): bool {
		return self::state()['full'];
	}

	/** Remembers a visit to a suite plugin (most recent first). */
	private static function remember( string $slug ): void {
		$state = self::state();
		if ( isset( $state['recent'][0] ) && $state['recent'][0] === $slug ) {
			return; // Already first: no write.
		}
		$recent          = array_values( array_diff( $state['recent'], array( $slug ) ) );
		array_unshift( $recent, $slug );
		$state['recent'] = array_slice( $recent, 0, self::RECENT );
		self::save( $state );
	}

	/**
	 * POST to admin-post.php (action noxpress_menu), from the hub and the
	 * tab bar: pin / unpin a plugin, full / compact menu. Its own endpoint,
	 * so no plugin page ever sees our form. Nonce → capability →
	 * whitelisted action → PRG (Bible §11).
	 */
	public static function route(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['noxpress_menu_action'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Noxpress_Core::view_cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress' ), '', array( 'response' => 403 ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['noxpress_menu_action'] ) );
		$slug   = isset( $_POST['noxpress_slug'] ) ? sanitize_key( wp_unslash( $_POST['noxpress_slug'] ) ) : '';
		$state  = self::state();

		if ( 'pin' === $action && isset( Noxpress_Core::suite()[ $slug ] ) ) {
			if ( ! in_array( $slug, $state['pins'], true ) && count( $state['pins'] ) < self::MAX_PINS ) {
				$state['pins'][] = $slug;
			}
		} elseif ( 'unpin' === $action ) {
			$state['pins'] = array_values( array_diff( $state['pins'], array( $slug ) ) );
		} elseif ( 'full' === $action ) {
			$state['full'] = ! empty( $_POST['noxpress_full'] );
		} else {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress' ), '', array( 'response' => 400 ) );
		}
		self::save( $state );

		// Back to the page the form was on (hub or plugin page), same admin.
		$back = isset( $_POST['noxpress_back'] ) ? sanitize_key( wp_unslash( $_POST['noxpress_back'] ) ) : '';
		$page = '' !== $back ? $back : Noxpress_Core::MENU;
		$url  = self_admin_url( 'admin.php?page=' . rawurlencode( $page ) );
		if ( Noxpress_Core::MENU === $page && 'full' !== $action ) {
			$url .= '#nx-row-' . $slug;
		}
		wp_safe_redirect( $url );
		exit;
	}

	/* =====================================================================
	 * Which plugin owns a submenu entry
	 * =================================================================== */

	/**
	 * Suite slug of the plugin that registered a page: the file of the
	 * page's callback (inside wp-content/plugins/<slug>/), else the catalog
	 * ("page" prefix before the first hyphen, or an exact "more" entry).
	 */
	public static function owner( string $page, array $suite ): string {
		foreach ( $suite as $slug => $info ) {
			if ( in_array( $page, (array) ( $info['more'] ?? array() ), true ) || ( $info['page'] ?? '' ) === $page ) {
				return (string) $slug;
			}
		}

		$hook = get_plugin_page_hookname( $page, Noxpress_Core::MENU );
		$from = self::callback_plugin( $hook );
		if ( '' !== $from ) {
			// A page of another plugin, theme or mu-plugin stays theirs, whatever its prefix.
			return isset( $suite[ $from ] ) ? $from : '';
		}

		$dash = strpos( $page, '-' );
		if ( false !== $dash ) {
			$prefix = substr( $page, 0, $dash + 1 );
			foreach ( $suite as $slug => $info ) {
				$main = (string) ( $info['page'] ?? '' );
				if ( '' !== $main && 0 === strpos( $main, $prefix ) ) {
					return (string) $slug;
				}
			}
		}
		return '';
	}

	/** Plugin folder of the first callback hooked to a page hook, '-' outside plugins, '' when unknown. */
	private static function callback_plugin( string $hook ): string {
		global $wp_filter;
		if ( '' === $hook || empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return '';
		}
		$root = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $cb ) {
				$fn = $cb['function'] ?? null;
				try {
					if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) ) {
						$ref = new ReflectionMethod( $fn[0], (string) $fn[1] );
					} elseif ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
						$ref = new ReflectionMethod( $fn );
					} elseif ( is_string( $fn ) || $fn instanceof Closure ) {
						$ref = new ReflectionFunction( $fn );
					} else {
						continue;
					}
				} catch ( ReflectionException $e ) {
					continue;
				}
				$file = (string) $ref->getFileName();
				if ( '' === $file ) {
					continue;
				}
				// plugin_basename() also resolves symlinked plugin folders.
				$rel = plugin_basename( $file );
				$cut = strpos( $rel, '/' );
				if ( false !== $cut && 0 !== strpos( wp_normalize_path( $file ), $root ) && $rel === ltrim( wp_normalize_path( $file ), '/' ) ) {
					return '-'; // Outside the plugins folder (theme, mu-plugin): not ours.
				}
				return false === $cut ? '-' : substr( $rel, 0, $cut );
			}
		}
		return '';
	}

	/* =====================================================================
	 * The submenu
	 * =================================================================== */

	/** Groups the "noxpress" submenu per plugin and, in compact mode, hides the rest. */
	public static function organize(): void {
		global $submenu;
		if ( empty( $submenu[ Noxpress_Core::MENU ] ) || ! is_array( $submenu[ Noxpress_Core::MENU ] ) || ! get_current_user_id() ) {
			return;
		}

		$suite  = Noxpress_Core::suite();
		$hub    = array();
		$groups = array();
		$others = array();
		foreach ( $submenu[ Noxpress_Core::MENU ] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}
			$page = (string) $item[2];
			if ( Noxpress_Core::MENU === $page ) {
				$hub[] = $item;
				continue;
			}
			$owner = self::owner( $page, $suite );
			if ( '' === $owner ) {
				$others[] = $item;
				continue;
			}
			$groups[ $owner ][] = $item;
		}

		// The catalog's main page first, then the plugin's own order.
		foreach ( $groups as $slug => $items ) {
			$main = (string) ( $suite[ $slug ]['page'] ?? '' );
			usort(
				$items,
				static function ( $a, $b ) use ( $main ) {
					return ( (string) $b[2] === $main ) <=> ( (string) $a[2] === $main );
				}
			);
			$groups[ $slug ] = array_values(
				array_filter(
					$items,
					static function ( $item ) {
						return current_user_can( (string) ( $item[1] ?? 'do_not_allow' ) );
					}
				)
			);
			if ( ! $groups[ $slug ] ) {
				unset( $groups[ $slug ] );
			}
		}
		self::$groups = $groups;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		foreach ( $groups as $slug => $items ) {
			foreach ( $items as $item ) {
				if ( '' !== $page && (string) $item[2] === $page ) {
					self::$current = (string) $slug;
					break 2;
				}
			}
		}
		if ( '' !== self::$current ) {
			self::remember( self::$current );
		}

		$state = self::state();
		if ( $state['full'] ) {
			return; // Exactly as WordPress builds it.
		}

		$shown = $state['pins'] ? $state['pins'] : $state['recent'];
		$shown = array_values( array_filter( $shown, static function ( $slug ) use ( $groups ) {
			return isset( $groups[ $slug ] );
		} ) );
		if ( '' !== self::$current && ! in_array( self::$current, $shown, true ) ) {
			$shown[] = self::$current;
		}

		$list = $hub;
		$rest = array();
		foreach ( $shown as $slug ) {
			$list[] = self::row( $slug, $groups[ $slug ], $suite[ $slug ] ?? array() );
		}
		foreach ( $groups as $slug => $items ) {
			$skip = in_array( $slug, $shown, true ) ? 1 : 0;
			foreach ( array_slice( $items, $skip ) as $item ) {
				// Hidden, but kept in the list: WordPress finds the page's parent and title.
				$item[4] = trim( ( $item[4] ?? '' ) . ' ' . self::HIDE_CLASS );
				$rest[]  = $item;
			}
		}
		foreach ( $others as $item ) {
			$list[] = $item;
		}
		$list[] = array(
			__( 'Όλα τα εργαλεία', 'noxpress' ),
			Noxpress_Core::view_cap(),
			'admin.php?page=' . Noxpress_Core::MENU . '#' . self::TOOLS,
			__( 'Όλα τα εργαλεία', 'noxpress' ),
			'nx-menu-tools',
		);

		$submenu[ Noxpress_Core::MENU ] = array_merge( $list, $rest ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
	}

	/**
	 * One visible row for a plugin: its main entry, named after the plugin,
	 * with the badges ("update-plugins" / "awaiting-mod" counters) of its
	 * other pages, so a hidden counter is never lost.
	 */
	private static function row( string $slug, array $items, array $info ): array {
		$row    = $items[0];
		$badges = '';
		foreach ( $items as $item ) {
			if ( preg_match_all( '#<span class="(?:update-plugins|awaiting-mod)[^"]*"[^>]*>.*?</span>\s*</span>#s', (string) $item[0], $m ) ) {
				$badges .= ' ' . implode( ' ', $m[0] );
			}
		}
		$name   = (string) ( $info['name'] ?? '' );
		$row[0] = esc_html( '' !== $name ? $name : wp_strip_all_tags( (string) $row[0] ) ) . $badges;
		return $row;
	}

	/**
	 * A hidden page highlights its plugin's visible row. Compact mode only:
	 * the full menu has every entry already.
	 */
	public static function submenu_file( $submenu_file, $parent_file ) {
		if ( Noxpress_Core::MENU !== $parent_file || '' === self::$current || empty( self::$groups[ self::$current ] ) || self::is_full() ) {
			return $submenu_file;
		}
		return (string) self::$groups[ self::$current ][0][2];
	}

	/** Hides the entries of the compact menu (tiny, every admin page). */
	public static function head_css(): void {
		if ( ! self::$groups ) {
			return;
		}
		echo '<style id="nx-menu-css">#adminmenu .' . esc_attr( self::HIDE_CLASS ) . '{display:none!important}</style>' . "\n";
	}

	/* =====================================================================
	 * Tabs, command palette, "Go to…"
	 * =================================================================== */

	/** Accessible entries of every suite plugin (for the hub). */
	public static function groups(): array {
		return self::$groups;
	}

	/** Admin URL of a submenu entry. */
	public static function url( string $page ): string {
		if ( false !== strpos( $page, '.php' ) ) {
			return admin_url( $page );
		}
		return admin_url( 'admin.php?page=' . rawurlencode( $page ) );
	}

	/** Plain-text label of an entry (badges removed). */
	public static function label( array $item ): string {
		$text = preg_replace( '#<span[^>]*>.*?</span>\s*(</span>)?#s', '', (string) $item[0] );
		return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
	}

	public static function assets(): void {
		if ( ! self::$groups || ! current_user_can( Noxpress_Core::view_cap() ) ) {
			return;
		}
		wp_enqueue_style( 'noxpress-menu', Noxpress_Core::url( 'assets/menu.css' ), array(), NOXPRESS_CORE );
		wp_enqueue_script( 'noxpress-menu', Noxpress_Core::url( 'assets/menu.js' ), array(), NOXPRESS_CORE, true );
	}

	/** Tab bar at the top of the pages of a plugin with more than one page. */
	public static function tabs(): void {
		if ( '' === self::$current || empty( self::$groups[ self::$current ] ) || count( self::$groups[ self::$current ] ) < 2 ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page  = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		$suite = Noxpress_Core::suite();
		$name  = (string) ( $suite[ self::$current ]['name'] ?? self::$current );
		?>
		<nav class="nx-tabs" aria-label="<?php echo esc_attr( $name ); ?>">
			<span class="nx-tabs-name"><?php echo esc_html( $name ); ?></span>
			<?php foreach ( self::$groups[ self::$current ] as $item ) : ?>
				<?php $is = (string) $item[2] === $page; ?>
				<a class="nx-tab<?php echo $is ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::url( (string) $item[2] ) ); ?>"<?php echo $is ? ' aria-current="page"' : ''; ?>><?php echo esc_html( self::label( $item ) ); ?></a>
			<?php endforeach; ?>
			<?php self::pin_form( self::$current, $page ); ?>
			<button type="button" class="nx-tabs-go" data-nx-go><?php esc_html_e( 'Μετάβαση σε…', 'noxpress' ); ?> <kbd>Ctrl K</kbd></button>
		</nav>
		<?php
	}

	/**
	 * The list behind Ctrl/Cmd+K: WordPress's command palette where it is
	 * loaded (menu.js registers one command per page), else our dialog.
	 */
	public static function palette(): void {
		if ( ! self::$groups || ! current_user_can( Noxpress_Core::view_cap() ) ) {
			return;
		}
		$suite = Noxpress_Core::suite();
		$cats  = Noxpress_Core::categories();
		$items = array(
			array(
				'id'    => 'noxpress',
				'name'  => __( 'Noxpress', 'noxpress' ),
				'page'  => __( 'Επισκόπηση', 'noxpress' ),
				'cat'   => '',
				'url'   => admin_url( 'admin.php?page=' . Noxpress_Core::MENU ),
			),
		);
		foreach ( self::$groups as $slug => $entries ) {
			$info = $suite[ $slug ] ?? array();
			$cat  = (string) ( $info['cat'] ?? 'other' );
			foreach ( $entries as $item ) {
				$items[] = array(
					'id'   => $slug . '/' . sanitize_title( (string) $item[2] ),
					'name' => (string) ( $info['name'] ?? $slug ),
					'page' => self::label( $item ),
					'cat'  => $cats[ $cat ] ?? $cats['other'],
					'url'  => self::url( (string) $item[2] ),
				);
			}
		}
		$config = array(
			'items' => $items,
			'text'  => array(
				'title'       => __( 'Μετάβαση σε…', 'noxpress' ),
				'placeholder' => __( 'Plugin ή σελίδα', 'noxpress' ),
				'empty'       => __( 'Δεν βρέθηκε τίποτα.', 'noxpress' ),
				'close'       => __( 'Κλείσιμο', 'noxpress' ),
			),
		);
		?>
		<script type="application/json" id="nx-menu-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
		<?php
	}

	/** «Πλήρες μενού» switch (hub). */
	public static function mode_form(): void {
		$full = self::is_full();
		?>
		<form method="post" class="nx-mode-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="noxpress_menu">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="noxpress_menu_action" value="full">
			<input type="hidden" name="noxpress_full" value="<?php echo $full ? '' : '1'; ?>">
			<span class="nx-muted"><?php echo esc_html( $full ? __( 'Μενού: πλήρες, με όλες τις σελίδες.', 'noxpress' ) : __( 'Μενού: σύντομο, με τα αγαπημένα (★) ή τα 5 πιο πρόσφατα.', 'noxpress' ) ); ?></span>
			<button type="submit" class="button"><?php echo esc_html( $full ? __( 'Σύντομο μενού', 'noxpress' ) : __( 'Πλήρες μενού', 'noxpress' ) ); ?></button>
		</form>
		<?php
	}

	/** Hidden fields of a pin / unpin form (hub rows and tab bar). */
	public static function pin_form( string $slug, string $back = '' ): void {
		$pinned = self::is_pinned( $slug );
		?>
		<form method="post" class="nx-pin-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="noxpress_menu">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="noxpress_menu_action" value="<?php echo $pinned ? 'unpin' : 'pin'; ?>">
			<input type="hidden" name="noxpress_slug" value="<?php echo esc_attr( $slug ); ?>">
			<input type="hidden" name="noxpress_back" value="<?php echo esc_attr( $back ); ?>">
			<button type="submit" class="nx-pin<?php echo $pinned ? ' is-pinned' : ''; ?>" aria-pressed="<?php echo $pinned ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $pinned ? __( 'Αφαίρεση από τα αγαπημένα του μενού', 'noxpress' ) : __( 'Προσθήκη στα αγαπημένα του μενού', 'noxpress' ) ); ?>">
				<span aria-hidden="true"><?php echo $pinned ? '★' : '☆'; ?></span>
				<span class="screen-reader-text"><?php echo esc_html( $pinned ? __( 'Αφαίρεση από τα αγαπημένα του μενού', 'noxpress' ) : __( 'Προσθήκη στα αγαπημένα του μενού', 'noxpress' ) ); ?></span>
			</button>
		</form>
		<?php
	}
}
