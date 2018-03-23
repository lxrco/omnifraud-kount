# Omnifraud: Kount

**Kount driver for the Omnifraud PHP fraud prevention library**

[![Build Status](https://travis-ci.org/lxrco/omnifraud-kount.svg?branch=master)](https://travis-ci.org/lxrco/omnifraud-kount)
[![Test Coverage](https://api.codeclimate.com/v1/badges/355505bfd36473d25c35/test_coverage)](https://codeclimate.com/github/lxrco/omnifraud-kount/test_coverage)


[Omnifraud](https://github.com/lxrco/omnifraud) is an fraud prevention livrary for PHP. It aims at providing a clear and consisten API for interacting with different fraud prevention service.

### Installation

```bash
composer require omnifraud/kount
```

### Usage

The Kount fraud service driver implements the following methods:
`trackingCode` ,`validateRequest`, `updateRequest`, `getRequestExternalLink`, `logRefusedRequest`.

The only method that is left empty is `cancelRequest` as Kount does not need requests to be cancelled.

#### Initialisation

The Kount contructor accepts the following configuration values (those are the default values):
```php
$service = new KountService([
    'testing' => false, // Use testing endpoint
    'website' => 'DEFAULT', // Website setting, will be passed as `SITE` to Kount
    'testRequestUrl' => 'https://awc.test.kount.net/workflow/detail.html?id=%s', // Url to view a TEST request
    'requestUrl' => 'https://awc.kount.net/workflow/detail.html?id=%s', // Url to view a PRODUCTION request
]);
```

#### Submitting a sale

Submitting a (successful or refused) sale to Kount requires a Session ID, so you will need to [implement the frontend code](https://github.com/lxrco/omnifraud#frontend-code).

Then you can use the `validateRequest` method to get a response:

```php
$sessionID = $_POST['sessionId']; // Retrieve your frontend session ID
// $sessionID = session_id(); You could also use the php session ID as long as you pass the same one to the frontend code


$request = new \Omnifraud\Request\Request();

// Required info

$session = $request->getSession();
$session->setId($sessionID);
$session->setIp($_SERVER['REMOTE_ADDR']);

$purchase = $request->getPurchase();
$purchase->setId((string)$order->id);
$purchase->setTotal($order->total * 100); // Integer, remove decimal point
$purchase->setCurrencyCode('CAD');

// Add some products
foreach($order->items as $item) {
    $product = new \Omnifraud\Request\Data\Product();
    $product->setCategory($item->category_name);
    $product->setSku($item->sku);
    $product->setName($item->name);
    $product->setQuantity($item->quantity);
    $product->setPrice($item->price * 100); // Integer, remove decimal point
    $purchase->addProduct($product);
}

// Additional optional info

$purchase->setCreatedAt(new DateTime($order->createdAt));

$payment = $request->getPayment();
$payment->setLast4($order->card->last4);
$payment->setBin($order->card->bin);
$payment->setAvs($order->avsResponse);
$payment->setCvv($order->cvvResponse);

$account = $request->getAccount();
$account->setId((string)$order->customer->id);
$account->setEmail($order->customer->email);

$billing = $request->getBillingAddress();
$billing->setFullName($order->card->name);
$billing->setStreetAddress($order->billing->address1);
$billing->setUnit($order->billing->address2);
$billing->setCity($order->billing->city);
$billing->setState($order->billing->state);
$billing->setPostalCode($order->billing->zip);
$billing->setCountryCode($order->billing->country->iso2);

$shipping = $request->getShippingAddress();
$shipping->setFullName($order->shipping->fullName); // Billing name
$shipping->setStreetAddress($order->shipping->address1);
$shipping->setUnit($order->shipping->address2);
$shipping->setCity($order->shipping->city);
$shipping->setState($order->shipping->state);
$shipping->setPostalCode($order->shipping->zip);
$shipping->setCountryCode($order->shipping->country->iso2);
$shipping->setPhone($order->shipping->phone);

// Send the request

$service = new \Omnifraud\Kount\KountService($serviceConfig);

if ($order->approved) {
    $response = $service->validateRequest($request);
    
    // Get score, SCORE IS INVERTED from the Kount logic to follow the Omnifraud convention so 100 is GOOD and 0 is BAD
    $score = $response->getPercentScore();
    
    // Request UID, save for later reference, you must also save sessionId if you want to update the case later
    $requestUid = $response->getRequestUid();
} else {
    // Log a refused request so Kount can learn about your custors attempts
    $service->logRefusedRequest($request);
}

```

Note: Kount responses are never *Async* nor *Guaranteed*


#### Linking to a case

In order to get the link to view a case on Kount, you just need the UID:

```php
$service = new \Omnifraud\Kount\KountService($serviceConfig);
$url = $service->getRequestExternalLink($requestUid);
```

#### Refreshing a case

Even if Kount answers instantly, you can still refresh the request to check if it was udpated, you need the request UID
and the user sessionId for this:

```php
<?php
$service = new \Omnifraud\Kount\KountService($serviceConfig);

$request = new \Omnifraud\Request\Request();
$request->setUid($requestUid);
$request->getSession()->setId($sessionId);

$response = $service->updateRequest($request);

```
