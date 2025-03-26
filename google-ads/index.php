<?php
// Require necessary dependencies
require_once 'vendor/autoload.php';
require_once 'dot_env/vendor/autoload.php';
// Google Ads API PHP client library imports

use Google\Ads\GoogleAds\Lib\V17\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V17\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V17\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;

use Google\Ads\GoogleAds\Lib\V17\GoogleAdsException;
use Google\Ads\GoogleAds\V17\Common\KeywordInfo;
use Google\Ads\GoogleAds\V17\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V17\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V17\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V17\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V17\Services\MutateAdGroupCriteriaRequest;
use Google\Ads\GoogleAds\V17\Services\GoogleAdsRow;
use Google\ApiCore\ApiException;


// Exception handling
use Exception;

use Dotenv\Dotenv;

// ✅ Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// Retrieve credentials and customer details
$developerToken  = $_ENV['GOOGLE_ADS_DEVELOPER_TOKEN'] ?? '';
$clientId        = $_ENV['GOOGLE_ADS_CLIENT_ID'] ?? '';
$clientSecret    = $_ENV['GOOGLE_ADS_CLIENT_SECRET'] ?? '';
$refreshToken    = $_ENV['GOOGLE_ADS_REFRESH_TOKEN'] ?? '';
$openAiApiKey    = $_ENV['OPENAI_API_KEY'] ?? '';

// Multiple Customer IDs (for selection)
$customerId = $_ENV['GOOGLE_ADS_CUSTOMER_ID'] ?? '';
$campaignId = $_ENV['GOOGLE_ADS_CAMPAIGN_ID'] ?? '';
$loginCustomerId = str_replace('-', '', $_ENV['GOOGLE_ADS_LOGIN_CUSTOMER_ID'] ?? '');

// Get selected customer ID from form (default: first)
$customerId = str_replace('-', '', $customerId);

// ✅ Initialize Google Ads Client
try {
    $oAuth2Credentials = (new OAuth2TokenBuilder())
        ->withClientId($clientId)
        ->withClientSecret($clientSecret)
        ->withRefreshToken($refreshToken)
        ->build();

    $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withDeveloperToken($developerToken)
        ->withOAuth2Credential($oAuth2Credentials)
        ->withLoginCustomerId($loginCustomerId)
        ->build();
} catch (Exception $e) {
    logError("Google Ads Client Error: " . $e->getMessage());
    die("❌ Google Ads Client Error: " . $e->getMessage());
}

// ✅ Log errors to file
function logError($message)
{
    file_put_contents('response_log.txt', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

function getAdGroupIds($googleAdsClient, $customerId)
{
    $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();

    $query = "SELECT ad_group.id, ad_group.name FROM ad_group ORDER BY ad_group.id";

    // Create the request object
    $searchRequest = new SearchGoogleAdsRequest();
    $searchRequest->setCustomerId($customerId);
    $searchRequest->setQuery($query);

    // Make the API call
    $response = $googleAdsServiceClient->search($searchRequest);

    // Store ad groups in an array
    $adGroups = [];
    foreach ($response->iterateAllElements() as $googleAdsRow) {
        $adGroupId = $googleAdsRow->getAdGroup()->getId();
        $adGroupName = $googleAdsRow->getAdGroup()->getName();
        $adGroups[$adGroupId] = $adGroupName; // Store ad group ID and name
    }

    return $adGroups; // Return the array of ad groups
}

// ✅ Fetch search terms from Google Ads API
function fetchSearchTerms($googleAdsClient, $customerId, $campaignId)
{
    try {
        $query = "SELECT search_term_view.search_term FROM search_term_view
                  WHERE segments.date DURING LAST_30_DAYS AND campaign.id = $campaignId";

        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        $request = new SearchGoogleAdsRequest(['customer_id' => $customerId, 'query' => $query]);

        $response = $googleAdsServiceClient->search($request);
        $searchTerms = [];

        foreach ($response->iterateAllElements() as $row) {
            $searchTerms[] = $row->getSearchTermView()->getSearchTerm();
        }
        return $searchTerms;
    } catch (Exception $e) {
        logError("Error fetching search terms: " . $e->getMessage());
        return [];
    }
}

// ✅ Filter irrelevant search terms using OpenAI API
function filterIrrelevantTerms($searchTerms, $openAiApiKey, &$promptUsed)
{
    if (empty($searchTerms)) {
        return [];
    }

    // Prepare the prompt text for OpenAI API
    $prompt = "Please review the following list of search terms we got from google ads for our business which provides stock market intraday tips for traders and return only those that are irrelevant. Irrelevant terms could include broader market advice, long-term investing tips, or unrelated services. Please return only the irrelevant terms as a JSON array (e.g., ['irrelevant term 1', 'irrelevant term 2']). Do not include relevant terms.\n\n";
    $prompt .= "Search terms:\n" . implode(", ", $searchTerms);

    // Store the prompt for display or debugging
    $promptUsed = $prompt;

    // Initialize cURL session
    $ch = curl_init('https://api.openai.com/v1/chat/completions');

    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $openAiApiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout after 30 seconds
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-4-0613',  // Corrected to use gpt-4-0613
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 500, // Adjust token limit as needed
        'temperature' => 0.7  // Lower temperature for more focused and predictable results
    ]));

    // Execute the cURL request
    $response = curl_exec($ch);

    // Handle cURL errors
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        logError("cURL error: $error_msg");
        curl_close($ch);
        return ['error' => 'cURL error: ' . $error_msg];
    }

    // Get HTTP response code to check for API success
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code !== 200) {
        logError("Error response from OpenAI API: HTTP $http_code");
        curl_close($ch);
        return ['error' => "HTTP error $http_code from OpenAI API"];
    }

    // Close the cURL session
    curl_close($ch);

    // Handle empty response
    if (!$response) {
        logError('No response from OpenAI API');
        return ['error' => 'No response from OpenAI API'];
    }

    // Log the raw response for debugging purposes
    file_put_contents('openai_debug_log.txt', date('Y-m-d H:i:s') . " - " . $response . PHP_EOL, FILE_APPEND);

    // Decode the response JSON
    $data = json_decode($response, true);

    // Handle JSON parsing errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("Invalid JSON format: " . json_last_error_msg());
        return ['error' => 'Invalid JSON format', 'raw_response' => $response];
    }

    // Extract the content from the API response
    if (isset($data['choices'][0]['message']['content'])) {
        $irrelevantTermsJson = $data['choices'][0]['message']['content'];

        // Decode the irrelevant terms (should be a JSON array string)
        $irrelevantTerms = json_decode($irrelevantTermsJson, true);

        // Check for JSON decoding errors in the irrelevant terms
        if (json_last_error() !== JSON_ERROR_NONE) {
            logError("Invalid JSON format in OpenAI response content");
            return ['error' => 'Invalid JSON format in response content', 'raw_content' => $irrelevantTermsJson];
        }

        // Return irrelevant terms, ensuring it's an empty array if no terms are found
        return $irrelevantTerms ?? [];
    } else {
        logError('API response format error: ' . print_r($data, true));
        return ['error' => 'API response format error', 'raw_response' => $data];
    }
}

// ✅ Add negative keywords to Google Ads

function addNegativeKeywordsToGoogleAds(GoogleAdsClient $googleAdsClient, string $customerId, string $adGroupId, array $negativeKeywords)
{
    // Get the Google Ads service client
    $adGroupCriterionServiceClient = $googleAdsClient->getAdGroupCriterionServiceClient();

    // Initialize an array to hold operations
    $operations = [];

    foreach ($negativeKeywords as $term) {
        // Create the KeywordInfo object for the negative keyword
        $keywordInfo = new KeywordInfo([
            'text' => $term,
            'match_type' => KeywordMatchType::EXACT // Use EXACT match type
        ]);

        // Create the AdGroupCriterion object for the negative keyword
        $adGroupCriterion = new AdGroupCriterion([
            'ad_group' => $adGroupId,
            'status' => AdGroupCriterionStatus::ENABLED,
            'keyword' => $keywordInfo,
            'negative' => true // Mark this as a negative keyword
        ]);

        // Create the operation to add the negative keyword
        $operation = new AdGroupCriterionOperation();
        $operation->setCreate($adGroupCriterion);

        // Add the operation to the list
        $operations[] = $operation;
    }

    // Create the mutate request
    $mutateRequest = new MutateAdGroupCriteriaRequest([
        'customer_id' => $customerId,
        'operations' => $operations
    ]);

    // Submit the mutate request
    try {
        $response = $adGroupCriterionServiceClient->mutateAdGroupCriteria($mutateRequest);

        // Process the response
        $results = $response->getResults();
        $output = [];

        // Fetch full details for each created criterion
        foreach ($results as $result) {
            $resourceName = $result->getResourceName();

            // Use GoogleAdsServiceClient to fetch full details
            $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();

            // Create the search request
            $query = "SELECT ad_group_criterion.resource_name, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type
                      FROM ad_group_criterion
                      WHERE ad_group_criterion.resource_name = '$resourceName'";

            $searchRequest = new SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query
            ]);

            // Execute the search request
            $searchResponse = $googleAdsServiceClient->search($searchRequest);

            foreach ($searchResponse->iterateAllElements() as $row) {
                /** @var GoogleAdsRow $row */
                $output[] = [
                    'resource_name' => $row->getAdGroupCriterion()->getResourceName(),
                    'keyword_text' => $row->getAdGroupCriterion()->getKeyword()->getText(),
                    'match_type' => $row->getAdGroupCriterion()->getKeyword()->getMatchType()
                ];
            }
        }

        return $output; // Return the processed response
    } catch (GoogleAdsException $e) {
        // Handle Google Ads API errors
        foreach ($e->getGoogleAdsFailure()->getErrors() as $error) {
            printf(
                "Google Ads API error: %s, code: %s, message: %s\n",
                $error->getErrorCode()->getErrorCode(),
                $error->getMessage()
            );
        }
        return ['error' => "Google Ads API error: " . $e->getMessage()];
    } catch (ApiException $e) {
        // Handle general API exceptions
        return ['error' => "API exception: " . $e->getMessage()];
    }
}

// Handle form submission for negative keywords
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adGroupId = $_POST['ad_group_id']; // Replace with your ad group ID
    $adGroupId = 'customers/' . $customerId . '/adGroups/' . $adGroupId; // Use the correct format

    // Get negative keywords from the form
    $negativeKeywords = $_POST['negative_keywords'] ?? [];

    if (!empty($negativeKeywords)) {
        // Call the function to add negative keywords
        $response = addNegativeKeywordsToGoogleAds($googleAdsClient, $customerId, $adGroupId, $negativeKeywords);

        if (isset($response['error'])) {
            echo "Error: " . htmlspecialchars($response['error']);
        } else {
            echo "Negative keywords submitted successfully!";
            echo "<pre>";
            print_r($response); // Display the processed response
            echo "</pre>";
        }
    } else {
        echo "No negative keywords selected.";
    }
}
// Fetch search terms
$searchTerms = fetchSearchTerms($googleAdsClient, $customerId, $campaignId);
$promptUsed = '';
$irrelevantTerms = filterIrrelevantTerms($searchTerms, $openAiApiKey, $promptUsed);

?>

<!-- HTML Form and Display -->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Ads Negative Keywords</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Optional: Custom CSS styles */
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            margin-top: 30px;
        }

        .form-section {
            margin-top: 20px;
        }

        h2 {
            color: #343a40;
            font-size: 32px;
        }

        h3 {
            color: #007bff;
            font-size: 24px;
            margin-top: 20px;
        }

        .form-label {
            font-weight: bold;
        }

        .checkbox-container {
            margin-left: 20px;
        }

        .list-group-item {
            font-size: 14px;
            padding: 10px;
        }

        .btn-danger {
            font-weight: bold;
        }

        pre {
            background-color: #f1f1f1;
            padding: 10px;
            border-radius: 5px;
            font-size: 14px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .scrollable-box {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            background-color: #fff;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2 class="text-center">Google Ads Negative Keywords</h2>

        <!-- Search Terms Display -->
        <div class="form-section">
            <h3>All Search Terms</h3>
            <div class="scrollable-box">
                <ul class="list-group">
                    <?php foreach ($searchTerms as $term): ?>
                        <li class="list-group-item"><?= htmlspecialchars($term) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Irrelevant Search Terms Form -->
        <div class="form-section">
            <h3>Irrelevant Search Terms (Filtered)</h3>
            <form method="post">
                <input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerId) ?>">
                <input type="hidden" name="campaign_id" value="<?= htmlspecialchars($campaignId) ?>">
                <label for="ad_group_id">Select Ad Group:</label><br>
                <select id="ad_group_id" name="ad_group_id" required>
                    <option value="">-- Select an Ad Group --</option>
                    <?php
                    $adGroups = getAdGroupIds($googleAdsClient, $customerId);

                    // Populate the select input
                    foreach ($adGroups as $adGroupId => $adGroupName) {
                        echo "<option value='$adGroupId'>$adGroupName (ID: $adGroupId)</option>";
                    }
                    ?>
                </select><br><br>

                <div class="checkbox-container">
                    <?php foreach ($irrelevantTerms as $term): ?>
                        <div class="form-check">
                            <input type="checkbox" name="negative_keywords[]" value="<?= htmlspecialchars($term) ?>" class="form-check-input">
                            <label class="form-check-label"><?= htmlspecialchars($term) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <br>
                <button type="submit" class="btn btn-danger btn-lg">Submit to Google Ads</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>