// HamaraService catalog — extracted from hs-prices.html (source of truth)
var SVC=[
  {id:'SVC001',name:'House Maid',icon:'🧹',cat:'Home Cleaning',base:129,groups:[
    {key:'task',title:'Select Tasks',style:'label',items:[
      {k:'sweep',  ico:'🧹',name:'Sweeping & Mopping'},
      {k:'dust',   ico:'🪣',name:'Dusting'},
      {k:'dishes', ico:'🍽️',name:'Dishwashing'},
      {k:'clothes',ico:'👗',name:'Folding Clothes'},
      {k:'laundry',ico:'👚',name:'Laundry (Washing)'}
    ]},
    {key:'sweep',title:'🧹 Home Size (for Sweeping & Mopping)',style:'bhk',showOn:'sweep',items:[
      {k:'studio',name:'Studio / 1 Room',  p:79},
      {k:'1bhk',  name:'1 BHK',           p:129},
      {k:'2bhk',  name:'2 BHK',           p:199},
      {k:'3bhk',  name:'3 BHK',           p:279},
      {k:'4bhk',  name:'4 BHK',           p:369},
      {k:'villa', name:'Villa / Bungalow', p:499}
    ]},
    {key:'dust',title:'🪣 Home Size (for Dusting)',style:'bhk',showOn:'dust',items:[
      {k:'studio',name:'Studio / 1 Room',  p:99},
      {k:'1bhk',  name:'1 BHK',           p:149},
      {k:'2bhk',  name:'2 BHK',           p:249},
      {k:'3bhk',  name:'3 BHK',           p:349},
      {k:'4bhk',  name:'4 BHK',           p:449},
      {k:'villa', name:'Villa / Bungalow', p:599}
    ]},
    {key:'dishes',title:'🍽️ Dishwashing — Occasion / Purpose',style:'bhk',showOn:'dishes',items:[
      {k:'daily',   name:'Daily (home)',                    p:99},
      {k:'people',  name:'Small gathering (10–20 people)',  p:199},
      {k:'event',   name:'Party / Event (20–50 people)',    p:399},
      {k:'marriage',name:'Marriage / Big function (50+ people)', p:799}
    ]},
    {key:'clothes',title:'👗 Folding Clothes — Pricing',style:'info',showOn:'clothes',
     info:'₹2 per cloth, minimum ₹50. Price calculated per item on service page.'},
    {key:'laundry',title:'👚 Laundry (Washing) — Pricing',style:'info',showOn:'laundry',
     info:'Base ₹149 for up to 10 items. +₹12 per extra item above 10.'}
  ]},
  {id:'SVC002',name:'Deep Cleaning',icon:'🧽',cat:'Home Cleaning',base:799,groups:[
    {key:'bhk',title:'🧴 With Material & Equipment — Price per Home Size',style:'bhk',items:[
      {k:'studio',name:'Studio / 1 Room',    p:599},
      {k:'1bhk',  name:'1 BHK',              p:799},
      {k:'2bhk',  name:'2 BHK',              p:1199},
      {k:'3bhk',  name:'3 BHK',              p:1599},
      {k:'4bhk',  name:'4 BHK+',             p:1999},
      {k:'rooms', name:'Individual Rooms',   p:399}
    ]},
    {key:'extras',title:'Optional Add-ons',style:'task',items:[
      {k:'mattress',   ico:'🛏️',name:'Mattress Shampooing',  p:249},
      {k:'carpet',     ico:'🟫',name:'Carpet Cleaning',       p:299},
      {k:'sofashampoo',ico:'🛋️',name:'Sofa Shampoo',         p:399},
      {k:'balcony',    ico:'🏠',name:'Balcony Cleaning',      p:149},
      {k:'watertank',  ico:'💧',name:'Water Tank Cleaning',   p:499},
      {k:'pest',       ico:'🪲',name:'Pest Control',          p:699}
    ]},
    {key:'condition',title:'Home Condition Surcharge',style:'bhk',items:[
      {k:'regular',   name:'Regularly Maintained', p:0},
      {k:'moderate',  name:'Moderately Dirty',     p:0},
      {k:'dirty',     name:'Very Dirty',           p:150},
      {k:'renovation',name:'Post-Renovation',      p:399}
    ]}
  ]},
  {id:'SVC003',name:'Bathroom Cleaning',icon:'🚿',cat:'Home Cleaning',base:299,groups:[
    {key:'with',title:'🧴 With Material & Equipment — Price per Bathroom',style:'bhk',items:[
      {k:'perBath',name:'Per Bathroom',p:299}
    ]},
    {key:'addon',title:'Add-ons',style:'task',items:[
      {k:'bathtub',ico:'🛁',name:'Bathtub Deep Clean',  p:149},
      {k:'shower', ico:'🚿',name:'Glass Shower Cabin',  p:99}
    ]},
    {key:'condition',title:'Condition Surcharge',style:'bhk',items:[
      {k:'normal',  name:'Normal',            p:0},
      {k:'moderate',name:'Moderately Dirty',  p:0},
      {k:'dirty',   name:'Very Dirty',        p:79},
      {k:'mold',    name:'Mold / Fungus',     p:119}
    ]}
  ]},
  {id:'SVC004',name:'Kitchen Cleaning',icon:'🍳',cat:'Home Cleaning',base:149,groups:[
    {key:'with',title:'🧴 With Material & Equipment — Price per Service',style:'task',items:[
      {k:'tiles',   ico:'🧱',name:'Degreasing Tiles & Walls',     p:149},
      {k:'cabinets',ico:'🗄️',name:'Cabinets (inside & outside)',  p:199},
      {k:'chimney', ico:'🏭',name:'Chimney Exterior',             p:149},
      {k:'sink',    ico:'🚰',name:'Sink & Tap Descaling',         p:99},
      {k:'stove',   ico:'🔥',name:'Stove & Countertop',           p:199},
      {k:'floor',   ico:'🧹',name:'Floor Scrubbing',              p:99}
    ]},
    {key:'grease',title:'Grease Level Surcharge',style:'bhk',items:[
      {k:'light',name:'Light / Regular',                          p:0},
      {k:'heavy',name:'Heavy / Months without cleaning',          p:99}
    ]}
  ]},
  {id:'SVC005',name:'Sofa / Carpet Cleaning',icon:'🛋️',cat:'Home Cleaning',base:299,groups:[
    {key:'sofa',title:'🛋️ Sofa Cleaning',style:'bhk',items:[
      {k:'2seater',name:'2-Seater Sofa',         p:299},
      {k:'3seater',name:'3-Seater Sofa',         p:399},
      {k:'5seater',name:'5-Seater / L-Shape',    p:599},
      {k:'recliner',name:'Per Recliner',         p:199}
    ]},
    {key:'carpet',title:'🟫 Carpet Cleaning',style:'bhk',items:[
      {k:'small',  name:'Small Carpet (up to 5x8 ft)',  p:249},
      {k:'medium', name:'Medium Carpet (5x8 to 8x10)', p:349},
      {k:'large',  name:'Large Carpet (above 8x10)',    p:499}
    ]}
  ]},
  {id:'SVC006',name:'Laundry / Ironing',icon:'👕',cat:'Home Cleaning',base:149,groups:[
    {key:'wash',title:'👗 Wash — Base Price',style:'bhk',items:[
      {k:'basePrice',name:'Per 10 clothes (base)',p:149},
      {k:'extraPer', name:'Per extra item (above 10)',p:12}
    ]},
    {key:'iron',title:'🔥 Ironing — Base Price',style:'bhk',items:[
      {k:'basePrice',name:'Per 10 clothes (base)',p:99},
      {k:'extraPer', name:'Per extra item (above 10)',p:10}
    ]},
    {key:'dry',title:'💨 Dry Service — Per Visit',style:'bhk',items:[
      {k:'perVisit',name:'Per visit',p:79}
    ]},
    {key:'fold',title:'👕 Fold Service — Per Visit',style:'bhk',items:[
      {k:'perVisit',name:'Per visit',p:79}
    ]},
    {key:'handwash',title:'✋ Handwash — Per Visit',style:'bhk',items:[
      {k:'perVisit',name:'Per visit',p:99}
    ]}
  ]},
  {id:'SVC007',name:'AC Cleaning & Repair',icon:'❄️',cat:'Home Services',base:299,groups:[
    {key:'clean',title:'❄️ AC Cleaning',style:'bhk',items:[
      {k:'split',    name:'Split AC Cleaning (1 unit)',    p:299},
      {k:'window',   name:'Window AC Cleaning',           p:249},
      {k:'cassette', name:'Cassette / Ceiling AC',        p:399},
      {k:'deepclean',name:'Deep Service (filter+coil)',   p:499}
    ]},
    {key:'repair',title:'🔧 AC Repair / Service',style:'bhk',items:[
      {k:'visit',     name:'Visit fee (diagnosis)',        p:199},
      {k:'gas',       name:'Gas Refilling (per unit)',     p:799},
      {k:'compressor',name:'Compressor Check',            p:299},
      {k:'pcb',       name:'PCB / Electrical Repair',     p:499}
    ]}
  ]},
  {id:'SVC009',name:'Home Appliance Repair',icon:'🔌',cat:'Home Services',base:199,groups:[
    {key:'visit',title:'🏠 Visit / Diagnosis Fee',style:'bhk',items:[
      {k:'visit',name:'Visit fee (diagnosis included)',p:199}
    ]},
    {key:'appliance',title:'Appliance Type (surcharge)',style:'bhk',items:[
      {k:'washingmachine',name:'Washing Machine',p:0},
      {k:'fridge',        name:'Refrigerator',  p:0},
      {k:'microwave',     name:'Microwave Oven',p:0},
      {k:'geyser',        name:'Water Heater',  p:0},
      {k:'dishwasher',    name:'Dishwasher',    p:99}
    ]}
  ]},
  {id:'SVC010',name:'Water Purifier Service',icon:'💧',cat:'Home Services',base:199,groups:[
    {key:'visit',title:'🏠 Visit / Service Fee',style:'bhk',items:[
      {k:'service', name:'Regular Service / Cleaning',      p:199},
      {k:'filter',  name:'Filter Replacement (per filter)', p:299},
      {k:'install', name:'New Installation',                p:499},
      {k:'repair',  name:'Repair Visit',                    p:249}
    ]}
  ]},
  {id:'SVC011',name:'Plumber',icon:'🪠',cat:'Home Services',base:149,groups:[
    {key:'visit',title:'🏠 Visit / Call-out Fee',style:'bhk',items:[
      {k:'visit',name:'Visit fee (minor repairs included)',p:149}
    ]},
    {key:'work',title:'Work Type (additional charges)',style:'bhk',items:[
      {k:'leakfix',   name:'Pipe Leak Fix',          p:0},
      {k:'tapfix',    name:'Tap / Faucet Repair',    p:0},
      {k:'blockage',  name:'Drain Unblocking',       p:99},
      {k:'toiletfix', name:'Toilet / Flush Repair',  p:0},
      {k:'newfit',    name:'New Pipe / Fitting',     p:149},
      {k:'motor',     name:'Motor / Pump Issue',     p:199}
    ]}
  ]},
  {id:'SVC012',name:'Electrician',icon:'⚡',cat:'Home Services',base:149,groups:[
    {key:'visit',title:'🏠 Visit / Call-out Fee',style:'bhk',items:[
      {k:'visit',name:'Visit fee (minor work included)',p:149}
    ]},
    {key:'work',title:'Work Type (additional charges)',style:'bhk',items:[
      {k:'switch',    name:'Switch / Socket Fix',    p:0},
      {k:'fan',       name:'Fan Installation / Fix', p:99},
      {k:'light',     name:'Light Fitting',          p:0},
      {k:'mcb',       name:'MCB / Short Circuit',    p:149},
      {k:'wiring',    name:'New Wiring (per point)', p:199},
      {k:'inverter',  name:'Inverter / UPS Setup',   p:299}
    ]}
  ]},
  {id:'SVC013',name:'Carpenter',icon:'🪚',cat:'Home Services',base:199,groups:[
    {key:'visit',title:'🏠 Visit / Call-out Fee',style:'bhk',items:[
      {k:'visit',name:'Visit fee (inspection included)',p:199}
    ]},
    {key:'work',title:'Work Type (per job)',style:'bhk',items:[
      {k:'doorfix',   name:'Door / Window Fix',       p:0},
      {k:'furnifix',  name:'Furniture Repair',        p:0},
      {k:'lock',      name:'Lock / Hinge Replacement',p:99},
      {k:'new',       name:'New Furniture Work',      p:299},
      {k:'wardrobefix',name:'Wardrobe Repair',        p:149}
    ]}
  ]},
  {id:'SVC014',name:'Painter',icon:'🎨',cat:'Home Services',base:499,groups:[
    {key:'rooms',title:'🖌️ Painting — Price per Room',style:'bhk',items:[
      {k:'pricePerRoom',name:'Per room (basic distemper)',p:499},
      {k:'emulsion',    name:'Per room (emulsion paint)', p:699},
      {k:'texture',     name:'Per room (texture paint)',  p:999},
      {k:'exterior',    name:'Exterior (per sq ft)',      p:12}
    ]},
    {key:'condition',title:'Wall Condition',style:'bhk',items:[
      {k:'good',       name:'Good condition',              p:0},
      {k:'moderate',   name:'Cracks / Patches needed',    p:99},
      {k:'bad',        name:'Heavy repair needed',        p:199}
    ]}
  ]},
  {id:'SVC015',name:'CCTV Installation',icon:'📹',cat:'Home Services',base:399,groups:[
    {key:'visit',title:'🏠 Visit / Installation Fee',style:'bhk',items:[
      {k:'visit',    name:'Visit fee (1 camera setup)',     p:399},
      {k:'percam',   name:'Per additional camera',          p:199},
      {k:'dvr',      name:'DVR / NVR Setup',                p:299},
      {k:'repair',   name:'Repair / Reconfigure visit',     p:249}
    ]}
  ]},
  {id:'SVC016',name:'Solar Panel Cleaning',icon:'☀️',cat:'Home Services',base:199,groups:[
    {key:'visit',title:'🏠 Cleaning Fee',style:'bhk',items:[
      {k:'upto4',    name:'Up to 4 panels',    p:199},
      {k:'upto8',    name:'5–8 panels',        p:299},
      {k:'upto12',   name:'9–12 panels',       p:399},
      {k:'above12',  name:'Above 12 panels',   p:499}
    ]}
  ]},
  {id:'SVC017',name:'Car / Bike Wash',icon:'🚗',cat:'Vehicle Care',base:149,groups:[
    {key:'car',title:'🚗 Car Wash Prices',style:'bhk',items:[
      {k:'exterior', name:'Exterior Only (Hatchback)',  p:149},
      {k:'interior', name:'Interior Cleaning',          p:199},
      {k:'full',     name:'Full Wash (Ext + Int)',       p:299},
      {k:'premium',  name:'Premium Detailing',          p:499},
      {k:'engine',   name:'Engine Bay Cleaning',        p:249}
    ]},
    {key:'bike',title:'🏍️ Bike Wash Prices',style:'bhk',items:[
      {k:'exterior',    name:'Bike Exterior Wash',      p:79},
      {k:'full',        name:'Full Bike Wash',          p:129},
      {k:'chain',       name:'Chain Cleaning & Lube',   p:79},
      {k:'premium',     name:'Bike Detailing',          p:249}
    ]},
    {key:'cartype',title:'Car Size Surcharge',style:'bhk',items:[
      {k:'hatchback',name:'Hatchback (base)',          p:0},
      {k:'sedan',    name:'Sedan',                     p:49},
      {k:'suv',      name:'SUV / MUV',                 p:99},
      {k:'largsuv',  name:'Large SUV / XUV',           p:149},
      {k:'luxury',   name:'Luxury / Imported',         p:249}
    ]}
  ]},
  {id:'SVC019',name:'Car & Bike Mechanic',icon:'🔧',cat:'Vehicle Care',base:199,groups:[
    {key:'visit',title:'🏠 Visit / Diagnosis Fee',style:'bhk',items:[
      {k:'car',  name:'Car visit fee (diagnosis)',    p:199},
      {k:'bike', name:'Bike visit fee (diagnosis)',   p:149}
    ]},
    {key:'service',title:'Service Type (additional)',style:'bhk',items:[
      {k:'oilchange',  name:'Oil & Filter Change',    p:149},
      {k:'brake',      name:'Brake Inspection / Fix', p:99},
      {k:'tyrefit',    name:'Tyre Puncture / Fit',    p:79},
      {k:'battery',    name:'Battery Check / Jump',   p:99},
      {k:'general',    name:'General Service',        p:299}
    ]}
  ]},
  {id:'SVC021',name:'Pest Control',icon:'🪲',cat:'Pest Control',base:599,groups:[
    {key:'size',title:'🏠 Home Size',style:'bhk',items:[
      {k:'studio',  name:'Studio / 1 Room',    p:399},
      {k:'1bhk',    name:'1 BHK',              p:599},
      {k:'2bhk',    name:'2 BHK',              p:799},
      {k:'3bhk',    name:'3 BHK',              p:999},
      {k:'4bhk',    name:'4 BHK+',             p:1299},
      {k:'villa',   name:'Villa / Bungalow',   p:1599}
    ]},
    {key:'pest',title:'Pest Type',style:'bhk',items:[
      {k:'cockroach',name:'Cockroach',          p:0},
      {k:'mosquito', name:'Mosquito / Flies',   p:0},
      {k:'termite',  name:'Termite',            p:199},
      {k:'rats',     name:'Rats / Rodents',     p:149},
      {k:'bedbugs',  name:'Bed Bugs',           p:299},
      {k:'all',      name:'Comprehensive',      p:299}
    ]}
  ]},
  {id:'SVC022',name:'Cook / Cooking Person',icon:'👨‍🍳',cat:'Cooking',base:249,groups:[
    {key:'svc',title:'Service Type',style:'task',items:[
      {k:'breakfast',ico:'🌅',name:'Breakfast Cooking',       p:249},
      {k:'lunch',    ico:'☀️',name:'Lunch Cooking',           p:299},
      {k:'dinner',   ico:'🌙',name:'Dinner Cooking',          p:279},
      {k:'twotime',  ico:'🍽️',name:'Two Meals (Lunch+Dinner)',p:499},
      {k:'fullday',  ico:'🏠',name:'Full Day Cook (3 Meals)', p:699},
      {k:'party',    ico:'🎉',name:'Party / Function',        p:1299},
      {k:'tiffin',   ico:'📦',name:'Tiffin (5 days)',         p:2499},
      {k:'monthly',  ico:'📅',name:'Monthly Cook',            p:6999}
    ]},
    {key:'people',title:'People Surcharge',style:'bhk',items:[
      {k:'p2',name:'1–2 people',p:0},
      {k:'p4',name:'3–4 people',p:79},
      {k:'p6',name:'5–6 people',p:149},
      {k:'p7',name:'7+ people', p:249}
    ]},
    {key:'style',title:'Cooking Style Surcharge',style:'bhk',items:[
      {k:'regular',   name:'Regular Home Style',p:0},
      {k:'restaurant',name:'Restaurant Style',  p:99}
    ]}
  ]},
  {id:'SVC034',name:'Security Guard & Bouncers',icon:'🛡️',cat:'Security',base:799,groups:[
    {key:'type',title:'Guard / Bouncer Type',style:'bhk',items:[
      {k:'daytime',      name:'Daytime Guard (8-hr shift)',    p:799},
      {k:'nighttime',    name:'Night Guard (8-hr shift)',      p:899},
      {k:'fullday',      name:'Full Day Guard (12-hr shift)',  p:1499},
      {k:'event',        name:'Event Security Guard',          p:1099},
      {k:'office',       name:'Office / Shop Security',        p:799},
      {k:'bouncer_event',name:'Event Bouncer',                 p:1099},
      {k:'bouncer_pub',  name:'Pub / Bar / Club Bouncer',      p:1299},
      {k:'bouncer_vip',  name:'VIP / Personal Bouncer',        p:1599}
    ]}
  ]},
  {id:'SVC032',name:'Gardener',icon:'🌱',cat:'Outdoor',base:299,groups:[
    {key:'visit',title:'🌿 Gardening Service',style:'bhk',items:[
      {k:'visit',    name:'Visit fee (basic work)',          p:299},
      {k:'trimming', name:'Trimming & Pruning',              p:199},
      {k:'planting', name:'New Plant Arrangement',          p:249},
      {k:'lawn',     name:'Lawn Mowing (per 1000 sq ft)',   p:349},
      {k:'monthly',  name:'Monthly Maintenance',            p:1499}
    ]}
  ]},
  {id:'SVC033',name:'Driver',icon:'🚕',cat:'Outdoor',base:249,groups:[
    {key:'car',title:'🚗 Car Driver',style:'bhk',items:[
      {k:'local',     name:'Local / City Trips (4 hrs)',  p:249},
      {k:'fullday',   name:'Full Day Driver (8 hrs)',     p:599},
      {k:'outstation',name:'Outstation / Long Trip',      p:899},
      {k:'monthly',   name:'Monthly Regular Driver',      p:7999},
      {k:'event',     name:'Event / Wedding Driver',      p:699}
    ]},
    {key:'auto',title:'🛺 Auto Driver',style:'bhk',items:[
      {k:'local',  name:'Local Auto Driving (4 hrs)',     p:149},
      {k:'fullday',name:'Full Day Auto Driver',           p:399},
      {k:'monthly',name:'Monthly Auto Driver',            p:4999}
    ]},
    {key:'tempo',title:'🚐 Tempo / Mini Truck Driver',style:'bhk',items:[
      {k:'shifting',  name:'Goods Shifting / Moving',     p:599},
      {k:'local',     name:'Local Driving',               p:399},
      {k:'outstation',name:'Outstation Trip',             p:1099},
      {k:'fullday',   name:'Full Day Driver',             p:799}
    ]},
    {key:'truck',title:'🚛 Truck Driver',style:'bhk',items:[
      {k:'shifting',  name:'House / Office Shifting',     p:1099},
      {k:'goods',     name:'Goods Transport',             p:899},
      {k:'outstation',name:'Outstation Trip',             p:1599},
      {k:'fullday',   name:'Full Day Truck Driver',       p:1299}
    ]},
    {key:'bus',title:'🚌 Bus Driver',style:'bhk',items:[
      {k:'event',     name:'Event / Function Bus',        p:1099},
      {k:'staff',     name:'Staff Pickup & Drop',         p:899},
      {k:'outstation',name:'Outstation Bus Trip',         p:1799},
      {k:'fullday',   name:'Full Day Bus Driver',         p:1499}
    ]},
    {key:'tractor',title:'🚜 Tractor Driver',style:'bhk',items:[
      {k:'farm',    name:'Farm / Agricultural Work',      p:699},
      {k:'goods',   name:'Goods / Material Transport',    p:599},
      {k:'fullday', name:'Full Day Tractor Driver',       p:899}
    ]}
  ]},
  {id:'SVC026',name:'Gym / Fitness Trainer',icon:'💪',cat:'Beauty & Wellness',base:399,groups:[
    {key:'session',title:'💪 Session Type',style:'bhk',items:[
      {k:'single',  name:'Single Session (1 hr)',         p:399},
      {k:'monthly', name:'Monthly (5 days/week)',         p:3999},
      {k:'threemonth',name:'3 Months Package',           p:9999},
      {k:'yoga',    name:'Yoga / Stretching (1 hr)',      p:299},
      {k:'diet',    name:'Diet Plan Consultation',        p:499}
    ]}
  ]},
  {id:'SVC023',name:"Men's Haircut at Home",icon:'✂️',cat:'Beauty & Wellness',base:149,groups:[
    {key:'svc',title:'Select Service',style:'task',items:[
      {k:'haircut',  ico:'✂️',name:'Haircut',           p:149},
      {k:'fade',     ico:'💈',name:'Fade / Taper Cut',  p:199},
      {k:'layer',    ico:'🎨',name:'Layer / Designer',  p:249},
      {k:'kids',     ico:'👦',name:'Kids Cut',          p:99},
      {k:'shave',    ico:'🪒',name:'Clean Shave',       p:99},
      {k:'beardtrim',ico:'🧔',name:'Beard Trim',        p:79},
      {k:'beardline',ico:'🧔',name:'Beard Shape & Line',p:149},
      {k:'bearddes', ico:'👑',name:'Designer Beard',    p:249},
      {k:'colblack', ico:'⚫',name:'Colour — Black',    p:299},
      {k:'colfash',  ico:'🎨',name:'Colour — Fashion',  p:499},
      {k:'highlights',ico:'✨',name:'Highlights',       p:699},
      {k:'massage',  ico:'💆',name:'Head Massage',      p:149},
      {k:'cleanup',  ico:'🧴',name:'Face Cleanup',      p:199},
      {k:'combo',    ico:'⭐',name:'Grooming Combo',    p:599},
      {k:'visit',    ico:'🏠',name:'Visit fee (shown on page)',p:49}
    ]}
  ]},
  {id:'SVC024',name:"Women's Haircut & Beauty",icon:'💇',cat:'Beauty & Wellness',base:249,groups:[
    {key:'cut',title:'Haircut',style:'task',items:[
      {k:'trim',    ico:'✂️',name:'Trim / Basic Cut',p:249},
      {k:'layer',   ico:'🌊',name:'Layer / Step Cut', p:349},
      {k:'uvcut',   ico:'💇',name:'U-Cut / V-Cut',    p:399},
      {k:'designer',ico:'✨',name:'Designer Cut',     p:499},
      {k:'visit',   ico:'🏠',name:'Visit fee (shown on page)',p:49}
    ]},
    {key:'hair',title:'Hair Treatments',style:'task',items:[
      {k:'blowdry',    ico:'💨',name:'Hair Wash & Blow Dry',p:299},
      {k:'globalcol',  ico:'🎨',name:'Global Colour',       p:499},
      {k:'highlights', ico:'✨',name:'Highlights',          p:699},
      {k:'balayage',   ico:'🌈',name:'Balayage',            p:899},
      {k:'roottouchup',ico:'🔄',name:'Root Touch-up',       p:349}
    ]},
    {key:'thread',title:'Threading',style:'bhk',items:[
      {k:'eyebrow', name:'Eyebrow',   p:49},
      {k:'upperlip',name:'Upper Lip', p:29},
      {k:'fullface',name:'Full Face', p:149}
    ]},
    {key:'wax',title:'Waxing',style:'bhk',items:[
      {k:'armswax',    name:'Arms',       p:149},
      {k:'legswax',    name:'Legs',       p:199},
      {k:'fullbodywax',name:'Full Body',  p:599},
      {k:'underarms',  name:'Underarms',  p:79}
    ]},
    {key:'facial',title:'Facial & Skin',style:'task',items:[
      {k:'basic',  ico:'🌿',name:'Basic Facial',   p:349},
      {k:'dtan',   ico:'🌞',name:'D-Tan Facial',   p:449},
      {k:'fruit',  ico:'🍑',name:'Fruit Facial',   p:599},
      {k:'gold',   ico:'✨',name:'Gold Facial',    p:799},
      {k:'antiage',ico:'💎',name:'Anti-Ageing',    p:999}
    ]},
    {key:'nail',title:'Nail Care',style:'task',items:[
      {k:'mani', ico:'💅',name:'Manicure',    p:299},
      {k:'pedi', ico:'🦶',name:'Pedicure',    p:349},
      {k:'combo',ico:'💆',name:'Mani + Pedi', p:599}
    ]},
    {key:'makeup',title:'Makeup',style:'task',items:[
      {k:'party',  ico:'💄',name:'Party Makeup',  p:1199},
      {k:'bridal', ico:'👰',name:'Bridal Makeup', p:1999},
      {k:'hd',     ico:'🌟',name:'HD / Airbrush', p:2999}
    ]}
  ]},
  {id:'SVC025',name:'Full Body Massage',icon:'💆',cat:'Beauty & Wellness',base:799,groups:[
    {key:'base',title:'Session Base Price',style:'bhk',items:[
      {k:'price',name:'Per session (base price)',p:799}
    ]},
    {key:'types',title:'Per Massage Type Price',style:'task',items:[
      {k:'swedish',      ico:'💆',name:'Swedish / Relaxation', p:799},
      {k:'deeptissue',   ico:'💪',name:'Deep Tissue',          p:999},
      {k:'ayurvedic',    ico:'🌿',name:'Ayurvedic / Abhyanga', p:1099},
      {k:'sports',       ico:'🏃',name:'Sports Massage',       p:999},
      {k:'reflexology',  ico:'🦶',name:'Reflexology (Feet)',   p:799},
      {k:'aromatherapy', ico:'🌸',name:'Aromatherapy',         p:899},
      {k:'headneck',     ico:'🧠',name:'Head & Neck',          p:599},
      {k:'backpain',     ico:'🔙',name:'Back Pain Relief',     p:899}
    ]}
  ]},
  {id:'SVC027',name:'Doctor Visit at Home',icon:'👨‍⚕️',cat:'Health Services',base:399,groups:[
    {key:'visit',title:'🏠 Doctor Visit Fee',style:'bhk',items:[
      {k:'gp',        name:'General Physician',          p:399},
      {k:'specialist',name:'Specialist Consultation',    p:699},
      {k:'pediatric', name:'Paediatrician',              p:499},
      {k:'senior',    name:'Senior Citizen Package',     p:449}
    ]}
  ]},
  {id:'SVC028',name:'Nurse Visit at Home',icon:'💉',cat:'Health Services',base:299,groups:[
    {key:'visit',title:'🏠 Nurse Visit Fee',style:'bhk',items:[
      {k:'basic',     name:'Basic Care Visit',           p:299},
      {k:'injection', name:'Injection / IV Drip',        p:199},
      {k:'dressing',  name:'Wound Dressing',             p:199},
      {k:'monitoring',name:'Daily Health Monitoring',    p:399},
      {k:'monthly',   name:'Monthly Nursing Plan',       p:4999}
    ]}
  ]},
  {id:'SVC029',name:'Lab Test Collection',icon:'🧪',cat:'Health Services',base:49,groups:[
    {key:'visit',title:'🏠 Home Collection Fee',style:'bhk',items:[
      {k:'visit',     name:'Home collection visit fee',  p:49},
      {k:'urgent',    name:'Urgent / Same Day',          p:99}
    ]}
  ]},
  {id:'SVC030',name:'Babysitter / Nanny',icon:'👶',cat:'Care Services',base:399,groups:[
    {key:'care',title:'Care Type',style:'task',items:[
      {k:'half',     ico:'🌤️',name:'Half Day (4 hrs)',       p:399},
      {k:'fullday',  ico:'☀️',name:'Full Day (8–10 hrs)',    p:699},
      {k:'overnight',ico:'🌙',name:'Overnight (10 PM–8 AM)',p:999},
      {k:'event',    ico:'🎉',name:'Event / Party (4 hrs)',  p:499},
      {k:'nanny',    ico:'👩‍🍼',name:'Experienced Nanny',   p:899},
      {k:'monthly',  ico:'📅',name:'Monthly Nanny',          p:14999}
    ]},
    {key:'extra',title:'Extra Charges',style:'bhk',items:[
      {k:'extrachild',name:'Per Extra Child / Day',p:199}
    ]}
  ]},
  {id:'SVC008',name:'AC Repair',icon:'🔧',cat:'Appliance Care',base:299,groups:[
    {key:'visit',title:'🔧 AC Repair Visit Fee',style:'bhk',items:[
      {k:'visit',      name:'Visit / Diagnosis Fee',           p:199},
      {k:'gas',        name:'Gas Refilling (per unit)',        p:799},
      {k:'compressor', name:'Compressor Repair',               p:499},
      {k:'pcb',        name:'PCB / Electrical Repair',         p:399},
      {k:'motor',      name:'Motor / Fan Repair',              p:299},
      {k:'install',    name:'New AC Installation (split)',     p:999}
    ]}
  ]},
  {id:'SVC018',name:'Bike Wash',icon:'🏍️',cat:'Vehicle Care',base:79,groups:[
    {key:'wash',title:'🏍️ Bike Wash Prices',style:'bhk',items:[
      {k:'exterior',  name:'Exterior Wash',           p:79},
      {k:'full',      name:'Full Wash (Ext + Chain)', p:129},
      {k:'premium',   name:'Premium Detailing',       p:249},
      {k:'chain',     name:'Chain Cleaning & Lube',   p:79}
    ]},
    {key:'type',title:'Bike Type Surcharge',style:'bhk',items:[
      {k:'scooter',   name:'Scooter / Moped (base)',  p:0},
      {k:'commuter',  name:'Commuter Bike',           p:0},
      {k:'sports',    name:'Sports / Heavy Bike',     p:49}
    ]}
  ]},
  {id:'SVC020',name:'2 Wheeler Mechanic',icon:'🔩',cat:'Vehicle Care',base:149,groups:[
    {key:'visit',title:'🔩 Visit / Diagnosis Fee',style:'bhk',items:[
      {k:'visit',     name:'Visit fee (diagnosis)',   p:149}
    ]},
    {key:'service',title:'Service Type (additional)',style:'bhk',items:[
      {k:'oilchange', name:'Oil & Filter Change',     p:99},
      {k:'brake',     name:'Brake Inspection / Fix',  p:79},
      {k:'puncture',  name:'Tyre Puncture Fix',       p:49},
      {k:'battery',   name:'Battery Check / Replace', p:79},
      {k:'general',   name:'General Service',         p:199},
      {k:'tuning',    name:'Engine Tuning',           p:149}
    ]}
  ]},
  {id:'SVC031',name:'Elderly Care',icon:'🧓',cat:'Care Services',base:599,groups:[
    {key:'care',title:'Care Package',style:'task',items:[
      {k:'companion',ico:'💬',name:'Companion Care (4 hrs)',   p:599},
      {k:'personal', ico:'🛁',name:'Personal Care (4–6 hrs)',  p:799},
      {k:'fullday',  ico:'☀️',name:'Full Day Care (8 hrs)',    p:1199},
      {k:'nightcare',ico:'🌙',name:'Night Care (10 PM–8 AM)', p:999},
      {k:'hospital', ico:'🏥',name:'Hospital Attendant',       p:899},
      {k:'monthly',  ico:'🗓️',name:'Monthly Plan',            p:17999}
    ]},
    {key:'needs',title:'Special Needs Add-ons',style:'task',items:[
      {k:'dementia', ico:'🧠',name:'Dementia Care',            p:149},
      {k:'bedridden',ico:'🛏️',name:'Bedridden Patient Care',  p:249},
      {k:'physio',   ico:'🤸',name:'Physiotherapy Support',    p:249},
      {k:'diabetes', ico:'💉',name:'Diabetes / Sugar Care',    p:79},
      {k:'catheter', ico:'🩺',name:'Catheter / Medical Care',  p:149}
    ]}
  ]}
];
if (typeof window!=='undefined') window.SVC = SVC;
