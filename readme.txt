=== Shakewell Media Replace ===
Contributors: shakewellagency
Tags: media, replace, attachment, upload, library
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace a media library file with a new upload, keeping the attachment ID and URL. Sizes and metadata are regenerated.

== Description ==

Shakewell Media Replace adds a "Replace file" control to the attachment edit screen, the media modal and the media library list. Pick a new file and the old one is swapped out: the attachment ID and the file's address stay the same, thumbnails and other sizes are rebuilt, and the old size files are deleted.

Safety checks on every replacement:

* The user needs the `upload_files` capability and permission to edit that attachment, and the request carries a nonce.
* The new file must pass WordPress's own upload checks (allowed file types, real content type) and the upload size limit.
* It must be the same kind of file (an image for an image, a PDF for a PDF) and have the same extension, so the address does not change.

What it deliberately does not do: change the file name, or rewrite addresses in post content. Very large images are not scaled down and are not rotated for their EXIF orientation on replacement, because either would rename the file. A copy of the file kept from before an image-editor edit stays on disk, because content may still link to it.

= Offload plugins =

Files stored in a bucket or CDN must be handled by the offload plugin. After a replacement the plugin updates the attachment metadata with `wp_update_attachment_metadata`, which offload plugins already listen to (Shakewell Media Offload re-uploads the new files this way). It then fires `do_action( 'shakewell_media_replace_replaced', $attachment_id, $path )` for plugins that need an explicit signal. Old size files in a bucket are not removed by this plugin.

= External services =

None.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen.

== Frequently Asked Questions ==

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
