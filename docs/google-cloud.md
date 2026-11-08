# Connecter Gmail et Drive au tableau de bord

Ce guide se fait une seule fois, depuis un navigateur, connecté au compte Google
`pierreolliviertissier@gmail.com`. Comptez 15 minutes.

Le tableau de bord demande deux autorisations seulement :

- **Gmail (modification)** : lire les brouillons et la correspondance, envoyer une candidature
  **uniquement après votre validation** dans le tableau de bord. Rien n'est jamais supprimé, à part
  le brouillon importé, une fois la candidature envoyée.
- **Drive (fichiers créés par l'application)** : l'application ne voit que le dossier
  « Candidatures » qu'elle crée elle-même, pas le reste de votre Drive.

## 1. Créer le projet

1. Ouvrir https://console.cloud.google.com/projectcreate
2. Nom du projet : `Candidatures`, puis **Créer**.
3. En haut de la page, vérifier que le projet « Candidatures » est bien sélectionné.

## 2. Activer les deux API

1. Ouvrir https://console.cloud.google.com/apis/library/gmail.googleapis.com puis **Activer**.
2. Ouvrir https://console.cloud.google.com/apis/library/drive.googleapis.com puis **Activer**.

## 3. Écran de consentement

1. Ouvrir https://console.cloud.google.com/auth/overview puis **Commencer**.
2. Nom de l'application : `Tableau de bord candidatures` ; adresse d'assistance : votre Gmail.
3. Audience : **Externe**.
4. Coordonnées : votre Gmail. Accepter les conditions puis **Créer**.
5. Menu **Audience** : cliquer sur **Publier l'application**, puis confirmer.
   Sans cette étape, Google coupe l'accès tous les 7 jours.

Google affichera « application non validée » lors de la connexion : c'est normal pour une
application personnelle. Cliquer sur **Paramètres avancés**, puis **Accéder à Tableau de bord
candidatures**.

## 4. Créer les identifiants

1. Ouvrir https://console.cloud.google.com/auth/clients puis **Créer un client**.
2. Type d'application : **Application Web**. Nom : `jobs.pierreolliviertissier.ch`.
3. **URI de redirection autorisés** → **Ajouter un URI** :
   `https://jobs.pierreolliviertissier.ch/google/callback`
4. **Créer**. Google affiche un **ID client** et un **Code secret du client**.
   Gardez cette fenêtre ouverte (ou téléchargez le JSON). Ne les envoyez à personne,
   pas même par e-mail ou dans une discussion.

## 5. Les ajouter au serveur

Se connecter en SSH, puis :

```bash
cd ~/sites-production/pierreolliviertissier/jobs.pierreolliviertissier.ch
read -r -p "ID client Google : " GID && read -r -s -p "Code secret Google : " GSECRET && echo \
 && sed -i '/^GOOGLE_CLIENT_ID=/d;/^GOOGLE_CLIENT_SECRET=/d' .env \
 && printf 'GOOGLE_CLIENT_ID=%s\nGOOGLE_CLIENT_SECRET=%s\n' "$GID" "$GSECRET" >> .env \
 && unset GID GSECRET && php artisan optimize && echo "Enregistré."
```

La commande demande l'ID puis le code secret (le code n'apparaît pas pendant la saisie, c'est
normal) : coller chaque valeur puis Entrée.

## 6. Se connecter depuis le tableau de bord

1. Ouvrir https://jobs.pierreolliviertissier.ch/compte-google
2. **Connecter mon compte Google**, choisir `pierreolliviertissier@gmail.com`, accepter.
3. La page affiche « Connecté ». Tout autre compte Google est refusé.

Pour couper l'accès à tout moment : bouton **Déconnecter** sur la même page, ou
https://myaccount.google.com/connections.
