# Generates docs/OpenPantry-Volunteer-Handbook.pdf.
#
# The PDF is built from this script alone — edit the story below and re-run
# (python make_volunteer_handbook.py) to publish a new edition. Styles, page
# setup, and the two station checklists come from handbook_common.py, which the
# Administrator Handbook shares: the checklists are printed in both books, so
# they are edited in one place.
#
# Audience: volunteers running the scanning station and the Menu Counter. Keep
# it non-technical — no file names, no settings, no database talk. Anything a
# volunteer would have to ask the administrator for belongs in the
# Administrator Handbook instead.
import os

from reportlab.lib.units import inch
from reportlab.platypus import Paragraph, Spacer, PageBreak

from handbook_common import (
    HERE, S, make_doc, cover, kicker, h1, body, bullet, step,
    info, warn, good, checkrow, steptable,
    scanning_station_checklist, menu_counter_checklist,
)

OUT = os.path.join(HERE, "OpenPantry-Volunteer-Handbook.pdf")

doc = make_doc(OUT, "Volunteer Handbook")
E = []  # story

# ================================================================ cover
E += cover(
    subtitle="Volunteer Handbook",
    badge_text="FOR VOLUNTEERS",
    blurb="How to open and run the checkout and Menu Counter stations.",
    revision="Revised August 2026 &nbsp;•&nbsp; Version 1.1")

# ================================================================ welcome
E.append(kicker("START HERE"))
E += h1("Welcome")
E.append(Paragraph(
    "Thank you for volunteering! OpenPantry is the software the pantry uses to "
    "check food out the door, keep the shelves counted, and know what to "
    "reorder. You don't need any technical background — this handbook walks "
    "you through opening each station and handling the few moments where the "
    "app asks you to do something.", S["lead"]))
E.append(body(
    "You'll mostly work at one of two stations: the <b>Scanning Station</b> "
    "(grocery-style checkout with a barcode scanner) or the <b>Menu "
    "Counter</b> (a tablet where shoppers tap the items they want and "
    "volunteers pick the order in back). Both are covered below."))
E.append(warn("GOLDEN RULE",
    "If anything looks wrong — a frozen screen, a scanner that won't beep, a "
    "weight that won't save — <b>don't force it</b>. Note the order number if "
    "there is one and ask the administrator on duty. Nothing you tap here can "
    "break the data."))

E.append(kicker("BEFORE YOU BEGIN"))
E += h1("Getting on the stations")
E.append(body(
    "You do <b>not</b> need a password to run the scanning station or the Menu "
    "Counter. Those screens are locked to the pantry's own network address "
    "(its IP), so simply being on the pantry Wi-Fi is what authorizes them — "
    "they open straight to the work screen."))
E.append(bullet(
    "The stations only work while you're on the <b>pantry's Wi-Fi</b>. On any "
    "other network the app shows an “Access Denied” screen — "
    "that's expected, not a login you're missing."))
E.append(bullet(
    "OpenPantry may also be set to open only during pantry hours. Outside "
    "those hours you'll see “Access is closed right now.” Try "
    "again during service."))
E.append(bullet(
    "If the pantry's internet address changes, the stations show that same "
    "“Access Denied” screen even on the right Wi-Fi. Whoever holds the "
    "<b>supervisor password</b> can put it right: open <b>Settings</b>, and "
    "under Secure Network Access press <b>Use My Current IP</b> and then "
    "<b>Set IP Address</b>. That is the one setting a supervisor can change — "
    "the rest of the page is read-only for them, so there is nothing to break."))
E.append(bullet(
    "A password is only requested on <b>administrator</b> screens — item "
    "setup, Settings, reports, and the delivery client list. Volunteers don't "
    "use those day to day; if you land on one by mistake, back out and ask the "
    "administrator."))
E.append(PageBreak())

# ================================================================ checklist 1
E += scanning_station_checklist()
E.append(PageBreak())

# ================================================================ running scan
E.append(kicker("CHECKOUT, STEP BY STEP"))
E += h1("Running the Scanning Station")

E.append(Paragraph("Starting and ending an order", S["h3"]))
E.append(step(1, "You don't press “start” — the first item you "
                 "scan automatically opens a new order and assigns it a number "
                 "(shown large at the top)."))
E.append(step(2, "Scan every item the shopper is taking — or add it by name if "
                 "it won't scan (see below). Each entry drops into the list "
                 "with a beep."))
E.append(step(3, "When they're done, tap <b>■ End Order</b>. The counts are "
                 "subtracted from inventory. (You can also scan the special "
                 "End Order command barcode if your station has one posted.)"))
E.append(step(4, "Made a mistake? Tap the red <b>×</b> next to a line to remove "
                 "that scan, or tap <b>× Cancel Order</b> to throw the whole "
                 "order away."))

E.append(Paragraph("Adding an item by name (no barcode needed)", S["h3"]))
E.append(body(
    "No barcode, a barcode that won't scan, or loose produce with no sticker? "
    "You can add the item by typing its <b>name</b> instead. The entry box is "
    "labeled <b>Barcode or Item Name</b> — start typing and, after two "
    "letters, matching items from the catalog appear beneath it as you type. "
    "When your text matches a single item, press <b>Enter</b> (or Tab) to "
    "record it, exactly as if you'd scanned it. Digits on their own are still "
    "treated as a barcode, so typing a name never interferes with the "
    "scanner."))
E.append(good("FASTEST FIX FOR A TORN OR MISSING BARCODE",
    "If a label is smudged or peeled off, don't hunt for the number — just "
    "type the first few letters of the item's name and pick it from the "
    "list."))

E.append(Paragraph("What you don't need to scan", S["h3"]))
E.append(body(
    "You don't have to capture everything a shopper takes. The scan data "
    "drives reordering of the core, regularly-stocked staples, so a few "
    "categories can be safely ignored:"))
E.append(bullet("High-calorie items with low nutritional value — e.g. candy, "
                "sweets, or desserts."))
E.append(bullet("Packaged meals and sandwiches."))
E.append(bullet("Anything that doesn't appear to be regularly stocked — "
                "one-off donations and oddments."))
E.append(body("If you're unsure whether something is a regularly-stocked "
              "staple, ask the administrator."))
E.append(PageBreak())

# ================================================================ produce
# Either-order weighing: the station tells a scale reading from a PLU by what
# arrives (decimal pounds with an "lb" suffix vs. bare digits), so there is no
# mode to set and nothing for a volunteer to remember beyond "clear the
# platform."
E.append(kicker("PRODUCE SOLD BY WEIGHT"))
E += h1("Weighing produce — either order")
E.append(Paragraph(
    "Fruits and vegetables are often sold by weight rather than count, so a "
    "produce entry has two halves: the <b>PLU code</b> on the sticker and the "
    "<b>weight</b> from the scale. The station takes them in <b>either "
    "order</b> — do whichever is quicker with your hands full, and don't tell "
    "the app which you're doing. It works it out.", S["lead"]))

E.append(Paragraph("Scan the PLU first", S["h3"]))
E.append(body(
    "Scan the produce code. A <b>Weight required</b> window pops up and the "
    "station beeps:"))
E.append(bullet(
    "<b>Using the scale:</b> just set the item on the scale. If it's in "
    "“PC” mode (see the checklist) the weight fills in by itself "
    "— confirm and it saves."))
E.append(bullet(
    "<b>By hand:</b> type 1 digit for pounds, then 2 digits for ounces. "
    "Example: typing <b>514</b> means <b>5 lb 14 oz</b>. Backspace fixes a "
    "slip; press <b>Enter</b> or <b>Save Weight</b>."))

E.append(Paragraph("Or weigh it first", S["h3"]))
E.append(body(
    "Set the item on the scale and leave it alone. The scale sends nothing "
    "until the reading settles, and then it sends it on its own — you don't "
    "press anything. The station catches the weight, beeps, and opens a "
    "<b>Weighed — PLU required</b> window with the weight shown at the "
    "top. (This half needs the scale in “PC” mode — in "
    "<b>print</b> mode it only sends when you press <b>PRINT</b>.)"))
E.append(step(1, "Scan the PLU sticker (or type the code and press "
                 "<b>Enter</b>). The weight you already took and the code are "
                 "saved together as one line."))
E.append(step(2, "Don't know the code? Tap <b>Type a name instead</b> and "
                 "start typing the produce name — only items sold by the pound "
                 "are offered, so you can't accidentally pick something that "
                 "would throw the weight away. Pick a match and its PLU is "
                 "filled in for you."))
E.append(step(3, "Wrong item on the scale, or you'd rather start over? "
                 "<b>Discard Weight</b> throws the reading away and nothing is "
                 "recorded."))
E.append(warn("CLEAR THE PLATFORM AFTER EVERY ITEM",
    "Whichever order you use, take the item off the scale and let it return to "
    "<b>0</b> before the next one. The scale only arms itself to send again "
    "once it has been cleared — a platform left loaded is the usual reason a "
    "weight “doesn't come through.”"))
E.append(info("IF SOMETHING LOOKS OFF WITH A WEIGHT",
    "The station protects you from the two mistakes that matter. If the scale "
    "is set to <b>kilograms</b> (or grams or ounces) it <b>refuses</b> the "
    "reading and asks you to switch it to <b>lb</b>, rather than recording a "
    "number that's less than half the real weight. And if the scale sends the "
    "same weight twice for one item, the repeat is recognized and ignored — "
    "you won't get a duplicate line."))
E.append(PageBreak())

E.append(kicker("PACKAGED GOODS"))
E += h1("When the app doesn't recognize a barcode")
E.append(body(
    "Occasionally a packaged item isn't in the catalog. A box appears asking "
    "you to <b>Identify this item</b>. Type a short, plain generic name (e.g. "
    "“Canned Tuna”, not the brand) and tap <b>Save &amp; "
    "Record</b>. The app remembers it for next time. If you're unsure of the "
    "name, ask the administrator."))
E.append(warn("KEEP THE CURSOR IN THE BOX",
    "The scanner types like a keyboard, so the barcode box must stay selected "
    "(it glows green). If scans stop registering, tap once inside that box and "
    "try again."))
E.append(PageBreak())

# ================================================================ team scanning
# Two stations, one order. Ownership never moves: only the station that started
# the order can End or Cancel it.
E.append(kicker("TWO VOLUNTEERS, ONE SHOPPER"))
E += h1("Helping on someone else's order (Assist)")
E.append(Paragraph(
    "On a busy day two volunteers can check the same household out together — "
    "one working the wired scanner, one with a phone camera on the other side "
    "of the cart. Both sets of scans land in the <b>same order</b>, so the "
    "shopper is counted once.", S["lead"]))

E.append(Paragraph("Joining an order", S["h3"]))
E.append(step(1, "Open the <b>Scan</b> page on your device. If another station "
                 "already has an order going, a card appears titled "
                 "<b>Another station is scanning</b>, listing the order number, "
                 "when it started, and how many items are on it so far."))
E.append(step(2, "Tap <b>+ Assist</b> on the order you're helping with. The "
                 "top of your screen changes from <i>Current Order</i> to "
                 "<b>Assisting Order #…</b>."))
E.append(step(3, "Scan normally. Everything you scan goes onto the shared "
                 "order, and each screen shows the other's items within a "
                 "couple of seconds — no refreshing."))
E.append(step(4, "When you're done helping, tap <b>Leave Assist</b>. You "
                 "don't have to: when the other volunteer ends the order, your "
                 "station returns to idle by itself."))

E.append(Paragraph("Who ends the order", S["h3"]))
E.append(body(
    "The station that <b>started</b> the order owns it. Only that screen has "
    "<b>End Order</b> and <b>Cancel Order</b>; a helper sees <b>Leave "
    "Assist</b> in their place. That's deliberate — it keeps two people from "
    "closing the same shopper out twice, and stops a helper cancelling an "
    "order that isn't theirs. If you're helping and the shopper is finished, "
    "tell the volunteer who started it."))
E.append(bullet(
    "The starting station shows a <b>“1 assisting”</b> tag while "
    "you're helping, so they know you're on it."))
E.append(bullet(
    "Once two stations share an order, a <b>Who</b> column appears in the item "
    "list. Your own scans carry a filled badge, your teammate's an outlined "
    "one. On a single station the column stays hidden."))
E.append(bullet(
    "<b>Either</b> of you can remove a mis-scan with the red <b>×</b> — "
    "including a line the other person entered."))
E.append(info("YOU CAN'T ASSIST WITH YOUR OWN ORDER OPEN",
    "If your station already has an order going, finish it first — the app "
    "will say <i>“End this station's own order before assisting "
    "another.”</i> The Assist card only appears on a station that is "
    "idle, and a pantry running a single scanner never sees it at all."))
E.append(PageBreak())

# ================================================================ checklist 2
E += menu_counter_checklist()

E.append(kicker("ORDERS &amp; PICKING"))
E += h1("Running the Menu Counter")
E.append(Paragraph("Helping a shopper at the order form", S["h3"]))
E.append(bullet("Shoppers tap the food items they want; selected items "
                "highlight. Items marked unavailable show but can't be "
                "picked."))
E.append(bullet("Besides entering their first name, they enter how many adults "
                "and children are in the household — the app uses that to set "
                "quantities automatically."))
E.append(bullet("Some items ask for a size (e.g. diapers) — a dropdown appears "
                "when the item is selected."))
E.append(bullet("A translate button lets shoppers switch languages; the form "
                "returns to English after each order so the next person starts "
                "fresh."))
E.append(bullet("When they submit, a confirmation appears and the order drops "
                "into the pick queue in back."))

E.append(Paragraph("Picking an order in back", S["h3"]))
E.append(step(1, "New orders appear in the queue sidebar with a progress bar. "
                 "Tap one to open its list."))
E.append(step(2, "Gather each item and tap it to mark it picked (it turns "
                 "green with a check)."))
E.append(step(3, "Use <b>+</b> to add another of an item, or <b>×</b> to remove "
                 "one that's out of stock or entered accidentally."))
E.append(step(4, "Once every item is checked, tap <b>Mark Complete</b> to clear "
                 "the order from the queue."))
E.append(step(5, "The queue refreshes on its own every 30 seconds — no need to "
                 "reload."))
E.append(PageBreak())

# ================================================================ other channels
E.append(kicker("DELIVERIES &amp; EVENTS"))
E += h1("Other channels you may see")
E.append(body(
    "Some volunteers help with home <b>Deliveries</b> or <b>Events</b> "
    "(community meals). These use their own simple forms reached from the top "
    "menu under <b>Checkout</b>. The pattern is the same: pick the items and "
    "counts, then submit. A volunteer lead will show you the delivery rotation "
    "and printing steps — and remember that client names, addresses, and phone "
    "numbers are private (see below)."))

E.append(kicker("EVERY SHIFT"))
E += h1("Privacy &amp; closing up")
E.append(Paragraph("Protecting shopper privacy", S["h3"]))
E.append(bullet("Never share or write down a shopper's name, address, or phone "
                "number outside the app."))
E.append(bullet("Don't leave a logged-in tablet unattended where the public can "
                "reach the reports."))

E.append(Paragraph("Closing the station", S["h3"]))
E.append(Spacer(1, 4))
E.append(checkrow("Finish or cancel any order still open on the scanning "
                  "station (the top should read “not started”)."))
E.append(checkrow("If you were helping another station, tap <b>Leave "
                  "Assist</b> — or make sure the volunteer who started the "
                  "order has ended it."))
E.append(checkrow("Take the last item off the produce scale so it reads "
                  "<b>0</b>."))
E.append(checkrow("Clear any leftover orders from the Menu Counter pick "
                  "queue."))
E.append(checkrow("Log out (the <b>Log out</b> link, top right) if you're "
                  "leaving the tablet in a public spot and the admin is signed "
                  "into the app."))
E.append(checkrow("There is no need to power off the equipment, but make sure "
                  "the tablets are on their chargers."))
E.append(checkrow("Tell the administrator about anything odd that happened "
                  "during the shift."))
E.append(good("THANK YOU",
    "Every order you check out becomes data that helps the pantry order "
    "smarter and show donors the real need. Your careful scanning literally "
    "keeps the shelves stocked."))

doc.build(E)
print("Wrote", OUT)
