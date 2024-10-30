<?php
if (!defined('ABSPATH')) exit;

require(__DIR__ . '/../vendor/autoload.php');

class Util {
    
	public static $token = 'orchestrated_campaignpress';

    public function __construct() { }

	/* 

	---
	Utility: nextSendDateTime
	--- 
	Calculates next scheduled send time based on parameters

	*/

    public static function nextSendDateTime($frequency, $settings, $timezone, $scheduleFormat = false) {
		$timezoneOffset = $timezone['label'];
		$dates = $settings['dates'];
		$days = $settings['days'];
		$times = $settings['times'];
		$sequencing = $settings['sequencing'];

		switch($frequency) {
			case "immediate":
				$nextSendDate = new DateTime('now', new DateTimeZone($timezoneOffset));
				$next15 = 15 - ($nextSendDate->format('i') % 15);
				$nextSendDate->modify("+{$next15} minutes");
				break;
			case "weekly":
				if(!$days || !$times) break;
				$day = $days[0];
				$time = $times[0];
				$hour = explode(':', $time)[0];
				$minutes = explode(':', $time)[1];
				$nextSendDate = new DateTime("next {$day['label']} $hour:$minutes", new DateTimeZone($timezoneOffset));
				break;
			case "monthly":
				if(!$days || !$times) break;
				$date = $dates[0];
				$time = $times[0];
				$hour = explode(':', $time)[0];
				$minutes = explode(':', $time)[1];
				$dateDouble = intval($date['value']) < 9 ? "0" . $date['value'] : $date['value'];
				$nextSendDate = new DateTime(date("Y-m-{$dateDouble} $hour:$minutes"), new DateTimeZone($timezoneOffset));
				if(new DateTime('now', new DateTimeZone($timezoneOffset)) > $nextSendDate) {
					$dayOffset = $date['value'] - 1;
					$nextSendDate = new DateTime('first day of next month', new DateTimeZone($timezoneOffset));
					$nextSendDate->modify("+{$dayOffset} days");
				}
				break;
			case "biweekly":
				if(!$days || !$times || !$dates || !$sequencing) break;
				$day = $days[0];
				$date = $dates[0];
				$time = $times[0];
				$hour = explode(':', $time)[0];
				$minutes = explode(':', $time)[1];
				$dateDouble = intval($date['value']) < 9 ? "0" . $date['value'] : $date['value'];
				if($sequencing === 'every_two') {
					$nextSendDate = new DateTime(date("Y-m-{$dateDouble} $hour:$minutes"), new DateTimeZone($timezoneOffset));
					if(new DateTime('now', new DateTimeZone($timezoneOffset)) > $nextSendDate) {
						$differentialDate = self::biWeeklyDateDifferential($nextSendDate->format('d'));
						$nextSendDate = new DateTime(date("Y-m-{$differentialDate} $hour:$minutes"), new DateTimeZone($timezoneOffset));
					}
				} else if($sequencing === 'every_other') {
					$nextSendDate = new DateTime(date("Y-m-{$dateDouble} $hour:$minutes"), new DateTimeZone($timezoneOffset));
					if(new DateTime('now', new DateTimeZone($timezoneOffset)) > $nextSendDate)
						$nextSendDate->modify('+2 weeks');
				}
				break;
			case "daily":
				//	Add day as number to array
                $aDays = array_map(function($d) {
                    $d['day'] = self::dayOfWeekAsNumber($d['value']);
                    return $d;
                }, $days);
				//	Sort by day
				usort($aDays, function($a,$b) {
					return $a['day'] <=> $b['day'];
				});
				//	If there are days...
                if(count($aDays) > 0) {
					//	Grab the first one
					$daysReversed = array_reverse($aDays);
                    $nextDay = array_pop($daysReversed);
                    $nextDate = new DateTime('now', new DateTimeZone($timezoneOffset));
					$nextDate->modify($nextDay['value'] . " this week");
                    $newTime = $times[$nextDay['value']];
					$newTimeT = explode(':', $newTime);
                    $nextDate->setTime($newTimeT[0], $newTimeT[1]);
					//	If the date is in the past, find a new one or skip to next one
					if($nextDate->getTimestamp() < (new DateTime('now', new DateTimeZone($timezoneOffset)))->getTimestamp()) {
						$nextDay = array_pop($daysReversed);
						//	Set the next date
						if($nextDay) $nextDate->modify($nextDay['value'] . " this week");
						else $nextDate->modify("next " . $nextDay['value']);
						//	and time
						$nextDate->setTime($newTimeT[0], $newTimeT[1]);
					}
					$nextSendDate = $nextDate;
                }
				break;
		}

		if($scheduleFormat)
			return $nextSendDate->format("Y-m-j H:i:s");
		else
			return $nextSendDate;
    }

	/* 

	---
	Utility: dayOfWeekAsNumber
	--- 
	Finds the number for a weekday value

	*/

    public static function dayOfWeekAsNumber($dayOfWeek) {
        switch($dayOfWeek) {
            case 'sunday':
                return 0;
                break;
            case 'monday':
                return 1;
                break;
            case 'tuesday':
                return 2;
                break;
            case 'wednesday':
                return 3;
                break;
            case 'thursday':
                return 4;
                break;
            case 'friday':
                return 5;
                break;
            case 'saturday':
                return 6;
                break;
        }
    }

	/* 

	---
	Utility: biWeeklyDateDifferential
	--- 
	Finds the opposing week in a biweekly scheduling

	*/

    public static function biWeeklyDateDifferential($d) {
        if(!$d) return;
        $diff = ($d - 1) + 15;
        if($diff > 28) return 'last day';
        else return $diff;
    }

	/* 

	---
	Utility: toastAddMessage
	--- 
	Add a toast message to be displayed to the user

	*/

	public static function toastAddMessage($messageId, $messageText, $disappear = true, $messageType = "info") {
		$toastMessages = get_option(self::$token . '_toast_messages', self::makeToastSettings());

		//  Filter messages with the same message id to prevent multiple messages of the same kind
		$toastMessages = array_filter($toastMessages, function($message) use ($messageId) { return $messageId !== $message['id']; });

		//  Add new message
		$toastMessages[] = ['id' => $messageId, 'text' => $messageText, 'type' => $messageType, 'disappear' => $disappear];

		update_option(self::$token . '_toast_messages', $toastMessages);

		return $toastMessages;
	}

	/* 

	---
	Utility: toastRemoveMessage
	--- 
	Remove toast messages to be displayed to the user

	*/

	public static function toastRemoveMessage($messageId) {
		$toastMessages = self::toastMessages($messageId);
		$updatedToastMessages = array_filter($toastMessages, function($message) use ($messageId) { return $messageId !== $message['id']; });

		update_option(self::$token . '_toast_messages', $updatedToastMessages);

		return $updatedToastMessages;
	}

	/* 

	---
	Utility: toastMessages
	--- 
	Gets all toast messages to be displayed to the user

	*/

	public static function toastMessages() {
		$toastMessages = get_option(self::$token . '_plugin_toast_messages', self::makeToastSettings());
		return $toastMessages;
	}

	/* 
	
	---
	Utility: makeToastSettings
	--- 
	Used to create an empty template of toast messages

	*/

	public static function makeToastSettings() {
		return [
		];
	}

	/* 
	
	---
	Utility: totalContentItems
	--- 
	Gets total content items for an audience/campaign

	*/

	public static function totalContentItems($audienceSettings) {
		$total = 0;
		$sections = $audienceSettings['queue']['sections'] ?? [];
		foreach($sections as $section) {
			$total += count($section['items']);
		}
		return $total;
	}

	/* 
	
	---
	Utility: parseTokens
	--- 
	Used to parse tokens within a subject, or other text

	*/

	public static function parseTokens($text, $data) {
		$todayDateFormatted = date('l, F j');
		$audienceTitle = $data->audience_title;
		$totalContentItems = $data->total_content_items;
		$text = str_replace("{date_today}", $todayDateFormatted, $text);
		$text = str_replace("{audience_title}", $audienceTitle, $text);
		$text = str_replace("{total_content_items}", $totalContentItems, $text);
		return $text;
	}

	/* 

	---
	Utility: log
	--- 
	Log activity

	*/

	public static function log($message = '–', $context = 'campaignpress', $state = 'info', $slug = null) {
		global $wpdb;

		$slug = $slug ?? sanitize_title(self::getFirstWords($message, 3));
		$tableName = $wpdb->prefix . "campaignpress_logs"; 
		$result = $wpdb->insert( 
			$tableName, 
			array( 
				'time' => current_time('mysql'), 
				'text' => $message, 
				'slug' => $slug, 
				'context' => $context, 
				'state' => $state, 
			) 
		);
	}


	public static function getFirstWords($string, $howMany = 3) {
		$words = explode(' ', $string); 
		$firstWords = array_slice($words, 0, $howMany);
		return implode(' ', $firstWords); 
	}
}