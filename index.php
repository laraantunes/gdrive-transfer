<?php

require_once 'vendor/autoload.php';

// Ensure it's run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$options = getopt("", ["email:", "folder_id::", "action::"]);

if (!isset($options['email'])) {
    die("Usage: php index.php --email=<email> [--folder_id=<optional_folder_id>] [--action=request|accept]\n");
}

$email = $options['email'];
$rootFolderId = isset($options['folder_id']) && !empty($options['folder_id']) ? $options['folder_id'] : 'root';
$action = isset($options['action']) ? $options['action'] : 'request';

if (!in_array($action, ['request', 'accept'])) {
    die("Invalid action. Must be 'request' or 'accept'.\n");
}

echo "Starting Google Drive ownership transfer...\n";
echo "Action: " . strtoupper($action) . "\n";
echo "Target Email: $email\n";
echo "Root Folder ID: $rootFolderId\n\n";

if ($action === 'accept') {
    echo "WARNING: You must be authenticated as the NEW owner ($email) to accept transfers.\n";
    echo "If you are still authenticated as the old owner, delete token.json and re-run.\n\n";
}

function getClient()
{
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

function processOwnership($service, $fileId, $targetEmail, $permissions, $action, $retryNum = 0)
{
    try {
        $alreadyOwner = false;
        $targetPermissionId = null;
        $targetPermissionRole = null;
        $isPendingOwner = false;

        if ($permissions) {
            foreach ($permissions as $permission) {
                if (isset($permission->emailAddress) && strtolower($permission->emailAddress) === strtolower($targetEmail)) {
                    $targetPermissionRole = $permission->getRole();
                    if ($targetPermissionRole === 'owner') {
                        $alreadyOwner = true;
                    } else {
                        $targetPermissionId = $permission->getId();
                        if ($permission->getPendingOwner()) {
                            $isPendingOwner = true;
                        }
                    }
                }
            }
        }

        if ($alreadyOwner) {
            echo " - Already owner.\n";
            return true;
        }

        if ($action === 'request') {
            if ($isPendingOwner) {
                echo " - Already requested (Pending Owner).\n";
                return true;
            }

            echo " - Requesting ownership transfer...\n";

            // Step 1: Ensure they are a writer FIRST (pendingOwnerWriterRequired)
            if (!$targetPermissionId) {
                echo "   - Adding as writer first...\n";
                $newPermission = new Google_Service_Drive_Permission(array(
                    'type' => 'user',
                    'role' => 'writer',
                    'emailAddress' => $targetEmail
                ));
                $createdPerm = $service->permissions->create($fileId, $newPermission, array('sendNotificationEmail' => false));
                $targetPermissionId = $createdPerm->getId();
            } elseif ($targetPermissionRole !== 'writer') {
                echo "   - Upgrading existing permission to writer first...\n";
                $upgradePermission = new Google_Service_Drive_Permission(array(
                    'role' => 'writer'
                ));
                $service->permissions->update($fileId, $targetPermissionId, $upgradePermission);
            }

            // Step 2: Now we can safely send the pending ownership request
            echo "   - Setting pending ownership...\n";
            $updatePermission = new Google_Service_Drive_Permission(array(
                'role' => 'writer',
                'pendingOwner' => true
            ));

            $propSuccess = false;
            $propRetry = 0;
            while (!$propSuccess && $propRetry < 5) {
                try {
                    // We only update the pendingOwner flag. We do NOT pass transferOwnership=true here because that's only allowed when role='owner'
                    $service->permissions->update($fileId, $targetPermissionId, $updatePermission);
                    $propSuccess = true;
                    echo "   - Request sent!\n";
                } catch (Exception $e) {
                    $propMsg = $e->getMessage();
                    if (strpos($propMsg, 'pendingOwnerWriterRequired') !== false) {
                        $propRetry++;
                        echo "   - Google backend delay detected. Waiting 2s before retry $propRetry...\n";
                        sleep(2);
                    } elseif (strpos($propMsg, 'Rate Limit') !== false || strpos($propMsg, 'quota') !== false) {
                        // Pass it up to the main catch block for exponential backoff
                        throw $e;
                    } else {
                        // Some other error
                        throw $e;
                    }
                }
            }

        } elseif ($action === 'accept') {

            if (!$targetPermissionId) {
                echo " - Error: Target email does not have a permission ID to update on this file (They must be a pendingOwner first).\n";
                return false;
            }

            echo " - Accepting pending ownership transfer...\n";
            $ownerPermission = new Google_Service_Drive_Permission(array(
                'role' => 'owner'
            ));

            $service->permissions->update($fileId, $targetPermissionId, $ownerPermission, array(
                'transferOwnership' => true
            ));
            echo "   - Success! Now the owner.\n";
        }

        return true;

    } catch (Exception $e) {
        $msg = $e->getMessage();
        echo "   - Error: " . $msg . "\n";

        if ($retryNum < 3 && (strpos($msg, 'Rate Limit Exceeded') !== false || strpos($msg, 'User Rate Limit Exceeded') !== false || strpos($msg, 'quotaExceeded') !== false)) {
            $sleepTime = pow(2, $retryNum) * 2;
            echo "   - Sleeping for $sleepTime seconds before retrying overall process...\n";
            sleep($sleepTime);
            // Before full retry, we should clear targetPermissionId so logic evaluates fresh if we re-enter loop
            return processOwnership($service, $fileId, $targetEmail, $permissions, $action, $retryNum + 1);
        }
        return false;
    }
}


function transferOwnershipRecursive($service, $folderId, $targetEmail, $action)
{
    // If accepting, maybe only search for pendingOwner?
    // But since this is recursive folder traversal, we'll traverse and accept anything with pendingOwner=true

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

                processOwnership($service, $file->getId(), $targetEmail, $file->getPermissions(), $action);

                if ($file->getMimeType() === 'application/vnd.google-apps.folder') {
                    // Recurse into this folder
                    transferOwnershipRecursive($service, $file->getId(), $targetEmail, $action);
                }
            }

            $pageToken = $results->getNextPageToken();
        } catch (Exception $e) {
            echo "Error listing files in folder $folderId: " . $e->getMessage() . "\n";
            echo "Sleeping for 5 seconds before attempting to continue...\n";
            sleep(5);
            $pageToken = null;
        }
    } while ($pageToken != null);
}

try {
    $client = getClient();
    $service = new Google_Service_Drive($client);
    transferOwnershipRecursive($service, $rootFolderId, $email, $action);
    echo "Process completed.\n";
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}

