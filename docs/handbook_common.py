# Shared design system and shared content for the OpenPantry handbooks.
#
# Both make_admin_handbook.py and make_volunteer_handbook.py import from here.
# The two PDFs deliberately share their station checklists — the Administrator
# Handbook reprints them ("post these at each station") — so the checklists live
# here once rather than in both scripts, where they had already been copied and
# could drift the next time the scan page changed.
#
# Requires reportlab (pip install reportlab) and the Arial/Consolas TTFs that
# ship with Windows; footprints-logo.jpg must sit next to this file.
#
# Design: Arial body, olive kickers, brown headings with a tan rule, colored
# callout boxes (blue info / red warning / green good-to-know), checkbox and
# numbered-step tables for the station checklists.
import os
import re
import sys

from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_CENTER
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, PageTemplate, Frame, Paragraph as _Paragraph, Spacer, Image,
    Table, TableStyle, HRFlowable, PageBreak, KeepTogether,
)
from reportlab.lib.styles import ParagraphStyle

HERE = os.path.dirname(os.path.abspath(__file__))
# Cover logo. The repo ships Footprints' own as the sample asset, so a hard
# reference to it puts one pantry's branding on every other pantry's handbook.
# Search generic locations first, ending at the app's live header logo — the one
# an admin has already replaced through Settings -> Appearance — and render the
# cover without an image rather than failing if there is none. The OpenPantry
# wordmark below it carries the identity either way.
#
# Override explicitly with OPENPANTRY_LOGO=/path/to/logo.jpg.
LOGO_CANDIDATES = [
    os.environ.get("OPENPANTRY_LOGO", ""),
    os.path.join(HERE, "logo.jpg"),
    os.path.join(HERE, os.pardir, "logo.jpg"),
    os.path.join(HERE, "footprints-logo.jpg"),
]


def find_logo():
    """First readable candidate, or None. Reported at build time, never guessed at."""
    for path in LOGO_CANDIDATES:
        if path and os.path.isfile(path):
            return os.path.abspath(path)
    return None


LOGO = find_logo()
print("cover logo: %s" % (LOGO or "none found - cover will use the wordmark only"))


def cover_logo(width=1.55 * inch, height=1.35 * inch):
    """The cover image, or blank space of the same height when none is found,
    so the rest of the cover keeps its spacing either way."""
    if LOGO:
        return Image(LOGO, width=width, height=height)
    return Spacer(1, height)

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

# Arial carries neither U+2696 (the scale glyph) nor U+26A0 (the warning
# triangle), and ReportLab draws a character its font lacks as an empty box
# without saying a word — so the scale-strip messages reprinted from the Scan
# page came out as tofu. Segoe UI Symbol has both. It is used only for those
# characters, not for running text, and it is the monochrome font on purpose:
# Segoe UI Emoji covers the same code points but would drop full-color pictures
# into a two-color print book.
try:
    pdfmetrics.registerFont(TTFont("Symbols", os.path.join(FONTS, "seguisym.ttf")))
    HAVE_SYMBOLS = True
except Exception as err:                       # no Segoe UI Symbol on this box
    sys.stderr.write("warning: symbol font unavailable (%s); "
                     "the scale and warning glyphs will print as boxes\n" % err)
    HAVE_SYMBOLS = False

_SYMBOL_RE = re.compile("([⚖⚠]+)")

def symbolize(t):
    """Switch the glyphs Arial is missing onto the symbol font.

    Applied centrally by Paragraph() below rather than by hand at each use, so
    a scale message pasted in from the Scan page renders correctly without the
    author having to know which characters Arial happens to lack."""
    if not HAVE_SYMBOLS:
        return t
    return _SYMBOL_RE.sub(r'<font face="Symbols">\1</font>', t)

def Paragraph(text, *args, **kw):
    """Drop-in for the ReportLab class, with the symbol swap applied.

    Every paragraph in both handbooks is built through here (the two make_*
    scripts import this name, not ReportLab's), which is what keeps the swap
    from having to be repeated at ~20 call sites."""
    return _Paragraph(symbolize(text), *args, **kw)

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
def make_doc(out_path, title):
    doc = BaseDocTemplate(
        out_path, pagesize=letter,
        leftMargin=0.98 * inch, rightMargin=0.98 * inch,
        topMargin=0.9 * inch, bottomMargin=0.9 * inch,
        title=title,
        author="Bruce Alexander • Chromagic Development")
    frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height,
                  leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([PageTemplate(id="page", frames=[frame])])
    return doc

def cover(subtitle, badge_text, blurb, revision):
    """The shared cover: logo, OpenPantry wordmark, subtitle, badge, blurb."""
    cc = ParagraphStyle("cc", fontName="Arial", fontSize=10.5,
                        leading=16, textColor=HexColor("#555555"),
                        alignment=TA_CENTER)
    E = [Spacer(1, 1.85 * inch),
         cover_logo(),
         Spacer(1, 0.55 * inch),
         Paragraph('<font face="Arial-Bold" size="30" color="#7cb342">Open</font>'
                   '<font face="Arial-Bold" size="30" color="#5d4a12">Pantry</font>',
                   ParagraphStyle("wm", alignment=TA_CENTER, leading=36)),
         Spacer(1, 6),
         Paragraph('<font size="15" color="#444444">%s</font>' % subtitle,
                   ParagraphStyle("sub", fontName="Arial", alignment=TA_CENTER,
                                  leading=20)),
         Spacer(1, 16)]
    badge = Table([[Paragraph(
        '<font face="Arial-Bold" size="10" color="#ffffff">%s</font>' % badge_text,
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
    E.append(Paragraph(blurb, cc))
    E.append(Spacer(1, 0.35 * inch))
    E.append(Paragraph(revision, cc))
    E.append(Paragraph("© 2026 Chromagic Development • Bruce Alexander "
                       "• MIT License", cc))
    E.append(PageBreak())
    return E

# ================================================================ shared content
# The two station checklists below are printed in BOTH handbooks — the volunteer
# one to work from, the administrator one to post at the stations and train
# from. Edit them here and re-run both scripts.

def scanning_station_checklist():
    E = [kicker("CHECKOUT · LASER SCANNER OR CAMERA")]
    E += h1("Open-the-Station Checklist — Scanning Station")
    E.append(Paragraph("Work top to bottom. Tick each box as you go.", S["italic"]))
    E.append(Spacer(1, 6))
    E.append(checkrow("Power on the Chromebook tablet and connect it to the "
                      "pantry Wi-Fi (the station only works on the pantry "
                      "network) if it is not already connected."))
    E.append(checkrow("Make sure the handheld barcode scanner is connected to "
                      "the tablet; wait for its ready beep / steady LED."))
    E.append(checkrow("Power on the produce scale and confirm the scale "
                      "strip reads <b>Scale ready</b>; see the setup "
                      "below if it does not."))
    # One page for both input methods now: the old Scan (Camera) menu entry is
    # gone, and the camera is a button on this same page.
    E.append(checkrow("Open the browser and go to the OpenPantry <b>Scan</b> "
                      "page. It opens straight to the scanner — <b>no "
                      "password</b> is needed, because the pantry network "
                      "authorizes the station."))
    E.append(checkrow("Scan any item's barcode as a test — a “Last "
                      "scan” line should appear. Then tap the red <b>× "
                      "Cancel Order</b> so the test doesn't count."))
    E.append(checkrow("Tap once inside the barcode box so the cursor sits there "
                      "(it glows green when focused)."))
    E.append(info("NO WIRED SCANNER? USE THE CAMERA ON THE SAME PAGE",
        "There is one <b>Scan</b> page for both ways of scanning. On a phone or "
        "any tablet without a handheld scanner, tap <b>Start Camera</b> in the "
        "<i>This Order</i> box and point the camera at the barcode — the page "
        "chirps once for each code it reads. <b>Stop Camera</b> puts it away. "
        "The camera needs permission the first time, and the page must be "
        "opened over <b>https</b>."))
    # Its own page: the six-step table plus its two callouts does not fit under
    # the checkboxes, and letting it flow splits the table across a page break.
    E.append(PageBreak())
    E.append(kicker("CHECKOUT · THE PRODUCE SCALE"))
    E += h1("Setting up the produce scale (once per tablet)")
    E.append(body("The <b>DYMO M25</b> is a USB scale. It plugs into the tablet "
                  "and sends its readings to OpenPantry on its own — there is "
                  "no keypad sequence to enter, no mode to select, and no unit "
                  "to set, because the station converts whatever unit the scale "
                  "displays into pounds. All it needs is power and, the first "
                  "time on a given tablet, the browser's permission:"))
    E.append(Spacer(1, 4))
    E.append(steptable([
        ("Stand the scale on a flat, dry surface with nothing on the platform.",
         "Nothing on the platform"),
        ("Plug its USB cable into the tablet.", "Connected to the station"),
        ("Press the scale's power key and let the display settle to <b>0</b>.",
         "Zero / stable reading"),
        ("On the <b>Scan</b> page, read the scale strip just above the barcode box.",
         "Says what the scale is doing"),
        ("If it says <b>Scale not connected</b>, tap <b>⚖ Connect Scale</b> and "
         "pick the scale from the browser's list.", "Once per tablet only"),
        ("Confirm the strip reads <b>⚖ Scale ready</b>.",
         "Interfaced to OpenPantry"),
    ]))
    E.append(Spacer(1, 10))
    E.append(good("WHEN THE STRIP SAYS “SCALE READY”, YOU'RE SET",
        "That line means the station is hearing the scale. You can then weigh "
        "produce in <b>either order</b> — scan the PLU and set the item on the "
        "scale, or weigh it first and scan the PLU when the station asks for "
        "it. You only ever tap <b>Connect Scale</b> once on a tablet: the "
        "browser remembers the scale and reconnects by itself every later "
        "shift."))
    E.append(info("THE SCALE SWITCHES ITSELF OFF",
        "The M25 powers down after a quiet spell to save its batteries, and a "
        "scale that has shut off looks exactly like one nobody has touched. If "
        "the strip says <b>⚠ Make sure scale is on.</b>, press the scale's "
        "power key to wake it — you do <i>not</i> have to redo the steps "
        "above. If the display is awake but not reading <b>0</b> with an empty "
        "platform, press its <b>Tare</b> / <b>Zero</b> key."))
    return E

def menu_counter_checklist():
    E = [kicker("CUSTOMER ORDERING KIOSK")]
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
    return E
