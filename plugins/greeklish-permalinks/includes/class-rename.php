<?php
/**
 * GLP_Rename — rename one media file to a name you give (1.3.0).
 *
 * The same engine as the bulk conversion (GLP_Bulk::rename_file()): every
 * size, "-scaled", the .webp / .avif copies, references in post content,
 * excerpts and WooCommerce downloadable files, and a 301 from each old
 * URL in the glp_map table. New here: an own name, files without Greek,
 * and a separate undo log (glp_rename_log, the last 100), so a bulk run
 * never erases the undo of a rename or the other way round.
 *
 * Who: edit_others_posts (Editor, Shop Manager, Administrator) and
 * edit_post on the file, because a rename changes URLs across the site.
 *
 * Where:
 *  - field «Όνομα αρχείου» on the attachment screen and in the media
 *    library's details: shows the final name as you type (AJAX
 *    glp_rename_preview) and a «Μετονομασία…» button to the page below.
 *    The field has no name attribute, so saving the attachment never
 *    renames anything;
 *  - row action «Μετονομασία» in the list view of the library;
 *  - bulk actions «Όνομα αρχείου από τον τίτλο» and «… από το
 *    προϊόν/άρθρο» (at most 50 files per request);
 *  - page Media → «Μετονομασίες» (upload.php?page=glp-rename): preview
 *    with the counts, the rename itself (POST + nonce, PRG), the server
 *    check, and the log with one «Αναίρεση» per rename.
 *
 * Admin only; nothing runs on the front end. GLP_DISABLE does not stop
 * it: it never runs by itself.
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Rename {

	const CAP      = 'edit_others_posts';
	const LOG      = 'glp_rename_log';
	const MAX_LOG  = 100;
	const MAX_BULK = 50;
	const MAX_LEN  = 120;
	const SLUG     = 'glp-rename';
	const NONCE    = 'glp_rename';
	const BULK     = array( 'glp_name_title', 'glp_name_parent' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 140 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'field_script' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_ajax_glp_rename_preview', array( __CLASS__, 'ajax_preview' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'field' ), 10, 2 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
	}

	/** May the current user rename this attachment? */
	public static function can( int $id ): bool {
		if ( ! current_user_can( self::CAP ) ) {
			return false;
		}
		if ( $id <= 0 ) {
			return true;
		}
		$post = get_post( $id );
		return $post && 'attachment' === $post->post_type && current_user_can( 'edit_post', $id );
	}

	public static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'upload.php' ) );
	}

	/* =====================================================================
	 * Names
	 * =================================================================== */

	/**
	 * A typed name as a file stem: Greek → Latin with the plugin's rules,
	 * lower case, only a-z 0-9 - _, no extension, at most 120 characters.
	 * '' when nothing is left.
	 */
	public static function clean_stem( string $raw, string $ext = '' ): string {
		$raw = trim( wp_strip_all_tags( $raw ) );
		$raw = str_replace( '\\', '/', $raw );
		$raw = (string) substr( $raw, (int) strrpos( '/' . $raw, '/' ) );
		if ( '' !== $ext && strlen( $raw ) > strlen( $ext ) + 1 && 0 === strcasecmp( substr( $raw, -strlen( $ext ) - 1 ), '.' . $ext ) ) {
			$raw = substr( $raw, 0, -strlen( $ext ) - 1 );
		}
		if ( GLP_Converter::has_greek( $raw ) ) {
			$opt  = GLP_Settings::file_opt();
			$conv = GLP_Converter::file_name( $raw . '.x', $opt );
			$raw  = substr( $conv, 0, -2 );
		}
		$s = strtolower( remove_accents( $raw ) );
		$s = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $s );
		$s = (string) preg_replace( '/-{2,}/', '-', $s );
		$s = trim( $s, '-_' );
		// WordPress reads these endings as a size or the big-image copy.
		$s = (string) preg_replace( '/(-\d+x\d+|-scaled|-e\d{10,})+$/', '', $s );
		if ( strlen( $s ) > self::MAX_LEN ) {
			$s = rtrim( substr( $s, 0, self::MAX_LEN ), '-_' );
		}
		return $s;
	}

	/** The file's current stem and extension. */
	public static function current( int $id ): array {
		$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
		$base = basename( $rel );
		$ext  = (string) pathinfo( $base, PATHINFO_EXTENSION );
		return array(
			'stem' => '' !== $ext ? substr( $base, 0, -strlen( $ext ) - 1 ) : $base,
			'ext'  => $ext,
			'rel'  => $rel,
		);
	}

	/** Stem from the attachment's title ('title') or the post it belongs to ('parent'). */
	public static function stem_from( int $id, string $from ): string {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		$title = '';
		if ( 'parent' === $from ) {
			$parent = $post->post_parent ? get_post( (int) $post->post_parent ) : null;
			$title  = $parent ? (string) $parent->post_title : '';
		} else {
			$title = (string) $post->post_title;
		}
		return self::clean_stem( $title, self::current( $id )['ext'] );
	}

	/* =====================================================================
	 * Rename + log
	 * =================================================================== */

	/**
	 * Dry run ($apply false) or rename. $via: field | row | title | parent.
	 *
	 * @return array GLP_Bulk result: status ok|skip|error, new, note, refs (dry run).
	 */
	public static function run( int $id, string $stem, bool $apply, string $via = 'row' ): array {
		if ( ! self::can( $id ) || $id <= 0 ) {
			return array(
				'k'      => 'f:' . $id,
				'status' => 'error',
				'new'    => '',
				'note'   => __( 'Δεν έχεις δικαίωμα μετονομασίας αυτού του αρχείου.', 'greeklish-permalinks' ),
			);
		}
		if ( ! $apply ) {
			return GLP_Bulk::rename_file( $id, $stem );
		}
		$meta = array(
			'id'   => wp_generate_password( 10, false ),
			'time' => time(),
			'user' => get_current_user_id(),
			'via'  => in_array( $via, array( 'field', 'row', 'title', 'parent' ), true ) ? $via : 'row',
		);
		return GLP_Bulk::rename_file(
			$id,
			$stem,
			static function ( array $entry ) use ( $meta ): bool {
				return self::add( array_merge( $meta, $entry ) );
			},
			static function ( string $k ) use ( $meta ): void {
				self::drop( $meta['id'] );
			}
		);
	}

	public static function log(): array {
		$l = get_option( self::LOG, array() );
		return is_array( $l ) ? array_values( array_filter( $l, 'is_array' ) ) : array();
	}

	/** Append one entry (newest last); the oldest go past 100. True only when written. */
	private static function add( array $entry ): bool {
		$l   = self::log();
		$l[] = $entry;
		if ( count( $l ) > self::MAX_LOG ) {
			$l = array_slice( $l, -self::MAX_LOG );
		}
		return update_option( self::LOG, $l, false );
	}

	private static function drop( string $log_id ): void {
		$l    = self::log();
		$kept = array_values(
			array_filter(
				$l,
				static function ( $e ) use ( $log_id ) {
					return ! isset( $e['id'] ) || $e['id'] !== $log_id;
				}
			)
		);
		if ( count( $kept ) !== count( $l ) ) {
			if ( $kept ) {
				update_option( self::LOG, $kept, false );
			} else {
				delete_option( self::LOG );
			}
		}
	}

	/**
	 * Undo one rename. Returns array( ok, message ). It goes back only when
	 * the file still has the name the rename gave it.
	 */
	public static function undo( string $log_id ): array {
		$entry = null;
		foreach ( self::log() as $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $log_id ) {
				$entry = $e;
				break;
			}
		}
		if ( null === $entry ) {
			return array( false, __( 'Η μετονομασία δεν βρέθηκε στο ημερολόγιο.', 'greeklish-permalinks' ) );
		}
		$id = isset( $entry['k'] ) && preg_match( '/^f:(\d+)$/', (string) $entry['k'], $m ) ? (int) $m[1] : 0;
		if ( ! self::can( $id ) || $id <= 0 ) {
			return array( false, __( 'Δεν έχεις δικαίωμα μετονομασίας αυτού του αρχείου.', 'greeklish-permalinks' ) );
		}
		$entry = GLP_Bulk::sanitize_log(
			array(
				'run'   => 'own',
				'time'  => 0,
				'items' => array( $entry ),
			)
		);
		if ( null === $entry ) {
			return array( false, __( 'Η εγγραφή του ημερολογίου δεν είναι έγκυρη: δεν άλλαξε τίποτα.', 'greeklish-permalinks' ) );
		}
		$entry = $entry['items'][0];
		try {
			$msg = GLP_Bulk::undo_entry( $entry );
		} catch ( \Throwable $e ) {
			return array( false, $e->getMessage() );
		}
		if ( (string) get_post_meta( $id, '_wp_attached_file', true ) !== $entry['rel'] ) {
			return array( false, '' !== $msg ? $msg : __( 'Η αναίρεση απέτυχε.', 'greeklish-permalinks' ) );
		}
		self::drop( $log_id );
		return array( true, '' !== $msg ? $msg : '' );
	}

	/** One line with what a rename changes. */
	public static function refs_text( array $refs ): string {
		return sprintf(
			/* translators: 1: files on disk, 2: of them WebP/AVIF, 3: posts, 4: downloadable files, 5: other references */
			__( '%1$d αρχεία στον δίσκο (%2$d WebP/AVIF) · %3$d άρθρα/σελίδες · %4$d αρχεία λήψης · %5$d άλλες αναφορές (μένουν με 301)', 'greeklish-permalinks' ),
			isset( $refs['files'] ) ? (int) $refs['files'] : 0,
			isset( $refs['webp'] ) ? (int) $refs['webp'] : 0,
			isset( $refs['posts'] ) ? (int) $refs['posts'] : 0,
			isset( $refs['dl'] ) ? (int) $refs['dl'] : 0,
			isset( $refs['other'] ) ? (int) $refs['other'] : 0
		);
	}

	/* =====================================================================
	 * Media screens: field, row action, bulk actions
	 * =================================================================== */

	public static function field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof WP_Post || ! self::can( (int) $post->ID ) ) {
			return $fields;
		}
		$cur = self::current( (int) $post->ID );
		if ( '' === $cur['rel'] || preg_match( '#^(https?:)?//#i', $cur['rel'] ) ) {
			return $fields;
		}
		$id   = (int) $post->ID;
		$html = sprintf(
			'<span class="glp-ren"><input type="text" class="widefat glp-ren-input" id="glp-ren-%1$d" value="%2$s" data-id="%1$d" data-ext="%3$s" maxlength="200" autocomplete="off" aria-describedby="glp-ren-out-%1$d" /><span style="display:flex;gap:8px;align-items:center;margin-top:6px"><span class="glp-ren-ext">%4$s</span><a class="button glp-ren-go" href="%5$s" data-base="%5$s">%6$s</a></span><span class="glp-ren-out description" id="glp-ren-out-%1$d" aria-live="polite" style="display:block"></span></span>',
			$id,
			esc_attr( $cur['stem'] ),
			esc_attr( $cur['ext'] ),
			esc_html( '' !== $cur['ext'] ? '.' . $cur['ext'] : '' ),
			esc_url( self::page_url( array( 'id' => $id ) ) ),
			esc_html__( 'Μετονομασία…', 'greeklish-permalinks' )
		);
		$fields['glp_name'] = array(
			'label' => __( 'Όνομα αρχείου', 'greeklish-permalinks' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Το «Μετονομασία…» δείχνει πρώτα τι θα αλλάξει. Τα παλιά URLs πάνε με 301 στο νέο. Η αποθήκευση του αρχείου δεν το μετονομάζει.', 'greeklish-permalinks' ),
		);
		return $fields;
	}

	public static function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || ! self::can( (int) $post->ID ) ) {
			return $actions;
		}
		$actions['glp_rename'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::page_url( array( 'id' => (int) $post->ID ) ) ),
			esc_html__( 'Μετονομασία', 'greeklish-permalinks' )
		);
		return $actions;
	}

	public static function bulk_actions( $actions ) {
		if ( ! is_array( $actions ) || ! current_user_can( self::CAP ) ) {
			return $actions;
		}
		$actions['glp_name_title']  = __( 'Όνομα αρχείου από τον τίτλο', 'greeklish-permalinks' );
		$actions['glp_name_parent'] = __( 'Όνομα αρχείου από το προϊόν/άρθρο', 'greeklish-permalinks' );
		return $actions;
	}

	/** WordPress checked the bulk-media nonce before this filter (upload.php). */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, self::BULK, true ) || ! current_user_can( self::CAP ) ) {
			return $redirect;
		}
		check_admin_referer( 'bulk-media' );
		$from  = 'glp_name_parent' === $action ? 'parent' : 'title';
		$ids   = array_slice( array_values( array_unique( array_map( 'intval', (array) $ids ) ) ), 0, self::MAX_BULK );
		$count = array(
			'ok'   => 0,
			'skip' => 0,
			'err'  => 0,
		);
		$notes = array();
		foreach ( $ids as $id ) {
			$stem = self::stem_from( $id, $from );
			if ( '' === $stem ) {
				$count['skip']++;
				if ( 'parent' === $from ) {
					/* translators: %d: attachment ID */
					$notes[] = sprintf( __( '#%d: δεν ανήκει σε προϊόν ή άρθρο.', 'greeklish-permalinks' ), $id );
				}
				continue;
			}
			$r = self::run( $id, $stem, true, $from );
			if ( 'ok' === $r['status'] ) {
				$count['ok']++;
			} elseif ( 'skip' === $r['status'] ) {
				$count['skip']++;
			} else {
				$count['err']++;
				$notes[] = '#' . $id . ': ' . $r['note'];
			}
			if ( ! empty( $r['stop'] ) ) {
				break;
			}
		}
		set_transient(
			'glp_ren_msg_' . get_current_user_id(),
			array(
				'bulk'  => $count,
				'notes' => array_slice( $notes, 0, 5 ),
				'more'  => count( (array) $ids ) >= self::MAX_BULK,
			),
			120
		);
		return add_query_arg( 'glp_ren', '1', remove_query_arg( 'glp_ren', (string) $redirect ) );
	}

	/** Result of a bulk action on the library list. */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		if ( ! $screen || 'upload' !== $screen->id || empty( $_GET['glp_ren'] ) ) {
			return;
		}
		$msg = get_transient( 'glp_ren_msg_' . get_current_user_id() );
		if ( ! is_array( $msg ) || ! isset( $msg['bulk'] ) ) {
			return;
		}
		delete_transient( 'glp_ren_msg_' . get_current_user_id() );
		$c    = $msg['bulk'];
		$type = $c['err'] ? 'warning' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>';
		printf(
			/* translators: 1: renamed, 2: skipped, 3: errors */
			esc_html__( 'Μετονομασία: %1$d εντάξει, %2$d παραλείψεις, %3$d σφάλματα. Η αναίρεση είναι στα Πολυμέσα → Μετονομασίες.', 'greeklish-permalinks' ),
			(int) $c['ok'],
			(int) $c['skip'],
			(int) $c['err']
		);
		echo '</p>';
		foreach ( (array) $msg['notes'] as $n ) {
			echo '<p>' . esc_html( (string) $n ) . '</p>';
		}
		if ( ! empty( $msg['more'] ) ) {
			/* translators: %d: limit */
			echo '<p>' . esc_html( sprintf( __( 'Έως %d αρχεία κάθε φορά: διάλεξε τα υπόλοιπα και ξαναδοκίμασε.', 'greeklish-permalinks' ), self::MAX_BULK ) ) . '</p>';
		}
		if ( $c['ok'] && 'no' === GLP_Redirects::probe() ) {
			echo '<p>' . esc_html__( 'Ο server δεν στέλνει στο WordPress τα αρχεία που λείπουν, οπότε τα παλιά URLs εικόνων που είναι έξω από το site (Google, social, emails) βγάζουν 404. Οι αναφορές μέσα στο site άλλαξαν.', 'greeklish-permalinks' ) . '</p>';
		}
		echo '</div>';
	}

	/* =====================================================================
	 * Scripts
	 * =================================================================== */

	public static function assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'media_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'glp-admin', GLP_URL . 'assets/admin.css', array(), GLP_VERSION );
		}
		if ( $screen && 'attachment' === $screen->id ) {
			self::field_script();
		}
	}

	/** Live preview of the field (media modal, attachment screen). */
	public static function field_script(): void {
		if ( ! current_user_can( self::CAP ) || wp_script_is( 'glp-rename', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( 'glp-rename', GLP_URL . 'assets/rename.js', array(), GLP_VERSION, true );
		wp_localize_script(
			'glp-rename',
			'glpRename',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'will'  => __( 'Θα γίνει: %s', 'greeklish-permalinks' ),
			)
		);
	}

	/** The final name of a typed name (no counts: those are on the page). */
	public static function ajax_preview(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! self::can( $id ) || $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχεις δικαίωμα μετονομασίας αυτού του αρχείου.', 'greeklish-permalinks' ) ), 403 );
		}
		$raw  = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$cur  = self::current( $id );
		$stem = self::clean_stem( $raw, $cur['ext'] );
		wp_send_json_success(
			array(
				'stem' => $stem,
				'name' => '' !== $stem ? $stem . ( '' !== $cur['ext'] ? '.' . strtolower( $cur['ext'] ) : '' ) : '',
				'same' => $stem === $cur['stem'],
			)
		);
	}

	/* =====================================================================
	 * Page: Media → Μετονομασίες
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			'upload.php',
			__( 'Μετονομασίες αρχείων', 'greeklish-permalinks' ),
			__( 'Μετονομασίες', 'greeklish-permalinks' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function p( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/** POST: rename (glp_ren_do) or undo (glp_ren_undo); nonce + capability, PRG. */
	public static function route(): void {
		if ( self::SLUG !== self::q( 'page' ) || ! isset( $_POST['glp_ren_action'] ) ) {
			return;
		}
		if ( ! isset( $_POST['glp_ren_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['glp_ren_nonce'] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) );
		}
		$action = sanitize_key( self::p( 'glp_ren_action' ) );
		$msgs   = array();
		$args   = array();
		if ( 'rename' === $action ) {
			$id   = absint( self::p( 'glp_ren_id' ) );
			$stem = self::clean_stem( self::p( 'glp_ren_stem' ), self::current( $id )['ext'] );
			$via  = 'field' === self::p( 'glp_ren_via' ) ? 'field' : 'row';
			$r    = self::run( $id, $stem, true, $via );
			if ( 'ok' === $r['status'] ) {
				/* translators: %s: new file path */
				$msgs[] = array( 'success', sprintf( __( 'Έγινε: %s. Τα παλιά URLs πάνε με 301 στο νέο.', 'greeklish-permalinks' ), $r['new'] ) );
				if ( '' !== $r['note'] ) {
					$msgs[] = array( 'info', $r['note'] );
				}
			} else {
				$msgs[] = array( 'skip' === $r['status'] ? 'warning' : 'error', $r['note'] );
				$args   = array(
					'id'   => $id,
					'name' => $stem,
				);
			}
		} elseif ( 'undo' === $action ) {
			list( $ok, $text ) = self::undo( self::p( 'glp_ren_log' ) );
			$msgs[]            = $ok
				? array( '' !== $text ? 'warning' : 'success', '' !== $text ? $text : __( 'Η μετονομασία αναιρέθηκε.', 'greeklish-permalinks' ) )
				: array( 'error', $text );
		}
		set_transient( 'glp_ren_msg_' . get_current_user_id(), array( 'page' => $msgs ), 60 );
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) );
		}
		$msg = get_transient( 'glp_ren_msg_' . get_current_user_id() );
		if ( is_array( $msg ) && isset( $msg['page'] ) ) {
			delete_transient( 'glp_ren_msg_' . get_current_user_id() );
		}
		$id = absint( self::q( 'id' ) );
		?>
		<div class="wrap glp-wrap">
			<h1><?php esc_html_e( 'Μετονομασίες αρχείων', 'greeklish-permalinks' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Νέο όνομα σε ένα αρχείο πολυμέσων: αλλάζουν όλα τα μεγέθη, τα αντίγραφα WebP/AVIF και οι αναφορές στα άρθρα, στις σελίδες και στα αρχεία λήψης, και τα παλιά URLs πάνε με 301 στο νέο.', 'greeklish-permalinks' ); ?></p>
			<?php
			if ( is_array( $msg ) && ! empty( $msg['page'] ) ) {
				foreach ( (array) $msg['page'] as $m ) {
					if ( is_array( $m ) && isset( $m[0], $m[1] ) && '' !== (string) $m[1] ) {
						$type = in_array( $m[0], array( 'success', 'warning', 'error', 'info' ), true ) ? $m[0] : 'info';
						echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible inline"><p>' . esc_html( (string) $m[1] ) . '</p></div>';
					}
				}
			}
			if ( $id > 0 ) {
				self::render_one( $id );
			}
			self::render_log();
			GLP_Admin_UI::footer();
			?>
		</div>
		<?php
	}

	private static function render_one( int $id ): void {
		if ( ! self::can( $id ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Δεν έχεις δικαίωμα μετονομασίας αυτού του αρχείου.', 'greeklish-permalinks' ) . '</p></div>';
			return;
		}
		$cur   = self::current( $id );
		$typed = self::q( 'name' );
		$stem  = '' !== $typed ? self::clean_stem( $typed, $cur['ext'] ) : '';
		$r     = '' !== $stem ? self::run( $id, $stem, false ) : null;
		$probe = GLP_Redirects::probe();
		$ext   = '' !== $cur['ext'] ? '.' . $cur['ext'] : '';
		?>
		<h2 class="glp-h2"><?php echo esc_html( get_the_title( $id ) ); ?></h2>
		<div class="glp-zone">
			<p>
				<?php echo wp_get_attachment_image( $id, array( 80, 80 ), true, array( 'style' => 'float:right;margin-left:12px;max-width:80px;height:auto' ) ); ?>
				<strong><?php esc_html_e( 'Τώρα:', 'greeklish-permalinks' ); ?></strong> <code><?php echo esc_html( $cur['rel'] ); ?></code>
			</p>
			<form method="get" action="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<p>
					<label for="glp-ren-name"><strong><?php esc_html_e( 'Νέο όνομα', 'greeklish-permalinks' ); ?></strong></label><br />
					<input type="text" id="glp-ren-name" name="name" class="regular-text" maxlength="200" value="<?php echo esc_attr( '' !== $typed ? $typed : $cur['stem'] ); ?>" /> <code><?php echo esc_html( $ext ); ?></code>
					<button type="submit" class="button"><?php esc_html_e( 'Προεπισκόπηση', 'greeklish-permalinks' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Τα ελληνικά γίνονται Greeklish με τους κανόνες σου, όλα πεζά, τα κενά παύλες. Αν το όνομα υπάρχει ήδη, παίρνει «-2», «-3».', 'greeklish-permalinks' ); ?></p>
			</form>
			<?php if ( '' !== $typed && '' === $stem ) : ?>
				<p class="glp-warn"><?php esc_html_e( 'Το νέο όνομα είναι κενό.', 'greeklish-permalinks' ); ?></p>
			<?php elseif ( null !== $r ) : ?>
				<?php if ( 'ok' === $r['status'] ) : ?>
					<p><strong><?php esc_html_e( 'Μετά:', 'greeklish-permalinks' ); ?></strong> <code><?php echo esc_html( $r['new'] ); ?></code></p>
					<?php if ( '' !== $r['note'] ) : ?>
						<p class="glp-warn"><?php echo esc_html( $r['note'] ); ?></p>
					<?php endif; ?>
					<p><?php echo esc_html( self::refs_text( isset( $r['refs'] ) ? $r['refs'] : array() ) ); ?></p>
					<?php if ( ! empty( $r['refs']['others'] ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: meta keys */
							echo esc_html( sprintf( __( 'Άλλες αναφορές σε: %s', 'greeklish-permalinks' ), implode( ', ', (array) $r['refs']['others'] ) ) );
							?>
						</p>
					<?php endif; ?>
					<?php if ( 'no' === $probe ) : ?>
						<p class="glp-warn"><?php esc_html_e( 'Προσοχή: ο server δεν στέλνει στο WordPress τα αρχεία που λείπουν, οπότε τα παλιά URLs του αρχείου έξω από το site (Google, social, emails) θα βγάζουν 404. Οι αναφορές μέσα στο site αλλάζουν έτσι κι αλλιώς.', 'greeklish-permalinks' ); ?></p>
					<?php elseif ( 'unknown' === $probe ) : ?>
						<p class="glp-muted"><?php esc_html_e( 'Ο έλεγχος του server δεν έγινε (το site δεν απάντησε σε αίτημα προς τον εαυτό του).', 'greeklish-permalinks' ); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
						<?php wp_nonce_field( self::NONCE, 'glp_ren_nonce' ); ?>
						<input type="hidden" name="glp_ren_action" value="rename" />
						<input type="hidden" name="glp_ren_id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<input type="hidden" name="glp_ren_stem" value="<?php echo esc_attr( $stem ); ?>" />
						<input type="hidden" name="glp_ren_via" value="<?php echo esc_attr( 'field' === self::q( 'via' ) ? 'field' : 'row' ); ?>" />
						<p class="glp-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Μετονομασία', 'greeklish-permalinks' ); ?></button></p>
					</form>
				<?php else : ?>
					<p class="glp-warn"><?php echo esc_html( $r['note'] ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_log(): void {
		$log = array_reverse( self::log() );
		$via = array(
			'field'  => __( 'Πεδίο', 'greeklish-permalinks' ),
			'row'    => __( 'Μετονομασία', 'greeklish-permalinks' ),
			'title'  => __( 'Από τον τίτλο', 'greeklish-permalinks' ),
			'parent' => __( 'Από το προϊόν/άρθρο', 'greeklish-permalinks' ),
		);
		?>
		<h2 class="glp-h2"><?php esc_html_e( 'Τελευταίες μετονομασίες', 'greeklish-permalinks' ); ?></h2>
		<div class="glp-zone">
			<?php if ( ! $log ) : ?>
				<p class="glp-muted"><?php esc_html_e( 'Δεν υπάρχει μετονομασία ακόμα. Ξεκίνα από τη Βιβλιοθήκη: «Μετονομασία» σε ένα αρχείο, ή μαζική ενέργεια στην προβολή λίστας.', 'greeklish-permalinks' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					/* translators: %d: number kept */
					echo esc_html( sprintf( __( 'Κρατιούνται οι τελευταίες %d. Η αναίρεση γυρίζει πίσω το όνομα, τα μεγέθη, τις αναφορές και το 301, μόνο αν το αρχείο έχει ακόμα το όνομα που του έδωσε η μετονομασία.', 'greeklish-permalinks' ), self::MAX_LOG ) );
					?>
				</p>
				<table class="widefat striped glp-table">
					<thead><tr>
						<th><?php esc_html_e( 'Πότε', 'greeklish-permalinks' ); ?></th>
						<th><?php esc_html_e( 'Πριν', 'greeklish-permalinks' ); ?></th>
						<th><?php esc_html_e( 'Μετά', 'greeklish-permalinks' ); ?></th>
						<th><?php esc_html_e( 'Πώς', 'greeklish-permalinks' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $log as $e ) : ?>
						<?php
						$aid  = isset( $e['k'] ) && preg_match( '/^f:(\d+)$/', (string) $e['k'], $mm ) ? (int) $mm[1] : 0;
						$old  = isset( $e['rel'] ) ? (string) $e['rel'] : '';
						$base = basename( $old );
						$new  = isset( $e['dir'], $e['plan'][ $base ] ) ? (string) $e['dir'] . (string) $e['plan'][ $base ] : '';
						$user = isset( $e['user'] ) ? get_userdata( (int) $e['user'] ) : false;
						?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), isset( $e['time'] ) ? (int) $e['time'] : 0 ) ); ?><?php echo $user ? '<br /><span class="glp-muted">' . esc_html( $user->display_name ) . '</span>' : ''; ?></td>
							<td><code><?php echo esc_html( $old ); ?></code></td>
							<td>
								<?php if ( $aid > 0 && get_post( $aid ) ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $aid ) ); ?>"><code><?php echo esc_html( $new ); ?></code></a>
								<?php else : ?>
									<code><?php echo esc_html( $new ); ?></code>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( isset( $e['via'], $via[ $e['via'] ] ) ? $via[ $e['via'] ] : '' ); ?></td>
							<td>
								<?php if ( self::can( $aid ) && $aid > 0 && isset( $e['id'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
										<?php wp_nonce_field( self::NONCE, 'glp_ren_nonce', false ); ?>
										<input type="hidden" name="glp_ren_action" value="undo" />
										<input type="hidden" name="glp_ren_log" value="<?php echo esc_attr( (string) $e['id'] ); ?>" />
										<button type="submit" class="button"><?php esc_html_e( 'Αναίρεση', 'greeklish-permalinks' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
