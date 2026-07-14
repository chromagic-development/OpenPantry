# Generates docs/OpenPantry-Administrator-Handbook.pdf.
#
# The PDF is built from this script alone — edit the story below and re-run
# (python make_admin_handbook.py) to publish a new edition. Requires
# reportlab (pip install reportlab) and the Arial/Consolas TTFs that ship
# with Windows; footprints-logo.jpg must sit next to this script.
#
# Design: Arial body, olive kickers, brown headings with a tan rule,
# colored callout boxes (blue info / red warning / green good-to-know),
# checkbox and numbered-step tables for the station checklists.
import os

from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_CENTER
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Image,
    Table, TableStyle, HRFlowable, PageBreak, KeepTogether,
)
from reportlab.lib.styles import ParagraphStyle

HERE = os.path.dirname(os.path.abspath(__file__))
OUT  = os.path.join(HERE, "OpenPantry-Administrator-Handbook.pdf")
LOGO = os.path.join(HERE, "footprints-logo.jpg")

# ---------------------------------------------------------------- fonts
FONTS = r"C:\Windows\Fonts"
pdfmetrics.registerFont(TTFont("Arial", os.path.join(FONTS, "arial.ttf")))
pdfmetrics.registerFont(TTFont("Arial-Bold", os.path.join(FONTS, "arialbd.ttf")))
pdfmetrics.registerFont(TTFont("Arial-Italic", os.path.join(FONTS, "ariali.ttf")))
pdfmetrics.registerFont(TTFont("Arial-BoldItalic", os.path.join(FONTS, "arialbi.ttf")))
pdfmetrics.registerFontFamily(
    "Arial", normal="Arial", bold="Arial-Bold",
    italic="Arial-Italic", boldItalic="Arial-BoldItalic")
pdfmetrics.registerFont(TTFont("Consolas", os.path.join(FONTS, "consola.ttf")))

# ---------------------------------------------------------------- palette
OLIVE      = HexColor("#7d9a2d")   # kickers
BROWN      = HexColor("#6b4e16")   # headings / badge / step numbers
BODY       = HexColor("#3d3d3d")
GRAY       = HexColor("#777777")
RULE_TAN   = HexColor("#d8cda6")
GREEN_WORD = HexColor("#7cb342")
INFO_BG    = HexColor("#ddebfa"); INFO_BAR = HexColor("#2f6db5")
WARN_BG    = HexColor("#f7ded8"); WARN_BAR = HexColor("#c0503e")
GOOD_BG    = HexColor("#e6edd6"); GOOD_BAR = HexColor("#6b8e23")
CODE_BG    = HexColor("#2b2b2b")
CODE_FG    = HexColor("#b8cc7a")
MONO_IN    = HexColor("#8a7040")   # inline code color

# ---------------------------------------------------------------- styles
def ps(name, **kw):
    base = dict(fontName="Arial", fontSize=10.5, leading=15, textColor=BODY)
    base.update(kw)
    return ParagraphStyle(name, **base)

S = {
    "kicker":  ps("kicker", fontName="Arial-Bold", fontSize=9, leading=12,
                  textColor=OLIVE, spaceBefore=14, spaceAfter=2),
    "h1":      ps("h1", fontName="Arial-Bold", fontSize=15.5, leading=19,
                  textColor=BROWN, spaceBefore=2, spaceAfter=4),
    "h3":      ps("h3", fontName="Arial-Bold", fontSize=10.5, leading=14,
                  textColor=HexColor("#666666"), spaceBefore=10, spaceAfter=3),
    "lead":    ps("lead", fontSize=12.5, leading=18.5, spaceBefore=6, spaceAfter=6),
    "body":    ps("body", spaceBefore=4, spaceAfter=4),
    "italic":  ps("italic", fontName="Arial-Italic", spaceBefore=4, spaceAfter=4),
    "bullet":  ps("bullet", spaceBefore=2.5, spaceAfter=2.5, leftIndent=16,
                  bulletIndent=4),
    "step":    ps("step", spaceBefore=2.5, spaceAfter=2.5, leftIndent=16,
                  bulletIndent=0),
    "boxk":    ps("boxk", fontName="Arial-Bold", fontSize=8.8, leading=12),
    "boxb":    ps("boxb", fontSize=9.6, leading=13.5),
    "check":   ps("check", fontSize=10.2, leading=13.8),
    "steptxt": ps("steptxt", fontSize=10.2, leading=13.8),
    "stepnote":ps("stepnote", fontSize=9.2, leading=12, textColor=GRAY),
}

def code(txt):
    return '<font face="Consolas" color="#8a7040">%s</font>' % txt

def kicker(t):   return Paragraph(t, S["kicker"])
def h1(t):
    return [Paragraph(t, S["h1"]),
            HRFlowable(width="100%", thickness=0.8, color=RULE_TAN,
                       spaceBefore=1, spaceAfter=7)]
def body(t):     return Paragraph(t, S["body"])
def bullet(t):
    return Paragraph('<bullet><font color="#7d9a2d">•</font></bullet>' + t,
                     S["bullet"])
def step(n, t):
    return Paragraph('<bullet><font face="Arial-Bold" color="#6b4e16">%d</font>'
                     '</bullet>' % n + t, S["step"])

def callout(kick, text, bg, bar, kcolor):
    kstyle = ParagraphStyle("bk", parent=S["boxk"], textColor=kcolor)
    inner = [Paragraph(kick, kstyle), Spacer(1, 2), Paragraph(text, S["boxb"])]
    t = Table([[inner]], colWidths=[6.55 * inch])
    t.setStyle(TableStyle([
        ("BACKGROUND",   (0, 0), (-1, -1), bg),
        ("LINEBEFORE",   (0, 0), (0, -1), 3, bar),
        ("LEFTPADDING",  (0, 0), (-1, -1), 12),
        ("RIGHTPADDING", (0, 0), (-1, -1), 12),
        ("TOPPADDING",   (0, 0), (-1, -1), 9),
        ("BOTTOMPADDING",(0, 0), (-1, -1), 9),
    ]))
    return KeepTogether([Spacer(1, 6), t, Spacer(1, 6)])

def info(k, t):  return callout(k, t, INFO_BG, INFO_BAR, INFO_BAR)
def warn(k, t):  return callout(k, t, WARN_BG, WARN_BAR, HexColor("#a53c2c"))
def good(k, t):  return callout(k, t, GOOD_BG, GOOD_BAR, HexColor("#5a7d1e"))

def codeblock(lines):
    style = ParagraphStyle("code", fontName="Consolas", fontSize=9.5,
                           leading=13, textColor=CODE_FG)
    t = Table([[[Paragraph(l, style) for l in lines]]], colWidths=[6.55 * inch])
    t.setStyle(TableStyle([
        ("BACKGROUND",   (0, 0), (-1, -1), CODE_BG),
        ("LEFTPADDING",  (0, 0), (-1, -1), 14),
        ("RIGHTPADDING", (0, 0), (-1, -1), 14),
        ("TOPPADDING",   (0, 0), (-1, -1), 12),
        ("BOTTOMPADDING",(0, 0), (-1, -1), 12),
    ]))
    return KeepTogether([Spacer(1, 4), t, Spacer(1, 4)])

def checkrow(text):
    t = Table([["", Paragraph(text, S["check"])]],
              colWidths=[0.34 * inch, 6.21 * inch])
    t.setStyle(TableStyle([
        ("BOX",          (0, 0), (0, 0), 1, HexColor("#444444")),
        ("VALIGN",       (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING",  (1, 0), (1, 0), 10),
        ("RIGHTPADDING", (1, 0), (1, 0), 0),
        ("TOPPADDING",   (0, 0), (-1, -1), 2),
        ("BOTTOMPADDING",(0, 0), (-1, -1), 2),
    ]))
    return KeepTogether([t, Spacer(1, 7)])

def steptable(rows):
    numstyle = ParagraphStyle("num", fontName="Arial-Bold", fontSize=10.5,
                              leading=13, textColor=white, alignment=TA_CENTER)
    data = [[Paragraph(str(i + 1), numstyle),
             Paragraph(txt, S["steptxt"]),
             Paragraph(note, S["stepnote"])]
            for i, (txt, note) in enumerate(rows)]
    t = Table(data, colWidths=[0.34 * inch, 3.6 * inch, 2.61 * inch])
    style = [
        ("BACKGROUND",   (0, 0), (0, -1), BROWN),
        ("VALIGN",       (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING",  (1, 0), (-1, -1), 12),
        ("TOPPADDING",   (0, 0), (-1, -1), 9),
        ("BOTTOMPADDING",(0, 0), (-1, -1), 9),
    ]
    for r in range(len(rows) - 1):
        style.append(("LINEBELOW", (1, r), (-1, r), 0.6, RULE_TAN))
    t.setStyle(TableStyle(style))
    return t

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
E.append(Image(LOGO, width=1.55 * inch, height=1.35 * inch))
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
                "password stays a hash) — plus delivery clients' "
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
E.append(info("TWO TIERS OF ACCESS — AND WHY VOLUNTEERS DON'T LOG IN",
    "The <b>volunteer kiosks are gated by the allowed IP only</b>: the scanning "
    "stations, the Menu Counter order form and pick queue, and the delivery "
    "kiosk open straight to the work screen on the pantry network, with <b>no "
    "password</b>. The <b>admin password guards the administrative screens</b> "
    "— the dashboard, Settings, Inventory, Restock, Lookup Tables, "
    "Reports, the Menu Counter item admin, and the delivery client roster. "
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
E.append(Paragraph("Reorder alerts &amp; email", S["h3"]))
E.append(body("Set a per-item lead-time alert and it shows as a banner on "
              "Order Now when projected days-of-stock drop below the "
              "threshold. Tick its <b>Email</b> box to also have it emailed. "
              "The cron job (" + code("cron_reorder_alerts.php") + ") mails a "
              "digest of triggered, email-flagged items to the administrator "
              "address, at most once per ~20 hours per item."))
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
                "crept in over time."))
E.append(bullet("<b>Reports</b> — item-usage reports with a chart; "
                "shoppers are anonymized as “Client N.”"))
E.append(bullet("The Menu Counter shares the same login, allowed-IP, and "
                "allowed-hours gate as the rest of OpenPantry, and it hides "
                "any item you've marked non-deliverable on the Inventory "
                "page."))

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

E.append(kicker("CHECKOUT · LASER SCANNER"))
E += h1("Open-the-Station Checklist — Scanning Station")
E.append(Paragraph("Work top to bottom. Tick each box as you go.", S["italic"]))
E.append(Spacer(1, 6))
E.append(checkrow("Power on the Chromebook tablet and connect it to the pantry "
                  "Wi-Fi (the scanner only works on the pantry network) if it "
                  "is not already connected."))
E.append(checkrow("Make sure the handheld barcode scanner is connected to the "
                  "tablet; wait for its ready beep / steady LED."))
E.append(checkrow("Power on the produce scale and complete the “PC” "
                  "setup below if it is not already set."))
E.append(checkrow("Open the browser and go to the OpenPantry <b>Scan "
                  "(Laser)</b> page. It opens straight to the scanner — "
                  "<b>no password</b> is needed, because the pantry network "
                  "authorizes the station."))
E.append(checkrow("Scan any item's barcode as a test — a “Last "
                  "scan” line should appear. Then tap the red <b>× "
                  "Cancel Order</b> so the test doesn't count."))
E.append(checkrow("Tap once inside the barcode box so the cursor sits there "
                  "(it glows green when focused)."))
E.append(Paragraph("Configuring the produce scale (one-time, each power-on)",
                   S["h3"]))
E.append(body("The VEVOR scale with RS232 Port connects to the tablet as a USB "
              "keyboard. After you power it on it must be switched into "
              "<b>“PC” mode</b> so it types weights straight into "
              "OpenPantry. Do this every time the scale is powered up:"))
E.append(Spacer(1, 4))
E.append(steptable([
    ("Power on the scale and let it settle to <b>0</b>.", "Zero / stable reading"),
    ("Press the <b>[ . ]</b> (period / decimal) key.", "Enters setup"),
    ("Wait until the display reads <b>“Entr”</b>.", "Ready for unit"),
    ("Press <b>[ 2 ]</b> to set the unit to <b>“lb”</b>.", "Unit = lb"),
    ("Press <b>[ . ]</b> (period), then press <b>[ 9 ]</b>.", "Selects PC interface"),
    ("Confirm the weight window shows <b>“PC”</b>.", "Interfaced to OpenPantry"),
]))
E.append(Spacer(1, 10))
E.append(good("WHEN YOU SEE “PC”, YOU'RE SET",
    "That reading means the scale is now talking to OpenPantry. Place a "
    "produce item on the scale during a scan and its weight fills the weight "
    "box automatically — no typing. If the window ever drops back to a "
    "plain weight, repeat the steps above."))
E.append(PageBreak())

E.append(kicker("CUSTOMER ORDERING KIOSK"))
E += h1("Open-the-Station Checklist — Menu Counter")
E.append(body("The Menu Counter has two screens: the <b>customer order "
              "form</b> that shoppers tap, and the <b>pick queue</b> that "
              "volunteers pull from in the back."))
E.append(Paragraph("Customer-facing tablet", S["h3"]))
E.append(Spacer(1, 4))
E.append(checkrow("Power on the customer tablet and connect it to the pantry "
                  "Wi-Fi."))
E.append(checkrow("Open the browser to the OpenPantry <b>Menu Counter</b> "
                  "order form. It opens directly — <b>no password</b> to "
                  "enter."))
E.append(checkrow("Confirm the page loads the item buttons grouped by category "
                  "(Dairy, Dry Goods, Frozen, …)."))
E.append(checkrow("Set the language selector back to English so the next "
                  "shopper starts fresh."))
E.append(checkrow("Stand the tablet in its holder facing the shopper; clean "
                  "the screen."))
E.append(Paragraph("Pick-queue tablet / screen (back of house)", S["h3"]))
E.append(Spacer(1, 4))
E.append(checkrow("Open the <b>Menu Counter → Orders</b> (pick queue) "
                  "page on the volunteer screen. It opens directly — "
                  "<b>no password</b> needed."))
E.append(checkrow("Confirm the queue auto-refreshes and the topbar shows "
                  "“pending orders” and “items "
                  "remaining”."))
E.append(checkrow("Submit one test order from the customer tablet and confirm "
                  "it appears in the queue, then Mark Complete to clear it."))
E.append(checkrow("Make sure bags/bins and a pen are staged at the pick "
                  "bench."))
E.append(good("NO LOGIN ON THE PANTRY NETWORK",
    "The Menu Counter order form and pick queue are secured by the pantry's "
    "network address, not a password, so volunteers never log in to use them. "
    "A password is only asked for on the administrator's <b>item-setup</b> "
    "screen — leave that to the person opening the pantry."))

doc.build(E)
print("Wrote", OUT)
