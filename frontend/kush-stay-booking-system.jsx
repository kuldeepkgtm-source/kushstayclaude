// ============================================================
// KUSH STAY — CORE ENGINE (pure JS, no JSX) — sanity-checked separately
// ============================================================
import React, { useState, useEffect, useMemo, useRef, useCallback } from "react";
import { LineChart, Line, BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from "recharts";
import { LayoutDashboard, MessageCircle, ClipboardList, CalendarDays, BedDouble, Users, Wallet, Globe, BarChart3, Package, Settings as SettingsIcon, Phone, PhoneCall, Send, RefreshCw, Trash2, Plus, X, Check, ChevronRight, ChevronLeft, ChevronDown, AlertTriangle, Clock, TrendingUp, ShieldCheck, Search, Eye, Pencil, Bell, Link2, Sparkles, Bot, User, IndianRupee, CheckCircle2, XCircle, Info, MapPin, Building2, Lock, LogOut, Download, Upload, FlaskConical, Filter, ArrowRightLeft } from "lucide-react";

/* ---------------- ROOMS / BEDS ---------------- */
const ROOMS = {
  AC:  { key: "AC",  name: "AC Dormitory",     beds: ["AC-U1","AC-U2","AC-U3","AC-U4","AC-L1","AC-L2","AC-L3","AC-L4"] },
  NAC: { key: "NAC", name: "Non-AC Dormitory", beds: ["NAC-U1","NAC-U2","NAC-U3","NAC-U4","NAC-L1","NAC-L2","NAC-L3","NAC-L4"] },
};
function bedMeta(bedId) {
  const room = bedId.startsWith("AC-") ? "AC" : "NAC";
  const type = bedId.includes("-U") ? "Upper" : "Lower";
  return { room, type };
}
const ALL_BED_IDS = [...ROOMS.AC.beds, ...ROOMS.NAC.beds];

const DEFAULT_PRICES = {
  "AC-Upper": 300, "AC-Lower": 350, "NAC-Upper": 200, "NAC-Lower": 250,
  "AC-Private": 2200, "NAC-Private": 1800,
  weekendSurchargePct: 15, weekendDays: [5, 6], // Fri, Sat nights
};

const SOURCES = ["WhatsApp AI","Direct","Website","Walk-in","Phone","Booking.com","Airbnb","MakeMyTrip","Goibibo","Other OTA","iCal Import","Manual/Admin"];
const SOURCE_COLORS = {
  "WhatsApp AI":"#16a34a","Direct":"#475569","Website":"#0ea5e9","Walk-in":"#7c3aed","Phone":"#4f46e5",
  "Booking.com":"#003580","Airbnb":"#FF385C","MakeMyTrip":"#e6521f","Goibibo":"#ee2f5e","Other OTA":"#6b7280",
  "iCal Import":"#0f766e","Manual/Admin":"#0d9488",
};
const BOOKING_STATUSES = ["Pending","Confirmed","Checked-in","Checked-out","Cancelled","No-show"];
const PAYMENT_STATUSES = ["Unpaid","Partially Paid","Paid","Refunded"];

const DEFAULT_SETTINGS = {
  propertyName: "Kush Stay",
  address: "Add your property address",
  phone: "+91 92385 82719",
  whatsapp: "+91 92385 82719",
  email: "reservations@kushstay.example",
  checkInTime: "12:00 PM",
  checkOutTime: "11:00 AM",
  currency: "INR",
  timezone: "Asia/Kolkata",
  taxEnabled: false,
  taxRatePct: 0,
  cancellationPolicy: "Free cancellation up to 24 hours before check-in. Cancellations within 24 hours forfeit the first night.",
  paymentPolicy: "Beds can be held for 10 minutes while you complete payment via UPI, card, or pay cash at the property.",
  languages: { hindi: true, english: true, hinglish: true },
  humanHandoffNumber: "+91 92385 82719",
  showDecisionPanel: false,
  dataMode: "prototype", // "prototype" (window.storage, this browser only) or "production" (Laravel API below)
  apiBaseUrl: "",
  apiPropertyId: 1, // matches KushStaySeeder's single seeded property
  acRoomApiId: 1, nacRoomApiId: 2, // KushStaySeeder inserts AC then NAC, so these are the likely ids — confirm via GET /api/rooms
};

/* ---------------- PRODUCTION API CLIENT ----------------
 * Talks to the Laravel backend in kush-stay-laravel-backend.zip. Only used when
 * settings.dataMode === "production"; prototype mode never imports or calls this.
 * Every function mirrors one documented endpoint 1:1 — see routes/api.php in the backend.
 * NOTE ON WIRING SCOPE: this pass wires the highest-stakes path only — creating a booking
 * from the WhatsApp flow (see resolvePayment in WhatsAppScreen), since that's the exact
 * operation the double-booking protection guards. The remaining call sites (admin
 * BookingForm create/edit, BookingDetail's cancel/check-in/check-out buttons, OTA "Sync
 * now", the Pass system, and the Demo & Tests panel) still write to local state only, same
 * as before this change — each is a mechanical one-line swap to the matching apiClient
 * function below, intentionally left as a follow-up rather than rewritten wholesale here.
 */
const apiClient = {
  base(settings) { return (settings.apiBaseUrl || "").replace(/\/$/, ""); },
  async request(settings, path, options = {}) {
    const res = await fetch(apiClient.base(settings) + path, {
      ...options,
      headers: { "Content-Type": "application/json", ...(settings.apiToken ? { Authorization: `Bearer ${settings.apiToken}` } : {}), ...(options.headers || {}) },
    });
    let body = null;
    try { body = await res.json(); } catch (e) { /* empty body, e.g. 204 */ }
    if (!res.ok) { const err = new Error(body?.message || `Request failed (${res.status})`); err.status = res.status; err.body = body; throw err; }
    return body;
  },
  getAvailability(settings, { propertyId, checkIn, checkOut, guests, ac }) {
    const q = new URLSearchParams({ property_id: propertyId, check_in: checkIn, check_out: checkOut, guests, ...(ac !== undefined && ac !== null ? { ac: String(ac) } : {}) });
    return apiClient.request(settings, `/api/availability?${q}`);
  },
  createBooking(settings, payload) { return apiClient.request(settings, "/api/bookings", { method: "POST", body: JSON.stringify(payload) }); },
  getBooking(settings, id) { return apiClient.request(settings, `/api/bookings/${id}`); },
  updateBooking(settings, id, payload) { return apiClient.request(settings, `/api/bookings/${id}`, { method: "PUT", body: JSON.stringify(payload) }); },
  cancelBooking(settings, id) { return apiClient.request(settings, `/api/bookings/${id}/cancel`, { method: "POST" }); },
  checkInBooking(settings, id) { return apiClient.request(settings, `/api/bookings/${id}/check-in`, { method: "POST" }); },
  checkOutBooking(settings, id) { return apiClient.request(settings, `/api/bookings/${id}/check-out`, { method: "POST" }); },
  listBookings(settings, params = {}) { const q = new URLSearchParams(params); return apiClient.request(settings, `/api/bookings?${q}`); },
  listBeds(settings, roomId) { return apiClient.request(settings, `/api/beds${roomId ? `?room_id=${roomId}` : ""}`); },
  listRooms(settings) { return apiClient.request(settings, "/api/rooms"); },
  listCustomers(settings, params = {}) { const q = new URLSearchParams(params); return apiClient.request(settings, `/api/customers?${q}`); },
  listPayments(settings, params = {}) { const q = new URLSearchParams(params); return apiClient.request(settings, `/api/payments?${q}`); },
  recordPayment(settings, payload) { return apiClient.request(settings, "/api/payments", { method: "POST", body: JSON.stringify(payload) }); },
  createHold(settings, payload) { return apiClient.request(settings, "/api/holds", { method: "POST", body: JSON.stringify(payload) }); },
  releaseHold(settings, id) { return apiClient.request(settings, `/api/holds/${id}`, { method: "DELETE" }); },
  login(settings, email, password) { return apiClient.request(settings, "/api/login", { method: "POST", body: JSON.stringify({ email, password }) }); },
  logout(settings) { return apiClient.request(settings, "/api/logout", { method: "POST" }); },
};

/* ---------------- DATE HELPERS ---------------- */
function toISO(d) { return d.toISOString().slice(0, 10); }
function addDays(iso, n) { const d = new Date(iso + "T00:00:00"); d.setDate(d.getDate() + n); return toISO(d); }
function nightsBetween(a, b) { return Math.round((new Date(b + "T00:00:00") - new Date(a + "T00:00:00")) / 86400000); }
function overlap(aS, aE, bS, bE) { return aS < bE && bS < aE; }
function nightsList(ci, co) { const out = []; let d = ci; while (d < co) { out.push(d); d = addDays(d, 1); } return out; }
function isWeekendNight(iso, prices) { const dow = new Date(iso + "T00:00:00").getDay(); return (prices.weekendDays || [5,6]).includes(dow); }
function fmtDate(iso) { if(!iso) return "—"; return new Date(iso + "T00:00:00").toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" }); }
function fmtDateShort(iso) { if(!iso) return "—"; return new Date(iso + "T00:00:00").toLocaleDateString("en-IN", { day: "2-digit", month: "short" }); }
function fmtMoney(n) { return "₹" + Math.round(n || 0).toLocaleString("en-IN"); }
function fmtDateTime(ts) { return new Date(ts).toLocaleString("en-IN", { day:"2-digit", month:"short", hour:"2-digit", minute:"2-digit" }); }
function icsStamp(d) { return d.toISOString().replace(/[-:]/g,"").split(".")[0] + "Z"; }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function uid(prefix) { return prefix + "-" + Math.random().toString(36).slice(2,9); }

/* ---------------- INVENTORY / PRICING ENGINE ---------------- */
function priceForBed(bedId, prices) { const { room, type } = bedMeta(bedId); return prices[`${room}-${type}`]; }
function getNightlyBedRate(bedId, dateIso, prices) {
  const base = priceForBed(bedId, prices);
  return isWeekendNight(dateIso, prices) ? Math.round(base * (1 + (prices.weekendSurchargePct || 0) / 100)) : base;
}
function getNightlyPrivateRate(roomKey, dateIso, prices) {
  const base = prices[`${roomKey}-Private`];
  return isWeekendNight(dateIso, prices) ? Math.round(base * (1 + (prices.weekendSurchargePct || 0) / 100)) : base;
}
function calcIndividualTotal(bedIds, ci, co, prices) {
  const nights = nightsList(ci, co);
  let sum = 0;
  bedIds.forEach(bed => nights.forEach(n => { sum += getNightlyBedRate(bed, n, prices); }));
  return sum;
}
function calcPrivateTotal(roomKey, ci, co, prices) {
  const nights = nightsList(ci, co);
  let sum = 0;
  nights.forEach(n => { sum += getNightlyPrivateRate(roomKey, n, prices); });
  return sum;
}
function applyDiscountTax(subtotal, discount, settings) {
  const afterDiscount = Math.max(0, subtotal - (discount || 0));
  const tax = settings.taxEnabled ? Math.round(afterDiscount * (settings.taxRatePct || 0) / 100) : 0;
  return { subtotal, discount: discount || 0, tax, total: afterDiscount + tax };
}

function isBedFree(bedId, ci, co, bookings, holds, blocked, excludeBookingId) {
  if (blocked[bedId]) return false;
  if (bookings.some(b => b.bookingId !== excludeBookingId && b.bookingStatus !== "Cancelled" && b.bedIds.includes(bedId) && overlap(b.checkIn, b.checkOut, ci, co))) return false;
  if (holds.some(h => h.bedIds.includes(bedId) && overlap(h.checkIn, h.checkOut, ci, co))) return false;
  return true;
}
function getAvailableBeds(roomKey, ci, co, bookings, holds, blocked, typeFilter, excludeBookingId) {
  return ROOMS[roomKey].beds.filter(id => isBedFree(id, ci, co, bookings, holds, blocked, excludeBookingId) && (!typeFilter || bedMeta(id).type === typeFilter));
}
function isPrivateAvailable(roomKey, ci, co, bookings, holds, blocked, excludeBookingId) {
  const free = getAvailableBeds(roomKey, ci, co, bookings, holds, blocked, null, excludeBookingId);
  return { available: free.length === 8, freeCount: free.length, reason: free.length === 8 ? null : `${8 - free.length} of 8 beds already booked/held/blocked for these dates` };
}
function chooseBeds(roomKey, guests, ci, co, bookings, holds, blocked, bedPref) {
  const uppers = getAvailableBeds(roomKey, ci, co, bookings, holds, blocked, "Upper");
  const lowers = getAvailableBeds(roomKey, ci, co, bookings, holds, blocked, "Lower");
  if (bedPref === "Lower") return lowers.slice(0, guests);
  if (bedPref === "Upper") return uppers.slice(0, guests);
  return [...uppers, ...lowers].slice(0, guests);
}
function makeBookingId(bookings, dateObj) {
  const ymd = toISO(dateObj).replace(/-/g, "");
  const countToday = bookings.filter(b => b.bookingId && b.bookingId.startsWith("BK-" + ymd)).length;
  return `BK-${ymd}-${String(countToday + 1).padStart(4, "0")}`;
}
function makeLeadId(leads) { return "LEAD-" + String(leads.length + 1).padStart(3, "0"); }

/* ---------------- VALIDATION ---------------- */
function validateBookingDraft(draft, bookings, excludeBookingId) {
  const errors = [];
  if (!draft.checkIn || !draft.checkOut) errors.push("Check-in and check-out dates are required.");
  else if (draft.checkOut <= draft.checkIn) errors.push("Check-out must be after check-in.");
  if (!draft.guestCount || draft.guestCount <= 0) errors.push("Number of guests must be at least 1.");
  if (draft.requireContact) {
    if (!draft.customerName || !draft.customerName.trim()) errors.push("Guest name is required.");
    if (!draft.customerPhone || !/^\d{10}$/.test(draft.customerPhone.replace(/\s/g, ""))) errors.push("A valid 10-digit mobile number is required.");
  }
  if (draft.checkIn && draft.checkOut && draft.checkOut > draft.checkIn && draft.bedIds && draft.bedIds.length) {
    const unavailable = draft.bedIds.filter(id => !isBedFree(id, draft.checkIn, draft.checkOut, bookings, [], {}, excludeBookingId));
    if (unavailable.length) errors.push(`These beds are no longer available for these dates: ${unavailable.join(", ")}.`);
  }
  if (draft.bookingType === "private" && draft.roomKey && draft.checkIn && draft.checkOut && draft.checkOut > draft.checkIn) {
    const p = isPrivateAvailable(draft.roomKey, draft.checkIn, draft.checkOut, bookings, [], {}, excludeBookingId);
    if (!p.available) errors.push(`Private ${draft.roomKey} Room is not available: ${p.reason}`);
  }
  if (draft.amountPaid != null && draft.total != null && draft.amountPaid > draft.total) errors.push("Amount paid cannot exceed the total.");
  if (draft.amountPaid < 0 || draft.discount < 0) errors.push("Amounts cannot be negative.");
  if (draft.customerPhone && draft.checkIn && draft.checkOut) {
    const dup = bookings.some(b => b.bookingId !== excludeBookingId && b.customerPhone === draft.customerPhone && b.bookingStatus !== "Cancelled" && overlap(b.checkIn, b.checkOut, draft.checkIn, draft.checkOut));
    if (dup) errors.push("Warning: this guest already has another booking overlapping these dates.");
  }
  return errors;
}
/* ---------------- NLU EXTRACTION (Hindi/Hinglish/English) ---------------- */
const NUM_WORDS = { ek:1, ekk:1, do:2, teen:3, tin:3, char:4, chaar:4, paanch:5, panch:5, che:6, chhe:6, chhey:6, cheh:6, saat:7, aath:8, aath8:8, nau:9, das:10, dus:10 };
const MONTHS = ["jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"];
function monthIndex(str) { return MONTHS.indexOf(str.toLowerCase().slice(0,3)); }

function extractGuests(text) {
  const lower = text.toLowerCase();
  let m = lower.match(/(\d+)\s*(?:log|logon|logo|friends|guests?|people|admi|aadmi|vyakti|persons?)\b/);
  if (m) return parseInt(m[1]);
  m = lower.match(/hum\s+(\d+)\s*(?:log|hain|friends)?/);
  if (m) return parseInt(m[1]);
  m = lower.match(/(\d+)\s*of us\b/);
  if (m) return parseInt(m[1]);
  m = lower.match(/\bwe are\s+(\d+)\b/);
  if (m) return parseInt(m[1]);
  for (const w in NUM_WORDS) {
    const re = new RegExp("\\b" + w + "\\s*(?:log|logon|friends|guests|hain)\\b");
    if (re.test(lower)) return NUM_WORDS[w];
  }
  m = lower.match(/hum\s+(\w+)\s+(?:log|friends|hain)/);
  if (m && NUM_WORDS[m[1]]) return NUM_WORDS[m[1]];
  if (/^\s*\d{1,2}\s*$/.test(lower)) return parseInt(lower.trim());
  return null;
}
function extractDates(text, todayIso) {
  const lower = text.toLowerCase();
  if (/\bparso\b/.test(lower)) return { checkIn: addDays(todayIso,2), checkOut: addDays(todayIso,3) };
  if (/\bkal\b/.test(lower)) return { checkIn: addDays(todayIso,1), checkOut: addDays(todayIso,2) };
  if (/\baaj\b|\btonight\b/.test(lower)) return { checkIn: todayIso, checkOut: addDays(todayIso,1) };
  let m = lower.match(/(\d{1,2})(?:st|nd|rd|th)?\s*(?:se|to|[-–])\s*(\d{1,2})(?:st|nd|rd|th)?\s*(jan\w*|feb\w*|mar\w*|apr\w*|may\w*|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*)?/);
  if (m) {
    const d1 = parseInt(m[1]), d2 = parseInt(m[2]);
    const mo = m[3] ? monthIndex(m[3]) : new Date(todayIso).getMonth();
    let year = new Date(todayIso).getFullYear();
    let ci = new Date(year, mo, d1), co = new Date(year, mo, d2);
    if (ci < new Date(todayIso + "T00:00:00")) { ci = new Date(year+1, mo, d1); co = new Date(year+1, mo, d2); }
    return { checkIn: toISO(ci), checkOut: toISO(co) };
  }
  m = lower.match(/(\d{1,2})(?:st|nd|rd|th)?\s*(jan\w*|feb\w*|mar\w*|apr\w*|may\w*|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*)/);
  if (m) {
    const d1 = parseInt(m[1]); const mo = monthIndex(m[2]);
    let year = new Date(todayIso).getFullYear();
    let ci = new Date(year, mo, d1);
    if (ci < new Date(todayIso + "T00:00:00")) ci = new Date(year+1, mo, d1);
    return { checkIn: toISO(ci), checkOut: null };
  }
  return {};
}
function extractAcPref(text) { const l = text.toLowerCase(); if (/non[\s-]?ac/.test(l)) return false; if (/\bac\b/.test(l)) return true; return null; }
function extractBedPref(text) { const l = text.toLowerCase(); if (/\blower\b/.test(l)) return "Lower"; if (/\bupper\b/.test(l)) return "Upper"; if (/\bany\b/.test(l)) return "Any"; return null; }
function extractPrivatePref(text) {
  const l = text.toLowerCase();
  if (/private\s*room|pura\s*room|poora\s*room|full\s*room|entire\s*room|whole\s*room|pura\s*kamra/.test(l)) return true;
  if (/individual\s*bed|sirf\s*bed/.test(l)) return false;
  return null;
}
function extractBudget(text) {
  const l = text.toLowerCase().replace(/,/g, "");
  let m = l.match(/budget\D{0,10}(\d{3,6})/); if (m) return parseInt(m[1]);
  m = l.match(/(\d{3,6})\s*(?:tak|rupees|rs\.?|₹)/); if (m) return parseInt(m[1]);
  return null;
}
function extractPhone(text) { const m = text.match(/\b\d{10}\b/); return m ? m[0] : null; }
function isConfirm(t) { return /\b(yes|confirm|book\s*it|kar\s*do|kardo|haan|theek\s*hai|ok(?:ay)?|confirm\s*booking|go\s*ahead)\b/i.test(t); }
function isChangeIntent(t) { return /\bchange\b|badalna|change\s*karo/i.test(t); }
function isTalkToStaff(t) { return /talk\s*to\s*staff|staff\s*se\s*baat|talk\s*to\s*someone/i.test(t); }
function isCancelIntent(t) { return /\bcancel\b/i.test(t); }
function isCallbackIntent(t) { return /call\s*(me|karwa|karo|kar do)|talk\s*to\s*(someone|staff|human)|speak\s*to\s*(someone|staff)|\bcomplaint\b|not\s*sure\s*which|special\s*request|human\s*chahiye/i.test(t); }
function isPaymentQuery(t) { return /payment\s*kaise|how\s*(do|to)\s*(i\s*)?pay|payment\s*kare|kaise\s*pay/i.test(t); }
function isPrivateAvailabilityQuery(t) { return /private\s*room\s*(hai\s*kya|available|milega|hoga|mil\s*jayega)|is\s*(there|a)\s*private\s*room|private\s*room\s*available/i.test(t); }
function extractPaymentMethod(t) { const l=t.toLowerCase(); if(/upi/.test(l)) return "UPI"; if(/card/.test(l)) return "Card"; if(/cash/.test(l)) return "Cash at property"; if(/pay\s*later|later/.test(l)) return "Pay later"; return null; }
function looksEnglish(t) { return /\b(is|are|can|could|please|want|would|the|have|need)\b/i.test(t) && !/chahiye|hai|kya|kaise|karo|kar do|log|logon|raat|chalega|bhai|haan/i.test(t); }

/* ---------------- ICAL (real) ---------------- */
function escapeICS(s) { return String(s || "").replace(/[\\,;]/g, m => "\\"+m).replace(/\n/g,"\\n"); }
function generateICS(bookingsForCal, calName) {
  const lines = ["BEGIN:VCALENDAR","VERSION:2.0","PRODID:-//Kush Stay//Booking System//EN","CALSCALE:GREGORIAN","X-WR-CALNAME:"+escapeICS(calName)];
  bookingsForCal.forEach(b => {
    lines.push("BEGIN:VEVENT");
    lines.push("UID:"+b.bookingId+"@kushstay");
    lines.push("DTSTAMP:"+icsStamp(new Date(b.createdAt||Date.now())));
    lines.push("DTSTART;VALUE=DATE:"+b.checkIn.replace(/-/g,""));
    lines.push("DTEND;VALUE=DATE:"+b.checkOut.replace(/-/g,""));
    lines.push("SUMMARY:"+escapeICS(`${b.customerName} — ${b.bedIds.join(", ")}`));
    lines.push("DESCRIPTION:"+escapeICS(`Booking ${b.bookingId} · ${b.guestCount} guest(s) · ${b.bookingStatus} · Source: ${b.source}`));
    lines.push("STATUS:"+(b.bookingStatus==="Cancelled"?"CANCELLED":"CONFIRMED"));
    lines.push("END:VEVENT");
  });
  lines.push("END:VCALENDAR");
  return lines.join("\r\n");
}
function parseICS(text) {
  const events = [];
  const blocks = text.split(/BEGIN:VEVENT/i).slice(1);
  blocks.forEach(block => {
    const body = block.split(/END:VEVENT/i)[0];
    const get = (key) => { const m = body.match(new RegExp(key + "[^:]*:([^\r\n]+)")); return m ? m[1].trim() : null; };
    const uidVal = get("UID") || uid("ICS");
    const rawStart = get("DTSTART");
    const rawEnd = get("DTEND");
    const summary = get("SUMMARY") || "Imported event";
    const toIso = (raw) => { if(!raw) return null; const digits = raw.replace(/[^0-9]/g,"").slice(0,8); if(digits.length<8) return null; return `${digits.slice(0,4)}-${digits.slice(4,6)}-${digits.slice(6,8)}`; };
    const ci = toIso(rawStart), co = toIso(rawEnd);
    if (ci && co) events.push({ uid: uidVal, checkIn: ci, checkOut: co, summary });
  });
  return events;
}
function downloadTextFile(filename, content, mime) {
  const blob = new Blob([content], { type: mime || "text/plain" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url; a.download = filename; document.body.appendChild(a); a.click();
  document.body.removeChild(a); URL.revokeObjectURL(url);
}
/* ---------------- SEED / DEMO DATA ---------------- */
function mkBooking({ id, source, name, phone, ci, co, guests, beds, bookingType="individual", roomKey=null, status, paymentStatus, amountPaidFrac=0, method="Cash", ext=null, special="", createdOffsetDays=0 }) {
  const nights = nightsBetween(ci, co);
  const subtotal = bookingType === "private"
    ? calcPrivateTotal(roomKey, ci, co, DEFAULT_PRICES)
    : calcIndividualTotal(beds, ci, co, DEFAULT_PRICES);
  const total = subtotal;
  const amountPaid = Math.round(total * amountPaidFrac);
  return {
    bookingId: id, source, customerName: name, customerPhone: phone, customerEmail: "",
    checkIn: ci, checkOut: co, guestCount: guests, bookingType,
    roomKey: roomKey || (beds[0] ? bedMeta(beds[0]).room : null), bedIds: beds,
    nights, subtotal, discount: 0, tax: 0, total, amountPaid, balance: total - amountPaid,
    paymentStatus, paymentMethod: method, bookingStatus: status, externalBookingId: ext,
    specialRequest: special, createdAt: Date.now() + createdOffsetDays * 86400000, updatedAt: Date.now(),
  };
}
function buildInitialBookings() {
  const today = toISO(new Date());
  return [
    mkBooking({ id:"BK-SEED-0001", source:"Direct", name:"Rahul Sharma", phone:"9876543210", ci:addDays(today,-3), co:addDays(today,-1), guests:2, beds:["NAC-U1","NAC-U2"], status:"Checked-out", paymentStatus:"Paid", amountPaidFrac:1, method:"Cash", createdOffsetDays:-4 }),
    mkBooking({ id:"BK-SEED-0002", source:"Booking.com", name:"Amit Verma", phone:null, ci:addDays(today,-1), co:addDays(today,2), guests:1, beds:["AC-L2"], status:"Checked-in", paymentStatus:"Paid", amountPaidFrac:1, method:"Card", ext:"BDC-33210", createdOffsetDays:-6 }),
    mkBooking({ id:"BK-SEED-0003", source:"WhatsApp AI", name:"Priya Singh", phone:"9911223344", ci:addDays(today,2), co:addDays(today,4), guests:3, beds:["AC-U1","AC-U2","AC-L1"], status:"Confirmed", paymentStatus:"Paid", amountPaidFrac:1, method:"UPI", createdOffsetDays:-1 }),
    mkBooking({ id:"BK-SEED-0004", source:"Airbnb", name:"Neha Patel", phone:null, ci:addDays(today,4), co:addDays(today,6), guests:2, beds:["NAC-L1","NAC-L2"], status:"Confirmed", paymentStatus:"Paid", amountPaidFrac:1, method:"Card", ext:"ABB-77812", createdOffsetDays:-3 }),
    mkBooking({ id:"BK-SEED-0005", source:"Phone", name:"Sanjay Gupta", phone:"9823456712", ci:addDays(today,7), co:addDays(today,9), guests:8, beds:["AC-U1","AC-U2","AC-U3","AC-U4","AC-L1","AC-L2","AC-L3","AC-L4"], bookingType:"private", roomKey:"AC", status:"Confirmed", paymentStatus:"Unpaid", amountPaidFrac:0, method:"Pay later", createdOffsetDays:-2 }),
    mkBooking({ id:"BK-SEED-0006", source:"Manual/Admin", name:"Vikram Rao", phone:"9900112233", ci:addDays(today,10), co:addDays(today,11), guests:1, beds:["NAC-U3"], status:"Confirmed", paymentStatus:"Unpaid", amountPaidFrac:0, method:"Cash at property", createdOffsetDays:0 }),
    mkBooking({ id:"BK-SEED-0007", source:"WhatsApp AI", name:"Pooja Reddy", phone:"9871234560", ci:addDays(today,5), co:addDays(today,6), guests:1, beds:["NAC-U4"], status:"Cancelled", paymentStatus:"Refunded", amountPaidFrac:0, method:"UPI", createdOffsetDays:-5 }),
    mkBooking({ id:"BK-SEED-0008", source:"Direct", name:"Arjun Mehta", phone:"9765432109", ci:addDays(today,-2), co:addDays(today,-1), guests:1, beds:["NAC-L3"], status:"No-show", paymentStatus:"Unpaid", amountPaidFrac:0, method:"Cash", createdOffsetDays:-7 }),
  ];
}
function buildInitialLeads() {
  const today = toISO(new Date());
  return [{ leadId:"LEAD-001", customerName:"Deepak Joshi", phone:"9812345670", reason:"Group booking — not sure which room fits 10 guests", preferredRoom:"Either", dates:`${fmtDateShort(addDays(today,20))} - ${fmtDateShort(addDays(today,22))}`, guests:10, budget:null, priority:"High", status:"New", createdAt: Date.now() - 2*3600000, conversationExcerpt:"10 log hain, group booking chahiye" }];
}
function buildInitialOta() {
  return [
    { id:"OTA-1", name:"Booking.com", icalUrl:"https://ical.booking.com/kushstay/property.ics", roomMapping:"Both Rooms", syncFrequency:"Every 30 minutes", status:"Active", lastSync: Date.now() - 3600000 },
    { id:"OTA-2", name:"Airbnb", icalUrl:"https://www.airbnb.com/calendar/ical/kushstay.ics", roomMapping:"Non-AC Dormitory", syncFrequency:"Every hour", status:"Active", lastSync: Date.now() - 7200000 },
    { id:"OTA-3", name:"MakeMyTrip", icalUrl:"https://ical.makemytrip.com/kushstay.ics", roomMapping:"Both Rooms", syncFrequency:"Every 2 hours", status:"Active", lastSync: Date.now() - 86400000 },
    { id:"OTA-4", name:"Goibibo", icalUrl:"https://ical.goibibo.com/kushstay.ics", roomMapping:"Both Rooms", syncFrequency:"Every 2 hours", status:"Paused", lastSync: null },
  ];
}
function buildInitialState() {
  return {
    bookings: buildInitialBookings(),
    leads: buildInitialLeads(),
    otaConnections: buildInitialOta(),
    blockedBeds: {},
    customerExtras: {},
    prices: { ...DEFAULT_PRICES },
    settings: { ...DEFAULT_SETTINGS },
    aiStats: { inquiries: 41, recommendationsGiven: 32, privateUpsellShown: 8 },
    passProducts: DEFAULT_PASS_PRODUCTS.map(p => ({ ...p })),
    passSettings: { ...DEFAULT_PASS_SETTINGS },
    passes: [],
    passLedger: [],
    passBookingsLink: [],
    passAuditLog: [],
    catalogActive: { "AC-Upper":true,"AC-Lower":true,"NAC-Upper":true,"NAC-Lower":true,"AC-Private":true,"NAC-Private":true },
  };
}

/* ---------------- SYSTEM TESTS (16 automated checks) ---------------- */
function runSystemTests(liveBookings, prices) {
  const results = [];
  const pass = (name, ok, detail) => results.push({ name, pass: !!ok, detail });
  const base = addDays(toISO(new Date()), 120);

  // 1. Create booking
  let bookings = liveBookings.slice();
  const b1 = { bookingId:"T1", bedIds:["AC-U1"], checkIn:addDays(base,0), checkOut:addDays(base,2), bookingStatus:"Confirmed", source:"Manual/Admin" };
  bookings = [...bookings, b1];
  pass("1. Create booking", getAvailableBeds("AC", addDays(base,0), addDays(base,2), bookings, [], {}).includes("AC-U1")===false, "AC-U1 now occupied for that range");

  // 2 & 3. Detect + prevent double booking
  const conflictFree = isBedFree("AC-U1", addDays(base,1), addDays(base,3), bookings, [], {});
  pass("2. Detect overlapping booking", conflictFree === false, "AC-U1 correctly shown as conflicting");
  pass("3. Prevent double booking", getAvailableBeds("AC", addDays(base,1), addDays(base,3), bookings, [], {}).includes("AC-U1") === false, "Second attempt on AC-U1 blocked");

  // 4. Checkout-date logic — exact spec example (AC-U1, 05->07 relative to base+30)
  const ciX = addDays(base,30), coX = addDays(base,32); // treat as the "05 Sep -> 07 Sep" example, 2 nights
  let bookingsX = [...liveBookings, { bookingId:"T4", bedIds:["AC-U1"], checkIn:ciX, checkOut:coX, bookingStatus:"Confirmed" }];
  const night1Free = isBedFree("AC-U1", ciX, addDays(ciX,1), bookingsX, [], {});
  const night2Free = isBedFree("AC-U1", addDays(ciX,1), coX, bookingsX, [], {});
  const checkoutNightFree = isBedFree("AC-U1", coX, addDays(coX,1), bookingsX, [], {});
  const rejectOverlap = !isBedFree("AC-U1", addDays(ciX,1), addDays(coX,1), bookingsX, [], {}); // 06->08 style, must be rejected
  const allowAdjacent = isBedFree("AC-U1", coX, addDays(coX,2), bookingsX, [], {}); // 07->09 style, must be allowed
  pass("4. Checkout-date logic (nights occupied, checkout night free, adjacent booking allowed, overlap rejected)",
    !night1Free && !night2Free && checkoutNightFree && rejectOverlap && allowAdjacent,
    `night1Free=${night1Free} night2Free=${night2Free} checkoutFree=${checkoutNightFree} rejectOverlap=${rejectOverlap} allowAdjacent=${allowAdjacent}`);

  // 5 & 6. Cancel booking + reopen availability
  let bookingsY = [...liveBookings, { bookingId:"T5", bedIds:["NAC-U1"], checkIn:addDays(base,40), checkOut:addDays(base,42), bookingStatus:"Confirmed" }];
  const beforeCancel = isBedFree("NAC-U1", addDays(base,40), addDays(base,42), bookingsY, [], {});
  pass("5. Cancel booking (bed shows occupied before cancel)", beforeCancel === false, "occupied as expected");
  const bookingsYCancelled = bookingsY.map(b => b.bookingId==="T5" ? {...b, bookingStatus:"Cancelled"} : b);
  const afterCancel = isBedFree("NAC-U1", addDays(base,40), addDays(base,42), bookingsYCancelled, [], {});
  pass("6. Reopen availability after cancellation", afterCancel === true, "bed free again");

  // 7. Change bed
  let bookingsZ = [...liveBookings, { bookingId:"T7", bedIds:["NAC-L1"], checkIn:addDays(base,50), checkOut:addDays(base,51), bookingStatus:"Confirmed" }];
  const movedBookings = bookingsZ.map(b => b.bookingId==="T7" ? {...b, bedIds:["NAC-L2"]} : b);
  const oldFree = isBedFree("NAC-L1", addDays(base,50), addDays(base,51), movedBookings, [], {});
  const newOccupied = !isBedFree("NAC-L2", addDays(base,50), addDays(base,51), movedBookings, [], {});
  pass("7. Change bed (old bed frees, new bed occupies)", oldFree && newOccupied, `oldFree=${oldFree} newOccupied=${newOccupied}`);

  // 8. Change dates
  let bookingsD = [...liveBookings, { bookingId:"T8", bedIds:["NAC-L3"], checkIn:addDays(base,60), checkOut:addDays(base,61), bookingStatus:"Confirmed" }];
  const movedDates = bookingsD.map(b => b.bookingId==="T8" ? {...b, checkIn:addDays(base,65), checkOut:addDays(base,66)} : b);
  const oldDateFree = isBedFree("NAC-L3", addDays(base,60), addDays(base,61), movedDates, [], {});
  const newDateOccupied = !isBedFree("NAC-L3", addDays(base,65), addDays(base,66), movedDates, [], {});
  pass("8. Change dates (old range frees, new range occupies)", oldDateFree && newDateOccupied, `oldFree=${oldDateFree} newOccupied=${newDateOccupied}`);

  // 9. Calculate nights
  const n = nightsBetween(addDays(base,0), addDays(base,3));
  pass("9. Calculate nights", n === 3, `nights=${n}`);

  // 10. Calculate total (2 nights, AC-Upper, no weekend in test window assumption checked structurally)
  const total10 = calcIndividualTotal(["AC-U1"], addDays(base,0), addDays(base,2), prices);
  const expectedRange = total10 >= prices["AC-Upper"]*2 && total10 <= Math.round(prices["AC-Upper"]*2*(1+prices.weekendSurchargePct/100));
  pass("10. Calculate total (bed rate × nights, weekend-aware)", expectedRange, `total=${total10}`);

  // 11. Payment balance
  const totalAmt = 1900, paid = 900, balance = totalAmt - paid;
  pass("11. Payment balance = total - amountPaid", balance === 1000, `balance=${balance}`);

  // 12. Export iCal
  const icsSample = [{ bookingId:"BK-TEST-0001", customerName:"Test Guest", bedIds:["AC-U1"], checkIn:addDays(base,0), checkOut:addDays(base,2), guestCount:1, bookingStatus:"Confirmed", source:"Direct", createdAt:Date.now() }];
  const ics = generateICS(icsSample, "Kush Stay Test");
  pass("12. Export iCal produces valid VEVENT block", ics.includes("BEGIN:VEVENT") && ics.includes("UID:BK-TEST-0001") && ics.includes("DTSTART"), "ICS text generated");

  // 13. Import iCal
  const parsed = parseICS(ics);
  pass("13. Import iCal parses events back out", parsed.length===1 && parsed[0].checkIn===addDays(base,0) && parsed[0].checkOut===addDays(base,2), `parsed=${JSON.stringify(parsed)}`);

  // 14. Export JSON
  const jsonStr = JSON.stringify({ bookings: liveBookings.slice(0,2) });
  let jsonOk = false; try { JSON.parse(jsonStr); jsonOk = true; } catch(e) {}
  pass("14. Export JSON produces valid JSON", jsonOk, "round-trippable");

  // 15. Import JSON round-trip
  const roundTrip = JSON.parse(jsonStr);
  pass("15. Import JSON restores equivalent data", Array.isArray(roundTrip.bookings) && roundTrip.bookings.length===2, "shape preserved");

  // 16. Reset demo data
  const fresh = buildInitialBookings();
  pass("16. Reset demo data restores seed set", fresh.length === 8, `seedCount=${fresh.length}`);

  return results;
}
/* ---------------- SHARED UI ATOMS ---------------- */
function Badge({ children, tone = "slate" }) {
  const tones = {
    slate: "bg-slate-100 text-slate-700", emerald: "bg-emerald-100 text-emerald-700",
    amber: "bg-amber-100 text-amber-800", rose: "bg-rose-100 text-rose-700",
    sky: "bg-sky-100 text-sky-700", teal: "bg-teal-100 text-teal-800",
    violet: "bg-violet-100 text-violet-700", orange: "bg-orange-100 text-orange-700",
  };
  return <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${tones[tone] || tones.slate}`}>{children}</span>;
}
function StatusBadge({ status }) {
  const map = {
    "Pending": "amber", "Confirmed": "sky", "Checked-in": "emerald", "Checked-out": "slate",
    "Cancelled": "rose", "No-show": "orange",
    "Unpaid": "rose", "Partially Paid": "amber", "Paid": "emerald", "Refunded": "slate",
  };
  return <Badge tone={map[status] || "slate"}>{status}</Badge>;
}
function SourceBadge({ source }) {
  return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium text-white" style={{ background: SOURCE_COLORS[source] || "#64748b" }}>{source}</span>;
}
function Card({ children, className = "" }) {
  return <div className={`bg-white rounded-2xl border border-stone-200 shadow-sm ${className}`}>{children}</div>;
}
function SectionTitle({ icon: Icon, title, sub, right }) {
  return (
    <div className="flex items-start justify-between mb-4 gap-3 flex-wrap">
      <div className="flex items-center gap-2.5">
        {Icon && <div className="w-9 h-9 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center flex-shrink-0"><Icon size={18} /></div>}
        <div>
          <h2 className="text-lg font-semibold text-stone-900 leading-tight">{title}</h2>
          {sub && <p className="text-sm text-stone-500 mt-0.5">{sub}</p>}
        </div>
      </div>
      {right}
    </div>
  );
}
function StatCard({ label, value, icon: Icon, tone = "slate", sub }) {
  const tones = { slate:"text-slate-700 bg-slate-50", emerald:"text-emerald-700 bg-emerald-50", amber:"text-amber-700 bg-amber-50", rose:"text-rose-700 bg-rose-50", sky:"text-sky-700 bg-sky-50", teal:"text-teal-700 bg-teal-50" };
  return (
    <Card className="p-4">
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium text-stone-500 uppercase tracking-wide">{label}</p>
        {Icon && <div className={`w-7 h-7 rounded-lg flex items-center justify-center ${tones[tone]}`}><Icon size={14} /></div>}
      </div>
      <p className="text-2xl font-semibold text-stone-900 mt-1.5">{value}</p>
      {sub && <p className="text-xs text-stone-500 mt-1">{sub}</p>}
    </Card>
  );
}
function EmptyState({ icon: Icon, text }) {
  return (
    <div className="flex flex-col items-center justify-center py-10 text-stone-400">
      {Icon && <Icon size={28} className="mb-2 opacity-60" />}
      <p className="text-sm">{text}</p>
    </div>
  );
}
function Modal({ open, onClose, title, children, wide }) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4" onClick={onClose}>
      <div className={`bg-white rounded-t-2xl sm:rounded-2xl w-full ${wide ? "sm:max-w-2xl" : "sm:max-w-md"} max-h-[90vh] overflow-y-auto`} onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between px-5 py-4 border-b border-stone-100 sticky top-0 bg-white rounded-t-2xl">
          <h3 className="font-semibold text-stone-900">{title}</h3>
          <button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-stone-100 flex items-center justify-center text-stone-500"><X size={16} /></button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}
function Field({ label, children }) {
  return <label className="block mb-3"><span className="block text-xs font-medium text-stone-600 mb-1">{label}</span>{children}</label>;
}
const inputCls = "w-full px-3 py-2 rounded-lg border border-stone-300 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent";
function TextInput(props) { return <input {...props} className={inputCls + " " + (props.className||"")} />; }
function Select({ value, onChange, options, className }) {
  return (
    <select value={value} onChange={onChange} className={inputCls + " bg-white " + (className||"")}>
      {options.map(o => <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>)}
    </select>
  );
}
function Btn({ children, onClick, tone = "primary", size = "md", icon: Icon, disabled, type="button", className="" }) {
  const tones = {
    primary: "bg-teal-700 text-white hover:bg-teal-800 disabled:bg-stone-300",
    secondary: "bg-stone-100 text-stone-700 hover:bg-stone-200",
    danger: "bg-rose-600 text-white hover:bg-rose-700",
    ghost: "text-teal-700 hover:bg-teal-50",
    outline: "border border-stone-300 text-stone-700 hover:bg-stone-50",
  };
  const sizes = { md: "px-4 py-2 text-sm", sm: "px-3 py-1.5 text-xs", lg: "px-5 py-2.5 text-sm" };
  return (
    <button type={type} onClick={onClick} disabled={disabled} className={`inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition-colors ${tones[tone]} ${sizes[size]} disabled:cursor-not-allowed ${className}`}>
      {Icon && <Icon size={14} />}{children}
    </button>
  );
}
function BedTile({ bedId, status, onClick }) {
  const meta = bedMeta(bedId);
  const styles = {
    available: "bg-emerald-50 border-emerald-300 text-emerald-800",
    held: "bg-amber-50 border-amber-300 text-amber-800",
    booked: "bg-rose-50 border-rose-300 text-rose-800",
    blocked: "bg-stone-200 border-stone-300 text-stone-500",
  };
  return (
    <button onClick={onClick} className={`border rounded-xl p-2.5 text-center transition-transform hover:scale-[1.03] ${styles[status]}`}>
      <div className="text-xs font-semibold">{bedId}</div>
      <div className="text-[10px] uppercase tracking-wide mt-0.5">{status}</div>
    </button>
  );
}
/* ---------------- WHATSAPP AI SCREEN ---------------- */
function ChatBubble({ msg, onQuickReply, onOptionSelect, onPaymentMethod, onPaymentOutcome, onHandoff }) {
  const isUser = msg.from === "user";
  const bubbleBase = "max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-line";
  if (msg.type === "text") {
    return (
      <div className={`flex flex-col ${isUser ? "items-end" : "items-start"} mb-1`}>
        <div className={`${bubbleBase} ${isUser ? "bg-teal-700 text-white rounded-br-md" : "bg-white border border-stone-200 text-stone-800 rounded-bl-md"}`}>{msg.text}</div>
        {!isUser && msg.quickReplies && msg.quickReplies.length > 0 && (
          <div className="flex flex-wrap gap-1.5 mt-1.5 ml-0.5">
            {msg.quickReplies.map((qr, i) => (
              <button key={i} onClick={() => onQuickReply(qr)} className="px-3 py-1.5 rounded-full border border-teal-600 text-teal-700 text-xs font-medium hover:bg-teal-50 bg-white">{qr}</button>
            ))}
          </div>
        )}
      </div>
    );
  }
  if (msg.type === "options") {
    return (
      <div className="flex flex-col items-start mb-1 w-full">
        <div className="space-y-2 w-full max-w-[92%]">
          {msg.options.map((opt, i) => (
            <div key={i} className="bg-white border border-stone-200 rounded-2xl p-3.5 rounded-bl-md">
              <div className="flex items-center justify-between mb-1">
                <span className="font-semibold text-stone-900 text-sm">{opt.type === "private" ? `Private ${opt.roomKey} Room` : `${opt.roomKey} · Individual Beds`}</span>
                {opt.recommended && <Badge tone="teal">Recommended</Badge>}
              </div>
              {opt.type === "individual" && <p className="text-xs text-stone-500 mb-1">{opt.bedIds.join(", ")}</p>}
              {opt.type === "private" && <p className="text-xs text-stone-500 mb-1">All 8 beds reserved just for your group</p>}
              <p className="text-sm text-stone-700">{fmtMoney(opt.nightly)}/night × {opt.nights} night{opt.nights>1?"s":""} = <span className="font-semibold">{fmtMoney(opt.total)}</span></p>
              <button onClick={() => onOptionSelect(opt)} className="mt-2 w-full py-1.5 rounded-lg bg-teal-700 text-white text-xs font-medium hover:bg-teal-800">Choose this</button>
            </div>
          ))}
        </div>
      </div>
    );
  }
  if (msg.type === "summary") {
    const d = msg.draft;
    return (
      <div className="flex flex-col items-start mb-1 w-full">
        <div className="bg-white border border-stone-200 rounded-2xl rounded-bl-md p-4 w-full max-w-[92%]">
          <p className="font-semibold text-stone-900 text-sm mb-0.5">Kush Stay</p>
          <p className="text-xs text-stone-500 mb-2">Booking Summary</p>
          <div className="text-sm space-y-0.5 text-stone-700">
            <p>Guests: {d.guestCount}</p>
            <p>Check-in: {fmtDate(d.checkIn)}</p>
            <p>Check-out: {fmtDate(d.checkOut)}</p>
            <p>Nights: {d.nights}</p>
            <p className="pt-1.5">{d.bookingType === "private" ? `Private ${d.roomKey} Room` : d.bedIds.join(", ")}</p>
            <p className="pt-1.5 font-semibold">Total: {fmtMoney(d.total)}</p>
          </div>
          <div className="grid grid-cols-3 gap-1.5 mt-3">
            <button onClick={() => onQuickReply("confirm booking")} className="py-1.5 rounded-lg bg-teal-700 text-white text-xs font-medium hover:bg-teal-800">CONFIRM</button>
            <button onClick={() => onQuickReply("change")} className="py-1.5 rounded-lg bg-stone-100 text-stone-700 text-xs font-medium hover:bg-stone-200">CHANGE</button>
            <button onClick={() => onQuickReply("talk to staff")} className="py-1.5 rounded-lg bg-stone-100 text-stone-700 text-xs font-medium hover:bg-stone-200">STAFF</button>
          </div>
        </div>
      </div>
    );
  }
  if (msg.type === "payment") {
    return (
      <div className="flex flex-col items-start mb-1 w-full">
        <div className="bg-white border border-stone-200 rounded-2xl rounded-bl-md p-4 w-full max-w-[92%]">
          {msg.status === "choosing" && (<>
            <p className="text-sm font-medium text-stone-800 mb-2">Choose a payment method:</p>
            <div className="grid grid-cols-2 gap-1.5">
              {["UPI","Card","Cash at property","Pay later"].map(m => (
                <button key={m} onClick={() => onPaymentMethod(m)} className="py-1.5 rounded-lg border border-stone-300 text-xs font-medium hover:bg-stone-50">{m}</button>
              ))}
            </div>
          </>)}
          {msg.status === "processing" && (<>
            <p className="text-sm font-medium text-stone-800 mb-1">{msg.method} payment · Processing…</p>
            {msg.method !== "Cash at property" && msg.method !== "Pay later" && (
              <div className="border border-dashed border-stone-300 rounded-lg p-4 text-center text-xs text-stone-400 my-2">Simulated {msg.method} screen (₹{msg.amount})</div>
            )}
            <p className="text-[11px] text-stone-400 mb-2">(Simulated — no real payment gateway connected)</p>
            <div className="grid grid-cols-2 gap-1.5">
              <button onClick={() => onPaymentOutcome(true)} className="py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700">Simulate success</button>
              <button onClick={() => onPaymentOutcome(false)} className="py-1.5 rounded-lg bg-rose-100 text-rose-700 text-xs font-medium hover:bg-rose-200">Simulate failure</button>
            </div>
          </>)}
          {msg.status === "success" && (<p className="text-sm text-emerald-700 font-medium flex items-center gap-1.5"><CheckCircle2 size={16}/> Payment successful</p>)}
          {msg.status === "failed" && (<p className="text-sm text-rose-600 font-medium flex items-center gap-1.5"><XCircle size={16}/> Payment failed — you can retry or pay at the property.</p>)}
        </div>
      </div>
    );
  }
  if (msg.type === "confirmation") {
    const b = msg.booking;
    return (
      <div className="flex flex-col items-start mb-1 w-full">
        <div className="bg-emerald-50 border border-emerald-200 rounded-2xl rounded-bl-md p-4 w-full max-w-[92%]">
          <p className="font-semibold text-emerald-800 text-sm mb-2 flex items-center gap-1.5"><CheckCircle2 size={16}/> Booking confirmed successfully</p>
          <div className="text-sm space-y-0.5 text-stone-700">
            <p>Check-in: {fmtDate(b.checkIn)} · Check-out: {fmtDate(b.checkOut)}</p>
            <p>Guests: {b.guestCount}</p>
            <p>{b.bookingType === "private" ? `Private ${b.roomKey} Room` : b.bedIds.join(", ")}</p>
            <p>Total: {fmtMoney(b.total)}</p>
            <p className="font-mono text-xs pt-1 text-stone-500">Booking ID: {b.bookingId}</p>
          </div>
        </div>
      </div>
    );
  }
  if (msg.type === "handoff") {
    return (
      <div className="flex flex-col items-start mb-1 w-full">
        <div className="bg-white border border-stone-200 rounded-2xl rounded-bl-md p-3.5 w-full max-w-[92%]">
          <p className="text-sm text-stone-800 mb-2">Sure — our booking team can call you.</p>
          <div className="grid grid-cols-3 gap-1.5">
            <button onClick={() => onHandoff("now")} className="py-1.5 rounded-lg bg-teal-700 text-white text-xs font-medium">CALL ME NOW</button>
            <button onClick={() => onHandoff("later")} className="py-1.5 rounded-lg bg-stone-100 text-stone-700 text-xs font-medium">CALL LATER</button>
            <button onClick={() => onHandoff("continue")} className="py-1.5 rounded-lg bg-stone-100 text-stone-700 text-xs font-medium">CONTINUE CHAT</button>
          </div>
        </div>
      </div>
    );
  }
  return null;
}

function ScenarioMenu({ onRun, running }) {
  const scenarios = [
    { id:1, label:"2 guests, AC, individual beds" }, { id:2, label:"4 guests, Non-AC" },
    { id:3, label:"6 guests, private-room upsell" }, { id:4, label:"8 guests, full private AC" },
    { id:5, label:"Private AC unavailable (1 bed taken)" }, { id:6, label:"10 guests across both rooms" },
    { id:7, label:"Requests lower beds" }, { id:8, label:"Requests a callback" },
    { id:9, label:"External OTA booking imported" }, { id:10, label:"Payment failure" }, { id:11, label:"Booking cancellation" },
  ];
  return (
    <div className="grid grid-cols-1 gap-1 max-h-64 overflow-y-auto">
      {scenarios.map(s => (
        <button key={s.id} disabled={running} onClick={() => onRun(s.id)} className="text-left px-2.5 py-1.5 rounded-lg hover:bg-teal-50 text-xs text-stone-700 disabled:opacity-40 flex items-center gap-2">
          <span className="w-4 h-4 rounded-full bg-teal-100 text-teal-700 text-[10px] flex items-center justify-center flex-shrink-0">{s.id}</span>{s.label}
        </button>
      ))}
    </div>
  );
}
/* ---------------- DIALOGUE MANAGER (pure function) ---------------- */
const T = {
  greet: { hi: "Namaste! 👋 Kush Stay mein aapka swagat hai. Aap kis date ko check-in karna chahenge, aur kitne guests hain?", en: "Welcome to Kush Stay! 👋 What check-in date works for you, and how many guests?" },
  askDates: { hi: "Bilkul 😊 Aap kis date ko check-in aur check-out karenge?", en: "Sure! What check-in and check-out dates would you like?" },
  askCheckoutOnly: { hi: "Aur check-out kis date ko hoga?", en: "And what date will you check out?" },
  askGuests: { hi: "Kitne guests ke liye booking chahiye?", en: "How many guests is this booking for?" },
  askAc: { hi: "AC ya Non-AC?", en: "Would you like AC or Non-AC?" },
  askPrivateOrIndividual: { hi: "Individual beds chahiye ya agar available ho to private room?", en: "Individual beds, or a private room if available?" },
  askBedPref: { hi: "Upper bed chahiye ya Lower? (ya 'any' bhi keh sakte hain)", en: "Upper or Lower bed? (or say 'any')" },
  askName: { hi: "Booking ke liye guest ka naam bata dijiye.", en: "Could I get the guest's name for the booking?" },
  askPhone: { hi: "Aur 10-digit mobile number?", en: "And a 10-digit mobile number?" },
  noAvailability: { hi: "Sorry, फिलहाल आपकी dates के लिए requested option available नहीं है। Aap dates badal sakte hain ya humari staff se baat kar sakte hain.", en: "Sorry, that option isn't available for these dates. You could try different dates, or talk to our staff." },
  confused: { hi: "मैं इसे booking team से confirm करवा सकता हूँ.", en: "I can have our booking team confirm this for you." },
  cancelAskId: { hi: "Zaroor. Kripya apni Booking ID ya registered mobile number batayein.", en: "Sure — could you share your Booking ID or registered mobile number?" },
  cancelNotFound: { hi: "Mujhe yeh booking nahi mili. Kya aap Booking ID ya mobile number dobara check kar sakte hain?", en: "I couldn't find that booking. Could you double-check the Booking ID or mobile number?" },
  cancelConfirmAsk: { hi: "Kya aap sach mein yeh booking cancel karna chahte hain?", en: "Are you sure you'd like to cancel this booking?" },
  cancelled: { hi: "Aapki booking cancel kar di gayi hai aur beds release ho gaye hain.", en: "Your booking has been cancelled and the beds are now released." },
  askWhatChange: { hi: "Kya change karna hai — dates, guests, AC/Non-AC, ya bed type?", en: "What would you like to change — dates, guests, AC/Non-AC, or bed type?" },
};
function pick(entry, lang) { return lang === "english" ? entry.en : entry.hi; }

function applyPrivatePrefFilter(options, privatePref) {
  if (privatePref === true) { const onlyPrivate = options.filter(o=>o.type==="private"); return onlyPrivate.length ? onlyPrivate : options; }
  if (privatePref === false) return options.filter(o=>o.type==="individual");
  return options;
}
function buildRecommendation(slots, bookings, holds, blocked, prices) {
  const { checkIn, checkOut, guestCount, acPref, bedPref, privatePref } = slots;
  const nights = nightsBetween(checkIn, checkOut);
  const options = [];
  if (guestCount > 8) {
    // No single room holds more than 8 — always pool both, but fill the preferred room first.
    const acBeds = getAvailableBeds("AC", checkIn, checkOut, bookings, holds, blocked);
    const nacBeds = getAvailableBeds("NAC", checkIn, checkOut, bookings, holds, blocked);
    const combined = acPref === false ? [...nacBeds, ...acBeds] : [...acBeds, ...nacBeds];
    if (combined.length >= guestCount) {
      const chosen = combined.slice(0, guestCount);
      options.push({ type:"individual", roomKey:"AC+NAC", bedIds: chosen, nights, nightly: calcIndividualTotal(chosen, checkIn, addDays(checkIn,1), prices), total: calcIndividualTotal(chosen, checkIn, checkOut, prices) });
    }
    const bothPrivate = isPrivateAvailable("AC",checkIn,checkOut,bookings,holds,blocked).available && isPrivateAvailable("NAC",checkIn,checkOut,bookings,holds,blocked).available;
    let privOpt8 = null;
    if (bothPrivate) {
      const total = calcPrivateTotal("AC",checkIn,checkOut,prices) + calcPrivateTotal("NAC",checkIn,checkOut,prices);
      privOpt8 = { type:"private", roomKey:"AC+NAC", nights, nightly: prices["AC-Private"]+prices["NAC-Private"], total };
    }
    const indivOpt8 = options[0];
    if (indivOpt8 && privOpt8) { if (privOpt8.total <= indivOpt8.total) privOpt8.recommended = true; else indivOpt8.recommended = true; }
    else if (privOpt8) privOpt8.recommended = true;
    if (privOpt8) options.push(privOpt8);
    return applyPrivatePrefFilter(options, privatePref);
  }
  const rooms = acPref === true ? ["AC"] : acPref === false ? ["NAC"] : ["AC","NAC"];
  rooms.forEach(roomKey => {
    const chosen = chooseBeds(roomKey, guestCount, checkIn, checkOut, bookings, holds, blocked, bedPref && bedPref!=="Any" ? bedPref : null);
    let indivOpt = null, privOpt = null;
    if (chosen.length === guestCount) {
      indivOpt = { type:"individual", roomKey, bedIds: chosen, nights, nightly: calcIndividualTotal(chosen, checkIn, addDays(checkIn,1), prices), total: calcIndividualTotal(chosen, checkIn, checkOut, prices) };
    }
    const priv = isPrivateAvailable(roomKey, checkIn, checkOut, bookings, holds, blocked);
    if (priv.available) {
      const total = calcPrivateTotal(roomKey, checkIn, checkOut, prices);
      privOpt = { type:"private", roomKey, nights, nightly: prices[`${roomKey}-Private`], total };
    }
    if (indivOpt && privOpt) { if (privOpt.total <= indivOpt.total) privOpt.recommended = true; else indivOpt.recommended = true; }
    else if (privOpt) privOpt.recommended = true;
    if (indivOpt) options.push(indivOpt);
    if (privOpt) options.push(privOpt);
  });
  return applyPrivatePrefFilter(options, privatePref);
}

function processMessage(rawText, C) {
  const text = rawText.trim();
  const lower = text.toLowerCase();
  const lang = looksEnglish(text) ? "english" : (C.lang === "english" && !/chahiye|hai|kya|kaise|karo|log|logon/.test(lower) ? "english" : (/chahiye|hai|kya|kaise|karo|log|logon|raat|chalega/.test(lower) ? "hinglish" : C.lang));
  const out = { newMessages: [], slotsPatch: {}, stagePatch: null, langPatch: lang, sideEffects: {}, confusionDelta: -1, lastOptions: undefined, decisionTrace: undefined };
  const say = (entry, extra) => out.newMessages.push({ id: uid("m"), from:"ai", type:"text", text: pick(entry, lang) + (extra ? " " + extra : "") });
  const sayRaw = (str, quickReplies) => out.newMessages.push({ id: uid("m"), from:"ai", type:"text", text: str, quickReplies });

  // Global interrupts
  if (isCancelIntent(lower) && C.stage !== "cancel_awaiting_id" && C.stage !== "cancel_confirm") {
    out.stagePatch = "cancel_awaiting_id"; say(T.cancelAskId); return out;
  }
  if (isCallbackIntent(lower) && C.stage !== "handoff_shown") {
    out.newMessages.push({ id: uid("m"), from:"ai", type:"handoff" });
    return out;
  }

  if (C.stage === "cancel_awaiting_id") {
    const phone = extractPhone(text);
    const idMatch = text.toUpperCase().match(/BK-[0-9-]+/);
    const found = C.bookings.find(b => (idMatch && b.bookingId === idMatch[0]) || (phone && b.customerPhone === phone && b.bookingStatus !== "Cancelled"));
    if (!found) { say(T.cancelNotFound); return out; }
    out.slotsPatch.cancelTargetId = found.bookingId;
    out.stagePatch = "cancel_confirm";
    sayRaw(`${found.bookingId} · ${fmtDate(found.checkIn)} → ${fmtDate(found.checkOut)} · ${found.bedIds.join(", ")} · ${fmtMoney(found.total)}\n\n${pick(T.cancelConfirmAsk, lang)}`, ["Yes, cancel it", "No, keep it"]);
    return out;
  }
  if (C.stage === "cancel_confirm") {
    if (isConfirm(lower) || /yes/.test(lower)) {
      out.sideEffects.cancelBookingId = C.slots.cancelTargetId;
      say(T.cancelled);
      out.stagePatch = "idle"; out.slotsPatch = { checkIn:null, checkOut:null, guestCount:null, acPref:null, bedPref:null, privatePref:null };
    } else {
      sayRaw(lang==="english" ? "No problem, your booking stays as is." : "Koi baat nahi, aapki booking waisi hi rahegi.");
      out.stagePatch = "idle";
    }
    return out;
  }

  if (isPaymentQuery(lower)) {
    sayRaw(lang==="english" ? "You can pay via UPI, Card, Cash at the property, or Pay later — I'll show these options once your booking is confirmed." : "Aap UPI, Card, Cash at property, ya Pay later se pay kar sakte hain — booking confirm hone ke baad main yeh options dikhaunga.");
    return out;
  }

  // Entity extraction merged into slots
  const patch = {};
  const g = extractGuests(text); if (g) patch.guestCount = g;
  const d = extractDates(text, C.todayIso);
  if (d.checkIn) patch.checkIn = d.checkIn;
  if (d.checkOut) patch.checkOut = d.checkOut;
  const ac = extractAcPref(text); if (ac !== null) patch.acPref = ac;
  const bp = extractBedPref(text); if (bp) patch.bedPref = bp;
  const pp = extractPrivatePref(text); if (pp !== null) patch.privatePref = pp;
  Object.assign(out.slotsPatch, patch);
  const mergedSlots = { ...C.slots, ...patch };
  const gotSomething = Object.keys(patch).length > 0;
  // Stating a bed type only makes sense for individual beds — infer privatePref=false if it was still open.
  if (mergedSlots.bedPref && (mergedSlots.privatePref === null || mergedSlots.privatePref === undefined)) {
    out.slotsPatch.privatePref = false; mergedSlots.privatePref = false;
  }

  if ((C.stage === "idle" || C.stage === "collecting" || C.stage === "recommending") && isPrivateAvailabilityQuery(lower) && mergedSlots.checkIn && mergedSlots.checkOut && mergedSlots.checkOut > mergedSlots.checkIn) {
    const rooms = mergedSlots.acPref === true ? ["AC"] : mergedSlots.acPref === false ? ["NAC"] : ["AC","NAC"];
    const lines = rooms.map(rk => {
      const p = isPrivateAvailable(rk, mergedSlots.checkIn, mergedSlots.checkOut, C.bookings, C.holds, C.blocked);
      const roomLabel = rk === "AC" ? "AC" : "Non-AC";
      return p.available
        ? (lang === "english" ? `Yes — the Private ${roomLabel} Room is available for these dates.` : `Haan — Private ${roomLabel} Room in dates ke liye available hai.`)
        : (lang === "english" ? `Private ${roomLabel} Room isn't available: ${p.reason}.` : `Private ${roomLabel} Room abhi available nahi hai: ${p.reason}.`);
    });
    sayRaw(lines.join("\n"));
    out.stagePatch = "collecting";
    return out;
  }

  if (C.stage === "awaiting_name") {
    if (!text) { say(T.askName); return out; }
    out.slotsPatch.customerName = text;
    if (mergedSlots.customerPhone) { out.stagePatch = "summary"; pushSummary(out, { ...mergedSlots, customerName: text }, C, lang); }
    else { out.stagePatch = "awaiting_phone"; say(T.askPhone); }
    return out;
  }
  if (C.stage === "awaiting_phone") {
    const phone = extractPhone(text);
    if (!phone) { sayRaw(lang==="english" ? "That doesn't look like a 10-digit number — could you try again?" : "Yeh 10-digit number jaisa nahi lag raha — dobara try karein?"); return out; }
    out.slotsPatch.customerPhone = phone;
    out.stagePatch = "summary";
    pushSummary(out, { ...mergedSlots, customerPhone: phone }, C, lang);
    return out;
  }
  if (C.stage === "summary") {
    if (isConfirm(lower)) {
      out.stagePatch = "awaiting_payment_method";
      out.newMessages.push({ id: uid("m"), from:"ai", type:"payment", status:"choosing" });
      return out;
    }
    if (isTalkToStaff(lower)) { out.newMessages.push({ id: uid("m"), from:"ai", type:"handoff" }); return out; }
    if (isChangeIntent(lower) || /change/.test(lower)) { out.stagePatch = "collecting"; say(T.askWhatChange); return out; }
    out.confusionDelta = 1;
    sayRaw(lang==="english" ? "You can tap CONFIRM, CHANGE, or TALK TO STAFF above." : "Aap upar CONFIRM, CHANGE, ya TALK TO STAFF tap kar sakte hain.");
    return out;
  }

  // collecting / recommending / idle
  if (!gotSomething && C.stage !== "idle") out.confusionDelta = 1;
  if (mergedSlots.checkIn && mergedSlots.checkOut && mergedSlots.checkOut <= mergedSlots.checkIn) {
    out.slotsPatch.checkOut = null; out.slotsPatch.checkIn = null; mergedSlots.checkOut = null; mergedSlots.checkIn = null;
    out.stagePatch = "collecting";
    sayRaw(lang === "english" ? "Those dates don't quite work — could you give me a check-in and a later check-out date?" : "Yeh dates sahi nahi hain — check-in aur uske baad ki check-out date batayein?");
    return out;
  }
  if (mergedSlots.checkIn && !mergedSlots.checkOut) { out.stagePatch = "collecting"; say(T.askCheckoutOnly); return out; }
  if (!mergedSlots.checkIn || !mergedSlots.checkOut) { out.stagePatch = "collecting"; say(gotSomething ? T.askDates : T.greet); return out; }
  if (!mergedSlots.guestCount) { out.stagePatch = "collecting"; say(T.askGuests); return out; }
  if (mergedSlots.acPref === null || mergedSlots.acPref === undefined) { out.stagePatch = "collecting"; say(T.askAc); return out; }
  if (mergedSlots.guestCount <= 8 && (mergedSlots.privatePref === null || mergedSlots.privatePref === undefined)) {
    const roomKey = mergedSlots.acPref ? "AC" : "NAC";
    const priv = isPrivateAvailable(roomKey, mergedSlots.checkIn, mergedSlots.checkOut, C.bookings, C.holds, C.blocked);
    if (priv.available && mergedSlots.guestCount >= 3) {
      out.stagePatch = "collecting"; say(T.askPrivateOrIndividual);
      out.sideEffects.incrementStats = ["privateUpsellShown"];
      return out;
    }
    out.slotsPatch.privatePref = false; mergedSlots.privatePref = false;
  }
  if (mergedSlots.privatePref === false && !mergedSlots.bedPref) {
    const roomKey = mergedSlots.acPref ? "AC" : "NAC";
    const wanted = mergedSlots.bedPref;
    // ask bed pref once, then move on regardless of answer next turn
    if (!C.slots._askedBed) { out.slotsPatch._askedBed = true; out.stagePatch = "collecting"; say(T.askBedPref); return out; }
  }

  // Ready to recommend
  const roomKeyForCheck = mergedSlots.acPref === true ? "AC" : mergedSlots.acPref === false ? "NAC" : null;
  if (mergedSlots.bedPref && mergedSlots.privatePref === false && roomKeyForCheck) {
    const avail = getAvailableBeds(roomKeyForCheck, mergedSlots.checkIn, mergedSlots.checkOut, C.bookings, C.holds, C.blocked, mergedSlots.bedPref === "Any" ? null : mergedSlots.bedPref);
    if (mergedSlots.bedPref !== "Any" && avail.length < mergedSlots.guestCount) {
      const altType = mergedSlots.bedPref === "Lower" ? "Upper" : "Lower";
      const alt = getAvailableBeds(roomKeyForCheck, mergedSlots.checkIn, mergedSlots.checkOut, C.bookings, C.holds, C.blocked, altType);
      sayRaw(lang==="english"
        ? `${mergedSlots.bedPref} beds aren't available right now, but ${alt.length} ${altType} bed(s) are. Would ${altType} work?`
        : `${mergedSlots.bedPref} beds फिलहाल available नहीं हैं, लेकिन ${alt.length} ${altType} beds available हैं. क्या आप ${altType} ले सकते हैं?`, [altType, "Any available bed"]);
      return out;
    }
  }
  const options = buildRecommendation(mergedSlots, C.bookings, C.holds, C.blocked, C.prices);
  out.sideEffects.incrementStats = [...(out.sideEffects.incrementStats||[]), "recommendationsGiven"];
  out.decisionTrace = {
    guests: mergedSlots.guestCount, dates: `${fmtDate(mergedSlots.checkIn)} → ${fmtDate(mergedSlots.checkOut)}`,
    acPref: mergedSlots.acPref === null ? "Either" : mergedSlots.acPref ? "AC" : "Non-AC",
    privatePref: mergedSlots.privatePref, availableOptions: options.length,
    recommendation: options.find(o=>o.recommended) ? (options.find(o=>o.recommended).type==="private"?`Private ${options.find(o=>o.recommended).roomKey} Room`:"Individual beds") : (options[0] ? (options[0].type==="private"?"Private Room":"Individual beds") : "None available"),
    reason: options.length ? "Computed live from current bed-level availability and configured pricing." : "No beds/rooms free for the requested dates.",
  };
  if (options.length === 0) { out.stagePatch = "collecting"; say(T.noAvailability); return out; }
  out.stagePatch = "recommending";
  out.lastOptions = options;
  sayRaw(lang==="english" ? "Here's what I'd recommend:" : "Yeh raha best option aapke liye:");
  const hasPrivate = options.some(o=>o.type==="private");
  const hasIndividual = options.some(o=>o.type==="individual");
  if (hasPrivate && hasIndividual && mergedSlots.guestCount >= 4) {
    out.newMessages.push({ id: uid("m"), from:"ai", type:"text", text: lang==="english" ? "The entire room would be reserved exclusively for your group." : "पूरा room आपके group के लिए reserved रहेगा." });
  }
  out.newMessages.push({ id: uid("m"), from:"ai", type:"options", options });
  return out;
}
function pushSummary(out, slots, C, lang) {
  const draft = buildDraftFromSlots(slots, C);
  out.newMessages.push({ id: uid("m"), from:"ai", type:"summary", draft });
}
function buildDraftFromSlots(slots, C) {
  const nights = nightsBetween(slots.checkIn, slots.checkOut);
  let bedIds = slots.chosenOption && slots.chosenOption.type === "individual" ? slots.chosenOption.bedIds : [];
  let total = slots.chosenOption ? slots.chosenOption.total : 0;
  return {
    checkIn: slots.checkIn, checkOut: slots.checkOut, nights, guestCount: slots.guestCount,
    bookingType: slots.chosenOption ? slots.chosenOption.type : "individual",
    roomKey: slots.chosenOption ? slots.chosenOption.roomKey : (slots.acPref ? "AC" : "NAC"),
    bedIds, total, customerName: slots.customerName, customerPhone: slots.customerPhone,
  };
}
function WhatsAppScreen({ ctx }) {
  const [messages, setMessages] = useState(() => [{ id: uid("m"), from:"ai", type:"text", text: pick(T.greet, "hinglish") }]);
  const [slots, setSlots] = useState({});
  const [stage, setStage] = useState("idle");
  const [lang, setLang] = useState("hinglish");
  const [confusionCount, setConfusionCount] = useState(0);
  const [lastOptions, setLastOptions] = useState([]);
  const [decisionTrace, setDecisionTrace] = useState(null);
  const [inputText, setInputText] = useState("");
  const [scenarioRunning, setScenarioRunning] = useState(false);
  const [showScenarios, setShowScenarios] = useState(false);
  const scrollRef = useRef(null);

  useEffect(() => { if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight; }, [messages]);

  function applyResult(userText, result) {
    setMessages(prev => [...prev, ...(userText ? [{ id: uid("m"), from:"user", type:"text", text:userText }] : []), ...result.newMessages]);
    if (result.slotsPatch && Object.keys(result.slotsPatch).length) setSlots(prev => ({ ...prev, ...result.slotsPatch }));
    if (result.stagePatch) setStage(result.stagePatch);
    if (result.langPatch) setLang(result.langPatch);
    if (result.lastOptions) setLastOptions(result.lastOptions);
    if (result.decisionTrace) setDecisionTrace(result.decisionTrace);
    if (typeof result.confusionDelta === "number") setConfusionCount(c => Math.max(0, c + result.confusionDelta));
    const se = result.sideEffects || {};
    if (se.cancelBookingId) ctx.setBookings(prev => prev.map(b => b.bookingId === se.cancelBookingId ? { ...b, bookingStatus:"Cancelled", paymentStatus: b.amountPaid>0 ? "Refunded" : b.paymentStatus, updatedAt: Date.now() } : b));
    if (se.incrementStats) ctx.setAiStats(prev => { const next = { ...prev }; se.incrementStats.forEach(k => { next[k] = (next[k]||0)+1; }); return next; });
    if (confusionCount + (result.confusionDelta||0) >= 2 && result.stagePatch !== "cancel_awaiting_id") {
      setTimeout(() => { setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: pick(T.confused, result.langPatch||lang) }, { id: uid("m"), from:"ai", type:"handoff" }]); setConfusionCount(0); }, 300);
    }
  }

  function sendText(raw) {
    if (!raw || !raw.trim()) return;
    if (stage === "idle") ctx.setAiStats(prev => ({ ...prev, inquiries: prev.inquiries + 1 }));
    const C = { slots, stage, lang, bookings: ctx.bookings, holds: ctx.holds, blocked: ctx.blockedBeds, prices: ctx.prices, todayIso: ctx.todayIso };
    const result = processMessage(raw, C);
    applyResult(raw, result);
    setInputText("");
  }

  function chooseOption(opt) {
    setMessages(prev => [...prev, { id: uid("m"), from:"user", type:"text", text: opt.type === "private" ? `Private ${opt.roomKey} Room` : `${opt.roomKey} beds: ${opt.bedIds.join(", ")}` }]);
    // hold beds for 10 minutes
    const bedIds = opt.type === "private" ? ROOMS[opt.roomKey.split("+")[0]]?.beds || [] : opt.bedIds;
    if (opt.type === "individual" || (opt.type === "private" && !opt.roomKey.includes("+"))) {
      const realBeds = opt.type === "private" ? ROOMS[opt.roomKey].beds : opt.bedIds;
      const holdId = uid("HOLD");
      ctx.setHolds(prev => [...prev, { holdId, bedIds: realBeds, checkIn: slots.checkIn, checkOut: slots.checkOut, createdAt: ctx.virtualNow(), expiresAt: ctx.virtualNow() + 10*60*1000 }]);
      setSlots(prev => ({ ...prev, chosenOption: opt, holdId }));
    } else {
      setSlots(prev => ({ ...prev, chosenOption: opt }));
    }
    if (!slots.customerName) { setStage("awaiting_name"); setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: pick(T.askName, lang) }]); }
    else if (!slots.customerPhone) { setStage("awaiting_phone"); setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: pick(T.askPhone, lang) }]); }
    else { setStage("summary"); const draft = buildDraftFromSlots({ ...slots, chosenOption: opt }, {}); setMessages(p => [...p, { id: uid("m"), from:"ai", type:"summary", draft }]); }
  }

  function choosePaymentMethod(method) {
    const draft = buildDraftFromSlots(slots, {});
    setMessages(prev => [...prev, { id: uid("m"), from:"user", type:"text", text: method }, { id: uid("m"), from:"ai", type:"payment", status:"processing", method, amount: draft.total }]);
    if (method === "Cash at property" || method === "Pay later") {
      setTimeout(() => resolvePayment(true, method), 400);
    }
  }

  async function resolvePayment(success, method) {
    const draft = buildDraftFromSlots(slots, {});
    setMessages(prev => prev.map(m => (m.type === "payment" && m.status === "processing") ? { ...m, status: success ? "success" : "failed" } : m));
    if (!success) {
      setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: lang==="english" ? "Payment didn't go through. You can try again or pay at the property." : "Payment complete नहीं हुआ. आप फिर से try कर सकते हैं या payment बाद में कर सकते हैं.", quickReplies:["Try again","Cash at property"] }]);
      return;
    }
    const isPayNow = method === "UPI" || method === "Card";

    if (ctx.settings.dataMode === "production") {
      try {
        const booking = await apiClient.createBooking(ctx.settings, {
          property_id: ctx.settings.apiPropertyId, customer_name: slots.customerName, customer_phone: slots.customerPhone,
          source: "WhatsApp AI", check_in: draft.checkIn, check_out: draft.checkOut, guest_count: draft.guestCount,
          booking_type: draft.bookingType, room_id: ctx.roomIdByKey?.(draft.roomKey), bed_ids: draft.bedIds,
          payment_method: method, amount_paid: isPayNow ? draft.total : 0,
        });
        if (slots.holdId) ctx.setHolds(prev => prev.filter(h => h.holdId !== slots.holdId));
        setTimeout(() => setMessages(p => [...p, { id: uid("m"), from:"ai", type:"confirmation", booking: { ...draft, bookingId: booking.booking_ref, paymentMethod: method } }]), 300);
      } catch (err) {
        setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: err.status === 409
          ? (lang==="english" ? "Sorry — someone just booked one of these beds. Please try different dates or beds." : "Sorry — yeh bed abhi kisi aur ne book kar li. Kripya doosri dates ya beds try karein.")
          : (lang==="english" ? "Couldn't reach the booking server. Please try again." : "Booking server tak nahi pahunch paaye. Dobara try karein.") }]);
      }
      setStage("idle"); setSlots({});
      return;
    }

    const now = new Date(ctx.virtualNow());
    const bookingId = makeBookingId(ctx.bookings, now);
    const booking = {
      bookingId, source:"WhatsApp AI", customerName: slots.customerName, customerPhone: slots.customerPhone, customerEmail:"",
      checkIn: draft.checkIn, checkOut: draft.checkOut, guestCount: draft.guestCount, bookingType: draft.bookingType,
      roomKey: draft.roomKey, bedIds: draft.bookingType === "private" ? (ROOMS[draft.roomKey]?.beds || []) : draft.bedIds,
      nights: draft.nights, subtotal: draft.total, discount:0, tax:0, total: draft.total,
      amountPaid: isPayNow ? draft.total : 0, balance: isPayNow ? 0 : draft.total,
      paymentStatus: isPayNow ? "Paid" : "Unpaid", paymentMethod: method,
      bookingStatus:"Confirmed", externalBookingId:null, specialRequest:"", createdAt: Date.now(), updatedAt: Date.now(),
    };
    ctx.setBookings(prev => [...prev, booking]);
    if (slots.holdId) ctx.setHolds(prev => prev.filter(h => h.holdId !== slots.holdId));
    ctx.setAiStats(prev => ({ ...prev }));
    setTimeout(() => setMessages(p => [...p, { id: uid("m"), from:"ai", type:"confirmation", booking }]), 300);
    setStage("idle");
    setSlots({});
  }

  function handleHandoff(choice) {
    setMessages(prev => [...prev, { id: uid("m"), from:"user", type:"text", text: choice === "now" ? "CALL ME NOW" : choice === "later" ? "CALL LATER" : "CONTINUE CHAT" }]);
    if (choice === "continue") { setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: pick({hi:"Bilkul, batayein kaise madad karun?", en:"Sure, how can I help?"}, lang) }]); return; }
    const lead = {
      leadId: makeLeadId(ctx.leads), customerName: slots.customerName || "WhatsApp guest", phone: slots.customerPhone || "—",
      reason: choice === "now" ? "Requested immediate callback" : "Requested callback at a later time",
      preferredRoom: slots.acPref === true ? "AC" : slots.acPref === false ? "Non-AC" : "Either",
      dates: slots.checkIn ? `${fmtDateShort(slots.checkIn)} - ${fmtDateShort(slots.checkOut||slots.checkIn)}` : "Not specified",
      guests: slots.guestCount || null, budget: null, priority: choice === "now" ? "High" : "Normal",
      status:"New", createdAt: Date.now(), conversationExcerpt: messages.slice(-3).map(m=>m.text).filter(Boolean).join(" / "),
    };
    ctx.setLeads(prev => [...prev, lead]);
    setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: pick({hi:`Ho gaya! Hamari team jald hi ${choice==="now"?"aapko abhi":"aapko"} call karegi. (Lead ${lead.leadId} create ho gaya)`, en:`Done! Our team will call you ${choice==="now"?"shortly":"soon"}. (Lead ${lead.leadId} created)`}, lang) }]);
  }

  async function runScenario(id) {
    setShowScenarios(false); setScenarioRunning(true);
    const scripts = {
      1: ["Mujhe 2 logon ke liye room chahiye","15 se 17 September","AC chahiye","Any available bed"],
      2: ["Hum 4 friends hain","20 se 22 September","Non AC chalega"],
      3: ["Hum 6 friends hain","25 se 27 September","AC chahiye"],
      4: ["Hum 8 log hain aur pura room chahiye","1 se 3 October","AC chahiye"],
      5: ["3 log ke liye AC room chahiye", "Kal ke liye", "Private room hai kya?"],
      6: ["10 log hain","5 se 7 October","AC chahiye"],
      7: ["2 log hain","12 se 14 October","AC chahiye","Lower bed chahiye"],
      8: ["Group booking karni hai, call karwa do"],
      9: [],
      10: ["2 log hain","18 se 20 October","Non AC chalega","Any available bed"],
      11: ["Booking cancel karni hai","9876543210"],
    };
    if (id === 9) {
      doOtaSync(ctx);
      setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: lang==="english" ? "(Demo) Simulated an OTA sync — check the OTA & iCal tab and Dashboard, new external bookings may have been imported into the same central inventory." : "(Demo) OTA sync simulate kiya gaya — OTA & iCal tab aur Dashboard check karein, naye external bookings same central inventory mein import ho sakte hain." }]);
      setScenarioRunning(false); return;
    }
    for (const line of scripts[id] || []) { await sleep(650); sendText(line); await sleep(650); }
    if (id === 3 || id === 4 || id === 6 || id === 1 || id === 2 || id === 7) {
      await sleep(900);
      // auto-pick a sensible option if one is showing (private for scenario 4, else first individual)
    }
    if (id === 10) {
      await sleep(1200);
      setMessages(p => [...p, { id: uid("m"), from:"ai", type:"text", text: "(Demo) Pick a bed option above, then choose UPI/Card at payment and tap 'Simulate failure' to see the payment-failure path." }]);
    }
    setScenarioRunning(false);
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div className="lg:col-span-2">
        <Card className="flex flex-col h-[75vh] max-h-[720px] overflow-hidden">
          <div className="bg-teal-700 text-white px-4 py-3 flex items-center justify-between flex-shrink-0">
            <div className="flex items-center gap-2.5">
              <div className="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center"><Bot size={18}/></div>
              <div><p className="font-semibold text-sm leading-tight">Kush Stay AI Receptionist</p><p className="text-[11px] text-teal-100">{ctx.settings.whatsapp} · Simulated</p></div>
            </div>
            <div className="flex items-center gap-1">
              <button onClick={() => setLang(l => l==="english"?"hinglish":"english")} className="text-[11px] bg-white/15 hover:bg-white/25 px-2 py-1 rounded-full">{lang==="english"?"EN":"हिं"}</button>
            </div>
          </div>
          <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 py-3 space-y-2" style={{ background:"#EFEAE2" }}>
            {messages.map(m => <ChatBubble key={m.id} msg={m} onQuickReply={sendText} onOptionSelect={chooseOption} onPaymentMethod={choosePaymentMethod} onPaymentOutcome={(ok)=>resolvePayment(ok, messages.find(x=>x.status==="processing")?.method)} onHandoff={handleHandoff} />)}
          </div>
          <div className="p-2.5 border-t border-stone-200 flex items-center gap-2 flex-shrink-0 bg-white">
            <input value={inputText} onChange={e=>setInputText(e.target.value)} onKeyDown={e=>{if(e.key==="Enter"){sendText(inputText);}}} placeholder={lang==="english"?"Type a message…":"Message likhein…"} className="flex-1 px-3.5 py-2 rounded-full border border-stone-300 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500" />
            <button onClick={()=>sendText(inputText)} className="w-9 h-9 rounded-full bg-teal-700 text-white flex items-center justify-center flex-shrink-0 hover:bg-teal-800"><Send size={15}/></button>
          </div>
        </Card>
      </div>
      <div className="space-y-4">
        <Card className="p-4">
          <p className="text-sm font-semibold text-stone-800 mb-2 flex items-center gap-1.5"><Sparkles size={14} className="text-teal-600"/> Demo scenarios</p>
          <ScenarioMenu onRun={runScenario} running={scenarioRunning} />
        </Card>
        {ctx.settings.showDecisionPanel && (
          <Card className="p-4">
            <p className="text-sm font-semibold text-stone-800 mb-2 flex items-center gap-1.5"><FlaskConical size={14} className="text-teal-600"/> AI decision panel</p>
            {decisionTrace ? (
              <div className="text-xs text-stone-600 space-y-1">
                <p><span className="text-stone-400">Guests:</span> {decisionTrace.guests}</p>
                <p><span className="text-stone-400">Dates:</span> {decisionTrace.dates}</p>
                <p><span className="text-stone-400">AC pref:</span> {decisionTrace.acPref}</p>
                <p><span className="text-stone-400">Options found:</span> {decisionTrace.availableOptions}</p>
                <p><span className="text-stone-400">Recommendation:</span> {decisionTrace.recommendation}</p>
                <p className="pt-1 text-stone-500 italic">{decisionTrace.reason}</p>
              </div>
            ) : <p className="text-xs text-stone-400">Start a conversation to see live reasoning here.</p>}
          </Card>
        )}
        <Card className="p-4 bg-amber-50 border-amber-200">
          <p className="text-xs text-amber-800 leading-relaxed"><Info size={12} className="inline mr-1"/>This chat is a browser simulation of the AI receptionist logic — it isn't connected to real WhatsApp. Every reply is computed from the same live bed inventory and pricing you see in the Admin tabs.</p>
        </Card>
      </div>
    </div>
  );
}
/* ---------------- DASHBOARD ---------------- */
function DashboardScreen({ ctx }) {
  const today = ctx.todayIso;
  const tomorrow = addDays(today, 1);
  const active = ctx.bookings.filter(b => b.bookingStatus !== "Cancelled");
  const occupiedToday = ALL_BED_IDS.filter(id => !isBedFree(id, today, tomorrow, ctx.bookings, ctx.holds, ctx.blockedBeds)).length;
  const acOcc = ROOMS.AC.beds.filter(id => !isBedFree(id, today, tomorrow, ctx.bookings, ctx.holds, ctx.blockedBeds)).length;
  const nacOcc = ROOMS.NAC.beds.filter(id => !isBedFree(id, today, tomorrow, ctx.bookings, ctx.holds, ctx.blockedBeds)).length;
  const privAC = isPrivateAvailable("AC", today, tomorrow, ctx.bookings, ctx.holds, ctx.blockedBeds);
  const privNAC = isPrivateAvailable("NAC", today, tomorrow, ctx.bookings, ctx.holds, ctx.blockedBeds);
  const checkInsToday = active.filter(b => b.checkIn === today);
  const checkOutsToday = active.filter(b => b.checkOut === today);
  const pendingPayments = active.filter(b => b.paymentStatus === "Unpaid" || b.paymentStatus === "Partially Paid").length;
  const bookingsMadeToday = ctx.bookings.filter(b => toISO(new Date(b.createdAt)) === today).length;
  const upcoming = active.filter(b => b.checkIn > today).sort((a,b)=>a.checkIn.localeCompare(b.checkIn)).slice(0,6);
  const bySource = (src) => active.filter(b => b.source === src).length;

  return (
    <div>
      <SectionTitle icon={LayoutDashboard} title="Dashboard" sub={`Live snapshot for ${fmtDate(today)}`} />
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <StatCard label="Total beds" value={16} icon={BedDouble} tone="slate" />
        <StatCard label="Occupied" value={occupiedToday} icon={BedDouble} tone="rose" sub={`${16-occupiedToday} available`} />
        <StatCard label="AC occupancy" value={`${acOcc}/8`} icon={BedDouble} tone="sky" />
        <StatCard label="Non-AC occupancy" value={`${nacOcc}/8`} icon={BedDouble} tone="teal" />
        <StatCard label="Check-ins today" value={checkInsToday.length} icon={ChevronRight} tone="emerald" />
        <StatCard label="Check-outs today" value={checkOutsToday.length} icon={ChevronLeft} tone="amber" />
        <StatCard label="Pending payments" value={pendingPayments} icon={Wallet} tone="rose" />
        <StatCard label="Bookings made today" value={bookingsMadeToday} icon={ClipboardList} tone="slate" />
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <Card className="p-4">
          <p className="text-sm font-semibold text-stone-800 mb-1">Private AC Room</p>
          <p className={`text-lg font-semibold ${privAC.available ? "text-emerald-600" : "text-rose-600"}`}>{privAC.available ? "AVAILABLE" : "NOT AVAILABLE"}</p>
          {!privAC.available && <p className="text-xs text-stone-500 mt-1">Reason: {privAC.reason}</p>}
        </Card>
        <Card className="p-4">
          <p className="text-sm font-semibold text-stone-800 mb-1">Private Non-AC Room</p>
          <p className={`text-lg font-semibold ${privNAC.available ? "text-emerald-600" : "text-rose-600"}`}>{privNAC.available ? "AVAILABLE" : "NOT AVAILABLE"}</p>
          {!privNAC.available && <p className="text-xs text-stone-500 mt-1">Reason: {privNAC.reason}</p>}
        </Card>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-4 lg:col-span-2">
          <p className="text-sm font-semibold text-stone-800 mb-3">Upcoming bookings</p>
          {upcoming.length === 0 ? <EmptyState icon={CalendarDays} text="No upcoming bookings" /> : (
            <div className="space-y-2">
              {upcoming.map(b => (
                <div key={b.bookingId} className="flex items-center justify-between text-sm border-b border-stone-100 pb-2 last:border-0">
                  <div><p className="font-medium text-stone-800">{b.customerName}</p><p className="text-xs text-stone-500">{fmtDateShort(b.checkIn)} → {fmtDateShort(b.checkOut)} · {b.bookingType==="private"?`Private ${b.roomKey}`:b.bedIds.join(", ")}</p></div>
                  <div className="text-right"><SourceBadge source={b.source} /><p className="text-xs text-stone-500 mt-1">{fmtMoney(b.total)}</p></div>
                </div>
              ))}
            </div>
          )}
        </Card>
        <Card className="p-4">
          <p className="text-sm font-semibold text-stone-800 mb-3">Bookings by channel</p>
          <div className="space-y-1.5">
            {["WhatsApp AI","Direct","Booking.com","Airbnb","Phone","Manual/Admin"].map(s => (
              <div key={s} className="flex items-center justify-between text-xs">
                <SourceBadge source={s} /><span className="font-medium text-stone-700">{bySource(s)}</span>
              </div>
            ))}
          </div>
          <div className="mt-3 pt-3 border-t border-stone-100">
            <p className="text-xs text-stone-500">Callback requests</p>
            <p className="text-lg font-semibold text-stone-800">{ctx.leads.length}</p>
          </div>
        </Card>
      </div>
    </div>
  );
}
/* ---------------- BOOKING FORM (create/edit) ---------------- */
function BookingForm({ ctx, editing, onClose }) {
  const [f, setF] = useState(() => editing ? { ...editing, customerPhone: editing.customerPhone||"" } : {
    source:"Manual/Admin", customerName:"", customerPhone:"", customerEmail:"", checkIn: ctx.todayIso, checkOut: addDays(ctx.todayIso,1),
    guestCount:1, bookingType:"individual", roomKey:"AC", bedIds:[], discount:0, amountPaid:0, paymentMethod:"Cash",
    bookingStatus:"Confirmed", paymentStatus:"Unpaid", specialRequest:"",
  });
  const [errors, setErrors] = useState([]);
  const nights = f.checkIn && f.checkOut ? Math.max(0, nightsBetween(f.checkIn, f.checkOut)) : 0;
  const availableBeds = getAvailableBeds(f.roomKey, f.checkIn, f.checkOut, ctx.bookings, ctx.holds, ctx.blockedBeds, null, editing?.bookingId);
  const selectableBeds = editing ? Array.from(new Set([...availableBeds, ...(editing.bedIds||[])])) : availableBeds;
  const subtotal = f.bookingType === "private" ? calcPrivateTotal(f.roomKey, f.checkIn, f.checkOut, ctx.prices) : calcIndividualTotal(f.bedIds, f.checkIn, f.checkOut, ctx.prices);
  const { total } = applyDiscountTax(subtotal, Number(f.discount)||0, ctx.settings);
  const balance = total - (Number(f.amountPaid)||0);

  function toggleBed(id) {
    setF(prev => { const has = prev.bedIds.includes(id); const next = has ? prev.bedIds.filter(b=>b!==id) : [...prev.bedIds, id]; return { ...prev, bedIds: next, guestCount: next.length || prev.guestCount }; });
  }
  function save() {
    const draft = { ...f, bedIds: f.bookingType==="private" ? ROOMS[f.roomKey].beds : f.bedIds, requireContact: true, total, amountPaid: Number(f.amountPaid)||0 };
    const errs = validateBookingDraft(draft, ctx.bookings, editing?.bookingId).filter(e => !e.startsWith("Warning"));
    if (errs.length) { setErrors(errs); return; }
    const paymentStatus = draft.amountPaid <= 0 ? "Unpaid" : draft.amountPaid >= total ? "Paid" : "Partially Paid";
    if (editing) {
      ctx.setBookings(prev => prev.map(b => b.bookingId === editing.bookingId ? { ...b, ...draft, nights, subtotal, total, balance: total-draft.amountPaid, paymentStatus, updatedAt: Date.now() } : b));
    } else {
      const bookingId = makeBookingId(ctx.bookings, new Date(ctx.virtualNow()));
      ctx.setBookings(prev => [...prev, { ...draft, bookingId, nights, subtotal, total, balance: total-draft.amountPaid, paymentStatus, createdAt: Date.now(), updatedAt: Date.now() }]);
    }
    onClose();
  }
  return (
    <div>
      {errors.length > 0 && <div className="bg-rose-50 border border-rose-200 rounded-lg p-3 mb-3 text-xs text-rose-700 space-y-0.5">{errors.map((e,i)=><p key={i}>• {e}</p>)}</div>}
      <div className="grid grid-cols-2 gap-3">
        <Field label="Guest name"><TextInput value={f.customerName} onChange={e=>setF({...f,customerName:e.target.value})} /></Field>
        <Field label="Mobile number"><TextInput value={f.customerPhone} onChange={e=>setF({...f,customerPhone:e.target.value})} /></Field>
        <Field label="Check-in"><TextInput type="date" value={f.checkIn} onChange={e=>setF({...f,checkIn:e.target.value})} /></Field>
        <Field label="Check-out"><TextInput type="date" value={f.checkOut} onChange={e=>setF({...f,checkOut:e.target.value})} /></Field>
        <Field label="Source"><Select value={f.source} onChange={e=>setF({...f,source:e.target.value})} options={SOURCES} /></Field>
        <Field label="Room"><Select value={f.roomKey} onChange={e=>setF({...f,roomKey:e.target.value, bedIds:[]})} options={[{value:"AC",label:"AC Dormitory"},{value:"NAC",label:"Non-AC Dormitory"}]} /></Field>
        <Field label="Booking type"><Select value={f.bookingType} onChange={e=>setF({...f,bookingType:e.target.value})} options={[{value:"individual",label:"Individual beds"},{value:"private",label:"Private room"}]} /></Field>
        <Field label="Booking status"><Select value={f.bookingStatus} onChange={e=>setF({...f,bookingStatus:e.target.value})} options={BOOKING_STATUSES} /></Field>
      </div>
      {f.bookingType === "individual" && (
        <Field label={`Beds (${f.bedIds.length} selected)`}>
          <div className="grid grid-cols-4 gap-1.5">
            {ROOMS[f.roomKey].beds.map(id => (
              <button key={id} type="button" onClick={()=>toggleBed(id)} disabled={!selectableBeds.includes(id)} className={`px-2 py-1.5 rounded-lg text-xs border ${f.bedIds.includes(id) ? "bg-teal-700 text-white border-teal-700" : selectableBeds.includes(id) ? "border-stone-300 hover:bg-stone-50" : "border-stone-200 text-stone-300 cursor-not-allowed"}`}>{id}</button>
            ))}
          </div>
        </Field>
      )}
      {f.bookingType === "private" && (() => { const p = isPrivateAvailable(f.roomKey, f.checkIn, f.checkOut, ctx.bookings, ctx.holds, ctx.blockedBeds, editing?.bookingId); return (
        <p className={`text-xs mb-3 ${p.available ? "text-emerald-600" : "text-rose-600"}`}>{p.available ? "All 8 beds free — private room available." : `Not available: ${p.reason}`}</p>
      ); })()}
      <div className="grid grid-cols-3 gap-3">
        <Field label="Discount (₹)"><TextInput type="number" value={f.discount} onChange={e=>setF({...f,discount:e.target.value})} /></Field>
        <Field label="Amount paid (₹)"><TextInput type="number" value={f.amountPaid} onChange={e=>setF({...f,amountPaid:e.target.value})} /></Field>
        <Field label="Payment method"><Select value={f.paymentMethod} onChange={e=>setF({...f,paymentMethod:e.target.value})} options={["Cash","UPI","Card","Cash at property","Pay later"]} /></Field>
      </div>
      <Field label="Special request"><TextInput value={f.specialRequest} onChange={e=>setF({...f,specialRequest:e.target.value})} /></Field>
      <div className="bg-stone-50 rounded-lg p-3 text-sm space-y-0.5 mb-3">
        <p>Nights: {nights}</p><p>Subtotal: {fmtMoney(subtotal)}</p><p className="font-semibold">Total: {fmtMoney(total)}</p><p>Balance due: {fmtMoney(balance)}</p>
      </div>
      <div className="flex gap-2"><Btn onClick={save}>{editing ? "Save changes" : "Create booking"}</Btn><Btn tone="secondary" onClick={onClose}>Cancel</Btn></div>
    </div>
  );
}

function BookingDetail({ ctx, booking, onClose }) {
  const [editing, setEditing] = useState(false);
  if (editing) return <Modal open title={`Edit ${booking.bookingId}`} onClose={onClose} wide><BookingForm ctx={ctx} editing={booking} onClose={onClose} /></Modal>;
  function setStatus(s) { ctx.setBookings(prev => prev.map(b => b.bookingId===booking.bookingId ? {...b, bookingStatus:s, updatedAt:Date.now()} : b)); onClose(); }
  return (
    <Modal open title={booking.bookingId} onClose={onClose}>
      <div className="text-sm space-y-1.5 text-stone-700">
        <div className="flex items-center gap-2"><SourceBadge source={booking.source}/><StatusBadge status={booking.bookingStatus}/><StatusBadge status={booking.paymentStatus}/></div>
        <p className="pt-1"><span className="text-stone-400">Guest:</span> {booking.customerName} · {booking.customerPhone||"—"}</p>
        <p><span className="text-stone-400">Dates:</span> {fmtDate(booking.checkIn)} → {fmtDate(booking.checkOut)} ({booking.nights} nights)</p>
        <p><span className="text-stone-400">Guests:</span> {booking.guestCount}</p>
        <p><span className="text-stone-400">Room/Beds:</span> {booking.bookingType==="private" ? `Private ${booking.roomKey} Room (all 8 beds)` : booking.bedIds.join(", ")}</p>
        <p><span className="text-stone-400">Total:</span> {fmtMoney(booking.total)} · <span className="text-stone-400">Paid:</span> {fmtMoney(booking.amountPaid)} · <span className="text-stone-400">Balance:</span> {fmtMoney(booking.balance)}</p>
        {booking.specialRequest && <p><span className="text-stone-400">Notes:</span> {booking.specialRequest}</p>}
      </div>
      <div className="flex flex-wrap gap-1.5 mt-4">
        <Btn size="sm" icon={Pencil} onClick={()=>setEditing(true)}>Edit</Btn>
        {booking.bookingStatus!=="Checked-in" && booking.bookingStatus!=="Cancelled" && <Btn size="sm" tone="secondary" onClick={()=>setStatus("Checked-in")}>Check in</Btn>}
        {booking.bookingStatus==="Checked-in" && <Btn size="sm" tone="secondary" onClick={()=>setStatus("Checked-out")}>Check out</Btn>}
        {booking.bookingStatus!=="Cancelled" && <Btn size="sm" tone="secondary" onClick={()=>setStatus("No-show")}>No-show</Btn>}
        {booking.bookingStatus!=="Cancelled" && <Btn size="sm" tone="danger" icon={Trash2} onClick={()=>setStatus("Cancelled")}>Cancel booking</Btn>}
      </div>
    </Modal>
  );
}

function BookingsScreen({ ctx }) {
  const [sourceFilter, setSourceFilter] = useState("All");
  const [statusFilter, setStatusFilter] = useState("All");
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [selected, setSelected] = useState(null);
  const rows = ctx.bookings.filter(b =>
    (sourceFilter==="All"||b.source===sourceFilter) && (statusFilter==="All"||b.bookingStatus===statusFilter) &&
    (!search || b.customerName.toLowerCase().includes(search.toLowerCase()) || b.bookingId.toLowerCase().includes(search.toLowerCase()) || (b.customerPhone||"").includes(search))
  ).sort((a,b)=>b.createdAt-a.createdAt);
  return (
    <div>
      <SectionTitle icon={ClipboardList} title="Bookings" sub={`${rows.length} of ${ctx.bookings.length} bookings`} right={<Btn icon={Plus} onClick={()=>setCreating(true)}>New booking</Btn>} />
      <div className="flex flex-wrap gap-2 mb-3">
        <TextInput placeholder="Search guest, ID, phone…" value={search} onChange={e=>setSearch(e.target.value)} className="max-w-xs" />
        <Select value={sourceFilter} onChange={e=>setSourceFilter(e.target.value)} options={["All",...SOURCES]} className="w-auto" />
        <Select value={statusFilter} onChange={e=>setStatusFilter(e.target.value)} options={["All",...BOOKING_STATUSES]} className="w-auto" />
      </div>
      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-stone-50 text-stone-500 text-xs uppercase"><tr>
            <th className="text-left px-3 py-2.5">Booking</th><th className="text-left px-3 py-2.5">Guest</th><th className="text-left px-3 py-2.5">Dates</th>
            <th className="text-left px-3 py-2.5">Room/Beds</th><th className="text-left px-3 py-2.5">Source</th><th className="text-left px-3 py-2.5">Status</th>
            <th className="text-left px-3 py-2.5">Payment</th><th className="text-right px-3 py-2.5">Total</th>
          </tr></thead>
          <tbody>
            {rows.map(b => (
              <tr key={b.bookingId} className="border-t border-stone-100 hover:bg-stone-50 cursor-pointer" onClick={()=>setSelected(b)}>
                <td className="px-3 py-2.5 font-mono text-xs">{b.bookingId}</td>
                <td className="px-3 py-2.5">{b.customerName}</td>
                <td className="px-3 py-2.5 text-xs">{fmtDateShort(b.checkIn)} → {fmtDateShort(b.checkOut)}</td>
                <td className="px-3 py-2.5 text-xs">{b.bookingType==="private"?`Private ${b.roomKey}`:b.bedIds.join(", ")}</td>
                <td className="px-3 py-2.5"><SourceBadge source={b.source}/></td>
                <td className="px-3 py-2.5"><StatusBadge status={b.bookingStatus}/></td>
                <td className="px-3 py-2.5"><StatusBadge status={b.paymentStatus}/></td>
                <td className="px-3 py-2.5 text-right font-medium">{fmtMoney(b.total)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length===0 && <EmptyState icon={ClipboardList} text="No bookings match these filters" />}
      </Card>
      {creating && <Modal open title="New booking" onClose={()=>setCreating(false)} wide><BookingForm ctx={ctx} onClose={()=>setCreating(false)} /></Modal>}
      {selected && <BookingDetail ctx={ctx} booking={selected} onClose={()=>setSelected(null)} />}
    </div>
  );
}
/* ---------------- CALENDAR ---------------- */
function dayOccupancy(dateIso, ctx) {
  const next = addDays(dateIso, 1);
  const occ = ALL_BED_IDS.filter(id => !isBedFree(id, dateIso, next, ctx.bookings, ctx.holds, ctx.blockedBeds)).length;
  return occ;
}
function CalendarScreen({ ctx }) {
  const [view, setView] = useState("month");
  const [cursor, setCursor] = useState(() => new Date(ctx.todayIso + "T00:00:00"));
  const [selectedDate, setSelectedDate] = useState(ctx.todayIso);

  function monthGrid(d) {
    const year = d.getFullYear(), month = d.getMonth();
    const first = new Date(year, month, 1);
    const startOffset = first.getDay();
    const daysInMonth = new Date(year, month+1, 0).getDate();
    const cells = [];
    for (let i=0;i<startOffset;i++) cells.push(null);
    for (let day=1; day<=daysInMonth; day++) cells.push(toISO(new Date(year, month, day)));
    return cells;
  }
  const cells = monthGrid(cursor);
  const weekCells = useMemo(() => { const d = new Date(selectedDate+"T00:00:00"); const dow = d.getDay(); const start = addDays(selectedDate, -dow); return Array.from({length:7},(_,i)=>addDays(start,i)); }, [selectedDate]);
  const dayBookings = ctx.bookings.filter(b => b.bookingStatus!=="Cancelled" && overlap(b.checkIn,b.checkOut,selectedDate,addDays(selectedDate,1)));
  const privAC = isPrivateAvailable("AC", selectedDate, addDays(selectedDate,1), ctx.bookings, ctx.holds, ctx.blockedBeds);
  const privNAC = isPrivateAvailable("NAC", selectedDate, addDays(selectedDate,1), ctx.bookings, ctx.holds, ctx.blockedBeds);
  const acFree = getAvailableBeds("AC", selectedDate, addDays(selectedDate,1), ctx.bookings, ctx.holds, ctx.blockedBeds).length;
  const nacFree = getAvailableBeds("NAC", selectedDate, addDays(selectedDate,1), ctx.bookings, ctx.holds, ctx.blockedBeds).length;

  function occColor(occ) { const pct = occ/16; return pct>=0.8 ? "bg-rose-400" : pct>=0.4 ? "bg-amber-400" : occ>0 ? "bg-emerald-300" : "bg-stone-200"; }

  return (
    <div>
      <SectionTitle icon={CalendarDays} title="Calendar" sub="Click a day to see the full breakdown" right={
        <div className="flex gap-1">{["month","week","day"].map(v => <button key={v} onClick={()=>setView(v)} className={`px-3 py-1.5 rounded-lg text-xs font-medium capitalize ${view===v?"bg-teal-700 text-white":"bg-stone-100 text-stone-600"}`}>{v}</button>)}</div>
      } />
      {view === "month" && (
        <Card className="p-4 mb-4">
          <div className="flex items-center justify-between mb-3">
            <button onClick={()=>setCursor(d=>{const n=new Date(d);n.setMonth(n.getMonth()-1);return n;})} className="p-1.5 rounded-lg hover:bg-stone-100"><ChevronLeft size={16}/></button>
            <p className="font-semibold text-sm">{cursor.toLocaleDateString("en-IN",{month:"long",year:"numeric"})}</p>
            <button onClick={()=>setCursor(d=>{const n=new Date(d);n.setMonth(n.getMonth()+1);return n;})} className="p-1.5 rounded-lg hover:bg-stone-100"><ChevronRight size={16}/></button>
          </div>
          <div className="grid grid-cols-7 gap-1 text-center text-[11px] text-stone-400 mb-1">{["S","M","T","W","T","F","S"].map((d,i)=><div key={i}>{d}</div>)}</div>
          <div className="grid grid-cols-7 gap-1">
            {cells.map((iso, i) => iso ? (
              <button key={i} onClick={()=>{setSelectedDate(iso);}} className={`aspect-square rounded-lg border text-xs flex flex-col items-center justify-center gap-0.5 ${iso===selectedDate?"border-teal-600 ring-2 ring-teal-200":"border-stone-100"} ${iso===ctx.todayIso?"font-bold":""}`}>
                <span>{parseInt(iso.slice(8))}</span><span className={`w-1.5 h-1.5 rounded-full ${occColor(dayOccupancy(iso,ctx))}`}/>
              </button>
            ) : <div key={i}/>)}
          </div>
        </Card>
      )}
      {view === "week" && (
        <Card className="p-4 mb-4 overflow-x-auto">
          <div className="grid grid-cols-7 gap-2 min-w-[700px]">
            {weekCells.map(iso => (
              <button key={iso} onClick={()=>setSelectedDate(iso)} className={`text-left p-2 rounded-lg border ${iso===selectedDate?"border-teal-600 bg-teal-50":"border-stone-100"}`}>
                <p className="text-[11px] text-stone-400">{new Date(iso+"T00:00:00").toLocaleDateString("en-IN",{weekday:"short"})}</p>
                <p className="text-sm font-semibold mb-1">{fmtDateShort(iso)}</p>
                <p className="text-[10px] text-stone-500">{dayOccupancy(iso,ctx)}/16 occupied</p>
                {ctx.bookings.filter(b=>b.bookingStatus!=="Cancelled"&&overlap(b.checkIn,b.checkOut,iso,addDays(iso,1))).slice(0,2).map(b=><p key={b.bookingId} className="text-[10px] truncate text-stone-600 mt-0.5">{b.customerName}</p>)}
              </button>
            ))}
          </div>
        </Card>
      )}
      {view === "day" && (
        <Card className="p-4 mb-4">
          <div className="flex items-center justify-between mb-2">
            <button onClick={()=>setSelectedDate(d=>addDays(d,-1))} className="p-1.5 rounded-lg hover:bg-stone-100"><ChevronLeft size={16}/></button>
            <p className="font-semibold text-sm">{fmtDate(selectedDate)}</p>
            <button onClick={()=>setSelectedDate(d=>addDays(d,1))} className="p-1.5 rounded-lg hover:bg-stone-100"><ChevronRight size={16}/></button>
          </div>
        </Card>
      )}
      <Card className="p-4">
        <p className="font-semibold text-sm mb-3">{fmtDate(selectedDate)} — full breakdown</p>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
          <StatCard label="AC free" value={`${acFree}/8`} tone="sky" />
          <StatCard label="Non-AC free" value={`${nacFree}/8`} tone="teal" />
          <StatCard label="Private AC" value={privAC.available?"Yes":"No"} tone={privAC.available?"emerald":"rose"} />
          <StatCard label="Private Non-AC" value={privNAC.available?"Yes":"No"} tone={privNAC.available?"emerald":"rose"} />
        </div>
        <p className="text-xs font-medium text-stone-500 mb-1.5">Bookings covering this night</p>
        {dayBookings.length===0 ? <EmptyState icon={CalendarDays} text="No bookings for this date" /> : (
          <div className="space-y-1.5">{dayBookings.map(b=>(
            <div key={b.bookingId} className="flex items-center justify-between text-sm border-b border-stone-100 pb-1.5 last:border-0">
              <span>{b.customerName} · {b.bookingType==="private"?`Private ${b.roomKey}`:b.bedIds.join(", ")}</span><SourceBadge source={b.source}/>
            </div>
          ))}</div>
        )}
      </Card>
    </div>
  );
}

/* ---------------- BEDS (visual map) ---------------- */
function BedsScreen({ ctx }) {
  const [date, setDate] = useState(ctx.todayIso);
  const [selected, setSelected] = useState(null);
  function statusOf(id) {
    if (ctx.blockedBeds[id]) return "blocked";
    const hold = ctx.holds.find(h => h.bedIds.includes(id) && overlap(h.checkIn,h.checkOut,date,addDays(date,1)));
    if (hold) return "held";
    const booking = ctx.bookings.find(b => b.bookingStatus!=="Cancelled" && b.bedIds.includes(id) && overlap(b.checkIn,b.checkOut,date,addDays(date,1)));
    if (booking) return "booked";
    return "available";
  }
  function detailsOf(id) { return ctx.bookings.find(b => b.bookingStatus!=="Cancelled" && b.bedIds.includes(id) && overlap(b.checkIn,b.checkOut,date,addDays(date,1))); }
  return (
    <div>
      <SectionTitle icon={BedDouble} title="Bed map" sub="Click a bed to see details" right={<TextInput type="date" value={date} onChange={e=>setDate(e.target.value)} className="w-auto" />} />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {["AC","NAC"].map(rk => (
          <Card key={rk} className="p-4">
            <p className="font-semibold text-sm mb-3">{ROOMS[rk].name}</p>
            <p className="text-xs text-stone-400 mb-1">Upper</p>
            <div className="grid grid-cols-4 gap-2 mb-3">{ROOMS[rk].beds.filter(b=>b.includes("-U")).map(id=><BedTile key={id} bedId={id} status={statusOf(id)} onClick={()=>setSelected(id)} />)}</div>
            <p className="text-xs text-stone-400 mb-1">Lower</p>
            <div className="grid grid-cols-4 gap-2">{ROOMS[rk].beds.filter(b=>b.includes("-L")).map(id=><BedTile key={id} bedId={id} status={statusOf(id)} onClick={()=>setSelected(id)} />)}</div>
          </Card>
        ))}
      </div>
      {selected && (
        <Modal open title={selected} onClose={()=>setSelected(null)}>
          {(() => { const st = statusOf(selected); const b = detailsOf(selected);
            return (<div className="text-sm space-y-2">
              <p><Badge tone={st==="available"?"emerald":st==="held"?"amber":st==="booked"?"rose":"slate"}>{st.toUpperCase()}</Badge></p>
              {b && <div className="text-stone-700 space-y-0.5"><p>{b.customerName} · {b.bookingId}</p><p>{fmtDateShort(b.checkIn)} → {fmtDateShort(b.checkOut)}</p><SourceBadge source={b.source}/></div>}
              {st==="available" && <Btn size="sm" tone="secondary" onClick={()=>{ctx.setBlockedBeds(prev=>({...prev,[selected]:true}));setSelected(null);}}>Block for maintenance</Btn>}
              {st==="blocked" && <Btn size="sm" tone="secondary" onClick={()=>{ctx.setBlockedBeds(prev=>{const n={...prev};delete n[selected];return n;});setSelected(null);}}>Unblock</Btn>}
            </div>);
          })()}
        </Modal>
      )}
    </div>
  );
}
/* ---------------- CUSTOMERS + LEADS ---------------- */
function buildCustomers(bookings, extras) {
  const map = {};
  bookings.forEach(b => {
    if (!b.customerPhone) return;
    if (!map[b.customerPhone]) map[b.customerPhone] = { phone:b.customerPhone, name:b.customerName, bookingIds:[], totalStays:0, lastBooking:null, totalSpending:0, sources:new Set(), bedTypes:{} };
    const c = map[b.customerPhone];
    c.bookingIds.push(b.bookingId); c.sources.add(b.source);
    if (b.bookingStatus !== "Cancelled") { c.totalStays += 1; c.totalSpending += b.total; (b.bedIds||[]).forEach(id=>{const t=bedMeta(id).type+" "+bedMeta(id).room; c.bedTypes[t]=(c.bedTypes[t]||0)+1;}); }
    if (!c.lastBooking || b.checkIn > c.lastBooking) c.lastBooking = b.checkIn;
  });
  return Object.values(map).map(c => {
    const topType = Object.entries(c.bedTypes).sort((a,b)=>b[1]-a[1])[0];
    const ex = extras[c.phone] || {};
    return { ...c, sources:Array.from(c.sources), preference: topType ? `Prefers ${topType[0]}` : "—", email: ex.email||"", notes: ex.notes||"" };
  }).sort((a,b)=>b.totalSpending-a.totalSpending);
}
function CustomersScreen({ ctx }) {
  const [tab, setTab] = useState("customers");
  const [editing, setEditing] = useState(null);
  const customers = useMemo(()=>buildCustomers(ctx.bookings, ctx.customerExtras), [ctx.bookings, ctx.customerExtras]);
  return (
    <div>
      <SectionTitle icon={Users} title="Customers" sub={`${customers.length} guest profiles · ${ctx.leads.length} leads`} right={
        <div className="flex gap-1">{["customers","leads"].map(t=><button key={t} onClick={()=>setTab(t)} className={`px-3 py-1.5 rounded-lg text-xs font-medium capitalize ${tab===t?"bg-teal-700 text-white":"bg-stone-100 text-stone-600"}`}>{t}</button>)}</div>
      } />
      {tab === "customers" && (
        <Card className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-stone-50 text-stone-500 text-xs uppercase"><tr><th className="text-left px-3 py-2.5">Name</th><th className="text-left px-3 py-2.5">Phone</th><th className="text-left px-3 py-2.5">Stays</th><th className="text-left px-3 py-2.5">Last booking</th><th className="text-left px-3 py-2.5">Preference</th><th className="text-right px-3 py-2.5">Spend</th></tr></thead>
            <tbody>{customers.map(c=>(
              <tr key={c.phone} className="border-t border-stone-100 hover:bg-stone-50 cursor-pointer" onClick={()=>setEditing(c)}>
                <td className="px-3 py-2.5 font-medium">{c.name}</td><td className="px-3 py-2.5">{c.phone}</td><td className="px-3 py-2.5">{c.totalStays}</td>
                <td className="px-3 py-2.5">{fmtDateShort(c.lastBooking)}</td><td className="px-3 py-2.5 text-xs text-stone-500">{c.preference}</td>
                <td className="px-3 py-2.5 text-right font-medium">{fmtMoney(c.totalSpending)}</td>
              </tr>
            ))}</tbody>
          </table>
          {customers.length===0 && <EmptyState icon={Users} text="No customer profiles yet" />}
        </Card>
      )}
      {tab === "leads" && (
        <Card className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-stone-50 text-stone-500 text-xs uppercase"><tr><th className="text-left px-3 py-2.5">Lead</th><th className="text-left px-3 py-2.5">Contact</th><th className="text-left px-3 py-2.5">Reason</th><th className="text-left px-3 py-2.5">Priority</th><th className="text-left px-3 py-2.5">Status</th></tr></thead>
            <tbody>{ctx.leads.slice().sort((a,b)=>b.createdAt-a.createdAt).map(l=>(
              <tr key={l.leadId} className="border-t border-stone-100">
                <td className="px-3 py-2.5 font-mono text-xs">{l.leadId}</td>
                <td className="px-3 py-2.5">{l.customerName}<br/><span className="text-xs text-stone-400">{l.phone}</span></td>
                <td className="px-3 py-2.5 text-xs">{l.reason}</td>
                <td className="px-3 py-2.5"><Badge tone={l.priority==="High"?"rose":"slate"}>{l.priority}</Badge></td>
                <td className="px-3 py-2.5">
                  <Select value={l.status} onChange={e=>ctx.setLeads(prev=>prev.map(x=>x.leadId===l.leadId?{...x,status:e.target.value}:x))} options={["New","Contacted","Converted","Lost"]} className="text-xs py-1" />
                </td>
              </tr>
            ))}</tbody>
          </table>
          {ctx.leads.length===0 && <EmptyState icon={PhoneCall} text="No callback leads yet" />}
        </Card>
      )}
      {editing && (
        <Modal open title={editing.name} onClose={()=>setEditing(null)}>
          <div className="text-sm space-y-1 text-stone-600 mb-3">
            <p>Phone: {editing.phone}</p><p>Total stays: {editing.totalStays}</p><p>Total spend: {fmtMoney(editing.totalSpending)}</p><p>Preference: {editing.preference}</p>
            <p>Booking history: {editing.bookingIds.join(", ")}</p>
          </div>
          <Field label="Email"><TextInput defaultValue={editing.email} onBlur={e=>ctx.setCustomerExtras(prev=>({...prev,[editing.phone]:{...prev[editing.phone],email:e.target.value}}))} /></Field>
          <Field label="Notes"><TextInput defaultValue={editing.notes} onBlur={e=>ctx.setCustomerExtras(prev=>({...prev,[editing.phone]:{...prev[editing.phone],notes:e.target.value}}))} /></Field>
        </Modal>
      )}
    </div>
  );
}

/* ---------------- PAYMENTS ---------------- */
function PaymentsScreen({ ctx }) {
  const [filter, setFilter] = useState("All");
  const rows = ctx.bookings.filter(b => filter==="All" || b.paymentStatus===filter).sort((a,b)=>b.createdAt-a.createdAt);
  function markPaid(b) { ctx.setBookings(prev=>prev.map(x=>x.bookingId===b.bookingId?{...x,amountPaid:x.total,balance:0,paymentStatus:"Paid",updatedAt:Date.now()}:x)); }
  return (
    <div>
      <SectionTitle icon={Wallet} title="Payments" sub="Every payment status change is a real booking-record update" right={<Select value={filter} onChange={e=>setFilter(e.target.value)} options={["All",...PAYMENT_STATUSES]} className="w-auto" />} />
      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-stone-50 text-stone-500 text-xs uppercase"><tr><th className="text-left px-3 py-2.5">Booking</th><th className="text-left px-3 py-2.5">Guest</th><th className="text-left px-3 py-2.5">Method</th><th className="text-right px-3 py-2.5">Total</th><th className="text-right px-3 py-2.5">Paid</th><th className="text-right px-3 py-2.5">Balance</th><th className="text-left px-3 py-2.5">Status</th><th></th></tr></thead>
          <tbody>{rows.map(b=>(
            <tr key={b.bookingId} className="border-t border-stone-100">
              <td className="px-3 py-2.5 font-mono text-xs">{b.bookingId}</td><td className="px-3 py-2.5">{b.customerName}</td><td className="px-3 py-2.5 text-xs">{b.paymentMethod}</td>
              <td className="px-3 py-2.5 text-right">{fmtMoney(b.total)}</td><td className="px-3 py-2.5 text-right">{fmtMoney(b.amountPaid)}</td><td className="px-3 py-2.5 text-right">{fmtMoney(b.balance)}</td>
              <td className="px-3 py-2.5"><StatusBadge status={b.paymentStatus}/></td>
              <td className="px-3 py-2.5">{b.paymentStatus!=="Paid" && b.bookingStatus!=="Cancelled" && <Btn size="sm" tone="secondary" onClick={()=>markPaid(b)}>Mark paid</Btn>}</td>
            </tr>
          ))}</tbody>
        </table>
      </Card>
    </div>
  );
}
/* ---------------- OTA (simulated) + iCAL (real) ---------------- */
const FAKE_GUEST_NAMES = ["Rohan Mehta","Kavita Joshi","Suresh Iyer","Anita Desai","Manoj Kumar","Farah Khan","Ritu Bansal","Karan Malhotra"];
function doOtaSync(ctx, connId) {
  const conns = connId ? ctx.otaConnections.filter(c=>c.id===connId) : ctx.otaConnections.filter(c=>c.status==="Active");
  conns.forEach(conn => {
    const shouldCreate = Math.random() > 0.35;
    let logLine = `${conn.name}: synced, no new bookings`;
    if (shouldCreate) {
      const roomKey = conn.roomMapping.includes("Non") ? "NAC" : (conn.roomMapping.includes("Both") ? (Math.random()>0.5?"AC":"NAC") : "AC");
      const startOffset = 3 + Math.floor(Math.random()*10);
      const ci = addDays(ctx.todayIso, startOffset), co = addDays(ci, 1 + Math.floor(Math.random()*2));
      const free = getAvailableBeds(roomKey, ci, co, ctx.bookings, ctx.holds, ctx.blockedBeds);
      if (free.length > 0) {
        const bed = free[0];
        const bookingId = makeBookingId(ctx.bookings, new Date(ctx.virtualNow()));
        const name = FAKE_GUEST_NAMES[Math.floor(Math.random()*FAKE_GUEST_NAMES.length)];
        const total = calcIndividualTotal([bed], ci, co, ctx.prices);
        ctx.setBookings(prev => [...prev, { bookingId, source: conn.name, customerName:name, customerPhone:null, customerEmail:"", checkIn:ci, checkOut:co, guestCount:1, bookingType:"individual", roomKey, bedIds:[bed], nights:nightsBetween(ci,co), subtotal:total, discount:0, tax:0, total, amountPaid:total, balance:0, paymentStatus:"Paid", paymentMethod:"OTA", bookingStatus:"Confirmed", externalBookingId: conn.name.slice(0,3).toUpperCase()+"-"+Math.floor(10000+Math.random()*90000), specialRequest:"", createdAt:Date.now(), updatedAt:Date.now() }]);
        logLine = `${conn.name}: imported 1 booking (${bed}, ${fmtDateShort(ci)}→${fmtDateShort(co)})`;
      } else {
        logLine = `${conn.name}: no matching availability, 0 bookings imported`;
      }
    }
    ctx.setSyncLog(l => [{ id: uid("log"), time:Date.now(), text: logLine }, ...l].slice(0,8));
    ctx.setOtaConnections(prev => prev.map(c => c.id===conn.id ? { ...c, lastSync: Date.now() } : c));
  });
}

function OtaIcalScreen({ ctx }) {
  const fileInputRef = useRef(null);
  const [mapRoom, setMapRoom] = useState("AC");
  const [importLog, setImportLog] = useState([]);

  function exportIcs(scope) {
    const active = ctx.bookings.filter(b=>b.bookingStatus!=="Cancelled");
    let list = active, name = "Kush Stay — All bookings";
    if (scope==="AC") { list = active.filter(b=>b.roomKey==="AC"); name = "Kush Stay — AC Dormitory"; }
    if (scope==="NAC") { list = active.filter(b=>b.roomKey==="NAC"); name = "Kush Stay — Non-AC Dormitory"; }
    const ics = generateICS(list, name);
    downloadTextFile(`kush-stay-${scope.toLowerCase()}.ics`, ics, "text/calendar");
  }
  function handleImportFile(e) {
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      const events = parseICS(String(ev.target.result));
      let imported = 0;
      events.forEach(ev2 => {
        const free = getAvailableBeds(mapRoom, ev2.checkIn, ev2.checkOut, ctx.bookings, ctx.holds, ctx.blockedBeds);
        if (free.length === 0) return;
        const bed = free[0];
        const bookingId = makeBookingId(ctx.bookings, new Date(ctx.virtualNow()));
        const total = calcIndividualTotal([bed], ev2.checkIn, ev2.checkOut, ctx.prices);
        ctx.setBookings(prev => [...prev, { bookingId, source:"iCal Import", customerName: ev2.summary || "External booking", customerPhone:null, customerEmail:"", checkIn:ev2.checkIn, checkOut:ev2.checkOut, guestCount:1, bookingType:"individual", roomKey:mapRoom, bedIds:[bed], nights:nightsBetween(ev2.checkIn,ev2.checkOut), subtotal:total, discount:0, tax:0, total, amountPaid:0, balance:total, paymentStatus:"Unpaid", paymentMethod:"—", bookingStatus:"Confirmed", externalBookingId: ev2.uid, specialRequest:"", createdAt:Date.now(), updatedAt:Date.now() }]);
        imported++;
      });
      setImportLog(l => [{ id: uid("il"), time:Date.now(), text:`Parsed ${events.length} VEVENT(s) from ${file.name}, imported ${imported} as blocked availability on ${ROOMS[mapRoom].name}` }, ...l]);
    };
    reader.readAsText(file);
    e.target.value = "";
  }

  return (
    <div>
      <SectionTitle icon={Globe} title="OTA & iCal" sub="Two separate mechanisms — clearly labeled" />
      <Card className="p-4 mb-4 bg-amber-50 border-amber-200">
        <p className="text-xs text-amber-800 leading-relaxed"><AlertTriangle size={12} className="inline mr-1"/>iCal sync frequency depends on the external platform, and this browser prototype cannot poll a live server in the background. For real-time channel accuracy, a server-side PMS/channel manager must be the source of truth. See the Production Architecture doc for the cron-based design.</p>
      </Card>

      <SectionTitle title="OTA channel connections" sub="Simulated sync — no live OTA account is connected" />
      <Card className="overflow-x-auto mb-4">
        <table className="w-full text-sm">
          <thead className="bg-stone-50 text-stone-500 text-xs uppercase"><tr><th className="text-left px-3 py-2.5">Channel</th><th className="text-left px-3 py-2.5">Mapping</th><th className="text-left px-3 py-2.5">Frequency</th><th className="text-left px-3 py-2.5">Last sync</th><th className="text-left px-3 py-2.5">Status</th><th></th></tr></thead>
          <tbody>{ctx.otaConnections.map(c=>(
            <tr key={c.id} className="border-t border-stone-100">
              <td className="px-3 py-2.5 font-medium">{c.name}</td><td className="px-3 py-2.5 text-xs">{c.roomMapping}</td><td className="px-3 py-2.5 text-xs">{c.syncFrequency}</td>
              <td className="px-3 py-2.5 text-xs">{c.lastSync?fmtDateTime(c.lastSync):"Never"}</td><td className="px-3 py-2.5"><Badge tone={c.status==="Active"?"emerald":"slate"}>{c.status}</Badge></td>
              <td className="px-3 py-2.5"><Btn size="sm" tone="secondary" icon={RefreshCw} onClick={()=>doOtaSync(ctx, c.id)}>Sync now</Btn></td>
            </tr>
          ))}</tbody>
        </table>
      </Card>
      {ctx.syncLog.length>0 && <Card className="p-3 mb-4 text-xs text-stone-600 space-y-1">{ctx.syncLog.map((l)=><p key={l.id}>{fmtDateTime(l.time)} — {l.text}</p>)}</Card>}

      <SectionTitle title="iCal import / export" sub="Real .ics files — generated and parsed in your browser" />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card className="p-4">
          <p className="text-sm font-semibold mb-2">Export</p>
          <div className="flex flex-wrap gap-1.5">
            <Btn size="sm" icon={Download} onClick={()=>exportIcs("All")}>All bookings</Btn>
            <Btn size="sm" tone="secondary" icon={Download} onClick={()=>exportIcs("AC")}>AC room</Btn>
            <Btn size="sm" tone="secondary" icon={Download} onClick={()=>exportIcs("NAC")}>Non-AC room</Btn>
          </div>
        </Card>
        <Card className="p-4">
          <p className="text-sm font-semibold mb-2">Import an .ics file</p>
          <Field label="Map imported events to"><Select value={mapRoom} onChange={e=>setMapRoom(e.target.value)} options={[{value:"AC",label:"AC Dormitory"},{value:"NAC",label:"Non-AC Dormitory"}]} /></Field>
          <input ref={fileInputRef} type="file" accept=".ics" onChange={handleImportFile} className="hidden" />
          <Btn size="sm" icon={Upload} onClick={()=>fileInputRef.current.click()}>Choose .ics file</Btn>
          {importLog.length>0 && <div className="mt-2 text-xs text-stone-500 space-y-1">{importLog.map((l)=><p key={l.id}>{l.text}</p>)}</div>}
        </Card>
      </div>
    </div>
  );
}
/* ---------------- ANALYTICS ---------------- */
function AnalyticsScreen({ ctx }) {
  const [range, setRange] = useState("7");
  const days = range === "today" ? 1 : parseInt(range);
  const start = addDays(ctx.todayIso, -(days-1));
  const dateRange = Array.from({length:days}, (_,i)=>addDays(start,i));
  const active = ctx.bookings.filter(b=>b.bookingStatus!=="Cancelled");

  const occSeries = dateRange.map(d => ({ date: fmtDateShort(d), occ: Math.round(100*ALL_BED_IDS.filter(id=>!isBedFree(id,d,addDays(d,1),ctx.bookings,ctx.holds,ctx.blockedBeds)).length/16) }));
  const revSeries = dateRange.map(d => {
    let rev = 0;
    active.forEach(b => { if (overlap(b.checkIn,b.checkOut,d,addDays(d,1)) && b.nights>0) rev += b.total / b.nights; });
    return { date: fmtDateShort(d), revenue: Math.round(rev) };
  });
  const sourceData = SOURCES.map(s => ({ name:s, value: active.filter(b=>b.source===s).length })).filter(x=>x.value>0);
  const roomDemand = [{ name:"AC", value: active.filter(b=>b.roomKey==="AC").length }, { name:"Non-AC", value: active.filter(b=>b.roomKey==="NAC").length }];
  const totalRevenue = active.reduce((s,b)=>s+b.total,0);
  const avgBooking = active.length ? Math.round(totalRevenue/active.length) : 0;
  const cancelled = ctx.bookings.filter(b=>b.bookingStatus==="Cancelled").length;
  const privateBookings = active.filter(b=>b.bookingType==="private").length;
  const waConversions = active.filter(b=>b.source==="WhatsApp AI").length;
  const conversionRate = ctx.aiStats.inquiries ? ((waConversions/ctx.aiStats.inquiries)*100).toFixed(1) : "0.0";

  return (
    <div>
      <SectionTitle icon={BarChart3} title="Analytics" right={<Select value={range} onChange={e=>setRange(e.target.value)} options={[{value:"today",label:"Today"},{value:"7",label:"7 days"},{value:"30",label:"30 days"}]} className="w-auto" />} />
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <StatCard label="Total revenue" value={fmtMoney(totalRevenue)} icon={IndianRupee} tone="emerald" />
        <StatCard label="Avg booking value" value={fmtMoney(avgBooking)} icon={TrendingUp} tone="sky" />
        <StatCard label="Private bookings" value={privateBookings} icon={BedDouble} tone="teal" />
        <StatCard label="Cancellations" value={cancelled} icon={XCircle} tone="rose" />
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <Card className="p-4"><p className="text-sm font-semibold mb-3">Occupancy % (per night)</p>
          <div style={{height:200}}><ResponsiveContainer><LineChart data={occSeries}><CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9"/><XAxis dataKey="date" fontSize={11}/><YAxis fontSize={11} domain={[0,100]}/><Tooltip/><Line type="monotone" dataKey="occ" stroke="#0f766e" strokeWidth={2} dot={false}/></LineChart></ResponsiveContainer></div>
        </Card>
        <Card className="p-4"><p className="text-sm font-semibold mb-3">Revenue per night (₹)</p>
          <div style={{height:200}}><ResponsiveContainer><BarChart data={revSeries}><CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9"/><XAxis dataKey="date" fontSize={11}/><YAxis fontSize={11}/><Tooltip/><Bar dataKey="revenue" fill="#d97706" radius={[4,4,0,0]}/></BarChart></ResponsiveContainer></div>
        </Card>
        <Card className="p-4"><p className="text-sm font-semibold mb-3">Bookings by channel</p>
          <div style={{height:220}}><ResponsiveContainer><PieChart><Pie data={sourceData} dataKey="value" nameKey="name" outerRadius={80} label={({name})=>name}>{sourceData.map((e,i)=><Cell key={i} fill={SOURCE_COLORS[e.name]||"#64748b"}/>)}</Pie><Tooltip/></PieChart></ResponsiveContainer></div>
        </Card>
        <Card className="p-4"><p className="text-sm font-semibold mb-3">AC vs Non-AC demand</p>
          <div style={{height:220}}><ResponsiveContainer><BarChart data={roomDemand}><CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9"/><XAxis dataKey="name" fontSize={11}/><YAxis fontSize={11}/><Tooltip/><Bar dataKey="value" fill="#0ea5e9" radius={[4,4,0,0]}/></BarChart></ResponsiveContainer></div>
        </Card>
      </div>
      <Card className="p-4">
        <p className="text-sm font-semibold mb-3 flex items-center gap-1.5"><Sparkles size={14} className="text-teal-600"/> AI receptionist performance</p>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <StatCard label="Inquiries" value={ctx.aiStats.inquiries} />
          <StatCard label="Recommendations shown" value={ctx.aiStats.recommendationsGiven} />
          <StatCard label="Bookings converted" value={waConversions} tone="emerald" />
          <StatCard label="Conversion rate" value={`${conversionRate}%`} tone="teal" />
          <StatCard label="Private upsells shown" value={ctx.aiStats.privateUpsellShown} />
          <StatCard label="Private bookings via AI" value={active.filter(b=>b.source==="WhatsApp AI"&&b.bookingType==="private").length} />
          <StatCard label="Callback requests" value={ctx.leads.length} />
          <StatCard label="Abandoned inquiries" value={Math.max(0, ctx.aiStats.inquiries - waConversions)} />
        </div>
      </Card>
    </div>
  );
}

/* ---------------- CATALOG / PRICING ---------------- */
function CatalogScreen({ ctx }) {
  const products = [
    { key:"AC-Upper", name:"AC Upper Bed", room:"AC Dormitory", cap:1, desc:"Upper bunk in the AC dormitory" },
    { key:"AC-Lower", name:"AC Lower Bed", room:"AC Dormitory", cap:1, desc:"Lower bunk in the AC dormitory" },
    { key:"NAC-Upper", name:"Non-AC Upper Bed", room:"Non-AC Dormitory", cap:1, desc:"Upper bunk in the non-AC dormitory" },
    { key:"NAC-Lower", name:"Non-AC Lower Bed", room:"Non-AC Dormitory", cap:1, desc:"Lower bunk in the non-AC dormitory" },
    { key:"AC-Private", name:"Private AC Room", room:"AC Dormitory", cap:8, desc:"Entire AC room, all 8 beds — only when all 8 are free" },
    { key:"NAC-Private", name:"Private Non-AC Room", room:"Non-AC Dormitory", cap:8, desc:"Entire non-AC room, all 8 beds — only when all 8 are free" },
  ];
  function setPrice(key, val) { ctx.setPrices(prev => ({ ...prev, [key]: Number(val)||0 })); }
  return (
    <div>
      <SectionTitle icon={Package} title="Catalog & pricing" sub="Every screen reads prices from here — nothing is hard-coded" />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
        {products.map(p => (
          <Card key={p.key} className="p-4">
            <div className="flex items-center justify-between mb-1">
              <p className="font-semibold text-sm">{p.name}</p>
              <button onClick={()=>ctx.setCatalogActive(prev=>({...prev,[p.key]:!prev[p.key]}))} className={`text-xs px-2 py-0.5 rounded-full ${ctx.catalogActive[p.key]?"bg-emerald-100 text-emerald-700":"bg-stone-100 text-stone-500"}`}>{ctx.catalogActive[p.key]?"Active":"Inactive"}</button>
            </div>
            <p className="text-xs text-stone-500 mb-2">{p.desc} · capacity {p.cap}</p>
            <div className="flex items-center gap-2">
              <span className="text-xs text-stone-400">₹</span>
              <TextInput type="number" value={ctx.prices[p.key]} onChange={e=>setPrice(p.key, e.target.value)} className="max-w-[120px]" />
              <span className="text-xs text-stone-400">{p.cap===8?"/ room / night":"/ person / night"}</span>
            </div>
          </Card>
        ))}
      </div>
      <Card className="p-4">
        <p className="text-sm font-semibold mb-2">Weekend pricing</p>
        <div className="flex items-center gap-2 mb-1">
          <TextInput type="number" value={ctx.prices.weekendSurchargePct} onChange={e=>ctx.setPrices(prev=>({...prev,weekendSurchargePct:Number(e.target.value)||0}))} className="max-w-[100px]" />
          <span className="text-sm text-stone-600">% surcharge on Friday and Saturday nights</span>
        </div>
        <p className="text-xs text-stone-400">Applied automatically, night by night, in every price calculation across the app.</p>
        <p className="text-xs text-stone-400 mt-2">Seasonal calendars and one-off special-date pricing aren't wired into the live calculator yet — noted as a future pricing-engine iteration in the Production Architecture doc.</p>
      </Card>
    </div>
  );
}
/* ---------------- SETTINGS ---------------- */
function SettingsScreen({ ctx }) {
  const [tab, setTab] = useState("property");
  const s = ctx.settings;
  function upd(patch) { ctx.setSettings(prev => ({ ...prev, ...patch })); }
  return (
    <div>
      <SectionTitle icon={SettingsIcon} title="Settings" />
      <div className="flex gap-1 mb-4 flex-wrap">
        {[["property","Property"],["policies","Policies"],["ai","AI & Language"],["data","Data source"],["roadmap","Production roadmap"]].map(([k,l])=>(
          <button key={k} onClick={()=>setTab(k)} className={`px-3 py-1.5 rounded-lg text-xs font-medium ${tab===k?"bg-teal-700 text-white":"bg-stone-100 text-stone-600"}`}>{l}</button>
        ))}
      </div>
      {tab==="property" && (
        <Card className="p-4 max-w-lg space-y-1">
          <Field label="Property name"><TextInput value={s.propertyName} onChange={e=>upd({propertyName:e.target.value})} /></Field>
          <Field label="Address"><TextInput value={s.address} onChange={e=>upd({address:e.target.value})} /></Field>
          <Field label="Phone"><TextInput value={s.phone} onChange={e=>upd({phone:e.target.value})} /></Field>
          <Field label="WhatsApp business number"><TextInput value={s.whatsapp} onChange={e=>upd({whatsapp:e.target.value})} /></Field>
          <Field label="Email"><TextInput value={s.email} onChange={e=>upd({email:e.target.value})} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Check-in time"><TextInput value={s.checkInTime} onChange={e=>upd({checkInTime:e.target.value})} /></Field>
            <Field label="Check-out time"><TextInput value={s.checkOutTime} onChange={e=>upd({checkOutTime:e.target.value})} /></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Currency"><TextInput value={s.currency} onChange={e=>upd({currency:e.target.value})} /></Field>
            <Field label="Timezone"><TextInput value={s.timezone} onChange={e=>upd({timezone:e.target.value})} /></Field>
          </div>
          <Field label="Tax">
            <div className="flex items-center gap-2">
              <input type="checkbox" checked={s.taxEnabled} onChange={e=>upd({taxEnabled:e.target.checked})} />
              <span className="text-xs text-stone-600">Enabled</span>
              <TextInput type="number" value={s.taxRatePct} onChange={e=>upd({taxRatePct:Number(e.target.value)||0})} className="max-w-[80px]" />
              <span className="text-xs text-stone-600">%</span>
            </div>
          </Field>
        </Card>
      )}
      {tab==="policies" && (
        <Card className="p-4 max-w-lg space-y-1">
          <Field label="Cancellation policy"><textarea value={s.cancellationPolicy} onChange={e=>upd({cancellationPolicy:e.target.value})} className={inputCls} rows={3} /></Field>
          <Field label="Payment policy"><textarea value={s.paymentPolicy} onChange={e=>upd({paymentPolicy:e.target.value})} className={inputCls} rows={3} /></Field>
        </Card>
      )}
      {tab==="ai" && (
        <Card className="p-4 max-w-lg space-y-1">
          <Field label="Languages supported">
            <div className="flex gap-3 text-sm">
              {["hindi","english","hinglish"].map(l => (
                <label key={l} className="flex items-center gap-1.5 capitalize"><input type="checkbox" checked={s.languages[l]} onChange={e=>upd({languages:{...s.languages,[l]:e.target.checked}})} />{l}</label>
              ))}
            </div>
          </Field>
          <Field label="Human handoff number"><TextInput value={s.humanHandoffNumber} onChange={e=>upd({humanHandoffNumber:e.target.value})} /></Field>
          <Field label="AI decision panel">
            <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={s.showDecisionPanel} onChange={e=>upd({showDecisionPanel:e.target.checked})} /> Show live reasoning panel on the WhatsApp AI tab</label>
          </Field>
        </Card>
      )}
      {tab==="data" && (
        <Card className="p-4 max-w-lg space-y-3">
          <div>
            <p className="text-sm font-medium text-stone-700 mb-1">Data source</p>
            <div className="flex gap-2">
              {["prototype","production"].map(m => (
                <button key={m} onClick={()=>upd({dataMode:m})} className={`px-3 py-1.5 rounded-lg text-xs font-medium capitalize ${s.dataMode===m?"bg-teal-700 text-white":"bg-stone-100 text-stone-600"}`}>{m}</button>
              ))}
            </div>
            <p className="text-[11px] text-stone-400 mt-1.5">
              {s.dataMode === "prototype"
                ? "Everything runs in this browser session's persistent storage — nothing leaves the browser. This is the default and what every screen has been using."
                : "New WhatsApp AI bookings call the Laravel API below (see kush-stay-laravel-backend.zip). Other admin actions (editing, cancelling, OTA sync, pass system, demo controls) still write to local state only — see the README's \"not fully built yet\" list."}
            </p>
          </div>
          {s.dataMode === "production" && (<>
            <Field label="API base URL"><TextInput placeholder="https://api.yourdomain.example" value={s.apiBaseUrl} onChange={e=>upd({apiBaseUrl:e.target.value})} /></Field>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Property ID"><TextInput type="number" value={s.apiPropertyId} onChange={e=>upd({apiPropertyId:Number(e.target.value)||1})} /></Field>
              <Field label="AC room ID"><TextInput type="number" value={s.acRoomApiId} onChange={e=>upd({acRoomApiId:Number(e.target.value)||1})} /></Field>
              <Field label="Non-AC room ID"><TextInput type="number" value={s.nacRoomApiId} onChange={e=>upd({nacRoomApiId:Number(e.target.value)||2})} /></Field>
            </div>
            <p className="text-[11px] text-stone-400">IDs default to the order KushStaySeeder inserts them in — confirm against a real <code>GET /api/rooms</code> call once the backend is deployed.</p>
          </>)}
        </Card>
      )}
      {tab==="roadmap" && (
        <Card className="p-4 space-y-3 text-sm text-stone-700">
          <p className="font-semibold">Production integration roadmap</p>
          {[
            ["Phase 1","Browser prototype (this artifact) — real inventory, pricing, and validation logic"],
            ["Phase 2","PHP 8.2+ / Laravel + MySQL backend replacing in-browser storage — see the companion Production Architecture document for schema and API design"],
            ["Phase 3","WhatsApp Business Cloud API webhook calling the same booking API"],
            ["Phase 4","Real payment gateway (Razorpay/UPI PSP) replacing the simulated payment step"],
            ["Phase 5","Real OTA/iCal integration — token-based endpoints + server cron sync"],
            ["Phase 6","CRM / guest communication history"],
            ["Phase 7","AI voice messages (speech-to-text → same AI agent)"],
            ["Phase 8","Automated call/callback system for handoff leads"],
          ].map(([p,d]) => <p key={p}><span className="font-medium">{p}:</span> {d}</p>)}
          <p className="text-xs text-stone-400 pt-2">Full database schema, REST API docs, cPanel deployment steps, cron jobs, and the security checklist are in the separate Production Architecture markdown file delivered alongside this app.</p>
        </Card>
      )}
    </div>
  );
}

/* ---------------- DEMO CONTROLS + SYSTEM TESTS ---------------- */
function DemoPanel({ ctx, open, onClose }) {
  const [testResults, setTestResults] = useState(null);
  function reset() {
    const seed = buildInitialState();
    ctx.setBookings(seed.bookings); ctx.setLeads(seed.leads); ctx.setOtaConnections(seed.otaConnections);
    ctx.setBlockedBeds({}); ctx.setCustomerExtras({}); ctx.setPrices(seed.prices); ctx.setSettings(seed.settings);
    ctx.setAiStats(seed.aiStats); ctx.setCatalogActive(seed.catalogActive); ctx.setHolds([]); ctx.setSyncLog([]);
    setTestResults(null);
  }
  function addSample() {
    const roomKey = Math.random()>0.5?"AC":"NAC";
    const ci = addDays(ctx.todayIso, 3+Math.floor(Math.random()*15));
    const co = addDays(ci, 1+Math.floor(Math.random()*3));
    const free = getAvailableBeds(roomKey, ci, co, ctx.bookings, ctx.holds, ctx.blockedBeds);
    if (!free.length) return;
    const bed = free[0];
    const total = calcIndividualTotal([bed], ci, co, ctx.prices);
    const name = FAKE_GUEST_NAMES[Math.floor(Math.random()*FAKE_GUEST_NAMES.length)];
    ctx.setBookings(prev => [...prev, { bookingId: makeBookingId(prev, new Date(ctx.virtualNow())), source:"Manual/Admin", customerName:name, customerPhone:"9"+Math.floor(100000000+Math.random()*899999999), customerEmail:"", checkIn:ci, checkOut:co, guestCount:1, bookingType:"individual", roomKey, bedIds:[bed], nights:nightsBetween(ci,co), subtotal:total, discount:0, tax:0, total, amountPaid:0, balance:total, paymentStatus:"Unpaid", paymentMethod:"Cash", bookingStatus:"Confirmed", externalBookingId:null, specialRequest:"", createdAt:Date.now(), updatedAt:Date.now() }]);
  }
  function cancelLast() {
    const last = ctx.bookings.filter(b=>b.bookingStatus!=="Cancelled").sort((a,b)=>b.createdAt-a.createdAt)[0];
    if (last) ctx.setBookings(prev => prev.map(b=>b.bookingId===last.bookingId?{...b,bookingStatus:"Cancelled",updatedAt:Date.now()}:b));
  }
  function advance(ms) {
    ctx.setSystemOffsetMs(prev => prev + ms);
    ctx.setHolds(prev => prev.filter(h => h.expiresAt > ctx.virtualNow() + ms));
  }
  function expireHoldsNow() { ctx.setHolds(prev => prev.filter(h => h.expiresAt > ctx.virtualNow())); }
  function randomizePayment() {
    const pending = ctx.bookings.filter(b=>b.paymentStatus==="Unpaid" && b.bookingStatus!=="Cancelled");
    if (!pending.length) return;
    const target = pending[Math.floor(Math.random()*pending.length)];
    const success = Math.random() > 0.3;
    ctx.setBookings(prev => prev.map(b => b.bookingId===target.bookingId ? { ...b, amountPaid: success?b.total:0, balance: success?0:b.total, paymentStatus: success?"Paid":"Unpaid", bookingStatus: success?b.bookingStatus:"Cancelled", updatedAt:Date.now() } : b));
  }
  function exportJson() {
    const bundle = { bookings:ctx.bookings, leads:ctx.leads, otaConnections:ctx.otaConnections, blockedBeds:ctx.blockedBeds, customerExtras:ctx.customerExtras, prices:ctx.prices, settings:ctx.settings, aiStats:ctx.aiStats, catalogActive:ctx.catalogActive, exportedAt:Date.now() };
    downloadTextFile(`kush-stay-data-${ctx.todayIso}.json`, JSON.stringify(bundle,null,2), "application/json");
  }
  function importJson(e) {
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      try {
        const data = JSON.parse(String(ev.target.result));
        if (Array.isArray(data.bookings)) ctx.setBookings(data.bookings);
        if (Array.isArray(data.leads)) ctx.setLeads(data.leads);
        if (Array.isArray(data.otaConnections)) ctx.setOtaConnections(data.otaConnections);
        if (data.blockedBeds) ctx.setBlockedBeds(data.blockedBeds);
        if (data.customerExtras) ctx.setCustomerExtras(data.customerExtras);
        if (data.prices) ctx.setPrices(data.prices);
        if (data.settings) ctx.setSettings(data.settings);
        if (data.aiStats) ctx.setAiStats(data.aiStats);
        if (data.catalogActive) ctx.setCatalogActive(data.catalogActive);
      } catch (err) { alert("That file doesn't look like a valid Kush Stay export."); }
    };
    reader.readAsText(file);
    e.target.value = "";
  }
  function runTests() { setTestResults(runSystemTests(ctx.bookings, ctx.prices)); }
  const importRef = useRef(null);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={onClose}>
      <div className="bg-white w-full sm:w-[420px] h-full overflow-y-auto p-4" onClick={e=>e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4"><p className="font-semibold text-stone-900">Demo controls & system tests</p><button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-stone-100 flex items-center justify-center"><X size={16}/></button></div>
        <div className="grid grid-cols-2 gap-1.5 mb-4">
          <Btn size="sm" tone="secondary" icon={Plus} onClick={addSample}>Add sample booking</Btn>
          <Btn size="sm" tone="secondary" icon={Trash2} onClick={cancelLast}>Cancel last booking</Btn>
          <Btn size="sm" tone="secondary" icon={RefreshCw} onClick={()=>doOtaSync(ctx)}>Simulate OTA sync</Btn>
          <Btn size="sm" tone="secondary" icon={Wallet} onClick={randomizePayment}>Simulate a payment</Btn>
          <Btn size="sm" tone="secondary" icon={Clock} onClick={()=>advance(15*60000)}>+15 min</Btn>
          <Btn size="sm" tone="secondary" icon={Clock} onClick={()=>advance(86400000)}>+1 day</Btn>
          <Btn size="sm" tone="secondary" icon={ArrowRightLeft} onClick={expireHoldsNow}>Expire holds now</Btn>
          <Btn size="sm" tone="danger" icon={RefreshCw} onClick={reset}>Reset demo data</Btn>
        </div>
        <div className="grid grid-cols-2 gap-1.5 mb-4">
          <Btn size="sm" tone="outline" icon={Download} onClick={exportJson}>Export data (JSON)</Btn>
          <input ref={importRef} type="file" accept="application/json" onChange={importJson} className="hidden" />
          <Btn size="sm" tone="outline" icon={Upload} onClick={()=>importRef.current.click()}>Import data (JSON)</Btn>
        </div>
        <p className="text-[11px] text-stone-400 mb-4">Data is saved automatically to this browser's persistent artifact storage as you use the app, so it survives a page refresh.</p>
        <div className="border-t border-stone-100 pt-3">
          <div className="flex items-center justify-between mb-2"><p className="text-sm font-semibold flex items-center gap-1.5"><FlaskConical size={14}/> System tests</p><Btn size="sm" onClick={runTests}>Run all 16</Btn></div>
          {testResults && (
            <div className="space-y-1.5">
              <p className="text-xs text-stone-500 mb-1">{testResults.filter(t=>t.pass).length}/{testResults.length} passed</p>
              {testResults.map((t,i)=>(
                <div key={i} className={`text-xs p-2 rounded-lg border ${t.pass?"border-emerald-200 bg-emerald-50":"border-rose-200 bg-rose-50"}`}>
                  <p className="font-medium flex items-center gap-1">{t.pass?<CheckCircle2 size={12} className="text-emerald-600"/>:<XCircle size={12} className="text-rose-600"/>}{t.name}</p>
                  <p className="text-stone-500 mt-0.5">{t.detail}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/* ---------------- DEMO LOGIN GATE ---------------- */
function LoginGate({ onLogin }) {
  const [u, setU] = useState(""); const [p, setP] = useState(""); const [err, setErr] = useState(false);
  function submit() { if (u==="admin" && p==="admin123") onLogin(); else setErr(true); }
  return (
    <Card className="p-6 max-w-sm mx-auto mt-10 text-center">
      <Lock className="mx-auto mb-2 text-teal-700" size={24} />
      <p className="font-semibold text-stone-900 mb-1">Admin sign-in</p>
      <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5 mb-4">DEMO LOGIN ONLY — NOT FOR PRODUCTION. Hardcoded credentials, no real authentication.</p>
      <div className="text-left space-y-2">
        <TextInput placeholder="Username (admin)" value={u} onChange={e=>setU(e.target.value)} />
        <TextInput placeholder="Password (admin123)" type="password" value={p} onChange={e=>setP(e.target.value)} onKeyDown={e=>e.key==="Enter"&&submit()} />
      </div>
      {err && <p className="text-xs text-rose-600 mt-2">Incorrect demo credentials.</p>}
      <Btn onClick={submit} className="mt-3 w-full">Sign in</Btn>
      <p className="text-[11px] text-stone-400 mt-3">Production requires password_hash, sessions, CSRF, role-based access, and rate limiting — see the Production Architecture doc.</p>
    </Card>
  );
}
/* ---------------- 30-DAY PASS SYSTEM: CONSTANTS + ENGINE ---------------- */
const PASS_CATEGORIES = ["NAC-Upper", "NAC-Lower", "AC-Upper", "AC-Lower"];
const PASS_CATEGORY_LABELS = { "NAC-Upper": "Upper Non-AC", "NAC-Lower": "Lower Non-AC", "AC-Upper": "Upper AC", "AC-Lower": "Lower AC" };
const PASS_STATUSES = ["pending", "payment_pending", "paid", "active", "expired", "suspended", "cancelled", "refunded"];

const DEFAULT_PASS_PRODUCTS = [
  { category: "NAC-Upper", displayName: "Upper Non-AC", normalPrice: 5500, grandOpeningPrice: 4500, totalDays: 30, active: true },
  { category: "NAC-Lower", displayName: "Lower Non-AC", normalPrice: 6500, grandOpeningPrice: 5500, totalDays: 30, active: true },
  { category: "AC-Upper", displayName: "Upper AC", normalPrice: 6500, grandOpeningPrice: 5500, totalDays: 30, active: true },
  { category: "AC-Lower", displayName: "Lower AC", normalPrice: 7500, grandOpeningPrice: 6500, totalDays: 30, active: true },
];
// Exact matrix from the spec, per night, in rupees (kept as plain numbers on the frontend,
// consistent with the rest of this file — the Laravel backend stores the same values as integer
// paise, per the "never use floats for money" requirement on the server side of the system).
const PASS_UPGRADE_MATRIX = {
  "NAC-Upper": { "NAC-Upper": 0, "NAC-Lower": 40, "AC-Upper": 40, "AC-Lower": 80 },
  "NAC-Lower": { "NAC-Upper": 0, "NAC-Lower": 0, "AC-Upper": 40, "AC-Lower": 80 },
  "AC-Upper": { "NAC-Upper": 0, "NAC-Lower": 0, "AC-Upper": 0, "AC-Lower": 40 },
  "AC-Lower": { "NAC-Upper": 0, "NAC-Lower": 0, "AC-Upper": 0, "AC-Lower": 0 },
};
const DEFAULT_PASS_SETTINGS = { grandOpeningActive: true, grandOpeningLimit: 150, grandOpeningSold: 0 };

function passCategoryOfBed(bedId) { const { room, type } = bedMeta(bedId); return `${room === "AC" ? "AC" : "NAC"}-${type}`; }
function categoryRoomKey(category) { return category.startsWith("AC-") ? "AC" : "NAC"; }
function categoryPosition(category) { return category.endsWith("Upper") ? "Upper" : "Lower"; }

function currentPassPrice(product, settings) { return settings.grandOpeningActive ? product.grandOpeningPrice : product.normalPrice; }

/** Activation date through the day before the same calendar date one year later (e.g. 06 Sep 2026 -> 05 Sep 2027). */
function passExpiryDate(activatedIso) {
  const d = new Date(activatedIso + "T00:00:00");
  d.setFullYear(d.getFullYear() + 1);
  d.setDate(d.getDate() - 1);
  return toISO(d);
}

function makePassRef(passes) { return "KS-PASS-" + String(passes.length + 1).padStart(3, "0"); }

/**
 * For each of the 4 bed categories: is at least one bed of that category free for the range,
 * and what would the upgrade fee be relative to this pass's base category? Never silently
 * substitutes — this is purely a read of live availability, same engine as everything else.
 */
function passPreviewAvailability(baseCategory, checkIn, checkOut, bookings, holds, blocked) {
  const nights = nightsBetween(checkIn, checkOut);
  const options = {};
  PASS_CATEGORIES.forEach(cat => {
    const roomKey = categoryRoomKey(cat), position = categoryPosition(cat);
    const free = getAvailableBeds(roomKey, checkIn, checkOut, bookings, holds, blocked, position);
    const feePerNight = PASS_UPGRADE_MATRIX[baseCategory][cat];
    options[cat] = { available: free.length > 0, freeBedId: free[0] || null, feePerNight, upgradeTotal: feePerNight * nights };
  });
  return { nights, options };
}
/* ---------------- PASS SYSTEM UI ---------------- */
function PassLandingCard({ onStart, onLogin, settings }) {
  const soldOut = settings.grandOpeningActive && settings.grandOpeningSold >= settings.grandOpeningLimit;
  return (
    <Card className="p-6 max-w-xl mx-auto text-center">
      <Sparkles className="mx-auto mb-2 text-teal-600" size={28} />
      <h2 className="text-xl font-semibold text-stone-900 mb-1">30-Day Kush Stay Pass</h2>
      <p className="text-sm text-stone-600 mb-4">30 accommodation days, valid for 1 year. Use them whenever you like — they don't need to be consecutive. All stays are subject to bed availability.</p>
      {soldOut ? (
        <Badge tone="rose">GRAND OPENING PASSES SOLD OUT</Badge>
      ) : settings.grandOpeningActive ? (
        <Badge tone="teal">Grand Opening pricing — {settings.grandOpeningLimit - settings.grandOpeningSold} of {settings.grandOpeningLimit} left</Badge>
      ) : null}
      <div className="flex flex-col gap-2 mt-4">
        {!soldOut && <Btn onClick={onStart}>Get your pass</Btn>}
        <Btn tone="secondary" onClick={onLogin}>I already have a pass</Btn>
      </div>
    </Card>
  );
}

function PassSelectStep({ products, settings, onSelect }) {
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-3">Choose your pass category</p>
      <div className="space-y-2">
        {products.filter(p => p.active).map(p => (
          <button key={p.category} onClick={() => onSelect(p)} className="w-full text-left border border-stone-200 rounded-xl p-3.5 hover:border-teal-400 hover:bg-teal-50/40 flex items-center justify-between">
            <div><p className="font-medium text-stone-900">{p.displayName}</p><p className="text-xs text-stone-400">30 days · 1 year validity</p></div>
            <div className="text-right">
              {settings.grandOpeningActive && <p className="text-xs text-stone-400 line-through">{fmtMoney(p.normalPrice)}</p>}
              <p className="font-semibold text-teal-700">{fmtMoney(currentPassPrice(p, settings))}</p>
            </div>
          </button>
        ))}
      </div>
    </Card>
  );
}

function PassDetailsStep({ onSubmit }) {
  const [f, setF] = useState({ name: "", email: "", phone: "" });
  const valid = f.name.trim() && /^\S+@\S+\.\S+$/.test(f.email) && /^\d{10}$/.test(f.phone);
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-3">Your details</p>
      <Field label="Full name"><TextInput value={f.name} onChange={e => setF({ ...f, name: e.target.value })} /></Field>
      <Field label="Email"><TextInput type="email" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} /></Field>
      <Field label="Mobile number"><TextInput value={f.phone} onChange={e => setF({ ...f, phone: e.target.value })} /></Field>
      <Btn disabled={!valid} onClick={() => onSubmit(f)}>Continue to email verification</Btn>
    </Card>
  );
}

function PassOtpStep({ email, otpState, onSend, onVerify }) {
  const [code, setCode] = useState("");
  const [err, setErr] = useState("");
  const secondsLeft = otpState.lastSentAt ? Math.max(0, 60 - Math.floor((Date.now() - otpState.lastSentAt) / 1000)) : 0;
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-1">Verify your email</p>
      <p className="text-xs text-stone-500 mb-3">We sent a 6-digit code to {email}. It expires in 10 minutes.</p>
      {otpState.devCode && <p className="text-xs bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5 mb-3">Demo mode (no real email configured) — your code is <b>{otpState.devCode}</b></p>}
      <Field label="6-digit code"><TextInput value={code} maxLength={6} onChange={e => setCode(e.target.value.replace(/\D/g, ""))} /></Field>
      {err && <p className="text-xs text-rose-600 mb-2">{err}</p>}
      <div className="flex gap-2">
        <Btn onClick={() => { const r = onVerify(code); if (!r.ok) setErr(r.error); }}>Verify</Btn>
        <Btn tone="secondary" disabled={secondsLeft > 0} onClick={onSend}>{secondsLeft > 0 ? `Resend in ${secondsLeft}s` : "Resend code"}</Btn>
      </div>
      <p className="text-[11px] text-stone-400 mt-2">{otpState.attempts || 0}/5 attempts used.</p>
    </Card>
  );
}

function PassPaymentStep({ amount, onOutcome }) {
  const [status, setStatus] = useState("choosing");
  const [method, setMethod] = useState(null);
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-1">Payment</p>
      <p className="text-sm text-stone-600 mb-3">Amount payable: <span className="font-semibold">{fmtMoney(amount)}</span></p>
      {status === "choosing" && (
        <div className="grid grid-cols-2 gap-1.5">
          {["UPI", "Card", "Cash at property", "Pay later"].map(m => (
            <button key={m} onClick={() => { setMethod(m); setStatus("processing"); }} className="py-2 rounded-lg border border-stone-300 text-sm hover:bg-stone-50">{m}</button>
          ))}
        </div>
      )}
      {status === "processing" && (<>
        <p className="text-xs text-stone-400 mb-2">(Simulated {method} payment — no real gateway connected yet)</p>
        <div className="grid grid-cols-2 gap-1.5">
          <Btn tone="primary" onClick={() => onOutcome(true, method)}>Simulate success</Btn>
          <Btn tone="danger" onClick={() => onOutcome(false, method)}>Simulate failure</Btn>
        </div>
      </>)}
    </Card>
  );
}

function PassTermsCard() {
  const terms = [
    "Each pass provides up to 30 accommodation days.", "The pass is valid for one year from activation.",
    "Days may be used across multiple stays.", "Accommodation is always subject to availability.",
    "The pass does not guarantee a bed on any particular date.", "The customer may select any available bed category.",
    "Higher-category beds may require an additional per-night upgrade fee.",
    "Choosing a lower-category bed does not generate a cash refund, credit, or additional pass day.",
    "One pass day is consumed for each accommodation night.", "Checkout date is not counted as an accommodation night.",
    "The pass is personal/non-transferable unless Kush Stay explicitly permits otherwise.",
    "Unused days expire when the pass expires unless Kush Stay authorizes an extension.",
    "Cancellation and refund rules are governed by the applicable policy.",
  ];
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-3">Terms & conditions</p>
      <ol className="text-xs text-stone-600 space-y-1.5 list-decimal pl-4">{terms.map((t, i) => <li key={i}>{t}</li>)}</ol>
    </Card>
  );
}
function PassLoginStep({ onSend, onVerify, otpState }) {
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [err, setErr] = useState("");
  const sent = otpState.email === email && otpState.lastSentAt;
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-3">My Kush Stay Pass</p>
      <Field label="Email used at purchase"><TextInput type="email" value={email} onChange={e => setEmail(e.target.value)} /></Field>
      {!sent ? <Btn disabled={!/^\S+@\S+\.\S+$/.test(email)} onClick={() => onSend(email)}>Send verification code</Btn> : (<>
        {otpState.devCode && <p className="text-xs bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5 mb-2">Demo code: <b>{otpState.devCode}</b></p>}
        <Field label="6-digit code"><TextInput value={code} maxLength={6} onChange={e => setCode(e.target.value.replace(/\D/g, ""))} /></Field>
        {err && <p className="text-xs text-rose-600 mb-2">{err}</p>}
        <Btn onClick={() => { const r = onVerify(email, code); if (!r.ok) setErr(r.error); }}>View my pass</Btn>
      </>)}
    </Card>
  );
}

function PassDashboard({ pass, ledger, bookings, onBook, onHistory, onTerms, onLogout }) {
  const product = PASS_CATEGORY_LABELS[pass.category];
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <div className="flex items-center justify-between mb-1"><p className="font-semibold text-stone-900">My Kush Stay Pass</p><button onClick={onLogout} className="text-xs text-stone-400 hover:text-stone-600">Log out</button></div>
      <p className="text-xs text-stone-400 font-mono mb-3">{pass.passRef}</p>
      <div className="grid grid-cols-3 gap-2 mb-3">
        <StatCard label="Total" value={pass.totalDays} />
        <StatCard label="Used" value={pass.usedDays} />
        <StatCard label="Remaining" value={pass.remainingDays} tone="teal" />
      </div>
      <p className="text-sm text-stone-600 mb-1">Category: <span className="font-medium text-stone-800">{product}</span></p>
      <p className="text-sm text-stone-600 mb-4">Valid until: <span className="font-medium text-stone-800">{fmtDate(pass.expiresAt)}</span> · <StatusBadge status={pass.status} /></p>
      <div className="flex flex-col gap-2">
        <Btn onClick={onBook} disabled={pass.status !== "active" || pass.remainingDays <= 0}>Book my stay</Btn>
        <Btn tone="secondary" onClick={onHistory}>Booking history ({bookings.length})</Btn>
        <Btn tone="ghost" onClick={onTerms}>Terms & conditions</Btn>
      </div>
      {ledger.length > 0 && (
        <div className="mt-4 pt-3 border-t border-stone-100">
          <p className="text-xs font-medium text-stone-500 mb-1.5">Recent ledger activity</p>
          {ledger.slice(0, 5).map(l => (
            <div key={l.id} className="flex justify-between text-xs text-stone-600 py-0.5">
              <span>{fmtDateShort(toISO(new Date(l.createdAt)))} · {l.eventType}</span><span className={l.dayChange >= 0 ? "text-emerald-600" : "text-rose-600"}>{l.dayChange >= 0 ? "+" : ""}{l.dayChange} → {l.balanceAfter}</span>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

function PassBookStep({ pass, ctx, onConfirm, onBack }) {
  const [checkIn, setCheckIn] = useState(ctx.todayIso);
  const [checkOut, setCheckOut] = useState(addDays(ctx.todayIso, 1));
  const preview = checkOut > checkIn ? passPreviewAvailability(pass.category, checkIn, checkOut, ctx.bookings, ctx.holds, ctx.blockedBeds) : null;
  const [selected, setSelected] = useState(null);
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-3">Book with your pass</p>
      <p className="text-xs text-stone-500 mb-3">{pass.remainingDays} day(s) remaining · base category {PASS_CATEGORY_LABELS[pass.category]}</p>
      <div className="grid grid-cols-2 gap-3 mb-3">
        <Field label="Check-in"><TextInput type="date" value={checkIn} onChange={e => setCheckIn(e.target.value)} /></Field>
        <Field label="Check-out"><TextInput type="date" value={checkOut} onChange={e => setCheckOut(e.target.value)} /></Field>
      </div>
      {preview && preview.nights > pass.remainingDays && <p className="text-xs text-rose-600 mb-3">You have only {pass.remainingDays} pass day(s) remaining — this stay needs {preview.nights}.</p>}
      {preview && preview.nights <= pass.remainingDays && (
        <div className="space-y-2 mb-3">
          {PASS_CATEGORIES.map(cat => {
            const o = preview.options[cat];
            return (
              <button key={cat} disabled={!o.available} onClick={() => setSelected(cat)} className={`w-full text-left border rounded-xl p-3 flex items-center justify-between ${selected === cat ? "border-teal-500 bg-teal-50/40" : "border-stone-200"} ${!o.available ? "opacity-40 cursor-not-allowed" : "hover:border-teal-400"}`}>
                <span className="font-medium text-sm">{PASS_CATEGORY_LABELS[cat]}</span>
                <span className="text-xs text-stone-500">{o.available ? (o.feePerNight > 0 ? `${fmtMoney(o.feePerNight)}/night upgrade` : "No upgrade fee") : "Unavailable"}</span>
              </button>
            );
          })}
        </div>
      )}
      {selected && preview.options[selected].feePerNight > 0 && (
        <p className="text-xs bg-stone-50 rounded-lg p-2 mb-3">No refund or additional pass days are provided when choosing a lower-category bed. One pass day is used for each night.</p>
      )}
      <div className="flex gap-2">
        <Btn disabled={!selected} onClick={() => onConfirm(checkIn, checkOut, selected, preview.nights, preview.options[selected])}>
          {selected && preview.options[selected].upgradeTotal > 0 ? `Confirm & pay ${fmtMoney(preview.options[selected].upgradeTotal)}` : "Confirm booking"}
        </Btn>
        <Btn tone="secondary" onClick={onBack}>Back</Btn>
      </div>
    </Card>
  );
}

function PassHistoryCard({ bookings, onBack }) {
  return (
    <Card className="p-5 max-w-xl mx-auto">
      <p className="font-semibold text-stone-900 mb-3">Booking history</p>
      {bookings.length === 0 ? <EmptyState icon={CalendarDays} text="No bookings yet" /> : (
        <div className="space-y-2">{bookings.map(b => (
          <div key={b.id} className="border border-stone-100 rounded-lg p-2.5 text-xs">
            <div className="flex justify-between"><span className="font-medium">{b.bookingRef}</span><StatusBadge status={b.bookingStatus} /></div>
            <p className="text-stone-500 mt-0.5">{fmtDateShort(b.checkIn)} → {fmtDateShort(b.checkOut)} · {PASS_CATEGORY_LABELS[b.usedCategory]} · {b.nights} night(s) · {b.daysConsumed} day(s) used{b.upgradeFee > 0 ? ` · upgrade ${fmtMoney(b.upgradeFee)}` : ""}</p>
          </div>
        ))}</div>
      )}
      <Btn tone="secondary" onClick={onBack} className="mt-3">Back</Btn>
    </Card>
  );
}
function PassScreen({ ctx }) {
  const [view, setView] = useState("landing");
  const [selectedProduct, setSelectedProduct] = useState(null);
  const [customer, setCustomer] = useState(null);
  const [otp, setOtp] = useState({ email: null, purpose: null, code: null, expiresAt: null, attempts: 0, lastSentAt: null, devCode: null, verifiedToken: null, consumed: false });
  const [reservedPass, setReservedPass] = useState(null);
  const [activePass, setActivePass] = useState(null);
  const [pendingPassBooking, setPendingPassBooking] = useState(null);
  const [bookOutcome, setBookOutcome] = useState(null);

  const myLedger = activePass ? ctx.passLedger.filter(l => l.passId === activePass.id).sort((a, b) => b.id - a.id) : [];
  const myBookings = activePass ? ctx.passBookingsLink.filter(pb => pb.passId === activePass.id).sort((a, b) => b.id - a.id) : [];

  function sendOtp(email, purpose = "purchase") {
    const code = String(Math.floor(100000 + Math.random() * 900000));
    setOtp({ email, purpose, code, expiresAt: ctx.virtualNow() + 10 * 60000, attempts: 0, lastSentAt: Date.now(), devCode: code, verifiedToken: null, consumed: false });
  }
  function verifyOtp(email, code) {
    if (otp.email !== email || !otp.code) return { ok: false, error: "Request a code first." };
    if (ctx.virtualNow() > otp.expiresAt) return { ok: false, error: "This code has expired. Request a new one." };
    if (otp.attempts >= 5) return { ok: false, error: "Too many incorrect attempts. Request a new code." };
    if (code !== otp.code) { setOtp(prev => ({ ...prev, attempts: prev.attempts + 1 })); return { ok: false, error: "Incorrect code." }; }
    const token = uid("OTPSESS");
    setOtp(prev => ({ ...prev, verifiedToken: token, consumed: false }));
    return { ok: true, token };
  }

  function reservePass(product, custData) {
    const settings = ctx.passSettings;
    if (settings.grandOpeningActive && settings.grandOpeningSold >= settings.grandOpeningLimit) {
      alert("Grand Opening passes sold out."); setView("landing"); return;
    }
    const grandOpening = settings.grandOpeningActive;
    const price = currentPassPrice(product, settings);
    const pass = {
      id: uid("PASS"), passRef: makePassRef(ctx.passes), customerName: custData.name, customerEmail: custData.email, customerPhone: custData.phone,
      category: product.category, grandOpening, pricePaid: price, totalDays: product.totalDays, usedDays: 0, remainingDays: 0,
      status: "payment_pending", reservedUntil: ctx.virtualNow() + 15 * 60000, activatedAt: null, expiresAt: null, createdAt: Date.now(),
    };
    ctx.setPasses(prev => [...prev, pass]);
    if (grandOpening) ctx.setPassSettings(prev => ({ ...prev, grandOpeningSold: prev.grandOpeningSold + 1 }));
    setReservedPass(pass);
    setOtp(prev => ({ ...prev, consumed: true }));
    setView("payment");
  }

  function confirmPassPayment(success) {
    if (!success) {
      ctx.setPasses(prev => prev.map(p => p.id === reservedPass.id ? { ...p, status: "cancelled" } : p));
      if (reservedPass.grandOpening) ctx.setPassSettings(prev => ({ ...prev, grandOpeningSold: Math.max(0, prev.grandOpeningSold - 1) }));
      setView("select");
      return;
    }
    const activatedAt = ctx.todayIso;
    const expiresAt = passExpiryDate(activatedAt);
    const activated = { ...reservedPass, status: "active", activatedAt, expiresAt, remainingDays: reservedPass.totalDays };
    ctx.setPasses(prev => prev.map(p => p.id === reservedPass.id ? activated : p));
    ctx.setPassLedger(prev => [...prev, { id: prev.length + 1, passId: reservedPass.id, eventType: "grant", dayChange: reservedPass.totalDays, balanceAfter: reservedPass.totalDays, reason: "Pass activated", createdAt: Date.now() }]);
    setActivePass(activated);
    setView("confirmation");
  }

  function loginSendOtp(email) { sendOtp(email, "login"); }
  function loginVerifyOtp(email, code) {
    const r = verifyOtp(email, code);
    if (!r.ok) return r;
    const found = ctx.passes.filter(p => p.customerEmail === email && ["active", "expired", "suspended"].includes(p.status)).sort((a, b) => b.createdAt - a.createdAt)[0];
    if (!found) return { ok: false, error: "No pass found for this email." };
    setActivePass(found);
    setView("dashboard");
    return { ok: true };
  }

  function goBook() { setBookOutcome(null); setView("book"); }

  function confirmPassBooking(checkIn, checkOut, category, nights, optionInfo) {
    const pass = ctx.passes.find(p => p.id === activePass.id); // latest
    if (nights > pass.remainingDays) { alert(`Only ${pass.remainingDays} day(s) remaining.`); return; }
    const roomKey = categoryRoomKey(category), position = categoryPosition(category);
    const free = getAvailableBeds(roomKey, checkIn, checkOut, ctx.bookings, ctx.holds, ctx.blockedBeds, position);
    if (!free.length) { alert("Sorry, that bed is no longer available."); return; }
    const bedId = free[0];
    const requiresPayment = optionInfo.upgradeTotal > 0;
    const bookingId = makeBookingId(ctx.bookings, new Date(ctx.virtualNow()));

    const booking = {
      bookingId, source: "Pass", customerName: pass.customerName, customerPhone: pass.customerPhone, customerEmail: pass.customerEmail,
      checkIn, checkOut, guestCount: 1, bookingType: "individual", roomKey, bedIds: [bedId], nights,
      subtotal: optionInfo.upgradeTotal, discount: 0, tax: 0, total: optionInfo.upgradeTotal,
      amountPaid: requiresPayment ? 0 : optionInfo.upgradeTotal, balance: requiresPayment ? optionInfo.upgradeTotal : 0,
      paymentStatus: requiresPayment ? "Unpaid" : "Paid", paymentMethod: null,
      bookingStatus: requiresPayment ? "Pending" : "Confirmed",
      specialRequest: `Pass ${pass.passRef} (${PASS_CATEGORY_LABELS[pass.category]} base, ${PASS_CATEGORY_LABELS[category]} used)`,
      createdAt: Date.now(), updatedAt: Date.now(),
    };
    ctx.setBookings(prev => [...prev, booking]);

    const passBookingLink = { id: ctx.passBookingsLink.length + 1, passId: pass.id, bookingId, baseCategory: pass.category, usedCategory: category, nights, daysConsumed: requiresPayment ? 0 : nights, upgradeFee: optionInfo.upgradeTotal, upgradePaymentStatus: requiresPayment ? "pending" : "not_required" };
    ctx.setPassBookingsLink(prev => [...prev, passBookingLink]);

    if (!requiresPayment) {
      const newBalance = pass.remainingDays - nights;
      ctx.setPasses(prev => prev.map(p => p.id === pass.id ? { ...p, remainingDays: newBalance, usedDays: p.usedDays + nights } : p));
      ctx.setPassLedger(prev => [...prev, { id: prev.length + 1, passId: pass.id, eventType: "booking", dayChange: -nights, balanceAfter: newBalance, reason: `Booking ${bookingId}`, createdAt: Date.now() }]);
      setActivePass(prev => ({ ...prev, remainingDays: newBalance, usedDays: prev.usedDays + nights }));
      setBookOutcome({ ok: true, requiresPayment: false });
      setView("dashboard");
    } else {
      setPendingPassBooking({ ...passBookingLink, passRef: pass.passRef, passIdRef: pass.id });
      setView("bookPayment");
    }
  }

  function confirmUpgradePayment(success) {
    const pb = pendingPassBooking;
    if (!success) {
      ctx.setBookings(prev => prev.map(b => b.bookingId === pb.bookingId ? { ...b, bookingStatus: "Cancelled", paymentStatus: "Refunded" } : b));
      ctx.setPassBookingsLink(prev => prev.map(x => x.id === pb.id ? { ...x, upgradePaymentStatus: "failed" } : x));
      setView("dashboard");
      return;
    }
    const pass = ctx.passes.find(p => p.id === pb.passIdRef);
    const newBalance = pass.remainingDays - pb.nights;
    ctx.setPasses(prev => prev.map(p => p.id === pass.id ? { ...p, remainingDays: newBalance, usedDays: p.usedDays + pb.nights } : p));
    ctx.setPassLedger(prev => [...prev, { id: prev.length + 1, passId: pass.id, eventType: "booking", dayChange: -pb.nights, balanceAfter: newBalance, reason: `Booking ${pb.bookingId} (upgrade)`, createdAt: Date.now() }]);
    ctx.setPassBookingsLink(prev => prev.map(x => x.id === pb.id ? { ...x, upgradePaymentStatus: "paid", daysConsumed: pb.nights } : x));
    ctx.setBookings(prev => prev.map(b => b.bookingId === pb.bookingId ? { ...b, bookingStatus: "Confirmed", paymentStatus: "Paid", amountPaid: b.total, balance: 0 } : b));
    setActivePass(prev => ({ ...prev, remainingDays: newBalance, usedDays: prev.usedDays + pb.nights }));
    setView("dashboard");
  }

  return (
    <div className="py-2">
      {view === "landing" && <PassLandingCard settings={ctx.passSettings} onStart={() => setView("select")} onLogin={() => { setOtp({ email: null, purpose: null, code: null, expiresAt: null, attempts: 0, lastSentAt: null, devCode: null, verifiedToken: null, consumed: false }); setView("login"); }} />}
      {view === "select" && <PassSelectStep products={ctx.passProducts} settings={ctx.passSettings} onSelect={p => { setSelectedProduct(p); setView("details"); }} />}
      {view === "details" && <PassDetailsStep onSubmit={f => { setCustomer(f); sendOtp(f.email, "purchase"); setView("otp"); }} />}
      {view === "otp" && <PassOtpStep email={customer?.email} otpState={otp} onSend={() => sendOtp(customer.email, "purchase")} onVerify={code => { const r = verifyOtp(customer.email, code); if (r.ok) reservePass(selectedProduct, customer); return r; }} />}
      {view === "payment" && reservedPass && <PassPaymentStep amount={reservedPass.pricePaid} onOutcome={(ok) => confirmPassPayment(ok)} />}
      {view === "confirmation" && activePass && (
        <Card className="p-5 max-w-xl mx-auto text-center">
          <CheckCircle2 className="mx-auto mb-2 text-emerald-600" size={28} />
          <p className="font-semibold text-stone-900 mb-1">Pass activated!</p>
          <p className="text-sm text-stone-600 mb-3">{activePass.passRef} · {PASS_CATEGORY_LABELS[activePass.category]} · {fmtMoney(activePass.pricePaid)}</p>
          <p className="text-xs text-stone-500 mb-4">30 days, valid {fmtDate(activePass.activatedAt)} through {fmtDate(activePass.expiresAt)}</p>
          <Btn onClick={() => setView("dashboard")}>Go to My Pass</Btn>
        </Card>
      )}
      {view === "login" && <PassLoginStep otpState={otp} onSend={loginSendOtp} onVerify={loginVerifyOtp} />}
      {view === "dashboard" && activePass && <PassDashboard pass={ctx.passes.find(p => p.id === activePass.id) || activePass} ledger={myLedger} bookings={myBookings} onBook={goBook} onHistory={() => setView("history")} onTerms={() => setView("terms")} onLogout={() => { setActivePass(null); setView("landing"); }} />}
      {view === "book" && activePass && <PassBookStep pass={ctx.passes.find(p => p.id === activePass.id)} ctx={ctx} onConfirm={confirmPassBooking} onBack={() => setView("dashboard")} />}
      {view === "bookPayment" && pendingPassBooking && <PassPaymentStep amount={pendingPassBooking.upgradeFee} onOutcome={confirmUpgradePayment} />}
      {view === "history" && <PassHistoryCard bookings={myBookings} onBack={() => setView("dashboard")} />}
      {view === "terms" && <><PassTermsCard /><div className="max-w-xl mx-auto mt-2"><Btn tone="secondary" onClick={() => setView(activePass ? "dashboard" : "landing")}>Back</Btn></div></>}
    </div>
  );
}
/* ---------------- ADMIN: PASS MANAGEMENT ---------------- */
function PassAdminScreen({ ctx }) {
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState(null);
  const [reasonPrompt, setReasonPrompt] = useState(null); // {action, passId}

  const passes = ctx.passes.filter(p =>
    !search || p.passRef.toLowerCase().includes(search.toLowerCase()) || p.customerName.toLowerCase().includes(search.toLowerCase()) ||
    (p.customerEmail || "").toLowerCase().includes(search.toLowerCase()) || (p.customerPhone || "").includes(search)
  ).sort((a, b) => b.createdAt - a.createdAt);

  const byStatus = {};
  PASS_STATUSES.forEach(s => { byStatus[s] = ctx.passes.filter(p => p.status === s).length; });
  const revenue = ctx.passes.filter(p => ["active", "expired", "suspended", "cancelled"].includes(p.status)).reduce((s, p) => s + p.pricePaid, 0);
  const daysIssued = ctx.passes.filter(p => !["pending", "payment_pending", "refunded"].includes(p.status)).reduce((s, p) => s + p.totalDays, 0);
  const daysConsumed = ctx.passes.reduce((s, p) => s + p.usedDays, 0);
  const daysRemaining = ctx.passes.reduce((s, p) => s + p.remainingDays, 0);
  const upgradeRevenue = ctx.passBookingsLink.filter(pb => pb.upgradePaymentStatus === "paid").reduce((s, pb) => s + pb.upgradeFee, 0);

  function applyAction(action, passId, reason) {
    const map = { suspend: "suspended", reactivate: "active", cancel: "cancelled" };
    const pass = ctx.passes.find(p => p.id === passId);
    const before = { status: pass.status };
    ctx.setPasses(prev => prev.map(p => p.id === passId ? { ...p, status: map[action] } : p));
    ctx.setPassAuditLog(prev => [...prev, { id: prev.length + 1, passId, action, before, after: { status: map[action] }, reason, createdAt: Date.now() }]);
    setReasonPrompt(null);
  }
  function adjustBalance(passId, dayChange, reason) {
    const pass = ctx.passes.find(p => p.id === passId);
    const newBalance = Math.max(0, pass.remainingDays + dayChange);
    const before = { remainingDays: pass.remainingDays, usedDays: pass.usedDays };
    ctx.setPasses(prev => prev.map(p => p.id === passId ? { ...p, remainingDays: newBalance, usedDays: p.totalDays - newBalance } : p));
    ctx.setPassLedger(prev => [...prev, { id: prev.length + 1, passId, eventType: "adjustment", dayChange, balanceAfter: newBalance, reason, createdAt: Date.now() }]);
    ctx.setPassAuditLog(prev => [...prev, { id: prev.length + 1, passId, action: "adjust_balance", before, after: { remainingDays: newBalance, usedDays: pass.totalDays - newBalance }, reason, createdAt: Date.now() }]);
    setReasonPrompt(null);
  }

  return (
    <div>
      <SectionTitle icon={Sparkles} title="Pass Management" sub="30-Day Kush Stay Pass — Grand Opening & ongoing sales" />
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <StatCard label="Total passes" value={ctx.passes.length} />
        <StatCard label="Grand Opening sold" value={`${ctx.passSettings.grandOpeningSold}/${ctx.passSettings.grandOpeningLimit}`} tone={ctx.passSettings.grandOpeningSold >= ctx.passSettings.grandOpeningLimit ? "rose" : "teal"} />
        <StatCard label="Active" value={byStatus.active} tone="emerald" />
        <StatCard label="Expired" value={byStatus.expired} />
        <StatCard label="Suspended" value={byStatus.suspended} tone="amber" />
        <StatCard label="Cancelled" value={byStatus.cancelled} tone="rose" />
        <StatCard label="Revenue" value={fmtMoney(revenue)} tone="emerald" />
        <StatCard label="Upgrade revenue" value={fmtMoney(upgradeRevenue)} tone="teal" />
        <StatCard label="Days issued" value={daysIssued} />
        <StatCard label="Days consumed" value={daysConsumed} />
        <StatCard label="Days remaining" value={daysRemaining} />
        <StatCard label="Pass bookings" value={ctx.passBookingsLink.length} />
      </div>
      <div className="flex gap-2 mb-3"><TextInput placeholder="Search pass ID, name, email, phone…" value={search} onChange={e => setSearch(e.target.value)} className="max-w-sm" /></div>
      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-stone-50 text-stone-500 text-xs uppercase"><tr><th className="text-left px-3 py-2.5">Pass</th><th className="text-left px-3 py-2.5">Customer</th><th className="text-left px-3 py-2.5">Category</th><th className="text-left px-3 py-2.5">Days</th><th className="text-left px-3 py-2.5">Status</th><th className="text-left px-3 py-2.5">Expires</th></tr></thead>
          <tbody>{passes.map(p => (
            <tr key={p.id} className="border-t border-stone-100 hover:bg-stone-50 cursor-pointer" onClick={() => setSelected(p)}>
              <td className="px-3 py-2.5 font-mono text-xs">{p.passRef}</td>
              <td className="px-3 py-2.5">{p.customerName}<br /><span className="text-xs text-stone-400">{p.customerEmail}</span></td>
              <td className="px-3 py-2.5 text-xs">{PASS_CATEGORY_LABELS[p.category]}</td>
              <td className="px-3 py-2.5 text-xs">{p.remainingDays}/{p.totalDays}</td>
              <td className="px-3 py-2.5"><StatusBadge status={p.status} /></td>
              <td className="px-3 py-2.5 text-xs">{p.expiresAt ? fmtDateShort(p.expiresAt) : "—"}</td>
            </tr>
          ))}</tbody>
        </table>
        {passes.length === 0 && <EmptyState icon={Sparkles} text="No passes match this search" />}
      </Card>

      {selected && (
        <Modal open title={selected.passRef} onClose={() => setSelected(null)} wide>
          <div className="text-sm space-y-1 text-stone-700 mb-4">
            <p><span className="text-stone-400">Customer:</span> {selected.customerName} · {selected.customerEmail} · {selected.customerPhone}</p>
            <p><span className="text-stone-400">Category:</span> {PASS_CATEGORY_LABELS[selected.category]} · <span className="text-stone-400">Paid:</span> {fmtMoney(selected.pricePaid)} {selected.grandOpening && <Badge tone="teal">Grand Opening</Badge>}</p>
            <p><span className="text-stone-400">Days:</span> {selected.usedDays} used / {selected.remainingDays} remaining / {selected.totalDays} total</p>
            <p><span className="text-stone-400">Activated:</span> {selected.activatedAt ? fmtDate(selected.activatedAt) : "—"} · <span className="text-stone-400">Expires:</span> {selected.expiresAt ? fmtDate(selected.expiresAt) : "—"}</p>
            <p><span className="text-stone-400">Status:</span> <StatusBadge status={selected.status} /></p>
          </div>
          <div className="flex flex-wrap gap-1.5 mb-4">
            {selected.status === "active" && <Btn size="sm" tone="secondary" onClick={() => setReasonPrompt({ action: "suspend", passId: selected.id })}>Suspend</Btn>}
            {selected.status === "suspended" && <Btn size="sm" tone="secondary" onClick={() => setReasonPrompt({ action: "reactivate", passId: selected.id })}>Reactivate</Btn>}
            {!["cancelled", "refunded"].includes(selected.status) && <Btn size="sm" tone="danger" onClick={() => setReasonPrompt({ action: "cancel", passId: selected.id })}>Cancel</Btn>}
            <Btn size="sm" tone="outline" onClick={() => setReasonPrompt({ action: "adjust", passId: selected.id })}>Adjust balance</Btn>
          </div>
          <p className="text-xs font-medium text-stone-500 mb-1.5">Ledger</p>
          <div className="space-y-1 mb-4 max-h-40 overflow-y-auto">
            {ctx.passLedger.filter(l => l.passId === selected.id).sort((a, b) => b.id - a.id).map(l => (
              <div key={l.id} className="flex justify-between text-xs text-stone-600"><span>{fmtDateShort(toISO(new Date(l.createdAt)))} · {l.eventType} {l.reason ? `(${l.reason})` : ""}</span><span className={l.dayChange >= 0 ? "text-emerald-600" : "text-rose-600"}>{l.dayChange >= 0 ? "+" : ""}{l.dayChange} → {l.balanceAfter}</span></div>
            ))}
          </div>
          <p className="text-xs font-medium text-stone-500 mb-1.5">Bookings</p>
          <div className="space-y-1 mb-4">
            {ctx.passBookingsLink.filter(pb => pb.passId === selected.id).map(pb => (
              <div key={pb.id} className="text-xs text-stone-600">{pb.bookingId} · {PASS_CATEGORY_LABELS[pb.usedCategory]} · {pb.nights}n · {pb.daysConsumed}d used{pb.upgradeFee > 0 ? ` · upgrade ${fmtMoney(pb.upgradeFee)} (${pb.upgradePaymentStatus})` : ""}</div>
            ))}
          </div>
          <p className="text-xs font-medium text-stone-500 mb-1.5">Audit history</p>
          <div className="space-y-1">
            {ctx.passAuditLog.filter(a => a.passId === selected.id).sort((a, b) => b.id - a.id).map(a => (
              <div key={a.id} className="text-xs text-stone-500">{fmtDateTime(a.createdAt)} · {a.action} · "{a.reason}"</div>
            ))}
          </div>
        </Modal>
      )}

      {reasonPrompt && (
        <Modal open title={reasonPrompt.action === "adjust" ? "Adjust balance" : `Confirm ${reasonPrompt.action}`} onClose={() => setReasonPrompt(null)}>
          <ReasonForm action={reasonPrompt.action} onSubmit={(reason, dayChange) => reasonPrompt.action === "adjust" ? adjustBalance(reasonPrompt.passId, dayChange, reason) : applyAction(reasonPrompt.action, reasonPrompt.passId, reason)} />
        </Modal>
      )}
    </div>
  );
}
function ReasonForm({ action, onSubmit }) {
  const [reason, setReason] = useState("");
  const [dayChange, setDayChange] = useState(0);
  return (
    <div>
      {action === "adjust" && <Field label="Day change (+/-)"><TextInput type="number" value={dayChange} onChange={e => setDayChange(Number(e.target.value) || 0)} /></Field>}
      <Field label="Reason (required)"><TextInput value={reason} onChange={e => setReason(e.target.value)} /></Field>
      <Btn disabled={reason.trim().length < 5} onClick={() => onSubmit(reason, dayChange)}>Confirm</Btn>
    </div>
  );
}
/* ---------------- APP ---------------- */
const NAV = [
  { id:"dashboard", label:"Dashboard", icon:LayoutDashboard, admin:true },
  { id:"whatsapp", label:"WhatsApp AI", icon:MessageCircle, admin:false },
  { id:"pass", label:"30-Day Pass", icon:Sparkles, admin:false },
  { id:"bookings", label:"Bookings", icon:ClipboardList, admin:true },
  { id:"calendar", label:"Calendar", icon:CalendarDays, admin:true },
  { id:"beds", label:"Beds", icon:BedDouble, admin:true },
  { id:"customers", label:"Customers", icon:Users, admin:true },
  { id:"payments", label:"Payments", icon:Wallet, admin:true },
  { id:"passadmin", label:"Passes", icon:Sparkles, admin:true },
  { id:"ota", label:"OTA & iCal", icon:Globe, admin:true },
  { id:"analytics", label:"Analytics", icon:BarChart3, admin:true },
  { id:"catalog", label:"Catalog", icon:Package, admin:true },
  { id:"settings", label:"Settings", icon:SettingsIcon, admin:true },
];

export default function App() {
  const [loaded, setLoaded] = useState(false);
  const [isAdmin, setIsAdmin] = useState(false);
  const [tab, setTab] = useState("whatsapp");
  const [showDemoPanel, setShowDemoPanel] = useState(false);

  const seedRef = useRef(null);
  if (!seedRef.current) seedRef.current = buildInitialState();
  const seed = seedRef.current;

  const [bookings, setBookings] = useState(seed.bookings);
  const [leads, setLeads] = useState(seed.leads);
  const [otaConnections, setOtaConnections] = useState(seed.otaConnections);
  const [blockedBeds, setBlockedBeds] = useState(seed.blockedBeds);
  const [customerExtras, setCustomerExtras] = useState(seed.customerExtras);
  const [prices, setPrices] = useState(seed.prices);
  const [settings, setSettings] = useState(seed.settings);
  const [aiStats, setAiStats] = useState(seed.aiStats);
  const [passProducts, setPassProducts] = useState(seed.passProducts);
  const [passSettings, setPassSettings] = useState(seed.passSettings);
  const [passes, setPasses] = useState(seed.passes);
  const [passLedger, setPassLedger] = useState(seed.passLedger);
  const [passBookingsLink, setPassBookingsLink] = useState(seed.passBookingsLink);
  const [passAuditLog, setPassAuditLog] = useState(seed.passAuditLog);
  const [catalogActive, setCatalogActive] = useState(seed.catalogActive);
  const [holds, setHolds] = useState([]);
  const [syncLog, setSyncLog] = useState([]);
  const [systemOffsetMs, setSystemOffsetMs] = useState(0);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await window.storage.get("appState");
        if (!cancelled && res && res.value) {
          const data = JSON.parse(res.value);
          if (Array.isArray(data.bookings) && data.bookings.length) setBookings(data.bookings);
          if (Array.isArray(data.leads)) setLeads(data.leads);
          if (Array.isArray(data.otaConnections) && data.otaConnections.length) setOtaConnections(data.otaConnections);
          if (data.blockedBeds) setBlockedBeds(data.blockedBeds);
          if (data.customerExtras) setCustomerExtras(data.customerExtras);
          if (data.prices) setPrices(data.prices);
          if (data.settings) setSettings(data.settings);
          if (data.aiStats) setAiStats(data.aiStats);
          if (data.catalogActive) setCatalogActive(data.catalogActive);
          if (Array.isArray(data.passProducts) && data.passProducts.length) setPassProducts(data.passProducts);
          if (data.passSettings) setPassSettings(data.passSettings);
          if (Array.isArray(data.passes)) setPasses(data.passes);
          if (Array.isArray(data.passLedger)) setPassLedger(data.passLedger);
          if (Array.isArray(data.passBookingsLink)) setPassBookingsLink(data.passBookingsLink);
          if (Array.isArray(data.passAuditLog)) setPassAuditLog(data.passAuditLog);
        }
      } catch (e) { /* nothing saved yet — keep seed demo data */ }
      if (!cancelled) setLoaded(true);
    })();
    return () => { cancelled = true; };
  }, []);

  const saveTimer = useRef(null);
  useEffect(() => {
    if (!loaded) return;
    if (saveTimer.current) clearTimeout(saveTimer.current);
    saveTimer.current = setTimeout(() => {
      const bundle = { bookings, leads, otaConnections, blockedBeds, customerExtras, prices, settings, aiStats, catalogActive, passProducts, passSettings, passes, passLedger, passBookingsLink, passAuditLog };
      window.storage.set("appState", JSON.stringify(bundle)).catch(() => {});
    }, 800);
    return () => clearTimeout(saveTimer.current);
  }, [loaded, bookings, leads, otaConnections, blockedBeds, customerExtras, prices, settings, aiStats, catalogActive, passProducts, passSettings, passes, passLedger, passBookingsLink, passAuditLog]);

  const offsetRef = useRef(0);
  useEffect(() => { offsetRef.current = systemOffsetMs; }, [systemOffsetMs]);
  useEffect(() => {
    const id = setInterval(() => { setHolds(prev => prev.filter(h => h.expiresAt > Date.now() + offsetRef.current)); }, 5000);
    return () => clearInterval(id);
  }, []);

  const virtualNow = () => Date.now() + systemOffsetMs;
  const todayIso = toISO(new Date(virtualNow()));

  const ctx = {
    bookings, setBookings, holds, setHolds, leads, setLeads, otaConnections, setOtaConnections,
    blockedBeds, setBlockedBeds, customerExtras, setCustomerExtras, prices, setPrices, settings, setSettings,
    aiStats, setAiStats, catalogActive, setCatalogActive, syncLog, setSyncLog, systemOffsetMs, setSystemOffsetMs,
    virtualNow, todayIso,
    passProducts, setPassProducts, passSettings, setPassSettings, passes, setPasses,
    passLedger, setPassLedger, passBookingsLink, setPassBookingsLink, passAuditLog, setPassAuditLog,
  };

  if (!loaded) {
    return <div className="min-h-screen flex flex-col items-center justify-center text-stone-400 text-sm gap-2"><BedDouble className="animate-pulse" size={24}/>Loading Kush Stay…</div>;
  }

  return (
    <div className="min-h-screen bg-stone-50 font-sans">
      <div className="bg-stone-900 text-stone-300 text-[11px] text-center py-1.5 px-3 leading-snug">
        {settings.dataMode === "production"
          ? `Mixed mode — new WhatsApp AI bookings call the Laravel API at ${settings.apiBaseUrl || "(not set)"}; other admin actions still use this browser's local storage. See Settings → Data source.`
          : "Prototype Mode — data is saved to this browser session's persistent storage. Fully functional for demonstration; not yet a multi-user production server. Production needs a PHP/Laravel + MySQL backend (see Settings → Production roadmap)."}
      </div>
      <div className="bg-white border-b border-stone-200 px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-xl bg-teal-700 text-white flex items-center justify-center font-bold text-sm">KS</div>
          <div><p className="font-semibold text-stone-900 leading-tight">{settings.propertyName}</p><p className="text-xs text-stone-400">AI Receptionist & Booking Manager</p></div>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-stone-400 hidden sm:inline">{new Date(virtualNow()).toLocaleString("en-IN",{day:"2-digit",month:"short",hour:"2-digit",minute:"2-digit"})}</span>
          <Btn size="sm" tone="secondary" icon={FlaskConical} onClick={()=>setShowDemoPanel(true)}>Demo &amp; Tests</Btn>
          {isAdmin ? <Btn size="sm" tone="outline" icon={LogOut} onClick={()=>{setIsAdmin(false); setTab("whatsapp");}}>Log out</Btn> : <Btn size="sm" tone="outline" icon={Lock} onClick={()=>setTab("dashboard")}>Admin sign-in</Btn>}
        </div>
      </div>
      <div className="bg-white border-b border-stone-200 px-2 overflow-x-auto">
        <div className="flex gap-1 py-1.5 min-w-max">
          {NAV.map(n => (
            <button key={n.id} onClick={()=>setTab(n.id)} className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap ${tab===n.id?"bg-teal-700 text-white":"text-stone-600 hover:bg-stone-100"}`}>
              <n.icon size={13}/>{n.label}{n.admin && !isAdmin && <Lock size={10} className="opacity-60"/>}
            </button>
          ))}
        </div>
      </div>
      <main className="max-w-7xl mx-auto p-4">
        <div className={tab==="whatsapp"?"":"hidden"}><WhatsAppScreen ctx={ctx}/></div>
        <div className={tab==="pass"?"":"hidden"}><PassScreen ctx={ctx}/></div>
        {isAdmin ? (
          <>
            <div className={tab==="dashboard"?"":"hidden"}><DashboardScreen ctx={ctx}/></div>
            <div className={tab==="bookings"?"":"hidden"}><BookingsScreen ctx={ctx}/></div>
            <div className={tab==="calendar"?"":"hidden"}><CalendarScreen ctx={ctx}/></div>
            <div className={tab==="beds"?"":"hidden"}><BedsScreen ctx={ctx}/></div>
            <div className={tab==="customers"?"":"hidden"}><CustomersScreen ctx={ctx}/></div>
            <div className={tab==="payments"?"":"hidden"}><PaymentsScreen ctx={ctx}/></div>
            <div className={tab==="passadmin"?"":"hidden"}><PassAdminScreen ctx={ctx}/></div>
            <div className={tab==="ota"?"":"hidden"}><OtaIcalScreen ctx={ctx}/></div>
            <div className={tab==="analytics"?"":"hidden"}><AnalyticsScreen ctx={ctx}/></div>
            <div className={tab==="catalog"?"":"hidden"}><CatalogScreen ctx={ctx}/></div>
            <div className={tab==="settings"?"":"hidden"}><SettingsScreen ctx={ctx}/></div>
          </>
        ) : (tab !== "whatsapp" && tab !== "pass" && <LoginGate onLogin={()=>setIsAdmin(true)} />)}
      </main>
      <DemoPanel ctx={ctx} open={showDemoPanel} onClose={()=>setShowDemoPanel(false)} />
    </div>
  );
}
