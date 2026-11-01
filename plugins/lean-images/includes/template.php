<?php
/**
 * Lean Images — wrapper template for the whole-page pass on WordPress
 * before 6.9 (Bible §14.11). The theme's template is included here, at
 * file scope, so it keeps the global scope it expects.
 */

defined( 'ABSPATH' ) || exit;

LIM_Front::begin();
include LIM_Front::template();
LIM_Front::end();
