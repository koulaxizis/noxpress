<?php
/**
 * LIM_Abilities — Lean Images in the WordPress Abilities API, for an AI or
 * a remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability (manage_options) and the same code as the buttons:
 *
 *  lean-images/status     progress, space, last batch, settings, server
 *                         support, Imagify (read)
 *  lean-images/run-batch  one batch now (respects the lock and the budget)
 *  lean-images/pause      stops the background batches
 *  lean-images/resume     starts them again (never leaves test mode)
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Abilities {

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
		$none = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$defs = array(
			'status'    => array(
				'label'       => 'Lean Images: status',
				'description' => 'Progress of the WebP / AVIF conversion (images done, waiting, failed), disk space of the sidecars and the saving, the last batch (why it stopped), running / test mode / settings, which formats the server can write, LIM_DISABLE, and whether Imagify is active and serving its own images. Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'run-batch' => array(
				'label'       => 'Lean Images: run one batch',
				'description' => 'Converts the next waiting images now, with the batch size and time budget of the settings (also while paused). Returns the batch summary; busy = another batch is running. Original files are never changed.',
				'input'       => $none,
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'run_batch' ),
			),
			'pause'     => array(
				'label'       => 'Lean Images: pause',
				'description' => 'Stops the background batches. Images already converted keep being served.',
				'input'       => $none,
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'pause' ),
			),
			'resume'    => array(
				'label'       => 'Lean Images: resume',
				'description' => 'Starts the background batches again (every 5 minutes). Does not change test mode.',
				'input'       => $none,
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'resume' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'lean-images/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( Lean_Images::CAP );
	}

	public static function status( $in = null ): array {
		$s     = LIM_Settings::get();
		$stats = LIM_Queue::stats();
		$last  = get_option( LIM_Queue::LAST, array() );
		$space = array();
		foreach ( LIM_Files::FORMATS as $fmt ) {
			$src           = $stats[ 'src_' . $fmt ];
			$space[ $fmt ] = array(
				'sidecar_bytes' => $stats[ $fmt ],
				'source_bytes'  => $src,
				'saving_pct'    => $src > 0 ? round( 100 - 100 * $stats[ $fmt ] / $src, 1 ) : 0,
			);
		}
		return array(
			'version'   => LIM_VERSION,
			'running'   => $s['running'],
			'test_mode' => $s['test'],
			'killed'    => Lean_Images::killed(),
			'busy'      => LIM_Queue::busy(),
			'images'    => LIM_Queue::counts(),
			'files'     => $stats['files'],
			'space'     => $space,
			'last'      => is_array( $last ) ? $last : array(),
			'settings'  => $s,
			'server'    => array(
				'editor' => LIM_Convert::editor(),
				'webp'   => LIM_Convert::supports( 'webp' ),
				'avif'   => LIM_Convert::supports( 'avif' ),
			),
			'imagify'   => array(
				'active' => LIM_Tools::imagify_active(),
				'serves' => LIM_Tools::imagify_serves(),
			),
		);
	}

	public static function run_batch( $in = null ): array {
		if ( Lean_Images::killed() ) {
			return array( 'error' => 'LIM_DISABLE is set in wp-config.php.' );
		}
		return LIM_Queue::batch();
	}

	public static function pause( $in = null ): array {
		LIM_Queue::set_running( false );
		return array( 'running' => false );
	}

	public static function resume( $in = null ): array {
		if ( Lean_Images::killed() ) {
			return array( 'error' => 'LIM_DISABLE is set in wp-config.php.' );
		}
		LIM_Queue::set_running( true );
		return array( 'running' => true );
	}
}
