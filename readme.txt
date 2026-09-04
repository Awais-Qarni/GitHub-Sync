=== GitHub Sync ===
Contributors: Muhammad Awais
Tags: github, sync, deploy, git, version control
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later

Manual two-way syncing between a GitHub repository folder and a WordPress folder. You decide when to pull and when to push.

== Description ==

GitHub Sync links one folder in a GitHub branch to one folder on your site. Nothing happens on its own: you press **Pull** to bring GitHub changes into WordPress, or **Push** to send your WordPress changes back to GitHub with a commit message you write yourself.

= Features =

* **Manual pull and push.** Every sync is started by you, from the Mappings screen.
* **Your own commit messages.** Pushing opens a dialog where you describe the change before it is committed.
* **Built for large code bases.** A sync runs in small steps with a progress bar, so it never hits the PHP time limit, and only files whose contents actually changed are transferred.
* **Safe pulls.** Files are downloaded to a staging folder and checked against the hash GitHub reported before anything in your live folder is touched. Whatever is about to be overwritten is zipped first, and restored automatically if writing fails.
* **Activity log.** Every sync records what it did, what it skipped and why it failed, on the Logs screen.
* **Two ways to connect.** A GitHub App, or a fine-grained personal access token.
* **No webhook, no background scheduler, no cron.** Nothing needs to reach your site from the outside.

== Installation ==

1. Upload the `github-sync` folder to `wp-content/plugins/`.
2. Activate the plugin on the Plugins screen.
3. Open **GitHub Sync → Settings** and connect your GitHub account.
4. Open **GitHub Sync → Add Mapping** to link a repository folder to a folder on your site.

There is nothing to build and no Composer step.

== Connecting to GitHub ==

**Personal access token (quickest)**

1. In GitHub, open Settings → Developer settings → Personal access tokens → Fine-grained tokens.
2. Generate a token for the repositories you want to sync, with **Contents: Read and write**.
3. Paste it into the plugin Settings screen and press **Test connection**.

**GitHub App (better for teams)**

1. In GitHub, open Settings → Developer settings → GitHub Apps → New GitHub App.
2. Untick **Active** under Webhook. This plugin never receives webhooks.
3. Under Repository permissions, set **Contents** to **Read and write**.
4. Create the app, copy the App ID, generate a private key, and install the app on your repositories.
5. Paste the App ID and the private key into the plugin Settings screen and press **Test connection**.

Credentials can also be defined in `wp-config.php` as `GITHUB_SYNC_APP_ID`, `GITHUB_SYNC_PRIVATE_KEY` or `GITHUB_SYNC_TOKEN`, which take priority over the stored values.

== Frequently Asked Questions ==

= Does it deploy automatically when I push to GitHub? =

No. Automatic deployment was removed in 2.0. Syncing is always started by hand from the Mappings screen, which is predictable and needs no webhook, cron or background queue.

= What happens to a very large repository? =

The sync is split into steps of a few files each. The browser drives the steps and shows progress, so the work continues across as many requests as it needs. Files whose contents have not changed are never transferred, so a second sync is much faster than the first.

= Can I map to the whole of wp-content? =

No. A mapping must point at a folder inside the themes folder, the plugins folder, or a subfolder of wp-content. The plugin also refuses to write into its own folder or into its backup folder.

= What exactly is backed up? =

Only the files a pull is about to overwrite or delete, zipped before anything in your live folder is touched. It is not a site backup and it is not a copy of the whole folder. Its purpose is automatic rollback: if writing the new files fails part way through, the old ones are put straight back, so you never end up with a half-updated folder.

Pushing never creates a backup, because the repository history already holds every earlier version.

Backups need the ZipArchive PHP extension. If your host does not have it, pulls still run, and the Logs screen records that no backup was taken.

= Where are the backups, and how do I restore one by hand? =

In `wp-content/uploads/github-sync/backups`, protected from direct web access, and pruned automatically after 14 days.

There is no restore button in the admin: automatic rollback is the only thing that reads these archives. To recover an older file yourself, download the archive over FTP and unzip it over the destination folder. The paths inside the archive are relative to that folder.

= What does the Pause button do? =

It hides the Pull and Push buttons for that mapping so nobody, including you, can sync it by accident. Nothing is deleted and no settings are lost: the mapping keeps its ignore list, its tracked file hashes and its history, and Resume brings the buttons back.

= Which files are ignored? =

Only the ones you list, per mapping. The Add Mapping screen pre-fills a suggested list, and every line in it can be edited or deleted, there and later under Settings on the mapping. The single exception is a repository's own `.git` folder, which is never synced in either direction because copying it into or out of a checkout breaks that checkout.

= Can I close the browser tab during a sync? =

You can, and nothing breaks: the sync simply stops where it is. Files already written stay written, and the unfinished run is cleared the next time you start a sync for that mapping.

== Changelog ==

= 2.0.0 =
* Removed automatic deployment: the webhook endpoint, the signature validator, the background jobs and the bundled Action Scheduler library are gone.
* Pull and push now run in bounded steps with a progress bar, so large repositories sync reliably.
* Added a commit message dialog for pushes, plus a default message and commit author in Settings.
* Added the Logs screen, with filters for level, mapping and time, and readable per-entry details instead of raw JSON.
* Ignore rules now belong to the mapping. The Add Mapping screen pre-fills suggestions you can edit or delete, and a mapping's rules, direction and deletion policy can be changed afterwards from its Settings dialog.
* Rebuilt the admin screens: mapping cards with a repository to destination view, live progress, a destination path preview, and dialogs for pushing and editing.
* Added connection testing, personal access token support, and clearer GitHub setup instructions.
* Downloads are streamed to disk and verified against the hash GitHub reported before anything is written.
* File tracking is now indexed and de-duplicated, which fixes uncontrolled growth of the tracking table.
* Backups now use the validated destination folder, so rollback restores to the right place.
* Fixed pulling JSON files, which were previously decoded instead of written verbatim.

= 1.0.0 =
* Initial release.
