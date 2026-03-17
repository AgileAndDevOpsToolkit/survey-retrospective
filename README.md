# Sondage PI Planning

Application web de sondage pour les PI Planning, conçue pour un usage local et ponctuel.

## Prérequis

- **PHP 8.0+** avec l'extension SQLite3 (activée par défaut sur la plupart des installations)
- Un navigateur moderne (Chrome, Firefox, Edge…)

## Installation et lancement

1. Ouvrez un terminal dans le dossier `survey-app/`

2. Lancez le serveur PHP intégré :

```bash
php -S localhost:8080
```

3. Ouvrez votre navigateur à l'adresse :
   - **Sondage** : [http://localhost:8080/index.html](http://localhost:8080/index.html)
   - **Résultats** : [http://localhost:8080/results.html](http://localhost:8080/results.html)

## Structure

```
survey-app/
├── db.php          # Initialisation SQLite + helpers
├── api.php         # API REST (GET sujets/résultats, POST réponses)
├── index.html      # Page de sondage (HTML/CSS/JS)
├── results.html    # Page de résultats avec graphiques (Chart.js)
├── survey.db       # Base SQLite (créée automatiquement au 1er appel)
└── README.md
```

## Schéma de la base de données

- **subjects** : liste des sujets (id, name)
- **responses** : réponses (id, pseudo, created_at)
- **q1_contribution** : contribution par sujet (response_id, subject_name, level)
- **q2_priority** : classement prioritaire (response_id, subject_name, rank)
- **q3_vision** : niveau de vision (response_id, subject_name, level)
- **q4_confidence** : niveau de confiance (response_id, subject_name, level)
- **q5_workload** : charge de travail (response_id, level)

## Personnalisation des sujets

Les sujets par défaut ("Sujet 1", "Sujet 2", "Sujet 3") sont définis dans `db.php`.
Pour les modifier, éditez le tableau `$defaults` dans la fonction `initDB()`.

Les participants peuvent aussi ajouter des sujets à la volée depuis la question 1.

## Remise à zéro

Supprimez simplement le fichier `survey.db` puis rechargez la page — la base sera recréée automatiquement.
