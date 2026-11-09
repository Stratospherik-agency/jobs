# Visionneuse PDF (PDF.js)

Visionneuse officielle de Mozilla PDF.js 6.4.299 (licence Apache 2.0, voir `public/pdfjs/LICENSE`),
hébergée sur le site pour respecter la politique de sécurité (aucune ressource d'un autre domaine).

- `public/pdfjs/` : scripts, styles, polices et traductions (fr, en-US) de la distribution
  `pdfjs-6.4.299-dist.zip`, sans les fichiers `.map`, le PDF d'exemple ni le débogueur.
- `resources/pdfjs/viewer.html` : la page de la visionneuse, servie uniquement par la route
  authentifiée `pieces-jointes/{attachment}/consulter` (`AttachmentController::viewer`).

Mise à jour : télécharger la nouvelle archive `pdfjs-X.Y.Z-dist.zip` depuis les versions de
github.com/mozilla/pdf.js, remplacer les mêmes fichiers, puis lancer `php artisan test`.
