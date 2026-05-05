# Firebase Setup for Live Tracking

## Step 1 — Create free Firebase project (2 minutes)
1. Go to https://console.firebase.google.com
2. Click "Add project" → name it "hamaraservice-track"
3. Disable Google Analytics (not needed) → Create project

## Step 2 — Create Realtime Database
1. Left sidebar → Build → Realtime Database
2. Click "Create Database"
3. Choose region: asia-southeast1 (Singapore, closest to India)
4. Start in **test mode** (allows read/write for 30 days)
5. Click Enable

## Step 3 — Get your database URL
Your URL will look like:
  https://hamaraservice-track-default-rtdb.asia-southeast1.firebasedatabase.app

## Step 4 — Update provider-dashboard.html
Find this line (near top of script):
  const FB = 'https://hamaraservice-track-default-rtdb.firebaseio.com';

Replace with YOUR database URL from Step 3.

## Step 5 — Set security rules (after testing)
In Firebase Console → Realtime Database → Rules:

{
  "rules": {
    "tracking": {
      "$bookingId": {
        "provider": {
          ".read": true,
          ".write": true
        }
      }
    }
  }
}

## How it works
- Provider phone → GPS → writes lat/lng to Firebase every 4 seconds
- Customer phone → reads Firebase every 4 seconds → moves marker on map
- Route drawn using OSRM (real roads, free, no API key needed)
- Works across any two different devices/phones on any network

## Free tier limits (more than enough)
- 1 GB storage
- 10 GB/month download  
- Simultaneous connections: 100
