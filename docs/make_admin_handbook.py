# Generates docs/OpenPantry-Administrator-Handbook.pdf.
#
# Edit the story below and re-run (python make_admin_handbook.py) to publish a
# new edition. Requires reportlab (pip install reportlab) and the Arial/Consolas
# TTFs that ship with Windows. The cover logo is optional - see find_logo().
#
# Design (Arial body, olive kickers, brown headings with a tan rule, colored
# callout boxes, checkbox and numbered-step tables) lives in handbook_common.py,
# as do the two station checklists reprinted in the back of this book — they are
# the same ones volunteers work from, so they are edited there once instead of
# separately in each script.
import os

from handbook_common import *

OUT = os.path.join(HERE, "OpenPantry-Administrator-Handbook.pdf")

# ---------------------------------------------------------------- document
doc = BaseDocTemplate(
    OUT, pagesize=letter,
    leftMargin=0.98 * inch, rightMargin=0.98 * inch,
    topMargin=0.9 * inch, bottomMargin=0.9 * inch,
    title="Administrator Handbook",
    author="Bruce Alexander • Chromagic Development")
frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height,
              leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
doc.addPageTemplates([PageTemplate(id="page", frames=[frame])])

E = []  # story

# ================================================================ cover
cover_center = ParagraphStyle("cc", fontName="Arial", fontSize=10.5,
                              leading=16, textColor=HexColor("#555555"),
                              alignment=TA_CENTER)
E.append(Spacer(1, 1.85 * inch))
E.append(cover_logo())
E.append(Spacer(1, 0.55 * inch))
E.append(Paragraph(
    '<font face="Arial-Bold" size="30" color="#7cb342">Open</font>'
    '<font face="Arial-Bold" size="30" color="#5d4a12">Pantry</font>',
    ParagraphStyle("wm", alignment=TA_CENTER, leading=36)))
E.append(Spacer(1, 6))
E.append(Paragraph('<font size="15" color="#444444">Administrator Handbook</font>',
                   ParagraphStyle("sub", fontName="Arial", alignment=TA_CENTER,
                                  leading=20)))
E.append(Spacer(1, 16))
badge = Table([[Paragraph(
    '<font face="Arial-Bold" size="10" color="#ffffff">FOR ADMINISTRATORS</font>',
    ParagraphStyle("bdg", alignment=TA_CENTER, leading=12))]],
    colWidths=[2.6 * inch])
badge.setStyle(TableStyle([
    ("BACKGROUND",    (0, 0), (-1, -1), BROWN),
    ("TOPPADDING",    (0, 0), (-1, -1), 8),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
]))
badge.hAlign = "CENTER"
E.append(badge)
E.append(Spacer(1, 0.55 * inch))
E.append(Paragraph("Setup, configuration, security, reporting, and day-to-day "
                   "operation of OpenPantry.", cover_center))
E.append(Spacer(1, 0.35 * inch))
E.append(Paragraph("Revised July 2026 &nbsp;•&nbsp; Version 1.1", cover_center))
E.append(Paragraph("© 2026 Chromagic Development • Bruce Alexander "
                   "• MIT License", cover_center))
E.append(PageBreak())

# ================================================================ overview
E.append(kicker("OVERVIEW"))
E += h1("About OpenPantry")
E.append(Paragraph(
    "OpenPantry is a self-contained PHP + SQLite application that helps a food "
    "pantry check items out the door, keep a live inventory, and forecast what "
    "to reorder — with no database server, build step, or external "
    "dependencies to install. It is a hybrid Just-In-Time inventory system "
    "built for smaller pantries with limited storage and high-demand "
    "essentials.", S["lead"]))
E.append(body(
    "Every outbound channel — grocery-style barcode checkout, the Menu "
    "Counter ordering kiosk, home deliveries, community events, and OrderAhead "
    "imports — feeds one inventory and one demand model, so the reorder "
    "report always sees the whole picture. This handbook is your reference for "
    "standing the system up, keeping it secure, and running it day to day."))
E.append(info("WHAT YOU'RE RESPONSIBLE FOR",
    "As administrator you own the four things volunteers never touch: the "
    "<b>OpenAI key &amp; settings</b>, the <b>network / hours access gate</b>, "
    "the <b>admin password &amp; encryption key backup</b>, and the <b>reorder "
    "cron job</b>. Each has its own section below."))

E.append(kicker("GETTING INSTALLED"))
E += h1("Requirements &amp; deployment")
E.append(bullet("Any web host with <b>PHP 8+</b> and the <b>pdo_sqlite</b> "
                "extension (standard on every default install)."))
E.append(bullet("For field encryption, the PHP <b>sodium</b> extension (built "
                "in on PHP 7.2+)."))
E.append(bullet("The application folder (and " + code("menucounter/") + ") must "
                "be <b>writable</b> by the web server so the databases and "
                "encryption key can be created on first hit."))
E.append(Spacer(1, 4))
E.append(step(1, "Copy the whole OpenPantry folder to your host. The nested "
                 "Menu Counter app ships inside it — no separate deploy."))
E.append(step(2, "Browse to the app root. The schema builds itself, the produce "
                 "table seeds, and " + code("openpantry.db") + " plus "
                 + code("menucounter/picklist.db") + " are created automatically."))
E.append(step(3, "Open <b>Settings</b> and complete first-time configuration "
                 "(next section)."))

E.append(kicker("DO THIS BEFORE OPENING"))
E += h1("First-time configuration (Settings)")
E.append(body("Everything below lives on the <b>Settings</b> page:"))
E.append(bullet("<b>Administrator password</b> — change it from the default "
                + code("admin") + " immediately. It is stored one-way hashed and "
                "can't be recovered, only reset. Changing it later requires "
                "entering the <b>current password</b> first, so a walk-up at an "
                "unattended screen (or a stolen login cookie) can't silently "
                "swap it and lock everyone out."))
E.append(bullet("<b>Supervisor password</b> (optional) — a second login for "
                "whoever opens the pantry. It reaches every screen the "
                "administrator password does, except that on <b>Settings</b> it "
                "can only change the <b>Public IPv4 Address</b> — the rest of "
                "the page is read-only. That lets a shift lead re-point the "
                "network gate when the pantry's address changes without being "
                "handed the OpenAI key, the mail settings, or the passwords. "
                "Setting or removing it asks for the administrator password. "
                "Leave it unset and the role doesn't exist — there is no "
                "default supervisor password."))
E.append(bullet("<b>OpenAI API key</b> — powers automatic "
                "brand→generic naming on new barcodes. Use the <b>test</b> "
                "button to confirm it works. Stored encrypted at rest."))
E.append(bullet("<b>Network Access IP</b> — set to your pantry's public "
                "Wi-Fi address so the kiosks only work on-site. Leave blank "
                "during setup to avoid locking yourself out, then set it."))
E.append(bullet("<b>Allowed hours</b> — optional weekly schedule that "
                "closes the kiosks outside service times."))
E.append(bullet("<b>Administrator email</b> — where reorder-reminder "
                "digests are sent — and where login soft-lock codes go "
                "(next section), so keep it current."))
E.append(bullet("<b>Email notifications (SMTP)</b> — optional "
                "authenticated sending; otherwise the app uses PHP "
                + code("mail()") + ". Use <b>Send Test Email</b> to verify "
                "delivery."))
E.append(bullet("<b>Produce tare</b> — ounces subtracted from hand-typed "
                "produce weights (not scale readings)."))
# The USB scale is a one-time setup job that belongs to whoever sets up the
# station, not to a volunteer mid-shift — so it lives here rather than in the
# volunteer handbook, which only covers reading the strip.
E.append(bullet("<b>USB scale pairing</b> — a HID Point-of-Sale scale (the "
                "kind USPS postage software uses, e.g. the DYMO M25) plugs "
                "straight into the station with no adapter or driver. Open the "
                "scanning page in Chrome or Edge, click <b>Connect Scale</b> "
                "once and pick the device; the browser remembers the grant, so "
                "every later visit reconnects on its own. Do this once per "
                "station and per browser profile — there is no app setting for "
                "it. A station still using the older cable-connected scale needs "
                "none of this: it types weights in as a keyboard would, "
                "and the scale strip described in the Volunteer Handbook "
                "never appears."))
E.append(bullet("<b>Par-level defaults</b> — default lead time and "
                "safety-stock Z used by the Order Now report."))
E.append(bullet("<b>Food pantry name &amp; logo</b> — branding shown in "
                "the header and on printed sheets."))
E.append(PageBreak())

# ================================================================ security
E.append(kicker("PROTECT THIS ABOVE ALL"))
E += h1("Security &amp; data privacy")
E.append(bullet("<b>Admin password</b> is stored as a one-way hash ("
                + code("password_hash") + "); even the running app can't read "
                "it back. The login cookie is derived from the hash, so "
                "changing the password instantly logs everyone out."))
E.append(bullet("<b>Supervisor password</b>, when set, is hashed and "
                "cookie-bound exactly like the admin one, and the cookie "
                "records which of the two opened the session — so a supervisor "
                "session stays a supervisor session, and changing or removing "
                "that password signs out everyone holding it."))
E.append(bullet("<b>Login rate limiting</b> throttles failed admin logins per "
                "IP: two free retries, then 10- and 30-second waits; at the "
                "fifth failure the login <b>soft-locks</b> and a single-use "
                "6-digit code is emailed to the administrator address (with an "
                "escalating 1–10 minute timeout as the fallback when no "
                "code can be sent). A successful login clears the slate. Covers "
                "both the OpenPantry login and the Menu Counter item admin."))
E.append(bullet("<b>Network + hours gate</b> (in " + code("auth.php") + ") "
                "blocks any device that isn't on the allowed IP or is outside "
                "allowed hours, with a styled “Access Denied” wall."))
E.append(bullet("<b>Encryption at rest</b> (libsodium) now covers <b>every "
                "Settings value</b> — the OpenAI key, allowed IP, SMTP "
                "credentials, and the rest (only the already-hashed admin "
                "and supervisor passwords stay hashes) — plus delivery clients' "
                "address / city / phone. Existing plaintext rows are upgraded "
                "automatically on the next load."))
E.append(good("KEEP THE DATABASES OFF THE WEB",
    "The root " + code(".htaccess") + " refuses to serve " + code("*.db") + ", "
    + code("*.db-wal") + ", " + code("*.db-shm") + ", and " + code("*.sqlite")
    + " files over HTTP, so the databases can't be downloaded by URL even on a "
    "default install. For defense in depth, point the "
    + code("OPENPANTRY_DB_DIR") + " SetEnv (in " + code(".htaccess") + ", "
    "alongside " + code("OPENPANTRY_KEY_PATH") + ") at a directory <b>outside "
    "public_html</b> — both databases are stored there, and an existing "
    "in-webroot database is checkpointed and moved across automatically on the "
    "next request. The SetEnv values are also honored on CLI runs, so the cron "
    "mailer resolves the same files."))
E.append(info("THREE TIERS OF ACCESS — AND WHY VOLUNTEERS DON'T LOG IN",
    "The <b>volunteer kiosks are gated by the allowed IP only</b>: the scanning "
    "stations, the Menu Counter order form and pick queue, and the delivery "
    "kiosk open straight to the work screen on the pantry network, with <b>no "
    "password</b>. The <b>admin password guards the administrative screens</b> "
    "— the dashboard, Settings, Inventory, Restock, Lookup Tables, "
    "Reports, the Menu Counter item admin, and the delivery client roster. "
    "The optional <b>supervisor password</b> sits between the two: the same "
    "administrative screens, but Settings is read-only apart from the IP "
    "address controls. "
    "Setting the allowed IP correctly is therefore what keeps the kiosks safe, "
    "so keep it current whenever the pantry's public address changes."))
E.append(warn("BACK UP ENCRYPTION_KEY.PHP",
    "The 32-byte key in " + code("encryption_key.php") + " is generated on "
    "first use and is the <b>only</b> thing that can decrypt your data. If you "
    "lose it, all encrypted fields are gone for good. Back it up, keep it out "
    "of version control, and ideally relocate it above the web root via the "
    + code("OPENPANTRY_KEY_PATH") + " environment variable or a "
    + code("FS_ENC_KEY_PATH") + " constant. On PHP without libsodium, "
    "encryption silently degrades to plaintext until a sodium-capable PHP runs."))

E.append(kicker("KEEPING COUNTS HONEST"))
E += h1("Managing inventory")
E.append(bullet("<b>Inventory page</b> — the canonical current-count "
                "list. Create items here and set each one's unit ("
                + code("each") + " or " + code("lb") + ")."))
E.append(bullet("<b>Restock</b> — stage counts and submit a batch that "
                "<i>adds</i> to inventory, flagged purchased vs. donated "
                "(drives the Purchased-% column). No order rows are written, "
                "so restocks stay out of usage reports."))
E.append(bullet("<b>Deliverable flag</b> — uncheck it to keep an item in "
                "inventory but hide it from the Menu Counter order form."))
E.append(bullet("<b>Count-per-case</b> — set how many units a supplier "
                "case holds; the Order Now report then shows a Case Request "
                "column (order need ÷ case size, rounded up)."))
E.append(bullet("<b>Order unit &amp; Avg Wt</b> — for produce the pantry weighs "
                "but the vendor sells by the piece (avocados on the scale in "
                + code("lb") + ", bought as a 48-count case): set Order Unit to "
                + code("each") + " and Avg Wt to the average pounds per piece. "
                "Count-per-case is then read in the order unit, the order sheet "
                "and email are written in it, and Restock Now converts the "
                "delivery back to pounds. Leave Order Unit on “same as stock” "
                "for everything else. Without an Avg Wt there is nothing to "
                "convert by, so the order falls back to the stock unit."))
E.append(bullet("<b>Checkout, deliveries, events, OrderAhead</b> all decrement "
                "inventory automatically as orders close or imports run."))
E.append(PageBreak())

# ================================================================ lookup
E.append(kicker("BARCODES TO GENERIC NAMES"))
E += h1("Lookup tables &amp; naming")
E.append(body("OpenPantry stores each item by a plain <b>generic name</b> "
              "(e.g. “Black Beans”) rather than brand, so demand "
              "aggregates cleanly. The first time a UPC is scanned, the app "
              "looks it up on Open Food Facts and asks OpenAI to reduce it to "
              "a 2–4 word generic, then caches the mapping."))
E.append(bullet("<b>Produce</b> — PLU codes (and 12-digit pantry labels "
                "starting with 4) map to produce names; the table ships "
                "pre-seeded and you can add more."))
E.append(bullet("<b>UPC / Generic Cache</b> — review and edit AI-derived "
                "names in place. If it guessed “Beans” when you "
                "wanted “Black Beans”, fix it here and future scans "
                "use your edit."))
E.append(bullet("<b>Add UPC Manually</b> — for codes Open Food Facts "
                "can't resolve: type the UPC, an optional branded name, and "
                "the generic name, and the mapping is cached (source "
                + code("manual") + ") for all future scans of that code."))

E.append(Paragraph("Merging duplicate item names", S["h3"]))
E.append(body("The generic name <i>is</i> the item — there is no id behind it "
              "— so the same food can end up stored under two spellings. The "
              "AI returns “Green beans” one week and “Green "
              "Beans” the next; a produce code says “Squash "
              "Zucchini” while a UPC says “Zucchini”; "
              "someone retypes a name. Each variant then keeps its own scan "
              "history, its own inventory row, and its own reorder alert, "
              "which splits demand in half and <b>biases both par levels "
              "low</b>. The <b>De-duplicate</b> tool merges them back "
              "together. It is deliberately kept off the menu — browse to "
              + code("/openpantry/deduplicate/") + " when you need it."))
E.append(bullet("<b>Possible Duplicates</b> — names that match once case, "
                "punctuation, word order, and plurals are ignored are grouped "
                "for you. Click <b>keep</b> on the spelling you want and "
                "<b>merge</b> on the one to fold into it."))
E.append(bullet("<b>What a merge moves</b> — every scan (so the demand "
                "history recombines), the UPC mappings and produce codes (so "
                "future scans land on the kept name), the inventory row "
                "(counts and lifetime restock totals are summed; the kept "
                "row's unit, case size, and Order Unit settings win), and any "
                "reorder alert. The duplicate then no longer exists."))
E.append(bullet("<b>Renaming an item outright</b> — type a name in the "
                "<b>or type a new name</b> box instead of picking one, and "
                "the whole item moves to that spelling everywhere."))
E.append(bullet("<b>“No barcode”</b> in the name table means no UPC "
                "or produce code still resolves to that name, so nothing new "
                "can be scanned into it. Usually a leftover from a rename — a "
                "good merge candidate."))
E.append(warn("A MERGE REWRITES HISTORY AND CANNOT BE UNDONE",
    "Merging edits past scans, not just future ones — that is the point, but "
    "there is no undo. Back up " + code("openpantry.db") + " before a big "
    "cleanup session. The reorder report needs no attention afterward: forecast "
    "fits are keyed to each item's scan history, so a merged item refits by "
    "itself on the next report load."))
E.append(good("MERGE BEFORE YOU TRUST A NEW ITEM'S PAR LEVEL",
    "Duplicates are worth a look whenever an item you know is moving shows a "
    "surprisingly low order need — a second spelling is quietly holding the "
    "other half of its scans. The Menu Counter has its own separate "
    "de-duplicate tool for the order-form items in its own database."))

E.append(kicker("TURNING SCANS INTO DECISIONS"))
E += h1("Reports &amp; the demand model")
E.append(bullet("<b>Order Now</b> — the reorder report. Computes a Par "
                "Level and how much to order per item."))
E.append(bullet("<b>Orders Listing</b> — every order and its items over a "
                "date range (delivery/event orders are tagged)."))
E.append(bullet("<b>Item Usage</b> — per-item totals over a date range."))
E.append(bullet("<b>Daily Volume</b> — orders and scans per day."))
E.append(bullet("<b>Basket Size</b> — distribution of items per in-pantry "
                "trip over time."))
# The odd one out, and worth saying so: every other report answers an
# operational question, this one answers a funder's.
E.append(bullet("<b>Impact</b> — the report you hand a board member or a "
                "grant officer: pounds distributed, households and people "
                "reached, top items, and where the food came from, over a "
                "window that defaults to the last 12 months. It is the only "
                "report that reads <b>both</b> databases — scans and orders "
                "from " + code("openpantry.db") + ", counter requests and "
                "household sizes from " + code("picklist.db") + " — and it "
                "keeps the two in separate sections rather than summing them, "
                "because a request a household typed and food that left the "
                "building are different events."))
E.append(info("THE IMPACT REPORT'S TWO ASSUMPTIONS ARE YOURS TO SET",
    "Packaged goods are counted, not weighed, so any pound figure covering "
    "them rests on an assumed <b>average pound per packaged item</b>; the "
    "meals-equivalent figure rests on an assumed <b>pounds per meal</b> "
    "(Feeding America uses 1.2). Both sit at the top of the report as editable "
    "inputs and are restated in its <b>Methodology &amp; Caveats</b> card, so "
    "anyone reading the headline numbers can see what they depend on. Set them "
    "to whatever your funder expects before you print."))
E.append(Paragraph("How Par Level is computed", S["h3"]))
E.append(body("For each item: <b>Par Level = Forecast(LeadTime) + "
              "SafetyStock</b>, where <b>SafetyStock = Z × "
              "√Variance(LeadTime)</b> and <b>Order need = max(0, Par "
              "Level − latest inventory count)</b>. Forecast and variance "
              "come from a quasi-Poisson model fitted to each item's weekly "
              "scan history, with a time trend and annual seasonality; "
              "dispersion inflates the safety stock so it reflects real "
              "volatility. Z defaults to 1.65 (~95%). Items with under ~2 "
              "months of history fall back to a trailing average and are "
              "marked with a °."))
E.append(good("THE FORECAST CACHE IS DISPOSABLE",
    "Fits are memoized in the " + code("forecast_cache") + " table and rebuild "
    "on demand. If a report ever looks stale or wrong, that table is safe to "
    "delete — it will rebuild from the scan history."))
# Bound to its paragraph: adding the Impact report above pushed this subhead
# to the foot of the page, where it sat alone with its text overleaf.
E.append(KeepTogether([
    Paragraph("Reorder alerts &amp; email", S["h3"]),
    body("Set a per-item lead-time alert and it shows as a banner on "
         "Order Now when projected days-of-stock drop below the "
         "threshold. Tick its <b>Email</b> box to also have it emailed. "
         "The cron job (" + code("cron_reorder_alerts.php") + ") mails a "
         "digest of triggered, email-flagged items to the administrator "
         "address, at most once per ~20 hours per item."),
]))
E.append(PageBreak())

# ================================================================ cron
E.append(kicker("AUTOMATED REMINDERS"))
E += h1("The reorder cron job")
E.append(body("Add a cron job (cPanel → Cron Jobs) that runs the mailer "
              "on your cadence, e.g. daily at 7am:"))
E.append(codeblock([
    "0 7 * * *  /usr/local/bin/php",
    "/home/you/public_html/openpantry/cron_reorder_alerts.php",
]))
E.append(warn("CALL PHP BY ITS ABSOLUTE PATH",
    "Use the full path to the PHP binary (e.g. " + code("/usr/local/bin/php")
    + " on Namecheap-style cPanel hosts). A bare " + code(".php") + " path can "
    "fail silently with “Permission denied” and no email ever goes "
    "out. Confirm with <b>Send Test Email</b> in Settings first."))

E.append(kicker("THE ORDERING KIOSK"))
E += h1("Administering the Menu Counter")
E.append(bullet("<b>Item admin</b> — add, remove, reorder (drag &amp; "
                "drop), and toggle items; set category, name, sizes, and the "
                "<b>Family Factor</b> (multiplied by household size, capped at "
                "5, rounded up) that decides how many units land in the pick "
                "queue."))
E.append(bullet("<b>Pick queue (Orders)</b> — the live back-of-house "
                "dashboard volunteers pull from; auto-refreshes every 30 "
                "seconds."))
E.append(bullet("<b>Deduplicate</b> — merge duplicate item rows that "
                "crept in over time. This one is the Menu Counter's own "
                "order-form items; the item-name merge tool under Lookup "
                "Tables is a separate page for a separate database."))
E.append(bullet("<b>Reports</b> — item-usage reports with a chart; "
                "shoppers are anonymized as “Client N.”"))
E.append(bullet("The Menu Counter shares the same login, allowed-IP, and "
                "allowed-hours gate as the rest of OpenPantry. Its order form "
                "shows exactly the items toggled <b>On</b> in Item admin — "
                "the Inventory page's Deliverable checkbox only affects the "
                "delivery menu, not the Menu Counter."))

E.append(kicker("HOME DELIVERIES"))
E += h1("Delivery paperwork &amp; the AI upload")
E.append(bullet("<b>Print Menus (order forms)</b> — one page per pending "
                "client, with a checkbox per in-stock item. Each item shows "
                "its <b>default Qty / Weight in parentheses</b> — e.g. "
                "<b>(2 each)</b> or <b>(1.5 lb)</b> — computed from that "
                "client's household size with the same rules the packing list "
                "uses, so the two sheets always agree. A bold footer on every "
                "page asks volunteers to write changes next to the default "
                "amount only when the client requests them."))
E.append(bullet("<b>Packing &amp; Delivery Lists</b> — per-client pick "
                "sheets for orders recorded this round. Count items show as "
                "<b>2 each</b>; produce delivered by weight shows as "
                "<b>1.5 lb</b>."))
E.append(bullet("<b>AI upload</b> — completed forms are scanned to one "
                "PDF and read by vision AI in two independent passes; "
                "disagreements are flagged “verify against the "
                "paper” in the results table."))
E.append(warn("THE AI READS CHECKBOXES ONLY",
    "The upload reader detects which boxes are marked — it does <b>not</b> "
    "read handwritten Qty / Weight corrections. When a volunteer writes a "
    "changed amount next to an item (per the footer instruction), apply that "
    "change by hand when packing; the packing list will otherwise show the "
    "standard computed amount."))

E.append(kicker("KEEPING IT HEALTHY"))
E += h1("Maintenance &amp; troubleshooting")
E.append(bullet("<b>Backups</b> — back up " + code("openpantry.db") + ", "
                + code("menucounter/picklist.db") + ", and "
                + code("encryption_key.php") + " together. The two databases "
                "hold your data; the key decrypts it. If you've set "
                + code("OPENPANTRY_DB_DIR") + ", both databases live in that "
                "private folder instead of the app folders."))
E.append(bullet("<b>No email arriving?</b> Verify the admin email and SMTP in "
                "Settings, send a test, and check the cron PHP path (above). "
                "Without SMTP the app falls back to PHP " + code("mail()") + "."))
E.append(bullet("<b>New barcode saved with a raw name?</b> The OpenAI step was "
                "skipped (bad/rate-limited key). Fix the key and edit the name "
                "under Lookup Tables."))
E.append(bullet("<b>Same item listed twice in a report?</b> Two spellings of "
                "one generic name. Merge them at "
                + code("/openpantry/deduplicate/") + ", which also recombines "
                "the split scan history the par levels are built from."))
E.append(bullet("<b>“Access Denied” for legitimate staff?</b> The "
                "public Wi-Fi IP changed or you're outside allowed hours. "
                "Update the allowed IP in Settings."))
E.append(bullet("<b>Locked out by the login throttle?</b> Wait out the timer, "
                "or use the 6-digit code emailed to the administrator address. "
                "A successful login resets the counter; 30 quiet minutes decay "
                "it on their own."))
E.append(bullet("<b>Report looks off?</b> Delete the " + code("forecast_cache")
                + " table; it rebuilds from scans."))
E.append(PageBreak())

# ================================================================ checklists
E.append(kicker("POST THESE AT EACH STATION"))
E += h1("Opening checklists (for training)")
E.append(Paragraph("These are the same checklists in the Volunteer Handbook. "
                   "Print and post one at each station, and use them when "
                   "training new volunteers.", S["italic"]))

E += scanning_station_checklist()
E.append(PageBreak())
E += menu_counter_checklist()

doc.build(E)
print("Wrote", OUT)
