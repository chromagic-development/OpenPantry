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
from reportlab.platypus import Spacer, PageBreak

# Paragraph comes from handbook_common, not ReportLab: that one wraps the glyphs
# Arial lacks (see symbolize there) so the scale strip prints properly.
from handbook_common import (
    HERE, S, make_doc, cover, kicker, h1, body, bullet, step, Paragraph,
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
    revision="Revised October 2026 &nbsp;•&nbsp; Version 1.4")

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
    "break the data. <b>One exception:</b> if a red <b>Recalled Product</b> "
    "window fills the screen and an alarm sounds, don't wait for anyone — take "
    "that item out of the cart first, then carry on. See “When an item has "
    "been recalled.” And if a <b>Connection lost</b> window covers the screen "
    "with three falling notes, stop scanning and wait — see “When the "
    "connection drops.”"))

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
# The same refusal, met mid-shift instead of on opening the page: the station
# window (scan.php renderNetPrompt, kind 'refused') names the gate it hit.
E.append(bullet(
    "If the address changes <i>while</i> the Scan page is already open, you "
    "won't see the Access Denied screen. Instead a <b>The server refused this "
    "station</b> window comes up and says why — the network address, or the "
    "pantry's hours. It clears itself as soon as the address is fixed or the "
    "hours open; see “When the connection drops.”"))
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
# The two failures are worded to say opposite things on purpose (isDbBusyError
# in common.php decides which). A volunteer who can't tell them apart either
# gives up on an order that would close on the next tap, or taps End forever on
# one that never will — so the difference has to be taught, not just printed.
E.append(info("IF END OR CANCEL REFUSES",
    "Very occasionally a pop-up says the order could not be ended or "
    "cancelled. <b>Read which one it is.</b> If it says the database was "
    "<b>busy</b>, another station was writing at that moment — just tap "
    "<b>End Order</b> again and it will go through. If it says <b>trying "
    "again will not help</b>, stop tapping and fetch the administrator. "
    "Either way <b>nothing was changed</b>: the order is still open with every "
    "scan on it, exactly as it was. A close that fails leaves no mess behind."))

E.append(Paragraph("Adding an item by name (no barcode needed)", S["h3"]))
E.append(body(
    "No barcode, a barcode that won't scan, or loose produce with no sticker? "
    "You can add the item by typing its <b>name</b> instead. The entry box is "
    "labeled <b>Barcode or Item Name</b> — start typing and, after two "
    "letters, matching items from the catalog appear beneath it as you type. "
    "The item printed twice as large is the one <b>Enter</b> (or Tab) will "
    "record, exactly as if you'd scanned it — the best match, at the top, "
    "until you press the <b>up/down arrow keys</b> to move the large print to "
    "another item. You can also keep typing to narrow the list, or just tap "
    "an item. Typing a name never interferes with the scanner."))

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
    "top. (On an older cable-connected scale this half needs it in "
    "“PC” mode — in <b>print</b> mode it only sends when you "
    "press <b>PRINT</b>. A USB scale needs no setting at all.)"))
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
    "weight “doesn't come through.” You don't have to watch the screen "
    "for it. On a USB scale the station plays a <b>short soft tick</b> the "
    "moment the platform is clear and the last item's code is in, and that "
    "tick is your cue to set the next item down. It sounds once per item, and "
    "it is quieter and rounder than every other sound here so it won't be "
    "mistaken for one — work to it and you will never put an item on too "
    "early."))
E.append(info("IF SOMETHING LOOKS OFF WITH A WEIGHT",
    "The station protects you from the two mistakes that matter. If a "
    "cable-connected scale is set to <b>kilograms</b> (or grams or ounces) it "
    "<b>refuses</b> the reading and asks you to switch it to <b>lb</b>, rather "
    "than recording a number that's less than half the real weight. (A USB "
    "scale needs no such warning — it reports its own units and the station "
    "converts them.) And if a scale sends the same weight twice for one item, "
    "the repeat is recognized and ignored — you won't get a duplicate line."))

# The USB-scale strip is the one piece of on-screen scale UI a volunteer has to
# recognize, so give it its own page block rather than a footnote.
E.append(Paragraph("The scale strip", S["h3"]))
E.append(body(
    "If your station uses a <b>USB scale</b>, a short strip sits just above the "
    "barcode box and tells you what the scale is doing. You never have to touch "
    "it during a normal shift — glance at it only when a weight doesn't arrive."))
E.append(bullet("<b>⚖ Scale connected — waiting for a reading…</b> — the "
                "station has found the scale but hasn't heard from it yet. "
                "Normal for a second or two after opening the page; if it "
                "stays, the scale isn't switched on."))
E.append(bullet("<b>⚖ Scale ready</b> — nothing on the platform, waiting for "
                "the next item."))
E.append(bullet("<b>⚖ Weighing…</b> — the reading is still moving. Take your "
                "hand off and let it settle."))
E.append(bullet("<b>⚖ Settling…</b> — the reading has stopped moving and the "
                "station is confirming it holds. Usually well under a second, "
                "and it is deliberate: scales call a weight “steady” "
                "a moment before it really is, so the station waits rather "
                "than banking a number that is still creeping up. A weight "
                "that is still drifting holds here longer — that pause is the "
                "station protecting the number, not lagging."))
E.append(bullet("<b>⚖ Total weight at … lb.</b> — the weight is in. Scan the "
                "PLU, then clear the platform."))
E.append(bullet("<b>⚠ No PLU entered.</b> — you put another item on while the "
                "last weight was still waiting to be identified. See below."))
E.append(bullet("<b>⚖ Clear the platform to weigh the next item.</b> — "
                "something is still sitting on the scale from the item you "
                "just finished. Lift it off and the scale is ready again."))
E.append(bullet("<b>⚠ Put on too soon — lift the item off and set it down "
                "again.</b> — a new item landed on the platform before the "
                "scale had finished with the last one, so the moment the "
                "platform was clear went by unseen and <b>this item is not "
                "being weighed</b>. Nothing is wrong with the item or the "
                "scale, and waiting will not clear it: lift the item off, wait "
                "for the tick, and set it back down."))
E.append(bullet("<b>⚠ Make sure scale is on.</b> — see below."))
E.append(bullet("<b>⚠ Over capacity</b> — the item is too heavy for this "
                "scale. Take it off and weigh it in two batches."))
E.append(bullet("<b>⚠ Scale needs re-zeroing</b> — clear the platform and "
                "press the scale's own <b>Tare</b> or <b>Zero</b> key."))
E.append(bullet("<b>⚠ Scale is reporting an unrecognized unit</b> — the scale "
                "has been switched to a unit the station doesn't record in. "
                "Use its <b>Units</b> key to put it back to <b>lb</b> or "
                "<b>oz</b>."))
E.append(bullet("<b>⚠ Scale fault</b> — switch the scale off and on again. If "
                "it comes back, carry on; if not, tell the administrator."))
E.append(bullet("<b>⚠ Scale disconnected</b> — the USB cable came loose, or "
                "the scale lost power. Re-seat the cable."))
E.append(warn("ONE WEIGHT AT A TIME",
    "Once the scale has taken a weight, the station holds it until you enter "
    "the PLU or press <b>Discard Weight</b> — and it will not weigh anything "
    "else in the meantime. Put a second item on by mistake and nothing happens: "
    "no beep, and the strip reads <b>No PLU entered.</b> The weight on screen "
    "is still the first item's, so finish that one and it records correctly. "
    "The mistaken item is <b>ignored completely</b> — it will not pop up asking "
    "for a PLU afterwards. To weigh it for real, take it off the scale and put "
    "it back on."))
E.append(warn("“MAKE SURE SCALE IS ON.”",
    "Scales switch themselves off to save their batteries, and a scale that has "
    "gone to sleep looks exactly like one nobody has used. The station "
    "<b>beeps</b> and shows <b>Make sure scale is on.</b> in two cases: when it "
    "has heard nothing at all since you opened the page (about ten seconds in — "
    "the scale was never switched on), and when the weight hasn't changed for "
    "<b>three minutes</b> (it was on and has since gone to sleep). Switch it "
    "back on, or set something on the platform, and the message clears itself. "
    "Nothing is lost — it's a nudge, not an error, and it exists so you don't "
    "set produce on a dead scale and wonder why nothing happens."))
E.append(PageBreak())

E.append(kicker("PACKAGED GOODS"))
E += h1("When the app doesn't recognize a barcode")
E.append(body(
    "Occasionally a packaged item isn't in the catalog. A box appears asking "
    "you to <b>Identify this item</b>. Type a short, plain generic name (e.g. "
    "“Canned Tuna”, not the brand) and tap <b>Save &amp; "
    "Record</b>. The app remembers it for next time. If you're unsure of the "
    "name, ask the administrator."))
# Settings → Ignore Unknown Items. With it on there is no Identify window at
# all, which reads as a failed scan to anyone taught to expect one — so say
# plainly that the quiet version is a success, not a miss.
E.append(info("OR THE STATION MAY NAME IT “UNIDENTIFIED” AND MOVE ON",
    "Your pantry may have the station set to keep the line moving rather than "
    "stop to ask. If so, an item it can't name <b>doesn't</b> open the "
    "Identify box at all: it swoops once, records the item under the "
    "placeholder name <b>Unidentified</b>, and shows a short note saying so. "
    "<b>That is a finished scan, not a failed one</b> — the item is counted, "
    "so bag it and carry on. Nothing is lost either: the name is only put off, "
    "and it can be filled in later."))
E.append(body(
    "You may meet the same thing from the other side. Scan an item and the "
    "Identify box sometimes opens already headed <b>Unidentified Item</b>, "
    "which means this barcode was recorded without a name on some earlier "
    "shift. Name it exactly as you would any other — and every past scan of "
    "<i>that one barcode</i> is renamed along with it, which is why it is "
    "worth doing whenever the box offers."))
E.append(warn("KEEP THE CURSOR IN THE BOX",
    "The scanner types like a keyboard, so the barcode box must stay selected "
    "(it glows green). If scans stop registering, tap once inside that box and "
    "try again."))
# scan.php handleCount(): a number from 1 to 15 typed right after an item
# means "this many in all", so n-1 more identical rows are recorded.
E.append(Paragraph("Several of the same item", S["h3"]))
E.append(body(
    "A shopper taking six cans of the same beets? Scan (or add by name) "
    "<b>one</b> of them, then type how many there are <b>in all</b> — a "
    "number from <b>1 to 15</b> — and press <b>Enter</b>. The rest are added "
    "for you: scan one can of beets, type <b>6</b>, and the order shows six "
    "cans. The number counts the one you already scanned, so don't add one "
    "for it. It applies once, to the item you added last; for another batch, "
    "scan the next item first. Produce that is weighed can't be counted this "
    "way — weigh each one. Typed the wrong number? Tap the red <b>×</b> on any "
    "extra lines."))
E.append(PageBreak())

# ================================================================ recalls
# The one screen in this book a volunteer must act on before doing anything
# else, so it gets a page of its own rather than a callout inside another
# section. The block is enforced in lookupBarcode() on the server, which is why
# nothing is recorded, why re-scanning cannot get round it, and why it takes
# hold on every station the instant the box is ticked.
E.append(kicker("FOOD SAFETY"))
E += h1("When an item has been recalled")
E.append(Paragraph(
    "A recall is the one moment at this station where you act first and ask "
    "afterwards. If a product has been recalled — a contamination, an "
    "undeclared allergen, a packaging fault — it must not leave the pantry, "
    "and the station is built so that you cannot miss it.", S["lead"]))
E.append(body(
    "Scan a recalled item and everything stops. A <b>red window</b> fills the "
    "screen, pulsing, headed <b>Recalled Product</b>, and an alarm sounds: a "
    "two-tone siren about a second and a half long, deliberately longer and "
    "more insistent than any other sound the station makes, so it carries even "
    "if you are several feet away with your back to the screen. The window "
    "names the item, so you know which package in the cart it means."))
E.append(step(1, "<b>Take that item out of the cart now</b>, before anything "
                 "else. Don't bag it, and don't hand it back to the shopper."))
E.append(step(2, "Put it somewhere it can't be picked up again by mistake — "
                 "away from the cart and away from the shelves."))
E.append(step(3, "Tap <b>Item Removed</b> to close the window. The siren has "
                 "already stopped on its own by then; that button is you "
                 "confirming the package is out of the cart, which is why "
                 "nothing else dismisses the window — not tapping the "
                 "background, not pressing Escape. You land back in the "
                 "barcode box and the rest of the order carries on as normal."))
E.append(step(4, "Tell the administrator on duty before you finish your "
                 "shift. There are very likely more of them on the shelves."))
E.append(warn("IT WAS NOT RECORDED, AND SCANNING IT AGAIN WON'T HELP",
    "Nothing about a recalled item goes onto the order — it isn't counted "
    "and it isn't taken out of inventory, so there is nothing for you to "
    "undo. The last-scan line says <b>not recorded</b> for exactly that "
    "reason. Scanning it a second time simply raises the same alarm again: "
    "the block isn't on your screen, it's on the server, so it holds on every "
    "station at once. Only an administrator can lift a recall, from the "
    "Lookup Tables page."))
E.append(info("IF THE SHOPPER ASKS",
    "You don't have to explain the recall, and please don't guess at the "
    "reason. “This one has been recalled, so I can't let it go out — let "
    "me find you another” is the whole of it. If they want to know more, "
    "fetch the administrator."))
E.append(PageBreak())

# ================================================================ outages
# scan.php's connection watch (postJson → connectionLost). The window has no
# buttons on purpose, so the page teaches waiting; what a volunteer must learn is
# the difference between the two lists on the restored notice — "not recorded"
# means scan again, "may not have saved" means look first — because re-scanning
# the second kind blind counts the item twice.
E.append(kicker("IF THE INTERNET GOES DOWN"))
E += h1("When the connection drops")
E.append(Paragraph(
    "Every scan is saved on the pantry's server over the internet. If the "
    "station loses touch with the server, it stops you straight away rather "
    "than letting you scan items that quietly go nowhere.", S["lead"]))
E.append(body(
    "You'll know at once. A red <b>Connection lost</b> window covers the "
    "screen, headed <b>Connection problem — stop scanning</b>, and the station "
    "plays <b>three long falling notes</b> — slower and lower than the recall "
    "siren, and nothing like the short buzz of a rejected item. Within a few "
    "seconds the window says which of three things has gone wrong:"))
E.append(bullet("<b>No internet connection</b> — the problem is at the pantry: "
                "the Wi-Fi, a cable, or the router. Check the tablet is still on "
                "the pantry Wi-Fi and tell whoever looks after the router."))
E.append(bullet("<b>The pantry server isn't responding</b> — the pantry's "
                "internet is fine; the website itself is down. Restarting the "
                "router won't help. If it lasts more than a few minutes, tell "
                "the administrator."))
E.append(bullet("<b>The server refused this station</b> — the server is up but "
                "is turning this station away, because the pantry's network "
                "address has changed or scanning is outside the pantry's hours. "
                "A supervisor or administrator can fix the address in Settings "
                "(see “Getting on the stations”)."))
E.append(step(1, "<b>Stop scanning.</b> There is nothing to press — the window "
                 "has no buttons, because the only fix is the connection "
                 "coming back."))
E.append(step(2, "Anything scanned while the window is up is refused with a buzz "
                 "and <b>not recorded</b>. The window lists those barcodes; set "
                 "the items aside so you can scan them again."))
E.append(step(3, "The station checks the connection every few seconds on its "
                 "own. When it's back, the window lifts with <b>three rising "
                 "notes</b> and a green notice says <b>Connection restored — "
                 "you can scan again</b>. The <b>This Order</b> list is redrawn "
                 "from the server, so it shows exactly what was saved."))
E.append(step(4, "Read the green notice before you carry on. It stays up for "
                 "30 seconds when there is something on it."))
E.append(warn("“NOT RECORDED” AND “MAY NOT HAVE SAVED” ARE DIFFERENT",
    "The notice can list items two ways, and they need opposite handling. "
    "<b>NOT recorded, scan again</b> means the item certainly didn't reach the "
    "server — scan it again. <b>Check This Order for …</b> means the connection "
    "dropped while that item was being saved, so it may have landed. "
    "<b>Look for it in This Order first</b>, and scan it again only if it isn't "
    "there — scanning it blind could count it twice."))
E.append(info("NOTHING ALREADY SAVED IS LOST",
    "An outage never undoes scans that were saved before it. The order stays "
    "open on the server, with everything on it, and carries on once the "
    "connection returns. A weight, name, or recall window you hadn't finished "
    "when the line dropped is still there underneath when the window lifts — "
    "finish it then."))
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
E.append(step(4, "When the other volunteer ends the order, your screen shows "
                 "<b>Assist Mode — waiting for the next order to assist</b> and "
                 "joins their next order on its own."))
E.append(step(5, "Done helping for good? Tap <b>Leave Assist</b> — the only way "
                 "back to starting orders of your own."))
E.append(bullet(
    "While waiting between orders, scanning an item joins the next order and "
    "records it there. If nobody has started one yet, the scanner buzzes and "
    "nothing is recorded."))
E.append(bullet(
    "If your station has the <b>Assist</b> command barcode posted, scanning it "
    "does the same thing as tapping <b>+ Assist</b>, so you never have to "
    "touch the screen. It only works when exactly one other station has an "
    "order open — if two do, the barcode says so and you pick from the card."))

E.append(Paragraph("Who ends the order", S["h3"]))
E.append(body(
    "The station that <b>started</b> the order owns it. Only that screen has "
    "<b>End Order</b> and <b>Cancel Order</b>; a helper sees <b>Leave "
    "Assist</b> instead, so nobody closes a shopper out twice or cancels an "
    "order that isn't theirs. When the shopper is finished, tell the volunteer "
    "who started it."))
E.append(bullet(
    "The starting station shows a <b>“1 assisting”</b> tag while "
    "you're helping, so they know you're on it."))
E.append(bullet(
    "Once two stations share an order, a <b>Who</b> column appears in the item "
    "list. Your own scans carry a filled badge, your teammate's an outlined "
    "one. On a single station the column stays hidden."))
E.append(bullet(
    "While assisting, your device beeps on each scan (handy on a phone). The "
    "<b>Beep on each scan</b> switch turns it off, and is remembered."))
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
# submit_order.php applies index.php's network + hours wall, so a form sent
# after closing (or off the pantry Wi-Fi) lands on Access Denied, unrecorded.
E.append(bullet("If <b>Access Denied</b> or “Access is closed right now” "
                "appears <i>after</i> they press submit, the order was <b>not</b> "
                "recorded — usually because pantry hours ended while they were "
                "filling it in. Note what they asked for and let the volunteer "
                "in back know, rather than having them start over."))

E.append(Paragraph("Picking an order in back", S["h3"]))
E.append(step(1, "New orders appear in the queue sidebar with a progress bar. "
                 "Tap one to open its list."))
E.append(step(2, "Gather each item and tap it to mark it picked (it turns "
                 "green with a check)."))
E.append(step(3, "Use <b>+</b> to add another of an item, or <b>×</b> to remove "
                 "one that's out of stock or entered accidentally."))
E.append(step(4, "Once every item is checked, tap <b>Mark Complete</b> to clear "
                 "the order from the queue."))
E.append(step(5, "The queue refreshes on its own every 5 seconds — no need to "
                 "reload. The dot beside <b>Refresh</b> pulses green while it "
                 "does."))
# orders.php runs the same connection watch as the scan station (apiFetch →
# connectionLost), with "stop picking" wording and a reload from the server on
# reconnect instead of a This Order redraw.
E.append(info("IF THE PICK QUEUE LOSES ITS CONNECTION",
    "The pick queue watches its connection the same way the scanning station "
    "does. If it can't reach the server, the dot beside Refresh turns red, a "
    "<b>Connection problem — stop picking</b> window covers the screen with "
    "three falling notes, and it says whether it's the internet or the server. "
    "Stop ticking items — a tick made now would look saved and not be. If the "
    "server is up but refusing this computer (the pantry's network address "
    "changed, or it's outside pantry hours), the window says <b>The server "
    "refused this computer</b> instead. When "
    "the connection returns the window lifts with three rising notes and the "
    "picklist <b>reloads from the server</b>, so every check mark shows what "
    "was really saved. The green notice lists any tick, <b>+</b>, <b>×</b>, or "
    "<b>Mark Complete</b> that was <b>NOT saved</b> (do it again) or that "
    "<b>may have saved</b> (look before you redo it)."))
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
# print_packing_lists.php: deliveryWeightLabel(..., $eachFirst = true).
E.append(bullet("On a <b>Packing &amp; Delivery List</b>, produce can read "
                "<b>9 each or 3 lb</b>: pack either nine pieces or three pounds, "
                "whichever is easier. A line with only a weight, such as "
                "<b>1.5 lb</b>, goes on the scale."))

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
                  "<b>0</b>. If the scale strip says <b>Make sure scale is "
                  "on.</b>, that's fine at closing — it just went to sleep."))
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
