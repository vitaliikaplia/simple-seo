=== Simple SEO ===
Contributors: vitaliikaplia
Tags: seo
Requires at least: 7.0
Requires PHP: 8.1
Stable tag: 0.46.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight SEO plugin for WordPress.

== Description ==

Simple SEO is in early development. It sets the title and the meta description of every page from templates with variables ({title}, {sep}, {site} and more), with the SEO title and description of a post, page or term taking precedence; prints the canonical address and tells search engines which pages to leave out (noindex, nofollow) by kind of page or one by one; makes an XML sitemap of what search engines may show, at the addresses Yoast SEO used; builds llms.txt for AI assistants from the same pages and, when switched on, a Markdown version of each of them; gives themes breadcrumbs and the primary category of a post; edits robots.txt and .htaccess with a history of versions; removes what WordPress adds to the code of pages (the version, the shortlink, discovery links, the emoji script, links to feeds), each by its own switch; decides where attachment pages go; gives shared links a proper preview on Facebook, Telegram, X and the like (Open Graph); tells search engines in Schema structured data what each page is and who stands behind the site, with FAQ pages from the questions and answers on them, JSON-LD of a page's own and local SEO (the type of the organization, its address, phone, opening hours and place on the map); gives WooCommerce products their place in the Schema graph and in Open Graph, the shop the SEO fields of its page and leaves the cart and the checkout out of search; gives images alt texts and titles from their names, on upload and in bulk; prints the codes of webmaster tools and the tags of Google Analytics, Google Tag Manager, Meta Pixel and TikTok Pixel, loaded without slowing pages down; shows the clicks, impressions, CTR and position of Google Search Console, for the site and for each post, in the admin; tells search engines through IndexNow when a page is published, changed or taken down; counts the addresses that answer 404 (no IP addresses) and hands them to the redirects of the WP Base theme; audits the pages of the sitemap as a visitor sees them, for titles and descriptions missing or the same, H1 headings, images without alt text, canonical addresses and noindex, and finds the broken links on them and the pages no other page links to; has its own settings for the home page, the 404 page and password-protected content, per language with wp-loc; and lets you choose which roles edit what. The SEO fields, the analysis, the imports and IndexNow work from WP-CLI too (wp simple-seo), the SEO fields of every post type and taxonomy are in the REST API, and AI agents get the SEO fields, the analysis, Search Console, the settings and IndexNow as tools through Simple MCP. The SEO block of the post editor and of the term form shows how the page will look in search results, on a computer and on a phone, while you type. It also analyses the content of a post for a focus keyphrase and a few more, and how easy its text is to read, each with a score and what to do, suggests the pages of the site its text could link to, and with an AI connected in Settings → Connectors writes the SEO title, the meta description and the focus keyphrase, the variables of the template kept, and the alt texts of images from what it sees in them; the lists of posts show both scores, and a table edits the SEO titles and descriptions of many posts or terms at once. Themes made for Yoast SEO keep working once Yoast is gone: Simple SEO answers the Yoast functions they call. While another SEO plugin such as Yoast SEO is active, it prints nothing on the site.

== Installation ==

1. Upload the `simple-seo` folder to `wp-content/plugins/`.
2. Activate Simple SEO on the Plugins screen.

== Changelog ==

= 0.46.0 =
* Fewer queries to the database. On an update Simple SEO puts right the autoload flag of each of its rows, so a row that came to be with another flag no longer costs a query on every page. The rows read on the site are autoloaded: llms.txt's (every page names it while the Markdown versions are on) and the monitor's (every 404 read it). Pages of the site now cost no query of Simple SEO's own; a 404 one, the count of its address.
* The admin: the states of the imports from other SEO plugins are read with one query on each screen instead of three; the analysis in the editor reads the other pages on the same keyphrase with one query.

= 0.45.0 =
* Markdown versions of pages (llmstxt.org, version 2), off until switched on in the llms.txt tab: each page search engines may show is also served as clean Markdown at its own address with index.md added (/about/ → /about/index.md, the home page → /index.md; /about.md works too) — its title and text without menus, scripts and styles, for AI assistants and agents. Posts of every type, the home page, the latest posts, terms and post type archives (the shop too), each kind on or off with its parts: the breadcrumbs, the description, the details (address, dates, author, terms; price, stock, SKU and attributes of products) and the featured image. A post or a term can say otherwise in its SEO fields.
* Pages name their Markdown version and the llms.txt that covers them (rel="alternate" type="text/markdown", rel="describedby") in their head and in the Link header; llms.txt links to the Markdown versions. A Markdown version is text/markdown, not for search results, with the page as its canonical address.
* The text is the one the site renders, blocks of themes included: headings, lists, quotes, tables, code, links and images.

= 0.44.0 =
* WooCommerce: the shop is the archive of products with the shop page's own SEO title, description, Open Graph and choice of indexing, its title (for {title} and the trails), excerpt and featured image. Any post type archive can have such a page: the filter simple_seo_archive_page.
* The trail of a product goes through the shop and the product's primary category with its parents (Home › Shop › Tables › Oak › the product); a category of products through the shop. The filter simple_seo_breadcrumb_taxonomy picks the taxonomy of a post type's trail when the settings choose none.
* The cart, the checkout and the account are hidden from search engines (WooCommerce does so too) and get no canonical. The sitemaps leave them out, and the shop page (the sitemap of products opens with its address), and products hidden from the catalog and the search.

= 0.43.0 =
* WooCommerce: the product of a product page is in the Schema graph of Simple SEO — WooCommerce's own product data (prices and sales, variations, stock, SKU, GTIN, rating and reviews), tied to its item page, its image, the site as the seller and the primary brand with its logo. WooCommerce prints no JSON-LD of its own of what the graph says; with the graph off, it prints its own as before.
* The shop's return policy (WooCommerce's page of it, when published) is on the organization of the graph.
* Open Graph of a product: the type product, the price as the shop shows it with its currency, the availability, the SKU and the brand.
* Products are item pages (ItemPage) by default; the Schema tab may choose another type.

= 0.42.0 =
* Alt texts of images written by AI from what it sees in the picture, in the language of the image (wp-loc) or of the site, through the provider connected in Settings → Connectors: the button "Write the alt text with AI" of an image in the media library and on its screen fills the alt text field; bulk editing of images writes the empty alt texts listed, one image after another, to look over and save; and a new image gets its alt text in the background (a WP-Cron event) a moment after the upload, switched on the AI tab (on by default).
* An alt text someone wrote is never replaced; the one Simple SEO made from the name of the file gives way. The AI is shown a smaller copy of the image (JPEG, PNG, GIF or WebP); a provider whose models do not read images says so.
* A text with a word broken by letters of another script, as models sometimes write ("волcurrent" for «волоссям»), is left out, and the AI is asked again, up to three times. Deleting the plugin removes the alt texts still waiting.

= 0.41.0 =
* AI in the SEO block, through the AI Client of WordPress 7.0 and whichever provider is connected in Settings → Connectors: the button "Write with AI" next to the SEO title, the meta description and the focus keyphrase of a post, and the SEO title and the meta description of a term, writes them from what the editor has now (the title, the excerpt, the text with its headings), in the language of the post or term (wp-loc) or of the site.
* In the SEO title and the meta description the AI writes only the words in place of {title} or {excerpt} of the template (the field's own, else the type's), so the separator, the site name and the other variables stay; it is asked for as many characters as the width search results give leaves, and a variant wider than that says so.
* The AI tab: whether a connected AI can write, with a link to Connectors, and how many variants it writes at a time, from 1 to 5: one goes straight into the field, of more the editor chooses. Without a connected provider there are no buttons. Whoever may edit the SEO fields of a post or term may use it.

= 0.40.0 =
* Suggestions of internal links in the SEO block of a post, under the content analysis and redone with it while the text is written: the other published pages of this site whose focus keyphrase, more keyphrases or title (two words or more) the text mentions, with the same starts of words the analysis matches, and that it does not link to yet. The pages mentioned most come first, ten at most; with wp-loc, the pages in the language of the post. The text is never changed.

= 0.39.0 =
* The audit lists the pages no other page of the sitemap links to, apart from the problems: a link of a page to itself does not count, and an address with or without its last slash is the same. When the sitemap had more pages than the audit reads, none is marked, since the pages not read may link to them.

= 0.38.0 =
* Broken links: once its pages are read, the audit checks the links on them, on this site and elsewhere, each address once. A HEAD request, and GET where a server does not take HEAD; a link to a page of the audit has its answer already. Requests to one host of another site go at most once a second (the filter simple_seo_audit_host_interval changes it); up to 5000 links (simple_seo_audit_max_links).
* A link that did not answer, or answered 429 or 5xx, is checked again a day later by a task of WP-Cron before it counts as broken.
* The page of the audit lists the broken links, the links of this site that redirect and those waiting for their check, each with its answer and the pages it is on.
* Deleting the plugin removes the links of the audit.

= 0.37.0 =
* An audit of pages: the page Audit under the SEO menu, for whoever sees the reports, opens the pages of the sitemap as a visitor sees them (the site asks itself for each, so what the theme prints is seen too) and reads what search engines read. It lists the pages that did not open or redirect, those without a title or a meta description or with the same one as other pages, without an H1 heading or with more than one, with images without alt text, with a canonical address that is another one, and those hidden from search results by noindex in the page or in the X-Robots-Tag header.
* The audit goes in the background, a few pages at a time, up to 2000 pages (the filter simple_seo_audit_max_pages changes it; simple_seo_audit_pages, the pages). A task of WP-Cron takes the next step, and the open page of the audit takes steps too, so a site without WP-Cron gets its audit as well. It can be stopped; its report stays until it runs again.
* When no page opens, the page says why: the site cannot reach its own address (a local site, a certificate, a firewall), or it is closed to visitors (a password, a maintenance mode, the login page).
* Deleting the plugin removes the report of the audit.

= 0.36.0 =
* A monitor of pages not found: every address of the site that answers 404 to a visitor is counted in a table of its own, with how many times it was asked for, the first and the last time and where the last visitor came from; no IP addresses. The arguments of trackers (utm_*, gclid, fbclid and the like) are left out of the address, an address is forgotten 90 days after it was last asked for, and at most 10,000 are kept. A redirect of the theme or of WordPress answers first, so an address it sends elsewhere is not counted. The monitor is on by default and is switched off on the 404 tab of the settings.
* The page Pages not found under the SEO menu, for whoever sees the reports: the addresses asked for most or last first, a search, hide an address (it is still counted), delete it, clear the list. On a site with the WP Base theme an address becomes a draft redirect rule of the theme in one click, with the address and 301 filled in, to finish there; Simple SEO makes no redirects itself.
* Deleting the plugin removes the table of the monitor.

= 0.35.0 =
* Tools for AI agents, as abilities of the WordPress Abilities API marked for MCP: Simple MCP 2.7 or newer (or another MCP server for WordPress) offers them. An agent reads and changes the SEO fields of a post or a term, finds the posts or terms without an SEO title or a meta description of their own, analyses a post, reads the data of Google Search Console and the settings, sees IndexNow and sends addresses through it. Each tool has the rights of whoever the MCP key or connection belongs to, as on the screens; the key of the Search Console service account is never given.
* On a site where Simple MCP was updated from a version older than 2.7.1, the agent sees the tools once the row of plugin and theme tools is ticked in the role permissions of Simple MCP.
* wp simple-seo analyze gives the title and the description its page shows in the language of the post, with wp-loc.

= 0.34.0 =
* WP-CLI commands, wp simple-seo: the SEO fields of a post or a term (post get, post update, term get, term update; an empty value clears a field), the fields there are (fields), the analysis of a post with the title and description its page shows (analyze), the import from Yoast SEO, Rank Math or All in One SEO with its report (import) and the removal of what they left (cleanup, which keeps the parts the theme reads unless named), and IndexNow (indexnow status, submit, send).
* The REST API has the SEO fields of posts and terms in a field of their own, simple_seo, on every post type and taxonomy with SEO fields, custom fields or not. It is read in the edit context, a checkbox as true or false and an image as its ID, and written as the editor writes: only the fields the user may edit, all the values sent or none, an empty value clears the field.
* An empty value written to an SEO field in any other way, with update_post_meta() or through the meta of the REST API, deletes the field rather than keeping it empty.

= 0.33.0 =
* IndexNow, on a tab of its own: when a page is published, changed, moved, taken down or deleted, its address goes to Bing a minute later, together with the others of that minute, from WordPress's scheduled tasks, so search engines visit it sooner. Only pages search engines may show are sent, as the sitemap lists them. The key is made by the plugin and its file answered at the root of the site. Nothing is sent while the site asks search engines not to index it, its address is local, or another SEO plugin is active. The tab shows the last answers; a batch Bing cannot take for now is tried again, three times in all.
* Yandex is no longer supported: the Integrations tab has no field for its code, and the import names Yandex's code among those not taken.

= 0.32.0 =
* Google Search Console in the admin. A Search Console page in the SEO menu shows the clicks, impressions, CTR and average position of the site for 7, 28 or 90 days, each beside the period before, with the top queries and the top pages; the SEO box of a published post shows its own and its top queries, loaded after the editor opens. With wp-loc each page shows its language.
* It connects with the JSON key of a service account of Google, pasted on a Search Console tab with the steps to make one; the account gets read access to the property only. The private key is kept encrypted with the keys of the site in wp-config.php and never shown again. The property of the site is found by itself, or chosen from those the account sees. Google's answers are kept for six hours: Search Console updates once a day.
* Access has a new row, who sees Search Console data: administrators and editors by default; administrators always.

= 0.31.0 =
* An Integrations tab: the codes that confirm the site to Google Search Console, Bing, Yandex, Baidu, Pinterest, Meta and Ahrefs, as meta tags of the home page — paste the code or the whole meta tag. The import takes them from Yoast SEO, Rank Math and All in One SEO.
* The tags of Google Analytics 4 (or another Google tag), Google Tag Manager, Meta Pixel and TikTok Pixel — paste the ID or the whole snippet. They load on the visitor's first move, so pages stay fast, or at once by a switch; with consent asked, they wait for the theme's cookie banner. Editors are not counted unless asked. The tab warns when the theme prints the same tags itself.

= 0.30.0 =
* The description made from the text of a post is as wide as a description in search results on a computer, not 156 characters: Cyrillic letters are wider than Latin ones, and such a description was cut with an ellipsis.
* The content analysis measures the SEO title and the meta description as search results do: too long is wider than they show (the check says how many pixels), too short is still too few characters. The search result preview and the analysis now agree.

= 0.29.0 =
* Alt texts and titles of images from their names, on an Images tab: a new image without an alt text gets one, and its title reads as words instead of the name of its file, each by its own switch. The name is the image's title first — WordPress takes it from the file as it was named on the computer, Cyrillic kept — then the file's name, without the extension, the copies and sizes WordPress makes, dates and counters. Names that say nothing of the picture (IMG_1234, a screenshot, a hash, a stock photo's code, an untitled design) give nothing.
* With wp-loc the copies of a new image for the other languages get the same alt text and title.
* Bulk editing lists the images too, with the alt text and the title of each and the text its name gives; a button fills the empty alt texts with it, to look over before saving.

= 0.28.0 =
* Local SEO, a tab of its own: the type of the organization the site represents — an organization of a kind (a corporation, an NGO, news media, an online store and the like) or a local business (a dentist, a law office, a shop, a restaurant and some fifty more) — with its phone, e-mail, address and the area it serves, and for a local business its coordinates, a link to the map, the opening hours of each day and a price range. The texts are kept per language with wp-loc.
* The organization of the Schema graph takes that type and those details under the same @id, so search engines and maps can show the address, phone and hours. A site that represents a person stays as it was.
* Sites on WP Base and its forks: a button on the tab takes the theme's details of the organization (its Schema.org options, the footer's phone and e-mail, the opening hours) into the empty fields, in every language.
* The import takes Rank Math's Local SEO (the type of business, address, coordinates, phones, price range and opening hours) and the organization's description, phone and e-mail of Yoast SEO and All in One SEO, filling only empty fields.

= 0.27.0 =
* Own JSON-LD of a post or a term, under Schema in its SEO block, for whoever edits the advanced fields: an object, a list of them or a document with @graph. Its nodes go last into the page's graph, past the filters that thin the graph out, and are printed in every mode of the Schema tab, alone where Simple SEO's graph is not. The text stays as typed; the editor shows the types of its nodes, or what is wrong with it. Nothing of it is printed while the post asks for a password.

= 0.26.0 =
* Each post has a type of page (web page, about page, contact page, FAQ page and the rest) and of article (article, blog post, news article and the rest, or none) in its SEO block, under Schema; without one it takes its post type's, set on the Schema tab. Pages are web pages and posts articles unless set otherwise.
* FAQ pages: the questions and answers of a post become a FAQPage in its graph. They come from the FAQ blocks of themes (ACF blocks with faq in their name, as in WP Base), from Builder's accordion and the core Details block on a page of the type FAQ, and from a new block, Questions and answers, for sites whose theme has no FAQ of its own: each question opens to its answer, without a script. The SEO block says how many questions the page has, or that its accordion could be one.
* The import takes the types of pages and articles: of posts and post types from Yoast SEO, of post types from Rank Math and All in One SEO.

= 0.25.0 =
* Schema structured data: one JSON-LD graph in the head of every page, with the site and its search, the organization or the person it represents (its names, logo and profiles elsewhere), the page of its kind (a collection for archives, a profile for authors, search results), its main image, the article with its author for posts, and the breadcrumb trail. The parts refer to each other, each in the language of the page with wp-loc.
* A Schema tab chooses who prints the graph: Auto (the default) prints it unless the theme switches off the graph of SEO plugins, as themes with a graph of their own do, so a page never gets two; Always prints it whatever the theme says; Off prints none. The tab says when the active theme switches it off and shows the graph of the home page.
* Themes made for Yoast SEO change the graph with Yoast's filters of its parts (wpseo_schema_webpage, wpseo_schema_article and the like) and of the whole (wpseo_schema_graph). The Builder theme leaves the breadcrumb trail to Simple SEO and prints its own only where Simple SEO's graph has none.
* The import takes whom the site represents, the names, the logo and the profiles from Yoast SEO, Rank Math and All in One SEO.

= 0.24.0 =
* Bulk editing, under SEO in the menu: a table of the posts of a type or the terms of a taxonomy with the SEO title and the meta description of each, the template as the placeholder and what search engines see now. Changes are saved together without reloading the page, and each row then shows what the page has. A filter lists only those without a title or a description of their own; there are search and pages.
* The page is for whoever edits the SEO fields, not only for those who change the settings: an editor or an author sees it under SEO, an author only with their own posts. Each field is saved only where the user may edit it. With wp-loc it lists the posts and terms of the admin's language.
* Saving a post there takes its scores for the column of the lists again.

= 0.23.0 =
* Readability in the SEO block of posts, under the SEO checks and with a score of its own: how long the sentences are (a quarter of them over 20 words at most), how long the paragraphs are (150 words), how much text goes without a subheading (300 words), and whether three or more sentences in a row start with the same word. The checks hold for any language; those that need one (passive voice, transition words) are left out.
* A column SEO in the lists of posts and pages with the SEO and readability scores of each post as saved. They are taken when the post is saved in the editor, by quick edit or through the REST API, or opened in the editor; a post changed otherwise since (an import, WP-CLI) shows a dash until then.

= 0.22.0 =
* The import takes the keyphrases too, into the focus keyphrase and the more keyphrases of posts that have none: the focus keyphrase of Yoast SEO and the more keyphrases of Yoast SEO Premium, the keyphrases of Rank Math (the first is the focus one, the rest go one on a line) and the focus and additional keyphrases of All in One SEO, from its own columns or, for older posts, from the JSON it kept them in before. A post with nothing but keyphrases is found too. Terms have no keyphrases in Simple SEO, so theirs stay where they are.
* Yoast's _yoast_wpseo_focuskw, as themes read and write it, is now Simple SEO's focus keyphrase, and %%focuskw%% in wpseo_replace_vars() gives it.

= 0.21.0 =
* Content analysis in the SEO block of posts: a focus keyphrase and up to four more, and checks of the page for them — in the SEO title, the meta description, the address, the first paragraph, the subheadings, the text (how often) and the alt text of images, and whether another page has the same focus keyphrase — with the length of the text, links to the site and elsewhere, images and their alt text, and the length of the title and the description. Each check says what to do; together they make a score out of 100.
* The analysis is redone a few seconds after the text, the keyphrases, the title or the description change, in the block editor and in the classic one alike, with the text of ACF and Builder blocks. Words match in their other forms (ключова фраза, ключові фрази), with no dictionary of any language.

= 0.20.0 =
* Groundwork for the content analysis that comes next: Simple SEO reads the text of a page as visitors see it, whatever editor made it. Blocks are rendered the way the site renders them, so ACF blocks and the blocks of the Builder theme give the text their templates print; classic content gets its paragraphs. It finds the headings with their levels, the paragraphs, the links (to the site or elsewhere, nofollow) and the images with their alt text, and counts the words, in the language of the post with wp-loc. Code, lists of other posts, menus, forms, maps and embeds are left out.

= 0.19.0 =
* A search result preview above the SEO fields of posts and terms: the card a search engine shows for the page, on a computer and on a phone, redone while the SEO title and description, the title, the excerpt and the address are typed. It counts the characters of the title and the description, says whether search engines will cut them, and marks a page hidden from search results. It replaces the "Now:" lines under the title and the description.
* With wp-loc the preview takes the templates, the variables and the site name of the post's or term's language, whatever language the admin is in; reading another language's settings in the admin no longer leaves that language switched on.
* A long address under a field ("Now:" under the canonical address) breaks instead of widening the form on a phone.

= 0.18.0 =
* Import from All in One SEO on the Import tab, next to Yoast SEO and Rank Math: the separator, the title and description templates (AIOSEO's #tags as Simple SEO's {variables}), what search engines leave out (kinds not shown in search results, robots of their own, the global robots) and where attachment pages go, the default image and the X account, the home page, the breadcrumbs, the sitemap switch and the feeds its crawl clean-up removed; the SEO fields of posts from its table, and of terms where AIOSEO Pro kept them. The report names what needs a hand: webmaster verification codes, addresses without the category base, the rules it added to robots.txt.
* Removing what AIOSEO left: its tables, other options, the capabilities it gave to roles, its settings, its user meta and its copies of the fields in post meta. A theme that reads AIOSEO's table, settings or post meta keeps that part unticked, and the files are named.

= 0.17.0 =
* Import from Rank Math on the Import tab, next to Yoast SEO: the separator, the title and description templates (Rank Math's %variables% as Simple SEO's {variables}), what search engines leave out (robots of each kind of page, or the global ones) and where attachment pages go, the default image and the X account, the home and 404 pages, the breadcrumbs, the sitemap switch and the text of robots.txt; the SEO fields of posts and terms. As with Yoast, only what Simple SEO does not have is filled. The report also names what needs a hand: redirections, webmaster verification codes, addresses without the category base.
* Removing what Rank Math left: its tables, other options, scheduled tasks and the capabilities it gave to roles, its settings, and its user, term and post meta. Rank Math's meta has no aliases in Simple SEO, so a theme that reads it keeps it unticked, and the files are named.

= 0.16.0 =
* Removing the data of Yoast SEO, on the Import tab once the import is done and Yoast is inactive: its tables, its other options with cached data and scheduled tasks, its settings (with wp-loc's copies for other languages), its user meta and its post meta, each part ticked on its own with its size shown, after a backup is confirmed.
* Yoast's settings stay unticked, and the files are named, when the active theme reads them (an organization's name and logo for its Schema, say). The post meta goes in batches, a few seconds per request, and the page goes on by itself. Yoast's plugin files are not touched.

= 0.15.0 =
* Import from Yoast SEO, on the new Import tab (offered by a notice once Yoast's data is found): the separator, the title and description templates (Yoast's %%variables%% as Simple SEO's {variables}), what search engines leave out and where attachment pages go, the default image and the X account, the home and 404 pages, the breadcrumbs with their labels as Yoast had them, the sitemap switch and the clean-up switches; the SEO fields of posts (title, description, Open Graph, noindex, nofollow, canonical, primary terms) and of terms; with wp-loc, Yoast's settings of the other languages.
* The import only fills what Simple SEO does not have: a tab saved here, a template or a field with a value stays. Yoast's data is not changed, so the import can be run again. It goes in batches, a few seconds per request, and goes on where it stopped. The report names what was taken, what was kept, the variables Simple SEO has no counterpart for and what it has nothing for yet (focus keyphrases, X fields, advanced robots, social templates).

= 0.14.0 =
* Yoast SEO post meta for themes: once Yoast is deactivated, get_post_meta(), update_post_meta(), add_post_meta() and delete_post_meta() with Yoast's keys read and write Simple SEO's own fields — _yoast_wpseo_title, _yoast_wpseo_metadesc, _yoast_wpseo_opengraph-title, _yoast_wpseo_opengraph-description, _yoast_wpseo_opengraph-image and _yoast_wpseo_opengraph-image-id, _yoast_wpseo_meta-robots-noindex, _yoast_wpseo_meta-robots-nofollow, _yoast_wpseo_canonical and _yoast_wpseo_primary_category (or the primary term of another taxonomy). Yoast's %%variables%% become Simple SEO's {variables} and back.
* The rows Yoast left stay as they were for the import and for Yoast itself; the list of all of a post's meta leaves the aliases out, so copying a post (a new wp-loc translation) copies Simple SEO's fields and not Yoast's old values. Keys Simple SEO has no field for (focus keyphrase, X, advanced robots) stay Yoast's rows.

= 0.13.0 =
* Yoast SEO filters for themes: once Yoast is deactivated, the filters themes hook into change Simple SEO's output as they changed Yoast's — wpseo_title (the <title> alone), wpseo_metadesc (also where a page has no description), wpseo_canonical, the Open Graph filters (wpseo_opengraph_type, _title, _desc, _url, _image with its width, height and type, wpseo_og_locale and wpseo_locale), wpseo_twitter_title and wpseo_twitter_image, wpseo_robots and wpseo_robots_array, wpseo_breadcrumb_links (Simple SEO's own breadcrumbs included), the markup filters of yoast_breadcrumb() (wpseo_breadcrumb_single_link, wpseo_breadcrumb_single_link_info, wpseo_breadcrumb_separator, wpseo_breadcrumb_output_class), the sitemap filters (wpseo_sitemap_entry, wpseo_sitemap_url, wpseo_sitemap_post_type_first_links, wpseo_exclude_from_sitemap_by_post_ids and wpseo_exclude_from_sitemap_by_term_ids) and wpseo_replacements.
* Filters about one page apply only to the page being shown. Where no theme hooks them, every page prints exactly what it printed before.
* New filters of Simple SEO's own: simple_seo_document_title (the text of <title> alone), simple_seo_sitemap_entry, simple_seo_sitemap_url and simple_seo_sitemap_exclude (posts or terms left out of the sitemaps and llms.txt).

= 0.12.0 =
* Yoast SEO compatibility for themes: once Yoast is deactivated, wpseo_replace_vars() (with %%sitename%%, %%sep%%, %%title%%, %%category%% and the other common variables), yoast_breadcrumb() (in the markup themes styled for Yoast, PHP and Twig alike), WPSEO_Primary_Term, yoast_get_primary_term_id() and yoast_get_primary_term() are answered with Simple SEO's own data, so a theme that calls them keeps working.
* Nothing of it is defined while Yoast or another SEO plugin is active, nor on the Plugins and Update screens and in WP-CLI plugin commands, where activating Yoast would otherwise collide with it. Filter simple_seo_yoast_compat switches it off.

= 0.11.0 =
* llms.txt at /llms.txt (llmstxt.org): the site name, a summary, details, then sections of links with the description of each page. Only what search engines may show is listed — the same posts and terms as in the XML sitemap. The XML sitemap is named in the Optional section.
* llms.txt tab: the switch, the summary and details (per language with wp-loc), a section of its own, the Optional section or nothing for each post type and taxonomy (pages and posts in, taxonomies out by default), the number of links in a section, and the file as the site serves it.
* With wp-loc each language has its own llms.txt under its prefix (/en/llms.txt), listing its own pages and naming the files of the other languages.
* The file is cached and built again whenever content or settings change.

= 0.10.0 =
* Clean-up tab: switches that remove from the code of every page the WordPress version (also from feeds), the shortlink (also the Link header), the XML-RPC discovery (the RSD link and the X-Pingback header), the REST API links (also the Link headers), the oEmbed discovery links and the emoji script and styles. The features behind them keep working.
* Links to feeds, each kind by its own switch: posts, comments of the whole site, comments of each post, categories, tags, other taxonomies, authors, search results, post type archives. The feeds keep working at their addresses.
* Out of the box only the WordPress version, the shortlink and the XML-RPC discovery are removed. While another SEO plugin is active, nothing is removed.

= 0.9.0 =
* Files tab: robots.txt and .htaccess of the site root, edited in the admin.
* robots.txt: without a file in the site root, the text WordPress serves (the virtual robots.txt), shown together with what Simple SEO and other plugins add to it; the sitemap line is not added twice. With a robots.txt file, the file itself, which can also be deleted to let WordPress serve its text.
* .htaccess: after a change the server opens the home page and the REST API; if either stops answering, the previous file is put back at once. A copy of the previous file stays next to it (.htaccess.simple-seo-backup) for a restore over FTP. Where the server is not Apache or LiteSpeed, the tab says the file changes nothing.
* Every change keeps the previous text: the last ten versions of each file, each one can be looked at and restored. A file changed meanwhile (WordPress rewrites its rules in .htaccess) is not overwritten.
* Files on disk are edited only by those who may edit files in WordPress (administrators; not with DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS); the virtual robots.txt by those who may change the Simple SEO settings.

= 0.8.0 =
* Breadcrumbs for themes: simple_seo_the_breadcrumbs() in PHP, {{ simple_seo_breadcrumbs() }} in Twig (Timber), [simple_seo_breadcrumbs] shortcode. Home, the posts page or the post type archive, the primary category with its parents, parent pages, the page itself and its page number; terms, archives, authors, dates, search results and 404 have their own trails.
* Breadcrumbs tab: separator, home label and text before (per language with wp-loc), whether to end with the page itself and to show the posts page, and the taxonomy each post type's trail goes through.
* Primary category: for a post with several categories (or terms of another hierarchical taxonomy) the SEO box picks the one the breadcrumbs go through; without a pick, the deepest one. {category} names it. With wp-loc a pick copied into a translation is read in the translation's language.

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
