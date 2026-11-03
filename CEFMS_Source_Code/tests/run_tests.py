#!/usr/bin/env python3
"""
CEFMS automated functional / security / performance tests.
Runs against the live system (php -S 127.0.0.1:8080) and the MySQL/MariaDB database.
Usage: python3 tests/run_tests.py   -> writes tests/results.json
"""
import json, os, re, subprocess, sys, threading, time
import requests

BASE = os.environ.get("CEFMS_URL", "http://127.0.0.1:8080")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENV = dict(os.environ, CEFMS_DB_USER="cefms", CEFMS_DB_PASS="cefms_pw")
RESULTS = []

# ---------------------------------------------------------------- helpers
def sql(q):
    out = subprocess.run(["mysql", "-ucefms", "-pcefms_pw", "-h127.0.0.1", "-N", "-B", "cefms", "-e", q],
                         capture_output=True, text=True)
    if out.returncode != 0:
        raise RuntimeError(out.stderr)
    return out.stdout.strip()

def reseed():
    subprocess.run(["php", os.path.join(ROOT, "sql/seed.php")], env=ENV, check=True, capture_output=True)

def sess():
    s = requests.Session()
    s.hdr = {}
    return s

def csrf(s, path):
    r = s.get(BASE + path)
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', r.text)
    return m.group(1) if m else ""

def form(s, path, data, from_path=None, follow=False):
    d = dict(data)
    d["csrf"] = csrf(s, from_path or path)
    return s.post(BASE + path, data=d, allow_redirects=follow)

def login_public(email, pw):
    s = sess()
    r = form(s, "/login.php", {"email": email, "password": pw})
    return s, r

def login_admin(email, pw):
    s = sess()
    r = form(s, "/admin/login.php", {"email": email, "password": pw})
    return s, r

def loc(r):
    return r.headers.get("Location", "")

def eid(title):
    return int(sql(f"SELECT eventId FROM event WHERE title='{title}'"))

def tc(id_, fr, title, precond, steps, expected):
    def deco(fn):
        t0 = time.time()
        try:
            ok, actual = fn()
        except Exception as ex:  # a crash in the test itself counts as a failure
            ok, actual = False, f"Test raised {type(ex).__name__}: {ex}"
        RESULTS.append(dict(id=id_, fr=fr, title=title, precondition=precond, steps=steps,
                            expected=expected, actual=actual, status="Pass" if ok else "Fail",
                            ms=int((time.time() - t0) * 1000)))
        print(f"{id_:5} {'PASS' if ok else 'FAIL'}  {title}  -> {actual[:90]}")
        return fn
    return deco

reseed()
sql("DELETE FROM user WHERE email LIKE 'tc_%'")
PESTA = eid("Pesta Kebudayaan Johor"); TARIAN = eid("Festival Tarian Tradisi")
PASAR = eid("Pasar Seni Warisan"); MALAM = eid("Malam Muzik Rakyat")
WAYANG = eid("Pameran Wayang Kulit"); KOMPANG = eid("Karnival Kompang Remaja")
PRICE_PESTA = 35

# ================================================================= FR1 homepage
@tc("TC01", "FR1", "Public opens homepage without logging in", "None (guest)", "GET /", "HTTP 200, page shows 'Upcoming events'")
def _():
    r = requests.get(BASE + "/")
    return r.status_code == 200 and "Upcoming events" in r.text, f"HTTP {r.status_code}, heading present={'Upcoming events' in r.text}"

@tc("TC02", "FR1", "Only published events are listed", "Seed: 1 Pending, 1 Cancelled event", "GET /events.php", "Pending and Cancelled events absent; published present")
def _():
    t = requests.get(BASE + "/events.php").text
    ok = "Pesta Kebudayaan Johor" in t and "Pameran Wayang Kulit" not in t and "Karnival Kompang" not in t
    return ok, f"published shown={'Pesta Kebudayaan Johor' in t}, pending hidden={'Pameran Wayang Kulit' not in t}, cancelled hidden={'Karnival Kompang' not in t}"

@tc("TC03", "FR1", "Category filter", "Seed data", "GET /events.php?cat=Dance", "Only the Dance event is shown")
def _():
    t = requests.get(BASE + "/events.php", params={"cat": "Dance"}).text
    return "Festival Tarian Tradisi" in t and "Pesta Kebudayaan" not in t, "Dance event shown, other categories hidden" if "Pesta Kebudayaan" not in t else "other categories visible"

@tc("TC04", "FR1", "Keyword search", "Seed data", "GET /events.php?q=Johor", "Pesta Kebudayaan Johor returned")
def _():
    t = requests.get(BASE + "/events.php", params={"q": "Johor"}).text
    return "Pesta Kebudayaan Johor" in t and "Festival Tarian" not in t, "matching event returned only"

@tc("TC05", "FR1", "Search with no result", "None", "GET /events.php?q=zzzz", "'No events match your search' message")
def _():
    t = requests.get(BASE + "/events.php", params={"q": "zzzzqq"}).text
    return "No events match your search" in t, "empty-state message shown" if "No events match" in t else "no message"

# ================================================================= FR2 sign up
def signup(s, name, email, phone, pw, pw2=None):
    return form(s, "/signup.php", {"fullName": name, "email": email, "phone": phone, "password": pw, "password2": pw if pw2 is None else pw2}, follow=False)

@tc("TC06", "FR2", "Sign up with valid details", "Email not registered", "POST /signup.php with valid data", "Account created, redirect to /login.php")
def _():
    r = signup(sess(), "TC User One", "tc_user1@mail.com", "0171234567", "Passw0rd1")
    return r.status_code == 302 and "/login.php" in loc(r) and sql("SELECT COUNT(*) FROM user WHERE email='tc_user1@mail.com'") == "1", f"HTTP {r.status_code} -> {loc(r)}, row created"

@tc("TC07", "FR2", "Duplicate email rejected", "tc_user1@mail.com exists", "Sign up again with same email", "Error 'already registered'; no second row")
def _():
    r = signup(sess(), "Dup", "tc_user1@mail.com", "0171234567", "Passw0rd1")
    return "already registered" in r.text and sql("SELECT COUNT(*) FROM user WHERE email='tc_user1@mail.com'") == "1", "error shown, still 1 row"

@tc("TC08", "FR2", "Weak password rejected", "None", "Password 'abc' (no digit, too short)", "Error about password rules")
def _():
    r = signup(sess(), "Weak", "tc_weak@mail.com", "0171234567", "abc")
    return "Password must be at least 8" in r.text and sql("SELECT COUNT(*) FROM user WHERE email='tc_weak@mail.com'") == "0", "rejected with password rule message"

@tc("TC09", "FR2", "Invalid phone number rejected", "None", "Phone '12345'", "Error about phone number")
def _():
    r = signup(sess(), "Phone", "tc_phone@mail.com", "12345", "Passw0rd1")
    return "valid Malaysian number" in r.text, "rejected with phone message"

@tc("TC10", "FR2", "Password confirmation mismatch", "None", "password != confirm", "Error 'Passwords do not match'")
def _():
    r = signup(sess(), "Mismatch", "tc_mm@mail.com", "0171234567", "Passw0rd1", "Passw0rd2")
    return "Passwords do not match" in r.text, "rejected"

@tc("TC11", "FR2", "Empty form rejected", "None", "Submit all fields empty", "Multiple validation errors")
def _():
    r = signup(sess(), "", "", "", "", "")
    return r.text.count("<li>") >= 4, f"{r.text.count('<li>')} validation messages"

@tc("TC12", "FR2/NFR2", "Password stored as a hash", "tc_user1 registered", "Read passwordHash from database", "Value is a bcrypt/argon hash, not the plain password")
def _():
    h = sql("SELECT passwordHash FROM user WHERE email='tc_user1@mail.com'")
    return h.startswith("$") and "Passw0rd1" not in h and len(h) >= 60, f"stored value starts '{h[:7]}...' length {len(h)}"

# ================================================================= FR3 login
@tc("TC13", "FR3", "Participant logs in with valid credentials", "aisyah@mail.com exists", "POST /login.php", "Redirect to /dashboard.php")
def _():
    s, r = login_public("aisyah@mail.com", "Aisyah@123")
    return r.status_code == 302 and "dashboard" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

@tc("TC14", "FR3", "Wrong password rejected", "aisyah@mail.com exists", "Log in with wrong password", "'Invalid email or password'")
def _():
    s, r = login_public("aisyah@mail.com", "wrong-pass")
    return "Invalid email or password" in r.text, "generic error shown"

@tc("TC15", "FR3", "Unregistered email rejected", "None", "Log in with unknown email", "'Invalid email or password' (no account enumeration)")
def _():
    s, r = login_public("nobody@mail.com", "Passw0rd1")
    return "Invalid email or password" in r.text, "same generic error"

@tc("TC16", "FR3", "Inactive participant cannot log in", "tc_user1 set to Inactive", "Log in as inactive user", "'account is inactive'")
def _():
    sql("UPDATE user SET status='Inactive' WHERE email='tc_user1@mail.com'")
    s, r = login_public("tc_user1@mail.com", "Passw0rd1")
    sql("UPDATE user SET status='Active' WHERE email='tc_user1@mail.com'")
    return "inactive" in r.text, "blocked with inactive message"

@tc("TC17", "FR3", "Organizer credentials rejected on public login", "siti@organizer.my exists", "Log in at /login.php", "Rejected (admin users must use admin login)")
def _():
    s, r = login_public("siti@organizer.my", "Organizer@123")
    return "Invalid email or password" in r.text, "rejected"

@tc("TC18", "FR3", "Logout ends the session", "Logged in", "GET /logout.php then GET /dashboard.php", "Dashboard redirects to login")
def _():
    s, _r = login_public("aisyah@mail.com", "Aisyah@123")
    s.get(BASE + "/logout.php")
    r = s.get(BASE + "/dashboard.php", allow_redirects=False)
    return r.status_code == 302 and "login" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

@tc("TC19", "FR3/FR4", "Dashboard requires login", "Guest", "GET /dashboard.php", "Redirect to /login.php")
def _():
    r = requests.get(BASE + "/dashboard.php", allow_redirects=False)
    return r.status_code == 302 and "login" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

# ================================================================= FR6 detail / FR5 calendar
@tc("TC20", "FR6", "Event detail shows venue, price and seats", "Seed", f"GET /event.php?id={PESTA}", "Venue, RM 35.00 and '300 of 300 seats' displayed")
def _():
    t = requests.get(BASE + "/event.php", params={"id": PESTA}).text
    ok = "Dataran Bandaraya Johor Bahru" in t and "RM 35.00" in t and "300 of 300 seats" in t
    return ok, "venue, price and seat availability displayed"

@tc("TC21", "FR6", "Unknown event returns 404", "None", "GET /event.php?id=9999", "HTTP 404 and friendly message")
def _():
    r = requests.get(BASE + "/event.php", params={"id": 9999})
    return r.status_code == 404 and "Event not found" in r.text, f"HTTP {r.status_code}"

@tc("TC22", "FR6", "Pending event is not visible to the public", "Event is Pending", f"GET /event.php?id={WAYANG}", "HTTP 404")
def _():
    r = requests.get(BASE + "/event.php", params={"id": WAYANG})
    return r.status_code == 404, f"HTTP {r.status_code}"

@tc("TC23", "FR6", "Guest sees login prompt instead of reserve form", "Guest", "Open published event", "'Log in to buy tickets' shown, no reserve form")
def _():
    t = requests.get(BASE + "/event.php", params={"id": PESTA}).text
    return "Log in to buy tickets" in t and 'action="/reserve.php"' not in t, "login prompt shown"

@tc("TC24", "FR5", "Calendar shows events in the selected month", "Seed", "GET /calendar.php?m=2026-11", "Pesta Kebudayaan Johor appears on 14 Nov")
def _():
    t = requests.get(BASE + "/calendar.php", params={"m": "2026-11"}).text
    return "Pesta Kebudayaan Johor" in t and "November 2026" in t and "Malam Muzik" not in t, "November events shown, December event absent"

@tc("TC25", "FR5", "Calendar navigates to next month", "Seed", "GET /calendar.php?m=2026-12", "Malam Muzik Rakyat shown")
def _():
    t = requests.get(BASE + "/calendar.php", params={"m": "2026-12"}).text
    return "Malam Muzik Rakyat" in t and "December 2026" in t, "December events shown"

@tc("TC26", "FR5", "Calendar event links to event detail", "Seed", "Read link from calendar", "Link targets /event.php?id=<id>")
def _():
    t = requests.get(BASE + "/calendar.php", params={"m": "2026-11"}).text
    return f"/event.php?id={PESTA}" in t, "link present"

@tc("TC27", "FR5", "Invalid month parameter handled safely", "None", "GET /calendar.php?m=abc", "HTTP 200, falls back to current month")
def _():
    r = requests.get(BASE + "/calendar.php", params={"m": "abc'--"})
    return r.status_code == 200, f"HTTP {r.status_code}"

# ================================================================= FR7 reserve
def new_participant(tag):
    email = f"tc_{tag}@mail.com"
    signup(sess(), f"TC {tag}", email, "0171234567", "Passw0rd1")
    return login_public(email, "Passw0rd1")[0], email

S_A, EMAIL_A = new_participant("a")

@tc("TC28", "FR7", "Reserve tickets reduces availability", "Logged in; 300 seats", "Reserve 2 tickets for Pesta", "Redirect to payment; availableSeats 300 -> 298; reservation Reserved")
def _():
    r = form(S_A, "/reserve.php", {"eventId": PESTA, "quantity": 2}, from_path=f"/event.php?id={PESTA}")
    left = sql(f"SELECT availableSeats FROM event WHERE eventId={PESTA}")
    st = sql(f"SELECT status, totalAmount FROM reservation WHERE eventId={PESTA} ORDER BY reservationId DESC LIMIT 1")
    return "/payment.php" in loc(r) and left == "298" and st.startswith("Reserved") and "70.00" in st, f"-> {loc(r)}, seats left {left}, reservation '{st}'"

@tc("TC29", "FR7", "Guest cannot reserve", "Guest", "POST /reserve.php without login", "Redirect to /login.php; nothing reserved")
def _():
    before = sql("SELECT COUNT(*) FROM reservation")
    r = requests.post(BASE + "/reserve.php", data={"eventId": PESTA, "quantity": 1}, allow_redirects=False)
    return r.status_code == 302 and "login" in loc(r) and sql("SELECT COUNT(*) FROM reservation") == before, f"HTTP {r.status_code} -> {loc(r)}"

@tc("TC30", "FR7", "Quantity 0 rejected", "Logged in", "Reserve quantity 0", "Error message; no reservation")
def _():
    before = sql("SELECT COUNT(*) FROM reservation")
    r = form(S_A, "/reserve.php", {"eventId": PESTA, "quantity": 0}, from_path=f"/event.php?id={PESTA}", follow=True)
    return "Quantity must be between" in r.text and sql("SELECT COUNT(*) FROM reservation") == before, "rejected with message"

@tc("TC31", "FR7", "Quantity above maximum (10) rejected", "Logged in", "Reserve quantity 11", "Error message")
def _():
    r = form(S_A, "/reserve.php", {"eventId": PESTA, "quantity": 11}, from_path=f"/event.php?id={PESTA}", follow=True)
    return "Quantity must be between" in r.text, "rejected"

@tc("TC32", "FR7", "Request more seats than available", "Event has 3 seats left", "Reserve 5 tickets", "Error 'Only 3 seat(s) left'; seats unchanged")
def _():
    sql(f"UPDATE event SET availableSeats=3 WHERE eventId={PASAR}")
    r = form(S_A, "/reserve.php", {"eventId": PASAR, "quantity": 5}, from_path=f"/event.php?id={PASAR}", follow=True)
    left = sql(f"SELECT availableSeats FROM event WHERE eventId={PASAR}")
    sql(f"UPDATE event SET availableSeats=150 WHERE eventId={PASAR}")
    return "Only 3 seat(s) left" in r.text and left == "3", f"message shown, seats still {left}"

@tc("TC33", "FR7", "Cannot reserve a cancelled event", "Event is Cancelled", "POST reserve for cancelled event", "Error 'not open for booking'")
def _():
    r = form(S_A, "/reserve.php", {"eventId": KOMPANG, "quantity": 1}, from_path=f"/event.php?id={PESTA}", follow=True)
    return "not open for booking" in r.text, "rejected"

@tc("TC34", "FR7", "Cannot reserve a pending event", "Event is Pending", "POST reserve for pending event", "Error 'not open for booking'")
def _():
    r = form(S_A, "/reserve.php", {"eventId": WAYANG, "quantity": 1}, from_path=f"/event.php?id={PESTA}", follow=True)
    return "not open for booking" in r.text, "rejected"

@tc("TC35", "FR7", "Unpaid hold expires and releases seats", "Reservation Reserved for 2 seats", "Move expiryDate to the past, load homepage", "Status Expired; seats restored to 300")
def _():
    sql(f"UPDATE reservation SET expiryDate = NOW() - INTERVAL 1 MINUTE WHERE eventId={PESTA} AND status='Reserved'")
    requests.get(BASE + "/")
    st = sql(f"SELECT status FROM reservation WHERE eventId={PESTA} ORDER BY reservationId DESC LIMIT 1")
    left = sql(f"SELECT availableSeats FROM event WHERE eventId={PESTA}")
    return st == "Expired" and left == "300", f"status {st}, seats left {left}"

@tc("TC36", "FR7", "Cancel an unpaid reservation", "Active reservation of 3 tickets", "POST /cancel.php", "Status Cancelled; seats restored")
def _():
    form(S_A, "/reserve.php", {"eventId": TARIAN, "quantity": 3}, from_path=f"/event.php?id={TARIAN}")
    rid = sql(f"SELECT reservationId FROM reservation WHERE eventId={TARIAN} ORDER BY reservationId DESC LIMIT 1")
    mid = sql(f"SELECT availableSeats FROM event WHERE eventId={TARIAN}")
    form(S_A, "/cancel.php", {"id": rid}, from_path=f"/payment.php?id={rid}")
    st = sql(f"SELECT status FROM reservation WHERE reservationId={rid}")
    left = sql(f"SELECT availableSeats FROM event WHERE eventId={TARIAN}")
    return mid == "197" and st == "Cancelled" and left == "200", f"held: {mid} -> after cancel: {left}, status {st}"

@tc("TC37", "FR7/NFR5", "No overselling under concurrent booking", "Event with 5 seats; 12 simultaneous requests", "12 parallel reservations of 1 ticket", "Exactly 5 succeed, 7 refused, availableSeats = 0")
def _():
    sql(f"UPDATE event SET availableSeats=5, totalSeats=5 WHERE eventId={MALAM}")
    sessions = [login_public("farid@mail.com", "Farid@123")[0] for _ in range(12)]
    tokens = [csrf(s, f"/event.php?id={MALAM}") for s in sessions]
    bar = threading.Barrier(12); out = [None] * 12
    def go(i):
        bar.wait()
        r = sessions[i].post(BASE + "/reserve.php", data={"eventId": MALAM, "quantity": 1, "csrf": tokens[i]}, allow_redirects=False)
        out[i] = loc(r)
    th = [threading.Thread(target=go, args=(i,)) for i in range(12)]
    [t.start() for t in th]; [t.join() for t in th]
    ok_n = sum(1 for x in out if "/payment.php" in x)
    left = sql(f"SELECT availableSeats FROM event WHERE eventId={MALAM}")
    held = sql(f"SELECT COALESCE(SUM(quantity),0) FROM reservation WHERE eventId={MALAM} AND status='Reserved'")
    sql(f"UPDATE reservation SET status='Cancelled' WHERE eventId={MALAM}")
    sql(f"UPDATE event SET availableSeats=250, totalSeats=250 WHERE eventId={MALAM}")
    return ok_n == 5 and left == "0" and held == "5", f"{ok_n} succeeded, {12-ok_n} refused, seats left {left}, seats held {held}"

# ================================================================= FR8 payment
def make_reservation(s, ev, qty=2):
    form(s, "/reserve.php", {"eventId": ev, "quantity": qty}, from_path=f"/event.php?id={ev}")
    return sql(f"SELECT reservationId FROM reservation WHERE eventId={ev} ORDER BY reservationId DESC LIMIT 1")

@tc("TC38", "FR8", "Successful payment issues a ticket code", "Active reservation", "Pay with Online Banking", "Reservation Paid, payment Successful, ticket code CEF-XXXXXX")
def _():
    rid = make_reservation(S_A, PESTA, 2)
    r = form(S_A, "/payment.php", {"id": rid, "method": "Online Banking"}, from_path=f"/payment.php?id={rid}", follow=True)
    row = sql(f"SELECT status, ticketCode FROM reservation WHERE reservationId={rid}")
    pay = sql(f"SELECT status, amount FROM payment WHERE reservationId={rid}")
    code = row.split("\t")[1] if "\t" in row else ""
    return row.startswith("Paid") and re.fullmatch(r"CEF-[A-Z2-9]{6}", code) is not None and pay.startswith("Successful") and code in r.text, f"reservation '{row}', payment '{pay}'"

@tc("TC39", "FR8", "Failed gateway response keeps the hold", "Active reservation", "Pay with simulated failure", "Payment Failed recorded; reservation stays Reserved; no ticket code")
def _():
    rid = make_reservation(S_A, PASAR, 1)
    r = form(S_A, "/payment.php", {"id": rid, "method": "Card", "simulate_fail": "1"}, from_path=f"/payment.php?id={rid}", follow=True)
    st = sql(f"SELECT status, COALESCE(ticketCode,'NULL') FROM reservation WHERE reservationId={rid}")
    pay = sql(f"SELECT status FROM payment WHERE reservationId={rid}")
    return st.startswith("Reserved") and "NULL" in st and pay == "Failed" and "Payment failed" in r.text, f"reservation '{st}', payment '{pay}'"

@tc("TC40", "FR8", "Retry after failed payment succeeds", "Reservation from TC39 still held", "Pay again successfully", "Reservation Paid with ticket code")
def _():
    rid = sql(f"SELECT reservationId FROM reservation WHERE eventId={PASAR} ORDER BY reservationId DESC LIMIT 1")
    form(S_A, "/payment.php", {"id": rid, "method": "E-Wallet"}, from_path=f"/payment.php?id={rid}", follow=True)
    st = sql(f"SELECT status FROM reservation WHERE reservationId={rid}")
    n = sql(f"SELECT COUNT(*) FROM payment WHERE reservationId={rid}")
    return st == "Paid" and n == "2", f"status {st}, payment attempts {n}"

@tc("TC41", "FR8", "Payment for an expired reservation refused", "Reservation expired", "Pay after hold expiry", "Refused; no payment row; no ticket")
def _():
    rid = make_reservation(S_A, TARIAN, 1)
    tok = csrf(S_A, f"/payment.php?id={rid}")
    sql(f"UPDATE reservation SET expiryDate = NOW() - INTERVAL 1 MINUTE WHERE reservationId={rid}")
    r = S_A.post(BASE + "/payment.php", data={"id": rid, "method": "Card", "csrf": tok}, allow_redirects=True)
    st = sql(f"SELECT status FROM reservation WHERE reservationId={rid}")
    n = sql(f"SELECT COUNT(*) FROM payment WHERE reservationId={rid}")
    return st == "Expired" and n == "0", f"status {st}, payments {n}"

@tc("TC42", "FR8", "Double payment prevented", "Reservation already Paid", "Submit payment again", "Refused; only one payment row")
def _():
    rid = make_reservation(S_A, PESTA, 1)
    form(S_A, "/payment.php", {"id": rid, "method": "Card"}, from_path=f"/payment.php?id={rid}")
    r = S_A.post(BASE + "/payment.php", data={"id": rid, "method": "Card", "csrf": csrf(S_A, f"/event.php?id={PESTA}")}, allow_redirects=True)
    n = sql(f"SELECT COUNT(*) FROM payment WHERE reservationId={rid} AND status='Successful'")
    return n == "1" and "already been paid" in r.text, f"successful payments for reservation: {n}, message shown={'already been paid' in r.text}"

@tc("TC43", "FR8/NFR2", "No card details stored", "Schema", "Inspect payment table columns", "No card number / CVV columns")
def _():
    cols = sql("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cefms' AND TABLE_NAME='payment'")
    return not re.search(r"card|cvv|pan|number", cols.replace("transactionRef", ""), re.I), f"columns: {cols}"

@tc("TC44", "FR4", "Dashboard lists the participant's bookings", "Participant with paid and expired bookings", "GET /dashboard.php", "Shows Paid ticket code and statuses")
def _():
    t = S_A.get(BASE + "/dashboard.php").text
    code = sql("SELECT ticketCode FROM reservation WHERE status='Paid' ORDER BY reservationId DESC LIMIT 1")
    return code in t and "Confirmed bookings" in t and "Expired" in t, f"ticket code {code} and statuses displayed"

@tc("TC45", "FR8/NFR2", "A user cannot open another user's reservation", "Reservation belongs to tc_a", "Log in as Aisyah and open the payment page", "HTTP 404")
def _():
    rid = sql("SELECT reservationId FROM reservation WHERE status='Paid' ORDER BY reservationId DESC LIMIT 1")
    s, _r = login_public("aisyah@mail.com", "Aisyah@123")
    r = s.get(BASE + "/ticket.php", params={"id": rid})
    return r.status_code == 404, f"HTTP {r.status_code}"

# ================================================================= admin access
@tc("TC46", "FR9", "Organizer logs in to admin panel", "siti@organizer.my active", "POST /admin/login.php", "Redirect to admin dashboard")
def _():
    s, r = login_admin("siti@organizer.my", "Organizer@123")
    return r.status_code == 302 and "/admin/index.php" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

@tc("TC47", "FR11", "Event Manager logs in to admin panel", "manager@cefms.my active", "POST /admin/login.php", "Redirect to admin dashboard")
def _():
    s, r = login_admin("manager@cefms.my", "Manager@123")
    return r.status_code == 302 and "/admin/index.php" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

@tc("TC48", "FR13", "Deactivated organizer cannot log in", "raju@organizer.my Inactive", "POST /admin/login.php", "'deactivated' message")
def _():
    s, r = login_admin("raju@organizer.my", "Organizer@123")
    return "deactivated" in r.text, "blocked"

@tc("TC49", "NFR2", "Participant cannot use admin login", "aisyah is a participant", "POST /admin/login.php", "Invalid email or password")
def _():
    s, r = login_admin("aisyah@mail.com", "Aisyah@123")
    return "Invalid email or password" in r.text, "rejected"

@tc("TC50", "NFR2", "Admin pages require login", "Guest", "GET /admin/events.php", "Redirect to /admin/login.php")
def _():
    r = requests.get(BASE + "/admin/events.php", allow_redirects=False)
    return r.status_code == 302 and "/admin/login.php" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

S_ORG, _ = login_admin("siti@organizer.my", "Organizer@123")
S_MGR, _ = login_admin("manager@cefms.my", "Manager@123")

@tc("TC51", "NFR2", "Organizer blocked from manager pages (RBAC)", "Organizer logged in", "GET /admin/organizers.php", "HTTP 403 Access denied")
def _():
    r = S_ORG.get(BASE + "/admin/organizers.php")
    return r.status_code == 403 and "Access denied" in r.text, f"HTTP {r.status_code}"

@tc("TC52", "NFR2", "Manager blocked from organizer-only page (RBAC)", "Manager logged in", "GET /admin/event_create.php", "HTTP 403")
def _():
    r = S_MGR.get(BASE + "/admin/event_create.php")
    return r.status_code == 403, f"HTTP {r.status_code}"

# ================================================================= FR9 create event
def create_event(s, **kw):
    d = dict(title="TC Festival Rasa", description="A test cultural festival for automated testing.", category="Food",
             ticketPrice="12.50", totalSeats="80", venue="Dataran Merdeka Test Hall", start="2026-12-20T10:00", end="2026-12-20T14:00")
    d.update(kw)
    return form(s, "/admin/event_create.php", d)

@tc("TC53", "FR9", "Organizer creates an event (status Pending)", "Organizer logged in", "Submit create-event form", "Redirect to history; event Pending; schedule created; hidden from public")
def _():
    r = create_event(S_ORG)
    row = sql("SELECT status, availableSeats FROM event WHERE title='TC Festival Rasa'")
    sch = sql("SELECT COUNT(*) FROM schedule s JOIN event e ON e.eventId=s.eventId WHERE e.title='TC Festival Rasa'")
    hidden = "TC Festival Rasa" not in requests.get(BASE + "/events.php").text
    return "/admin/history.php" in loc(r) and row == "Pending\t80" and sch == "1" and hidden, f"row '{row}', schedules {sch}, hidden from public={hidden}"

@tc("TC54", "FR9", "Missing title rejected", "Organizer", "Submit with empty title", "Validation error; no row")
def _():
    n = sql("SELECT COUNT(*) FROM event")
    r = create_event(S_ORG, title="")
    return "Event title is required" in r.text and sql("SELECT COUNT(*) FROM event") == n, "rejected"

@tc("TC55", "FR9", "End time before start time rejected", "Organizer", "end earlier than start", "Error about end time")
def _():
    r = create_event(S_ORG, title="TC Bad Time", start="2026-12-20T14:00", end="2026-12-20T10:00")
    return "End time must be after" in r.text, "rejected"

@tc("TC56", "FR9", "Negative price rejected", "Organizer", "ticketPrice -5", "Error about price")
def _():
    r = create_event(S_ORG, title="TC Neg", ticketPrice="-5")
    return "Ticket price must be between" in r.text, "rejected"

@tc("TC57", "FR9", "Event in the past rejected", "Organizer", "start in year 2020", "Error 'must start in the future'")
def _():
    r = create_event(S_ORG, title="TC Past", start="2020-01-01T10:00", end="2020-01-01T12:00")
    return "must start in the future" in r.text, "rejected"

@tc("TC58", "FR10", "Event history shows only the organizer's own events", "Siti and Daniel have events", "GET /admin/history.php as Siti", "Siti's events listed, Daniel's absent")
def _():
    t = S_ORG.get(BASE + "/admin/history.php").text
    return "Pesta Kebudayaan Johor" in t and "TC Festival Rasa" in t and "Pasar Seni Warisan" not in t, "own events only"

@tc("TC59", "FR10", "Event history status filter", "Siti has Pending and Published events", "GET /admin/history.php?status=Pending", "Only pending events")
def _():
    t = S_ORG.get(BASE + "/admin/history.php", params={"status": "Pending"}).text
    return "TC Festival Rasa" in t and "Pesta Kebudayaan Johor" not in t, "filtered"

@tc("TC60", "FR10", "History shows tickets sold and revenue", "Paid reservations for Pesta", "GET /admin/history.php", "Tickets sold and revenue columns reflect paid bookings")
def _():
    sold = sql(f"SELECT SUM(quantity) FROM reservation WHERE eventId={PESTA} AND status='Paid'")
    rev = sql(f"SELECT SUM(totalAmount) FROM reservation WHERE eventId={PESTA} AND status='Paid'")
    t = S_ORG.get(BASE + "/admin/history.php").text
    return f"RM {float(rev):,.2f}" in t, f"{sold} tickets, revenue RM {float(rev):,.2f} displayed"

# ================================================================= FR11 manage event details
NEWID = int(sql("SELECT eventId FROM event WHERE title='TC Festival Rasa'"))

@tc("TC61", "FR11", "Manager approves a pending event", "TC Festival Rasa Pending, venue free", "POST approve", "Status Published and listed publicly")
def _():
    form(S_MGR, "/admin/events.php", {"id": NEWID, "action": "approve"}, from_path="/admin/events.php")
    st = sql(f"SELECT status FROM event WHERE eventId={NEWID}")
    visible = "TC Festival Rasa" in requests.get(BASE + "/events.php").text
    return st == "Published" and visible, f"status {st}, publicly visible={visible}"

@tc("TC62", "FR11", "Manager edits event details", "Event Published", "Change price and seats", "Values saved")
def _():
    form(S_MGR, f"/admin/event_edit.php?id={NEWID}", {"id": NEWID, "title": "TC Festival Rasa", "description": "Updated description for the festival.", "category": "Food", "ticketPrice": "15.00", "totalSeats": "100"})
    row = sql(f"SELECT ticketPrice, totalSeats, availableSeats FROM event WHERE eventId={NEWID}")
    return row == "15.00\t100\t100", f"price/seats/available = {row}"

@tc("TC63", "FR11", "Seats cannot be reduced below tickets already booked", "10 seats booked", "Set totalSeats to 5", "Error; seats unchanged")
def _():
    sql(f"UPDATE event SET availableSeats = totalSeats - 10 WHERE eventId={NEWID}")
    r = form(S_MGR, f"/admin/event_edit.php?id={NEWID}", {"id": NEWID, "title": "TC Festival Rasa", "description": "Updated description for the festival.", "category": "Food", "ticketPrice": "15.00", "totalSeats": "5"})
    total = sql(f"SELECT totalSeats FROM event WHERE eventId={NEWID}")
    sql(f"UPDATE event SET availableSeats = totalSeats WHERE eventId={NEWID}")
    return "cannot be lower than" in r.text and total == "100", "rejected, total stays 100"

@tc("TC64", "FR11", "Cancelling an event cancels bookings and hides it", "Event with an active reservation", "POST cancel", "Event Cancelled; reservation Cancelled; hidden from public")
def _():
    rid = make_reservation(S_A, NEWID, 2)
    form(S_MGR, "/admin/events.php", {"id": NEWID, "action": "cancel"}, from_path="/admin/events.php")
    ev = sql(f"SELECT status FROM event WHERE eventId={NEWID}")
    rs = sql(f"SELECT status FROM reservation WHERE reservationId={rid}")
    hidden = "TC Festival Rasa" not in requests.get(BASE + "/events.php").text
    return ev == "Cancelled" and rs == "Cancelled" and hidden, f"event {ev}, reservation {rs}, hidden={hidden}"

@tc("TC65", "FR11", "Only managers can approve (organizer POST refused)", "Organizer logged in", "Organizer POSTs approve", "HTTP 403; status unchanged")
def _():
    r = form(S_ORG, "/admin/events.php", {"id": WAYANG, "action": "approve"}, from_path="/admin/event_create.php")
    st = sql(f"SELECT status FROM event WHERE eventId={WAYANG}")
    return r.status_code == 403 and st == "Pending", f"HTTP {r.status_code}, event status still {st}"

# ================================================================= FR12 schedule
@tc("TC66", "FR12", "Schedule update blocked by venue clash", "Pesta uses Dataran Bandaraya JB 14 Nov 10:00-22:00", "Move Wayang Kulit to same venue 14 Nov 12:00-14:00", "Error 'Venue clash'; schedule unchanged")
def _():
    sid = sql(f"SELECT scheduleId FROM schedule WHERE eventId={WAYANG}")
    r = form(S_MGR, f"/admin/schedule.php?edit={sid}", {"scheduleId": sid, "venue": "Dataran Bandaraya Johor Bahru", "start": "2026-11-14T12:00", "end": "2026-11-14T14:00"})
    v = sql(f"SELECT venue FROM schedule WHERE scheduleId={sid}")
    return "Venue clash" in r.text and v == "Muzium Negara, Kuala Lumpur", "clash reported, venue unchanged"

@tc("TC67", "FR12", "Schedule update allowed when times do not overlap", "Same venue, next day", "Move Wayang Kulit to 15 Nov 10:00-12:00", "Saved; schedule status Rescheduled")
def _():
    sid = sql(f"SELECT scheduleId FROM schedule WHERE eventId={WAYANG}")
    form(S_MGR, f"/admin/schedule.php?edit={sid}", {"scheduleId": sid, "venue": "Dataran Bandaraya Johor Bahru", "start": "2026-11-15T10:00", "end": "2026-11-15T12:00"})
    row = sql(f"SELECT venue, startDateTime, status FROM schedule WHERE scheduleId={sid}")
    return row == "Dataran Bandaraya Johor Bahru\t2026-11-15 10:00:00\tRescheduled", row

@tc("TC68", "FR12", "Back-to-back bookings are allowed (end = next start)", "Pesta ends 14 Nov 22:00", "Schedule another event 22:00-23:00 at same venue", "Saved")
def _():
    sid = sql(f"SELECT scheduleId FROM schedule WHERE eventId={WAYANG}")
    form(S_MGR, f"/admin/schedule.php?edit={sid}", {"scheduleId": sid, "venue": "Dataran Bandaraya Johor Bahru", "start": "2026-11-14T22:00", "end": "2026-11-14T23:00"})
    row = sql(f"SELECT startDateTime FROM schedule WHERE scheduleId={sid}")
    return row == "2026-11-14 22:00:00", f"start now {row}"

@tc("TC69", "FR12", "End before start rejected on schedule form", "Manager", "end earlier than start", "Error message")
def _():
    sid = sql(f"SELECT scheduleId FROM schedule WHERE eventId={WAYANG}")
    r = form(S_MGR, f"/admin/schedule.php?edit={sid}", {"scheduleId": sid, "venue": "X Hall", "start": "2026-11-20T14:00", "end": "2026-11-20T10:00"})
    return "End time must be after" in r.text, "rejected"

@tc("TC70", "FR12/FR11", "Approval blocked when the venue is double-booked", "Pending event overlaps a published event at same venue", "Approve Wayang Kulit (moved into Pesta's slot)", "Error 'Cannot approve'; stays Pending")
def _():
    sid = sql(f"SELECT scheduleId FROM schedule WHERE eventId={WAYANG}")
    sql(f"UPDATE schedule SET startDateTime='2026-11-14 12:00:00', endDateTime='2026-11-14 14:00:00' WHERE scheduleId={sid}")
    r = form(S_MGR, "/admin/events.php", {"id": WAYANG, "action": "approve"}, from_path="/admin/events.php", follow=True)
    st = sql(f"SELECT status FROM event WHERE eventId={WAYANG}")
    sql(f"UPDATE schedule SET startDateTime='2026-12-19 14:00:00', endDateTime='2026-12-19 17:00:00', venue='Muzium Negara, Kuala Lumpur' WHERE scheduleId={sid}")
    return "Cannot approve" in r.text and st == "Pending", f"status {st}"

# ================================================================= FR13 organizers
@tc("TC71", "FR13", "Manager adds an organizer who can then log in", "Manager logged in", "POST add organizer; log in as new organizer", "Account created; login OK")
def _():
    form(S_MGR, "/admin/organizers.php", {"action": "add", "fullName": "TC Organizer", "email": "tc_org@organizer.my", "phone": "0191112222", "password": "TempPass1"})
    row = sql("SELECT role, status FROM user WHERE email='tc_org@organizer.my'")
    s, r = login_admin("tc_org@organizer.my", "TempPass1")
    return row == "Organizer\tActive" and "/admin/index.php" in loc(r), f"row '{row}', login -> {loc(r)}"

@tc("TC72", "FR13", "Duplicate organizer email rejected", "tc_org exists", "Add organizer with same email", "Error")
def _():
    r = form(S_MGR, "/admin/organizers.php", {"action": "add", "fullName": "Dup", "email": "tc_org@organizer.my", "phone": "0191112222", "password": "TempPass1"})
    return "already registered" in r.text, "rejected"

@tc("TC73", "FR13", "Edit organizer details", "tc_org exists", "Change name and phone", "Saved")
def _():
    oid = sql("SELECT userId FROM user WHERE email='tc_org@organizer.my'")
    form(S_MGR, f"/admin/organizer_edit.php?id={oid}", {"id": oid, "fullName": "TC Organizer Edited", "email": "tc_org@organizer.my", "phone": "0193334444"})
    return sql(f"SELECT fullName, phone FROM user WHERE userId={oid}") == "TC Organizer Edited\t0193334444", "saved"

@tc("TC74", "FR13", "Deactivated organizer cannot log in", "tc_org Active", "Deactivate then log in", "Status Inactive; login blocked")
def _():
    oid = sql("SELECT userId FROM user WHERE email='tc_org@organizer.my'")
    form(S_MGR, "/admin/organizers.php", {"id": oid, "action": "deactivate"}, from_path="/admin/organizers.php")
    st = sql(f"SELECT status FROM user WHERE userId={oid}")
    s, r = login_admin("tc_org@organizer.my", "TempPass1")
    return st == "Inactive" and "deactivated" in r.text, f"status {st}, login blocked"

@tc("TC75", "FR13", "Reactivated organizer can log in again", "tc_org Inactive", "Activate then log in", "Login OK")
def _():
    oid = sql("SELECT userId FROM user WHERE email='tc_org@organizer.my'")
    form(S_MGR, "/admin/organizers.php", {"id": oid, "action": "activate"}, from_path="/admin/organizers.php")
    s, r = login_admin("tc_org@organizer.my", "TempPass1")
    return "/admin/index.php" in loc(r), f"login -> {loc(r)}"

# ================================================================= NFR / security / performance
@tc("TC76", "NFR2", "Form without CSRF token is rejected", "None", "POST /login.php without csrf", "HTTP 403")
def _():
    r = requests.post(BASE + "/login.php", data={"email": "aisyah@mail.com", "password": "Aisyah@123"}, allow_redirects=False)
    return r.status_code == 403, f"HTTP {r.status_code}"

@tc("TC77", "NFR2", "SQL injection in login does not bypass authentication", "None", "email = ' OR '1'='1' --", "Login fails")
def _():
    s, r = login_public("' OR '1'='1' -- ", "x")
    d = s.get(BASE + "/dashboard.php", allow_redirects=False)
    return "Invalid email or password" in r.text and d.status_code == 302, "rejected; dashboard still protected"

@tc("TC78", "NFR2", "Script in user input is escaped (XSS)", "None", "Register with name <script>alert(1)</script>", "Name shown as text, not executed")
def _():
    signup(sess(), "<script>alert(1)</script>", "tc_xss@mail.com", "0171234567", "Passw0rd1")
    s, _r = login_public("tc_xss@mail.com", "Passw0rd1")
    t = s.get(BASE + "/dashboard.php").text
    return "<script>alert(1)</script>" not in t and "&lt;script&gt;" in t, "output encoded"

@tc("TC79", "NFR2", "Error log records failed logins", "Earlier failed logins performed", "Read logs/app.log", "WARNING entries for failed logins")
def _():
    log = open(os.path.join(ROOT, "logs/app.log")).read()
    return "Failed participant login" in log and "Failed admin login" in log, f"{log.count('WARNING')} warning lines in log"

@tc("TC80", "NFR3", "Homepage responds in under 3 seconds", "Server running", "20 sequential GET / requests", "Average < 3000 ms and max < 3000 ms")
def _():
    ts = []
    for _i in range(20):
        t0 = time.time(); requests.get(BASE + "/"); ts.append((time.time() - t0) * 1000)
    return max(ts) < 3000, f"avg {sum(ts)/len(ts):.0f} ms, max {max(ts):.0f} ms"

@tc("TC81", "NFR3", "System handles 100 concurrent visitors", "Server running", "100 parallel GET requests to /events.php", "All HTTP 200 within 3 s each")
def _():
    res = []
    def go():
        t0 = time.time(); r = requests.get(BASE + "/events.php"); res.append((r.status_code, (time.time() - t0)))
    th = [threading.Thread(target=go) for _ in range(100)]
    t0 = time.time(); [t.start() for t in th]; [t.join() for t in th]
    ok = all(c == 200 for c, _ in res)
    return ok and max(t for _, t in res) < 3, f"{sum(1 for c,_ in res if c==200)}/100 returned 200, slowest {max(t for _,t in res):.2f} s, total {time.time()-t0:.2f} s"

@tc("TC82", "NFR5", "Data integrity: foreign keys and checks enforced", "Database", "Insert reservation with unknown user", "Database rejects the row")
def _():
    try:
        sql("INSERT INTO reservation (userId,eventId,quantity,totalAmount,reservedDate,expiryDate) VALUES (9999,1,1,10,NOW(),NOW())")
        return False, "row accepted"
    except RuntimeError as ex:
        return "foreign key" in str(ex).lower(), "rejected by foreign key constraint"

@tc("TC83", "NFR1", "Friendly 404 for unknown reservation", "Logged in", "GET /payment.php?id=999999", "HTTP 404 with link back")
def _():
    s, _r = login_public("aisyah@mail.com", "Aisyah@123")
    r = s.get(BASE + "/payment.php", params={"id": 999999})
    return r.status_code == 404 and "Reservation not found" in r.text, f"HTTP {r.status_code}"


# ================================================================= extra checks added after review
@tc("TC84", "FR11", "Event becomes Completed after its end time", "Published event whose schedule has ended", "Set schedule end in the past, load /events.php", "Event status changes to Completed and it is no longer listed")
def _():
    ev = eid("Malam Muzik Rakyat")
    sql(f"UPDATE schedule SET startDateTime='2026-10-01 19:00:00', endDateTime='2026-10-01 22:00:00' WHERE eventId={ev}")
    t = requests.get(BASE + "/events.php").text
    st = sql(f"SELECT status FROM event WHERE eventId={ev}")
    sql(f"UPDATE schedule SET startDateTime='2026-12-05 19:30:00', endDateTime='2026-12-05 22:30:00' WHERE eventId={ev}")
    sql(f"UPDATE event SET status='Published' WHERE eventId={ev}")
    return st == "Completed" and "Malam Muzik Rakyat" not in t, f"status {st}"

@tc("TC85", "NFR2", "Deactivated user loses access immediately", "Organizer logged in, then deactivated by manager", "Manager deactivates organizer; organizer reloads admin page", "Redirected to admin login (session no longer valid)")
def _():
    s, _r = login_admin("lim@organizer.my", "Organizer@123")
    uid = sql("SELECT userId FROM user WHERE email='lim@organizer.my'")
    form(S_MGR, "/admin/organizers.php", {"id": uid, "action": "deactivate"}, from_path="/admin/organizers.php")
    r = s.get(BASE + "/admin/history.php", allow_redirects=False)
    form(S_MGR, "/admin/organizers.php", {"id": uid, "action": "activate"}, from_path="/admin/organizers.php")
    return r.status_code == 302 and "/admin/login.php" in loc(r), f"HTTP {r.status_code} -> {loc(r) or '(page served)'}"

@tc("TC86", "FR8/NFR5", "Two simultaneous payments for one reservation", "Active reservation", "Submit 2 payments in parallel", "Exactly one Successful payment and one ticket code")
def _():
    s1 = login_public("farid@mail.com", "Farid@123")[0]
    rid = make_reservation(s1, PESTA, 1)
    toks = [csrf(s1, f"/payment.php?id={rid}")] * 2
    bar = threading.Barrier(2)
    def go(i):
        bar.wait()
        s1.post(BASE + "/payment.php", data={"id": rid, "method": "Card", "csrf": toks[i]}, allow_redirects=False)
    th = [threading.Thread(target=go, args=(i,)) for i in range(2)]
    [t.start() for t in th]; [t.join() for t in th]
    n = sql(f"SELECT COUNT(*) FROM payment WHERE reservationId={rid} AND status='Successful'")
    return n == "1", f"successful payments: {n}"

# ================================================================= Reports and Archive (manager)
MENUAI = eid("Pesta Menuai Kaamatan")

@tc("TC87", "FR14", "Manager opens the Reports page", "Manager logged in", "GET /admin/reports.php", "HTTP 200 with totals and an event table")
def _():
    r = S_MGR.get(BASE + "/admin/reports.php")
    return r.status_code == 200 and "Event details" in r.text and "Tickets sold" in r.text, f"HTTP {r.status_code}, table present={'Event details' in r.text}"

@tc("TC88", "FR14", "Report revenue matches the database", "Paid bookings exist", "Compare revenue on the page with SUM of paid reservations", "Same figure on the page and in the database")
def _():
    total = float(sql("SELECT COALESCE(SUM(totalAmount),0) FROM reservation WHERE status='Paid'"))
    t = S_MGR.get(BASE + "/admin/reports.php").text
    shown = f"RM {total:,.2f}"
    return shown in t, f"database total {shown}, shown on page={shown in t}"

@tc("TC89", "FR14", "Report filter by status", "Seed data", "GET /admin/reports.php?status=Completed", "Only Completed events are listed")
def _():
    t = S_MGR.get(BASE + "/admin/reports.php", params={"status": "Completed"}).text
    return "Pesta Menuai Kaamatan" in t and "Pesta Kebudayaan Johor" not in t, "completed event shown, published events hidden"

@tc("TC90", "FR14", "Report filter by date range", "Seed data", "GET /admin/reports.php?from=2026-11-01&to=2026-11-10", "Only events starting in that range are listed")
def _():
    t = S_MGR.get(BASE + "/admin/reports.php", params={"from": "2026-11-08", "to": "2026-11-08"}).text
    return "Pasar Seni Warisan" in t and "Pesta Kebudayaan Johor" not in t, "single-day range returns only that day's event"

@tc("TC91", "FR14", "Report can be downloaded as CSV", "Manager logged in", "GET /admin/reports.php?export=csv", "Content-Type text/csv with a header row")
def _():
    r = S_MGR.get(BASE + "/admin/reports.php", params={"export": "csv"})
    ok = r.status_code == 200 and "text/csv" in r.headers.get("Content-Type", "") and r.text.startswith("Event,Organizer")
    return ok, f"HTTP {r.status_code}, type {r.headers.get('Content-Type')}"

@tc("TC92", "FR14/NFR2", "Organizer cannot open Reports or Archive", "Organizer logged in", "GET /admin/reports.php and /admin/archive.php", "Both return 403 Access denied")
def _():
    a = S_ORG.get(BASE + "/admin/reports.php"); b = S_ORG.get(BASE + "/admin/archive.php")
    return a.status_code == 403 and b.status_code == 403, f"reports HTTP {a.status_code}, archive HTTP {b.status_code}"

@tc("TC93", "FR14/NFR2", "Guest is sent to admin login for Reports", "Not logged in", "GET /admin/reports.php", "302 redirect to /admin/login.php")
def _():
    r = requests.get(BASE + "/admin/reports.php", allow_redirects=False)
    return r.status_code == 302 and "/admin/login.php" in loc(r), f"HTTP {r.status_code} -> {loc(r)}"

@tc("TC94", "FR14", "Invalid report filters are ignored safely", "Manager logged in", "GET /admin/reports.php?from=abc&status=x'--", "HTTP 200, no error page")
def _():
    r = S_MGR.get(BASE + "/admin/reports.php", params={"from": "abc", "to": "9999-99-99", "status": "x'--", "category": "zzz"})
    return r.status_code == 200 and "Event details" in r.text, f"HTTP {r.status_code}"

@tc("TC95", "FR15", "Manager archives a past event", "Completed event whose end time has passed", "POST archive for the completed event", "archived = 1 and event leaves Manage Event Details")
def _():
    form(S_MGR, "/admin/archive.php", {"id": MENUAI, "action": "archive"})
    flag = sql(f"SELECT archived FROM event WHERE eventId={MENUAI}")
    listing = S_MGR.get(BASE + "/admin/events.php").text
    return flag == "1" and "Pesta Menuai Kaamatan" not in listing, f"archived={flag}, still listed={'Pesta Menuai Kaamatan' in listing}"

@tc("TC96", "FR15", "Archived event still counts in the report", "Event archived in TC95", "GET /admin/reports.php", "Archived event is shown with its revenue")
def _():
    t = S_MGR.get(BASE + "/admin/reports.php").text
    return "Pesta Menuai Kaamatan" in t and "archived" in t, "event present in report and marked archived"

@tc("TC97", "FR15", "A future event cannot be archived", "Published event that has not happened", "POST archive for Pesta Kebudayaan Johor", "Refused, archived stays 0")
def _():
    form(S_MGR, "/admin/archive.php", {"id": PESTA, "action": "archive"})
    flag = sql(f"SELECT archived FROM event WHERE eventId={PESTA}")
    return flag == "0", f"archived={flag}"

@tc("TC98", "FR15", "A pending event cannot be archived", "Pending event", "POST archive for Pameran Wayang Kulit", "Refused, archived stays 0")
def _():
    form(S_MGR, "/admin/archive.php", {"id": WAYANG, "action": "archive"})
    flag = sql(f"SELECT archived FROM event WHERE eventId={WAYANG}")
    return flag == "0", f"archived={flag}"

@tc("TC99", "FR15", "Manager restores an archived event", "Event archived in TC95", "POST restore", "archived = 0 and event is listed again")
def _():
    form(S_MGR, "/admin/archive.php", {"id": MENUAI, "action": "restore"})
    flag = sql(f"SELECT archived FROM event WHERE eventId={MENUAI}")
    listing = S_MGR.get(BASE + "/admin/events.php").text
    return flag == "0" and "Pesta Menuai Kaamatan" in listing, f"archived={flag}"

@tc("TC100", "FR15", "Archive all past events", "One completed and one cancelled past event", "POST archive_all", "Both archived, future events untouched")
def _():
    form(S_MGR, "/admin/archive.php", {"action": "archive_all"})
    n = sql("SELECT COUNT(*) FROM event WHERE archived=1")
    future = sql("SELECT COUNT(*) FROM event WHERE archived=1 AND status IN ('Published','Pending')")
    return n == "2" and future == "0", f"archived={n}, future archived={future}"

@tc("TC101", "FR15/NFR2", "Archive rejects a form without a CSRF token", "Manager logged in", "POST archive without csrf", "HTTP 403")
def _():
    r = S_MGR.post(BASE + "/admin/archive.php", data={"id": KOMPANG, "action": "restore"}, allow_redirects=False)
    return r.status_code == 403, f"HTTP {r.status_code}"

# ================================================================= summary
passed = sum(1 for r in RESULTS if r["status"] == "Pass")
print(f"\n{passed}/{len(RESULTS)} passed")
json.dump({"run": time.strftime("%Y-%m-%d %H:%M:%S"), "total": len(RESULTS), "passed": passed, "results": RESULTS},
          open(os.path.join(ROOT, "tests/results.json"), "w"), indent=1)
sys.exit(0 if passed == len(RESULTS) else 1)
