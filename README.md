# WebPush
> Web Push library for PHP

Forked from [Minishlink/web-push](https://github.com/Minishlink/web-push)

## Installation
`composer require dogma165/push-notification`

## Usage
WebPush can be used to send notifications to endpoints which server delivers web push notifications as described in 
the [Web Push protocol](https://tools.ietf.org/html/draft-thomson-webpush-protocol-00).
As it is standardized, you don't have to worry about what server type it relies on.

Notifications with payloads are supported with this library on Chrome 50+.

```php
<?php

use Vda\WebPushNotification\WebPush;

// array of notifications
$notifications = array(
     array(
        'endpoint' => 'https://android.googleapis.com/gcm/send/abcdef...', // Chrome
        'payload' => 'hello !',
        'userPublicKey' => 'BPcMbnWQL5GOYX/5LKZXT6sLmHiMsJSiEvIFvfcDvX7IZ9qqtq68onpTPEYmyxSQNiH7UD/98AUcQ12kBoxz/0s=', // base 64 encoded, should be 88 chars
        'userAuthToken' => 'CxVX6QsVToEGEcjfYPqXQw==',// base 64 encoded, should be 24 chars
    ), array(
        'endpoint' => 'https://example.com/other/endpoint/of/another/vendor/abcdef...',
        'payload' => '{msg:"test"}',
        'userPublicKey' => '(stringOf88Chars)', 
        'userAuthToken' => '(stringOf24Chars)',
    ),
);

$webPush = new WebPush();

// send multiple notifications with payload
foreach ($notifications as $notification) {
    $webPush->sendNotification(
        $notification['endpoint'],
        $notification['payload'], // optional (defaults null)
        $notification['userPublicKey'], // optional (defaults null)
        $notification['userAuthToken'] // optional (defaults null)
    );
}
$webPush->flush();

// send one notification and flush directly
$webPush->sendNotification(
    $notifications[0]['endpoint'],
    $notifications[0]['payload'], // optional (defaults null)
    $notifications[0]['userPublicKey'], // optional (defaults null)
    $notifications[0]['userAuthToken'], // optional (defaults null)
    true // optional (defaults false)
);
```
### GCM servers notes (Chrome)
For compatibility reasons, this library detects if the server is a GCM server and appropriately sends the notification.

You will need to specify your GCM api key when instantiating WebPush:
```php
<?php

use Vda\WebPushNotification\WebPush;

$endpoint = 'https://android.googleapis.com/gcm/send/abcdef...'; // Chrome
$apiKeys = array(
    'GCM' => 'MY_GCM_API_KEY',
);

$webPush = new WebPush($apiKeys);
$webPush->sendNotification($endpoint, null, null, null, true);
```


### Time To Live
Time To Live (TTL, in seconds) is how long a push message is retained by the push service (eg. Mozilla) in case the user browser 
is not yet accessible (eg. is not connected). You may want to use a very long time for important notifications. The default TTL is 4 weeks. 
However, if you send multiple nonessential notifications, set a TTL of 0: the push notification will be delivered only 
if the user is currently connected. For other cases, you should use a minimum of one day if your users have multiple time 
zones, and if they don't several hours will suffice.

```php
<?php

use Vda\WebPushNotification\WebPush;

$webPush = new WebPush(); // default TTL is 4 weeks
// send some important notifications...

$webPush->setTTL(3600);
// send some not so important notifications

$webPush->setTTL(0);
// send some trivial notifications
```

## License
[MIT](https://github.com/Minishlink/web-push/blob/master/LICENSE)
