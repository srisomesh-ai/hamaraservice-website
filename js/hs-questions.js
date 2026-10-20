// Job questions asked before booking so the provider understands the job
// before quoting. Answers go into the booking summary as "Label: answer".
// Keep in sync with lib/services/job_questions.dart in the customer app.
window.HS_JOB_QUESTIONS = {
 "SVC005": [
  {
   "k": "items",
   "label": "Items",
   "q": "What needs cleaning?",
   "opts": [
    "Sofa",
    "Carpet / rug",
    "Mattress",
    "Curtains",
    "Dining chairs"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "size",
   "label": "Size",
   "q": "How big is it?",
   "opts": [
    "Small (3-seater / 1 carpet)",
    "Medium (5-seater)",
    "Large (7+ seater / many items)"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC007": [
  {
   "k": "need",
   "label": "Need",
   "q": "What do you need?",
   "opts": [
    "Visit & quote only",
    "Service / cleaning",
    "Not cooling / repair",
    "Gas refill",
    "Installation",
    "Uninstallation"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "type",
   "label": "AC type",
   "q": "Which AC?",
   "opts": [
    "Split",
    "Window",
    "Cassette / central",
    "Not sure"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "count",
   "label": "No. of ACs",
   "q": "How many ACs?",
   "opts": [
    "1",
    "2",
    "3",
    "4 or more"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC009": [
  {
   "k": "appliance",
   "label": "Appliance",
   "q": "Which appliance?",
   "opts": [
    "Washing machine",
    "Refrigerator",
    "Microwave",
    "TV",
    "Geyser",
    "Chimney",
    "Other"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "problem",
   "label": "Problem",
   "q": "What is the problem?",
   "opts": [
    "Not working at all",
    "Noise / leakage",
    "Partly working",
    "Installation",
    "Not sure"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "age",
   "label": "Age",
   "q": "How old is it?",
   "opts": [
    "Under 2 years",
    "2–5 years",
    "Over 5 years"
   ],
   "multi": false,
   "req": false
  }
 ],
 "SVC010": [
  {
   "k": "need",
   "label": "Need",
   "q": "What do you need?",
   "opts": [
    "Service / filter change",
    "Repair",
    "New installation",
    "Yearly AMC"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "type",
   "label": "Type",
   "q": "Purifier type?",
   "opts": [
    "RO",
    "UV",
    "RO + UV",
    "Not sure"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC011": [
  {
   "k": "work",
   "label": "Work",
   "q": "What work is needed?",
   "opts": [
    "Visit & quote only",
    "Leak / tap repair",
    "Blockage",
    "Fitting (tap / basin / toilet)",
    "Water tank / motor",
    "New pipeline"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "count",
   "label": "Points",
   "q": "How many points / items?",
   "opts": [
    "1",
    "2–3",
    "4–6",
    "Whole house"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "property",
   "label": "Property",
   "q": "Property type?",
   "opts": [
    "Independent house",
    "Apartment / flat",
    "Shop / office",
    "Building / complex",
    "Factory / warehouse"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC012": [
  {
   "k": "work",
   "label": "Work",
   "q": "What work is needed?",
   "opts": [
    "Visit & quote only",
    "Switch / socket repair",
    "Fan / light installation",
    "Short circuit / fault",
    "New wiring",
    "Inverter / MCB"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "count",
   "label": "Points",
   "q": "How many points / items?",
   "opts": [
    "1–2",
    "3–5",
    "6–10",
    "Whole house"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "property",
   "label": "Property",
   "q": "Property type?",
   "opts": [
    "Independent house",
    "Apartment / flat",
    "Shop / office",
    "Building / complex",
    "Factory / warehouse"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC013": [
  {
   "k": "work",
   "label": "Work",
   "q": "What work is needed?",
   "opts": [
    "Visit & quote only",
    "Repair (door / furniture)",
    "Furniture assembly",
    "New furniture made",
    "Lock / handle fitting",
    "Modular kitchen / wardrobe"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "size",
   "label": "Size",
   "q": "How much work?",
   "opts": [
    "1 small item",
    "A few items",
    "Full room or more"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC014": [
  {
   "k": "area",
   "label": "Area",
   "q": "Where?",
   "opts": [
    "Interior",
    "Exterior",
    "Both"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "paint",
   "label": "Paint",
   "q": "Who buys the paint?",
   "opts": [
    "Provider brings paint",
    "I will buy paint"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "walls",
   "label": "Walls",
   "q": "Wall condition?",
   "opts": [
    "Good",
    "Cracks / damp — needs putty",
    "Not sure"
   ],
   "multi": false,
   "req": false
  }
 ],
 "SVC015": [
  {
   "k": "need",
   "label": "Need",
   "q": "What do you need?",
   "opts": [
    "Visit & quote only (site survey)",
    "Install new CCTV",
    "Add more cameras",
    "Repair / service existing"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "cameras",
   "label": "No. of cameras",
   "q": "How many cameras?",
   "opts": [
    "1",
    "2",
    "3–4",
    "5–8",
    "9–16",
    "More than 16",
    "Not sure"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "property",
   "label": "Property",
   "q": "Where will it be installed?",
   "opts": [
    "Independent house",
    "Apartment / flat",
    "Shop / office",
    "Building / complex",
    "Factory / warehouse"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "supply",
   "label": "Equipment",
   "q": "Cameras & recorder (DVR/NVR)?",
   "opts": [
    "Provider supplies everything",
    "I already have the cameras",
    "Need advice"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "type",
   "label": "Camera type",
   "q": "Camera type?",
   "opts": [
    "Wired HD",
    "Wi-Fi / IP camera",
    "Not sure"
   ],
   "multi": false,
   "req": false
  },
  {
   "k": "remote",
   "label": "Mobile viewing",
   "q": "Watch on your phone?",
   "opts": [
    "Yes",
    "No"
   ],
   "multi": false,
   "req": false
  }
 ],
 "SVC016": [
  {
   "k": "panels",
   "label": "Panels",
   "q": "How many panels?",
   "opts": [
    "1–5",
    "6–10",
    "11–20",
    "More than 20"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "access",
   "label": "Roof access",
   "q": "Roof access?",
   "opts": [
    "Easy (stairs)",
    "Ladder needed",
    "Difficult / high roof"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC019": [
  {
   "k": "vehicle",
   "label": "Vehicle",
   "q": "Which vehicle?",
   "opts": [
    "Car",
    "Bike / scooter"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "problem",
   "label": "Problem",
   "q": "What is the problem?",
   "opts": [
    "Won't start",
    "Breakdown on road",
    "General service",
    "Brakes / clutch",
    "Battery / electrical",
    "Other"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "fuel",
   "label": "Fuel",
   "q": "Fuel type?",
   "opts": [
    "Petrol",
    "Diesel",
    "CNG",
    "Electric"
   ],
   "multi": false,
   "req": false
  }
 ],
 "SVC021": [
  {
   "k": "pest",
   "label": "Pest",
   "q": "Which pests?",
   "opts": [
    "Cockroaches",
    "Termites",
    "Bed bugs",
    "Rats / mice",
    "Mosquitoes",
    "General"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "size",
   "label": "Property size",
   "q": "Property size?",
   "opts": [
    "1 BHK",
    "2 BHK",
    "3 BHK",
    "4+ BHK / villa",
    "Shop / office"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC022": [
  {
   "k": "meals",
   "label": "Meals",
   "q": "Which meals?",
   "opts": [
    "Breakfast",
    "Lunch",
    "Dinner",
    "Party / function"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "food",
   "label": "Food",
   "q": "Food type?",
   "opts": [
    "Veg",
    "Non-veg",
    "Both"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "often",
   "label": "How often",
   "q": "How often?",
   "opts": [
    "One time",
    "Daily",
    "Few days a week"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC026": [
  {
   "k": "goal",
   "label": "Goal",
   "q": "Your goal?",
   "opts": [
    "Weight loss",
    "Muscle gain",
    "General fitness",
    "Yoga",
    "Rehab / senior fitness"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "sessions",
   "label": "Sessions",
   "q": "How many sessions?",
   "opts": [
    "Single trial",
    "12 per month",
    "20+ per month"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC027": [
  {
   "k": "reason",
   "label": "Reason",
   "q": "Reason for visit?",
   "opts": [
    "Fever / cold",
    "Elderly check-up",
    "Chronic condition follow-up",
    "Post-surgery",
    "Other"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "patient",
   "label": "Patient",
   "q": "Patient age?",
   "opts": [
    "Child",
    "Adult",
    "Senior (60+)"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC028": [
  {
   "k": "need",
   "label": "Need",
   "q": "What care is needed?",
   "opts": [
    "Injection / IV drip",
    "Wound dressing",
    "Catheter care",
    "Elderly care",
    "Post-operative care"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "duration",
   "label": "Duration",
   "q": "For how long?",
   "opts": [
    "Single visit",
    "Daily visits",
    "12-hour shift",
    "24-hour stay"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC029": [
  {
   "k": "tests",
   "label": "Tests",
   "q": "Which tests?",
   "opts": [
    "Routine blood test",
    "Diabetes",
    "Thyroid",
    "Full body check-up",
    "Urine test",
    "As per prescription"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "people",
   "label": "People",
   "q": "How many people?",
   "opts": [
    "1",
    "2",
    "3 or more"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC030": [
  {
   "k": "kids",
   "label": "Children",
   "q": "How many children?",
   "opts": [
    "1",
    "2",
    "3 or more"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "age",
   "label": "Child age",
   "q": "Age of the youngest?",
   "opts": [
    "Under 1 year",
    "1–3 years",
    "3–6 years",
    "6+ years"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "hours",
   "label": "Hours per day",
   "q": "Hours per day?",
   "opts": [
    "4 hours",
    "8 hours",
    "12 hours",
    "24 hours / live-in"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "howlong",
   "label": "For how long",
   "q": "For how long?",
   "opts": [
    "1 day",
    "2–3 days",
    "1 week",
    "1 month",
    "Ongoing (monthly)"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC031": [
  {
   "k": "hours",
   "label": "Hours per day",
   "q": "Hours per day?",
   "opts": [
    "Day (12 hours)",
    "Night (12 hours)",
    "24 hours / live-in"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "mobility",
   "label": "Mobility",
   "q": "Patient mobility?",
   "opts": [
    "Walks on their own",
    "Needs support",
    "Bedridden"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "howlong",
   "label": "For how long",
   "q": "For how long?",
   "opts": [
    "1 day",
    "2–3 days",
    "1 week",
    "1 month",
    "Ongoing (monthly)"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC032": [
  {
   "k": "work",
   "label": "Work",
   "q": "What work?",
   "opts": [
    "Regular maintenance",
    "Lawn mowing",
    "Planting",
    "New garden setup",
    "Tree trimming"
   ],
   "multi": true,
   "req": true
  },
  {
   "k": "area",
   "label": "Area",
   "q": "Garden size?",
   "opts": [
    "Balcony / pots",
    "Small garden",
    "Large garden / lawn"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "often",
   "label": "How often",
   "q": "How often?",
   "opts": [
    "One time",
    "Weekly",
    "Monthly"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC033": [
  {
   "k": "hours",
   "label": "Duty",
   "q": "Duty length?",
   "opts": [
    "Few hours",
    "Full day (8–12 hours)",
    "Outstation trip",
    "Monthly driver"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "howlong",
   "label": "For how long",
   "q": "For how many days?",
   "opts": [
    "1 day",
    "2–3 days",
    "1 week",
    "1 month or more"
   ],
   "multi": false,
   "req": true
  }
 ],
 "SVC034": [
  {
   "k": "guards",
   "label": "No. of guards",
   "q": "How many guards / bouncers?",
   "opts": [
    "1",
    "2",
    "3–5",
    "6–10",
    "More than 10"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "shift",
   "label": "Shift",
   "q": "Hours per shift?",
   "opts": [
    "8 hours",
    "12 hours",
    "24 hours"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "timing",
   "label": "Timing",
   "q": "Day or night?",
   "opts": [
    "Day",
    "Night",
    "Day & night"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "howlong",
   "label": "For how long",
   "q": "For how long?",
   "opts": [
    "1 day",
    "2–3 days",
    "1 week",
    "1 month",
    "Ongoing (monthly)"
   ],
   "multi": false,
   "req": true
  },
  {
   "k": "place",
   "label": "Location",
   "q": "Where?",
   "opts": [
    "Event / function",
    "Home",
    "Shop / office",
    "Apartment / society",
    "Construction site",
    "Factory / warehouse"
   ],
   "multi": false,
   "req": true
  }
 ]
};
