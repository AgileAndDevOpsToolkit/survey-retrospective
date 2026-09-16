# Sondage PI Planning

Application web de sondage pour les PI Planning, conçue pour un usage local et ponctuel.

Trois pages HTML, un seul script PHP, et **toute la base de données dans un fichier `data.json`**
à la racine, à côté des fichiers `.html`. Pas de SQLite, pas d'extension PHP à installer.

## Lancement

### Avec Docker (recommandé)

```bash
docker compose up --build
# ou, à la main :
docker build -t survey-retro .
docker run --rm -p 8080:80 survey-retro
```

### Avec PHP seul

```bash
php -S localhost:8080
```

Puis ouvrir :

- **Sondage** : <http://localhost:8080/index.html>
- **Résultats** : <http://localhost:8080/results.html>
- **Administration** : <http://localhost:8080/admin.html>

## Structure

```
.
├── index.html      # Questionnaire (5 questions, ajout de sujets à la volée)
├── results.html    # Résultats agrégés avec graphiques (Chart.js)
├── admin.html      # Administration : sujets par défaut, suppression des réponses, sauvegarde
├── api.php         # Unique back-end : lit/écrit data.json
├── data.json       # LA base de données (sujets + réponses)
├── Dockerfile
├── docker-compose.yml
└── tests/          # Tests automatisés de l'API et des pages (non livrés dans l'image)
```

## Page d'administration (`admin.html`)

- **Sujets par défaut** : ajouter, renommer, réordonner, supprimer. Chaque modification est
  enregistrée immédiatement dans `data.json`. Les participants peuvent toujours ajouter
  des sujets à la volée depuis la question 1 : ils rejoignent alors la liste.
- **Réponses** : liste des participations, suppression individuelle, et bouton
  **Supprimer toutes les réponses** (les sujets sont conservés).
- **Données** : téléchargement de `data.json` et diagnostic du serveur (droits d'écriture…).

La page admin n'a pas d'authentification : l'application est prévue pour un réseau local
ou une session de travail ponctuelle.

## Le fichier `data.json`

```json
{
  "version": 1,
  "subjects": ["Migration API v2", "Refonte page d'accueil", "..."],
  "responses": [
    {
      "id": 1,
      "pseudo": "Alice",
      "created_at": "2026-09-16T10:12:33+00:00",
      "q1": { "Migration API v2": "contribué activement" },
      "q2": ["Refonte page d'accueil", "Migration API v2"],
      "q3": { "Migration API v2": "super claire" },
      "q4": { "Migration API v2": "on va réussir" },
      "q5": "J'ai juste ce qu'il faut"
    }
  ]
}
```

- `subjects` : l'ordre du tableau est l'ordre d'affichage.
- `q1`, `q3`, `q4` : dictionnaire `sujet → réponse` (seuls les sujets renseignés y figurent).
- `q2` : liste ordonnée des sujets, du plus prioritaire au moins prioritaire.

Vous pouvez éditer ce fichier à la main (serveur arrêté ou non : chaque écriture de l'API
relit le fichier sous verrou avant de le modifier).

### Robustesse

- Toute écriture se fait sous verrou exclusif (`flock`) puis de façon atomique
  (fichier temporaire + `rename`), avec repli en écriture directe si `rename` est
  impossible (par ex. `data.json` monté en *bind-mount* Docker).
- Fichier **absent** → recréé avec les sujets par défaut au premier appel.
- Fichier **corrompu** (JSON invalide) → conservé en `data.json.corrupt-<date>` puis recréé.
- Contenu **partiellement invalide** (sujet inconnu, valeur hors liste…) → nettoyé à la lecture.
- Toutes les entrées utilisateur sont validées côté serveur (longueurs, valeurs autorisées,
  doublons de sujets insensibles à la casse, taille du corps de requête).
- L'API répond **toujours** en JSON, y compris en cas d'erreur PHP, avec un code HTTP explicite.

## API (`api.php`)

| Méthode | Appel | Rôle |
|---|---|---|
| GET | `?action=subjects` | Liste des sujets |
| GET | `?action=results` | Sujets + toutes les réponses |
| GET | `?action=db` | Contenu brut de `data.json` (sauvegarde) |
| GET | `?action=health` | Diagnostic (chemin, droits, compteurs) |
| POST | `{"action":"submit", "pseudo", "new_subjects", "q1", "q2", "q3", "q4", "q5"}` | Enregistre une participation |
| POST | `{"action":"set_subjects", "subjects":[…]}` | Remplace la liste ordonnée des sujets |
| POST | `{"action":"delete_response", "id":N}` | Supprime une réponse |
| POST | `{"action":"clear_responses"}` | Supprime toutes les réponses |

## Remise à zéro

Depuis `admin.html` (bouton *Supprimer toutes les réponses*), ou en supprimant `data.json`
puis en rechargeant une page : il sera recréé avec les sujets par défaut.

## Tests

```bash
cd tests
./run.sh          # démarre un serveur PHP de test, joue les tests API (bash/curl)
                  # puis les tests d'interface (Playwright, si installé)
```
