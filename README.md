# StreamFlix

A PHP/MySQL movie streaming web app with full admin panel, subscription management, and advanced database features.

## Features

- **User Auth** — Register, login, logout with bcrypt passwords
- **Movie Browsing** — Home feed, genre/category filters, search, movie detail pages
- **Streaming** — Watch videos with real-time duration tracking via JS + sendBeacon
- **Ratings** — Star rating system with live average updates (3-second polling)
- **Watchlist** — Add/remove movies per user
- **Watch History** — Deduplicated per movie, most recently watched first
- **Subscriptions** — Monthly and 6-month plans with cancel/switch modals
- **Admin Panel** — Full CRUD for Movies, Users, Subscriptions, Genres, Categories, Viewing History
- **Real-time Catalogue** — Home page detects movie additions/deletions within 3 seconds (lightweight polling)

## Advanced Database Objectives

| Objective | Implementation |
|---|---|
| **Triggers** | `trg_after_rating_insert/update` auto-recalculates `Movie.Rating`; `trg_after_subscription_insert` activates user; `trg_before_user_delete` logs before CASCADE wipe |
| **Stored Procedures** | `ProcessSubscription`, `RecordWatchSession`, `ExpireSubscriptions`, `SafeSubscriptionCheck` (SERIALIZABLE isolation) |
| **Transaction Logging** | `AuditLog` table written by every INSERT/UPDATE/DELETE via `auditLog()` helper — visible in admin dashboard |
| **Locking** | `SELECT FOR UPDATE` on User/Movie rows; `LOCK IN SHARE MODE` on Movie during watch session writes |
| **Concurrency Control** | `beginTransaction / commitTransaction / rollbackTransaction` wrappers around every critical operation with full rollback on exception |

## Stack

- **Backend:** PHP 8.x
- **Database:** MySQL (InnoDB) via XAMPP
- **Frontend:** Vanilla JS + CSS (no frameworks)

## Setup

### Requirements
- XAMPP (Apache + MySQL + PHP 8.x)

### Installation

1. Clone this repo into your XAMPP htdocs folder:
   ```
   git clone <repo-url> C:/xampp/htdocs/streamflix
   ```

2. Start Apache and MySQL in XAMPP Control Panel.

3. Import the database:
   - Open **phpMyAdmin** → Import → select `streamflix.sql`
   - Or run: `mysql -u root streamflix < streamflix.sql`

4. Import advanced features (triggers, procedures, events):
   - Import `streamflix_advanced.sql` the same way

5. Create upload folders if they don't exist:
   ```
   uploads/thumbnails/
   uploads/videos/
   ```
   *(Already present via `.gitkeep` — just needs write permissions)*

6. Visit `http://localhost/streamflix/`

### Admin Access

Admin accounts are defined in `php/db.php`:
```php
define('ADMIN_EMAILS', ['admin@streamflix.com', 'admin2@streamflix.com']);
```
Register with one of these emails to get admin access.

### Default Upload Limits

XAMPP's default PHP limits are too small for video uploads. Edit `C:/xampp/php/php.ini`:
```
upload_max_filesize = 1500M
post_max_size       = 1500M
max_execution_time  = 300
max_input_time      = 300
```
Then restart Apache.

## Project Structure

```
streamflix/
├── php/
│   ├── db.php          # DB connection, transaction helpers, stored procedure equivalents
│   └── navbar.php      # Shared navigation bar
├── api/
│   ├── movies.php      # REST API: GET/POST/DELETE movies
│   ├── genres.php      # REST API: GET genres
│   └── events.php      # Lightweight catalogue change polling endpoint
├── admin/
│   ├── dashboard.php   # Stats, audit log, expire subscriptions
│   ├── movies.php      # Add/delete movies with file uploads
│   ├── users.php       # Add/delete users
│   ├── subscriptions.php
│   ├── viewing_history.php
│   ├── genres.php
│   ├── categories.php
│   ├── sidebar.php
│   └── auth_guard.php
├── css/
│   └── style.css
├── uploads/
│   ├── thumbnails/     # Movie poster images (not committed)
│   ├── videos/         # Movie video files (not committed)
│   └── logo.png
├── home.php            # Main dashboard with Netflix-style rows
├── movie.php           # Movie detail / watchlist / subscribe
├── watch.php           # Video player + rating
├── movies.php          # Full movie grid with search
├── watchlist.php       # User's saved movies
├── history.php         # User's watch history
├── subscription.php    # Subscribe / cancel / switch plans
├── category.php        # Browse by category
├── genre.php           # Browse by genre
├── register.php
├── login.php
├── logout.php
├── index.php           # Landing page
├── streamflix.sql      # Base database schema
└── streamflix_advanced.sql  # Triggers, stored procedures, events
```
(sample)
Homepage:
<img width="1914" height="908" alt="image" src="https://github.com/user-attachments/assets/a1348fc4-47a4-4835-b36b-f9e57158be96" />
