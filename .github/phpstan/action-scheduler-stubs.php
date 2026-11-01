<?php
/**
 * Action Scheduler functions the suite calls (shipped with WooCommerce,
 * not part of php-stubs/woocommerce-stubs). Signatures from Action
 * Scheduler 3.x; only for PHPStan, never loaded by a plugin.
 */

/** @return int */
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

/** @return bool */
function as_has_scheduled_action( $hook, $args = null, $group = '' ) {}

/** @return int|bool */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {}

/** @return int */
function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

/** @return int */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

/** @return void */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {}

/** @return int|null */
function as_unschedule_action( $hook, $args = array(), $group = '' ) {}
