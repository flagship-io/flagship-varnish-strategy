<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Flagship\Flagship;

$envId = getenv("FS_ENV_ID");
$apiKey = getenv("FS_API_KEY");

try {
    //Get request headers
    $headers = apache_request_headers();

    //Start Flagship SDK
    Flagship::Start($envId, $apiKey);

    $visitorId = null;

    //Get visitor id header if exists
    if (isset($headers['x-fs-visitor'])) {
        $visitorId = $headers['x-fs-visitor'];
    }

    $cacheHashKey = null;

    // Create a flagship visitor, if visitorId is null the SDK will generate one
    $visitor = Flagship::newVisitor($visitorId)->withContext(['nbBooking' => 4])->build();

    //Get the visitor's id in case the SDK generates one
    $visitorId = $visitor->getVisitorId();

    //Fetch flags
    $visitor->fetchFlags();

    $experiences = [];

    //Loop over flags to get all campaignId and variationId 
    foreach ($visitor->getFlagsDTO() as $value) {
        $experience = "{$value->getCampaignId()}:{$value->getVariationId()}";
        if (in_array($experience, $experiences)) {
            continue;
        }
        $experiences[] = $experience;
    }

    //Create a unique cache key by hashing campaignId and variationId targeting  the current visitor
    if (count($experiences)) {
        $experiences = implode("|", $experiences);
        $cacheHashKey = hash("sha256", $experiences);
    }

    // if experiences is empty no cache key will be created
    if (!$cacheHashKey) {
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

    echo '<div>This example uses the Flagship SDK</div><button>' . $visitor->getFlag('restaurant_cta_review_text', 'Leave a Review')->getValue() . '</button>';
} catch (\Throwable $th) {
    throw $th;
}
