# Generates docs/OpenPantry-Poisson-GLM-Reference.pdf.
#
# A standing reference for why the Order Report's demand model is a
# quasi-Poisson GLM. Written to be read cold, months from now, by someone
# deciding whether to keep, replace, or tune the forecast — so it leads with
# the payoff, states plainly what the Poisson is NOT responsible for (the
# recurring confusion), and records the alternatives that were considered.
#
# Built from this script alone — edit the story below and re-run
# (python make_poisson_reference.py) to publish a new edition. Requires
# reportlab and the Arial/Consolas TTFs that ship with Windows.
#
# Design deliberately mirrors make_admin_handbook.py (olive kickers, brown
# headings with a tan rule, colored callouts, dark code blocks) so the two
# documents read as one family. No cover page: this is a reference sheet.
import os

from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_CENTER
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer,
    Table, TableStyle, HRFlowable, KeepTogether,
)
from reportlab.lib.styles import ParagraphStyle

HERE = os.path.dirname(os.path.abspath(__file__))
OUT  = os.path.join(HERE, "OpenPantry-Poisson-GLM-Reference.pdf")

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
OLIVE     = HexColor("#7d9a2d")
BROWN     = HexColor("#6b4e16")
BODY      = HexColor("#3d3d3d")
GRAY      = HexColor("#777777")
RULE_TAN  = HexColor("#d8cda6")
INFO_BG   = HexColor("#ddebfa"); INFO_BAR = HexColor("#2f6db5")
WARN_BG   = HexColor("#f7ded8"); WARN_BAR = HexColor("#c0503e")
GOOD_BG   = HexColor("#e6edd6"); GOOD_BAR = HexColor("#6b8e23")
CODE_BG   = HexColor("#2b2b2b")
CODE_FG   = HexColor("#b8cc7a")
HEAD_BG   = HexColor("#efe9d8")
ZEBRA     = HexColor("#faf8f2")

# ---------------------------------------------------------------- styles
def ps(name, **kw):
    base = dict(fontName="Arial", fontSize=10.5, leading=15, textColor=BODY)
    base.update(kw)
    return ParagraphStyle(name, **base)

S = {
    "kicker": ps("kicker", fontName="Arial-Bold", fontSize=9, leading=12,
                 textColor=OLIVE, spaceBefore=11, spaceAfter=2),
    "h1":     ps("h1", fontName="Arial-Bold", fontSize=15.5, leading=19,
                 textColor=BROWN, spaceBefore=2, spaceAfter=4),
    "lead":   ps("lead", fontSize=12, leading=17, spaceBefore=5, spaceAfter=5),
    "body":   ps("body", fontSize=10.2, leading=14.2, spaceBefore=3.5, spaceAfter=3.5),
    "quote":  ps("quote", fontName="Arial-Italic", fontSize=11.5, leading=16,
                 leftIndent=18, textColor=HexColor("#55524a"),
                 spaceBefore=7, spaceAfter=7),
    "bullet": ps("bullet", spaceBefore=2.5, spaceAfter=2.5, leftIndent=16,
                 bulletIndent=4),
    "boxk":   ps("boxk", fontName="Arial-Bold", fontSize=8.8, leading=12),
    "boxb":   ps("boxb", fontSize=9.6, leading=13.5),
    "th":     ps("th", fontName="Arial-Bold", fontSize=9.2, leading=12.5,
                 textColor=BROWN),
    "td":     ps("td", fontSize=9.4, leading=13),
    "foot":   ps("foot", fontSize=8.6, leading=12, textColor=GRAY,
                 spaceBefore=8),
}

def code(t):    return '<font face="Consolas" color="#8a7040">%s</font>' % t
def kicker(t):  return Paragraph(t, S["kicker"])
def h1(t):
    return [Paragraph(t, S["h1"]),
            HRFlowable(width="100%", thickness=0.8, color=RULE_TAN,
                       spaceBefore=1, spaceAfter=7)]
def body(t):    return Paragraph(t, S["body"])
def lead(t):    return Paragraph(t, S["lead"])
def quote(t):   return Paragraph(t, S["quote"])
def foot(t):    return Paragraph(t, S["foot"])
def bullet(t):
    return Paragraph('<bullet><font color="#7d9a2d">\u2022</font></bullet>' + t,
                     S["bullet"])

def callout(kick, text, bg, bar, kcolor):
    kstyle = ParagraphStyle("bk", parent=S["boxk"], textColor=kcolor)
    inner = [Paragraph(kick, kstyle), Spacer(1, 2), Paragraph(text, S["boxb"])]
    t = Table([[inner]], colWidths=[6.55 * inch])
    t.setStyle(TableStyle([
        ("BACKGROUND",    (0, 0), (-1, -1), bg),
        ("LINEBEFORE",    (0, 0), (0, -1), 3, bar),
        ("LEFTPADDING",   (0, 0), (-1, -1), 12),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 12),
        ("TOPPADDING",    (0, 0), (-1, -1), 9),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 9),
    ]))
    return KeepTogether([Spacer(1, 5), t, Spacer(1, 5)])

def info(k, t): return callout(k, t, INFO_BG, INFO_BAR, INFO_BAR)
def warn(k, t): return callout(k, t, WARN_BG, WARN_BAR, HexColor("#a53c2c"))
def good(k, t): return callout(k, t, GOOD_BG, GOOD_BAR, HexColor("#5a7d1e"))

def codeblock(lines):
    style = ParagraphStyle("code", fontName="Consolas", fontSize=9.5,
                           leading=13.5, textColor=CODE_FG)
    t = Table([[[Paragraph(l, style) for l in lines]]], colWidths=[6.55 * inch])
    t.setStyle(TableStyle([
        ("BACKGROUND",    (0, 0), (-1, -1), CODE_BG),
        ("LEFTPADDING",   (0, 0), (-1, -1), 14),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 14),
        ("TOPPADDING",    (0, 0), (-1, -1), 12),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 12),
    ]))
    return KeepTogether([Spacer(1, 4), t, Spacer(1, 4)])

def datatable(header, rows, widths):
    """Header row on tan, tan rules between rows, subtle zebra striping."""
    data = [[Paragraph(c, S["th"]) for c in header]]
    data += [[Paragraph(c, S["td"]) for c in r] for r in rows]
    t = Table(data, colWidths=[w * inch for w in widths], repeatRows=1)
    style = [
        ("BACKGROUND",    (0, 0), (-1, 0), HEAD_BG),
        ("VALIGN",        (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING",   (0, 0), (-1, -1), 9),
        ("RIGHTPADDING",  (0, 0), (-1, -1), 9),
        ("TOPPADDING",    (0, 0), (-1, -1), 5.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5.5),
        ("LINEBELOW",     (0, 0), (-1, 0), 0.8, RULE_TAN),
    ]
    for r in range(1, len(data)):
        if r % 2 == 0:
            style.append(("BACKGROUND", (0, r), (-1, r), ZEBRA))
        if r < len(data) - 1:
            style.append(("LINEBELOW", (0, r), (-1, r), 0.5, RULE_TAN))
    t.setStyle(TableStyle(style))
    return KeepTogether([Spacer(1, 4), t, Spacer(1, 5)])

# ---------------------------------------------------------------- document
doc = BaseDocTemplate(
    OUT, pagesize=letter,
    leftMargin=0.98 * inch, rightMargin=0.98 * inch,
    topMargin=0.72 * inch, bottomMargin=0.72 * inch,
    title="Why a Poisson GLM",
    author="Bruce Alexander \u2022 Chromagic Development")
frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height,
              leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
doc.addPageTemplates([PageTemplate(id="page", frames=[frame])])

E = []

# ================================================================ masthead
E.append(Paragraph(
    '<font face="Arial-Bold" size="19" color="#7cb342">Open</font>'
    '<font face="Arial-Bold" size="19" color="#5d4a12">Pantry</font>'
    '<font face="Arial" size="19" color="#999999">  \u2014  Why a Poisson GLM</font>',
    ParagraphStyle("mast", leading=24)))
E.append(Paragraph(
    '<font size="10" color="#777777">Demand forecasting reference '
    '\u2022 Order Report \u2022 reports/order_report/forecast.php</font>',
    ParagraphStyle("sub", fontName="Arial", leading=15, spaceBefore=2)))
E.append(HRFlowable(width="100%", thickness=1.2, color=RULE_TAN,
                    spaceBefore=8, spaceAfter=4))

# ================================================================ short answer
E.append(kicker("THE SHORT ANSWER"))
E += h1("Safety stock must be sized for the level being forecast")
E.append(lead(
    "Safety stock is Z\u00b7\u221aVar. Sizing it requires the variance of the demand "
    "actually being ordered for \u2014 which, for a seasonal or growing item, is a "
    "level that has not recently been observed. A Poisson GLM supplies a variance "
    "that follows the forecast to that level. A constant-variance model cannot."))
E.append(good("IN ONE LINE",
    "Poisson lets the buffer breathe with the season. Gaussian freezes it at the "
    "yearly average."))

# ================================================================ payoff
E.append(kicker("THE PAYOFF"))
E += h1("What level-responsiveness is worth")
E.append(body(
    "Bottled water moving 5/day in winter and 20/day in summer. Fourteen-day "
    "order, Z = 1.65, \u03c6 = 2."))
E.append(datatable(
    ["Season", "Forecast", "Poisson buffer", "Constant-variance buffer"],
    [["Winter \u2014 5/day",  "70",  "19.5", "30.9"],
     ["Summer \u2014 20/day", "280", "39.1", "30.9"]],
    [1.75, 1.15, 1.75, 1.9]))
E.append(body(
    "The Poisson buffer doubles as demand quadruples \u2014 spread grows as "
    "\u221a4 = 2. The constant-variance buffer was fitted once across the whole "
    "year and lands near the annual average in both seasons: roughly "
    "<b>58% too much stock in winter</b> and <b>21% too little in summer</b>, "
    "with the shortfall falling in the season most likely to run out."))

# ================================================================ ingredients
E.append(kicker("THE THREE INGREDIENTS"))
E += h1("Family, link, and design matrix are independent choices")
E.append(datatable(
    ["Ingredient", "Choice here", "What it governs"],
    [["family", "quasi-Poisson",
      "How observations scatter around \u03bc. Sets the IRLS weight "
      "w = \u03bc and Var = \u03c6\u00b7\u03bc."],
     ["link", "log",
      "Maps the linear predictor \u03b7 to \u03bc. Makes effects multiplicative "
      "and keeps \u03bb &gt; 0 under extrapolation."],
     ["design matrix", "[1, trend, sin/cos \u2026]",
      "What \u03b7 is composed of. Fourier seasonality lives here, gated on "
      "history span."]],
    [1.15, 1.35, 4.05]))
E.append(body(
    "They compose, but none depends on the others \u2014 any one can be swapped. "
    "In the fit loop they are adjacent lines: " + code("$mu = exp($eta)") +
    " is the link; " + code("$w = $mu") + " is the family. Log is the "
    "<i>canonical</i> link for Poisson, which is why the general IRLS weight "
    "collapses to exactly \u03bc and the solver stays short enough to hand-roll "
    "in dependency-free PHP."))

# ================================================================ objection
E.append(kicker("THE COMMON OBJECTION"))
E += h1("Why \u03c6 does not make the Poisson redundant")
E.append(body(
    "\u03c6 is one scalar per item, fitted from Pearson residuals. It can rescale "
    "a relationship; it cannot create one. Posed the question that actually "
    "matters, a single number has no answer:"))
E.append(quote(
    "\u201c\u03c3 was 3.1 when demand ran 4/day. What is \u03c3 at 15/day?\u201d"))
E.append(body("Var = \u03c6\u00b7\u03bc answers it in one multiplication:"))
E.append(codeblock([
    "from history:   \u03c6 = 3.1\u00b2 / 4      = 2.4",
    "at 15/day:      Var = 2.4 \u00d7 15    = 36",
    "                \u03c3   = \u221a36        = 6.0",
]))
E.append(datatable(
    ["", "Supplies", "Level-dependent?"],
    [["Poisson", "Variance proportional to \u03bc",
      "<b>Yes</b> \u2014 this is the part that transfers"],
     ["\u03c6", "The constant of proportionality",
      "No \u2014 one number, fitted from all buckets"]],
    [0.85, 2.4, 3.3]))
E.append(body(
    "So \u03c6 is not correcting a mistake in the Poisson; it fills the one blank "
    "the Poisson deliberately leaves. Pure Poisson hardcodes that constant at "
    "1.0, which is usually wrong. Quasi-Poisson keeps the proportionality \u2014 "
    "the useful half \u2014 and measures the constant instead of assuming it."))
E.append(body(
    "Measured against known variability, the shape-plus-scale split reproduced "
    "true spread within <b>0.88\u20131.01\u00d7</b> across every demand pattern "
    "tested, including produce at \u03c6 \u2248 0.06 \u2014 a case where pure "
    "Poisson would have over-buffered by 4\u00d7."))

# ================================================================ scope
E.append(kicker("SCOPE"))
E += h1("What the Poisson is <i>not</i> responsible for")
E.append(warn("RECURRING CONFUSIONS",
    "<b>\u03bb staying positive is the log link, not the family.</b> A Poisson GLM "
    "with an identity link can produce a negative fitted mean; a Gaussian GLM with "
    "a log link cannot. Multiplicative trend and seasonality are likewise the "
    "link.<br/><br/>"
    "<b>The size of the variance is \u03c6</b>, not the family.<br/><br/>"
    "<b>No Poisson probability mass function is ever evaluated.</b> "
    "Quasi-likelihood specifies only the mean\u2013variance relation, so "
    "quasi-Poisson is not, strictly speaking, a distribution at all."))
E.append(body(
    "The family's entire contribution is <b>Var(\u03bc) = \u03bc</b> \u2014 variance "
    "rises with level. Non-negativity and discreteness of the response are why "
    "that relationship tends to hold empirically for count data; they are not "
    "separate benefits the family delivers."))
E.append(body(
    "It follows that the Poisson earns nothing in a model that does not "
    "extrapolate. With no trend and no seasonality, \u03bc never moves, and a "
    "variance that tracks \u03bc is indistinguishable from a plain empirical one."))

# ================================================================ alternatives
E.append(kicker("ALTERNATIVES"))
E += h1("Other families that would also work")
E.append(datatable(
    ["Family", "Var(\u03bc)", "Verdict"],
    [["Gaussian", "\u03c6",
      "<b>Rejected</b> \u2014 no \u03bc in the formula, so the buffer cannot "
      "follow the forecast."],
     ["Gamma", "\u03c6\u03bc\u00b2",
      "<b>Rejected</b> \u2014 requires y &gt; 0; pantry data is full of zero weeks."],
     ["Negative binomial", "\u03bc + \u03bc\u00b2/k",
      "Viable \u2014 handles zeros, but costs a second parameter, k."],
     ["Tweedie (1 &lt; p &lt; 2)", "\u03c6\u03bc<super>p</super>",
      "Viable \u2014 best fit for produce in lbs, but p must be estimated."],
     ["quasi-Poisson", "\u03c6\u03bc",
      "<b>Chosen</b> \u2014 one scalar, closed-form from Pearson residuals."]],
    [1.45, 1.05, 4.05]))
E.append(body(
    "Poisson also has an aggregation property the others lack. Because "
    "Var = \u03c6\u00b7\u03bc is linear in \u03bc, variance over a multi-day horizon "
    "is \u03c6\u00b7\u03a3\u03bb = \u03c6\u00b7Forecast \u2014 a single multiplication. "
    "Under Gamma or Tweedie the horizon aggregation needs every day's rate "
    "individually."))

# ================================================================ openpantry
E.append(kicker("IN OPENPANTRY"))
E += h1("Where this lives, and when it applies")
E.append(codeblock([
    "Par Level     = Forecast(LT) + Safety Stock",
    "Forecast(LT)  = \u03a3 \u03bb(today+d)   for d = 1..LT",
    "Safety Stock  = Z \u00d7 \u221a( \u03c6 \u00d7 Forecast(LT) )",
]))
E.append(body(
    "Implemented in " + code("reports/order_report/forecast.php") + " \u2014 "
    + code("op_glm_fit_series()") + " fits the model, "
    + code("op_project_forecast()") + " projects it over the lead time. Items "
    "without enough history fall back to a trailing average in "
    + code("report_lib.php") + ", whose \u03c3 is measured over distribution days "
    "only so that closed-day zeros are not mistaken for demand volatility."))
E.append(body(
    "The model is gated on how much history each item has. Seasonal richness "
    "scales with span, so the capability arrives in stages:"))
E.append(datatable(
    ["Milestone", "Date", "Capability"],
    [["First fit possible", "2026-09-15",
      "Intercept + trend only (harmonics = 0)"],
     ["1 seasonal harmonic", "2027-06-02", "Annual cycle enters the model"],
     ["2 seasonal harmonics", "2027-12-29", "Sharper seasonal shape"]],
    [1.75, 1.25, 3.55]))
E.append(info("CURRENT STATUS",
    "Scan history begins 2026-07-07. Until the first gate clears, every item "
    "runs the trailing-average fallback and no \u03c6 is computed anywhere \u2014 "
    "the S and G columns are hardcoded to 1.0. This is the gates working "
    "correctly on a young dataset, not a fault. The Poisson machinery "
    "contributes nothing until the model begins projecting levels it has not "
    "observed, which is when seasonality switches on."))
E.append(foot(
    "Gates: history span \u2265 60 days and \u2265 10 complete weekly buckets. "
    "Harmonic thresholds: 330 days for one, 540 for two. Training window capped "
    "at 1100 days. Dates above are derived from the earliest scan on record and "
    "shift if that changes."))

# Glue each section's kicker + heading + rule to the flowable that follows, so
# a heading can never strand itself at the foot of a page ahead of its own
# content. Done as a post-pass so the story above stays flat and readable.
def glue_headings(story):
    out, i = [], 0
    while i < len(story):
        f = story[i]
        if (isinstance(f, Paragraph) and f.style.name == "kicker"
                and i + 3 < len(story)):
            # Flatten rather than nest: a KeepTogether inside a KeepTogether
            # makes reportlab give up and break the page instead of measuring.
            nxt = story[i + 3]
            tail = list(nxt._content) if isinstance(nxt, KeepTogether) else [nxt]
            out.append(KeepTogether(story[i:i + 3] + tail))
            i += 4
        else:
            out.append(f)
            i += 1
    return out

doc.build(glue_headings(E))
print("wrote", OUT)
