<?php
require_once './flagshipRequest.php';

$envId = getenv("FS_ENV_ID");
$apiKey = getenv("FS_API_KEY");

try {
    //Get request headers
    $headers = apache_request_headers();

    $flagship = new Flagship($envId, $apiKey);

    //Get visitor id header if exists otherwise generate one
    if (!isset($headers['x-fs-visitor'])) {
        $visitorId = $headers['x-fs-visitor'];
    } else {
        $visitorId = $flagship->generateUID();
    }

    //Call flagship decision api
    $flagship->start(
        $visitorId,
        json_encode([
            'nbBooking' => 4,
        ])
    );

    //Generate cache hash key
    $cacheHashKey = $flagship->getHashKey();

    if ($cacheHashKey === false) {
        $cacheHashKey = 'optout';
        $visitorId = 'ignore-me';
    }

    //If the request method is Head, only the lightweight backend will be use
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        header('x-fs-visitor: ' . $visitorId);
        header('x-fs-experiences: ' . $cacheHashKey);
        exit();
    }

    header('x-fs-visitor: ' . $visitorId);
    header('x-fs-experiences: ' . $cacheHashKey);
    header('Cache-Control: max-age=1, s-maxage=600');

    echo '<pre>';

    if ($cacheHashKey == 'optout') {
        echo 'Global Cache 🔥 <br />';
    }

    //Create page content with the flag restaurant_cta_review_text 
    echo '<div>This example uses the Flagship Decision API</div> <button>' . $flagship->getFlag('restaurant_cta_review_text', 'Leave a Review') . '</button>';
} catch (\Throwable $th) {
    throw $th;
}
