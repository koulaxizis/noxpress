<?php
/**
 * Noxpress_Hub — the "Noxpress" landing page (Bible §16).
 *
 * One table for the whole suite: status, installed and available version,
 * and WordPress's own actions (update, install, activate, details,
 * auto-updates). Above it: the update channel and "Check now".
 *
 * Viewing needs manage_woocommerce (activate_plugins without WooCommerce).
 * Each action shows only to users who can do it (install_plugins,
 * update_plugins, activate_plugins); with DISALLOW_FILE_MODS WordPress
 * denies them all and the page says so.
 *
 * POST routes (admin_init, Bible §11): page slug → nonce → capability →
 * whitelisted action → PRG with a per-user notice transient.
 *
 * Bulk actions (install, update, uninstall all): a confirm dialog with a
 * checklist, then one request per plugin through WordPress's own AJAX
 * (install-plugin, update-plugin, delete-plugin), so no request can time
 * out and the package checks stay the same. Only (de)activation has an
 * AJAX of ours (noxpress_bulk_state).
 */

defined( 'ABSPATH' ) || exit;

final class Noxpress_Hub {

	const NONCE       = 'noxpress_hub';
	const NONCE_FIELD = '_noxpress_nonce';

	/** Unticked by default in "Uninstall all": their data is hard to replace. */
	const KEEP_BY_DEFAULT = array( 'site-bridge', 'data-migrator' );

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_noxpress_bulk_state', array( __CLASS__, 'ajax_state' ) );
	}

	private static function on_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) && Noxpress_Core::MENU === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
	}

	public static function assets(): void {
		if ( ! self::on_page() ) {
			return;
		}
		wp_enqueue_style( 'noxpress-hub', Noxpress_Core::url( 'assets/hub.css' ), array(), NOXPRESS_CORE );
		add_thickbox(); // Plugin details popup.
		if ( self::bulk_allowed() ) {
			wp_enqueue_script( 'noxpress-hub-bulk', Noxpress_Core::url( 'assets/hub-bulk.js' ), array(), NOXPRESS_CORE, true );
		}
	}

	/** Bulk actions: file changes allowed, and on multisite only in the network admin. */
	private static function bulk_allowed(): bool {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) && ( ! is_multisite() || is_network_admin() );
	}

	/** Capability for (de)activating suite plugins: network-wide on multisite. */
	private static function state_cap(): string {
		return is_multisite() ? 'manage_network_plugins' : 'activate_plugins';
	}

	/**
	 * AJAX: activate or deactivate one suite plugin (bulk actions).
	 * Nonce → capability → catalog slug in its canonical folder.
	 */
	public static function ajax_state(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::state_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress' ) ), 403 );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$file = Noxpress_Core::plugin_file( $slug );
		if ( ! in_array( $op, array( 'activate', 'deactivate' ), true ) || ! isset( Noxpress_Core::installed()[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Άγνωστο plugin της σουίτας.', 'noxpress' ) ), 400 );
		}

		if ( 'activate' === $op ) {
			$result = activate_plugin( $file, '', is_multisite() );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		} else {
			if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
				deactivate_plugins( $file, false, true );
			}
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file );
			}
			if ( is_plugin_active( $file ) ) {
				wp_send_json_error( array( 'message' => __( 'Η απενεργοποίηση δεν έγινε.', 'noxpress' ) ) );
			}
		}
		wp_send_json_success();
	}

	/** Admin URL for plugin management: the network admin on multisite. */
	private static function manage_url( string $path ): string {
		return is_multisite() ? network_admin_url( $path ) : admin_url( $path );
	}

	private static function msg_key(): string {
		return 'noxpress_hub_msg_' . get_current_user_id();
	}

	public static function route(): void {
		if ( ! self::on_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['noxpress_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['noxpress_action'] ) );
		$msg    = '';

		if ( 'channel' === $action ) {
			$channel = isset( $_POST['noxpress_channel'] ) ? sanitize_key( wp_unslash( $_POST['noxpress_channel'] ) ) : '';
			if ( in_array( $channel, array( 'stable', 'beta' ), true ) ) {
				Noxpress_Updater::set_channel( $channel );
				Noxpress_Updater::reapply();
				$msg = __( 'Το κανάλι ενημερώσεων αποθηκεύτηκε.', 'noxpress' );
			}
		} elseif ( 'check' === $action ) {
			Noxpress_Updater::refresh();
			$msg = __( 'Ο έλεγχος για ενημερώσεις ολοκληρώθηκε.', 'noxpress' );
		} elseif ( 'hide' === $action ) {
			$slug    = isset( $_POST['noxpress_slug'] ) ? sanitize_key( wp_unslash( $_POST['noxpress_slug'] ) ) : '';
			$version = isset( $_POST['noxpress_version'] ) ? sanitize_text_field( wp_unslash( $_POST['noxpress_version'] ) ) : '';
			$catalog = Noxpress_Core::catalog();
			if ( isset( $catalog[ $slug ] ) && preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
				Noxpress_Updater::hide( $slug, $version );
				/* translators: 1: plugin name, 2: version number */
				$msg = sprintf( __( 'Η ειδοποίηση για το %1$s %2$s κρύφτηκε. Θα ξαναεμφανιστεί με νεότερη έκδοση.', 'noxpress' ), $catalog[ $slug ]['name'], $version );
			}
		}

		if ( '' !== $msg ) {
			set_transient( self::msg_key(), $msg, 60 );
		}
		wp_safe_redirect( self_admin_url( 'admin.php?page=' . Noxpress_Core::MENU ) );
		exit;
	}

	/** One row of the table for every catalog plugin. */
	private static function rows(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = Noxpress_Core::installed();
		$manifest  = Noxpress_Updater::manifest();
		$updates   = get_site_transient( 'update_plugins' );

		// WordPress's update button needs the plugin in the update transient:
		// rebuild it when it is behind the manifest (e.g. a fresh install).
		foreach ( $installed as $slug => $data ) {
			$rel  = Noxpress_Updater::latest( $slug, $manifest );
			$file = Noxpress_Core::plugin_file( $slug );
			if ( $rel && version_compare( $rel['version'], (string) $data['Version'], '>' )
				&& ! ( is_object( $updates ) && isset( $updates->response[ $file ] ) ) ) {
				Noxpress_Updater::reapply();
				$updates = get_site_transient( 'update_plugins' );
				break;
			}
		}

		$auto = (array) get_site_option( 'auto_update_plugins', array() );
		$rows = array();

		foreach ( Noxpress_Core::catalog() as $slug => $info ) {
			$file   = Noxpress_Core::plugin_file( $slug );
			$is_in  = isset( $installed[ $slug ] );
			if ( ! $is_in && ! empty( $info['unlisted'] ) ) {
				continue;
			}
			$active = $is_in && ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) );
			$rel    = Noxpress_Updater::latest( $slug, $manifest );
			$other  = Noxpress_Updater::other( $slug, $manifest );
			$hidden = Noxpress_Updater::hidden( $slug );
			// Shown when newer than what is installed and not hidden at this version.
			if ( $other && ( ( $is_in && ! version_compare( $other['version'], (string) $installed[ $slug ]['Version'], '>' ) )
				|| ( '' !== $hidden && ! version_compare( $other['version'], $hidden, '>' ) ) ) ) {
				$other = null;
			}

			$rows[] = array(
				'slug'      => $slug,
				'file'      => $file,
				'info'      => $info,
				'installed' => $is_in,
				'active'    => $active,
				'version'   => $is_in ? (string) $installed[ $slug ]['Version'] : '',
				'release'   => $rel,
				'other'     => $other,
				'update'    => $is_in && $rel && version_compare( $rel['version'], (string) $installed[ $slug ]['Version'], '>' ),
				'queued'    => is_object( $updates ) && isset( $updates->response[ $file ] ),
				'auto'      => in_array( $file, $auto, true ),
			);
		}
		return $rows;
	}

	public static function render(): void {
		if ( ! current_user_can( Noxpress_Core::view_cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress' ) );
		}

		$msg = get_transient( self::msg_key() );
		if ( false !== $msg ) {
			delete_transient( self::msg_key() );
		}

		$rows       = self::rows();
		$can_update = current_user_can( 'update_plugins' );
		$file_mods  = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		$checked    = Noxpress_Updater::last_checked();
		$have_list  = (bool) Noxpress_Updater::manifest();
		$auto_ok    = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' );
		?>
		<div class="wrap nx-wrap">
			<h1><?php esc_html_e( 'Noxpress', 'noxpress' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Όλα τα plugins της σουίτας Noxpress: κατάσταση, εκδόσεις και ενημερώσεις.', 'noxpress' ); ?></p>

			<?php if ( is_string( $msg ) && '' !== $msg ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<?php if ( $file_mods ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Οι αλλαγές αρχείων είναι απενεργοποιημένες σε αυτό το site (DISALLOW_FILE_MODS): οι ενημερώσεις και οι εγκαταστάσεις γίνονται εκτός WordPress.', 'noxpress' ); ?></p></div>
			<?php elseif ( ! $can_update ) : ?>
				<p class="nx-muted"><?php esc_html_e( 'Οι εγκαταστάσεις και οι ενημερώσεις γίνονται από διαχειριστή του site.', 'noxpress' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $have_list ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Η λίστα εκδόσεων δεν είναι διαθέσιμη αυτή τη στιγμή (noxpress.tech). Θα ξαναδοκιμάσει αυτόματα.', 'noxpress' ); ?></p></div>
			<?php endif; ?>

			<?php
			$others = count( array_filter( $rows, static function ( $r ) {
				return $r['other'] && $r['installed'];
			} ) );
			?>
			<?php if ( $others && $can_update && ! $file_mods && 'stable' === Noxpress_Updater::channel() ) : ?>
				<div class="nx-zone nx-other">
					<p>
						<span class="nx-badge nx-badge--beta"><?php esc_html_e( 'beta', 'noxpress' ); ?></span>
						<?php
						/* translators: %d: number of plugins */
						printf( esc_html( _n( 'Υπάρχει νεότερη beta έκδοση για %d plugin.', 'Υπάρχουν νεότερες beta εκδόσεις για %d plugins.', $others, 'noxpress' ) ), (int) $others );
						?>
					</p>
					<p class="nx-muted"><?php esc_html_e( 'Οι beta εκδόσεις δοκιμάζονται ακόμα. Σε live site, καλύτερα πρώτα σε δοκιμαστικό ή με πρόσφατο backup.', 'noxpress' ); ?></p>
					<form method="post" class="nx-inline-form">
						<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
						<input type="hidden" name="noxpress_action" value="channel">
						<input type="hidden" name="noxpress_channel" value="beta">
						<button type="submit" class="button"><?php esc_html_e( 'Μετάβαση στο Beta για όλα', 'noxpress' ); ?></button>
						<span class="nx-muted nx-small-inline"><?php esc_html_e( 'ή «Εγκατάσταση beta» μόνο για όποιο plugin θέλεις, πιο κάτω.', 'noxpress' ); ?></span>
					</form>
				</div>
			<?php endif; ?>

			<h2 class="nx-h2"><?php esc_html_e( 'Plugins', 'noxpress' ); ?></h2>
			<?php self::render_bulk( $rows ); ?>
			<table class="widefat nx-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'noxpress' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση', 'noxpress' ); ?></th>
						<th><?php esc_html_e( 'Έκδοση', 'noxpress' ); ?></th>
						<th><?php esc_html_e( 'Ενέργειες', 'noxpress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php self::render_row( $row, $file_mods, $auto_ok ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $can_update && ! $file_mods ) : ?>
				<h2 class="nx-h2"><?php esc_html_e( 'Ενημερώσεις', 'noxpress' ); ?></h2>
				<div class="nx-zone">
					<form method="post" class="nx-inline-form">
						<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
						<input type="hidden" name="noxpress_action" value="channel">
						<p class="nx-label"><?php esc_html_e( 'Κανάλι ενημερώσεων', 'noxpress' ); ?></p>
						<label class="nx-check">
							<input type="radio" name="noxpress_channel" value="stable" <?php checked( 'stable', Noxpress_Updater::channel() ); ?>>
							<?php esc_html_e( 'Stable: μόνο εκδόσεις που έχουν δοκιμαστεί.', 'noxpress' ); ?>
						</label>
						<label class="nx-check">
							<input type="radio" name="noxpress_channel" value="beta" <?php checked( 'beta', Noxpress_Updater::channel() ); ?>>
							<?php esc_html_e( 'Beta: και οι νέες εκδόσεις που δοκιμάζονται ακόμα.', 'noxpress' ); ?>
						</label>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'noxpress' ); ?></button></p>
					</form>
					<form method="post" class="nx-check-form">
						<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
						<input type="hidden" name="noxpress_action" value="check">
						<button type="submit" class="button"><?php esc_html_e( 'Έλεγχος τώρα', 'noxpress' ); ?></button>
						<span class="nx-muted">
							<?php
							if ( $checked ) {
								/* translators: %s: date and time */
								printf( esc_html__( 'Τελευταίος έλεγχος: %s', 'noxpress' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked ) ) );
							} else {
								esc_html_e( 'Τελευταίος έλεγχος: ποτέ', 'noxpress' );
							}
							?>
						</span>
					</form>
					<p class="nx-muted nx-small"><?php esc_html_e( 'Κάθε πακέτο ελέγχεται με sha256 και ψηφιακή υπογραφή πριν από την εγκατάσταση.', 'noxpress' ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	private static function render_row( array $row, bool $file_mods, bool $auto_ok ): void {
		$slug = $row['slug'];
		$file = $row['file'];
		$rel  = $row['release'];
		?>
		<tr data-nx-slug="<?php echo esc_attr( $slug ); ?>">
			<td>
				<strong><?php echo esc_html( $row['info']['name'] ); ?></strong>
				<span class="nx-desc"><?php echo esc_html( $row['info']['desc'] ); ?></span>
			</td>
			<td>
				<?php if ( $row['active'] ) : ?>
					<span class="nx-badge nx-badge--ok"><?php esc_html_e( 'Ενεργό', 'noxpress' ); ?></span>
				<?php elseif ( $row['installed'] ) : ?>
					<span class="nx-badge nx-badge--muted"><?php esc_html_e( 'Ανενεργό', 'noxpress' ); ?></span>
				<?php else : ?>
					<span class="nx-badge nx-badge--muted"><?php esc_html_e( 'Δεν είναι εγκατεστημένο', 'noxpress' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $row['installed'] ) : ?>
					<code><?php echo esc_html( $row['version'] ); ?></code>
				<?php endif; ?>
				<?php if ( $rel && ( $row['update'] || ! $row['installed'] ) ) : ?>
					<span class="nx-badge nx-badge--warn">
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Διαθέσιμη: %s', 'noxpress' ), esc_html( $rel['version'] ) );
						?>
					</span>
				<?php elseif ( $row['installed'] && $rel ) : ?>
					<span class="nx-badge nx-badge--ok"><?php esc_html_e( 'Ενημερωμένο', 'noxpress' ); ?></span>
				<?php endif; ?>
				<?php if ( $rel && ! empty( $rel['beta'] ) && ( $row['update'] || ! $row['installed'] ) ) : ?>
					<span class="nx-badge nx-badge--beta"><?php esc_html_e( 'beta', 'noxpress' ); ?></span>
				<?php endif; ?>
				<?php if ( $row['other'] ) : ?>
					<span class="nx-badge nx-badge--beta nx-other-badge">
						<?php
						/* translators: %s: version number */
						printf( esc_html( ! empty( $row['other']['beta'] ) ? __( 'Νεότερη beta: %s', 'noxpress' ) : __( 'Νεότερη stable: %s', 'noxpress' ) ), esc_html( $row['other']['version'] ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td class="nx-actions">
				<?php
				$links = array();

				if ( $row['active'] && '' !== $row['info']['page'] && ! is_network_admin() ) {
					$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $row['info']['page'] ) ) . '">' . esc_html__( 'Άνοιγμα', 'noxpress' ) . '</a>';
				}
				if ( ! $file_mods && $row['update'] && $row['queued'] && current_user_can( 'update_plugins' ) ) {
					$links[] = '<a class="button button-primary" href="' . esc_url( wp_nonce_url( self::manage_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file ) ) . '">' . esc_html__( 'Ενημέρωση', 'noxpress' ) . '</a>';
				}
				if ( ! $file_mods && ! $row['installed'] && $rel && current_user_can( 'install_plugins' ) ) {
					$links[] = '<a class="button button-primary" href="' . esc_url( wp_nonce_url( self::manage_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug ) ) . '">' . esc_html__( 'Εγκατάσταση', 'noxpress' ) . '</a>';
				}
				if ( $row['installed'] && ! $row['active'] && current_user_can( 'activate_plugins' ) ) {
					$links[] = '<a class="button" href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) ) . '">' . esc_html__( 'Ενεργοποίηση', 'noxpress' ) . '</a>';
				}
				if ( $rel && current_user_can( 'install_plugins' ) ) {
					$links[] = '<a class="thickbox open-plugin-details-modal" href="' . esc_url( self::manage_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $slug ) . '&section=changelog&TB_iframe=true&width=640&height=560' ) ) . '">' . esc_html__( 'Αλλαγές', 'noxpress' ) . '</a>';
				}
				$other   = $row['other'];
				$confirm = esc_attr( __( 'Οι beta εκδόσεις δοκιμάζονται ακόμα. Να εγκατασταθεί η beta;', 'noxpress' ) );
				if ( ! $file_mods && $other && ! empty( $other['beta'] ) && $row['installed'] && current_user_can( 'update_plugins' ) ) {
					$url     = wp_nonce_url( self::manage_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) . '&' . Noxpress_Updater::PICK . '=beta' ), 'upgrade-plugin_' . $file );
					$links[] = '<a class="button nx-beta-button" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">' . esc_html__( 'Εγκατάσταση beta', 'noxpress' ) . '</a>';
				}
				if ( ! $file_mods && $other && ! empty( $other['beta'] ) && ! $row['installed'] && ! $rel && current_user_can( 'install_plugins' ) ) {
					$url     = wp_nonce_url( self::manage_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) . '&' . Noxpress_Updater::PICK . '=beta' ), 'install-plugin_' . $slug );
					$links[] = '<a class="button nx-beta-button" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">' . esc_html__( 'Εγκατάσταση beta', 'noxpress' ) . '</a>';
				}
				if ( $other && ! empty( $other['beta'] ) && current_user_can( 'install_plugins' ) ) {
					$links[] = '<a class="thickbox open-plugin-details-modal" href="' . esc_url( self::manage_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $slug ) . '&' . Noxpress_Updater::PICK . '=beta&section=changelog&TB_iframe=true&width=640&height=560' ) ) . '">' . ( $rel ? esc_html__( 'Αλλαγές beta', 'noxpress' ) : esc_html__( 'Αλλαγές', 'noxpress' ) ) . '</a>';
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above.
				echo implode( ' ', $links );

				if ( $other && ( current_user_can( 'update_plugins' ) || current_user_can( 'install_plugins' ) ) ) {
					?>
					<form method="post" class="nx-hide-form">
						<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
						<input type="hidden" name="noxpress_action" value="hide">
						<input type="hidden" name="noxpress_slug" value="<?php echo esc_attr( $slug ); ?>">
						<input type="hidden" name="noxpress_version" value="<?php echo esc_attr( $other['version'] ); ?>">
						<button type="submit" class="button-link nx-hide"><?php esc_html_e( 'Απόκρυψη για αυτή την έκδοση', 'noxpress' ); ?></button>
					</form>
					<?php
				}

				if ( $auto_ok && ! $file_mods && $row['installed'] && $rel && current_user_can( 'update_plugins' ) ) {
					$toggle = $row['auto'] ? 'disable-auto-update' : 'enable-auto-update';
					$url    = wp_nonce_url( self::manage_url( 'plugins.php?action=' . $toggle . '&plugin=' . rawurlencode( $file ) ), 'updates' );
					?>
					<span class="nx-auto">
						<?php echo esc_html( $row['auto'] ? __( 'Αυτόματες ενημερώσεις: ναι', 'noxpress' ) : __( 'Αυτόματες ενημερώσεις: όχι', 'noxpress' ) ); ?>
						· <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $row['auto'] ? __( 'Απενεργοποίηση αυτόματων', 'noxpress' ) : __( 'Ενεργοποίηση αυτόματων', 'noxpress' ) ); ?></a>
					</span>
					<?php
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Bulk buttons and their confirm dialog (the dialog is filled by
	 * assets/hub-bulk.js from the JSON below). Each button shows only when
	 * it has something to do and the user may do it.
	 */
	private static function render_bulk( array $rows ): void {
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'install_plugins' ) && ! current_user_can( 'delete_plugins' ) ) {
			return;
		}
		if ( ! self::bulk_allowed() ) {
			if ( is_multisite() && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
				echo '<p class="nx-muted">' . esc_html__( 'Οι μαζικές ενέργειες γίνονται από το network admin.', 'noxpress' ) . '</p>';
			}
			return;
		}

		$lists     = array(
			'install'   => array(),
			'update'    => array(),
			'uninstall' => array(),
		);
		$beta_only = 0;
		foreach ( $rows as $row ) {
			$item = array(
				'slug'    => $row['slug'],
				'file'    => $row['file'],
				'name'    => $row['info']['name'],
				'active'  => $row['active'],
				'checked' => true,
				'note'    => '',
			);
			if ( ! $row['installed'] ) {
				if ( $row['release'] ) {
					$lists['install'][] = $item + array( 'version' => $row['release']['version'] );
				} elseif ( $row['other'] ) {
					++$beta_only;
				}
				continue;
			}
			if ( $row['update'] && $row['queued'] ) {
				$lists['update'][] = $item + array( 'version' => $row['version'] . ' → ' . $row['release']['version'] );
			}
			$item['version'] = $row['version'];
			if ( in_array( $row['slug'], self::KEEP_BY_DEFAULT, true ) ) {
				$item['checked'] = false;
				$item['note']    = 'site-bridge' === $row['slug']
					? __( 'Σβήνει κλειδιά και συζεύξεις: κόβεται η σύνδεση του Claude με τα sites.', 'noxpress' )
					: __( 'Χωρίς το τικ «Να μείνουν τα αντίγραφα» στις ρυθμίσεις του, σβήνει όλα τα backups.', 'noxpress' );
			}
			$lists['uninstall'][] = $item;
		}
		if ( ! current_user_can( 'install_plugins' ) ) {
			$lists['install'] = array();
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			$lists['update'] = array();
		}
		if ( ! current_user_can( 'delete_plugins' ) || ! current_user_can( self::state_cap() ) ) {
			$lists['uninstall'] = array();
		}
		if ( ! $lists['install'] && ! $lists['update'] && ! $lists['uninstall'] ) {
			return;
		}

		$labels = array(
			/* translators: %d: number of plugins */
			'install'   => __( 'Εγκατάσταση όλων (%d)', 'noxpress' ),
			/* translators: %d: number of plugins */
			'update'    => __( 'Ενημέρωση όλων (%d)', 'noxpress' ),
			/* translators: %d: number of plugins */
			'uninstall' => __( 'Απεγκατάσταση όλων (%d)', 'noxpress' ),
		);
		$config = array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'wpNonce'   => wp_create_nonce( 'updates' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'plugins'   => self::manage_url( 'plugins.php' ),
			'suite'     => count( Noxpress_Core::installed() ),
			'lists'     => $lists,
			'betaOnly'  => 'stable' === Noxpress_Updater::channel() ? $beta_only : 0,
			'word'      => __( 'ΔΙΑΓΡΑΦΗ', 'noxpress' ),
			'text'      => array(
				'installTitle'   => __( 'Εγκατάσταση όλων', 'noxpress' ),
				'updateTitle'    => __( 'Ενημέρωση όλων', 'noxpress' ),
				'uninstallTitle' => __( 'Απεγκατάσταση όλων', 'noxpress' ),
				'installIntro'   => __( 'Τα plugins εγκαθίστανται ένα-ένα, από το κανάλι ενημερώσεων του site. Δεν ενεργοποιούνται, εκτός αν το τσεκάρεις.', 'noxpress' ),
				'updateIntro'    => __( 'Τα plugins ενημερώνονται ένα-ένα. Όσα είναι ενεργά μένουν ενεργά.', 'noxpress' ),
				'uninstallIntro' => __( 'Τα plugins απενεργοποιούνται και διαγράφονται. Η διαγραφή τρέχει το uninstall κάθε plugin: σβήνει ρυθμίσεις και δεδομένα του, εκτός από όσα κρατά το ίδιο το plugin (π.χ. παραγγελίες ή ό,τι έχεις τσεκάρει να μείνει στα Εργαλεία του). Δεν αναιρείται.', 'noxpress' ),
				'liveWarn'       => __( 'Σε live site τα χαρακτηριστικά τους φεύγουν αμέσως από το κατάστημα. Καλύτερα με πρόσφατο backup.', 'noxpress' ),
				/* translators: %d: number of plugins */
				'betaOnly'       => __( 'Plugins μόνο με beta έκδοση, που δεν μπαίνουν στο κανάλι Stable: %d. Για αυτά, «Μετάβαση στο Beta για όλα» ή «Εγκατάσταση beta» στη γραμμή τους.', 'noxpress' ),
				'activate'       => __( 'Ενεργοποίηση μετά την εγκατάσταση', 'noxpress' ),
				/* translators: %s: the word to type */
				'typeWord'       => __( 'Γράψε %s για επιβεβαίωση:', 'noxpress' ),
				'start'          => __( 'Έναρξη', 'noxpress' ),
				'cancel'         => __( 'Άκυρο', 'noxpress' ),
				'close'          => __( 'Κλείσιμο', 'noxpress' ),
				'waiting'        => __( 'Σε αναμονή', 'noxpress' ),
				'working'        => __( 'Γίνεται…', 'noxpress' ),
				'done'           => __( 'Έγινε', 'noxpress' ),
				'failed'         => __( 'Σφάλμα', 'noxpress' ),
				/* translators: 1: plugins done, 2: plugins failed */
				'summary'        => __( 'Ολοκληρώθηκε: %1$d επιτυχίες, %2$d σφάλματα.', 'noxpress' ),
				'gone'           => __( 'Δεν έμεινε κανένα plugin της σουίτας, άρα ούτε αυτή η σελίδα. Συνέχεια στη σελίδα Plugins.', 'noxpress' ),
				'leave'          => __( 'Η εργασία δεν τελείωσε. Να φύγεις από τη σελίδα;', 'noxpress' ),
				'network'        => __( 'Σφάλμα δικτύου.', 'noxpress' ),
			),
		);
		?>
		<div class="nx-bulk" id="nx-bulk">
			<?php foreach ( $labels as $op => $label ) : ?>
				<?php if ( $lists[ $op ] ) : ?>
					<button type="button" class="button<?php echo 'uninstall' === $op ? ' nx-danger-button' : ''; ?>" data-nx-bulk="<?php echo esc_attr( $op ); ?>"><?php echo esc_html( sprintf( $label, count( $lists[ $op ] ) ) ); ?></button>
				<?php endif; ?>
			<?php endforeach; ?>
			<dialog class="nx-dialog" id="nx-bulk-dialog" aria-labelledby="nx-bulk-title"></dialog>
			<script type="application/json" id="nx-bulk-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
		</div>
		<?php
	}

	/** Footer (Bible §7). */
	private static function footer(): void {
		?>
		<p class="nx-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'noxpress' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="nx-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="nx-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'noxpress' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="nx-footer-cta">
			<a class="nx-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'noxpress' ); ?>
			</a>
			<a class="nx-footer-dash" href="<?php echo esc_url( self_admin_url( 'admin.php?page=' . Noxpress_Core::MENU ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'noxpress' ); ?>
			</a>
		</p>
		<?php
	}
}
