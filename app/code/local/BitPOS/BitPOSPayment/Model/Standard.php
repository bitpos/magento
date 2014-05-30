<?php

class BitPOS_BitPOSPayment_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_isInitializeNeeded = true;
    protected $_code = 'bitpospayment';
    protected $_apiToken = null;

// protected $_formBlockType = 'bitpospayment/dispfields';

    public function authorize(Varien_Object $payment, $amount)
    {
        $data = $payment->getData();
        Mage::getUrl('bitpospayment/arithmetic/checkout');
    }

    /**
     * Return URL to redirect the customer to.
     * Called after 'place order' button is clicked.
     * Called after order is created and saved.
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        /* Mage log is your friend.
        * While it shouldn't be on in production,
        * it makes debugging problems with your api much easier.
        * The file is in magento-root/var/log/system.log
        */
        mage::log('Called custom ' . __METHOD__);
        $url = $this->getConfigData('redirecturl');

        if (!isset($this->_apiToken)) {
            $this->_apiToken = Mage::getSingleton('checkout/session')->getData('apiToken');
        }

        if ($this->getConfigData('testnet') == 1)
            return "https://payment.test.bitpos.me/payment.jsp?orderId=" . $this->_apiToken;
        else
            return "https://payment.bitpos.me/payment.jsp?orderId=" . $this->_apiToken;
    }


    /**
     *
     * <payment_action>Sale</payment_action>
     * Initialize payment method. Called when purchase is complete.
     * Order is created after this method is called.
     *
     * @param string $paymentAction
     * @param Varien_Object $stateObject
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function initialize($paymentAction, $stateObject)
    {
        Mage::log('Called ' . __METHOD__ . ' with payment ' . $paymentAction);
        parent::initialize($paymentAction, $stateObject);

        //Payment is also used for refund and other backend functions.
        //Verify this is a sale before continuing.
        if ($paymentAction != 'sale') {
            return $this;
        }

        //Set the default state of the new order.
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT; // state now = 'pending_payment'
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        //Extract order details and send to mockpay api. Get api token and save it to checkout/session.
        try {
            $this->_customBeginPayment();
        } catch (Exception $e) {
            Mage::log($e);
            Mage::throwException($e->getMessage());
        }
        return $this;
    }

    /**
     *
     * Extract cart/quote details and send to api.
     * Respond with token
     * @throws SoapFault
     * @throws Mage_Exception
     * @throws Exception
     */
    protected function _customBeginPayment()
    {
        Mage::log('Called ' . __METHOD__);

        $sessionCheckout = Mage::getSingleton('checkout/session');

        $quoteId = $sessionCheckout->getQuoteId();
        $quote = Mage::getModel("sales/quote")->load($quoteId);
        $grandTotal = $quote->getData('grand_total');
        $subTotal = $quote->getSubtotal();
        $shippingHandling = ($grandTotal-$subTotal);
        Mage::Log("Sub Total: $subTotal | Shipping & Handling: $shippingHandling | Grand Total $grandTotal");

        $order_id = $quote->getReservedOrderId();
        Mage::Log("Order id is: " . $order_id);

        Mage::Log("Testnet config: " . $this->getConfigData('testnet') );

        if ($this->getConfigData('testnet') == 1)
            $url = "https://rest.test.bitpos.me/services/webpay/order/create";
        else
            $url = "https://rest.bitpos.me/services/webpay/order/create";


        $oUrl = Mage::getModel('core/url');
        $apiHrefSuccess = $oUrl->getUrl("bitpospayment/standard/success");
        $apiHrefFailure = $oUrl->getUrl("bitpospayment/standard/failure");
        $apiHrefCancel = $oUrl->getUrl("bitpospayment/standard/cancel");

        $arr = array('currency' => 'AUD',
            'amount' => $grandTotal * 100,
            'reference' => $order_id,
            'description' => 'Products',
            'successURL' => $apiHrefSuccess,
            'failureURL' => $apiHrefFailure);


        $data = json_encode($arr);

        Mage::Log("JSON Encoded order: " . $data);

        $username = Mage::helper('core')->decrypt($this->getConfigData('username'));
        $password = Mage::helper('core')->decrypt($this->getConfigData('password'));
        Mage::Log("Username: $username Password: ********");

        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");  //for updating we have to use PUT method.
        curl_setopt($ch, CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
        curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $resArray = json_decode($result);
        $encodedOrderId = $resArray->{'encodedOrderId'};

        Mage::log('BitPOS encoded order id is ' . $encodedOrderId);

        $sessionCheckout->setData('apiToken', $encodedOrderId);

    }
}

?>
