<?php
/**
 * Fast Pages — wrapper template (Bible §14.11). WordPress includes it in
 * place of the theme's template (FPG_Cache::wrap); the theme's template is
 * included here, at file scope, so it keeps the global scope it expects.
 */

defined( 'ABSPATH' ) || exit;

FPG_Cache::begin();
include FPG_Cache::template();
FPG_Cache::end();
