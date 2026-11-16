<?php
/**
 * PTB_Abilities — Product Tabs in the WordPress Abilities API, for an AI
 * or a remote tool that runs abilities (e.g. Site Bridge's ability_run),
 * with the same capability as the admin (manage_woocommerce). Read only:
 *
 *  product-tabs/list            mode, the shared tabs with their rules,
 *                               and one product's tabs when product_id
 *                               is given
 *  product-tabs/import-preview  what the import from "Custom Product Tabs
 *                               for WooCommerce" would bring
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$defs = array(
			'list'           => array(
				'label'       => 'Product Tabs: list tabs',
				'description' => 'The mode (off / test / live), the order setting of the known tabs, the shared tabs with their rules (all products, category ids, tag ids, product kinds, excluded ids), and, with product_id, that product\'s own tabs (title, type text / video, video URL, role trailer / presentation, priority, shown, shared with the other formats of its work, imported) and the shared tabs that match it. Content is returned as plain text, cut at 300 characters.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'product_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'run'         => array( __CLASS__, 'list_tabs' ),
			),
			'import-preview' => array(
				'label'       => 'Product Tabs: import preview',
				'description' => 'What the import from "Custom Product Tabs for WooCommerce" would bring, without writing anything: products with its data, products with tabs, tabs, videos, text tabs, tabs with [book_preview], tabs left out, products already imported, and whether that plugin is still active.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'run'         => array( __CLASS__, 'import_preview' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'product-tabs/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( Product_Tabs::CAP );
	}

	private static function plain( string $html ): string {
		$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		return PTB_Settings::clip( $t, 300 );
	}

	public static function list_tabs( $in = null ) {
		$in     = is_array( $in ) ? $in : array();
		$s      = PTB_Settings::get();
		$shared = array();
		foreach ( PTB_Settings::shared() as $t ) {
			$shared[] = array(
				'id'       => $t['id'],
				'title'    => $t['title_el'],
				'title_en' => $t['title_en'],
				'type'     => $t['type'],
				'video'    => PTB_Tabs::video_url( $t['video'] ),
				'text'     => self::plain( $t['content_el'] ),
				'priority' => $t['priority'],
				'on'       => $t['on'],
				'all'      => $t['all'],
				'cats'     => $t['cats'],
				'tags'     => $t['tags'],
				'kinds'    => $t['kinds'],
				'exclude'  => $t['exclude'],
			);
		}
		$out = array(
			'version'  => PTB_VERSION,
			'mode'     => $s['mode'],
			'disabled' => Product_Tabs::killed(),
			'order'    => $s['order'],
			'shared'   => $shared,
		);
		$pid = isset( $in['product_id'] ) ? (int) $in['product_id'] : 0;
		if ( $pid > 0 ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				return new WP_Error( 'ptb_no_product', 'No product with this id.' );
			}
			$data = PTB_Tabs::get( $pid );
			$tabs = array();
			foreach ( $data['tabs'] as $t ) {
				$tabs[] = array(
					'id'       => $t['id'],
					'title'    => $t['title'],
					'type'     => $t['type'],
					'video'    => PTB_Tabs::video_url( $t['video'] ),
					'role'     => $t['role'],
					'text'     => self::plain( $t['content'] ),
					'priority' => $t['priority'],
					'on'       => $t['on'],
					'share'    => $t['share'],
					'imported' => 'wb' === $t['src'],
				);
			}
			$match = array();
			foreach ( PTB_Tabs::shared_for( $p ) as $t ) {
				$match[] = array(
					'id'       => $t['id'],
					'hidden'   => in_array( $t['id'], $data['hide'], true ),
					'own_text' => isset( $data['over'][ $t['id'] ] ),
				);
			}
			$out['product'] = array(
				'id'       => $pid,
				'name'     => $p->get_name(),
				'imported' => $data['imported'],
				'tabs'     => $tabs,
				'shared'   => $match,
			);
		}
		return $out;
	}

	public static function import_preview( $in = null ): array {
		$p              = PTB_Import::preview();
		$p['wb_active'] = PTB_Import::wb_plugin_active();
		return $p;
	}
}
