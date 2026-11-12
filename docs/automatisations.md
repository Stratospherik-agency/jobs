# Automatisations et notifications

Le tableau de bord prépare, range et prévient. **Il n'envoie jamais rien à un recruteur sans toi** :
les candidatures attendent ton clic « Valider et envoyer », et les relances restent des brouillons
dans Gmail, que tu envoies toi-même.

## Ce qui tourne tout seul

| Automatisation | Quand | Ce qu'elle fait |
| --- | --- | --- |
| Score de pertinence | à chaque offre, et chaque nuit à 5 h 30 | Note sur 100 : métier (35), zone (20), taux (15), secteur (10), mots-clés ATS (20). Les annonces ignorées font baisser le score des annonces semblables. Le détail s'affiche sur la fiche. |
| Candidature préparée d'office | toutes les 15 minutes | Offre notée 3/3 (ou au-dessus du score choisi) dont les documents sont dans le registre : la candidature est remplie (destinataire, objet, message, PDF) et passe « À valider ». |
| Relances | chaque jour à l'heure choisie (7 h par défaut) | À la date de relance, brouillon créé dans Gmail, dans le fil de la candidature. Puis un rappel si toujours rien après le délai choisi (21 jours par défaut). |
| Suivi des réponses | toutes les heures | Lit le libellé Gmail « Jobs » et les fils des candidatures envoyées. Refus ou invitation à un entretien : statut mis à jour et noté dans l'historique. Relance envoyée depuis Gmail : statut « Relancé ». |
| Préparation d'entretien | dès le passage au statut « Entretien » | Fiche sur la page de l'offre : entreprise, poste, preuves chiffrées de ta banque de preuves, questions probables, questions à poser. |
| Veille des entreprises cibles | chaque jour (6 h 30 par défaut) | Nouvelle offre d'une entreprise de l'onglet « Spontanées », ou e-mail du libellé « Jobs » qui la cite (recrutement, nomination, levée de fonds…). |
| Résumé du matin | chaque jour à 7 h 15 | Nouvelles offres, candidatures à valider, relances, entretiens et signaux de veille, avec un lien vers chaque fiche. Rien n'est envoyé s'il n'y a rien à dire. |
| Bilan hebdomadaire | le vendredi à 17 h | Taux de réponse par source, type de candidature, secteur et langue, avec des ajustements proposés. |

Tout se règle dans **Réglages** :

- onglet **Notifications** : pour chaque type, e-mail oui/non, web app oui/non, heure, intervalle en jours
  (et jour de la semaine pour le bilan). Le réglage « App iOS » est prêt pour la future application iPhone.
  Le bouton **Tester les notifications** envoie un message de test ;
- onglet **Automatisations** : préparation d'office (oui/non, score minimal), brouillons de relance et leur
  modèle, mise à jour automatique du statut, libellé Gmail lu, banque de preuves, dernières exécutions.

Les notifications de la web app apparaissent sous la **cloche**, en haut à droite.

## Mise en ligne (pas à pas)

Compte 10 minutes. Chaque commande se copie entière, puis Entrée.

1. Fusionne la pull request sur GitHub (bouton vert « Merge pull request », puis « Confirm merge »).
2. Ouvre le Terminal de ton Mac et connecte-toi au serveur :

   ```bash
   ssh oocv_root@oocv.ftp.infomaniak.com
   ```

3. Va dans le dossier du site :

   ```bash
   cd ~/sites-production/pierreolliviertissier/jobs.pierreolliviertissier.ch
   ```

4. Mets le site à jour (une ligne à la fois, attendre la fin de chacune) :

   ```bash
   php artisan down
   git pull --ff-only
   composer install --no-dev --optimize-autoloader --no-interaction
   php artisan migrate --force
   php artisan filament:assets
   php artisan optimize
   php artisan filament:optimize
   php artisan up
   ```

   `migrate` doit afficher deux lignes `DONE` (`create_notifications_table` et `add_automation_fields`).

5. Vérifie que la tâche planifiée (cron) existe bien :

   ```bash
   crontab -l
   ```

   Tu dois voir une ligne qui finit par `php artisan schedule:run >> /dev/null 2>&1`. Si elle manque,
   ajoute-la dans le Manager Infomaniak (Hébergement > Tâches planifiées / Cron), toutes les minutes :

   ```
   * * * * * cd ~/sites-production/pierreolliviertissier/jobs.pierreolliviertissier.ch && php artisan schedule:run >> /dev/null 2>&1
   ```

6. Lance une première fois toutes les automatisations, pour vérifier qu'elles fonctionnent :

   ```bash
   php artisan automatisations:executer --force
   ```

   Chaque ligne affiche un résumé, par exemple `scores : 12 score(s) mis à jour`. Une ligne rouge
   indique une erreur : copie-la pour Claude.

7. Ferme la connexion : `exit`.

## Réglages à faire une fois dans le tableau de bord

1. **Réglages > E-mails** : renseigne la boîte d'envoi et le destinataire (voir « Les e-mails n'arrivent
   pas » plus bas), **Enregistrer**, puis clique sur **Tester les notifications**. Tu dois recevoir un e-mail
   et voir une notification sous la cloche.
2. Toujours dans **Notifications**, choisis pour chaque type e-mail et/ou web app, l'heure et l'intervalle en
   jours, puis **Enregistrer**.
3. **Réglages > Automatisations > Banque de preuves** : colle le tableau de ta banque de preuves
   (fichier `candidatures/base/banque-preuves.md` du projet, la partie qui commence par `| # |`).
4. **Gmail** : pour que les réponses des recruteurs soient repérées même hors du fil de la candidature,
   ajoute dans Gmail un filtre qui range dans le libellé « Jobs » les e-mails des domaines des entreprises
   auxquelles tu postules (ou les e-mails contenant « candidature »). Pour la veille, crée une alerte Google
   (google.com/alerts) par entreprise cible et range-les aussi dans « Jobs ».

## État du système et alertes

En haut du tableau de bord, l'encart **État du système** contrôle chaque maillon : tâche planifiée (cron),
envoi des e-mails, compte Google, synchronisation du registre, alertes d'emploi dans le libellé Gmail
« Jobs » et nouvelles offres ajoutées par la recherche quotidienne. Une ligne verte : tout va bien.
Sinon, chaque point en panne est affiché avec la marche à suivre, et une alerte « Problème technique »
part (web app et e-mail, une fois par jour au plus). Si ce sont les e-mails qui ne partent pas,
l'alerte arrive sous la cloche.

## Les e-mails n'arrivent pas

Tout se règle dans le tableau de bord, sans SSH : **Réglages > E-mails**.

1. **Boîte d'envoi** : l'adresse d'une boîte e-mail Infomaniak existante (par exemple une adresse de ton
   domaine) et son mot de passe. Le mot de passe est chiffré et n'est jamais réaffiché.
2. **Destinataire** : l'adresse où tu veux recevoir les résumés et alertes (par exemple ton Gmail).
3. Le serveur (mail.infomaniak.com, port 465, SSL) est déjà rempli dans « Réglages avancés ».
4. **Enregistrer**, puis **Tester les notifications** en haut de la page. En cas d'échec, le message
   affiche l'erreur exacte du serveur d'e-mail. Pense aussi au dossier **Spam**.

Ces réglages remplacent les lignes `MAIL_…` du fichier `.env`.

## Les alertes d'emploi n'arrivent pas dans « Jobs »

La recherche quotidienne et le tableau de bord ne lisent que ce libellé. Il faut donc que chaque
plateforme t'envoie ses alertes et qu'un filtre Gmail les y range :

1. Vérifie que les alertes existent : LinkedIn (Emplois > Alertes), jobup, jobs.ch, Indeed,
   et l'alerte Google Jobs.
2. Dans Gmail, ouvre une alerte de chaque plateforme et note l'adresse de l'expéditeur.
3. Gmail > Paramètres > Filtres et adresses bloquées > Créer un filtre. Dans « De », colle par exemple :

   ```
   jobmail@jobs.ch OR candidat@jobup.ch OR match.indeed.com OR jobalerts-noreply@linkedin.com
   ```

   en ajoutant les adresses relevées à l'étape 2. Puis « Créer un filtre » > cocher « Appliquer le
   libellé : Jobs » et « Appliquer également le filtre aux conversations correspondantes ».

## Commandes utiles (SSH)

| Besoin | Commande |
| --- | --- |
| Tout lancer maintenant | `php artisan automatisations:executer --force` |
| Une seule tâche | `php artisan automatisations:executer relances --force` (ou `scores`, `preparation`, `reponses`, `veille`, `resume_matin`, `bilan`, `sante`) |
| Voir ce qui est planifié | `php artisan schedule:list` |

## Limites connues

- Le registre Google Sheets reste en lecture seule pour le tableau de bord : un statut changé par le suivi
  des réponses apparaît dans le tableau de bord, pas encore dans la feuille.
- Le tableau de bord ne rédige pas les CV et lettres : la recherche quotidienne de Claude les prépare dans
  Canva et les note dans le registre (colonnes « Lettre (texte) », « Objet », « Message d'accompagnement »,
  « PDF (liens Canva) » et, si l'annonce en donne une, « E-mail de contact »).
