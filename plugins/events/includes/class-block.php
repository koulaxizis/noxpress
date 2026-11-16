<?php
/**
 * EVT_Block — the «Events» block (noxpress/events), rendered on the server
 * with the same code as [nx_events]. The editor script is plain JS (no
 * build step): ServerSideRender with a few settings in the sidebar.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Block {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'evt-block',
			EVT_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			EVT_VERSION,
			true
		);
		wp_localize_script(
			'evt-block',
			'evtBlock',
			array(
				'title'    => __( 'Εκδηλώσεις', 'events' ),
				'desc'     => __( 'Λίστα επερχόμενων ή περασμένων εκδηλώσεων.', 'events' ),
				'count'    => __( 'Πλήθος', 'events' ),
				'past'     => __( 'Περασμένες (αντί για επερχόμενες)', 'events' ),
				'city'     => __( 'Πόλη (κενό = όλες)', 'events' ),
				'heading'  => __( 'Τίτλος (κενό = χωρίς)', 'events' ),
				'current'  => __( 'Μόνο του τρέχοντος προϊόντος ή δημιουργού', 'events' ),
				'settings' => __( 'Ρυθμίσεις', 'events' ),
			)
		);
		register_block_type(
			'noxpress/events',
			array(
				'api_version'     => 2,
				'editor_script'   => 'evt-block',
				'style'           => 'evt-front',
				'attributes'      => array(
					'count'   => array(
						'type'    => 'number',
						'default' => 5,
					),
					'past'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'city'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'title'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'current' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	public static function render( $attrs ): string {
		$a = is_array( $attrs ) ? $attrs : array();
		$cur = ! empty( $a['current'] );
		return EVT_Front::shortcode(
			array(
				'count'   => isset( $a['count'] ) ? (int) $a['count'] : 5,
				'past'    => ! empty( $a['past'] ) ? '1' : '0',
				'city'    => isset( $a['city'] ) ? (string) $a['city'] : '',
				'title'   => isset( $a['title'] ) ? (string) $a['title'] : '',
				'creator' => $cur && is_singular( 'nox_creator' ) ? 'current' : '',
				'product' => $cur && is_singular( 'product' ) ? 'current' : '',
			)
		);
	}
}
