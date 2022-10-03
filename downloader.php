<?php
define('LOCKFILE', __DIR__ . '/downloader.lock');

$lock_file = fopen(LOCKFILE, 'c');
$got_lock = flock($lock_file, LOCK_EX | LOCK_NB, $wouldblock);
if ($lock_file === false || (! $got_lock && ! $wouldblock)) {
    throw new Exception("Unexpected error opening or locking lock file. Perhaps you " . "don't  have permission to write to the lock file or its " . "containing directory?");
} else if (! $got_lock && $wouldblock) {
    exit("Another instance is already running; terminating.\n");
}

// Lock acquired; let's write our PID to the lock file for the convenience
// of humans who may wish to terminate the script.
ftruncate($lock_file, 0);
fwrite($lock_file, getmypid() . "\n");

require 'vendor/autoload.php';

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\Remote\DesiredCapabilities;

$user = "user@name.tld";
$pass = "XXXXXXXXXX";
$login_url = "https://app.caya.com/login";


$save_dir = "/tmp/";

$d = new caya_downloader($user, $pass, $login_url);

$d->download_pdfs($save_dir);

class caya_downloader
{

    function __construct($username, $password, $url, $debug = false)
    {
        $this->_user = $username;
        $this->_pass = $password;
        $this->_url = $url;

        $this->cookie_file = "/tmp/cookiefile";

        $browserdriver_params = [
            '--headless',
            '--no-sandbox'
        ];
        if ($debug) {
            $browserdriver_params = [
                '--no-sandbox'
            ];
        }

        $this->client = \Symfony\Component\Panther\Client::createChromeClient('/usr/bin/chromedriver', $browserdriver_params, [
            'capabilities' => [
                'goog:loggingPrefs' => [
                    'browser' => 'ALL', // calls to console.* methods
                    'performance' => 'ALL' // performance data
                ]
            ]
        ]);

        $this->login();
    }

    private function login()
    {
        $this->client->followRedirects(true);
        $size = new WebDriverDimension(1600, 1500);
        $this->client->manage()
            ->window()
            ->setSize($size);

        $crawler = $this->client->request('GET', $this->_url);

        $this->client->waitForVisibility('#cookiescript_wrapper');
        $this->client->executeScript("document.querySelector('#cookiescript_accept').click()");

        $this->client->waitForVisibility('#launcher');
        $this->client->waitForVisibility('#username');

        $this->client->findElement(WebDriverBy::cssSelector('#username'))->sendKeys($this->_user);
        $this->client->findElement(WebDriverBy::cssSelector('#password'))->sendKeys($this->_pass);

        $this->client->executeScript("document.querySelector('button[type=\"submit\"] span').click()");

        $this->client->waitForVisibility('#caya-folderScreen-table');

        $this->client->waitForVisibility('.ReactVirtualized__Grid__innerScrollContainer');


        $driver = $this->client->getWebDriver();
        $log = $driver->manage()->getLog("performance");

        $header = array();
        foreach ($log as $entry) {
            $log_entry = json_decode($entry["message"]);
            if (isset($log_entry->message->method) && $log_entry->message->method == "Network.requestWillBeSent") {
                if (preg_match("/^https:\/\/api.getcaya.com/", $log_entry->message->params->request->url)) {
                    if (isset($log_entry->message->params->request->headers->authorization)) {
                        $header["Authorization"] = $log_entry->message->params->request->headers->authorization;
                        break;
                    }
                }
            }
        }

        $cookies = $this->client->getCookieJar()->all();
        foreach ($cookies as $c) {
            $curl_cookies[$c->getName()] = $c->getValue();
        }

        $cookie_string = "";
        foreach ($curl_cookies as $name => $value) {
            $cookie_string .= $name . "=" . $value . "; ";
        }

        $header["Cookie"] = $cookie_string;

        $this->header = $header;

    }

    function download_pdfs($dir)
    {
        $header = $this->header;

        $header["Content-Type"] = "application/json";
        
        
        $payload_usersettings = '[{"operationName":"MeUser","variables":{},"query":"query MeUser {\n  meUser {\n    ...User\n    __typename\n  }\n}\n\nfragment User on User {\n  id\n  email\n  name\n  firstName\n  lastName\n  role\n  status\n  locale\n  __typename\n}\n"},{"operationName":"SaasOnly","variables":{},"query":"query SaasOnly {\n  me {\n    ...SaasOnly\n    __typename\n  }\n}\n\nfragment SaasOnly on Customer {\n  id\n  saasOnly\n  __typename\n}\n"},{"operationName":"contactConnection","variables":{},"query":"query contactConnection($where: ContactWhereInput, $after: String, $first: Int) {\n  contactConnection(where: $where, after: $after, first: $first) {\n    pageInfo {\n      startCursor\n      endCursor\n      hasNextPage\n      hasPreviousPage\n      __typename\n    }\n    aggregate {\n      count\n      __typename\n    }\n    edges {\n      cursor\n      node {\n        ...Contact\n        __typename\n      }\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment Contact on Contact {\n  email\n  name\n  __typename\n}\n"},{"operationName":"MeUsers","variables":{},"query":"query MeUsers {\n  meUsers {\n    ...User\n    __typename\n  }\n}\n\nfragment User on User {\n  id\n  email\n  name\n  firstName\n  lastName\n  role\n  status\n  locale\n  __typename\n}\n"},{"operationName":"MeCustomer","variables":{},"query":"query MeCustomer {\n  me {\n    ...Customer\n    __typename\n  }\n  meFolders {\n    inbox {\n      ...ContainerRootFolder\n      __typename\n    }\n    archive {\n      ...ContainerRootFolder\n      __typename\n    }\n    trash {\n      ...ContainerRootFolder\n      __typename\n    }\n    sharedWithMe {\n      ...ContainerRootFolder\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment Customer on Customer {\n  id\n  publicId\n  encodedPublicId\n  planId\n  email\n  ...PhoneNumber\n  firstName\n  lastName\n  companyName\n  address {\n    ...Address\n    __typename\n  }\n  returnsAll\n  returnsNextAt\n  returnsInterval\n  ...PaymentConsent\n  plan {\n    name\n    alias\n    price\n    canCancel\n    canReactivate\n    startDate\n    renewalDate\n    endDate\n    isActive\n    __typename\n  }\n  newsletter\n  scanCenter\n  cayaAddress\n  createdAt\n  updatedAt\n  isBehindPaywall\n  showCustomerSupportNumber\n  ...ShowShredConsent\n  cayaEmail\n  paymentMethodStatus\n  ...SaasOnly\n  ...MeWorkflows\n  __typename\n}\n\nfragment PhoneNumber on Customer {\n  id\n  phoneNumber\n  __typename\n}\n\nfragment Address on Address {\n  id\n  lines\n  streetAddress\n  city\n  zip\n  countryCode\n  __typename\n}\n\nfragment PaymentConsent on Customer {\n  id\n  paymentConsentAcceptedAt\n  __typename\n}\n\nfragment ShowShredConsent on Customer {\n  id\n  showShredConsent\n  __typename\n}\n\nfragment SaasOnly on Customer {\n  id\n  saasOnly\n  __typename\n}\n\nfragment MeWorkflows on Customer {\n  id\n  workflowTier\n  hasAcceptedWorkflowTCs\n  __typename\n}\n\nfragment ContainerRootFolder on ContainerRootFolder {\n  id\n  createdAt\n  updatedAt\n  canArchive\n  canTrash\n  canReturn\n  canUnreturn\n  canInbox\n  canShare\n  canDownload\n  type\n  __typename\n}\n"},{"operationName":"UserRole","variables":{},"query":"query UserRole {\n  meUser {\n    id\n    role\n    __typename\n  }\n}\n"}]';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.getcaya.com/");
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_POST, true);
        foreach ($header as $k => $v) {
            $curl_header[] = "$k: $v";
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_usersettings);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return "CURL ERROR - " . curl_error($ch);
        } else {
            $json_result = json_decode($result);
        }
        
        $mailbox_folder_id = $json_result[4]->data->meFolders->inbox->id ; 

        $payload = '[{"operationName":"QuotasDestinations","variables":{},"query":"query QuotasDestinations {\n  quotasDestinations {\n    maximum\n    free\n    remaining\n    limitReached\n    __typename\n  }\n}\n"},{"operationName":"MeQuotas","variables":{},"query":"query MeQuotas {\n  quotas {\n    sources {\n      ...Quota\n      __typename\n    }\n    returns {\n      ...Quota\n      __typename\n    }\n    scheduledReturns {\n      ...Quota\n      __typename\n    }\n    seats {\n      ...Quota\n      __typename\n    }\n    scans {\n      ...Quota\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment Quota on Quota {\n  used\n  free\n  type\n  limitReached\n  enabled\n  maximum\n  remaining\n  __typename\n}\n"},{"operationName":"DocumentsConnection","variables":{"orderBy":"createdAt_DESC","first":1000,"where":{"folder":{"id":"'.$mailbox_folder_id.'"},"unread":false,"type_in":["DocumentDigital","DocumentScan"]}},"query":"query DocumentsConnection($after: String, $before: String, $first: Int, $last: Int, $orderBy: ContainerOrderByInput = createdAt_DESC, $skip: Int, $where: ContainerWhereInput!) {\n  connection: containersConnection(\n    after: $after\n    before: $before\n    first: $first\n    last: $last\n    orderBy: $orderBy\n    skip: $skip\n    where: $where\n  ) {\n    edges {\n      cursor\n      node {\n        ... on ContainerDocument {\n          ...ContainerDocument\n          __typename\n        }\n        __typename\n      }\n      __typename\n    }\n    pageInfo {\n      startCursor\n      endCursor\n      hasNextPage\n      hasPreviousPage\n      __typename\n    }\n    aggregate {\n      count\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment ContainerDocument on ContainerDocument {\n  id\n  documentType: type\n  folder {\n    ...ContainerFolderParent\n    __typename\n  }\n  metadata {\n    ...ContainerDocumentMetadata\n    __typename\n  }\n  ...DocumentFiles\n  ...DocumentCapabilities\n  createdAt\n  currentLocationType\n  updatedAt\n  nextLocationType\n  nextMoveAt\n  paywallType\n  isDestroyed\n  digitalSource\n  scanMetadata {\n    date\n    scanId\n    __typename\n  }\n  recipients {\n    ...ContainerRecipients\n    __typename\n  }\n  owner {\n    ...Contact\n    __typename\n  }\n  __typename\n}\n\nfragment ContainerFolderParent on ContainerFolderParent {\n  id\n  type\n  title\n  __typename\n}\n\nfragment ContainerDocumentMetadata on ContainerDocumentMetadata {\n  id\n  recipientName\n  senderEmail\n  senderName\n  senderPhone\n  subject\n  tags\n  __typename\n}\n\nfragment DocumentFiles on ContainerDocument {\n  id\n  file\n  fileToken\n  filePspdfkit\n  documentId\n  __typename\n}\n\nfragment DocumentCapabilities on ContainerDocument {\n  id\n  canInbox\n  canDownload\n  canArchive\n  canTrash\n  canReturn\n  canRequestRescan\n  canUnreturn\n  canMarkAsRead\n  canMarkAsUnread\n  canShred\n  canDestroy\n  canCopy\n  canShare\n  canPay\n  canDestroy\n  canEditMetadata\n  canDownloadOriginal\n  __typename\n}\n\nfragment ContainerRecipients on ContainerRecipients {\n  count\n  id\n  recipients {\n    ...ContainerRecipient\n    __typename\n  }\n  __typename\n}\n\nfragment ContainerRecipient on ContainerRecipient {\n  email\n  name\n  status\n  canUnshare\n  canEditPermission\n  __typename\n}\n\nfragment Contact on Contact {\n  email\n  name\n  __typename\n}\n"},{"operationName":"DocumentFilenamePattern","variables":{},"query":"query DocumentFilenamePattern {\n  documentFilenamePattern {\n    tokens\n    __typename\n  }\n}\n"}]';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.getcaya.com/");
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return "CURL ERROR - " . curl_error($ch);
        } else {
            $json_result = json_decode($result);
        }
   

        foreach ($json_result as $r) {

            if (isset($r->data->connection->edges)) {

                foreach ($r->data->connection->edges as $e) {

                    $file_url = $e->node->file;
                    $file_name = $e->node->documentId;
                    $file_date = $e->node->createdAt;
                    $file_date = preg_replace("/:/", "_", $file_date);

                    if (! file_exists($dir . $file_name . $file_date . ".pdf")) {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $file_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);
                        file_put_contents($dir . $file_name . $file_date . ".pdf", $result);
                    }
                }
            }
        }
    }
}
