<?php

require_once 'vendor/autoload.php';

// Ensure it's run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

echo "Starting Google Drive bulk ownership acceptance...\n";
echo "WARNING: You must be authenticated as the NEW owner to accept transfers.\n";
echo "If you are still authenticated as the old owner, delete token.json and re-run to auth as the new owner.\n\n";

function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('Google Drive Bulk Accept');
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
            printf("Open the following link in your browser to authenticate:\n%s\n\n", $authUrl);
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

try {
    $client = getClient();
    $service = new Google_Service_Drive($client);
    
    // Attempt to get the logged-in user's email
    $myEmail = 'unknown';
    try {
        $about = $service->about->get(array('fields' => 'user'));
        $myEmail = $about->getUser()->getEmailAddress();
        echo "Authenticated as: $myEmail\n";
    } catch (Exception $e) {
        echo "Could not fetch user info, proceeding anyway. (" . $e->getMessage() . ")\n";
    }
    
    echo "Scanning all visible files (excluding ones you already own) for pending ownership...\n";
    
    $pageToken = null;
    $processedCount = 0;
    $acceptedCount = 0;
    $pageCount = 0;
    
    do {
        try {
            $pageCount++;
            // We search for files not trashed and not already owned by 'me'
            // This heavily reduces the search space specifically for acceptance
            $optParams = array(
                'q' => "trashed = false and not 'me' in owners",
                'fields' => 'nextPageToken, files(id, name, permissions)',
                'pageToken' => $pageToken,
                'pageSize' => 500
            );
            $results = $service->files->listFiles($optParams);

            $files = $results->getFiles();
            if (empty($files)) {
                echo "Page $pageCount: No new candidates found on this page. Continuing scan...\n";
            }
            
            foreach ($files as $file) {
                $processedCount++;
                $permissions = $file->getPermissions();
                $targetPermissionId = null;
                $isPendingOwner = false;
                
                if ($permissions) {
                    foreach ($permissions as $permission) {
                        $isUserMatch = false;
                        if (isset($permission->emailAddress) && $myEmail !== 'unknown' && strtolower($permission->emailAddress) === strtolower($myEmail)) {
                            $isUserMatch = true;
                        }
                        
                        // If it explicitly matches our email OR if the permission has pendingOwner=true
                        // (as the active user can generally only see/accept their own pending statuses)
                        if ($isUserMatch || $permission->getPendingOwner()) {
                             if ($permission->getPendingOwner()) {
                                $targetPermissionId = $permission->getId();
                                $isPendingOwner = true;
                                break;
                             }
                        }
                    }
                }
                
                if ($isPendingOwner && $targetPermissionId) {
                    echo "Found pending ownership for: " . $file->getName() . " (" . $file->getId() . ")\n";
                    echo " - Accepting...\n";
                    
                    $retryNum = 0;
                    $success = false;
                    while (!$success && $retryNum < 5) {
                        try {
                            $ownerPermission = new Google_Service_Drive_Permission(array(
                                'role' => 'owner'
                            ));

                            $service->permissions->update($file->getId(), $targetPermissionId, $ownerPermission, array(
                                'transferOwnership' => true
                            ));
                            echo "   - Success!\n";
                            $acceptedCount++;
                            $success = true;
                        } catch (Exception $e) {
                            $msg = $e->getMessage();
                            echo "   - Error accepting: " . $msg . "\n";
                            if (strpos($msg, 'Rate Limit Exceeded') !== false || strpos($msg, 'User Rate Limit Exceeded') !== false || strpos($msg, 'quotaExceeded') !== false) {
                                $sleepTime = pow(2, $retryNum) * 2;
                                echo "   - Sleeping for $sleepTime seconds before retrying...\n";
                                sleep($sleepTime);
                                $retryNum++;
                            } else {
                                break; // Other errors break the retry loop and continue to the next file
                            }
                        }
                    }
                }
            }

            $pageToken = $results->getNextPageToken();
            if ($processedCount > 0 && $processedCount % 500 === 0) {
                echo "Processed $processedCount files (not owned by me) so far across $pageCount pages...\n";
            }
        } catch (Exception $e) {
            echo "Error listing files: " . $e->getMessage() . "\n";
            $msg = $e->getMessage();
            if (strpos($msg, 'Rate Limit') !== false || strpos($msg, 'quota') !== false) {
                 echo "Sleeping for 10 seconds before continuing list iteration...\n";
                 sleep(10);
            } else {
                 echo "Non-retryable error during list. Aborting scan.\n";
                 break;
            }
        }
    } while (!empty($pageToken));

    echo "\nCompleted scanning.\n";
    echo "Total files checked (not owned by me): $processedCount\n";
    echo "Total ownerships accepted: $acceptedCount\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
