<?php
/**
 * Plugin Name: CampaignPress
 * Description: Easily add WordPress content to your next Mailchimp email campaign!
 * Author: Orchestrated
 * Author URI: http://www.orchestrated.ca
 * Version: 1.4
 * Requires at least: 4.0
 * Tested up to: 6.4.3
 *
 * Text Domain: orchestrated_campaignpress
 *
 * @package WordPress
 * @author Orchestrated
 * @since 1.0
 */

ini_set('display_errors', 1);

include_once(__DIR__ . '/includes/core.php');

//	New CampaignPress instance
$instance = new CampaignPress();

//	Install/uninstall plugin
register_activation_hook(__FILE__, [$instance, 'install']);
register_deactivation_hook(__FILE__, [$instance, 'uninstall']);
