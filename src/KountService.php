<?php

namespace Omnifraud\Kount;

use Kount_Ris_ArraySettings;
use Kount_Ris_Data_CartItem;
use Kount_Ris_Exception;
use Kount_Ris_Request;
use Kount_Ris_Request_Inquiry;
use Kount_Ris_Request_Update;
use Kount_Ris_ValidationException;
use Kount_Util_ConfigFileReader;
use Omnifraud\Contracts\ResponseInterface;
use Omnifraud\Contracts\ServiceInterface;
use Omnifraud\Request\Request;
use Omnifraud\Request\RequestException;

class KountService implements ServiceInterface
{
    /** @var \Kount_Ris_Settings */
    protected $settings;

    /** @var callable */
    protected $fakeExecute;

    protected $config = [
        'testing' => false,
        'website' => 'DEFAULT',
        'testTransactionUrl' => 'https://awc.test.kount.net/workflow/detail.html?id=%s',
        'transactionUrl' => 'https://awc.kount.net/workflow/detail.html?id=%s',
    ];

    public function __construct(array $config)
    {
        // Load default settings
        $reader = Kount_Util_ConfigFileReader::instance();

        $merged = array_merge($this->config, $reader->getSettings(), $config);

        if (!$merged['URL']) {
            $merged['URL'] = $merged['testing'] ? 'https://risk.test.kount.net' : 'https://risk.kount.net';
        }

        $this->config = $merged;

        $this->settings = new Kount_Ris_ArraySettings($merged);
    }

    public function trackingCode(string $pageType): string
    {
        if ($pageType !== self::PAGE_CHECKOUT) {
            return '';
        }

        $dataCollector = $this->config['testing'] ? 'sandbox02.kaxsdc.com' : 'prod01.kaxsdc.com';
        $merchantId = $this->settings->getMerchantId();

        return <<<JS
trackingCodes.push(function (sid) {
    var script = document.createElement('script');
    script.setAttribute('src', 'https://{$dataCollector}/collect/sdk?m={$merchantId}&s=' + sid);
    var img = document.createElement('img');
    img.setAttribute('src', 'https://{$dataCollector}/logo.gif?m={$merchantId}&s=' + sid);

    document.body.appendChild(script);
    document.body.appendChild(img);
});
JS;
    }

    public function validateRequest(Request $request): ResponseInterface
    {
        return $this->doValidateRequest($request, 'A');
    }

    protected function doValidateRequest(Request $request, $authResponse)
    {
        $inquiry = new Kount_Ris_Request_Inquiry($this->settings);

        // Default
        $inquiry->setMack('Y');
        $inquiry->setAuth($authResponse);
        $inquiry->setWebsite($this->config['website']);

        // Session
        $inquiry->setSessionId($request->getSession()->getId());
        $inquiry->setIpAddress($request->getSession()->getIp());

        // Payment
        if ($request->getPayment()->getLast4()) {
            if ($request->getPayment()->getBin()) {
                $cardNumbers = $request->getPayment()->getBin() . 'XXXXXX' . $request->getPayment()->getLast4();
                $inquiry->setPaymentMasked($cardNumbers);
            } else {
                $inquiry->setKhashPaymentEncoding(false);
                $inquiry->setCardPayment($request->getPayment()->getLast4());
            }
        }
        $inquiry->setAvst($this->avsToAvst($request->getPayment()->getAvs()));
        $inquiry->setAvsz($this->avsToAvsz($request->getPayment()->getAvs()));
        $inquiry->setCvvr($this->cvvToCvvr($request->getPayment()->getCvv()));

        // Purchase
        $inquiry->setOrderNumber($request->getPurchase()->getId());
        $inquiry->setEpoch($request->getPurchase()->getCreatedAt()->getTimestamp());
        $inquiry->setCurrency($request->getPurchase()->getCurrencyCode());
        $inquiry->setTotal($request->getPurchase()->getTotal());

        $cart = [];
        foreach ($request->getPurchase()->getProducts() as $product) {
            $cart[] = new Kount_Ris_Data_CartItem(
                $product->getCategory(),
                $product->getSku(),
                $product->getName(),
                $product->getQuantity(),
                $product->getPrice()
            );
        }
        if (count($cart) > 0) {
            $inquiry->setCart($cart);
        }

        // Account
        $inquiry->setUnique($request->getAccount()->getId());
        $inquiry->setEmail($request->getAccount()->getEmail());
        $inquiry->setShippingEmail($request->getAccount()->getEmail());

        // Billing address
        $inquiry->setBillingAddress(
            $request->getBillingAddress()->getStreetAddress(),
            $request->getBillingAddress()->getUnit(),
            $request->getBillingAddress()->getCity(),
            $request->getBillingAddress()->getState(),
            $request->getBillingAddress()->getPostalCode(),
            $request->getBillingAddress()->getCountryCode()
        );
        $inquiry->setName($request->getBillingAddress()->getFullName());

        // Shipping address
        $inquiry->setShippingAddress(
            $request->getShippingAddress()->getStreetAddress(),
            $request->getShippingAddress()->getUnit(),
            $request->getShippingAddress()->getCity(),
            $request->getShippingAddress()->getState(),
            $request->getShippingAddress()->getPostalCode(),
            $request->getShippingAddress()->getCountryCode()
        );
        $inquiry->setShippingName($request->getShippingAddress()->getFullName());
        $inquiry->setShippingPhoneNumber($request->getShippingAddress()->getPhone());

        $kountResponse = $this->execute($inquiry);

        return new KountResponse($kountResponse);
    }

    /**
     * @param \Kount_Ris_Request $inquiry
     *
     * @return \Kount_Ris_Response
     * @throws RequestException
     */
    protected function execute(Kount_Ris_Request $inquiry)
    {
        try {
            if ($this->fakeExecute) {
                return call_user_func($this->fakeExecute, $inquiry);
            }
            return $inquiry->getResponse();
        } catch (Kount_Ris_ValidationException $e) {
            throw new RequestException('Invalid Request: ' . $e->getMessage());
        } catch (Kount_Ris_Exception $e) {
            throw new RequestException('Invalid Request: ' . $e->getMessage());
        }
    }

    /**
     * Convert to address verification result
     * @see http://www.emsecommerce.net/avs_cvv2_response_codes.htm
     */
    protected function avsToAvst($avs)
    {
        $values = [
            // Us
            'X' => 'M',
            'Y' => 'M',
            'A' => 'M',
            'W' => 'N',
            'Z' => 'N',
            'N' => 'N',
            'U' => 'X',
            'R' => 'X',
            'E' => 'N',
            'S' => 'X',
            // International
            'D' => 'M',
            'M' => 'M',
            'B' => 'M',
            'P' => 'N',
            'C' => 'N',
            'I' => 'N',
            'G' => 'X',
        ];

        return isset($values[$avs]) ? $values[$avs] : 'X';
    }

    /**
     * Convert to zip verification result
     * @see http://www.emsecommerce.net/avs_cvv2_response_codes.htm
     */
    protected function avsToAvsz($avs)
    {
        $values = [
            // Us
            'X' => 'M',
            'Y' => 'M',
            'A' => 'N',
            'W' => 'M',
            'Z' => 'M',
            'N' => 'N',
            'U' => 'X',
            'R' => 'X',
            'E' => 'X',
            'S' => 'X',
            // International
            'D' => 'M',
            'M' => 'M',
            'B' => 'N',
            'P' => 'M',
            'C' => 'N',
            'I' => 'N',
            'G' => 'X',
        ];

        return isset($values[$avs]) ? $values[$avs] : 'X';
    }

    /**
     * Convert to basic M/N/X match
     * @see http://www.emsecommerce.net/avs_cvv2_response_codes.htm
     */
    protected function cvvToCvvr($cvv)
    {
        $values = [
            'M' => 'M',
            'N' => 'N',
            'P' => 'X',
            'S' => 'X',
            'U' => 'X',
            '' => 'X',
        ];

        return isset($values[$cvv]) ? $values[$cvv] : 'X';
    }

    public function setFakeExecute(callable $fake)
    {
        $this->fakeExecute = $fake;
    }

    public function updateRequest(Request $request): ResponseInterface
    {
        $update = new Kount_Ris_Request_Update($this->settings);
        $update->setSessionId($request->getSession()->getId());
        $update->setTransactionId($request->getUid());
        $update->setMack('Y');
        $update->setMode('X');

        $rawResponse = $this->execute($update);

        return new KountResponse($rawResponse);
    }

    public function getRequestExternalLink($requestUid): ?string
    {
        $url = $this->config['testing'] ? $this->config['testTransactionUrl'] : $this->config['transactionUrl'];
        return sprintf($url, $requestUid);
    }

    public function logRefusedRequest(Request $request): void
    {
        $this->doValidateRequest($request, 'D');
    }

    public function cancelRequest(string $requestUid): void
    {
        // Do nothing
    }
}
