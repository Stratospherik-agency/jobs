# Déploiement sur Infomaniak

Guide pour un hébergement web Infomaniak avec accès SSH, comme le site Laravel existant.
Les libellés exacts du Manager peuvent varier légèrement.

## 1. Préparer l'hébergement (Manager Infomaniak, une seule fois)

1. **Sous-domaine** : ajouter le site `candidatures.pierreolliviertissier.ch` sur l'hébergement.
   Le DNS étant chez Infomaniak, l'enregistrement est créé automatiquement.
2. **Dossier racine du site** : le faire pointer vers le dossier `public` de l'application,
   par exemple `~/sites/candidatures.pierreolliviertissier.ch/public`. Rien d'autre ne doit être exposé.
3. **PHP** : version 8.3 ou plus récente pour ce site.
4. **Certificat SSL** : activer Let's Encrypt sur le sous-domaine, puis la redirection HTTPS.
5. **Base de données** : créer une base MySQL/MariaDB et un utilisateur dédiés, avec un mot de passe long.
6. **Boîte d'envoi** : une adresse (par exemple `alertes@pierreolliviertissier.ch`) pour les e-mails d'alerte.

## 2. Récupérer le code sur le serveur (une seule fois)

Le dépôt est privé : créer une clé de déploiement en lecture seule.

```bash
ssh-keygen -t ed25519 -f ~/.ssh/jobs_deploy -N "" -C "infomaniak-candidatures"
cat ~/.ssh/jobs_deploy.pub
```

Ajouter la clé publique dans GitHub : dépôt `jobs` > Settings > Deploy keys > Add deploy key (sans cocher « Allow write access »).

```bash
cat >> ~/.ssh/config <<'CFG'
Host github-jobs
  HostName github.com
  User git
  IdentityFile ~/.ssh/jobs_deploy
  IdentitiesOnly yes
CFG

cd ~/sites
git clone git@github-jobs:Stratospherik-agency/jobs.git candidatures.pierreolliviertissier.ch
cd candidatures.pierreolliviertissier.ch
```

## 3. Configurer (une seule fois)

```bash
cp .env.example .env
chmod 600 .env
nano .env        # DASHBOARD_OWNER_EMAIL, base de données, SMTP
php artisan key:generate
```

Garder une copie de `APP_KEY` dans un gestionnaire de mots de passe : sans elle, les secrets
chiffrés en base (double authentification, futurs accès Google) sont illisibles.

## 4. Installer et mettre à jour

À lancer au premier déploiement, puis à chaque nouvelle version :

```bash
cd ~/sites/candidatures.pierreolliviertissier.ch
php artisan down
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan filament:assets
php artisan optimize
php artisan filament:optimize
php artisan up
```

## 5. Créer le compte (une seule fois)

```bash
php artisan dashboard:owner
```

Puis ouvrir https://candidatures.pierreolliviertissier.ch, se connecter et configurer la double
authentification (scanner le QR code, puis conserver les 8 codes de secours hors ligne).

## 6. Tâches planifiées

Dans le Manager (Tâches planifiées / Cron) ou par `crontab -e`, toutes les minutes :

```
* * * * * cd ~/sites/candidatures.pierreolliviertissier.ch && php artisan schedule:run >> /dev/null 2>&1
```

Elles serviront aux synchronisations Gmail et Sheets des étapes suivantes.

## 7. Vérifications après mise en ligne

```bash
curl -sI https://candidatures.pierreolliviertissier.ch/login | grep -iE "x-robots-tag|content-security|strict-transport|x-frame"
curl -s https://candidatures.pierreolliviertissier.ch/robots.txt
curl -sI https://candidatures.pierreolliviertissier.ch/.env     # doit répondre 404 ou 403
```

## En cas de problème

| Situation | Action |
| --- | --- |
| Mot de passe oublié | `php artisan dashboard:owner` en SSH |
| Téléphone perdu, codes de secours épuisés | `php artisan dashboard:owner --reset-2fa` en SSH |
| Alerte de connexion suspecte | Changer le mot de passe (ferme toutes les sessions), puis `--reset-2fa` |
| Erreur 500 | Lire `storage/logs/laravel-*.log` ; ne jamais passer `APP_DEBUG=true` en production |
