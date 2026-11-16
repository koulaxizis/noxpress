<?php
/**
 * ACL_Watch — WordPress events.
 *
 *  content  public post types (posts, pages, products, coupons and any
 *           public CPT): published, unpublished, changed while published
 *           (title, slug, status, author, parent, content / excerpt as
 *           «changed»), trashed, restored, deleted. Drafts, revisions,
 *           autosaves and menu items are left out. Products and coupons
 *           go to their own categories.
 *  users    logins and logouts of staff, failed logins (the user id when
 *           the name belongs to an account, otherwise «unknown name»,
 *           never the typed text; at most 30 rows an hour), new users,
 *           deleted users, role changes, e-mail and password changes (only
 *           «changed»), password resets, application passwords.
 *  plugins  installed, activated, deactivated, updated (from → to),
 *           deleted; themes installed, switched, updated, deleted;
 *           WordPress updated; the file editor saved a file.
 *  settings updated_option for the watched list (WordPress core, every
 *           woocommerce_* setting, the *_settings options of the suite,
 *           acl_watched_options), secrets and personal data hidden.
 *  media    uploaded, deleted.
 *  menus    a menu was saved or deleted (off by default).
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Watch {

	/** WordPress core options worth a row. */
	const CORE_OPTIONS = array(
		'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register',
		'default_role', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG',
		'permalink_structure', 'category_base', 'tag_base', 'show_on_front', 'page_on_front', 'page_for_posts',
		'posts_per_page', 'blog_public', 'default_comment_status', 'comment_moderation', 'comment_registration',
		'comments_notify', 'moderation_notify', 'uploads_use_yearmonth_folders', 'wp_page_for_privacy_policy',
		'site_icon', 'default_category', 'rss_use_excerpt', 'posts_per_rss',
	);

	/** Options that are critical when they change. */
	const CRITICAL_OPTIONS = array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role' );

	/** woocommerce_* options that are state, not settings. */
	const WC_NOISE = '/(version|_last_|timestamp|_cache|notices?$|notice_|tracker|queue|onboarding|task_?list|inbox|marketplace|remote|_hash$|_lock$|schedul|maxmind|_ces_|tour|dismiss|_counts?$|_status$|admin_install|_install_|_db_|migration|meta_box_errors|feature_|navigation_|analytics|_snooze|_survey|_usage|_gla_|refunded_orders|_bacs_accounts$|_downloads_count|_new_|_show_|_reminder|_promo|_banner|_nudge|_activity_panel|_previous_|_hpos_|custom_orders_table_data_sync|_sync_|_reactify|_coming_soon_page|_pinned|newly_installed|_onboard)/';

	/** Suite settings: {code}_settings and a few named options. */
	const SUITE_RE = '/^(rs|sp|sf|tp|shf|pfm|ewd|bpv|shn|crt|pkp|hic|vpk|glp|orc|pbn|sbr|pbq|por|wsh|cke|nm|hms|bkf|lop|tra|fpg|brc|mgl|tfi|lim|rdr|ssg|pl|acl|lgd|[a-z]{2,4})_settings$|^(noxpress_channel|sbr_schedule|lop_schedule|tp_care|rs_beneficiaries|rs_default_vat_rate)$/';

	/** @var array Plugin versions before an update: file => version. */
	private static $before = array();

	/** @var array Names of plugins about to be deleted: file => name. */
	private static $deleting = array();

	/** @var array Labels of users about to be deleted: id => array( label, role ). */
	private static $users = array();

	public static function init(): void {
		// Content.
		add_action( 'transition_post_status', array( __CLASS__, 'transition' ), 10, 3 );
		add_action( 'post_updated', array( __CLASS__, 'post_updated' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'trashed' ), 10, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'untrashed' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'deleted' ), 10, 2 );

		// Users.
		add_action( 'wp_login', array( __CLASS__, 'login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 10, 1 );
		add_action( 'wp_logout', array( __CLASS__, 'logout' ), 10, 1 );
		add_action( 'user_register', array( __CLASS__, 'user_register' ), 10, 1 );
		add_action( 'delete_user', array( __CLASS__, 'before_delete_user' ), 10, 1 );
		add_action( 'deleted_user', array( __CLASS__, 'user_deleted' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'user_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'user_role_added' ), 10, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'user_role_removed' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'profile_update' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'password_reset' ), 10, 1 );
		add_action( 'wp_create_application_password', array( __CLASS__, 'app_password_created' ), 10, 2 );
		add_action( 'wp_delete_application_password', array( __CLASS__, 'app_password_deleted' ), 10, 2 );
		add_action( 'mgl_logged_in', array( __CLASS__, 'magic_login' ), 10, 1 );

		// Plugins, themes, core.
		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ), 10, 2 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'pre_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgraded' ), 10, 2 );
		add_action( 'delete_plugin', array( __CLASS__, 'before_delete_plugin' ), 10, 1 );
		add_action( 'deleted_plugin', array( __CLASS__, 'plugin_deleted' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 3 );
		add_action( 'deleted_theme', array( __CLASS__, 'theme_deleted' ), 10, 2 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'core_updated' ), 10, 1 );
		add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'file_edited' ), 0 );

		// Settings.
		add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'option_added' ), 10, 2 );

		// Media.
		add_action( 'add_attachment', array( __CLASS__, 'media_added' ), 10, 1 );
		add_action( 'delete_attachment', array( __CLASS__, 'media_deleted' ), 10, 1 );

		// Menus.
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'menu_saved' ), 10, 1 );
		add_action( 'wp_delete_nav_menu', array( __CLASS__, 'menu_deleted' ), 10, 1 );
	}

	/** Every callback runs inside this: the log must never break a save. */
	private static function safe( callable $fn ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Content
	 * =================================================================== */

	/** Category and object type for a post type, or null when not watched. */
	public static function post_kind( string $type ): ?array {
		if ( in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return array( 'products', 'product' );
		}
		if ( 'shop_coupon' === $type ) {
			return array( 'coupons', 'coupon' );
		}
		if ( in_array( $type, array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'attachment', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'scheduled-action' ), true ) ) {
			return null;
		}
		$obj = get_post_type_object( $type );
		if ( ! $obj ) {
			return null;
		}
		if ( $obj->public || in_array( $type, array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ), true ) ) {
			return array( 'content', $type );
		}
		return null;
	}

	private static function skip_post( WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return true;
		}
		return 'auto-draft' === $post->post_status;
	}

	private static function title( WP_Post $post ): string {
		$t = (string) $post->post_title;
		if ( 'product_variation' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$p = wc_get_product( $post->ID );
			if ( $p ) {
				$t = (string) $p->get_name();
			}
		}
		return '' !== $t ? $t : '#' . $post->ID;
	}

	/** Publishing and unpublishing (any change into or out of «publish»). */
	public static function transition( $new, $old, $post ): void {
		self::safe(
			static function () use ( $new, $old, $post ) {
				if ( ! $post instanceof WP_Post || $new === $old || self::skip_post( $post ) ) {
					return;
				}
				$kind = self::post_kind( $post->post_type );
				if ( ! $kind || 'trash' === $new || 'trash' === $old || 'new' === $old ) {
					return;
				}
				if ( 'auto-draft' === $old && 'publish' !== $new && 'future' !== $new ) {
					return;
				}
				$action = '';
				if ( 'publish' === $new ) {
					$action = 'published';
				} elseif ( 'publish' === $old ) {
					$action = 'unpublished';
				} elseif ( 'future' === $new ) {
					$action = 'scheduled';
				}
				if ( 'products' === $kind[0] && 'auto-draft' === $old ) {
					return; // WooCommerce: ACL_Watch_WC records the creation.
				}
				if ( '' === $action ) {
					return;
				}
				$d = array();
				ACL_Redact::diff( $d, 'status', $old, $new );
				ACL_Store::add(
					array(
						'category'    => $kind[0],
						'action'      => $kind[1] . '.' . $action,
						'object_type' => $kind[1],
						'object_id'   => $post->ID,
						'label'       => self::title( $post ),
						'details'     => $d,
						'severity'    => 'unpublished' === $action ? 'notice' : 'info',
					)
				);
			}
		);
	}

	/** Changes of a published post, and of products in any state. */
	public static function post_updated( $id, $after, $before ): void {
		self::safe(
			static function () use ( $id, $after, $before ) {
				if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || self::skip_post( $after ) ) {
					return;
				}
				$kind = self::post_kind( $after->post_type );
				if ( ! $kind ) {
					return;
				}
				$watched = 'content' !== $kind[0] || 'publish' === $after->post_status || 'publish' === $before->post_status || 'future' === $after->post_status;
				if ( ! $watched || 'trash' === $after->post_status || 'trash' === $before->post_status ) {
					return;
				}
				$d = array();
				ACL_Redact::diff( $d, 'title', $before->post_title, $after->post_title );
				ACL_Redact::diff( $d, 'slug', $before->post_name, $after->post_name );
				ACL_Redact::diff( $d, 'status', $before->post_status, $after->post_status );
				ACL_Redact::diff( $d, 'author', (int) $before->post_author, (int) $after->post_author );
				ACL_Redact::diff( $d, 'parent', (int) $before->post_parent, (int) $after->post_parent );
				ACL_Redact::diff( $d, 'menu_order', (int) $before->menu_order, (int) $after->menu_order );
				ACL_Redact::diff( $d, 'password', $before->post_password, $after->post_password );
				if ( $before->post_content !== $after->post_content ) {
					$d['content'] = array( 't' => 'changed' );
				}
				if ( $before->post_excerpt !== $after->post_excerpt ) {
					$d['excerpt'] = array( 't' => 'changed' );
				}
				if ( 'publish' === $after->post_status && $before->post_date !== $after->post_date ) {
					ACL_Redact::diff( $d, 'date', $before->post_date, $after->post_date );
				}
				if ( ! $d ) {
					return;
				}
				ACL_Store::add(
					array(
						'category'    => $kind[0],
						'action'      => $kind[1] . '.updated',
						'object_type' => $kind[1],
						'object_id'   => (int) $id,
						'label'       => self::title( $after ),
						'details'     => $d,
					)
				);
			}
		);
	}

	public static function trashed( $id ): void {
		self::post_event( (int) $id, 'trashed', 'notice' );
	}

	public static function untrashed( $id ): void {
		self::post_event( (int) $id, 'restored', 'info' );
	}

	public static function deleted( $id, $post = null ): void {
		self::post_event( (int) $id, 'deleted', 'warning', $post );
	}

	private static function post_event( int $id, string $action, string $severity, $post = null ): void {
		self::safe(
			static function () use ( $id, $action, $severity, $post ) {
				$post = $post instanceof WP_Post ? $post : get_post( $id );
				if ( ! $post instanceof WP_Post || self::skip_post( $post ) ) {
					return;
				}
				$kind = self::post_kind( $post->post_type );
				if ( ! $kind ) {
					return;
				}
				// A draft that was never published is not content of the site.
				if ( 'content' === $kind[0] && 'deleted' === $action && in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
					return;
				}
				ACL_Store::add(
					array(
						'category'    => $kind[0],
						'action'      => $kind[1] . '.' . $action,
						'object_type' => $kind[1],
						'object_id'   => $id,
						'label'       => self::title( $post ),
						'severity'    => $severity,
					)
				);
			}
		);
	}

	/* =====================================================================
	 * Users
	 * =================================================================== */

	private static function user_cat( int $uid ): string {
		return ACL_Context::is_staff( $uid ) ? 'users' : 'customer_logins';
	}

	private static function user_label( int $uid ): string {
		if ( ! ACL_Context::is_staff( $uid ) ) {
			return 'customer';
		}
		$u = get_userdata( $uid );
		return $u instanceof WP_User ? (string) $u->display_name : '#' . $uid;
	}

	private static function user_event( string $action, int $uid, array $details = array(), string $severity = 'info', ?array $actor = null, string $cat = 'users' ): void {
		$e = array(
			'category'    => $cat,
			'action'      => 'user.' . $action,
			'object_type' => 'user',
			'object_id'   => $uid,
			'label'       => self::user_label( $uid ),
			'details'     => $details,
			'severity'    => $severity,
		);
		if ( null !== $actor ) {
			$e['actor'] = $actor;
		}
		ACL_Store::add( $e );
	}

	public static function login( $login, $user ): void {
		self::safe(
			static function () use ( $user ) {
				if ( ! $user instanceof WP_User ) {
					return;
				}
				$uid = (int) $user->ID;
				self::user_event( 'login', $uid, array(), 'info', ACL_Context::actor( $uid ), self::user_cat( $uid ) );
			}
		);
	}

	public static function login_failed( $username ): void {
		self::safe(
			static function () use ( $username ) {
				$name = is_string( $username ) ? trim( $username ) : '';
				$user = '' !== $name ? get_user_by( is_email( $name ) ? 'email' : 'login', $name ) : false;
				if ( ! $user && '' !== $name && is_email( $name ) ) {
					$user = get_user_by( 'login', $name );
				}
				$uid = $user instanceof WP_User ? (int) $user->ID : 0;
				if ( $uid && ! ACL_Context::is_staff( $uid ) && ! ACL_Settings::on( 'customer_logins' ) ) {
					return;
				}
				// At most 30 rows an hour, then one row that says so.
				$key = 'acl_fail_' . gmdate( 'YmdH' );
				$n   = (int) get_transient( $key ) + 1;
				set_transient( $key, $n, HOUR_IN_SECONDS + 60 );
				if ( $n > 31 ) {
					return;
				}
				if ( 31 === $n ) {
					ACL_Store::add(
						array(
							'category' => 'users',
							'action'   => 'user.login_failed_many',
							'severity' => 'warning',
							'actor'    => array( 0, 'visitor', '' ),
						)
					);
					return;
				}
				$e = array(
					'category' => $uid ? self::user_cat( $uid ) : 'users',
					'action'   => $uid ? 'user.login_failed' : 'user.login_failed_unknown',
					'severity' => $uid && ACL_Context::is_staff( $uid ) ? 'notice' : 'info',
					'actor'    => array( 0, 'visitor', '' ),
				);
				if ( $uid ) {
					$e['object_type'] = 'user';
					$e['object_id']   = $uid;
					$e['label']       = self::user_label( $uid );
				}
				ACL_Store::add( $e );
			}
		);
	}

	public static function logout( $uid = 0 ): void {
		self::safe(
			static function () use ( $uid ) {
				$uid = (int) $uid;
				if ( $uid > 0 ) {
					self::user_event( 'logout', $uid, array(), 'info', ACL_Context::actor( $uid ), self::user_cat( $uid ) );
				}
			}
		);
	}

	public static function user_register( $uid ): void {
		self::safe(
			static function () use ( $uid ) {
				$uid = (int) $uid;
				$u   = get_userdata( $uid );
				if ( ! $u instanceof WP_User ) {
					return;
				}
				$staff = ACL_Context::is_staff( $uid );
				// A customer who makes an account at checkout is not news.
				if ( ! $staff && ! ACL_Context::by_staff_tools() ) {
					return;
				}
				$role = $u->roles ? (string) reset( $u->roles ) : '';
				$d    = array( 'role' => array( 't' => $role ) );
				self::user_event( 'created', $uid, $d, in_array( 'administrator', (array) $u->roles, true ) ? 'critical' : 'notice' );
			}
		);
	}

	public static function before_delete_user( $uid ): void {
		self::safe(
			static function () use ( $uid ) {
				$uid                 = (int) $uid;
				$u                   = get_userdata( $uid );
				self::$users[ $uid ] = array( self::user_label( $uid ), $u instanceof WP_User && $u->roles ? (string) reset( $u->roles ) : '' );
			}
		);
	}

	public static function user_deleted( $uid ): void {
		self::safe(
			static function () use ( $uid ) {
				$uid  = (int) $uid;
				$info = self::$users[ $uid ] ?? array( '#' . $uid, '' );
				ACL_Store::add(
					array(
						'category'    => 'users',
						'action'      => 'user.deleted',
						'object_type' => 'user',
						'object_id'   => $uid,
						'label'       => $info[0],
						'details'     => array( 'role' => array( 't' => $info[1] ) ),
						'severity'    => 'warning',
					)
				);
			}
		);
	}

	public static function user_role( $uid, $role, $old_roles ): void {
		self::safe(
			static function () use ( $uid, $role, $old_roles ) {
				$old_roles = is_array( $old_roles ) ? $old_roles : array();
				if ( ! $old_roles ) {
					return; // A new user: user_register records it.
				}
				$d = array();
				ACL_Redact::diff( $d, 'role', implode( ', ', $old_roles ), (string) $role );
				if ( ! $d ) {
					return;
				}
				$up = 'administrator' === $role && ! in_array( 'administrator', $old_roles, true );
				self::user_event( 'role', (int) $uid, $d, $up ? 'critical' : 'warning' );
			}
		);
	}

	public static function user_role_added( $uid, $role ): void {
		self::safe(
			static function () use ( $uid, $role ) {
				if ( doing_action( 'user_register' ) ) {
					return; // A new user: user_register records it.
				}
				$d = array( 'role' => array( 't' => '+' . (string) $role ) );
				self::user_event( 'role', (int) $uid, $d, 'administrator' === $role ? 'critical' : 'warning' );
			}
		);
	}

	public static function user_role_removed( $uid, $role ): void {
		self::safe(
			static function () use ( $uid, $role ) {
				if ( doing_action( 'user_register' ) ) {
					return;
				}
				self::user_event( 'role', (int) $uid, array( 'role' => array( 't' => '-' . (string) $role ) ), 'warning' );
			}
		);
	}

	public static function profile_update( $uid, $old ): void {
		self::safe(
			static function () use ( $uid, $old ) {
				$uid = (int) $uid;
				$new = get_userdata( $uid );
				if ( ! $new instanceof WP_User || ! $old instanceof WP_User ) {
					return;
				}
				$staff = ACL_Context::is_staff( $uid );
				if ( ! $staff && ! ACL_Context::by_staff_tools() ) {
					return; // A customer editing their own account.
				}
				$d = array();
				if ( (string) $old->user_email !== (string) $new->user_email ) {
					$d['email'] = array( 'h' => 1 );
				}
				if ( (string) $old->user_pass !== (string) $new->user_pass ) {
					$d['password'] = array( 'h' => 1 );
				}
				if ( $staff ) {
					ACL_Redact::diff( $d, 'display_name', $old->display_name, $new->display_name );
					ACL_Redact::diff( $d, 'login', $old->user_login, $new->user_login );
				}
				if ( $d ) {
					self::user_event( 'updated', $uid, $d, isset( $d['email'] ) && $staff ? 'notice' : 'info' );
				}
			}
		);
	}

	public static function password_reset( $user ): void {
		self::safe(
			static function () use ( $user ) {
				if ( $user instanceof WP_User && ACL_Context::is_staff( (int) $user->ID ) ) {
					self::user_event( 'password_reset', (int) $user->ID, array(), 'notice', ACL_Context::actor( (int) $user->ID ) );
				}
			}
		);
	}

	public static function app_password_created( $uid, $item ): void {
		self::safe(
			static function () use ( $uid, $item ) {
				$name = is_array( $item ) && isset( $item['name'] ) ? (string) $item['name'] : '';
				self::user_event( 'app_password_created', (int) $uid, array( 'name' => array( 't' => ACL_Redact::scalar( $name ) ) ), 'warning' );
			}
		);
	}

	public static function app_password_deleted( $uid, $item ): void {
		self::safe(
			static function () use ( $uid, $item ) {
				$name = is_array( $item ) && isset( $item['name'] ) ? (string) $item['name'] : '';
				self::user_event( 'app_password_revoked', (int) $uid, array( 'name' => array( 't' => ACL_Redact::scalar( $name ) ) ), 'notice' );
			}
		);
	}

	public static function magic_login( $uid ): void {
		self::safe(
			static function () use ( $uid ) {
				$uid = (int) $uid;
				if ( $uid > 0 && ACL_Settings::on( 'customer_logins' ) ) {
					self::user_event( 'magic_login', $uid, array(), 'info', ACL_Context::actor( $uid ), 'customer_logins' );
				}
			}
		);
	}

	/* =====================================================================
	 * Plugins, themes, core
	 * =================================================================== */

	private static function plugin_name( string $file ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return isset( $all[ $file ]['Name'] ) && '' !== $all[ $file ]['Name'] ? (string) $all[ $file ]['Name'] : $file;
	}

	private static function plugin_version( string $file ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';
	}

	private static function plugin_event( string $action, string $file, array $d = array(), string $severity = 'warning', string $name = '' ): void {
		ACL_Store::add(
			array(
				'category'    => 'plugins',
				'action'      => 'plugin.' . $action,
				'object_type' => 'plugin',
				'label'       => '' !== $name ? $name : self::plugin_name( $file ),
				'details'     => array_merge( array( 'file' => array( 't' => substr( $file, 0, 190 ) ) ), $d ),
				'severity'    => $severity,
			)
		);
	}

	public static function plugin_activated( $file, $network = false ): void {
		self::safe(
			static function () use ( $file, $network ) {
				self::plugin_event( 'activated', (string) $file, $network ? array( 'network' => array( 't' => '1' ) ) : array(), 'critical' );
			}
		);
	}

	public static function plugin_deactivated( $file, $network = false ): void {
		self::safe(
			static function () use ( $file, $network ) {
				self::plugin_event( 'deactivated', (string) $file, $network ? array( 'network' => array( 't' => '1' ) ) : array(), 'warning' );
			}
		);
	}

	/** Remembers the version of each plugin before an upgrader replaces it. */
	public static function pre_install( $response, $extra ) {
		self::safe(
			static function () use ( $extra ) {
				if ( is_array( $extra ) && ! empty( $extra['plugin'] ) && is_string( $extra['plugin'] ) ) {
					self::$before[ $extra['plugin'] ] = self::plugin_version( $extra['plugin'] );
				}
				if ( is_array( $extra ) && ! empty( $extra['theme'] ) && is_string( $extra['theme'] ) ) {
					$t                                     = wp_get_theme( $extra['theme'] );
					self::$before[ 'theme:' . $extra['theme'] ] = $t->exists() ? (string) $t->get( 'Version' ) : '';
				}
			}
		);
		return $response;
	}

	public static function upgraded( $upgrader, $extra ): void {
		self::safe(
			static function () use ( $upgrader, $extra ) {
				if ( ! is_array( $extra ) || empty( $extra['type'] ) ) {
					return;
				}
				$type   = (string) $extra['type'];
				$action = isset( $extra['action'] ) ? (string) $extra['action'] : '';
				if ( 'plugin' === $type ) {
					if ( 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
						$file = (string) $upgrader->plugin_info();
						$name = '' !== $file ? self::plugin_name( $file ) : '';
						if ( '' === $name && isset( $upgrader->new_plugin_data['Name'] ) ) {
							$name = (string) $upgrader->new_plugin_data['Name'];
						}
						self::plugin_event( 'installed', $file, array( 'version' => array( 't' => '' !== $file ? self::plugin_version( $file ) : '' ) ), 'critical', $name );
					} elseif ( 'update' === $action ) {
						$files = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
						foreach ( $files as $file ) {
							$file = (string) $file;
							$d    = array();
							ACL_Redact::diff( $d, 'version', self::$before[ $file ] ?? '', self::plugin_version( $file ) );
							self::plugin_event( 'updated', $file, $d, 'notice' );
						}
					}
					return;
				}
				if ( 'theme' === $type ) {
					$list = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
					if ( 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
						$t = $upgrader->theme_info();
						if ( $t instanceof WP_Theme ) {
							$list = array( $t->get_stylesheet() );
						}
					}
					foreach ( $list as $slug ) {
						$t = wp_get_theme( (string) $slug );
						$d = array();
						if ( 'update' === $action ) {
							ACL_Redact::diff( $d, 'version', self::$before[ 'theme:' . $slug ] ?? '', $t->exists() ? $t->get( 'Version' ) : '' );
						}
						ACL_Store::add(
							array(
								'category'    => 'plugins',
								'action'      => 'install' === $action ? 'theme.installed' : 'theme.updated',
								'object_type' => 'theme',
								'label'       => $t->exists() ? (string) $t->get( 'Name' ) : (string) $slug,
								'details'     => $d,
								'severity'    => 'install' === $action ? 'warning' : 'notice',
							)
						);
					}
					return;
				}
				if ( 'translation' === $type ) {
					return; // Language packs: no row.
				}
			}
		);
	}

	public static function before_delete_plugin( $file ): void {
		self::safe(
			static function () use ( $file ) {
				self::$deleting[ (string) $file ] = self::plugin_name( (string) $file );
			}
		);
	}

	public static function plugin_deleted( $file, $ok ): void {
		self::safe(
			static function () use ( $file, $ok ) {
				if ( $ok ) {
					self::plugin_event( 'deleted', (string) $file, array(), 'critical', self::$deleting[ (string) $file ] ?? '' );
				}
			}
		);
	}

	public static function theme_switched( $name, $new, $old ): void {
		self::safe(
			static function () use ( $name, $new, $old ) {
				$d = array();
				ACL_Redact::diff( $d, 'theme', $old instanceof WP_Theme ? $old->get( 'Name' ) : '', $new instanceof WP_Theme ? $new->get( 'Name' ) : (string) $name );
				ACL_Store::add(
					array(
						'category'    => 'plugins',
						'action'      => 'theme.switched',
						'object_type' => 'theme',
						'label'       => (string) $name,
						'details'     => $d,
						'severity'    => 'critical',
					)
				);
			}
		);
	}

	public static function theme_deleted( $stylesheet, $ok ): void {
		self::safe(
			static function () use ( $stylesheet, $ok ) {
				if ( $ok ) {
					ACL_Store::add(
						array(
							'category'    => 'plugins',
							'action'      => 'theme.deleted',
							'object_type' => 'theme',
							'label'       => (string) $stylesheet,
							'severity'    => 'warning',
						)
					);
				}
			}
		);
	}

	public static function core_updated( $version ): void {
		self::safe(
			static function () use ( $version ) {
				global $wp_version;
				$d = array();
				ACL_Redact::diff( $d, 'version', (string) $wp_version, (string) $version );
				ACL_Store::add(
					array(
						'category'    => 'plugins',
						'action'      => 'core.updated',
						'object_type' => 'core',
						'label'       => 'WordPress',
						'details'     => $d,
						'severity'    => 'notice',
					)
				);
			}
		);
	}

	/** The plugin / theme file editor is about to save (ajax, before core's handler). */
	public static function file_edited(): void {
		self::safe(
			static function () {
				// phpcs:disable WordPress.Security.NonceVerification.Missing -- core's handler checks the nonce; we only note the attempt.
				$file   = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['file'] ) ) : '';
				$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['plugin'] ) ) : '';
				$theme  = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['theme'] ) ) : '';
				// phpcs:enable
				if ( '' === $file || ! current_user_can( 'edit_plugins' ) && ! current_user_can( 'edit_themes' ) ) {
					return;
				}
				ACL_Store::add(
					array(
						'category'    => 'plugins',
						'action'      => 'file.edited',
						'object_type' => '' !== $theme ? 'theme' : 'plugin',
						'label'       => '' !== $theme ? $theme : $plugin,
						'details'     => array( 'file' => array( 't' => substr( $file, 0, 190 ) ) ),
						'severity'    => 'critical',
					)
				);
			}
		);
	}

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	/** True when an option name is watched. */
	public static function watched( string $name ): bool {
		static $extra = null;
		if ( null === $extra ) {
			$f     = apply_filters( 'acl_watched_options', ACL_Settings::get()['extra'] );
			$extra = is_array( $f ) ? array_map( 'strval', $f ) : array();
		}
		if ( 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' ) || ACL_Redact::is_secret( $name ) ) {
			return in_array( $name, $extra, true ) && ! ACL_Redact::is_secret( $name );
		}
		if ( in_array( $name, self::CORE_OPTIONS, true ) || in_array( $name, $extra, true ) ) {
			return true;
		}
		if ( 0 === strpos( $name, 'woocommerce_' ) ) {
			return ! preg_match( self::WC_NOISE, $name );
		}
		return (bool) preg_match( self::SUITE_RE, $name ) && ! in_array( $name, array( 'acl_db' ), true );
	}

	public static function option_updated( $name, $old, $new ): void {
		self::safe(
			static function () use ( $name, $old, $new ) {
				$name = (string) $name;
				if ( ! self::watched( $name ) ) {
					return;
				}
				$d = array();
				if ( ! ACL_Redact::diff( $d, $name, $old, $new ) ) {
					return;
				}
				// Keys of the option relative to its name, for a shorter row.
				$short = array();
				foreach ( $d as $k => $v ) {
					$k           = (string) $k;
					$short[ $k === $name ? 'value' : substr( $k, strlen( $name ) + 1 ) ] = $v;
				}
				ACL_Store::add(
					array(
						'category'    => 'settings',
						'action'      => 'option.updated',
						'object_type' => 'option',
						'label'       => $name,
						'details'     => $short,
						'severity'    => in_array( $name, self::CRITICAL_OPTIONS, true ) ? 'critical' : 'info',
					)
				);
			}
		);
	}

	/** A watched option saved for the first time (e.g. a WooCommerce tab). */
	public static function option_added( $name, $value ): void {
		self::safe(
			static function () use ( $name, $value ) {
				$name = (string) $name;
				if ( ! did_action( 'init' ) || ! self::watched( $name ) || ! ACL_Context::by_staff_tools() ) {
					return;
				}
				self::option_updated( $name, null, $value );
			}
		);
	}

	/* =====================================================================
	 * Media, menus
	 * =================================================================== */

	public static function media_added( $id ): void {
		self::safe(
			static function () use ( $id ) {
				ACL_Store::add(
					array(
						'category'    => 'media',
						'action'      => 'media.uploaded',
						'object_type' => 'attachment',
						'object_id'   => (int) $id,
						'label'       => wp_basename( (string) get_attached_file( (int) $id ) ),
					)
				);
			}
		);
	}

	public static function media_deleted( $id ): void {
		self::safe(
			static function () use ( $id ) {
				ACL_Store::add(
					array(
						'category'    => 'media',
						'action'      => 'media.deleted',
						'object_type' => 'attachment',
						'object_id'   => (int) $id,
						'label'       => wp_basename( (string) get_attached_file( (int) $id ) ),
						'severity'    => 'notice',
					)
				);
			}
		);
	}

	public static function menu_saved( $id ): void {
		self::safe(
			static function () use ( $id ) {
				$m = wp_get_nav_menu_object( (int) $id );
				ACL_Store::add(
					array(
						'category'    => 'menus',
						'action'      => 'menu.updated',
						'object_type' => 'menu',
						'object_id'   => (int) $id,
						'label'       => $m ? (string) $m->name : '#' . (int) $id,
						'details'     => array( 'menu' => array( 't' => 'saved' ) ),
					)
				);
			}
		);
	}

	public static function menu_deleted( $id ): void {
		self::safe(
			static function () use ( $id ) {
				ACL_Store::add(
					array(
						'category'    => 'menus',
						'action'      => 'menu.deleted',
						'object_type' => 'menu',
						'object_id'   => (int) $id,
						'label'       => '#' . (int) $id,
						'severity'    => 'notice',
					)
				);
			}
		);
	}
}
