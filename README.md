# Simple SEO

Simple SEO is a lightweight SEO plugin for WordPress, built by Vitalii Kaplia for client and personal projects. It is meant to replace Yoast SEO on typical custom-built sites. Every feature is written from scratch; no code is taken from other SEO plugins.

The plugin is in early development. So far it sets the page title and the meta description — from each post's or term's own SEO fields, otherwise from templates with variables — prints the canonical address, tells search engines which pages to leave out (by kind of page or one by one), makes an XML sitemap (a sitemap for each language under wp-loc), decides where attachment pages go, gives shared links a proper preview on social networks (Open Graph and the X card), has its own settings for the home page, the 404 page and password-protected content (per language with wp-loc), and lets you choose which roles edit what. While another SEO plugin is active, it prints nothing on the site.

## Requirements

WordPress 7.0 or newer and PHP 8.1 or newer.

## Installation

Upload the `simple-seo` folder to `wp-content/plugins/` and activate it. Updates come through the normal WordPress updater, straight from the `master` branch of this repository.

## License

GPLv2 or later. Author: Vitalii Kaplia — [kaplia.pro](https://kaplia.pro/).
