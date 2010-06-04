<?php
/**
 * EmailLabsAPI - Wrapper for some common EmailLabs API functions.  Also POSTs form
 * data to several forms that are not officially part of the API.
 * 
 * Originally intended for internal use only. Sorry for the poor state of documentation.
 * See README for more.
 * 
 * Author: Eli Dickinson, eli (at) fiercemarkets.com
 * 
 * http://github.com/elidickinson/EmailLabsAPI
 * 
 * File Revision: r4520 | 2009-07-17 15:55:38 -0400 (Fri, 17 Jul 2009)
 * 
 */


/**
 * CLASS: EmailLabsAPIResponse
 *
 * Parses the XML response from EmailLabs API into easy-to-use fields
 * Notes that only API functions (i.e. callApi() below) return XML. POSTs to
 * EmailLabs /functions/*.html URLs return HTML pages or redirect on success.
 */
class EmailLabsAPIResponse {
	var $xml, $string, $firstRecord;
	protected $type;
	
	function EmailLabsAPIResponse($response_string) {
		$this->xml = new DOMDocument();
		$this->xml->loadXML($response_string);
		$this->string = $response_string;
		
		$types = $this->xml->getElementsByTagName('TYPE');
		if($types->length >= 1) {
			$this->type = $types->item(0)->textContent;
		}
		$this->firstRecord = $this->record();
	}
	
	function record($num = 0) {
		$recs = $this->xml->getElementsByTagName('RECORD');
		if($num >= $recs->length) {
			return false;
		}		
		
		// OK, the logic here gets a little ridiculous. 
		// Case 1 (apiRecordQueryData) has records with "rows" that look like this:
		// 		<DATA type="demographic" id="1">Mary</DATA>
		// Case 2 (apiDemographicQueryEnabled) has records with data "rows" that work together like:
		//		<DATA type="name">Where do you live?</DATA> 
   	//		<DATA type="id">1425</DATA> 
   	//		<DATA type="type">select list</DATA> 
		
		$result = array();
		foreach ($recs->item($num)->childNodes as $tag) {
			if ($tag->nodeType != XML_TEXT_NODE && $tag->attributes) {
				$type = $tag->getAttribute('type');
				$id = $tag->getAttribute('id');
				if($type && $id) { // ------ Case 1
					if(!isset($result[$type]) || !is_array($result[$type])) {
						$result[$type] = array();
					}
					$result[$type][$id] = $tag->textContent;
				}
				else if($type) {	// ------ Case 2
					$result[$type] = $tag->textContent;
				}
			}
		}
		return $result;
	}
	
	function allRecords() {
		$recs = array();
		$num_recs = $this->xml->getElementsByTagName('RECORD')->length;
		for($i=0;$i < $num_recs;$i++) {
			$recs[] = $this->record($i);
		}
		return $recs;
	}
	
	function success() {
		return ($this->type == 'success');
	}
}

/**
 * CLASS: EmailLabsAPI
 *
 */
class EmailLabsAPI {
	var $config = array('siteid'=>'8121');
	
	/**
	 * Subscribes a user to the specified list(s). If the person is already
	 *  subscribed, this function can be used to update demographics.
	 *
	 * @param $email
	 * @param $mlids
	 *	Either an array of Mailing List IDs or a single MLID. 
	 *  Note: time increases linearly with number of MLIDs specified
	 * @param $demographics
	 *	Hash in form of array('1'=>'Joe', '2'=>'Smith','47'=>'Engineer')
	 * @return void
	 * @author Eli Dickinson
	 **/
	function subscribe($email, $mlids, $demographics = array(),$send_welcome=true) {
		// sanity check
		if(!is_array($mlids)) {
			if(is_numeric($mlids))
				$mlids = array($mlids);
			else
				return FALSE;
		}
				
		if(!$this->is_valid_email($email)) {
			return FALSE;
		}
		
		$params = array();
		$params['submitaction'] = '3';
		$params['siteid'] = $this->config['siteid'];
		$params['email'] = $email;
		$params['tagtype'] = 'q2';
		$params['welcome'] = $send_welcome ? "on" : "off";
		$params['activity'] = 'submit';
		$params['redirection'] = 'http://example.com/success';
        // $params['val_95785'] = $_SERVER['REMOTE_ADDR']; // Default Source IP Address to REMOTE_ADDR. Can be overwritten by $demographics
		foreach($demographics as $dem_id=> $dem_value) {
			// IDs like 1234 or val_1234 are added to params. Anything else in demographics array is ignored
			if(stripos($dem_id,"val_") === 0) {
				$params[$dem_id] = $dem_value;
			}
			else if(is_numeric($dem_id)) {
				$params['val_'.$dem_id] = $dem_value;
			}
		}
		$result = true;
		foreach($mlids as $mlid) {
			$params['mlid'] = $mlid;	
			$resp = $this->http_post_form("http://www.uptilt.com/functions/mailing_list.html", $params);
			
			// Check if EmailLabs returned the 'success' redirect
			$result = ($result && (strpos($resp,'example.com/success') !== FALSE));
		}
		
		// Returns true if all attempts to subscribe to all MLIDs went through. False otherwise.
		return $result;
	}

	static function is_valid_email($email, $check_bogosity = FALSE) {
		$regex = '/^[a-zA-Z0-9\._\-\+]+\@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-\.]+$/';
		if (preg_match($regex, $email) == 0) {
			return FALSE;
		}
		
		if($check_bogosity && (preg_match('/^(asdf|jkl|1234?5?|x+|junk|spam)\@(asdf|jkl|1234?5?|456|x+|spam)\./i', $email))) {
			return FALSE;
		}
		
		return TRUE;
	}

/* 
  //
  //This function works and is more efficient than above, but does NOT update demographics for existing subscribers  AT ALL
  //
	function subscribe($email, $mlids, $demographics = array(),$send_welcome=true) {
		if(!is_array($mlids)) {
			$mlids = array($mlids);
		}
		$params = array();
		$params['submitaction'] = '3';
		$params['siteid'] = $this->config['siteid'];
		$params['email'] = $email;
		$params['tagtype'] = 'q2';
		$params['welcome'] = $send_welcome ? "on" : "off";
		$params['update_demographics'] = "true";
		$params['activity'] = 'submit';
		foreach($mlids as $mlid) {
			$params['mlid'.$mlid] = "sub";
		}
		$params = array_merge($demographics, $params);
		//print_r($params);
		$result = $this->http_post_form("http://www.uptilt.com/functions/multiple_mailing_list.html", $params);		
	}
*/


	/**
	 * Unsubscribes a user from specified list(s). 
	 *
	 * @param $email
	 * @param $mlids
	 *	either a single MLID or an array of MLIDs
	 * @return void
	 *
	 */
	function unsubscribe($email, $mlids) {
		if(!is_array($mlids)) {
			$mlids = array($mlids);
		}
		$params = array();
		$params['submitaction'] = '2';
		$params['siteid'] = $this->config['siteid'];
		$params['email'] = $email;
		$params['tagtype'] = 'q2';
		$params['activity'] = 'submit';
		$params['redirection'] = 'http://example.com/success';
		foreach($mlids as $mlid) {
			$params['mlid'.$mlid] = "unsub";
		}
		$result = $this->http_post_form("http://www.uptilt.com/functions/multiple_mailing_list.html", $params);	
	}
	
	/** 
	 * Get user demographics for the specified email address in the specified list
	 */
	function get_demographics($email,$mlid) {
		$response = $this->apiRecordQueryData($email,$mlid);
		if (is_array($response->firstRecord['demographic'])) {
			return $response->firstRecord['demographic'];
		}
		else {
			return false;
		}
	}
	
	function list_enabled_demographics($mlid) {
		$response = $this->apiDemographicQueryEnabled($mlid);
		if ($response->success()) {
			return $response;
		}
		else {
			return false;
		}
	}
	
	function is_subscribed($email, $mlid) {		
		$response = $this->apiRecordQueryData($email,$mlid);
		//var_dump($response);
		if(!$response->success() || !is_array($response->firstRecord)) {
			// no record or error searching
			return false;
		}
		else {
			//has a record indicating subscriber is trashed
			return ($response->firstRecord['extra']['trashed'] == 'n');
		}
	}
	
	
	
	/**
	 * Forwards a message to a non-subscriber (fwd to a friend feature). Requires that an
	 *  enhancer be created in each mailing list in which you want to use it.
	 *  Oddly, enhancer ID (aka "p") is required, but MLID is not.
	 *
	 * @return true on success, false on fail
	 */
	function forward_message($enhancer_id,$from_email,$to_email,$message) {
		if (!is_numeric($enhancer_id) || $enhancer_id <= 0) {
			trigger_error("Invalid enhancer ID", E_USER_ERROR);
		}
		if (!preg_match('/.+@.+\..+/',$from_email)) {
			trigger_error("Invalid From Email Address", E_USER_ERROR);
		}
		if (!preg_match('/.+@.+\..+/',$to_email)) {
			trigger_error("Invalid To Email Address", E_USER_ERROR);
		}



		// note: this is not actually an API call
		$url = 'http://www.uptilt.com/functions/email_referral_newsletter.html';
		
		$data = array();
		$data['mid'] = '';
		$data['mlid'] = '';
		$data['siteid'] = '';
		$data['activity'] = 'submit';
		$data['data'] = '';
		$data['sender'] = $from_email;
		$data['uid'] = '';
		$data['pers_mess'] = $message;
		$data['p'] = $enhancer_id;
		
		$data['recipients'] = $to_email;
		
		$resp = $this->http_post_form($url,$data);
			
		// Check if EmailLabs returned the 'success' redirect
		$result = strpos($resp,'has been sent') !== FALSE;
		return $result;
		
	}
	
	// ******************** Wrappers for EmailLabs API functions ************************
	
	/**
	 * Query data about a particular record in a particular list
	 */
	protected function apiRecordQueryData($email,$mlid) {
		if (!is_numeric($mlid)) {
			trigger_error("Invalid MLID", E_USER_ERROR);
		}
		if (!preg_match('/.+@.+\..+/',$email)) {
			trigger_error("Invalid Email Address", E_USER_ERROR);
		}
		
		$data = "<DATASET><SITE_ID>{$this->config['siteid']}</SITE_ID><MLID>$mlid</MLID><DATA type=\"email\">$email</DATA></DATASET>";
		$apiresult = $this->callApi('record','query-data',$data);
		$resp = new EmailLabsAPIResponse($apiresult);
		return $resp;
	}
	
	/**
	 * Returns a list of active demographics for the given MLID
	 */
	 
	protected function apiDemographicQueryEnabled($mlid) {
		if (!is_numeric($mlid)) {
			trigger_error("Invalid MLID", E_USER_ERROR);
		}
		$data = "<DATASET><SITE_ID>{$this->config['siteid']}</SITE_ID><MLID>$mlid</MLID></DATASET>";
		$apiresult = $this->callApi('demographic','query-enabled',$data);
		$resp = new EmailLabsAPIResponse($apiresult);
		return $resp;
	}
	
	
	// ******************** EmailLabs Low-Level functions **************************
	
	protected function callApi($api_type, $api_activity, $api_input=''){
		$data = array();
		$data['type'] = $api_type;
		$data['activity'] = $api_activity;
		$data['input'] = $api_input;
		$result = $this->http_post_form("http://www.uptilt.com/API/mailing_list.html", $data);
		return $result;
	}
		
	/**
	 * Posts form data to specified URL using PHP cURL extensions. Data is a hash of key=>value
	 *
	 * @return Returns resulting body of the POST. Fails on error or timeout.
	 * @author Eli Dickinson
	 **/	
	protected function http_post_form($url,$data,$timeout=20) {
		if(!function_exists("curl_exec")) {
			trigger_error("PHP/cURL is not installed on this server");
		}
		
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_URL,$url); // set url to post to 
		curl_setopt($ch, CURLOPT_FAILONERROR, 1); 
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // allow redirects 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); // return into a variable 
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // times out after $timeout secs 
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0); 
		curl_setopt($ch, CURLOPT_POST, 1); // set POST method 
		
		// Translate $data hash into form encoded string (e.g. "param1=val&param2=val&param3=val")
		$values = array();
		foreach($data as $key=>$value)
	  	$values[]="$key=".urlencode($value);
	    $data_string=implode("&",$values);
		
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string); // add POST fields 
		$result = curl_exec($ch); // run the whole process 
		curl_close($ch);
		return $result;
	}
	
	
}


?>