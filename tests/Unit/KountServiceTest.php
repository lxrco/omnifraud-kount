<?php declare(strict_types=1);

namespace Tests\Unit;

use Kount_Ris_Exception;
use Kount_Ris_Request_Inquiry;
use Kount_Ris_Request_Update;
use Kount_Ris_Response;
use Kount_Ris_ValidationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Omnifraud\Contracts\MessageInterface;
use Omnifraud\Contracts\ResponseInterface;
use Omnifraud\Contracts\ServiceInterface;
use Omnifraud\Kount\KountService;
use Omnifraud\Request\RequestException;
use Omnifraud\Testing\MakesTestRequests;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class KountServiceTest extends TestCase
{
    use MakesTestRequests;
    use MockeryPHPUnitIntegration;

    private function makeExpectedDataArray()
    {
        return [
            'MERC' => 'MERCHANT_ID',
            'VERS' => '0695',
            'PENC' => 'MASK',
            'MODE' => 'Q',
            'CURR' => 'CAD',
            'SDK' => 'PHP',
            'SDK_VERSION' => 'Sdk-Ris-Php-0631-20150506T1139',
            'MACK' => 'Y',
            'SITE' => 'DEFAULT',
            'SESS' => 'SESSION_ID',
            'IPAD' => '1.2.3.4',
            'LAST4' => '9000',
            'PTOK' => '457173XXXXXX9000',
            'PTYP' => 'CARD',
            'AVST' => 'M',
            'AVSZ' => 'M',
            'CVVR' => 'M',
            'ORDR' => '1',
            'EPOC' => 1504354332,
            'TOTL' => 56025,
            'UNIQ' => 'ACCOUNT_ID',
            'EMAL' => 'test@example.com',
            'S2EM' => 'test@example.com',
            'B2A1' => '1 billing street',
            'B2A2' => '1A',
            'B2CI' => 'Billing Town',
            'B2ST' => 'Billing State',
            'B2PC' => '54321',
            'B2CC' => 'CA',
            'S2A1' => '1 shipping street',
            'S2A2' => '25',
            'S2CI' => 'Shipping Town',
            'S2ST' => 'Shipping State',
            'S2PC' => '12345',
            'S2CC' => 'US',
            'S2NM' => 'John Shipping',
            'S2PN' => '1234567891',

            'NAME' => 'John Billing',
            'AUTH' => 'A',

            'PROD_TYPE[0]' => 'Category1',
            'PROD_ITEM[0]' => 'SKU1',
            'PROD_DESC[0]' => 'Product number 1',
            'PROD_QUANT[0]' => 1,
            'PROD_PRICE[0]' => 6025,

            'PROD_TYPE[1]' => 'Category2',
            'PROD_ITEM[1]' => 'SKU2',
            'PROD_DESC[1]' => 'Product number 2',
            'PROD_QUANT[1]' => 2,
            'PROD_PRICE[1]' => 25000,
        ];
    }

    public function testTrackingCodeMethod()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $this->assertContains(
            "'https://sandbox02.kaxsdc.com/collect/sdk?m=MERCHANT_ID&s=' + sid",
            $driver->trackingCode(ServiceInterface::PAGE_CHECKOUT) . "\n"
        );
        $driver = new KountService([
            'testing' => false,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $this->assertContains(
            "'https://prod01.kaxsdc.com/collect/sdk?m=MERCHANT_ID&s=' + sid",
            $driver->trackingCode(ServiceInterface::PAGE_CHECKOUT) . "\n"
        );

        $this->assertEquals('', $driver->trackingCode(ServiceInterface::PAGE_ALL));
    }

    public function testValidateRequestWithCompleteRequest()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $request = $this->makeTestRequest();

        $driver->setFakeExecute(function (Kount_Ris_Request_Inquiry $inquiry) {
            $reflection = new ReflectionClass(Kount_Ris_Request_Inquiry::class);
            $property = $reflection->getProperty('data');
            $property->setAccessible(true);
            $data = $property->getValue($inquiry);

            $this->assertEquals($this->makeExpectedDataArray(), $data);

            return new Kount_Ris_Response('');
        });

        $driver->validateRequest($request);
    }

    public function testValidateRequestWithCompleteRequestWithoutBin()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $request = $this->makeTestRequest();
        $request->getPayment()->setBin(null);

        $driver->setFakeExecute(function (Kount_Ris_Request_Inquiry $inquiry) {
            $reflection = new ReflectionClass(Kount_Ris_Request_Inquiry::class);
            $property = $reflection->getProperty('data');
            $property->setAccessible(true);
            $data = $property->getValue($inquiry);

            $expected = $this->makeExpectedDataArray();
            $expected['PENC'] = '';
            $expected['PTOK'] = '9000';

            $this->assertEquals($expected, $data);

            return new Kount_Ris_Response('');
        });

        $driver->validateRequest($request);
    }

    public function testValidateRequestWithInvalidRequest()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $request = $this->makeTestRequest();

        $driver->setFakeExecute(function (Kount_Ris_Request_Inquiry $inquiry) {
            throw new Kount_Ris_ValidationException('Required field [dummy] missing for mode [D]');
        });

        $this->expectException(RequestException::class);

        $driver->validateRequest($request);
    }

    public function testValidateRequestWithRequestError()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $request = $this->makeTestRequest();

        $driver->setFakeExecute(function (Kount_Ris_Request_Inquiry $inquiry) {
            throw new Kount_Ris_Exception('Could not resolve host: dummy.com');
        });

        $this->expectException(RequestException::class);

        $driver->validateRequest($request);
    }

    public function testResponseParsing()
    {
        $mockResponse = Mockery::mock(Kount_Ris_Response::class);
        $mockResponse->shouldReceive('getErrors')
            ->once()
            ->andReturn([
                'error1',
                'error2',
            ]);
        $mockResponse->shouldReceive('getWarnings')
            ->once()
            ->andReturn([
                'warning1',
                'warning2',
            ]);
        $mockResponse->shouldReceive('getScore')
            ->once()
            ->andReturn(55);
        $mockResponse->shouldReceive('getTransactionId')
            ->once()
            ->andReturn(6587);
        $mockResponse->shouldReceive('getResponseAsDict')
            ->once()
            ->andReturn(['STATUS' => 'GOOD']);

        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $request = $this->makeTestRequest();

        $driver->setFakeExecute(function (Kount_Ris_Request_Inquiry $inquiry) use ($mockResponse) {
            return $mockResponse;
        });

        $response = $driver->validateRequest($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);

        $this->assertEquals('{"STATUS":"GOOD"}', $response->getRawResponse());
        $this->assertEquals(45, $response->getPercentScore());
        $this->assertFalse($response->isAsync());
        $this->assertFalse($response->isGuaranteed());
        $this->assertEquals('6587', $response->getRequestUid());

        $messages = $response->getMessages();

        $this->assertCount(4, $messages);
        $flat_errors = [];
        foreach ($messages as $message) {
            $flat_errors[] = [$message->getType(), $message->getMessage()];
        }
        $this->assertEquals([
            [MessageInterface::TYPE_ERROR, 'error1'],
            [MessageInterface::TYPE_ERROR, 'error2'],
            [MessageInterface::TYPE_WARNING, 'warning1'],
            [MessageInterface::TYPE_WARNING, 'warning2'],
        ], $flat_errors);
    }

    public function testUpdateAsync()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $driver->setFakeExecute(function (Kount_Ris_Request_Update $inquiry) {
            $reflection = new ReflectionClass(Kount_Ris_Request_Update::class);
            $property = $reflection->getProperty('data');
            $property->setAccessible(true);
            $data = $property->getValue($inquiry);

            $this->assertEquals([
                'MERC' => 'MERCHANT_ID',
                'VERS' => '0695',
                'PENC' => 'KHASH',
                'MODE' => 'X',
                'SESS' => 'SESSION_ID',
                'TRAN' => '1234',
                'MACK' => 'Y',
            ], $data);

            return new Kount_Ris_Response('');
        });

        $request = $this->makeTestRequest();
        $request->setUid('1234');

        $driver->updateRequest($request);
    }

    public function testGetRequestExternalLink()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $expectedLink = 'https://awc.test.kount.net/workflow/detail.html?id=TEST';
        $this->assertEquals($expectedLink, $driver->getRequestExternalLink('TEST'));
    }

    public function testLogRefusedRequestWithCompleteRequest()
    {
        $driver = new KountService([
            'testing' => true,
            'MERCHANT_ID' => 'MERCHANT_ID',
        ]);

        $request = $this->makeTestRequest();

        $driver->setFakeExecute(function (Kount_Ris_Request_Inquiry $inquiry) {
            $reflection = new ReflectionClass(Kount_Ris_Request_Inquiry::class);
            $property = $reflection->getProperty('data');
            $property->setAccessible(true);
            $data = $property->getValue($inquiry);

            $expectedData = $this->makeExpectedDataArray();
            $expectedData['AUTH'] = 'D'; // Declined

            $this->assertEquals($expectedData, $data);

            return new Kount_Ris_Response('');
        });

        $driver->logRefusedRequest($request);
    }
}
