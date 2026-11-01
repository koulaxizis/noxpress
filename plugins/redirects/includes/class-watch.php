<?php
/**
 * RDR_Watch — addresses that just stopped working become suggestions on
 * the Rules tab, never rules by themselves (approved design, question 10):
 *
 *  - a published post, page or product goes to the trash or is deleted:
 *    «301 to its category», «410» or «no»;
 *  - a category, tag or other public term changes its address (WordPress
 *    keeps old slugs for posts, not for terms): «301 to the new address».
 *
 * Kept in the option rdr_pending (not autoloaded, newest 50). Written only
 * by these edits (admin, REST, cron of other plugins), never by a visitor.
 * Greeklish Permalinks' bulk conversion is skipped: it keeps its own
 * redirect table.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Watch {

	const OPT = 'rdr_pending';
	const MAX = 50;

	/** @var array<int,string> Term id => old normalised path, during an edit. */
	private static $term_old = array();

	public static function init(): void {
		add_action( 'wp_trash_post', array( __CLASS__, 'post_gone' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'post_gone' ) );
		add_action( 'edit_terms', array( __CLASS__, 'term_before' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'term_after' ), 10, 3 );
	}

	/** Greeklish Permalinks' bulk run (its own AJAX actions). */
	private static function in_glp_bulk(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only read to skip.
		$action = isset( $_POST['action'] ) && is_scalar( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		return wp_doing_ajax() && 0 === strpos( $action, 'glp' );
	}

	public static function post_gone( $post_id ): void {
		try {
			$p = get_post( (int) $post_id );
			if ( ! $p || 'publish' !== $p->post_status || in_array( $p->post_type, array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'customize_changeset', 'wp_global_styles', 'product_variation' ), true ) ) {
				return;
			}
			if ( ! is_post_type_viewable( $p->post_type ) || self::in_glp_bulk() ) {
				return;
			}
			$path = RDR_Path::norm( (string) get_permalink( $p ) );
			if ( null === $path || '' === $path || RDR_Rules::find_exact( $path ) ) {
				return;
			}
			// A suggested target: the first category of a product or a post.
			$cat = '';
			$tax = 'product' === $p->post_type ? 'product_cat' : ( 'post' === $p->post_type ? 'category' : '' );
			if ( '' !== $tax && taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $p, $tax );
				if ( is_array( $terms ) && $terms ) {
					$link = get_term_link( $terms[0] );
					$cat  = is_wp_error( $link ) ? '' : (string) $link;
				}
			}
			self::add(
				array(
					'kind'  => 'post',
					'id'    => (int) $p->ID,
					'path'  => $path,
					'title' => wp_strip_all_tags( get_the_title( $p ) ),
					'to'    => $cat,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function term_before( $term_id, $taxonomy ): void {
		try {
			$tx = get_taxonomy( (string) $taxonomy );
			if ( ! $tx || ! $tx->public ) {
				return;
			}
			$link = get_term_link( (int) $term_id, (string) $taxonomy );
			if ( ! is_wp_error( $link ) ) {
				$n = RDR_Path::norm( (string) $link );
				if ( null !== $n ) {
					self::$term_old[ (int) $term_id ] = $n;
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function term_after( $term_id, $tt_id, $taxonomy ): void {
		try {
			$term_id = (int) $term_id;
			if ( ! isset( self::$term_old[ $term_id ] ) || self::in_glp_bulk() ) {
				return;
			}
			$old = self::$term_old[ $term_id ];
			unset( self::$term_old[ $term_id ] );
			clean_term_cache( $term_id, (string) $taxonomy );
			$link = get_term_link( $term_id, (string) $taxonomy );
			if ( is_wp_error( $link ) ) {
				return;
			}
			$new = RDR_Path::norm( (string) $link );
			if ( null === $new || $new === $old || '' === $old || RDR_Rules::find_exact( $old ) ) {
				return;
			}
			$term = get_term( $term_id, (string) $taxonomy );
			self::add(
				array(
					'kind'  => 'term',
					'id'    => $term_id,
					'path'  => $old,
					'title' => $term instanceof WP_Term ? $term->name : '',
					'to'    => (string) $link,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function add( array $item ): void {
		$item['time'] = time();
		$item['key']  = substr( md5( $item['kind'] . '|' . $item['path'] ), 0, 12 );
		$list         = self::pending();
		$list         = array_values(
			array_filter(
				$list,
				static function ( $x ) use ( $item ) {
					return $x['key'] !== $item['key'];
				}
			)
		);
		array_unshift( $list, $item );
		update_option( self::OPT, array_slice( $list, 0, self::MAX ), false );
	}

	public static function pending(): array {
		$l = get_option( self::OPT, array() );
		return is_array( $l ) ? array_values( array_filter( $l, 'is_array' ) ) : array();
	}

	public static function find( string $key ): ?array {
		foreach ( self::pending() as $x ) {
			if ( isset( $x['key'] ) && $x['key'] === $key ) {
				return $x;
			}
		}
		return null;
	}

	public static function dismiss( string $key ): void {
		$list = array_values(
			array_filter(
				self::pending(),
				static function ( $x ) use ( $key ) {
					return ! isset( $x['key'] ) || $x['key'] !== $key;
				}
			)
		);
		update_option( self::OPT, $list, false );
	}
}
