-- Names 91 UPCs that were sitting in upc_lookup under the 'Unidentified'
-- placeholder. Identified 2026-11-03/04 from Open Food Facts (and its pet / beauty /
-- products sister sites), UPCitemdb and go-upc; brand names replaced with the
-- pantry's generic names. brand_name keeps the product title that was found.
--
-- Mirrors the station's Identify window (api_scan.php): only rows still named
-- 'Unidentified' are touched, so a UPC someone has since named by hand keeps
-- that name. source becomes 'manual', updated_at is stamped.
--
-- Run against openpantry.db, e.g.:  sqlite3 openpantry.db < identify_unidentified_upcs.sql

BEGIN;

CREATE TEMP TABLE upc_names (upc TEXT PRIMARY KEY, generic_name TEXT NOT NULL, brand_name TEXT);
INSERT INTO upc_names (upc, generic_name, brand_name) VALUES
  ('888313914999', 'Hot Dogs', 'Nathan''s Beef Franks 12 oz'),
  ('884912116505', 'Breakfast Cereal', 'Post Great Grains Cereal 15.5 oz'),
  ('840243105212', 'Dog Food', 'Blue Buffalo Dog Food'),
  ('693804480934', 'Canned Cat Food', 'Tiki Cat Wet Cat Food'),
  ('74198608201', 'Bagged Dog Food', 'Diamond Naturals All Life Stages Dog Chicken & Rice'),
  ('73010631731', 'Tampons', 'L. Organic Cotton Tampons'),
  ('54000200427', 'Toilet Paper', 'Scott 1000 Sheets 1 Roll'),
  ('50000836123', 'Canned Cat Food', 'Fancy Feast Tuna Feast in Gravy 3 oz'),
  ('37000003823', 'Toothpaste', 'Kids Crest Cavity Protection Toothpaste'),
  ('30772094037', 'Dish Soap', 'Dawn Ultra Dishwashing Liquid'),
  ('30223081456', 'Beef Short Ribs with Mashed Potatoes', 'Hannaford Beef Short Ribs with Mashed Potatoes'),
  ('30111426024', 'Dog Food', 'Royal Canin Dog Food'),
  ('30111169051', 'Bagged Dog Food', 'Royal Canin Breed Health Nutrition Golden Retriever Adult Dry Dog Food'),
  ('23100103631', 'Bagged Dog Food - 3.15 lb', 'Pedigree Adult Roasted Chicken & Vegetables 3.15 lb'),
  ('23100019079', 'Canned Dog Food', 'Pedigree Chicken & Rice 13.2 oz'),
  ('23100017792', 'Canned Dog Food', 'Cesar Filet Mignon'),
  ('17800125994', 'Canned Dog Food', 'Purina ONE Healthy Puppy Lamb & Long Grain Rice Entree 13 oz'),
  ('10181042645', 'Body Lotion', 'Palmer''s Firming Body Lotion'),
  ('888641132560', 'Bagged Cat Food - 4 lb', 'Essence Ocean & Freshwater Grain-Free Dry Cat Food 4 lb'),
  ('886817011633', 'Canned Cat Food', 'Reveal Tuna Fillet with Mackerel Wet Cat Food 2.47 oz'),
  ('878408008306', 'Canned Cat Food', 'Cats in the Kitchen Kitty Gone Wild Salmon Au Jus 3.2 oz'),
  ('878408008252', 'Canned Cat Food', 'Weruva Cats in the Kitchen 1 Land 2 Sea 3 oz'),
  ('878408008214', 'Canned Cat Food', 'Weruva Pumpkin Chicken Cat Food 3 oz'),
  ('859610006007', 'Bagged Cat Food - 7 lb', 'Blue Buffalo Tastefuls Sensitive Stomach Dry Cat Food 7 lb'),
  ('852978008515', 'Cat Treats', 'Fruitables Cat Treats Tuna & Pumpkin 2.5 oz'),
  ('852301008687', 'Bagged Cat Food - 10 lb', 'Stella & Chewy''s Coated Cat Kibble 10 lb'),
  ('851320001150', 'Hair Conditioner', 'Pure Brazilian Anti Frizz Daily Conditioner 13.5 oz'),
  ('850046988486', 'Canned Cat Food', 'The Honest Kitchen Minced Salmon & Cod Cat Food 2.8 oz'),
  ('840243135639', 'Canned Cat Food', 'Blue Buffalo True Solutions Weight Control Wet Cat Food 3 oz'),
  ('840243101917', 'Canned Dog Food', 'Blue Buffalo Basics Grain Free Duck Wet Dog Food 12.5 oz'),
  ('816559011851', 'Hair Conditioner', 'Alberto VO5 Split End Repair Conditioner 1.5 oz'),
  ('810044056259', 'Dog Treats', 'Clifford Chicken Meatballs Dog Treats'),
  ('810028245198', 'Canned Cat Food', 'B.F.F. Kitten Chicken Dinner in Gravy 2.8 oz'),
  ('810028242784', 'Canned Cat Food', 'Weruva Kitten Chicken Au Jus 3 oz'),
  ('800443490365', 'Cat Harness', 'Youly Reflective Cat Harness & Leash Set'),
  ('794522003563', 'Green Tea Bags', 'Tazo Organic Green Ginger Tea 16 ct'),
  ('781723220226', 'Turkey Pop-Up Timer', 'Harold Import Pop-Up Turkey Timers 2 ct'),
  ('4895176106801', 'Turkey Pop-Up Timer', 'Mainstays Pop-Up Timer Set 4 ct'),
  ('741391973909', 'Green Tea Bags', 'Rishi Organic Jasmine Green Tea 15 ct'),
  ('741391973855', 'Black Tea Bags', 'Rishi Organic Earl Grey Tea 15 ct'),
  ('693804989840', 'Canned Cat Food', 'Tiki Cat Baby Chicken, Tuna & Chicken Liver 2.4 oz'),
  ('693804989833', 'Canned Cat Food', 'Tiki Cat Baby Chicken, Duck & Duck Liver Kitten 2.4 oz'),
  ('681131078962', 'Canned Cat Food', 'Special Kitty Chicken, Fish & Rice Pate 5.5 oz'),
  ('310158054757', 'Denture Adhesive', 'Super Poligrip Extra Care Denture Adhesive Cream 2.2 oz'),
  ('195515042673', 'Canned Dog Food', 'Wonder Bound Small Breed Loaf in Gravy Beef 3.5 oz'),
  ('194346284337', 'Wipes', 'Equate Extra Large Flushable Wet Wipes 144 ct'),
  ('191907877380', 'Chicken Breast Bites', 'Gourmet Specialty Foods Tandoori Chicken Breast Protein Bites 16 oz'),
  ('99482458522', 'Large Eggs', '365 Large White Eggs Grade A 12 ct'),
  ('79100923274', 'Dog Treats', 'Milk-Bone Maro Snacks 40 oz'),
  ('76344894520', 'Canned Dog Food', 'Wellness 95% Chicken Grain Free Wet Dog Food 13.2 oz'),
  ('76344089445', 'Canned Cat Food', 'Wellness Pate Turkey Dinner Cat Food 3 oz'),
  ('76344089438', 'Canned Cat Food', 'Wellness Complete Health Pate Beef & Chicken Cat Food'),
  ('76344089414', 'Canned Cat Food', 'Wellness Pate Chicken Entree Cat Food 3 oz'),
  ('76344036777', 'Canned Cat Food', 'Wellness Minced Turkey Entree Cat Food'),
  ('76344036647', 'Canned Cat Food', 'Wellness Minced Turkey Entree Grain Free Cat Food'),
  ('74198613557', 'Bagged Dog Food - 25 lb', 'Diamond Care Weight Management Adult Dog Food 25 lb'),
  ('74198612703', 'Bagged Dog Food - 14 lb', 'Taste of the Wild Small Breed Appalachian Valley 14 lb'),
  ('73657008682', 'Bagged Dog Food - 4 lb', 'Evolve Deboned Beef, Barley & Brown Rice Dog Food 4 lb'),
  ('73438005091', 'Eggnog', 'Oakhurst Rich and Creamy Eggnog 16 fl oz'),
  ('73010719675', 'Sanitary Pads', 'L. Super Ultra Thin Pads with Wings'),
  ('72705116416', 'Bagged Dog Food - 26 lb', 'Fromm Four Star Salmon A La Veg Dry Dog Food 26 lb'),
  ('72579000064', 'Black Beans', 'Clover Valley Black Beans 16 oz'),
  ('72071061853', 'Vegetable Oil', 'La Spagnola Soybean Salad Oil'),
  ('71190480309', 'Bagged Cat Food - 3.15 lb', '9Lives Plus Care Urinary Tract 3.15 lb'),
  ('71190478436', 'Bagged Cat Food - 3.15 lb', '9Lives Chicken, Beef & Salmon Cat Food 50.4 oz'),
  ('71190006875', 'Canned Dog Food', 'Rachael Ray Nutrish Beef Stroganwoof Wet Dog Food 8 oz'),
  ('71190006745', 'Canned Dog Food', 'Rachael Ray Nutrish Chicken Paw Pie Wet Dog Food 8 oz'),
  ('71190003607', 'Bagged Cat Food - 3 lb', 'Rachael Ray Nutrish Indoor Complete Dry Cat Food 3 lb'),
  ('52742869902', 'Bagged Cat Food - 8.5 lb', 'Hill''s Prescription Diet m/d Dry Cat Food 8.5 lb'),
  ('52742217406', 'Bagged Dog Food - 6 lb', 'Hill''s Science Diet Advanced Fitness Adult Dog Food 6 lb'),
  ('52742069432', 'Bagged Cat Food - 3.5 lb', 'Hill''s Science Diet Kitten Sensitive Stomach & Skin Salmon & Brown Rice 3.5 lb'),
  ('52100004525', 'Italian Seasoning', 'McCormick Gourmet Organic Italian Seasoning 0.55 oz'),
  ('46706303519', 'Bird Food', 'Higgins InTune Duets Chickpeas & Bananas for Parrots 3 oz'),
  ('41564007012', 'Canned Shelled Beans', 'Stewart''s Shelled Beans 15.5 oz'),
  ('41520868282', 'Light Bulbs', 'Smart Living 3-Way Soft White Light Bulb 50/100/150W'),
  ('40000599937', 'Candy', 'Mars Halloween Chocolate Candy Variety Bag'),
  ('38100027030', 'Canned Cat Food', 'Purina Pro Plan Urinary Tract Chicken Entree 3 oz'),
  ('35000689115', 'Toothbrushes', 'Colgate MaxFresh Wisp Disposable Mini Toothbrush 4 ct'),
  ('33200003069', 'Laundry Detergent', 'Arm & Hammer Clean Burst 5-in-1 Laundry Power Paks 14 ct'),
  ('33200001737', 'Laundry Detergent', 'Arm & Hammer Plus OxiClean Odor Blasters Laundry Pods 42 ct'),
  ('32700129545', 'Cat Treats', 'Delectables Chicken, Duck & Cheese Cat Treats'),
  ('30772158104', 'Kids Diapers (Wings) 3', 'Luvs Platinum Protection Diapers Size 3 32 ct'),
  ('30111477132', 'Canned Dog Food', 'Royal Canin Veterinary Diet Renal Support D Dog Food 13 oz'),
  ('23100110257', 'Canned Cat Food', 'Sheba Perfect Portions Beef & Chicken Pate 2.64 oz'),
  ('22808004868', 'Canned Dog Food', 'Merrick Grain Free Real Texas Beef Dinner 12.7 oz'),
  ('22517447130', 'Canned Cat Food', 'Catit Dinner Ocean Fish with Tilapia & Potato'),
  ('17800178709', 'Bagged Dog Food - 36 lb', 'Purina ONE True Instinct Salmon & Tuna Dry Dog Food 36 lb'),
  ('17800102261', 'Bagged Cat Food - 3.5 lb', 'Purina ONE Plus Salmon & Ocean Whitefish Dry Cat Food 3.5 lb'),
  ('11822308779', 'Toothbrush and Toothpaste Kit', 'Colgate Total Travel Toothbrush & Toothpaste 2 Pack'),
  ('11111613348', 'Bar Soap', 'Dove Gentle Exfoliating Beauty Bar 3.75 oz'),
  ('693804480941', 'Canned Cat Food', 'Tiki Cat Baby Chicken & Egg Kitten Food 2.4 oz');

UPDATE upc_lookup
   SET generic_name = (SELECT n.generic_name FROM upc_names n WHERE n.upc = upc_lookup.upc),
       brand_name   = (SELECT n.brand_name   FROM upc_names n WHERE n.upc = upc_lookup.upc),
       source       = 'manual',
       updated_at   = datetime('now', 'localtime')
 WHERE generic_name = 'Unidentified'
   AND upc IN (SELECT upc FROM upc_names);

-- Optional, but it is what the Identify window does too: move each UPC's own
-- past scans off the placeholder onto its new name, so the demand already
-- recorded lands on the real item. Scoped by barcode, never by name. Delete
-- this statement if you want only upc_lookup changed.
UPDATE scans
   SET generic_name = (SELECT n.generic_name FROM upc_names n WHERE n.upc = scans.barcode)
 WHERE generic_name = 'Unidentified'
   AND barcode IN (SELECT upc FROM upc_names);

DROP TABLE upc_names;

COMMIT;
