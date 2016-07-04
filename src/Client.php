<?php

namespace Achse\ShapeShiftIo;

use Achse\ShapeShiftIo\ApiError\ApiErrorException;
use Achse\ShapeShiftIo\ApiError\NoPendingTransactionException;
use Achse\ShapeShiftIo\ApiError\NotDepositAddressException;
use Achse\ShapeShiftIo\ApiError\NotValidResponseFromApiException;
use Achse\ShapeShiftIo\ApiError\TransactionNotCancelledException;
use Achse\ShapeShiftIo\ApiError\UnknownPairException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use LogicException;
use Nette\NotImplementedException;
use Nette\SmartObject;
use Nette\Utils\Json;
use Nette\Utils\Strings;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class Client
{

    use SmartObject;

    const DEFAULT_BASE_URL = 'https://shapeshift.io';

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var GuzzleClient
     */
    private $guzzleClient;

    /**
     * @param string $baseUrl
     */
    public function __construct(string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $this->baseUrl = $baseUrl;
        $this->guzzleClient = new GuzzleClient(['base_uri' => $baseUrl]);
    }

    /**
     * @see https://info.shapeshift.io/api#api-2
     *
     * @param string $coin1
     * @param string $coin2
     * @return float
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getRate(string $coin1, string $coin2) : float
    {
        return (float)$this->get(sprintf('%s/%s', Resources::RATE, $this->getPair($coin1, $coin2)))->rate;
    }

    /**
     * @see https://info.shapeshift.io/api#api-3
     *
     * @param string $coin1
     * @param string $coin2
     * @return float
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getLimit(string $coin1, string $coin2) : float
    {
        return (float)$this->get(sprintf('%s/%s', Resources::LIMIT, $this->getPair($coin1, $coin2)))->limit;
    }

    /**
     * @see https://info.shapeshift.io/api#api-103
     *
     * @param string|null $coin1
     * @param string|null $coin2
     * @return stdClass[]
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getMarketInfo(string $coin1 = null, string $coin2 = null) : array
    {
        return $this->get(sprintf('%s/%s', Resources::MARKET_INFO, $this->getPair($coin1, $coin2)));
    }

    /**
     * @see https://info.shapeshift.io/api#api-4
     *
     * @param int $max
     * @return stdClass[]
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getRecentTransactionList(int $max) : array
    {
        return $this->get(sprintf('%s/%s', Resources::RECENT_TRANSACTIONS, $max));
    }

    /**
     * @see https://info.shapeshift.io/api#api-5
     *
     * @param string $address
     * @return stdClass
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getStatusOfDepositToAddress(string $address) : stdClass
    {
        return $this->get(sprintf('%s/%s', Resources::RECENT_DEPOSIT_TRANSACTION_STATUS, $address));
    }

    /**
     * @see https://info.shapeshift.io/api#api-6
     *
     * @param string $address
     * @return int
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getTimeRemaining(string $address) : int
    {
        return (int)$this->get(sprintf('%s/%s', Resources::TIME_REMAINING, $address))->seconds_remaining;
    }

    /**
     * @see https://info.shapeshift.io/api#api-104
     *
     * @return stdClass
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getSupportedCoins() : stdClass
    {
        return $this->get(Resources::LIST_OF_SUPPORTED_COINS);
    }

    /**
     * @see https://info.shapeshift.io/api#api-105
     *
     * @param string $apiKey
     * @return stdClass[]
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getListAOfTransactionsByApiKey(string $apiKey) : array
    {
        return $this->get(sprintf('%s/%s', Resources::LIST_OF_TRANSACTIONS_WITH_API_KEY, $apiKey));
    }

    /**
     * @see https://info.shapeshift.io/#api-106
     *
     * @param string $address
     * @param string $apiKey
     * @return stdClass[]
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getTransactionsByOutputAddress(string $address, string $apiKey) : array
    {
        return $this->get(
            sprintf('%s/%s/%s', Resources::LIST_OF_TRANSACTIONS_WITH_API_KEY_BY_ADDRESS, $address, $apiKey)
        );
    }

    /**
     * @see https://info.shapeshift.io/#api-7
     *
     * @param string $withdrawalAddress
     * @param string $coin1
     * @param string $coin2
     * @param string|null $returnAddress
     * @param string|null $destinationTag
     * @param string|null $rsAddress
     * @param string|null $apiKey
     * @return array|stdClass
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function createTransaction(
        string $withdrawalAddress,
        string $coin1,
        string $coin2,
        string $returnAddress = null,
        string $rsAddress = null,
        string $destinationTag = null,
        string $apiKey = null
    ) {
        $input = $this->buildSubmitTransactionBodyObject(
            $withdrawalAddress,
            $coin1,
            $coin2,
            $returnAddress,
            $rsAddress,
            $destinationTag,
            $apiKey
        );

        return $this->post(Resources::CREATE_TRANSACTION, Json::encode($input));
    }

    /**
     * @see https://info.shapeshift.io/#api-8
     *
     * @param string $email
     * @param string $transactionId
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function requestEmailReceipt(string $email, string $transactionId) : void
    {
        $input = new stdClass();
        $input->email = $email;
        $input->txid = $transactionId;

        $this->post(Resources::REQUEST_RECEIPT, Json::encode($input));
    }

    /**
     * @see https://info.shapeshift.io/#api-9
     *
     * @param float $amount
     * @param string $withdrawalAddress
     * @param string $coin1
     * @param string $coin2
     * @param string|null $returnAddress
     * @param string|null $rsAddress
     * @param string|null $destinationTag
     * @param string|null $apiKey
     * @return array|stdClass
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function createFixedAmountTransaction(
        float $amount,
        string $withdrawalAddress,
        string $coin1,
        string $coin2,
        string $returnAddress = null,
        string $rsAddress = null,
        string $destinationTag = null,
        string $apiKey = null
    ) {
        $input = $this->buildSubmitTransactionBodyObject(
            $withdrawalAddress,
            $coin1,
            $coin2,
            $returnAddress,
            $rsAddress,
            $destinationTag,
            $apiKey
        );
        $input->amount = $amount;

        $result = $this->post(Resources::SEND_AMOUNT, Json::encode($input));

        if (!isset($result->success)) {
            throw new NotValidResponseFromApiException('API responded with invalid structure.');
        }

        return $result->success;
    }

    /**
     * @see https://info.shapeshift.io/#api-9
     *
     * @param float $amount
     * @param string $coin1
     * @param string $coin2
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function getAmountForTransaction(float $amount, string $coin1, string $coin2)
    {
        $input = $this->buildSubmitTransactionBodyObject(null, $coin1, $coin2);
        $input->amount = $amount;
        Json::encode($input);

        throw new NotImplementedException();
    }

    /**
     * @see ěhttps://info.shapeshift.io/#api-108
     *
     * @param string $address
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function cancelTransaction(string $address) : void
    {
        $input = new stdClass();
        $input->address = $address;

        try {
            $result = $this->post(Resources::CANCEL_PENDING_TRANSACTION, Json::encode($input));
        } catch (ApiErrorException $e) {
            throw new TransactionNotCancelledException($e->getMessage(), $e->getCode(), $e);
        }

        if (!isset($result->success) || $result->success !== ' Pending Transaction cancelled ') {
            throw new ApiErrorException('Canceling transaction failed.');
        }
    }

    /**
     * @see https://info.shapeshift.io/#api-107
     *
     * @param string $address
     * @param string $coin
     * @return stdClass
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    public function validateAddress(string $address, string $coin) : stdClass
    {
        $result = $this->get(sprintf('%s/%s/%s', Resources::VALIDATE_ADDRESS, $address, $coin));

        if (!isset($result->isValid) && isset($result->isvalid)) {
            $result->isValid = $result->isvalid;
            unset ($result->isvalid);
        }

        return $result;
    }

    /**
     * @param string $url
     * @return stdClass|array
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    private function get(string $url)
    {
        try {
            $response = $this->guzzleClient->get($url);
        } catch (RequestException $exception) {
            $this->handleGuzzleRequestException($exception);
        }

        return $this->processResult($url, $response);
    }

    /**
     * @param string $url
     * @param string $body
     * @return array|stdClass
     *
     * @throws RequestFailedException
     * @throws ApiErrorException
     */
    private function post(string $url, $body)
    {
        try {
            $request = new Request('POST', $url, [], $body);
            $response = $this->guzzleClient->send($request);
        } catch (RequestException $exception) {
            $this->handleGuzzleRequestException($exception);
        }

        return $this->processResult($url, $response);
    }

    /**
     * @param array|stdClass $result
     * @param string $url
     * @throws ApiErrorException
     */
    private function checkErrors($result, string $url)
    {
        $error = $this->findErrorInResult($result);

        if ($error !== null) {
            if ($error === 'Unknown pair') {
                throw new UnknownPairException('Coin identifiers pair unknown.');

            } elseif ($error === 'This address is NOT a ShapeShift deposit address. Do not send anything to it.') {
                throw new NotDepositAddressException($error);

            } elseif ($error === 'Unable to find pending transaction') {
                throw new NoPendingTransactionException($error);

            } elseif (!$this->isEndpointOkWithError($url)) {
                throw new ApiErrorException($error);
            }
        }
    }

    /**
     * @param string|null $coin1
     * @param string|null $coin2
     * @return string
     */
    private function getPair(string $coin1 = null, string $coin2 = null) : string
    {
        if (($coin1 === null || $coin2 === null) && $coin1 !== $coin2) {
            throw new LogicException('You must provide both or none of the coins.');
        }

        return $coin1 !== null ? sprintf('%s_%s', $coin1, $coin2) : '';
    }

    /**
     * ShapeShift API does NOT provide 400 status code on error and for some endpoints
     * can be $result->error success response.
     *
     * @param string $url
     * @return bool
     */
    private function isEndpointOkWithError(string $url) : bool
    {
        return Strings::startsWith($url, Resources::VALIDATE_ADDRESS);
    }

    /**
     * @param stdClass|array $result
     * @return string|stdClass|null
     */
    private function findErrorInResult($result)
    {
        $error = null;
        if ($result instanceof stdClass) {
            $error = $result->error ?? $result->err ?? null;
        }

        return $error;
    }

    /**
     * @param RequestException $exception
     * @throws RequestFailedException
     */
    private function handleGuzzleRequestException(RequestException $exception)
    {
        $message = sprintf('Request failed due: "%s".', $exception->getMessage());
        throw new RequestFailedException($message, $exception->getCode(), $exception);
    }

    /**
     * @param string $url
     * @param ResponseInterface $response
     * @return array|stdClass
     *
     * @throws ApiErrorException
     */
    private function processResult(string $url, ResponseInterface $response)
    {
        $result = Json::decode($response->getBody()->getContents());
        $this->checkErrors($result, $url);

        return $result;
    }

    /**
     * @param string|null $withdrawalAddress
     * @param string $coin1
     * @param string $coin2
     * @param string|null $returnAddress
     * @param string|null $rsAddress
     * @param string|null $destinationTag
     * @param string|null $apiKey
     * @return stdClass
     */
    private function buildSubmitTransactionBodyObject(
        string $withdrawalAddress = null,
        string $coin1,
        string $coin2,
        string $returnAddress = null,
        string $rsAddress = null,
        string $destinationTag = null,
        string $apiKey = null
    ) : stdClass
    {
        $input = new stdClass();
        if ($withdrawalAddress !== null) {
            $input->withdrawal = $withdrawalAddress;
        }
        $input->pair = sprintf('%s_%s', $coin1, $coin2);
        if ($returnAddress !== null) {
            $input->returnAddress = $returnAddress;
        }
        if ($destinationTag !== null) {
            $input->destTag = $destinationTag;
        }
        if ($rsAddress !== null) {
            $input->rsAddress = $rsAddress;
        }
        if ($apiKey !== null) {
            $input->apiKey = $apiKey;
        }

        return $input;
    }

}
