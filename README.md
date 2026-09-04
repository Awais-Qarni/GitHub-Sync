# GitHub Sync

A WordPress plugin that links one folder in a GitHub branch to one folder on your site, and syncs them when you say so.

Nothing happens on its own. You press **Pull** to bring GitHub changes into WordPress, or **Push** to send your WordPress changes back with a commit message you write yourself. There is no webhook, no cron job and no background queue, so nothing needs to reach your site from the outside.

## Features

- **Manual pull and push.** Every sync is started by you, from the Mappings screen.
- **Your own commit messages.** Pushing opens a dialog where you describe the change before it is committed.
- **Built for large code bases.** A sync runs in small steps with a progress bar, so it never hits the PHP time limit. Only files whose contents actually changed are transferred.
- **Safe pulls.** Files are downloaded to a staging folder and verified against the hash GitHub reported before anything in your live folder is touched. Whatever is about to be overwritten is zipped first, and restored automatically if writing fails.
- **Per-mapping ignore rules.** Suggestions are pre-filled when you add a mapping, and every line can be edited or removed.
- **Activity log.** Every sync records what it did, what it skipped and why it failed.
- **Two ways to connect.** A GitHub App, or a fine-grained personal access token.

## Requirements

- WordPress 6.0 or newer
- PHP 8.1 or newer
- The `ZipArchive` extension for pull backups (optional; without it a pull still runs and logs a warning)

## Installation

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate it on the Plugins screen.
3. Open **GitHub Sync → Settings** and connect your GitHub account.
4. Open **GitHub Sync → Add Mapping** to link a repository folder to a folder on your site.

There is nothing to build and no Composer step.

## Connecting to GitHub

### Personal access token (quickest)

1. In GitHub, open **Settings → Developer settings → Personal access tokens → Fine-grained tokens**.
2. Generate a token for the repositories you want to sync, with **Contents: Read and write**.
3. Paste it into the plugin Settings screen and press **Test connection**.

### GitHub App (better for teams)

1. In GitHub, open **Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Untick **Active** under Webhook. This plugin never receives webhooks.
3. Under Repository permissions, set **Contents** to **Read and write**.
4. Create the app, copy the App ID, generate a private key, and install the app on your repositories.
5. Paste the App ID and the private key into the plugin Settings screen and press **Test connection**.

Credentials can also live in `wp-config.php`, where they take priority over anything saved in the database:

```php
define( 'GITHUB_SYNC_TOKEN', 'github_pat_...' );
// or, for a GitHub App:
define( 'GITHUB_SYNC_APP_ID', '123456' );
define( 'GITHUB_SYNC_PRIVATE_KEY', "-----BEGIN RSA PRIVATE KEY-----\n..." );
```

## How syncing works

Every synced file has a content fingerprint recorded in the database, using the same hash Git itself uses. Comparison is on content, never on timestamps.

**Pull** asks GitHub for a listing of the branch, compares each entry against the recorded fingerprint and against what is really on disk, and downloads only what differs. Untouched files are never rewritten.

**Push** walks the destination folder, hashes each file, uploads only the ones that changed, and builds the new tree on top of the previous commit. The resulting commit's diff contains only the files you actually changed. If nothing differs, no commit is created at all.

Changed files are replaced whole; there is no line-by-line merging. Pull before you push if the same file may have been edited on both sides.

Deletions are opt in. Unless **Also remove files that were deleted on the other side** is ticked for a mapping, neither direction deletes anything.

## Where things live

| Path | What it holds |
| --- | --- |
| `src/Sync/` | The pull and push state machines |
| `src/GitHub/` | REST transport, authentication, tree and commit APIs |
| `src/Security/` | Path validation and credential encryption |
| `src/Admin/`, `templates/` | The admin screens |
| `wp-content/uploads/github-sync/` | Backups and per-run scratch space, blocked from web access |

## Safety notes

- A mapping must point inside the plugins folder, the themes folder, or a subfolder of `wp-content`. The plugin refuses to write into its own folder or its backup folder.
- Pushes never force-update a branch, so a branch that moved on GitHub reports an error instead of overwriting someone's work.
- A repository's own `.git` folder is never synced in either direction.
- Backups are kept in `wp-content/uploads/github-sync/backups` for 14 days.

## License

GPLv2 or later.
