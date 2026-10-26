<?php
/**
 * SBR_Tools_Write — write and admin tools.
 *
 * The caller (SBR_Site::execute) has already checked the site's level,
 * the open write window and the connection's scope. These tools only use
 * WordPress APIs (REST, update_option, media_handle_sideload, the
 * upgraders), so plugins' own hooks, validation and caches run.
 *
 * A tool may return '_log_extra' (e.g. the old value of an option): the
 * executor moves it into the audit log and drops it from the answer.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Tools_Write {

	/* =====================================================================
	 * Write
	 * =================================================================== */

	public static function tool_rest_write( array $a ) {
		$method = strtoupper( SBR_Tools::a_str( $a, 'method' ) );
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return new WP_Error( 'sbr_args', 'method must be POST, PUT, PATCH or DELETE.' );
		}
		return SBR_Tools::rest( $method, SBR_Tools::a_str( $a, 'route' ), SBR_Tools::a_obj( $a, 'query' ), SBR_Tools::a_obj( $a, 'body' ) );
	}

	public static function tool_option_set( array $a ) {
		$name = SBR_Tools::a_str( $a, 'name' );
		if ( '' === $name || strlen( $name ) > 191 ) {
			return new WP_Error( 'sbr_args', 'Invalid option name.' );
		}
		$why = SBR_Guard::blocked_option( $name );
		if ( '' !== $why ) {
			return new WP_Error( 'sbr_option', $why );
		}
		if ( ! array_key_exists( 'value', $a ) ) {
			return new WP_Error( 'sbr_args', 'Give a value.' );
		}
		$sentinel = new stdClass();
		$old      = get_option( $name, $sentinel );
		$existed  = $old !== $sentinel;
		$autoload = array_key_exists( 'autoload', $a ) ? SBR_Tools::a_bool( $a, 'autoload' ) : null;
		$changed  = update_option( $name, $a['value'], $autoload );
		return array(
			'name'       => $name,
			'existed'    => $existed,
			'changed'    => (bool) $changed,
			'old'        => $existed ? SBR_Guard::clean_tree( $old ) : null,
			'_log_extra' => array(
				'option'  => $name,
				'existed' => $existed,
				'old'     => $existed ? $old : null,
			),
		);
	}

	public static function tool_media_upload_from_url( array $a ) {
		$url = SBR_Tools::a_str( $a, 'url' );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'sbr_args', 'Invalid or non-public URL.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'sbr_http', 'Download failed: ' . $tmp->get_error_message() );
		}
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$name = '' === $name ? 'file' : $name;
		$file = array(
			'name'     => sanitize_file_name( rawurldecode( $name ) ),
			'tmp_name' => $tmp,
		);
		$post = SBR_Tools::a_int( $a, 'post_id', 0, 0, PHP_INT_MAX );
		$id   = media_handle_sideload( $file, $post, SBR_Tools::a_str( $a, 'title' ) ? sanitize_text_field( SBR_Tools::a_str( $a, 'title' ) ) : null );
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$alt = SBR_Tools::a_str( $a, 'alt' );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return array(
			'id'   => (int) $id,
			'url'  => wp_get_attachment_url( $id ),
			'mime' => get_post_mime_type( $id ),
		);
	}

	public static function tool_cache_flush( array $a ): array {
		$done = array();
		if ( wp_cache_flush() ) {
			$done[] = 'object cache';
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
			$done[] = 'WooCommerce product transients';
		}
		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			wc_delete_shop_order_transients();
			$done[] = 'WooCommerce order transients';
		}
		// The suite's page cache (Fast Pages 1.0.0+, Bible §6): everything.
		if ( has_action( 'noxpress_purge_page_cache' ) ) {
			do_action( 'noxpress_purge_page_cache' );
			$done[] = 'page cache';
		}
		return array( 'flushed' => $done );
	}

	/** The class an ability call needs: read, write or admin. */
	public static function ability_class( array $a ): string {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return 'write';
		}
		$ab = wp_get_ability( SBR_Tools::a_str( $a, 'name' ) );
		if ( ! $ab ) {
			return 'write';
		}
		$meta = method_exists( $ab, 'get_meta' ) ? (array) $ab->get_meta() : array();
		$ann  = (array) ( $meta['annotations'] ?? array() );
		if ( ! empty( $ann['destructive'] ) ) {
			return 'admin';
		}
		if ( ! empty( $ann['readonly'] ) ) {
			return 'read';
		}
		return 'write';
	}

	public static function tool_ability_run( array $a ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'sbr_tool', 'This site has no Abilities API (WordPress 6.9+).' );
		}
		$ab = wp_get_ability( SBR_Tools::a_str( $a, 'name' ) );
		if ( ! $ab ) {
			return new WP_Error( 'sbr_args', 'Unknown ability.' );
		}
		$res = $ab->execute( $a['input'] ?? null );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'result' => SBR_Guard::clean_tree( $res ) );
	}

	/** Closes the write window now (it never opens or extends it). */
	public static function tool_write_window_close( array $a ): array {
		$was = SBR_Settings::window_until();
		SBR_Settings::close_window();
		return array(
			'closed'     => true,
			'was_until'  => $was ? gmdate( 'c', $was ) : null,
			'_log_extra' => array( 'was_until' => $was ),
		);
	}

	/* =====================================================================
	 * Admin: plugins, themes, channel
	 * =================================================================== */

	private static function upgrader_ready() {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'sbr_files', 'DISALLOW_FILE_MODS is set on this site: plugins and themes cannot change.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return new WP_Error( 'sbr_files', 'WordPress needs FTP credentials to write files on this server, so remote installs and updates are not possible.' );
		}
		return true;
	}

	/** Plugin file for a slug or a file, or ''. */
	private static function plugin_file( string $p ): string {
		$all = get_plugins();
		if ( isset( $all[ $p ] ) ) {
			return $p;
		}
		foreach ( array_keys( $all ) as $file ) {
			if ( 0 === strpos( $file, $p . '/' ) || $file === $p . '.php' ) {
				return $file;
			}
		}
		return '';
	}

	private static function zip_allowed( string $url ): bool {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}
		// The prefix is compared as text: no dot segments (an HTTP client
		// resolves "/../" and leaves the prefix), escapes, user info,
		// query or fragment.
		if ( preg_match( '#[%\\\\@?\#]|/\.\.?(/|$)#', $url ) ) {
			return false;
		}
		foreach ( SBR_Settings::lines( 'zip_hosts' ) as $prefix ) {
			if ( 0 === strpos( $url, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public static function tool_plugins_install( array $a ) {
		$ok = self::upgrader_ready();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$items = isset( $a['items'] ) && is_array( $a['items'] ) ? array_slice( $a['items'], 0, 20 ) : array();
		if ( ! $items ) {
			return new WP_Error( 'sbr_args', 'Give items: [{"slug": "…"} or {"zip_url": "…"}].' );
		}
		$out = array();
		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array( 'slug' => (string) $item );
			$slug = sanitize_key( SBR_Tools::a_str( $item, 'slug' ) );
			$zip  = SBR_Tools::a_str( $item, 'zip_url' );
			$row  = array(
				'slug'    => $slug,
				'zip_url' => $zip,
			);
			if ( '' !== $slug && '' !== self::plugin_file( $slug ) ) {
				$row['status'] = 'already installed';
				$row['file']   = self::plugin_file( $slug );
			} else {
				if ( '' !== $zip ) {
					if ( ! self::zip_allowed( $zip ) ) {
						$row['status'] = 'error';
						$row['error']  = 'This zip URL is not in the allowed hosts of the site\'s settings.';
						$out[]         = $row;
						continue;
					}
					$package = $zip;
				} elseif ( '' !== $slug ) {
					$api = plugins_api(
						'plugin_information',
						array(
							'slug'   => $slug,
							'fields' => array( 'sections' => false ),
						)
					);
					if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
						$row['status'] = 'error';
						$row['error']  = is_wp_error( $api ) ? $api->get_error_message() : 'No download link.';
						$out[]         = $row;
						continue;
					}
					$package = (string) $api->download_link;
				} else {
					$row['status'] = 'error';
					$row['error']  = 'Give a slug or a zip_url.';
					$out[]         = $row;
					continue;
				}
				$skin     = new WP_Ajax_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$res      = $upgrader->install( $package );
				if ( true !== $res ) {
					$err           = is_wp_error( $res ) ? $res : $skin->get_errors();
					$row['status'] = 'error';
					$row['error']  = is_wp_error( $err ) && $err->has_errors() ? $err->get_error_message() : 'The install failed.';
					$out[]         = $row;
					continue;
				}
				$row['status'] = 'installed';
				$row['file']   = (string) $upgrader->plugin_info();
			}
			if ( SBR_Tools::a_bool( $a, 'activate' ) && ! empty( $row['file'] ) && ! is_plugin_active( $row['file'] ) ) {
				$act              = activate_plugin( $row['file'] );
				$row['activated'] = ! is_wp_error( $act );
				if ( is_wp_error( $act ) ) {
					$row['activate_error'] = $act->get_error_message();
				}
			}
			$out[] = $row;
		}
		wp_clean_plugins_cache();
		return array( 'results' => $out );
	}

	public static function tool_plugins_activate( array $a ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( SBR_Tools::a_list( $a, 'plugins', 50 ) as $p ) {
			$file = self::plugin_file( $p );
			if ( '' === $file ) {
				$out[] = array(
					'plugin' => $p,
					'status' => 'error',
					'error'  => 'Not installed.',
				);
				continue;
			}
			if ( is_plugin_active( $file ) ) {
				$out[] = array(
					'plugin' => $p,
					'file'   => $file,
					'status' => 'already active',
				);
				continue;
			}
			$res   = activate_plugin( $file );
			$out[] = is_wp_error( $res )
				? array(
					'plugin' => $p,
					'file'   => $file,
					'status' => 'error',
					'error'  => $res->get_error_message(),
				)
				: array(
					'plugin' => $p,
					'file'   => $file,
					'status' => 'activated',
				);
		}
		return array( 'results' => $out );
	}

	public static function tool_plugins_deactivate( array $a ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$self = plugin_basename( SBR_FILE );
		$out  = array();
		foreach ( SBR_Tools::a_list( $a, 'plugins', 50 ) as $p ) {
			$file = self::plugin_file( $p );
			if ( '' === $file ) {
				$out[] = array(
					'plugin' => $p,
					'status' => 'error',
					'error'  => 'Not installed.',
				);
				continue;
			}
			if ( $file === $self ) {
				$out[] = array(
					'plugin' => $p,
					'status' => 'error',
					'error'  => 'Site Bridge never deactivates itself.',
				);
				continue;
			}
			if ( ! is_plugin_active( $file ) ) {
				$out[] = array(
					'plugin' => $p,
					'file'   => $file,
					'status' => 'already inactive',
				);
				continue;
			}
			deactivate_plugins( $file );
			$out[] = array(
				'plugin' => $p,
				'file'   => $file,
				'status' => is_plugin_active( $file ) ? 'error' : 'deactivated',
			);
		}
		return array( 'results' => $out );
	}

	public static function tool_plugins_update( array $a ) {
		$ok = self::upgrader_ready();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		// 1.5.1: a fresh check, as the hub's "Check now" does. Without it
		// the 12-hour caches of WordPress and of the Noxpress manifest hide
		// a release made since the last check.
		if ( class_exists( 'Noxpress_Updater' ) && method_exists( 'Noxpress_Updater', 'refresh' ) ) {
			Noxpress_Updater::refresh();
		} else {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
		}
		$tr     = get_site_transient( 'update_plugins' );
		$avail  = isset( $tr->response ) && is_array( $tr->response ) ? $tr->response : array();
		$wanted = SBR_Tools::a_list( $a, 'plugins', 100 );
		$files  = array();
		$out    = array();
		if ( in_array( 'all', $wanted, true ) ) {
			$files = array_keys( $avail );
		} else {
			foreach ( $wanted as $p ) {
				$file = self::plugin_file( $p );
				if ( '' === $file ) {
					$out[] = array(
						'plugin' => $p,
						'status' => 'error',
						'error'  => 'Not installed.',
					);
				} elseif ( ! isset( $avail[ $file ] ) ) {
					$out[] = array(
						'plugin' => $p,
						'file'   => $file,
						'status' => 'no update',
					);
				} else {
					$files[] = $file;
				}
			}
		}
		if ( $files ) {
			$before   = get_plugins();
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$res      = $upgrader->bulk_upgrade( $files );
			wp_clean_plugins_cache();
			$after = get_plugins();
			foreach ( $files as $file ) {
				$r     = is_array( $res ) ? ( $res[ $file ] ?? null ) : null;
				$err   = is_wp_error( $r ) ? $r->get_error_message() : '';
				$out[] = array(
					'file'   => $file,
					'from'   => $before[ $file ]['Version'] ?? '',
					'to'     => $after[ $file ]['Version'] ?? '',
					'status' => ( $r && ! is_wp_error( $r ) ) ? 'updated' : 'error',
					'error'  => '' !== $err ? $err : ( ( $r && ! is_wp_error( $r ) ) ? '' : implode( ' ', $skin->get_error_messages() ? (array) $skin->get_error_messages() : array( 'The update failed.' ) ) ),
				);
			}
		}
		return array( 'results' => $out );
	}

	public static function tool_themes_update( array $a ) {
		$ok = self::upgrader_ready();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		wp_update_themes();
		$tr     = get_site_transient( 'update_themes' );
		$avail  = isset( $tr->response ) && is_array( $tr->response ) ? $tr->response : array();
		$wanted = SBR_Tools::a_list( $a, 'themes', 50 );
		$slugs  = in_array( 'all', $wanted, true ) ? array_keys( $avail ) : array_values( array_intersect( $wanted, array_keys( $avail ) ) );
		$out    = array();
		foreach ( array_diff( $wanted, $slugs, array( 'all' ) ) as $s ) {
			$out[] = array(
				'theme'  => $s,
				'status' => wp_get_theme( $s )->exists() ? 'no update' : 'error',
			);
		}
		if ( $slugs ) {
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Theme_Upgrader( $skin );
			$res      = $upgrader->bulk_upgrade( $slugs );
			foreach ( $slugs as $s ) {
				$r     = is_array( $res ) ? ( $res[ $s ] ?? null ) : null;
				$out[] = array(
					'theme'   => $s,
					'version' => wp_get_theme( $s )->get( 'Version' ),
					'status'  => ( $r && ! is_wp_error( $r ) ) ? 'updated' : 'error',
				);
			}
		}
		return array( 'results' => $out );
	}

	public static function tool_noxpress_channel_set( array $a ) {
		if ( ! class_exists( 'Noxpress_Updater' ) ) {
			return new WP_Error( 'sbr_tool', 'Noxpress Core is not loaded on this site.' );
		}
		$ch = 'beta' === SBR_Tools::a_str( $a, 'channel' ) ? 'beta' : 'stable';
		$old = Noxpress_Updater::channel();
		Noxpress_Updater::set_channel( $ch );
		Noxpress_Updater::refresh();
		return array(
			'channel'  => Noxpress_Updater::channel(),
			'previous' => $old,
		);
	}
}
