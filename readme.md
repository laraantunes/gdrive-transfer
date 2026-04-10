# Google Drive Transfer Script

A simple CLI-based PHP Script to transfer ownership of files and folders in Google Drive to another user.

## Changes Made

1. **CLI Parameter Parsing**: The script now requires command-line arguments to specify the target behavior.
   - `--email`: (Required) The email address of the target account you want to transfer ownership to.
   - `--folder_id`: (Optional) The specific Google Drive folder ID to recursively process. If omitted, it will default to traversing from the root (`My Drive`).

2. **OAuth2 Flow**: If credentials are missing or expired, the script will output a URL. Opening this URL in your browser will let you log in, authorize the app, and provide an authentication code to enter back into the terminal. The session is then saved to `token.json` so you don't have to authenticate every run.

3. **Intelligent Ownership Logic**: Following your instructions, the script inspects the file's current permissions:
   - If the user is already the `owner`, it does nothing.
   - If the user has `reader` or no access, it grants `writer` permissions on the file first.
   - Once writer access is confirmed, it upgrades their permission to `owner` and legally transfers ownership.

4. **Error Handling & Rate Limits**: Due to Google's API Quotas, we can only perform so many actions in a row. The script will automatically intercept `Rate Limit Exceeded` errors and employ **exponential backoff**, sleeping for a few seconds before automatically continuing.

## How to Verify and Run

Open up a terminal (such as Command Prompt or PowerShell) pointing to `d:\Lara\xampp8.2\htdocs\gdrive-transfer`.

Run a test with a specific folder ID using the following format:
```bash
php index.php --email=new_owner@gmail.com --folder_id=YOUR_TEST_FOLDER_ID
```
> [!TIP]
> You can find a folder's ID in Google Drive by opening it in your browser and grabbing the long string of letters & numbers at the end of the URL.

Watch the terminal output as it processes each file. Ensure the test folder and its contents successfully migrate before attempting a larger folder!
