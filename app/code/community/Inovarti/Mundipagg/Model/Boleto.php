<?php

/**
 *
 * @category   Inovarti
 * @package    Inovarti_Mundipagg
 * @author     Suporte <suporte@inovarti.com.br>
 */
class Inovarti_Mundipagg_Model_Boleto extends Inovarti_Mundipagg_Model_Api {

    protected $_isGateway = false;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = true; //usar admin
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_code = 'mundipagg_boleto';
    protected $_infoBlockType = 'mundipagg/info_boleto';
    protected $_order;
    protected $transaction_id = null;

    public function initialize($paymentAction, $stateObject) {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();

        $payment->setAmountAuthorized($order->getTotalDue());
        $this->processPayment($payment, $order->getBaseTotalDue());
        return $this;
    }

    public function processPayment(Varien_Object $payment, $amount) {
        ini_set('soap.wsdl_cache_enabled', '0');
        $billing = $payment->getOrder()->getBillingAddress();
        $order = $payment->getOrder();
        $order_id = $order->getIncrementId();
        $totals = $this->formataValor($amount);

        $parametros = array('createOrderRequest' => array(
                'MerchantKey' => $this->getmerchantKey(),
                'OrderReference' => $order_id,
                'AmountInCents' => $totals,
                'AmountInCentsToConsiderPaid' => $totals,
                'EmailUpdateToBuyerEnum' => 'No',
                'CurrencyIsoEnum' => 'BRL',
                'ShoppingCartCollection' => array('ShoppingCart' => array(
                        'FreightCostInCents' => $this->formataValor($order->getShippingAmount()),
                        'ShoppingCartItemCollection' => $this->getItensProductOrder()
                    )
                ),
                'Buyer' => $this->getBuyer($payment),
                'BoletoTransactionCollection' => array('BoletoTransaction' => array(
                        'AmountInCents' => $totals,
                        'Instructions' => $this->getConfigData('message'),
                        'NossoNumero' => $order_id,
                        'DaysToAddInBoletoExpirationDate' => $this->getConfigData('daystoaddinboletoexpirationdate'),
                        'TransactionReference' => $this->getConfigData('transactionreference'),
                    ))
        ));


        $authorize = $this->getService()->CreateOrder($parametros);
        $this->_debug('processPayment():$resultado=' . print_r($authorize, 1));

        $resultado = (isset($authorize->CreateOrderResult->BoletoTransactionResultCollection->BoletoTransactionResult)) ?
                $authorize->CreateOrderResult->BoletoTransactionResultCollection->BoletoTransactionResult :
                0;
        if (isset($authorize->CreateOrderResult->Success) && $authorize->CreateOrderResult->Success == true) {
            if (count($resultado) == 1) {
                $this->_addTransaction($payment, $resultado->TransactionKey, Mage_Sales_Model_Order_Payment_Transaction::TYPE_ORDER, $resultado);
            } else {
                foreach ($resultado as $key => $trans) {
                    $this->_addTransaction($payment, $resultado->TransactionKey, Mage_Sales_Model_Order_Payment_Transaction::TYPE_ORDER, $trans);
                }
            }
            //Grava retorno
            $info = $this->getInfoInstance();
            $info->setLastTransId($resultado->TransactionKey);
            $info->setOrderKey($authorize->CreateOrderResult->OrderKey);
            $info->setOrderReference($authorize->CreateOrderResult->OrderReference);
            $info->setBoletoUrl($resultado->BoletoUrl);
            $this->transaction_id = $resultado->TransactionKey;
            $info->save();
            //Fim Grava retorno
        } else {
            if (isset($authorize->CreateOrderResult->ErrorReport->ErrorItemCollection->ErrorItem)) {
                $payment->setSkipOrderProcessing(true);
                Mage::throwException(Mage::helper('mundipagg')->__($authorize->CreateOrderResult->ErrorReport->ErrorItemCollection->ErrorItem->Description));
                return $this;
            } else {
                $payment->setSkipOrderProcessing(true);
                Mage::throwException(Mage::helper('mundipagg')->__($this->MessageGateway($resultado->AcquirerReturnCode)));
                return $this;
            }
        }
    }

}
