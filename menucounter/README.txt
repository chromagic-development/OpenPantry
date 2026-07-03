================================================================================
 FOOTPRINTS FOOD PANTRY (PantryPrep) — DIGITAL PICKLIST SYSTEM
 README & SETUP GUIDE
================================================================================

Created by Bruce Alexander


--------------------------------------------------------------------------------
 OVERVIEW
--------------------------------------------------------------------------------

This is a web-based food pantry order management system. Customers submit
orders through an online form, staff pick items from a live queue dashboard,
and administrators configure available items and view usage reports.

The application is self-contained and requires no external database server —
it uses SQLite, which stores all data in a single local file (picklist.db).


--------------------------------------------------------------------------------
 FILE STRUCTURE
--------------------------------------------------------------------------------

  index.php         Customer-facing order form
  orders.php        Employee pick queue dashboard
  admin.php         Administrator configuration panel (password protected)
  report.php        Item usage reports with chart (requires admin login)
  submit_order.php  Handles order form POST submission (no UI)
  api.php           JSON API for AJAX calls from orders.php and admin.php
  db.php            Database initialization and shared helpers
  picklist.db       SQLite database (auto-created on first run)
  logo.jpg          Organization logo (lives at the foodscan/ root; replaceable
                    via Settings → Food Pantry Information)
  favicon.ico       Browser tab icon (optional)

  Recommended folder structure on your server:

    /footprints/            <- main folder (accessible via web)
      index.php
      submit_order.php
      api.php               <- NOTE: orders.php calls ../api.php
      db.php
      logo.jpg
      favicon.ico
      picklist.db           <- auto-created, must be writable

    /footprints/orders/     <- employee dashboard folder
      orders.php

    /footprints/admin/      <- admin folder
      admin.php
      report.php


--------------------------------------------------------------------------------
 SERVER REQUIREMENTS
--------------------------------------------------------------------------------

  - PHP 7.2 or higher
  - PDO extension enabled (standard on most hosts)
  - pdo_sqlite extension enabled
  - The application folder must be writable by PHP (for picklist.db creation)


--------------------------------------------------------------------------------
 INSTALLATION
--------------------------------------------------------------------------------

  1. Upload all PHP files and logo.jpg to your web server in the
     folder structure shown above.

  2. Ensure the folder containing db.php is writable by the web server so
     SQLite can create and write to picklist.db.

  3. Open index.php in a browser. The database and all tables are created
     automatically on first load.

  4. Navigate to admin/admin.php to configure items and settings.
     Default password: admin
     Change this immediately after first login.


--------------------------------------------------------------------------------
 PAGES & FEATURES
--------------------------------------------------------------------------------

  INDEX.PHP — Customer Order Form
  --------------------------------
  - Customers select food items using toggle buttons
  - Items are grouped by category in a two-column grid
  - Adults and Children fields (1–10 each) determine quantity via Family Factor
  - Items marked Unavailable in admin are shown but cannot be selected
  - Items with sizes show a dropdown of available size options when selected
  - Supports Google Translate widget for multilingual access
  - Access can be restricted by IP address (set in admin System Configuration)
  - On submission, the page returns to English and the confirmation banner
    fades after 5 seconds

  ORDERS.PHP — Employee Pick Queue
  ----------------------------------
  - Shows all pending orders in a sidebar queue with progress bars
  - Click an order to view its full picklist grouped by category
  - Click any item to toggle it as picked (green checkmark)
  - ＋ button duplicates an item in the order
  - ✕ button removes an item from the order
  - Mark Complete button activates once all items are picked
  - Queue auto-refreshes every 30 seconds
  - Live stats in the topbar show pending orders and items remaining

  ADMIN.PHP — Item Configuration (Password Protected)
  -----------------------------------------------------
  - Add, remove, reorder (drag-and-drop), and toggle items on/off
  - Columns per item: Active, Category, Item Name, Has Size?, Size Label,
    Sizes (comma-separated options), Family Factor, Unavailable?, Remove
  - Category and Item Name changes require password re-entry for approval
  - New items can be added without password re-approval
  - Family Factor: multiplied by family size (capped at 5) then rounded up
    to determine how many units of that item appear in the pick queue.
    Example: Factor 0.5 → family of 3 gets ceil(3 × 0.5) = 2 units
  - System Configuration section: set allowed IP address and admin password
  - Admin login persists for 2 months via cookie; logout clears it
  - Reports link navigates to report.php

  REPORT.PHP — Usage Reports (Requires Admin Login)
  ---------------------------------------------------
  - Filter by date range, customer name, category, and item name
  - Customer names are anonymized as Client 1, Client 2, etc.
  - Results shown as a table with inline quantity bars and a bar chart
  - Chart automatically switches to horizontal layout for more than 8 items
  - Chart Y-axis shows whole numbers only
  - Print button produces a clean printed report
  - Item names in reports reflect current names from admin (renames propagate
    forward via config_item_id linkage)


--------------------------------------------------------------------------------
 DATABASE TABLES
--------------------------------------------------------------------------------

  orders            One row per submitted order
    id, name, adults, children, week_date, notes, created_at, status

  order_items       One row per item unit in an order
    id, order_id, category, item_name, item_detail, completed, config_item_id

  config_items      Items configured in admin
    id, category, item_name, has_detail, detail_label, active, sort_order,
    unavailable, size_options, family_factor

  settings          Key-value system settings
    key, value
    Keys: admin_password, allowed_ip


--------------------------------------------------------------------------------
 SECURITY NOTES
--------------------------------------------------------------------------------

  - The admin password is stored in plain text in the settings table.
    Change the default "admin" password immediately after installation.

  - The auth cookie token is a SHA-256 hash of the password, not the password
    itself. Changing the admin password instantly invalidates existing sessions.

  - IP restriction in System Configuration blocks index.php access to devices
    not matching the configured IP. Leave blank to allow all IPs.

  - report.php and admin.php are not accessible without the admin cookie.

  - orders.php uses the same IP restriction as index.php.


--------------------------------------------------------------------------------
 DEFAULT ITEMS (seeded on first run)
--------------------------------------------------------------------------------

  DAIRY:        Salted Butter, Unsalted Butter, Eggs
  DRY GOODS:    Canned Tuna, Canned Chicken, Almond Milk,
                Kid's Snacks (16 and Under)
  FROZEN ITEMS: Ground Beef, Fish Nuggets, Whole Turkey
  SPECIALS:     Coffee, Tea
  OTHER ITEMS:  Diapers (Child) [size], Diapers (Adult) Male/Female [size]


--------------------------------------------------------------------------------
 TRANSLATION
--------------------------------------------------------------------------------

  The order form (index.php) includes a Google Translate widget supporting:
  English, Spanish, Portuguese, Arabic, Cantonese, French, Haitian Creole,
  Somali, Vietnamese, Khmer, and Russian.

  The selected language persists across page reloads using localStorage.
  Language labels are shown in their native scripts (e.g. Español, العربية).


--------------------------------------------------------------------------------
 SUPPORT
--------------------------------------------------------------------------------

  For issues or questions, contact:
  Bruce Alexander

================================================================================
