<?php
if (!defined('ABSPATH')) exit;

require(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/util.php');

class PluginSettings {
    private $token;
    public $instance;
    public $settings;

    public function __construct($token = 'orchestrated_campaignpress') { 
		$this->token = $token;
		$this->settings = get_option($this->token . '_plugin_settings', $this->makeSettings());
	}

    public function get($param) {
        $settings = get_option($this->token . '_plugin_settings', $this->makeSettings());
        if(isset($settings[$param]))
            return $settings[$param];

        return false;
    }

	public function set($param, $value) {
        $settings = $this->updateSetting($param, $value);
		$this->settings = $settings;
	}

    public function updateSetting($name, $value) {
        $settings = get_option($this->token . '_plugin_settings', $this->makeSettings());

		//  Update specific setting
		$settings[$name] = $value;

		//  Update plugin settings
		update_option($this->token . '_plugin_settings', $settings);

		$this->settings = $settings;

		//  Return the full settings object
		return $settings;
    }

    public function updateSettings($settings) {
		//  Update plugin settings
		update_option($this->token . '_plugin_settings', $settings);

		$this->settings = $settings;

		//  Return the full settings object
		return $settings;
    }

    public function makeSettings() {
		//	See if key is stored in env file
		//	This is for those who worry about storing keys in the db
		$apiKey = null;
		if(file_exists(ABSPATH . ".env")) {
			$dotenv = Dotenv\Dotenv::createImmutable(ABSPATH, '.env');
			$dotenv->load();
			$apiKey = $_ENV['MAILCHIMP_API_KEY'] ?? null;
		}
		if(!function_exists('wp_get_current_user'))
			include(ABSPATH . "wp-includes/pluggable.php");
        $currentUser = wp_get_current_user();
		$fromName = $currentUser->display_name ?? get_bloginfo('name');
		$fromEmail = $currentUser->user_email ?? get_bloginfo('admin_email');

		return [
			'version' => 1.0,
			'use_top_level_menu' => 1,
			'is_setup' => 0,
			'setup_type' => 'individual',
			'setup_step' => 'step_intro_1',
			'audiences' => [],
			'active_audience' => null,
			'api_key' => $apiKey,
			'toast_show_scheduled_campaigns' => 1,
			'show_debug' => 0,
			'plugin_root_dir' => plugin_dir_url(dirname(__FILE__)),
			'default_from_name' => $fromName,
			'default_from_email' => $fromEmail,
		];
	}

    public function verifySettings() {
		$freshSettings = $this->makeSettings();
		//	Checks if there are setting properties missing in the object, and inject ones that are not
		foreach($freshSettings as $key => $fs) {
			if(!isset($settings[$key])) {
				$settings[$key] = $freshSettings[$key];
			}
		}
		return $settings;
    }

    public function resetSettings() {
		$freshSettings = $this->makeSettings();
		$this->updateSettings($freshSettings);
		return $freshSettings;
    }
}