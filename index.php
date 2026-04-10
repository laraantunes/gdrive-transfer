<?php

require_once 'vendor/autoload.php';

// Ensure it's run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$options = getopt("", ["email:", "folder_id::"]);

if (!isset($options['email'])) {
    die("Usage: php index.php --email=<new_owner_email> [--folder_id=<optional_folder_id>]\n");
}

$newOwnerEmail = $options['email'];
$rootFolderId = isset($options['folder_id']) && !empty($options['folder_id']) ? $options['folder_id'] : 'root';

echo "Starting Google Drive ownership transfer...\n";
echo "New Owner Email: $newOwnerEmail\n";
echo "Root Folder ID: $rootFolderId\n";

function getClient() {
    $client = new Google_Client();
    $client->setApplicationName('Google Drive Ownership Transfer');
    $client->setScopes(Google_Service_Drive::DRIVE);
    $client->setAuthConfig('client_secrets.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            if (array_key_exists('error', $accessToken)) {
                throw new Exception(implode(', ', $accessToken));
            }
        }
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

function updateOwnership($service, $fileId, $newOwnerEmail, $permissions, $retryNum = 0) {
    try {
        $alreadyOwner = false;
        $writerPermissionId = null;

        if ($permissions) {
            foreach ($permissions as $permission) {
                if (isset($permission->emailAddress) && strtolower($permission->emailAddress) === strtolower($newOwnerEmail)) {
                    if ($permission->getRole() === 'owner') {
                        $alreadyOwner = true;
                    } else {
                        $writerPermissionId = $permission->getId();
                    }
                }
            }
        }

        if ($alreadyOwner) {
            echo " - Already owner.\n";
            return true;
        }

        echo " - Transferring ownership...\n";

        if (!$writerPermissionId) {
            echo "   - Adding as writer first...\n";
            $newPermission = new Google_Service_Drive_Permission(array(
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $newOwnerEmail
            ));
            $createdPerm = $service->permissions->create($fileId, $newPermission, array('sendNotificationEmail' => false));
            $writerPermissionId = $createdPerm->getId();
            usleep(500000); // Wait 0.5s for propagation
        }
        
        // Transfer ownership
        $ownerPermission = new Google_Service_Drive_Permission(array(
            'role' => 'owner'
        ));
        
        $service->permissions->update($fileId, $writerPermissionId, $ownerPermission, array(
            'transferOwnership' => true,
        ));
        
        echo "   - Success!\n";
        return true;
        
    } catch (Exception $e) {
        $msg = $e->getMessage();
        echo "   - Error: " . $msg . "\n";
        
        // Retry logic for rate limits/quota exceeded
        if ($retryNum < 3 && (strpos($msg, 'Rate Limit Exceeded') !== false || strpos($msg, 'User Rate Limit Exceeded') !== false || strpos($msg, 'quotaExceeded') !== false)) {
            $sleepTime = pow(2, $retryNum) * 2; // exponential backoff
            echo "   - Sleeping for $sleepTime seconds before retrying...\n";
            sleep($sleepTime);
            return updateOwnership($service, $fileId, $newOwnerEmail, $permissions, $retryNum + 1);
        }
        return false;
    }
}

function transferOwnershipRecursive($service, $folderId, $newOwnerEmail) {
    echo "Processing folder ID: $folderId\n";
    $pageToken = null;
    
    do {
        try {
            $optParams = array(
                'q' => "'" . $folderId . "' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, permissions)',
                'pageToken' => $pageToken,
                'pageSize' => 50
            );
            $results = $service->files->listFiles($optParams);
            
            foreach ($results->getFiles() as $file) {
                echo "Processing: " . $file->getName() . " (" . $file->getId() . ")\n";
                
                updateOwnership($service, $file->getId(), $newOwnerEmail, $file->getPermissions());

                if ($file->getMimeType() === 'application/vnd.google-apps.folder') {
                    // Recurse into this folder
                    transferOwnershipRecursive($service, $file->getId(), $newOwnerEmail);
                }
            }
            
            $pageToken = $results->getNextPageToken();
        } catch (Exception $e) {
            echo "Error listing files in folder $folderId: " . $e->getMessage() . "\n";
            echo "Sleeping for 5 seconds before attempting to continue...\n";
            sleep(5);
            // It could be rate limit, we will just clear pageToken and skip remaining in this directory to avoid infinite loops, but ideally more robust retry could be added here.
            $pageToken = null; 
        }
    } while ($pageToken != null);
}

try {
    $client = getClient();
    $service = new Google_Service_Drive($client);
    transferOwnershipRecursive($service, $rootFolderId, $newOwnerEmail);
    echo "Transfer completed.\n";
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
