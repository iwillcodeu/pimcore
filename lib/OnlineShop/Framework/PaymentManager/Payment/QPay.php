<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @category   Pimcore
 * @package    EcommerceFramework
 * @copyright  Copyright (c) 2009-2016 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */


namespace OnlineShop\Framework\PaymentManager\Payment;

class QPay implements IPayment
{
    // supported hashing algorithms
    const HASH_ALGO_MD5 = 'md5';
    const HASH_ALGO_HMAC_SHA512 = 'hmac_sha512';

    /**
     * @var string
     */
    protected $secret;

    /**
     * @var string
     */
    protected $customer;

    /**
     * @var string
     */
    protected $toolkitPassword;

    /**
     * @var string
     */
    protected $paymenttype = 'SELECT';

    /**
     * Keep old implementation for backwards compatibility
     * @var string
     */
    protected $hashAlgorithm = self::HASH_ALGO_MD5;

    /**
     * @var string[]
     */
    protected $authorizedData;

    /**
     * @var \Zend_Locale
     */
    protected $currencyLocale;

    /**
     * Whitelist of optional properties allowed for payment init
     * @var array
     */
    protected $optionalPaymentProperties = [
        'imageURL',
        'confirmURL',
        'confirmMail',
        'displayText',
        'shopId' // value=mobile for mobile checkout page
    ];

    /**
     * @param \Zend_Config $xml
     *
     * @throws \Exception
     */
    public function __construct(\Zend_Config $xml)
    {
        $settings = $xml->config->{$xml->mode};
        if($settings->secret == '' || $settings->customer == '')
        {
            throw new \Exception('payment configuration is wrong. secret or customer is empty !');
        }

        $this->secret = $settings->secret;
        $this->customer = $settings->customer;
        $this->toolkitPassword = $settings->toolkitPassword;

        if ($settings->paymenttype) {
            $this->paymenttype = $settings->paymenttype;
        }

        $this->initHashAlgorithm($settings);
        $this->initOptionalPaymentProperties($settings);

        $this->currencyLocale = \OnlineShop\Framework\Factory::getInstance()->getEnvironment()->getCurrencyLocale();
    }

    /**
     * Initialize hash algorithm
     *
     * @param \Zend_Config $settings
     */
    protected function initHashAlgorithm(\Zend_Config $settings)
    {
        if ($settings->hashAlgorithm) {
            $hashAlgorithm = (string) $settings->hashAlgorithm;
            if (!in_array($hashAlgorithm, [static::HASH_ALGO_MD5, static::HASH_ALGO_HMAC_SHA512])) {
                throw new \InvalidArgumentException(sprintf('%s is no valid hash algorithm', $hashAlgorithm));
            }

            $this->hashAlgorithm = $hashAlgorithm;
        }
    }

    /**
     * Initialize optional payment properties from config
     *
     * @param \Zend_Config $settings
     */
    protected function initOptionalPaymentProperties(\Zend_Config $settings)
    {
        if ($settings->optionalPaymentProperties instanceof \Zend_Config) {
            $configArray = $settings->optionalPaymentProperties->toArray();
            if (isset($configArray['property'])) {
                // Zend_Config behaves differently if there's just a single object in the set
                if (is_array($configArray['property'])) {
                    foreach ($configArray['property'] as $optionalProperty) {
                        $this->optionalPaymentProperties[] = $optionalProperty;
                    }
                } elseif (is_string($configArray['property'])) {
                    $this->optionalPaymentProperties[] = $configArray['property'];
                }
            }

            $this->optionalPaymentProperties = array_unique($this->optionalPaymentProperties);
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Qpay';
    }

    /**
     * start payment
     * @param \OnlineShop\Framework\PriceSystem\IPrice $price
     * @param array                       $config
     *
     * @return \Zend_Form
     * @throws \Exception
     * @throws \Zend_Form_Exception
     */
    public function initPayment(\OnlineShop\Framework\PriceSystem\IPrice $price, array $config)
    {
        // check params
        $required = [
            'successURL' => null
            , 'cancelURL' => null
            , 'failureURL' => null
            , 'serviceURL' => null
            , 'orderDescription' => null
            , 'orderIdent' => null
            , 'language' => null
        ];

        $check = array_intersect_key($config, $required);
        if (count($required) != count($check)) {
            throw new \Exception(sprintf('required fields are missing! required: %s', implode(', ', array_keys(array_diff_key($required, $check)))));
        }

        // collect payment data
        $paymentData['secret'] = $this->secret;
        $paymentData['customerId'] = $this->customer;
        $paymentData['amount'] = round($price->getAmount(), 2);
        $paymentData['currency'] = $price->getCurrency()->getShortName();
        $paymentData['duplicateRequestCheck'] = 'yes';

        // can be overridden by adding paymentType to optional properties and passing its value in config
        $paymentData['paymentType'] = $this->paymenttype;

        foreach ($required as $property => $null) {
            $paymentData[$property] = $config[$property];
        }

        // handle optional properties
        foreach ($this->optionalPaymentProperties as $optionalProperty) {
            if (array_key_exists($optionalProperty, $config)) {
                $paymentData[$optionalProperty] = $config[$optionalProperty];
            }
        }

        // set fingerprint order
        $paymentData['requestFingerprintOrder'] = ''; // make sure the key is in the order array
        $paymentData['requestFingerprintOrder'] = implode(',', array_keys($paymentData));

        // compute fingerprint
        $fingerprint = $this->computeFingerprint(array_values($paymentData));

        // create form
        $form = new \Zend_Form(array('disableLoadDefaultDecorators' => false));
        $form->setAction('https://www.qenta.com/qpay/init.php');
        $form->setMethod('post');

        // omit these keys from the form
        $blacklistedFormKeys = ['secret'];
        foreach ($paymentData as $property => $value) {
            if (in_array($property, $blacklistedFormKeys)) {
                continue;
            }

            $form->addElement('hidden', $property, ['value' => $value]);
        }

        // add fingerprint to request
        $form->addElement('hidden', 'requestFingerprint', ['value' => $fingerprint]);

        // add submit button
        $form->addElement('submit', 'submitbutton');

        return $form;
    }

    /**
     * @param mixed $response
     *
     * @return \OnlineShop\Framework\PaymentManager\IStatus
     * @throws \Exception
     */
    public function handleResponse($response)
    {
        //unsetting response document because it is not needed (and spams up log files)
        unset($response['document']);

        // check required fields
        $required = [
            'orderIdent' => null
        ];

        $authorizedData = [
            'orderNumber' => null
            , 'language' => null
            , 'amount' => null
            , 'currency' => null
        ];


        // check fields
        $check = array_intersect_key($response, $required);
        if(count($required) != count($check))
        {
            throw new \Exception( sprintf('required fields are missing! required: %s', implode(', ', array_keys(array_diff_key($required, $authorizedData)))) );
        }

        // build fingerprint params
        $fingerprintParams = [];
        $fingerprintFields = explode(',', $response['responseFingerprintOrder']);
        foreach ($fingerprintFields as $field) {
            $fingerprintParams[] = $field === 'secret' ? $this->secret : $response[$field];
        }

        // compute and check fingerprint
        $fingerprint = $this->computeFingerprint($fingerprintParams);
        if ($response["paymentState"] !== "FAILURE" && $fingerprint != $response['responseFingerprint']) {
            // fingerprint is wrong, ignore this response
            return new \OnlineShop\Framework\PaymentManager\Status(
                $response['orderIdent']
                , $response['orderNumber']
                , $response['avsResponseMessage'] ?: $response['message']
                , \OnlineShop\Framework\PaymentManager\IStatus::STATUS_CANCELLED
            );
        }

        // handle
        $authorizedData = array_intersect_key($response, $authorizedData);
        $this->setAuthorizedData( $authorizedData );


        // restore price object for payment status
        $price = new \OnlineShop\Framework\PriceSystem\Price($authorizedData['amount'], new \Zend_Currency($authorizedData['currency'], \OnlineShop\Framework\Factory::getInstance()->getEnvironment()->getCurrencyLocale()));


        return new \OnlineShop\Framework\PaymentManager\Status(
            $response['orderIdent']
            , $response['orderNumber']
            , $response['avsResponseMessage'] ?: $response['message']
            , $response['orderNumber'] !== NULL && $response['paymentState'] == 'SUCCESS'
                ? \OnlineShop\Framework\PaymentManager\IStatus::STATUS_AUTHORIZED
                : \OnlineShop\Framework\PaymentManager\IStatus::STATUS_CANCELLED
            , [
                'qpay_amount' => (string)$price
                , 'qpay_paymentType' => $response['paymentType']
                , 'qpay_paymentState' => $response['paymentState']
                , 'qpay_response' => print_r($response, true)
            ]
        );
    }

    /**
     * return the authorized data from payment provider
     *
     * @return array
     */
    public function getAuthorizedData()
    {
        return $this->authorizedData;
    }

    /**
     * set authorized data from payment provider
     *
     * @param array $authorizedData
     */
    public function setAuthorizedData(array $authorizedData)
    {
        $this->authorizedData = $authorizedData;
    }

    /**
     * execute payment
     *
     * @param \OnlineShop\Framework\PriceSystem\IPrice $price
     * @param string                      $reference
     *
     * @return \OnlineShop\Framework\PaymentManager\IStatus
     * @throws \Exception
     */
    public function executeDebit(\OnlineShop\Framework\PriceSystem\IPrice $price = null, $reference = null)
    {
        # https://integration.wirecard.at/doku.php/wcp:toolkit_light:start
        # https://integration.wirecard.at/doku.php/wcs:backend_operations?s[]=deposit
        # https://integration.wirecard.at/doku.php/backend:deposit


        if( $price )
        {
            // recurPayment

            $request = [
                'customerId' => $this->customer
                , 'toolkitPassword' => $this->toolkitPassword
                , 'command' => 'recurPayment'
                , 'language' => $this->authorizedData['language']
                , 'requestFingerprint' => ''
                , 'orderDescription' => $reference
                , 'sourceOrderNumber' => $this->authorizedData['orderNumber']
                , 'amount' => $price->getAmount()
                , 'currency' => $price->getCurrency()->getShortName()
            ];


            // add fingerprint
            $request['requestFingerprint'] = $this->computeFingerprint([
                $request['customerId']
                , $request['toolkitPassword']
                , $this->secret
                , $request['command']
                , $request['language']
                , $request['sourceOrderNumber']
                , $request['orderDescription']
                , $request['amount']
                , $request['currency']
            ]);

        }
        else
        {
            // default clearing auth
            $price = new \OnlineShop\Framework\PriceSystem\Price($this->authorizedData['amount'], new \Zend_Currency($this->authorizedData['currency'], $this->currencyLocale));

            $request = [
                'customerId' => $this->customer
                , 'toolkitPassword' => $this->toolkitPassword
                , 'command' => 'deposit'
                , 'language' => $this->authorizedData['language']
                , 'requestFingerprint' => ''
                , 'orderNumber' => $this->authorizedData['orderNumber']
                , 'amount' => $price->getAmount()
                , 'currency' => $price->getCurrency()->getShortName()
            ];


            // add fingerprint
            $request['requestFingerprint'] = $this->computeFingerprint([
                $request['customerId']
                , $request['toolkitPassword']
                , $this->secret
                , $request['command']
                , $request['language']
                , $request['orderNumber']
                , $request['amount']
                , $request['currency']
            ]);
        }


        // execute request
        $response = $this->serverToServerRequest( 'https://checkout.wirecard.com/page/toolkit.php', $request );


        // check response
        if($response['status'] === '0')
        {
            // Operation successfully done.

            return new \OnlineShop\Framework\PaymentManager\Status(
                $reference
                , $response['paymentNumber'] ?: $response['orderNumber']
                , ''
                , \OnlineShop\Framework\PaymentManager\IStatus::STATUS_CLEARED
                , [
                    'qpay_amount' => (string)$price
                    , 'qpay_command' => $request['command']
                    , 'qpay_response' => print_r($response, true)
                ]
            );
        }
        else if($response['errors'])
        {
            // https://integration.wirecard.at/doku.php/backend:response_parameters

            $error = [];
            for($e = 1; $e <= $response['errors']; $e++)
            {
                $error[] = $response['error_' . $e . '_error_message'];
            }

            return new \OnlineShop\Framework\PaymentManager\Status(
                $reference
                , $response['paymentNumber'] ?: $response['orderNumber']
                , implode("\n", $error)
                , \OnlineShop\Framework\PaymentManager\IStatus::STATUS_CANCELLED
                , [
                    'qpay_amount' => (string)$price
                    , 'qpay_command' => $request['command']
                    , 'qpay_response' => print_r($response, true)
                ]
            );
        }
        else
        {
            throw new \Exception( print_r($response, true) );
        }
    }

    /**
     * execute credit
     *
     * @param \OnlineShop\Framework\PriceSystem\IPrice $price
     * @param string                      $reference
     * @param                             $transactionId
     *
     * @return \OnlineShop\Framework\PaymentManager\IStatus
     */
    public function executeCredit(\OnlineShop\Framework\PriceSystem\IPrice $price, $reference, $transactionId)
    {
        // TODO: Implement executeCredit() method.
    }

    /**
     * Compute fingerprint for array of input parameters depending on configured algorithm
     *
     * @param array $params
     * @return string
     */
    protected function computeFingerprint(array $params)
    {
        $data   = implode('', $params);
        $result = null;

        switch ($this->hashAlgorithm) {
            case static::HASH_ALGO_MD5:
                return $this->computeMd5Fingerprint($data);

            case static::HASH_ALGO_HMAC_SHA512:
                return $this->computeHmacSha512Fingerprint($data);
        }
    }

    /**
     * Compute MD5 fingerprint
     *
     * @param $data
     * @return string
     */
    protected function computeMd5Fingerprint($data)
    {
        return md5($data);
    }

    /**
     * Calculate HMAC_SHA512 fingerprint
     *
     * @param $data
     * @return string
     */
    protected function computeHmacSha512Fingerprint($data)
    {
        return hash_hmac('sha512', $data, $this->secret);
    }

    /**
     * @param $url
     * @param $params
     *
     * @return string[]
     */
    protected function serverToServerRequest($url, $params)
    {
        $postFields = '';
        foreach ($params as $key => $value)
        {
            $postFields .= $key . '=' . $value . '&';
        }
        $postFields = substr($postFields, 0, strlen($postFields)-1);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        $response = curl_exec($curl);
        curl_close($curl);

        $r = [];
        parse_str($response, $r);
        return $r;
    }
}
