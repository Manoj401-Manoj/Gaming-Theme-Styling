# GameForge – Gaming Website Theme

**GameForge** is a complete, ready-to-run gaming website platform built with PHP, MySQL, and vanilla JavaScript. It provides a set of professionally designed templates (CyberNeon, BladeArena, MythQuest, PixelVault) that you can preview and customize instantly. The platform includes user authentication, a games catalog, news/blog system, gaming setups showcase, tournaments management, comments, and an admin panel—all in a single PHP file.

![GameForge Screenshot](https://via.placeholder.com/800x400?text=GameForge+Preview)

---

## Features

- **4 Unique Gaming Templates** – CyberNeon (cyberpunk), BladeArena (esports), MythQuest (fantasy), PixelVault (retro). Each template has its own color scheme, fonts, and vibe.
- **Fully Functional Demo Data** – Pre-populated with games, news articles, gaming setups, and tournaments for immediate preview.
- **User Authentication** – Register, login, profile editing, avatar support, and password change.
- **Game Catalog** – Add, edit, delete games with genre, platform, rating, description, and featured flag. Search and filter by genre.
- **News/Blog System** – Articles with categories, author attribution, view counts, and a commenting system.
- **Gaming Setups** – Community showcase for PC builds. Users can submit their own setups (CPU, GPU, RAM, etc.) and like others' setups.
- **Tournaments** – Manage tournaments with prize pool, team slots, registration tracking, and status (upcoming/ongoing/completed).
- **Admin Panel** – Admin users can manage games and setups directly from the template interface.
- **User Settings** – Notification preferences, dark mode toggle, privacy controls, and language selection.
- **Fully Responsive** – Works on desktop, tablet, and mobile.
- **No External Dependencies** – Everything is self-contained in a single `index.php` file (plus the database schema).

---

## Requirements

- PHP 7.4 or higher (with `mysqli` extension enabled)
- MySQL 5.7 or higher (or MariaDB)
- A web server (Apache, Nginx, or PHP built-in server)

---

## Installation

### 1. Clone or Download the Project

Place the `index.php` file and `database.sql` in your web server's document root (or a subdirectory).

### 2. Create the Database

Import the provided SQL schema:

```bash
mysql -u root -p < database.sql
