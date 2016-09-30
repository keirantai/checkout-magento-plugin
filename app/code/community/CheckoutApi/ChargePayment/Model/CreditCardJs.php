<?php

/**
 * Class for CreditCardJs payment method
 *
 * Class CheckoutApi_ChargePayment_Model_CreditCardJs
 *
 */
class CheckoutApi_ChargePayment_Model_CreditCardJs extends CheckoutApi_ChargePayment_Model_Checkout
{
    protected $_code            = CheckoutApi_ChargePayment_Helper_Data::CODE_CREDIT_CARD_JS;
    protected $_canUseInternal  = false;

    protected $_formBlockType = 'chargepayment/form_checkoutApiJs';
    protected $_infoBlockType = 'chargepayment/info_checkoutApiJs';

    const RENDER_MODE           = 2;
    const RENDER_NAMESPACE      = 'CheckoutIntegration';
    const CARD_FORM_MODE        = 'cardTokenisation';

    const PAYMENT_MODE_MIXED            = 'mixed';
    const PAYMENT_MODE_CARD             = 'card';
    const PAYMENT_MODE_LOCAL_PAYMENT    = 'localpayment';

    /**
     * Return to checkout page
     *
     * @return bool|string
     *
     */
    public function getCheckoutRedirectUrl() {
        $controllerName     = (string)Mage::app()->getFrontController()->getRequest()->getControllerName();

        if ($controllerName === 'onepage') {
            return false;
        }

        $requestData        = Mage::app()->getRequest()->getParam('payment');
        $cardToken          = !empty($requestData['checkout_card_token']) ? $requestData['checkout_card_token'] : null;
        $lpRedirectUrl      = !empty($requestData['lp_redirect_url']) ? $requestData['lp_redirect_url'] : NULL;
        $session            = Mage::getSingleton('chargepayment/session_quote');

        if (!is_null($cardToken) || !is_null($lpRedirectUrl)) {
            return false;
        }

        $params['method'] = $this->_code;

        $session->setJsCheckoutApiParams($params);

        return Mage::helper('checkout/url')->getCheckoutUrl();
    }

    /**
     * Return redirect url for 3d and local payments
     *
     * @return bool
     */
    public function getOrderPlaceRedirectUrl() {
        $session    = Mage::getSingleton('chargepayment/session_quote');
        $isLocal    = $session->getIsLocalPayment();
        $lpUrl      = $session->getLpRedirectUrl();
        $is3d       = $session->getIs3d();
        $is3dUrl    = $session->getPaymentRedirectUrl();

        $session
            ->setIsLocalPayment(false)
            ->setLpRedirectUrl(false)
            ->setIs3d(false)
            ->setPaymentRedirectUrl(false);

        if ($isLocal) {
            $this->restoreQuoteSession();

            return $lpUrl;
        }

        if ($is3d && $is3dUrl) {
            $this->restoreQuoteSession();

            return $is3dUrl;
        }

        return false;
    }

    /**
     * Create Payment Token
     *
     * @return array
     *
     * @version 20160203
     */
    public function getPaymentToken() {
        $Api                = CheckoutApi_Api::getApi(array('mode' => $this->getEndpointMode()));
        $isCurrentCurrency  = $this->getIsUseCurrentCurrency();
        $price              = $isCurrentCurrency ? $this->_getQuote()->getGrandTotal() : $this->_getQuote()->getBaseGrandTotal();
        $priceCode          = $isCurrentCurrency ? $this->getCurrencyCode() : Mage::app()->getStore()->getBaseCurrencyCode();

		// does not create charge on checkout.com if amount is 0
        if (empty($price)) {
            return array();
        }
		
        $amount     = $Api->valueToDecimal($price, $priceCode);
        $config     = $this->_getCharge($amount);

        $paymentTokenCharge = $Api->getPaymentToken($config);

        if ($Api->getExceptionState()->hasError()) {
            Mage::log($Api->getExceptionState()->getErrorMessage(), null, $this->_code.'.log');
            Mage::log($Api->getExceptionState(), null, $this->_code.'.log');

            return array(
                'success' => false,
                'token'   => '',
                'message' => $Api->getExceptionState()->getErrorMessage()
            );
        }

        $paymentTokenReturn     = array(
            'success' => false,
            'token'   => '',
            'message' => ''
        );

        if($paymentTokenCharge->isValid()){
            $paymentToken                   = $paymentTokenCharge->getId();
            $paymentTokenReturn['token']    = $paymentToken ;
            $paymentTokenReturn['success']  = true;

            $paymentTokenReturn['customerEmail']    = $config['postedParam']['email'];
            $paymentTokenReturn['customerName']     = $config['postedParam']['customerName'];
            $paymentTokenReturn['value']            = $amount;
            $paymentTokenReturn['currency']         = $priceCode;

            Mage::getSingleton('chargepayment/session_quote')->addPaymentToken($paymentToken);
        }else {
            if($paymentTokenCharge->getEventId()) {
                $eventCode = $paymentTokenCharge->getEventId();
            }else {
                $eventCode = $paymentTokenCharge->getErrorCode();
            }
            $paymentTokenReturn['message'] = Mage::helper('payment')->__( $paymentTokenCharge->getExceptionState()->getErrorMessage().
                ' ( '.$eventCode.')');

            Mage::logException($paymentTokenReturn['message']);
        }

        return $paymentTokenReturn;
    }

    /**
     * Return Quote from session
     *
     * @param null $quoteId
     * @return mixed
     *
     * @version 20160202
     */
    private function _getQuote($quoteId = null) {
        $quoteId = (int)$quoteId;
        if (!empty($quoteId)) {
            return Mage::getModel('sales/quote')->load($quoteId);
        }
        return Mage::getSingleton('checkout/session')->getQuote();
    }

    /**
     * Get Public Shared Key
     *
     * @return mixed
     *
     * @version 20160407
     */
    public function getPublicKeyWebHook() {
        return Mage::helper('chargepayment')->getConfigData($this->_code, 'publickey_web');
    }

    /**
     * Return true if is 3D
     *
     * @return bool
     *
     * @version 20160202
     */
    public function getIs3D() {
        return Mage::helper('chargepayment')->getConfigData($this->_code, 'is_3d');
    }

    /**
     * Return the timeout value for a request to the gateway.
     *
     * @return mixed
     *
     * @version 20160203
     */
    public function getTimeout() {
        return Mage::helper('chargepayment')->getConfigData($this->_code, 'timeout');
    }

    /**
     * Validate payment method information object
     *
     * @return Mage_Payment_Model_Abstract
     *
     * @version 20160203
     */
    public function validate() {
        return $this;
    }

    /**
     * For authorize
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this
     * @throws Mage_Core_Exception
     *
     * @version 20160204
     */
    public function authorize(Varien_Object $payment, $amount) {
		// does not create charge on checkout.com if amount is 0
        if (empty($amount)) {
            return $this;
        }
		
        $requestData        = Mage::app()->getRequest()->getParam('payment');
        $session            = Mage::getSingleton('chargepayment/session_quote');
        $isCurrentCurrency  = $this->getIsUseCurrentCurrency();
        $quoteId            = null;
        $order              = $payment->getOrder();

        /* Local Payment */
        $lpRedirectUrl  = !empty($requestData['lp_redirect_url']) ? $requestData['lp_redirect_url'] : NULL;
        $lpName         = !empty($requestData['lp_name']) ? $requestData['lp_name'] : NULL;
        $isLocalPayment = $this->isLocalPayment();

        if ($isLocalPayment && !is_null($lpRedirectUrl) && !is_null($lpName)) {
            /* Get Current token from url */
            $query = parse_url($lpRedirectUrl, PHP_URL_QUERY);
            parse_str($query, $params);
            $currentToken = $params['paymentToken'];

            $Api            = CheckoutApi_Api::getApi(array('mode' => $this->getEndpointMode()));
            $verifyParams   = array('paymentToken' => $currentToken, 'authorization' => $this->_getSecretKey());
            $response       = $Api->verifyChargePaymentToken($verifyParams);

            if (is_object($response) && method_exists($response, 'toArray')) {
                Mage::log($response->toArray(), null, $this->_code.'.log');
            }

            if ($Api->getExceptionState()->hasError()) {
                Mage::log($Api->getExceptionState()->getErrorMessage(), null, $this->_code.'.log');
                Mage::log($Api->getExceptionState(), null, $this->_code.'.log');
                $errorMessage = Mage::helper('chargepayment')->__('Your payment was not completed.'. $Api->getExceptionState()->getErrorMessage().' and try again or contact customer support.');
                Mage::throwException($errorMessage);
            }

            if(!$response->isValid() || !$this->_responseValidation($response)) {
                return $this;
            }

            $Api->updateTrackId($response, $payment->getOrder()->getIncrementId());

            $session->addCheckoutLocalPaymentToken($currentToken);

            $session
                ->setLpRedirectUrl($lpRedirectUrl)
                ->setIsLocalPayment(true)
                ->setLpName($lpName);

            $payment->setTransactionId($response->getId());
            $payment->setIsTransactionClosed(0);
            $payment->setAdditionalInformation('use_current_currency', $isCurrentCurrency);
            $payment->setIsTransactionPending(true);

            return $this;
        }

        /* Normal Payment */
        $cardToken = $payment->getData('cc_type');
        if (empty($cardToken)) {
            $cardToken = !empty($requestData['checkout_card_token']) ? $requestData['checkout_card_token'] : NULL;
        } else {
            $quoteId = $payment->getData('cc_owner');
        }
        $isDebug = $this->isDebug();

        if (is_null($cardToken)) {
            Mage::throwException(Mage::helper('chargepayment')->__('Authorize action is not available.'));
            Mage::log('Empty Card Token', null, $this->_code.'.log');
        }

        $price              = $isCurrentCurrency ? $this->_getQuote($quoteId)->getGrandTotal() : $this->_getQuote($quoteId)->getBaseGrandTotal();
        $priceCode          = $isCurrentCurrency ? $this->getCurrencyCode() : Mage::app()->getStore()->getBaseCurrencyCode();

        $Api    = CheckoutApi_Api::getApi(array('mode' => $this->getEndpointMode()));
        $amount = $Api->valueToDecimal($price, $priceCode);
        $config = $this->_getCharge($amount, $quoteId);

        $config['postedParam']['trackId']   = $payment->getOrder()->getIncrementId();
        $config['postedParam']['cardToken'] = $cardToken;

        $autoCapture    = $this->_isAutoCapture();
        $result         = $Api->createCharge($config);

        if (is_object($result) && method_exists($result, 'toArray')) {
            Mage::log($result->toArray(), null, $this->_code.'.log');
        }

        if ($Api->getExceptionState()->hasError()) {
            Mage::log($Api->getExceptionState()->getErrorMessage(), null, $this->_code.'.log');
            Mage::log($Api->getExceptionState(), null, $this->_code.'.log');
            $errorMessage = Mage::helper('chargepayment')->__('Your payment was not completed.'. $Api->getExceptionState()->getErrorMessage().' and try again or contact customer support.');
            Mage::throwException($errorMessage);
        }

        $toValidate = array(
            'currency' => $priceCode,
            'value'    =>  $Api->valueToDecimal($price, $priceCode),
        );

        $validateRequest = $Api->validateRequest($toValidate,$result);

        if($result->isValid()) {
            if ($this->_responseValidation($result)) {
                $redirectUrl    = $result->getRedirectUrl();
                $entityId       = $result->getId();

                /* is 3D payment */
                if ($redirectUrl && $entityId) {
                    $payment->setAdditionalInformation('payment_token', $entityId);
                    $payment->setAdditionalInformation('payment_token_url', $redirectUrl);

                    $session->addPaymentToken($entityId);
                    $session
                        ->setIs3d(true)
                        ->setPaymentRedirectUrl($redirectUrl)
                        ->setEndpointMode($this->getEndpointMode())
                        ->setSecretKey($this->_getSecretKey())
                        ->setNewOrderStatus($this->getNewOrderStatus())
                    ;
                } else {
                    $payment->setTransactionId($entityId);
                    $payment->setIsTransactionClosed(0);
                    $payment->setAdditionalInformation('use_current_currency', $isCurrentCurrency);

                    if ($autoCapture) {
                        if($validateRequest['status']!== 1 && (int)$result->getResponseCode() !== CheckoutApi_ChargePayment_Model_Checkout::CHECKOUT_API_RESPONSE_CODE_APPROVED ){
                            $order->addStatusHistoryComment('Suspected fraud - Please verify amount and quantity.', false);
                            $payment->setIsFraudDetected(true);
                        }
                        $payment->setIsTransactionPending(true);
                    }
                    $session->setIs3d(false);
                }
            }
        } else {
            if ($isDebug) {
                /* Authorize processing error response. */
                $errors             = $result->toArray();

                if (!empty($errors['errorCode'])) {
                    $responseCode       = (int)$errors['errorCode'];
                    $responseMessage    = (string)$errors['message'];
                    $errorMessage       = "Error Code - {$responseCode}. Message - {$responseMessage}.";
                } else {
                    $errorMessage = Mage::helper('chargepayment')->__('Authorize action is not available.');
                }
            } else {
                $errorMessage = Mage::helper('chargepayment')->__('Authorize action is not available.');
            }

            Mage::throwException($errorMessage);
            Mage::log($result->printError(), null, $this->_code.'.log');
        }

        return $this;
    }

    /**
     * Return base data for charge
     *
     * @param null $amount
     * @param null $quoteId
     * @return array
     *
     * @version 20160204
     */
    private function _getCharge($amount = null, $quoteId = null) {
        $secretKey          = $this->_getSecretKey();
        $isCurrentCurrency  = $this->getIsUseCurrentCurrency();
        $quote              = $this->_getQuote($quoteId);

        $billingAddress     = $quote->getBillingAddress();
        $shippingAddress    = $quote->getBillingAddress();
        $orderedItems       = $quote->getAllItems();
        $currencyDesc       = $isCurrentCurrency ? $this->getCurrencyCode() : Mage::app()->getStore()->getBaseCurrencyCode();
        $amountCents        = $amount;
        $chargeMode         = $this->getIs3D();

        $street = Mage::helper('customer/address')
            ->convertStreetLines($shippingAddress->getStreet(), 2);

        $billingAddressConfig = array (
            'addressLine1'  => $street[0],
            'addressLine2'  => $street[1],
            'postcode'      => $billingAddress->getPostcode(),
            'country'       => $billingAddress->getCountry(),
            'city'          => $billingAddress->getCity(),
            'state'         => $billingAddress->getRegion(),
            'phone'         => array('number' => $billingAddress->getTelephone())
        );

        $billingPhoneNumber = $billingAddress->getTelephone();

        if (!empty($billingPhoneNumber)) {
            $billingAddressConfig['phone'] = array('number' => $billingPhoneNumber);
        }

        $shippingAddressConfig = array(
            'addressLine1'       => $street[0],
            'addressLine2'       => $street[1],
            'postcode'           => $shippingAddress->getPostcode(),
            'country'            => $shippingAddress->getCountry(),
            'city'               => $shippingAddress->getCity()
        );

        $phoneNumber = $shippingAddress->getTelephone();

        if (!empty($phoneNumber)) {
            $shippingAddressConfig['phone'] = array('number' => $phoneNumber);
        }

        $products = array();

        foreach ($orderedItems as $item) {
            $product        = Mage::getModel('catalog/product')->load($item->getProductId());
            $productPrice   = $item->getPrice();
            $productPrice   = is_null($productPrice) || empty($productPrice) ? 0 : $productPrice;
            $productImage   = $product->getImage();

            $products[] = array (
                'name'       => $item->getName(),
                'sku'        => $item->getSku(),
                'price'      => $productPrice,
                'quantity'   => $item->getQty(),
                'image'      => $productImage != 'no_selection' && !is_null($productImage) ? Mage::helper('catalog/image')->init($product , 'image')->__toString() : '',
            );
        }

        $config                     = array();
        $config['authorization']    = $secretKey;

        $config['postedParam'] = array (
            'trackId'           => NULL,
            'customerName'      => $billingAddress->getName(),
            'email'             => Mage::helper('chargepayment')->getCustomerEmail($quoteId),
            'value'             => $amountCents,
            'chargeMode'        => $chargeMode,
            'currency'          => $currencyDesc,
            'billingDetails'    => $billingAddressConfig,
            'shippingDetails'   => $shippingAddressConfig,
            'products'          => $products,
            'customerIp'        => Mage::helper('core/http')->getRemoteAddr(),
            'metadata'          => array(
                'server'            => Mage::helper('core/http')->getHttpUserAgent(),
                'quoteId'           => $quote->getId(),
                'magento_version'   => Mage::getVersion(),
                'plugin_version'    => Mage::helper('chargepayment')->getExtensionVersion(),
                'lib_version'       => CheckoutApi_Client_Constant::LIB_VERSION,
                'integration_type'  => 'JS',
                'time'              => Mage::getModel('core/date')->date('Y-m-d H:i:s')
            )
        );

        $autoCapture = $this->_isAutoCapture();

        $config['postedParam']['autoCapture']  = $autoCapture ? CheckoutApi_Client_Constant::AUTOCAPUTURE_CAPTURE : CheckoutApi_Client_Constant::AUTOCAPUTURE_AUTH;;
        $config['postedParam']['autoCapTime']  = self::AUTO_CAPTURE_TIME;

        return $config;
    }

    /**
     * Return true if local payment
     *
     * @return bool
     *
     * @version 20160425
     */
    public function isLocalPayment() {
        $paymentMode = Mage::helper('chargepayment')->getConfigData($this->_code, 'payment_mode');

        return $paymentMode === self::PAYMENT_MODE_MIXED
            || $paymentMode === self::PAYMENT_MODE_LOCAL_PAYMENT ? true : false;
    }
}