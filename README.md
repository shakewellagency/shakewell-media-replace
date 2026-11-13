# Shakewell Media Replace

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Replace a media library file with a new upload and keep the attachment ID and the file's address. Sizes and metadata are rebuilt, and the old size files are removed. It is a small, Shakewell-owned alternative to the third-party media replace plugins.

## What it does

- Adds **Replace file** to the attachment edit screen (a box), the media modal and the media library list row.
- Requires `upload_files` and `edit_post` on the attachment, plus a per-attachment nonce.
- Runs WordPress's own upload checks (`wp_check_filetype_and_ext`, allowed mime types) and the upload size limit.
- Requires the same broad type and the same extension, so the URL never changes.
- Regenerates sizes and metadata, calls `clean_attachment_cache`, and updates metadata through `wp_update_attachment_metadata`.
- Adds a version query string to previews in the admin so the browser shows the new picture.
- Accessible: real labels, keyboard operable, and a polite live status message.

Not in this version: renaming the file, and updating the address in post content. Images over WordPress's big image threshold are not scaled down on replacement, because scaling renames the file.

## Offload plugins

Files stored in S3 or on a CDN must be handled by the offload plugin. After a replacement this plugin:

1. Fires `wp_update_attachment_metadata`, which [Shakewell Media Offload](https://github.com/shakewellagency/shakewell-media-offload) listens to, so it re-uploads the new files.
2. Fires `do_action( 'shakewell_media_replace_replaced', $attachment_id, $path )` for any plugin that wants an explicit signal.

Old size files already in a bucket are not deleted.

## Work on it

```sh
composer install
npm ci
npm run env:start      # needs Docker
npm test
composer lint
npm run plugin-check
```

## Release

Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, then tag `vX.Y.Z`. The release workflow builds `shakewell-media-replace.zip`.

## Made by Shakewell

Part of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later. Use it, fork it and send fixes, or [talk to Shakewell](https://shakewell.agency) to have it set up or a plugin built.

See them all: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).
