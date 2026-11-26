=== Arcos Static Sites ===
Contributors: arcosmultimediagroup
Tags: static site, bricks, bricksforge, seo, geo, polylang, wpml
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.9.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate clean, rate-limit-free static HTML/CSS/JS/media ZIP exports or push directly to a same-server live directory, with incremental delta builds, Bricks + Bricksforge Pro Forms bridging, SEOPress SEO/GEO optimization, and client-side blog filtering.

== Changelog ==

= 1.9.9 =
* Added **WPML** multilingual support: iterates active WPML languages (`wpml_active_languages` / `wpml_switch_language`) when queuing posts, pages, CPTs, homepages, and taxonomy archives, and populates `lang` in `blog-index.json` via `wpml_post_language_details` for client-side blog filtering.

= 1.9.8 =
* Replaced site-specific example placeholders in the admin dashboard (`https://domain.com` and `GT-XXXXXXX`) with generic placeholders.

= 1.9.7 =
* Refactored `arcos-static-sites.php` into modular files under `includes/` (`trait-admin.php`, `trait-exporter.php`, `trait-forms-bridge.php`, `trait-ftp.php`, `trait-logs.php`, `trait-updater.php`) and updated the GitHub Actions release workflow to package `includes/`.

= 1.9.6 =
* Added automatic export of public WordPress taxonomy term archives (`category`, `post_tag`, and public custom taxonomies with non-empty terms) so archive links no longer 404 on static exports.

= 1.9.5 =
* Redesigned the **Tools → Arcos Static Sites** admin dashboard with a modern header bar and 5 organized tabs (**Export & Delivery**, **SEO, Forms & Security**, **Releases & Archives**, **Export Logs**, and **System & Updates**) while preserving all existing settings and workflows.

= 1.9.4 =
* Added **Push via FTP / FTPS** and **Both (ZIP + FTP / FTPS)** delivery destinations with host, port, username, password, remote directory path, SSL/TLS (`ftp_ssl_connect`), Passive Mode (`PASV`), and a **Test FTP Connection** button.
* Added **PHP FTP Extension** check to the **Server & PHP Readiness Checklist**.

= 1.9.3 =
* Added built-in GitHub Releases self-updater (`luisameglio/arcos-static-sites`) with private-repo Personal Access Token support (`ARCOS_STATIC_SITES_GITHUB_TOKEN` or admin UI), `Check for Updates Now` button, and GitHub Actions release packaging workflow.
* Added a live **Server & PHP Readiness Checklist** (`PHP Version`, `memory_limit`, `max_execution_time`, `max_input_vars`, `ZipArchive`, writable storage, and `zlib.output_compression`) and improved AJAX error reporting to surface exact HTTP status and HTML error snippets instead of raw JSON parse failures.
* Added persistent **Export Logs** (`uploads/arcos-static-sites/logs/`) recording each export's parameters, page logs, errors, and timing, with a table to **View**, **Download**, **Delete**, or **Clear All Logs**.
* Added a **Cancel Export** button while an export is running to abort in-flight requests, clean up temporary build files, and mark the export log as cancelled.
* Fixed client-side blog bridge (`#arcos-static-blog-bridge`) selecting the wrong `.brx-query-trail` when multiple query loops exist on a page (now matches `targetQueryId` from `data-brx-filter` / highest `data-max-pages`), fixed `perPage` fallback when `data-query-vars` is `[]`, and updated active radio filter styling.
* Fixed `rewrite_urls()` missing bare home URLs without a trailing slash (such as Bricks Logo `<a class="brxe-logo" href="https://...">`).

= 1.9.2 =
* Increased `wp_remote_get()` timeout to 45 seconds with `@set_time_limit(120)` per batch, added automatic 1x retry on `cURL error 28` / `5xx` errors, and added a configurable **Pages per Batch** selector (`2`, `4`, `8`, defaulting to `4`) to prevent loopback timeouts on shared/managed hosts.

= 1.9.1 =
* Added custom **Release Label** field when exporting and an **Edit** label button next to each archive in **Generated Static Archives** for easier rollback identification.

= 1.9.0 =
* Added **Push / Restore to Folder** button to each saved `.zip` archive in **Generated Static Archives** for 1-click release rollback into the Target Live Server Directory (`0755`/`0644` permissions enforced).

= 1.8.11 =
* Fixed custom `emailContent` templates using plain-text newlines (`\n`) collapsing onto one line in HTML emails by applying `nl2br()` and wrapping them in the styled email card.

= 1.8.10 =
* Fixed `find_bricks_form_element()` to check Bricks templates embedded inside the current `postId` before falling back to global form ID search (resolving shared form IDs like `wvlmwo` across multiple templates).

= 1.8.9 =
* Fixed form submission `redirect` action (`{site_url}/thank-you/`) rewriting internal WordPress `home_url()` redirects to root-relative paths (`/thank-you/`) so redirects work cleanly on live static, MAMP Pro, and localhost exports.

= 1.8.8 =
* Added optional **Generate Optimized .htaccess (Caching & Gzip)** setting for Apache/LiteSpeed static sites (1-year cache for assets, `no-cache` for HTML/JSON/XML, and `mod_deflate` compression).
* Added a browser `beforeunload` confirmation guard while an export is running.

= 1.8.7 =
* Improved form email template styling (clean header card + table), added fallback field key matching (`id`, `el_id`, `name`), and omitted blank optional fields from email rows.

= 1.8.6 =
* Added ACF Pro Options Pages (`options_*` in `wp_options`) to `compute_global_hash()` so Incremental Export automatically detects changes to global ACF Options fields.

= 1.8.5 =
* Enforced `0755` permissions on pushed directories (`mkdir_0755`) and `0644` on pushed files instead of inheriting host `umask`/`wp_mkdir_p()` `0711`.

= 1.8.4 =
* Added automatic Google Tag Manager (`GTM-...`) `<head>` + `<body>` `<noscript>` injection alongside `G-` / `GT-` / `AW-` `gtag.js` in the Google Tag field.

= 1.8.3 =
* Included export settings (`base_url`, `canonical_domain`, `force_index`, `ga4_id`, `form_bridge`) in `compute_global_hash()` so changing GA4 ID or SEO/output settings triggers HTML refresh in Incremental mode.

= 1.8.2 =
* Fixed Incremental Export missing WordPress Navigation Menu (`nav_menu_item` / `nav_menu_locations`) and Bricks Global Settings/Classes changes by including them in `compute_global_hash()`.

= 1.8.1 =
* Added Polylang language slug (`lang`) to `blog-index.json`.
* Updated client-side blog bridge (`#arcos-static-blog-bridge`) to automatically filter posts by `<html lang="...">` on multilingual static sites.

= 1.8.0 =
* Added full Bricksforge Pro Forms (`.brxe-brf-pro-forms`) support to the static form bridge and REST endpoint (`/wp-json/arcos-static/v1/submit`).
* Added nested `brf-pro-forms-field-*` field discovery, required validation, email sending, dynamic tag resolution, and saving to `bricksforge_submissions` when `create_submission` is enabled.
* Added `?arcos_export_token=` query-parameter fallback alongside `X-Arcos-Export-Token` for Private CMS Mode export bypass.

= 1.7.2 =
* Added a **Static Sites** shortcut (with Private CMS lock status indicator) to the WordPress Admin Bar for `manage_options` users.

= 1.7.1 =
* Saved **Target Base Path / URL** (`base_url`) and **Clean Assets** (`pagespeed_off`) in plugin settings so values persist between exports.

= 1.7.0 =
* Added **Private CMS Mode** to redirect logged-out frontend visitors to `wp-login.php` while automatically exempting REST form submissions and authenticated internal export requests.

= 1.6.1 =
* Added optional **Google Tag / GA4 ID** field (`G-...` / `GT-...`) to inject `gtag.js` cleanly into static exports when Site Kit is not used on the CMS.

= 1.6.0 =
* Added client-side **Static Blog Pagination, Search & Category Filtering** (`blog-index.json` + `#arcos-static-blog-bridge`) compatible with Bricks Query Loops, Query Filters, and Pagination.

= 1.5.0 =
* Added **SEOPress SEO & GEO Optimization**: smart global `noindex` stripping (preserving per-page SEOPress `noindex`), **Production Domain** rewriting for canonicals / Open Graph / JSON-LD, and automatic `sitemap.xml`, `robots.txt`, and `llms.txt` generation.

= 1.4.4 =
* Added automatic export of Custom Post Type archive pages (`has_archive => true` via `get_post_type_archive_link()`).

= 1.4.3 =
* Excluded `bricks_template` and `elementor_library` from **Post Types to Export** and **Selected Pages Only**, while still tracking template changes for incremental builds.

= 1.4.2 =
* Replaced the comma-separated IDs input in **Selected Pages Only** with a searchable multi-select checklist of published pages, posts, and CPTs.

= 1.4.1 =
* Added **Detected Server Folders** dropdown (`#arcos-dir-select`) with automatic scanning of writable parent/sibling directories around `ABSPATH`.

= 1.4.0 =
* Updated plugin author to **Arcos Multimedia Group**.
* Added **Push Directly to Server Folder** and **Both** delivery destinations with directory validation (`Verify Folder`) and `ABSPATH` overwrite protection.

= 1.3.0 =
* Added **Incremental Export (Changes Only)** and **Selected Pages Only** modes backed by a persistent manifest (`arcos_static_sites_manifest`) tracking `post_modified_gmt`, asset `filemtime`, and Bricks template/CSS global hashes.

= 1.2.0 =
* Added native Bricks **Save Submission** (`save-submission`) support via `\Bricks\Integrations\Form\Submission_Database::insert_data()`.

= 1.1.0 =
* Added `/wp-json/arcos-static/v1/submit` REST endpoint with configurable CORS headers, automatic Bricks form config lookup, ACF Pro & Bricks dynamic tag resolution (`bricks_render_dynamic_data()`), `wp_mail()` delivery, and frontend AJAX interception.

= 1.0.0 =
* Initial release by Arcos Multimedia Group: batched page rendering with `?PageSpeed=off`, direct disk asset copying from `ABSPATH`, CSS `url(...)` asset discovery, URL rewriting, and 1-click `ZipArchive` packaging under **Tools → Arcos Static Sites**.
