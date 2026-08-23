# Suivi Prospects — Marketing Relationnel

Application web pour suivre tes prospects depuis le premier contact jusqu'à leur inscription : invitation, présentation, intérêt, inscription ou abandon.

## Structure du dossier

```
suivi-prospects/
├── index.html              → page principale
├── database.sql            → script de création de la base de données
├── assets/
│   ├── css/style.css       → styles (couleurs modifiables en haut du fichier)
│   └── js/app.js           → logique de l'application
└── api/
    ├── config.php          → connexion à la base de données (à configurer)
    ├── auth.php            → API d'inscription et de connexion
    └── prospects.php       → API sécurisée des prospects
```

## Installation (avec WAMP, MAMP, XAMPP ou Laragon)

1. **Copie le dossier `suivi-prospects`** dans le répertoire web de ton serveur local :
   - WAMP : `C:\wamp64\www\`
   - XAMPP : `C:\xampp\htdocs\`
   - Laragon : `C:\laragon\www\`

2. **Crée la base de données** :
   - Ouvre phpMyAdmin (`http://localhost/phpmyadmin`)
   - Va dans l'onglet **Importer**, choisis le fichier `database.sql`, puis valide.
   - Cela crée la base `suivi_prospects`, les comptes utilisateurs et les prospects.
   - Les comptes sont créés depuis l'écran d'inscription ; aucun prospect de démonstration partagé n'est importé.

3. **Configure la connexion** dans `api/config.php` si besoin :
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'suivi_prospects');
   define('DB_USER', 'root');
   define('DB_PASS', '');   // souvent vide en local
   ```

   Après une mise à jour du projet, applique les migrations avant de relancer
   l'application :
   ```bash
   php scripts/migrate.php
   ```
   En production Docker, cette étape est exécutée automatiquement au démarrage.

4. **Lance l'application** :
   - Démarre Apache + MySQL depuis WAMP/XAMPP/Laragon
   - Ouvre `http://localhost/suivi-prospects/` dans ton navigateur

## Fonctionnalités

- **Ajouter un prospect** : nom, prénom, téléphone, email, source du contact
- **Cocher "Invitation déjà faite"** avec la date
- **Cocher "A assisté à la présentation"** avec la date
- **Statut du pipeline** : Nouveau → Invité → Présentation faite → Intéressé → Inscrit (ou Perdu)
- **Changer le statut rapidement** directement depuis la liste
- **Programmer une prochaine relance** (date de suivi)
- **Notes libres** sur chaque prospect
- **Recherche** par nom, prénom ou téléphone
- **Filtres** par statut
- **Statistiques** : total, en cours, inscrits, taux de conversion

## Personnaliser les couleurs

Le blanc est la couleur principale (fond de l'application). Toutes les couleurs sont centralisées en haut du fichier `assets/css/style.css` :

```css
:root {
    --color-bg: #ffffff;         /* couleur principale */
    --color-accent: #0f6f52;     /* couleur d'accent (boutons, liens actifs) */
    /* ajoute ou modifie ici d'autres variables de couleur si besoin */
}
```

Il suffit de changer ces valeurs (ou d'en ajouter de nouvelles) pour que toute l'application change de style automatiquement.

## Prochaines pistes (si tu veux aller plus loin)

- Ajouter la gestion de ton réseau (filleuls) une fois qu'un prospect est inscrit
- Ajouter des notifications de relance (rappel si aucune action depuis X jours)
- Ajouter un système de connexion multi-utilisateurs si tu veux le partager avec ton équipe
# Suivi
