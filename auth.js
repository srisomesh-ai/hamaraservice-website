// ═══════════════════════════════════════════════════════════════
// HamaraService — Firebase Auth Module
// Handles Google Sign-In for both customers and providers
// Project: hamaraservice-s009
// ═══════════════════════════════════════════════════════════════

// Firebase config — paste your Web API Key below
// Go to: Firebase Console → hamaraservice-s009 → Project Settings → General → Your apps → Web app
const FIREBASE_CONFIG = {
  apiKey:            "PASTE_YOUR_WEB_API_KEY_HERE",
  authDomain:        "hamaraservice-s009.firebaseapp.com",
  databaseURL:       "https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app",
  projectId:         "hamaraservice-s009",
  storageBucket:     "hamaraservice-s009.appspot.com",
  messagingSenderId: "PASTE_SENDER_ID_HERE",
  appId:             "PASTE_APP_ID_HERE"
};

const DB_URL = "https://hamaraservice-s009-default-rtdb.asia-southeast1.firebasedatabase.app";

// ── Firebase REST helpers (no SDK needed for DB) ──────────────
async function dbWrite(path, data) {
  try {
    const token = await getIdToken();
    const url = `${DB_URL}/${path}.json${token ? '?auth='+token : ''}`;
    await fetch(url, {method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)});
  } catch(e) { console.warn('dbWrite failed:', e); }
}

async function dbRead(path) {
  try {
    const token = await getIdToken();
    const url = `${DB_URL}/${path}.json${token ? '?auth='+token : ''}`;
    const r = await fetch(url);
    return r.ok ? await r.json() : null;
  } catch(e) { return null; }
}

// ── Current user token ────────────────────────────────────────
let _currentUser = null;
async function getIdToken() {
  if (!_currentUser) return null;
  try { return await _currentUser.getIdToken(); } catch(e) { return null; }
}

// ── Google Sign-In ────────────────────────────────────────────
async function signInWithGoogle() {
  const provider = new firebase.auth.GoogleAuthProvider();
  provider.addScope('email');
  provider.addScope('profile');
  try {
    const result = await firebase.auth().signInWithPopup(provider);
    return result.user;
  } catch(e) {
    console.error('Google sign-in error:', e);
    throw e;
  }
}

// ── Sign out ──────────────────────────────────────────────────
async function signOut() {
  await firebase.auth().signOut();
  _currentUser = null;
}

// ── Auth state observer ───────────────────────────────────────
function onAuthReady(callback) {
  firebase.auth().onAuthStateChanged(user => {
    _currentUser = user;
    callback(user);
  });
}

// ── Save user profile to Firebase DB ─────────────────────────
async function saveUserProfile(uid, data) {
  await dbWrite('users/' + uid, data);
}

async function getUserProfile(uid) {
  return await dbRead('users/' + uid);
}

async function saveProviderProfile(uid, data) {
  await dbWrite('providers/' + uid, data);
}

async function getProviderProfile(uid) {
  return await dbRead('providers/' + uid);
}
