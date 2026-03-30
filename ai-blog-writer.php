<?php
/**
 * Plugin Name: AI Blog Writer
 * Plugin URI:  https://github.com/malcolmperalty/ai-blog-writer
 * Description: Test consumer for AI Context Registry. Generates blog post drafts using context from AICX and the WordPress AI Client SDK.
 * Version:     0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author:      Malcolm Peralty
 * Author URI:  https://malcolmperalty.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-blog-writer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', static function (): void {
	require_once __DIR__ . '/includes/class-generator.php';

	$generator = new AI_Blog_Writer\Generator();
	$generator->register_hooks();
} );
