<?php
class BitPOS_BitPOSPayment_StandardController extends Mage_Core_Controller_Front_Action
{

    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    protected function _isFinalStatus($status)
    {
        return ($status == 'RECEIVED_BROADCAST' || $status == 'CONFIRMED');
    }

    protected function _getApiQuoteId(){
        $quoteId = Mage::getSingleton('checkout/session')->getData('apiQuoteId');
        Mage::log('Returned quoteId ' . $quoteId);
        return $quoteId;
    }

    protected function _getApiOrderId(){
        $orderId = Mage::getSingleton('checkout/session')->getData('apiOrderId');
        Mage::log('Returned orderId ' . $orderId);
        return $orderId;
    }



    protected function _getOrderStatus()
    {
        $orderId = $this->_getCheckout()->getData('apiToken');
        mage::log('Called custom ' . __METHOD__ . ' with order id ' . orderId);

        if (Mage::getStoreConfig('payment/bitpospayment/testnet') == 1)
            $url = "https://rest.test.bitpos.me/services/webpay/order/status/";
        else
            $url = "https://rest.bitpos.me/services/webpay/order/status/";

        $url .= $orderId;

        $username = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/bitpospayment/username'));
        $password = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/bitpospayment/password'));
        Mage::Log("Username: $username Password: ********");

        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $resArray = json_decode($result);
        $encodedOrderId = $resArray->{'encodedOrderId'};
        $status = $resArray->{'status'};

        return $status;
    }

    public function successAction()
    {
        mage::log('Called custom ' . __METHOD__ . ' with order id ' . orderId);


        $result = $this->_getOrderStatus();

        Mage::log('BitPOS encoded order status is ' . $result);

        $order = Mage::getSingleton('sales/order');
        $order->load($this->_getApiOrderId());
        $state = $order->getState();

        if ($this->_isFinalStatus($result)) {
            if ($state == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $this->_createInvoice($order);
                //sets the status to 'pending'.
                $msg = 'Payment completed via BitPOS.';
                $order->setState(Mage_Sales_Model_Order::STATE_NEW, true, $msg, false);
                $order->save();

                /* @var $quote Mage_Sales_Model_Quote */
                $quote = Mage::getSingleton('checkout/session')->getQuote();
                $quote->setIsActive(false)->save();
            }

            $this->_redirect('checkout/onepage/success', array('_secure' => true));
        } else {
            Mage::Log('Could not complete order, result: ' . $result);

            $this->_redirect('checkout/onepage/failure', array('_secure' => true));

            $this->cancelAction();
        }
    }

    protected function _cancelAction()
    {
        Mage::Log('Called ' . __METHOD__);

        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($this->_getApiQuoteId());
        /* @var $quote Mage_Sales_Model_Quote */
        $quote = $session->getQuote();
        $quote->setIsActive(false)->save();
        $quote->delete();

        $orderId = $this->_getApiOrderId();
        Mage::Log('Cancelling order ' . $orderId);
        if ($orderId) {
            $order = Mage::getSingleton('sales/order');
            $order->load($orderId);
            if ($order->getId()) {
                $state = $order->getState();
                if($state == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT){
                    $order->cancel()->save();
                    Mage::getSingleton('core/session')->addNotice('Your order has been cancelled.');
                }
            }
        }
        $this->_redirect('checkout/cart');
    }


    public function failureAction(){
        Mage::Log('Called ' . __METHOD__);
        $this->cancelAction();
    }

    public function cancelAction(){
        Mage::Log('Called ' . __METHOD__);
        $this->_cancelAction();
    }

    protected function _createInvoice($orderObj)
    {
        if (!$orderObj->canInvoice()) {
            return false;
        }
        $invoice = $orderObj->prepareInvoice();
        $invoice->register();
        if($invoice->canCapture()){
            $invoice->capture();
        }
        $invoice->save();
        $orderObj->addRelatedObject($invoice);
        return $invoice;
    }
}