#!/usr/bin/env bash
# Tests de l'API : validation, verrou/concurrence, fichier absent ou corrompu.
# Variables attendues : BASE (URL du serveur), WORK (dossier contenant data.json).
set -uo pipefail
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ✅ $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  ❌ $1"; [ -n "${2:-}" ] && echo "     $2"; }
post() { printf '%s' "$1" > "$WORK/body"; curl -s -o "$WORK/out" -w '%{http_code}' -H 'Content-Type: application/json' --data-binary "@$WORK/body" "$BASE/api.php"; }
get()  { curl -s -o "$WORK/out" -w '%{http_code}' "$BASE/api.php?action=$1"; }
jq_()  { python3 -c "import json,sys; d=json.load(open('$WORK/out')); print($1)"; }
db()   { python3 -c "import json; d=json.load(open('$WORK/data.json')); print($1)"; }

echo "== API : lectures de base =="
[ "$(get subjects)" = 200 ] && [ "$(jq_ 'len(d["subjects"])')" = 7 ] && ok "GET subjects → 7 sujets par défaut" || bad "GET subjects" "$(cat $WORK/out)"
[ "$(get results)" = 200 ] && [ "$(jq_ 'len(d["responses"])')" = 0 ] && ok "GET results → 0 réponse" || bad "GET results"
[ "$(get health)" = 200 ] && [ "$(jq_ 'd["file_writable"]')" = True ] && ok "GET health → fichier accessible en écriture" || bad "GET health" "$(cat $WORK/out)"
[ "$(get nope)" = 404 ] && ok "GET action inconnue → 404 JSON" || bad "GET action inconnue"
code=$(curl -s -o "$WORK/out" -w '%{http_code}' -X PUT "$BASE/api.php"); [ "$code" = 405 ] && ok "PUT → 405" || bad "PUT → $code"

echo "== API : validation des soumissions =="
[ "$(post '{"action":"submit"}')" = 400 ] && ok "submit sans pseudo → 400" || bad "submit sans pseudo"
[ "$(post 'not json')" = 400 ] && ok "corps non JSON → 400" || bad "corps non JSON"
[ "$(post '{"action":"submit","pseudo":"Bob"}')" = 400 ] && [ "$(jq_ 'd["error"]')" = "Veuillez répondre à la question 5" ] && ok "submit sans Q5 → 400" || bad "submit sans Q5" "$(cat $WORK/out)"
[ "$(post '{"action":"submit","pseudo":"Bob","q5":"n importe quoi"}')" = 400 ] && ok "Q5 hors liste → 400" || bad "Q5 hors liste"

payload='{"action":"submit","pseudo":"  Alice   Dupont  ","new_subjects":["Nouveau sujet","  migration api V2 ",""],
 "q1":{"Migration API v2":"contribué activement","Nouveau sujet":"suivi le sujet","Inconnu":"suivi le sujet","Intégration SSO":"valeur invalide"},
 "q2":["Nouveau sujet","Migration API v2","Inconnu","Migration API v2"],
 "q3":{"Migration API v2":"super claire"},"q4":{"Nouveau sujet":"on va réussir"},
 "q5":"J'\''ai juste ce qu'\''il faut"}'
[ "$(post "$payload")" = 200 ] && ok "submit valide → 200 (id $(jq_ 'd["response_id"]'))" || bad "submit valide" "$(cat $WORK/out)"
[ "$(db 'd["responses"][0]["pseudo"]')" = "Alice Dupont" ] && ok "pseudo nettoyé (espaces)" || bad "pseudo nettoyé" "$(db 'd["responses"][0]["pseudo"]')"
[ "$(db 'len(d["subjects"])')" = 8 ] && [ "$(db '"Nouveau sujet" in d["subjects"]')" = True ] && ok "sujet à la volée ajouté ; doublon insensible à la casse ignoré" || bad "new_subjects" "$(db 'd["subjects"]')"
[ "$(db 'sorted(d["responses"][0]["q1"].keys())')" = "['Migration API v2', 'Nouveau sujet']" ] && ok "Q1 : sujet inconnu et valeur invalide écartés" || bad "Q1 nettoyage" "$(db 'd["responses"][0]["q1"]')"
[ "$(db 'd["responses"][0]["q2"]')" = "['Nouveau sujet', 'Migration API v2']" ] && ok "Q2 : inconnu et doublon écartés, ordre conservé" || bad "Q2 nettoyage" "$(db 'd["responses"][0]["q2"]')"

# Rétro-compat : POST sans "action" mais avec pseudo
[ "$(post '{"pseudo":"Legacy","q5":"J'\''ai pas assez de travail, je m'\''ennuie"}')" = 200 ] && ok "POST sans action → traité comme submit" || bad "rétro-compat submit"

long=$(python3 -c "print('é'*100)")
[ "$(post "{\"action\":\"submit\",\"pseudo\":\"$long\",\"q5\":\"J'ai juste ce qu'il faut\"}")" = 200 ] && [ "$(db 'len(d["responses"][-1]["pseudo"].encode())<=60 and d["responses"][-1]["pseudo"].encode().decode("utf-8") is not None')" = True ] && ok "pseudo trop long tronqué sur frontière UTF-8" || bad "pseudo long" "$(cat $WORK/out)"
big=$(python3 -c "print('x'*300000)")
[ "$(post "{\"action\":\"submit\",\"pseudo\":\"$big\"}")" = 413 ] && ok "corps > 256 Ko → 413" || bad "corps trop gros"

echo "== API : HTML/XSS stocké tel quel (échappé à l'affichage) =="
[ "$(post '{"action":"submit","pseudo":"<script>alert(1)</script>","q5":"J'\''ai juste ce qu'\''il faut"}')" = 200 ] && ok "pseudo avec balises accepté (stocké, échappé côté pages)" || bad "pseudo balises"

echo "== API : administration =="
[ "$(post '{"action":"set_subjects","subjects":["B","A","  a ","","B"]}')" = 200 ] && [ "$(jq_ 'd["subjects"]')" = "['B', 'A']" ] && ok "set_subjects : nettoyage, dédoublonnage, ordre" || bad "set_subjects" "$(cat $WORK/out)"
[ "$(db 'd["responses"][0]["q1"]')" = "{}" ] && [ "$(db 'd["responses"][0]["q2"]')" = "[]" ] && ok "réponses purgées des sujets supprimés" || bad "purge réponses" "$(db 'd["responses"][0]')"
[ "$(post '{"action":"set_subjects"}')" = 400 ] && ok "set_subjects sans liste → 400" || bad "set_subjects sans liste"
[ "$(post '{"action":"set_subjects","subjects":[]}')" = 200 ] && [ "$(db 'd["subjects"]')" = "[]" ] && ok "set_subjects [] → liste vide autorisée" || bad "set_subjects vide"
n=$(db 'len(d["responses"])')
[ "$(post '{"action":"delete_response","id":1}')" = 200 ] && [ "$(jq_ 'd["deleted"]')" = 1 ] && [ "$(db 'len(d["responses"])')" = $((n-1)) ] && ok "delete_response id=1" || bad "delete_response"
[ "$(post '{"action":"delete_response","id":9999}')" = 200 ] && [ "$(jq_ 'd["deleted"]')" = 0 ] && ok "delete_response inexistant → 0 supprimé" || bad "delete inexistant"
[ "$(post '{"action":"delete_response","id":"1"}')" = 400 ] && ok "delete_response id non entier → 400" || bad "delete id string"
[ "$(post '{"action":"clear_responses"}')" = 200 ] && [ "$(db 'len(d["responses"])')" = 0 ] && ok "clear_responses → 0 réponse" || bad "clear_responses"
[ "$(post '{"action":"hack"}')" = 404 ] && ok "action POST inconnue → 404" || bad "action inconnue"

echo "== Robustesse fichier =="
rm -f "$WORK/data.json"
[ "$(get subjects)" = 200 ] && [ "$(jq_ 'len(d["subjects"])')" = 7 ] && [ -f "$WORK/data.json" ] && ok "fichier absent → recréé avec les sujets par défaut dès la 1re lecture" || bad "fichier absent lecture"
rm -f "$WORK/data.json"; [ "$(post '{"action":"submit","pseudo":"Zoé","q5":"J'\''ai juste ce qu'\''il faut"}')" = 200 ] && [ -f "$WORK/data.json" ] && [ "$(db 'len(d["subjects"])')" = 7 ] && ok "fichier absent → recréé avec défauts à la 1re écriture" || bad "fichier absent écriture"
echo '{ "subjects": [ "x", ' > "$WORK/data.json"
[ "$(get results)" = 200 ] && ok "fichier corrompu → lecture répond quand même" || bad "corrompu lecture" "$(cat $WORK/out)"
[ "$(post '{"action":"submit","pseudo":"Yann","q5":"J'\''ai juste ce qu'\''il faut"}')" = 200 ] && ls "$WORK"/data.json.corrupt-* >/dev/null 2>&1 && [ "$(db 'len(d["responses"])')" = 1 ] && ok "fichier corrompu → sauvegardé en .corrupt-* puis recréé" || bad "corrompu écriture" "$(ls $WORK)"
printf '' > "$WORK/data.json"
[ "$(get subjects)" = 200 ] && [ "$(jq_ 'len(d["subjects"])')" = 7 ] && ok "fichier vide → défauts" || bad "fichier vide"
echo '{"subjects":"pas une liste","responses":[{"pseudo":"","q5":"x"},{"pseudo":"Ok","id":"bad","q2":"nope"},42]}' > "$WORK/data.json"
[ "$(get results)" = 200 ] && [ "$(jq_ 'len(d["responses"])')" = 1 ] && [ "$(jq_ 'd["responses"][0]["q2"]')" = "[]" ] && ok "structure partiellement invalide → normalisée" || bad "normalisation" "$(cat $WORK/out)"
[ "$(post '{"action":"clear_responses"}')" = 200 ] && ok "réinitialisation avant tests suivants" || bad "reset"

echo "== Robustesse fichier : non accessible en écriture =="
chmod 444 "$WORK/data.json"; chmod 555 "$WORK"
code=$(post '{"action":"submit","pseudo":"Nope","q5":"J'\''ai juste ce qu'\''il faut"}')
if [ "$(id -u)" = 0 ]; then
    echo "  (exécuté en root : le test 'lecture seule' n'est pas significatif, code=$code)"
elif [ "$code" = 500 ] && [ "$(jq_ 'd["error"]')" = "Erreur serveur" ]; then ok "écriture impossible → 500 JSON explicite"; else bad "écriture impossible" "$code $(cat $WORK/out)"; fi
chmod 755 "$WORK"; chmod 664 "$WORK/data.json"

echo "== Concurrence : 40 soumissions en parallèle, aucune perdue =="
post '{"action":"clear_responses"}' > /dev/null
python3 - <<PY
import json, urllib.request, concurrent.futures, os
BASE=os.environ["BASE"]; WORK=os.environ["WORK"]
def submit(i):
    body=json.dumps({"action":"submit","pseudo":f"user{i}","new_subjects":[f"Sujet {i%5}"],
        "q1":{"Migration API v2":"suivi le sujet"},"q5":"J'ai juste ce qu'il faut"}).encode()
    req=urllib.request.Request(BASE+"/api.php",data=body,headers={"Content-Type":"application/json"})
    with urllib.request.urlopen(req,timeout=30) as r: return json.load(r)["response_id"]
with concurrent.futures.ThreadPoolExecutor(20) as ex: ids=list(ex.map(submit,range(40)))
d=json.load(open(WORK+"/data.json"))
pseudos=sorted(r["pseudo"] for r in d["responses"])
assert len(d["responses"])==40, len(d["responses"])
assert len(set(ids))==40, "ids non uniques"
assert pseudos==sorted(f"user{i}" for i in range(40))
assert sorted(s for s in d["subjects"] if s.startswith("Sujet "))==[f"Sujet {i}" for i in range(5)], d["subjects"]
print("  ✅ 40 réponses, 40 ids uniques, 5 sujets créés sans doublon (JSON final valide)")
PY
[ $? -eq 0 ] && PASS=$((PASS+1)) || { FAIL=$((FAIL+1)); echo "  ❌ concurrence"; }

echo
echo "API : $PASS OK, $FAIL KO"
[ "$FAIL" = 0 ]
