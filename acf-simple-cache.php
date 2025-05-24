<?php
/**
 * Plugin Name: Local JSON in uploads for ACF
 * Plugin URI: https://www.nextplugins.com/acf-simple-cache
 * Description: Boost ACF speed by enabling json caching
 * Version: 1.0.2
 * Author: NextPlugins
 * Requires at least: 4.4
 * Author URI: https://www.nextplugins.com
 * Text Domain: nextplugins-acf-sc
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if(!defined('ACFSC_UPLODS')) {
	$upload = wp_upload_dir();
	define('ACFSC_UPLODS', $upload['basedir'] . '/acf-json');
}

function Acfsc_dir_notice() {

	return '<div class="error notice"><p>' . __( 'ACF Simple Cache: Wordpress uploads folder is not writable. Please create folder with name "acf-json" in wordpress uploads folder.', 'nextplugins-acf-sc' ) . '</p></div>';
}

function Acfsc_Acf_Not_Active() {

	echo '<div class="error notice"><p>' . __( 'ACF Simple Cache: Advanced Custom Fields (or Advanced Custom Fields PRO) plugin not installed.', 'nextplugins-acf-sc' ) . '</p></div>';
}

function Acfsc_plugin_activation() {
	if ( ! is_dir(ACFSC_UPLODS)) {
		if( ! mkdir( ACFSC_UPLODS, 0700 ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				Acfsc_dir_notice()
			);
		} else {
			file_put_contents(ACFSC_UPLODS.'/index.php', "<?php \n // Silence is golden.");
		}
	}
}

register_activation_hook( __FILE__, 'Acfsc_plugin_activation' );

function NextPluginsAcfscCheckAcf() {
	if(in_array( 'advanced-custom-fields-pro/acf.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ))
	{
		return true;
	}

	if(in_array( 'advanced-custom-fields/acf.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ))
	{
		return true;
	}

	return false;
}

function Acfsc_acf_json_save_point( $path ) {

	// return
	return ACFSC_UPLODS;
}

function Acfsc_acf_json_load_point( $paths ) {

	// append path
	$paths[] = ACFSC_UPLODS;

	// return
	return $paths;
}

function NextPluginsInitAcfsc() {
	if ( NextPluginsAcfscCheckAcf() ) {
		add_filter('acf/settings/save_json', 'Acfsc_acf_json_save_point');
		add_filter('acf/settings/load_json', 'Acfsc_acf_json_load_point');
	} else {
		add_action( 'admin_notices', 'Acfsc_Acf_Not_Active' );
	}
}

add_action( 'plugins_loaded', 'NextPluginsInitAcfsc', 10 );