# Simple SEO

Simple SEO is a lightweight SEO plugin for WordPress, built by Vitalii Kaplia for client and personal projects. It is meant to replace Yoast SEO on typical custom-built sites. Every feature is written from scratch; no code is taken from other SEO plugins.

The plugin is in early development. So far it sets the page title and the meta description — from each post's or term's own SEO fields, otherwise from templates with variables — prints the canonical address, tells search engines which pages to leave out (by kind of page or one by one), makes an XML sitemap (a sitemap for each language under wp-loc), builds llms.txt for AI assistants from the same pages, gives themes breadcrumbs and the primary category of a post, edits robots.txt and .htaccess with a history of versions (and puts back a .htaccess that takes the site down), removes what WordPress adds to the code of pages (each item by its own switch), decides where attachment pages go, gives shared links a proper preview on social networks (Open Graph and the X card), has its own settings for the home page, the 404 page and password-protected content (per language with wp-loc), and lets you choose which roles edit what. The SEO block of the post editor and of the term form shows how the page will look in search results, on a computer and on a phone, while you type. It also analyses the content of a post for a focus keyphrase and a few more, and how easy its text is to read, each with a score and what to do; the lists of posts show both scores, and a table edits the SEO titles and descriptions of many posts or terms at once. Themes made for Yoast SEO keep working without it: the Yoast functions they call (wpseo_replace_vars(), yoast_breadcrumb(), WPSEO_Primary_Term) are answered by Simple SEO, the Yoast filters they hook into (title, description, Open Graph, robots, breadcrumbs, sitemaps) change Simple SEO's output, and the Yoast post meta they read and write (_yoast_wpseo_title, _yoast_wpseo_metadesc and the like) is Simple SEO's own fields. The Import tab takes over the settings and the SEO fields of posts and terms of Yoast SEO, Rank Math or All in One SEO, and afterwards removes what the plugin left in the database. While another SEO plugin is active, it prints nothing on the site.

## Requirements

WordPress 7.0 or newer and PHP 8.1 or newer.

## Installation

Upload the `simple-seo` folder to `wp-content/plugins/` and activate it. Updates come through the normal WordPress updater, straight from the `master` branch of this repository.

## License

GPLv2 or later. Author: Vitalii Kaplia — [kaplia.pro](https://kaplia.pro/).
