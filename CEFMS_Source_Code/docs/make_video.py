import json, html, os, subprocess, shutil, glob
from playwright.sync_api import sync_playwright
F = 1.5
B = "http://127.0.0.1:8080"; ROOT = "/home/claude/cefms"; OUT = ROOT + "/docs/video/"
ENV = dict(os.environ, CEFMS_DB_USER="cefms", CEFMS_DB_PASS="cefms_pw")
subprocess.run(["php", ROOT + "/sql/seed.php"], env=ENV, check=True, capture_output=True)
open(ROOT + "/logs/app.log", "w").close()
for f in glob.glob(OUT + "*.webm"): os.remove(f)
run1 = json.load(open(ROOT + "/tests/results_run1.json")); fin = json.load(open(ROOT + "/tests/results_final.json"))

BASE_CSS = "body{margin:0;font-family:Arial,sans-serif;background:#0f1b24;color:#fff;display:flex;flex-direction:column;justify-content:center;height:100vh;padding:0 70px;box-sizing:border-box}h1{font-size:44px;margin:0 0 12px}h2{font-size:30px;margin:0 0 18px;color:#E8B84B}p{font-size:22px;color:#cfdde6;margin:6px 0}pre{background:#0a1218;border:1px solid #2b4452;border-radius:8px;padding:16px;font-size:16px;line-height:1.45;overflow:hidden;white-space:pre-wrap;margin:8px 0;font-family:'DejaVu Sans Mono',monospace}.red{color:#ff8a80}.grn{color:#7dd99b}.dim{color:#8fa5b3}.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px}.tag{display:inline-block;background:#E8B84B;color:#12384A;font-weight:700;padding:3px 12px;border-radius:12px;font-size:16px}"

def slide(body): return f"<!doctype html><meta charset=utf-8><style>{BASE_CSS}</style><body>{body}</body>"

def run():
    with sync_playwright() as p:
        br = p.chromium.launch(executable_path="/opt/pw-browsers/chromium-1194/chrome-linux/chrome", args=["--no-sandbox"])
        ctx = br.new_context(viewport={"width": 1280, "height": 720}, record_video_dir=OUT, record_video_size={"width": 1280, "height": 720})
        pg = ctx.new_page(); state = {"cap": ""}
        def cap(text):
            state["cap"] = text
            pg.evaluate("""t=>{let d=document.getElementById('cefcap');if(!d){d=document.createElement('div');d.id='cefcap';
              d.style.cssText='position:fixed;left:0;right:0;bottom:0;z-index:99999;background:rgba(10,18,24,.9);color:#fff;font:600 21px Arial;padding:14px 28px;border-top:3px solid #E8B84B';document.body.appendChild(d)}
              d.textContent=t}""", text)
        def go(url, wait=0): pg.goto(B + url); cap(state["cap"]); pg.wait_for_timeout(F*wait)
        def say(text, ms): cap(text); pg.wait_for_timeout(F*ms)
        def typ(sel, val, d=45): pg.click(sel); pg.keyboard.type(val, delay=d)
        def show(body, text, ms): pg.set_content(slide(body)); cap(text); pg.wait_for_timeout(F*ms)

        # S1 title
        show("<span class=tag>CSC2854 PROJECT</span><h1>CEFMS - Error Detection &amp; Troubleshooting</h1><p>Cultural Events and Festivals Management System</p><p class=dim>Fazrique Isyraf Bin Abu Bakar &middot; BCS2403-011 &middot; DCS 6B</p>",
             "Demo: how CEFMS detects errors and how I troubleshoot them", 5500)
        # S2 home
        cap("Public site: anyone can browse events without logging in"); go("/", 5000)
        # S3 signup validation
        say("Error detection 1 - input validation on Sign Up", 800); go("/signup.php", 600)
        typ("#fullName", "Test User"); typ("#email", "test@mail"); typ("#phone", "12345"); typ("#password", "abc"); typ("#password2", "abd")
        pg.wait_for_timeout(F*500); pg.click("button[type=submit]"); pg.wait_for_load_state(); cap("Bad email, phone and weak password are rejected with clear messages"); pg.wait_for_timeout(F*5500)
        # S4 wrong login
        say("Error detection 2 - wrong password is rejected and written to the log", 600); go("/login.php", 500)
        typ("#email", "aisyah@mail.com"); typ("#password", "wrongpass"); pg.click("button[type=submit]"); pg.wait_for_load_state(); cap("Generic error shown - the attempt is recorded in logs/app.log"); pg.wait_for_timeout(F*4500)
        # S5 login, reserve, gateway failure, retry
        say("Valid login, then reserve 2 tickets for Pesta Kebudayaan Johor", 500)
        pg.fill("#email", "aisyah@mail.com"); pg.fill("#password", "Aisyah@123"); pg.wait_for_timeout(F*500); pg.click("button[type=submit]"); pg.wait_for_url("**/dashboard.php"); cap(state["cap"]); pg.wait_for_timeout(F*1200)
        go("/event.php?id=1", 1500); pg.fill("#qty", "2"); pg.wait_for_timeout(F*700); pg.click("text=Reserve / Buy Ticket"); pg.wait_for_url("**/payment.php*"); cap("Seats are held for 15 minutes while the user pays"); pg.wait_for_timeout(F*3500)
        say("Error detection 3 - payment gateway failure (simulated)", 600)
        pg.click("text=Simulate gateway failure"); pg.wait_for_load_state(); cap("Payment failed: seats stay held, no ticket issued, user can retry"); pg.wait_for_timeout(F*5000)
        say("Troubleshooting path: retry the payment", 600); pg.check("input[value='E-Wallet']"); pg.click("text=Pay RM"); pg.wait_for_url("**/ticket.php*"); cap("Retry succeeds - ticket code issued"); pg.wait_for_timeout(F*4500)
        # S6 seat availability
        subprocess.run(["mysql", "-ucefms", "-pcefms_pw", "-h127.0.0.1", "cefms", "-e", "UPDATE event SET availableSeats=3 WHERE eventId=3"], check=True)
        say("Error detection 4 - seat availability check (only 3 seats left)", 500); go("/event.php?id=3", 800)
        pg.fill("#qty", "3"); pg.evaluate("document.getElementById('qty').max=10"); pg.fill("#qty", "5"); pg.wait_for_timeout(F*500); pg.click("text=Reserve / Buy Ticket"); pg.wait_for_load_state(); cap("Request for 5 tickets refused: 'Only 3 seat(s) left' - no overselling"); pg.wait_for_timeout(F*5000)
        # S7 venue clash (admin)
        say("Admin panel - Event Manager schedules an event at a booked venue", 500); go("/admin/login.php", 400)
        pg.fill("#email", "manager@cefms.my"); pg.fill("#password", "Manager@123"); pg.click("button[type=submit]"); pg.wait_for_url("**/admin/index.php"); cap(state["cap"]); pg.wait_for_timeout(F*800)
        go("/admin/schedule.php?edit=7", 600); pg.fill("#venue", "Dataran Bandaraya Johor Bahru"); pg.fill("#start", "2026-11-14T12:00"); pg.fill("#end", "2026-11-14T14:00"); pg.wait_for_timeout(F*600)
        pg.click("text=Save schedule"); pg.wait_for_load_state(); cap("Error detection 5 - venue clash blocked: double booking is prevented"); pg.wait_for_timeout(F*5500)
        # S8 log
        log = open(ROOT + "/logs/app.log").read().strip().splitlines()[-9:]
        body = "<h2>logs/app.log - errors are detected and recorded</h2><pre>" + "\n".join(
            ("<span class=red>%s</span>" if " WARNING " in l else "<span class=grn>%s</span>") % html.escape(l[:150]) for l in log) + "</pre><p class=dim>Each line: time, level, message, request URL and user id - used to trace a problem to its cause.</p>"
        show(body, "Detection: warnings and errors are written to logs/app.log with time, URL and user", 9000)
        # S9 troubleshooting: failed tests
        fails = [r for r in run1["results"] if r["status"] == "Fail"]
        body = f"<span class=tag>TROUBLESHOOTING</span><h2>Step 1 - Test run found {len(fails)} defects ({run1['passed']}/{run1['total']} passed)</h2>"
        for r in fails:
            body += f"<pre><span class=red>FAIL {r['id']}  {html.escape(r['title'])}</span>\n<span class=dim>expected:</span> {html.escape(r['expected'])}\n<span class=dim>actual:  </span> {html.escape(r['actual'])}</pre>"
        show(body, "Troubleshooting step 1 - Detect: the automated test suite reports two failing test cases", 11000)
        # S10 analysis + fix
        body = """<h2>Step 2 - Analyse the root cause &nbsp; Step 3 - Fix</h2><div class=cols>
<div><p><b>TC85 - deactivated user keeps access</b></p><p class=dim>Cause: session trusted without re-checking the account status.</p>
<pre><span class=red>- function current_user() {
-     return $_SESSION['user'] ?? null;
- }</span>
<span class=grn>+ function current_user() {
+     ... SELECT status FROM user WHERE userId=?
+     if (status != 'Active') { unset(session user);
+         log_event('warning', 'Session ended...');
+         return null; }
+ }</span></pre></div>
<div><p><b>TC84 - event never becomes Completed</b></p><p class=dim>Cause: no code moved Published events to Completed.</p>
<pre><span class=grn>+ function complete_past_events() {
+   UPDATE event e JOIN schedule s
+   SET e.status='Completed'
+   WHERE e.status='Published'
+     AND s.endDateTime &lt; NOW
+ }
+ // called from expire_reservations()</span></pre></div></div>"""
        show(body, "Troubleshooting steps 2-3 - Analyse the root cause in the code, then fix it", 12000)
        # S11 re-test
        sm = {}
        for r in fin["results"]: sm.setdefault(r["fr"].split("/")[0], []).append(r["status"] == "Pass")
        body = f"<span class=tag>VERIFIED</span><h2>Step 4 - Re-run all tests: <span class=grn>{fin['passed']}/{fin['total']} passed</span></h2><pre>"
        for k in sorted(sm): body += f"{k:<6} {'#' * sum(sm[k]):<30} {sum(sm[k])}/{len(sm[k])}\n"
        body += "</pre><p class=dim>Both defects fixed with no regression. Full details are in the Test Report section of the project report.</p>"
        show(body, "Troubleshooting step 4 - Verify: all test cases now pass, so the fix is confirmed", 8000)
        show("<h1>Thank you</h1><p>Source code, database script, test cases and report are submitted with this video.</p>", "CEFMS - Fazrique Isyraf Bin Abu Bakar (BCS2403-011)", 3500)
        ctx.close(); br.close()
run()
src = sorted(glob.glob(OUT + "*.webm"))[-1]
subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", src, "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "23", "-r", "25", OUT + "CEFMS_Error_Detection_Troubleshooting_Demo.mp4"], check=True)
print(subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", OUT + "CEFMS_Error_Detection_Troubleshooting_Demo.mp4"], capture_output=True, text=True).stdout)
