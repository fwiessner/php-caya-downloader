<?php

require 'vendor/autoload.php';

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Symfony\Component\Panther\Client;

/**
 * 1) Instantiate the class, providing:
 *    - user/pass
 *    - login URL
 *    - whether to run headless or visible
 *    - verbose mode (for additional output)
 * 2) ->login() uses Panther to automate login, close popups, etc.
 * 3) ->downloadPdfs() queries GraphQL for doc links, downloads them.
 */
class CayaDownloader
{
    private $user;
    private $pass;
    private $loginUrl;
    private $debug;
    private $verbose;
    
    /** @var \Symfony\Component\Panther\Client */
    private $client;
    
    /**
     * Headers we’ll capture after login, e.g. Authorization, Cookie.
     * Used by subsequent cURL requests.
     */
    private $headers = [];
    
    public function __construct($user, $pass, $loginUrl, $debug = false, $verbose = false)
    {
        $this->user     = $user;
        $this->pass     = $pass;
        $this->loginUrl = $loginUrl;
        $this->debug    = $debug;
        $this->verbose  = $verbose;
        
        $this->initBrowser();
    }
    
    /**
     * Hilfsmethode: Schreibt Log-Ausgaben, falls verbose aktiv ist.
     */
    private function log($message)
    {
        if ($this->verbose) {
            echo $message;
        }
    }
    
    /**
     * Configure and create the Panther Client (headless Chrome or visible).
     */
    private function initBrowser()
    {
        // Zuerst vorhandene Chromedriver-Prozesse beenden
        $this->killExistingChromeDriver();
        
        $browserArgs = ['--headless', '--no-sandbox'];
        if ($this->debug) {
            // Remove headless to see the browser window
            $browserArgs = ['--no-sandbox'];
        }
        
        $this->client = Client::createChromeClient(
            '/usr/bin/chromedriver',  // Path to your chromedriver
            $browserArgs,
            [
                'capabilities' => [
                    'goog:loggingPrefs' => [
                        'browser'     => 'ALL',
                        'performance' => 'ALL'
                    ]
                ]
            ]
            );
    }
    
    /**
     * 2) Navigate to login page, accept cookies, fill in credentials, click login, close popups.
     *    Then parse performance logs for Bearer token and cookies for cURL usage.
     */
    public function login()
    {
        $this->client->followRedirects(true);
        
        // Set window size (ensures everything is “visible” for queries)
        $size = new WebDriverDimension(1600, 1500);
        $this->client->manage()->window()->setSize($size);
        
        // --- 1) Go to login page
        $crawler = $this->client->request('GET', $this->loginUrl);
        
        // --- 2) Accept cookie banner if present
        $this->client->waitForVisibility('#cookiescript_buttons');
        $this->client->executeScript("document.querySelector('#cookiescript_accept')?.click();");
        
        // --- 3) Wait for form fields, fill in user/pass
        $this->client->waitForVisibility('#username');
        $this->client->findElement(WebDriverBy::cssSelector('#username'))->sendKeys($this->user);
        $this->client->findElement(WebDriverBy::cssSelector('#password'))->sendKeys($this->pass);
        
        // --- 4) Click login
        $this->client->executeScript("document.querySelector('button[type=\"submit\"] span')?.click();");
        sleep(2);
        
        // --- 5) Close tutorial / popups if they appear
        // Usetiful popup
        $this->client->executeScript("
            document.querySelector('button.uf-qfgyv-close-button[data-uf-button=\"close\"]')?.click();
        ");
        
        // 'Empfohlene Aktionen' dialog (button[data-testid=\"close-button\"])
        $this->client->executeScript("
            document.querySelector('button[data-testid=\"close-button\"]')?.click();
        ");
        sleep(2);
        
        // --- 6) Wait for main table to confirm we are logged in
        //     e.g. #caya-folderScreen-table
        $this->client->waitForVisibility('#caya-folderScreen-table', 15);
        
        // --- 7) Parse logs for Bearer token
        $driver = $this->client->getWebDriver();
        $log    = $driver->manage()->getLog("performance");
        
        $authHeader = null;
        foreach ($log as $entry) {
            $msg = json_decode($entry['message'], true);
            if (
                isset($msg['message']['method']) &&
                $msg['message']['method'] === 'Network.requestWillBeSent'
                ) {
                    $url = $msg['message']['params']['request']['url'] ?? '';
                    $hdr = $msg['message']['params']['request']['headers']['authorization'] ?? '';
                    // If the request is to "customer-api.caya.com" or "api.getcaya.com" and has 'authorization'
                    if (
                        ($hdr !== '') && (
                            strpos($url, 'https://customer-api.caya.com') === 0 ||
                            strpos($url, 'https://api.getcaya.com') === 0
                            )
                        ) {
                            $authHeader = $hdr; // e.g. "Bearer eyJ..."
                            break;
                        }
                }
        }
        
        // If we found an auth header, store it
        if ($authHeader) {
            $this->headers['Authorization'] = $authHeader;
        }
        
        // Also gather cookies for cURL
        $cookies = $this->client->getCookieJar()->all();
        $cookieStr = '';
        foreach ($cookies as $c) {
            $cookieStr .= $c->getName().'='.$c->getValue().'; ';
        }
        $this->headers['Cookie'] = $cookieStr;
        
        $this->log("Login complete.\n");
    }
    
    /**
     * 3) Download PDFs from “inbox” by:
     *    - Querying the GraphQL to get folder ID (if needed),
     *    - Then retrieve documents from that folder,
     *    - Then download each PDF link in the JSON.
     *
     * Zusätzlich wird überprüft, ob ein Dokument bereits heruntergeladen wurde.
     */
    public function downloadPdfs($saveDir)
    {
        // 3.1) OPTIONAL: Hole den Folder-ID über GraphQL
        $folderId = $this->fetchInboxFolderId();
        if (!$folderId) {
            echo "Failed to find folder ID, cannot continue.\n";
            return;
        }
        
        // 3.2) Query documents in the folder
        $docs = $this->fetchDocumentsForFolder($folderId);
        
        // Lade Liste der bereits heruntergeladenen Dateien
        $downloadedFiles = $this->loadDownloadedFiles($saveDir);
        
        // 3.3) Download each doc file from the “file” link
        foreach ($docs as $doc) {
            // Verwende z.B. die 'id' als eindeutigen Identifikator
            $docId = $doc['id'];
            
            if (in_array($docId, $downloadedFiles)) {
                $this->log("Dokument mit ID {$docId} wurde bereits heruntergeladen, überspringe.\n");
                continue;
            }
            
            $fileUrl  = $doc['file'] ?? '';
            $filename = $doc['filename'] ?? ($doc['id'].'-unnamed.pdf');
            if (!$fileUrl) {
                $this->log("No file URL for docId={$doc['id']}, skipping.\n");
                continue;
            }
            $this->downloadFile($fileUrl, $saveDir, $filename);
            
            // Nach erfolgreichem Download: Dokument-ID speichern
            $downloadedFiles[] = $docId;
            $this->saveDownloadedFiles($saveDir, $downloadedFiles);
        }
    }
    
    /**
     * Hilfsmethode: Lädt die Liste bereits heruntergeladener Dateien aus einer JSON-Datei.
     */
    private function loadDownloadedFiles($saveDir)
    {
        $filePath = 'downloaded_files.json';
        if (file_exists($filePath)) {
            $json = file_get_contents($filePath);
            $downloaded = json_decode($json, true);
            if (!is_array($downloaded)) {
                $downloaded = [];
            }
        } else {
            $downloaded = [];
        }
        return $downloaded;
    }
    
    /**
     * Hilfsmethode: Speichert die Liste der heruntergeladenen Dateien als JSON.
     */
    private function saveDownloadedFiles($saveDir, array $downloaded)
    {
        $filePath = 'downloaded_files.json';
        file_put_contents($filePath, json_encode($downloaded));
    }
    
    /**
     * Beispiel: Hole Folder-ID via GraphQL (z. B. meFolders->inbox->id)
     */
    private function fetchInboxFolderId()
    {
        // Das Request-Payload für die GraphQL-Abfrage
        $payload = '[{
            "operationName": "MeCustomer",
            "variables": {},
            "query": "query MeCustomer { meFolders { inbox { id } archive { id } trash { id } } }"
        }]';
        
        $jsonResponse = $this->postGraphQL('https://customer-api.caya.com/', $payload);
        
        if (!is_array($jsonResponse) || empty($jsonResponse[0]->data->meFolders->inbox->id)) {
            return null;
        }
        
        return $jsonResponse[0]->data->meFolders->inbox->id;
    }
    
    /**
     * Beispiel: Hole Dokumente für einen bestimmten Ordner.
     */
    private function fetchDocumentsForFolder($folderId)
    {
        $payload = '[{
            "operationName":"DocumentsConnection",
            "variables":{
                "orderBy":"createdAt_DESC",
                "first":50,
                "where":{
                    "folder":{"id":"'.$folderId.'"},
                    "unread":false,
                    "type_in":["DocumentDigital","DocumentScan"]
                }
            },
            "query":"query DocumentsConnection($after: String, $before: String, $first: Int, $last: Int, $orderBy: ContainerOrderByInput = createdAt_DESC, $skip: Int, $where: ContainerWhereInput!) {\\n  connection: containersConnection(\\n    after: $after\\n    before: $before\\n    first: $first\\n    last: $last\\n    orderBy: $orderBy\\n    skip: $skip\\n    where: $where\\n  ) {\\n    edges {\\n      node {\\n        ... on ContainerDocument {\\n          id\\n          filename\\n          file\\n          createdAt\\n          documentId\\n          metadata { subject tags senderName }\\n        }\\n      }\\n    }\\n  }\\n}"
        }]';
        
        $jsonResponse = $this->postGraphQL('https://customer-api.caya.com/', $payload);
        if (!is_array($jsonResponse) || !isset($jsonResponse[0]->data->connection->edges)) {
            return [];
        }
        
        $edges = $jsonResponse[0]->data->connection->edges;
        $results = [];
        foreach ($edges as $edge) {
            $node = $edge->node;
            $results[] = [
                'id'         => $node->id,
                'filename'   => $node->filename,
                'file'       => $node->file,
                'documentId' => $node->documentId,
            ];
        }
        return $results;
    }
    
    /**
     * Hilfsmethode für einen POST-Request an den GraphQL-Endpunkt.
     */
    private function postGraphQL($url, $payload)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        // Baue die Request-Header zusammen
        $headers = [
            'Content-Type: application/json',
        ];
        foreach ($this->headers as $k => $v) {
            $headers[] = "$k: $v";
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $raw = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "CURL ERROR: " . curl_error($ch) . "\n";
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        
        return json_decode($raw);
    }
    
    /**
     * Lädt die PDF (oder eine andere Datei) von einem S3-Link mit ?X-Amz-Signature=...
     * und speichert sie unter $saveDir/$filename.
     */
    private function downloadFile($url, $saveDir, $filename)
    {
        // Bereinige den Dateinamen für das Dateisystem
        $filenameSafe = preg_replace('/[^\w\-.]/', '_', $filename);
        $destPath     = rtrim($saveDir, '/').'/'.$filenameSafe;
        
        $this->log("Downloading $url => $destPath\n");
        
        $ch = curl_init($url);
        $baseHeaders = [
            'User-Agent: caya-downloader/1.0',
        ];
        if (!empty($this->headers['Cookie'])) {
            $baseHeaders[] = 'Cookie: ' . $this->headers['Cookie'];
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $baseHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $data = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "CURL ERROR (downloadFile): " . curl_error($ch) . "\n";
        } else {
            file_put_contents($destPath, $data);
            $this->log("Saved PDF => $destPath\n");
        }
        curl_close($ch);
    }
    
    /**
     * Hilfsmethode: Beendet alle laufenden Chromedriver-Prozesse, falls vorhanden.
     */
    private function killExistingChromeDriver()
    {
        // Überprüfen, ob ein Chromedriver-Prozess läuft (Linux-Befehl)
        exec('pgrep chromedriver', $output, $returnVar);
        if (!empty($output)) {
            // Falls Prozesse gefunden wurden, beenden wir sie
            exec('pkill chromedriver');
            $this->log("Vorhandene Chromedriver-Prozesse wurden beendet.\n");
        }
    }
}

// -------------------------------------------------------------------------
// USAGE EXAMPLE
// -------------------------------------------------------------------------

include 'settings.php';

// Bestimme, ob der Parameter "-v" übergeben wurde (verbose mode)
$verbose = (isset($argv) && in_array('-v', $argv));

// Falls du den Browser im Debug-Modus (sichtbar) haben möchtest, übergebe true:
$downloader = new CayaDownloader($user, $pass, $login_url, $debug = false, $verbose);

// 2) Log in
$downloader->login();

// 3) Download PDFs in das Verzeichnis (z. B. /tmp/)
$downloader->downloadPdfs($save_dir);
