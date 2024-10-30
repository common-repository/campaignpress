<?php

if (!defined('ABSPATH')) exit;

require(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/mailchimp.php');

use \DrewM\MailChimp\MailChimp;
use \DrewM\MailChimp\Webhook;

class CampaignPress {

    public $_name = "CampaignPress";
    public $_token = 'orchestrated_campaignpress';
    public $_version = '1.1.3';
    public $debugging = false;
	public $connector;
    public $isPluginSetup = false;
    public $pluginSettings;
	public $currentPage = "";
	public $isCampaignPressPage = false;
	public $isPostPage = false;
	public $toastMessages;
	public $screens = ["toplevel_page_orchestrated_campaignpress_general", "campaignpress_page_orchestrated_campaignpress_settings", "orchestrated_campaignpress_settings", "orchestrated_campaignpress_general", "settings_page_orchestrated_campaignpress_general", "settings_page_orchestrated_campaignpress_settings"];

	public function __construct () {
		//	Debugging if developing locally
		//if($_SERVER['SERVER_NAME'] === "localhost") $this->debugging = true;

		$this->pluginSettings = new PluginSettings();

		$this->currentPage = $_GET['page'] ?? "";
		$this->isCampaignPressPage = in_array($this->currentPage, $this->screens);

		// Initialise settings
		add_action('init', array($this, 'pluginInit'), 11);

		//	Meta box
    	add_action('add_meta_boxes', [$this, 'addMetabox']);

		//	REST API routes
    	add_action('rest_api_init', [$this, 'registerRestRoutes']);

		// Add settings page to menu
		add_action('admin_menu' , [$this, 'addMenuItem']);

		//	Notices for admin
		add_action('admin_notices', [$this, 'toastDisplay']);

		//	Handling page requests
    	add_action('parse_request', [$this, 'parseRequest']);
    	add_filter('query_vars', [$this, 'previewPageQuery']);

		//	Ensure script loads as module
    	add_filter('script_loader_tag', [$this, 'addTypeAttribute'] , 10, 3);

		//	Load scripts/style sheets
    	add_action('admin_enqueue_scripts', [$this, 'adminPluginAssets']);

		add_filter('manage_posts_columns' , [$this, 'postsAddColumn']);
		add_action('manage_posts_custom_column' , [$this, 'postsColumnContent'], 10, 2);

		add_filter('heartbeat_received', [$this, 'wpReceiveHeartbeat'], 1, 2);
		add_filter('heartbeat_send', [$this, 'wpSendHeartbeat'], 1, 2);
	}

	/* 
	
	---
	Action: pluginInit
	--- 
	Register settings for CampaignPress within WordPress generally
	
	*/

	public function pluginInit() {
		add_action('after_theme_setup', [$this, 'themeSetup']);
		add_action('admin_enqueue_scripts', [$this, 'loadEditorFiles']);
		add_image_size('campaignpress_section_thumbnail_medium', 0, 174*4, true, 'center', 'top');
		add_image_size('campaignpress_section_thumbnail_large', 0, 390*4, true, 'center', 'top');

		wp_enqueue_style('materialicons', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');

		$this->registerPluginEndPoints();

		$this->apiKey = $this->determineApiKey();
		if($this->apiKey) $this->connector = $this->determineConnector($this->apiKey);
		$this->isPluginSetup = $this->pluginSettings->get('is_setup') ?? false;

		$this->toastMessages = Util::toastMessages();
	}

	/* 

	---
	Action: addMenuItem
	--- 
	Adds a menu item link to WordPress admin

	*/

  	public function addMenuItem() {
		if(!$this->pluginSettings) $useTopLevelMenu = 1;
		else $useTopLevelMenu = $this->pluginSettings->get('use_top_level_menu');

		add_action('admin_print_styles-' . $this->_token, array($this, 'adminPluginAssets'));

		if($useTopLevelMenu === 1) {
			add_options_page(__($this->_name, $this->_token), __($this->_name, $this->_token), "manage_options", $this->_token . "_general", array($this, "menuGeneralHtml"));
			add_options_page(__("Settings", $this->_token), __("– Settings", $this->_token), "manage_options", $this->_token . "_settings", array($this, "menuSettingsHtml"));
		} else {
			add_menu_page($this->_name, $this->_name, "manage_options", $this->_token . "_general", array($this, "menuGeneralHtml"), plugin_dir_url(__FILE__) . "/../../dist/menu_icon.png", 20);
			add_submenu_page($this->_token . "_general", "{$this->_name} Settings", "Settings", "manage_options", $this->_token . "_settings", array($this, "menuSettingsHtml"));
		}
	}

	public function menuGeneralHtml() {
		echo $this->pageHtml();
	}

	public function menuSettingsHtml() {
		echo $this->pageHtml('settings');
	}
  
	/* 

	---
	Action: pageHtml
	--- 
	Provides markup necessary to display the pages for WP admin

	*/

	public function pageHtml($forcePageToShow = null) {
		wp_enqueue_style('materialicons', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');
			
		$pageToShow = str_replace($this->_token . "_", "", sanitize_text_field($_GET['page']));
		if(!$this->isPluginSetup) $pageToShow = "guided_setup";
		if($forcePageToShow) $pageToShow = "settings";
		$wpNonce = wp_create_nonce('wp_rest');
		$showDebug = $audienceActive = $this->pluginSettings->get('show_debug');

		$settingsPage = "<div class=\"wrap\">";
		$settingsPage .= "  <div id=\"campaignpress-panels\" class=\"campaignpress-ui-container\" data-page=\"{$pageToShow}\" data-nonce=\"{$wpNonce}\" data-show-debug=\"{$showDebug}\"></div>";
		$settingsPage .= "</div>";

		if($this->debugging && $this->isPluginSetup) {
			//
			//  For debugging
			//
			$audienceActive = $this->pluginSettings->get('active_audience');
			$audienceSettings = $this->connector->getAudienceSettings($audienceActive['id']);
			//
			$settingsPage .= "<div class=\"tw-bg-white tw-w-8/12 tw-p-4 tw-rounded-lg tw-relative tw-z-10 tw-shadow-md tw-border tw-border-t-0 tw-border-gray-300 tw-whitespace-pre-wrap\">";
			$settingsPage .= "<div class=\"tw-text-base tw-font-bold\">Plugin Settings</div>". json_encode($this->pluginSettings->settings, JSON_PRETTY_PRINT) . "</div>";

			$settingsPage .= "<div class=\"tw-mt-4 tw-bg-white tw-w-8/12 tw-p-4 tw-rounded-lg tw-relative tw-z-10 tw-shadow-md tw-border tw-border-t-0 tw-border-gray-300 tw-whitespace-pre-wrap\">";
			$settingsPage .= "<div class=\"tw-text-base tw-font-bold\">Active Audience Settings</div>". json_encode($audienceSettings, JSON_PRETTY_PRINT) . "</div>";
			//
		}

		return $settingsPage;
	}

	/* 

	---
	Action: adminPluginAssets
	--- 
	Register CSS/JS for settings screens

	*/

	public function adminPluginAssets() {
		wp_enqueue_media();
		wp_enqueue_editor();

		wp_enqueue_script('heartbeat');

		$screen = get_current_screen();
		$screenName = $screen->base;
		$this->isPostPage = $screenName === "post";

		wp_register_style('orchestrated-campaignpress-tailwind', plugin_dir_url(__FILE__) . '/../../dist/css/tailwind.css', array(), $this->_version);
		wp_enqueue_style('orchestrated-campaignpress-tailwind');

		if($this->isPostPage || $this->isCampaignPressPage) {
			wp_register_style('orchestrated-campaignpress-css', plugin_dir_url(__FILE__) . '/../../js/dist/app.css', array(), $this->_version);
			wp_enqueue_style('orchestrated-campaignpress-css');

			wp_register_script('orchestrated-campaignpress-app', plugin_dir_url(__FILE__) . '/../../js/dist/app.js', array('jquery'), $this->_version, true);
			wp_enqueue_script('orchestrated-campaignpress-app');
		}
  	}

	/* 
	
	---
	Action: addTypeAttribute
	--- 
	Register CSS/JS for settings screens
	
	*/

	public function addTypeAttribute($tag, $handle, $src) {
		if ('orchestrated-campaignpress-app' !== $handle) return $tag;
		$tag = '<script type="module" src="' . esc_url( $src ) . '"></script>';
		return $tag;
	}

	/* 

	---
	Action: install
	--- 
	Register CampaignPress during plugin installation

	*/

	public function install() {
		update_option($this->_token . '_version', $this->_version);

		$this->registerPluginEndPoints();
		$this->logSetup();
		Util::log("CampaignPress successful installed.", "campaignpress", "check_circle");
		flush_rewrite_rules();
	}

	/* 

	---
	Action: uninstall
	--- 
	Uninstall CampaignPress from WordPress

	*/

	public function uninstall() {
		$this->logRemove();
		flush_rewrite_rules();
	}

	/* 

	---
	Action: registerPluginEndPoints
	--- 
	Register plugin-specific pages, like the newsletter preview page

	*/

	public function registerPluginEndPoints() {
		add_rewrite_rule('campaignpress/preview', 'index.php?blackout=$matches[1]', 'top');
		add_rewrite_rule('campaignpress/mailchimp/webhook/([a-z0-9-]+)[/]?', 'index.php?audience_id=$matches[1]', 'top');
		add_permastruct('campaignpress', '/%campaignpress%');
	}

	/* 

	---
	Action: parseRequest
	--- 
	Parse request and display correct content

	*/

	public function parseRequest($query) {
		$pageUrl = $query->request;
		$params = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);

		//	User is opening a preview
		if($pageUrl == "campaignpress/preview") {
			echo $this->getEmailContentsAsHtml(null, true);
			exit;
		} else {
			//	Handling potential webhooks
			$urlSegments = explode("/", $pageUrl);

			//	Webhooks for Mailchimp
			if(count($urlSegments) > 1 && $urlSegments[1] === 'mailchimp') {
				if($urlSegments[3]) {
					$audienceId = $urlSegments[3];
					$handled = $this->connector->handleWebhook($audienceId, $params);
					if($handled) {
						$audienceSettings = $this->connector->getAudienceSettings($audienceId);
						$campaignId = $audienceSettings['campaign']['mc_id'];
						$this->wpAddHeartbeat('webhook:campaign:sent', ['connector' => 'mailchimp', 'audience_id' => $audienceId, 'campaign_id' => $campaignId]);
					}
					exit;
				}
			}
		}
	}

	/* 

	---
	Action: toastDisplay
	--- 
	Display messages to user

	*/

	public function toastDisplay() {
		if(!$this->isPluginSetup) return;

		$toastMessages = Util::toastMessages();

		foreach($toastMessages as $message) {
			printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $message['type'] ), $message['text']);

			if($message['disappear']) Util::toastRemoveMessage($message['id']);
		}

		//  Display queue #
		$this->toastDisplayQueue();
	}

	/* 

	---
	Action: toastDisplayQueue
	--- 
	Display a message that tells the user how many items are in queue

	*/

	public function toastDisplayQueue() {
		if(!$this->isPluginSetup) return;
		if(!$this->connector) return;
		if(!$this->pluginSettings->get('toast_show_scheduled_campaigns')) return;

    	$audiences = $this->connector->getAudiences();
    	$messages = "";

    	foreach($audiences as $audience) {
			$audienceId = $audience['id'];
			$audienceSettings = $this->connector->getAudienceSettings($audienceId);
			$audienceEmailScheduled = $audienceSettings['campaign']['email_scheduled'];
			$audienceState = $audienceSettings['state'];
			$audienceTitle = $audience['title'];
			$frequency = $audienceSettings['campaign']['email_frequency'];
			$frequencySettings = $audienceSettings['campaign']['email_frequency_settings'];
			$timezone = $audienceSettings['timezone'];
			
			if($audienceState === 1) {
				$audienceEmailScheduledDate = Util::nextSendDateTime($frequency, $frequencySettings, $timezone, false);
				$emailScheduledText = $audienceEmailScheduledDate->format('l F j \a\t h:ia');
				
				if(!$this->isCampaignPressPage) {
					$totalContentItems = Util::totalContentItems($audienceSettings);
					$previewLinkUrl = "/campaignpress/preview?audience_id=${audienceId}";
					$messages .= "<div class=\"tw-text-[12px] tw-flex tw-items-center\"><span class=\"tw-leading-none tw-border-l-2 tw-border-gray-400 tw-font-semibold tw-text-gray-500 tw-px-2 tw-justify-center\">${audienceTitle}</span> ";
					$messages .= "Next send scheduled for";
					$messages .= "&nbsp;<span class=\"tw-italic\">${emailScheduledText}</span>, ";
					$messages .= "with";
					$messages .= " ${totalContentItems} ";
					$messages .= "posts";
					$messages .= ".&nbsp;<a class=\"tw-font-bold tw-mx-2\" href=\"${previewLinkUrl}\" target=\"preview\">";
					$messages .= "Preview the email &raquo;";
					$messages .= "</a></div>\n\n";
				}
			}
	    }

    	if(strlen($messages) === 0) return;

		printf('
			<script>
			var xmlhttp = new XMLHttpRequest();
			xmlhttp.onreadystatechange = function() {
				console.log(xmlhttp.readyState);
			}

			function hideScheduleNotices() {
				alert(\'' . 'This will remove this display of your scheduled Campaigns, but you can always re-enable it in CampaignPress settings.' . '\');
				xmlhttp.open("POST", "/wp-json/campaignpress/v1/toast/hide", true);
				xmlhttp.send();
			}
			</script>

			<div class="notice notice-info tw-w-7/12 tw-py-2 tw-relative tw-flex tw-rounded-md">
				<div class="  tw-hidden    tw-absolute tw-right-3 tw-top-2 tw-text-2xl tw-cursor-pointer material-symbols-outlined" onclick="hideScheduleNotices()">close</div>
				<div class="tw-mr-2 tw-flex tw-align-top">
					<div class="tw-leading-none tw-border tw-border-gray-400 tw-text-gray-400 tw-px-1 tw-flex tw-items-center tw-justify-center tw-rounded-md">CampaignPress</div>
				</div>
				<div class="tw-flex tw-flex-col tw-justify-center tw-py-1 tw-gap-1">%1$s</div>
			</div>', 
			$messages);
  	}

	/* 

	---
	Route: hideToastMessages
	--- 
	Hide toast messages

	*/

	public function hideToastMessages() {
		//  Disable toast messages for scheduled campaigns
		if($this->pluginSettings->get('toast_show_scheduled_campaigns'))
			$this->pluginSettings->set('toast_show_scheduled_campaigns', false);
	}

	/* 
	
	---
	Action: getEmailContentsAsHtml
	--- 
	Return preview page markup
	
	*/

	public function getEmailContentsAsHtml($audienceId = null, $includeFrame = false) {
		if(!$audienceId) $audienceId = sanitize_text_field($_GET['audience_id']);

		if(!isset($audienceId)) {
			echo "Audience ID not specified";
			exit;
		}

		$audienceSettings = $this->connector->getAudienceSettings($audienceId);

		$contentToRender = "";
		$sections = $audienceSettings['queue']['sections'];
		$campaign = $audienceSettings['campaign'];
		$templateContent = $campaign['email_template']['template_content'];
		$templateWidthType = $campaign['email_template']['width_type'];
		
		switch($templateWidthType) {
			case "fixed":
				$templateWidth = "600px";
				break;
			case "fluid":
				$templateWidth = "90%";
				break;
		}

		if($includeFrame) {
			$contentToRender .= "<div style='display: flex; width: 70%; margin: auto; background: #e5e5e5; border-radius: 20px; min-height: 90vh;'>\n";
		}

		if($includeFrame) {
			$contentToRender .= " <div style='width: ${templateWidth}; margin: 20px auto; background: #ffffff; border-radius: 6px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); font-size: 100%;'>\n";
		} else {
			$contentToRender .= " <div style='width: ${templateWidth}; margin: auto; background: #ffffff; font-size: 100%;'>\n";
		}
		
		foreach($templateContent as $row)
			$contentToRender .= $this->previewRenderRow($row, $audienceSettings);

		$contentToRender .= " </div>\n";

		if($includeFrame)
			$contentToRender .= "</div>\n";
			
		$contentToRender .= "\n<style>\n";
		$contentToRender .= "* { font-family: Arial, sans-serif; }\n";
		$contentToRender .= "img { border-radius: 3px; }\n";
		$contentToRender .= "</style>\n"; 
		return $contentToRender;
	}

	/* 
	
	---
	Action: postsAddColumn
	--- 
	Add column to post listing
	
	*/

	public function postsAddColumn($columns) {
		$columns['campaignpress_in_campaign'] = 'CampaignPress';
		return $columns;
	}

	/* 
	
	---
	Action: postsColumnContent
	--- 
	Show if CampaignPress is in article
	
	*/

	public function postsColumnContent($column, $postId) {
		if ($column == 'campaignpress_in_campaign') {
			$found = $this->findPostInSections($postId);
			if($found) {
				$audience = $this->connector->getAudience($found['audience_id']);
				$audienceTitle = $audience['title'];
				$sectionTitle = $found['section_title'];
				echo __('Appears in ') . "<br><strong>" . $audienceTitle . "</strong>" . " (" . $sectionTitle . ")";
			}
		}
	}

	/* 
	
	---
	Action: previewRenderRow
	--- 
	Render a row in the template preview
	
	*/

	public function previewRenderRow($row, $audienceSettings = null) {
		if(!isset($row['type'])) return "";
		
		$rowType = $row['type'];

		switch($rowType) {
			case "text":
				return $this->previewRenderRowText($row);
				break;
			case "two_col":
				return $this->previewRenderRowTwoCol($row, $audienceSettings);
				break;
			case "image":
				return $this->previewRenderRowImage($row);
				break;
			case "scheduled_image":
				return $this->previewRenderRowScheduledImage($row);
				break;
			case "spacer":
				return $this->previewRenderRowSpacer($row);
				break;
			case "code":
				return $this->previewRenderRowCode($row);
				break;
			case "section":
				return $this->previewRenderRowSection($row, $audienceSettings);
				break;
			case "category":
				return $this->previewRenderRowCategory($row, $audienceSettings);
				break;
		}
	}

	/* 
	
	---
	Action: previewRenderRowText
	--- 
	Render a row in the template preview (text)
	
	*/

	public function previewRenderRowText($row) {
		$text = $row['text'];
		$cssId = $row['css_id'];

		$content = "<div id=\"$cssId\" style=\"box-sizing: border-box; margin: 0 20px; padding: 10px; font-size: 80%;\">";
		$content .= $text;
		$content .= "</div>";

		return $content;
	}

	/* 
	
	---
	Action: previewRenderRowCode
	--- 
	Render a row in the template preview (code)
	
	*/

	public function previewRenderRowCode($row) {
		$code = $row['code'];

		$content = $code;

		return $content;
	}

	/* 
	
	---
	Action: previewRenderRowSection
	--- 
	Render a row in the template preview (section)
	
	*/

	public function previewRenderRowSection($row, $audienceSettings) {
		if(!$audienceSettings) return "";
		if(!isset($row['section'])) return "";

		$sectionInCampaign = $row['section'];
		$sectionsInQueue = array_filter($audienceSettings['queue']['sections'], function($item) use ($sectionInCampaign) { return $sectionInCampaign['id'] === $item['id']; });
		$sectionInQueue = reset($sectionsInQueue);
		$items = $sectionInQueue['items'];
		$rangeTo = isset($row['range_to']) ? intval($row['range_to']) : 100;
		$rangeFrom = isset($row['range_from']) ? intval($row['range_from']) : 0;
		$layoutStyle = isset($row['layout_style']) ? $row['layout_style'] : 'large_first_thumb_rest';
		$currentItem = 0;
		$itemBlock = "";

		$itemBlock .= "<table cellpadding=10 cellspacing=0 border=0>";

		foreach($items as $item) {
			if($currentItem >= $rangeFrom && $currentItem < $rangeTo) {

				switch($layoutStyle) {
					case "large_first_thumb_rest":
						if(has_post_thumbnail($item['id'])) {
							if($currentItem === 0) $thumbnailUrl = get_the_post_thumbnail_url($item['id'], 'campaignpress_section_thumbnail_large');
							else $thumbnailUrl = get_the_post_thumbnail_url($item['id'], 'campaignpress_section_thumbnail_medium');
							$item['thumbnail_url'] = $thumbnailUrl;
						}

						$isFirst = $currentItem === 0;
						$isEven = (($currentItem + 1) % 2) === 1;

						if(!$isEven && !$isFirst) $itemBlock .= "<tr>\n";
						if($isFirst) $itemBlock .= " <td colspan='2' width='100%' valign='top'>\n";
						else $itemBlock .= " <td width='50%' valign='top'>\n";
					
						if(isset($item['thumbnail_url'])) {
							$itemBlock .= "   <a href='" . $item['link_to_content'] . "' style='display:block; padding-bottom:20px;' class='campaignpress-link'>\n";
							$itemBlock .= "    <img src='" . $item['thumbnail_url'] . "' alt='" . $item['title'] . "' width='100%' height='auto' class='campaignpress-image' />\n";
							$itemBlock .= "   </a>";
						}

						if(isset($item['link_to_content'])) $itemBlock .= "   <a href='" . $item['link_to_content'] . "' style='font-weight:bold;' class='campaignpress-link'>" . $item['title'] . "</a>\n";
						else $itemBlock .= $item['title'];

						$itemBlock .= "   <div style='font-size:95%;margin-top:10px'>\n";
						$itemBlock .= $item['excerpt'];
						$itemBlock .= "   </div>\n";
						$itemBlock .= "   <div style='font-size:95%;margin-top:10px'>\n";
						$itemBlock .= "    <a href='" . $item['link_to_content'] . "' style='font-weight:bold;' class='campaignpress-link'>READ MORE</a>\n";
						$itemBlock .= "   </div>\n";
						$itemBlock .= " </td>\n";

						if($isFirst || $isEven) $itemBlock .= " </tr>\n";
						break;

					case "thumb_all_cols":
						if(has_post_thumbnail($item['id'])) {
							$thumbnailUrl = get_the_post_thumbnail_url($item['id'], 'campaignpress_section_thumbnail_medium');
							$item['thumbnail_url'] = $thumbnailUrl;
						}
						
						$isEven = (($currentItem + 1) % 2) === 1;

						if($isEven) $itemBlock .= "<tr>\n";
						$itemBlock .= " <td width='50%' valign='top'>\n";
					
						if(isset($item['thumbnail_url'])) {
							$itemBlock .= "   <a href='" . $item['link_to_content'] . "' style='display:block; padding-bottom:20px;' class='campaignpress-link'>\n";
							$itemBlock .= "    <img src='" . $item['thumbnail_url'] . "' alt='" . $item['title'] . "' width='100%' height='auto' class='campaignpress-image' />\n";
							$itemBlock .= "   </a>";
						}

						if(isset($item['link_to_content'])) $itemBlock .= "   <a href='" . $item['link_to_content'] . "' style='font-weight:bold;' class='campaignpress-link'>" . $item['title'] . "</a>\n";
						else $itemBlock .= $item['title'];

						$itemBlock .= "   <div style='font-size:95%;margin-top:10px'>\n";
						$itemBlock .= $item['excerpt'];
						$itemBlock .= "   </div>\n";
						$itemBlock .= "   <div style='font-size:95%;margin-top:10px'>\n";
						$itemBlock .= "    <a href='" . $item['link_to_content'] . "' style='font-weight:bold;' class='campaignpress-link'>READ MORE</a>\n";
						$itemBlock .= "   </div>\n";
						$itemBlock .= " </td>\n";

						if(!$isEven) $itemBlock .= " </tr>\n";

						break;

					case "thumb_all_rows":
						if(has_post_thumbnail($item['id'])) {
							$thumbnailUrl = get_the_post_thumbnail_url($item['id'], 'campaignpress_section_thumbnail_medium');
							$item['thumbnail_url'] = $thumbnailUrl;
						}
						
						$itemBlock .= "<tr>\n";
					
						if(isset($item['thumbnail_url'])) {
							$itemBlock .= " <td width='35%' valign='top'>\n";
							$itemBlock .= "   <a href='" . $item['link_to_content'] . "' style='display:block; padding-bottom:0px;' class='campaignpress-link'>\n";
							$itemBlock .= "    <img src='" . $item['thumbnail_url'] . "' alt='" . $item['title'] . "' width='100%' height='auto' class='campaignpress-image' />\n";
							$itemBlock .= "   </a>";
							$itemBlock .= " </td>\n";
						}
						
						$itemBlock .= " <td width='65%' valign='top'>\n";

						if(isset($item['link_to_content'])) $itemBlock .= "   <a href='" . $item['link_to_content'] . "' style='font-weight:bold;' class='campaignpress-link'>" . $item['title'] . "</a>\n";
						else $itemBlock .= $item['title'];

						$itemBlock .= "   <div style='font-size:95%;margin-top:10px'>\n";
						$itemBlock .= $item['excerpt'];
						$itemBlock .= "   </div>\n";
						$itemBlock .= "   <div style='font-size:95%;margin-top:10px'>\n";
						$itemBlock .= "    <a href='" . $item['link_to_content'] . "' style='font-weight:bold;' class='campaignpress-link'>READ MORE</a>\n";
						$itemBlock .= "   </div>\n";

						$itemBlock .= " </td>\n";
						$itemBlock .= " </tr>\n";

						break;
				}
			}

			$currentItem++;
		}

		$itemBlock .= "</table>";

		return $itemBlock;
	}

	/* 
	
	---
	Action: previewRenderRowCategory
	--- 
	Render a row in the template preview (section)
	
	*/

	public function previewRenderRowCategory($row, $audienceSettings) {
		if(!$audienceSettings) return "";
		if(!isset($row['category'])) return "";

		$category = $row['category'];
		$categorySlug = $category['value'];
		$categoryO = get_term_by('slug', $categorySlug, 'category');
		$categoryId = $categoryO->term_id;
		$rangeTo = isset($row['range_to']) ? intval($row['range_to']) : 100;
		$includeDays = isset($row['include_days']) ? intval($row['include_days']) : 100; 
		$currentItem = 0;
		$itemBlock = "";

		$daysDateTime = (new DateTime())->modify("-${includeDays} days");
		$daysDate = $daysDateTime->format("Y-m-d h:m:s");

		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT DISTINCT ID 
			FROM {$wpdb->posts} as p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
    		INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE post_status = 'publish' 
				AND post_date >= %s 
				AND tt.taxonomy = 'category' 
    			AND tt.term_id = %d 
			LIMIT %d"
		, $daysDate, $categoryId, $rangeTo);

		$results = $wpdb->get_results($query);

		foreach($results as $result) {
			$post = get_post($result->ID);
			$postUrl = $this->getWPPermalink($post->ID);
			$postTitle = $post->post_title;
			$postExcerpt = get_the_excerpt($post->ID);
			$itemBlock .= "<div style='padding: 10px; box-sizing: border-box; margin: 0 20px;'>";
			$itemBlock .= " <div style='font-weight: bold; margin-bottom: 10px;'>";

			$itemBlock .= "<a href='" . $postUrl . "' class='campaignpress-link'>" . $postTitle . "</a>";

			$itemBlock .= " </div>";
			$itemBlock .= " <div style='font-size: 70%;'>";
			$itemBlock .= $postExcerpt;
			$itemBlock .= " </div>";
			$itemBlock .= " <div style='border-bottom: 1px solid #e4e4e4; height: 2px; margin-top: 18px;'></div>";
			$itemBlock .= "</div>";

			$currentItem++;
		}

		return $itemBlock;
	}

	/* 
	
	---
	Action: previewRenderRowTwoCol
	--- 
	Render a row in the template preview (two col)
	
	*/

	public function previewRenderRowTwoCol($row, $audienceSettings) {
		$label = $row['label'];
		$columns = $row['columns'];
		$columnLeft = $columns['left'];
		$columnRight = $columns['right'];
		$leftContent = $this->previewRenderRow($columnLeft, $audienceSettings);
		$rightContent = $this->previewRenderRow($columnRight, $audienceSettings);

		$content = "";
		$content .= "<style>";
		$content .= " p { margin-top: 0; }";
		$content .= "</style>";
		$content .= "<div style=\"width: 100%;\">";
		$content .= " <div style=\"width: 50%; float: left;\">";
		$content .= $leftContent;
		$content .= " </div>";
		$content .= " <div style=\"width: 50%; float: left;\">";
		$content .= $rightContent;
		$content .= " </div>";
		$content .= " <div style=\"clear: both;\"></div>";
		$content .= "</div>";

		return $content;
	}

	/* 
	
	---
	Action: previewRenderRowSpacer
	--- 
	Render a row in the template preview (spacer)
	
	*/

	public function previewRenderRowSpacer($row) {
		$label = $row['label'];
		$spacerHeight = $row['height'];

		$content = "<div style=\"width: 100%; height: ${spacerHeight}px;\">&nbsp;</div>";

		return $content;
	}

	/* 
	
	---
	Action: previewRenderRowImage
	--- 
	Render a row in the template preview (image)
	
	*/

	public function previewRenderRowImage($row) {
		$imageUrl = $row['image']['url'];
		$imageAlignment = isset($row['image_alignment']) ? $row['image_alignment'] : null;
		$imageWidth = isset($row['image_width']) ? $row['image_width'] : null;
		$imageLink = isset($row['image_link']) ? $row['image_link'] : null;
		$imageWidthTag = "80%";
		$imageMarginTag = "0 auto";

		if(isset($imageWidth['title'])) {
			switch($imageWidth['title']) {
				case "Full":
					$imageWidthTag = "100%";
				break;
				case "Medium":
					$imageWidthTag = "60%";
				break;
				case "Small":
					$imageWidthTag = "30%";
				break;
				case "Extra Small":
					$imageWidthTag = "10%";
				break;
			}
		}

		if(isset($imageAlignment['h']['title'])) {
			switch($imageAlignment['h']['title']) {
				case "Left":
					$imageMarginTag = "0 auto 0 0";
				break;
				case "Center":
					$imageMarginTag = "0 auto";
				break;
				case "Right":
					$imageMarginTag = "0 0 0 auto";
				break;
			}
		}

		if($imageUrl && $imageUrl !== "") {
			$padding = '10px 10px 10px';
			if($imageWidth['title'] === 'Full') $padding = '0';
			$content = "";
			$content .= '<div style="width: 100%; display: flex; box-sizing: border-box; padding: ' . $padding . ';">';

			if(isset($imageLink) && $imageLink !== "")
				$content .= '<a href="' . $imageLink . '" style="width: 100%; display: flex;" class="campaignpress-link">';

			$content .= ' <img src="' . $imageUrl . '" width="' . $imageWidthTag . '" height="auto" style="margin: ' . $imageMarginTag . '; padding: 0px; box-sizing: border-box;" class="campaignpress-image" />';

			if(isset($imageLink) && $imageLink !== "")
				$content .= '</a>';
		
			$content .= '</div>';

			return $content;
		} else
			return "";
	}

	/* 
	
	---
	Action: previewRenderRowScheduledImage
	--- 
	Render a row in the template preview (scheduled_image)
	
	*/

	public function previewRenderRowScheduledImage($row) {
		$activeDay = strtolower((new DateTime())->format('l'));
		$imageToShow = $row['images'][$activeDay]['attachment'] ? $row['images'][$activeDay] : $row['images']['default'];
		$imageUrl = isset($imageToShow['attachment']) ? $imageToShow['attachment']['url'] : null;
		$imageAlignment = isset($imageToShow['hAlign']) ? $imageToShow['hAlign'] : null;
		$imageWidth = isset($imageToShow['width']) ? $imageToShow['width'] : null;
		$imageLink = isset($imageToShow['link']) ? $imageToShow['link'] : null;
		$imageWidthTag = "80%";
		$imageMarginTag = "0 auto";

		if(isset($imageWidth['title'])) {
			switch($imageWidth['title']) {
				case "Full":
					$imageWidthTag = "100%";
				break;
				case "Medium":
					$imageWidthTag = "60%";
				break;
				case "Small":
					$imageWidthTag = "30%";
				break;
				case "Extra Small":
					$imageWidthTag = "10%";
				break;
			}
		}

		if(isset($imageAlignment['h']['title'])) {
			switch($imageAlignment['h']['title']) {
				case "Left":
					$imageMarginTag = "0 auto 0 0";
				break;
				case "Center":
					$imageMarginTag = "0 auto";
				break;
				case "Right":
					$imageMarginTag = "0 0 0 auto";
				break;
			}
		}

		if($imageUrl && $imageUrl !== "") {
			$content = "";
			$content .= '<div style="width: 100%; display: flex; box-sizing: border-box; padding: 20px 30px 10px;">';

			if(isset($imageLink) && $imageLink !== "")
				$content .= '<a href="' . $imageLink . '" style="width: 100%; display: flex;" class="campaignpress-link">';

			$content .= ' <img src="' . $imageUrl . '" width="' . $imageWidthTag . '" height="auto" style="margin: ' . $imageMarginTag . '; padding: 0px; box-sizing: border-box;" class="campaignpress-image" />';

			if(isset($imageLink) && $imageLink !== "")
				$content .= '</a>';
		
			$content .= '</div>';

			return $content;
		} else
			return "";
	}

	/* 
	
	---
	Action: previewPageQuery
	--- 
	Prepare query strong for CampaignPress
	
	*/

	public function previewPageQuery($queryVars) {
		$queryVars[] = 'campaignpress';
		return $queryVars;
	}

	/* 
	
	---
	Action: metaboxMarkup
	--- 
	Provides markup to display metabox within pages and posts
	
	*/

	public function metaboxMarkup() {
		global $post;

		if(!$this->connector)
			echo "CampaignPress can't connect to your Mailchimp account. Visit the plugin's settings to correct this issue.";
		
		wp_enqueue_style('materialicons', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');
		
		$postId = $post->ID;
		$postTitle = esc_html($post->post_title);
		$postExcerpt = get_the_excerpt($post->ID) ?? "";
		$postStatus = get_post_status($post->ID) ?? "draft";
		$wpNonce = wp_create_nonce('wp_rest');
	
		echo "<div id=\"campaignpress-metabox\" class=\"campaignpress-ui-container\" data-nonce=\"{$wpNonce}\"></div>\n" . 
			"<input type=\"hidden\" id=\"campaignpress-post-id\" value=\"{$postId}\" />\n" . 
			"<input type=\"hidden\" id=\"campaignpress-post-status\" value=\"{$postStatus}\" />\n" . 
			"<input type=\"hidden\" id=\"campaignpress-post-title\" value=\"{$postTitle}\" />\n" .
			"<input type=\"hidden\" id=\"campaignpress-post-excerpt\" value=\"{$postExcerpt}\" />";
	}

	/* 
	
	---
	Action: addMetabox
	--- 
	Integrates CampaignPress within posts and pages via `metaboxMarkup`
	
	*/
	
	public function addMetabox() {
		add_meta_box("campaignpress-metabox-container", "CampaignPress", [$this, "metaboxMarkup"], 'post', 'side', 'high', null);
	}

	/* 
	
	---
	Route Registration
	--- 
	Creating routes within WordPress
	
	*/

	public function registerRestRoutes() {
		register_rest_route('campaignpress/v1', '/settings', array('methods' => 'GET', 'callback' => [$this, 'getSettings'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/settings', array('methods' => 'POST', 'callback' => [$this, 'storeSettings'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/settings/logs', array('methods' => 'GET', 'callback' => [$this, 'getLogs'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/campaigns/remove', array('methods' => 'POST', 'callback' => [$this, 'campaignsRemove'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/templates/remove', array('methods' => 'POST', 'callback' => [$this, 'templatesRemove'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences', array('methods' => 'GET', 'callback' => [$this, 'audiences'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/reset', array('methods' => 'POST', 'callback' => [$this, 'audiencesReset'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/webhook_registration', array('methods' => 'POST', 'callback' => [$this, 'audienceWebhookRegistration'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/(?P<audience_id>[a-zA-Z0-9-]+)', array('methods' => 'GET', 'callback' => [$this, 'getAudienceSettings'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/(?P<audience_id>[a-zA-Z0-9-]+)', array('methods' => 'POST', 'callback' => [$this, 'storeAudienceSettings'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/(?P<audience_id>[a-zA-Z0-9-]+)/sections', array('methods' => 'GET', 'callback' => [$this, 'getSectionsForAudience'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/(?P<audience_id>[a-zA-Z0-9-]+)/preview', array('methods' => 'POST', 'callback' => [$this, 'sendPreview'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/audiences/(?P<audience_id>[a-zA-Z0-9-]+)/template', array('methods' => 'POST', 'callback' => [$this, 'updateAudienceTemplate'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/content', array('methods' => 'GET', 'callback' => [$this, 'getWPContent'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/content/(?P<post_id>[0-9-]+)', array('methods' => 'GET', 'callback' => [$this, 'getWPPost'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/metabox', array('methods' => 'GET', 'callback' => [$this, 'getWPMetaboxData'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/metabox', array('methods' => 'POST', 'callback' => [$this, 'updateWPMetaboxData'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/metabox', array('methods' => 'DELETE', 'callback' => [$this, 'removeWPMetaboxData'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/reset', array('methods' => 'POST', 'callback' => [$this, 'resetCampaignPress'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/toast/hide', array('methods' => 'POST', 'callback' => [$this, 'hideToastMessages'], 'permission_callback' => [$this, 'routePermissions']));

		register_rest_route('campaignpress/v1', '/validate-mailchimp-api-key', array('methods' => 'POST', 'callback' => [$this, 'validateMailchimpApiKey'], 'permission_callback' => [$this, 'routePermissions']));
		register_rest_route('campaignpress/v1', '/remove-mailchimp-api-key', array('methods' => 'POST', 'callback' => [$this, 'removeMailchimpApiKey'], 'permission_callback' => [$this, 'routePermissions']));
		
		register_rest_route('campaignpress/v1', '/categories', array('methods' => 'GET', 'callback' => [$this, 'getCategories'], 'permission_callback' => [$this, 'routePermissions']));
	}

	/* 
	
	---
	Route Permissions: routePermissions
	--- 
	Used to determine who can access rest routes
	
	*/

	public function routePermissions(WP_REST_Request $request) {
		return current_user_can('manage_options');
	}

	/* 
	
	---
	Route: getSettings
	--- 
	Used to get the plugin settings for CampaignPress and return them to the UI
	
	*/

	public function getSettings(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		$response['data'] = $this->pluginSettings->settings;
		$response['server_date_time'] = new DateTime();
		$response['message']['settings'] = "Plugin settings have been retrieved";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: storeSettings
	--- 
	Used to update plugin settings for CampaignPress
	*/

	public function storeSettings(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		//  Get plugin settings
		$existingSettings = $this->pluginSettings->settings;

		//  Store active audience to compare later
		$activeAudience = $existingSettings['active_audience'];
		$audienceSettings = $activeAudience ? $this->connector->getAudienceSettings($activeAudience['id']) : null;

		$existingSettings['use_top_level_menu'] = intval($request->get_param('use_top_level_menu'));
		$existingSettings['toast_show_scheduled_campaigns'] = intval($request->get_param('toast_show_scheduled_campaigns'));
		$existingSettings['show_debug'] = intval($request->get_param('show_debug'));
		$existingSettings['plugin_root_dir'] = plugin_dir_url(dirname(__FILE__));
		if($request->get_param('setup_type')) $existingSettings['setup_type'] = $request->get_param('setup_type');
		if($request->get_param('setup_step')) $existingSettings['setup_step'] = $request->get_param('setup_step');
		if($request->get_param('is_setup')) $existingSettings['is_setup'] = $request->get_param('is_setup');
		if($request->get_param('active_audience')) $existingSettings['active_audience'] = $request->get_param('active_audience');
		if($request->get_param('default_from_name')) $existingSettings['default_from_name'] = $request->get_param('default_from_name');
		if($request->get_param('default_from_email')) $existingSettings['default_from_email'] = $request->get_param('default_from_email');

		$this->pluginSettings->updateSettings($existingSettings);
		
		$response['data']['audience'] = $audienceSettings;
		$response['data']['settings'] = $existingSettings;
		$response['message']['settings'] = "Plugin settings have been updated";
		
		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getLogs
	--- 
	Used to get the plugin logs for CampaignPress, Mailchimp and return them to the UI
	
	*/

	public function getLogs(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		global $wpdb;
		$tableName = $wpdb->prefix . "campaignpress_logs"; 

		$result = $wpdb->get_results("
			SELECT * 
			FROM $tableName
			ORDER BY id DESC
			LIMIT 200;");
		
		$response['data'] = $result;
		$response['message']['settings'] = "Plugin settings have been retrieved";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getAudienceSettings
	--- 
	Used to get settings for a particular Mailchimp Audience
	
	*/

	public function getAudienceSettings(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$audienceId = $request->get_param('audience_id');

		//	Check for updated canonical urls (get_the_permalink)
		$this->updatePostUrlsInSections($audienceId);

		$audience = $this->connector->getAudience($audienceId);
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);
		$audienceScheduledTime = $audienceSettings['campaign']['email_scheduled'];

		//  Only registers if it doesn't exist
		$this->connector->registerWebhook($audienceId, $audienceSettings);

		$response['data']['audience_settings'] = $audienceSettings;
		$response['message']['audience_settings'] = "Audience settings for {$audience['title']} returned";

		return rest_ensure_response($response);
	}
	
	/* 
	
	---
	Route: storeAudienceSettings
	--- 
	Used to store settings for a particular Mailchimp Audience
	
	*/

	public function storeAudienceSettings(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		$audienceId = $request->get_param('audience_id');
		
		if(!$audienceId) return;

		//	Get audience/settings
		$audience = $this->connector->getAudience($audienceId);
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);
		
		$audienceState = $audienceSettings['state']; //  Campaign state

		//  Update audience settings with new values
		if($request->has_param('state')) $audienceSettings['state'] = intval($request->get_param('state'));
		if($request->has_param('active_editor_tab')) $audienceSettings['active_editor_tab'] = $request->get_param('active_editor_tab');
		if($request->has_param('queue')) $audienceSettings['queue'] = $request->get_param('queue');
		if($request->has_param('campaign')) $audienceSettings['campaign'] = $request->get_param('campaign');
		
		$audienceFrequency = $audienceSettings['campaign']['email_frequency_settings']; //  Campaign frequency
		$audienceEmailScheduled = $audienceSettings['campaign']['email_scheduled'];	//	Campaign scheduled date
		$audienceEmailSubject = $audienceSettings['campaign']['email_subject']; //  Campaign subject
		$audienceCampaignId = $audienceSettings['campaign']['mc_id']; //  Campaign ID
		$audienceCampaignFolderId = isset($audienceSettings['campaign']['mc_folder_id']) ? $audienceSettings['campaign']['mc_folder_id'] : null; //  Campaign Folder ID
		$audienceTemplateId = $audienceSettings['campaign']['email_template']['mc_id'];
		
		//	Load campaign/template (if they exist)
		$campaign = null;
		$template = null;

		if($audienceCampaignId && $audienceTemplateId) {
			$campaign = $this->connector->getCampaign($audienceCampaignId);
			$template = $this->connector->getTemplate($audienceTemplateId);
		}
		
		//	Content of email
		$emailHtml = $this->getEmailContentsAsHtml($audienceId, false);

		//	Campaign was deleted or some other issue
		if(!$campaign) {
			
			//	Clean up drafts
			$this->connector->clearCampaignDrafts($audienceId);

			//  Create campaign folder if it doesn't exist
            if(!$audienceCampaignFolderId) {
				$audienceName = $audience['title'];
				$folder = $this->connector->createCampaignFolder("${audienceName}");
				$audienceCampaignFolderId = $folder['id'];
			}
            
			if($audienceSettings['campaign'])
				$audienceSettings['campaign']['mc_folder_id'] = $audienceCampaignFolderId;

			//	Create template/campaign
			$template = $this->connector->createTemplate("CampaignPress-${audienceId}", $emailHtml);
			$campaign = $this->connector->createCampaign($audienceId, $audienceEmailSubject, false, $audienceCampaignFolderId);
			
			$audienceSettings['campaign']['mc_id'] = $campaign['id'];

			$audienceSettings['campaign']['email_template']['mc_id'] = $template['id'];
			$audienceSettings['campaign']['email_template']['mc_name'] = $template['name'];

			if($template && $campaign)
				$updatedContent = $this->connector->updateContentForCampaign($audienceId, $campaign, $template, false);

			//	Force re-caching
			$this->connector->getCampaigns(true);

		} else {

			//	Template was deleted or some other issue
			if(!$template) {

				$template = $this->connector->createTemplate("CampaignPress-${audienceId}", $emailHtml);

				$audienceSettings['campaign']['email_template']['mc_id'] = $template['id'];
				$audienceSettings['campaign']['email_template']['mc_name'] = $template['name'];

				if($template)
					$updatedContent = $this->connector->updateContentForCampaign($audienceId, $campaign, $template, false);

			}
		}

		$audienceCampaignId = $campaign['id'];

		//	Update subject with token real values
		$audienceEmailSubjectParsed = Util::parseTokens($audienceEmailSubject, (object)['audience_title' => $audience['title'], 'total_content_items' => Util::totalContentItems($audienceSettings)]);

		//  Remove items from template sections (they get copied from the queue)
		$sectionsWithoutItems = array_map(function($item) { unset($item['section']['items']); return $item; }, $audienceSettings['campaign']['email_template']['template_content']);
		$audienceSettings['campaign']['email_template']['template_content'] = $sectionsWithoutItems;

		//  Update audience/campaign with Mailchimp campaign id
		$this->connector->updateAudienceSettings($audienceId, $audienceSettings);
		
		$isFrequencyChanged = $audienceFrequency !== $audienceSettings['campaign']['email_frequency_settings'];
		$isAudienceStateChanged = intval($audienceState) !== intval($audienceSettings['state']);
		$isAudienceStateActive = intval($audienceSettings['state']) === 1;

		$response['data']['audience'] = $audience;
		$response['data']['audience_settings'] = $audienceSettings;
		$response['message']['audience'] = "Audience returned for {$audienceId}";
		$response['message']['audience_settings'] = "Audience Settings have been updated for {$audienceId}";

		Util::log("AudienceSettings successful updated for Audience {$audienceId}", "campaignpress", "check_circle");
		
		//  Scheduling
		if($isAudienceStateActive) {
			//  Is audience active, and did it change to active just now?
			//  Or, did the frequency change?
			if($isAudienceStateChanged || $isFrequencyChanged) {
				$didCampaignUpdate = $this->connector->updateCampaign($audienceCampaignId, $audienceId, $audienceEmailSubjectParsed);
				$didScheduleUpdate = $this->scheduleCampaign($audienceCampaignId, $audienceId);

				$response['data']['campaign_updated'] = $didCampaignUpdate;
				$response['data']['campaign_scheduled'] = $didScheduleUpdate;
				$response['message']['campaign_updated'] = "Campaign was updated";
				$response['message']['campaign_scheduled'] = "Campaign was scheduled";
			}

		} else if($audienceEmailScheduled || $isAudienceStateChanged) {

			// //  Schedule campaign if state has changed to true and did not have a previous schedule
			$didUnschedule = $this->unscheduleCampaign($audienceCampaignId, $audienceId);

			if($didUnschedule) {
				$response['data']['campaign_unscheduled'] = $didUnschedule;
				$response['message']['campaign_unscheduled'] = "Campaign was unscheduled";
			} else {
				$response['data']['campaign_unscheduled'] = $this->connector->lastError();
				$response['message']['campaign_unscheduled'] = "Campaign was not unscheduled";
			}

		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: validateMailchimpApiKey
	--- 
	Used to validate Mailchimp API key
	
	*/

	public function validateMailchimpApiKey(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		$parameters = $request->get_params();
		
		if(isset($parameters['api_key'])) {

			//	Verify API key
			if(MailchimpApi::verifyApiKey($parameters['api_key'])) {

				//  Update API key
				$this->pluginSettings->updateSetting('api_key', $parameters['api_key']);

				Util::toastRemoveMessage('invalid_mailchimp_api_key');

				//	Refresh audiences, with new API key
				$this->connector = $this->determineConnector($parameters['api_key']);
				$audiences = $this->connector->getAudiences(true);

				//	Set active audience to the first one available
				$firstActiveAudience = $audiences[0];
				$this->pluginSettings->set('active_audience', $firstActiveAudience);

				$response['data']['api_key'] = $parameters['api_key'];
				$response['message']['api_key'] = "MailChimp API Key was stored and validated";
				$response['message']['active_audience'] = "Mailchimp active audience has been changed to {$firstActiveAudience['title']}";

			} else {

				//	Failed verification
				$response['status'] = "ERROR";
				$response['data']['api_key'] = false;
				$response['message']['api_key'] = "MailChimp API Key was not validated";

			}

		} else {
			//	No API key provided
			$response['status'] = "ERROR";
			$response['data']['api_key'] = false;
			$response['message']['api_key'] = "MailChimp API Key was not valid";

		}
		
		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: removeMailchimpApiKey
	--- 
	Used to remove Mailchimp API key from CampaignPress
	
	*/

	public function removeMailchimpApiKey(WP_REST_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		update_option($this->_token . '_api_key', null);
		$response['message']['api_key'] = "MailChimp API Key was stored and validated";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: audiences
	--- 
	Used to return Mailchimp Audiences to the UI
	
	*/

	public function audiences(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$isForced = $request->get_param('force') == 'true' ? true : false;
		if($this->connector) {
			$audiences = $this->connector->getAudiences($isForced);
			$response['data']['audiences'] = $audiences;
			$response['message']['audiences'] = "MailChimp Audiences retrieved";

			$this->pluginSettings->set('audiences', $audiences);
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: audiencesReset
	--- 
	Advanced function used to reset audiences for debugging/testing, does not effect Mailchimp data
	
	*/

	public function audiencesReset(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		if($this->connector) {
			$audiences = $this->connector->getAudiences(true);
			foreach($audiences as $audience) {
				update_option($this->_token . '_audience_settings_' . $audience['id'], $this->connector->makeAudienceSettings());
			}
			$response['message']['audiences'] = "Audience data has been reset";
			$response['data']['audiences'] = $audiences;
		} else {
			$response['status'] = 'ERROR';
			$response['message']['audiences'] = "Audience data has not been reset";
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: audienceWebhookRegistration
	--- 
	Force webhooks to register
	
	*/

	public function audienceWebhookRegistration(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		if($this->connector) {
			$audiences = $this->connector->getAudiences(true);
			foreach($audiences as $audience) {
				$audienceSettings = $this->connector->getAudienceSettings($audience['id']);
				$this->connector->registerWebhook($audience['id'], $audienceSettings, true);	
			}
			$response['message']['audiences'] = "Webhooks were forcefully registered";
			$response['data']['audiences'] = $audiences;
		} else {
			$response['status'] = 'ERROR';
			$response['message']['audiences'] = "Webhooks could not be registered";
		}

		return rest_ensure_response($response);
	}
	
	/* 
	
	---
	Route: campaignsRemove
	--- 
	Advanced function used to remove campaigns for debugging/testing, only effects Mailchimp data
	
	*/

	public function campaignsRemove(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		if($this->connector) {
			$campaigns = $this->connector->getCampaigns();
			
			foreach($campaigns as $campaign) {
				//  Remove template
				if($campaign['settings']['template_id']) 
					$this->connector->removeTemplate($campaign['settings']['template_id']);

				//  Remove campaign
				$this->connector->removeCampaign($campaign['id']);
			}

			if($this->connector->getLastError()) {
				$response['status'] = 'ERROR';
				$response['message']['campaigns'] = "Campaign data has not been reset";
			} else {
				$response['data']['campaigns'] = array_map(function($item) { return $item['id']; }, $campaigns);
				$response['message']['campaigns'] = "Campaign data has been reset";
			}
		} else {
			$response['status'] = 'ERROR';
			$response['message']['campaigns'] = "Campaign data has not been reset";
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: templatesRemove
	--- 
	Advanced function used to remove campaigns for debugging/testing, only effects Mailchimp data
	
	*/

	public function templatesRemove(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		if($this->connector) {
			$templates = $this->connector->getTemplates();
			
			foreach($templates as $template) {
				$this->connector->removeTemplate($template['id']);
			}

			if($this->connector->getLastError()) {
				$response['status'] = 'ERROR';
				$response['message']['templates'] = $this->connector->getLastError();
			} else {
				$response['data']['templates'] = array_map(function($item) { return $item['id']; }, $templates);
				$response['message']['templates'] = "Template data has been reset";
			}
		} else {
			$response['status'] = 'ERROR';
			$response['message']['templates'] = "Template data has not been reset";
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getWPContent
	--- 
	Used to return WordPress content to the UI, via search, etc.
	
	*/

	public function getWPContent(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$terms = $request->get_param('terms');
		$postsToReturn = [];
		
		global $wpdb;

		if(strlen($terms) >= 3) {
			$query = $wpdb->prepare("SELECT DISTINCT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_status = 'publish' LIMIT 20", "%{$terms}%");
		} else {
			$query = $wpdb->prepare("SELECT DISTINCT ID FROM {$wpdb->posts} WHERE post_status = 'publish' ORDER BY post_date DESC LIMIT 10");
		}

		$results = $wpdb->get_results($query);

		foreach($results as $result) {
			$post = get_post($result->ID);
			$postsToReturn[] = [
				'id' => $post->ID,
				'created_at' => $post->post_date,
				'title' => $post->post_title,
				'excerpt' => wp_trim_words(wpautop($post->post_content), 20),
				'link_to_content' => $this->getWPPermalink($post),
				'keep_in_queue' => false,
			];
		}

		$response['data']['posts'] = $postsToReturn;
		$response['message']['posts'] = "Posts matching {$terms} were returned";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getWPPost
	--- 
	Used to return WordPress content to the UI, via search, etc.
	
	*/

	public function getWPPost(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$postId = $request->get_param('post_id');
		$post = get_post($postId);
		$post->post_excerpt = wp_trim_words(wpautop($post->post_content), 20);

		$response['data']['post'] = $post;
		$response['message']['post'] = "Post with ID {$postId} was returned";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getWPMetaboxData
	--- 
	Used to return data relevant to the metabox display.
	
	*/

	public function getWPMetaboxData(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		//  Get active audience
		$audienceActive = $this->pluginSettings->get('active_audience');

		//  Use it, unless another was specified
		$audienceId = $request->get_param('audience_id') ?? $audienceActive['id'];
		$sectionId = $request->get_param('section_id') ?? null;
		$postId = $request->get_param('id') ?? null;
		$post = get_post($postId);

		//  Has this post been used in a section already?
		$postData = $this->findPostInSections($postId, $audienceId);

		//  Post has been added to a section already
		if($postData) {
			//  We can get the audience and section from here
			$audienceId = $postData['audience_id'];
			$sectionId = $postData['section_id'];
		} else {
			//  Supply default post data so user can start with some data
			$postData = ['id' => $postId, 'title' => $post->post_title, 'excerpt' => get_the_excerpt($postId), 'keep_in_queue' => false, 'link_to_content' => $this->getWPPermalink($postId), 'existing' => false];
		}

		//	Return the postData with response
		$response['data']['post'] = $postData;

		//	If we got audienceId, return the sections and selected audience with response
		if($audienceId) {
			$audienceSettings = $this->connector->getAudienceSettings($audienceId);
			$audienceSections = $audienceSettings['queue']['sections'];
			$response['data']['sections'] = $audienceSections;
			$response['data']['audience_selected'] = $this->connector->getAudience($audienceId);
		}

		//	If we got both audience and section, return section selected with response
		if($audienceId && $sectionId) {
			$audienceSections = $audienceSettings['queue']['sections'];
			$audienceSectionsFiltered = array_filter($audienceSections, function($section) use ($sectionId) { return $section['id'] === $sectionId; });
			$audienceSectionSelected = reset($audienceSectionsFiltered);

			$response['data']['section_selected'] = $audienceSectionSelected;
		}

		//	Return available audiences with response
		$response['data']['audiences'] = $this->connector->getAudiences();

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: updateWPMetaboxData
	--- 
	Used to return data relevant to the metabox display.
	
	*/

	public function updateWPMetaboxData(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		if($request->get_param('section_posts_reordered')) {
			//	Check if this is just to reorder posts in a section
			$postsReOrdered = $request->get_param('section_posts_reordered');
			$postSection = $request->get_param('section');
			$audienceId = $request->get_param('audience_id');
			$audienceSettings = $this->connector->getAudienceSettings($audienceId);
			$sections = $audienceSettings['queue']['sections'];
			
			$sectionsUpdated = array_map(function($section) use ($postSection, $postsReOrdered) {
				if($section['id'] === $postSection['id'] && $postsReOrdered) {
					$section['items'] = $postsReOrdered;
				}
				return $section;
			}, $sections);
			
			$audienceSettings['queue']['sections'] = $sectionsUpdated;
			
			$response['data']['section_selected'] = $postSection;
			$response['message']['section_selected'] = "Section has been returned";
			
			$response['data']['section_posts_reordered'] = $postsReOrdered;
			$response['message']['section_posts_reordered'] = "Section posts have been reordered and returned";

			$audienceSettings = $this->connector->updateAudienceSettings($audienceId, $audienceSettings);
		} else {

			//	Normal save/update
			$audienceId = $request->get_param('audience_id') ?? null;
			$sectionId = $request->get_param('section_id') ?? null;
			$postId = $request->get_param('id') ?? null;
			$postTitle = $request->get_param('title') ?? "";
			$postExcerpt = $request->get_param('excerpt') ?? "";
			$postKeepInQueue = $request->get_param('keep_in_queue') ?? false;
			$postLinkToContent = $request->get_param('link_to_content') ?? $this->getWPPermalink($postId);
			$isItemNew = true;

			//	Remove the post from all sections first
			$audienceSettings = $this->removePostInSections($postId, $audienceId);

			$audienceSections = array_map(function($section) use($sectionId, $postId, $postTitle, $postExcerpt, $postKeepInQueue, $postLinkToContent) {
				//  If the section is the correct one, add the new item
				if($section['id'] == $sectionId) {
					$isItemNew = true;

					//  First see if this one already exists, update it or create it
					for($item = 0; $item < count($section['items']); $item++) {
						if($section['items'][$item]['id'] == $postId) {
							$section['items'][$item]['title'] = $postTitle;
							$section['items'][$item]['excerpt'] = $postExcerpt;
							$section['items'][$item]['keep_in_queue'] = $postKeepInQueue;
							$section['items'][$item]['link_to_content'] = $postLinkToContent;
							$isItemNew = false;
							break;
						}
					}

					//  Insert a new item if it doesn't already exist
					if($isItemNew) {
						$section['items'][] = [
							'id' => $postId,
							'title' => $postTitle,
							'excerpt' => $postExcerpt,
							'keep_in_queue' => $postKeepInQueue,
							'link_to_content' => $postLinkToContent,
						];
					}
				}

				return $section;
			}, $audienceSettings['queue']['sections']);

			$audienceSectionSelected = reset(array_filter($audienceSections, function($item) use ($sectionId) { return $item['id'] == $sectionId; }));

			$audienceSettings['queue']['sections'] = $audienceSections;

			$audienceSettings = $this->connector->updateAudienceSettings($audienceId, $audienceSettings);

			$response['data']['sections'] = $audienceSections;
			$response['message']['sections'] = "Sections for audience were returned";

			$response['data']['audience_selected'] = $this->connector->getAudience($audienceId);
			$response['message']['audience_selected'] = "Audience returned";

			$response['data']['section_selected'] = $audienceSectionSelected;
			$response['message']['section_selected'] = "Section returned";

			$response['data']['post'] = ['id' => $postId, 'title' => $postTitle, 'excerpt' => $postExcerpt, 'keep_in_queue' => $postKeepInQueue, 'link_to_content' => $postLinkToContent];
			$response['message']['post'] = "Post data returned";
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: removeWPMetaboxData
	--- 
	Used to remove a post from an audience's section
	
	*/

	public function removeWPMetaboxData(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$postId = $request->get_param('post_id') ?? null;
		$audienceId = $request->get_param('audience_id') ?? null;
		$wasRemoved = $this->removePostInSections($postId, $audienceId);

		if($wasRemoved) {
			$response['data']['audience_settings'] = $wasRemoved;
			$response['message']['audience_settings'] = "Post was removed from sections";
		} else {
			$response['data']['audience_settings'] = null;
			$response['message']['audience_settings'] = "Post was not removed from sections";
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: resetCampaignPress
	--- 
	Used to remove a post from an audience's section
	
	*/

	public function resetCampaignPress(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];
		
		delete_option($this->_token . '_plugin_settings');
		
		$audiences = $this->connector->getAudiences();

		foreach($audiences as $audience) {
			delete_option($this->_token . '_audience_settings_' . $audience['id']);
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getSectionsForAudience
	--- 

	*/

	public function getSectionsForAudience(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$audienceId = $request->get_param('audience_id');
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);
		$sections = $audienceSettings['queue']['sections'];
		$sectionsToReturn = [];
		
		foreach($sections as $section) {
			$sectionsToReturn[] = [
				'id' => $section['id'],
				'title' => $section['title'],
				'items' => count($section['items']),
			];
		}

		$response['data']['sections'] = $sectionsToReturn;
		$response['message']['sections'] = "Sections for {$audienceId} have been returned";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: getCategories
	--- 

	*/

	public function getCategories(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		//	WordPress function for getting categories
		$data = get_categories();
		$categories = [];
		
		foreach($data as $category) {
			$categories[] = [
				'id' => $category->slug,
				'cat_id' => $category->cat_ID,
				'title' => $category->name,
			];
		}
		
		$response['data'] = $categories;
		$response['message']['categories'] = "Categories have been returned";

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: sendPreview
	--- 

	*/

	public function sendPreview(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$audienceId = $request->get_param('audience_id');
		$campaign = $request->get_param('campaign');
		$emailHtml = $this->getEmailContentsAsHtml($audienceId, false);
		$audience = $this->connector->getAudience($audienceId);
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);
		$totalContentItems = Util::totalContentItems($audienceSettings);
		$subjectLine = Util::parseTokens($campaign['email_subject'], (object)['audience_title' => $audience['title'], 'total_content_items' => $totalContentItems]);
		$emailsToSend = $request->get_param('preview_email_addresses');

		//  Create temporary campaign on Mailchimp
		$createdMcCampaign = $this->connector->createCampaign($audienceId, $subjectLine, true);

		if($createdMcCampaign) {
			$response['message']['campaign'] = "Temp Campaign was created via Mailchimp API";

		//  Create template on Mailchimp and update campaign to use it
		$createdMcTemplate = $this->connector->createTemplate("CampaignPress-Preview-${audienceId}", $emailHtml);
		$updatedMcCampaign = $this->connector->updateContentForCampaign($audienceId, $createdMcCampaign, $createdMcTemplate, true);

		if($updatedMcCampaign) {
			$response['message']['template'] = "Temp Campaign was updated with Temp Template via Mailchimp API";  

			//  Send it to the test user
			$previewSent = $this->connector->sendPreview($createdMcCampaign['id'], $emailsToSend);

			if($previewSent) {
				$response['message']['preview'] = "Preview email was sent"; 
			} else {
				$response['status'] = "ERROR";
				$response['message']['preview'] = "Preview email was not sent"; 
			}
		} else {
			$response['status'] = "ERROR";
			$response['message']['template'] = "Temp Campaign was not updated with Temp Template via Mailchimp API";  
		}
		} else {
			$response['status'] = "ERROR";
			$response['message']['campaign'] = "Temp Campaign was not created via Mailchimp API";
		}

		//  Remove temp campaign and temp template
		$this->connector->removeTemplate($createdMcTemplate['id']);
		$this->connector->removeCampaign($createdMcCampaign['id']);

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Route: updateAudienceTemplate
	--- 

	*/

	public function updateAudienceTemplate(WP_Rest_Request $request) {
		$response = [
			'status' => 'OK',
			'data' => [],
			'message' => [],
		];

		$audienceId = $request->get_param('audience_id');
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);
		$audienceCampaignId = $audienceSettings['campaign']['mc_id'];
		$campaign = $this->connector->getCampaign($audienceCampaignId);
		$emailHtml = $this->getEmailContentsAsHtml($audienceId, false);
		$templateId = $audienceSettings['campaign']['email_template']['mc_id'];
		$template = $this->connector->getTemplate($templateId);

		//	Does not have a template
		if(!$template) {
			//	Create template and update campaign
			$template = $this->connector->createTemplate("CampaignPress-${audienceId}", $emailHtml);
			if($template && $campaign)
				$this->connector->updateContentForCampaign($audienceId, $campaign, $template, false);
		} else {
			//	Update campaign with template
			$this->connector->updateTemplate($template['id'], "CampaignPress-${audienceId}", $emailHtml);
			$this->connector->updateContentForCampaign($audienceId, $campaign, $template, false);
		}

		return rest_ensure_response($response);
	}

	/* 
	
	---
	Utility: scheduleCampaign
	--- 
	Schedule a campaign for sending at a later date

	*/

	private function scheduleCampaign($campaignId, $audienceId) {
		if($this->connector) {
			//	Audience
			$audience = $this->connector->getAudience($audienceId);
			$audienceSettings = $this->connector->getAudienceSettings($audienceId);
			$campaignState = intval($audienceSettings['state']);
			$audienceCampaignId = $audienceSettings['campaign']['mc_id'];

			//	Make sure that sections have updated canonical urls
			$this->updatePostUrlsInSections($audienceId);

			//	Parse subject line
			$subjectLine = $audienceSettings['campaign']['email_subject'];
			$subjectLine = Util::parseTokens($subjectLine, (object)['audience_title' => $audience['title'], 'total_content_items' => Util::totalContentItems($audienceSettings)]);
			
			//	Frequency
			$frequency = $audienceSettings['campaign']['email_frequency'];
			$frequencySettings = $audienceSettings['campaign']['email_frequency_settings'];

			//	Timezone/Date
			$timezone = $audienceSettings['timezone'];

			//	State
			$emailScheduled = $audienceSettings['campaign']['email_scheduled'];
			$wasScheduled = false;

			//  Only schedule if the campaign is active
			if($campaignState !== 1) return false;

			//	Get the next scheduled date / time
			$scheduleTimeConnector = Util::nextSendDateTime($frequency, $frequencySettings, $timezone, true);
			$scheduleTime = Util::nextSendDateTime($frequency, $frequencySettings, $timezone, true);

			//  Try to schedule campaign
			$campaign = $this->connector->getCampaign($audienceCampaignId);

			$wasScheduled = $this->connector->scheduleCampaign($campaignId, $scheduleTimeConnector); 

			if(!$wasScheduled) return false;

			//	Prepare email template
			$emailHtml = $this->getEmailContentsAsHtml($audienceId, false);

			//	Check for existing template
			$templateId = $audienceSettings['campaign']['email_template']['mc_id'];
			$templateName = $audienceSettings['campaign']['email_template']['mc_name'];

			//	Has a template
			if($templateId) {

				//	Get it and update it
				$template = $this->connector->getTemplate($templateId);

				//	We had a templateId, but it must not exist, so we will create another template
				if(!$template) $template = $this->connector->createTemplate("CampaignPress-${audienceId}", $emailHtml);

				else $this->connector->updateTemplate($template['id'], "CampaignPress-${audienceId}", $emailHtml);
				
				$audienceSettings['campaign']['email_template']['mc_id'] = $template['id'];
				$audienceSettings['campaign']['email_template']['mc_name'] = $template['name'];
	
				if($campaign && $template)
					$this->connector->updateContentForCampaign($audienceId, $campaign, $template, true);

			} else {

				$template = $this->connector->createTemplate("CampaignPress-${audienceId}", $emailHtml);

				$audienceSettings['campaign']['email_template']['mc_id'] = $template['id'];
				$audienceSettings['campaign']['email_template']['mc_name'] = $template['name'];

				if($campaign && $template)
					$this->connector->updateContentForCampaign($audienceId, $campaign, $template, true);

			}

			//  Update to show email is schedule for the specified time
			$audienceSettings['campaign']['mc_id'] = $campaign['id'];
			$audienceSettings['campaign']['email_scheduled'] = $scheduleTime;
			
			$this->connector->updateAudienceSettings($audienceId, $audienceSettings);

			return $audienceSettings;
		} else {
			//	Mailchimp not connected
			Util::toastAddMessage('api_issue', "Can't schedule ${campaignId}", true, "error");
			
			return false;
		}
	}

	/* 
	
	---
	Utility: unscheduleCampaign
	--- 
	Unschedule a campaign

	*/

	private function unscheduleCampaign($campaignId, $audienceId) {
		if(!$this->connector) {
			Util::toastAddMessage('api_issue', "Can't unschedule ${campaignId} with no connector", true, "error");
			return false;
		}

		//	Get audience, update schedule
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);
		$audienceSettings['campaign']['email_scheduled'] = null;
		$audienceSettings['campaign']['mc_id'] = null;
		$this->connector->updateAudienceSettings($audienceId, $audienceSettings);

		//	Post to connector
		$wasUnscheduled = $this->connector->unscheduleCampaign($campaignId);

		if($wasUnscheduled) return $wasUnscheduled;

		return false;
	}

	/* 

	---
	Utility: invalidateApiKey
	--- 
	Invalidate the API key, so that the user is notified and re-enters it

	*/

	private function invalidateApiKey() {
		$this->pluginSettings->set('api_key', null);
	}

	/* 

	---
	Utility: updatePostUrlsInSections
	--- 
	Refreshes the current URL (slug) of content items

	*/

	private function updatePostUrlsInSections($audienceId) {
		$audienceSettings = $this->connector->getAudienceSettings($audienceId);

		foreach($audienceSettings['queue']['sections'] as $section) {
			foreach($section['items'] as $item) {
				if($item['link_to_content'] !== get_the_permalink($item['id']))
					$item['link_to_content'] = get_the_permalink($item['id']);
			}
		}

		return $this->connector->updateAudienceSettings($audienceId, $audienceSettings);
	}

	/* 
	
	---
	Utility: findPostInSections
	--- 
	Find a post by post ID within an audience's sections

	*/

	private function findPostInSections($postId, $audienceId = null) {
		//  If the audience isn't defined, we will search all audiences
		if(!$audienceId) $audiences = $this->connector->getAudiences();
		else {
			//  Just the audience id
			$audience = $this->connector->getAudience($audienceId);
			$audiences = [$audience];
		}

		foreach($audiences as $audience) {
			$audienceSettings = $this->connector->getAudienceSettings($audience['id']);
			$foundItem = null;
			foreach($audienceSettings['queue']['sections'] as $section) {
				$items = $section['items'] ?? [];
				if($items) {
					$foundItem = array_filter($items, 
						function($item) use ($postId) { 
							return intval($item['id']) == intval($postId); 
						}
					);
				}
				
				if($foundItem) {
					$foundItem = reset($foundItem);
					$foundItem['existing'] = true;
					$foundItem['audience_id'] = $audience['id'];
					$foundItem['section_id'] = $section['id'];
					$foundItem['section_title'] = $section['title'];
					return $foundItem;
				}
			}
		}

		return false;
	}

	/* 

	---
	Utility: removePostInSections
	--- 
	Remove a post by post ID within an audience's sections

	*/

	private function removePostInSections($postId, $audienceId = null) {
		if(!$postId) return false;

		//  If the audience isn't defined, we will search all audiences
		if(!$audienceId) $audiences = $this->connector->getAudiences();
		else {
			//  Just the audience id
			$audience = $this->connector->getAudience($audienceId);
			$audiences = [$audience];
		}

		foreach($audiences as $audience) {
			$audienceSettings = $this->connector->getAudienceSettings($audience['id']);
			$audienceQueueSections = [];

			foreach($audienceSettings['queue']['sections'] as $section) {
				$section['items'] = array_values(array_filter($section['items'], 
					function($item) use ($postId) { 
						return intval($item['id']) !== intval($postId); 
					}
				));

				$audienceQueueSections[] = $section;
			}

			$audienceSettings['queue']['sections'] = $audienceQueueSections;

			$this->connector->updateAudienceSettings($audience['id'], $audienceSettings);

			return $audienceSettings;

		}

		return false;
	}

	/* 

	---
	Utility: logSetup
	--- 
	Setup logging

	*/

	private function logSetup() {
		global $wpdb;
		$tableName = $wpdb->prefix . "campaignpress_logs"; 
		$charsetCollate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $tableName (
			id mediumint(9) NOT NULL AUTO_INCREMENT, 
			time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, 
  			text text NOT NULL,
  			state varchar(55) DEFAULT 'info' NOT NULL,
  			context varchar(55) DEFAULT '' NOT NULL,
  			slug varchar(55) DEFAULT '' NOT NULL,
  			PRIMARY KEY  (id)
		) $charsetCollate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		add_option("campaignpress_db_version", "1.0");

		Util::log("LoggingTable successful created ${tableName}.", "campaignpress", "check_circle");
	}

	/* 

	---
	Utility: logRemove
	--- 
	Remove logging

	*/

	private function logRemove() {
		global $wpdb;
		$tableName = $wpdb->prefix . "campaignpress_logs"; 
		$charsetCollate = $wpdb->get_charset_collate();
		$sql = "DROP TABLE IF EXISTS $tableName;";
		$result = $wpdb->query($sql);

		delete_option("campaignpress_db_version");
	}

	/* 

	---
	Utility: getWPPermalink($postOrPostId)
	--- 
	Gets the permalink (for real)

	*/

	private function getWPPermalink($postOrPostId) {
		//	Determine if we're getting a whole post or just a post id
		if(is_numeric($postOrPostId)) $post = get_post($postOrPostId);
		else $post = $postOrPostId;

		//	WP will sometimes return domain.com?p=33434 instead of real permalink, so we will detect and resolve
		$permalink = get_the_permalink($post->ID);
		if(!!strpos($permalink, '?p=')) {
			$slug = $post->slug;
			$url = get_site_url() . $slug;
			return $url;
		} else {
			return $permalink;
		}
	}

	/* 

	---
	Filter: wpAddHeartbeat
	--- 
	Adds heartbeat data for next heartbeat

	*/

	function wpAddHeartbeat($type, $data) {
		Util::log("Heartbeat data added", "campaignpress", "check_circle");
		return update_option($this->_token . '_heartbeat_data', ['type' => $type, 'data' => $data]);
	}

	/* 

	---
	Filter: wpReceiveHeartbeat
	--- 
	Checks when heartbeat is received

	*/

	function wpReceiveHeartbeat($response, $data) {
		Util::log("Heartbeat data received", "campaignpress", "check_circle");
		
		// If we didn't receive our data, don't send any back.
		if(empty($data[$this->_token])) {
			return $response;
		}
	
		// Calculate our data and pass it back. For this example, we'll hash it.
		$receivedData = $data[$this->_token];
	
		$response[$this->_token] = sha1($receivedData);

		
		return $response;
	}

	/* 

	---
	Filter: wpSendHeartbeat
	--- 
	Inserts heartbeat data if any is queued

	*/

	function wpSendHeartbeat($response, $data) {
		$nextHeartbeatData = get_option($this->_token . '_heartbeat_data');

		if($nextHeartbeatData) {

			$asJson = json_encode($nextHeartbeatData);

			$response[$this->_token] = $asJson;

			delete_option($this->_token . '_heartbeat_data');

		}

		return $response;
	}

	//	Only using Mailchimp for now
	private function determineConnector($apiKey) {
		return new MailchimpApi($apiKey);
	}

	//	Only using Mailchimp for now
	private function determineApiKey() {
		return $this->pluginSettings->get('api_key');
	}

	public function loadEditorFiles() {
		wp_enqueue_media();
		wp_enqueue_editor();
	}

	public function themeSetup() {
		add_theme_support('disable-custom-colors');
		add_theme_support('disable-custom-font-sizes');
		add_theme_support('post-thumbnails');
	}
}

