#!/usr/bin/env python3
"""Tests d'interface (Playwright/Chromium) : index.html, results.html, admin.html.
Variables attendues : BASE (URL du serveur), WORK (dossier contenant data.json)."""
import json, os, sys
from playwright.sync_api import sync_playwright

BASE = os.environ["BASE"]; WORK = os.environ["WORK"]
PASS = FAIL = 0
def ok(m):  globals()["PASS"] = PASS + 1; print(f"  ✅ {m}")
def bad(m, extra=""):
    globals()["FAIL"] = FAIL + 1; print(f"  ❌ {m}"); print(f"     {extra}" if extra else "", end="")
def check(cond, m, extra=""): ok(m) if cond else bad(m, extra)
def db(): return json.load(open(f"{WORK}/data.json"))

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1200, "height": 900})
    errors = []
    page = ctx.new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("dialog", lambda d: d.accept())   # confirm()/prompt() acceptés par défaut

    # Hors ligne (CI, bac à sable) : Chart.js est servi depuis une copie locale si elle existe,
    # et les échecs de chargement de ressources externes (polices) ne comptent pas comme erreurs.
    CHART_LOCAL = os.environ.get("CHART_JS_LOCAL", "/tmp/chart.umd.js")
    if os.path.exists(CHART_LOCAL):
        ctx.route("**/chart.umd.min.js", lambda route: route.fulfill(path=CHART_LOCAL, content_type="application/javascript"))
    ctx.route("**/fonts.googleapis.com/**", lambda route: route.abort())

    # -------- admin : repartir d'une base propre + réglages --------
    print("== admin.html ==")
    page.goto(f"{BASE}/admin.html"); page.wait_for_selector("#subjectList .subject-item, #subjectList .empty")
    if page.locator("#clearBtn").is_enabled():
        page.click("#clearBtn"); page.wait_for_function("document.querySelector('#respWrapper').textContent.includes('Aucune réponse')")
    check(page.locator("#subjectList .subject-item").count() == 7, "7 sujets par défaut affichés")
    page.fill("#newSubjectInput", "Sujet <b>admin</b>"); page.press("#newSubjectInput", "Enter")
    page.wait_for_function("document.querySelectorAll('#subjectList .subject-item').length === 8")
    check("Sujet <b>admin</b>" in db()["subjects"], "ajout d'un sujet depuis l'admin → data.json")
    check(page.locator("#subjectList .subject-item .name").nth(7).inner_text() == "Sujet <b>admin</b>", "nom affiché échappé (pas d'injection HTML)")
    page.fill("#newSubjectInput", "sujet <B>ADMIN</B>"); page.press("#newSubjectInput", "Enter")
    page.wait_for_selector(".toast.show")
    check(len(db()["subjects"]) == 8, "doublon (casse différente) refusé")
    page.locator("#subjectList .subject-item").nth(7).locator("button[title=Monter]").click()
    page.wait_for_function("document.querySelector('#subjectStatus').textContent.includes('Ordre')")
    check(db()["subjects"][6] == "Sujet <b>admin</b>", "réordonnancement enregistré")
    page.evaluate("window.prompt = () => ' Sujet renommé '")
    page.locator("#subjectList .subject-item").nth(6).locator("button[title=Renommer]").click()
    page.wait_for_function("document.querySelector('#subjectStatus').textContent.includes('renommé')")
    check(db()["subjects"][6] == "Sujet renommé", "renommage enregistré (et nettoyé)", str(db()["subjects"]))
    page.locator("#subjectList .subject-item").nth(6).locator("button[title=Supprimer]").click()
    page.wait_for_function("document.querySelectorAll('#subjectList .subject-item').length === 7")
    check(len(db()["subjects"]) == 7, "suppression d'un sujet enregistrée")
    check("PHP" in page.locator("#healthStatus").inner_text(), "diagnostic serveur affiché")

    # -------- index : parcours complet --------
    print("== index.html ==")
    page.goto(f"{BASE}/index.html"); page.wait_for_selector(".q1-select", state="attached")
    check(page.locator(".q1-select").count() == 7, "Q1 : 7 lignes de sujets")
    page.click(".step.active button:has-text('Commencer')"); page.wait_for_selector(".toast.show")
    check(page.locator(".step.active").get_attribute("data-step") == "0", "pseudo vide → bloqué à l'étape 0")
    page.fill("#pseudoInput", "Alice <i>UI</i>"); page.press("#pseudoInput", "Enter")
    check(page.locator(".step.active").get_attribute("data-step") == "1", "Entrée sur le pseudo → étape 1")
    page.fill("#newSubjectInput", "Sujet volant"); page.press("#newSubjectInput", "Enter")
    check(page.locator(".q1-select").count() == 8 and page.locator("#q2List .drag-item").count() == 8, "sujet ajouté à la volée dans Q1..Q4")
    page.locator(".q1-select").nth(0).select_option("contribué activement")
    page.locator(".q1-select").nth(7).select_option("suivi le sujet")
    page.click(".step.active button:has-text('Suivant')")
    # Q2 : simuler un drag & drop HTML5 (dernier élément en tête)
    items = page.locator("#q2List .drag-item")
    items.nth(7).drag_to(items.nth(0))
    first = page.locator("#q2List .drag-item").nth(0).get_attribute("data-subject")
    check(first == "Sujet volant", "Q2 : drag & drop réordonne la liste", first)
    page.click(".step.active button:has-text('Suivant')"); page.locator(".q3-select").nth(0).select_option("très flou")
    page.click(".step.active button:has-text('Suivant')"); page.locator(".q4-select").nth(0).select_option("on va échouer")
    page.click(".step.active button:has-text('Suivant')")
    page.click("#submitBtn"); page.wait_for_selector(".toast.show")
    check(page.locator(".step.active").get_attribute("data-step") == "5", "Q5 vide → envoi refusé")
    page.locator(".radio-card").nth(1).click()
    page.click("#submitBtn")
    page.wait_for_selector(".step[data-step='6'].active")
    d = db(); r = d["responses"][-1]
    check(r["pseudo"] == "Alice <i>UI</i>", "réponse enregistrée")
    check("Sujet volant" in d["subjects"], "sujet à la volée persisté")
    check(r["q1"] == {"Migration API v2": "contribué activement", "Sujet volant": "suivi le sujet"}, "Q1 enregistré", str(r["q1"]))
    check(r["q2"][0] == "Sujet volant" and len(r["q2"]) == 8, "Q2 enregistré avec l'ordre choisi", str(r["q2"]))
    check(r["q3"] == {"Migration API v2": "très flou"} and r["q4"] == {"Migration API v2": "on va échouer"}, "Q3/Q4 enregistrés")
    check(r["q5"] == "J'ai juste ce qu'il faut", "Q5 enregistré")

    # Serveur indisponible → message d'erreur propre, pas de crash JS
    page.route("**/api.php*", lambda route: route.abort())
    page.goto(f"{BASE}/index.html"); page.wait_for_selector(".toast.show")
    check("Impossible de charger" in page.locator("#toast").inner_text(), "API injoignable → toast explicite")
    page.unroute("**/api.php*")

    # -------- results --------
    print("== results.html ==")
    page.goto(f"{BASE}/results.html"); page.wait_for_selector("#q5Chart")
    check(page.locator(".stat-value").nth(0).inner_text() == "1", "1 participant affiché")
    check(page.locator("table.matrix tbody tr").count() == 2, "matrice Q1 : 1 ligne + total")
    check(page.locator("table.matrix").inner_html().count("<i>") == 0, "pseudo échappé dans la matrice")
    check(page.locator(".rank-item").nth(0).inner_text().startswith("Sujet volant"), "Q2 : sujet classé 1er en tête")
    n_charts = page.evaluate("Object.values(Chart.instances).length")
    check(n_charts == 4, "4 graphiques Chart.js", str(n_charts))
    page.click("button:has-text('Rafraîchir')"); page.wait_for_timeout(500)
    check(page.evaluate("Object.values(Chart.instances).length") == 4, "rafraîchir ne fuit pas d'instances Chart.js")
    page.screenshot(path=f"{WORK}/results.png", full_page=True)

    # -------- admin : suppression individuelle et totale --------
    print("== admin.html (réponses) ==")
    page.goto(f"{BASE}/admin.html"); page.wait_for_selector(".resp-table")
    check(page.locator(".resp-table tbody tr").count() == 1, "1 réponse listée")
    page.locator(".resp-table tbody tr").nth(0).locator("button").click()
    page.wait_for_function("document.querySelector('#respWrapper').textContent.includes('Aucune réponse')")
    check(len(db()["responses"]) == 0, "suppression individuelle → data.json")
    # réinjecter 3 réponses via l'API puis tout supprimer
    for i in range(3):
        page.evaluate("""(i) => fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'submit', pseudo:'p'+i, q5:"J'ai juste ce qu'il faut"})}).then(r=>r.json())""", i)
    page.click("button:has-text('Rafraîchir')"); page.wait_for_function("document.querySelectorAll('.resp-table tbody tr').length === 3")
    check(page.locator("#clearBtn").is_enabled(), "bouton 'Supprimer toutes les réponses' actif")
    page.click("#clearBtn")
    page.wait_for_function("document.querySelector('#respWrapper').textContent.includes('Aucune réponse')")
    check(len(db()["responses"]) == 0 and len(db()["subjects"]) == 8, "tout supprimé, sujets conservés")
    check(not page.locator("#clearBtn").is_enabled(), "bouton désactivé quand il n'y a rien à supprimer")

    # Mobile : pas de débordement horizontal
    mob = ctx.new_page(); mob.set_viewport_size({"width": 390, "height": 800})
    for f in ("index.html", "admin.html", "results.html"):
        mob.goto(f"{BASE}/{f}"); mob.wait_for_timeout(400)
        sw, cw = mob.evaluate("[document.documentElement.scrollWidth, document.documentElement.clientWidth]")
        check(sw <= cw + 1, f"{f} : pas de scroll horizontal sur mobile", f"{sw} > {cw}")

    check(not errors, "aucune erreur JS/console sur les 3 pages", "\n     ".join(errors[:5]))
    browser.close()

print(f"\nUI : {PASS} OK, {FAIL} KO")
sys.exit(0 if FAIL == 0 else 1)
