# Printable barcode sheets for items the pantry labels itself.
#
# Two sheets, and they solve different problems:
#
#   UPC sheet   Items with no manufacturer barcode at all — butter tubs, halal
#               meat, diapers. Each gets an in-store label in the GS1 prefix-2
#               range, which is reserved for exactly this:
#
#                   2 IIIII VVVVV C     UPC-A, 12 digits
#                   │ │     │     └─ check digit
#                   │ │     └─────── variable measure (price/weight)
#                   │ └───────────── item code
#                   └─────────────── prefix 2, in-store
#
#               The item's ordinal MUST live in IIIII, never in VVVVV.
#               lookupBarcode() keys these on the leading six digits because a
#               retail scale rewrites the trailing five for every package, so a
#               sheet that counts up in VVVVV puts every item it lists under one
#               key — and naming any one of them names all of them. VVVVV is
#               therefore fixed at 00000 here; nothing weighs these.
#
#   PLU sheet   Loose produce sold by the pound. Plain 4-5 digit PLU codes in
#               Code128, since a PLU is not a valid UPC-A.
#
# Assignments come from the database by default, so reprinting a sheet cannot
# renumber an item out from under the names already cached against it. Adding
# items from a text file appends them at the next free ordinal, leaving every
# existing assignment alone.
#
# Requires reportlab (pip install reportlab). Arial is used when the Windows
# TTFs are present and Helvetica otherwise, so this runs on a clean checkout.
#
#   python make_barcode_sheets.py --db ../openpantry.db
#   python make_barcode_sheets.py --db /path/openpantry.db --add new_items.txt
#   python make_barcode_sheets.py --items all_items.txt --only upc
#
import argparse
import os
import sqlite3
import sys

from reportlab.graphics.barcode import createBarcodeDrawing
from reportlab.lib import colors
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import inch
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle,
)

HERE = os.path.dirname(os.path.abspath(__file__))

OLIVE    = colors.HexColor("#7d9a2d")
BROWN    = colors.HexColor("#6b4e16")
GRAY     = colors.HexColor("#777777")
RULE_TAN = colors.HexColor("#d8cda6")
CARD_BG  = colors.HexColor("#fafaf5")

# The handbooks hard-require the Windows Arial TTFs. A barcode sheet is the
# thing a new pantry prints first, so it falls back instead of refusing to run.
def register_fonts():
    win = r"C:\Windows\Fonts"
    faces = {"Arial": "arial.ttf", "Arial-Bold": "arialbd.ttf"}
    try:
        for name, ttf in faces.items():
            pdfmetrics.registerFont(TTFont(name, os.path.join(win, ttf)))
        pdfmetrics.registerFontFamily("Arial", normal="Arial", bold="Arial-Bold")
        return "Arial", "Arial-Bold"
    except Exception:
        return "Helvetica", "Helvetica-Bold"


# ------------------------------------------------------------------ barcodes

def upc_check_digit(eleven: str) -> str:
    """Standard UPC-A modulo-10: odd positions weighted 3, counting from 1."""
    if len(eleven) != 11 or not eleven.isdigit():
        raise ValueError("need 11 digits, got %r" % (eleven,))
    total = sum(int(d) * (3 if i % 2 == 0 else 1) for i, d in enumerate(eleven))
    return str((10 - total % 10) % 10)


def store_label(ordinal: int, prefix: str = "2") -> str:
    """Full 12-digit in-store label for an item ordinal.

    The ordinal occupies the item field; the variable-measure field stays
    00000. Keeping it there is what makes lookupBarcode()'s six-digit key
    identify one item instead of the whole sheet.
    """
    if not 1 <= ordinal <= 99999:
        raise ValueError("ordinal out of range: %d" % ordinal)
    body = "%s%05d%05d" % (prefix, ordinal, 0)
    return body + upc_check_digit(body)


def store_key(ordinal: int, prefix: str = "2") -> str:
    """The six digits lookupBarcode() will cache the name under."""
    return "%s%05d" % (prefix, ordinal)


# ------------------------------------------------------------------ sources

def items_from_db(path, prefix="2", block=(1, 999)):
    """(ordinal, name) for keys the pantry assigned itself, plus what was skipped.

    Reading assignments back rather than renumbering from scratch is the whole
    point: a reprint has to keep pointing at the names already cached, or every
    label on the shelf starts resolving to the wrong item.

    upc_lookup also holds six-digit keys for *retailer* labels — deli, bakery,
    meat trays — which look identical and must not be printed: the store already
    prints those, and a sheet copy would carry an item number the store never
    used. They are told apart by the only thing that distinguishes them, which
    is that the pantry reserves a block of low item numbers for itself. Anything
    outside the block is returned separately rather than dropped in silence.
    """
    lo, hi = block
    con = sqlite3.connect(path, timeout=5)
    try:
        rows = con.execute(
            "SELECT upc, generic_name FROM upc_lookup"
            " WHERE length(upc) = 6 AND substr(upc, 1, 1) = ?"
            " ORDER BY upc", (prefix,)).fetchall()
    finally:
        con.close()
    mine, theirs = [], []
    for upc, name in rows:
        tail = upc[1:]
        if not tail.isdigit() or int(tail) == 0:
            continue
        (mine if lo <= int(tail) <= hi else theirs).append((int(tail), name))
    return mine, theirs


def plu_from_db(path):
    con = sqlite3.connect(path, timeout=5)
    try:
        return con.execute(
            "SELECT code, generic_name FROM produce_lookup"
            " WHERE length(code) IN (4, 5) ORDER BY generic_name COLLATE NOCASE").fetchall()
    finally:
        con.close()


def read_names(path):
    with open(path, encoding="utf-8") as fh:
        return [ln.strip() for ln in fh if ln.strip() and not ln.startswith("#")]


def append_names(existing, names):
    """Give each new name the lowest free ordinal, leaving existing ones put."""
    taken = {o for o, _ in existing}
    have = {n.strip().lower() for _, n in existing}
    merged, nxt = list(existing), 1
    for name in names:
        if name.strip().lower() in have:
            continue
        while nxt in taken:
            nxt += 1
        merged.append((nxt, name))
        taken.add(nxt)
    return sorted(merged)


# ------------------------------------------------------------------ rendering

def card(name, value, symbology, body, bold, width):
    """One labelled barcode: item name above, scannable symbol below."""
    drawing = createBarcodeDrawing(
        symbology, value=value, humanReadable=True,
        barHeight=0.62 * inch, barWidth=0.011 * inch if symbology == "UPCA" else 0.013 * inch,
        quiet=True, fontName=body, fontSize=7)
    label = ParagraphStyle("label", fontName=bold, fontSize=10.5,
                           leading=13, textColor=BROWN)
    inner = Table([[Paragraph(name, label)], [drawing]], colWidths=[width])
    inner.setStyle(TableStyle([
        ("ALIGN",        (0, 0), (-1, -1), "CENTER"),
        ("VALIGN",       (0, 0), (-1, -1), "MIDDLE"),
        ("BACKGROUND",   (0, 0), (-1, -1), CARD_BG),
        ("BOX",          (0, 0), (-1, -1), 0.4, RULE_TAN),
        ("TOPPADDING",   (0, 0), (-1, 0), 8),
        ("BOTTOMPADDING", (0, 1), (-1, 1), 8),
    ]))
    return inner


def sheet(story, title, subtitle, rows, symbology, body, bold, page_width, cols=2):
    story.append(Paragraph(title, ParagraphStyle(
        "h", fontName=bold, fontSize=16, leading=20, textColor=BROWN, spaceAfter=2)))
    story.append(Paragraph(subtitle, ParagraphStyle(
        "sub", fontName=body, fontSize=8.5, leading=12, textColor=GRAY, spaceAfter=12)))
    if not rows:
        story.append(Paragraph("Nothing to print.", ParagraphStyle(
            "empty", fontName=body, fontSize=10, textColor=GRAY)))
        return
    cell_w = page_width / cols
    cards = [card(name, value, symbology, body, bold, cell_w - 12) for name, value in rows]
    grid = [cards[i:i + cols] for i in range(0, len(cards), cols)]
    if len(grid[-1]) < cols:
        grid[-1] += [""] * (cols - len(grid[-1]))
    table = Table(grid, colWidths=[cell_w] * cols, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("VALIGN",        (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING",   (0, 0), (-1, -1), 6),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 10),
    ]))
    story.append(table)


# ------------------------------------------------------------------ entry

def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--db", help="openpantry.db to read assignments from")
    ap.add_argument("--items", metavar="FILE",
                    help="item names, one per line, numbered from 1 (no database)")
    ap.add_argument("--add", metavar="FILE",
                    help="extra item names to append at the next free ordinals")
    ap.add_argument("--prefix", default="2", metavar="D",
                    help="in-store prefix digit (default 2)")
    ap.add_argument("--block", default="1-999", metavar="LO-HI",
                    help="item numbers the pantry has reserved for itself"
                         " (default 1-999); keys outside it are the retailer's"
                         " own labels and are never printed")
    ap.add_argument("--only", choices=("upc", "plu"), help="print just one sheet")
    ap.add_argument("--out-prefix", default="openpantry", metavar="NAME",
                    help="output filename stem (default openpantry)")
    args = ap.parse_args(argv)

    if not args.db and not args.items:
        ap.error("give --db or --items (see --help)")
    if args.add and not args.db:
        ap.error("--add appends to existing assignments, so it needs --db")

    body, bold = register_fonts()

    try:
        lo, hi = (int(x) for x in args.block.split("-", 1))
    except ValueError:
        ap.error("--block wants LO-HI, e.g. 1-999")
    assigned, retailer = items_from_db(args.db, args.prefix, (lo, hi)) if args.db else ([], [])
    if args.items:
        assigned = append_names(assigned, read_names(args.items))
    added = []
    if args.add:
        before = {o for o, _ in assigned}
        assigned = append_names(assigned, read_names(args.add))
        added = [(o, n) for o, n in assigned if o not in before]

    page_w = letter[0] - 1.4 * inch
    written = []

    if args.only != "plu":
        rows = [(name, store_label(o, args.prefix)) for o, name in assigned]
        out = os.path.join(HERE, "%s_item_barcodes.pdf" % args.out_prefix)
        doc = SimpleDocTemplate(
            out, pagesize=letter, title="Item Barcodes",
            leftMargin=0.7 * inch, rightMargin=0.7 * inch,
            topMargin=0.6 * inch, bottomMargin=0.6 * inch)
        story = []
        sheet(story, "Item Barcodes",
              "In-store labels for items with no manufacturer barcode. The item "
              "number is the digits after the leading %s; the trailing five are "
              "always zero." % args.prefix,
              rows, "UPCA", body, bold, page_w)
        doc.build(story)
        written.append((out, len(rows)))

    if args.only != "upc":
        plu = plu_from_db(args.db) if args.db else []
        out = os.path.join(HERE, "%s_plu_barcodes.pdf" % args.out_prefix)
        doc = SimpleDocTemplate(
            out, pagesize=letter, title="PLU Barcodes",
            leftMargin=0.7 * inch, rightMargin=0.7 * inch,
            topMargin=0.6 * inch, bottomMargin=0.6 * inch)
        story = []
        sheet(story, "Produce PLU Barcodes",
              "Scan after weighing. These carry the PLU only — the scale is not "
              "part of the code.",
              [(name, code) for code, name in plu], "Code128", body, bold, page_w)
        doc.build(story)
        written.append((out, len(plu)))

    for path, n in written:
        print("wrote %s (%d item%s)" % (path, n, "" if n == 1 else "s"))
    if retailer:
        print()
        print("Skipped %d retailer-printed key%s outside block %d-%d "
              "(the store prints those itself):"
              % (len(retailer), "" if len(retailer) == 1 else "s", lo, hi))
        for o, name in retailer[:10]:
            print("  %s%05d  %s" % (args.prefix, o, name))
        if len(retailer) > 10:
            print("  ... and %d more" % (len(retailer) - 10))
    if added:
        print("\nNew item numbers assigned - add these under Lookup Tables so the")
        print("labels resolve when scanned:")
        for o, name in added:
            print("  %s  %-34s  label %s" % (store_key(o, args.prefix), name,
                                             store_label(o, args.prefix)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
