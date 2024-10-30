<?php
if (!defined('ABSPATH')) exit;

require_once(__DIR__ . '/connector.php');
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/settings.php');

use \DrewM\MailChimp\MailChimp;
use \DrewM\MailChimp\Webhook;

class MailchimpApi extends Connector {
    private $driver;
    private $token;
    public $lastErrorStatus;
    public $lastErrorMessage;
    public $lastErrorDetails;
    public $apiKey;
    public $audiences;
    public $audiencesLast;
    public $campaigns;
    public $campaignLast;
    public $pluginSettings;

    public function __construct($apiKey, $token = 'orchestrated_campaignpress') {
        parent::__construct($apiKey, $token);
        $this->token = $token;
        $this->apiKey = $apiKey;
        $this->driver = new MailChimp($apiKey);
        $this->setup();
    }

    public static function verifyApiKey($apiKey) {
        try {
            $d = new MailChimp($apiKey);
            $listGroups = $d->get('lists');
            return true;
        } catch(\Throwable $e) {
            return false;
        }

        return false;
    }

    public function setup() {
        $this->campaignLast = get_option($this->token . '_cache_campaigns_last', time());
        $this->campaigns = get_option($this->token . '_cache_campaigns', []);
        $this->audienceLast = get_option($this->token . '_cache_audiences_last', time());
        $this->audiences = get_option($this->token . '_cache_audiences', []);

        $this->pluginSettings = new PluginSettings();
    }

    public function verifyConnection() {
        if(!$this->driver) return false;
        $listGroups = $this->driver->get('lists');
        if($this->driver->getLastError()) return false;
        Util::log("Connection to Mailchimp verified.", "mailchimp", "check_circle");
        return true;
    }

    // 
    //      Audiences
    // 

    public function getAudienceSettings($audienceId) {
        if(!$this->driver) return false;

		//	Get plugin settings
        $settings = get_option($this->token . '_audience_settings_' . $audienceId, $this->makeAudienceSettings());

		//	Fix issues with audience settings
		$queue = $settings['queue'];

		//	Find sections with empty items
		$queue['sections'] = array_map(function($section) {
			if(!$section['items']) $section['items'] = [];
			return $section;
		}, $queue['sections']);

		$settings['queue'] = $queue;
		
		//  Return full settings object
		return $settings;
    }

    public function updateAudienceSettings($audienceId, $updatedSettings) {
        //  Update timezone if it is different
		$timezoneLabel = wp_timezone_string();
		$timezone = wp_timezone();
		$time = new \DateTime('now', $timezone);
		$timezoneOffset = $time->format('P');
        $updatedSettings['timezone'] = ['label' => $timezoneLabel, 'offset' => $timezoneOffset];

        //  Get active audience id
        $activeAudience = $this->pluginSettings->get('active_audience');
        $activeAudienceId = $activeAudience['id'];

        //  Only update audience_settings if the active audience is the same as the audience we're updating
        if(intval($activeAudienceId) == intval($audienceId)) {
            //  Update audience settings
            update_option($this->token . '_audience_settings_' . $audienceId, $updatedSettings);
        }

        //  Return the full settings object
        return $updatedSettings;
    }

    public function getAudiences($forceUpdate = false) {
        if(!$this->driver) return false;

        $audienceLastUpdate = get_option($this->token . '_cache_audiences_last', time());
        $audiencesCached = get_option($this->token . '_cache_audiences', []);
        $expiryTimeInHours = (1 * 60 * 60);

        //	Only update the audiences every hour, or if audiences are empty
        if(count($audiencesCached) > 0 && ((time() - $audienceLastUpdate) > $expiryTimeInHours) || count($audiencesCached) == 0 || $forceUpdate || count($audiencesCached) === 0) {
            $listGroups = $this->driver->get('lists');
            $audiencesCached = $listGroups['lists'];

            //  Add segments
            $audiencesCached = array_map(function($audience) {
                $audienceId = $audience['id'];
                $segmentRequest = $this->driver->get("lists/{$audienceId}/segments");
                $segments = $segmentRequest['segments'];
                $audience['segments'] = $segments;
                return $audience;
            }, $audiencesCached);

            update_option($this->token . '_cache_audiences_last', time());
            update_option($this->token . '_cache_audiences', $audiencesCached);            
        }

        return array_map(function($audience) {
            $audienceSettings = $this->getAudienceSettings($audience['id']);
            return [
                'id' => $audience['id'],
                'web_id' => $audience['web_id'],
                'title' => $audience['name'],
                'sub_count' => $audience['stats']['member_count'],
                'last_sent' => $audience['stats']['campaign_last_sent'],
                'state' => $audienceSettings['state'],
                'scheduled' => $audienceSettings['campaign']['email_scheduled'],
                'settings' => $audienceSettings,
                'segments' => $audience['segments'] ?? [],
            ];
        }, $audiencesCached);
;
    }

    public function getAudience($audienceId) {
        if(!$this->driver) return false;

        $audiences = $this->getAudiences();

		foreach($audiences as $audience) {
			if($audience['id'] == $audienceId) return $audience;
		}
    }

    public function makeAudienceSettings() {
		$timezoneLabel = wp_timezone_string();
		$timezone = wp_timezone();
		$time = new \DateTime('now', $timezone);
		$timezoneOffset = $time->format('P');
		
		return [
			'version' => 1.0,
			'state' => 1,
			'active_editor_tab' => 'content',
			'last_send_date' => null,
			'webhook_configured' => 0,
			'timezone' => ['label' => $timezoneLabel, 'offset' => $timezoneOffset],
			'preview_email_addresses' => get_option('admin_email'),
			'queue' => [
				'sections' => [
				[
					'id' => 'general',
					'title' => 'General',
					'items' => [],
				],
				],
			],
			'campaign' => [
				'mc_id' => '',
                'mc_folder_id' => '',
				'email_scheduled' => null,
				'email_subject' => '',
				'email_frequency' => 'weekly',
				'email_frequency_settings' => ['days' => [['value' => 'monday', 'label' => 'Monday']], 'times' => ['12:30'], 'dates' => [1], 'sequencing' => 'every_two'],
				'email_template' => [
					'mc_id' => '', 
					'mc_name' => '', 
					'width_type' => 'fixed', 
					'template_content' => [],
				],
			],
		];
    }

    // 
    //      Campaigns
    // 

    public function getCampaigns($forceUpdate = false, $statuses = ["save", "paused", "schedule"]) {
        if(!$this->driver) return false;

        $campaignLastUpdate = get_option($this->token . '_cache_campaigns_last', time());
        $campaignsCachedValue = get_option($this->token . '_cache_campaigns', []);
        $campaignsCached = is_array($campaignsCachedValue) ? $campaignsCachedValue : [];
        $expiryTimeInHours = (1 * 60 * 60);

        //  If the cache has expired, or we're forcing an update, or there are no cached campaigns, then go get them and cache them.
        if(count($campaignsCached) > 0 && ((time() - $campaignLastUpdate) > $expiryTimeInHours) || count($campaignsCached) == 0 || $forceUpdate || count($campaignsCached) === 0) {
            //  Get from API
            $campaignData = $this->driver->get('campaigns', ['status' => $statuses, 'count' => 1000]);
            $campaignsCached = $campaignData['campaigns'];
            
            $campaignsCached = array_filter($campaignsCached, function($campaign) {
                //  This is not a good way to do this. Need to parse a cached audience settings, filter by mc_campaign_id.
                $campaignTitle = $campaign['settings']['title'];
                return strpos($campaignTitle, "(CampaignPress") >= 0;
            });
            
            update_option($this->token . '_cache_campaigns_last', time());
            update_option($this->token . '_cache_campaigns', $campaignsCached);
        }
        
        return $campaignsCached;
    }

    public function getCampaign($campaignId) {
        if(!$this->driver) return false;
        if(!$campaignId) return false;
        
        $campaign = self::processResponse($this->driver->get("campaigns/${campaignId}"));
        if($campaign) return $campaign;
        
        return false;
    }

    public function createCampaign($audienceId, $subjectLine, $isTest = false, $folderId = null) {
        if(!$this->driver) return false;
    
        $audience = $this->getAudience($audienceId);

        //  Data to create campaign
		$fromName = $this->pluginSettings->get('default_from_name');
		$fromEmail = $this->pluginSettings->get('default_from_email');
		$subjectLine = $isTest ? "[CampaignPress] {$subjectLine}" : $subjectLine;
		$title = $isTest ? "${subjectLine} (CampaignPress Test)" : "${subjectLine} (CampaignPress)";

		//  Create campaign
		$createdCampaign = self::processResponse($this->driver->post('campaigns', [
            'type' => 'regular', 
            'settings' => [
                'subject_line' => $subjectLine, 
                'title' => $title, 
                'from_name' => $fromName, 
                'reply_to' => $fromEmail,
                'folder_id' => $folderId,
            ], 
            'recipients' => ['list_id' => $audienceId]
        ]));
        
        if($createdCampaign) {
            //  Audience
            $audienceCampaignId = $createdCampaign['id'];

            Util::log("Campaign successful created for Audience ${audienceId} for Campaign ${audienceCampaignId}.", "mailchimp", "check_circle");

			return $createdCampaign;
		} else {
            Util::log("Campaign failed created for Audience ${audienceId}.", "mailchimp", "error");
        }

		return false;
    }

    public function updateCampaign($campaignId, $audienceId, $subjectLine) {
        if(!$this->driver) return false;

		$fromName = $this->pluginSettings->get('default_from_name');
		$fromEmail = $this->pluginSettings->get('default_from_email');
		$title = "${subjectLine} (CampaignPress)";

		$updatedCampaign = $this->driver->patch("campaigns/${campaignId}", [
            'type' => 'regular', 
            'settings' => [
                'subject_line' => $subjectLine, 
                'title' => $title, 
                'from_name' => $fromName, 
                'reply_to' => $fromEmail
            ], 
            'recipients' => ['list_id' => $audienceId]
        ]);

		if(!$this->lastError()) {
            Util::log("Campaign successful updated for Audience ${audienceId}.", "mailchimp", "check_circle");
			return $updatedCampaign;
		}
        
        Util::log("Campaign failed updated for Audience ${audienceId}.", "mailchimp", "error");
        return false;
    }

    public function removeCampaign($campaignId) {
        if(!$this->driver) return false;
		return $this->driver->delete("campaigns/{$campaignId}");
    }

    public function scheduleCampaign($campaignId, $dateTime) {
        if(!$this->driver) return false;

		$timezoneLabel = wp_timezone_string();
        $date = new DateTime($dateTime, new DateTimeZone($timezoneLabel));
        $date->setTimezone(new DateTimeZone('UTC'));
        $dateTimeISO = $date->format("Y-m-j H:i:s");

        $response = self::processResponse($this->driver->post("campaigns/{$campaignId}/actions/schedule", ['schedule_time' => $dateTimeISO]));

        if($response) Util::log("Campaign successful scheduled for Campaign ${campaignId}: Local: ${dateTime}, GMT: ${dateTimeISO}.", "mailchimp", "check_circle");
        else {
            $errorReason = $this->lastErrorMessage;
            $errorDetails = $this->lastErrorDetails;
            Util::log("Campaign failed scheduled for Campaign ${campaignId}.", "mailchimp", "error");
            Util::log("Campaign failed reason: ${errorReason}", "mailchimp", "error");
            Util::log("Campaign failed details: ${errorDetails}", "mailchimp", "error");
            Util::log("Campaign failed scheduled time: Local: ${dateTime}, GMT: ${dateTimeISO}", "mailchimp", "error");
        }
        return $response;
    }

    public function unscheduleCampaign($campaignId) {
        if(!$this->driver) return false;
        $response = self::processResponse($this->driver->post("campaigns/{$campaignId}/actions/unschedule", []));
        if($response) Util::log("Campaign successful unscheduled for Campaign ${campaignId}.", "mailchimp", "check_circle");
        else Util::log("Campaign failed unscheduled for Campaign ${campaignId}.", "mailchimp", "error");
        return $response;
    }

    // 
    //      Templates
    // 
    
    public function updateContentForCampaign($audienceId, $mcCampaign, $mcTemplate, $preview = false) {
		$mcCampaignId = $mcCampaign['id'];
		$mcTemplateId = $mcTemplate['id'];

		$result = $this->driver->put("campaigns/{$mcCampaignId}/content", ['template' => ['id' => $mcTemplateId]]);

        if($result) Util::log("Template successful updated for Campaign ${mcCampaignId}, Audience ${audienceId}.", "mailchimp", "check_circle");
        else Util::log("Template failed updated for Campaign ${mcCampaignId}, Audience ${audienceId}.", "mailchimp", "error");
		
		return $result;
    }

    public function getTemplates($forceUpdate = false) {
        if(!$this->driver) return false;
        
        $templatesLastUpdate = get_option($this->token . '_cache_templates_last', time());
        $templatesCached = get_option($this->token . '_cache_templates', []);
        $expiryTimeInHours = (1 * 60 * 60);

        if(count($templatesCached) > 0 && ((time() - $templatesLastUpdate) > $expiryTimeInHours) || count($templatesCached) == 0 || $forceUpdate || count($templatesCached) === 0) {
            $templates = $this->driver->get('templates');
            $templatesCached = $templates['templates'];

            update_option($this->token . '_cache_templates_last', time());
            update_option($this->token . '_cache_templates', $templatesCached);
        }

        return array_filter($templatesCached, function($template) {
            return strpos($template['name'], "(CampaignPress") === 0;
        });
    }

    public function getTemplate($templateId) {
        if(!$this->driver) return false;

        //  Return the template if it was already created
        $template = $this->driver->get("templates/${templateId}");
        if($template) return $template;

        return false; 
    }

    public function createTemplate($templateName, $templateHtml) {
        if(!$this->driver) return false;

        $result = $this->driver->post("templates", ['name' => $templateName, 'html' => $templateHtml]);

        if($result) Util::log("Template successful created \"${templateName}\".", "mailchimp", "check_circle");
        else Util::log("Template failed created \"${templateName}\".", "mailchimp", "error");

        return $result;
    }

    public function updateTemplate($templateId, $templateName, $templateHtml) {
        if(!$this->driver) return false;

        $result = $this->driver->patch("templates/{$templateId}", ['name' => $templateName, 'html' => $templateHtml]);

        if($result) Util::log("Template successful updated \"${templateName}\".", "mailchimp", "check_circle");
        else Util::log("Template failed updated \"${templateName}\".", "mailchimp", "error");

        return $result;
    }

    public function removeTemplate($templateId) {
        if(!$this->driver) return false;

        $result = $this->driver->delete("templates/{$templateId}");

        if($result) Util::log("Template successful updated \"${templateName}\".", "mailchimp", "check_circle");
        else Util::log("Template failed updated \"${templateName}\".", "mailchimp", "error");

        return $result;
    }

    public function sendPreview($campaignId, $emailAddresses) {
        if(!$this->driver) return false;
        
        $testEmailAddresses = explode(",", $emailAddresses);

        if($this->driver) {
            $result = $this->driver->post("campaigns/{$campaignId}/actions/test", ['test_emails' => $testEmailAddresses, 'send_type' => 'html']);
            
            if($result) Util::log("Preview successful sent for ${campaignId}.", "mailchimp", "check_circle");
            else Util::log("Preview failed sent for ${campaignId}.", "mailchimp", "error");
        }

        return false;
    }

    // 
    //      Webhooks
    // 

    public function getWebhooks($audienceId) {
        if(!$this->driver) return false;
        $webhooks = $this->driver->get("lists/${audienceId}/webhooks");
        return $webhooks['webhooks'];
    }

    public function registerWebhook($audienceId, $audienceSettings, $forceRegister = false) {
        if(!$this->driver) return false;

        $webhookHost = get_site_url();

		if($audienceSettings['webhook_configured'] === 0 || $forceRegister) {
            // Remove it first
            $this->unregisterWebhook($audienceId);

            // Add it
			$webhookUrl = "{$webhookHost}/campaignpress/mailchimp/webhook/${audienceId}";
			$mcWebhookCreated = $this->driver->post("lists/${audienceId}/webhooks", ['url' => $webhookUrl, 'events' => ['campaign' => true]]);

			if(!$this->lastError()) {
				$audienceSettings['webhook_configured'] = 1;
				$this->updateAudienceSettings($audienceId, $audienceSettings);
                Util::log("Webhook successful configured for ${audienceId}.", "mailchimp", "check_circle");
			} else {
                Util::log("Webhook failed configured for ${audienceId}.", "mailchimp", "error");
            }
		}
    }

    public function unregisterWebhook($audienceId) {
        if(!$this->driver) return false;
		
        $audiences = $this->getAudiences();

        //  Get active webhooks, see if anything matches
		foreach($audiences as $audience) {
			$audienceId = $audience['id'];
			$mcWebhooks = $this->driver->get("lists/${audienceId}/webhooks");
			$mcWebhooks = $mcWebhooks['webhooks'];
			$cpWebhooks = array_filter($mcWebhooks, function($webhook) {
				$webhook = is_array($webhook) ? $webhook : null;
				if($webhook && isset($webhook['url']))
					return strpos($webhook['url'], "/campaignpress/webhook/") > 0;
				else
					return false;
			});

            //  Remove them and tell Mailchimp
			foreach($cpWebhooks as $cpWebhook) {
				$cpWebhookId = $cpWebhook['id'];
				$mcWebHookDeleted = $this->driver->delete("lists/${audienceId}/webhooks/{$cpWebhookId}");
				if($mcWebHookDeleted) {
					$audienceSettings = $this->getAudienceSettings($audienceId);
					$audienceSettings['webhook_configured'] = 0;
					$this->updateAudienceSettings($audienceId, $audienceSettings);
                    Util::log("Webhook successful unregistration for ${audienceId}.", "mailchimp", "check_circle");
				} else {
                    Util::log("Webhook failed unregistration for ${audienceId}.", "mailchimp", "error");
                }
			}
		}
    }

    public function handleWebhook($audienceId, $params) {
        if(!$this->driver) return false;
		if(!isset($params['data'])) return;
        
        $data = $params['data'];
        $status = $data['status'];

        Util::log("Webhook: incoming data for ${audienceId}, with status ${status}.", "mailchimp", "check_circle");
        
		switch($status) {
            case "sent":
                Util::log("Webhook: sent status for ${audienceId}.", "mailchimp", "check_circle");
				$this->sentCampaign($audienceId, $data);
                return true;
				break;
		}

        return false;
    }
    
    private function sentCampaign($audienceId, $data) {
        $subject = $data['subject'];
		$listId = trim($data['list_id']);
		$audience = $this->getAudience($audienceId);
		$audienceSettings = $this->getAudienceSettings($audienceId);
		$audienceTitle = $audience['title'];
		$audienceSubject = $audienceSettings['campaign']['email_subject'];
        $audienceSubjectParsed = Util::parseTokens($audienceSubject, (object)['audience_title' => $audience['title'], 'total_content_items' => Util::totalContentItems($audienceSettings)]);
		$frequencySettings = $audienceSettings['campaign']['email_frequency_settings'];
        $frequency = $audienceSettings['campaign']['email_frequency'];
		$timezone = $audienceSettings['timezone'];
		$audienceSections = $audienceSettings['queue']['sections'];
		$templateSections = $audienceSettings['campaign']['email_template']['template_content'];
        $aCampaignId = $audienceSettings['campaign']['mc_id'];
        $pCampaignId = trim($data['id']);
		$audienceSectionsModified = [];
		$scheduleTime = Util::nextSendDateTime($frequency, $frequencySettings, $timezone, false);
        $scheduledTimeD = Util::nextSendDateTime($frequency, $frequencySettings, $timezone, true);
		$scheduleDate = $scheduleTime->format('l, F d \a\t h:ia');

        
        //  Match list and campaign
		if($listId === $audienceId && $aCampaignId === $pCampaignId) {
            Util::log("Webhook: campaign ${listId} ${audienceId} of ${frequency} frequency for ${scheduleDate} has been sent.", "mailchimp", "check_circle");
            
			$audienceSettings['last_send_date'] = date('c', time());
			$audienceSettings['campaign']['email_scheduled'] = null;
			$audienceSettings['campaign']['mc_id'] = null;

			//  Update queue section items
			foreach($audienceSections as $section) {
				$items = $section['items'];
				$itemsToKeep = array_filter($items, function($item) {
					//  Only keep items marked
					return $item['keep_in_queue'] == TRUE;
				});
				$section['items'] = $itemsToKeep;
				$audienceSectionsModified[] = $section;
			}

			$audienceSettings['queue']['sections'] = $audienceSectionsModified;

			//  Update settings for audience
			$this->updateAudienceSettings($audienceId, $audienceSettings);

            //  Reschedule for the next scheduled date
            if($frequency !== 'immediate') {
                $this->updateCampaign($aCampaignId, $audienceId, $audienceSubjectParsed);
                $this->scheduleCampaign($aCampaignId, $scheduledTimeD);

			    //  Report to user that a new campaign has been scheduled for audience
			    Util::toastAddMessage('campaign_scheduled', "Your next Campaign for ${audienceTitle} has been scheduled for ${scheduleDate}", true, "alert");

			    echo $scheduleDate;
            } else {
                Util::log("Webhook: campaign for ${audienceId} is scheduled for immediate, so next campaign is not scheduled.", "mailchimp", "check_circle");
            }
        }
    }

    public function getCampaignFolders() {
        $response = $this->driver->get("campaign-folders", ['count' => 1000]);
        return $response['folders'];
    }

    public function getCampaignFolderByName($folderName) {
        $folders = $this->getCampaignFolders();
        $foundFolders = array_filter($folders, function($f) use ($folderName) {
            if($f['name'] === $folderName) return $f;
        });

        if(count($foundFolders) > 0)
            return array_pop($foundFolders);

        return false;
    }

    public function createCampaignFolder($folderName) {
        //  See if there's a folder matching this name first
        $existingFolder = $this->getCampaignFolderByName($folderName);
        if($existingFolder) return $existingFolder;

        //  Make a new one
        $response = $this->driver->post("campaign-folders", ['name' => $folderName]);
        if(!!$response) return $response;
        else return $response;
    }

    public function getCampaignFolder($folderId) {
        $response = self::processResponse($this->driver->get("campaign-folders/${folderId}"));
        if(!!$response) return $response;
        else return $response;
    }

    public function clearCampaignDrafts($audienceId) {
        $campaigns = $this->getCampaigns(true, ['save', 'paused']);
        $campaigns = array_filter($campaigns, function($campaign) use ($audienceId) {
            return $campaign['recipients']['list_id'] === $audienceId;
        });
        foreach($campaigns as $campaign) {
            $campaignId = $campaign['id'];
            $response = self::processResponse($this->driver->delete("campaigns/$campaignId"));
        }
    }

    private function processErrors($errors) {
        $response = [];
        for($error = 0; $error < count($errors); $error++)
            $response[] = $errors[$error]['message'];
        return implode(', ', $response);
    }

    private function processResponse($response) {
        if(is_array($response) && isset($response['status']) && isset($response['detail'])) {
            $this->lastErrorStatus = $response['status'];
            $this->lastErrorMessage = $response['detail'];
            $this->lastErrorDetails = isset($response['errors']) ? self::processErrors($response['errors']) : "";
            Util::log("Error Mailchimp " . $response['status'] . ", Message: " . $this->lastErrorMessage, "mailchimp", "error");
            Util::log("Error Mailchimp " . $response['status'] . ", Detail: " . $this->lastErrorDetails, "mailchimp", "error");

            //  Bad request
            if($response['status'] === 400) {
                return false;
            }
            if($response['status'] === 404) {
                return false;
            }
        }
        return $response;
    }

    // 
    //      State
    // 

    public function lastError() {
        if(!$this->driver) return false;

        $this->driver->getLastError();
    }

    public function success() {
        if(!$this->driver) return false;

        $this->driver->success();
    }

}