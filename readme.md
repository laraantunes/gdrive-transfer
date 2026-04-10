# Google Drive Transfer Script

A simple CLI-based PHP Script to transfer ownership of files and folders in Google Drive to another user.

## Changes Made

1. **CLI Parameter Parsing**: The script now requires command-line arguments to specify the target behavior.
   - `--email`: (Required) The email address of the target account you want to transfer ownership to.
   - `--folder_id`: (Optional) The specific Google Drive folder ID to recursively process. If omitted, it will default to traversing from the root (`My Drive`).

2. **OAuth2 Flow**: If credentials are missing or expired, the script will output a URL. Opening this URL in your browser will let you log in, authorize the app, and provide an authentication code to enter back into the terminal. The session is then saved to `token.json` so you don't have to authenticate every run.

3. **Ownership Logic & Consent Constraints**: Google Drive consumer accounts no longer allow forced ownership transfers. To solve the "Consent is required" error, this tool utilizes a two-step process:
   - **Step 1 - Request (Old Account)**: The script grants `writer` permissions to the target email and flags them as a `pendingOwner`.
   - **Step 2 - Accept (New Account)**: The receiving user runs the script as themselves to officially accept the pending transfer by setting `transferOwnership` to true.

4. **Error Handling & Rate Limits**: Due to Google's API Quotas, we can only perform so many actions in a row. The script will automatically intercept `Rate Limit Exceeded` errors and employ **exponential backoff**, sleeping for a few seconds before automatically continuing.

## How to Verify and Run

Open up a terminal (such as Command Prompt or PowerShell) pointing to `d:\Lara\xampp8.2\htdocs\gdrive-transfer`.

### Step 1: Request Ownership Transfer
Run this authenticated as the **current owner**:
```bash
php index.php --email=new_owner@gmail.com --folder_id=YOUR_TEST_FOLDER_ID --action=request
```
> [!TIP]
> You can find a folder's ID in Google Drive by opening it in your browser and grabbing the long string of letters & numbers at the end of the URL.
> For the first time, running without `--action` will automatically default to `request`.

### Step 2: Accept Ownership Transfer
Because Google requires explicit consent, you must now run the script authenticated as the **new owner** to finalize the transfers.
1. Delete your `token.json` file in the folder to clear the old account's session.
2. Run the accept command:
```bash
php index.php --email=new_owner@gmail.com --folder_id=YOUR_TEST_FOLDER_ID --action=accept
```
3. Authenticate in the browser window using the **new owner's** google account.

Watch the terminal output as it processes each file. Ensure the test folder and its contents successfully migrate before attempting a larger folder!
