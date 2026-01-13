# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **ぷれぐら！PLAYGROUND** - a Japanese e-commerce and content platform for digital manga/comics and physical goods. The site is built with vanilla PHP and hosted on Xserver (Japanese hosting provider).

## Architecture

### Directory Structure
- `/` - Public-facing portfolio site (index.php, article.php, creator.php, manga-viewer.php)
- `/store/` - E-commerce storefront (products, cart, checkout, member accounts)
- `/admin/` - Admin dashboard (requires authentication)
- `/includes/` - Shared PHP modules (db.php, auth.php, member-auth.php, csrf.php, etc.)
- `/sql/` - Database schema files

### Authentication Systems
Two separate auth systems exist:
1. **Admin auth** (`includes/auth.php`): For `/admin/` pages. Features account lockout (10 failed attempts), TOTP 2FA support, 8-hour session timeout.
2. **Member auth** (`includes/member-auth.php`): For `/store/` customer accounts. Supports OAuth (Google, LINE), session tokens in cookies.

### Database
- MySQL with PDO (`includes/db.php`)
- Connection: `getDB()` returns singleton PDO instance
- Key tables: `members`, `products`, `orders`, `order_items`, `works`, `work_pages`, `creators`, `collections`
- See `sql/ec_tables.sql` for EC schema, `sql/security_tables.sql` for security tables

### Payment Integration
- Stripe for payments (`includes/stripe-config.php`)
- Webhook endpoint: `/store/webhook.php`
- Currency: JPY
- Test mode toggle via `STRIPE_LIVE_MODE` constant

### Key Features
- **Manga Viewer** (`manga-viewer.php`): RTL reading, touch gestures, preview limits for unpurchased content
- **Digital Bookshelf** (`store/bookshelf.php`): Purchased digital content access
- **Works/Pages**: Works have multiple pages stored in `work_pages` table, linked via `work_id`
- **Collections**: Group related works together

## URL Rewriting

`.htaccess` provides clean URLs:
- `/article/slug-name` → `article.php?slug=slug-name`
- `/creator/slug-name` → `creator.php?slug=slug-name`
- `/manga/123` → `manga-viewer.php?id=123`

## Security

- CSRF protection via `includes/csrf.php` - use `csrfField()` in forms and `requireCsrfToken()` on POST handlers
- Admin pages require `requireAuth()` call
- Member pages require `requireMemberAuth()` call
- Input sanitization in `includes/sanitize.php`

## Development Notes

- Frontend uses Tailwind CSS via CDN
- Font: Zen Maru Gothic
- Icons: Font Awesome 6
- No build process - vanilla PHP files served directly
- Site settings stored in `site_settings` database table, accessed via `getSiteSettings()`

## Language

Code comments and UI text are in Japanese. The site targets Japanese users.
