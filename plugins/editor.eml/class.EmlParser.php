<?php
defined('AJXP_EXEC') or die( 'Access not allowed');

class EmlParser extends AJXP_Plugin{
	
	public static $currentListingOnlyEmails;
	
	public function switchAction($action, $httpVars, $filesVars){
		
		if(!isSet($this->actions[$action])) return false;
    	
		$repository = ConfService::getRepository();
		if(!$repository->detectStreamWrapper(true)){
			return false;
		}
		
		$streamData = $repository->streamData;
    	$destStreamURL = $streamData["protocol"]."://".$repository->getId();
		if(empty($httpVars["file"])) return;
    	$file = $destStreamURL.AJXP_Utils::decodeSecureMagic($httpVars["file"]);
    	
    	switch($action){
    		case "eml_get_xml_structure":
    			require_once("Mail/mimeDecode.php");
    			$params = array(
    				'include_bodies' => false,
    				'decode_bodies' => false,
    				'decode_headers' => true
    			);    			
    			$content = file_get_contents($file);
    			$decoder = new Mail_mimeDecode($content);
    			
    			header('Content-Type: text/xml; charset=UTF-8');
				header('Cache-Control: no-cache');    			
    			print($decoder->getXML($decoder->decode(array($params))));    			
    		break;
    		case "eml_get_bodies":
    			require_once("Mail/mimeDecode.php");
    			$params = array(
    				'include_bodies' => true,
    				'decode_bodies' => true,
    				'decode_headers' => false
    			);    			
    			$content = file_get_contents($file);
    			$decoder = new Mail_mimeDecode($content);
    			$structure = $decoder->decode($params);
    			$html = $this->_findPartByCType($structure, "text", "html");
    			$text = $this->_findPartByCType($structure, "text", "plain");
    			if($html != false && isSet($html->ctype_parameters) && isSet($html->ctype_parameters["charset"])){
    				$charset = $html->ctype_parameters["charset"];
    			}
    			if(isSet($charset)){
    				header('Content-Type: text/xml; charset='.$charset);
					header('Cache-Control: no-cache');
					print('<?xml version="1.0" encoding="'.$charset.'"?>');
					print('<email_body>');    			    				
    			}else{
	    			AJXP_XMLWriter::header("email_body");
    			}
    			if($html!==false){
    				print('<mimepart type="html"><![CDATA[');
    				$text = $html->body;
    				print($text);
    				print("]]></mimepart>");
    			}
    			if($text!==false){
    				print('<mimepart type="plain"><![CDATA[');
    				print($text->body);
    				print("]]></mimepart>");
    			}
    			AJXP_XMLWriter::close("email_body");    			
    			
    		break;
    		case "eml_dl_attachment":
    			$attachId = $httpVars["attachment_id"];
    			if(!isset($attachId)) break;
    			
    			require_once("Mail/mimeDecode.php");
    			$params = array(
    				'include_bodies' => true,
    				'decode_bodies' => true,
    				'decode_headers' => false
    			);    			
    			$content = file_get_contents($file);
    			$decoder = new Mail_mimeDecode($content);
    			$structure = $decoder->decode($params);
    			$part = $this->_findAttachmentById($structure, $attachId);
    			if($part !== false){
	    			$fake = new fsAccessDriver("fake", "");
	    			$fake->readFile($part->body, "file", $part->d_parameters['filename'], true);
	    			exit();
    			}else{
    				var_dump($structure);
    			}
    		break;
    		
    		default: 
    		break;
    	}
    	
    	
	}
	
	public function extractMimeHeaders($currentNode, &$metadata, $wrapperClassName, &$realFile){
		if(!preg_match("/\.eml$/i",$currentNode)){
			EmlParser::$currentListingOnlyEmails = FALSE;
			return;
		}
		if(EmlParser::$currentListingOnlyEmails === NULL){
			EmlParser::$currentListingOnlyEmails = true;
		}
		if(!isSet($realFile)){
			$realFile = call_user_func(array($wrapperClassName, "getRealFSReference"), $currentNode);
		}
		
		require_once("Mail/mimeDecode.php");
    	$params = array(
    		'include_bodies' => false,
    		'decode_bodies' => false,
    		'decode_headers' => true
    	);    			
		$mess = ConfService::getMessages();    	
    	$content = file_get_contents($realFile);
    	$decoder = new Mail_mimeDecode($content);
		$structure = $decoder->decode($params);
		$allowedHeaders = array("to", "from", "subject", "message-id", "mime-version", "date", "return-path");
		foreach ($structure->headers as $hKey => $hValue){
			if(!in_array($hKey, $allowedHeaders)) continue;
			if(is_array($hValue)){
				$hValue = implode(", ", $hValue);
			}
			if($hKey == "date"){
				$date = strtotime($hValue);
				$hValue = date($mess["date_format"], $date);
			}
			$metadata["eml_".$hKey] = AJXP_Utils::xmlEntities($hValue, true);
		}
	}	
	
	public function lsPostProcess($action, $httpVars, $outputVars){
		if(EmlParser::$currentListingOnlyEmails === true){
			$config = '<columns template_name="eml.list">
				<column messageId="editor.eml.1" attributeName="ajxp_label" sortType="String"/>
				<column messageId="editor.eml.2" attributeName="eml_to" sortType="String"/>
				<column messageId="editor.eml.4" attributeName="eml_date" sortType="Date"/>
				<column messageId="editor.eml.3" attributeName="eml_subject" sortType="String" fixedWidth="50%"/>
				<column messageId="2" attributeName="filesize" sortType="NumberKo"/>
			</columns>';			
		}else{
			// Restore standard columns.. 
			$xmlRegistry = AJXP_PluginsService::getXmlRegistry();
			$xPath = new DOMXPath($xmlRegistry);
			$cols = $xPath->query("client_configs/component_config[@className='FilesList']/columns/*");
			$config = '<columns switchGridMode="filelist">';
			foreach($cols as $colNode){
				$xml = $xmlRegistry->saveXML($colNode);
				$xml = str_replace("additional_column", "column", $xml);
				$config.=$xml;
			}
			$config.= '</columns>';
		}			
		$dom = new DOMDocument("1.0", "UTF-8");
		$dom->loadXML($outputVars["ob_output"]);
		if(EmlParser::$currentListingOnlyEmails === true){
			// Replace all text attributes by the "from" value
			foreach ($dom->documentElement->childNodes as $child){
				$child->setAttribute("text", $child->getAttribute("eml_from"));
			}
		}
		
		// Add the columns template definition
		$insert = new DOMDocument("1.0", "UTF-8");		
		$config = "<client_configs><component_config className=\"FilesList\">$config</component_config></client_configs>";			
		$insert->loadXML($config);
		$imported = $dom->importNode($insert->documentElement, true);
		$dom->documentElement->appendChild($imported);
		header('Content-Type: text/xml; charset=UTF-8');
		header('Cache-Control: no-cache');			
		print($dom->saveXML());
	}
	
	protected function _findPartByCType($structure, $primary, $secondary){
		if($structure->ctype_primary == $primary && $structure->ctype_secondary == $secondary){
			return $structure;
		}
		if(empty($structure->parts)) return false;
		foreach($structure->parts as $part){
			$res = $this->_findPartByCType($part, $primary, $secondary);
			if($res !== false){
				return $res;
			}
		}
		return false;
	}
	 
	protected function _findAttachmentById($structure, $attachId){
		if(!empty($structure->disposition) &&  $structure->disposition == "attachment" 
			&& ($structure->headers["x-attachment-id"] == $attachId || $attachId == "0" )){
			return $structure;
		}
		if(empty($structure->parts)) return false;
		foreach($structure->parts as $part){
			$res = $this->_findAttachmentById($part, $attachId);
			if($res !== false){
				return $res;
			}
		}
		return false;
	}
	 
}

?>