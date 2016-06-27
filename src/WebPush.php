<?php

/*
 * This file is part of the WebPush library.
 *
 * (c) Louis Lagrange <lagrange.louis@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vda\WebPushNotification;

use Buzz\Browser;
use Buzz\Client\AbstractClient;
use Buzz\Client\MultiCurl;
use Buzz\Exception\RequestException;
use Buzz\Message\Response;

class WebPush
{
    const GCM_URL = 'https://android.googleapis.com/gcm/send';
    const TEMP_GCM_URL = 'https://gcm-http.googleapis.com/gcm';

    /** @var Browser */
    protected $browser;

    /** @var array Key is push server type and value is the API key */
    protected $apiKeys;

    /** @var array Array of array of Notifications by server type */
    private $notificationsByServerType;

    /** @var array Array of not standard endpoint sources */
    private $urlByServerType = array(
        'GCM' => self::GCM_URL,
    );

    /** @var int Time To Live of notifications */
    private $TTL;

    /** @var bool Automatic padding of payloads, if disabled, trade security for bandwidth */
    private $automaticPadding = true;

    /** @var boolean */
    private $nativePayloadEncryptionSupport;

    /**
     * WebPush constructor.
     *
     * @param array $apiKeys Some servers needs authentication. Provide your API keys here. (eg. array('GCM' => 'GCM_API_KEY'))
     * @param int|null $TTL Time To Live of notifications, default being 4 weeks.
     * @param int|null $timeout Timeout of POST request
     * @param AbstractClient|null $client
     */
    public function __construct(array $apiKeys = array(), $TTL = 2419200, $timeout = 30, AbstractClient $client = null)
    {
        $this->apiKeys = $apiKeys;
        $this->TTL = $TTL;

        $client = isset($client) ? $client : new MultiCurl();
        $client->setTimeout($timeout);
        $this->browser = new Browser($client);

        $this->nativePayloadEncryptionSupport = version_compare(phpversion(), '7.1', '>=');
    }

    /**
     * Send a notification.
     *
     * @param string $endpoint
     * @param string|null $payload If you want to send an array, json_encode it.
     * @param string|null $userPublicKey
     * @param string|null $userAuthToken
     * @param bool $flush If you want to flush directly (usually when you send only one notification)
     *
     * @return array|bool Return an array of information if $flush is set to true and the queued requests has failed.
     *                    Else return true.
     * @throws \ErrorException
     */
    public function sendNotification($endpoint, $payload = null, $userPublicKey = null, $userAuthToken = null, $flush = false)
    {
        if (isset($userAuthToken) && is_bool($userAuthToken)) {
            throw new \ErrorException('The API has changed: sendNotification now takes the optional user auth token as parameter.');
        }

        if(isset($payload)) {
            if (strlen($payload) > Encryption::MAX_PAYLOAD_LENGTH) {
                throw new \ErrorException('Size of payload must not be greater than '.Encryption::MAX_PAYLOAD_LENGTH.' octets.');
            }

            $payload = Encryption::padPayload($payload, $this->automaticPadding);
        }

        // sort notification by server type
        $type = $this->sortEndpoint($endpoint);
        $this->notificationsByServerType[$type][] = new Notification($endpoint, $payload, $userPublicKey, $userAuthToken);

        if ($flush) {
            $res = $this->flush();
            return $res;
        }

    }

    /**
     * Flush notifications. Triggers the requests.
     *
     * @return array|bool If there are no errors, return true.
     *                    If there were no notifications in the queue, return false.
     *                    Else return an array of information for each notification sent (success, statusCode, headers).
     *
     * @throws \ErrorException
     */
    public function flush()
    {
        if (empty($this->notificationsByServerType)) {
            return false;
        }

        // if GCM is present, we should check for the API key
        if (array_key_exists('GCM', $this->notificationsByServerType)) {
            if (empty($this->apiKeys['GCM'])) {
                throw new \ErrorException('No GCM API Key specified.');
            }
        }

        // for each endpoint server type
        $responses = array();
        foreach ($this->notificationsByServerType as $serverType => $notifications) {
            $responses += $this->prepareAndSend($notifications, $serverType);
        }

        // if multi curl, flush
        if ($this->browser->getClient() instanceof MultiCurl) {
            /** @var MultiCurl $multiCurl */
            $multiCurl = $this->browser->getClient();
            $multiCurl->flush();
        }

        /** @var Response|null $response */
        //$return = array();
        foreach ($responses as $response) {
            $return = [
                'statusCode' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
            ];
        }

        // reset queue
        $this->notificationsByServerType = null;

        return $return;
    }

    private function prepareAndSend(array $notifications, $serverType)
    {
        $responses = array();
        /** @var Notification $notification */
        foreach ($notifications as $notification) {
            $endpoint = $notification->getEndpoint();
            $payload = $notification->getPayload();
            $userPublicKey = $notification->getUserPublicKey();
            $userAuthToken = $notification->getUserAuthToken();

            if (isset($payload) && isset($userPublicKey) && isset($userAuthToken)) {
                $encrypted = Encryption::encrypt($payload, $userPublicKey, $userAuthToken, $this->nativePayloadEncryptionSupport);

                $headers = array(
                    'Content-Length' => strlen($encrypted['cipherText']),
                    'Content-Type' => 'application/octet-stream',
                    'Content-Encoding' => 'aesgcm',
                    'Encryption' => 'keyid="p256dh";salt="'.$encrypted['salt'].'"',
                    'Crypto-Key' => 'keyid="p256dh";dh="'.$encrypted['localPublicKey'].'"',
                    'TTL' => $this->TTL,
                );

                $content = $encrypted['cipherText'];
            } else {
                $headers = array(
                    'Content-Length' => 0,
                    'TTL' => $this->TTL,
                );

                $content = '';
            }

            if ($serverType === 'GCM') {
                // FUTURE remove when Chrome servers are all up-to-date
                $endpoint = str_replace(self::GCM_URL, self::TEMP_GCM_URL, $endpoint);

                $headers['Authorization'] = 'key='.$this->apiKeys['GCM'];
            }

            $responses[] = $this->sendRequest($endpoint, $headers, $content);
        }

        return $responses;
    }

    /**
     * @param string $url
     * @param array  $headers
     * @param string $content
     *
     * @return \Buzz\Message\MessageInterface|null
     */
    private function sendRequest($url, array $headers, $content)
    {
        try {
            $response = $this->browser->post($url, $headers, $content);
        } catch (RequestException $e) {
            $response = null;
        }

        return $response;
    }

    /**
     * @param string $endpoint
     *
     * @return string
     */
    private function sortEndpoint($endpoint)
    {
        foreach ($this->urlByServerType as $type => $url) {
            if (substr($endpoint, 0, strlen($url)) === $url) {
                return $type;
            }
        }

        return 'standard';
    }

    /**
     * @return Browser
     */
    public function getBrowser()
    {
        return $this->browser;
    }

    /**
     * @param Browser $browser
     *
     * @return WebPush
     */
    public function setBrowser($browser)
    {
        $this->browser = $browser;

        return $this;
    }

    /**
     * @return int
     */
    public function getTTL()
    {
        return $this->TTL;
    }

    /**
     * @param int $TTL
     *
     * @return WebPush
     */
    public function setTTL($TTL)
    {
        $this->TTL = $TTL;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isAutomaticPadding()
    {
        return $this->automaticPadding;
    }

    /**
     * @param boolean $automaticPadding
     *
     * @return WebPush
     */
    public function setAutomaticPadding($automaticPadding)
    {
        $this->automaticPadding = $automaticPadding;

        return $this;
    }
}
