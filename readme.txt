=== Simple SEO ===
Contributors: vitaliikaplia
Tags: seo
Requires at least: 7.0
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight SEO plugin for WordPress.

== Description ==

Simple SEO is in early development. It sets the title and the meta description of every page from templates with variables ({title}, {sep}, {site} and more), with the SEO title and description of a post, page or term taking precedence; prints the canonical address and tells search engines which pages to leave out (noindex, nofollow) by kind of page or one by one; makes an XML sitemap of what search engines may show, at the addresses Yoast SEO used; decides where attachment pages go; gives shared links a proper preview on Facebook, Telegram, X and the like (Open Graph); has its own settings for the home page, the 404 page and password-protected content, per language with wp-loc; and lets you choose which roles edit what. While another SEO plugin such as Yoast SEO is active, it prints nothing on the site.

== Installation ==

1. Upload the `simple-seo` folder to `wp-content/plugins/`.
2. Activate Simple SEO on the Plugins screen.

== Changelog ==

= 0.7.0 =
* XML sitemap at /sitemap_index.xml, with a sitemap for each post type, taxonomy and (while author archives are shown) the authors, 1000 addresses a page. The addresses are the ones Yoast SEO used, so a sitemap submitted to Google Search Console keeps working; /sitemap.xml and WordPress's /wp-sitemap.xml redirect to it.
* Only what search engines may show goes in: published posts without a password, not hidden by their kind or on their own, whose canonical address is their own; terms with posts. The page sitemap opens with the home page, other post types with their archive.
* Each address has its last modification date and the images of the post (featured image and images in the text).
* With wp-loc, each language has its own sitemaps under its own prefix (/en/post-sitemap.xml), listed language by language in the index; every address names its translations (xhtml:link alternate) and x-default. Types wp-loc does not translate stay in the default language.
* In a browser the sitemaps are a readable, styled list (CSS, in the language of the sitemap) instead of raw XML.
* robots.txt names the sitemap. Switched off on the Indexing tab, the WordPress sitemap is back.

= 0.6.0 =
* Special pages tab: title and description templates of the home page, the 404 page and password-protected content, and an image for social networks of the home page and of protected content.
* A post or page that waits for its password uses these templates instead of its post type's; its own SEO title, description and image still win.
* With wp-loc, the special pages settings are kept for each language: the settings page edits the language chosen in the admin bar.
* Fixed: settings stored under a numeric key (such as 404) were lost when read.

= 0.5.0 =
* Open Graph tags for link previews on Facebook, Telegram, Viber, LinkedIn and the like: title, description, address, type, locale, site name, image with its size, type and alt text, publication and modification times of posts. X gets its card.
* Each post, page and term can have its own title, description and image for social networks; otherwise the SEO title and description, the featured image, or the default image.
* Social networks tab: the default image and the site's X account.
* Image fields pick from the media library.

= 0.4.0 =
* Canonical address on every page that search engines may show, with page numbers, instead of the one WordPress prints only on posts; a post or term can name another address.
* Indexing tab: which kinds of pages search engines may show — each post type, taxonomy, post type archive, author and date archives (date and author archives hidden by default). Search results and 404 are always hidden.
* Each post, page and term can be shown or hidden on its own and can ask search engines not to follow its links.
* Attachment pages redirect to the file (default), to the post they are attached to, or stay.
* Works with wp-loc: one canonical address, not two.

= 0.3.0 =
* The page title and the meta description are set on the site: from the SEO title and description of the post, page or term, otherwise from templates.
* Titles tab: the separator and title and description templates for every post type, taxonomy, post type archive, author and date archives and search results, with variables such as {title}, {sep}, {site}, {page} and {excerpt}.
* "Insert structure" button next to the fields and templates; the editor shows the title and description the page has now.
* While Yoast SEO or another SEO plugin is active, Simple SEO prints nothing on the site.

= 0.2.0 =
* SEO title and meta description fields for posts, pages, custom post types and terms, in the classic and the block editor.
* SEO settings page with an Access tab: which roles change the settings, edit SEO fields and edit indexing fields.
* Requires WordPress 7.0.

= 0.1.0 =
* Initial plugin skeleton.
