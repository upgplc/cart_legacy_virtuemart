<?php

/**
 *
 * SecureHosting Payment Module
 * @author: UPG Plc
 * @package VirtueMart
 * @subpackage payment
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.org
 */
defined ('_JEXEC') or die('Restricted access');
if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentSecureHosting extends vmPSPlugin {

	function __construct(& $subject, $config) {

		parent::__construct($subject, $config);

		$jlang = JFactory::getLanguage ();
		$jlang->load ('plg_vmpayment_secure_hosting', JPATH_ADMINISTRATOR, NULL, TRUE);
		$this->_loggable = true;
		$this->_debug = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array(
			'payment_logos' => array('', 'char'),
			'shreference' => array('', 'char'),
			'checkcode' => array('', 'char'),
			'filename' => array('', 'char'),
			'activateas' => array(0, 'int'),
			'phrase' => array('', 'char'),
			'referrer' => array('', 'char'),
			'testmode' => array(0, 'int'),
			'countries' => array('', 'char'),
			'min_amount' => array('', 'int'),
			'max_amount' => array('', 'int'),
			'secure_post' => array('', 'int'),
			'cost_per_transaction' => array('', 'int'),
			'cost_percent_total' => array('', 'int'),
			'tax_id' => array(0, 'int'),
			'status_pending' => array('', 'char'),
			'status_success' => array('', 'char'),
			'status_canceled' => array('', 'char')
		);

		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
	}

	public function getVmPluginCreateTableSQL() {

		return $this->createTableSQL('Payment SecureHosting Table');
	}

	function _getSKRILLURL ($method) {

		$url = 'www.securehosting.com';

		return $url;
	}

	function _processStatus (&$mb_data, $vmorder, $method) {

		switch ($mb_data['status']) {
			case 2 :
				$mb_data['payment_status'] = 'Completed';
				break;
			case 0 :
				$mb_data['payment_status'] = 'Pending';
				break;
			case -1 :
				$mb_data['payment_status'] = 'Cancelled';
				break;
			case -2 :
				$mb_data['payment_status'] = 'Failed';
				break;
			case -3 :
				$mb_data['payment_status'] = 'Chargeback';
				break;
		}

		$md5data = $mb_data['merchant_id'] . $mb_data['transaction_id'] .
			strtoupper (md5 (trim($method->secret_word))) . $mb_data['mb_amount'] . $mb_data['mb_currency'] .
			$mb_data['status'];

		$calcmd5 = md5 ($md5data);
		if (strcmp (strtoupper ($calcmd5), $mb_data['md5sig'])) {
			return "MD5 checksum doesn't match - calculated: $calcmd5, expected: " . $mb_data['md5sig'];
		}

		return FALSE;
	}

	function getTableSQLFields() {

		$SQLfields = array(
	    'id' => ' INT(11) UNSIGNED NOT NULL AUTO_INCREMENT ',
	    'virtuemart_order_id' => ' int(1) UNSIGNED',
	    'order_number' => ' varchar(64)',
	    'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED',
	    'payment_name' => 'varchar(5000)',
	    'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
	    'payment_currency' => 'varchar(3) ',
	    'cost_per_transaction' => ' decimal(10,2)',
	    'cost_percent_total' => ' decimal(10,2)',
	    'tax_id' => ' smallint(1)',
	    'sh_custom' => ' varchar(255)  ',
	    'sh_response_order_id' => ' varchar(32) ',
	    'sh_response_transaction_date' => ' varchar(28)',
	    'sh_response_authcode' => ' varchar(28)',
	    'sh_response_cardtype' => ' varchar(28)',
	    'sh_response_cv2avsresult' => ' varchar(28)',
	    'shresponse_raw' => ' varchar(512)'
		);
		return $SQLfields;
	}

	function plgVmConfirmedOrder($cart, $order) {

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		if (!class_exists('VirtueMartModelCurrency'))
		require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');

		$new_status = '';

		$address = $order['details']['BT'];
		$shipaddress = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists('TableVendors'))
		require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		
		if ($shipaddress == null) {
			$shipaddress = $address;
		} 
	
		//Build the Secuitem product string
		$secuitems = '';
		foreach($order['items'] as $item){
	        $secuitems .= '[' . $item->virtuemart_product_id . '|'. 
					$item->order_item_sku . '|' . 
					$item->order_item_name . '|' . 
					number_format($item->product_final_price, 2, '.', '') . '|' . 
					$item->product_quantity . '|' . 
					number_format($item->product_subtotal_with_tax, 2, '.', '') . ']';
		}   
	     			
	    //Start building the array of fields to send across
		$post_variables = Array(
			
		//Standard checkout fields
	    'shreference' => $method->shreference,
		'checkcode' => $method->checkcode,
		'filename' => $method->shreference . '/' . $method->filename,
	    'order_id' => $order['details']['BT']->order_number,
	    'custom' => $return_context,
		'secuitems' => $secuitems,
	    'transactionamount' => number_format($order['details']['BT']->order_total, 2, '.', ''),
		'subtotal'=>number_format($order['details']['BT']->order_subtotal, 2, '.', ''),
		'shippingcharge'=>number_format($order['details']['BT']->order_shipment, 2, '.', ''),
		'transactiontax'=>number_format($order['details']['BT']->order_tax, 2, '.', ''),
		'transactioncurrency' => $currency_code_3,
		'callbackurl' => JROUTE::_(JURI::root() . 'index.php'),
	    'callbackdata' => 'order_id|' . $order['details']['BT']->order_number . '|option|com_virtuemart|view|pluginresponse|task|pluginnotification|tmpl|component' ,
		'cancel_url' => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
		'success_url' => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
				
		//Cardholder fields
	    'cardholdersname' => $address->first_name . " " . $address->last_name,
	    'cardholderaddr1' => $address->address_1,
		'cardholderaddr2' => $address->address_2,
		'cardholdercity' => $address->city,
		'cardholderstate' =>  ShopFunctions::getStateByID($address->virtuemart_state_id),
		'cardholdercountry' => ShopFunctions::getCountryByID($address->virtuemart_country_id),
	    'cardholderpostcode' => $address->zip,
	    'cardholdersemail' => $order['details']['BT']->email,
	    'cardholdertelephonenumber' => $address->phone_1,
		
		//shilling fields
		'shippingname' => $shipaddress->first_name . " " . $address->last_name,
		'shippingaddr1' => $shipaddress->address_1,
		'shippingaddr2' => $shipaddress->address_2,
		'shippingcity' => $shipaddress->city,
		'shippingstate' =>  ShopFunctions::getStateByID($shipaddress->virtuemart_state_id),
		'shippingcountry' => ShopFunctions::getCountryByID($shipaddress->virtuemart_country_id),
		'shippingpostcode' => $shipaddress->zip,
		'shippingtelephonenumber' => $shipaddress->phone_1,
	    );

		 //Advanced Secuitems
	    if ($method->activateas) {
			if(preg_match('/value=\"([a-zA-Z0-9]{32})\"/', $this->_GetAdvancedSecuitems($secuitems, number_format($order['details']['BT']->order_total, 2, '.', ''), $method->shreference, $method->phrase, $method->referrer), $Matches))
				$post_variables['secuString'] = $Matches[1];			
	    }
		
		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['sh_custom'] = $return_context;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = number_format($order['details']['BT']->order_total, 2, '.', '');
		$dbValues['tax_id'] = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);
		
		//Check if we're running in live or test mode
		if($method->testmode){
			$url = JText::_('VMPAYMENT_SH_TEST_URL');
		} else {
			$url = JText::_('VMPAYMENT_SH_LIVE_URL');
		}
		
		//Build the redirect form
		$html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html .= '<form action="' . $url . '" method="post" name="vm_sh_form" >';
		$html.= '<input type="submit"  value="' . JText::_('VMPAYMENT_SH_REDIRECT_MESSAGE') . '" />';
		$html.= '<div align="left">';
		foreach ($post_variables as $name => $value) {
			$html.=  '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
		}
		$html.= '</div>';
		$html.= '</form></div>';
		$html.= ' <script type="text/javascript">';
		$html.= ' document.vm_sh_form.submit();';
		$html.= ' </script></body></html>';

		// 	2 = don't delete the cart, don't send email and don't redirect
		return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $dbValues['payment_name'], $new_status);
	 
	}

	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	function plgVmOnPaymentResponseReceived(&$html) {

		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getVar('on', 0);
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!class_exists('VirtueMartCart'))
		require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		if (!class_exists('shopFunctionsF'))
		require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

		$get_data = JRequest::get('get');
		$payment_name = $this->renderPluginName($method);

		if (!empty($get_data)) {
			vmdebug('plgVmOnPaymentResponseReceived', $get_data);
			$order_number = $get_data['on'];
			if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
			$payment_name = $this->renderPluginName($method);
		} else {
			vmError('Data received, but no order number');
			return;
		}
	
			$order = array();
			$this->logInfo('process OK', 'message');
			$order['order_status'] = 'U';
			$order['comments'] = JText::sprintf('Customer completed checkout. Waiting for confirmation from UPG.', $order_number);
			
			$modelOrder = VmModel::getModel('orders');
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);			
		
		$html = $this->_getPaymentResponseHtml($get_data, $payment_name);

		//We delete the old stuff
		// get the correct cart / session
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return true;
	}

	function plgVmOnUserPaymentCancel() {

		if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		
		$order_number = JRequest::getVar('on');
		if (!$order_number)
		return false;
		$db = JFactory::getDBO();
		$query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'";

		$db->setQuery($query);
		$virtuemart_order_id = $db->loadResult();

		if (!$virtuemart_order_id) {
			return null;
		}
		$this->handlePaymentUserCancel($virtuemart_order_id);

		return true;
	}

	/*
	 *   plgVmOnPaymentNotification() - This event is fired by Offline Payment. It can be used to validate the payment data as entered by the user.
	* Return:
	* Parameters:
	*  None
	*  @author Valerie Isaksen
	*/

	function plgVmOnPaymentNotification() {
		//Load the Get request and the Order
		$get_data = JRequest::get('get');
		//Only update successful orders
		if ($get_data["transactionnumber"] != "-1") {
			
			//If the class VirtueMartModelOrders doesn't exists, go get it
			if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			
			if(!isset($get_data['order_id'])) return;
			$order_number = $get_data['order_id'];
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($get_data['order_id']);
			if(!$virtuemart_order_id) return;

			//Get the Payment Instances
			$payment = $this->getDataByOrderId($virtuemart_order_id);
			$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);

			$this->_storeInternalData($method, $get_data, $virtuemart_order_id);
			$order = array();
			$this->logInfo('process OK', 'message');
			$order['order_status'] = 'F';
			$order['comments'] = JText::sprintf('Payment confirmed by UPG. Transaction complete.', $order_number);
			
			$modelOrder = VmModel::getModel('orders');
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

			$this->logInfo('Notification, sentOrderConfirmedEmail ' . $order_number . ' message');
			//// remove vmcart
			$this->emptyCart($payment->sh_custom);
		}
	}

	/**
	 * Get the Advanced Secuitems SecuString
	 * @param String $secuitems
	 * @param String $TransactionAmount
	 * @param String $shcode
	 * @param String $secustring
	 * @param String $referer
	 * @return String
	 */
	function _GetAdvancedSecuitems($secuitems, $TransactionAmount, $shcode,	$secustring, $referer){
		
		$post_data = "shreference=".$shcode;
		$post_data .= "&secuitems=".$secuitems;
		$post_data .= "&secuphrase=".$secustring;
		$post_data .= "&transactionamount=".$TransactionAmount;
		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_URL, JText::_('VMPAYMENT_SH_AS_URL'));
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_REFERER, $referer); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		$secuString = trim(curl_exec($ch));
		curl_close($ch);
		return $secuString;
	}

	/**
	 * Store checkout data for the order
	 * @param Unknown $method
	 * @param Array $sh_data
	 * @param String $virtuemart_order_id
	 */
	function _storeInternalData($method, $sh_data, $virtuemart_order_id) {
		$db = JFactory::getDBO ();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery ($query);
		$columns = $db->loadColumn (0);

		$post_msg = '';
		foreach ($sh_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			switch($key){
				case 'cv2avsresult': $response_fields['sh_response_cv2avsresult'] = $value; break;
				case 'upgcardtype': $response_fields['sh_response_cardtype'] = $value; break;
				case 'upgauthcode': $response_fields['sh_response_authcode'] = $value; break;
				case 'transactionnumber': $response_fields['sh_response_order_id'] = $value; break;
				case 'transactiontime': $response_fields['sh_response_transaction_date'] = $value; break;
			}
		}

		$response_fields['payment_name'] = $this->renderPluginName($method);
		$response_fields['order_number'] = $sh_data['order_id'];
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
	}

	function _parse_response ($response) {

		$matches = array();
		$rlines = explode ("\r\n", $response);

		foreach ($rlines as $line) {
			if (preg_match ('/([^:]+): (.*)/im', $line, $matches)) {
				continue;
			}

			if (preg_match ('/([0-9a-f]{32})/im', $line, $matches)) {
				return $matches;
			}
		}

		return $matches;
	}

	/**
	 * Display stored payment data for an order
	 * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
	 */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {

		if (!$this->selectedThisByMethodId($payment_method_id)) {
			return null; // Another method was selected, do nothing
		}

		if (!($paymentTable = $this->_getInternalData($virtuemart_order_id) )) {
			return '';
		}
		$this->getPaymentCurrency($paymentTable);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('securehosting_PAYMENT_NAME', $paymentTable->payment_name);

		$code = "sh_response_";
		foreach ($paymentTable as $key => $value) {
			if (substr($key, 0, strlen($code)) == $code) {
				$html .= $this->getHtmlRowBE($key, $value);
			}
		}
		$html .= '</table>' . "\n";
		return $html;
	}

	/**
	 * Get checkout data for an order
	 * @param String $virtuemart_order_id
	 * @param String $order_number
	 * @return string
	 */
	function _getInternalData($virtuemart_order_id, $order_number='') {
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
			$q .= " `order_number` = '" . $order_number . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}
		
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			return '';
		}
		return $paymentTable;
	}

	/**
	 * Process Callback Response
	 *
	 * @param array $data
	 * @return string DECLINED or AUTHORISED
	 * @access protected
	 */
	function _processCallback($callbackdata) {
		
		if ($callbackdata["transactionnumber"] == "-1") {
			// the transaction has failed for one reason or another... 
			$this->sendEmailToVendorAndAdmins("error with AUTHORISATION", $callbackdata["failurereason"]);
			return 'DECLINED';
		} else {
			return 'AUTHORISED';
		}

		return '';
	}

	function _getPaymentResponseHtml($get_data, $payment_name) {
		VmConfig::loadJLang('com_virtuemart');

		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('securehosting_PAYMENT_NAME', $payment_name);
		if (!empty($get_data)) {
			$html .= $this->getHtmlRow('securehosting_ORDER_NUMBER', $get_data['on']);
		}
		$html .= '</table>' . "\n";

		return $html;
	}

	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		if (preg_match('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 * @author: Valerie Isaksen
	 *
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 *
	 */
	protected function checkConditions($cart, $method, $cart_prices) {


		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
		OR
		($method->min_amount <= $amount AND ($method->max_amount == 0) ));

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id']))
		$address['virtuemart_country_id'] = 0;
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			if ($amount_cond) {
				return true;
			}
		}

		return false;
	}

	/**
	 * We must reimplement this triggers for joomla 1.7
	 */

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 * @author ValÃƒÂ©rie Isaksen
	 *
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {

		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Max Milbers
	 * @author ValÃƒÂ©rie isaksen
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 *
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 * @author Valerie Isaksen
	 * @author Max Milbers
	 */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	/*
	 * plgVmonSelectedCalculatePricePayment
	* Calculate the price (value, tax_id) of the selected method
	* It is called by the calculator
	* This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	* @author Valerie Isaksen
	* @cart: VirtueMartCart the current cart
	* @cart_prices: array the new cart prices
	* @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
	*
	*
	*/

	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 * @author Max Milbers
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	/**
	 * This method is fired when showing the order details in the frontend, for every orderline.
	 * It can be used to display line specific package codes, e.g. with a link to external tracking and
	 * tracing systems
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise
	 * @author Oscar van Eijk

	 public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
	 return null;
	 }
	 */
	function plgVmDeclarePluginParamsPaymentVM3($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}



}

// No closing tag
